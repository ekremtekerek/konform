<?php
/**
 * Mağaza kapsamlı kural.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Preflight;

defined( 'ABSPATH' ) || exit;

/**
 * Mağaza genelinde bir kez çalışan kural.
 *
 * Bulguları siparişe değil mağazaya aittir; bu yüzden sipariş kimliği 0'dır ve
 * tarama sonucunda tek bir satır olarak görünürler.
 */
interface StoreRule extends Rule {

	/**
	 * Mağaza ayarlarını denetler.
	 *
	 * @return Finding[]
	 */
	public function check_store(): array;
}
