<?php
/**
 * AB üyeliği ve KDV numarası biçimi testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Invoice\Eu;
use PHPUnit\Framework\TestCase;

/**
 * Vergi kategorisi çözümlemesinin tamamı "alıcı AB'de mi" sorusuna dayanır;
 * bu sınıftaki bir hata sessizce yanlış kategoriye yol açar.
 */
final class EuTest extends TestCase {

	/**
	 * Üye devletler tanınır.
	 *
	 * @return void
	 */
	public function test_recognises_member_states(): void {
		$this->assertTrue( Eu::is_member( 'FR' ) );
		$this->assertTrue( Eu::is_member( 'de' ) );
		$this->assertTrue( Eu::is_member( ' PL ' ) );
	}

	/**
	 * Birleşik Krallık 2021'den beri üye değildir.
	 *
	 * @return void
	 */
	public function test_united_kingdom_is_not_a_member(): void {
		$this->assertFalse( Eu::is_member( 'GB' ) );
	}

	/**
	 * Kuzey İrlanda mal ticaretinde AB KDV sistemi içindedir.
	 *
	 * @return void
	 */
	public function test_northern_ireland_stays_in_the_vat_area(): void {
		$this->assertTrue( Eu::is_member( 'XI' ) );
	}

	/**
	 * Üye olmayan ülkeler reddedilir.
	 *
	 * @return void
	 */
	public function test_rejects_non_members(): void {
		$this->assertFalse( Eu::is_member( 'US' ) );
		$this->assertFalse( Eu::is_member( 'TR' ) );
		$this->assertFalse( Eu::is_member( '' ) );
	}

	/**
	 * Geçerli biçimli KDV numaraları tanınır.
	 *
	 * @return void
	 */
	public function test_accepts_well_formed_vat_numbers(): void {
		$this->assertTrue( Eu::looks_like_vat_number( 'DE123456789' ) );
		$this->assertTrue( Eu::looks_like_vat_number( 'FR40303265045' ) );
		$this->assertTrue( Eu::looks_like_vat_number( 'NL123456789B01' ) );
		$this->assertTrue( Eu::looks_like_vat_number( 'de 123 456 789' ) );
	}

	/**
	 * Bozuk numaralar reddedilir.
	 *
	 * @return void
	 */
	public function test_rejects_malformed_vat_numbers(): void {
		$this->assertFalse( Eu::looks_like_vat_number( '123456789' ) );
		$this->assertFalse( Eu::looks_like_vat_number( 'US123456789' ) );
		$this->assertFalse( Eu::looks_like_vat_number( 'DE' ) );
		$this->assertFalse( Eu::looks_like_vat_number( '' ) );
	}

	/**
	 * Yunanistan'ın KDV ön eki EL, ISO kodu GR'dir.
	 *
	 * Bu tek istisnayı kaçırmak, Yunan müşterilerde yanlış "ülke uyuşmuyor"
	 * uyarısı üretir.
	 *
	 * @return void
	 */
	public function test_greek_prefix_is_normalised_to_iso_code(): void {
		$this->assertSame( 'GR', Eu::vat_prefix( 'EL123456789' ) );
		$this->assertSame( 'DE', Eu::vat_prefix( 'DE123456789' ) );
		$this->assertSame( '', Eu::vat_prefix( 'X' ) );
	}
}
