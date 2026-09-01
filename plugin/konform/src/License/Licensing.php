<?php
/**
 * Lisans durumu ve özellik kapıları.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\License;

defined( 'ABSPATH' ) || exit;

/**
 * Hangi özelliğin hangi planda açık olduğunu tek yerde tutar.
 *
 * Freemius'a BİLEREK sıkı bağlanmaz. SDK varsa ona sorar, yoksa ücretsiz
 * plana düşer. Gerekçe iki katlı:
 *
 *   1. Ödeme sağlayıcısı bir gün değişebilir; özellik ayrımı o kararın
 *      rehinesi olmamalı.
 *   2. Freemius hesabı olmadan da geliştirme ve test yapılabilmeli — aksi
 *      hâlde her katkıcının satıcı hesabına ihtiyacı olurdu.
 *
 * Özellik kontrolü kod tabanına dağılmaz; hepsi burada toplanır ki
 * fiyatlandırma değiştiğinde tek dosya değişsin.
 */
final class Licensing {

	/**
	 * Tek taramada işlenecek en fazla sipariş.
	 *
	 * Bu sınır PLANA BAĞLI DEĞİLDİR ve olamaz: WordPress.org, eklentinin
	 * kodunda bulunan bir işleve lisansa bağlı kullanım sınırı koymayı
	 * yasaklıyor. Bkz. docs/adr/0004-ucretsiz-pro-ayrimi.md
	 *
	 * Gerçek bir sınır yine de ŞART, ama gerekçesi başka: tarama bir yönetici
	 * isteği içinde çalışır ve 200 bin siparişlik bir mağazada sınırsız tarama
	 * sayfayı zaman aşımına uğratıp veritabanını yorar. Değiştirmek isteyen
	 * konform/preflight_limit kancasını kullanır; büyük mağazalar için doğru
	 * çözüm kuyruğa alınmış toplu tarama olacak (yol haritasında).
	 */
	public const PREFLIGHT_LIMIT = 1000;

	/**
	 * Etkin plan.
	 *
	 * @return Plan
	 */
	public static function plan(): Plan {
		$plan = self::from_freemius();

		/**
		 * Etkin planı değiştirir.
		 *
		 * Geliştirme ve otomatik testlerde planı zorlamak için kullanılır.
		 *
		 * @param Plan $plan Çözümlenen plan.
		 */
		$filtered = \apply_filters( 'konform/plan', $plan );

		return $filtered instanceof Plan ? $filtered : $plan;
	}

	/**
	 * Etkin plan verilen planı kapsıyor mu.
	 *
	 * @param Plan $required Gereken plan.
	 * @return bool
	 */
	public static function has( Plan $required ): bool {
		return self::plan()->covers( $required );
	}

	/**
	 * Ön uçuş taramasının sipariş sınırı.
	 *
	 * @return int
	 */
	public static function preflight_limit(): int {
		return self::PREFLIGHT_LIMIT;
	}

	/**
	 * Barındırılan resmi doğrulama açık mı.
	 *
	 * Planla ayrılan TEK şey budur ve ayrılabilmesinin sebebi, eklentinin
	 * bunu zaten kendi başına yapamamasıdır: kural seti XSLT 2.0'a derlenir,
	 * PHP'nin ext-xsl uzantısı XSLT 1.0'da kalır. Yani burada kapatılan şey
	 * "kodda olan bir işlev" değil, dışarıda işletilen bir servise erişimdir.
	 * WordPress.org'un yasakladığı yapay sınır bu değildir.
	 *
	 * Aynı zamanda lisans koruması: bu servis null'lanmış bir kopyada
	 * çalışmaz.
	 *
	 * @return bool
	 */
	public static function has_hosted_validation(): bool {
		return self::has( Plan::PRO );
	}

	/**
	 * Freemius SDK'sından planı okur.
	 *
	 * SDK yoksa ücretsiz plana düşer — geliştirme ortamının normal hâli budur.
	 *
	 * @return Plan
	 */
	private static function from_freemius(): Plan {
		if ( ! \function_exists( 'konform_fs' ) ) {
			return Plan::FREE;
		}

		$freemius = \konform_fs();

		if ( ! is_object( $freemius ) || ! method_exists( $freemius, 'can_use_premium_code' ) ) {
			return Plan::FREE;
		}

		if ( ! $freemius->can_use_premium_code() ) {
			return Plan::FREE;
		}

		if ( method_exists( $freemius, 'is_plan' ) && $freemius->is_plan( 'agency', true ) ) {
			return Plan::AGENCY;
		}

		return Plan::PRO;
	}
}
