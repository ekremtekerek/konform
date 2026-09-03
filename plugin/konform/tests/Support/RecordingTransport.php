<?php
/**
 * Testler için sahte KSeF taşıyıcısı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Support;

use Konform\Ksef\Response;
use Konform\Ksef\Transport;

/**
 * İstekleri kaydeder, hazır yanıtları sırayla döndürür.
 *
 * KSeF akışının HTTP'siz sınanmasını sağlar: hangi uca gidildiği, gövdede ne
 * olduğu ve hangi başlıkların eklendiği buradan okunur.
 */
final class RecordingTransport implements Transport {

	/**
	 * Yapılan istekler.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $requests = array();

	/**
	 * Sırayla döndürülecek yanıtlar.
	 *
	 * @var array<int,Response>
	 */
	private array $responses;

	/**
	 * Taşıyıcıyı kurar.
	 *
	 * @param array<int,Response> $responses Yanıtlar.
	 */
	public function __construct( array $responses ) {
		$this->responses = $responses;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method  HTTP yöntemi.
	 * @param string               $url     Tam URL.
	 * @param array<string,string> $headers Başlıklar.
	 * @param string|null          $body    Gövde.
	 * @param int                  $timeout Zaman aşımı.
	 * @return Response
	 * @throws \RuntimeException Beklenenden fazla istek yapılırsa.
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): Response {
		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => null === $body ? null : json_decode( $body, true ),
			'timeout' => $timeout,
		);

		$next = array_shift( $this->responses );

		if ( ! $next instanceof Response ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test mesaji; ciktiya gitmiyor.
				sprintf( 'Beklenmeyen istek: %s %s', $method, $url )
			);
		}

		return $next;
	}
}
