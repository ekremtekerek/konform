<?php
/**
 * KSeF HTTP taşıyıcısı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Ksef;

defined( 'ABSPATH' ) || exit;

/**
 * KSeF'e yapılan HTTP çağrılarını soyutlar.
 *
 * Ayrı bir arayüz olmasının sebebi test edilebilirlik: akışın kendisi
 * (yetkilendirme, oturum, gönderim, durum) WordPress'e bağlı olmadan
 * sınanabilmeli. Gerçek çağrılar WpTransport üzerinden gider.
 */
interface Transport {

	/**
	 * Bir HTTP isteği yapar.
	 *
	 * @param string               $method  HTTP yöntemi.
	 * @param string               $url     Tam URL.
	 * @param array<string,string> $headers Başlıklar.
	 * @param string|null          $body    Gövde; yoksa null.
	 * @param int                  $timeout Saniye cinsinden zaman aşımı.
	 * @return Response
	 * @throws \RuntimeException Ağ katmanı isteği tamamlayamazsa.
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): Response;
}
