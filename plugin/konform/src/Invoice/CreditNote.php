<?php
/**
 * İade faturası eşlemesi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce iadesini EN 16931 iade faturasına (belge tipi 381) çevirir.
 *
 * Neden ayrı bir belge: kesilmiş bir faturayı iade yüzünden değiştirmek yasal
 * olarak yanlıştır. Muhasebe kaydı düzeltilmez, KARŞI KAYIT atılır — iade
 * faturası tam olarak budur ve önceki faturaya atıf yapar (BT-25).
 *
 * WooCommerce iade tutarlarını negatif saklar; EN 16931 ise iade faturasında
 * POZİTİF tutar bekler ve işaret belge tipinden anlaşılır. Bu dönüşümü
 * kaçırmak, doğrulayıcının toplamları tutarsız bulmasına yol açar.
 */
final class CreditNote {

	/**
	 * İade faturası belge tipi kodu — UNTDID 1001.
	 */
	public const TYPE_CODE = '381';

	/**
	 * İadeyi anlamsal faturaya çevirir.
	 *
	 * @param \WC_Order_Refund $refund İade kaydı.
	 * @param \WC_Order        $order  Asıl sipariş.
	 * @return SemanticInvoice
	 */
	public static function map( \WC_Order_Refund $refund, \WC_Order $order ): SemanticInvoice {
		$seller = OrderMapper::seller();
		$buyer  = OrderMapper::buyer( $order );
		$lines  = self::lines( $refund, $order, $seller, $buyer );

		$created = $refund->get_date_created();

		$issue_date = $created instanceof \WC_DateTime
			? new \DateTimeImmutable( $created->date( 'Y-m-d' ) )
			: new \DateTimeImmutable( 'today' );

		/**
		 * İade faturası numarasını değiştirir.
		 *
		 * Çoğu ülkede iade faturaları kendi kesintisiz serisini gerektirir;
		 * sipariş numarasına ek yapmak her yerde kabul edilmez.
		 *
		 * @param string           $number Numara.
		 * @param \WC_Order_Refund $refund İade.
		 * @param \WC_Order        $order  Sipariş.
		 */
		$number = (string) \apply_filters(
			'konform/credit_note_number',
			$order->get_order_number() . '-C' . $refund->get_id(),
			$refund,
			$order
		);

		return new SemanticInvoice(
			$number,
			$issue_date,
			self::TYPE_CODE,
			(string) $order->get_currency(),
			$seller,
			$buyer,
			$lines,
			self::tax_subtotals( $lines ),
			0.0,
			$issue_date,
			$buyer,
			(string) $order->get_order_number(),
			self::order_date( $order )
		);
	}

	/**
	 * Asıl siparişin düzenlenme tarihi.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return \DateTimeImmutable
	 */
	private static function order_date( \WC_Order $order ): \DateTimeImmutable {
		$created = $order->get_date_created();

		return $created instanceof \WC_DateTime
			? new \DateTimeImmutable( $created->date( 'Y-m-d' ) )
			: new \DateTimeImmutable( 'today' );
	}

	/**
	 * İade kalemlerini fatura satırlarına çevirir.
	 *
	 * @param \WC_Order_Refund $refund İade.
	 * @param \WC_Order        $order  Sipariş.
	 * @param Party            $seller Satıcı.
	 * @param Party            $buyer  Alıcı.
	 * @return Line[]
	 */
	private static function lines( \WC_Order_Refund $refund, \WC_Order $order, Party $seller, Party $buyer ): array {
		$rates    = self::rate_map( $order );
		$lines    = array();
		$position = 0;

		foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			// WooCommerce iade tutarlarini negatif saklar; EN 16931 pozitif bekler.
			$net_amount = round( abs( (float) $item->get_total() ), 2 );

			if ( 0.0 === $net_amount ) {
				continue;
			}

			++$position;

			$quantity   = $item instanceof \WC_Order_Item_Product
				? max( 1.0, abs( (float) $item->get_quantity() ) )
				: 1.0;
			$rate       = self::rate_for( $item, $rates );
			$is_service = ! $item instanceof \WC_Order_Item_Product;
			$decision   = TaxCategoryResolver::resolve( $seller, $buyer, $rate, $is_service );

			$lines[] = new Line(
				(string) $position,
				(string) $item->get_name(),
				$quantity,
				Line::DEFAULT_UNIT,
				round( $net_amount / $quantity, 4 ),
				$net_amount,
				$decision->category,
				$rate
			);
		}

		if ( array() === $lines ) {
			$lines = self::amount_only_line( $refund, $seller, $buyer );
		}

		return $lines;
	}

	/**
	 * Kalemsiz iadeler için tek satır üretir.
	 *
	 * WooCommerce'te yöneticinin kalem seçmeden düz bir tutar iade etmesi çok
	 * yaygındır. O iadenin hiç kalemi yoktur ve satırsız bir belge BR-16'ya
	 * takılır: "An Invoice shall have at least one Invoice line."
	 *
	 * Oran, iade edilen vergi ile net tutardan geriye hesaplanır — burada
	 * başka kaynak yoktur.
	 *
	 * @param \WC_Order_Refund $refund İade.
	 * @param Party            $seller Satıcı.
	 * @param Party            $buyer  Alıcı.
	 * @return Line[]
	 */
	private static function amount_only_line( \WC_Order_Refund $refund, Party $seller, Party $buyer ): array {
		$gross = round( abs( (float) $refund->get_amount() ), 2 );
		$tax   = round( abs( (float) $refund->get_total_tax() ), 2 );
		$net   = round( $gross - $tax, 2 );

		if ( $net <= 0.0 ) {
			return array();
		}

		$rate     = $tax > 0.0 ? round( $tax / $net * 100, 2 ) : 0.0;
		$decision = TaxCategoryResolver::resolve( $seller, $buyer, $rate, false );

		$reason = trim( (string) $refund->get_reason() );

		return array(
			new Line(
				'1',
				'' !== $reason ? $reason : __( 'Refund', 'konform' ),
				1.0,
				Line::DEFAULT_UNIT,
				$net,
				$net,
				$decision->category,
				$rate
			),
		);
	}

	/**
	 * Asıl siparişin vergi oranı haritasını kurar.
	 *
	 * Oran iade kaleminden değil ASIL SİPARİŞTEN okunur; iade kalemlerinin
	 * kendi vergi satırları eksik olabilir.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return array<int, float>
	 */
	private static function rate_map( \WC_Order $order ): array {
		$map = array();

		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			if ( $tax_item instanceof \WC_Order_Item_Tax ) {
				$map[ (int) $tax_item->get_rate_id() ] = (float) $tax_item->get_rate_percent();
			}
		}

		return $map;
	}

	/**
	 * Kalemin vergi oranını döndürür.
	 *
	 * @param \WC_Order_Item    $item  Kalem.
	 * @param array<int, float> $rates Oran haritası.
	 * @return float
	 */
	private static function rate_for( \WC_Order_Item $item, array $rates ): float {
		$taxes = $item->get_taxes();

		if ( ! isset( $taxes['total'] ) || ! is_array( $taxes['total'] ) ) {
			return 0.0;
		}

		foreach ( $taxes['total'] as $rate_id => $amount ) {
			if ( '' !== $amount && 0.0 !== (float) $amount && isset( $rates[ (int) $rate_id ] ) ) {
				return $rates[ (int) $rate_id ];
			}
		}

		return 0.0;
	}

	/**
	 * Satırlardan vergi kırılımını üretir.
	 *
	 * @param Line[] $lines Satırlar.
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
}
