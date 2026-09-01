<?php
/**
 * Barındırılan doğrulama servisi istemcisi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Belgeyi resmi EN 16931 kural setine göre doğrulatır.
 *
 * Doğrulama neden burada değil de uzakta: EN 16931 kural setleri XSLT 2.0'a
 * derlenir, PHP'nin ext-xsl uzantısı ise XSLT 1.0'da kalır. Kütüphane
 * seviyesindeki kontroller yalnızca yapısaldır (şema ve tamlık), alıcının
 * dayattığı 200'den fazla iş kuralı değil.
 *
 * Bu kısıt ürünün lisans korumasıdır: null'lanmış bir kopya doğrulama
 * yapamaz, yani işe yaramaz. Bkz. docs/adr/0003-dogrulama-calisma-ortami.md
 */
final class HostedValidator {

	/**
	 * Servis adresinin saklandığı seçenek.
	 */
	public const OPTION_ENDPOINT = 'konform_validator_endpoint';

	/**
	 * Lisans anahtarının saklandığı seçenek.
	 */
	public const OPTION_KEY = 'konform_validator_key';

	/**
	 * İstek zaman aşımı, saniye.
	 *
	 * Doğrulama ısınmış serviste 50–150 ms sürüyor; 15 saniye soğuk başlangıç
	 * ve ağ gecikmesi için fazlasıyla yeterli.
	 */
	private const TIMEOUT = 15;

	/**
	 * Doğrulama yapılabilir durumda mı.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->endpoint() && '' !== $this->key();
	}

	/**
	 * Belgeyi doğrular.
	 *
	 * @param string $xml Fatura XML'i.
	 * @return ValidationResult
	 */
	public function validate( string $xml ): ValidationResult {
		if ( ! $this->is_configured() ) {
			return ValidationResult::skipped();
		}

		$response = \wp_remote_post(
			\trailingslashit( $this->endpoint() ) . 'v1/validate',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'authorization' => 'Bearer ' . $this->key(),
					'content-type'  => 'application/json',
				),
				'body'    => (string) \wp_json_encode( array( 'xml' => $xml ) ),
			)
		);

		if ( \is_wp_error( $response ) ) {
			return ValidationResult::unavailable( $response->get_error_message() );
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return ValidationResult::unavailable(
				sprintf( 'Validation service returned HTTP %d.', $status )
			);
		}

		$body = json_decode( (string) \wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['valid'] ) ) {
			return ValidationResult::unavailable( 'Validation service returned an unexpected response.' );
		}

		return new ValidationResult(
			(bool) $body['valid'],
			true,
			isset( $body['errors'] ) && is_array( $body['errors'] ) ? $body['errors'] : array(),
			isset( $body['warnings'] ) && is_array( $body['warnings'] ) ? $body['warnings'] : array(),
			isset( $body['rules_version'] ) ? (string) $body['rules_version'] : '',
			isset( $body['duration_ms'] ) ? (int) $body['duration_ms'] : 0
		);
	}

	/**
	 * Servis adresi.
	 *
	 * @return string
	 */
	private function endpoint(): string {
		/**
		 * Doğrulama servisinin adresini değiştirir.
		 *
		 * @param string $endpoint Adres.
		 */
		return (string) \apply_filters(
			'konform/validator_endpoint',
			(string) \get_option( self::OPTION_ENDPOINT, '' )
		);
	}

	/**
	 * Lisans anahtarı.
	 *
	 * @return string
	 */
	private function key(): string {
		/**
		 * Doğrulama servisinin lisans anahtarını değiştirir.
		 *
		 * @param string $key Anahtar.
		 */
		return (string) \apply_filters(
			'konform/validator_key',
			(string) \get_option( self::OPTION_KEY, '' )
		);
	}
}
