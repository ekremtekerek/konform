<?php
/**
 * Polonya KSeF FA(3) belge üreticisi.
 *
 * @package Konform
 */

declare( strict_types = 1 );

namespace Konform\Invoice;

use Konform\Vendor\Intermedia\Ksef\Fa3\Enums\TKodFormularza;
use Konform\Vendor\Intermedia\Ksef\Fa3\Enums\TKodKraju;
use Konform\Vendor\Intermedia\Ksef\Fa3\Enums\TKodWaluty;
use Konform\Vendor\Intermedia\Ksef\Fa3\Enums\TKodyKrajowUE;
use Konform\Vendor\Intermedia\Ksef\Fa3\Enums\TRodzajFaktury;
use Konform\Vendor\Intermedia\Ksef\Fa3\Enums\TStawkaPodatku;
use Konform\Vendor\Intermedia\Ksef\Fa3\Enums\TWybor1;
use Konform\Vendor\Intermedia\Ksef\Fa3\Enums\TWybor1_2;
use Konform\Vendor\Intermedia\Ksef\Fa3\Enums\WariantFormularza;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\AdnotacjeType;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\FakturaType;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\FaType;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\FaWierszType;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\KodFormularzaType;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\NoweSrodkiTransportuType;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\PMarzyType;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\Podmiot1Type;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\Podmiot2Type;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\TAdres;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\TNaglowek;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\TPodmiot1;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\TPodmiot2;
use Konform\Vendor\Intermedia\Ksef\Fa3\Model\ZwolnienieType;
use Konform\Vendor\Intermedia\Ksef\Fa3\Serializer\XmlSerializer;
use Konform\Vendor\Intermedia\Ksef\Fa3\Validator\XmlValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Anlamsal faturayı Polonya'nın FA(3) ulusal şemasına çevirir.
 *
 * Neden ayrı bir üretici: FA(3), Factur-X ve XRechnung'un aksine CII değildir.
 * Alan adları Lehçe, yapı farklı ve EN 16931 ile birebir örtüşmez. Aynı
 * üreticiye sıkıştırmak iki şemayı da bozardı.
 *
 * ÖNEMLİ: Bu üreticinin çıktısı tek başına fatura DEĞİLDİR. FA(3), KSeF'e
 * gönderilip sistemden numara alana kadar hukuken var olmaz ve gönderim
 * tarihi resmî düzenleme tarihi sayılır. Bkz. docs/adr/0006-polonya-ksef.md
 */
final class Fa3Builder implements DocumentBuilder {

	/**
	 * Şema dosyasının paket içindeki yolu.
	 */
	private const SCHEMA = '/intermedia/ksef-fa3/schema/FA3.xsd';

	/**
	 * Standart oranlar için FA(3) alan çiftleri: oran => [net, KDV].
	 *
	 * @var array<string,string[]>
	 */
	private const STANDARD_FIELDS = array(
		'23' => array( 'p131', 'p141' ),
		'8'  => array( 'p132', 'p142' ),
		'5'  => array( 'p133', 'p143' ),
	);

	/**
	 * Oran dışı kategoriler için FA(3) net toplam alanı.
	 *
	 * FA(3) bunlari orana gore degil VERGI REJIMINE gore ayirir; %0 tek bir
	 * alan degildir, uce bolunmustur. Yanlis alana yazmak yanlis beyan demek.
	 *
	 * @var array<string,string>
	 */
	private const CATEGORY_FIELDS = array(
		'Z'  => 'p1361',  // %0 yurt içi.
		'K'  => 'p1362',  // %0 AB içi teslim (WDT).
		'G'  => 'p1363',  // %0 ihracat.
		'E'  => 'p137',   // Muaf.
		'AE' => 'p1310',  // Tersine yük.
		'O'  => 'p138',   // Yurt dışı teslim/hizmet.
	);

	/**
	 * Kategoriden satır düzeyi oran enum'una eşleme.
	 *
	 * @var array<string,string>
	 */
	private const CATEGORY_RATES = array(
		'Z'  => 'STAWKA_0_KRAJ',
		'K'  => 'STAWKA_0_WDT',
		'G'  => 'STAWKA_0_EXPORT',
		'E'  => 'ZW',
		'AE' => 'OO',
		'O'  => 'NP_POZA_TERYT',
	);

