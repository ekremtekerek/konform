# Konform doğrulama servisi

EN 16931 Schematron kural setini çalıştıran küçük bir HTTP servisi.

**Neden ayrı bir servis?** Resmî kural seti XSLT 2.0'a derlenir. PHP'nin
`ext-xsl` uzantısı libxslt'yi sarmalar ve XSLT 1.0'da kalır, dolayısıyla resmî
doğrulama eklentinin içinde çalıştırılamaz. Ayrıntı:
[`docs/adr/0003-dogrulama-calisma-ortami.md`](../docs/adr/0003-dogrulama-calisma-ortami.md).

Bu kısıt aynı zamanda ürünün lisans korumasıdır: nulled bir kopya doğrulama
yapamaz.

---

## Uçlar

| Yol | Yöntem | Kimlik | Yanıt |
|---|---|---|---|
| `/health` | GET | yok | `{ok, rules_version, syntax}` |
| `/v1/validate` | POST | `Authorization: Bearer <LICENSE_SECRET>` | `{valid, errors[], warnings[], rules_version}` |

Gövde: `{"xml": "<rsm:CrossIndustryInvoice …>"}`, en fazla 2 MB.

`LICENSE_SECRET` tanımlı değilse servis **her isteği 401 ile reddeder**.
Yanlışlıkla kimliksiz açılan bir servis çalışır durumda görünmez.

---

## Dağıtım

Gereken: Docker'ı olan herhangi bir sunucu ve alan adının A kaydının o sunucuya
bakması. TLS'i Caddy kendisi alır ve yeniler.

```sh
git clone https://github.com/ekremtekerek/konform.git
cd konform/validator
cp .env.example .env
# .env içinde DOMAIN'i yazın ve anahtarı üretin:
#   openssl rand -hex 32
docker compose up -d --build
```

İlk yapı kural setini indirip SEF'e derlediği için birkaç dakika sürer. Sonrası
saniyeler içindedir; imaj kendi kendine yeter, çalışma anında dışarı çıkmaz.

Doğrulama:

```sh
curl -s https://<alan-adiniz>/health
# {"ok":true,"rules_version":"1.3.16","syntax":"CII"}
```

### Sağlayıcı seçimi

İki şart var:

1. **Veri yerleşimi AB'de olmalı.** Fatura XML'i işleniyor ve gizlilik
   politikası bunu taahhüt ediyor (bkz. [`docs/PRIVACY.md`](../docs/PRIVACY.md)).
2. **Servis sürekli ayakta olmalı.** Eklenti 15 saniye bekliyor
   (`HostedValidator::TIMEOUT`). Uykuya dalıp istekte uyanan barındırmalarda
   ilk isteğin uyanması 30–60 saniye sürer, yani **her seyrek istek zaman
   aşımına uğrar**. Bu yüzden ölçeği sıfıra inen ücretsiz katmanlar
   (Render/Koyeb ücretsiz web servisleri, Cloud Run varsayılanı) bu iş için
   uygun değil — servis "çalışıyor" görünür ama satılan özellik çalışmaz.

Servis durum tutmaz. Yük artarsa aynı imajdan ikinci bir kopya çalıştırmak
yeterlidir; paylaşılan bir veritabanı yoktur.

#### Ücretsiz: Oracle Cloud Always Free

İki şartı da karşılayan ücretsiz seçenek. Frankfurt (`eu-frankfurt-1`) bölgesi,
Ampere A1 (ARM) makine, sürekli açık. Haziran 2026'dan beri ücretsiz sınır
2 OCPU / 12 GB; bu servis için gerekenin kat kat üstünde.

İmaj ARM'de sorunsuz çalışır: `node:22-slim` çok mimarili ve hem `saxon-js`
hem `xslt3` saf JavaScript'tir, derlenen bir ikili yoktur.

Üç tuzağı var, üçü de kuruluşta:

- **Kapasite.** A1 makineleri "out of capacity" hatası verebilir. Frankfurt
  genelde birkaç dakikada verir; alamazsanız biraz sonra tekrar deneyin.
- **Güvenlik duvarı iki katmanlı.** VCN security list'te 80/443'ü açmak
  yetmez; Oracle Linux imajı portları makinenin kendi güvenlik duvarında da
  kapatır. İkisi de açılmazsa Caddy sertifika alamaz ve sebebi görünmez.
- **Atıl makineler geri alınabilir.** Oracle, 7 gün boyunca CPU, ağ ve bellek
  kullanımının 20'nin altında kaldığı Always Free makinelerini geri alma
  hakkını saklı tutuyor. Günde birkaç fatura doğrulayan bir servis bu tanıma
  **girer**. Bedeli olan risk budur: para ödenen bir özelliğin altındaki servis
  sessizce durabilir.

Makinenin kendi güvenlik duvarını açmak (Oracle Linux):

```sh
sudo firewall-cmd --permanent --add-service=http --add-service=https
sudo firewall-cmd --reload
# Bazi Oracle imajlarinda firewalld yerine dogrudan iptables kuralli gelir:
sudo iptables -I INPUT -p tcp -m multiport --dports 80,443 -j ACCEPT
sudo netfilter-persistent save 2>/dev/null || sudo service iptables save
```

Bu yüzden ücretsiz katmanda `/health` ucuna dışarıdan bir izleme koymak
isteğe bağlı değildir. Beş dakikada bir kontrol eden ücretsiz bir uptime
servisi yeterli; makine geri alınırsa aynı gün haberiniz olur.

Riski tamamen kaldırmak isterseniz Hetzner CX22 (Almanya, ~4 €/ay) aynı
`compose.yml` ile çalışır ve ne kapasite ne geri alma sorunu vardır.

---

## Eklenti tarafındaki ayar

**WooCommerce → Konform** sayfasının altındaki "Official validation (Pro)" bölümü:

- **Doğrulama ucu**: `https://<alan-adiniz>/v1/validate`
- **Anahtar**: `.env` içindeki `LICENSE_SECRET` değeri

Servis erişilemezse eklenti belgeyi yine de üretir ve doğrulamanın
çalışmadığını kaydeder. Ağ arızası fatura kesmeyi durdurmamalıdır.

---

## Kural seti güncellemesi

Avrupa Komisyonu yeni sürüm yayımladığında:

```sh
# .env içinde RULES_VERSION'ı değiştirin
docker compose up -d --build
```

Dün geçerli olan bir belge bugün bulgu üretebilir; bu kuralların değişmesidir,
bir kusur değil. Kullanım şartları bunu açıkça söyler.

---

## Yerelde çalıştırma

```sh
npm ci
npm run build:rules      # kural setini indirir ve derler
npm test                 # resmî örnek belgeye karşı öz-test
LICENSE_SECRET=dev npm start
```

`npm test` HTTP katmanını atlar ve `validate()` fonksiyonunu doğrudan çağırır;
lisans anahtarı devreye girmez.
