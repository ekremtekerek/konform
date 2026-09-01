<?php
/**
 * EN 16931 anlamsal fatura modeli.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * Sözdiziminden bağımsız fatura gösterimi.
 *
 * EN 16931 iki katmanlıdır: anlamsal veri modeli (BT-* alanları) ve onu taşıyan
 * sözdizimi (CII, UBL). Bu sınıf yalnızca anlamsal katmandır — hangi ülkeye
 * hangi formatta gideceğini bilmez. Faz 2'deki üretici bunu sözdizimine çevirir.
 */
final class SemanticInvoice {

	/**
	 * Kurucu.
	 *
	 * @param string                  $number        Fatura numarası. BT-1.
	 * @param \DateTimeImmutable      $issue_date Düzenlenme tarihi. BT-2.
	 * @param string                  $type_code     Belge tipi kodu (UNTDID 1001). BT-3.
	 * @param string                  $currency      Para birimi (ISO 4217). BT-5.
	 * @param Party                   $seller        Satıcı. BG-4.
	 * @param Party                   $buyer         Alıcı. BG-7.
	 * @param Line[]                  $lines         Fatura satırları. BG-25.
	 * @param TaxSubtotal[]           $tax_subtotals Vergi kırılımı. BG-23.
	 * @param float                   $paid_amount   Ödenmiş tutar. BT-113.
	 * @param \DateTimeImmutable|null $delivery_date Fiili teslim tarihi. BT-72.
	 * @param Party|null              $ship_to       Teslim adresi. BG-13.
	 * @param string|null             $preceding_invoice_number Atif yapilan fatura. BT-25.
	 * @param \DateTimeImmutable|null $preceding_invoice_date  Atıf yapılan faturanın tarihi. BT-26.
	 */
	public function __construct(
		public readonly string $number,
		public readonly \DateTimeImmutable $issue_date,
		public readonly string $type_code,
		public readonly string $currency,
		public readonly Party $seller,
		public readonly Party $buyer,
		public readonly array $lines,
		public readonly array $tax_subtotals,
		public readonly float $paid_amount = 0.0,
		public readonly ?\DateTimeImmutable $delivery_date = null,
		public readonly ?Party $ship_to = null,
		public readonly ?string $preceding_invoice_number = null,
		public readonly ?\DateTimeImmutable $preceding_invoice_date = null,
	) {}

	/**
	 * Satır net tutarlarının toplamı. BT-106.
	 *
	 * @return float
	 */
	public function line_net_total(): float {
		$total = 0.0;

		foreach ( $this->lines as $line ) {
			$total += $line->net_amount;
		}

		return round( $total, 2 );
	}

	/**
	 * Vergi hariç toplam. BT-109.
	 *
	 * Faz 1'de belge düzeyi indirim ve masraf desteklenmediği için satır
	 * toplamına eşittir.
	 *
	 * @return float
	 */
	public function tax_exclusive_total(): float {
		return $this->line_net_total();
	}

	/**
	 * Toplam vergi tutarı. BT-110.
	 *
	 * @return float
	 */
	public function tax_total(): float {
		$total = 0.0;

		foreach ( $this->tax_subtotals as $subtotal ) {
			$total += $subtotal->tax_amount;
		}

		return round( $total, 2 );
	}

	/**
	 * Vergi dahil toplam. BT-112.
	 *
	 * @return float
	 */
	public function tax_inclusive_total(): float {
		return round( $this->tax_exclusive_total() + $this->tax_total(), 2 );
	}

	/**
	 * Ödenecek tutar. BT-115.
	 *
	 * @return float
	 */
	public function due_amount(): float {
		return round( $this->tax_inclusive_total() - $this->paid_amount, 2 );
	}

	/**
	 * Faturada kullanılan benzersiz vergi kategorisi kodlarını döndürür.
	 *
	 * @return string[]
	 */
	public function tax_categories(): array {
		$categories = array();

		foreach ( $this->tax_subtotals as $subtotal ) {
			$categories[ $subtotal->category ] = true;
		}

		return array_keys( $categories );
	}
}
