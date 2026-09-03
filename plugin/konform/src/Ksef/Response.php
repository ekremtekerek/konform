<?php
/**
 * KSeF HTTP yanıtı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Ksef;

defined( 'ABSPATH' ) || exit;

/**
 * Tek bir HTTP yanıtı.
 */
final class Response {

	/**
	 * Yanıtı kurar.
	 *
	 * @param int    $status HTTP durum kodu.
	 * @param string $body   Yanıt gövdesi.
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body
	) {
	}

	/**
	 * İstek başarılı mı.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Gövdeyi JSON olarak çözer.
	 *
	 * @return array<string,mixed>
	 * @throws \RuntimeException Gövde geçerli JSON değilse.
	 */
	public function json(): array {
		if ( '' === trim( $this->body ) ) {
			return array();
		}

		$decoded = json_decode( $this->body, true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge gider, HTML'e degil.
				sprintf( 'KSeF returned a response that is not valid JSON (HTTP %d).', $this->status )
			);
		}

		return $decoded;
	}
}
