# 0006 — Polonya / KSeF desteği

Tarih: 3 Eylül 2026
Durum: **Kabul edildi** — kademeli olarak yapılıyor

## Bağlam

Zorunluluk yürürlükte ve talep kanıtlanmış:

| Tarih | Kapsam |
|---|---|
| 1 Şubat 2026 | Cirosu 200 milyon PLN üstü mükellefler |
| 1 Nisan 2026 | **KDV mükellefi tüm işletmeler** |
| 2027 | Mikro işletmeler ve KDV'den muaf olanlar |
| 1 Ocak 2027 | Cezaların başlaması (şu an geçiş dönemi) |

WordPress.org'da 700 kurulumlu bir KSeF eklentisi var — talebin var olduğu
kanıtlanmış tek pazar.

## Belirleyici olan şey

**KSeF bir format değil, bir tescil sistemi.** Bir FA(3) dosyası KSeF'e
gönderilip kabul edilene ve kendisine bir **KSeF numarası** atanana kadar
hukuken var olmaz; gönderim tarihi resmî düzenleme tarihi sayılır.

Bu, Fransa ve Almanya'dan temelden farklıdır. Factur-X dosyası tek başına
anlamlıdır. FA(3) dosyası tek başına hiçbir şeydir.

## Peppol ile karıştırılmamalı

İlk değerlendirmede Polonya, [ADR 0005](0005-iletim-yapilacak-mi.md)'teki
Peppol iletimiyle aynı kefeye konmuştu. **Bu eksik bir değerlendirmeydi.**

- **Peppol** ticari bir erişim noktasıyla sözleşme gerektirir.
- **KSeF doğrudan devletin API'sidir.** Aracı yok, sözleşme yok, ücret yok.
  Üstelik `api-test.ksef.mf.gov.pl` adresinde açık bir test ortamı var;
  oradaki faturaların hukuki sonucu yoktur ve bir süre sonra silinirler.

Yani Polonya, ADR 0005'in beklediği "altyapı işletmeciliğine geçiş"
kararından bağımsız yapılabilir. ADR 0005 Peppol için geçerliliğini korur.

## Kütüphane seçimi

**`intermedia/ksef-fa3` v1.0.2** kullanılacak (MIT, PHP ^8.1).

Gerekçe, ADR 0001'dekiyle aynı: yalın olanı seç.

- Resmî FA(3) XSD şemalarından üretilmiş modeller, enum'lar, XML serileştirici
  **ve XSD doğrulayıcı**; şemaları paketin içinde taşıyor.
- Bağımlılıkları **yalnızca `ext-dom` ve `ext-libxml`**. Strauss'la öneklenecek
  ek bir paket ağacı yok, çakışma yüzeyi yok.

Reddedilenler:

- `n1ebieski/ksef-php-client` — aktif ve popüler (100 bin indirme) ama
  psr-http, valinor, phpseclib, qr-code dahil ağır bir bağımlılık ağacı
  getiriyor. Bir WordPress eklentisine bu yük girmemeli.
- `nozugroup/ksef-client-php` — KSeF 2.0'a özel ama v0.1.0 ve 52 indirme.

**API istemcisi kendimiz yazılacak**, `wp_remote_*` üzerinden. KSeF 2.0 REST
ve JSON; birkaç uç için Guzzle ve PSR yığını taşımak gereksiz. `HostedValidator`
zaten bu deseni kuruyor.

Bu karar korundu ama **beklenmedik bir yerden sınandı**: KSeF, AES anahtarını
RSA-OAEP/SHA-256 ile sarmalamayı şart koşuyor ve PHP bunu ancak 8.5'te
yapabiliyor. Eklenti PHP 8.2 istediği için bir çözüm gerekti; phpseclib
ölçüldü (362 dosya, 3,4 MB) ve reddedildi. Ayrıntı ve gerekçe:
[ADR 0008](0008-oaep-kendimiz.md).

## Kademeler

1. **FA(3) üretimi** — ✅ **yapıldı.** `Profile::KSEF`, `Fa3Builder`,
   `SemanticInvoice` → `FakturaType` eşlemesi, yerel XSD doğrulaması.
   Kimlik bilgisi gerektirmez, çevrimdışı test edilir.
2. **KSeF API istemcisi** — ✅ **yapıldı ve canlı doğrulandı.** Yetkilendirme,
   oturum açma, şifreli gönderim, durum sorgulama. `api-test` ortamına gerçek
   bir FA(3) faturası gönderildi ve KSeF numarası alındı.