	/**
	 * Standart oranlar için satır düzeyi enum adları.
	 *
	 * @var array<string,string>
	 */
	private const STANDARD_RATES = array(
		'23' => 'STAWKA_23',
		'8'  => 'STAWKA_8',
		'5'  => 'STAWKA_5',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @param Profile $profile Belge profili.
	 * @return bool
	 */
	public function supports( Profile $profile ): bool {
		return Profile::KSEF === $profile;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @param Profile         $profile Belge profili.
	 * @return string
	 * @throws \RuntimeException Profil desteklenmiyorsa veya şema doğrulaması geçmezse.
	 */
	public function build_xml( SemanticInvoice $invoice, Profile $profile ): string {
		if ( ! $this->supports( $profile ) ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil.
				sprintf( 'Fa3Builder does not support the "%s" profile.', $profile->value )
			);
		}

		$faktura           = new FakturaType();
		$faktura->naglowek = $this->header( $invoice );
		$faktura->podmiot1 = $this->seller( $invoice->seller );
		$faktura->podmiot2 = $this->buyer( $invoice->buyer );
		$faktura->fa       = $this->body( $invoice );

		$document = ( new XmlSerializer() )->createDocument( 'Faktura', $faktura );

		$this->assert_valid( $document );

		$xml = $document->saveXML();

		if ( ! is_string( $xml ) || '' === $xml ) {
			throw new \RuntimeException( 'The FA(3) document could not be serialised.' );
		}

		return $xml;
	}

	/**
	 * {@inheritDoc}
	 *
	 * FA(3) saf XML olarak iletilir; hibrit karşılığı yoktur.
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @param Profile         $profile Belge profili.
	 * @param string          $pdf     PDF içeriği.
	 * @return never
	 * @throws \RuntimeException Her zaman.
	 */
	public function build_hybrid( SemanticInvoice $invoice, Profile $profile, string $pdf ): never {
		unset( $invoice, $profile, $pdf );

		throw new \RuntimeException( 'KSeF FA(3) has no hybrid form; it is transmitted as XML.' );
	}

	/**
	 * Başlık bloğu.
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @return TNaglowek
	 */
	private function header( SemanticInvoice $invoice ): TNaglowek {
		$code        = new KodFormularzaType();
		$code->value = TKodFormularza::FA;

		$header                    = new TNaglowek();
		$header->kodFormularza     = $code;
		$header->wariantFormularza = WariantFormularza::WARIANT_3;
		$header->dataWytworzeniaFa = $invoice->issue_date->format( 'Y-m-d\TH:i:s\Z' );

		return $header;
	}

	/**
	 * Satıcı bloğu.
	 *
	 * @param Party $seller Satıcı.
	 * @return Podmiot1Type
	 */
	private function seller( Party $seller ): Podmiot1Type {
		$identity        = new TPodmiot1();
		$identity->nazwa = $seller->name;
		$identity->nIP   = $this->nip( $seller->vat_number );

		$block                      = new Podmiot1Type();
		$block->daneIdentyfikacyjne = $identity;
		$block->adres               = $this->address( $seller );

		return $block;
	}

	/**
	 * Alıcı bloğu.
	 *
	 * @param Party $buyer Alıcı.
	 * @return Podmiot2Type
	 */
	private function buyer( Party $buyer ): Podmiot2Type {
		$identity        = new TPodmiot2();
		$identity->nazwa = $buyer->name;

		$this->identify( $identity, $buyer );

		$block                      = new Podmiot2Type();
		$block->daneIdentyfikacyjne = $identity;
		$block->adres               = $this->address( $buyer );

		return $block;
	}

	/**
	 * Alıcının kimlik alanını doldurur.
	 *
	 * FA(3) alicida DORT SECENEKTEN BIRINI zorunlu tutar ve bunlar birbirinin
	 * yerine gecmez:
	 *
	 *   NIP                -> Polonyali mukellef
	 *   KodUE + NrVatUE    -> baska bir AB ulkesinde KDV mukellefi
	 *   KodKraju + NrID    -> AB disi, kimlik numarasi olan
	 *   BrakID             -> kimlik numarasi yok (tuketici)
	 *
	 * Ilk yazimda yalnizca Polonyali alicida NIP yaziliyor, yurt disi alicida
	 * hicbiri yazilmiyordu. Bu, semanin reddettigi bir belge uretiyordu ve
	 * YEREL dogrulamada goruluyordu: AB ici teslim, ihracat, tersine yuk ve
	 * yurt disi hizmet senaryolarinin hicbiri uretilemiyordu. Yurt ici
	 * satislar calistigi icin fark edilmemisti; kategori matrisi gosterdi.
	 *
	 * @param TPodmiot2 $identity Kimlik bloğu.
	 * @param Party     $buyer    Alıcı.
	 * @return void
	 */
	private function identify( TPodmiot2 $identity, Party $buyer ): void {
		$country = strtoupper( trim( $buyer->country ) );
		$vat     = trim( $buyer->vat_number );

		if ( 'PL' === $country ) {
			if ( '' !== $vat ) {
				$identity->nIP = $this->nip( $vat );

				return;
			}

			$identity->brakID = TWybor1::VAL_1;

			return;
		}

		/*
		 * AB icindeki mukellef: ulke kodu ve KDV numarasi AYRI alanlara gider,
		 * numara onekini tasimaz.
		 */
		if ( '' !== $vat && Eu::is_member( $country ) ) {
			$code = TKodyKrajowUE::tryFrom( $country );

			if ( null !== $code ) {
				$identity->kodUE   = $code;
				$identity->nrVatUE = $this->strip_prefix( $vat, $country );

				return;
			}
		}

		if ( '' !== $vat ) {
			$identity->kodKraju = $this->country( $country );
			$identity->nrID     = $vat;

			return;
		}

		// Kimlik numarasi yok; sema bunu acikca beyan etmeyi istiyor.
		$identity->brakID = TWybor1::VAL_1;
	}

	/**
	 * KDV numarasından ülke önekini atar.
	 *
	 * @param string $vat_number KDV numarası.
	 * @param string $country    Ülke kodu.
	 * @return string
	 */
	private function strip_prefix( string $vat_number, string $country ): string {
		$value = strtoupper( preg_replace( '/\s+/', '', $vat_number ) ?? '' );

		return str_starts_with( $value, $country ) ? substr( $value, strlen( $country ) ) : $value;
	}

	/**
	 * Adres bloğu.
	 *
	 * @param Party $party Taraf.
	 * @return TAdres
	 */
	private function address( Party $party ): TAdres {
		$address           = new TAdres();
		$address->kodKraju = $this->country( $party->country );
		$address->adresL1  = trim( $party->address );
		$address->adresL2  = trim( $party->postcode . ' ' . $party->city );

		return $address;
	}

	/**
	 * Fatura gövdesi.
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @return FaType
	 * @throws \RuntimeException Oran ya da kategori eşlenemezse.
	 */
	private function body( SemanticInvoice $invoice ): FaType {
		$body                = new FaType();
		$body->rodzajFaktury = TRodzajFaktury::VAT;
		$body->kodWaluty     = $this->currency( $invoice->currency );
		$body->p1            = $invoice->issue_date->format( 'Y-m-d' );
		$body->p2            = $invoice->number;
		$body->p15           = $this->amount( $invoice->tax_inclusive_total() );
		$body->adnotacje     = $this->annotations( $invoice );

		if ( $invoice->delivery_date instanceof \DateTimeImmutable ) {
			$body->p6 = $invoice->delivery_date->format( 'Y-m-d' );
		}

		/*
		 * FA(3) her oran icin AYRI bir alan cifti kullanir (net, KDV). Serbest
		 * bir liste yok; %23 icin p131/p141, %8 icin p132/p142 gibi. Bu yuzden
		 * oranlar dongude degil, esleme uzerinden yaziliyor.
		 */
		foreach ( $invoice->tax_subtotals as $subtotal ) {
			$category = strtoupper( trim( $subtotal->category ) );

			if ( 'S' === $category ) {
				$fields = self::STANDARD_FIELDS[ $this->rate_key( $subtotal->rate ) ] ?? null;

				if ( null === $fields ) {
					throw new \RuntimeException(
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil.
						sprintf( 'FA(3) has no field for the standard VAT rate %s%%.', $this->rate_key( $subtotal->rate ) )
					);
				}

				[ $net_field, $tax_field ] = $fields;

				$body->{$net_field} = $this->amount( $subtotal->basis_amount );
				$body->{$tax_field} = $this->amount( $subtotal->tax_amount );

				continue;
			}

			$field = self::CATEGORY_FIELDS[ $category ] ?? null;

			if ( null === $field ) {
				throw new \RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil.
					sprintf( 'FA(3) has no field for the tax category "%s".', $category )
				);
			}

			// Oran disi rejimlerde KDV tutari yoktur; yalnizca net toplam yazilir.
			$body->{$field} = $this->amount( $subtotal->basis_amount );
		}

		$body->faWiersz = $this->lines( $invoice );

		return $body;
	}

