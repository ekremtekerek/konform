<?php
/**
 * Eklenti çekirdeği.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform;

use Konform\Admin\PreflightPage;
use Konform\I18n\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Eklentinin yaşam döngüsünü ve kanca kayıtlarını yönetir.
 */
final class Plugin {

	/**
	 * Tekil örnek.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Çift başlatmayı engeller.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Tekil örneği döndürür.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Doğrudan örneklemeyi engeller.
	 */
	private function __construct() {}

	/**
	 * Kancaları kaydeder.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		/*
		 * Çeviriler 'init' üzerinde yüklenir. Daha erken yüklemek WP 6.7+ sürümünde
		 * "_load_textdomain_just_in_time was called incorrectly" uyarısı üretir.
		 */
		add_action( 'init', array( $this, 'load_translations' ) );

		/*
		 * switch_to_locale() sonrası eklenti çevirilerinin yeni dile göre yeniden
		 * yüklenmesi gerekir; aksi hâlde belge, admin dilinde üretilir.
		 */
		add_action( 'change_locale', array( $this, 'reload_translations' ) );

		/*
		 * Siparişin dili oluşturulduğu anda yazılır. Sonradan geriye dönük doğru
		 * dili tahmin etmek mümkün değildir. Bkz. docs/I18N.md bölüm 2.
		 */
		add_action( 'woocommerce_checkout_create_order', array( Locale::class, 'capture' ), 10, 1 );
		add_action( 'woocommerce_new_order', array( Locale::class, 'capture_by_id' ), 10, 1 );

		if ( is_admin() ) {
			PreflightPage::register();
		}
	}

	/**
	 * Metin alanını yükler.
	 *
	 * Ücretsiz sürümde çeviriler translate.wordpress.org'dan gelir ve WordPress
	 * bunları kendiliğinden yükler. Bu çağrı Pro sürümün kendi .mo dosyaları için
	 * gereklidir. Bkz. docs/I18N.md bölüm 4.
	 *
	 * @return void
	 */
	public function load_translations(): void {
		load_plugin_textdomain(
			'konform',
			false,
			dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Dil değiştiğinde metin alanını yeniden yükler.
	 *
	 * @param string $locale Yeni locale.
	 * @return void
	 */
	public function reload_translations( string $locale ): void {
		unload_textdomain( 'konform' );
		$this->load_translations();

		unset( $locale );
	}
}
