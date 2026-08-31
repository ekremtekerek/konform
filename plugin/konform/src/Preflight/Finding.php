<?php
/**
 * Tek bir ön uçuş bulgusu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Preflight;

defined( 'ABSPATH' ) || exit;

/**
 * Bir bulgu üç soruyu birden cevaplamak zorundadır: ne oldu, neden önemli,
 * nereden düzeltilir.
 *
 * Bu metinler ürünün en önemli metinleridir — destek talebinin yerine
 * geçmeleri beklenir. "Geçersiz sipariş" demek yerine, hangi alanın hangi
 * kural yüzünden eksik olduğunu ve nereden düzeltileceğini söylerler.
 * Bkz. docs/I18N.md bölüm 6.
 */
final class Finding {

	/**
	 * Mağaza genelindeki bulgular için sipariş kimliği.
	 */
	public const STORE_WIDE = 0;

	/**
	 * Kurucu.
	 *
	 * @param string   $rule_id  Bulguyu üreten kuralın kimliği.
	 * @param string   $code     Sorunun kural içindeki alt kimliği. Aynı kural
	 *                           birden fazla farklı sorun bildirebildiği için
	 *                           gruplama bu koda göre yapılır.
	 * @param Severity $severity Ciddiyet.
	 * @param int      $order_id Sipariş kimliği; mağaza geneli için 0.
	 * @param string   $what     Ne oldu.
	 * @param string   $why      Neden önemli (hangi kural, hangi ülke).
	 * @param string   $fix      Nereden düzeltilir.
	 * @param string   $standard İlgili EN 16931 iş kuralı veya alan kodu.
	 */
	public function __construct(
		public readonly string $rule_id,
		public readonly string $code,
		public readonly Severity $severity,
		public readonly int $order_id,
		public readonly string $what,
		public readonly string $why,
		public readonly string $fix,
		public readonly string $standard = '',
	) {}

	/**
	 * Gruplama anahtarı.
	 *
	 * @return string
	 */
	public function group_key(): string {
		return $this->rule_id . ':' . $this->code;
	}

	/**
	 * Bulgu mağaza geneli mi.
	 *
	 * @return bool
	 */
	public function is_store_wide(): bool {
		return self::STORE_WIDE === $this->order_id;
	}

	/**
	 * Siparişin yönetici düzenleme bağlantısı.
	 *
	 * @return string
	 */
	public function order_url(): string {
		if ( $this->is_store_wide() ) {
			return '';
		}

		$order = \wc_get_order( $this->order_id );

		return $order instanceof \WC_Order ? $order->get_edit_order_url() : '';
	}
}
