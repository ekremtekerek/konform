<?php
/**
 * Arşivlenmiş belge.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Arşivdeki tek bir belge kaydı.
 *
 * Belgeler asla üzerine yazılmaz. Yeniden üretim yeni bir sürüm satırı
 * oluşturur — kesilmiş bir faturayı sessizce değiştirmek yasal olarak yanlış
 * olurdu; eskisi de kalmalı ki denetimde neyin ne zaman gönderildiği
 * gösterilebilsin.
 */
final class Document {

	/**
	 * Kurucu.
	 *
	 * @param int    $id             Kayıt kimliği.
	 * @param int    $order_id       Sipariş kimliği.
	 * @param string $invoice_number Fatura numarası.
	 * @param string $profile        Belge profili.
	 * @param string $format         Dosya biçimi (xml, pdf).
	 * @param string $locale         Belge dili.
	 * @param string $relative_path  Uploads altındaki göreli yol.
	 * @param string $file_hash      Dosyanın SHA-256 özeti.
	 * @param int    $byte_size      Dosya boyutu.
	 * @param int    $version        Kaçıncı üretim.
	 * @param string $created_at     Oluşturulma zamanı (MySQL biçimi).
	 * @param int    $created_by     Oluşturan kullanıcı; kuyrukta 0.
	 * @param string $ksef_number    KSeF numarası; gönderilmemişse boş.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly string $invoice_number,
		public readonly string $profile,
		public readonly string $format,
		public readonly string $locale,
		public readonly string $relative_path,
		public readonly string $file_hash,
		public readonly int $byte_size,
		public readonly int $version,
		public readonly string $created_at,
		public readonly int $created_by,
		public readonly string $ksef_number = '',
	) {}

	/**
	 * Belge KSeF'e gönderilip numara aldı mı.
	 *
	 * Polonya icin belirleyici soru budur: FA(3) dosyasi KSeF numarasi alana
	 * kadar hukuken var olmaz. Dosyanin diskte durmasi tek basina bir sey
	 * ifade etmez.
	 *
	 * @return bool
	 */
	public function is_registered(): bool {
		return '' !== $this->ksef_number;
	}

	/**
	 * Veritabanı satırından nesne kurar.
	 *
	 * @param array<string, mixed> $row Satır.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['order_id'] ?? 0 ),
			(string) ( $row['invoice_number'] ?? '' ),
			(string) ( $row['profile'] ?? '' ),
			(string) ( $row['document_format'] ?? '' ),
			(string) ( $row['document_locale'] ?? '' ),
			(string) ( $row['relative_path'] ?? '' ),
			(string) ( $row['file_hash'] ?? '' ),
			(int) ( $row['byte_size'] ?? 0 ),
			(int) ( $row['version'] ?? 1 ),
			(string) ( $row['created_at'] ?? '' ),
			(int) ( $row['created_by'] ?? 0 ),
			(string) ( $row['ksef_number'] ?? '' )
		);
	}

	/**
	 * Dosyanın diskteki tam yolu.
	 *
	 * @return string
	 */
	public function absolute_path(): string {
		return Archive::root() . '/' . ltrim( $this->relative_path, '/' );
	}

	/**
	 * Dosya hâlâ diskte mi.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return is_readable( $this->absolute_path() );
	}

	/**
	 * Dosyanın içeriği değişmemiş mi.
	 *
	 * Arşivin bütünlüğünü kanıtlamak için kullanılır; denetimde belgenin
	 * üretildiğinden beri değiştirilmediğini göstermek gerekir.
	 *
	 * @return bool
	 */
	public function is_intact(): bool {
		if ( ! $this->exists() ) {
			return false;
		}

		return hash_equals( $this->file_hash, (string) hash_file( 'sha256', $this->absolute_path() ) );
	}

	/**
	 * İndirme bağlantısı.
	 *
	 * Dosyalar doğrudan URL ile sunulmaz; yetki kontrolünden geçen bir uç
	 * nokta üzerinden verilir.
	 *
	 * @return string
	 */
	public function download_url(): string {
		return \wp_nonce_url(
			\add_query_arg(
				array(
					'action'   => 'konform_download',
					'document' => $this->id,
				),
				\admin_url( 'admin-post.php' )
			),
			'konform_download_' . $this->id
		);
	}
}
