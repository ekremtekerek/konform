<?php
/**
 * Arşiv saklama politikası.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Belgeleri yasal saklama süresi boyunca tutar, sonrasında temizler.
 *
 * Varsayılan on yıldır; AB ülkelerinin çoğunda fatura saklama süresi altı ile
 * on yıl arasındadır ve en uzunu almak güvenli taraftır. Süre dolmadan hiçbir
 * şey silinmez.
 *
 * Silme İSTEĞE BAĞLIDIR ve varsayılan olarak KAPALIDIR. Bir eklentinin mali
 * belgeleri kendiliğinden silmesi kabul edilemez; süre dolsa bile bu kararı
 * satıcı verir.
 */
final class Retention {

	/**
	 * Temizlik işinin kanca adı.
	 */
	public const HOOK = 'konform_prune_archive';

	/**
	 * Varsayılan saklama süresi, yıl.
	 */
	public const DEFAULT_YEARS = 10;

	/**
	 * Otomatik silmenin açık olup olmadığını tutan seçenek.
	 */
	public const OPTION_ENABLED = 'konform_retention_prune_enabled';

	/**
	 * Kancaları kaydeder.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'prune' ) );
		add_action( 'init', array( self::class, 'schedule' ), 20 );
	}

	/**
	 * Günlük temizlik işini planlar.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! \function_exists( 'as_has_scheduled_action' ) || ! \function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( \as_has_scheduled_action( self::HOOK, array(), 'konform' ) ) {
			return;
		}

		\as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), 'konform' );
	}

	/**
	 * Saklama süresi, yıl.
	 *
	 * @return int
	 */
	public static function years(): int {
		/**
		 * Saklama süresini değiştirir.
		 *
		 * Ülkeye göre altı ile on yıl arasında değişir.
		 *
		 * @param int $years Yıl.
		 */
		return max( 1, (int) \apply_filters( 'konform/retention_years', self::DEFAULT_YEARS ) );
	}

	/**
	 * Otomatik silme açık mı.
	 *
	 * @return bool
	 */
	public static function is_pruning_enabled(): bool {
		return (bool) \get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * Süresi dolmuş belgeleri siler.
	 *
	 * @return int Silinen belge sayısı.
	 */
	public static function prune(): int {
		if ( ! self::is_pruning_enabled() ) {
			return 0;
		}

		global $wpdb;

		$table  = Database::documents_table();
		$cutoff = \gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::years() . ' years' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablo adi sabit ve $wpdb->prefix turevi.
				"SELECT * FROM {$table} WHERE created_at < %s LIMIT 200",
				$cutoff
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			return 0;
		}

		$deleted = 0;

		foreach ( $rows as $row ) {
			$document = Document::from_row( $row );

			if ( $document->exists() ) {
				\wp_delete_file( $document->absolute_path() );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => $document->id ), array( '%d' ) );

			/*
			 * Denetim izi SILINMEZ. Belgenin bir zamanlar var oldugu ve ne
			 * zaman kaldirildigi kaydi kalmalidir.
			 */
			AuditLog::record(
				AuditLog::EVENT_PRUNED,
				$document->order_id,
				$document->id,
				sprintf( 'Retention period of %d years elapsed.', self::years() )
			);

			++$deleted;
		}

		return $deleted;
	}
}
