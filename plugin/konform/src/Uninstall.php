<?php
/**
 * Eklenti kaldırıldığında yapılan temizlik.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform;

defined( 'ABSPATH' ) || exit;

/**
 * "Sil" denildiğinde çalışır — devre dışı bırakmada değil.
 *
 * Neden kökte uninstall.php yok: Freemius kaldırma olayını kendi yönetiyor ve
 * eklenti kökünde bir uninstall.php dosyası bulunmasını kabul etmiyor
 * ("Please move its logic to the after_uninstall hook"). Kanca kaydı
 * Konform\License\Freemius::instance() içindedir.
 *
 * Fatura arşivi yasal saklama süresine tabidir ve ASLA otomatik silinmez;
 * kullanıcı bunu ayarlardan açıkça onaylamak zorundadır.
 */
final class Uninstall {

	/**
	 * Silinecek seçenekler.
	 *
	 * Arşiv tabloları ve dosyaları bu listede DEĞİLDİR.
	 *
	 * konform_archive_key de KASITLI OLARAK yok. Arşiv dosyaları silinmediğine
	 * göre, bütünlüklerini doğrulayan anahtarı silmek geride doğrulanamayan
	 * belgeler bırakırdı.
	 *
	 * @var string[]
	 */
	private const OPTIONS = array(
		'konform_delete_data_on_uninstall',
		'konform_db_version',
		'konform_seller_vat_number',
		'konform_retention_prune_enabled',
		'konform_validator_endpoint',
		'konform_validator_key',
	);

	/**
	 * Silinecek site geçici verileri.
	 *
	 * @var string[]
	 */
	private const TRANSIENTS = array(
		'konform_preflight_report',
		'konform_plugin_translations',
		'konform_locale_install_failed',
	);

	/**
	 * Temizliği yapar.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		// Kullanıcı acikca onaylamadiysa hicbir seye dokunulmaz.
		if ( ! \get_option( 'konform_delete_data_on_uninstall', false ) ) {
			return;
		}

		self::delete_options();

		// Coklu site: her sitenin kendi ayarlari temizlenir.
		if ( ! \is_multisite() ) {
			return;
		}

		foreach ( \get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
			\switch_to_blog( (int) $site_id );

			self::delete_options();

			\restore_current_blog();
		}
	}

	/**
	 * Etkin sitedeki seçenekleri siler.
	 *
	 * @return void
	 */
	private static function delete_options(): void {
		foreach ( self::OPTIONS as $option ) {
			\delete_option( $option );
		}

		foreach ( self::TRANSIENTS as $transient ) {
			\delete_site_transient( $transient );
		}
	}
}
