<?php
/**
 * Eklenti kaldırıldığında çalışır.
 *
 * Yalnızca "sil" denildiğinde tetiklenir — devre dışı bırakmada değil.
 * Fatura arşivi yasal saklama süresine tabi olduğu için ASLA otomatik
 * silinmez; kullanıcı bunu ayarlardan açıkça onaylamak zorundadır.
 *
 * @package Konform
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Arşiv verisi korunur. Kullanıcı ayarlardan "kaldırırken tüm verileri sil"
 * seçeneğini işaretlemediyse hiçbir şeye dokunulmaz.
 */
if ( ! get_option( 'konform_delete_data_on_uninstall', false ) ) {
	return;
}

$konform_options = array(
	'konform_delete_data_on_uninstall',
	'konform_settings',
	'konform_db_version',
);

foreach ( $konform_options as $konform_option ) {
	delete_option( $konform_option );
}

// Çoklu site: her sitenin kendi ayarları temizlenir.
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $konform_site_id ) {
		switch_to_blog( (int) $konform_site_id );

		foreach ( $konform_options as $konform_option ) {
			delete_option( $konform_option );
		}

		restore_current_blog();
	}
}
