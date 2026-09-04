<?php
/**
 * KSeF api-test ortamına karşı canlı doğrulama koşusu.
 *
 * NEDEN BU BETİK VAR
 *
 * Eklentinin KSeF istemcisi belgelerden yazıldı ve belgeler her şeyi
 * söylemiyor. Gerçekten çalışıp çalışmadığını ancak KSeF'in kendisi söyler.
 *
 * Sorun şu: gönderim yapmak için kimlik doğrulaması gerekiyor, o da bir KSeF
 * jetonu istiyor, jeton üretmek de bir kez XAdES imzalı doğrulama gerektiriyor.
 * Test ortamı bunun için KENDİ İMZALI sertifikaya izin veriyor.
 *
 * Bu betik o kapıyı açar: kendi imzalı bir sertifika üretir, AuthTokenRequest
 * belgesini XAdES ile imzalar, erişim jetonu alır ve gerçek bir FA(3)
 * faturasını gönderir.
 *
 * SADECE GELİŞTİRME ARACIDIR. Eklenti paketine girmez; gerçek kullanıcılar
 * XAdES kullanmaz, KSeF jetonunu bir kez kendileri üretip yapıştırır.
 *
 * Çalıştır:
 *   docker compose run --rm -T composer php /repo/bin/ksef-live-test.php
 *
 * @package Konform
 */

declare( strict_types = 1 );

const BASE_URL = 'https://api-test.ksef.mf.gov.pl/v2';

const NS_AUTH  = 'http://ksef.mf.gov.pl/auth/token/2.1';
const NS_DS    = 'http://www.w3.org/2000/09/xmldsig#';
const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

const ALGO_C14N      = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
const ALGO_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
const ALGO_DIGEST    = 'http://www.w3.org/2001/04/xmlenc#sha256';
const ALGO_SIGNATURE = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

/**
 * Adım başlığı yazar.
 *
 * @param string $text Başlık.
 * @return void
 */
function step( string $text ): void {
	printf( "\n=== %s ===\n", $text );
}

/**
 * Bilgi satırı yazar.
 *
 * @param string $label Etiket.
 * @param string $value Değer.
 * @return void
 */
function info( string $label, string $value ): void {
	printf( "  %-22s %s\n", $label, $value );
}

/**
 * HTTP isteği yapar.
 *
 * @param string      $method  Yöntem.
 * @param string      $path    Yol.
 * @param string|null $body    Gövde.
 * @param string      $type    İçerik türü.
 * @param string      $token   Yetkilendirme jetonu.
 * @return array{status:int,body:string}
 */
function request( string $method, string $path, ?string $body = null, string $type = 'application/json', string $token = '' ): array {
	$headers = array( 'Accept: application/json' );

	if ( null !== $body ) {
		$headers[] = 'Content-Type: ' . $type;
	}

	if ( '' !== $token ) {
		$headers[] = 'Authorization: Bearer ' . $token;
	}

	$curl = curl_init( BASE_URL . $path );

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 40,
		)
	);

	if ( null !== $body ) {
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $body );
	}

	$response = (string) curl_exec( $curl );
	$status   = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );


	return array(
		'status' => $status,
		'body'   => $response,
	);
}

/**
 * Yanıtı çözer, hata varsa durur.
 *
 * @param array{status:int,body:string} $response Yanıt.
 * @param string                        $what     Ne yapılıyordu.
 * @return array<string,mixed>
 */
