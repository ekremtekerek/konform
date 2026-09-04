<?php
/**
 * KSeF gönderimi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Ksef;

use Konform\Storage\Archive;
use Konform\Storage\AuditLog;
use Konform\Storage\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Bir belgeyi KSeF'e gönderir ve sonucu arşive işler.
 *
 * Bu sınıf, `Client`'ın ham API akışını eklentinin dünyasına bağlar: hangi
 * belge gönderildi, numarası ne, denetim kaydına ne yazıldı.
 *
 * TASARIMDA BELİRLEYİCİ OLAN
 *
 * Gönderim ile tescil AYRI olaylardır. KSeF faturayı önce kabul eder (referans
 * numarası verir), numarayı sonra atar; arada dakikalar olabilir. Bu yüzden
 * gönderim kaydı, numara beklenmeden ve hemen yazılır.
 *
 * Sebebi şudur: süreç arada koparsa (zaman aşımı, çökme, sunucu yeniden
 * başlatma) faturanın gönderildiği bilinmelidir. Aksi hâlde kuyruk onu
 * "hiç gönderilmemiş" sanıp tekrar gönderir ve KSeF'te MÜKERRER fatura oluşur.
 * Mükerrer bir fatura, gönderilmemiş bir faturadan daha kötüdür.
 */
final class Submission {

	/**
	 * Servisi kurar.
	 *
	 * @param Client $client KSeF istemcisi.
	 */
	public function __construct( private readonly Client $client ) {
	}

	/**
	 * Ayarlardan bir servis kurar.
	 *
	 * @return self
	 */
	public static function create(): self {
		return new self( new Client( new WpTransport(), Settings::base_url() ) );
	}

	/**
	 * Belgeyi gönderir ve KSeF numarasını arşive işler.
	 *
	 * @param Document $document Arşivdeki belge.
	 * @param string   $xml      FA(3) belgesi.
	 * @param string   $nip      Satıcının NIP'i.
	 * @return string KSeF numarası.
	 * @throws \RuntimeException Gönderim başarısızsa.
	 */
	public function submit( Document $document, string $xml, string $nip ): string {
		/*
		 * Zaten numarasi olan belge TEKRAR GONDERILMEZ. KSeF'te ayni faturanin
		 * iki kaydi olmasi, duzeltilmesi zor bir hatadir.
		 */
		if ( $document->is_registered() ) {
			return $document->ksef_number;
		}

		if ( ! Settings::has_token() ) {
			throw new \RuntimeException( 'No KSeF token is configured.' );
		}

		$this->client->authenticate(
			Settings::token(),
			$nip,
			$this->client->public_key_certificate( Client::USAGE_TOKEN )
		);

		$key = Encryption::generate_key();
		$iv  = Encryption::generate_iv();

		$session = $this->client->open_session(
			Encryption::wrap_key( $key, $this->client->public_key_certificate( Client::USAGE_SYMMETRIC_KEY ) ),
			$iv
		);

		try {
			$reference = $this->client->send_invoice( $session, $xml, $key, $iv );

			/*
			 * Kayit BURADA yaziliyor, numara beklenmeden. Asagisi koparsa bile
			 * faturanin gonderildigi biliniyor olmali.
			 */
			AuditLog::record(
				AuditLog::EVENT_KSEF_SENT,
				$document->order_id,
				$document->id,
				sprintf( 'KSeF reference %s (session %s).', $reference, $session )
			);

			$number = $this->await_number( $session, $reference, $document );

			Archive::record_ksef_number( $document->id, $number );

			AuditLog::record(
				AuditLog::EVENT_KSEF_REGISTERED,
				$document->order_id,
				$document->id,
				sprintf( 'KSeF number %s.', $number )
			);

			return $number;
		} finally {
			/*
			 * Oturum her durumda kapatilmaya calisilir; acik kalan oturum
			 * KSeF tarafinda 12 saat surunur. Kapatma hatasi asil sonucu
			 * golgelememeli, o yuzden yutuluyor.
			 */
			try {
				$this->client->close_session( $session );
			} catch ( \RuntimeException $ignored ) {
				unset( $ignored );
			}
		}
	}

	/**
	 * KSeF numarasının atanmasını bekler.
	 *
	 * @param string   $session   Oturum referansı.
	 * @param string   $reference Fatura referansı.
	 * @param Document $document  Belge.
	 * @return string
	 * @throws \RuntimeException Reddedilirse veya zaman aşımına uğrarsa.
	 */
	private function await_number( string $session, string $reference, Document $document ): string {
		$limit = (int) \apply_filters( 'konform/ksef_status_attempts', 20 );
		$pause = (int) \apply_filters( 'konform/ksef_status_pause', 3 );

		for ( $attempt = 0; $attempt < $limit; $attempt++ ) {
			$status = $this->client->invoice_status( $session, $reference );

			if ( isset( $status['ksefNumber'] ) && '' !== (string) $status['ksefNumber'] ) {
				return (string) $status['ksefNumber'];
			}

			$code = (int) ( $status['status']['code'] ?? 0 );

			if ( $code >= 400 ) {
				$description = (string) ( $status['status']['description'] ?? 'unknown error' );

				AuditLog::record(
					AuditLog::EVENT_KSEF_REJECTED,
					$document->order_id,
					$document->id,
					sprintf( 'KSeF rejected the invoice: %s', $description )
				);

				throw new \RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
					sprintf( 'KSeF rejected the invoice: %s', $description )
				);
			}

			sleep( $pause );
		}

		/*
		 * Numara gelmedi ama fatura GONDERILDI. Denetim kaydinda gonderim
		 * olayi duruyor; kuyruk buradan devam edebilir. Bu yuzden burada
		 * "basarisiz" degil, "henuz sonuclanmadi" deniyor.
		 */
		throw new \RuntimeException(
			'KSeF has not assigned a number yet; the invoice was sent and the reference is recorded.'
		);
	}
}
