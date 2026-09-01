<?php
/**
 * Arka plan kuyruğu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Queue;

use Konform\Invoice\Generator;
use Konform\Storage\AuditLog;

defined( 'ABSPATH' ) || exit;

/**
 * Belge üretimini istek dışına taşır.
 *
 * Üretim XML serileştirme, dosya yazma ve gerektiğinde dil paketi indirme
 * içerir. Bunları müşterinin ödeme isteği içinde çalıştırmak, ödeme sayfasını
 * yavaşlatır ve zaman aşımı hâlinde siparişi belirsiz bir durumda bırakır.
 *
 * WooCommerce'in paketlediği Action Scheduler kullanılır; ayrı bir bağımlılık
 * eklenmez.
 */
final class Scheduler {

	/**
	 * Kuyruk kancası.
	 */
	public const HOOK = 'konform_generate_document';

	/**
	 * Action Scheduler grubu.
	 *
	 * Kendi işlerimizi diğer eklentilerinkinden ayırır; sorun giderirken
	 * ve temizlik yaparken gereklidir.
	 */
	public const GROUP = 'konform';

	/**
	 * Kancaları kaydeder.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_completed', array( self::class, 'enqueue' ), 20, 1 );
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
	}

	/**
	 * Action Scheduler kullanılabilir mi.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return \function_exists( 'as_enqueue_async_action' ) && \function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Siparişi üretim kuyruğuna alır.
	 *
	 * @param int $order_id Sipariş kimliği.
	 * @return void
	 */
	public static function enqueue( int $order_id ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		/**
		 * Sipariş için belge üretilip üretilmeyeceğini belirler.
		 *
		 * @param bool $should   Üretilsin mi.
		 * @param int  $order_id Sipariş kimliği.
		 */
		if ( ! \apply_filters( 'konform/should_generate', true, $order_id ) ) {
			return;
		}

		$args = array( 'order_id' => $order_id );

		if ( ! self::is_available() ) {
			// Action Scheduler yoksa senkron uretiriz; sessizce atlamaktan iyidir.
			self::run( $order_id );

			return;
		}

		// Ayni siparis icin bekleyen is varsa ikinci kez kuyruga alma.
		if ( \as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}

		\as_enqueue_async_action( self::HOOK, $args, self::GROUP );

		AuditLog::record( AuditLog::EVENT_QUEUED, $order_id );
	}

	/**
	 * Kuyruktaki işi çalıştırır.
	 *
	 * @param int $order_id Sipariş kimliği.
	 * @return void
	 */
	public static function run( int $order_id ): void {
		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		Generator::generate( $order );
	}
}
