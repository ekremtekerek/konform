<?php
/**
 * WooCommerce siparişinden anlamsal faturaya eşleme.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Order nesnesini EN 16931 anlamsal modeline çevirir.
 *
 * Bu sınıf hiçbir zaman istisna fırlatmaz ve eksik veriyi uydurmaz — eksik olan
 * eksik kalır, sorunları bulmak ön uçuş kontrolünün işidir. Eşleyicinin sessizce
 * varsayılan doldurması, hatanın vergi idaresinde ortaya çıkması demektir.
 */
final class OrderMapper {

	/**
	 * KDV numarasının saklanabileceği bilinen sipariş meta anahtarları.
	 *
	 * WooCommerce çekirdeğinde KDV numarası alanı yoktur; her eklenti kendi
	 * anahtarını kullanır. Sırayla denenir, ilk dolu olan kazanır.
	 *
	 * @var string[]
	 */
	private const VAT_META_KEYS = array(
		'_billing_vat_number',
		'_vat_number',
		'_billing_eu_vat_number',
		'_billing_tax_number',
		'vat_number',
		'_wcpdf_billing_vat_number',
		'_billing_vat_id',
	);

	/**
	 * Siparişi anlamsal faturaya çevirir.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return SemanticInvoice
	 */
	public static function map( \WC_Order $order ): SemanticInvoice {
		$seller = self::seller();
		$buyer  = self::buyer( $order );
		$lines  = self::lines( $order, $seller, $buyer );

		/**
		 * Fatura numarasını değiştirir.
		 *
		 * Yasal fatura numarası çoğu ülkede kesintisiz bir seri olmak
		 * zorundadır; sipariş numarası her zaman bu şartı sağlamaz.
		 *
		 * @param string    $number Fatura numarası.
		 * @param \WC_Order $order  Sipariş.
		 */
		$number = (string) \apply_filters( 'konform/invoice_number', (string) $order->get_order_number(), $order );

		$created = $order->get_date_created();

		$issue_date = $created instanceof \WC_DateTime
			? new \DateTimeImmutable( $created->date( 'Y-m-d' ) )
			: new \DateTimeImmutable( 'today' );

		return new SemanticInvoice(
			$number,
			$issue_date,
			'380',
			(string) $order->get_currency(),
			$seller,
			$buyer,
			$lines,
			self::tax_subtotals( $lines ),
			(float) $order->get_total_refunded() > 0.0 ? 0.0 : self::paid_amount( $order ),
			self::delivery_date( $order, $issue_date ),
			self::ship_to( $order, $buyer )
		);
	}

	/**
	 * Fiili teslim tarihi. BT-72.
	 *
	 * AB içi teslimlerde (kategori K) bu alan ZORUNLUDUR — BR-IC-11. Eksikliği
	 * resmi Schematron tarafından ölümcül hata olarak raporlanır.
	 *
	 * @param \WC_Order          $order      Sipariş.
	 * @param \DateTimeImmutable $fallback   Bulunamazsa kullanılacak tarih.
	 * @return \DateTimeImmutable
	 */
	private static function delivery_date( \WC_Order $order, \DateTimeImmutable $fallback ): \DateTimeImmutable {
		foreach ( array( $order->get_date_completed(), $order->get_date_paid() ) as $candidate ) {
			if ( $candidate instanceof \WC_DateTime ) {
				return new \DateTimeImmutable( $candidate->date( 'Y-m-d' ) );
			}
		}

		return $fallback;
	}

	/**
	 * Teslim adresi. BG-13, ülke kodu BT-80.
	 *
	 * AB içi teslimlerde ülke kodu ZORUNLUDUR — BR-IC-12. Kargo adresi boşsa
	 * (dijital ürün, kargosuz sipariş) fatura adresine düşülür; teslim yine de
	 * o ülkeye yapılmış sayılır.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @param Party     $buyer Alıcı.
	 * @return Party
	 */
	private static function ship_to( \WC_Order $order, Party $buyer ): Party {
		$country = trim( (string) $order->get_shipping_country() );

		if ( '' === $country ) {
			return $buyer;
		}

		$name    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$company = trim( (string) $order->get_shipping_company() );

		$address = trim( (string) $order->get_shipping_address_1() );
		$line_2  = trim( (string) $order->get_shipping_address_2() );

		if ( '' !== $line_2 ) {
			$address .= ' ' . $line_2;
		}

		return new Party(
			'' !== $company ? $company : ( '' !== $name ? $name : $buyer->name ),
			$country,
			'',
			$address,
			(string) $order->get_shipping_city(),
			(string) $order->get_shipping_postcode(),
			'',
			'' !== $company
		);
	}

