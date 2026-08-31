<?php
/**
 * AB üyeliği ve vergi bölgesi yardımcıları.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * Vergi kategorisi çözümlemesi "alıcı AB'de mi" sorusuna dayanır; bu sınıf o
 * soruyu tek bir yerde cevaplar.
 */
final class Eu {

	/**
	 * AB üye devletleri (ISO 3166-1 alpha-2).
	 *
	 * Birleşik Krallık 2021'den beri üye değildir. Kuzey İrlanda mal
	 * ticaretinde "XI" ön ekiyle AB KDV sistemi içinde kalmaya devam eder.
	 *
	 * @var string[]
	 */
	private const MEMBERS = array(
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'ES',
		'FI',
		'FR',
		'GR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
	);

	/**
	 * Ülkenin AB KDV bölgesinde olup olmadığını bildirir.
	 *
	 * @param string $country ISO 3166-1 alpha-2 ülke kodu.
	 * @return bool
	 */
	public static function is_member( string $country ): bool {
		$country = strtoupper( trim( $country ) );

		if ( 'XI' === $country ) {
			return true;
		}

		return in_array( $country, self::MEMBERS, true );
	}

	/**
	 * Tüm üye devletleri döndürür.
	 *
	 * @return string[]
	 */
	public static function members(): array {
		return self::MEMBERS;
	}

	/**
	 * KDV numarasının biçimsel olarak geçerli görünüp görünmediğini bildirir.
	 *
	 * Bu yalnızca bir biçim kontrolüdür — numaranın gerçekten kayıtlı olduğunu
	 * doğrulamaz. Gerçek doğrulama VIES servisini gerektirir ve Pro sürümün
	 * işidir.
	 *
	 * @param string $vat_number KDV numarası, ülke ön ekiyle birlikte.
	 * @return bool
	 */
	public static function looks_like_vat_number( string $vat_number ): bool {
		$vat_number = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $vat_number ) ?? '' );

		if ( strlen( $vat_number ) < 4 ) {
			return false;
		}

		$prefix = substr( $vat_number, 0, 2 );

		if ( ! self::is_member( $prefix ) ) {
			return false;
		}

		// Yunanistan KDV numaralarında ülke kodu EL, ISO kodu GR'dir.
		return (bool) preg_match( '/^[A-Z]{2}[0-9A-Z]{2,13}$/', $vat_number );
	}

	/**
	 * KDV numarasındaki ülke ön ekini döndürür.
	 *
	 * @param string $vat_number KDV numarası.
	 * @return string İki harfli ön ek; okunamazsa boş dize.
	 */
	public static function vat_prefix( string $vat_number ): string {
		$vat_number = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $vat_number ) ?? '' );

		if ( strlen( $vat_number ) < 2 ) {
			return '';
		}

		$prefix = substr( $vat_number, 0, 2 );

		// EL, Yunanistan'ın KDV ön ekidir; ISO ülke kodu GR olarak normalize edilir.
		return 'EL' === $prefix ? 'GR' : $prefix;
	}
}
