<?php
/**
 * Lisans planları.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\License;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC bölüm 07'deki paketleme.
 *
 * Planlar sıralıdır: AGENCY, PRO'nun her şeyini kapsar.
 */
enum Plan: string {

	/**
	 * WordPress.org'daki ücretsiz sürüm.
	 */
	case FREE = 'free';

	/**
	 * €149/yıl.
	 */
	case PRO = 'pro';

	/**
	 * €399/yıl.
	 */
	case AGENCY = 'agency';

	/**
	 * Sıralama ağırlığı.
	 *
	 * @return int
	 */
	public function rank(): int {
		return match ( $this ) {
			self::FREE   => 0,
			self::PRO    => 1,
			self::AGENCY => 2,
		};
	}

	/**
	 * Bu plan verilen planı kapsıyor mu.
	 *
	 * @param self $required Gereken plan.
	 * @return bool
	 */
	public function covers( self $required ): bool {
		return $this->rank() >= $required->rank();
	}

	/**
	 * Kullanıcıya gösterilecek ad.
	 *
	 * Plan adları markadır, çevrilmez.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::FREE   => 'Free',
			self::PRO    => 'Pro',
			self::AGENCY => 'Agency',
		};
	}
}
