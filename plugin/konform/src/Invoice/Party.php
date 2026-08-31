<?php
/**
 * Fatura tarafı (satıcı veya alıcı).
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * EN 16931'de satıcı ve alıcı aynı yapıdadır; ayrım yalnızca alan
 * numaralarındadır (satıcı BT-27..BT-34, alıcı BT-44..BT-49).
 */
final class Party {

	/**
	 * Kurucu.
	 *
	 * @param string $name       Ticari unvan. Satıcı BT-27, alıcı BT-44.
	 * @param string $country    ISO 3166-1 alpha-2. Satıcı BT-40, alıcı BT-55.
	 * @param string $vat_number KDV numarası. Satıcı BT-31, alıcı BT-48.
	 * @param string $address    Sokak adresi. Satıcı BT-35, alıcı BT-50.
	 * @param string $city       Şehir. Satıcı BT-37, alıcı BT-52.
	 * @param string $postcode   Posta kodu. Satıcı BT-38, alıcı BT-53.
	 * @param string $email      E-posta. Satıcı BT-34, alıcı BT-49.
	 * @param bool   $is_company Tüzel kişi mi (B2B ayrımı için).
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $country,
		public readonly string $vat_number,
		public readonly string $address,
		public readonly string $city,
		public readonly string $postcode,
		public readonly string $email,
		public readonly bool $is_company,
	) {}

	/**
	 * Tarafın AB KDV bölgesinde olup olmadığını bildirir.
	 *
	 * @return bool
	 */
	public function is_in_eu(): bool {
		return Eu::is_member( $this->country );
	}

	/**
	 * Geçerli görünen bir KDV numarası taşıyıp taşımadığını bildirir.
	 *
	 * @return bool
	 */
	public function has_vat_number(): bool {
		return '' !== $this->vat_number && Eu::looks_like_vat_number( $this->vat_number );
	}
}
