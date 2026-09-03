<?php
/**
 * WordPress HTTP API üzerinden KSeF taşıyıcısı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Ksef;

defined( 'ABSPATH' ) || exit;

/**
 * KSeF çağrılarını `wp_remote_request()` ile yapar.
 *
 * Guzzle ya da bir PSR yığını taşınmıyor: birkaç uç için o ağırlık gereksiz ve
 * WordPress'in kendi HTTP katmanı vekil sunucu, sertifika ve zaman aşımı
 * ayarlarına zaten saygı gösteriyor. Aynı desen `HostedValidator`'da da var.
 */
final class WpTransport implements Transport {

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method  HTTP yöntemi.
	 * @param string               $url     Tam URL.
	 * @param array<string,string> $headers Başlıklar.
	 * @param string|null          $body    Gövde.
	 * @param int                  $timeout Saniye cinsinden zaman aşımı.
	 * @return Response
	 * @throws \RuntimeException Ağ katmanı isteği tamamlayamazsa.
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): Response {
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = \wp_remote_request( $url, $args );

		if ( \is_wp_error( $response ) ) {
			/*
			 * Ag hatasi ile KSeF'in reddi ayri seylerdir. Burada istek hic
			 * ulasmadi; cagiran taraf bunu yeniden deneyebilir.
			 */
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
				sprintf( 'The KSeF request could not be sent: %s', $response->get_error_message() )
			);
		}

		return new Response(
			(int) \wp_remote_retrieve_response_code( $response ),
			(string) \wp_remote_retrieve_body( $response )
		);
	}
}
