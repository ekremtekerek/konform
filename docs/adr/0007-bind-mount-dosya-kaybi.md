# 0007 — Bağımlılık izolasyonu bind mount üzerinde çalıştırılmaz

Tarih: 3 Eylül 2026
Durum: **Kabul edildi**

## Bağlam

Polonya FA(3) üreticisi yazıldıktan sonra testler `Konform\Vendor\Intermedia\
Ksef\Fa3\Model\FakturaType` sınıfını bulamadı. Sınıf paketin içindeydi ama
önekli ağaca hiç kopyalanmamıştı.

Aranan sebep uzun süre yanlış yerde arandı. Elenen açıklamalar:

| Şüpheli | Nasıl elendi |
|---|---|
| Ad alanı öneki yanlış | Önekli `TNaglowek.php` dosyasında birebir doğru |
| `post-strauss.php` yarım silme | Temiz kurulumda **aynı** sayılar çıktı |
| Strauss sürüm hatası | 0.22.6 ve 0.29.1 birebir aynı sonucu verdi |
| Yarış durumu | Hiçbir şey silmeden tekrar koşuldu, sonuç değişmedi |

## Bulgu

Windows'ta Docker Desktop'ın bind mount'u, PHP'nin `RecursiveDirectoryIterator`
çağrılarında büyük dizinleri **eksik** döndürüyor. Aynı dizin, aynı süreç,
aynı an:

```
glob()                      -> 99 dosya
scandir()                   -> 99 dosya
RecursiveDirectoryIterator  -> 52 dosya
```

Kabuk araçları (`ls`, `find`, `cp`, `tar`) eksiksiz okuyor. Sorun yalnızca
PHP'nin özyinelemeli dizin yineleyicisinde.

Zincirdeki üç adım da bu yineleyiciye dayanır: **Strauss**, **post-strauss.php**
ve **`composer dump-autoload`**. Üçü de hata vermeden, `exit 0` dönerek eksik
iş yapıyordu.

Ölçülen kayıp:

| Paket / dizin | Kaynak | Önekli ağaç |
|---|---|---|
| `intermedia/ksef-fa3` → `src/Model` | 99 | 52 |
| `horstoeko/zugferd` → `entities/extended/ram` | 52 | 12 |

Aynı Strauss, aynı yapılandırma, tek fark dosya sisteminin bind mount olmaması:
99 ve 52 — eksiksiz.

## Neden bu kadar tehlikeli

`Konform\Vendor\*` sınıflarının **psr-4 karşılığı yoktur**; `composer.json`
onları `"classmap": ["vendor-prefixed/"]` ile kaydeder. Yani budanmış bir
classmap, çalışma anında doğrudan "class not found" demektir. Yedek çözüm yolu
yok.

Yayınlanan 0.1.0 denetlendi: paketteki **959 sınıfın tamamı** classmap'te,
eksik yok. Pakete girmeyen 40 zugferd dosyasının hepsi `extended` profilinde
ve Konform o profili hiç kullanmıyor. Yani kullanıcı etkisi olmadı — ama bu
şans eseri, tasarım gereği değil.

## Karar

**Kurulum, Strauss, post-strauss ve otomatik yükleyici üretimi konteyner içi
diskte (overlay fs) çalıştırılır; sonuç bind mount'a kopyalanır.**

- `bin/deps.sh` — kurulum + Strauss + post-strauss + dump zinciri
- `bin/dump-autoload.sh` — budama sonrası classmap üretimi

Kopyalama `tar`/`cp` ile yapılır; ikisinin de bind mount'u eksiksiz okuduğu
ölçüldü. Her iki betik kopyanın dosya sayısını doğrular ve tutmuyorsa durur.

`bin/build.sh` ve `composer strauss` bu betikleri kullanır. Doğrudan
`vendor/bin/strauss` çağrılmamalıdır.

## Koruma

`post-strauss.php` başlarken yineleyici sayımını `scandir` sayımıyla
karşılaştırır; ayrıldıkları anda **durur**. Asıl zarar veren şey hatanın
kendisi değil, hatanın `exit 0` ile başarı gibi görünmesiydi.

## Not

Sorun ortamla ilgilidir; Linux üzerinde bir CI koşusunda ortaya çıkmaz. Bu
yüzden "bende çalışıyor" burada geçerli bir kanıt değil: **sürüm paketi her
zaman açılıp içeriği sayılarak doğrulanmalı.** Bkz. `docs/RELEASE.md`.
