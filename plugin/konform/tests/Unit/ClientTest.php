<?php
/**
 * KSeF istemci testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Ksef\Client;
use Konform\Ksef\Encryption;
use Konform\Ksef\Response;
use Konform\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Bu testler KSeF'e bağlanmaz; akışın KURULUMUNU sınar.
 *
 * Canlı sınav bir KSeF test jetonu gerektiriyor ve o jeton mağaza sahibinin
 * hesabından alınır. Buradaki testler ondan bağımsız olarak şunları tutuyor:
 * hangi uçlara hangi sırayla gidiliyor, gövdelerde hangi alanlar var, jeton
 * şifreleniyor mu, hata yanıtı nasıl okunuyor.
 */
final class ClientTest extends TestCase {

	/**
	 * Yetkilendirme kaç HTTP çağrısı yapar.
	 */
	private const AUTH_CALLS = 4;

	/**
	 * Test sertifikası (PEM, açık anahtar).
	 *
	 * @var string
	 */
	private string $certificate = '';

	/**
	 * Anahtar çifti üretir.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$resource = \openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		$details           = \openssl_pkey_get_details( $resource );
		$this->certificate = $details['key'];

		\openssl_pkey_export( $resource, $this->private_key );
	}

	/**
	 * Test özel anahtarı (PEM).
	 *
	 * @var string
	 */
	private string $private_key = '';

	/**
	 * Yetkilendirme belgelenmiş üç adımı sırayla yapar.
	 *
	 * @return void
	 */
	public function test_authentication_follows_the_documented_steps(): void {
		$transport = new RecordingTransport( $this->auth_responses() );

		( new Client( $transport, Client::TEST_BASE_URL ) )
			->authenticate( 'KSEF-TOKEN', '1234567890', $this->certificate );

		$this->assertSame(
			array( '/auth/challenge', '/auth/ksef-token', '/auth/REF-1', '/auth/token/redeem' ),
			$this->paths( $transport )
		);
	}

	/**
	 * Jeton düz metin olarak gönderilmez.
	 *
	 * KSeF jetonu kalıcı bir kimlik bilgisidir. Şifrelenmeden gönderilmesi,
	 * karşı taraf kabul etse bile ciddi bir sızıntı olurdu.
	 *
	 * @return void
	 */
	public function test_the_ksef_token_is_never_sent_in_clear_text(): void {
		$transport = new RecordingTransport( $this->auth_responses() );

		( new Client( $transport, Client::TEST_BASE_URL ) )
			->authenticate( 'GIZLI-JETON', '1234567890', $this->certificate );

		$submission = $transport->requests[1]['body'];

		$this->assertArrayHasKey( 'encryptedToken', $submission );
		$this->assertStringNotContainsString( 'GIZLI-JETON', (string) wp_json_encode( $transport->requests ) );

		// Sarmalanmis anahtar 2048 bitlik RSA icin 256 bayttir.
		$this->assertSame( 256, strlen( (string) base64_decode( $submission['encryptedToken'], true ) ) );
	}

	/**
	 * Zaman damgasının milisaniyeleri korunur.
	 *
	 * KSeF meydan okumada zaman damgasini KESIRLI SANIYELI bir ISO-8601
	 * dizgesi olarak donduruyor. Kesirler atilip saniyeye yuvarlanirsa KSeF
	 * imzayi kabul etmiyor ve "hatali jeton" diye reddediyor — mesajdan sebep
	 * anlasilmiyor.
	 *
	 * Bu once fark edilmedi cunku sahte yanitlarda zaman damgasi tam sayi
	 * veriliyordu; gercek bicim ancak WordPress'te uctan uca sinavda ortaya
	 * cikti. Test artik gercek bicimi kullaniyor ve sifrelenmis jetonu cozup
	 * milisaniyelerin yerinde oldugunu dogruluyor.
	 *
	 * @return void
	 */
	public function test_the_challenge_timestamp_keeps_its_milliseconds(): void {
		$transport = new RecordingTransport(
			array(
				new Response(
					200,
					(string) wp_json_encode(
						array(
							'challenge' => 'C-1',
							'timestamp' => '2026-09-04T06:08:10.938+00:00',
						)
					)
				),
				new Response(
					200,
					(string) wp_json_encode(
						array(
							'authenticationToken' => array( 'token' => 'AUTH-1' ),
							'referenceNumber'     => 'REF-1',
						)
					)
				),
				new Response( 200, (string) wp_json_encode( array( 'status' => array( 'code' => 200 ) ) ) ),
				new Response( 200, (string) wp_json_encode( array( 'accessToken' => array( 'token' => 'ACCESS-1' ) ) ) ),
			)
		);

		( new Client( $transport, Client::TEST_BASE_URL ) )
			->authenticate( 'JETON', '1234567890', $this->certificate );

		$encrypted = (string) base64_decode( $transport->requests[1]['body']['encryptedToken'], true );

		$plain = '';
		$this->assertTrue(
			\openssl_private_decrypt( $encrypted, $plain, $this->private_key, OPENSSL_PKCS1_OAEP_PADDING, 'sha256' ),
			'Sarmalanan jeton çözülemedi.'
		);

		$parts = explode( '|', $plain );

		$this->assertSame( 'JETON', $parts[0] );

		// Milisaniye korunmus olmali: saniyeye yuvarlansaydi "000" ile biterdi.
		$this->assertSame( '938', substr( $parts[1], -3 ), 'Milisaniyeler kaybolmuş.' );
		$this->assertSame( 13, strlen( $parts[1] ), 'Unix milisaniye 13 hane olmalı.' );
	}

