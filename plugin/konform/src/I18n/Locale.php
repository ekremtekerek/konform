<?php
/**
 * Dil ekseni çözümlemesi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Konform'da "dil" tek bir şey değildir; üç ayrı eksendir:
 *
 *   1. UI dili            — yöneticinin okuduğu dil          → admin()
 *   2. Belge dili         — ALICININ okuduğu dil             → document()
 *   3. Regülasyon profili — SATICININ ülkesinin kural seti    → regulatory_profile()
 *
 * Bu üçü bağımsızdır. İstanbul'daki bir mağaza paneli Türkçe kullanıp Fransız
 * müşteriye Fransızca fatura kesebilir ve Alman kural setine tabi olabilir.
 *
 * Tam gerekçe için bkz. docs/I18N.md.
 */
final class Locale {

	/**
	 * Elle geçersiz kılma için sipariş meta anahtarı.
	 */
	public const META_OVERRIDE = '_konform_document_locale';

	/**
	 * Sipariş oluşturulduğu anda yakalanan locale.
	 */
	public const META_CAPTURED = '_konform_checkout_locale';

	/**
	 * Ülke kodundan varsayılan locale eşlemesi.
	 *
	 * Yalnızca alıcının dili hakkında hiçbir ipucu kalmadığında kullanılır —
	 * ülke dil demek değildir (BE, CH, CA çok dillidir), bu yüzden son çaredir.
	 *
	 * @var array<string, string>
	 */
	private const COUNTRY_LOCALE = array(
		'AT' => 'de_DE',
		'BE' => 'nl_BE',
		'BG' => 'bg_BG',
		'CH' => 'de_CH',
		'CZ' => 'cs_CZ',
		'DE' => 'de_DE',
		'DK' => 'da_DK',
		'EE' => 'et',
		'ES' => 'es_ES',
		'FI' => 'fi',
		'FR' => 'fr_FR',
		'GB' => 'en_GB',
		'GR' => 'el',
		'HR' => 'hr',
		'HU' => 'hu_HU',
		'IE' => 'en_GB',
		'IT' => 'it_IT',
		'LT' => 'lt_LT',
		'LU' => 'fr_FR',
		'LV' => 'lv',
		'NL' => 'nl_NL',
		'NO' => 'nb_NO',
		'PL' => 'pl_PL',
		'PT' => 'pt_PT',
		'RO' => 'ro_RO',
		'SE' => 'sv_SE',
		'SI' => 'sl_SI',
		'SK' => 'sk_SK',
		'TR' => 'tr_TR',
		'US' => 'en_US',
	);

	/**
	 * Yönetici arayüzünün dili.
	 *
	 * Site geneli değil kullanıcı bazlıdır — WordPress her yöneticinin kendi dil
	 * tercihini tutar.
	 *
	 * @return string
	 */
	public static function admin(): string {
		return \get_user_locale();
	}

