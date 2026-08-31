<?php
/**
 * Satıcı kimlik alanları.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Preflight\Rules;

use Konform\Invoice\Eu;
use Konform\Invoice\OrderMapper;
use Konform\Preflight\Finding;
use Konform\Preflight\Severity;
use Konform\Preflight\StoreRule;

defined( 'ABSPATH' ) || exit;

/**
 * BR-06, BR-08, BR-09, BR-CO-26: satıcının adı, adresi, ülkesi ve vergi
 * kimliği zorunludur.
 *
 * Mağaza kapsamlıdır: bu alanlar siparişten değil ayarlardan gelir, dolayısıyla
 * bir kez denetlenir. Sipariş başına raporlamak aynı sorunu yüzlerce kez
 * tekrarlar ve gerçek sipariş sorunlarını gözden kaçırtır.
 *
 * Genelde en yüksek getirili düzeltme budur: tek bir ayar bütün siparişleri
 * temizler.
 */
final class SellerIdentity implements StoreRule {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'seller_identity';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Store identity', 'konform' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return Finding[]
	 */
	public function check_store(): array {
		$findings = array();
		$seller   = OrderMapper::seller();

		if ( '' === trim( $seller->name ) ) {
			$findings[] = new Finding(
				$this->id(),
				'name_missing',
				Severity::BLOCKER,
				Finding::STORE_WIDE,
				__( 'Your store has no business name.', 'konform' ),
				__( 'The seller name appears on every invoice and is mandatory.', 'konform' ),
				__( 'Set the site title under Settings > General.', 'konform' ),
				'BT-27 / BR-06'
			);
		}

		if ( '' === trim( $seller->country ) ) {
			$findings[] = new Finding(
				$this->id(),
				'country_missing',
				Severity::BLOCKER,
				Finding::STORE_WIDE,
				__( 'Your store has no country set.', 'konform' ),
				__( 'The seller country decides which national e-invoicing rules apply to you. Nothing can be generated without it.', 'konform' ),
				__( 'Set it under WooCommerce > Settings > General > Store address.', 'konform' ),
				'BT-40 / BR-09'
			);
		}

		if ( '' === trim( $seller->address ) || '' === trim( $seller->city ) ) {
			$findings[] = new Finding(
				$this->id(),
				'address_incomplete',
				Severity::BLOCKER,
				Finding::STORE_WIDE,
				__( 'Your store postal address is incomplete.', 'konform' ),
				__( 'The seller street address and city are mandatory on every EU e-invoice.', 'konform' ),
				__( 'Complete the address under WooCommerce > Settings > General > Store address.', 'konform' ),
				'BG-5 / BR-08'
			);
		}

		if ( '' === trim( $seller->vat_number ) ) {
			$findings[] = new Finding(
				$this->id(),
				'vat_missing',
				Severity::BLOCKER,
				Finding::STORE_WIDE,
				__( 'Your store has no VAT number configured.', 'konform' ),
				__( 'A seller VAT identifier is required on EU e-invoices. WooCommerce has no field for it, so Konform stores it separately.', 'konform' ),
				__( 'Add it in the Settings box at the bottom of this page.', 'konform' ),
				'BT-31 / BR-CO-26'
			);
		} elseif ( Eu::is_member( $seller->country ) && ! Eu::looks_like_vat_number( $seller->vat_number ) ) {
			$findings[] = new Finding(
				$this->id(),
				'vat_invalid',
				Severity::BLOCKER,
				Finding::STORE_WIDE,
				sprintf(
					/* translators: %s: the VAT number configured for the store. */
					__( 'Your store VAT number "%s" is not in a valid EU format.', 'konform' ),
					$seller->vat_number
				),
				__( 'An EU VAT number starts with the two-letter country code, for example FR12345678901.', 'konform' ),
				__( 'Correct it in the Settings box at the bottom of this page.', 'konform' ),
				'BT-31'
			);
		}

		return $findings;
	}
}