	/**
	 * Erişim jetonu sonraki isteklerin başlığına eklenir.
	 *
	 * @return void
	 */
	public function test_the_access_token_authorises_later_requests(): void {
		$transport = null;

		$client = $this->authenticated_client(
			array( new Response( 200, (string) wp_json_encode( array( 'referenceNumber' => 'SESSION-1' ) ) ) ),
			$transport
		);

		$client->open_session( 'anahtar', 'iv' );

		$this->assertSame(
			'Bearer ACCESS-1',
			$transport->requests[ self::AUTH_CALLS ]['headers']['Authorization']
		);
	}

	/**
	 * Oturum açarken şifreleme bilgisi bildirilir.
	 *
	 * @return void
	 */
	public function test_opening_a_session_declares_the_encryption(): void {
		$transport = null;

		$client = $this->authenticated_client(
			array( new Response( 200, (string) wp_json_encode( array( 'referenceNumber' => 'SESSION-1' ) ) ) ),
			$transport
		);

		$key = Encryption::generate_key();
		$iv  = Encryption::generate_iv();

		$reference = $client->open_session( Encryption::wrap_key( $key, $this->certificate ), $iv );

		$this->assertSame( 'SESSION-1', $reference );

		$body = $transport->requests[ self::AUTH_CALLS ]['body'];

		$this->assertSame( 'FA', $body['formCode']['value'] );
		$this->assertSame( base64_encode( $iv ), $body['encryption']['initializationVector'] );
		$this->assertArrayHasKey( 'encryptedSymmetricKey', $body['encryption'] );
	}