3. **Saklama ve arayüz** — 🔶 **büyük kısmı yapıldı.** `documents` tablosunda
   `ksef_number` sütunu (şema sürümü 2), `Archive::record_ksef_number()`, üç
   yeni denetim olayı, `Ksef\Settings`, `Ksef\Submission` ve sipariş ekranında
   tescil durumu.

   **Ayar ekranı bilerek eklenmedi.** Jeton alanını göstermek, gönderim
   üretim akışına bağlı değilken Polonya'yı yarı yarıya kullanıcıya açardı:
   kullanıcı jetonunu girer, hiçbir şey olmaz. Alan, 4. kademe bitip Polonya
   duyurulabilir hâle geldiğinde eklenecek. `Settings` sınıfı hazır ve
   sınanmış durumda.
4. **Kuyruk ve yeniden gönderim** — ✅ **yapıldı.** `Queue\KsefQueue`,
   gecikmeli yeniden zamanlama, mükerrer gönderimin önlenmesi.

## Mükerrer gönderim nasıl önleniyor

Bir belge KSeF açısından üç durumdan birindedir ve her biri farklı davranış
gerektirir:

| Durum | Davranış |
|---|---|
| Tescilli (numarası var) | Hiçbir şey yapılmaz |
| Gönderilmiş, numarasız | **Yalnızca sorgulanır** |
| Hiç gönderilmemiş | Gönderilir |

İkinci durum bu tasarımın kalbi. KSeF faturayı önce kabul eder (referans
numarası verir), numarayı dakikalar sonra atar. Arada süreç koparsa —zaman
aşımı, çökme, kuyruğun yeniden başlaması— ve "gönderildi mi" sorusunun cevabı
saklanmıyorsa, yeniden deneme faturayı **tekrar gönderir**. KSeF'te aynı
faturanın iki kaydı oluşur ve bunun düzeltilmesi zordur.

Bu yüzden `ksef_session` ve `ksef_reference` sütunları var (şema sürümü 3) ve
referans, numara **beklenmeden**, gönderimin hemen ardından kalıcılaştırılıyor.
Mükerrer bir fatura, geç tescil edilmiş bir faturadan kötüdür.

Koruma iki katmanlı: kuyruk aynı belge için ikinci iş eklemiyor, `Submission`
ise kuyruk atlansa bile gönderilmiş belgeyi tekrar göndermiyor. Biri kuyruğu
temiz tutuyor, öbürü faturayı koruyor.

## Bekleme döngüyle değil, yeniden zamanlamayla

Numara için `sleep()` ile beklenmiyor. Bir kuyruk işini dakikalarca meşgul
etmek, sıradaki işlerin beklemesi demek. Sonuç hazır değilse iş iki dakika
gecikmeyle yeniden zamanlanıyor; en fazla 20 kez, yani yaklaşık 40 dakika.

Sınır dolduğunda vazgeçmek belgeyi kaybetmek değil: referans arşivde,
gönderim olayı denetim kaydında duruyor. Sonradan sorgulanabilir.

## WordPress'te uçtan uca sınav: iki yeşil doğrulama arasında kalan yol

Polonya açıldıktan sonra eklenti WordPress'te gerçek bir siparişle sınandı ve
**hiçbir fatura gönderemediği** ortaya çıktı:

```
KSeF rejected the authentication:
Uwierzytelnianie zakończone niepowodzeniem z powodu błędnego tokenu
```

Sebep zaman damgasıydı. KSeF meydan okumada onu **kesirli saniyeli** ISO-8601
dizgesi olarak döndürüyor (`2026-09-04T06:08:10.9389841+00:00`) ve
`strtotime()` kesirleri atıp saniyeye yuvarlıyordu. İmzalanan dizge
`{jeton}|{milisaniye}` olduğundan, yuvarlanmış değer reddediliyor — üstelik
mesaj "hatalı jeton" diyor, yani sebebi göstermiyor.

`DateTimeImmutable::format('Uv')` ikisini birlikte veriyor.

**Bunun neden daha önce görülmediği, kaydedilmeye değer.** O sırada iki
doğrulama da yeşildi ve ikisi de aynı yolu atlıyordu:

| Doğrulama | Neden kaçırdı |
|---|---|
| 85 birim test | Sahte yanıtlarda zaman damgası **tam sayı** veriliyordu; gerçek biçim hiç görülmedi |
| Canlı KSeF denemeleri | **XAdES** yolunu kullanıyordu; jeton yolu ise kullanıcıların tek yolu |

Ders: bir yolu iki farklı katmanda doğrulamak, o yolun *aynı* varsayımla
sınandığı anlamına gelebiliyor. Kullanıcının gerçekten izlediği yol, gerçek
verisiyle, en az bir kez baştan sona koşulmalı.

## Polonya açıldı

Dört kademe bitince Polonya kullanıcıya açıldı (0.2.0):

