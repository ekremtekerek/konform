<?php
/**
 * Barındırılan doğrulama servisi istemcisi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Validation;

use Konform\License\Licensing;
use Konform\Queue\Scheduler;

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
	 * Etkileşimli istekte zaman aşımı, saniye.
	 *
	 * Bir yönetici ekran başında bekliyor. Doğrulamanın kendisi ısınmış
	 * serviste 100–300 ms sürer; 15 saniye ağ gecikmesi için fazlasıyla
	 * yeterlidir ve ekranı kilitlemez.
	 */
	private const TIMEOUT_INTERACTIVE = 15;

	/**
	 * Arka plan işinde zaman aşımı, saniye.
	 *
	 * Uykuya dalan barındırmalarda (Render'ın ücretsiz katmanı gibi) ilk
	 * isteğin uyanması 50 saniyeyi bulabiliyor. Kuyrukta kimse ekran başında
	 * beklemediği için burada beklemek serbesttir; 15 saniyede kesmek,
	 * uyanmakta olan bir servisi erişilemez saymak olurdu.
	 */
	private const TIMEOUT_BACKGROUND = 90;

	/**
	 * Bir kez daha denenecek HTTP durumları.
	 *
	 * Bunlar servisin ayakta olmadığını değil, o an cevap veremediğini
	 * gösterir. 404 listede çünkü uykuya dalan barındırmalarda geçiş
	 * anında kenar sunucu bunu döndürüyor.
	 *
	 * @var int[]
	 */
	private const RETRYABLE = array( 404, 500, 502, 503, 504 );

	/**
	 * Yeniden denemeden önce beklenecek süre, saniye.
	 */
	private const RETRY_PAUSE = 3;

	/**
	 * Doğrulama yapılabilir durumda mı.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		if ( ! Licensing::has_hosted_validation() ) {
			return false;
		}

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

		$response = $this->request( $xml );

		if ( \is_wp_error( $response ) ) {
			return ValidationResult::unavailable( $response->get_error_message() );
		}

		$status = (int) \wp_remote_retrieve_response_code( $response );

		/*
		 * Uykuya dalan barindirmalar (Render'in ucretsiz katmani) makine
		 * durdurulurken yolu kisa bir sure kaydinden dusuruyor ve kenar
		 * sunucu istegi bekletmek yerine 404 donduruyor. Olculdu: birkac
		 * dakika 404, sonra kendiliginden 200.
		 *
		 * Bu yuzden kesin bir HTTP durumuyla donen gecici hatalarda bir kez
		 * daha deneriz. Zaman asiminda denemeyiz: orada butcenin tamami
		 * zaten harcanmistir, ikinci deneme yalnizca bekleyeni iki kat
		 * bekletir.
		 */
		if ( in_array( $status, self::RETRYABLE, true ) ) {
			sleep( self::RETRY_PAUSE );

			$response = $this->request( $xml );

			if ( \is_wp_error( $response ) ) {
				return ValidationResult::unavailable( $response->get_error_message() );
			}

			$status = (int) \wp_remote_retrieve_response_code( $response );
		}

		if ( 200 !== $status ) {
			return ValidationResult::unavailable(
				404 === $status
					// 404 hem yanlis adres hem gecici kesinti demek olabilir.
					// Iki ihtimali de soyleyelim; teshis suresini kisaltir.
					? 'Validation service returned HTTP 404. Check the service address, or the service may be starting up.'
					: sprintf( 'Validation service returned HTTP %d.', $status )
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
	 * Doğrulama isteğini gönderir.
	 *
	 * @param string $xml Fatura XML'i.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $xml ) {
		return \wp_remote_post(
			\trailingslashit( $this->endpoint() ) . 'v1/validate',
			array(
				'timeout' => $this->timeout(),
				'headers' => array(
					'authorization' => 'Bearer ' . $this->key(),
					'content-type'  => 'application/json',
				),
				'body'    => (string) \wp_json_encode( array( 'xml' => $xml ) ),
			)
		);
	}

	/**
	 * İsteğe tanınacak süre.
	 *
	 * @return int Saniye.
	 */
	private function timeout(): int {
		$timeout = Scheduler::is_running_in_background()
			? self::TIMEOUT_BACKGROUND
			: self::TIMEOUT_INTERACTIVE;

		/**
		 * Doğrulama isteğine tanınan süreyi değiştirir.
		 *
		 * Uykuya dalan bir barındırma kullanıyorsanız ve arka plan süresi
		 * yetmiyorsa buradan uzatabilirsiniz.
		 *
		 * @param int  $timeout       Saniye.
		 * @param bool $in_background Arka plan işinde miyiz.
		 */
		return (int) \apply_filters(
			'konform/validation_timeout',
			$timeout,
			Scheduler::is_running_in_background()
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
