# ADR 0003 — Doğrulama servisi nerede çalışacak

- **Tarih:** 1 Eylül 2026
- **Durum:** Kabul edildi
- **Karar:** Node servisi (konteyner), Cloudflare Worker **değil**
- **Değiştirdiği:** `docs/SPEC.md` bölüm 09 ve Faz 3 tanımı

---

## Bağlam

SPEC, doğrulama servisini "Cloudflare Worker + Saxon-JS" olarak tanımlamıştı.
Gerekçe hâlâ geçerli: EN 16931 ve KoSIT kural setleri XSLT 2.0'a derlenir,
PHP'nin `ext-xsl` uzantısı XSLT 1.0'da kaldığı için resmi doğrulama eklentinin
içinde çalıştırılamaz. Bu kısıt ürünün hendeğidir — null'lanmış bir kopya
doğrulama yapamaz.

Değişen şey **nerede** çalıştığı.

## Neyi denedik

Cloudflare Worker yolu iki noktadan tıkandı.

**1. `saxon-js` npm paketi yalnızca Node yapısını içeriyor.**
`nodejs_compat` bayrağıyla bile workerd altında açılmıyor:

```
Uncaught ReferenceError: abstractNode is not defined
  at node_modules/saxon-js/SaxonJS2N.js
```

**2. Tarayıcı yapısı (`SaxonJS2.js`) serbestçe dağıtılmıyor.**
npm paketinde yok; jsDelivr, unpkg ve cdnjs 404 döndü. Saxonica'nın kendi
sitesinden alınıyor. Alınabilse bile workerd'de DOM olmadığı için çalışacağı
garanti değildi.

Boyut açısından Worker ideal olurdu — SEF gzip 141 KB, Saxon-JS gzip 358 KB,
toplam ~500 KB ile ücretsiz katmanın 3 MB sınırının çok altında. Sorun boyut
değil, çalışma ortamı.

## Karar

Servis **düz `node:http` üzerinde bir Node uygulaması** olarak çalışır ve
konteyner olarak dağıtılır. Çatı katmanı yok: tek uçlu, tek işli bir servise
Express/Hono eklemek yalnızca saldırı yüzeyi ve bakım yükü olurdu.

Konteyner olduğu için barındırma seçeneği açık kalır — Fly.io, Railway, Hetzner
veya Cloudflare Containers. Tek satıcıya bağlanmıyoruz.

**Hendek bundan etkilenmiyor.** Hendek Cloudflare'e değil, doğrulamanın PHP
süreci içinde yapılamamasına dayanıyordu; o gerçek değişmedi.

## Doğrulama

Node yolu ölçüldü ve çalışıyor:

| Test | Sonuç |
|---|---|
| Resmi örnek `CII_business_example_01` | 0 hata, 396 ms (soğuk) |
| Kendi çıktımız, yurt içi FR | 0 hata |
| Kendi çıktımız, AB içi hizmet (AE) | 0 hata |
| Kendi çıktımız, AB içi mal (K) | **2 ölümcül hata** |
| HTTP üzerinden, ısınmış süreç | 52–138 ms |
| Bozuk XML | ayrıştırma hatası olarak raporlanıyor |

## Servis ilk işinde kendi hatamızı buldu

Kategori K (AB içi mal teslimi) çıktımız resmi Schematron'dan geçemedi:

- **BR-IC-11** — Fiili teslim tarihi (BT-72) veya fatura dönemi (BG-14) zorunlu
- **BR-IC-12** — Teslim ülkesi kodu (BT-80) zorunlu

`OrderMapper` bu alanları hiç doldurmuyordu. Eklendi, üçü de temiz geçiyor.

Bu, doğrulama servisinin neden ürünün en değerli parçası olduğunun kanıtıdır:
gözle bakarak veya birim testiyle bulunamayacak bir uyumluluk hatasıydı, ve
müşteride ortaya çıksa fatura vergi idaresinde reddedilecekti.

## Sonuçlar

- ✅ Kanıtlanmış, ölçülmüş bir yol; teknik risk kapandı.
- ✅ Barındırma satıcısına bağımlılık yok.
- ⚠️ Aylık maliyet artık sıfır değil. Küçük bir konteyner ~5 USD/ay veya
  sıfıra ölçeklenen bir katmanda daha az. Yılda €149 × yüzlerce müşterinin
  yanında ihmal edilebilir.
- ⚠️ Worker'ın kenar ağı avantajı kayboldu. Doğrulama arka planda, Action
  Scheduler kuyruğunda çalıştığı için gecikme kullanıcıya yansımıyor.
- ℹ️ Saxon-JS lisansı bu kullanıma elverişli: ikili biçimde dağıtım, kendi
  sunucumuzda çalıştırma ve uygulamanın parçası olarak yeniden dağıtım
  açıkça izinli. Yasak olan, yazılımı üçüncü kişilere sunmayı birincil amaç
  edinen bir siteye koymak — bizim yaptığımız bu değil.
