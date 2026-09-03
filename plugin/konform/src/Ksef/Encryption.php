<?php
/**
 * KSeF gönderimi için şifreleme.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Ksef;

defined( 'ABSPATH' ) || exit;

/**
 * KSeF 2.0'ın istediği iki katmanlı şifrelemeyi uygular.
 *
 * KSeF her faturayı istemcide şifrelenmiş ister:
 *
 * 1. Rastgele bir AES-256 anahtarı ve IV üretilir; fatura XML'i
 *    AES-256-CBC + PKCS#7 ile şifrelenir.
 * 2. AES anahtarı, Bakanlık'ın açık anahtarıyla RSA-OAEP (SHA-256,
 *    MGF1-SHA256) sarmalanır ve oturum açılırken gönderilir.
 *
 * İkinci adım PHP'de göründüğü kadar kolay değil ve sebebi ölçüldü:
 * `openssl_public_encrypt()` OAEP özetini seçmeye ancak **PHP 8.5.0**'da
 * izin verdi (`digest_algo` parametresi). Daha eski sürümlerde OAEP her zaman
 * SHA-1 kullanır ve KSeF böyle bir anahtarı kabul etmez. Eklenti PHP 8.2
 * istediği için yaygın barındırmaların çoğu bu duruma düşer.
 *
 * Çözüm olarak phpseclib düşünüldü ve ölçüldü: 362 PHP dosyası, 3,4 MB.
 * Tek bir işlev için paketi neredeyse ikiye katlıyordu. Bunun yerine
 * yalnızca **EME-OAEP kodlaması** (RFC 8017, 7.1.1) burada yapılıyor; RSA
 * işleminin kendisi OpenSSL'de kalıyor.
 *
 * Bu, "kendi kriptonu yazma" kuralının bilinçli ve dar bir istisnası:
 *
 * - Yalnızca **şifreleme** var. Çözme yok, dolayısıyla padding oracle yok.
 * - Açık anahtarla ve rastgele tohumla çalışır; gizliye bağlı dallanma yok.
 * - Doğruluğu OpenSSL'e karşı bayt bayt sınanabilir: yanlış kodlanmış bir
 *   OAEP bloğu çözülürken sessizce geçmez, lHash ve 0x01 ayracı denetlendiği
 *   için gürültüyle patlar. Testler bunu yapıyor.
 *
 * PHP 8.5 ve üstünde yerel yol kullanılır; kendi kodlamamız yalnızca eski
 * sürümlerde devreye girer. İkisi de aynı testten geçer.
 */
final class Encryption {

	/**
	 * AES anahtar uzunluğu (bayt). KSeF AES-256 ister.
	 */
	private const KEY_BYTES = 32;

	/**
	 * CBC başlatma vektörü uzunluğu (bayt).
	 */
	private const IV_BYTES = 16;

	/**
	 * Fatura şifrelemesinde kullanılan simetrik algoritma.
	 */
	private const CIPHER = 'aes-256-cbc';

	/**
	 * OAEP'te kullanılan özet.
	 */
	private const DIGEST = 'sha256';

	/**
	 * SHA-256 çıktı uzunluğu (bayt).
	 */
	private const HASH_BYTES = 32;

	/**
	 * Rastgele bir AES-256 anahtarı üretir.
	 *
	 * @return string 32 baytlık ham anahtar.
	 * @throws \RuntimeException Güvenli rastgelelik alınamazsa.
	 */
	public static function generate_key(): string {
		return self::random( self::KEY_BYTES );
	}

	/**
	 * Rastgele bir başlatma vektörü üretir.
	 *
	 * @return string 16 baytlık ham IV.
	 * @throws \RuntimeException Güvenli rastgelelik alınamazsa.
	 */
	public static function generate_iv(): string {
		return self::random( self::IV_BYTES );
	}

