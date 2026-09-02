# 0005 — Fatura iletimi yapılacak mı

Tarih: 2 Eylül 2026
Durum: **Karar bekliyor** — ürün sahibinin kararı

## Bağlam

Konform belgeyi üretiyor ve doğruluyor, ama **ağa göndermiyor**. `TERMS.md` ve
`readme.txt` bunu açıkça söylüyor.

Pazar araştırması bu boşluğu somutlaştırdı. WordPress.org'da AB e-faturasına
yönelen bir düzine eklenti var; en güçlüsü **POP** (90 kurulum, 15 oyla 98/100,
8 ülke, ZUGFeRD/Factur-X/Peppol/KSeF/SdI) ve **faturayı ağa gönderiyor**.
Kredi tabanlı bir SaaS modeli kullanıyor.

Önemli olan şu: Fransa'da Eylül 2026'dan sonra bir mağazanın **yasal
yükümlülüğü faturayı iletmektir.** Belge üretmek o yükümlülüğün yalnızca bir
parçası. Bugünkü hâlimizle müşteri işini bitiremiyor; ayrıca bir sağlayıcı
bulmak zorunda.

## Seçenekler

### A. Üretir ve doğrularız, iletmeyiz

Bugünkü konum. Dar ama savunulabilir: ön uçuş kontrolü ve resmî EN 16931
doğrulaması rakiplerin sayfalarında geçmiyor, ve doğrulama teknik olarak
korunaklı (kural seti XSLT 2.0, PHP'de çalışmıyor).

- **Artısı:** bugün hazır. Bakım yükü sabit. Hiçbir sağlayıcıyla sözleşme,
  hiçbir para akışı sorumluluğu yok.
- **Eksisi:** müşteri işini bitiremiyor. POP gibi ürünlerin yanında "yarım"
  görünür. Fiyatlandırmada tavan düşük.
- **Konumlandırma:** POP'un rakibi değil, tamamlayıcısı. "Neyi göndereceğinizi
  önce doğrulayın."

### B. Peppol erişim noktası entegrasyonu ekleriz

- **Artısı:** işin tamamı kapanır. Fiyat tavanı yükselir. Fransa 2026 ve
  Polonya KSeF gibi zorunluluklarda tek eklenti yeter.
- **Eksisi:** bu bir hafta sonu işi değil. Erişim noktası sağlayıcısıyla
  sözleşme, kimlik doğrulama, teslim garantisi, hata kuyruğu, yeniden gönderim,
  ve **başkasının parasal yükümlülüğünü taşıyan bir sorumluluk**. Bir belge
  iletilmezse bunun bedeli müşterinin cezası olur.
- Tek kişilik ekiple bu sorumluluğu almak, 0 kurulumlu bir üründe erken.

### C. Önce A, kanıt gelirse B

Ücretsiz sürüm yayılsın, gerçek kullanıcılardan "gönderemiyorum" şikâyeti
gelsin, sonra B'ye geçilsin.

## Öneri

**C.** Gerekçe:

1. B'nin maliyeti yüksek ve geri dönüşü belirsiz. Bugün **sıfır kullanıcı**
   var; kimsenin istemediği bir özelliği inşa etme riski gerçek.
2. A zaten satılabilir ve tek başına savunulabilir bir değer taşıyor:
   "faturanız reddedilecek mi." Bu, POP'un da çözmediği problem.
3. İletim eklendiğinde ürünün doğası değişir — yazılım satmaktan altyapı
   işletmeye geçilir. O adım, talebi ölçmeden atılmamalı.

Kısacası: bu boşluk bugün bir eksiklik değil, **bilinçli bir sınır**. Öyle
kaldığı sürece `readme.txt` ve `TERMS.md`'de olduğu gibi açıkça söylenmeli;
alıcının yanlış beklentiyle gelmesi hem iade hem kötü değerlendirme demektir.

## Karar verildiğinde

Bu dosya güncellenecek. B seçilirse ilk adım sağlayıcı seçimi olur ve o da
ayrı bir ADR ister — Peppol erişim noktaları arasında fiyat, AB veri yerleşimi
ve SLA farkları belirleyicidir.
