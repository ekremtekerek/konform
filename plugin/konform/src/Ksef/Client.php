<?php
/**
 * KSeF 2.0 API istemcisi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Ksef;

defined( 'ABSPATH' ) || exit;

/**
 * KSeF 2.0 REST API'siyle konuşur.
 *
 * AKIŞ
 *
 * Yetkilendirme üç adımdır ve tek bir çağrı değildir:
 *
 * 1. `POST /auth/challenge` — bir meydan okuma ve zaman damgası alınır
 *    (10 dakika geçerli).
 * 2. `POST /auth/ksef-token` — `"{token}|{timestampMs}"` dizgesi Bakanlık'ın
 *    açık anahtarıyla RSA-OAEP/SHA-256 ile şifrelenip gönderilir.
 * 3. `POST /auth/token/redeem` — işlem bitince erişim jetonu (JWT) alınır.
 *
 * Gönderim ise şifreli bir oturum üzerinden yapılır: AES anahtarı oturum
 * açılırken sarmalanmış olarak verilir, fatura o anahtarla şifrelenip
 * gönderilir. Bkz. Encryption.
 *
 * NELER DOĞRULANDI, NELER DOĞRULANMADI
 *
 * Uç noktalar, alan adları ve şifreleme gereksinimleri Bakanlık'ın kendi
 * belgelerinden (CIRFMF/ksef-api) alındı; taban adres OpenAPI tanımının
 * `servers` bloğundan geliyor. Bunların hiçbiri **canlı olarak** sınanmadı:
 * `api-test` ortamına bağlanmak bir KSeF test jetonu gerektiriyor ve o jeton
 * mağaza sahibinin hesabından alınır.
 *
 * Yanıt gövdelerinin iç içe yapısı belgelerde her yerde açık değil; bu yüzden
 * jeton okuyan yerler hem düz dizge hem `{ "token": ... }` biçimini kabul
 * ediyor. Canlı sınavdan sonra hangisi doğruysa o bırakılmalı — ikisini birden
 * kalıcı olarak taşımak, bilmemenin kodda kalıcılaşması olur.
 */
final class Client {

	/**
	 * Test ortamının taban adresi.
	 *
	 * OpenAPI tanimindaki `servers` blogundan. Yollarin `/api/v2` ile
	 * basladigi yolundaki ikincil kaynaklar bununla celisiyordu; tanim esas
	 * alindi.
	 */
	public const TEST_BASE_URL = 'https://api-test.ksef.mf.gov.pl/v2';

	/**
	 * Üretim ortamının taban adresi.
	 */
	public const PRODUCTION_BASE_URL = 'https://api.ksef.mf.gov.pl/v2';

	/**
	 * Etkileşimli istekler için zaman aşımı (saniye).
	 */
	private const TIMEOUT = 30;

	/**
	 * Durum sorgulamaları arasındaki bekleme (saniye).
	 */
	private const POLL_PAUSE = 2;

	/**
	 * Bir durumun tamamlanması için en fazla kaç sorgu yapılır.
	 */
	private const POLL_LIMIT = 30;

	/**
	 * Erişim jetonu.
	 *
	 * @var string
	 */
	private string $access_token = '';

	/**
	 * İstemciyi kurar.
	 *
	 * @param Transport $transport HTTP taşıyıcısı.
	 * @param string    $base_url  Taban adres.
	 */
	public function __construct(
		private readonly Transport $transport,
		private readonly string $base_url = self::TEST_BASE_URL
	) {
	}

	/**
	 * Jeton şifrelemede kullanılan sertifikanın kullanım etiketi.
	 */
	public const USAGE_TOKEN = 'KsefTokenEncryption';

	/**
	 * Simetrik anahtar şifrelemede kullanılan sertifikanın kullanım etiketi.
	 */
	public const USAGE_SYMMETRIC_KEY = 'SymmetricKeyEncryption';

