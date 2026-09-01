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
}
