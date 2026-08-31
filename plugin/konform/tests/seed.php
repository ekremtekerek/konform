<?php
/**
 * Gelistirme icin ornek siparis uretir. Yalnizca yerel ortamda calistirilir.
 *
 * Calistir:
 *   docker compose run --rm -T wpcli wp eval-file \
 *     wp-content/plugins/konform/tests/seed.php
 *
 * @package Konform
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Vergi orani ekler.
 *
 * @param string $country Ulke kodu.
 * @param string $rate    Oran.
 * @param string $name    Ad.
 * @return void
 */
function konform_seed_tax_rate( string $country, string $rate, string $name ): void {
	global $wpdb;

	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = %s LIMIT 1",
			$country
		)
	);

	if ( $exists ) {
		return;
	}

	WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => $country,
			'tax_rate_state'    => '',
			'tax_rate'          => $rate,
			'tax_rate_name'     => $name,
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_class'    => '',
		)
	);
}

/**
 * Urun olusturur veya mevcut olani dondurur.
 *
 * @param string $name    Urun adi.
 * @param string $price   Fiyat.
 * @param bool   $virtual Sanal urun mu.
 * @return int
 */
function konform_seed_product( string $name, string $price, bool $virtual ): int {
	$found = get_page_by_title( $name, OBJECT, 'product' );

	if ( $found ) {
		return (int) $found->ID;
	}

	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( $price );
	$product->set_tax_status( 'taxable' );
	$product->set_virtual( $virtual );
	$product->set_downloadable( $virtual );
	$product->set_catalog_visibility( 'visible' );
	$product->set_status( 'publish' );

	return (int) $product->save();
}

/**
 * Siparis olusturur.
 *
 * @param int                  $product_id Urun kimligi.
 * @param int                  $quantity   Adet.
 * @param array<string,string> $billing    Fatura adresi alanlari.
 * @param array<string,string> $meta       Ek meta.
 * @return int
 */
function konform_seed_order( int $product_id, int $quantity, array $billing, array $meta = array() ): int {
	$order = wc_create_order();

	$order->add_product( wc_get_product( $product_id ), $quantity );

	foreach ( $billing as $field => $value ) {
		$setter = 'set_billing_' . $field;

		if ( method_exists( $order, $setter ) ) {
			$order->{$setter}( $value );
		}
	}

	foreach ( $meta as $key => $value ) {
		$order->update_meta_data( $key, $value );
	}

	$order->calculate_taxes();
	$order->calculate_totals( false );
	$order->set_status( 'completed' );

	return (int) $order->save();
}

konform_seed_tax_rate( 'FR', '20.0000', 'TVA' );

$goods   = konform_seed_product( 'Konform Test Widget', '100', false );
$service = konform_seed_product( 'Konform Test Ebook', '50', true );

$created = array();

// 1. Yurt ici FR B2C - KDV %20 uygulanir, temiz olmali.
$created['FR B2C yurt ici'] = konform_seed_order(
	$goods,
	2,
	array(
		'first_name' => 'Camille',
		'last_name'  => 'Durand',
		'country'    => 'FR',
		'address_1'  => '12 rue de Rivoli',
		'city'       => 'Paris',
		'postcode'   => '75001',
		'email'      => 'camille@example.test',
	)
);

// 2. AB ici B2B mal, KDV numarasi VAR - kategori K, tahmin uyarisi bekleniyor.
$created['DE B2B mal, KDV no var'] = konform_seed_order(
	$goods,
	1,
	array(
		'first_name' => 'Jonas',
		'last_name'  => 'Weber',
		'company'    => 'Weber Handel GmbH',
		'country'    => 'DE',
		'address_1'  => 'Hauptstrasse 5',
		'city'       => 'Berlin',
		'postcode'   => '10115',
		'email'      => 'jonas@example.test',
	),
	array( '_billing_vat_number' => 'DE123456789' )
);

// 3. AB ici B2B mal, KDV numarasi YOK - BLOCKER bekleniyor.
$created['DE B2B mal, KDV no YOK'] = konform_seed_order(
	$goods,
	1,
	array(
		'first_name' => 'Lena',
		'last_name'  => 'Fischer',
		'company'    => 'Fischer Import GmbH',
		'country'    => 'DE',
		'address_1'  => 'Bahnhofstrasse 2',
		'city'       => 'Munchen',
		'postcode'   => '80331',
		'email'      => 'lena@example.test',
	)
);

// 4. AB ici B2B hizmet - kategori AE bekleniyor.
$created['NL B2B hizmet'] = konform_seed_order(
	$service,
	1,
	array(
		'first_name' => 'Sanne',
		'last_name'  => 'de Vries',
		'company'    => 'De Vries Advies BV',
		'country'    => 'NL',
		'address_1'  => 'Keizersgracht 100',
		'city'       => 'Amsterdam',
		'postcode'   => '1015',
		'email'      => 'sanne@example.test',
	),
	array( '_billing_vat_number' => 'NL123456789B01' )
);

// 5. Toplam tutmayan siparis - BLOCKER bekleniyor.
$mismatch = wc_get_order(
	konform_seed_order(
		$goods,
		1,
		array(
			'first_name' => 'Paul',
			'last_name'  => 'Martin',
			'country'    => 'FR',
			'address_1'  => '3 avenue Foch',
			'city'       => 'Lyon',
			'postcode'   => '69001',
			'email'      => 'paul@example.test',
		)
	)
);
$mismatch->set_total( (string) ( (float) $mismatch->get_total() - 7.50 ) );
$mismatch->save();
$created['FR toplam tutmuyor'] = $mismatch->get_id();

// 6. Ulkesi eksik siparis - BLOCKER bekleniyor.
$broken = wc_get_order(
	konform_seed_order(
		$goods,
		1,
		array(
			'first_name' => 'Anon',
			'last_name'  => '',
			'country'    => 'FR',
			'address_1'  => '1 rue Test',
			'city'       => 'Nice',
			'postcode'   => '06000',
			'email'      => 'anon@example.test',
		)
	)
);
$broken->set_billing_country( '' );
$broken->set_billing_first_name( '' );
$broken->save();
$created['Ulke ve ad eksik'] = $broken->get_id();

echo "Olusturulan siparisler:\n";

foreach ( $created as $label => $order_id ) {
	printf( "  #%-5d %s\n", (int) $order_id, $label );
}
