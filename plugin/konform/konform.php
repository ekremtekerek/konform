<?php
/**
 * Plugin Name:       Konform – EU E-Invoicing for WooCommerce
 * Plugin URI:        https://konform.dev/
 * Description:       Turns WooCommerce orders into legally valid e-invoices for the seller's country and delivers them through the seller's own e-invoicing provider.
 * Version:           0.1.0-dev
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Requires Plugins:  woocommerce
 * Author:            Cisoft
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       konform
 * Domain Path:       /languages
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0-dev';
const PLUGIN_FILE = __FILE__;

/**
 * Metin alanı. WordPress.org bunun eklenti slug'ına eşit olmasını şart koşar,
 * bu yüzden değeri kilitlidir. Bkz. docs/I18N.md bölüm 4.
 *
 * Not: __() çağrılarında sabit değil, daima 'konform' literali kullanılır —
 * WP-CLI tarayıcısı değişken metin alanını göremez.
 */
const TEXT_DOMAIN = 'konform';

/**
 * Composer autoloader'ı yükler.
 *
 * Makinede PHP kurulu olmadığı için bağımlılıklar Docker üzerinden kurulur:
 *   docker compose run --rm composer install
 *
 * @return bool Autoloader yüklendiyse true.
 */
function load_autoloader(): bool {
	$autoload = __DIR__ . '/vendor/autoload.php';

	if ( ! is_readable( $autoload ) ) {
		return false;
	}

	require_once $autoload;

	return true;
}

/**
 * Bağımlılıklar eksikken yönetici uyarısı gösterir.
 *
 * @param string $message Çevrilmiş uyarı metni.
 * @return void
 */
function admin_notice( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			printf(
				'<div class="notice notice-error"><p><strong>Konform</strong> — %s</p></div>',
				esc_html( $message )
			);
		}
	);
}

/**
 * Eklentiyi başlatır.
 *
 * @return void
 */
function bootstrap(): void {
	if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
		admin_notice(
			sprintf(
				/* translators: 1: required PHP version, 2: PHP version running on the server. */
				__( 'Konform requires PHP %1$s or later. This server is running PHP %2$s.', 'konform' ),
				'8.2',
				PHP_VERSION
			)
		);

		return;
	}

	if ( ! load_autoloader() ) {
		admin_notice(
			__( 'Konform dependencies are missing. Run "composer install" in the plugin directory.', 'konform' )
		);

		return;
	}

	/*
	 * Freemius kopru fonksiyonu GENEL ad alanindadir ve SDK'yi kendisi
	 * baslatir. Kimlik bilgileri girilmemisse etkisizdir; eklenti Freemius
	 * olmadan calismaya devam eder.
	 */
	require_once __DIR__ . '/freemius.php';
	konform_fs();

	Plugin::instance()->boot();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 5 );

/**
 * HPOS (yüksek performanslı sipariş depolama) uyumluluğunu bildirir.
 *
 * Sipariş verisiyle çalışan bir eklentide bu bildirim zorunludur; aksi hâlde
 * WooCommerce eklentiyi uyumsuz olarak işaretler ve HPOS'u devre dışı bırakır.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			PLUGIN_FILE,
			true
		);
	}
);
