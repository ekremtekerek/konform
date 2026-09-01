<?php
/**
 * Yerleşik sade fatura şablonu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Pdf;

use Konform\Invoice\ExemptionReason;
use Konform\Invoice\Party;
use Konform\Invoice\SemanticInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Mağazada PDF fatura eklentisi yoksa devreye giren son çare şablon.
 *
 * ÖNEMLİ SINIR: FPDF yalnızca Latin-1 (CP1252) kodlamasını destekler. Fransızca
 * ve Almanca metinler bu kümeye sığar; Lehçe, Çekçe, Yunanca ve Türkçe sığmaz.
 *
 * Bu durumda karakterleri sessizce kırpmak veya benzerine çevirmek KABUL
 * EDİLEMEZ: alıcının adı faturada yanlış yazılırsa belge hukuken kusurludur ve
 * satıcı bunu asla fark etmez. Bu yüzden kaybın olacağı yerde üretmeyi
 * reddeder ve sebebini söyleriz — ürünün geri kalanındaki mantığın aynısı.
 *
 * Çözüm yolu kullanıcı için basittir: bir PDF fatura eklentisi kurmak. O
 * eklentilerin çıktısı tam UTF-8'dir ve WcpdfSource onu tercih eder.
 *
 * Bkz. docs/adr/0002-pdf-uretimi.md
 */
final class BuiltinPdfSource implements PdfSource {

	/**
	 * Sayfa kenar boşluğu, mm.
	 */
	private const MARGIN = 15.0;