	/**
	 * Mağaza ayarlarından satıcı tarafını kurar.
	 *
	 * @return Party
	 */
	public static function seller(): Party {
		$base    = \function_exists( 'wc_get_base_location' ) ? \wc_get_base_location() : array();
		$country = isset( $base['country'] ) ? (string) $base['country'] : '';

		$address = (string) \get_option( 'woocommerce_store_address', '' );
		$line_2  = (string) \get_option( 'woocommerce_store_address_2', '' );

		if ( '' !== $line_2 ) {
			$address .= ' ' . $line_2;
		}

		/**
		 * Satıcının KDV numarasını değiştirir.
		 *
		 * WooCommerce çekirdeğinde böyle bir ayar yoktur; eklenti kendi
		 * seçeneğini tutar.
		 *
		 * @param string $vat_number Satıcı KDV numarası.
		 */
		$vat_number = (string) \apply_filters( 'konform/seller_vat_number', (string) \get_option( 'konform_seller_vat_number', '' ) );

		return new Party(
			(string) \get_option( 'blogname', '' ),
			$country,
			$vat_number,
			trim( $address ),
			(string) \get_option( 'woocommerce_store_city', '' ),
			(string) \get_option( 'woocommerce_store_postcode', '' ),
			(string) \get_option( 'admin_email', '' ),
			true
		);
	}

	/**
	 * Siparişin fatura adresinden alıcı tarafını kurar.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return Party
	 */
	public static function buyer( \WC_Order $order ): Party {
		$company = trim( (string) $order->get_billing_company() );

		$name = '' !== $company
			? $company
			: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$address = trim( (string) $order->get_billing_address_1() );
		$line_2  = trim( (string) $order->get_billing_address_2() );

		if ( '' !== $line_2 ) {
			$address .= ' ' . $line_2;
		}

		return new Party(
			$name,
			(string) $order->get_billing_country(),
			self::vat_number( $order ),
			$address,
			(string) $order->get_billing_city(),
			(string) $order->get_billing_postcode(),
			(string) $order->get_billing_email(),
			'' !== $company
		);
	}

	/**
	 * Siparişteki alıcı KDV numarasını bulur.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return string Bulunamazsa boş dize.
	 */
	public static function vat_number( \WC_Order $order ): string {
		$found = '';

		foreach ( self::VAT_META_KEYS as $key ) {
			$value = trim( (string) $order->get_meta( $key ) );

			if ( '' !== $value ) {
				$found = $value;
				break;
			}
		}

		/**
		 * Alıcının KDV numarasını değiştirir.
		 *
		 * Bilinen anahtarlar arasında olmayan bir eklenti kullanılıyorsa bu
		 * kanca tek bağlantı noktasıdır.
		 *
		 * @param string    $found Bulunan KDV numarası.
		 * @param \WC_Order $order Sipariş.
		 */
		return (string) \apply_filters( 'konform/buyer_vat_number', $found, $order );
	}

