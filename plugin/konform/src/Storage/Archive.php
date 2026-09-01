<?php
/**
 * Belge arşivi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Üretilen belgeleri yasal saklama süresi boyunca saklar.
 *
 * İki kural bu sınıfın tamamını belirler:
 *
 *   1. HİÇBİR BELGE ÜZERİNE YAZILMAZ. Yeniden üretim yeni bir sürüm satırı
 *      açar. Kesilmiş bir faturayı sessizce değiştirmek yasal olarak yanlıştır;
 *      denetimde neyin ne zaman gönderildiği gösterilebilmelidir.
 *
 *   2. DOSYALAR DOĞRUDAN URL İLE SUNULMAZ. Bunlar mali belgelerdir; tahmin
 *      edilebilir bir adresten indirilebilir olmaları kabul edilemez. Dizin adı
 *      rastgele bir anahtar taşır, içeriye erişim engellenir ve indirme
 *      yetkilendirilmiş bir uç noktadan geçer.
 */
final class Archive {

	/**
	 * Dizin adındaki rastgele anahtarın saklandığı seçenek.
	 */
	private const KEY_OPTION = 'konform_archive_key';

	/**
	 * Arşiv kök dizininin tam yolu.
	 *
	 * @return string
	 */
	public static function root(): string {
		$uploads = \wp_upload_dir();

		return \trailingslashit( $uploads['basedir'] ) . 'konform-' . self::key();
	}

	/**
	 * Dizin adında kullanılan rastgele anahtar.
	 *
	 * @return string
	 */
	public static function key(): string {
		$key = (string) \get_option( self::KEY_OPTION, '' );

		if ( '' === $key ) {
			$key = \wp_generate_password( 20, false, false );
			\update_option( self::KEY_OPTION, $key, false );
		}

		return $key;
	}

	/**
	 * Arşiv dizinini oluşturur ve dışarıya kapatır.
	 *
	 * Apache'de .htaccess yeterlidir; nginx'te değildir. Asıl koruma dizin
	 * adındaki rastgele anahtar ile indirmenin yetki kontrolünden geçmesidir.
	 *
	 * @return bool Dizin kullanılabilir durumdaysa true.
	 */
	public static function prepare(): bool {
		$root = self::root();

		if ( ! \wp_mkdir_p( $root ) ) {
			return false;
		}

		if ( ! file_exists( $root . '/.htaccess' ) ) {
			self::write( $root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		if ( ! file_exists( $root . '/index.php' ) ) {
			self::write( $root . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		return is_dir( $root ) && \wp_is_writable( $root );
	}

	/**
	 * Belgeyi arşive yazar.
	 *
	 * @param int    $order_id       Sipariş kimliği.
	 * @param string $invoice_number Fatura numarası.
	 * @param string $profile        Profil değeri.
	 * @param string $format         Dosya biçimi (xml, pdf).
	 * @param string $locale         Belge dili.
	 * @param string $content        Dosya içeriği.
	 * @return Document|null Yazılamazsa null.
	 */
	public static function store(
		int $order_id,
		string $invoice_number,
		string $profile,
		string $format,
		string $locale,
		string $content
	): ?Document {
		if ( ! self::prepare() ) {
			return null;
		}

		$version   = self::next_version( $order_id );
		$hash      = hash( 'sha256', $content );
		$directory = \gmdate( 'Y/m' );
		$folder    = self::root() . '/' . $directory;

		if ( ! \wp_mkdir_p( $folder ) ) {
			return null;
		}

		/*
		 * sanitize_file_name() burada YANLIS aractir: ciplak bir uzantiya
		 * uygulaninca WordPress onu adsiz bir dosya sanip
		 * "unnamed-file.pdf" uretiyor. Uzantiyi izin listesiyle dogruluyoruz.
		 */
		$filename = sprintf(
			'%d-v%d-%s.%s',
			$order_id,
			$version,
			substr( $hash, 0, 12 ),
			self::extension( $format )
		);

		$absolute = $folder . '/' . $filename;

		if ( ! self::write( $absolute, $content ) ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ozel arsiv tablosu; WP API karsiligi yok.
		$inserted = $wpdb->insert(
			Database::documents_table(),
			array(
				'order_id'        => $order_id,
				'invoice_number'  => $invoice_number,
				'profile'         => $profile,
				'document_format' => $format,
				'document_locale' => $locale,
				'relative_path'   => $directory . '/' . $filename,
				'file_hash'       => $hash,
				'byte_size'       => strlen( $content ),
				'version'         => $version,
				'created_at'      => \current_time( 'mysql', true ),
				'created_by'      => \get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d' )
		);

		if ( ! $inserted ) {
			// Kayit acilamadiysa yetim dosya birakma.
			\wp_delete_file( $absolute );

			return null;
		}

		return self::find( (int) $wpdb->insert_id );
	}

	/**
	 * Kimliğe göre belge getirir.
	 *
	 * @param int $id Belge kimliği.
	 * @return Document|null
	 */
	public static function find( int $id ): ?Document {
		global $wpdb;

		$table = Database::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablo adi sabit ve $wpdb->prefix turevi; deger placeholder ile baglanir.
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? Document::from_row( $row ) : null;
	}

	/**
	 * Siparişe ait belgeleri yeniden eskiye döndürür.
	 *
	 * @param int $order_id Sipariş kimliği.
	 * @return Document[]
	 */
	public static function for_order( int $order_id ): array {
		global $wpdb;

		$table = Database::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablo adi sabit ve $wpdb->prefix turevi; deger placeholder ile baglanir.
				"SELECT * FROM {$table} WHERE order_id = %d ORDER BY version DESC, id DESC",
				$order_id
			),
			ARRAY_A
		);

		return array_map( array( Document::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Siparişin en son belgesi.
	 *
	 * @param int $order_id Sipariş kimliği.
	 * @return Document|null
	 */
	public static function latest_for_order( int $order_id ): ?Document {
		$documents = self::for_order( $order_id );

		return array() === $documents ? null : $documents[0];
	}

	/**
	 * Siparişin bir sonraki belge sürümü.
	 *
	 * @param int $order_id Sipariş kimliği.
	 * @return int
	 */
	private static function next_version( int $order_id ): int {
		global $wpdb;

		$table = Database::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablo adi sabit ve $wpdb->prefix turevi; deger placeholder ile baglanir.
				"SELECT MAX(version) FROM {$table} WHERE order_id = %d",
				$order_id
			)
		);

		return null === $max ? 1 : (int) $max + 1;
	}

	/**
	 * Dosya uzantısını doğrular.
	 *
	 * İzin listesi kullanılır: uzantı dosya adına giriyor ve kullanıcı
	 * girdisinden türeyebilecek her değer bir yol saldırısı yüzeyidir.
	 *
	 * @param string $format İstenen biçim.
	 * @return string
	 */
	private static function extension( string $format ): string {
		$format = strtolower( trim( $format ) );

		return in_array( $format, array( 'xml', 'pdf' ), true ) ? $format : 'xml';
	}

	/**
	 * Dosyayı diske yazar.
	 *
	 * WP_Filesystem kullanılmaz: kimlik bilgisi diyaloğu gerektirebilir ve
	 * bu kod Action Scheduler kuyruğunda, hiçbir kullanıcı oturumu olmadan
	 * çalışır. Hedef her zaman uploads dizinidir.
	 *
	 * @param string $path    Tam yol.
	 * @param string $content İçerik.
	 * @return bool
	 */
	private static function write( string $path, string $content ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Bkz. yukaridaki gerekce.
		$bytes = file_put_contents( $path, $content, LOCK_EX );

		return false !== $bytes;
	}
}
