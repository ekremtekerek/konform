<?php
/**
 * Bagimlilik duman testi - WordPress gerektirmez.
 * Calistir: docker compose run --rm composer php tests/smoke.php
 *
 * @package Konform
 */

declare( strict_types = 1 );

require __DIR__ . '/../vendor/autoload.php';

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;

$doc = ZugferdDocumentBuilder::createNew( ZugferdProfiles::PROFILE_EN16931 );

$doc->setDocumentInformation(
	'KNF-2026-0001',
	'380',
	\DateTime::createFromFormat( 'Ymd', '20260831' ),
	'EUR'
);

$doc->setDocumentSeller( 'Cisoft', 'FR-SELLER-1' );
$doc->setDocumentBuyer( 'Acheteur SARL', 'FR-BUYER-1' );

$xml = $doc->getContent();

printf( "profil    : EN 16931%s", PHP_EOL );
printf( "xml uzunlugu : %d bayt%s", strlen( $xml ), PHP_EOL );
printf( "kok eleman   : %s%s", ( new SimpleXMLElement( $xml ) )->getName(), PHP_EOL );
printf( "para birimi  : %s%s", str_contains( $xml, 'EUR' ) ? 'EUR bulundu' : 'EKSIK', PHP_EOL );
echo str_contains( $xml, 'KNF-2026-0001' ) ? "SONUC: gecti\n" : "SONUC: KALDI\n";
