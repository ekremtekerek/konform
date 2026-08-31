# Konform — Ürün Spesifikasyonu

> WooCommerce siparişini, satıcının ülkesinde yasal geçerli e-faturaya çeviren ve
> satıcının kendi sağlayıcısına teslim eden köprü.

| | |
|---|---|
| **Taslak** | 0.1 |
| **Tarih** | 31 Ağustos 2026 |
| **Durum** | Yön seçildi, inşaya hazır |
| **Ekip** | 1 kişi, tam zamanlı |
| **Kanal** | WordPress.org (ücretsiz) + Freemius (ücretli) |
| **İlk sürüm hedefi** | 12 hafta |
| **Çalışma adı** | Konform — değiştirilebilir |

---

## 01 · Neden bu, neden şimdi

Avrupa e-faturayı 2026–2027 arasında zorunlu hale getiriyor. Zorunluluk, yazılımda
satın alınabilecek en güvenilir taleptir: müşteri "güzel olurdu" diye değil,
"yoksa faturam geçersiz" diye öder.

- **Fransa:** 1 Eylül 2026'dan itibaren her KDV mükellefi e-fatura *alabilmek*
  zorunda. Kesme yükümlülüğü büyük ve orta ölçekte aynı tarihte; küçük ve mikro
  işletmelerde **1 Eylül 2027**. Tipik bir WooCommerce satıcısı bu son gruba
  düşüyor — yani gerçek hedef tarihimize tam **12 ay** var. Ürünü inşa edip pazara
  sokmak için ideal pencere.
- **Polonya:** KSeF zorunluluğu Şubat 2026'da büyük mükelleflerle başladı, Nisan
  2026'da neredeyse tüm KDV mükelleflerini kapsadı. Mikro işletmeler Ocak 2027.
- **Almanya:** XRechnung ve ZUGFeRD zaten yürürlükte, EN 16931 uyumlu.

**Kritik bulgu:** Araştırmada DE + FR + PL üçünü aynı anda kapsayan tek bir
WooCommerce eklentisi bulunamadı.

### Konumlandırma

Tek özellik satmıyoruz. **Regülasyonlar geldikçe büyüyen bir uyumluluk katmanı**
satıyoruz — bu, aboneliğin her yıl yenilenmesi için kalıcı bir gerekçe demek.

Müşteri sadece AB'li de değil: AB'ye satan ABD, İngiltere ve Türkiye mağazaları da
alıcı. Pazar gerçekten global.

---

## 02 · Araştırmanın değiştirdiği üç şey

İlk plandan üç sapma. İkisi kapsam kesti, biri mimariyi yeniden yazdı.

### ❌ GPSR kama olamaz — kapsamdan çıktı

Ürün güvenliği bilgisi alanında zaten en az dört WooCommerce eklentisi var:
Euverify, GPSR for WooCommerce, EuroSafe (CodeCanyon), wp-woo-plugins. Alan
emtialaşmış. Ücretsiz sürümde bir onay listesi maddesi olarak kalabilir, ama
ürünün konumlandırması olamaz.

### ⚠️ Peppol ağına kendin girme — mimari değişti

Storecove gibi erişim noktası sağlayıcıları **~€495/ay**'dan başlıyor. Tek kişilik
bir üründe bu maliyet ve getirdiği operatör sorumluluğu kabul edilemez.

**Çözüm: ağ operatörü olma, köprü ol.** Satıcı kendi PDP / Peppol sağlayıcısını
getirir (BYO-PDP), biz uyumlu dosyayı üretip onun API'sine iteriz.

### ✅ Doğrulama süreç içinde yapılamaz — hendek bulundu

EN 16931 ve KoSIT kural setleri XSLT 2.0'a derleniyor; PHP'nin `ext-xsl` uzantısı
libxslt'yi sarmalıyor ve **XSLT 1.0'da kalıyor**. Yani resmi doğrulama eklentinin
içinde çalıştırılamaz. Kütüphane seviyesindeki kontroller yalnızca yapısaldır
(şema ve tamlık), alıcının dayattığı 200+ iş kuralı değil.

Kısıt gibi görünüyor — aslında ürünün en değerli parçası. Bkz. bölüm 06.

---

## 03 · Kama: sipariş verisi zaten bozuk

