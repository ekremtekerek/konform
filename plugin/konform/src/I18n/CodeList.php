<?php
/**
 * EN 16931 kod listeleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * EN 16931 kod listeleri kanoniktir: XML'e daima kodun kendisi yazılır ve kod
 * ASLA çevrilmez. Çevrilen tek şey, bu kodların yönetici arayüzündeki insan
 * tarafından okunan etiketidir.
 *
 *     $xml_value = 'AE';                              // her zaman
 *     $screen    = CodeList::label( 'tax_category', 'AE' );  // kullanıcının dilinde
 *
 * Bkz. docs/I18N.md bölüm 3.
 */
final class CodeList {

	/**
	 * Vergi kategorisi kodları — UNTDID 5305.
	 */
	public const TAX_CATEGORY = 'tax_category';

	/**
	 * Belge tipi kodları — UNTDID 1001.
	 */
	public const DOCUMENT_TYPE = 'document_type';

	/**
	 * Bilinen kod listeleri ve geçerli kodları.
	 *
	 * Etiketler burada tutulmaz; çeviriler istek anında çözülmelidir.
	 *
	 * @var array<string, string[]>
	 */
	private const CODES = array(
		self::TAX_CATEGORY  => array( 'S', 'Z', 'E', 'AE', 'K', 'G', 'O', 'L', 'M' ),
		self::DOCUMENT_TYPE => array( '380', '381', '384', '389' ),
	);

	/**
	 * Bir kodun insan tarafından okunan etiketini döndürür.
	 *
	 * Etiketler her çağrıda yeniden üretilir; statik önbelleğe alınırsa
	 * switch_to_locale() sonrasında önceki dilde takılı kalırlar.
	 *
	 * @param string $code_list Kod listesi tanımlayıcısı.
	 * @param string $code Kod.
	 * @return string Etiket; bilinmeyen kodda kodun kendisi.
	 */
	public static function label( string $code_list, string $code ): string {
		$labels = self::labels( $code_list );

		return $labels[ $code ] ?? $code;
	}

	/**
	 * Bir kod listesinin tamamını "kod => etiket" biçiminde döndürür.
	 *
	 * Açılır menüleri doldurmak için kullanılır.
	 *
	 * @param string $code_list Kod listesi tanımlayıcısı.
	 * @return array<string, string>
	 */
	public static function options( string $code_list ): array {
		return self::labels( $code_list );
	}

	/**
	 * Kodun listede geçerli olup olmadığını bildirir.
	 *
	 * @param string $code_list Kod listesi tanımlayıcısı.
	 * @param string $code Kod.
	 * @return bool
	 */
	public static function exists( string $code_list, string $code ): bool {
		return \in_array( $code, self::CODES[ $code_list ] ?? array(), true );
	}

	/**
	 * Etiketleri üretir.
	 *
	 * Kodun yanında parantez içinde kanonik değeri de gösterilir — muhasebeci
	 * kullanıcı çoğu zaman kodu arar, açıklamayı değil.
	 *
	 * @param string $code_list Kod listesi tanımlayıcısı.
	 * @return array<string, string>
	 */
	private static function labels( string $code_list ): array {
		switch ( $code_list ) {
			case self::TAX_CATEGORY:
				return array(
					'S'  => __( 'Standard rate', 'konform' ),
					'Z'  => __( 'Zero rated goods', 'konform' ),
					'E'  => __( 'Exempt from VAT', 'konform' ),
					'AE' => __( 'VAT reverse charge', 'konform' ),
					'K'  => __( 'VAT exempt intra-community supply', 'konform' ),
					'G'  => __( 'Export, outside the scope of VAT', 'konform' ),
					'O'  => __( 'Services outside the scope of VAT', 'konform' ),
					'L'  => __( 'Canary Islands general indirect tax', 'konform' ),
					'M'  => __( 'Ceuta and Melilla tax', 'konform' ),
				);

			case self::DOCUMENT_TYPE:
				return array(
					'380' => __( 'Commercial invoice', 'konform' ),
					'381' => __( 'Credit note', 'konform' ),
					'384' => __( 'Corrected invoice', 'konform' ),
					'389' => __( 'Self-billed invoice', 'konform' ),
				);

			default:
				return array();
		}
	}
}
