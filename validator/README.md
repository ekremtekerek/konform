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

Fatura XML'i işlendiği için **veri yerleşimi AB'de olmalıdır** — gizlilik
politikası bunu taahhüt eder (bkz. [`docs/PRIVACY.md`](../docs/PRIVACY.md)).
Hetzner (Almanya/Finlandiya) veya Scaleway (Fransa) bu şartı karşılar ve
CX22 sınıfı bir makine (2 vCPU / 4 GB) başlangıç için fazlasıyla yeterlidir.

Servis durum tutmaz. Yük artarsa aynı imajdan ikinci bir kopya çalıştırmak
yeterlidir; paylaşılan bir veritabanı yoktur.

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
