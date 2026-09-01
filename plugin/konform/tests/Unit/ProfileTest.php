<?php
/**
 * Belge profili testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Invoice\ExemptionReason;
use Konform\Invoice\Profile;
use Konform\License\Plan;
use PHPUnit\Framework\TestCase;

/**
 * Profil satıcının ülkesinden türer, alıcının değil — mükellefiyet satıcıya
 * aittir. Bu ayrımı kaçırmak, Fransız mağazanın Alman müşterisine XRechnung
 * kesmesine yol açardı.
 */
final class ProfileTest extends TestCase {

	/**
	 * Ülkeler doğru profile eşlenir.
	 *
	 * @return void
	 */
	public function test_countries_map_to_profiles(): void {
		$this->assertSame( Profile::FACTUR_X, Profile::for_country( 'FR' ) );
		$this->assertSame( Profile::XRECHNUNG, Profile::for_country( 'DE' ) );
		$this->assertSame( Profile::EN16931, Profile::for_country( 'NL' ) );
		$this->assertSame( Profile::EN16931, Profile::for_country( '' ) );
	}

	/**
	 * Ülke kodu büyük-küçük harf duyarsızdır.
	 *
	 * @return void
	 */
	public function test_country_code_is_case_insensitive(): void {
		$this->assertSame( Profile::FACTUR_X, Profile::for_country( 'fr' ) );
		$this->assertSame( Profile::XRECHNUNG, Profile::for_country( ' de ' ) );
	}

	/**
	 * Yalnızca Factur-X hibrittir.
	 *
	 * XRechnung ve KSeF saf XML olarak iletilir; oralarda PDF üretmek
	 * gereksiz iştir.
	 *
	 * @return void
	 */
	public function test_only_factur_x_is_hybrid(): void {
		$this->assertTrue( Profile::FACTUR_X->is_hybrid() );
		$this->assertFalse( Profile::XRECHNUNG->is_hybrid() );
		$this->assertFalse( Profile::EN16931->is_hybrid() );

		$this->assertSame( 'pdf', Profile::FACTUR_X->extension() );
		$this->assertSame( 'xml', Profile::XRECHNUNG->extension() );
	}

	/**
	 * Vergisiz kategoriler istisna gerekçesi ve VATEX kodu gerektirir.
	 *
	 * BR-AE-10, BR-K-10, BR-G-10: gerekçe eksikse belge doğrulamadan geçmez.
	 *
	 * @return void
	 */
	public function test_untaxed_categories_require_an_exemption_reason(): void {
		foreach ( array( 'AE', 'K', 'G', 'E', 'O' ) as $category ) {
			$this->assertTrue( ExemptionReason::is_required( $category ), $category );
			$this->assertNotSame( '', ExemptionReason::text( $category ), $category );
		}

		$this->assertFalse( ExemptionReason::is_required( 'S' ) );
		$this->assertSame( '', ExemptionReason::text( 'S' ) );
	}

	/**
	 * VATEX kodları kanoniktir.
	 *
	 * @return void
	 */
	public function test_vatex_codes_are_canonical(): void {
		$this->assertSame( 'VATEX-EU-AE', ExemptionReason::code( 'AE' ) );
		$this->assertSame( 'VATEX-EU-IC', ExemptionReason::code( 'K' ) );
		$this->assertSame( 'VATEX-EU-G', ExemptionReason::code( 'G' ) );
		$this->assertSame( '', ExemptionReason::code( 'S' ) );
	}

	/**
	 * Planlar sıralıdır; üst plan alttakini kapsar.
	 *
	 * @return void
	 */
	public function test_plans_are_ordered(): void {
		$this->assertTrue( Plan::AGENCY->covers( Plan::PRO ) );
		$this->assertTrue( Plan::PRO->covers( Plan::FREE ) );
		$this->assertTrue( Plan::PRO->covers( Plan::PRO ) );
		$this->assertFalse( Plan::FREE->covers( Plan::PRO ) );
	}
}
