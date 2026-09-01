<?php
/**
 * Ön uçuş rapor sayımı testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Preflight\Finding;
use Konform\Preflight\Report;
use Konform\Preflight\Severity;
use PHPUnit\Framework\TestCase;

/**
 * REGRESYON: ilk sürümde sayaçlar BULGU sayıyordu, sipariş değil. Altı
 * siparişlik bir mağazada ekranda "12 sipariş" yazıyordu; her siparişte iki
 * bulgu vardı. Raporun abartılı görünmesi güvenilirliğini bitirir.
 *
 * Ayrıca mağaza geneli bulgular sipariş saymamalıdır — tek bir ayar
 * düzeltmesiyle çözülen bir sorun, yüzlerce siparişi "sorunlu" göstermemeli.
 */
final class ReportTest extends TestCase {

	/**
	 * Bulgu üretir.
	 *
	 * @param int      $order_id Sipariş kimliği.
	 * @param Severity $severity Ciddiyet.
	 * @param string   $code     Alt kimlik.
	 * @return Finding
	 */
	private function finding( int $order_id, Severity $severity, string $code = 'x' ): Finding {
		return new Finding( 'rule', $code, $severity, $order_id, 'ne', 'neden', 'nasil' );
	}

	/**
	 * Aynı siparişteki birden fazla bulgu tek sipariş sayılır.
	 *
	 * @return void
	 */
	public function test_multiple_findings_on_one_order_count_once(): void {
		$report = new Report(
			6,
			array(
				$this->finding( 10, Severity::BLOCKER, 'a' ),
				$this->finding( 10, Severity::BLOCKER, 'b' ),
				$this->finding( 10, Severity::BLOCKER, 'c' ),
			)
		);

		$this->assertSame( 1, $report->blocked_orders() );
		$this->assertSame( 5, $report->clean_orders() );
	}

	/**
	 * Mağaza geneli bulgular sipariş saymaz.
	 *
	 * @return void
	 */
	public function test_store_wide_findings_do_not_count_as_orders(): void {
		$report = new Report(
			4,
			array(
				$this->finding( Finding::STORE_WIDE, Severity::BLOCKER, 'vat_missing' ),
				$this->finding( Finding::STORE_WIDE, Severity::BLOCKER, 'address' ),
			)
		);

		$this->assertSame( 0, $report->blocked_orders() );
		$this->assertSame( 4, $report->clean_orders() );
		$this->assertCount( 2, $report->store_findings() );
	}

	/**
	 * Engelleyen ve uyaran siparişler ayrı sayılır.
	 *
	 * @return void
	 */
	public function test_blocked_and_flagged_are_counted_separately(): void {
		$report = new Report(
			5,
			array(
				$this->finding( 1, Severity::BLOCKER ),
				$this->finding( 2, Severity::WARNING ),
				$this->finding( 3, Severity::WARNING ),
			)
		);

		$this->assertSame( 1, $report->blocked_orders() );
		$this->assertSame( 2, $report->flagged_orders() );
		$this->assertSame( 2, $report->clean_orders() );
	}

	/**
	 * Gruplama kural kimliği ile alt koda göre yapılır.
	 *
	 * Aynı kuralın farklı sorunları tek başlık altında toplanırsa kullanıcı
	 * yalnızca ilkini görür ve diğerini hiç fark etmez.
	 *
	 * @return void
	 */
	public function test_grouping_separates_distinct_problems_of_the_same_rule(): void {
		$report = new Report(
			2,
			array(
				$this->finding( 1, Severity::BLOCKER, 'name_missing' ),
				$this->finding( 2, Severity::BLOCKER, 'country_missing' ),
			)
		);

		$this->assertCount( 2, $report->grouped() );
	}

	/**
	 * Engelleyiciler uyarılardan önce sıralanır.
	 *
	 * @return void
	 */
	public function test_blockers_are_listed_before_warnings(): void {
		$report = new Report(
			2,
			array(
				$this->finding( 1, Severity::WARNING, 'w' ),
				$this->finding( 2, Severity::BLOCKER, 'b' ),
			)
		);

		$groups = $report->grouped();
		$first  = reset( $groups );

		$this->assertSame( Severity::BLOCKER, $first[0]->severity );
	}

	/**
	 * Hiç bulgu yoksa rapor temizdir.
	 *
	 * @return void
	 */
	public function test_empty_report_is_clean(): void {
		$report = new Report( 3, array() );

		$this->assertTrue( $report->is_clean() );
		$this->assertSame( 3, $report->clean_orders() );
	}
}
