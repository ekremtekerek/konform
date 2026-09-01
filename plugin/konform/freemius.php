<?php
/**
 * Freemius genel erişim noktası.
 *
 * Freemius, SDK'ya `konform_fs()` gibi GENEL ad alanındaki bir fonksiyonla
 * erişilmesini bekler. Eklentinin geri kalanı `Konform` ad alanında olduğu
 * için bu köprü ayrı bir dosyada duruyor.
 *
 * Kimlik bilgileri girilene kadar fonksiyon null döner ve eklenti ücretsiz
 * planda çalışmaya devam eder.
 *
 * @package Konform
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'konform_fs' ) ) {
	/**
	 * Freemius SDK örneğini döndürür.
	 *
	 * @return object|null Yapılandırılmamışsa null.
	 */
	function konform_fs(): ?object {
		return \Konform\License\Freemius::instance();
	}
}