	/**
	 * Sipariş kalemlerini fatura satırlarına çevirir.
	 *
	 * @param \WC_Order $order  Sipariş.
	 * @param Party     $seller Satıcı.
	 * @param Party     $buyer  Alıcı.
	 * @return Line[]
	 */
	private static function lines( \WC_Order $order, Party $seller, Party $buyer ): array {
		$rates    = self::rate_map( $order );
		$lines    = array();
		$position = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			++$position;

			$product    = $item->get_product();
			$is_service = $product instanceof \WC_Product
				&& ( $product->is_virtual() || $product->is_downloadable() );

			$quantity   = (float) $item->get_quantity();
			$net_amount = round( (float) $item->get_total(), 2 );
			$rate       = self::rate_for( $item, $rates );
			$decision   = TaxCategoryResolver::resolve( $seller, $buyer, $rate, $is_service );

			$lines[] = new Line(
				(string) $position,
				(string) $item->get_name(),
				$quantity,
				Line::DEFAULT_UNIT,
				$quantity > 0.0 ? round( $net_amount / $quantity, 4 ) : 0.0,
				$net_amount,
				$decision->category,
				$rate
			);
		}

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Shipping ) {
				continue;
			}

			$net_amount = round( (float) $item->get_total(), 2 );

			if ( 0.0 === $net_amount ) {
				continue;
			}

			++$position;

			$rate     = self::rate_for( $item, $rates );
			$decision = TaxCategoryResolver::resolve( $seller, $buyer, $rate, false );

			$lines[] = new Line(
				(string) $position,
				(string) $item->get_name(),
				1.0,
				Line::DEFAULT_UNIT,
				$net_amount,
				$net_amount,
				$decision->category,
				$rate
			);
		}

		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Fee ) {
				continue;
			}

			++$position;

			$net_amount = round( (float) $item->get_total(), 2 );
			$rate       = self::rate_for( $item, $rates );
			$decision   = TaxCategoryResolver::resolve( $seller, $buyer, $rate, true );

			$lines[] = new Line(
				(string) $position,
				(string) $item->get_name(),
				1.0,
				Line::DEFAULT_UNIT,
				$net_amount,
				$net_amount,
				$decision->category,
				$rate
			);
		}

		return $lines;
	}

	/**
	 * Siparişin vergi kalemlerinden "oran kimliği => yüzde" haritası kurar.
	 *
	 * Oranı tutardan geriye hesaplamak yuvarlama hatası üretir; WooCommerce
	 * oranın kendisini vergi kaleminde saklar ve doğru kaynak budur.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return array<int, float>
	 */
	private static function rate_map( \WC_Order $order ): array {
		$map = array();

		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			if ( ! $tax_item instanceof \WC_Order_Item_Tax ) {
				continue;
			}

			$map[ (int) $tax_item->get_rate_id() ] = (float) $tax_item->get_rate_percent();
		}

		return $map;
	}

	/**
	 * Bir kalemin vergi oranını döndürür.
	 *
	 * @param \WC_Order_Item    $item  Sipariş kalemi.
	 * @param array<int, float> $rates Oran haritası.
	 * @return float Yüzde olarak oran.
	 */
	private static function rate_for( \WC_Order_Item $item, array $rates ): float {
		$taxes = $item->get_taxes();

		if ( ! isset( $taxes['total'] ) || ! is_array( $taxes['total'] ) ) {
			return 0.0;
		}

		foreach ( $taxes['total'] as $rate_id => $amount ) {
			if ( '' === $amount || 0.0 === (float) $amount ) {
				continue;
			}

			if ( isset( $rates[ (int) $rate_id ] ) ) {
				return $rates[ (int) $rate_id ];
			}
		}

		return 0.0;
	}

	/**
	 * Satırlardan vergi kırılımını üretir.
	 *
	 * BR-CO-18: her (kategori, oran) çifti için tam olarak bir kırılım satırı
	 * bulunmalıdır.
	 *
	 * @param Line[] $lines Fatura satırları.
	 * @return TaxSubtotal[]
	 */
	private static function tax_subtotals( array $lines ): array {
		$groups = array();

		foreach ( $lines as $line ) {
			$key = $line->tax_category . '|' . number_format( $line->tax_rate, 2, '.', '' );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'category' => $line->tax_category,
					'rate'     => $line->tax_rate,
					'basis'    => 0.0,
				);
			}

			$groups[ $key ]['basis'] += $line->net_amount;
		}

		$subtotals = array();

		foreach ( $groups as $group ) {
			$basis = round( $group['basis'], 2 );

			$subtotals[] = new TaxSubtotal(
				$group['category'],
				$group['rate'],
				$basis,
				round( $basis * $group['rate'] / 100, 2 )
			);
		}

		return $subtotals;
	}

	/**
	 * Ödenmiş tutarı döndürür.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return float
	 */
	private static function paid_amount( \WC_Order $order ): float {
		return null === $order->get_date_paid() ? 0.0 : round( (float) $order->get_total(), 2 );
	}
}
