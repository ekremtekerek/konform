<?php
/**
 * KSeF'in belgeyi reddettiğini bildiren istisna.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Ksef;

defined( 'ABSPATH' ) || exit;

/**
 * KSeF belgeyi reddetti.
 *
 * Ret ile geçici hata AYRI şeylerdir ve karıştırılmamalıdır:
 *
 * - **Geçici hata** (ağ kesintisi, zaman aşımı, KSeF yavaş): tekrar denemek
 *   sonucu değiştirebilir.
 * - **Ret**: belge KSeF'in kurallarına uymuyor. Aynı belgeyi tekrar sormak
 *   aynı cevabı verir.
 *
 * Ayrı bir tür olmasının sebebi budur. Kuyruk bunu görünce yeniden denemeyi
 * bırakır; aksi hâlde kırk dakika boyunca yirmi kez aynı soruyu sorar ve
 * denetim kaydına yirmi özdeş "reddedildi" satırı yazardı. Gürültü, gerçek
 * sorunu görünmez kılar.
 */
final class RejectedByKsef extends \RuntimeException {
}
