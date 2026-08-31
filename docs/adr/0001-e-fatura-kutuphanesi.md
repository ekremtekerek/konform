# ADR 0001 — E-fatura üretim kütüphanesi

- **Tarih:** 31 Ağustos 2026
- **Durum:** Kabul edildi
- **Karar:** `horstoeko/zugferd` (`^1.0.132`), kendi arayüzümüzün arkasında

---

## Bağlam

Faz 2'de Factur-X (FR) ve XRechnung / ZUGFeRD (DE) üretmemiz gerekiyor. Bu
formatların XML şemalarını sıfırdan yazmak haftalar alır ve hata payı yüksektir,
dolayısıyla hazır bir PHP kütüphanesi kullanılacak.

İki gerçek aday var; ikisi de aynı geliştiriciye ait:

| | `horstoeko/invoicesuite` | `horstoeko/zugferd` |
|---|---|---|
| En son sürüm | `v0.0.27` | `v1.0.132` |
| Sürüm geçmişi | 27 sürüm, **tamamı `0.0.x`** | 130+ kararlı `1.0.x` sürümü |
| PHP tabanı | `>= 8.2` | `>= 7.3` |
| Kapsam | ZUGFeRD, Factur-X, XRechnung + UBL + özel formatlar | ZUGFeRD, Factur-X, XRechnung (CII) |
| Ağır bağımlılıklar | `mpdf/mpdf ^8`, `ext-gd` | `jms/serializer`, `xsd2php-runtime` |
| Bakım durumu | Geliştiricinin belirttiği devam yolu | Olgun, hâlâ sürüm alıyor |

## Değerlendirme

İlk eğilim `invoicesuite` yönündeydi; gerekçe "bağımlılığı çok hafif, yalnızca
`ext-dom`" idi. **Bu gerekçe yanlış çıktı.** Kurulum denemesi paketin
`mpdf/mpdf ^8` ve dolayısıyla `ext-gd` çektiğini gösterdi. mpdf, WordPress
ekosisteminde birden fazla eklenti tarafından paketlenen tipik bir çakışma
kaynağıdır — yani hafiflik avantajı hiç yoktu.

Bağımlılık ağırlığı iki adayda da yüksek olduğu için, **her iki durumda da
namespace izolasyonu (php-scoper / Strauss) zorunlu.** Bu da çakışma riskini
karar ekseni olmaktan çıkarıyor: ikisi de aynı şekilde izole edilecek.

Geriye tek belirleyici eksen kalıyor: **API kararlılığı.**

Uyumluluk ürününün çekirdeğinde `0.0.x` sürüm numarasıyla ilerleyen bir paket
bulundurmak, tek kişilik bir ekip için sürekli bir bakım vergisidir. 27 sürümün
tamamının `0.0.x` olması, kırıcı değişikliklerin normal kabul edildiğini gösterir.

Ayrıca sürüm 1 kapsamımız (FR Factur-X + DE XRechnung) `zugferd`'in tam olarak
olgunlaştığı alandır. `invoicesuite`'in ek getirisi olan UBL ve özel format
desteği ancak v2'de (IT, ES, Peppol BIS) anlam kazanır.

## Karar

`horstoeko/zugferd` `^1.0.132` kullanılacak.

Kütüphane **doğrudan çağrılmayacak**; `Konform\Invoice\DocumentBuilder` arayüzünün
arkasına alınacak. Böylece:

- v2'de `invoicesuite`'e (o zamana kadar muhtemelen 1.0 olmuş olur) geçmek tek bir
  adaptör sınıfını değiştirmek demek olur,
- iş mantığımız üçüncü taraf API'sine sızmaz,
- birim testleri kütüphane olmadan sahte adaptörle çalışabilir.

## Sonuçlar

- ✅ Kararlı, savaşta denenmiş bir çekirdek.
- ✅ Sürüm 1 hedefleri (FR + DE) birebir kapsanıyor.
- ⚠️ Faz 2 bitmeden **php-scoper / Strauss kurulumu zorunlu.** `jms/serializer`
  izole edilmezse başka bir eklentiyle çakışıp siteyi çökertir. Bu, sürüm 1
  çıkışının engelleyici maddesidir.
- ⚠️ UBL çıktısı gerektiğinde (Peppol BIS, v3) yeniden değerlendirilecek.
- ℹ️ `zugferd` PHP 7.3+ desteklese de **PHP tabanımız 8.2**. Gerekçe: PHP 8.1'in
  güvenlik desteği Aralık 2025'te bitti; güvenlik desteği olmayan bir PHP
  sürümünde çalışan bir *uyumluluk* ürünü kendi içinde çelişkilidir.

## Doğrulama

`plugin/konform/tests/smoke.php` bağımlılığın çalıştığını kanıtlıyor:

```
profil       : EN 16931
xml uzunlugu : 1577 bayt
kok eleman   : CrossIndustryInvoice
para birimi  : EUR bulundu
SONUC: gecti
```
