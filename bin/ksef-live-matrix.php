<?php
/**
 * Vergi kategorisi eşlemesini gerçek KSeF'e karşı sınar.
 *
 * NEDEN
 *
 * FA(3)'te %0 tek bir alan değildir: yurt içi, AB içi teslim ve ihracat ayrı
 * alanlardır; muafiyet ve tersine yük de ayrıdır. Yanlış alana yazmak yerel
 * şema denetiminden GEÇER ama beyanı bozar. Yani bu eşlemenin doğruluğunu
 * yalnızca KSeF'in kendisi söyleyebilir.
 *
 * Bu betik her kategori için bir fatura üretip gönderir ve hangisinin kabul
 * edildiğini, hangisinin reddedildiğini raporlar.
 *
 * Önce bin/ksef-live-test.php çalıştırılmalı; erişim jetonunu o üretir.
 *
 * SADECE GELİŞTİRME ARACIDIR; eklenti paketine girmez.
 *
 * @package Konform
 */

declare( strict_types = 1 );

/*
 * SIRA ONEMLI: otomatik yukleyici once, ABSPATH sonra. Freemius SDK'si
 * ABSPATH tanimliysa WordPress islevlerini cagirmaya calisir.
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
 * cURL taşıyıcısı.
 */
final class MatrixTransport implements Transport {

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

		return new Response( (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE ), $response );
	}
}

/**
 * Senaryo için fatura kurar.
 *
 * @param string $nip      Satıcı NIP'i.
 * @param string $category Vergi kategorisi.
 * @param float  $rate     Oran.
 * @param string $country  Alıcı ülkesi.
 * @param string $reason   Muafiyet gerekçesi.
 * @return SemanticInvoice
 */
function scenario_invoice( string $nip, string $category, float $rate, string $country, string $reason = '' ): SemanticInvoice {
	$tax = round( 100.0 * $rate / 100, 2 );

	$buyer_vat = 'PL' === $country ? 'PL9876543210' : $country . '123456789';

	return new SemanticInvoice(
		sprintf( 'KONFORM/%s/%s', gmdate( 'YmdHis' ), $category ),
		new DateTimeImmutable( 'today' ),
		'380',
		'PLN',
		new Party( 'Konform Test', 'PL', 'PL' . $nip, 'ul. Testowa 1', 'Warszawa', '00-001', 'sprzedawca@example.test', true ),
		new Party( 'Nabywca ' . $country, $country, $buyer_vat, 'ul. Rynek 1', 'Miasto', '30-001', 'nabywca@example.test', true ),
		array( new Line( '1', 'Lampa biurkowa', 1.0, 'szt.', 100.0, 100.0, $category, $rate ) ),
		array( new TaxSubtotal( $category, $rate, 100.0, $tax, $reason ) )
	);
}

// ---------------------------------------------------------------------------

$token_file = dirname( __DIR__ ) . '/build/ksef-access-token.txt';

if ( ! is_readable( $token_file ) ) {
	echo "Erisim jetonu yok. Once: php bin/ksef-live-test.php\n";
	exit( 1 );
}

$nip    = getenv( 'KSEF_TEST_NIP' ) ?: '5265877635';
$client = new Client( new MatrixTransport(), Client::TEST_BASE_URL );

$client->use_access_token( trim( (string) file_get_contents( $token_file ) ) );

/*
 * Senaryolar. Her biri FA(3)'te FARKLI bir alana yazilmali; amac hangisinin
 * KSeF tarafindan kabul edildigini gormek.
 */
$scenarios = array(
	array( 'S', 23.0, 'PL', '', 'yurt ici %23 (P_13_1/P_14_1)' ),
	array( 'S', 8.0, 'PL', '', 'yurt ici %8 (P_13_2/P_14_2)' ),
	array( 'S', 5.0, 'PL', '', 'yurt ici %5 (P_13_3/P_14_3)' ),
	array( 'K', 0.0, 'DE', '', 'AB ici teslim / WDT (P_13_6_2)' ),
	array( 'G', 0.0, 'US', '', 'ihracat (P_13_6_3)' ),
	array( 'AE', 0.0, 'DE', '', 'tersine yuk (P_13_10)' ),
	array( 'O', 0.0, 'US', '', 'yurt disi hizmet (P_13_8)' ),
	array( 'E', 0.0, 'PL', 'Zwolnienie na podstawie art. 43 ust. 1 ustawy o VAT', 'muafiyet (P_13_7 + P_19C)' ),
);

$builder = new Fa3Builder();
$results = array();

echo "=== Belgeler uretiliyor ===\n";

$documents = array();

foreach ( $scenarios as $scenario ) {
	list( $category, $rate, $country, $reason, $label ) = $scenario;

	try {
		$invoice = scenario_invoice( $nip, $category, $rate, $country, $reason );
		$xml     = $builder->build_xml( $invoice, Profile::KSEF );

		$documents[] = array(
			'label'  => $label,
			'number' => $invoice->number,
			'xml'    => $xml,
		);

		printf( "  %-34s uretildi (%d bayt)\n", $label, strlen( $xml ) );
	} catch ( \Throwable $error ) {
		$results[ $label ] = 'URETILEMEDI: ' . $error->getMessage();

		printf( "  %-34s URETILEMEDI: %s\n", $label, $error->getMessage() );
	}
}

if ( array() === $documents ) {
	echo "\nGonderilecek belge yok.\n";
	exit( 1 );
}

echo "\n=== Oturum aciliyor ===\n";

$key = Encryption::generate_key();
$iv  = Encryption::generate_iv();

$session = $client->open_session(
	Encryption::wrap_key( $key, $client->public_key_certificate( Client::USAGE_SYMMETRIC_KEY ) ),
	$iv
);

printf( "  oturum: %s\n", $session );

echo "\n=== Gonderiliyor ===\n";

foreach ( $documents as $index => $document ) {
	try {
		$documents[ $index ]['reference'] = $client->send_invoice( $session, $document['xml'], $key, $iv );

		printf( "  %-34s kabul edildi\n", $document['label'] );
	} catch ( \Throwable $error ) {
		$results[ $document['label'] ] = 'REDDEDILDI: ' . $error->getMessage();

		printf( "  %-34s REDDEDILDI\n", $document['label'] );
	}
}

echo "\n=== Numaralar bekleniyor ===\n";

sleep( 6 );

foreach ( $documents as $document ) {
	if ( ! isset( $document['reference'] ) ) {
		continue;
	}

	try {
		$status = $client->invoice_status( $session, $document['reference'] );

		$number = (string) ( $status['ksefNumber'] ?? '' );

		$results[ $document['label'] ] = '' !== $number
			? 'TESCIL EDILDI: ' . $number
			: 'BEKLIYOR: ' . (string) ( $status['status']['description'] ?? '?' );
	} catch ( \Throwable $error ) {
		$results[ $document['label'] ] = 'SORGULANAMADI: ' . $error->getMessage();
	}
}

$client->close_session( $session );

echo "\n=== SONUC ===\n";

foreach ( $scenarios as $scenario ) {
	$label = $scenario[4];

	printf( "  %-34s %s\n", $label, $results[ $label ] ?? '?' );
}
