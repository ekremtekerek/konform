<?php
/**
 * Ön uçuş tarayıcı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Preflight;

use Konform\Invoice\OrderMapper;
use Konform\License\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Geçmiş siparişleri tarar ve e-fatura üretimini engelleyecek sorunları
 * raporlar.
 *
 * Ürünün açılış hamlesi budur: değer ilk 60 saniyede, hiçbir yapılandırma
 * yapılmadan görünür.
 */
final class Scanner {

	/**
	 * Kayıtlı kurallar.
	 *
	 * @var Rule[]|null
	 */
	private static ?array $rules = null;

	/**
	 * Kural listesini döndürür.
	 *
	 * @return Rule[]
	 */
	public static function rules(): array {
		if ( null !== self::$rules ) {
			return self::$rules;
		}

		$rules = array(
			new Rules\SellerIdentity(),
			new Rules\InvoiceBasics(),
			new Rules\BuyerIdentity(),
			new Rules\TaxCategoryConsistency(),
			new Rules\TotalsConsistency(),
		);

		/**
		 * Ön uçuş kurallarını değiştirir.
		 *
		 * Ülke bazlı kurallar buraya eklenir.
		 *
		 * @param Rule[] $rules Kural listesi.
		 */
		$rules = (array) \apply_filters( 'konform/preflight_rules', $rules );

		self::$rules = array_values(
			array_filter(
				$rules,
				static fn ( $rule ): bool => $rule instanceof Rule
			)
		);

		return self::$rules;
	}

	/**
	 * Son siparişleri tarar.
	 *
	 * @param int|null $limit Taranacak sipariş sayısı; null ise plana göre belirlenir.
	 * @return Report
	 */
	public static function scan( ?int $limit = null ): Report {
		$limit = $limit ?? Licensing::preflight_limit();

		/**
		 * Tarama sınırını değiştirir.
		 *
		 * Pro sürüm bu sınırı kaldırır.
		 *
		 * @param int $limit Sipariş sayısı.
		 */
		$limit = (int) \apply_filters( 'konform/preflight_limit', $limit );
		$limit = max( 1, $limit );

		// Mağaza geneli kurallar bir kez çalışır; sipariş başına tekrarlanmaz.
		$findings = self::check_store();

		$orders = \wc_get_orders(
			array(
				'limit'   => $limit,
				'status'  => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
			)
		);

		if ( ! is_array( $orders ) ) {
			return new Report( 0, $findings );
		}

		$scanned = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			++$scanned;

			foreach ( self::check_order( $order ) as $finding ) {
				$findings[] = $finding;
			}
		}

		return new Report( $scanned, $findings );
	}

	/**
	 * Mağaza ayarlarını denetler.
	 *
	 * @return Finding[]
	 */
	public static function check_store(): array {
		$findings = array();

		foreach ( self::rules() as $rule ) {
			if ( ! $rule instanceof StoreRule ) {
				continue;
			}

			foreach ( $rule->check_store() as $finding ) {
				if ( $finding instanceof Finding ) {
					$findings[] = $finding;
				}
			}
		}

		return $findings;
	}

	/**
	 * Tek bir siparişi denetler.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return Finding[]
	 */
	public static function check_order( \WC_Order $order ): array {
		$invoice  = OrderMapper::map( $order );
		$findings = array();

		foreach ( self::rules() as $rule ) {
			if ( ! $rule instanceof OrderRule ) {
				continue;
			}

			foreach ( $rule->check( $invoice, $order ) as $finding ) {
				if ( $finding instanceof Finding ) {
					$findings[] = $finding;
				}
			}
		}

		return $findings;
	}

	/**
	 * Kural önbelleğini temizler.
	 *
	 * Testlerde kural listesi değiştirildiğinde gereklidir.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$rules = null;
	}
}
