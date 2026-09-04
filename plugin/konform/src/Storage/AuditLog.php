<?php
/**
 * Denetim izi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Ekleme yapılan, hiçbir zaman güncellenmeyen olay günlüğü.
 *
 * Vergi denetiminde sorulan soru "fatura nerede" değil, "bu fatura ne zaman,
 * kim tarafından, hangi hâliyle üretildi ve nereye gönderildi" olur. Arşiv
 * belgeyi tutar; bu tablo o soruların cevabını tutar.
 *
 * Güncelleme veya silme metodu bilerek yoktur.
 */
final class AuditLog {

	/**
	 * Belge üretildi.
	 */
	public const EVENT_GENERATED = 'generated';

	/**
	 * Üretim başarısız oldu.
	 */
	public const EVENT_FAILED = 'failed';

	/**
	 * Belge indirildi.
	 */
	public const EVENT_DOWNLOADED = 'downloaded';

	/**
	 * Üretim kuyruğa alındı.
	 */
	public const EVENT_QUEUED = 'queued';

	/**
	 * Resmi kural setine göre geçersiz bulundu.
	 */
	public const EVENT_INVALID = 'invalid';

	/**
	 * Müşteri e-postasına eklendi.
	 */
	public const EVENT_EMAILED = 'emailed';

	/**
	 * Saklama süresi dolduğu için arşivden kaldırıldı.
	 */
	public const EVENT_PRUNED = 'pruned';

	/**
	 * KSeF'e gönderildi; henüz numara alınmadı.
	 *
	 * Gonderim ile tescil AYRI olaylardir ve aralari dakikalar surebilir.
	 * Ikisini tek olayda birlestirmek, arada kalan faturayi gorunmez yapardi:
	 * gonderilmis ama kabul edilmemis bir belge, hic gonderilmemis gibi
	 * gorunurdu.
	 */
	public const EVENT_KSEF_SENT = 'ksef_sent';

	/**
	 * KSeF numarası alındı; fatura hukuken var oldu.
	 */
	public const EVENT_KSEF_REGISTERED = 'ksef_registered';

	/**
	 * KSeF belgeyi reddetti.
	 */
	public const EVENT_KSEF_REJECTED = 'ksef_rejected';

	/**
	 * Olay kaydeder.
	 *
	 * @param string $event       Olay türü.
	 * @param int    $order_id    Sipariş kimliği.
	 * @param int    $document_id Belge kimliği; yoksa 0.
	 * @param string $detail      Serbest metin ayrıntı.
	 * @return void
	 */
	public static function record( string $event, int $order_id, int $document_id = 0, string $detail = '' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ozel denetim tablosu; WP API karsiligi yok.
		$wpdb->insert(
			Database::audit_table(),
			array(
				'order_id'    => $order_id,
				'document_id' => $document_id,
				'event'       => substr( $event, 0, 40 ),
				'detail'      => $detail,
				'actor'       => \get_current_user_id(),
				'created_at'  => \current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Siparişin olaylarını yeniden eskiye döndürür.
	 *
	 * @param int $order_id Sipariş kimliği.
	 * @param int $limit    En fazla kaç kayıt.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_order( int $order_id, int $limit = 50 ): array {
		global $wpdb;

		$table = Database::audit_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablo adi sabit ve $wpdb->prefix turevi; degerler placeholder ile baglanir.
				"SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT %d",
				$order_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Olay türünün kullanıcıya gösterilecek etiketi.
	 *
	 * @param string $event Olay türü.
	 * @return string
	 */
	public static function label( string $event ): string {
		return match ( $event ) {
			self::EVENT_GENERATED  => __( 'Document generated', 'konform' ),
			self::EVENT_FAILED     => __( 'Generation failed', 'konform' ),
			self::EVENT_DOWNLOADED => __( 'Document downloaded', 'konform' ),
			self::EVENT_QUEUED     => __( 'Queued for generation', 'konform' ),
			self::EVENT_INVALID    => __( 'Rejected by official validation', 'konform' ),
			self::EVENT_EMAILED    => __( 'Attached to customer email', 'konform' ),
			self::EVENT_PRUNED     => __( 'Removed after the retention period', 'konform' ),
			self::EVENT_KSEF_SENT       => __( 'Sent to KSeF', 'konform' ),
			self::EVENT_KSEF_REGISTERED => __( 'Registered by KSeF', 'konform' ),
			self::EVENT_KSEF_REJECTED   => __( 'Rejected by KSeF', 'konform' ),
			default                => $event,
		};
	}
}