	/**
	 * Belgenin (fatura PDF'i ve e-postası) dili.
	 *
	 * Çözümleme sırası — ilk eşleşen kazanır:
	 *   1. Elle geçersiz kılma meta'sı
	 *   2. WPML / Polylang sipariş dili
	 *   3. Sipariş oluşturulurken yakalanan locale
	 *   4. Fatura ülkesinden türetilen varsayılan
	 *   5. Mağaza varsayılanı
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return string Kurulu olduğu doğrulanmış locale.
	 */
	public static function document( \WC_Order $order ): string {
		$candidates = array(
			(string) $order->get_meta( self::META_OVERRIDE ),
			self::from_wpml( $order ),
			(string) $order->get_meta( self::META_CAPTURED ),
			self::from_country( (string) $order->get_billing_country() ),
			\get_locale(),
		);

		$locale = '';

		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate ) {
				$locale = $candidate;
				break;
			}
		}

		/**
		 * Belge dilini değiştirir.
		 *
		 * @param string    $locale Çözümlenen locale.
		 * @param \WC_Order $order  Sipariş.
		 */
		$locale = (string) \apply_filters( 'konform/document_locale', $locale, $order );

		return self::installed( $locale );
	}

	/**
	 * Uygulanacak regülasyon profili (ülke kodu).
	 *
	 * Hangi ülkenin kural setine tabi olduğumuzu SATICININ adresi belirler,
	 * alıcınınki değil. Belge diliyle hiçbir ilgisi yoktur.
	 *
	 * @return string ISO 3166-1 alpha-2 ülke kodu, ör. "FR".
	 */
	public static function regulatory_profile(): string {
		$base = \function_exists( 'wc_get_base_location' ) ? \wc_get_base_location() : array();

		$country = isset( $base['country'] ) ? (string) $base['country'] : '';

		/**
		 * Regülasyon profilini değiştirir.
		 *
		 * Çok şirketli kurulumlarda fatura kesen tüzel kişi mağaza adresinden
		 * farklı olabilir.
		 *
		 * @param string $country Ülke kodu.
		 */
		return (string) \apply_filters( 'konform/regulatory_profile', $country );
	}

	/**
	 * Verilen locale altında bir geri çağırım çalıştırır ve dili geri alır.
	 *
	 * Kod tabanında switch_to_locale() çağrılmasına izin verilen TEK yer burasıdır.
	 * Restore işlemi finally bloğundadır; geri çağırım istisna fırlatsa bile
	 * yönetici paneli yanlış dilde kalmaz.
	 *
	 * @template T
	 * @param string   $locale   Kullanılacak locale.
	 * @param callable $callback Çalıştırılacak geri çağırım.
	 * @return mixed Geri çağırımın dönüş değeri.
	 */
	public static function render( string $locale, callable $callback ) {
		$locale   = self::installed( $locale );
		$switched = false;

		if ( \determine_locale() !== $locale ) {
			$switched = \switch_to_locale( $locale );
		}

		try {
			return $callback();
		} finally {
			if ( $switched ) {
				\restore_previous_locale();
			}
		}
	}

	/**
	 * Sipariş oluşturulurken o anki locale'i siparişe yazar.
	 *
	 * Kaydedilmezse alıcının dili sonradan bilinemez; ülke tahmini zayıf bir
	 * son çaredir.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return void
	 */
	public static function capture( \WC_Order $order ): void {
		if ( '' !== (string) $order->get_meta( self::META_CAPTURED ) ) {
			return;
		}

		$order->update_meta_data( self::META_CAPTURED, \determine_locale() );
	}

	/**
	 * Sipariş kimliğiyle çağrılan capture() biçimi.
	 *
	 * Ödeme sayfası dışında oluşturulan siparişleri de yakalar — ama YALNIZCA
	 * müşterinin kendi oturumunda. Yönetici panelinden, WP-CLI'dan veya REST
	 * ile oluşturulan siparişte o anki dil YÖNETİCİNİN dilidir, müşterinin
	 * değil; onu yakalamak Fransız müşteriye Türkçe fatura kesilmesine yol
	 * açar. Bu durumlarda alan boş bırakılır ve fatura ülkesinden türetilen
	 * varsayılan devreye girer — zayıf bir tahmindir ama yanlış bir kesinlikten
	 * iyidir.
	 *
	 * @param int $order_id Sipariş kimliği.
	 * @return void
	 */
	public static function capture_by_id( int $order_id ): void {
		if ( ! self::is_customer_context() ) {
			return;
		}

		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_CAPTURED ) ) {
			return;
		}

		self::capture( $order );
		$order->save();
	}

	/**
	 * İsteğin müşterinin kendi oturumundan gelip gelmediğini bildirir.
	 *
	 * @return bool
	 */
	private static function is_customer_context(): bool {
		if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
			return false;
		}

		if ( \defined( 'REST_REQUEST' ) && \REST_REQUEST ) {
			return false;
		}

		if ( \defined( 'DOING_CRON' ) && \DOING_CRON ) {
			return false;
		}

		// Ön yüzden gelen AJAX isteğinde is_admin() true döner; o hâli sayarız.
		return ! \is_admin() || \wp_doing_ajax();
	}

	/**
	 * Ülke kodundan varsayılan locale türetir.
	 *
	 * @param string $country ISO 3166-1 alpha-2 ülke kodu.
	 * @return string Eşleşme yoksa boş dize.
	 */
	public static function from_country( string $country ): string {
		return self::COUNTRY_LOCALE[ strtoupper( $country ) ] ?? '';
	}

	/**
	 * WPML veya Polylang tarafından yazılan sipariş dilini okur.
	 *
	 * Bu eklentiler dil kodunu ("fr") saklar, tam locale'i ("fr_FR") değil.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return string Bulunamazsa boş dize.
	 */
	private static function from_wpml( \WC_Order $order ): string {
		$code = (string) $order->get_meta( 'wpml_language' );

		if ( '' === $code ) {
			return '';
		}

		foreach ( \get_available_languages() as $available ) {
			if ( $available === $code || str_starts_with( $available, $code . '_' ) ) {
				return $available;
			}
		}

		return '';
	}

	/**
	 * Eklenti çevirilerini indirir.
	 *
	 * `wp_download_language_pack()` YALNIZCA çekirdek çevirilerini kurar. Bizim
	 * ve WooCommerce'in dizgeleri ayrı paketlerdir; onlar inmeden fatura
	 * üzerindeki etiketler İngilizce kalır. Bu, uzun süre bilinen bir eksikti
	 * (docs/I18N.md bölüm 9).
	 *
	 * WordPress bekleyen çeviri güncellemelerini yalnızca SİTENİN dilleri için
	 * listeler; hedef locale bu listeye geçici olarak eklenmelidir.
	 *
	 * @param string $locale Kurulacak locale.
	 * @return bool En az bir paket kurulduysa true.
	 */
	private static function install_plugin_translations( string $locale ): bool {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$add_locale = static function ( $locales ) use ( $locale ) {
			$locales   = \is_array( $locales ) ? $locales : array();
			$locales[] = $locale;

			return array_unique( $locales );
		};

		\add_filter( 'plugins_update_check_locales', $add_locale );
		\add_filter( 'themes_update_check_locales', $add_locale );

		\wp_clean_plugins_cache( true );
		\wp_update_plugins();

		\remove_filter( 'plugins_update_check_locales', $add_locale );
		\remove_filter( 'themes_update_check_locales', $add_locale );

		$updates = \wp_get_translation_updates();

		$wanted = array_values(
			array_filter(
				\is_array( $updates ) ? $updates : array(),
				static fn ( $update ): bool => isset( $update->language ) && $update->language === $locale
			)
		);

		if ( array() === $wanted ) {
			return false;
		}

		$upgrader = new \Language_Pack_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->bulk_upgrade( $wanted, array( 'clear_update_cache' => false ) );

		return \is_array( $result ) && array() !== array_filter( $result );
	}

	/**
	 * Locale'in kullanılabilir olduğunu garantiler; gerekirse dil paketini kurar.
	 *
	 * Kurulu olmayan bir locale'e geçmek sessizce mağazanın diline düşer — yani
	 * Fransız alıcı Türkçe fatura alır. Uyumluluk ürününde bu kabul edilemez,
	 * bu yüzden eksik dil paketi talep anında indirilir.
	 *
	 * Ağ çağrısı içerir. Fatura üretimi Action Scheduler kuyruğunda arka planda
	 * çalıştığı için bu kabul edilebilir; istek içinde çağrılmamalıdır.
	 *
	 * @param string $locale Aday locale.
	 * @return string Kullanılabilir locale.
	 */
	private static function installed( string $locale ): string {
		if ( '' === $locale ) {
			return \get_locale();
		}

		if ( 'en_US' === $locale ) {
			return $locale;
		}

		if ( \in_array( $locale, \get_available_languages(), true ) ) {
			/*
			 * Cekirdek paketi kurulu olsa bile eklenti cevirileri eksik
			 * olabilir; ikisi ayri paketlerdir ve bu ayrimi kacirmak, fatura
			 * etiketlerinin sessizce Ingilizce kalmasina yol acar.
			 */
			self::ensure_plugin_translations( $locale );

			return $locale;
		}

		if ( self::install_language_pack( $locale ) ) {
			return $locale;
		}

		return \get_locale();
	}

	/**
	 * Eklenti çevirilerinin bir kez indirilmesini sağlar.
	 *
	 * Sonuç bir gün hatırlanır; her fatura üretiminde güncelleme kontrolü
	 * yapmak kabul edilemez bir maliyettir.
	 *
	 * @param string $locale Locale.
	 * @return void
	 */
	private static function ensure_plugin_translations( string $locale ): void {
		$seen = \get_site_transient( 'konform_plugin_translations' );
		$seen = \is_array( $seen ) ? $seen : array();

		if ( isset( $seen[ $locale ] ) ) {
			return;
		}

		$seen[ $locale ] = \time();
		\set_site_transient( 'konform_plugin_translations', $seen, \DAY_IN_SECONDS );

		self::install_plugin_translations( $locale );
	}

	/**
	 * Eksik dil paketini WordPress.org'dan indirir.
	 *
	 * Başarısız denemeler bir gün boyunca hatırlanır; aksi hâlde her fatura
	 * üretiminde yeniden ağ çağrısı yapılır.
	 *
	 * @param string $locale Kurulacak locale.
	 * @return bool Kurulduysa true.
	 */
	private static function install_language_pack( string $locale ): bool {
		$failed = \get_site_transient( 'konform_locale_install_failed' );
		$failed = \is_array( $failed ) ? $failed : array();

		if ( isset( $failed[ $locale ] ) ) {
			return false;
		}

		if ( ! \function_exists( 'wp_download_language_pack' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$installed = \wp_download_language_pack( $locale );

		if ( \is_string( $installed ) && '' !== $installed ) {
			/*
			 * Cekirdek paketi geldi, ama bu YETMEZ: wp_download_language_pack
			 * yalnizca WordPress cekirdeginin cevirilerini indirir. Bizim ve
			 * WooCommerce'in dizgeleri ayri paketlerdir ve onlarsiz fatura
			 * ustundeki etiketler Ingilizce kalir.
			 */
			self::install_plugin_translations( $locale );

			return true;
		}

		$failed[ $locale ] = \time();
		\set_site_transient( 'konform_locale_install_failed', $failed, \DAY_IN_SECONDS );

		/**
		 * Belge dili için dil paketi kurulamadı.
		 *
		 * Fatura mağazanın dilinde üretilecek. Uyumluluk raporlaması bu kancaya
		 * bağlanabilir.
		 *
		 * @param string $locale Kurulamayan locale.
		 */
		\do_action( 'konform/language_pack_failed', $locale );

		return false;
	}
}
