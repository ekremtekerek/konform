<?php
/**
 * Sipariş kapsamlı kural.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Preflight;

use Konform\Invoice\SemanticInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Her sipariş için ayrı ayrı çalışan kural.
 */
interface OrderRule extends Rule {

	/**
	 * Siparişi denetler.
	 *
	 * @param SemanticInvoice $invoice Eşlenmiş fatura.
	 * @param \WC_Order       $order   Kaynak sipariş.
	 * @return Finding[]
	 */
	public function check( SemanticInvoice $invoice, \WC_Order $order ): array;
}
