# Sürüm çıkarma

Bir sürümü elle toplamak hata üretir; sıra aşağıdaki gibidir ve her adımın bir
doğrulaması vardır.

---

## 1. Sürüm numarası

Üç yerde aynı olmalı:

| Yer | Alan |
|---|---|
| `plugin/konform/konform.php` | `Version:` başlığı |
| `plugin/konform/konform.php` | `const VERSION` |
| `plugin/konform/readme.txt` | `Stable tag:` |

`readme.txt` içindeki `== Changelog ==` bölümüne de bu sürüm girilir.
`build.sh` sürümü `Version:` başlığından okur; diğer ikisi tutmazsa hiçbir şey
patlamaz ama WordPress ve Freemius farklı sürüm görür.

---

## 2. Paketi üret

**İki ayrı paket üretilir ve karıştırılmamalıdır.**

```sh
bash bin/build.sh             # ücretsiz  -> WordPress.org
bash bin/build.sh --premium   # ücretli   -> Freemius
```

Üretilenler: `build/konform-<sürüm>.zip` ve `build/konform-<sürüm>-premium.zip`,
her ikisinin kökünde `konform/` dizini ile.

### Neden iki paket

Tek fark Freemius SDK'sının `is_premium` bayrağıdır.

**Bu bayrak bir özellik kapısı DEĞİLDİR.** Önce öyle sanıldı, ölçümle
düzeltildi. SDK'nın tanımı şu:

```php
function can_use_premium_code() {
    return $this->is_trial() || $this->has_features_enabled_license();
}
```

`is_premium`'a hiç bakmıyor. Yerel kurulumda ölçüldü: `is_premium => false`
olan yapıda, lisans varken `can_use_premium_code()` **true** dönüyor ve
`Licensing::plan()` **pro** veriyor.

Bayrağın işi, çalışan yapının hangisi olduğunu işaretlemektir. SDK güncelleme
isteğinde `is_premium() || _can_download_premium()` diye bakar ve müşterinin
indirdiği ürün premium pakettir. Freemius'a yüklenen zip bu yüzden premium
olmalıdır; aksi hâlde sürümler ücretsiz yapı olarak dağıtılır.

**Asıl risk başkaydı ve gerçekti:** satış bir kez açıldığında Deployment
tamamen boştu ("No deployments yet"). Ödeme yapan kişinin indirebileceği
hiçbir paket yoktu. Satışı kapatmayı gerektiren buydu.

Bayrak yalnızca hazırlık dizinindeki kopyada açılır; depodaki kaynak ücretsiz
sürümdür ve öyle kalır. `sed` tutmazsa betik hata verip durur — sessizce
ücretsiz paket üretip premium diye yüklemez.

`build.sh` artık `build/` dizininin tamamını değil yalnızca hazırlık dizinini
siler; aksi hâlde ikinci varyant üretilirken birincinin zip'i uçardı.

Betik sırayla: kaynağı kopyalar (testler ve geliştirme dosyaları hariç),
bağımlılıkları kurar, Strauss ile `Konform\Vendor\` altına önekler,
`post-strauss.php` ile meta verileri ve varlıkları düzeltir, geliştirme
paketlerini siler, arşivler.

Strauss dört bin dosya işler; adım dakikalar sürer, takılmış değildir.

---

## 3. Paketi denetle

Bunlar CI'nin göremediği, yalnızca üretilmiş pakette görülebilen şeylerdir.

### Önce: paket eksiksiz mi

**Bu denetim atlanamaz.** Windows'ta bind mount, PHP'nin dizin yineleyicisinde
büyük dizinleri eksik döndürüyor; Strauss sınıf dosyalarını sessizce atlayabilir
— hata vermeden, `exit 0` dönerek. `Konform\Vendor\*` sınıflarının psr-4
karşılığı olmadığı, yalnızca classmap'ten çözüldükleri için eksik bir dosya
doğrudan çalışma anında ölümcül hatadır.
Ayrıntı: [ADR 0007](adr/0007-bind-mount-dosya-kaybi.md).

Denetimin kendisi bind mount üzerinde çalıştırılırsa aynı tuzağa düşer (ilk
denemede sınıfların 909'unu saydı, gerçek sayı 959'du). Bu yüzden paket önce
konteyner içi diske kopyalanır:

```sh
rm -rf build/verify && mkdir -p build/verify
( cd build/verify && unzip -q ../konform-*.zip )

docker compose run --rm -T composer sh -c "
  rm -rf /tmp/pkg && mkdir -p /tmp/pkg
  cp -r /repo/build/verify/konform /tmp/pkg/konform
  php /repo/bin/verify-classmap.php /tmp/pkg/konform
