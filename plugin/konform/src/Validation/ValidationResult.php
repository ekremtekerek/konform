<?php
/**
 * Doğrulama sonucu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Resmi kural setine göre doğrulama sonucu.
 *
 * Üç durumu ayırt eder ve bu ayrım önemlidir:
 *
 *   - geçerli            → belge gönderilebilir
 *   - geçersiz           → belge reddedilir, sebepler elimizde
 *   - ulaşılamadı        → servis cevap vermedi; belge hakkında BİLGİMİZ YOK
 *
 * Üçüncüsünü ikincisiyle karıştırmak, geçici bir ağ arızasında faturaların
 * kesilmemesine yol açardı.
 */
final class ValidationResult {

	/**
	 * Kurucu.
	 *
	 * @param bool                             $valid         Belge geçerli mi.
	 * @param bool                             $available     Servise ulaşılabildi mi.
	 * @param array<int, array<string,string>> $errors       Ölümcül bulgular.
	 * @param array<int, array<string,string>> $warnings     Uyarılar.
	 * @param string                           $rules_version Kural seti sürümü.
	 * @param int                              $duration_ms   Servis tarafı süre.
	 * @param string                           $message       Ulaşılamadıysa sebep.
	 */
	public function __construct(
		public readonly bool $valid,
		public readonly bool $available,
		public readonly array $errors = array(),
		public readonly array $warnings = array(),
		public readonly string $rules_version = '',
		public readonly int $duration_ms = 0,
		public readonly string $message = '',
	) {}

	/**
	 * Servise ulaşılamadığını bildiren sonuç.
	 *
	 * @param string $message Sebep.
	 * @return self
	 */
	public static function unavailable( string $message ): self {
		return new self( false, false, array(), array(), '', 0, $message );
	}

	/**
	 * Doğrulama yapılmadığını bildiren sonuç.
	 *
	 * Ücretsiz sürümde kullanılır; eksiklik değil, kapsam dışıdır.
	 *
	 * @return self
	 */
	public static function skipped(): self {
		return new self( false, false, array(), array(), '', 0, 'Validation is not enabled.' );
	}

	/**
	 * Belgenin gönderilmesi engellenmeli mi.
	 *
	 * Yalnızca KESİN olarak geçersiz bulunduğunda engellenir. Servise
	 * ulaşılamaması bir engel değildir — geçici bir ağ arızası yüzünden
	 * faturaların kesilmemesi kabul edilemez.
	 *
	 * @return bool
	 */
	public function blocks(): bool {
		return $this->available && ! $this->valid;
	}

	/**
	 * Bulguların kısa özeti.
	 *
	 * @param int $limit En fazla kaç bulgu.
	 * @return string
	 */
	public function summary( int $limit = 5 ): string {
		if ( ! $this->available ) {
			return $this->message;
		}

		if ( $this->valid ) {
			return sprintf( 'Valid against EN 16931 %s.', $this->rules_version );
		}

		$parts = array();

		foreach ( array_slice( $this->errors, 0, $limit ) as $error ) {
			$rule = isset( $error['rule'] ) ? (string) $error['rule'] : '';
			$text = isset( $error['message'] ) ? (string) $error['message'] : '';

			$parts[] = '' !== $rule ? $rule . ': ' . $text : $text;
		}

		return implode( ' | ', $parts );
	}
}
