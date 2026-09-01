<?php
/**
 * Zugferd kütüphanesi adaptörü.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

use Konform\Vendor\horstoeko\zugferd\ZugferdDocumentBuilder;
use Konform\Vendor\horstoeko\zugferd\ZugferdProfiles;

defined( 'ABSPATH' ) || exit;

/**
 * Anlamsal faturayı CII sözdizimine çevirir.
 *
 * Kod tabanında zugferd kütüphanesine dokunan TEK sınıf burasıdır. ADR 0001'in
 * gerekçesi buydu: kütüphane 1.0.x kararlı olsa da bir gün değişebilir ve o
 * değişimin yarıçapı tek dosyayla sınırlı kalmalıdır.
 *
 * Kütüphane Strauss ile Konform\Vendor\ altına taşınmıştır; öneksiz ada
 * başvurmak çalışma anında sınıf bulunamadı hatası verir.
 */
final class ZugferdBuilder implements DocumentBuilder {

	/**
	 * Vergi tipi kodu — UNTDID 5153. KDV için daima "VAT".
	 */
	private const TAX_TYPE = 'VAT';

	/**
	 * {@inheritDoc}
	 *
	 * @param Profile $profile Belge profili.
	 * @return bool
	 */
	public function supports( Profile $profile ): bool {
		return in_array( $profile, array( Profile::FACTUR_X, Profile::XRECHNUNG, Profile::EN16931 ), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @param Profile         $profile Belge profili.
	 * @return string
	 * @throws \RuntimeException Profil desteklenmiyorsa.
	 */
	public function build_xml( SemanticInvoice $invoice, Profile $profile ): string {
		if ( ! $this->supports( $profile ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Konform: unsupported document profile "%s".', $profile->value ) )
			);
		}

		$document = ZugferdDocumentBuilder::createNew( $this->library_profile( $profile ) );

		$document->setDocumentInformation(
			$invoice->number,
			$invoice->type_code,
			$invoice->issue_date,
			$invoice->currency
		);

		$this->apply_buyer_reference( $document, $invoice, $profile );
		$this->apply_seller( $document, $invoice->seller );
		$this->apply_buyer( $document, $invoice->buyer );
		$this->apply_lines( $document, $invoice );
		$this->apply_tax_breakdown( $document, $invoice );
		$this->apply_summation( $document, $invoice );

		return (string) $document->getContent();
	}

	/**
	 * Profili kütüphane sabitine çevirir.
	 *
	 * @param Profile $profile Belge profili.
	 * @return int
	 */
	private function library_profile( Profile $profile ): int {
		return match ( $profile ) {
			Profile::XRECHNUNG => ZugferdProfiles::PROFILE_XRECHNUNG_3,
			default            => ZugferdProfiles::PROFILE_EN16931,
		};
	}

	/**
	 * Alıcı referansını (BT-10) yazar.
	 *
	 * XRechnung'da bu alan ZORUNLUDUR — kamu alımlarında Leitweg-ID taşır.
	 * Mağazada böyle bir alan olmadığı için fatura numarasına düşülür ve
	 * gerçek değeri sağlamak için kanca sunulur.
	 *
	 * @param ZugferdDocumentBuilder $document Belge.
	 * @param SemanticInvoice        $invoice  Fatura.
	 * @param Profile                $profile  Profil.
	 * @return void
	 */
	private function apply_buyer_reference( ZugferdDocumentBuilder $document, SemanticInvoice $invoice, Profile $profile ): void {
		/**
		 * Alıcı referansını (BT-10) değiştirir.
		 *
		 * Alman kamu kurumlarına kesilen faturalarda Leitweg-ID buraya yazılır.
		 *
		 * @param string          $reference Referans.
		 * @param SemanticInvoice $invoice   Fatura.
		 * @param Profile         $profile   Profil.
		 */
		$reference = (string) \apply_filters( 'konform/buyer_reference', $invoice->number, $invoice, $profile );

		if ( '' !== $reference ) {
			$document->setDocumentBuyerReference( $reference );
		}
	}

	/**
	 * Satıcı bilgilerini yazar.
	 *
	 * @param ZugferdDocumentBuilder $document Belge.
	 * @param Party                  $seller   Satıcı.
	 * @return void
	 */
	private function apply_seller( ZugferdDocumentBuilder $document, Party $seller ): void {
		$document->setDocumentSeller( $seller->name );

		if ( '' !== $seller->vat_number ) {
			$document->addDocumentSellerTaxRegistration( 'VA', $seller->vat_number );
		}

		$document->setDocumentSellerAddress(
			$seller->address,
			null,
			null,
			$seller->postcode,
			$seller->city,
			$seller->country
		);

		if ( '' !== $seller->email ) {
			$document->setDocumentSellerContact( null, null, null, null, $seller->email );
		}
	}

	/**
	 * Alıcı bilgilerini yazar.
	 *
	 * @param ZugferdDocumentBuilder $document Belge.
	 * @param Party                  $buyer    Alıcı.
	 * @return void
	 */
	private function apply_buyer( ZugferdDocumentBuilder $document, Party $buyer ): void {
		$document->setDocumentBuyer( $buyer->name );

		if ( '' !== $buyer->vat_number ) {
			$document->addDocumentBuyerTaxRegistration( 'VA', $buyer->vat_number );
		}

		$document->setDocumentBuyerAddress(
			$buyer->address,
			null,
			null,
			$buyer->postcode,
			$buyer->city,
			$buyer->country
		);

		if ( '' !== $buyer->email ) {
			$document->setDocumentBuyerContact( null, null, null, null, $buyer->email );
		}
	}

	/**
	 * Fatura satırlarını yazar.
	 *
	 * @param ZugferdDocumentBuilder $document Belge.
	 * @param SemanticInvoice        $invoice  Fatura.
	 * @return void
	 */
	private function apply_lines( ZugferdDocumentBuilder $document, SemanticInvoice $invoice ): void {
		foreach ( $invoice->lines as $line ) {
			$document->addNewPosition( $line->id );
			$document->setDocumentPositionProductDetails( $line->name );
			$document->setDocumentPositionNetPrice( $line->net_price );
			$document->setDocumentPositionQuantity( $line->quantity, $line->unit_code );

			$document->addDocumentPositionTax(
				$line->tax_category,
				self::TAX_TYPE,
				$line->tax_rate
			);

			$document->setDocumentPositionLineSummation( $line->net_amount );
		}
	}

	/**
	 * Vergi kırılımını yazar.
	 *
	 * Vergisiz kategorilerde istisna gerekçesi eklenir; onsuz belge
	 * doğrulamadan geçmez.
	 *
	 * @param ZugferdDocumentBuilder $document Belge.
	 * @param SemanticInvoice        $invoice  Fatura.
	 * @return void
	 */
	private function apply_tax_breakdown( ZugferdDocumentBuilder $document, SemanticInvoice $invoice ): void {
		foreach ( $invoice->tax_subtotals as $subtotal ) {
			$needs_reason = ExemptionReason::is_required( $subtotal->category );

			$reason = $needs_reason
				? ( '' !== $subtotal->exemption_reason ? $subtotal->exemption_reason : ExemptionReason::text( $subtotal->category ) )
				: '';

			$code = $needs_reason
				? ( '' !== $subtotal->exemption_code ? $subtotal->exemption_code : ExemptionReason::code( $subtotal->category ) )
				: '';

			$document->addDocumentTax(
				$subtotal->category,
				self::TAX_TYPE,
				$subtotal->basis_amount,
				$subtotal->tax_amount,
				$subtotal->rate,
				'' !== $reason ? $reason : null,
				'' !== $code ? $code : null
			);
		}
	}

	/**
	 * Toplamları yazar.
	 *
	 * BR-CO-13 ve BR-CO-15 gereği bu değerler satırlarla kuruşu kuruşuna
	 * tutmak zorundadır; hesaplama SemanticInvoice içinde tek yerde yapılır.
	 *
	 * @param ZugferdDocumentBuilder $document Belge.
	 * @param SemanticInvoice        $invoice  Fatura.
	 * @return void
	 */
	private function apply_summation( ZugferdDocumentBuilder $document, SemanticInvoice $invoice ): void {
		$document->setDocumentSummation(
			$invoice->tax_inclusive_total(),
			$invoice->due_amount(),
			$invoice->line_net_total(),
			0.0,
			0.0,
			$invoice->tax_exclusive_total(),
			$invoice->tax_total(),
			0.0,
			$invoice->paid_amount
		);
	}
}
