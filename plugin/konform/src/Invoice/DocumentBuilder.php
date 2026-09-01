<?php
/**
 * Belge üretici arayüzü.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * Anlamsal faturayı somut bir sözdizimine çeviren bileşen.
 *
 * Bu arayüz, ADR 0001'de verilen sözün karşılığıdır: üçüncü taraf kütüphane
 * hiçbir zaman doğrudan çağrılmaz, hep bu arayüzün arkasında durur. Böylece
 * kütüphaneyi değiştirmek tek bir adaptör sınıfını değiştirmek demek olur ve
 * iş mantığımız dış API'ye sızmaz.
 *
 * Birim testleri gerçek kütüphane olmadan sahte bir adaptörle çalışabilir.
 */
interface DocumentBuilder {

	/**
	 * Bu üreticinin verilen profili destekleyip desteklemediğini bildirir.
	 *
	 * @param Profile $profile Belge profili.
	 * @return bool
	 */
	public function supports( Profile $profile ): bool;

	/**
	 * Faturanın XML gösterimini üretir.
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @param Profile         $profile Belge profili.
	 * @return string XML içeriği.
	 * @throws \RuntimeException Profil desteklenmiyorsa veya üretim başarısızsa.
	 */
	public function build_xml( SemanticInvoice $invoice, Profile $profile ): string;

	/**
	 * XML'i verilen PDF'e gömerek PDF/A-3 hibrit belge üretir.
	 *
	 * Yalnızca hibrit profiller (Factur-X) için çağrılır; XRechnung ve KSeF
	 * saf XML olarak iletilir ve PDF üretmek gereksiz iş olur.
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @param Profile         $profile Belge profili.
	 * @param string          $pdf     İnsan tarafından okunan PDF içeriği.
	 * @return string PDF/A-3 içeriği.
	 * @throws \RuntimeException Gömme başarısızsa.
	 */
	public function build_hybrid( SemanticInvoice $invoice, Profile $profile, string $pdf ): string;
}
