<?php
/**
 * Alıcı kimlik alanları.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Preflight\Rules;

use Konform\Invoice\Eu;
use Konform\Invoice\SemanticInvoice;
use Konform\Preflight\Finding;
use Konform\Preflight\OrderRule;
use Konform\Preflight\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * BR-07, BR-11, BR-AE-09, BR-K-09: alıcının adı ve ülkesi her zaman; KDV
 * numarası ise vergisiz AB içi B2B teslimlerde zorunludur.
 *
 * Ürünün bulduğu en değerli sorun budur ve en sık rastlanandır: mağaza AB içi
 * B2B satış yapıyor, KDV almıyor, ama müşterinin KDV numarasını hiç toplamamış.
 *
 * Önemli: bu denetim vergi KATEGORİSİNE bakarak yapılamaz. KDV numarası
 * olmadığında eşleyici zaten K/AE kategorisini seçemez ve Z'ye düşer — yani
 * kategoriye bakan bir kontrol tam da yakalaması gereken durumu kaçırır.
 * Bu yüzden koşul doğrudan taraflardan ve alınan vergiden türetilir.
 */
final class BuyerIdentity implements OrderRule {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'buyer_identity';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Customer identity', 'konform' );
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
		$buyer    = $invoice->buyer;

		if ( '' === trim( $buyer->name ) ) {
			$findings[] = new Finding(
				$this->id(),
				'name_missing',
				Severity::BLOCKER,
				$id,
				__( 'The order has no customer name.', 'konform' ),
				__( 'The buyer name is mandatory on every invoice.', 'konform' ),
				__( 'Open the order and fill in the billing name or company.', 'konform' ),
				'BT-44 / BR-07'
			);
		}

		if ( '' === trim( $buyer->country ) ) {
			$findings[] = new Finding(
				$this->id(),
				'country_missing',
				Severity::BLOCKER,
				$id,
				__( 'The order has no billing country.', 'konform' ),
				__( 'The buyer country determines the VAT treatment. Without it the tax category cannot be decided.', 'konform' ),
				__( 'Open the order and set the billing country.', 'konform' ),
				'BT-55 / BR-11'
			);

			return $findings;
		}

		if ( ! $this->is_untaxed_intra_community_b2b( $invoice ) ) {
			return $findings;
		}

		if ( '' === trim( $buyer->vat_number ) ) {
			$findings[] = new Finding(
				$this->id(),
				'vat_missing',
				Severity::BLOCKER,
				$id,
				__( 'This is a cross-border EU business sale with no VAT, but the customer VAT number is missing.', 'konform' ),
				__( 'When VAT is not charged on an intra-community supply, the buyer VAT identifier is mandatory. Without it the exemption cannot be justified and the invoice is rejected.', 'konform' ),
				__( 'Open the order and add the VAT number to the billing details, then start collecting it at checkout.', 'konform' ),
				'BT-48 / BR-AE-09'
			);

			return $findings;
		}

		if ( ! Eu::looks_like_vat_number( $buyer->vat_number ) ) {
			$findings[] = new Finding(
				$this->id(),
				'vat_invalid',
				Severity::BLOCKER,
				$id,
				sprintf(
					/* translators: %s: the VAT number stored on the order. */
					__( 'The customer VAT number "%s" is not in a valid EU format.', 'konform' ),
					$buyer->vat_number
				),
				__( 'An EU VAT number starts with the two-letter country code, for example DE123456789.', 'konform' ),
				__( 'Open the order and correct the VAT number in the billing details.', 'konform' ),
				'BT-48'
			);

			return $findings;
		}

		$prefix = Eu::vat_prefix( $buyer->vat_number );

		if ( '' !== $prefix && strtoupper( $buyer->country ) !== $prefix ) {
			$findings[] = new Finding(
				$this->id(),
				'vat_country_mismatch',
				Severity::WARNING,
				$id,
				sprintf(
					/* translators: 1: country code taken from the VAT number, 2: billing country code. */
					__( 'The VAT number is registered in %1$s but the billing country is %2$s.', 'konform' ),
					$prefix,
					strtoupper( $buyer->country )
				),
				__( 'A mismatch is legitimate for branch offices, but it is also a common sign of a mistyped VAT number.', 'konform' ),
				__( 'Open the order and confirm both values with the customer.', 'konform' ),
				'BT-48 / BT-55'
			);
		}

		return $findings;
	}

	/**
	 * Siparişin vergisiz AB içi B2B teslim olup olmadığını belirler.
	 *
	 * @param SemanticInvoice $invoice Eşlenmiş fatura.
	 * @return bool
	 */
	private function is_untaxed_intra_community_b2b( SemanticInvoice $invoice ): bool {
		$seller = $invoice->seller;
		$buyer  = $invoice->buyer;

		if ( ! $seller->is_in_eu() || ! $buyer->is_in_eu() ) {
			return false;
		}

		if ( strtoupper( $seller->country ) === strtoupper( $buyer->country ) ) {
			return false;
		}

		if ( ! $buyer->is_company ) {
			return false;
		}

		return 0.0 === $invoice->tax_total();
	}
}