	/**
	 * Fatura XML'ini AES-256-CBC ile şifreler.
	 *
	 * @param string $plaintext Fatura XML'i.
	 * @param string $key       32 baytlık AES anahtarı.
	 * @param string $iv        16 baytlık IV.
	 * @return string Ham şifreli metin.
	 * @throws \RuntimeException Anahtar/IV uzunluğu yanlışsa veya şifreleme başarısızsa.
	 */
	public static function encrypt_document( string $plaintext, string $key, string $iv ): string {
		if ( self::KEY_BYTES !== strlen( $key ) ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
				sprintf( 'The AES key must be %d bytes; %d given.', self::KEY_BYTES, strlen( $key ) )
			);
		}

		if ( self::IV_BYTES !== strlen( $iv ) ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
				sprintf( 'The initialisation vector must be %d bytes; %d given.', self::IV_BYTES, strlen( $iv ) )
			);
		}

		// OPENSSL_RAW_DATA ham cikti verir; PKCS#7 dolgusu varsayilandir.
		$cipher = \openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( ! is_string( $cipher ) ) {
			throw new \RuntimeException( 'The invoice could not be encrypted for KSeF.' );
		}

		return $cipher;
	}

	/**
	 * AES anahtarını KSeF'in açık anahtarıyla sarmalar.
	 *
	 * @param string $key         32 baytlık AES anahtarı.
	 * @param string $certificate KSeF'in açık anahtar sertifikası (PEM).
	 * @return string Ham sarmalanmış anahtar.
	 * @throws \RuntimeException Sertifika okunamazsa veya şifreleme başarısızsa.
	 */
	public static function wrap_key( string $key, string $certificate ): string {
		return self::wrap_key_using( $key, $certificate, self::has_native_oaep_digest() );
	}

	/**
	 * OAEP özetinin doğrudan seçilebildiği bir PHP üzerinde miyiz.
	 *
	 * @return bool
	 */
	public static function has_native_oaep_digest(): bool {
		return PHP_VERSION_ID >= 80500;
	}

	/**
	 * AES anahtarını belirtilen yolu kullanarak sarmalar.
	 *
	 * Yol seçimi bilerek dışarıdan verilebiliyor. Aksi hâlde geliştirme
	 * ortamının PHP sürümü hangi dalın sınandığını belirlerdi: konteyner
	 * 8.5 olduğu için yerel yol hep kazanır ve **gerçek kullanıcıların
	 * düştüğü dal hiç çalışmazdı.** Testler ikisini de zorluyor.
	 *
	 * @param string $key         32 baytlık AES anahtarı.
	 * @param string $certificate KSeF'in açık anahtar sertifikası (PEM).
	 * @param bool   $native      Yerel OAEP özeti kullanılsın mı.
	 * @return string Ham sarmalanmış anahtar.
	 * @throws \RuntimeException Sertifika okunamazsa veya şifreleme başarısızsa.
	 */
	public static function wrap_key_using( string $key, string $certificate, bool $native ): string {
		$public = \openssl_pkey_get_public( $certificate );

		if ( false === $public ) {
			throw new \RuntimeException( 'The KSeF public key certificate could not be read.' );
		}

		$details = \openssl_pkey_get_details( $public );

		if ( ! is_array( $details ) || ! isset( $details['bits'] ) || OPENSSL_KEYTYPE_RSA !== $details['type'] ) {
			throw new \RuntimeException( 'The KSeF certificate does not contain an RSA public key.' );
		}

		/*
		 * PHP 8.5 ve ustunde OAEP ozeti dogrudan secilebiliyor; orada kendi
		 * kodlamamiza gerek yok.
		 */
		if ( $native ) {
			$wrapped = '';

			if ( \openssl_public_encrypt( $key, $wrapped, $public, OPENSSL_PKCS1_OAEP_PADDING, self::DIGEST ) ) {
				return $wrapped;
			}

			throw new \RuntimeException( 'The AES key could not be wrapped with the KSeF public key.' );
		}

		$modulus_bytes = (int) ceil( ( (int) $details['bits'] ) / 8 );
		$encoded       = self::oaep_encode( $key, $modulus_bytes );
		$wrapped       = '';

		// Dolgu zaten yapildi; OpenSSL yalnizca ham RSA islemini yapiyor.
		if ( ! \openssl_public_encrypt( $encoded, $wrapped, $public, OPENSSL_NO_PADDING ) ) {
			throw new \RuntimeException( 'The AES key could not be wrapped with the KSeF public key.' );
		}

		return $wrapped;
	}

	/**
	 * EME-OAEP kodlaması. RFC 8017, bölüm 7.1.1.
	 *
	 * Ayrı ve açık bir metot olarak duruyor ki testler, çalışılan PHP sürümü
	 * ne olursa olsun bu yolu doğrudan sınayabilsin. Yerel yol varken bile
	 * kodlamanın doğruluğu ölçülmelidir.
	 *
	 * @param string $message       Sarmalanacak veri.
	 * @param int    $modulus_bytes RSA modülüsünün bayt uzunluğu.
	 * @return string Kodlanmış blok; uzunluğu tam olarak $modulus_bytes.
	 * @throws \RuntimeException Veri anahtara sığmazsa.
	 */
	public static function oaep_encode( string $message, int $modulus_bytes ): string {
		$max = $modulus_bytes - ( 2 * self::HASH_BYTES ) - 2;

		if ( strlen( $message ) > $max ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
				sprintf( 'The message is too long for OAEP: %d bytes, limit %d.', strlen( $message ), $max )
			);
		}

		// Etiket bostur; lHash bos dizgenin ozetidir.
		$l_hash = hash( self::DIGEST, '', true );

		$padding = str_repeat( "\x00", $modulus_bytes - strlen( $message ) - ( 2 * self::HASH_BYTES ) - 2 );

		$data_block = $l_hash . $padding . "\x01" . $message;

		$seed = self::random( self::HASH_BYTES );

		$data_block_mask   = self::mgf1( $seed, $modulus_bytes - self::HASH_BYTES - 1 );
		$masked_data_block = $data_block ^ $data_block_mask;

		$seed_mask   = self::mgf1( $masked_data_block, self::HASH_BYTES );
		$masked_seed = $seed ^ $seed_mask;

		return "\x00" . $masked_seed . $masked_data_block;
	}

	/**
	 * MGF1 maske üretme işlevi. RFC 8017, ek B.2.1.
	 *
	 * @param string $seed   Tohum.
	 * @param int    $length İstenen maske uzunluğu (bayt).
	 * @return string
	 */
	private static function mgf1( string $seed, int $length ): string {
		$mask = '';

		// Kac blok gerektigi bastan hesaplaniyor; dongu kosulunda strlen()
		// cagirmak hem yavas hem de kod standardinca yasak.
		$blocks = (int) ceil( $length / self::HASH_BYTES );

		for ( $counter = 0; $counter < $blocks; $counter++ ) {
			$mask .= hash( self::DIGEST, $seed . pack( 'N', $counter ), true );
		}

		return substr( $mask, 0, $length );
	}

	/**
	 * Kriptografik olarak güvenli rastgele bayt üretir.
	 *
	 * @param int $length Bayt sayısı.
	 * @return string
	 * @throws \RuntimeException Güvenli rastgelelik alınamazsa.
	 */
	private static function random( int $length ): string {
		try {
			return random_bytes( $length );
		} catch ( \Throwable $error ) {
			/*
			 * Zayif bir yedege dusmek yok. Tahmin edilebilir bir anahtar,
			 * sifrelemenin tamamini anlamsiz kilar.
			 */
			throw new \RuntimeException(
				'No cryptographically secure randomness is available; the invoice cannot be encrypted.',
				0,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ciktiya degil, onceki istisna bagina gidiyor.
				$error
			);
		}
	}
}
