<?php
/**
 * Gerçek bir FA(3) faturasını KSeF api-test ortamına gönderir.
 *
 * Bu betiğin amacı, eklentinin KENDİ sınıflarını gerçek sisteme karşı
 * sınamak: Fa3Builder'ın ürettiği belge kabul ediliyor mu, Encryption'ın
 * şifrelemesi çözülebiliyor mu, Client'ın oturum akışı doğru mu.
 *
 * Bu yüzden burada akış yeniden yazılmıyor; eklentinin sınıfları doğrudan
 * çağrılıyor. Yalnızca taşıyıcı WordPress yerine cURL kullanıyor.
 *
 * Önce bin/ksef-live-test.php çalıştırılmalı; erişim jetonunu o üretir.
 *
 * SADECE GELİŞTİRME ARACIDIR; eklenti paketine girmez.
 *
 * @package Konform
 */

declare( strict_types = 1 );

/*
 * SIRA ONEMLI. Freemius SDK'si bir 'files' otomatik yukleme girdisiyle
 * geliyor ve ABSPATH TANIMLIYSA WordPress islevlerini cagirmaya calisip
 * oluyor. Erken cikisi 'ABSPATH tanimli degil' kosuluna bagli; bu yuzden
 * otomatik yukleyici once, ABSPATH sonra. PHPUnit de tam olarak bu sirayla
 * calistigi icin testlerde sorun cikmiyor.
 */
require dirname( __DIR__ ) . '/plugin/konform/vendor/autoload.php';
require dirname( __DIR__ ) . '/plugin/konform/tests/bootstrap.php';

use Konform\Invoice\Fa3Builder;
use Konform\Invoice\Line;
use Konform\Invoice\Party;
use Konform\Invoice\Profile;
use Konform\Invoice\SemanticInvoice;
use Konform\Invoice\TaxSubtotal;
use Konform\Ksef\Client;
use Konform\Ksef\Encryption;
use Konform\Ksef\Response;
use Konform\Ksef\Transport;

/**
 * cURL tabanlı taşıyıcı.
 *
 * WordPress dışında çalıştığımız için WpTransport kullanılamıyor; arayüz aynı.
 */
final class CurlTransport implements Transport {

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method  Yöntem.
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Başlıklar.
	 * @param string|null          $body    Gövde.
	 * @param int                  $timeout Zaman aşımı.
	 * @return Response
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): Response {
		$lines = array();

		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		$curl = curl_init( $url );

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_HTTPHEADER     => $lines,
				CURLOPT_TIMEOUT        => $timeout,
			)
		);

		if ( null !== $body ) {
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $body );
		}

		$response = (string) curl_exec( $curl );
		$status   = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );

		return new Response( $status, $response );
	}
}

/**
 * Adım başlığı.
 *
 * @param string $text Başlık.
 * @return void
 */
function step( string $text ): void {
	printf( "\n=== %s ===\n", $text );
}

/**
 * Bilgi satırı.
 *
 * @param string $label Etiket.
 * @param string $value Değer.
 * @return void
 */
function info( string $label, string $value ): void {
	printf( "  %-22s %s\n", $label, $value );
}

/**
 * Test faturası kurar.
 *
 * @param string $nip Satıcının NIP'i.
 * @return SemanticInvoice
 */
function build_invoice( string $nip ): SemanticInvoice {
	return new SemanticInvoice(
		'KONFORM/' . gmdate( 'YmdHis' ),
		new DateTimeImmutable( 'today' ),
		'380',
		'PLN',
		new Party( 'Konform Test', 'PL', 'PL' . $nip, 'ul. Testowa 1', 'Warszawa', '00-001', 'sprzedawca@example.test', true ),
		new Party( 'Nabywca Testowy', 'PL', 'PL9876543210', 'ul. Rynek 1', 'Krakow', '30-001', 'nabywca@example.test', true ),
		array(
			new Line( '1', 'Lampa biurkowa', 1.0, 'szt.', 100.0, 100.0, 'S', 23.0 ),
		),
		array(
			new TaxSubtotal( 'S', 23.0, 100.0, 23.0 ),
		)
	);
}

// ---------------------------------------------------------------------------

$token_file = dirname( __DIR__ ) . '/build/ksef-access-token.txt';

if ( ! is_readable( $token_file ) ) {
	echo "Erisim jetonu yok. Once calistirin: php bin/ksef-live-test.php\n";
	exit( 1 );
}

$nip    = getenv( 'KSEF_TEST_NIP' ) ?: '5265877635';
$client = new Client( new CurlTransport(), Client::TEST_BASE_URL );

$client->use_access_token( trim( (string) file_get_contents( $token_file ) ) );

try {
	step( 'FA(3) belgesi uretiliyor' );

	$invoice = build_invoice( $nip );
	$xml     = ( new Fa3Builder() )->build_xml( $invoice, Profile::KSEF );

	info( 'numara', $invoice->number );
	info( 'boyut', strlen( $xml ) . ' bayt' );
	info( 'sema dogrulamasi', 'gecti' );

	step( 'Simetrik anahtar sertifikasi aliniyor' );

	$certificate = $client->public_key_certificate( Client::USAGE_SYMMETRIC_KEY );

	$details = openssl_pkey_get_details( openssl_pkey_get_public( $certificate ) );

	info( 'anahtar', $details['bits'] . ' bit RSA' );

	step( 'Oturum aciliyor' );

	$key = Encryption::generate_key();
	$iv  = Encryption::generate_iv();

	$session = $client->open_session( Encryption::wrap_key( $key, $certificate ), $iv );

	info( 'oturum', $session );

	step( 'Fatura gonderiliyor' );

	$reference = $client->send_invoice( $session, $xml, $key, $iv );

	info( 'fatura referansi', $reference );

	step( 'Islenme durumu bekleniyor' );

	$ksef_number = '';

	for ( $attempt = 0; $attempt < 20; $attempt++ ) {
		$status = $client->invoice_status( $session, $reference );

		$code = (int) ( $status['status']['code'] ?? 0 );

		info( 'durum', sprintf( '%d %s', $code, (string) ( $status['status']['description'] ?? '' ) ) );

		if ( isset( $status['ksefNumber'] ) ) {
			$ksef_number = (string) $status['ksefNumber'];
			break;
		}

		if ( $code >= 400 ) {
			echo "\nKSeF faturayi reddetti:\n";
			print_r( $status );
			exit( 1 );
		}

		sleep( 3 );
	}

	step( 'Oturum kapatiliyor' );

	$client->close_session( $session );

	if ( '' !== $ksef_number ) {
		printf( "\nBASARILI. KSeF numarasi: %s\n", $ksef_number );
		exit( 0 );
	}

	echo "\nKSeF numarasi zaman asiminda alinamadi.\n";
	exit( 1 );
} catch ( \Throwable $error ) {
	printf( "\nHATA: %s\n", $error->getMessage() );
	exit( 1 );
}
