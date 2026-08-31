<?php
/**
 * Ön uçuş kural arayüzleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Preflight;

defined( 'ABSPATH' ) || exit;

/**
 * Tüm kuralların ortak tabanı.
 *
 * Kurallar iki kapsamda çalışır ve bu ayrım önemlidir:
 *
 *   - StoreRule  : mağaza genelinde bir kez denetlenir. Eksik mağaza KDV
 *                  numarası her siparişte tekrarlanacak bir sorun değildir;
 *                  tek bir ayar düzeltmesi hepsini çözer. Sipariş başına
 *                  raporlamak, gerçek sipariş sorunlarını gözden kaçırtır.
 *   - OrderRule  : her sipariş için ayrı denetlenir.
 *
 * Ön uçuş yalnızca SATICININ DÜZELTEBİLECEĞİ sorunları raporlar. Eklentinin
 * kendisinin üreteceği alanlar (istisna gerekçe metni gibi) buraya girmez.
 */
interface Rule {

	/**
	 * Kuralın benzersiz kimliği.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Kuralın kullanıcıya gösterilecek adı.
	 *
	 * @return string
	 */
	public function title(): string;
}
