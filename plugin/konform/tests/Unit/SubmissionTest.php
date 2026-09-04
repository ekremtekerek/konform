<?php
/**
 * KSeF gönderim ve ayar testleri.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Tests\Unit;

use Konform\Ksef\Client;
use Konform\Ksef\Settings;
use Konform\Ksef\Submission;
use Konform\Storage\Document;
use Konform\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Gönderimin veritabanına dokunmayan kararlarını sınar.
 *
 * Arşive yazma ve denetim kaydı gerçek bir WordPress kurulumu ister; burada
 * sınanan şey ondan öncesi: gönderim yapılmalı mı, hangi ayarla, ve
 * yapılmaması gereken durumda gerçekten ağa çıkılmıyor mu.
 */
final class SubmissionTest extends TestCase {

	/**
	 * Her testten önce seçenekleri temizler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['konform_test_options'] = array();
	}

	/**
	 * Varsayılan ortam testtir.
	 *
	 * Uretim ortamina gonderilen her fatura hukuki sonuc dogurur. Varsayilanin
	 * uretim olmasi, yanlislikla gercek fatura kesilmesi demek olurdu.
	 *
	 * @return void
	 */
	public function test_the_default_environment_is_test(): void {
		$this->assertSame( Settings::ENVIRONMENT_TEST, Settings::environment() );
		$this->assertFalse( Settings::is_production() );
		$this->assertSame( Client::TEST_BASE_URL, Settings::base_url() );
	}

	/**
	 * Üretim ortamı açıkça seçilir.
	 *
	 * @return void
	 */
	public function test_production_must_be_chosen_explicitly(): void {
		update_option( Settings::OPTION_ENVIRONMENT, Settings::ENVIRONMENT_PRODUCTION );

		$this->assertTrue( Settings::is_production() );
		$this->assertSame( Client::PRODUCTION_BASE_URL, Settings::base_url() );
	}

	/**
	 * Tanınmayan bir ortam değeri üretime düşmez.
	 *
	 * @return void
	 */
	public function test_an_unknown_environment_falls_back_to_test(): void {
		update_option( Settings::OPTION_ENVIRONMENT, 'canli-gibi-bir-sey' );

		$this->assertFalse( Settings::is_production() );
	}

	/**
	 * Jeton kaydedilir ve boş dize onu siler.
	 *
	 * @return void
	 */
	public function test_the_token_round_trips_and_can_be_cleared(): void {
		$this->assertFalse( Settings::has_token() );

		Settings::set_token( '  JETON-1  ' );

		$this->assertTrue( Settings::has_token() );
		$this->assertSame( 'JETON-1', Settings::token() );

		Settings::set_token( '' );

		$this->assertFalse( Settings::has_token() );
	}

	/**
	 * Numarası olan belge tekrar gönderilmez.
	 *
	 * KSeF'te ayni faturanin iki kaydi olmasi, duzeltilmesi zor bir hatadir.
	 * Bu test onu tutuyor: tasiyiciya HIC istek gitmemeli.
	 *
	 * @return void
	 */
	public function test_an_already_registered_document_is_not_sent_again(): void {
		Settings::set_token( 'JETON' );

		$transport  = new RecordingTransport( array() );
		$submission = new Submission( new Client( $transport, Client::TEST_BASE_URL ) );

		$document = $this->document( '5265877635-20260904-3A0E71000000-0A' );

		$this->assertSame(
			'5265877635-20260904-3A0E71000000-0A',
			$submission->submit( $document, '<Faktura/>', '5265877635' )
		);

		$this->assertSame( array(), $transport->requests, 'Ağa çıkılmamalıydı.' );
	}

	/**
	 * Jeton yoksa ağa çıkılmaz.
	 *
	 * @return void
	 */
	public function test_without_a_token_nothing_is_sent(): void {
		$transport  = new RecordingTransport( array() );
		$submission = new Submission( new Client( $transport, Client::TEST_BASE_URL ) );

		try {
			$submission->submit( $this->document(), '<Faktura/>', '5265877635' );

			$this->fail( 'Jetonsuz gönderim istisna atmalıydı.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'token', $error->getMessage() );
		}

		$this->assertSame( array(), $transport->requests, 'Ağa çıkılmamalıydı.' );
	}

	/**
	 * Numarası olan belge tescilli sayılır.
	 *
	 * @return void
	 */
	public function test_registration_is_decided_by_the_ksef_number(): void {
		$this->assertFalse( $this->document()->is_registered() );
		$this->assertTrue( $this->document( 'KSEF-1' )->is_registered() );
	}

	/**
	 * Test belgesi.
	 *
	 * @param string $ksef_number KSeF numarası.
	 * @return Document
	 */
	private function document( string $ksef_number = '' ): Document {
		return new Document(
			1,
			42,
			'FA/2026/1',
			'ksef',
			'xml',
			'pl_PL',
			'konform/2026/09/fa-2026-1.xml',
			str_repeat( 'a', 64 ),
			1339,
			1,
			'2026-09-04 06:00:00',
			0,
			$ksef_number
		);
	}
}