Rakiplerin hepsi "XML üretiyoruz" diyor. Kimsenin söylemediği şey: **WooCommerce
sipariş verisi EN 16931 için yeterince temiz değil** ve satıcı bunu ancak fatura
reddedildiğinde öğreniyor.

Gerçek dünyada bozulan yerler:

- Eksik veya doğrulanmamış KDV numarası
- Yanlış vergi kategorisi kodu
- B2B / B2C ayrımının hiç yapılmamış olması
- Reverse charge senaryoları
- OSS / IOSS eşiği
- Kargo ve indirimlerin vergi tabanına yanlış dağıtılması
- Kuruş yuvarlama farkları

### Ürünün açılış hamlesi

Geçmiş siparişleri tarayan bir **ön uçuş kontrolü**:

> "Son 500 siparişinin 47'si reddedilirdi. Sebebi şu, düzeltmesi şurada."

Değer ilk 60 saniyede görünür, kurulum gerektirmez, ücretsiz sürümün kancası olur.

Bu aynı zamanda **en iyi destek yükü azaltıcı**: müşteri veriyi baştan düzeltirse,
üretim aşamasında sana ticket açmaz.

---

## 04 · Boru hattı

```
[01] Sipariş            WooCommerce siparişi tamamlanır, kuyruğa alınır
      ↓
[02] Anlamsal eşleme    EN 16931 veri modeline çevrim              ★ değer
      ↓                 Vergi kategorileri, reverse charge, OSS
      ↓
[03] Sözdizimi          Factur-X, XRechnung, FA(3), UBL 2.1, CII
      ↓
[04] Doğrulama          Resmi Schematron kural seti                ★ değer
      ↓                 Barındırılan servis (XSLT 2.0)
      ↓
[05] Teslim             İndir · e-postaya ekle · satıcının PDP'sine it
      ↓
[06] Arşiv              10 yıl saklama + değiştirilemez denetim izi
```

Değerin çoğu **02** ve **04**'te — ikisi de kopyalanması en zor olanlar.

---

## 05 · Ülke ve format matrisi

Sürüm 1 yalnızca üstteki satırları hedefliyor. Alt sıradakiler **doğrulanmadı** —
yol haritasına almadan önce kaynaktan teyit edilecek.

| Ülke | Format | Yükümlülük | Tarih | Durum | Sürüm |
|---|---|---|---|---|---|
| FR | Factur-X, UBL 2.1, CII | Alma — tüm işletmeler | 2026-09-01 | Yürürlükte | v1 |
| FR | Factur-X, UBL 2.1, CII | Kesme — küçük ve mikro | 2027-09-01 | **Ana hedef** | v1 |
| DE | XRechnung, ZUGFeRD | EN 16931 uyumlu | Yürürlükte | Yürürlükte | v1 |
| PL | FA(3) XML / KSeF | Tüm KDV mükellefleri | 2026-04 | Yürürlükte | v1.1 |
| PL | FA(3) XML / KSeF | Mikro işletmeler | 2027-01 | Yaklaşıyor | v1.1 |
| IT | FatturaPA | Teyit edilecek | — | ⚠ Doğrulanmadı | v2 |
| ES | Facturae / Verifactu | Teyit edilecek | — | ⚠ Doğrulanmadı | v2 |
| EU | Peppol BIS / ViDA | Birlik geneli | 2030 | Uzak | v3 |

---

## 06 · Hendek: neden üç ayda klonlanamaz

Her WordPress eklentisi null'lanabilir. Bu yüzden savunma kodda değil, **kodun
dışında** olmalı.

1. **Barındırılan doğrulama servisi.** Resmi Schematron kural setleri XSLT 2.0
   gerektirdiği için PHP içinde çalışamaz — zorunlu olarak sunucu tarafında.
   Null'lanmış kopya doğrulama yapamaz, yani işe yaramaz. *Kısıtın kendisi lisans
   korumasına dönüşüyor.*
2. **Kural seti bakımı.** 27 ülke, her yıl değişen şemalar ve iş kuralları. Tek
   seferlik kopyalayan altı ay sonra bozulur. Hem hendek hem yenileme gerekçesi.
3. **Eşleme mantığının derinliği.** Reverse charge, OSS/IOSS, karma KDV oranları,
   kargo dağıtımı, yuvarlama. Rakiplerin "XML üretiyoruz" dediği yer aslında işin
   en kolay yüzde onu.