- `Profile::for_country()` içinde `PL` → `Profile::KSEF`
- `Generator` üreticiyi **profile göre** seçiyor; FA(3) CII olmadığı için
  `ZugferdBuilder` onu üretemez
- Üretilen her FA(3), `konform/document_generated` üzerinden `KsefQueue`'ya
  giriyor
- Ayar ekranında jeton ve ortam; yalnızca satıcı Polonya'daysa görünüyor
- `readme.txt`'de KSeF **dış servis olarak bildirildi** — WordPress.org bunu
  şart koşuyor ve kullanıcının faturasının nereye gittiğini bilmesi gerekiyor

### Açarken kapatılan bir tuzak

`HostedValidator` belgeyi **EN 16931** kural setine karşı doğrular. FA(3) ise
EN 16931 değil, ulusal bir şemadır. Pro planda doğrulama yapılandırılmış bir
Polonyalı mağazada her fatura anlamsız hatalarla **bloke olacaktı**.

FA(3) artık o doğrulamayı atlıyor. Zaten iki yerde doğrulanıyor: üretilirken
resmî XSD'ye karşı (`Fa3Builder`), gönderilirken KSeF'in kendisi tarafından.

## Kademelerin tarihçesi

**1. kademe tek başına kullanıcıya açılmadı.** FA(3) üretip göndermemek,
desteklememekten kötüdür: kullanıcı "Polonya destekleniyor" görür, belgesini
alır, elindekinin hukuken var olmadığını sonra öğrenir. Polonya `readme.txt`'de
ancak 2. kademe bitince duyurulur.

Bunun somut karşılığı: **`Profile::for_country()` içinde `PL` yoktur.**
`Profile::KSEF` ve `Fa3Builder` vardır ama üretim akışına bağlı değildir.

İlk denemede `'PL' => self::KSEF` eklenmişti ve bu sessiz bir gerileme
üretiyordu: hiçbir üretici KSEF'i desteklemediği için `Generator::generate()`
`null` dönüyor, denetim kaydına *"No builder supports profile ksef"* yazıyor ve
Polonyalı mağaza **bugün aldığı EN 16931 CII belgesini kaybediyordu** — yerine
geçerli bir şey konmadan. Testlerden hiçbiri bunu görmüyordu, çünkü hepsi
`Fa3Builder`'ı doğrudan çağırıyordu.

`Fa3BuilderTest::test_poland_still_receives_a_buildable_profile` artık bunu
tutuyor: PL'nin aldığı profilin yalnızca ne olduğunu değil, gerçekten
üretilebilir olduğunu da doğruluyor.

## 1. kademede öğrenilenler

Kütüphaneyi okumak iki varsayımı çürüttü; ikisi de kod yazmadan önce yakalandı.

- **`STAWKA_0` diye tek bir değer yok.** FA(3)'te %0, rejime göre üçe ayrılır:
  yurt içi (`STAWKA_0_KRAJ`), AB içi teslim (`STAWKA_0_WDT`) ve ihracat
  (`STAWKA_0_EXPORT`). Muafiyet (`ZW`) ile tersine yük (`OO`) ayrı değerlerdir.
- **Toplam alanları da öyle.** `p1361`, `p1362`, `p1363`, `p137`, `p1310`,
  `p138` — her biri farklı bir vergi rejimi.

Sonuç: eşleme **orana değil vergi kategorisine** dayanmak zorunda. Yalnızca
sayısal orana bakan bir eşleme, AB içi teslimi yurt içi sıfır alanına yazardı;
şema denetiminden geçer ama beyanı bozardı. `Fa3BuilderTest` bunu koruyor.

## Canlı doğrulama

`api-test.ksef.mf.gov.pl` ortamına gerçek bir gönderim yapıldı ve **KSeF
numarası alındı**:

```
5265877635-20260904-3A0E71000000-0A
```

Bu, zincirin tamamının gerçek sistem tarafından kabul edildiği anlamına gelir:
`Fa3Builder`'ın ürettiği belge, `Encryption`'ın AES şifrelemesi ve kendi
yazdığımız OAEP sarmalaması, `Client`'ın oturum akışı.

### Kapı nasıl açıldı

Gönderim için erişim jetonu gerekiyor; jeton üretmek bir kez **XAdES imzalı**
doğrulama istiyor. Test ortamı bunun için **kendi imzalı** sertifikaya izin
veriyor. `bin/ksef-live-test.php` bunu yapıyor: sertifikayı üretiyor,
`AuthTokenRequest` belgesini XAdES ile imzalıyor, erişim jetonunu alıyor.