"
# "EKSIK: 0" beklenir. Aksi halde surum cikarilmaz.
```

```sh
# Bagimlilik izolasyonu: oneksiz sinif sizmamali
unzip -l build/konform-*.zip | grep -E 'vendor/(horstoeko|jms|smalot|setasign)' 
# ciktisi bos olmali

# Composer'in kendisi veya test artefakti girmemis olmali
unzip -l build/konform-*.zip | grep -E 'vendor/composer/composer|phpunit'
# ciktisi bos olmali

# Freemius SDK oneklenmemis olmali - onek lisanslamayi bozar
unzip -l build/konform-*.zip | grep 'vendor/freemius'
# dolu olmali
```

Yukarıdakiler dosyaların varlığına bakar. Asıl soru ise paketin **kendi**
otomatik yükleyicisinin ne çözdüğüdür — dosya doğru yerde durup yükleyici onu
görmüyor olabilir. CI bunu kaynak ağacında sınar; dağıtılan pakette ayrıca
sınanmalıdır:

```sh
MSYS_NO_PATHCONV=1 docker compose run --rm -T -w /repo/build/konform composer php -r '
require "vendor/autoload.php";
$bare = "horstoeko" . chr(92) . "zugferd" . chr(92) . "ZugferdDocumentBuilder";
$prefixed = "Konform" . chr(92) . "Vendor" . chr(92) . $bare;
if ( ! class_exists( $prefixed ) ) { echo "HATA: onekli sinif yok\n"; exit(1); }
if ( class_exists( $bare ) ) { echo "HATA: oneksiz sinif sizmis\n"; exit(1); }
echo "izolasyon tamam\n";
'
```

Ters eğik çizgi `chr(92)` ile kuruluyor: kabuk ve PHP arasında geçen bir
dizgede kaçış karakteri sessizce yenir ve sınıf adı yanlış çözülür.

### Plugin Check

WordPress.org'un asıl kapısı budur. Yerel WordPress'te:

```sh
docker compose run --rm wpcli wp plugin install plugin-check --activate
docker compose run --rm wpcli wp plugin check konform \
  --format=csv --fields=file,line,type,code --exclude-directories=tests \
  | grep ',ERROR,'
```

Çıktı boş olmalı. Kalması kabul edilen uyarılar:

- `load_plugin_textdomainFound` — Pro sürüm kendi `.mo` dosyalarını taşır,
  çağrı gereklidir (bkz. `docs/I18N.md` bölüm 4).
- `PrefixAllGlobals.InvalidPrefixPassed` (`freemius.php`) — SDK köprü
  fonksiyonu; adı Freemius tarafından belirlenir.
- `DirectDB.UnescapedDBParameter` — tablo adları `$wpdb->prefix` ile kurulur,
  kullanıcı girdisi değildir; değerler `prepare()` ile bağlanır.

`Tested up to:` değeri güncel WordPress sürümünün gerisinde kalırsa Plugin
Check bunu **hata** sayar. Güncel sürüm:
`curl -s https://api.wordpress.org/core/version-check/1.7/`

---

## 4. Freemius'a yükle

Dashboard → Konform → Deployment → Add New Version → **`-premium` zip'ini**
yükle. Ücretsiz paketi buraya yüklemeyin; lisans alan müşteride Pro açılmaz.

Yükledikten sonra sürümün **Release Status'ünü `Released` yapın**. Freemius
uyarıyor: *"The paid version will not be available for download or update for
your customers until you change the release status to Released."*

**"Release plans to users" iki şart sağlanmadan açılmaz:**

1. Deployment'ta yayınlanmış bir **premium** sürüm bulunmalı
2. Doğrulama servisi ayakta olmalı

Pro planın satılan **tek** özelliği resmî doğrulamadır (bkz.
[ADR 0004](adr/0004-ucretsiz-pro-ayrimi.md)); servis çalışmıyorsa satın alan
kişi ödediği şeyin tamamını alamaz.

**Üzerine yazma tuzağı:** var olan bir sürümün üstüne yükleme yapıldığında
Freemius Release Status'ü **`Unreleased`'e geri döndürüyor**. Fark edilmezse
satış açık kalır ama paket teslim edilmez. Her üzerine yazmadan sonra durumu
yeniden `Released` yapın.

Servis 1 Eylül 2026'da yayına alındı: `konform-validator.onrender.com`.

---

## 5. WordPress.org'a gönder

Yalnızca ücretsiz sürüm gönderilir; Pro Freemius üzerinden dağıtılır.

1. https://wordpress.org/plugins/developers/add/
2. Aynı zip yüklenir.
3. İnceleme sırası birkaç gün ile birkaç hafta arasındadır.

### Slug — geri dönüşü yok, önce bunu okuyun

WordPress.org slug'ı **ana eklenti dosyasındaki `Plugin Name` başlığından**
türetir ve **onaydan sonra değiştirilemez**. Metin alanı da slug ile birebir
aynı olmak zorundadır.

