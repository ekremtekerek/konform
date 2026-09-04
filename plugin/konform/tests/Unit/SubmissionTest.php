<?php
/**
 * KSeF gönderim ve ayar testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Ksef\Client;
use Konform\Ksef\Response;
use Konform\Ksef\Settings;
use Konform\Ksef\Submission;
use Konform\Storage\Document;
use Konform\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Gönderimin veritabanına dokunmayan kararlarını sınar.
 *
 * Arşive yazma ve denetim kaydı gerçek bir WordPress kurulumu ister; burada
 * sınanan şey ondan öncesi: gönderim yapılmalı mı, hangi ayarla, ve
 * yapılmaması gereken durumda gerçekten ağa çıkılmıyor mu.
 */
final class SubmissionTest extends TestCase {

	/**
	 * Her testten önce seçenekleri temizler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['konform_test_options'] = array();

		/*
		 * Gercek bir RSA sertifikasi uretiliyor: authenticate() jetonu bu
		 * sertifikayla sifreliyor ve sahte bir dize orada okunamaz.
		 */
		$resource = \openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		$csr  = \openssl_csr_new( array( 'commonName' => 'Konform Test' ), $resource, array( 'digest_alg' => 'sha256' ) );
		$x509 = \openssl_csr_sign( $csr, null, $resource, 1, array( 'digest_alg' => 'sha256' ) );

