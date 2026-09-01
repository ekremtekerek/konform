# 0004 — Ücretsiz/Pro ayrımı yalnızca dış servis üzerinden yapılır

Tarih: 1 Eylül 2026
Durum: Kabul edildi

## Bağlam

Eklenti üç şeyi planla ayırıyordu:

1. `has_hosted_validation()` — resmî EN 16931 doğrulaması
2. `has_automatic_generation()` — sipariş tamamlanınca kendiliğinden üretim
3. `preflight_limit()` — ücretsizde 50, Pro'da 1000 sipariş

WordPress.org gönderim formu, kabul edilmesi zorunlu bir beyan içeriyor:

> I confirm that my plugin code does not include artificial limitations to the
> included functionality. I acknowledge that I must comply with this in future,
> and that my plugin and account could be suspended indefinitely if I fail to
> do so.

Kural metni paywall'ı, **lisans/özellik kapısını**, kullanım sınırlarını ve
"eklentinin kodunda bulunan işlevi kısıtlayan" her mekanizmayı yasaklıyor.
Etrafında iş kurmak serbest; kodun içine yapay sınır koymak değil.

2 ve 3 tam olarak bu tanıma giriyordu. Üretim kodu eklentide baştan sona
mevcut; onu kapatan tek şey lisanstı. Tarama sınırının performans gerekçesi
gerçekti, ama plana bağlanmış olması onu kullanım sınırı hâline getiriyordu.

Yaptırım da sıradan değil: eklenti **ve hesap** süresiz askıya alınabiliyor.

## Karar

Planla ayrılan tek şey **barındırılan doğrulama servisi** olacak.

Ayrılabilmesinin sebebi, eklentinin bunu zaten kendi başına yapamaması:
kural seti XSLT 2.0'a derlenir, PHP'nin `ext-xsl` uzantısı XSLT 1.0'da kalır
(bkz. [ADR 0003](0003-dogrulama-calisma-ortami.md)). Yani kapatılan şey
"kodda olan bir işlev" değil, dışarıda işletilen ve gerçek maliyeti olan bir
servise erişim. Kuralın yasakladığı yapay sınır bu değildir.

Buna göre:

- Otomatik üretim **her planda** çalışır. `has_automatic_generation()`
  kaldırıldı.
- Tarama sınırı plandan koparıldı. Tek bir `PREFLIGHT_LIMIT` var; gerekçesi
  fiyatlandırma değil, taramanın bir yönetici isteği içinde çalışması.
  `konform/preflight_limit` kancasıyla değiştirilebilir.
- `has_hosted_validation()` kalır.

## Sonuçları

**Pro'nun satış gerekçesi tek maddeye iner:** belge kesilmeden önce resmî
EN 16931 kural setine karşı doğrulama. Uyumluluk ürününde en değerli sorunun
—"bu fatura reddedilir mi"— cevabı bu olduğu için yeterli görülüyor.

Sonradan eklenecek Peppol/KSeF iletimi de dış servis olduğu için aynı
gerekçeyle satılabilir; yol haritası bu yönde daralmıyor.

**Ücretsiz sürüm gerçekten kullanışlı hâle geliyor.** `readme.txt` zaten
"Not a crippled demo" diyordu; artık bu cümle tam olarak doğru.

**Bu kapılar geri konulamaz.** WordPress.org'da yayınlanan sürüm bu beyana
tabidir ve beyan gelecek sürümleri de kapsar. Freemius'tan dağıtılan Pro
paketi ek özellik taşıyabilir, ama .org'daki koda lisans kapısı eklemek
eklentiyi ve hesabı riske atar.

## Ek: dördüncü kapı

İlk taramada üç kapı sayılmıştı. Kod tabanı baştan sona tarandığında bir
dördüncüsü çıktı: `EmailDelivery::attach()`, üretilen belgeyi WooCommerce'in
müşteriye gönderdiği e-postaya eklemeyi Pro'ya bağlıyordu. Bu da tamamen
eklenti kodunda duran bir işlev; kaldırıldı.

Ders: "birkaç yerde kapı var" diye çalışmak yetmiyor. Kalan tek plan koşulu
`Licensing::has_hosted_validation()` ve onu çağıran iki yer
(`HostedValidator`, ayar kutusunu gösteren `PreflightPage`).
