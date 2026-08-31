<?php
/**
 * Vergi kategorisi çözümlemesi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * Bir satırın UNTDID 5305 vergi kategorisini belirler.
 *
 * WooCommerce yalnızca bir vergi oranı bilir; EN 16931 ise oranın YANINDA
 * kategoriyi de ister ve bu kategori satıcı ile alıcının vergi bölgelerine
 * bağlıdır. Bu dönüşüm eşleyicinin en değerli parçasıdır — rakiplerin
 * "XML üretiyoruz" dediği yerde asıl iş buradadır.
 */
final class TaxCategoryResolver {

	/**
	 * Bir satır için vergi kategorisini çözümler.
	 *
	 * @param Party $seller     Satıcı.
	 * @param Party $buyer      Alıcı.
	 * @param float $rate       Vergi oranı, yüzde.
	 * @param bool  $is_service Satır hizmet mi (sanal/indirilebilir ürün).
	 * @return Decision
	 */
	public static function resolve( Party $seller, Party $buyer, float $rate, bool $is_service = false ): Decision {
		$seller_in_eu = $seller->is_in_eu();
		$buyer_in_eu  = $buyer->is_in_eu();
		$same_country = strtoupper( $seller->country ) === strtoupper( $buyer->country );

		if ( ! $seller_in_eu ) {
			return new Decision( 'O', 'seller_outside_eu' );
		}

		if ( $rate > 0.0 ) {
			// Oran varsa kategori standarttır; yurt içi de OSS mesafeli satış da aynı.
			return new Decision( 'S', $same_country ? 'domestic_rated' : 'oss_distance_selling' );
		}

		if ( ! $buyer_in_eu ) {
			return new Decision( 'G', 'export_outside_eu' );
		}

		if ( ! $same_country && $buyer->is_company && $buyer->has_vat_number() ) {
			/*
			 * AB içi B2B, vergi alınmıyor. Mal teslimi 'K', hizmet 'AE' ister.
			 * WooCommerce bu ayrımı doğrudan bilmez; sanal/indirilebilir ürünü
			 * hizmet saymak makul bir yaklaşımdır ama kesin değildir.
			 */
			return $is_service
				? new Decision( 'AE', 'intra_community_service', false )
				: new Decision( 'K', 'intra_community_goods', false );
		}

		if ( ! $same_country && $buyer_in_eu ) {
			/*
			 * AB içi, alıcı KDV numarası yok veya bireysel: normalde alıcının
			 * ülkesinin oranı uygulanmalıydı. Oranın sıfır olması yapılandırma
			 * hatasına işaret eder.
			 */
			return new Decision( 'Z', 'cross_border_zero_rate_suspicious', false );
		}

		return new Decision( 'Z', 'domestic_zero_rate', false );
	}

	/**
	 * Gerekçe anahtarının insan tarafından okunan açıklamasını döndürür.
	 *
	 * @param string $reason Gerekçe anahtarı.
	 * @return string
	 */
	public static function explain( string $reason ): string {
		switch ( $reason ) {
			case 'seller_outside_eu':
				return __( 'The store is based outside the EU, so the supply is outside the scope of EU VAT.', 'konform' );

			case 'domestic_rated':
				return __( 'Domestic sale with a VAT rate applied.', 'konform' );

			case 'oss_distance_selling':
				return __( 'Cross-border sale to an EU consumer, taxed at the destination rate under OSS.', 'konform' );

			case 'export_outside_eu':
				return __( 'The buyer is outside the EU, so the supply is treated as an export.', 'konform' );

			case 'intra_community_service':
				return __( 'Assumed to be a service because every item is virtual or downloadable.', 'konform' );

			case 'intra_community_goods':
				return __( 'Assumed to be goods because the order contains shippable items.', 'konform' );

			case 'cross_border_zero_rate_suspicious':
				return __( 'Cross-border EU sale with no VAT and no valid buyer VAT number.', 'konform' );

			case 'domestic_zero_rate':
				return __( 'Domestic sale with no VAT applied.', 'konform' );

			default:
				return $reason;
		}
	}
}
