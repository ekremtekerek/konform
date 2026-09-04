<?php
/**
 * Belge üretim akışı.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

use Konform\I18n\Locale;
use Konform\Pdf\PdfRenderer;
use Konform\Preflight\Scanner;
use Konform\Preflight\Severity;
use Konform\Storage\Archive;
use Konform\Storage\AuditLog;
use Konform\Storage\Document;
use Konform\Validation\HostedValidator;
use Konform\Validation\ValidationResult;

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
	 * Sipariş için fatura üretir ve arşivler.
	 *
	 * @param \WC_Order $order Sipariş.
	 * @return Document|null Üretilemezse null.
	 */
	public static function generate( \WC_Order $order ): ?Document {
		return self::produce( OrderMapper::map( $order ), $order );
	}

	/**
	 * İade için iade faturası üretir ve arşivler.
	 *
	 * Asıl fatura DEĞİŞTİRİLMEZ. Muhasebede kayıt düzeltilmez, karşı kayıt
	 * atılır; iade faturası ayrı bir belgedir ve öncekine atıf yapar.
	 *
	 * @param \WC_Order_Refund $refund İade kaydı.
	 * @param \WC_Order        $order  Asıl sipariş.
	 * @return Document|null Üretilemezse null.
	 */
	public static function generate_credit_note( \WC_Order_Refund $refund, \WC_Order $order ): ?Document {
		return self::produce( CreditNote::map( $refund, $order ), $order );
	}

	/**
	 * Anlamsal faturadan arşivlenmiş belgeye giden ortak akış.
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @param \WC_Order       $order   Kaynak sipariş.
	 * @return Document|null
	 */
	private static function produce( SemanticInvoice $invoice, \WC_Order $order ): ?Document {
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

		$profile = Profile::for_country( $invoice->seller->country );
		$locale  = Locale::document( $order );
		$builder = self::builder( $profile );

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
			$xml = Locale::render(
				$locale,
				static fn (): string => $builder->build_xml( $invoice, $profile )
			);
		} catch ( \Throwable $error ) {
			AuditLog::record( AuditLog::EVENT_FAILED, $order_id, 0, $error->getMessage() );

			return null;
		}

		/*
		 * Resmi kural setine gore dogrulama. PDF'e gomulmeden ONCE yapilir;
		 * dogrulanan sey gonderilecek olanin ta kendisi olmali.
		 *
		 * FA(3) BU DOGRULAMADAN GECIRILMEZ. Polonya'nin semasi EN 16931 degil;
		 * ulusal bir sema. Onu EN 16931 kural setine gondermek anlamsiz
		 * hatalar uretir ve uretimi bloke ederdi. FA(3)'un dogrulanmasi iki
		 * yerde yapiliyor: uretilirken resmi XSD'ye karsi (Fa3Builder) ve
		 * gonderilirken KSeF'in kendisi tarafindan.
		 */
		$validation = Profile::KSEF === $profile
			? ValidationResult::skipped()
			: ( new HostedValidator() )->validate( $xml );

		if ( $validation->blocks() ) {
			AuditLog::record( AuditLog::EVENT_INVALID, $order_id, 0, $validation->summary() );

			return null;
		}

		try {
			// PDF de belge dilinde uretilir: etiketler alicinin dilinde olmali.
			$content = $profile->is_hybrid()
				? Locale::render(
					$locale,
					static fn (): string => $builder->build_hybrid(
						$invoice,
						$profile,
						PdfRenderer::render( $order, $invoice )
					)
				)
				: $xml;
		} catch ( \Throwable $error ) {
			AuditLog::record( AuditLog::EVENT_FAILED, $order_id, 0, $error->getMessage() );

			return null;
		}

		$document = Archive::store(
			$order_id,
			$invoice->number,
			$profile->value,
			$profile->extension(),
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
			sprintf(
				'%s, type %s, %s, version %d — %s',
				$profile->label(),
				$invoice->type_code,
				$locale,
				$document->version,
				$validation->summary()
			)
		);

		/**
		 * Belge üretildikten sonra çalışır.
		 *
		 * Teslim adımları (e-posta eki, PDP gönderimi) buraya bağlanır.
		 *
		 * @param Document        $document Arşivlenmiş belge.
		 * @param \WC_Order       $order    Sipariş.
		 * @param SemanticInvoice $invoice  Anlamsal fatura.
		 */
		\do_action( 'konform/document_generated', $document, $order, $invoice );

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
	 * Profile uygun üreticiyi döndürür.
	 *
	 * Polonya'nin FA(3) semasi CII degildir ve ZugferdBuilder onu uretemez;
	 * bu yuzden uretici artik profile gore seciliyor.
	 *
	 * @param Profile $profile Belge profili.
	 * @return DocumentBuilder
	 */
	private static function builder( Profile $profile ): DocumentBuilder {
		$default = Profile::KSEF === $profile ? new Fa3Builder() : new ZugferdBuilder();

		/**
		 * Belge üreticisini değiştirir.
		 *
		 * ADR 0001: kütüphane değişirse yalnızca bu adaptör değişir.
		 *
		 * @param DocumentBuilder $default Üretici.
		 * @param Profile         $profile Belge profili.
		 */
		$builder = \apply_filters( 'konform/document_builder', $default, $profile );

		return $builder instanceof DocumentBuilder ? $builder : $default;
	}
}
