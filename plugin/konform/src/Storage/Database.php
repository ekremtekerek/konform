<?php
/**
 * Veritabanı şeması.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Arşiv ve denetim izi tablolarını kurar.
 *
 * Belgeler sipariş meta'sında tutulmaz: yasal saklama süresi boyunca
 * sorgulanabilir, sıralanabilir ve raporlanabilir olmaları gerekir; meta
 * tablosu bunun için yanlış araçtır.
 */
final class Database {

	/**
	 * Şema sürümü. Değiştiğinde tablolar güncellenir.
	 */
	public const VERSION = '1';

	/**
	 * Şema sürümünün saklandığı seçenek.
	 */
	private const OPTION = 'konform_db_version';

	/**
	 * Arşivlenmiş belge tablosunun adı.
	 *
	 * @return string
	 */
	public static function documents_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'konform_documents';
	}

	/**
	 * Denetim izi tablosunun adı.
	 *
	 * @return string
	 */
	public static function audit_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'konform_audit';
	}

	/**
	 * Şema güncel değilse kurar.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( self::VERSION === (string) \get_option( self::OPTION, '' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Tabloları oluşturur veya günceller.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$docs    = self::documents_table();
		$audit   = self::audit_table();

		/*
		 * relative_path yolun tamamini degil uploads altindaki goreli kismini
		 * tutar; site tasindiginda veya uploads dizini degistiginde arsiv
		 * kirilmasin diye.
		 */
		\dbDelta(
			"CREATE TABLE {$docs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				invoice_number varchar(100) NOT NULL DEFAULT '',
				profile varchar(32) NOT NULL DEFAULT '',
				document_format varchar(16) NOT NULL DEFAULT '',
				document_locale varchar(20) NOT NULL DEFAULT '',
				relative_path varchar(255) NOT NULL DEFAULT '',
				file_hash char(64) NOT NULL DEFAULT '',
				byte_size bigint(20) unsigned NOT NULL DEFAULT 0,
				version smallint(5) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY invoice_number (invoice_number),
				KEY created_at (created_at)
			) {$charset};"
		);

		\dbDelta(
			"CREATE TABLE {$audit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				document_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(40) NOT NULL DEFAULT '',
				detail text NULL,
				actor bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY event (event),
				KEY created_at (created_at)
			) {$charset};"
		);

		\update_option( self::OPTION, self::VERSION );
	}
}
