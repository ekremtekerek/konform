<?php
/**
 * Polonya FA(3) üreticisi testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Invoice\Fa3Builder;
use Konform\Invoice\Line;
use Konform\Invoice\Party;
use Konform\Invoice\Profile;
use Konform\Invoice\SemanticInvoice;
use Konform\Invoice\TaxSubtotal;
use Konform\Invoice\ZugferdBuilder;
use PHPUnit\Framework\TestCase;

/**
 * FA(3), Factur-X ve XRechnung'un aksine CII değildir; alanlar Lehçedir ve
 * vergi rejimleri orana değil kategoriye göre ayrılır. Bu testlerin çoğu tam
 * olarak o ayrımı koruyor: %0 tek bir alan değildir, üçe bölünmüştür ve
 * yanlış alana yazmak yanlış beyan demektir.
 *
 * Belgeler resmî XSD'ye karşı doğrulanır; üretici şema dışı bir belge
 * üretirse test kırılır.
 */
final class Fa3BuilderTest extends TestCase {

	/**
	 * Muafiyetin hukuki dayanağı (BT-120).
	 */
	private const EXEMPTION = 'Zwolnienie na podstawie art. 43 ust. 1 ustawy o VAT';


	/**
	 * Polonya, iletim hazır olana dek üretilebilir bir profil almaya devam eder.
	 *
	 * KSEF profili ve Fa3Builder hazir ama uretim akisina bagli DEGIL. PL'yi
	 * KSEF'e yonlendirmek, hicbir uretici o profili desteklemedigi icin
	 * Generator'un null donmesine yol acar: magaza bugun aldigi EN 16931 CII
	 * belgesini, yerine gecerli bir sey konmadan kaybeder.
	 *
	 * Bu test o gerilemeyi tutuyor; profilin yalnizca ne oldugunu degil,
	 * gercekten uretilebilir oldugunu da dogruluyor.
	 *
	 * @return void
	 */
	public function test_poland_still_receives_a_buildable_profile(): void {
		$profile = Profile::for_country( 'PL' );

		$this->assertSame( Profile::EN16931, $profile );
		$this->assertTrue( ( new ZugferdBuilder() )->supports( $profile ) );
	}

	/**
	 * Yalnızca KSeF profilini destekler.
	 *
	 * @return void
	 */
	public function test_supports_only_ksef(): void {
		$builder = new Fa3Builder();

		$this->assertTrue( $builder->supports( Profile::KSEF ) );
		$this->assertFalse( $builder->supports( Profile::FACTUR_X ) );
		$this->assertFalse( $builder->supports( Profile::XRECHNUNG ) );
		$this->assertFalse( $builder->supports( Profile::EN16931 ) );
	}

	/**
	 * Yurt içi satış şemaya uygun belge üretir.
	 *
	 * @return void
	 */
	public function test_domestic_sale_produces_valid_document(): void {
		$xml = ( new Fa3Builder() )->build_xml( $this->invoice(), Profile::KSEF );

		$this->assertStringContainsString( '<Faktura', $xml );
		$this->assertStringContainsString( 'FA/2026/1', $xml );

		// %23 net ve KDV kendi alanlarina yazilmali.
		$this->assertStringContainsString( '<P_13_1>100.00</P_13_1>', $xml );
		$this->assertStringContainsString( '<P_14_1>23.00</P_14_1>', $xml );
	}

	/**
	 * Satıcının NIP'i ülke önekinden arındırılır.
	 *
	 * @return void
	 */
	public function test_seller_nip_has_no_country_prefix(): void {
		$xml = ( new Fa3Builder() )->build_xml( $this->invoice(), Profile::KSEF );

		$this->assertStringContainsString( '<NIP>1234567890</NIP>', $xml );
		$this->assertStringNotContainsString( 'PL1234567890', $xml );
	}

	/**
	 * AB içi teslim, ihracattan ve yurt içi sıfırdan ayrı alana yazılır.
	 *
	 * FA(3)'te %0 tek bir alan degildir. AB ici teslimi p1361'e yazmak sema
	 * denetiminden gecer ama beyani bozar; bu test o hatayi yakalar.
	 *
	 * @return void
	 */
	public function test_intra_community_supply_uses_its_own_field(): void {
		$invoice = $this->invoice( 'K', 0.0 );
		$xml     = ( new Fa3Builder() )->build_xml( $invoice, Profile::KSEF );

		$this->assertStringContainsString( '<P_13_6_2>100.00</P_13_6_2>', $xml );
		$this->assertStringNotContainsString( '<P_13_6_1>', $xml );
		$this->assertStringNotContainsString( '<P_13_6_3>', $xml );
	}