	/**
	 * Fatura satırları.
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @return FaWierszType[]
	 */
	private function lines( SemanticInvoice $invoice ): array {
		$rows   = array();
		$number = 1;

		foreach ( $invoice->lines as $line ) {
			$row              = new FaWierszType();
			$row->nrWierszaFa = (string) $number;
			$row->p7          = $line->name;
			$row->p8A         = $line->unit_code;
			$row->p8B         = $this->quantity( $line->quantity );
			$row->p9A         = $this->amount( $line->net_price );
			$row->p11         = $this->amount( $line->net_amount );

			$row->p12 = $this->line_rate( $line->tax_category, $line->tax_rate );

			$rows[] = $row;
			++$number;
		}

		return $rows;
	}

	/**
	 * Zorunlu şerh bloğu.
	 *
	 * FA(3) bu blogu zorunlu tutar ve alanlarin cogu "hayir" anlamina gelen
	 * VAL_2 ile doldurulur. Kasa muhasebesi, oz fatura, marj usulu gibi ozel
	 * rejimler desteklenmiyor; destekleninceye kadar hepsi acikca "hayir".
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @return AdnotacjeType
	 */
	private function annotations( SemanticInvoice $invoice ): AdnotacjeType {
		$annotations       = new AdnotacjeType();
		$annotations->p16  = TWybor1_2::VAL_2;
		$annotations->p17  = TWybor1_2::VAL_2;
		$annotations->p18  = TWybor1_2::VAL_2;
		$annotations->p18A = TWybor1_2::VAL_2;
		$annotations->p23  = TWybor1_2::VAL_2;

		$annotations->zwolnienie = $this->exemption( $invoice );

		// Yeni tasit teslimi ayri bir rejimdir ve desteklenmiyor; sema bu blogu
		// zorunlu tuttugu icin acikca "yok" deniyor.
		$transport       = new NoweSrodkiTransportuType();
		$transport->p22N = TWybor1::VAL_1;

		$annotations->noweSrodkiTransportu = $transport;

		// Marj usulu de desteklenmiyor.
		$margin              = new PMarzyType();
		$margin->pPMarzyN    = TWybor1::VAL_1;
		$annotations->pMarzy = $margin;

		return $annotations;
	}