	/**
	 * Fatura şifreli gönderilir ve özetleri doğru hesaplanır.
	 *
	 * @return void
	 */
	public function test_the_invoice_is_sent_encrypted_with_correct_hashes(): void {
		$transport = null;

		$client = $this->authenticated_client(
			array( new Response( 200, (string) wp_json_encode( array( 'referenceNumber' => 'INVOICE-1' ) ) ) ),
			$transport
		);

		$key = Encryption::generate_key();
		$iv  = Encryption::generate_iv();
		$xml = '<Faktura><P_2>FA/2026/1</P_2></Faktura>';

		$this->assertSame( 'INVOICE-1', $client->send_invoice( 'SESSION-1', $xml, $key, $iv ) );

		$body = $transport->requests[ self::AUTH_CALLS ]['body'];

		// Duz metin ozeti ve boyutu ORIJINAL faturaya ait olmali.
		$this->assertSame( base64_encode( hash( 'sha256', $xml, true ) ), $body['invoiceHash'] );
		$this->assertSame( strlen( $xml ), $body['invoiceSize'] );

		$encrypted = (string) base64_decode( $body['encryptedInvoiceContent'], true );

		$this->assertSame( base64_encode( hash( 'sha256', $encrypted, true ) ), $body['encryptedInvoiceHash'] );
		$this->assertSame( strlen( $encrypted ), $body['encryptedInvoiceSize'] );

		// Gonderilen icerik gercekten sifreli, ve dogru anahtarla geri cozulebiliyor.
		$this->assertStringNotContainsString( 'FA/2026/1', $encrypted );
		$this->assertSame(
			$xml,
			\openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv )
		);
	}

	/**
	 * Durum sorgusu "online" yolundan yapılmaz.
	 *
	 * Oturum ACMAK icin /sessions/online kullanilir, ama acilmis oturumu ve
	 * icindeki faturalari SORGULAMAK /sessions/{ref} altindan yapilir. Ilk
	 * yazimda ikisi de "online" idi ve api-test ortami 404 dondurdu; belgeler
	 * bu ayrimi acikca soylemiyordu.
	 *
	 * @return void
	 */
	public function test_status_is_queried_without_the_online_segment(): void {
		$transport = null;

		$client = $this->authenticated_client(
			array( new Response( 200, (string) wp_json_encode( array( 'ksefNumber' => 'KSEF-1' ) ) ) ),
			$transport
		);

		$status = $client->invoice_status( 'SESSION-1', 'INVOICE-1' );

		$this->assertSame( 'KSEF-1', $status['ksefNumber'] );

		$url = (string) $transport->requests[ self::AUTH_CALLS ]['url'];

		$this->assertStringEndsWith( '/sessions/SESSION-1/invoices/INVOICE-1', $url );
		$this->assertStringNotContainsString( '/sessions/online/', $url );
	}

	/**
	 * Oturum durumu da aynı yoldan sorgulanır.
	 *
	 * @return void
	 */
	public function test_the_session_status_path(): void {
		$transport = null;

		$client = $this->authenticated_client(
			array(
				new Response(
					200,
					(string) wp_json_encode(
						array(
							'status'       => array( 'code' => 100 ),
							'invoiceCount' => 1,
						)
					)
				),
			),
			$transport
		);

		$this->assertSame( 1, $client->session_status( 'SESSION-1' )['invoiceCount'] );

		$this->assertStringEndsWith(
			'/sessions/SESSION-1',
			(string) $transport->requests[ self::AUTH_CALLS ]['url']
		);
	}

	/**
	 * Yetkilendirilmemiş istemci gönderim yapmaz.
	 *
	 * @return void
	 */
	public function test_an_unauthenticated_client_refuses_to_send(): void {
		$this->expectException( \RuntimeException::class );

		( new Client( new RecordingTransport( array() ), Client::TEST_BASE_URL ) )
			->open_session( 'anahtar', 'iv' );
	}

	/**
	 * KSeF'in hata açıklaması mesaja taşınır.
	 *
	 * "HTTP 400" tek başına kullanıcıya hiçbir şey söylemez.
	 *
	 * @return void
	 */
	public function test_the_ksef_error_description_reaches_the_message(): void {
		$transport = null;

		$client = $this->authenticated_client(
			array(
				new Response(
					400,
					(string) wp_json_encode(
						array(
							'exception' => array(
								'exceptionDetailList' => array(
									array( 'exceptionDescription' => 'Nieprawidlowy numer NIP' ),
								),
							),
						)
					)
				),
			),
			$transport
		);

		$this->expectExceptionMessageMatches( '/Nieprawidlowy numer NIP/' );

		$client->close_session( 'SESSION-1' );
	}

	/**
	 * Kimlik doğrulama reddedilirse beklemeye devam edilmez.
	 *
	 * @return void
	 */
	public function test_a_rejected_authentication_stops_immediately(): void {
		$transport = new RecordingTransport(
			array(
				new Response(
					200,
					(string) wp_json_encode(
						array(
							'challenge' => 'C-1',
							'timestamp' => 1756900000000,
						)
					)
				),
				new Response(
					200,
					(string) wp_json_encode(
						array(
							'authenticationToken' => 'AUTH-1',
							'referenceNumber'     => 'REF-1',
						)
					)
				),
				new Response(
					200,
					(string) wp_json_encode(
						array(
							'status' => array(
								'code'        => 401,
								'description' => 'Token niewazny',
							),
						)
					)
				),
			)
		);

		try {
			( new Client( $transport, Client::TEST_BASE_URL ) )
				->authenticate( 'JETON', '1234567890', $this->certificate );

			$this->fail( 'Reddedilen doğrulama istisna atmalıydı.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'Token niewazny', $error->getMessage() );
		}

		// Redeem cagrilmamali: reddedilen dogrulama beklemeye devam etmez.
		$this->assertCount( 3, $transport->requests );
	}

	/**
	 * Sertifika, istenen kullanıma göre seçilir.
	 *
	 * KSeF iki sertifika döndürüyor ve farklı işler için: jetonu şifrelemek
	 * (KsefTokenEncryption) ve AES anahtarını sarmalamak
	 * (SymmetricKeyEncryption). Canlı yanıt kontrol edilene kadar bu metot
	 * her zaman simetrik olanı veriyordu; yani kimlik doğrulama YANLIŞ
	 * sertifikayla şifreleme yapardı.
	 *
	 * @return void
	 */
	public function test_the_certificate_is_selected_by_usage(): void {
		foreach (
			array(
				Client::USAGE_SYMMETRIC_KEY => 'simetrik',
				Client::USAGE_TOKEN         => 'jeton',
			) as $usage => $expected
		) {
			$client = new Client(
				new RecordingTransport( array( new Response( 200, $this->certificate_response() ) ) ),
				Client::TEST_BASE_URL
			);

			$pem = $client->public_key_certificate( $usage );

			$this->assertStringContainsString( '-----BEGIN CERTIFICATE-----', $pem );
			$this->assertStringContainsString( base64_encode( $expected ), $pem, $usage . ' icin yanlis sertifika' );
		}
	}

	/**
	 * Bilinmeyen bir kullanım sessizce yanlış sertifika döndürmez.
	 *
	 * @return void
	 */
	public function test_an_unknown_certificate_usage_is_refused(): void {
		$client = new Client(
			new RecordingTransport( array( new Response( 200, $this->certificate_response() ) ) ),
			Client::TEST_BASE_URL
		);

		$this->expectException( \RuntimeException::class );

		$client->public_key_certificate( 'BoyleBirKullanimYok' );
	}

	/**
	 * KSeF'in canlı sertifika yanıtının biçimi.
	 *
	 * Alanlar ve kullanım etiketleri api-test ortamından alınan gerçek
	 * yanıttan: iki sertifika, biri jeton biri simetrik anahtar için.
	 *
	 * @return string
	 */
	private function certificate_response(): string {
		return (string) wp_json_encode(
			array(
				array(
					'certificate'   => base64_encode( 'jeton' ),
					'certificateId' => 'CERT-1',
					'usage'         => array( Client::USAGE_TOKEN ),
				),
				array(
					'certificate'   => base64_encode( 'simetrik' ),
					'certificateId' => 'CERT-2',
					'usage'         => array( Client::USAGE_SYMMETRIC_KEY ),
				),
			)
		);
	}

	/**
	 * Yetkilendirmesi tamamlanmış bir istemci kurar.
	 *
	 * @param array<int,Response>     $responses Yetkilendirmeden sonraki yanıtlar.
	 * @param RecordingTransport|null $transport Kullanılan taşıyıcı, geri verilir.
	 * @return Client
	 */
	private function authenticated_client( array $responses, ?RecordingTransport &$transport ): Client {
		$transport = new RecordingTransport( array_merge( $this->auth_responses(), $responses ) );

		$client = new Client( $transport, Client::TEST_BASE_URL );
		$client->authenticate( 'JETON', '1234567890', $this->certificate );

		return $client;
	}

	/**
	 * Yetkilendirmeyi başarıyla tamamlayan yanıt dizisi.
	 *
	 * @return array<int,Response>
	 */
	private function auth_responses(): array {
		return array(
			new Response(
				200,
				(string) wp_json_encode(
					array(
						'challenge' => 'C-1',
						'timestamp' => 1756900000000,
					)
				)
			),
			new Response(
				200,
				(string) wp_json_encode(
					array(
						'authenticationToken' => array( 'token' => 'AUTH-1' ),
						'referenceNumber'     => 'REF-1',
					)
				)
			),
			new Response( 200, (string) wp_json_encode( array( 'status' => array( 'code' => 200 ) ) ) ),
			new Response( 200, (string) wp_json_encode( array( 'accessToken' => array( 'token' => 'ACCESS-1' ) ) ) ),
		);
	}

	/**
	 * İsteklerin yollarını çıkarır.
	 *
	 * @param RecordingTransport $transport Taşıyıcı.
	 * @return array<int,string>
	 */
	private function paths( RecordingTransport $transport ): array {
		return array_map(
			static fn ( array $request ): string => str_replace( Client::TEST_BASE_URL, '', (string) $request['url'] ),
			$transport->requests
		);
	}
}
