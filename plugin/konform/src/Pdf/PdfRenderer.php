<?php
/**
 * PDF kaynağı seçimi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Pdf;

use Konform\Invoice\SemanticInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Kullanılabilir PDF kaynakları arasından ilkini seçer.
 *
 * Sıra bilinçlidir: mağazanın kendi fatura eklentisi varsa onun çıktısı
 * kazanır, çünkü mağazanın markasını taşır ve tam UTF-8'dir. Kendi sade
 * şablonumuz yalnızca son çaredir.
 */
final class PdfRenderer {

	/**
	 * Kaynakları öncelik sırasıyla döndürür.
	 *
	 * @return PdfSource[]
	 */
	public static function sources(): array {
		$sources = array(
			new WcpdfSource(),
			new BuiltinPdfSource(),
		);

		/**
		 * PDF kaynaklarını değiştirir.
		 *
		 * Başka bir fatura eklentisiyle entegrasyon buraya eklenir. Sıra
		 * önceliği belirler.
		 *
		 * @param PdfSource[] $sources Kaynaklar.
		 */
		$sources = (array) \apply_filters( 'konform/pdf_sources', $sources );

		return array_values(
			array_filter( $sources, static fn ( $source ): bool => $source instanceof PdfSource )
		);
	}

	/**
	 * İlk kullanılabilir kaynakla PDF üretir.
	 *
	 * @param \WC_Order       $order   Sipariş.
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @return string
	 * @throws \RuntimeException Hiçbir kaynak üretemezse; mesaj denenen
	 *                           kaynakların sebeplerini taşır.
	 */
	public static function render( \WC_Order $order, SemanticInvoice $invoice ): string {
		$reasons = array();

		foreach ( self::sources() as $source ) {
			if ( ! $source->is_available() ) {
				continue;
			}

			try {
				return $source->render( $order, $invoice );
			} catch ( \Throwable $error ) {
				$reasons[] = $source->label() . ': ' . $error->getMessage();
			}
		}

		$message = array() === $reasons
			? 'No PDF source is available.'
			: 'No PDF source could render the invoice. ' . implode( ' | ', $reasons );

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil; kacislamak metni bozar.
		throw new \RuntimeException( $message );
	}
}