function decode( array $response, string $what ): array {
	if ( $response['status'] < 200 || $response['status'] >= 300 ) {
		printf( "\nHATA: %s basarisiz (HTTP %d)\n%s\n", $what, $response['status'], substr( $response['body'], 0, 1200 ) );
		exit( 1 );
	}

	$decoded = json_decode( $response['body'], true );

	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Kendi imzalı test sertifikası üretir.
 *
 * KSeF, SubjectIdentifierType=certificateSubject oldugunda NIP'i sertifikanin
 * konusundan okur. Kurumsal muhur sertifikasinda bu, 2.5.4.97
 * (organizationIdentifier) alanina "VATPL-<NIP>" olarak yazilir; deger bicimi
 * Bakanlik'in kendi .NET istemcisinin testlerinden alindi.
 *
 * @param string $nip NIP.
 * @return array{cert:string,key:\OpenSSLAsymmetricKey}
 */
function build_certificate( string $nip ): array {
	$key = openssl_pkey_new(
		array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		)
	);

	$dn = array(
		'countryName'            => 'PL',
		'organizationName'       => 'Konform Test',
		'organizationIdentifier' => 'VATPL-' . $nip,
		'commonName'             => 'Konform Test Seal',
	);

	$csr  = openssl_csr_new( $dn, $key, array( 'digest_alg' => 'sha256' ) );
	$x509 = openssl_csr_sign( $csr, null, $key, 2, array( 'digest_alg' => 'sha256' ) );

	openssl_x509_export( $x509, $pem );

	return array(
		'cert' => $pem,
		'key'  => $key,
	);
}

/**
 * PEM sertifikayı ham DER'e çevirir.
 *
 * @param string $pem PEM.
 * @return string
 */
