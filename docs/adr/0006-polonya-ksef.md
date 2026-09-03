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

## Kademeler

1. **FA(3) üretimi** — yeni `Profile` durumu, yeni `DocumentBuilder`,
   `SemanticInvoice` → `FakturaType` eşlemesi, yerel XSD doğrulaması.
   Kimlik bilgisi gerektirmez, çevrimdışı test edilebilir.
2. **KSeF API istemcisi** — oturum açma, gönderim, durum sorgulama, KSeF
   numarası ve UPO'nun alınması. `api-test` ortamına karşı.
3. **Saklama ve arayüz** — KSeF numarasının arşive ve denetim kaydına
   işlenmesi, token ayarı, sipariş ekranında durum.
4. **Kuyruk ve yeniden gönderim** — reddedilen belge, mükerrer gönderimin
   önlenmesi.

**1. kademe tek başına kullanıcıya açılmaz.** FA(3) üretip göndermemek,
desteklememekten kötüdür: kullanıcı "Polonya destekleniyor" görür, belgesini
alır, elindekinin hukuken var olmadığını sonra öğrenir. Polonya `readme.txt`'de
ancak 2. kademe bitince duyurulur.
