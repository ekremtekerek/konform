<?php
/**
 * Toplamların tutarlılığı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Preflight\Rules;

use Konform\Invoice\SemanticInvoice;
use Konform\Preflight\Finding;
use Konform\Preflight\OrderRule;
use Konform\Preflight\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * BR-CO-13, BR-CO-15: toplamlar birbiriyle kuruşu kuruşuna tutmalıdır.
 *
 * Bu kural, faturaların reddedilmesinin en sinsi sebebini yakalar: eşlenmiş
 * faturanın toplamı ile WooCommerce'in sipariş toplamı arasındaki fark. Fark
 * bir kuruş bile olsa doğrulayıcı belgeyi reddeder ve satıcı sebebini asla
 * anlamaz.
 */
final class TotalsConsistency implements OrderRule {

	/**
	 * Kabul edilen en büyük fark.
	 */
	private const TOLERANCE = 0.01;

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'totals_consistency';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Invoice totals', 'konform' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SemanticInvoice $invoice Eşlenmiş fatura.
	 * @param \WC_Order       $order   Kaynak sipariş.
	 * @return Finding[]
	 */
	public function check( SemanticInvoice $invoice, \WC_Order $order ): array {
		$findings = array();
		$id       = $order->get_id();

		$mapped = $invoice->tax_inclusive_total();
		$actual = round( (float) $order->get_total(), 2 );
		$drift  = round( abs( $mapped - $actual ), 2 );

		if ( $drift > self::TOLERANCE ) {
			$findings[] = new Finding(
				$this->id(),
				'grand_total_drift',
				Severity::BLOCKER,
				$id,
				sprintf(
					/* translators: 1: total calculated from the invoice lines, 2: order total in WooCommerce, 3: the difference. */
					__( 'The invoice lines add up to %1$s but the order total is %2$s, a difference of %3$s.', 'konform' ),
					$this->money( $mapped, $invoice->currency ),
					$this->money( $actual, $invoice->currency ),
					$this->money( $drift, $invoice->currency )
				),
				__( 'Validators reject an invoice whose totals do not reconcile, even by one cent. This usually comes from an order-level discount, a coupon or a refund that is not represented on any line.', 'konform' ),
				__( 'Open the order and compare the line totals with the order total.', 'konform' ),
				'BT-112 / BR-CO-15'
			);
		}

		$tax_total = $invoice->tax_total();
		$order_tax = round( (float) $order->get_total_tax(), 2 );

		if ( round( abs( $tax_total - $order_tax ), 2 ) > self::TOLERANCE ) {
			$findings[] = new Finding(
				$this->id(),
				'vat_total_drift',
				Severity::BLOCKER,
				$id,
				sprintf(
					/* translators: 1: VAT total from the VAT breakdown, 2: VAT total recorded by WooCommerce. */
					__( 'The VAT breakdown totals %1$s but WooCommerce recorded %2$s.', 'konform' ),
					$this->money( $tax_total, $invoice->currency ),
					$this->money( $order_tax, $invoice->currency )
				),
				__( 'The sum of the VAT breakdown must equal the VAT charged. A gap means a rate was applied that Konform could not reconstruct from the order.', 'konform' ),
				__( 'Review the tax lines on the order and the rounding setting under WooCommerce > Settings > Tax.', 'konform' ),
				'BT-110 / BR-CO-13'
			);
		}

		return $findings;
	}

	/**
	 * Tutarı düz metin olarak biçimlendirir.
	 *
	 * WooCommerce fiyatı HTML olarak üretir; bulgu metinleri düz metin olmak zorundadır çünkü
	 * ekranda kaçışlanarak basılırlar.
	 *
	 * @param float  $amount   Tutar.
	 * @param string $currency Para birimi kodu.
	 * @return string
	 */
	private function money( float $amount, string $currency ): string {
		$formatted = \wc_price( $amount, array( 'currency' => $currency ) );

		return trim( \html_entity_decode( \wp_strip_all_tags( $formatted ), ENT_QUOTES, 'UTF-8' ) );
	}
}
