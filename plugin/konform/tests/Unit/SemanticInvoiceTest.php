<?php
/**
 * Toplam aritmetiği testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Invoice\Line;
use Konform\Invoice\Party;
use Konform\Invoice\SemanticInvoice;
use Konform\Invoice\TaxSubtotal;
use PHPUnit\Framework\TestCase;

/**
 * BR-CO-13 ve BR-CO-15 gereği toplamlar kuruşu kuruşuna tutmalıdır. Bir
 * kuruşluk fark bile belgeyi reddettirir, ve satıcı sebebini asla anlamaz.
 */
final class SemanticInvoiceTest extends TestCase {

	/**
	 * Fatura üretir.
	 *
	 * @param Line[]        $lines     Satırlar.
	 * @param TaxSubtotal[] $subtotals Vergi kırılımı.
	 * @param float         $paid      Ödenmiş tutar.
	 * @return SemanticInvoice
	 */
	private function invoice( array $lines, array $subtotals, float $paid = 0.0 ): SemanticInvoice {
		$party = new Party( 'Test', 'FR', '', 'Street', 'City', '00000', '', true );

		return new SemanticInvoice(
			'INV-1',
			new \DateTimeImmutable( '2026-09-01' ),
			'380',
			'EUR',
			$party,
			$party,
			$lines,
			$subtotals,
			$paid
		);
	}

	/**
	 * Satır üretir.
	 *
	 * @param float $net  Net tutar.
	 * @param float $rate Oran.
	 * @return Line
	 */
	private function line( float $net, float $rate ): Line {
		return new Line( '1', 'Item', 1.0, 'C62', $net, $net, 'S', $rate );
	}

	/**
	 * Satır toplamı ve vergi doğru hesaplanır.
	 *
	 * @return void
	 */
	public function test_totals_add_up(): void {
		$invoice = $this->invoice(
			array( $this->line( 100.0, 20.0 ), $this->line( 50.0, 20.0 ) ),
			array( new TaxSubtotal( 'S', 20.0, 150.0, 30.0 ) )
		);

		$this->assertSame( 150.0, $invoice->line_net_total() );
		$this->assertSame( 150.0, $invoice->tax_exclusive_total() );
		$this->assertSame( 30.0, $invoice->tax_total() );
		$this->assertSame( 180.0, $invoice->tax_inclusive_total() );
	}

	/**
	 * Ödenmiş tutar ödenecek tutardan düşülür.
	 *
	 * @return void
	 */
	public function test_paid_amount_reduces_the_amount_due(): void {
		$invoice = $this->invoice(
			array( $this->line( 100.0, 20.0 ) ),
			array( new TaxSubtotal( 'S', 20.0, 100.0, 20.0 ) ),
			120.0
		);

		$this->assertSame( 120.0, $invoice->tax_inclusive_total() );
		$this->assertSame( 0.0, $invoice->due_amount() );
	}

	/**
	 * Kayan nokta birikimi kuruş hatası üretmez.
	 *
	 * 0.1 + 0.2 türü birikim tam da BR-CO-15'i patlatan şeydir.
	 *
	 * @return void
	 */
	public function test_floating_point_accumulation_stays_exact_to_the_cent(): void {
		$lines = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$lines[] = $this->line( 0.1, 0.0 );
		}

		$invoice = $this->invoice( $lines, array( new TaxSubtotal( 'Z', 0.0, 0.3, 0.0 ) ) );

		$this->assertSame( 0.3, $invoice->line_net_total() );
		$this->assertSame( 0.3, $invoice->tax_inclusive_total() );
	}

	/**
	 * Birden fazla oran ayrı kırılım satırlarında toplanır.
	 *
	 * @return void
	 */
	public function test_multiple_rates_are_summed(): void {
		$invoice = $this->invoice(
			array( $this->line( 100.0, 20.0 ), $this->line( 100.0, 5.5 ) ),
			array(
				new TaxSubtotal( 'S', 20.0, 100.0, 20.0 ),
				new TaxSubtotal( 'S', 5.5, 100.0, 5.5 ),
			)
		);

		$this->assertSame( 25.5, $invoice->tax_total() );
		$this->assertSame( 225.5, $invoice->tax_inclusive_total() );
	}

	/**
	 * Kullanılan kategoriler benzersiz olarak döner.
	 *
	 * @return void
	 */
	public function test_tax_categories_are_unique(): void {
		$invoice = $this->invoice(
			array( $this->line( 10.0, 20.0 ) ),
			array(
				new TaxSubtotal( 'S', 20.0, 10.0, 2.0 ),
				new TaxSubtotal( 'S', 5.5, 10.0, 0.55 ),
				new TaxSubtotal( 'AE', 0.0, 10.0, 0.0 ),
			)
		);

		$this->assertSame( array( 'S', 'AE' ), $invoice->tax_categories() );
	}
}