	/**
	 * KSeF'in açık anahtar sertifikasını indirir.
	 *
	 * KSeF BIRDEN COK sertifika dondurur ve her biri farkli is icindir; canli
	 * ortamdan alinan yanitta ikisi var:
	 *
	 *   KsefTokenEncryption    - kimlik dogrulamada JETONU sifrelemek icin
	 *   SymmetricKeyEncryption - oturumda AES ANAHTARINI sarmalamak icin
	 *
	 * Yanlis olani secmek sessiz bir hata degil: karsi taraf cozemez ve islem
	 * reddedilir. Bu yuzden kullanim etiketi cagiranin acikca sectigi bir sey.
	 *
	 * @param string $usage Kullanım etiketi.
	 * @return string PEM biçiminde sertifika.
	 * @throws \RuntimeException Sertifika alınamazsa.
	 */
	public function public_key_certificate( string $usage = self::USAGE_SYMMETRIC_KEY ): string {
		$response = $this->send( 'GET', '/security/public-key-certificates', null, false );

		$certificates = $response->json();

		foreach ( $certificates as $certificate ) {
			if ( ! is_array( $certificate ) ) {
				continue;
			}

			$usages = $certificate['usage'] ?? array();

			if ( is_array( $usages ) && in_array( $usage, $usages, true ) ) {
				return $this->to_pem( (string) ( $certificate['certificate'] ?? '' ) );
			}
		}

		throw new \RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
			sprintf( 'KSeF did not return a certificate for %s.', $usage )
		);
	}

	/**
	 * KSeF jetonuyla kimlik doğrular ve erişim jetonunu saklar.
	 *
	 * @param string $ksef_token   Mağaza sahibinin KSeF jetonu.
	 * @param string $nip          Satıcının NIP'i.
	 * @param string $certificate  KSeF sertifikası; USAGE_TOKEN etiketli olan.
	 * @return void
	 * @throws \RuntimeException Doğrulama başarısızsa.
	 */
	public function authenticate( string $ksef_token, string $nip, string $certificate ): void {
		$context = array(
			'type'  => 'Nip',
			'value' => $nip,
		);

		$challenge = $this->send(
			'POST',
			'/auth/challenge',
			array( 'contextIdentifier' => $context ),
			false
		)->json();

		$value     = (string) ( $challenge['challenge'] ?? '' );
		$timestamp = $challenge['timestamp'] ?? null;

		if ( '' === $value || null === $timestamp ) {
			throw new \RuntimeException( 'KSeF did not return an authentication challenge.' );
		}

		/*
		 * Zaman damgasi meydan okumadan gelir ve MILISANIYE olmalidir. Yerel
		 * saat kullanilmaz: sunucuyla arasindaki fark dogrulamayi bozar.
		 */
		$encrypted = Encryption::wrap_key(
			sprintf( '%s|%s', $ksef_token, $this->milliseconds( $timestamp ) ),
			$certificate
		);

		$submitted = $this->send(
			'POST',
			'/auth/ksef-token',
			array(
				'challenge'         => $value,
				'contextIdentifier' => $context,
				'encryptedToken'    => base64_encode( $encrypted ),
			),
			false
		)->json();

		$authentication = $this->token( $submitted['authenticationToken'] ?? null );
		$reference      = (string) ( $submitted['referenceNumber'] ?? '' );

		if ( '' === $authentication || '' === $reference ) {
			throw new \RuntimeException( 'KSeF did not return an authentication token.' );
		}

		$this->await_authentication( $reference, $authentication );

		$redeemed = $this->send( 'POST', '/auth/token/redeem', null, false, $authentication )->json();

		$access = $this->token( $redeemed['accessToken'] ?? null );

		if ( '' === $access ) {
			throw new \RuntimeException( 'KSeF did not return an access token.' );
		}

		$this->access_token = $access;
	}

	/**
	 * Daha önce alınmış bir erişim jetonunu kullanır.
	 *
	 * Erisim jetonu sinirli sureli ama tek kullanimlik degil; saklanip yeniden
	 * kullanilabilir. Her gonderimde bastan kimlik dogrulamak hem yavas hem
	 * gereksiz.
	 *
	 * @param string $token Erişim jetonu.
	 * @return void
	 */
	public function use_access_token( string $token ): void {
		$this->access_token = $token;
	}

	/**
	 * Etkileşimli bir oturum açar.
	 *
	 * @param string $wrapped_key Sarmalanmış AES anahtarı (ham).
	 * @param string $iv          Başlatma vektörü (ham).
	 * @return string Oturumun referans numarası.
	 * @throws \RuntimeException Oturum açılamazsa.
	 */
	public function open_session( string $wrapped_key, string $iv ): string {
		$response = $this->send(
			'POST',
			'/sessions/online',
			array(
				'formCode'   => array(
					'systemCode'    => 'FA (3)',
					'schemaVersion' => '1-0E',
					'value'         => 'FA',
				),
				'encryption' => array(
					'encryptedSymmetricKey' => base64_encode( $wrapped_key ),
					'initializationVector'  => base64_encode( $iv ),
				),
			)
		)->json();

		$reference = (string) ( $response['referenceNumber'] ?? '' );

		if ( '' === $reference ) {
			throw new \RuntimeException( 'KSeF did not return a session reference number.' );
		}

		return $reference;
	}

	/**
	 * Faturayı oturuma gönderir.
	 *
	 * @param string $session Oturum referans numarası.
	 * @param string $xml     Fatura XML'i (şifrelenmemiş).
	 * @param string $key     AES anahtarı.
	 * @param string $iv      Başlatma vektörü.
	 * @return string Faturanın referans numarası.
	 * @throws \RuntimeException Gönderim başarısızsa.
	 */
	public function send_invoice( string $session, string $xml, string $key, string $iv ): string {
		$encrypted = Encryption::encrypt_document( $xml, $key, $iv );

		$response = $this->send(
			'POST',
			sprintf( '/sessions/online/%s/invoices', rawurlencode( $session ) ),
			array(
				'invoiceHash'             => base64_encode( hash( 'sha256', $xml, true ) ),
				'invoiceSize'             => strlen( $xml ),
				'encryptedInvoiceHash'    => base64_encode( hash( 'sha256', $encrypted, true ) ),
				'encryptedInvoiceSize'    => strlen( $encrypted ),
				'encryptedInvoiceContent' => base64_encode( $encrypted ),
			)
		)->json();

		$reference = (string) ( $response['referenceNumber'] ?? '' );

		if ( '' === $reference ) {
			throw new \RuntimeException( 'KSeF did not return an invoice reference number.' );
		}

		return $reference;
	}

	/**
	 * Faturanın işlenme durumunu sorgular.
	 *
	 * @param string $session Oturum referans numarası.
	 * @param string $invoice Fatura referans numarası.
	 * @return array<string,mixed>
	 * @throws \RuntimeException Sorgulama başarısızsa.
	 */
	public function invoice_status( string $session, string $invoice ): array {
		/*
		 * DIKKAT: sorgulama yolu "/sessions/online/..." DEGIL. Oturum acmak
		 * icin /sessions/online kullanilir ama acilmis oturumu ve icindeki
		 * faturalari sorgulamak /sessions/{ref} altindan yapilir. Ilk yazimda
		 * "online" buraya da konmustu ve canli deneme 404 dondurdu.
		 */
		return $this->send(
			'GET',
			sprintf(
				'/sessions/%s/invoices/%s',
				rawurlencode( $session ),
				rawurlencode( $invoice )
			),
			null
		)->json();
	}

	/**
	 * Oturumun durumunu sorgular.
	 *
	 * @param string $session Oturum referans numarası.
	 * @return array<string,mixed>
	 * @throws \RuntimeException Sorgulama başarısızsa.
	 */
	public function session_status( string $session ): array {
		return $this->send( 'GET', sprintf( '/sessions/%s', rawurlencode( $session ) ), null )->json();
	}

	/**
	 * Oturumu kapatır; toplu UPO üretimini başlatır.
	 *
	 * @param string $session Oturum referans numarası.
	 * @return void
	 * @throws \RuntimeException Kapatma başarısızsa.
	 */
	public function close_session( string $session ): void {
		$this->send( 'POST', sprintf( '/sessions/online/%s/close', rawurlencode( $session ) ), null );
	}

	/**
	 * Kimlik doğrulamanın tamamlanmasını bekler.
	 *
	 * @param string $reference Referans numarası.
	 * @param string $token     Kimlik doğrulama jetonu.
	 * @return void
	 * @throws \RuntimeException Doğrulama başarısız olur ya da zaman aşımına uğrarsa.
	 */
	private function await_authentication( string $reference, string $token ): void {
		for ( $attempt = 0; $attempt < self::POLL_LIMIT; $attempt++ ) {
			$status = $this->send(
				'GET',
				sprintf( '/auth/%s', rawurlencode( $reference ) ),
				null,
				false,
				$token
			)->json();

			$code = (int) ( $status['status']['code'] ?? 0 );

			if ( 200 === $code ) {
				return;
			}

			/*
			 * 400 ve ustu kalicidir; beklemek durumu degistirmez. Sadece
			 * "isleniyor" durumunda tekrar sorulur.
			 */
			if ( $code >= 400 ) {
				$description = (string) ( $status['status']['description'] ?? 'unknown error' );

				throw new \RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
					sprintf( 'KSeF rejected the authentication: %s', $description )
				);
			}

			sleep( self::POLL_PAUSE );
		}

		throw new \RuntimeException( 'KSeF did not complete the authentication in time.' );
	}

	/**
	 * İstek gönderir.
	 *
	 * @param string                   $method    HTTP yöntemi.
	 * @param string                   $path      Taban adrese göreli yol.
	 * @param array<string,mixed>|null $payload   JSON gövdesi.
	 * @param bool                     $authorise Erişim jetonu eklensin mi.
	 * @param string                   $token     Erişim jetonu yerine kullanılacak jeton.
	 * @return Response
	 * @throws \RuntimeException İstek başarısızsa.
	 */
	private function send(
		string $method,
		string $path,
		?array $payload = null,
		bool $authorise = true,
		string $token = ''
	): Response {
		$headers = array( 'Accept' => 'application/json' );
		$body    = null;

		if ( null !== $payload ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = (string) wp_json_encode( $payload );
		}

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		} elseif ( $authorise ) {
			if ( '' === $this->access_token ) {
				throw new \RuntimeException( 'The KSeF client is not authenticated.' );
			}

			$headers['Authorization'] = 'Bearer ' . $this->access_token;
		}

		$response = $this->transport->request( $method, $this->base_url . $path, $headers, $body, self::TIMEOUT );

		if ( ! $response->ok() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
			throw new \RuntimeException( $this->error( $response, $path ) );
		}

		return $response;
	}

	/**
	 * Hata yanıtını okunabilir bir mesaja çevirir.
	 *
	 * @param Response $response Yanıt.
	 * @param string   $path     İstenen yol.
	 * @return string
	 */
	private function error( Response $response, string $path ): string {
		$detail = '';

		try {
			$decoded = $response->json();
			$first   = $decoded['exception']['exceptionDetailList'][0] ?? null;

			if ( is_array( $first ) ) {
				$detail = (string) ( $first['exceptionDescription'] ?? '' );
			}
		} catch ( \RuntimeException $ignored ) {
			unset( $ignored );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Cagiran taraf bunu istisna mesaji yapar; gunluge gider.
		return sprintf(
			'KSeF refused the request to %s (HTTP %d)%s',
			$path,
			$response->status,
			'' === $detail ? '.' : ': ' . $detail
		);
	}

	/**
	 * Jeton alanını okur.
	 *
	 * Belgeler jetonun düz dizge mi yoksa `{ "token": ... }` nesnesi mi
	 * olduğunu her yerde açıkça söylemiyor; ikisi de kabul ediliyor. Canlı
	 * sınavdan sonra doğru olan bırakılmalı.
	 *
	 * @param mixed $value Alan değeri.
	 * @return string
	 */
	private function token( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return (string) ( $value['token'] ?? '' );
		}

		return '';
	}

	/**
	 * Zaman damgasını milisaniyeye çevirir.
	 *
	 * @param mixed $timestamp Meydan okumadan gelen zaman damgası.
	 * @return string
	 * @throws \RuntimeException Zaman damgası anlaşılamazsa.
	 */
	private function milliseconds( $timestamp ): string {
		if ( is_int( $timestamp ) || ( is_string( $timestamp ) && ctype_digit( $timestamp ) ) ) {
			return (string) $timestamp;
		}

		if ( is_string( $timestamp ) ) {
			$parsed = strtotime( $timestamp );

			if ( false !== $parsed ) {
				return (string) ( $parsed * 1000 );
			}
		}

		throw new \RuntimeException( 'The KSeF challenge timestamp could not be read.' );
	}

	/**
	 * Ham sertifikayı PEM biçimine getirir.
	 *
	 * @param string $certificate Base64 sertifika ya da hazır PEM.
	 * @return string
	 * @throws \RuntimeException Sertifika boşsa.
	 */
	private function to_pem( string $certificate ): string {
		$certificate = trim( $certificate );

		if ( '' === $certificate ) {
			throw new \RuntimeException( 'The KSeF certificate is empty.' );
		}

		if ( str_contains( $certificate, '-----BEGIN' ) ) {
			return $certificate;
		}

		return "-----BEGIN CERTIFICATE-----\n"
			. chunk_split( $certificate, 64, "\n" )
			. "-----END CERTIFICATE-----\n";
	}
}
