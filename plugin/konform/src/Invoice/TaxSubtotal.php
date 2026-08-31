<?php
/**
 * Vergi kırılımı satırı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * EN 16931 BG-23 (VAT BREAKDOWN) karşılığı.
 *
 * Faturadaki her (kategori, oran) çifti için tam olarak bir kırılım satırı
 * bulunmak zorundadır — BR-CO-18.
 */
final class TaxSubtotal {

	/**
	 * Kurucu.
	 *
	 * @param string $category         Vergi kategorisi kodu. BT-118.
	 * @param float  $rate             Vergi oranı, yüzde. BT-119.
	 * @param float  $basis_amount     Matrah. BT-116.
	 * @param float  $tax_amount       Vergi tutarı. BT-117.
	 * @param string $exemption_reason İstisna gerekçesi metni. BT-120.
	 * @param string $exemption_code   İstisna gerekçe kodu. BT-121.
	 */
	public function __construct(
		public readonly string $category,
		public readonly float $rate,
		public readonly float $basis_amount,
		public readonly float $tax_amount,
		public readonly string $exemption_reason = '',
		public readonly string $exemption_code = '',
	) {}

	/**
	 * Bu kırılımın istisna gerekçesi gerektirip gerektirmediğini bildirir.
	 *
	 * BR-AE-10, BR-E-10, BR-G-10, BR-K-10: vergi alınmayan kategorilerde
	 * gerekçe zorunludur.
	 *
	 * @return bool
	 */
	public function needs_exemption_reason(): bool {
		return in_array( $this->category, array( 'AE', 'E', 'G', 'K', 'O' ), true );
	}
}
