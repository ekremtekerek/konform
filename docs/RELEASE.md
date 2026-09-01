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

```sh
bash bin/build.sh
```

Üretilen: `build/konform-<sürüm>.zip`, kökünde `konform/` dizini ile.

Betik sırayla: kaynağı kopyalar (testler ve geliştirme dosyaları hariç),
bağımlılıkları kurar, Strauss ile `Konform\Vendor\` altına önekler,
`post-strauss.php` ile meta verileri ve varlıkları düzeltir, geliştirme
paketlerini siler, arşivler.

Strauss dört bin dosya işler; adım dakikalar sürer, takılmış değildir.

---

## 3. Paketi denetle

Bunlar CI'nin göremediği, yalnızca üretilmiş pakette görülebilen şeylerdir.

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

Dashboard → Konform → Deployment → Add New Version → zip'i yükle.

**"Release plans to users" doğrulama servisi yayında olmadan açılmaz.** Pro
planın satılan üç özelliğinden biri resmî doğrulamadır; servis olmadan satın
alan kişi ödediği şeyi alamaz.

---

## 5. WordPress.org'a gönder

Yalnızca ücretsiz sürüm gönderilir; Pro Freemius üzerinden dağıtılır.

1. https://wordpress.org/plugins/developers/add/
2. Aynı zip yüklenir.
3. İnceleme sırası birkaç gün ile birkaç hafta arasındadır.

İnceleme ekibinin sorduğu iki şey bu eklentide zaten karşılanmış durumda:
`readme.txt` içindeki **External services** bölümü doğrulama servisini,
gönderilen veriyi ve varsayılan olarak kapalı olduğunu açıkça anlatır;
gizlilik ve kullanım şartları depoda kaynağa karşı denetlenebilir hâldedir.

Kabul edildikten sonra `Tested up to` her WordPress sürümünde güncellenmeli;
aksi halde eklenti dizinde "güncel değil" uyarısıyla gösterilir.
