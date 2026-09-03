<?php
/**
 * KSeF şifreleme testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Ksef\Encryption;
use PHPUnit\Framework\TestCase;

/**
 * Buradaki asıl sınav şu: kendi yazdığımız EME-OAEP kodlaması, OpenSSL'in
 * kendi OAEP çözücüsü tarafından kabul ediliyor mu?
 *
 * Bu, "geçtiyse doğrudur" denebilecek nadir durumlardan biri. Yanlış kodlanmış
 * bir OAEP bloğu sessizce geçmez: çözücü lHash'i ve 0x01 ayracını denetler, en
 * ufak sapmada başarısız olur. Yani round-trip testi, kodlamanın bayt bayt
 * doğru olduğunun güçlü kanıtıdır.
 *
 * Rastgele tohum kullanıldığı için testler birçok kez ve farklı uzunluklarla
 * tekrarlanıyor; tek bir şanslı geçiş yeterli değil.
 */
final class EncryptionTest extends TestCase {

	/**
	 * Test RSA anahtar çifti (PEM, özel anahtar).
	 *
	 * @var string
	 */
	private string $private_key = '';

	/**
	 * Test RSA açık anahtarı (PEM).
	 *
	 * @var string
	 */
	private string $public_key = '';

	/**
	 * RSA modülüsünün bayt uzunluğu.
	 *
	 * @var int
	 */
	private int $modulus_bytes = 0;

	/**
	 * Her testten önce anahtar çifti üretir.
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

		if ( false === $resource ) {
			$this->markTestSkipped( 'RSA anahtar çifti üretilemedi.' );
		}

		\openssl_pkey_export( $resource, $this->private_key );

		$details             = \openssl_pkey_get_details( $resource );
		$this->public_key    = $details['key'];
		$this->modulus_bytes = (int) ceil( $details['bits'] / 8 );
	}

	/**
	 * Kendi OAEP kodlamamız OpenSSL tarafından çözülebilir.
	 *
	 * Testin can alıcı noktası: kodlama bizden, çözme OpenSSL'den. İkisi
	 * uyuşuyorsa kodlama RFC 8017'ye uygundur.
	 *
	 * @return void
	 */
	public function test_our_oaep_encoding_is_accepted_by_openssl(): void {
		$this->requires_native_oaep_digest();

		$secret = Encryption::generate_key();

		$encoded = Encryption::oaep_encode( $secret, $this->modulus_bytes );

		$wrapped = '';
		$this->assertTrue(
			\openssl_public_encrypt( $encoded, $wrapped, $this->public_key, OPENSSL_NO_PADDING ),
			'Ham RSA işlemi başarısız oldu.'
		);

		$unwrapped = '';
		$this->assertTrue(
			\openssl_private_decrypt( $wrapped, $unwrapped, $this->private_key, OPENSSL_PKCS1_OAEP_PADDING, 'sha256' ),
			'OpenSSL kendi OAEP çözücüsüyle bloğu çözemedi.'
		);

		$this->assertSame( $secret, $unwrapped );
	}

	/**
	 * Farklı uzunluklarda ve tekrar tekrar çözülebilir.
	 *
	 * Rastgele tohum yüzünden her çağrı farklı bir blok üretir; tek geçiş
	 * kanıt değildir.
	 *
	 * @return void
	 */
	public function test_encoding_round_trips_across_lengths_and_repeats(): void {
		$this->requires_native_oaep_digest();

		foreach ( array( 1, 16, 32, 64, 190 ) as $length ) {
			for ( $attempt = 0; $attempt < 5; $attempt++ ) {
				$message = random_bytes( $length );
				$encoded = Encryption::oaep_encode( $message, $this->modulus_bytes );

				$wrapped = '';
				\openssl_public_encrypt( $encoded, $wrapped, $this->public_key, OPENSSL_NO_PADDING );

				$unwrapped = '';
				$decrypted = \openssl_private_decrypt(
					$wrapped,
					$unwrapped,
					$this->private_key,
					OPENSSL_PKCS1_OAEP_PADDING,
					'sha256'
				);

				$this->assertTrue( $decrypted, sprintf( '%d baytlık veri çözülemedi.', $length ) );
				$this->assertSame( $message, $unwrapped );
			}
		}
	}

	/**
	 * Kodlanmış blok anahtarla aynı uzunlukta ve sıfır baytla başlar.
	 *
	 * @return void
	 */
	public function test_encoded_block_has_the_shape_rfc_8017_requires(): void {
		$encoded = Encryption::oaep_encode( Encryption::generate_key(), $this->modulus_bytes );

		$this->assertSame( $this->modulus_bytes, strlen( $encoded ) );
		$this->assertSame( "\x00", $encoded[0] );
	}

	/**
	 * Aynı veri iki kez kodlanınca farklı blok çıkar.
	 *
	 * OAEP'in rastgele tohumu olmazsa şifreleme belirlenimci olur ve aynı
	 * anahtarın tekrar gönderildiği anlaşılır.
	 *
	 * @return void
	 */
	public function test_encoding_is_randomised(): void {
		$secret = Encryption::generate_key();

		$this->assertNotSame(
			Encryption::oaep_encode( $secret, $this->modulus_bytes ),
			Encryption::oaep_encode( $secret, $this->modulus_bytes )
		);
	}

