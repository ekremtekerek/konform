<?php
/**
 * Doğrulama karar mantığı testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Bu sınıftaki tek bir mantık hatası iki yönde de felakettir:
 *
 *   - Geçersiz belge engellenmezse, reddedileceği bilinen fatura gönderilir.
 *   - Ulaşılamama durumu geçersizlikle karıştırılırsa, geçici bir ağ arızası
 *     bütün faturaların kesilmesini durdurur.
 *
 * Bu yüzden üç durum ayrı ayrı kilitlenir.
 */
final class ValidationResultTest extends TestCase {

	/**
	 * Geçerli belge engellenmez.
	 *
	 * @return void
	 */
	public function test_valid_document_does_not_block(): void {
		$result = new ValidationResult( true, true, array(), array(), '1.3.16' );

		$this->assertFalse( $result->blocks() );
		$this->assertStringContainsString( '1.3.16', $result->summary() );
	}

	/**
	 * Kesin olarak geçersiz belge ENGELLENİR.
	 *
	 * @return void
	 */
	public function test_confirmed_invalid_document_blocks(): void {
		$result = new ValidationResult(
			false,
			true,
			array(
				array(
					'rule'    => 'BR-IC-11',
					'message' => 'Delivery date required.',
				),
			)
		);

		$this->assertTrue( $result->blocks() );
		$this->assertStringContainsString( 'BR-IC-11', $result->summary() );
	}

	/**
	 * Servise ulaşılamaması ENGEL DEĞİLDİR.
	 *
	 * Geçici bir ağ arızası yüzünden faturaların kesilmemesi kabul edilemez.
	 *
	 * @return void
	 */
	public function test_unreachable_service_does_not_block(): void {
		$result = ValidationResult::unavailable( 'cURL error 7' );

		$this->assertFalse( $result->blocks() );
		$this->assertFalse( $result->available );
		$this->assertSame( 'cURL error 7', $result->summary() );
	}

	/**
	 * Doğrulama kapalıysa engel yoktur.
	 *
	 * Ücretsiz sürümde bu normal durumdur, eksiklik değil.
	 *
	 * @return void
	 */
	public function test_skipped_validation_does_not_block(): void {
		$result = ValidationResult::skipped();

		$this->assertFalse( $result->blocks() );
		$this->assertFalse( $result->available );
	}

	/**
	 * Özet birden fazla hatayı sınırlar.
	 *
	 * @return void
	 */
	public function test_summary_limits_the_number_of_errors(): void {
		$errors = array();

		for ( $i = 0; $i < 10; $i++ ) {
			$errors[] = array(
				'rule'    => 'BR-' . $i,
				'message' => 'problem',
			);
		}

		$summary = ( new ValidationResult( false, true, $errors ) )->summary( 3 );

		$this->assertSame( 3, substr_count( $summary, 'problem' ) );
	}
}
