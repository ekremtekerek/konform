<?php
/**
 * Vergi kategorisi çözümlemesi testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Invoice\Party;
use Konform\Invoice\TaxCategoryResolver;
use PHPUnit\Framework\TestCase;

/**
 * Eşleyicinin en değerli parçası burasıdır: WooCommerce yalnızca vergi ORANINI
 * bilir, EN 16931 ise oranın yanında KATEGORİYİ de ister ve kategori tarafların
 * vergi bölgesine bağlıdır.
 *
 * Bu testler yanlış kategorinin faturayı reddettirdiği senaryoları kilitler.
 */
final class TaxCategoryResolverTest extends TestCase {

	/**
	 * Taraf üretir.
	 *
	 * @param string $country    Ülke kodu.
	 * @param bool   $is_company Tüzel kişi mi.
	 * @param string $vat        KDV numarası.
	 * @return Party
	 */
	private function party( string $country, bool $is_company = false, string $vat = '' ): Party {
		return new Party( 'Test', $country, $vat, 'Street 1', 'City', '12345', 'a@b.test', $is_company );
	}

	/**
	 * Yurt içi satışta oran varsa kategori standarttır.
	 *
	 * @return void
	 */
	public function test_domestic_sale_with_rate_is_standard(): void {
		$decision = TaxCategoryResolver::resolve( $this->party( 'FR' ), $this->party( 'FR' ), 20.0 );

		$this->assertSame( 'S', $decision->category );
		$this->assertSame( 'domestic_rated', $decision->reason );
		$this->assertTrue( $decision->is_certain );
	}

	/**
	 * Sınır ötesi tüketici satışında oran varsa OSS kapsamında standarttır.
	 *
	 * @return void
	 */
	public function test_cross_border_consumer_with_rate_is_oss(): void {
		$decision = TaxCategoryResolver::resolve( $this->party( 'FR' ), $this->party( 'DE' ), 19.0 );

		$this->assertSame( 'S', $decision->category );
		$this->assertSame( 'oss_distance_selling', $decision->reason );
	}

	/**
	 * AB dışına satış ihracattır.
	 *
	 * @return void
	 */
	public function test_sale_outside_eu_is_export(): void {
		$decision = TaxCategoryResolver::resolve( $this->party( 'FR' ), $this->party( 'US' ), 0.0 );

		$this->assertSame( 'G', $decision->category );
	}

	/**
	 * AB içi B2B mal teslimi kategori K'dır ve karar KESİN DEĞİLDİR.
	 *
	 * Mal ile hizmet ayrımı sanal/indirilebilir olmaktan tahmin edilir; bu
	 * makul bir yaklaşımdır ama her zaman doğru değildir ve ön uçuş bunu
	 * uyarı olarak bildirmelidir.
	 *
	 * @return void
	 */
	public function test_intra_community_goods_is_k_and_uncertain(): void {
		$buyer    = $this->party( 'DE', true, 'DE123456789' );
		$decision = TaxCategoryResolver::resolve( $this->party( 'FR' ), $buyer, 0.0, false );

		$this->assertSame( 'K', $decision->category );
		$this->assertFalse( $decision->is_certain );
	}

	/**
	 * AB içi B2B hizmet tersine tabidir.
	 *
	 * @return void
	 */
	public function test_intra_community_service_is_reverse_charge(): void {
		$buyer    = $this->party( 'NL', true, 'NL123456789B01' );
		$decision = TaxCategoryResolver::resolve( $this->party( 'FR' ), $buyer, 0.0, true );

		$this->assertSame( 'AE', $decision->category );
	}

	/**
	 * KDV numarası olmayan sınır ötesi satış K'ya DÜŞMEZ.
	 *
	 * Bu, ürünün en değerli ön uçuş bulgusunun dayandığı davranıştır:
	 * numara yoksa istisna gerekçelendirilemez, dolayısıyla kategori K
	 * seçilemez ve durum şüpheli olarak işaretlenir.
	 *
	 * @return void
	 */
	public function test_cross_border_business_without_vat_number_is_not_k(): void {
		$buyer    = $this->party( 'DE', true );
		$decision = TaxCategoryResolver::resolve( $this->party( 'FR' ), $buyer, 0.0 );

		$this->assertNotSame( 'K', $decision->category );
		$this->assertSame( 'Z', $decision->category );
		$this->assertSame( 'cross_border_zero_rate_suspicious', $decision->reason );
		$this->assertFalse( $decision->is_certain );
	}

	/**
	 * Geçersiz biçimli KDV numarası da yok sayılır.
	 *
	 * @return void
	 */
	public function test_malformed_vat_number_does_not_qualify_for_exemption(): void {
		$buyer    = $this->party( 'DE', true, 'not-a-vat-number' );
		$decision = TaxCategoryResolver::resolve( $this->party( 'FR' ), $buyer, 0.0 );

		$this->assertSame( 'Z', $decision->category );
	}

	/**
	 * Satıcı AB dışındaysa teslim kapsam dışıdır.
	 *
	 * @return void
	 */
	public function test_seller_outside_eu_is_out_of_scope(): void {
		$decision = TaxCategoryResolver::resolve( $this->party( 'TR' ), $this->party( 'FR' ), 0.0 );

		$this->assertSame( 'O', $decision->category );
	}
}
