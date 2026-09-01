<?php
/**
 * Arka plan kuyruğu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Queue;

use Konform\Invoice\Generator;
use Konform\License\Licensing;
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
	 * Şu an kuyruktan çalışan bir iş var mı.
	 *
	 * Arka planda kimse ekran başında beklemiyor, dolayısıyla doğrulama
	 * servisine tanınan süre uzatılabilir. Action Scheduler'ın bulunmadığı
	 * senkron yedek yolda bu bayrak KURULMAZ: orada bir müşterinin ödeme
	 * isteği ya da bir yöneticinin ekranı bekliyor olabilir.
	 *
	 * @var bool
	 */
	private static bool $in_background = false;

	/**
	 * Kuyruktan çalışan bir işin içinde miyiz.
	 *
	 * @return bool
	 */
	public static function is_running_in_background(): bool {
		return self::$in_background;
	}

	/**
	 * Kancaları kaydeder.
	 *
	 * @return void
	 */
	/**
	 * İade faturası kuyruk kancası.
	 */
	public const HOOK_CREDIT_NOTE = 'konform_generate_credit_note';

	/**
	 * Kancaları kaydeder.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_completed', array( self::class, 'enqueue' ), 20, 1 );
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );

		/*
		 * Iade, asil faturayi degistirmez; ayri bir iade faturasi uretilir.
		 * WooCommerce'te iade siradan bir olaydir ve bunu atlamak, arsivin
		 * gercek durumu yansitmamasina yol acar.
		 */
		add_action( 'woocommerce_order_refunded', array( self::class, 'enqueue_credit_note' ), 20, 2 );
		add_action( self::HOOK_CREDIT_NOTE, array( self::class, 'run_credit_note' ), 10, 1 );
	}

	/**
	 * İade faturasını kuyruğa alır.
	 *
	 * @param int $order_id  Sipariş kimliği.
	 * @param int $refund_id İade kimliği.
	 * @return void
	 */
	public static function enqueue_credit_note( int $order_id, int $refund_id ): void {
		if ( $order_id <= 0 || $refund_id <= 0 ) {
			return;
		}

		if ( ! Licensing::has_automatic_generation() ) {
			return;
		}

		$args = array( 'refund_id' => $refund_id );

		if ( ! self::is_available() ) {
			self::generate_credit_note_for( $refund_id );

			return;
		}

		if ( \as_has_scheduled_action( self::HOOK_CREDIT_NOTE, $args, self::GROUP ) ) {
			return;
		}

		\as_enqueue_async_action( self::HOOK_CREDIT_NOTE, $args, self::GROUP );

		AuditLog::record( AuditLog::EVENT_QUEUED, $order_id, 0, 'Credit note for refund #' . $refund_id );
	}

	/**
	 * Kuyruktaki iade faturası işini çalıştırır.
	 *
	 * @param int $refund_id İade kimliği.
	 * @return void
	 */
	public static function run_credit_note( int $refund_id ): void {
		self::$in_background = true;

		try {
			self::generate_credit_note_for( $refund_id );
		} finally {
			self::$in_background = false;
		}
	}

	/**
	 * İade faturasını üretir.
	 *
	 * Run_credit_note() ile ayrı tutulur; gerekçe generate_for() ile aynıdır.
	 *
	 * @param int $refund_id İade kimliği.
	 * @return void
	 */
	private static function generate_credit_note_for( int $refund_id ): void {
		$refund = \wc_get_order( $refund_id );

		if ( ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$order = \wc_get_order( $refund->get_parent_id() );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		Generator::generate_credit_note( $refund, $order );
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

		/*
		 * Ucretsiz surumde uretim elle tetiklenir; otomatik akis Pro'nun
		 * satis gerekcelerinden biridir. Elle uretim her planda calisir.
		 */
		if ( ! Licensing::has_automatic_generation() ) {
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
			self::generate_for( $order_id );

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
		self::$in_background = true;

		try {
			self::generate_for( $order_id );
		} finally {
			self::$in_background = false;
		}
	}

	/**
	 * Belgeyi üretir.
	 *
	 * Run() ile ayrı tutulur: run() kuyruk kancasıdır ve arka plan bayrağını
	 * kurar, bu ise Action Scheduler yokken senkron yedek yoldan da çağrılır.
	 *
	 * @param int $order_id Sipariş kimliği.
	 * @return void
	 */
	private static function generate_for( int $order_id ): void {
		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		Generator::generate( $order );
	}
}