	/**
	 * Muafiyet bloğu.
	 *
	 * FA(3) bu blogu zorunlu tutar ve iki secenek sunar: ya muafiyet yoktur
	 * (P_19N), ya da vardir ve HUKUKI DAYANAGI yazilir (P_19 + P_19A/B/C).
	 * Muaf bir faturaya "muafiyet yok" demek yanlis beyandir, o yuzden
	 * kategoriye bakiliyor.
	 *
	 * BILINEN EKSIK: P_19A ulusal kanun maddesi, P_19B ise 2006/112/AT
	 * yonergesinin maddesi ister. Elimizdeki BT-120 metni serbest bicimlidir
	 * ve hangisi oldugunu bilemeyiz; bu yuzden "diger hukuki dayanak" alanina
	 * (P_19C) yaziliyor. Polonya kullaniciya acilmadan once bu eslemenin
	 * Polonya KDV mevzuatina gore gozden gecirilmesi gerekiyor.
	 * Bkz. docs/adr/0006-polonya-ksef.md
	 *
	 * @param SemanticInvoice $invoice Anlamsal fatura.
	 * @return ZwolnienieType
	 * @throws \RuntimeException Muaf faturada gerekçe yoksa.
	 */
	private function exemption( SemanticInvoice $invoice ): ZwolnienieType {
		$block = new ZwolnienieType();

		$exempt = array_filter(
			$invoice->tax_subtotals,
			static fn ( TaxSubtotal $subtotal ): bool => 'E' === strtoupper( trim( $subtotal->category ) )
		);

		if ( array() === $exempt ) {
			$block->p19N = TWybor1::VAL_1;

			return $block;
		}

		$reason = '';

		foreach ( $exempt as $subtotal ) {
			if ( '' !== trim( $subtotal->exemption_reason ) ) {
				$reason = trim( $subtotal->exemption_reason );
				break;
			}
		}

		if ( '' === $reason ) {
			throw new \RuntimeException(
				'An exempt FA(3) invoice must state the legal basis for the exemption (BT-120).'
			);
		}

		$block->p19  = TWybor1::VAL_1;
		$block->p19C = $reason;

		return $block;
	}

