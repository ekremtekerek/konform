<?php
/**
 * WooCommerce PDF Invoices & Packing Slips entegrasyonu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Pdf;

use Konform\Invoice\SemanticInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Mağazada WooCommerce PDF Invoices & Packing Slips kuruluysa onun ürettiği
 * faturayı kullanır.
 *
 * Tercih edilen kaynak budur: mağazanın kendi şablonunu, logosunu ve
 * düzenini taşır, tam UTF-8 destekler ve satıcı onu zaten müşteriye
 * gönderiyordur. Aynı belgenin makine okunur karşılığını ona iliştirmek,
 * ikinci bir görsel fatura üretmekten her açıdan iyidir.
 */
final class WcpdfSource implements PdfSource {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'wcpdf';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return 'WooCommerce PDF Invoices & Packing Slips';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return \function_exists( 'wcpdf_get_document' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order       $order   Sipariş.
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @return string
	 * @throws \RuntimeException Belge alınamazsa.
	 */
	public function render( \WC_Order $order, SemanticInvoice $invoice ): string {
		unset( $invoice );

		if ( ! $this->is_available() ) {
			throw new \RuntimeException( 'WooCommerce PDF Invoices is not active.' );
		}

		$document = \wcpdf_get_document( 'invoice', $order, true );

		if ( ! is_object( $document ) || ! method_exists( $document, 'get_pdf' ) ) {
			throw new \RuntimeException( 'WooCommerce PDF Invoices did not return an invoice document.' );
		}

		$pdf = $document->get_pdf();

		if ( ! is_string( $pdf ) || '' === $pdf ) {
			throw new \RuntimeException( 'WooCommerce PDF Invoices returned an empty document.' );
		}

		return $pdf;
	}
}
