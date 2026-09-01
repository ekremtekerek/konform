# ADR 0002 — İnsan tarafından okunan PDF'i kim üretir

- **Tarih:** 1 Eylül 2026
- **Durum:** Kabul edildi
- **Karar:** Mağazanın PDF eklentisi varsa o kullanılır; yoksa sınırları açıkça
  bildirilen yerleşik bir şablona düşülür

---

## Bağlam

Factur-X hibrit bir belgedir: aynı dosyada hem insanın okuduğu PDF hem makinenin
okuduğu XML bulunur. XML'i biz üretiyoruz. PDF'i kim üretecek?

`horstoeko/zugferd` bu soruyu cevaplamıyor —
`ZugferdDocumentPdfBuilder( $document, $pdfData )` **var olan bir PDF** alır ve
içine XML'i gömer. PDF'i çizmez.

Bu yalnızca Fransa için gerekli. Almanya'da XRechnung, Polonya'da KSeF saf XML
olarak iletilir; oralarda PDF üretmek gereksiz iştir.

## Değerlendirilen seçenekler

### A — Kendi UTF-8 PDF motorumuzu paketlemek

`mpdf` veya `TCPDF` eklemek. Her ikisi de tam Unicode destekler.

**Ret gerekçesi:** font dosyalarıyla birlikte 40–60 MB. WordPress.org'a gönderilen
bir eklentide bu ağırlık kabul edilemez ve kullanıcı fark eder. Üstelik ADR
0001'de bağımlılık ağırlığından kaçınmıştık; buraya geri dönmek tutarsız olurdu.

### B — PDF fatura eklentisi zorunlu kılmak

Sert bağımlılık. Ret gerekçesi: ücretsiz sürümün kurulur kurulmaz çalışması
gerekiyor; "önce şu eklentiyi kur" diyen bir ürün huniyi kırar.

### C — Var olanı kullan, yoksa sade şablona düş ✅

`PdfSource` arayüzü, öncelik sırasıyla denenen kaynaklar:

1. `WcpdfSource` — WooCommerce PDF Invoices & Packing Slips (100 binden fazla
   kurulum). Mağazanın kendi şablonunu, logosunu ve düzenini taşır; tam UTF-8.
2. `BuiltinPdfSource` — FPDF ile sade şablon. Son çare.

## Kabul edilen sınır: yerleşik şablon Latin-1'dir

`setasign/fpdf` yalnızca CP1252 destekler. Kod tabanında `iconv`, `mb_convert`
veya UTF-8 işleme **hiç yok** — kontrol edildi.

Bu, Fransızca ve Almanca metinler için yeterlidir (é, è, ç, ö, ü, ß hepsi
Latin-1'de). Lehçe, Çekçe, Yunanca ve Türkçe için yeterli **değildir**.

**Karakterleri sessizce kırpmıyoruz.** Alıcının adı faturada yanlış yazılırsa
belge hukuken kusurludur ve satıcı bunu asla fark etmez — ürünün her yerinde
kaçındığımız sessiz bozulmanın tam örneği. Bunun yerine `assert_representable()`
üretmeyi reddeder ve hangi alanın soruna yol açtığını söyler:

```
The built-in template cannot render "buyer name" because it contains
characters outside Latin-1. Install a PDF invoice plugin for full
Unicode support.
```

Kullanıcı için çözüm tek adımdır ve zaten ücretsizdir.

## Sonuçlar

- ✅ Sürüm 1 hedefi (FR Factur-X) yerleşik şablonla karşılanıyor.
- ✅ Eklenti ağırlığı artmıyor; ek bağımlılık yok.
- ✅ Mağazanın kendi fatura tasarımı korunuyor — kimsenin şablonunu ezmiyoruz.
- ⚠️ Latin-1 dışı alıcı adı olan Fransız mağazası yerleşik şablonu kullanamaz.
  Ön uçuş raporunda bunu önceden uyarmak **yapılacaklar listesinde**.
- ℹ️ Almanya ve Polonya bu ADR'den etkilenmez; saf XML üretilir.
- ℹ️ Talep gelirse bir sonraki adım `wp-content` içine indirilen isteğe bağlı
  bir Unicode font paketi olabilir; eklenti zip'ini şişirmez.

## Ek bulgu: Strauss varlıkları kopyalamıyor

Bu ADR'yi uygularken ortaya çıktı — Strauss `fpdf.php`'yi kopyaladı ama
`font/` dizinini kopyalamadı ve kütüphane çalışma anında
`Could not include font definition file` ile patladı.

`.yml` metadata dosyalarında yaşananla aynı aileden: **Strauss paketin kodunu
taşır, varlıklarını değil.** `bin/post-strauss.php` artık tek tek dosya
listelemek yerine, öneksiz pakette olup önekli pakette olmayan her şeyi
aynalıyor.
