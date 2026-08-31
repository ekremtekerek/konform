<?php
/**
 * Fatura satırı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * EN 16931 BG-25 (INVOICE LINE) karşılığı.
 */
final class Line {

	/**
	 * WooCommerce'te birim kavramı yoktur; UN/ECE Rec 20'de "adet" C62'dir.
	 */
	public const DEFAULT_UNIT = 'C62';

	/**
	 * Kurucu.
	 *
	 * @param string $id           Satır tanımlayıcısı. BT-126.
	 * @param string $name         Ürün adı. BT-153.
	 * @param float  $quantity     Miktar. BT-129.
	 * @param string $unit_code    Birim kodu (UN/ECE Rec 20). BT-130.
	 * @param float  $net_price    Birim net fiyat. BT-146.
	 * @param float  $net_amount   Satır net tutarı. BT-131.
	 * @param string $tax_category Vergi kategorisi kodu (UNTDID 5305). BT-151.
	 * @param float  $tax_rate     Vergi oranı, yüzde. BT-152.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly float $quantity,
		public readonly string $unit_code,
		public readonly float $net_price,
		public readonly float $net_amount,
		public readonly string $tax_category,
		public readonly float $tax_rate,
	) {}
}