4. **Zorluk bir özellik.** WordPress.org'a 2025'te 6.162 eklenti eklendi ve
   analizler çoğunun tekrar olduğunu gösteriyor. Kolay olan her şey zaten elli
   kere yapıldı — bu iş kolay olmadığı için değerli.

---

## 07 · Paketleme ve fiyat

Ücretsiz sürüm **WordPress.org'da olmak zorunda** — dağıtım kanalı orası.
Freemius kesintisi satış başına yaklaşık **%10,5** (%7 platform + ödeme geçidi);
aylık 50 bin doların üstünde kademeli olarak düşüyor, 100 bin doların üstünde
%0,5'e kadar iniyor. KDV üzerinden komisyon alınmıyor, payout ücreti yok.

### Ücretsiz · WordPress.org — €0

- Ön uçuş kontrolü, son 50 sipariş
- Tek ülke, manuel indirme
- Temel EN 16931 eşlemesi
- GPSR alanları

### Pro — €149 / yıl

- Üç ülke, sınırsız ön uçuş
- Barındırılan resmi doğrulama
- Otomatik üretim ve e-posta teslimi
- 10 yıllık arşiv ve denetim izi
- PDF/A-3 gömülü hibrit fatura

### Agency — €399 / yıl

- Tüm desteklenen ülkeler
- 5 site lisansı
- PDP / Peppol sağlayıcı API teslimi
- WP-CLI ve toplu işlem
- Öncelikli destek

---

## 08 · 12 haftalık yol haritası

Tam zamanlı tek kişi varsayımı. Her fazın sonunda elle tutulur bir çıktı var —
hiçbir faz "altyapı hazırlığı" diye bitmiyor.

### Faz 0 · Hafta 1–2 — Ortam ve iskelet

Docker tabanlı WordPress + WooCommerce geliştirme ortamı. Makinede PHP kurulu
değil ve yönetici yetkisi yok, dolayısıyla her şey konteyner içinde. Composer ile
`horstoeko/invoicesuite` entegrasyonu, eklenti iskeleti, Freemius SDK'sı en baştan
bağlanır.

> **Çıktı:** Yerelde çalışan, lisans kontrolü yapan boş eklenti

### Faz 1 · Hafta 3–5 — Ön uçuş kontrolü ve eşleyici

Sipariş → EN 16931 anlamsal model eşlemesi ve bu eşlemenin nerede kırıldığını
raporlayan tarayıcı. Ücretsiz sürümün tamamı burada bitiyor. Bu faz ürünün kalbi;
acele edilirse geri kalan her şey çürük temele oturur.

> **Çıktı:** WordPress.org'a gönderilebilir ücretsiz sürüm

### Faz 2 · Hafta 6–8 — Üretim ve arşiv

Factur-X ve XRechnung üretimi, PDF/A-3 içine XML gömme, Action Scheduler ile
kuyruklu toplu işleme, arşiv ve denetim izi. Kuyruk tablosunun sınırsız şişmesine
karşı en baştan budama politikası yazılır.

> **Çıktı:** FR ve DE için uçtan uca çalışan fatura üretimi

### Faz 3 · Hafta 9–10 — Doğrulama servisi

Resmi Schematron kural setlerini çalıştıran barındırılan servis. XSLT 2.0
gereksinimi PHP'de karşılanamadığı için Cloudflare Worker üzerinde Saxon-JS ile
çözülür; kural setleri KV'de önbelleklenir. Ürünün lisans koruması da fiilen
buradan gelir.

> **Çıktı:** Pro sürümün satılabilir hale geldiği an

### Faz 4 · Hafta 11–12 — Paketleme ve çıkış

Freemius fiyat planları ve feature gating, WordPress.org gönderimi ve inceleme
süreci, dokümantasyon, tanıtım sayfası. Fransa'nın Eylül 2027 tarihine 12 ay kala
pazara girilmiş olur.

> **Çıktı:** Satışa açık ürün

---

## 09 · Teknik yığın

### Eklenti

PHP 8.1+, tipli kod. `horstoeko/invoicesuite` — ZUGFeRD, Factur-X ve XRechnung'u
hem CII hem UBL sözdiziminde üretiyor ve eski `horstoeko/zugferd`'in resmi devamı;
yeni projeler için önerilen paket bu. Ağır iş Action Scheduler kuyruğunda, istek
içinde değil.

