<?php
/**
 * KSeF gönderim kuyruğu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Queue;

use Konform\Ksef\Submission;
use Konform\Storage\Archive;
use Konform\Storage\AuditLog;
use Konform\Storage\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Belgeleri KSeF'e gönderir ve numaranın atanmasını bekler.
 *
 * NEDEN AYRI BİR KUYRUK
 *
 * Belge üretimi ile KSeF gönderimi farklı hızlarda işler. Üretim yereldir ve
 * saniye sürer; gönderim ağa çıkar, karşı taraf faturayı kabul ettikten sonra
 * numarayı dakikalar içinde atar. İkisini tek işe sıkıştırmak, üretimi de
 * ağın hızına bağlardı.
 *
 * BEKLEME DÖNGÜYLE DEĞİL, YENİDEN ZAMANLAMAYLA
 *
 * Numara için `sleep()` ile beklenmiyor. Bir kuyruk işini dakikalarca meşgul
 * etmek, aynı anda başka işlerin sırada kalması demek. Sonuç hazır değilse iş
 * gecikmeli olarak yeniden zamanlanıyor.
 *
 * MÜKERRER GÖNDERİM
 *
 * Kuyruk aynı belgeyi tekrar gönderemez. `Submission` gönderilmiş bir belgeyi
 * yalnızca sorgular; kuyruğun kendisi de aynı belge için bekleyen iş varsa
 * ikincisini eklemez. İki katman da gerekli: biri kuyruğu temiz tutar, öbürü
 * kuyruk atlansa bile faturayı korur.
 */
final class KsefQueue {

	/**
	 * Gönderim kancası.
	 */
	public const HOOK = 'konform_submit_to_ksef';

	/**
	 * Kuyruk grubu.
	 */
	public const GROUP = 'konform';

	/**
	 * Sonuç beklerken iki sorgu arasındaki gecikme (saniye).
	 */
	private const RETRY_DELAY = 120;

	/**
	 * En fazla kaç kez sorulur.
	 *
	 * 20 deneme x 2 dakika = yaklasik 40 dakika. KSeF normalde saniyeler
	 * icinde numara atar; bu sinir, sistemin gecici olarak yavasladigi
	 * durumlar icin genis tutuldu.
	 */
	private const MAX_ATTEMPTS = 20;

	/**
	 * Kuyruk kancasını kaydeder.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( self::HOOK, array( self::class, 'run' ), 10, 2 );
	}

	/**
	 * Action Scheduler kullanılabilir mi.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return \function_exists( 'as_schedule_single_action' ) && \function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Belgeyi gönderim için kuyruğa alır.
	 *
	 * @param int $document_id Belge kimliği.
	 * @param int $attempt     Kaçıncı deneme.
	 * @return void
	 */
	public static function enqueue( int $document_id, int $attempt = 0 ): void {
		if ( $document_id <= 0 ) {
			return;
		}

		$document = Archive::find( $document_id );

		// Tescilli belge kuyruga alinmaz; yapacak is yok.
		if ( null === $document || $document->is_registered() ) {
			return;
		}

		$args = array(
			'document_id' => $document_id,
			'attempt'     => $attempt,
		);

		if ( ! self::is_available() ) {
			// Action Scheduler yoksa senkron denenir; sessizce atlamaktan iyidir.
			self::run( $document_id, $attempt );

			return;
		}

		if ( \as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}

		$delay = $attempt > 0 ? self::RETRY_DELAY : 0;

		\as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );

		if ( 0 === $attempt ) {
			AuditLog::record( AuditLog::EVENT_QUEUED, $document->order_id, $document_id, 'Queued for KSeF submission.' );
		}
	}

	/**
	 * Kuyruktaki işi çalıştırır.
	 *
	 * @param int $document_id Belge kimliği.
	 * @param int $attempt     Kaçıncı deneme.
	 * @return void
	 */
	public static function run( int $document_id, int $attempt = 0 ): void {
		$document = Archive::find( $document_id );

		if ( null === $document || $document->is_registered() ) {
			return;
		}

		$nip = self::seller_nip();

		if ( '' === $nip ) {
			AuditLog::record(
				AuditLog::EVENT_FAILED,
				$document->order_id,
				$document_id,
				'No seller NIP is configured; the invoice cannot be sent to KSeF.'
			);

			return;
		}

		try {
			$number = Submission::create()->submit( $document, self::contents( $document ), $nip );
		} catch ( \RuntimeException $error ) {
			/*
			 * Hata gonderim sirasinda da olabilir, sorgulama sirasinda da.
			 * Ikisi de yeniden denenebilir; KSeF'in acikca REDDETTIGI durum
			 * Submission tarafinda denetim kaydina yazildi ve tekrar denemek
			 * sonucu degistirmez, ama burada onu ayirt edecek bir isaret yok.
			 * Bu yuzden deneme sayisi sinirlaniyor: sonsuz dongu olusmuyor.
			 */
			AuditLog::record(
				AuditLog::EVENT_FAILED,
				$document->order_id,
				$document_id,
				$error->getMessage()
			);

			self::reschedule( $document_id, $attempt );

			return;
		}

		if ( '' === $number ) {
			self::reschedule( $document_id, $attempt );
		}
	}

	/**
	 * İşi yeniden zamanlar.
	 *
	 * @param int $document_id Belge kimliği.
	 * @param int $attempt     Kaçıncı deneme.
	 * @return void
	 */
	private static function reschedule( int $document_id, int $attempt ): void {
		/*
		 * Action Scheduler yokken yeniden zamanlama YAPILMAZ. enqueue() o
		 * durumda isi senkron calistiriyor; buradan tekrar cagirmak, tek bir
		 * istek icinde arka arkaya yirmi ag turu demek olurdu ve sayfa
		 * kilitlenirdi. Tek deneme yapilir, gerisi sonraki tetiklemeye kalir.
		 */
		if ( ! self::is_available() ) {
			return;
		}

		if ( $attempt + 1 >= self::MAX_ATTEMPTS ) {
			/*
			 * Vazgecmek belgeyi kaybetmek degil: referans arsivde duruyor,
			 * denetim kaydinda gonderim olayi var. Sonradan elle sorgulanabilir.
			 */
			$document = Archive::find( $document_id );

			AuditLog::record(
				AuditLog::EVENT_FAILED,
				null === $document ? 0 : $document->order_id,
				$document_id,
				'KSeF did not assign a number within the retry window; the submission reference is stored and can be checked again.'
			);

			return;
		}

		self::enqueue( $document_id, $attempt + 1 );
	}

	/**
	 * Belgenin içeriğini okur.
	 *
	 * @param Document $document Belge.
	 * @return string
	 * @throws \RuntimeException Dosya okunamazsa.
	 */
	private static function contents( Document $document ): string {
		if ( ! $document->is_intact() ) {
			throw new \RuntimeException( 'The archived document is missing or has been modified; it will not be sent.' );
		}

		$contents = file_get_contents( $document->absolute_path() );

		if ( ! is_string( $contents ) ) {
			throw new \RuntimeException( 'The archived document could not be read.' );
		}

		return $contents;
	}

	/**
	 * Satıcının NIP'i.
	 *
	 * @return string
	 */
	private static function seller_nip(): string {
		$vat = (string) \get_option( 'konform_seller_vat_number', '' );

		$digits = preg_replace( '/\D/', '', $vat );

		return is_string( $digits ) ? $digits : '';
	}
}