	/**
	 * Anahtara sığmayan veri reddedilir.
	 *
	 * @return void
	 */
	public function test_oversized_message_is_refused(): void {
		$this->expectException( \RuntimeException::class );

		Encryption::oaep_encode( str_repeat( 'x', $this->modulus_bytes ), $this->modulus_bytes );
	}

	/**
	 * Sarmalanan anahtar, KSeF'in beklediği biçimde çözülebilir.
	 *
	 * @return void
	 */
	public function test_wrapped_key_can_be_unwrapped(): void {
		$this->requires_native_oaep_digest();

		$key     = Encryption::generate_key();
		$wrapped = Encryption::wrap_key( $key, $this->public_key );

		$this->assertSame( $this->modulus_bytes, strlen( $wrapped ) );

		$unwrapped = '';
		$this->assertTrue(
			\openssl_private_decrypt( $wrapped, $unwrapped, $this->private_key, OPENSSL_PKCS1_OAEP_PADDING, 'sha256' )
		);

		$this->assertSame( $key, $unwrapped );
	}

	/**
	 * Her iki sarmalama yolu da KSeF'in beklediği sonucu verir.
	 *
	 * Geliştirme konteyneri PHP 8.5 olduğu için `wrap_key()` her zaman yerel
	 * yolu seçer. Gerçek kullanıcıların çoğu PHP 8.2–8.4'te, yani KENDİ
	 * kodlamamızın çalıştığı dalda. O dal sınanmazsa, kimsenin görmediği bir
	 * yolu üretime göndermiş oluruz.
	 *
	 * @return void
	 */
	public function test_both_wrapping_paths_produce_a_valid_key(): void {
		$this->requires_native_oaep_digest();

		$key = Encryption::generate_key();

		foreach ( array( true, false ) as $native ) {
			$wrapped = Encryption::wrap_key_using( $key, $this->public_key, $native );

			$this->assertSame( $this->modulus_bytes, strlen( $wrapped ) );

			$unwrapped = '';
			$this->assertTrue(
				\openssl_private_decrypt(
					$wrapped,
					$unwrapped,
					$this->private_key,
					OPENSSL_PKCS1_OAEP_PADDING,
					'sha256'
				),
				sprintf( '%s yol ile sarmalanan anahtar çözülemedi.', $native ? 'yerel' : 'kendi' )
			);

			$this->assertSame( $key, $unwrapped );
		}
	}

	/**
	 * Anahtar ve IV doğru uzunlukta üretilir.
	 *
	 * @return void
	 */
	public function test_key_and_iv_have_the_lengths_ksef_requires(): void {
		$this->assertSame( 32, strlen( Encryption::generate_key() ) );
		$this->assertSame( 16, strlen( Encryption::generate_iv() ) );
		$this->assertNotSame( Encryption::generate_key(), Encryption::generate_key() );
	}

	/**
	 * Fatura AES-256-CBC ile şifrelenir ve geri çözülür.
	 *
	 * @return void
	 */
	public function test_document_encryption_round_trips(): void {
		$xml = '<?xml version="1.0"?><Faktura><P_2>FA/2026/1</P_2></Faktura>';
		$key = Encryption::generate_key();
		$iv  = Encryption::generate_iv();

		$cipher = Encryption::encrypt_document( $xml, $key, $iv );

		// PKCS#7 dolgusu: cikti her zaman blok katidir ve girdiden uzundur.
		$this->assertSame( 0, strlen( $cipher ) % 16 );
		$this->assertGreaterThan( strlen( $xml ), strlen( $cipher ) );

		$this->assertSame(
			$xml,
			\openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv )
		);
	}

	/**
	 * Yanlış uzunlukta anahtar sessizce kabul edilmez.
	 *
	 * @return void
	 */
	public function test_short_key_is_refused(): void {
		$this->expectException( \RuntimeException::class );

		Encryption::encrypt_document( 'x', 'kisa-anahtar', Encryption::generate_iv() );
	}

	/**
	 * Yanlış uzunlukta IV sessizce kabul edilmez.
	 *
	 * @return void
	 */
	public function test_short_iv_is_refused(): void {
		$this->expectException( \RuntimeException::class );

		Encryption::encrypt_document( 'x', Encryption::generate_key(), 'kisa' );
	}

	/**
	 * Geçersiz sertifika reddedilir.
	 *
	 * @return void
	 */
	public function test_invalid_certificate_is_refused(): void {
		$this->expectException( \RuntimeException::class );

		Encryption::wrap_key( Encryption::generate_key(), 'sertifika-degil' );
	}

	/**
	 * OAEP özeti seçilebilmiyorsa doğrulama yapılamaz.
	 *
	 * `openssl_private_decrypt()` özet parametresini PHP 8.5'te aldı. Daha
	 * eski sürümlerde OpenSSL'e "SHA-256 ile çöz" denemiyor, dolayısıyla
	 * kodlamamız bu yolla doğrulanamıyor.
	 *
	 * @return void
	 */
	private function requires_native_oaep_digest(): void {
		if ( PHP_VERSION_ID < 80500 ) {
			$this->markTestSkipped( 'OAEP özeti seçimi PHP 8.5 ile geldi; doğrulama bu sürümde yapılamaz.' );
		}
	}
}
