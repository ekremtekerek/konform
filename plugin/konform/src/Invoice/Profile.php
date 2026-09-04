<?php
/**
 * Ülkeye göre belge profili.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * Hangi ülkeye hangi formatın gönderileceğini belirler.
 *
 * Anlamsal model (EN 16931) her ülkede aynıdır; değişen, onu taşıyan
 * sözdizimi ve ülkenin dayattığı ek kısıtlardır. Bu ayrımı korumak, yeni bir
 * ülke eklemenin tek bir case eklemek kadar kolay olmasını sağlar.
 */
enum Profile: string {

	/**
	 * Fransa. Factur-X, EN 16931 uyumluluk seviyesi, CII sözdizimi.
	 */
	case FACTUR_X = 'factur-x';

	/**
	 * Almanya. XRechnung 3.x, CII sözdizimi.
	 */
	case XRECHNUNG = 'xrechnung';

	/**
	 * Ülkeye özel bir profil tanımlı değilse kullanılan taban.
	 */
	case EN16931 = 'en16931';

	/**
	 * Polonya. KSeF FA(3), ulusal sema - CII ya da UBL degil.
	 *
	 * Dikkat: FA(3) dosyasi tek basina hukuken var olmaz; KSeF'e gonderilip
	 * numara alana kadar fatura sayilmaz. Bkz. docs/adr/0006-polonya-ksef.md
	 */
	case KSEF = 'ksef';

	/**
	 * Satıcının ülkesine uygun profili döndürür.
	 *
	 * Belirleyici SATICININ ülkesidir, alıcının değil — mükellefiyet satıcıya
	 * aittir. Bkz. Locale::regulatory_profile().
	 *
	 * @param string $country ISO 3166-1 alpha-2 ülke kodu.
	 * @return self
	 */
	public static function for_country( string $country ): self {
		/*
		 * Polonya ancak ILETIM CALISTIKTAN SONRA buraya eklendi. FA(3) dosyasi
		 * KSeF'e gonderilip numara alana kadar hukuken var olmaz; gonderim
		 * yokken PL'yi buraya koymak, magazanin aldigi belgeyi yerine gecerli
		 * bir sey konmadan elinden almak olurdu.
		 *
		 * Bkz. docs/adr/0006-polonya-ksef.md
		 */
		$profile = match ( strtoupper( trim( $country ) ) ) {
			'FR'    => self::FACTUR_X,
			'DE'    => self::XRECHNUNG,
			'PL'    => self::KSEF,
			default => self::EN16931,
		};

		/**
		 * Kullanılacak belge profilini değiştirir.
		 *
		 * Çok şirketli veya sınır ötesi kurulumlarda mağaza ülkesi tek başına
		 * yeterli olmayabilir.
		 *
		 * @param Profile $profile Seçilen profil.
		 * @param string  $country Ülke kodu.
		 */
		$filtered = \apply_filters( 'konform/profile', $profile, $country );

		return $filtered instanceof self ? $filtered : $profile;
	}

	/**
	 * Kullanıcıya gösterilecek ad.
	 *
	 * Format adları markadır, çevrilmez.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::FACTUR_X  => 'Factur-X (EN 16931)',
			self::XRECHNUNG => 'XRechnung 3.0',
			self::EN16931   => 'EN 16931 (CII)',
			self::KSEF      => 'KSeF FA(3)',
		};
	}

	/**
	 * Bu profilin PDF/A-3 içine gömülü hibrit belge olarak üretilip
	 * üretilmeyeceğini bildirir.
	 *
	 * Factur-X tanımı gereği hibrittir: insan tarafından okunan PDF ve makine
	 * tarafından okunan XML tek dosyadır. XRechnung ise saf XML olarak iletilir.
	 *
	 * @return bool
	 */
	public function is_hybrid(): bool {
		return self::FACTUR_X === $this;
	}

	/**
	 * Üretilecek dosyanın uzantısı.
	 *
	 * @return string
	 */
	public function extension(): string {
		return $this->is_hybrid() ? 'pdf' : 'xml';
	}
}
