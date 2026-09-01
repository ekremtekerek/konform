<?php
/**
 * İnsan tarafından okunan PDF kaynağı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Pdf;

use Konform\Invoice\SemanticInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Factur-X hibrit bir belgedir: aynı dosyada hem insanın okuduğu PDF hem
 * makinenin okuduğu XML bulunur. XML'i biz üretiriz; PDF'i üretmek ise ayrı
 * bir iştir ve tek bir doğru cevabı yoktur.
 *
 * Mağazaların çoğunda zaten bir PDF fatura eklentisi vardır ve o eklentinin
 * çıktısı mağazanın markasını taşır. Onun yerine geçmeye çalışmak yerine,
 * varsa onu kullanırız; yoksa kendi sade şablonumuza düşeriz.
 *
 * Bu ayrım aynı zamanda kapsamı korur: PDF düzeni çözülmüş ve kalabalık bir
 * problemdir, bizim farkımız orada değil.
 */
interface PdfSource {

	/**
	 * Kaynağın kimliği.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Kullanıcıya gösterilecek adı.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Bu kaynak şu an kullanılabilir mi.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Siparişin PDF'ini üretir.
	 *
	 * Belge dili çağıran tarafından Locale::render() ile ayarlanmış olarak
	 * gelir; bu metot kendi başına dil değiştirmez.
	 *
	 * @param \WC_Order       $order   Sipariş.
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @return string PDF içeriği.
	 * @throws \RuntimeException Üretilemezse, sebebi kullanıcıya gösterilecek
	 *                           açıklıkta.
	 */
	public function render( \WC_Order $order, SemanticInvoice $invoice ): string;
}
