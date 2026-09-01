<?php
/**
 * KDV istisna gerekçeleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * BR-AE-10, BR-E-10, BR-G-10, BR-K-10, BR-O-10: vergi alınmayan her kategori
 * için istisna gerekçesi (BT-120) veya gerekçe kodu (BT-121) zorunludur.
 *
 * Bu metinler standart hukuki ifadelerdir ve satıcının yazacağı bir şey
 * değildir — eklenti üretir. Ön uçuş kontrolünde eksiklik olarak raporlanmaz;
 * kullanıcının düzeltemeyeceği bir şeyi ona hata göstermek raporu güvenilmez
 * yapar (bkz. Preflight\Rule).
 *
 * Kodlar CEF VATEX kod listesindendir ve kanoniktir, çevrilmez. Çevrilen
 * yalnızca faturada görünen metindir ve belge diline uyar.
 */
final class ExemptionReason {

	/**
	 * Kategoriye karşılık gelen VATEX kodunu döndürür.
	 *
	 * @param string $category Vergi kategorisi kodu (UNTDID 5305).
	 * @return string Gerekli değilse boş dize.
	 */
	public static function code( string $category ): string {
		return match ( $category ) {
			'AE'    => 'VATEX-EU-AE',
			'K'     => 'VATEX-EU-IC',
			'G'     => 'VATEX-EU-G',
			'O'     => 'VATEX-EU-O',
			default => '',
		};
	}

	/**
	 * Faturada görünecek gerekçe metnini döndürür.
	 *
	 * Belge dilinde üretilir; çağrı Locale::render() içinden yapılmalıdır.
	 *
	 * @param string $category Vergi kategorisi kodu.
	 * @return string Gerekli değilse boş dize.
	 */
	public static function text( string $category ): string {
		$text = match ( $category ) {
			'AE'    => __( 'Reverse charge: VAT is due from the recipient.', 'konform' ),
			'K'     => __( 'Intra-Community supply, exempt under Article 138 of Council Directive 2006/112/EC.', 'konform' ),
			'G'     => __( 'Export outside the European Union, exempt from VAT.', 'konform' ),
			'E'     => __( 'Exempt from VAT.', 'konform' ),
			'O'     => __( 'Not subject to VAT.', 'konform' ),
			default => '',
		};

		/**
		 * İstisna gerekçesi metnini değiştirir.
		 *
		 * Bazı ülkeler kendi kanun maddelerine atıf yapılmasını ister.
		 *
		 * @param string $text     Gerekçe metni.
		 * @param string $category Vergi kategorisi kodu.
		 */
		return (string) \apply_filters( 'konform/exemption_reason', $text, $category );
	}

	/**
	 * Kategorinin gerekçe gerektirip gerektirmediğini bildirir.
	 *
	 * @param string $category Vergi kategorisi kodu.
	 * @return bool
	 */
	public static function is_required( string $category ): bool {
		return in_array( $category, array( 'AE', 'E', 'G', 'K', 'O' ), true );
	}
}
