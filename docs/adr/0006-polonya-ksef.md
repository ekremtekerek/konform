# 0006 — Polonya / KSeF desteği

Tarih: 3 Eylül 2026
Durum: **Ertelendi** — ADR 0005'e bağımlı

## Bağlam

Polonya, ürün için en cazip görünen pazardı: zorunluluk **zaten yürürlükte**
ve WordPress.org'da 700 kurulumlu bir rakip var (Flexible Invoices KSeF
eklentisi), yani talebin varlığı kanıtlanmış.

Takvim:

| Tarih | Kapsam |
|---|---|
| 1 Şubat 2026 | Cirosu 200 milyon PLN üstü mükellefler |
| 1 Nisan 2026 | **KDV mükellefi tüm işletmeler** |
| 2027 | Mikro işletmeler ve KDV'den muaf olanlar |
| 1 Ocak 2027 | Cezaların başlaması (şu an geçiş dönemi) |

Bugün Polonya'ya jenerik EN 16931 CII üretiyoruz. Doğru format **FA(3)** —
CII ya da UBL değil, Polonya'nın kendi ulusal şeması. Yani yeni bir
`DocumentBuilder` gerekiyor; `ZugferdBuilder`'a profil eklemekle olmaz.

## Araştırmanın ortaya çıkardığı şey

**KSeF bir format değil, bir tescil sistemi.**

Bir FA(3) dosyası KSeF'e gönderilip sisteme kabul edilene ve kendisine bir
**KSeF numarası** atanana kadar **hukuken var olmaz**. Gönderim tarihi resmî
düzenleme tarihi sayılır.

Bu, Fransa ve Almanya'dan temelden farklı. Bir Factur-X dosyası tek başına
anlamlıdır: üretirsiniz, alıcıya gönderirsiniz, iş görür. Bir FA(3) dosyası
tek başına **hiçbir şey değildir**.

## Sonuç

Polonya desteği, ürettiğimiz belgeyi KSeF'e **iletme** yeteneği olmadan
anlamlı değil. İletim ise [ADR 0005](0005-iletim-yapilacak-mi.md) ile bilinçli
olarak ertelendi.

Yarısını yapmak — FA(3) üretip göndermemek — **desteklememekten kötüdür**.
Kullanıcı ekranda "Polonya destekleniyor" görür, belgesini alır, ve elindeki
şeyin hukuken var olmadığını ancak sonra öğrenir. Uyumluluk ürününde
yapılabilecek en kötü şey budur.

## Karar

**Ertelendi.** Polonya, ADR 0005'te iletim yönünde karar verilirse gündeme
gelir; o karardan önce değil.

Bu arada `Profile::for_country()` Polonya için CII döndürmeye devam ediyor.
Bu, "Polonya destekleniyor" iddiası değil — hiçbir yerde öyle bir iddia yok;
`readme.txt` yalnızca Fransa ve Almanya'yı sayıyor ve Polonya'yı "sırada"
diyor. Bu ifade **düzeltilmeli**: "sırada" demek, iletim kararı verilmeden
tutulamayacak bir söz.

## İşin gerçek boyutu (karar verilirse)

1. FA(3) şema eşlemesi — yeni builder, EN 16931 semantik modelinden Polonya
   ulusal şemasına. Alan kümesi CII'den farklı; birebir eşleşmeyen alanlar var.
2. KSeF API entegrasyonu — kimlik doğrulama (token/sertifika), gönderim,
   durum sorgulama, KSeF numarasının alınması ve saklanması.
3. Hata ve yeniden gönderim kuyruğu — reddedilen belgenin ne olacağı,
   mükerrer gönderimin önlenmesi.
4. KSeF numarasının arşive ve denetim kaydına işlenmesi.

Yani asıl iş 1 değil, 2–4. Ve 2–4, ADR 0005'in konusudur.
