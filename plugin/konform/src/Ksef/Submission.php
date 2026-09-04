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
 * ÜÇ DURUM, ÜÇ DAVRANIŞ
 *
 * Bir belge KSeF açısından üç durumdan birindedir ve her biri farklı bir şey
 * yapmayı gerektirir:
 *
 * 1. **Tescilli** (numarası var) → hiçbir şey yapılmaz.
 * 2. **Gönderilmiş, numarasız** (referansı var) → yalnızca SORGULANIR.
 * 3. **Hiç gönderilmemiş** → gönderilir.
 *
 * İkinci durum bu tasarımın kalbi. KSeF faturayı önce kabul eder, numarayı
 * dakikalar sonra atar. Arada süreç koparsa (zaman aşımı, çökme, kuyruk
 * yeniden başlatma) ve "gönderildi mi" sorusunun cevabı saklanmıyorsa,
 * yeniden deneme faturayı TEKRAR gönderir. KSeF'te aynı faturanın iki kaydı
 * oluşur ve bunun düzeltilmesi zordur.
 *
 * Bu yüzden referans, numara beklenmeden ve gönderimin hemen ardından
 * kalıcılaştırılır. Mükerrer bir fatura, geç tescil edilmiş bir faturadan
 * kötüdür.
 *
 * BEKLEME BURADA YAPILMAZ
 *
 * Numara için döngüde beklenmiyor. Bir web isteğini ya da kuyruk işini
 * dakikalarca meşgul etmek yerine, sonuç hazır değilse boş dönülüyor ve
 * yeniden sorulması `Queue\KsefQueue`'ya bırakılıyor.
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
	 * Belgeyi gönderir ya da durumunu sorgular.
	 *
	 * @param Document $document Arşivdeki belge.
	 * @param string   $xml      FA(3) belgesi.
	 * @param string   $nip      Satıcının NIP'i.
	 * @return string KSeF numarası; henüz atanmadıysa boş dize.
	 * @throws \RuntimeException Gönderim ya da sorgulama başarısızsa.
	 */
	public function submit( Document $document, string $xml, string $nip ): string {
		if ( $document->is_registered() ) {
			return $document->ksef_number;
		}

		/*
		 * Gonderilmis ama numarasi gelmemis belge TEKRAR GONDERILMEZ; yalnizca
		 * sorulur. Mukerrer faturayi onleyen kural budur.
		 */
		if ( $document->is_submitted() ) {
			return $this->resume( $document, $nip );
		}

		$this->authenticate( $nip );

		$key = Encryption::generate_key();
		$iv  = Encryption::generate_iv();

		$session = $this->client->open_session(
			Encryption::wrap_key( $key, $this->client->public_key_certificate( Client::USAGE_SYMMETRIC_KEY ) ),
			$iv
		);

		$reference = $this->client->send_invoice( $session, $xml, $key, $iv );

		/*
		 * Referans BURADA kalicilastiriliyor: numara beklenmeden, gonderimin
		 * hemen ardindan. Asagisi koparsa bile bu belge bir daha gonderilmez,
		 * sorulur.
		 */
		Archive::record_ksef_submission( $document->id, $session, $reference );

		AuditLog::record(
			AuditLog::EVENT_KSEF_SENT,
			$document->order_id,
			$document->id,
			sprintf( 'KSeF reference %s (session %s).', $reference, $session )
		);

		return $this->settle( $document, $session, $reference );
	}

	/**
	 * Durumu okur ve oturumu ne zaman kapatacağına karar verir.
	 *
	 * Oturum ÜÇ durumda kapatılır: numara alındı, KSeF reddetti, ya da bir
	 * hata oluştu. Yalnızca "henüz sonuçlanmadı" durumunda açık bırakılır,
	 * çünkü kapatılmış bir oturumun faturalarının hâlâ sorgulanabildiği
	 * doğrulanmadı ve yeniden deneme o oturumu tekrar soracak.
	 *
	 * @param Document $document  Belge.
	 * @param string   $session   Oturum referansı.
	 * @param string   $reference Fatura referansı.
	 * @return string KSeF numarası; henüz atanmadıysa boş dize.
	 * @throws \Throwable Sorgulama başarısızsa.
	 */
	private function settle( Document $document, string $session, string $reference ): string {
		try {
			$number = $this->read_status( $document, $session, $reference );
		} catch ( \Throwable $error ) {
			$this->close( $session );

			throw $error;
		}

		if ( '' !== $number ) {
			$this->close( $session );
		}

		return $number;
	}

	/**
	 * Gönderilmiş bir belgenin durumunu sorgular.
	 *
	 * @param Document $document Belge.
	 * @param string   $nip      Satıcının NIP'i.
	 * @return string KSeF numarası; henüz atanmadıysa boş dize.
	 * @throws \RuntimeException Sorgulama başarısızsa.
	 */
	public function resume( Document $document, string $nip ): string {
		if ( $document->is_registered() ) {
			return $document->ksef_number;
		}

		if ( ! $document->is_submitted() ) {
			throw new \RuntimeException( 'The document has not been sent to KSeF; there is nothing to resume.' );
		}

		$this->authenticate( $nip );

		return $this->settle( $document, $document->ksef_session, $document->ksef_reference );
	}

	/**
	 * Kimlik doğrular.
	 *
	 * @param string $nip Satıcının NIP'i.
	 * @return void
	 * @throws \RuntimeException Jeton yoksa veya doğrulama başarısızsa.
	 */
	private function authenticate( string $nip ): void {
		if ( ! Settings::has_token() ) {
			throw new \RuntimeException( 'No KSeF token is configured.' );
		}

		$this->client->authenticate(
			Settings::token(),
			$nip,
			$this->client->public_key_certificate( Client::USAGE_TOKEN )
		);
	}

	/**
	 * Faturanın durumunu okur ve sonucu işler.
	 *
	 * @param Document $document  Belge.
	 * @param string   $session   Oturum referansı.
	 * @param string   $reference Fatura referansı.
	 * @return string KSeF numarası; henüz atanmadıysa boş dize.
	 * @throws \RuntimeException KSeF reddederse.
	 */
	private function read_status( Document $document, string $session, string $reference ): string {
		$status = $this->client->invoice_status( $session, $reference );

		$number = trim( (string) ( $status['ksefNumber'] ?? '' ) );

		if ( '' !== $number ) {
			Archive::record_ksef_number( $document->id, $number );

			AuditLog::record(
				AuditLog::EVENT_KSEF_REGISTERED,
				$document->order_id,
				$document->id,
				sprintf( 'KSeF number %s.', $number )
			);

			return $number;
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

			$message = sprintf( 'KSeF rejected the invoice: %s', $description );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
			throw new \RuntimeException( $message );
		}

		/*
		 * Henuz sonuclanmadi. Hata degil; kuyruk tekrar soracak.
		 *
		 * Oturum BILEREK ACIK BIRAKILIYOR. Kapatilmis bir oturumun faturalarinin
		 * hala sorgulanabildigi DOGRULANMADI; varsaymak yerine, isin bittigi
		 * kesinlesene kadar (numara ya da ret) oturum acik tutuluyor. KSeF
		 * oturumlari zaten 12 saat sonra kendiliginden dusuyor ve yeniden
		 * deneme penceresi kirk dakika.
		 */
		return '';
	}

	/**
	 * Oturumu kapatmayı dener.
	 *
	 * Acik kalan oturum KSeF tarafinda 12 saat surunur. Kapatma hatasi asil
	 * sonucu golgelememeli; bu yuzden yutuluyor.
	 *
	 * @param string $session Oturum referansı.
	 * @return void
	 */
	private function close( string $session ): void {
		try {
			$this->client->close_session( $session );
		} catch ( \RuntimeException $ignored ) {
			unset( $ignored );
		}
	}
}