	/**
	 * Satır düzeyi KDV oranı.
	 *
	 * Kategori belirleyicidir. FA(3)'te %0, rejime gore uc ayri deger alir
	 * (yurt ici, AB ici teslim, ihracat) ve muafiyet ile tersine yuk ayri
	 * degerlerdir; yalnizca sayisal orana bakmak bunlari ayirt edemez.
	 *
	 * @param string $category UNTDID 5305 vergi kategorisi.
	 * @param float  $rate     Yüzde olarak oran.
	 * @return TStawkaPodatku
	 * @throws \RuntimeException Kategori ya da oran eşlenemezse.
	 */
	private function line_rate( string $category, float $rate ): TStawkaPodatku {
		$category = strtoupper( trim( $category ) );

		if ( 'S' !== $category ) {
			$case = self::CATEGORY_RATES[ $category ] ?? null;

			if ( null === $case ) {
				throw new \RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil.
					sprintf( 'FA(3) has no VAT rate for the tax category "%s".', $category )
				);
			}

			return constant( TStawkaPodatku::class . '::' . $case );
		}

		$key  = $this->rate_key( $rate );
		$case = self::STANDARD_RATES[ $key ] ?? null;

		if ( null === $case ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil.
				sprintf( 'FA(3) does not accept the VAT rate %s%%.', $key )
			);
		}

		return constant( TStawkaPodatku::class . '::' . $case );
	}

	/**
	 * Oranı eşleme anahtarına çevirir.
	 *
	 * @param float $rate Yüzde olarak oran.
	 * @return string
	 */
	private function rate_key( float $rate ): string {
		return rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * KDV numarasından NIP çıkarır.
	 *
	 * NIP on hanelidir ve ulke oneki tasimaz; "PL1234567890" gelirse onek
	 * atilir.
	 *
	 * @param string $vat_number KDV numarası.
	 * @return string
	 */
	private function nip( string $vat_number ): string {
		$digits = preg_replace( '/\D/', '', $vat_number );

		return is_string( $digits ) ? $digits : '';
	}

	/**
	 * Ülke kodunu FA(3) enum'una çevirir.
	 *
	 * @param string $country ISO 3166-1 alpha-2 kodu.
	 * @return TKodKraju
	 * @throws \RuntimeException Kod tanınmıyorsa.
	 */
	private function country( string $country ): TKodKraju {
		$code = TKodKraju::tryFrom( strtoupper( trim( $country ) ) );

		if ( null === $code ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil.
				sprintf( 'FA(3) does not recognise the country code "%s".', $country )
			);
		}

		return $code;
	}

	/**
	 * Para birimini FA(3) enum'una çevirir.
	 *
	 * @param string $currency ISO 4217 kodu.
	 * @return TKodWaluty
	 * @throws \RuntimeException Kod tanınmıyorsa.
	 */
	private function currency( string $currency ): TKodWaluty {
		$code = TKodWaluty::tryFrom( strtoupper( trim( $currency ) ) );

		if ( null === $code ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil.
				sprintf( 'FA(3) does not recognise the currency code "%s".', $currency )
			);
		}

		return $code;
	}

	/**
	 * Tutarı şemanın beklediği biçime getirir.
	 *
	 * @param float $amount Tutar.
	 * @return string
	 */
	private function amount( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	/**
	 * Miktarı şemanın beklediği biçime getirir.
	 *
	 * Tutarlarin aksine miktar kesirli olabilir (yarim saatlik hizmet gibi).
	 * Gereksiz sifirlar atilir; hepsi atilirsa geriye bos dizge kalmasin.
	 *
	 * @param float $quantity Miktar.
	 * @return string
	 */
	private function quantity( float $quantity ): string {
		$formatted = rtrim( rtrim( number_format( $quantity, 6, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	/**
	 * Belgeyi resmî XSD'ye karşı doğrular.
	 *
	 * Sema disi bir belge KSeF tarafindan reddedilir. Uretim asamasinda
	 * yakalamak, gonderimde reddedilmekten iyidir.
	 *
	 * @param \DOMDocument $document Belge.
	 * @return void
	 * @throws \RuntimeException Doğrulama başarısızsa.
	 */
	private function assert_valid( \DOMDocument $document ): void {
		$schema = dirname( __DIR__, 2 ) . '/vendor' . self::SCHEMA;

		if ( ! is_readable( $schema ) ) {
			$schema = dirname( __DIR__, 2 ) . '/vendor-prefixed' . self::SCHEMA;
		}

		if ( ! is_readable( $schema ) ) {
			throw new \RuntimeException( 'The FA(3) schema file is missing from the package.' );
		}

		$result = ( new XmlValidator( $schema ) )->validate( $document );

		if ( $result->isValid ) {
			return;
		}

		$messages = array();

		foreach ( array_slice( $result->errors, 0, 5 ) as $error ) {
			$messages[] = trim( (string) $error->message );
		}

		throw new \RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mesaj gunluge ve denetim izine gider, HTML'e degil.
			sprintf( 'The FA(3) document failed schema validation: %s', implode( '; ', $messages ) )
		);
	}
}