	/**
	 * İhracat kendi alanına yazılır.
	 *
	 * @return void
	 */
	public function test_export_uses_its_own_field(): void {
		$xml = ( new Fa3Builder() )->build_xml( $this->invoice( 'G', 0.0 ), Profile::KSEF );

		$this->assertStringContainsString( '<P_13_6_3>100.00</P_13_6_3>', $xml );
		$this->assertStringNotContainsString( '<P_13_6_2>', $xml );
	}

	/**
	 * Muafiyet ve tersine yük ayrı alanlara yazılır.
	 *
	 * @return void
	 */
	public function test_exempt_and_reverse_charge_have_distinct_fields(): void {
		$builder = new Fa3Builder();

		$exempt = $builder->build_xml( $this->invoice( 'E', 0.0, self::EXEMPTION ), Profile::KSEF );
		$this->assertStringContainsString( '<P_13_7>100.00</P_13_7>', $exempt );

		$reverse = $builder->build_xml( $this->invoice( 'AE', 0.0 ), Profile::KSEF );
		$this->assertStringContainsString( '<P_13_10>100.00</P_13_10>', $reverse );
	}

	/**
	 * Desteklenmeyen bir KDV oranı sessizce yutulmaz.
	 *
	 * FA(3) serbest oran kabul etmez. Bilinmeyen bir orani atlayip belgeyi
	 * uretmek, eksik beyanla fatura kesmek demek olurdu.
	 *
	 * @return void
	 */
	public function test_unsupported_rate_is_refused(): void {
		$this->expectException( \RuntimeException::class );

		( new Fa3Builder() )->build_xml( $this->invoice( 'S', 19.0 ), Profile::KSEF );
	}

	/**
	 * Dayanağı olmayan muafiyet reddedilir.
	 *
	 * FA(3) muaf faturada muafiyetin hukuki dayanagini ister. Dayanak
	 * olmadan belge uretip "muafiyet yok" demek yanlis beyan olurdu; bu
	 * yuzden uretici belge uretmek yerine duruyor.
	 *
	 * @return void
	 */
	public function test_exempt_without_legal_basis_is_refused(): void {
		$this->expectException( \RuntimeException::class );

		( new Fa3Builder() )->build_xml( $this->invoice( 'E', 0.0 ), Profile::KSEF );
	}

	/**
	 * Hibrit biçim reddedilir.
	 *
	 * @return void
	 */
	public function test_hybrid_is_refused(): void {
		$this->expectException( \RuntimeException::class );

		( new Fa3Builder() )->build_hybrid( $this->invoice(), Profile::KSEF, 'pdf' );
	}

	/**
	 * Test faturası.
	 *
	 * @param string $category Vergi kategorisi.
	 * @param float  $rate     Yüzde olarak oran.
	 * @param string $exemption_reason Muafiyetin hukuki dayanağı. BT-120.
	 * @return SemanticInvoice
	 */
	private function invoice( string $category = 'S', float $rate = 23.0, string $exemption_reason = '' ): SemanticInvoice {
		$tax = round( 100.0 * $rate / 100, 2 );

		return new SemanticInvoice(
			'FA/2026/1',
			new \DateTimeImmutable( '2026-09-03' ),
			'380',
			'PLN',
			new Party( 'Sprzedawca Sp. z o.o.', 'PL', 'PL1234567890', 'ul. Mickiewicza 1', 'Warszawa', '00-001', 'sprzedawca@example.test', true ),
			new Party( 'Nabywca S.A.', 'PL', 'PL9876543210', 'ul. Rynek 1', 'Kraków', '30-001', 'nabywca@example.test', true ),
			array(
				new Line( '1', 'Lampa biurkowa', 1.0, 'szt.', 100.0, 100.0, $category, $rate ),
			),
			array(
				new TaxSubtotal( $category, $rate, 100.0, $tax, $exemption_reason ),
			)
		);
	}
}
