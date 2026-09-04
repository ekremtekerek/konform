<?php
/**
 * KSeF ayarları.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Ksef;

defined( 'ABSPATH' ) || exit;

/**
 * KSeF bağlantı ayarlarını okur ve yazar.
 *
 * Jeton bir KIMLIK BILGISIDIR. WordPress'te sırların güvenli saklanacağı bir
 * yer yoktur; seçenek tablosu, veritabanına erişebilen herkese açıktır. Bunu
 * değiştiremeyiz ama iki şeyi yapabiliriz ve yapıyoruz:
 *
 * - Jeton hiçbir günlüğe, hata mesajına ya da denetim kaydına yazılmaz.
 * - Kaldırma sırasında silinir; sitede unutulmuş bir jeton bırakılmaz.
 *
 * Arayüzde de asla geri gösterilmez; yalnızca "tanımlı" bilgisi verilir.
 */
final class Settings {

	/**
	 * KSeF jetonunun saklandığı seçenek.
	 */
	public const OPTION_TOKEN = 'konform_ksef_token';

	/**
	 * Ortam seçeneği: test ya da üretim.
	 */
	public const OPTION_ENVIRONMENT = 'konform_ksef_environment';

	/**
	 * Test ortamı.
	 */
	public const ENVIRONMENT_TEST = 'test';

	/**
	 * Üretim ortamı.
	 */
	public const ENVIRONMENT_PRODUCTION = 'production';

	/**
	 * Jeton tanımlı mı.
	 *
	 * @return bool
	 */
	public static function has_token(): bool {
		return '' !== self::token();
	}

	/**
	 * KSeF jetonunu döndürür.
	 *
	 * @return string
	 */
	public static function token(): string {
		return trim( (string) \get_option( self::OPTION_TOKEN, '' ) );
	}

	/**
	 * Jetonu kaydeder.
	 *
	 * @param string $token Jeton; boş dize jetonu siler.
	 * @return void
	 */
	public static function set_token( string $token ): void {
		$token = trim( $token );

		if ( '' === $token ) {
			\delete_option( self::OPTION_TOKEN );

			return;
		}

		\update_option( self::OPTION_TOKEN, $token, false );
	}

	/**
	 * Seçili ortam.
	 *
	 * Varsayilan TEST. Uretim ortami, gonderilen her faturanin hukuki sonucu
	 * olmasi demek; kullanicinin bunu bilerek secmesi gerekir.
	 *
	 * @return string
	 */
	public static function environment(): string {
		$value = (string) \get_option( self::OPTION_ENVIRONMENT, self::ENVIRONMENT_TEST );

		return self::ENVIRONMENT_PRODUCTION === $value
			? self::ENVIRONMENT_PRODUCTION
			: self::ENVIRONMENT_TEST;
	}

	/**
	 * Seçili ortamın taban adresi.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		return self::ENVIRONMENT_PRODUCTION === self::environment()
			? Client::PRODUCTION_BASE_URL
			: Client::TEST_BASE_URL;
	}

	/**
	 * Üretim ortamında mıyız.
	 *
	 * @return bool
	 */
	public static function is_production(): bool {
		return self::ENVIRONMENT_PRODUCTION === self::environment();
	}
}
