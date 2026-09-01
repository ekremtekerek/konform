<?php
/**
 * Sipariş ekranındaki belge kutusu ve indirme ucu.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Admin;

use Konform\Invoice\Generator;
use Konform\Storage\Archive;
use Konform\Storage\AuditLog;
use Konform\Storage\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Siparişin e-fatura belgelerini yönetici ekranında gösterir.
 *
 * İndirme doğrudan URL ile yapılmaz. Arşivdeki dosyalar mali belgelerdir;
 * yalnızca yetkili kullanıcı, geçerli bir nonce ile ve bütünlüğü doğrulanmış
 * hâlde indirebilir. Her indirme denetim izine yazılır.
 */
final class OrderDocuments {

	/**
	 * Kancaları kaydeder.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_konform_download', array( self::class, 'download' ) );
		add_action( 'admin_post_konform_generate', array( self::class, 'generate' ) );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
	}

	/**
	 * Sipariş ekranına kutuyu ekler.
	 *
	 * HPOS açıkken ekran kimliği farklıdır; ikisi de kaydedilir.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				'konform-documents',
				__( 'E-invoice', 'konform' ),
				array( self::class, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Kutunun içeriğini çizer.
	 *
	 * @param mixed $post_or_order Gönderi veya sipariş nesnesi.
	 * @return void
	 */
	public static function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: \wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : 0 );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_id  = $order->get_id();
		$documents = Archive::for_order( $order_id );

		if ( array() === $documents ) {
			self::render_blockers( $order );
		} else {
			self::render_documents( $documents );
		}

		self::render_generate_button( $order_id, array() !== $documents );
		self::render_audit( $order_id );
	}

	/**
	 * Üretimi engelleyen bulguları listeler.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return void
	 */
	private static function render_blockers( \WC_Order $order ): void {
		$blockers = Generator::blockers( $order );

		if ( array() === $blockers ) {
			printf( '<p>%s</p>', esc_html__( 'No document generated yet.', 'konform' ) );

			return;
		}

		printf(
			'<p><strong>%s</strong></p><ul style="margin-inline-start:16px;list-style:disc">',
			esc_html__( 'This order cannot be invoiced yet:', 'konform' )
		);

		foreach ( array_slice( $blockers, 0, 5 ) as $blocker ) {
			printf( '<li>%s</li>', esc_html( $blocker ) );
		}

		echo '</ul>';
	}

	/**
	 * Arşivlenmiş belgeleri listeler.
	 *
	 * @param Document[] $documents Belgeler.
	 * @return void
	 */
	private static function render_documents( array $documents ): void {
		echo '<ul style="margin:0">';

		foreach ( $documents as $document ) {
			$intact = $document->is_intact();

			printf(
				'<li style="margin-bottom:8px"><a href="%1$s"><strong>%2$s</strong></a><br/><span class="description">%3$s</span>%4$s</li>',
				esc_url( $document->download_url() ),
				esc_html(
					sprintf(
						/* translators: %d: document version number. */
						__( 'Version %d', 'konform' ),
						$document->version
					)
				),
				esc_html(
					sprintf(
						'%s · %s · %s',
						$document->profile,
						$document->locale,
						size_format( $document->byte_size )
					)
				),
				$intact
					? ''
					: '<br/><span style="color:#a4261d">' . esc_html__( 'File missing or modified', 'konform' ) . '</span>'
			);
		}

		echo '</ul>';
	}

	/**
	 * Üretim düğmesini çizer.
	 *
	 * @param int  $order_id Sipariş kimliği.
	 * @param bool $exists   Daha önce üretilmiş mi.
	 * @return void
	 */
	private static function render_generate_button( int $order_id, bool $exists ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
		wp_nonce_field( 'konform_generate_' . $order_id );
		echo '<input type="hidden" name="action" value="konform_generate"/>';
		printf( '<input type="hidden" name="order_id" value="%d"/>', (int) $order_id );

		printf(
			'<button type="submit" class="button">%s</button>',
			esc_html( $exists ? __( 'Generate new version', 'konform' ) : __( 'Generate document', 'konform' ) )
		);

		echo '</form>';
	}

	/**
	 * Denetim izini özetler.
	 *
	 * @param int $order_id Sipariş kimliği.
	 * @return void
	 */
	private static function render_audit( int $order_id ): void {
		$events = AuditLog::for_order( $order_id, 5 );

		if ( array() === $events ) {
			return;
		}

		printf( '<p style="margin-bottom:4px"><strong>%s</strong></p><ul style="margin:0;font-size:12px">', esc_html__( 'History', 'konform' ) );

		foreach ( $events as $event ) {
			printf(
				'<li>%1$s — %2$s</li>',
				esc_html( (string) $event['created_at'] ),
				esc_html( AuditLog::label( (string) $event['event'] ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Belgeyi indirir.
	 *
	 * @return void
	 */
	public static function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to download this document.', 'konform' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['document'] ) ? absint( wp_unslash( $_GET['document'] ) ) : 0;

		check_admin_referer( 'konform_download_' . $id );

		$document = Archive::find( $id );

		if ( null === $document ) {
			wp_die( esc_html__( 'Document not found.', 'konform' ), '', array( 'response' => 404 ) );
		}

		if ( ! $document->is_intact() ) {
			wp_die(
				esc_html__( 'The archived file is missing or has been modified since it was created. It cannot be served.', 'konform' ),
				'',
				array( 'response' => 409 )
			);
		}

		AuditLog::record( AuditLog::EVENT_DOWNLOADED, $document->order_id, $document->id );

		$filename = sprintf( 'invoice-%s-v%d.%s', $document->invoice_number, $document->version, $document->format );

		nocache_headers();
		header( 'Content-Type: ' . ( 'pdf' === $document->format ? 'application/pdf' : 'application/xml' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . $document->byte_size );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Dogrulanmis arsiv dosyasinin akitilmasi; WP_Filesystem burada uygun degil.
		readfile( $document->absolute_path() );

		exit;
	}

	/**
	 * Belgeyi elle üretir.
	 *
	 * @return void
	 */
	public static function generate(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to generate documents.', 'konform' ), '', array( 'response' => 403 ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		check_admin_referer( 'konform_generate_' . $order_id );

		$order = \wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			Generator::generate( $order );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