	/**
	 * Kullanılabilir içerik genişliği, mm (A4 = 210).
	 */
	private const WIDTH = 180.0;

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'builtin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Konform built-in template', 'konform' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( '\Konform_FPDF' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order       $order   Sipariş.
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @return string
	 * @throws \RuntimeException Metin Latin-1'e sığmazsa.
	 */
	public function render( \WC_Order $order, SemanticInvoice $invoice ): string {
		unset( $order );

		$this->assert_representable( $invoice );

		$pdf = new \Konform_FPDF( 'P', 'mm', 'A4' );
		$pdf->SetAutoPageBreak( true, 20 );
		$pdf->SetMargins( self::MARGIN, self::MARGIN, self::MARGIN );
		$pdf->AddPage();

		$this->draw_header( $pdf, $invoice );
		$this->draw_parties( $pdf, $invoice );
		$this->draw_lines( $pdf, $invoice );
		$this->draw_totals( $pdf, $invoice );
		$this->draw_tax_notes( $pdf, $invoice );

		return (string) $pdf->Output( 'S' );
	}

	/**
	 * Başlık ve fatura künyesi.
	 *
	 * @param \Konform_FPDF   $pdf     PDF.
	 * @param SemanticInvoice $invoice Fatura.
	 * @return void
	 */
	private function draw_header( \Konform_FPDF $pdf, SemanticInvoice $invoice ): void {
		$pdf->SetFont( 'Helvetica', 'B', 16 );
		$pdf->Cell( 110, 8, $this->text( $invoice->seller->name ), 0, 0, 'L' );

		$pdf->SetFont( 'Helvetica', 'B', 16 );
		$pdf->Cell( 70, 8, $this->text( __( 'Invoice', 'konform' ) ), 0, 1, 'R' );

		$pdf->SetFont( 'Helvetica', '', 9 );
		$pdf->Cell( 110, 5, $this->text( $invoice->seller->address ), 0, 0, 'L' );
		$pdf->Cell(
			70,
			5,
			$this->text( __( 'Number', 'konform' ) . ': ' . $invoice->number ),
			0,
			1,
			'R'
		);

		$pdf->Cell(
			110,
			5,
			$this->text( trim( $invoice->seller->postcode . ' ' . $invoice->seller->city . ' ' . $invoice->seller->country ) ),
			0,
			0,
			'L'
		);
		$pdf->Cell(
			70,
			5,
			$this->text( __( 'Date', 'konform' ) . ': ' . \wp_date( 'Y-m-d', $invoice->issue_date->getTimestamp() ) ),
			0,
			1,
			'R'
		);

		if ( '' !== $invoice->seller->vat_number ) {
			$pdf->Cell(
				110,
				5,
				$this->text( __( 'VAT number', 'konform' ) . ': ' . $invoice->seller->vat_number ),
				0,
				1,
				'L'
			);
		}

		$pdf->Ln( 6 );
	}

	/**
	 * Alıcı bloğu.
	 *
	 * @param \Konform_FPDF   $pdf     PDF.
	 * @param SemanticInvoice $invoice Fatura.
	 * @return void
	 */
	private function draw_parties( \Konform_FPDF $pdf, SemanticInvoice $invoice ): void {
		$buyer = $invoice->buyer;

		$pdf->SetFont( 'Helvetica', 'B', 9 );
		$pdf->Cell( self::WIDTH, 5, $this->text( __( 'Bill to', 'konform' ) ), 0, 1, 'L' );

		$pdf->SetFont( 'Helvetica', '', 10 );
		$pdf->Cell( self::WIDTH, 5, $this->text( $buyer->name ), 0, 1, 'L' );

		$pdf->SetFont( 'Helvetica', '', 9 );

		foreach ( $this->address_lines( $buyer ) as $line ) {
			$pdf->Cell( self::WIDTH, 5, $this->text( $line ), 0, 1, 'L' );
		}

		$pdf->Ln( 6 );
	}

	/**
	 * Alıcının adres satırlarını döndürür.
	 *
	 * @param Party $party Taraf.
	 * @return string[]
	 */
	private function address_lines( Party $party ): array {
		$lines = array();

		if ( '' !== $party->address ) {
			$lines[] = $party->address;
		}

		$city = trim( $party->postcode . ' ' . $party->city );

		if ( '' !== $city ) {
			$lines[] = $city;
		}

		if ( '' !== $party->country ) {
			$lines[] = $party->country;
		}

		if ( '' !== $party->vat_number ) {
			$lines[] = __( 'VAT number', 'konform' ) . ': ' . $party->vat_number;
		}

		return $lines;
	}

	/**
	 * Satır tablosu.
	 *
	 * @param \Konform_FPDF   $pdf     PDF.
	 * @param SemanticInvoice $invoice Fatura.
	 * @return void
	 */
	private function draw_lines( \Konform_FPDF $pdf, SemanticInvoice $invoice ): void {
		$columns = array(
			array( __( 'Description', 'konform' ), 88.0, 'L' ),
			array( __( 'Qty', 'konform' ), 16.0, 'R' ),
			array( __( 'Unit price', 'konform' ), 28.0, 'R' ),
			array( __( 'VAT', 'konform' ), 20.0, 'R' ),
			array( __( 'Net', 'konform' ), 28.0, 'R' ),
		);

		$pdf->SetFont( 'Helvetica', 'B', 9 );
		$pdf->SetFillColor( 235, 238, 240 );

		foreach ( $columns as $column ) {
			$pdf->Cell( $column[1], 7, $this->text( (string) $column[0] ), 0, 0, (string) $column[2], true );
		}

		$pdf->Ln();
		$pdf->SetFont( 'Helvetica', '', 9 );

		foreach ( $invoice->lines as $line ) {
			$name = $line->name;

			if ( mb_strlen( $name ) > 52 ) {
				$name = mb_substr( $name, 0, 51 ) . '…';
			}

			$pdf->Cell( 88, 6, $this->text( $name ), 0, 0, 'L' );
			$pdf->Cell( 16, 6, $this->text( \number_format_i18n( $line->quantity, 0 ) ), 0, 0, 'R' );
			$pdf->Cell( 28, 6, $this->text( $this->money( $line->net_price, $invoice->currency ) ), 0, 0, 'R' );
			$pdf->Cell(
				20,
				6,
				$this->text( $line->tax_category . ' ' . \number_format_i18n( $line->tax_rate, 0 ) . '%' ),
				0,
				0,
				'R'
			);
			$pdf->Cell( 28, 6, $this->text( $this->money( $line->net_amount, $invoice->currency ) ), 0, 1, 'R' );
		}

		$pdf->Ln( 2 );
	}

	/**
	 * Toplamlar.
	 *
	 * @param \Konform_FPDF   $pdf     PDF.
	 * @param SemanticInvoice $invoice Fatura.
	 * @return void
	 */
	private function draw_totals( \Konform_FPDF $pdf, SemanticInvoice $invoice ): void {
		$rows = array(
			array( __( 'Net total', 'konform' ), $invoice->tax_exclusive_total(), false ),
			array( __( 'VAT', 'konform' ), $invoice->tax_total(), false ),
			array( __( 'Total', 'konform' ), $invoice->tax_inclusive_total(), true ),
		);

		foreach ( $rows as $row ) {
			$pdf->SetFont( 'Helvetica', $row[2] ? 'B' : '', $row[2] ? 11 : 9 );
			$pdf->Cell( 132, 6, '', 0, 0 );
			$pdf->Cell( 20, 6, $this->text( (string) $row[0] ), 0, 0, 'R' );
			$pdf->Cell( 28, 6, $this->text( $this->money( (float) $row[1], $invoice->currency ) ), 0, 1, 'R' );
		}

		$pdf->Ln( 4 );
	}

	/**
	 * KDV kırılımı ve istisna gerekçeleri.
	 *
	 * @param \Konform_FPDF   $pdf     PDF.
	 * @param SemanticInvoice $invoice Fatura.
	 * @return void
	 */
	private function draw_tax_notes( \Konform_FPDF $pdf, SemanticInvoice $invoice ): void {
		$pdf->SetFont( 'Helvetica', '', 8 );

		foreach ( $invoice->tax_subtotals as $subtotal ) {
			if ( ! ExemptionReason::is_required( $subtotal->category ) ) {
				continue;
			}

			$reason = '' !== $subtotal->exemption_reason
				? $subtotal->exemption_reason
				: ExemptionReason::text( $subtotal->category );

			if ( '' === $reason ) {
				continue;
			}

			$pdf->MultiCell( self::WIDTH, 4, $this->text( $reason ), 0, 'L' );
			$pdf->Ln( 1 );
		}
	}

	/**
	 * Tutarı biçimlendirir.
	 *
	 * @param float  $amount   Tutar.
	 * @param string $currency Para birimi kodu.
	 * @return string
	 */
	private function money( float $amount, string $currency ): string {
		return \number_format_i18n( $amount, 2 ) . ' ' . $currency;
	}

	/**
	 * Metni FPDF'in beklediği CP1252 kodlamasına çevirir.
	 *
	 * @param string $text Metin.
	 * @return string
	 */
	private function text( string $text ): string {
		$converted = @iconv( 'UTF-8', 'CP1252', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Donusum kaybi assert_representable() ile onceden yakalanir; buradaki uyari gereksizdir.

		return false === $converted ? $text : $converted;
	}

	/**
	 * Faturadaki tüm metinlerin Latin-1'e sığdığını doğrular.
	 *
	 * Sığmıyorsa üretmeyi reddeder. Sessizce karakter kaybetmek, alıcının
	 * adının faturada yanlış yazılması demektir; bunu satıcı fark etmez.
	 *
	 * @param SemanticInvoice $invoice Fatura.
	 * @return void
	 * @throws \RuntimeException Kayıp olacaksa.
	 */
	private function assert_representable( SemanticInvoice $invoice ): void {
		$fields = array(
			'seller name'  => $invoice->seller->name,
			'seller city'  => $invoice->seller->city,
			'buyer name'   => $invoice->buyer->name,
			'buyer street' => $invoice->buyer->address,
			'buyer city'   => $invoice->buyer->city,
		);

		foreach ( $invoice->lines as $index => $line ) {
			$fields[ 'line ' . ( (int) $index + 1 ) ] = $line->name;
		}

		foreach ( $fields as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Donusturulemeyen karakterde iconv uyari uretir; burada aranan tam olarak o durumdur.
			if ( false === @iconv( 'UTF-8', 'CP1252', $value ) ) {
				$message = sprintf(
					'The built-in template cannot render "%s" because it contains characters outside Latin-1. Install a PDF invoice plugin for full Unicode support.',
					$label
				);

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil; kacislamak metni bozar.
				throw new \RuntimeException( $message );
			}
		}
	}
}
