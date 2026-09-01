<?php
/**
 * Belgenin müşteri e-postasına eklenmesi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Delivery;

use Konform\Storage\Archive;
use Konform\Storage\AuditLog;

defined( 'ABSPATH' ) || exit;

/**
 * Üretilen e-faturayı WooCommerce'in müşteriye gönderdiği e-postaya ekler.
 *
 * Ayrı bir e-posta göndermek yerine var olana eklenir: müşteri zaten bir
 * sipariş e-postası alıyor, ikinci bir mesaj gereksiz gürültüdür ve teslim
 * edilebilirliği düşürür.
 *
 * Yalnızca BÜTÜNLÜĞÜ DOĞRULANMIŞ belge eklenir. Diskte değişmiş veya eksik
 * bir dosyayı müşteriye göndermektense hiç göndermemek doğrudur.
 */
final class EmailDelivery {

	/**
	 * Belgenin ekleneceği e-posta kimlikleri.
	 *
	 * @var string[]
	 */
	private const EMAIL_IDS = array( 'customer_completed_order', 'customer_invoice' );

	/**
	 * Kancaları kaydeder.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_attachments', array( self::class, 'attach' ), 20, 4 );
	}

	/**
	 * E-postaya belgeyi ekler.
	 *
	 * @param string[] $attachments Mevcut ekler.
	 * @param string   $email_id    E-posta kimliği.
	 * @param mixed    $subject      E-postanın konusu; sipariş olması beklenir.
	 * @param mixed    $email       E-posta nesnesi.
	 * @return string[]
	 */
	public static function attach( $attachments, $email_id, $subject, $email = null ): array {
		unset( $email );

		$attachments = is_array( $attachments ) ? $attachments : array();

		/**
		 * Belgenin ekleneceği e-posta kimliklerini değiştirir.
		 *
		 * @param string[] $ids E-posta kimlikleri.
		 */
		$ids = (array) \apply_filters( 'konform/email_attachment_ids', self::EMAIL_IDS );

		if ( ! in_array( (string) $email_id, $ids, true ) ) {
			return $attachments;
		}

		if ( ! $subject instanceof \WC_Order ) {
			return $attachments;
		}

		$document = Archive::latest_for_order( $subject->get_id() );

		if ( null === $document ) {
			return $attachments;
		}

		/*
		 * Butunlugu dogrulanmamis dosya gonderilmez. Diskte degismis bir mali
		 * belgeyi musteriye iletmek, hic iletmemekten kotudur.
		 */
		if ( ! $document->is_intact() ) {
			AuditLog::record(
				AuditLog::EVENT_FAILED,
				$subject->get_id(),
				$document->id,
				'Not attached to email: the archived file is missing or was modified.'
			);

			return $attachments;
		}

		$attachments[] = $document->absolute_path();

		AuditLog::record(
			AuditLog::EVENT_EMAILED,
			$subject->get_id(),
			$document->id,
			(string) $email_id
		);

		return $attachments;
	}
}
