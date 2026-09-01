# Konform

**EU E-Invoicing for WooCommerce** — WooCommerce siparişlerini satıcının ülkesinde
yasal geçerli e-faturaya çevirir ve satıcının kendi sağlayıcısına teslim eder.

| | |
|---|---|
| Durum | Faz 1 — ön uçuş kontrolü çalışıyor |
| Sürüm | `0.1.0-dev` |
| Gereksinim | PHP 8.2+, WordPress 6.5+, WooCommerce |
| Lisans | GPL-2.0-or-later |

---

## Depo düzeni

```
konform/
├── plugin/konform/        WordPress eklentisi
│   ├── konform.php        Ana dosya, başlıklar, önyükleme
│   ├── uninstall.php      Kaldırma (arşiv korunur)
│   ├── src/
│   │   ├── Plugin.php     Yaşam döngüsü ve kanca kayıtları
│   │   └── I18n/
│   │       ├── Locale.php    Üç dil ekseni  ← docs/I18N.md
│   │       └── CodeList.php  EN 16931 kod listeleri
│   ├── languages/         .pot ve çeviriler
│   └── tests/
├── worker/                Doğrulama servisi (Faz 3, Cloudflare)
├── docs/
│   ├── SPEC.md            Ürün spesifikasyonu ve yol haritası
│   ├── I18N.md            Dil mimarisi — kod yazmadan önce oku
│   └── adr/               Mimari kararlar
└── docker-compose.yml
```

## Geliştirme ortamı

Makinede PHP ve Composer **kurulu değil** ve yönetici yetkisi yok — her şey
Docker içinde çalışır.

```bash
cp .env.example .env
bash bin/setup.sh
```

Kurulum bitince: <http://localhost:8080/wp-admin> — `admin` / `admin`

### Sık kullanılan komutlar

```bash
# Baslat / durdur
docker compose up -d
docker compose down

# WP-CLI  (servis adindan sonra 'wp' tekrar yazilir)
docker compose run --rm wpcli wp plugin list
docker compose run --rm wpcli wp option get woocommerce_default_country

# Composer  (dikkat: servis adindan sonra 'composer' tekrar yazilir)
docker compose run --rm composer composer install
docker compose run --rm composer composer require <paket>

# Bagimlilik duman testi - WordPress gerektirmez
docker compose run --rm composer php tests/smoke.php

# Kod standardi
docker compose run --rm composer composer lint
docker compose run --rm composer composer lint:fix
```

### Çeviri dosyaları

```bash
docker compose run --rm wpcli wp i18n make-pot \
  wp-content/plugins/konform \
  wp-content/plugins/konform/languages/konform.pot \
  --slug=konform --domain=konform
```

---

## Bilinmesi gerekenler

### 1. "Dil" burada üç ayrı şeydir

Bu eklentide en pahalı hata, üç dil eksenini birbirine karıştırmaktır:

| Eksen | Kim okur | Kaynak |
|---|---|---|
| **UI dili** | Mağaza sahibi | `Locale::admin()` |
| **Belge dili** | **Alıcı** | `Locale::document( $order )` |
| **Regülasyon profili** | Vergi idaresi | `Locale::regulatory_profile()` |

Fatura, yöneticinin değil **alıcının** dilinde üretilir. Kod tabanında
`switch_to_locale()` çağrılmasına izin verilen tek yer `Locale::render()`.

Tam kurallar: [`docs/I18N.md`](docs/I18N.md) — kod yazmadan önce okunmalı.

### 2. Kod listeleri çevrilmez

EN 16931 kodları (`S`, `AE`, `380`, `EUR`, `C62`) kanoniktir. XML'e daima kodun
kendisi yazılır; çevrilen yalnızca ekrandaki etikettir.

```php
$xml_value = 'AE';                                    // her zaman
$screen    = CodeList::label( 'tax_category', 'AE' ); // kullanicinin dilinde
```

### 3. Bağımlılıklar izole edilmiştir

Üretim bağımlılıkları — `jms/serializer`, `symfony/*`, `setasign/fpdf` ve 18 paket
daha — Strauss ile `Konform\Vendor\` altına taşınır. İzole edilmeselerdi aynı
kütüphaneyi farklı sürümde paketleyen başka bir eklentiyle çakışıp siteyi
çökertirlerdi.

```bash
docker compose run --rm composer composer strauss
```

Bu komut üç iş yapar: Strauss'u çalıştırır, `bin/post-strauss.php` ile YAML
metadata dosyalarını önekleyip öneksiz kopyaları siler, sonra autoload'ı yeniden
üretir.

İkinci adım şart: Strauss yalnızca `.php` dosyalarını işler, ama zugferd sınıf
eşlemelerini 399 adet `.yml` dosyasında taşır ve `jms/serializer` bu anahtarların
gerçek sınıf adlarıyla birebir eşleşmesini bekler.

`vendor-prefixed/` bir **yapı artefaktıdır** — depoya girmez, bu komutla yeniden
üretilir.

⚠️ Windows bind mount üzerinde öneksiz paketlerin silinmesi bazen başarısız olur.
Betik hangilerinin kaldığını söyler; kabuktan `rm -rf` ile temizleyin. Linux'ta
(CI) bu sorun yaşanmaz.

Gerekçe: [`docs/adr/0001-e-fatura-kutuphanesi.md`](docs/adr/0001-e-fatura-kutuphanesi.md)

---

## Yol haritası

| Faz | Hafta | Çıktı |
|---|---|---|
| **0** | 1–2 | Ortam ve iskelet — *tamamlandı* |
| **1** | 3–5 | Ön uçuş kontrolü ve EN 16931 eşleyici — *tamamlandı* |
| 2 | 6–8 | Factur-X / XRechnung üretimi, PDF/A-3, arşiv — *izolasyon tamam* |
| 3 | 9–10 | Barındırılan doğrulama servisi (Cloudflare + Saxon-JS) |
| 4 | 11–12 | Freemius paketleme, WordPress.org gönderimi |

Ayrıntı: [`docs/SPEC.md`](docs/SPEC.md)
