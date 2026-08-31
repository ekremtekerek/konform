<?php
/**
 * Ön uçuş tarama raporu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Preflight;

defined( 'ABSPATH' ) || exit;

/**
 * Bir tarama turunun sonucu.
 *
 * Ürünün açılış cümlesi buradan çıkar: "Son 50 siparişinin 12'si reddedilirdi."
 *
 * Sayımlarda tek kural geçerlidir: her yerde BENZERSIZ SIPARIS sayılır, bulgu
 * değil. Tek siparişte beş sorun olması onu beş sipariş yapmaz — bu ayrımı
 * kaçırmak raporu abartılı ve güvenilmez gösterir.
 */
final class Report {

	/**
	 * Kurucu.
	 *
	 * @param int       $scanned  Taranan sipariş sayısı.
	 * @param Finding[] $findings Bulgular.
	 */
	public function __construct(
		public readonly int $scanned,
		public readonly array $findings,
	) {}

	/**
	 * Verilen bulgulardaki benzersiz sipariş sayısı.
	 *
	 * Mağaza geneli bulgular sipariş saymaz.
	 *
	 * @param Finding[] $findings Bulgular.
	 * @return int
	 */
	public static function distinct_orders( array $findings ): int {
		$ids = array();

		foreach ( $findings as $finding ) {
			if ( ! $finding->is_store_wide() ) {
				$ids[ $finding->order_id ] = true;
			}
		}

		return count( $ids );
	}

	/**
	 * Reddedilecek sipariş sayısı.
	 *
	 * @return int
	 */
	public function blocked_orders(): int {
		return self::distinct_orders( $this->by_severity( Severity::BLOCKER ) );
	}

	/**
	 * İncelenmesi gereken sipariş sayısı.
	 *
	 * @return int
	 */
	public function flagged_orders(): int {
		return self::distinct_orders( $this->by_severity( Severity::WARNING ) );
	}

	/**
	 * Sorunsuz sipariş sayısı.
	 *
	 * @return int
	 */
	public function clean_orders(): int {
		$problem = array();

		foreach ( $this->findings as $finding ) {
			if ( ! $finding->is_store_wide() && Severity::INFO !== $finding->severity ) {
				$problem[ $finding->order_id ] = true;
			}
		}

		return max( 0, $this->scanned - count( $problem ) );
	}

	/**
	 * Mağaza genelindeki bulgular.
	 *
	 * Bunlar tek bir ayar düzeltmesiyle çözülür ve rapor listesinin başında
	 * gösterilmelidir.
	 *
	 * @return Finding[]
	 */
	public function store_findings(): array {
		return array_values(
			array_filter(
				$this->findings,
				static fn ( Finding $finding ): bool => $finding->is_store_wide()
			)
		);
	}

	/**
	 * Sipariş bulgularını sorun türüne göre gruplar.
	 *
	 * Kullanıcı tek tek siparişleri değil, tekrar eden kök sebebi görmek ister:
	 * "12 siparişte alıcı KDV numarası eksik" tek bir düzeltmeye işaret eder.
	 *
	 * @return array<string, Finding[]>
	 */
	public function grouped(): array {
		$groups = array();

		foreach ( $this->findings as $finding ) {
			if ( $finding->is_store_wide() ) {
				continue;
			}

			$groups[ $finding->group_key() ][] = $finding;
		}

		uasort(
			$groups,
			static function ( array $a, array $b ): int {
				$by_severity = $b[0]->severity->weight() <=> $a[0]->severity->weight();

				if ( 0 !== $by_severity ) {
					return $by_severity;
				}

				return self::distinct_orders( $b ) <=> self::distinct_orders( $a );
			}
		);

		return $groups;
	}

	/**
	 * Hiç sorun bulunmadı mı.
	 *
	 * @return bool
	 */
	public function is_clean(): bool {
		return array() === $this->findings;
	}

	/**
	 * Belirli ciddiyetteki bulguları döndürür.
	 *
	 * @param Severity $severity Ciddiyet.
	 * @return Finding[]
	 */
	private function by_severity( Severity $severity ): array {
		return array_values(
			array_filter(
				$this->findings,
				static fn ( Finding $finding ): bool => $severity === $finding->severity
			)
		);
	}
}