		\openssl_x509_export( $x509, $this->certificate );
	}

	/**
	 * Test sertifikası (PEM).
	 *
	 * @var string
	 */
	private string $certificate = '';

	/**
	 * Varsayılan ortam testtir.
	 *
	 * Uretim ortamina gonderilen her fatura hukuki sonuc dogurur. Varsayilanin
	 * uretim olmasi, yanlislikla gercek fatura kesilmesi demek olurdu.
	 *
	 * @return void
	 */
	public function test_the_default_environment_is_test(): void {
		$this->assertSame( Settings::ENVIRONMENT_TEST, Settings::environment() );
		$this->assertFalse( Settings::is_production() );
		$this->assertSame( Client::TEST_BASE_URL, Settings::base_url() );
	}

	/**
	 * Üretim ortamı açıkça seçilir.
	 *
	 * @return void
	 */
	public function test_production_must_be_chosen_explicitly(): void {
		update_option( Settings::OPTION_ENVIRONMENT, Settings::ENVIRONMENT_PRODUCTION );

		$this->assertTrue( Settings::is_production() );
		$this->assertSame( Client::PRODUCTION_BASE_URL, Settings::base_url() );
	}

	/**
	 * Tanınmayan bir ortam değeri üretime düşmez.
	 *
	 * @return void
	 */
	public function test_an_unknown_environment_falls_back_to_test(): void {
		update_option( Settings::OPTION_ENVIRONMENT, 'canli-gibi-bir-sey' );

		$this->assertFalse( Settings::is_production() );
	}

	/**
	 * Jeton kaydedilir ve boş dize onu siler.
	 *
	 * @return void
	 */
	public function test_the_token_round_trips_and_can_be_cleared(): void {
		$this->assertFalse( Settings::has_token() );

		Settings::set_token( '  JETON-1  ' );

		$this->assertTrue( Settings::has_token() );
		$this->assertSame( 'JETON-1', Settings::token() );

		Settings::set_token( '' );

		$this->assertFalse( Settings::has_token() );
	}

	/**
	 * Numarası olan belge tekrar gönderilmez.
	 *
	 * KSeF'te ayni faturanin iki kaydi olmasi, duzeltilmesi zor bir hatadir.
	 * Bu test onu tutuyor: tasiyiciya HIC istek gitmemeli.
	 *
	 * @return void
	 */
	public function test_an_already_registered_document_is_not_sent_again(): void {
		Settings::set_token( 'JETON' );

		$transport  = new RecordingTransport( array() );
		$submission = new Submission( new Client( $transport, Client::TEST_BASE_URL ) );

		$document = $this->document( '5265877635-20260904-3A0E71000000-0A' );

		$this->assertSame(
			'5265877635-20260904-3A0E71000000-0A',
			$submission->submit( $document, '<Faktura/>', '5265877635' )
		);

		$this->assertSame( array(), $transport->requests, 'Ağa çıkılmamalıydı.' );
	}

	/**
	 * Jeton yoksa ağa çıkılmaz.
	 *
	 * @return void
	 */
	public function test_without_a_token_nothing_is_sent(): void {
		$transport  = new RecordingTransport( array() );
		$submission = new Submission( new Client( $transport, Client::TEST_BASE_URL ) );

		try {
			$submission->submit( $this->document(), '<Faktura/>', '5265877635' );

			$this->fail( 'Jetonsuz gönderim istisna atmalıydı.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'token', $error->getMessage() );
		}

		$this->assertSame( array(), $transport->requests, 'Ağa çıkılmamalıydı.' );
	}

	/**
	 * Gönderilmiş belge tekrar gönderilmez, yalnızca sorgulanır.
	 *
	 * Bu testin tuttugu sey mukerrer fatura. KSeF faturayi once kabul eder,
	 * numarayi sonra atar; arada surec koparsa yeniden deneme faturayi TEKRAR
	 * gondermemeli. Referans arsivde durdugu icin sorgulamakla yetiniliyor.
	 *
	 * @return void
	 */
	public function test_a_submitted_document_is_queried_not_resent(): void {
		Settings::set_token( 'JETON' );

		$transport = new RecordingTransport(
			array_merge(
				$this->authentication_responses(),
				// Numara henuz yok: "isleniyor".
				array( new Response( 200, (string) wp_json_encode( array( 'status' => array( 'code' => 150 ) ) ) ) )
			)
		);

		$submission = new Submission( new Client( $transport, Client::TEST_BASE_URL ) );

		$number = $submission->submit( $this->submitted_document(), '<Faktura/>', '5265877635' );

		$this->assertSame( '', $number, 'Numara yokken bos donmeliydi.' );

		$methods = array();
		$paths   = array();

		foreach ( $transport->requests as $request ) {
			$methods[] = $request['method'];
			$paths[]   = str_replace( Client::TEST_BASE_URL, '', (string) $request['url'] );
		}

		/*
		 * /sessions/online hem oturum acmanin hem fatura gondermenin yoludur.
		 * Hicbir istegin oraya gitmemesi, gonderim yapilmadiginin kanitidir.
		 */
		foreach ( $paths as $path ) {
			$this->assertStringNotContainsString( '/sessions/online', $path );
		}

		$this->assertContains( '/sessions/SESSION-1/invoices/REF-1', $paths );

		// Sorgulama GET'tir; gonderim POST olurdu.
		$this->assertSame( 'GET', $methods[ count( $methods ) - 1 ] );
	}

	/**
	 * Hiç gönderilmemiş belge sorgulanamaz.
	 *
	 * @return void
	 */
	public function test_resuming_an_unsent_document_is_refused(): void {
		Settings::set_token( 'JETON' );

		$transport  = new RecordingTransport( array() );
		$submission = new Submission( new Client( $transport, Client::TEST_BASE_URL ) );

		$this->expectException( \RuntimeException::class );

		$submission->resume( $this->document(), '5265877635' );
	}

	/**
	 * Gönderilmiş belge gönderilmiş sayılır.
	 *
	 * @return void
	 */
	public function test_submission_is_decided_by_the_reference(): void {
		$this->assertFalse( $this->document()->is_submitted() );
		$this->assertTrue( $this->submitted_document()->is_submitted() );
	}

	/**
	 * Numarası olan belge tescilli sayılır.
	 *
	 * @return void
	 */
	public function test_registration_is_decided_by_the_ksef_number(): void {
		$this->assertFalse( $this->document()->is_registered() );
		$this->assertTrue( $this->document( 'KSEF-1' )->is_registered() );
	}

	/**
	 * Sertifikanın base64 gövdesi.
	 *
	 * KSeF sertifikayi PEM basliklari olmadan, ham base64 olarak dondurur;
	 * Client onu PEM'e cevirir. Test de ayni bicimi vermeli.
	 *
	 * @return string
	 */
	private function der(): string {
		return (string) preg_replace( '/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $this->certificate );
	}

	/**
	 * Gönderilmiş ama numarasız belge.
	 *
	 * @return Document
	 */
	private function submitted_document(): Document {
		return new Document(
			1,
			42,
			'FA/2026/1',
			'ksef',
			'xml',
			'pl_PL',
			'konform/2026/09/fa-2026-1.xml',
			str_repeat( 'a', 64 ),
			1339,
			1,
			'2026-09-04 06:00:00',
			0,
			'',
			'SESSION-1',
			'REF-1'
		);
	}

	/**
	 * Sertifika ve kimlik doğrulama yanıtları.
	 *
	 * @return array<int,Response>
	 */
	private function authentication_responses(): array {
		return array(
			new Response(
				200,
				(string) wp_json_encode(
					array(
						array(
							'usage'       => array( Client::USAGE_TOKEN ),
							'certificate' => $this->der(),
						),
					)
				)
			),
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
						'referenceNumber'     => 'AREF-1',
					)
				)
			),
			new Response( 200, (string) wp_json_encode( array( 'status' => array( 'code' => 200 ) ) ) ),
			new Response( 200, (string) wp_json_encode( array( 'accessToken' => array( 'token' => 'ACCESS-1' ) ) ) ),
		);
	}

	/**
	 * Test belgesi.
	 *
	 * @param string $ksef_number KSeF numarası.
	 * @return Document
	 */
	private function document( string $ksef_number = '' ): Document {
		return new Document(
			1,
			42,
			'FA/2026/1',
			'ksef',
			'xml',
			'pl_PL',
			'konform/2026/09/fa-2026-1.xml',
			str_repeat( 'a', 64 ),
			1339,
			1,
			'2026-09-04 06:00:00',
			0,
			$ksef_number
		);
	}
}
