<?php
/**
 * Birim testleri için önyükleme.
 *
 * Testler WordPress kurulumu GEREKTİRMEZ. Denenen şeyler alan mantığı —
 * vergi kategorisi çözümlemesi, toplam aritmetiği, karar kuralları — ve bunlar
 * WordPress'e bağlı değildir. Tam bir WP test paketi kurmak, en kırılgan
 * mantığı test etmenin önüne engel koyardı.
 *
 * Sınıflar `defined( 'ABSPATH' ) || exit;` ile korunduğu için ABSPATH burada
 * tanımlanır; ihtiyaç duyulan avuç dolusu WordPress fonksiyonu da sahtelenir.
 *
 * @package Konform
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Kancaları uygulamaz; ilk değeri döndürür.
	 *
	 * @param string $tag   Kanca adı.
	 * @param mixed  $value Değer.
	 * @param mixed  ...$args Ek argümanlar.
	 * @return mixed
	 */
	function apply_filters( string $tag, $value, ...$args ) {
		unset( $tag, $args );

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Eylem tetiklemez.
	 *
	 * @param string $tag     Kanca adı.
	 * @param mixed  ...$args Argümanlar.
	 * @return void
	 */
	function do_action( string $tag, ...$args ): void {
		unset( $tag, $args );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Çeviri yapmaz; metni olduğu gibi döndürür.
	 *
	 * @param string $text   Metin.
	 * @param string $domain Metin alanı.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Çoğul biçimi seçer.
	 *
	 * @param string $single Tekil.
	 * @param string $plural Çoğul.
	 * @param int    $number Sayı.
	 * @param string $domain Metin alanı.
	 * @return string
	 */
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		unset( $domain );

		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Sayıyı biçimlendirir.
	 *
	 * @param float $number   Sayı.
	 * @param int   $decimals Ondalık basamak.
	 * @return string
	 */
	function number_format_i18n( float $number, int $decimals = 0 ): string {
		return number_format( $number, $decimals );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON'a çevirir.
	 *
	 * @param mixed $data    Veri.
	 * @param int   $options Seçenekler.
	 * @param int   $depth   Derinlik.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

/*
 * Seçenek deposu. Gerçek WordPress yerine bellekte tutulur; ayar okuyan
 * sınıflar böylece veritabanı olmadan sınanabiliyor.
 */
$GLOBALS['konform_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Seçenek okur.
	 *
	 * @param string $name    Ad.
	 * @param mixed  $default Varsayılan.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		return $GLOBALS['konform_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Seçenek yazar.
	 *
	 * @param string $name     Ad.
	 * @param mixed  $value    Değer.
	 * @param mixed  $autoload Otomatik yükleme.
	 * @return bool
	 */
	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );

		$GLOBALS['konform_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Seçenek siler.
	 *
	 * @param string $name Ad.
	 * @return bool
	 */
	function delete_option( string $name ): bool {
		unset( $GLOBALS['konform_test_options'][ $name ] );

		return true;
	}
}