### Geliştirme ortamı

Docker. Makinede PHP ve Composer yok, yönetici yetkisi de yok — bu yüzden
WordPress, WooCommerce, PHP ve Composer'ın tamamı konteynerde. Docker veri diski
zaten D:'de duruyor, C:'de yer sorunu çıkmaz.

### Doğrulama servisi

Cloudflare Worker + Saxon-JS ile XSLT 2.0 Schematron çalıştırma, kural setleri
için KV önbelleği. Aylık maliyeti neredeyse sıfır — Peppol erişim noktasının
€495/ay'ının yanında.

### Kalite kapısı

GitHub Actions üzerinde PHPUnit + WordPress test paketi, PHPCS `WordPress-Extra`.
Her girdi `sanitize_*`, her çıktı `esc_*`, tüm REST ve AJAX uçlarında nonce ve
`current_user_can`. Temiz kaldırma (uninstall), çoklu site uyumu, tam i18n.

---

## 10 · Gelir beklentisi

Muhafazakâr model. Ücretsizden ücretliye dönüşüm %1,5–2 varsayıldı; iyi bir
eklenti için gerçekçi bir bant.

| Zaman | Aktif kurulum | Dönüşüm | Müşteri | Brüt yıllık |
|---|---|---|---|---|
| Ay 6 | 2.000 | 1,5% | 30 | ≈ €4.500 |
| Ay 18 | 15.000 | 2,0% | 300 | ≈ €50.000 |
| Ay 36 | 40.000 | 2,0% | 800+ | ≈ €150–200.000 |

Bu rakamlardan Freemius payı olarak yaklaşık %10,5 düşecek.

Tavanın nerede olduğunu görmek için: Yoast SEO aylık ~2,9 milyon dolar, Elementor
~7,2 milyon dolar ciro yapıyor. WooCommerce eklentileri ortalama en yüksek fiyat
bandında ($61+) çünkü doğrudan gelire bağlılar.

---

## 11 · Bunu öldürebilecek şeyler

### ⚠️ Regülasyon ertelenir — orta risk

Fransa e-fatura takvimi daha önce ertelendi, tekrar ertelenebilir.
**Savunma:** tek ülkeye bağlanma. Ücretsiz sürümün değeri — ön uçuş kontrolü —
hiçbir tarihe bağlı olmamalı.

### ⚠️ WooCommerce çekirdeği yutar — orta risk

Woo, MCP desteğini 10.9 ile çekirdeğe aldı; e-faturayı da alabilir.
**Savunma:** çekirdek asla ülke derinliğine inmez. Değerimiz jenerik XML'de değil,
27 ülkenin kural setinde.

### 🔴 Hukuki sorumluluk — yüksek risk

"Uyumluluğu garanti ederiz" denemez.
**Savunma:** konum net olmalı — biz standarda uygun belge üretir ve doğrularız,
yasal onay satıcının PDP'sinden gelir. EULA ve pazarlama dilinde bu sınır baştan
çizilir.

### ⚠️ Destek yükü — orta risk

Muhasebe konuları ticket üretir.
**Savunma:** ön uçuş kontrolünün hata mesajları, ticket'ın yerine geçecek kadar
açıklayıcı yazılmalı — "geçersiz" değil, "şu alan şu yüzden eksik, şuradan
düzelt".

---

## Kaynaklar

- Freemius fiyatlandırma — https://freemius.com/wordpress/pricing/
- horstoeko/invoicesuite — https://packagist.org/packages/horstoeko/invoicesuite
- horstoeko/zugferd — https://github.com/horstoeko/zugferd
- Fransa e-fatura rehberi (Avalara) — https://www.avalara.com/blog/en/europe/2026/07/french-e-invoicing-mandate-readiness.html
- Polonya KSeF (Avrupa Komisyonu) — https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/467108896/eInvoicing+in+Poland
- AB e-fatura takvimleri — https://www.invoicenavigator.eu/deadlines
- Storecove Peppol erişim noktası — https://www.storecove.com/us/en/solutions/peppol-access-point/
- WP eklenti pazarı fiyat analizi — https://plugintheme.net/blog/wordpress-plugin-theme-market-2026-pricing-trends-data-analysis

---

**Sonraki adım:** Faz 0 — Docker ortamı ve eklenti iskeleti.