XAdES imzası **bağımlılıksız** yazıldı; PHP'nin yerel `DOMNode::C14N()`
desteği yeterli oldu. Bakanlık kabul etti (*"Uwierzytelnianie zakończone
sukcesem"*).

Sertifika konusunda NIP, `2.5.4.97` (organizationIdentifier) alanına
`VATPL-<NIP>` olarak yazılıyor. Bu biçim belgelerde yok; Bakanlık'ın kendi
.NET istemcisinin birim testlerinden alındı.

**Bu betikler yalnızca geliştirme araçlarıdır.** `bin/` dizini paketin dışında;
gerçek kullanıcılar XAdES kullanmaz, KSeF jetonunu bir kez kendileri üretip
eklentiye yapıştırır.

### Kategori matrisi: tek senaryo doğrulamak değildir

İlk canlı deneme yalnızca **yurt içi %23** faturasıyla yapıldı ve başarılıydı.
Buna dayanıp "Polonya doğrulandı" demek yanlış olurdu; `bin/ksef-live-matrix.php`
bütün vergi kategorilerini denediğinde ortaya çıktı ki **sınır ötesi
senaryoların hiçbiri belge bile üretemiyordu.**

Sebep: FA(3) alıcıda dört kimlik seçeneğinden birini zorunlu tutuyor —
`NIP`, `KodUE`+`NrVatUE`, `KodKraju`+`NrID`, ya da açıkça "kimlik yok"
(`BrakID`). Üretici yalnızca Polonyalı alıcıda NIP yazıyor, yurt dışı alıcıda
hiçbirini yazmıyordu. Yurt içi satışlar çalıştığı için görünmüyordu.

Düzeltmeden sonra **sekiz senaryonun tamamı** gerçek KSeF tarafından kabul
edilip tescil edildi:

| Senaryo | FA(3) alanı |
|---|---|
| Yurt içi %23 / %8 / %5 | P_13_1 / P_13_2 / P_13_3 |
| AB içi teslim (WDT) | P_13_6_2 |
| İhracat | P_13_6_3 |
| Tersine yük | P_13_10 |
| Yurt dışı hizmet | P_13_8 |
| Muafiyet | P_13_7 + P_19C |

**Yeni bir vergi kategorisi ya da alıcı türü eklendiğinde matris yeniden
çalıştırılmalı.** Yerel şema doğrulaması bu hatayı yakalıyordu — eksik olan
doğrulama değil, denenen senaryoların kapsamıydı.

Muafiyetin `P_19C`'ye yazılması KSeF tarafından reddedilmedi. Bu riski
azaltıyor ama ortadan kaldırmıyor: kabul edilmek, alanın hukuken doğru olduğunu
kanıtlamaz. Aşağıdaki uzman incelemesi notu geçerliliğini koruyor.

### Canlı denemenin yakaladığı iki hata

Belgelerden yazarken ikisi de görülmemişti:

1. **Yanlış sertifika.** KSeF iki sertifika döndürüyor: `KsefTokenEncryption`
   (jetonu şifrelemek) ve `SymmetricKeyEncryption` (AES anahtarını
   sarmalamak). `public_key_certificate()` her zaman simetrik olanı
   veriyordu; kimlik doğrulama yanlış anahtarla şifreleme yapacaktı.

2. **Yanlış durum yolu.** Oturum **açmak** `/sessions/online`, ama açılmış
   oturumu ve faturalarını **sorgulamak** `/sessions/{ref}` altından
   yapılıyor. İkisinde de "online" kullanmak 404 veriyordu.

Bir de kayda değer bir ayrıntı: meydan okumanın `timestamp` alanı tam sayı
milisaniye değil, **ISO-8601 dizgesi** olarak geliyor. İstemcinin ikisini de
kabul etmesi işe yaradı.

## Bilinen eksik: muafiyetin hukuki dayanağı

FA(3) `Zwolnienie` bloğunu zorunlu tutar ve iki seçenek sunar: muafiyet yoktur
(`P_19N`), ya da vardır ve **hukuki dayanağı** yazılır. Dayanak üç alana
ayrılmıştır:

| Alan | Beklenen |
|---|---|
| `P_19A` | Polonya KDV Kanunu'nun ilgili maddesi |
| `P_19B` | 2006/112/AT yönergesinin ilgili maddesi |
| `P_19C` | Diğer hukuki dayanak |

Elimizdeki BT-120 metni serbest biçimlidir ve hangisine karşılık geldiğini
bilemeyiz; bu yüzden şimdilik `P_19C`'ye yazılıyor. Dayanak hiç yoksa üretici
belge üretmek yerine **duruyor** — muaf bir faturaya "muafiyet yok" demek
yanlış beyandır.

**Polonya kullanıcıya açılmadan önce bu eşleme Polonya KDV mevzuatına göre
gözden geçirilmeli.** Muhtemelen yurt içi muafiyetler `P_19A`'ya, AB içi
işlemler `P_19B`'ye gitmeli.
