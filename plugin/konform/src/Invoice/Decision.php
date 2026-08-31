<?php
/**
 * Gerekçesiyle birlikte taşınan çözümleme sonucu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * Vergi kategorisi çözümlemesi her zaman kesin değildir. Kararın yanında
 * gerekçesini ve kesinlik durumunu taşımak, ön uçuş kontrolünün "burayı
 * tahmin ettik, doğrulayın" diyebilmesini sağlar.
 *
 * Sessizce tahmin etmek, uyumluluk ürününde yanlış cevaptan daha kötüdür.
 */
final class Decision {

	/**
	 * Kurucu.
	 *
	 * @param string $category   Vergi kategorisi kodu (UNTDID 5305).
	 * @param string $reason     Kararın makine okunur gerekçe anahtarı.
	 * @param bool   $is_certain Karar kesin mi, yoksa varsayıma mı dayanıyor.
	 */
	public function __construct(
		public readonly string $category,
		public readonly string $reason,
		public readonly bool $is_certain = true,
	) {}
}
