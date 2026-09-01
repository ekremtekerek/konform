<?php
/**
 * Freemius SDK bağlantısı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\License;

defined( 'ABSPATH' ) || exit;

/**
 * Freemius SDK'sını başlatır.
 *
 * SDK BİLEREK öneklenmez (Strauss dışında bırakılır). Freemius, aynı sitedeki
 * eklentiler arasında en yeni SDK sürümünün kazandığı bir tahkim mekanizması
 * kullanır ve bu, global `Freemius` sınıfının paylaşılmasına dayanır.
 * Öneklemek lisanslamayı ve güncellemeleri bozar.
 *
 * Kimlik bilgileri girilene kadar sınıf ETKİSİZDİR: eklenti Freemius olmadan
 * çalışmaya devam eder ve ücretsiz plana düşer. Böylece geliştirme ve testler
 * satıcı hesabı gerektirmez.
 */
final class Freemius {

	/**
	 * Freemius ürün kimliği.
	 *
	 * Freemius panelinden alınır: Settings → Keys.
	 * Girilene kadar SDK hiç yüklenmez.
	 */
	private const PRODUCT_ID = '';

	/**
	 * Freemius genel anahtarı (public key).
	 *
	 * Bu bir sır DEĞİLDİR; istemci tarafında görünür olması normaldir.
	 * Gizli anahtar (secret key) burada kullanılmaz ve depoya girmez.
	 */
	private const PUBLIC_KEY = '';

	/**
	 * SDK örneği.
	 *
	 * @var object|null
	 */
	private static ?object $instance = null;

	/**
	 * Kimlik bilgileri girilmiş mi.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::PRODUCT_ID && '' !== self::PUBLIC_KEY;
	}

	/**
	 * SDK örneğini döndürür.
	 *
	 * @return object|null Yapılandırılmamışsa null.
	 */
	public static function instance(): ?object {
		if ( null !== self::$instance ) {
			return self::$instance;
		}

		if ( ! self::is_configured() ) {
			return null;
		}

		$sdk = \dirname( \Konform\PLUGIN_FILE ) . '/vendor/freemius/wordpress-sdk/start.php';

		if ( ! \is_readable( $sdk ) ) {
			return null;
		}

		require_once $sdk;

		if ( ! \function_exists( 'fs_dynamic_init' ) ) {
			return null;
		}

		self::$instance = \fs_dynamic_init(
			array(
				'id'             => self::PRODUCT_ID,
				'slug'           => 'konform',
				'type'           => 'plugin',
				'public_key'     => self::PUBLIC_KEY,
				'is_premium'     => false,
				'has_addons'     => false,
				'has_paid_plans' => true,
				'menu'           => array(
					'slug'    => 'konform',
					'support' => false,
					'parent'  => array(
						'slug' => 'woocommerce',
					),
				),
			)
		);

		/*
		 * SDK'nin yuklendigini bildirir; Freemius bunu bekler.
		 */
		\do_action( 'konform_fs_loaded' );

		return self::$instance;
	}
}
