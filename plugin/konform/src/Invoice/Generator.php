<?php
/**
 * Belge üretim akışı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

use Konform\I18n\Locale;
use Konform\Preflight\Scanner;
use Konform\Preflight\Severity;
use Konform\Storage\Archive;
use Konform\Storage\AuditLog;
use Konform\Storage\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Siparişten arşivlenmiş belgeye giden yolu yönetir.
 *
 * Tasarımın kilit kararı: ön uçuş kontrolü yalnızca bir rapor değil, üretimin
 * KAPISIDIR. Engelleyici bulgusu olan bir siparişten belge üretmeyi reddederiz.
 * Reddedileceği bilinen bir faturayı üretip göndermek, hiç üretmemekten
 * kötüdür — satıcı gönderdiğini sanır, sorunu haftalar sonra vergi
 * idaresinden öğrenir.
 */
final class Generator {

	/**
	 * Sipariş için belge üretir ve arşivler.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return Document|null Üretilemezse null.
	 */
	public static function generate( \WC_Order $order ): ?Document {
		$order_id = $order->get_id();
		$blockers = self::blockers( $order );

		if ( array() !== $blockers ) {
			AuditLog::record(
				AuditLog::EVENT_FAILED,
				$order_id,
				0,
				sprintf(
					'Pre-flight blocked generation: %s',
					implode( '; ', array_slice( $blockers, 0, 5 ) )
				)
			);

			return null;
		}

		$invoice = OrderMapper::map( $order );
		$profile = Profile::for_country( $invoice->seller->country );
		$locale  = Locale::document( $order );
		$builder = self::builder();

		if ( ! $builder->supports( $profile ) ) {
			AuditLog::record(
				AuditLog::EVENT_FAILED,
				$order_id,
				0,
				sprintf( 'No builder supports profile "%s".', $profile->value )
			);

			return null;
		}

		try {
			/*
			 * Belge ALICININ dilinde uretilir. Locale::render() disinda
			 * switch_to_locale() cagrilmaz; bkz. docs/I18N.md.
			 */
			$content = Locale::render(
				$locale,
				static fn (): string => $builder->build_xml( $invoice, $profile )
			);
		} catch ( \Throwable $error ) {
			AuditLog::record( AuditLog::EVENT_FAILED, $order_id, 0, $error->getMessage() );

			return null;
		}

		$document = Archive::store(
			$order_id,
			$invoice->number,
			$profile->value,
			'xml',
			$locale,
			$content
		);

		if ( null === $document ) {
			AuditLog::record(
				AuditLog::EVENT_FAILED,
				$order_id,
				0,
				'Archive write failed. Check that the uploads directory is writable.'
			);

			return null;
		}

		AuditLog::record(
			AuditLog::EVENT_GENERATED,
			$order_id,
			$document->id,
			sprintf( '%s, %s, version %d', $profile->label(), $locale, $document->version )
		);

		/**
		 * Belge üretildikten sonra çalışır.
		 *
		 * Teslim adımları (e-posta eki, PDP gönderimi) buraya bağlanır.
		 *
		 * @param Document  $document Arşivlenmiş belge.
		 * @param \WC_Order $order    Sipariş.
		 */
		\do_action( 'konform/document_generated', $document, $order );

		return $document;
	}

	/**
	 * Üretimi engelleyen bulguların özetini döndürür.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return string[]
	 */
	public static function blockers( \WC_Order $order ): array {
		$findings = array_merge( Scanner::check_store(), Scanner::check_order( $order ) );
		$messages = array();

		foreach ( $findings as $finding ) {
			if ( Severity::BLOCKER === $finding->severity ) {
				$messages[] = $finding->what;
			}
		}

		return $messages;
	}

	/**
	 * Kullanılacak üreticiyi döndürür.
	 *
	 * @return DocumentBuilder
	 */
	private static function builder(): DocumentBuilder {
		/**
		 * Belge üreticisini değiştirir.
		 *
		 * ADR 0001: kütüphane değişirse yalnızca bu adaptör değişir.
		 *
		 * @param DocumentBuilder $builder Üretici.
		 */
		$builder = \apply_filters( 'konform/document_builder', new ZugferdBuilder() );

		return $builder instanceof DocumentBuilder ? $builder : new ZugferdBuilder();
	}
}