Bu yüzden gönderimde ad kasıtlı olarak kısadır: `Plugin Name: Konform`.
Uzun adla gönderilseydi slug `konform-eu-e-invoicing-for-woocommerce` olurdu,
metin alanımız `konform` olduğu için translate.wordpress.org'dan gelen
çeviriler hiçbir zaman yüklenmezdi — `docs/I18N.md`'nin tamamı boşa giderdi.

Slug gönderimden sonra **bir kez** düzeltilebilir; sonrası için ekiple
yazışmak gerekir.

Onay geldikten sonra görünen ad slug'a dokunmadan uzatılabilir. İki dosyada
birden değiştirin, yoksa eklenti ekranıyla dizin farklı ad gösterir:

- `plugin/konform/konform.php` → `Plugin Name:`
- `plugin/konform/readme.txt` → `=== ... ===` başlığı

Önerilen uzun ad: `Konform – EU E-Invoicing for WooCommerce`.

`Contributors` alanı da gerçek bir WordPress.org kullanıcı adı olmalıdır
(`ekremtekerek`); uydurma bir değer yazar bağlantısını boşa düşürür.

İnceleme ekibinin sorduğu iki şey bu eklentide zaten karşılanmış durumda:
`readme.txt` içindeki **External services** bölümü doğrulama servisini,
gönderilen veriyi ve varsayılan olarak kapalı olduğunu açıkça anlatır;
gizlilik ve kullanım şartları depoda kaynağa karşı denetlenebilir hâldedir.

Kabul edildikten sonra `Tested up to` her WordPress sürümünde güncellenmeli;
aksi halde eklenti dizinde "güncel değil" uyarısıyla gösterilir.

---

## Gönderim kaydı

**0.1.0 — 1 Eylül 2026, WordPress.org'a gönderildi.**

- Atanan slug: **`konform`** (metin alanıyla eşleşiyor, hedef buydu)
- Otomatik tarama: **Pass**
- Tek uyarı: `missing_composer_json_file` — paket `vendor/` taşıyıp
  `composer.json` taşımıyordu. Gönderilen sürüm için düzeltilmedi (sayfa
  "uyarıyı dolanmayın, inceleyen elle doğrulayacak" diyor ve yeniden gönderim
  önerilmiyor); `build.sh` bundan sonraki paketlerde `composer.json`'ı
  bırakıyor.
- İnceleme yazışması: ekremtekerek@gmail.com, konu
  *"[WordPress Plugin Directory] Review in Progress: Konform"*

Onay gelene kadar slug bu sayfadan **bir kez** değiştirilebilir; sonrası için
plugins@wordpress.org ile yazışmak gerekir.

Gönderim ekranındaki "Upload updated plugin for review" ile inceleme
başlamadan düzeltilmiş paket yüklenebilir. Yeni bir gönderim AÇMAYIN.

---

## Temiz kurulum sınavı

**Bunu atlamayın.** Geliştirme kurulumu `plugin/konform` dizinini doğrudan
bağlar; müşterinin indirdiği paket başka bir şeydir. Bu adım eklenmeden önce
üretilen paket hiç kurulmamıştı ve ilk denemede gerçek bir hata çıktı:
kaldırma temizliği hiç çalışmıyordu.

Temiz bir WordPress + WooCommerce ayağa kaldırın (ayrı veritabanı, ayrı hacim,
eklenti dizini BAĞLANMADAN), sonra:

1. `wp plugin install <zip> --activate` — hatasız kurulmalı
2. Ürün + sipariş oluşturup `Generator::generate()` çalıştırın — belge
   üretilmeli, arşiv dosyası diskte olmalı, `is_intact()` doğrulanmalı
3. `wp option get uninstall_plugins` — eklenti burada görünmeli. Boş `[]`
   dönüyorsa kaldırma temizliği hiç çalışmayacak demektir.
4. `konform_delete_data_on_uninstall` seçeneğini açıp
   `wp plugin uninstall konform --deactivate` çalıştırın, sonra ölçün:

| Ne | Beklenen |
|---|---|
| Ayar seçenekleri | silinmiş |
| `konform_archive_key` | **duruyor** |
| Arşiv dizini ve dosyaları | **duruyor** |
| `konform_documents`, `konform_audit` | **duruyor** |

Ayrımın anlamı: kullanıcı **ayarlarını** silmek istedi, **faturalarını**
değil. Arşiv dosyaları kaldığına göre bütünlüklerini doğrulayan anahtar da
kalmalıdır.

Windows'ta konteynere yol geçirirken `MSYS_NO_PATHCONV=1` gerekir; yoksa
Git Bash `/pkg/...` yolunu Windows yoluna çevirir ve WP-CLI "Invalid plugin
slug" der.