function der( string $pem ): string {
	$body = preg_replace( '/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem );

	return (string) base64_decode( (string) $body, true );
}

/**
 * Konu/veren adını RFC 2253 sırasına göre dizeye çevirir.
 *
 * @param array<string,mixed> $name Ad bileşenleri.
 * @return string
 */
function distinguished_name( array $name ): string {
	$parts = array();

	foreach ( array_reverse( $name ) as $key => $value ) {
		$parts[] = $key . '=' . ( is_array( $value ) ? implode( '+', $value ) : $value );
	}

	return implode( ',', $parts );
}

/**
 * AuthTokenRequest belgesini kurar ve XAdES ile imzalar.
 *
 * @param string                 $challenge Meydan okuma.
 * @param string                 $nip       NIP.
 * @param string                 $pem       Sertifika.
 * @param \OpenSSLAsymmetricKey  $key       Özel anahtar.
 * @return string İmzalı XML.
 */
function signed_request( string $challenge, string $nip, string $pem, \OpenSSLAsymmetricKey $key ): string {
	$document                     = new DOMDocument( '1.0', 'UTF-8' );
	$document->preserveWhiteSpace = false;

	$root = $document->createElementNS( NS_AUTH, 'AuthTokenRequest' );
	$document->appendChild( $root );

	$root->appendChild( $document->createElementNS( NS_AUTH, 'Challenge', $challenge ) );

	$context = $document->createElementNS( NS_AUTH, 'ContextIdentifier' );
	$context->appendChild( $document->createElementNS( NS_AUTH, 'Nip', $nip ) );
	$root->appendChild( $context );

	$root->appendChild( $document->createElementNS( NS_AUTH, 'SubjectIdentifierType', 'certificateSubject' ) );

	// --- Imza iskeleti ---------------------------------------------------

	$signature = $document->createElementNS( NS_DS, 'ds:Signature' );
	$signature->setAttribute( 'Id', 'Signature' );
	$root->appendChild( $signature );

	$signed_info = $document->createElementNS( NS_DS, 'ds:SignedInfo' );
	$signature->appendChild( $signed_info );

	$c14n_method = $document->createElementNS( NS_DS, 'ds:CanonicalizationMethod' );
	$c14n_method->setAttribute( 'Algorithm', ALGO_C14N );
	$signed_info->appendChild( $c14n_method );

	$signature_method = $document->createElementNS( NS_DS, 'ds:SignatureMethod' );
	$signature_method->setAttribute( 'Algorithm', ALGO_SIGNATURE );
	$signed_info->appendChild( $signature_method );

	// Belgenin kendisine referans; enveloped donusumu imzayi disarida birakir.
	$document_reference = $document->createElementNS( NS_DS, 'ds:Reference' );
	$document_reference->setAttribute( 'URI', '' );
	$signed_info->appendChild( $document_reference );

	$transforms = $document->createElementNS( NS_DS, 'ds:Transforms' );
	$document_reference->appendChild( $transforms );

	foreach ( array( ALGO_ENVELOPED, ALGO_C14N ) as $algorithm ) {
		$transform = $document->createElementNS( NS_DS, 'ds:Transform' );
		$transform->setAttribute( 'Algorithm', $algorithm );
		$transforms->appendChild( $transform );
	}

	$digest_method = $document->createElementNS( NS_DS, 'ds:DigestMethod' );
	$digest_method->setAttribute( 'Algorithm', ALGO_DIGEST );
	$document_reference->appendChild( $digest_method );

	$document_digest = $document->createElementNS( NS_DS, 'ds:DigestValue' );
	$document_reference->appendChild( $document_digest );

	// SignedProperties'e referans.
	$properties_reference = $document->createElementNS( NS_DS, 'ds:Reference' );
	$properties_reference->setAttribute( 'URI', '#SignedProperties' );
	$properties_reference->setAttribute( 'Type', 'http://uri.etsi.org/01903#SignedProperties' );
	$signed_info->appendChild( $properties_reference );

	$properties_digest_method = $document->createElementNS( NS_DS, 'ds:DigestMethod' );
	$properties_digest_method->setAttribute( 'Algorithm', ALGO_DIGEST );
	$properties_reference->appendChild( $properties_digest_method );

	$properties_digest = $document->createElementNS( NS_DS, 'ds:DigestValue' );
	$properties_reference->appendChild( $properties_digest );

	$signature_value = $document->createElementNS( NS_DS, 'ds:SignatureValue' );
	$signature->appendChild( $signature_value );

	$key_info = $document->createElementNS( NS_DS, 'ds:KeyInfo' );
	$x509_data = $document->createElementNS( NS_DS, 'ds:X509Data' );
	$x509_data->appendChild(
		$document->createElementNS( NS_DS, 'ds:X509Certificate', base64_encode( der( $pem ) ) )
	);
	$key_info->appendChild( $x509_data );
	$signature->appendChild( $key_info );

	// --- XAdES nitelikli ozellikleri -------------------------------------

	$parsed = openssl_x509_parse( $pem );

	$object = $document->createElementNS( NS_DS, 'ds:Object' );
	$signature->appendChild( $object );

	$qualifying = $document->createElementNS( NS_XADES, 'xades:QualifyingProperties' );
	$qualifying->setAttribute( 'Target', '#Signature' );
	$object->appendChild( $qualifying );

	$signed_properties = $document->createElementNS( NS_XADES, 'xades:SignedProperties' );
	$signed_properties->setAttribute( 'Id', 'SignedProperties' );
	$qualifying->appendChild( $signed_properties );

	$signature_properties = $document->createElementNS( NS_XADES, 'xades:SignedSignatureProperties' );
	$signed_properties->appendChild( $signature_properties );

	$signature_properties->appendChild(
		$document->createElementNS( NS_XADES, 'xades:SigningTime', gmdate( 'Y-m-d\TH:i:s\Z' ) )
	);

	$signing_certificate = $document->createElementNS( NS_XADES, 'xades:SigningCertificate' );
	$signature_properties->appendChild( $signing_certificate );

	$cert = $document->createElementNS( NS_XADES, 'xades:Cert' );
	$signing_certificate->appendChild( $cert );

	$cert_digest = $document->createElementNS( NS_XADES, 'xades:CertDigest' );
	$cert->appendChild( $cert_digest );

	$cert_digest_method = $document->createElementNS( NS_DS, 'ds:DigestMethod' );
	$cert_digest_method->setAttribute( 'Algorithm', ALGO_DIGEST );
	$cert_digest->appendChild( $cert_digest_method );

	$cert_digest->appendChild(
		$document->createElementNS( NS_DS, 'ds:DigestValue', base64_encode( hash( 'sha256', der( $pem ), true ) ) )
	);

	$issuer_serial = $document->createElementNS( NS_XADES, 'xades:IssuerSerial' );
	$cert->appendChild( $issuer_serial );

	$issuer_serial->appendChild(
		$document->createElementNS( NS_DS, 'ds:X509IssuerName', distinguished_name( $parsed['issuer'] ) )
	);
	$issuer_serial->appendChild(
		$document->createElementNS( NS_DS, 'ds:X509SerialNumber', (string) ( $parsed['serialNumber'] ?? '0' ) )
	);

	// --- Ozetler ve imza -------------------------------------------------

	/*
	 * Belge ozeti, imza cikarilmis halin kanonik bicimi uzerinden alinir;
	 * enveloped donusumunun anlami budur.
	 */
	$without_signature = clone $document;
	$stripped          = $without_signature->getElementsByTagNameNS( NS_DS, 'Signature' )->item( 0 );
	$stripped->parentNode->removeChild( $stripped );

	$document_digest->appendChild(
		$document->createTextNode( base64_encode( hash( 'sha256', (string) $without_signature->C14N(), true ) ) )
	);

	$properties_digest->appendChild(
		$document->createTextNode( base64_encode( hash( 'sha256', (string) $signed_properties->C14N(), true ) ) )
	);

	openssl_sign( (string) $signed_info->C14N(), $raw, $key, OPENSSL_ALGO_SHA256 );

	$signature_value->appendChild( $document->createTextNode( base64_encode( $raw ) ) );

	return (string) $document->saveXML();
}

// ---------------------------------------------------------------------------

$nip = getenv( 'KSEF_TEST_NIP' ) ?: '5265877635';

step( 'Test sertifikasi uretiliyor' );

$identity = build_certificate( $nip );
$parsed   = openssl_x509_parse( $identity['cert'] );

info( 'NIP', $nip );
info( 'konu', distinguished_name( $parsed['subject'] ) );

step( 'Meydan okuma isteniyor' );

$challenge = decode(
	request( 'POST', '/auth/challenge', (string) json_encode( array( 'contextIdentifier' => array( 'type' => 'Nip', 'value' => $nip ) ) ) ),
	'challenge'
);

info( 'challenge', (string) ( $challenge['challenge'] ?? '?' ) );
info( 'timestamp', (string) ( $challenge['timestamp'] ?? '?' ) );

step( 'AuthTokenRequest imzalaniyor (XAdES)' );

$xml = signed_request( (string) $challenge['challenge'], $nip, $identity['cert'], $identity['key'] );

file_put_contents( '/repo/build/auth-request.xml', $xml );

info( 'boyut', strlen( $xml ) . ' bayt' );

step( 'Imzali belge gonderiliyor' );

$submission = request( 'POST', '/auth/xades-signature', $xml, 'application/xml' );

printf( "  HTTP %d\n", $submission['status'] );

if ( $submission['status'] < 200 || $submission['status'] >= 300 ) {
	printf( "\nYANIT:\n%s\n", substr( $submission['body'], 0, 1500 ) );
	exit( 1 );
}

$submitted = decode( $submission, 'xades-signature' );

$reference           = (string) $submitted['referenceNumber'];
$authentication      = (string) $submitted['authenticationToken']['token'];

info( 'referans', $reference );

step( 'Dogrulamanin tamamlanmasi bekleniyor' );

$code = 0;

for ( $attempt = 0; $attempt < 20; $attempt++ ) {
	$status = decode( request( 'GET', '/auth/' . rawurlencode( $reference ), null, 'application/json', $authentication ), 'auth status' );

	$code = (int) ( $status['status']['code'] ?? 0 );

	info( 'durum', sprintf( '%d %s', $code, (string) ( $status['status']['description'] ?? '' ) ) );

	if ( 200 === $code || $code >= 400 ) {
		break;
	}

	sleep( 2 );
}

if ( 200 !== $code ) {
	echo "\nDogrulama tamamlanmadi.\n";
	exit( 1 );
}

step( 'Erisim jetonu aliniyor' );

$redeemed = decode(
	request( 'POST', '/auth/token/redeem', null, 'application/json', $authentication ),
	'token redeem'
);

$access = (string) ( $redeemed['accessToken']['token'] ?? $redeemed['accessToken'] ?? '' );

if ( '' === $access ) {
	echo "\nErisim jetonu alinamadi:\n";
	print_r( $redeemed );
	exit( 1 );
}

info( 'accessToken', substr( $access, 0, 40 ) . '...' );
info( 'uzunluk', strlen( $access ) . ' karakter' );

file_put_contents( '/repo/build/ksef-access-token.txt', $access );

echo "\nErisim jetonu /repo/build/ksef-access-token.txt dosyasina yazildi.\n";
echo "Oturum ve gonderim sinavi icin: php /repo/bin/ksef-live-send.php\n";
