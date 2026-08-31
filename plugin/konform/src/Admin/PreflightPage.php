<?php
/**
 * Ön uçuş kontrolü yönetici sayfası.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Admin;

use Konform\Preflight\Finding;
use Konform\Preflight\Report;
use Konform\Preflight\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Ürünün ilk izlenimini veren ekran.
 *
 * Kullanıcı hiçbir yapılandırma yapmadan buraya gelir ve 60 saniye içinde kaç
 * siparişinin reddedileceğini görür. Bu ekran satışın kendisidir.
 */
final class PreflightPage {

	/**
	 * Yönetici sayfası tanımlayıcısı.
	 */
	private const SLUG = 'konform';

	/**
	 * Tarama sonucunun önbellek anahtarı.
	 */
	private const CACHE_KEY = 'konform_preflight_report';

	/**
	 * Bir grupta gösterilecek en fazla sipariş bağlantısı.
	 */
	private const MAX_LINKS = 10;

	/**
	 * Kancaları kaydeder.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 60 );
		add_action( 'admin_post_konform_save_settings', array( self::class, 'save_settings' ) );
		add_action( 'admin_post_konform_rescan', array( self::class, 'rescan' ) );
	}

	/**
	 * Menü kaydını yapar.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Konform e-invoicing', 'konform' ),
			__( 'Konform', 'konform' ),
			'manage_woocommerce',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Ayarları kaydeder.
	 *
	 * @return void
	 */
	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'konform' ) );
		}

		check_admin_referer( 'konform_save_settings' );

		$vat_number = isset( $_POST['konform_seller_vat_number'] )
			? sanitize_text_field( wp_unslash( $_POST['konform_seller_vat_number'] ) )
			: '';

		$vat_number = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $vat_number ) );

		update_option( 'konform_seller_vat_number', $vat_number );
		delete_transient( self::CACHE_KEY );

		wp_safe_redirect( add_query_arg( 'konform-saved', '1', self::url() ) );
		exit;
	}

	/**
	 * Önbelleği temizleyip yeniden tarar.
	 *
	 * @return void
	 */
	public static function rescan(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to run this scan.', 'konform' ) );
		}

		check_admin_referer( 'konform_rescan' );

		delete_transient( self::CACHE_KEY );

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Sayfayı çizer.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$report = self::report();

		echo '<div class="wrap konform-wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Konform — e-invoicing pre-flight check', 'konform' ) );

		if ( isset( $_GET['konform-saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Settings saved. The check was run again.', 'konform' )
			);
		}

		self::render_styles();
		self::render_summary( $report );
		self::render_store_findings( $report );
		self::render_order_findings( $report );
		self::render_settings();

		echo '</div>';
	}

	/**
	 * Özet bloğunu çizer.
	 *
	 * @param Report $report Tarama raporu.
	 * @return void
	 */
	private static function render_summary( Report $report ): void {
		$blocked = $report->blocked_orders();

		echo '<div class="konform-hero">';

		if ( 0 === $report->scanned ) {
			printf(
				'<p class="konform-lede">%s</p>',
				esc_html__( 'There are no completed orders to check yet. Come back once you have taken your first order.', 'konform' )
			);
		} elseif ( 0 === $blocked ) {
			printf(
				'<p class="konform-lede konform-ok">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: number of orders that were checked. */
						_n(
							'All %s recent order would be accepted.',
							'All %s recent orders would be accepted.',
							$report->scanned,
							'konform'
						),
						number_format_i18n( $report->scanned )
					)
				)
			);
		} else {
			printf(
				'<p class="konform-lede konform-bad">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: number of orders that would be rejected, 2: number of orders checked. */
						_n(
							'%1$s of your last %2$s orders would be rejected.',
							'%1$s of your last %2$s orders would be rejected.',
							$blocked,
							'konform'
						),
						number_format_i18n( $blocked ),
						number_format_i18n( $report->scanned )
					)
				)
			);
		}

		$stats = array(
			array( __( 'Checked', 'konform' ), $report->scanned, '' ),
			array( __( 'Would be rejected', 'konform' ), $blocked, 'bad' ),
			array( __( 'Needs review', 'konform' ), $report->flagged_orders(), 'warn' ),
			array( __( 'Ready to invoice', 'konform' ), $report->clean_orders(), 'ok' ),
		);

		echo '<div class="konform-stats">';

		foreach ( $stats as $stat ) {
			printf(
				'<div class="konform-stat konform-%1$s"><span class="konform-stat-value">%2$s</span><span class="konform-stat-label">%3$s</span></div>',
				esc_attr( (string) $stat[2] ),
				esc_html( number_format_i18n( (int) $stat[1] ) ),
				esc_html( (string) $stat[0] )
			);
		}

		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'konform_rescan' );
		echo '<input type="hidden" name="action" value="konform_rescan"/>';
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Run the check again', 'konform' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Mağaza genelindeki bulguları çizer.
	 *
	 * Bunlar tek bir ayar düzeltmesiyle çözülür ve bu yüzden listenin başında,
	 * sipariş bulgularından ayrı gösterilirler.
	 *
	 * @param Report $report Tarama raporu.
	 * @return void
	 */
	private static function render_store_findings( Report $report ): void {
		$findings = $report->store_findings();

		if ( array() === $findings ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Fix these once, for the whole store', 'konform' ) );

		foreach ( $findings as $finding ) {
			echo '<div class="konform-group konform-store">';
			self::render_finding_body( $finding, '' );
			echo '</div>';
		}
	}

	/**
	 * Sipariş bulgularını çizer.
	 *
	 * @param Report $report Tarama raporu.
	 * @return void
	 */
	private static function render_order_findings( Report $report ): void {
		$groups = $report->grouped();

		if ( array() === $groups ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Order data that needs attention', 'konform' ) );

		foreach ( $groups as $findings ) {
			$orders = Report::distinct_orders( $findings );

			$count = sprintf(
				/* translators: %s: number of affected orders. */
				_n( '%s order', '%s orders', $orders, 'konform' ),
				number_format_i18n( $orders )
			);

			echo '<div class="konform-group">';
			self::render_finding_body( $findings[0], $count );
			self::render_affected( $findings );
			echo '</div>';
		}
	}

	/**
	 * Tek bir bulgunun gövdesini çizer.
	 *
	 * @param Finding $finding Bulgu.
	 * @param string  $count   Etkilenen sipariş sayısı metni; boş bırakılabilir.
	 * @return void
	 */
	private static function render_finding_body( Finding $finding, string $count ): void {
		printf(
			'<h3><span class="konform-badge konform-%1$s">%2$s</span> %3$s <span class="konform-count">%4$s</span></h3>',
			esc_attr( $finding->severity->value ),
			esc_html( $finding->severity->label() ),
			esc_html( self::rule_title( $finding->rule_id ) ),
			esc_html( $count )
		);

		printf( '<p class="konform-what">%s</p>', esc_html( $finding->what ) );
		printf( '<p class="konform-why">%s</p>', esc_html( $finding->why ) );
		printf(
			'<p class="konform-fix"><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'How to fix:', 'konform' ),
			esc_html( $finding->fix )
		);

		if ( '' !== $finding->standard ) {
			printf(
				'<p class="konform-standard">%1$s %2$s</p>',
				esc_html__( 'Rule:', 'konform' ),
				esc_html( $finding->standard )
			);
		}
	}

	/**
	 * Etkilenen siparişlerin bağlantılarını çizer.
	 *
	 * @param Finding[] $findings Bulgular.
	 * @return void
	 */
	private static function render_affected( array $findings ): void {
		$seen = array();

		foreach ( $findings as $finding ) {
			$seen[ $finding->order_id ] = $finding;
		}

		$shown     = array_slice( $seen, 0, self::MAX_LINKS, true );
		$remaining = count( $seen ) - count( $shown );

		echo '<p class="konform-orders">';
		printf( '%s ', esc_html__( 'Affected orders:', 'konform' ) );

		foreach ( $shown as $order_id => $finding ) {
			$url = $finding->order_url();

			if ( '' === $url ) {
				printf( '<span>#%s</span> ', esc_html( (string) $order_id ) );
				continue;
			}

			printf( '<a href="%1$s">#%2$s</a> ', esc_url( $url ), esc_html( (string) $order_id ) );
		}

		if ( $remaining > 0 ) {
			printf(
				'<span class="konform-more">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: number of additional affected orders. */
						_n( 'and %s more', 'and %s more', $remaining, 'konform' ),
						number_format_i18n( $remaining )
					)
				)
			);
		}

		echo '</p>';
	}

	/**
	 * Ayar formunu çizer.
	 *
	 * @return void
	 */
	private static function render_settings(): void {
		printf( '<h2>%s</h2>', esc_html__( 'Settings', 'konform' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="konform-settings">';
		wp_nonce_field( 'konform_save_settings' );
		echo '<input type="hidden" name="action" value="konform_save_settings"/>';

		printf(
			'<p><label for="konform_seller_vat_number"><strong>%1$s</strong></label><br/><input type="text" id="konform_seller_vat_number" name="konform_seller_vat_number" value="%2$s" class="regular-text" placeholder="FR12345678901"/><br/><span class="description">%3$s</span></p>',
			esc_html__( 'Your VAT number', 'konform' ),
			esc_attr( (string) get_option( 'konform_seller_vat_number', '' ) ),
			esc_html__( 'WooCommerce has no field for this, so Konform stores it. Include the country prefix.', 'konform' )
		);

		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Save', 'konform' ) );
		echo '</form>';
	}

	/**
	 * Raporu önbellekten alır veya üretir.
	 *
	 * @return Report
	 */
	private static function report(): Report {
		$cached = get_transient( self::CACHE_KEY );

		if ( $cached instanceof Report ) {
			return $cached;
		}

		$report = Scanner::scan();

		set_transient( self::CACHE_KEY, $report, 15 * MINUTE_IN_SECONDS );

		return $report;
	}

	/**
	 * Kural kimliğinden başlık üretir.
	 *
	 * @param string $rule_id Kural kimliği.
	 * @return string
	 */
	private static function rule_title( string $rule_id ): string {
		foreach ( Scanner::rules() as $rule ) {
			if ( $rule->id() === $rule_id ) {
				return $rule->title();
			}
		}

		return $rule_id;
	}

	/**
	 * Sayfa adresi.
	 *
	 * @return string
	 */
	private static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Sayfaya özel stiller.
	 *
	 * Ayrı bir dosya yüklemeye değmeyecek kadar küçük; tek sayfada kullanılıyor.
	 * Yön bağımsız olması için mantıksal CSS özellikleri kullanılır — bkz.
	 * docs/I18N.md bölüm 7.
	 *
	 * @return void
	 */
	private static function render_styles(): void {
		$css = '
		.konform-wrap{max-width:900px}
		.konform-hero{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px 24px;margin:16px 0 24px}
		.konform-lede{font-size:20px;line-height:1.4;margin:0 0 16px;font-weight:600}
		.konform-lede.konform-bad{color:#a4261d}
		.konform-lede.konform-ok{color:#116149}
		.konform-stats{display:flex;flex-wrap:wrap;gap:24px;margin-bottom:16px}
		.konform-stat{display:flex;flex-direction:column;min-width:110px}
		.konform-stat-value{font-size:26px;font-weight:600;line-height:1.2}
		.konform-stat-label{font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.04em}
		.konform-stat.konform-bad .konform-stat-value{color:#a4261d}
		.konform-stat.konform-warn .konform-stat-value{color:#8a5700}
		.konform-stat.konform-ok .konform-stat-value{color:#116149}
		.konform-group{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px 20px;margin-bottom:14px}
		.konform-group.konform-store{border-inline-start:3px solid #2271b1}
		.konform-group h3{margin:0 0 10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
		.konform-badge{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:3px 8px;border-radius:3px}
		.konform-badge.konform-blocker{background:#f6e4e2;color:#a4261d}
		.konform-badge.konform-warning{background:#f7eddc;color:#8a5700}
		.konform-badge.konform-info{background:#e6eef4;color:#2c5777}
		.konform-count{font-weight:400;color:#646970;font-size:13px}
		.konform-what{margin:0 0 6px;font-weight:600}
		.konform-why{margin:0 0 6px;color:#50575e}
		.konform-fix{margin:0 0 6px}
		.konform-standard{margin:0;color:#787c82;font-size:12px;font-family:monospace}
		.konform-orders{margin:10px 0 0;font-size:13px;color:#646970}
		.konform-orders a{margin-inline-end:4px}
		.konform-settings{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px 20px}
		';

		printf( '<style>%s</style>', esc_html( $css ) );
	}
}
