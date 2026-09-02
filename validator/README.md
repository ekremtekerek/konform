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

### Render (şu an kullanılan)

Panelden **New → Web Service**, GitHub deposu `ekremtekerek/konform`:

| Alan | Değer |
|---|---|
| Name | `konform-validator` |
| Language | Docker |
| Branch | `main` |
| Region | Frankfurt (EU Central) |
| Root Directory | `validator` |
| Instance Type | Free |
| Health Check Path | `/health` |
| `LICENSE_SECRET` | değer alanındaki **Generate** ile üretilir |
| `RULES_VERSION` | `1.3.16` |

Kök dizin `validator` olduğu için `plugin/` altındaki değişiklikler yeniden
dağıtım tetiklemez. `Dockerfile` doğrudan kullanılır; `compose.yml` ve
`Caddyfile` bu yolda devrede değildir.

İlk yapı kural setini indirip SEF'e derlediği için birkaç dakika sürer —
ölçülen: 1 dk 1 sn.

### Kendi sunucunuzda

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

**Şu an yayında:** Render, Frankfurt bölgesi, ücretsiz katman
(`konform-validator`, `https://konform-validator.onrender.com`). Kaynak GitHub
deposundan, kök dizin `validator/`, `main` dalına her gönderimde yeniden
dağıtılıyor.

Tek şart veri yerleşiminin AB'de olması: fatura XML'i işleniyor. Gizlilik
politikası bunu açıkça taahhüt etmiyor, ama AB e-fatura uyumluluğu satan bir
üründe verinin AB dışına çıkması satışta size sorulur.

Servis durum tutmaz. Yük artarsa aynı imajdan ikinci bir kopya çalıştırmak
yeterlidir; paylaşılan bir veritabanı yoktur.

#### Ücretsiz katmanın iki bedeli — ve nasıl karşılandığı

Bunlar ölçüldü, tahmin değil.

**Uykuya dalma.** Ücretsiz servis 15 dakika atıl kalınca duruyor; ilk isteğin
uyanması 50 saniyeyi buluyor. Eklenti bunu tek bir zaman aşımıyla karşılasaydı
seyrek gelen her istek boşa giderdi. Bu yüzden süre bağlama göre ayrıldı:
etkileşimli istekte 15 saniye (bir yönetici ekran başında bekliyor, ekran
kilitlenmemeli), kuyruktaki işte 90 saniye (orada kimse beklemiyor). Ayrımı
`Scheduler::is_running_in_background()` kuruyor.

Pratik sonucu: **otomatik üretim** (sipariş tamamlanınca, Pro) soğuk servisi
bekler ve doğrular. Sipariş ekranındaki **elle üretim** soğuk servise denk
gelirse "doğrulama yapılamadı" der, belge yine üretilir; ikinci deneme
ısınmış servise gider ve doğrular.

**Geçiş anında 404.** Servis dururken kenar sunucu isteği bekletmek yerine
birkaç dakika 404 döndürüyor. Ölçüm: çalışan bir servis önce 200, sonra
~2 dakika 404, sonra yine 200. Eklenti kesin bir HTTP durumuyla dönen geçici
hatalarda (404, 5xx) 3 saniye sonra bir kez daha deniyor. Zaman aşımında
denemiyor — orada bütçenin tamamı zaten harcanmıştır.

**Hız.** 0,1 CPU'da doğrulama 4,2 saniye sürüyor (güçlü bir makinede
0,1–0,3 sn). Isınmış serviste 15 saniyelik bütçeye sığıyor, ama pay dar.

Bu üçü, ücretsiz katmanı çalışır kılıyor; ortadan kaldırmıyor. Sürekli açık
bir makine ($7/ay Render Starter ya da ~4 €/ay Hetzner) üçünü de sıfırlar.

#### Kalıcı ücretsiz alternatif: Oracle Cloud Always Free

Uykuya dalmayan tek kalıcı ücretsiz seçenek. Frankfurt (`eu-frankfurt-1`)
bölgesi, Ampere A1 (ARM) makine, sürekli açık. Haziran 2026'dan beri ücretsiz
sınır 2 OCPU / 12 GB; bu servis için gerekenin kat kat üstünde. Yukarıdaki üç
bedeli de ortadan kaldırır, ama kendi riskini getirir (aşağıda).

Bu yola geçilirse `compose.yml` ve `Caddyfile` kullanılır; Render'da ikisi de
devrede değildir, TLS'i ve yönlendirmeyi Render kendi yapar.

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

---

## İzleme

`/health` ucu GitHub Actions'tan yarım saatte bir kontrol ediliyor
(`.github/workflows/validator-health.yml`). Başarısız koşum, depo sahibine
GitHub tarafından e-posta ile bildirilir; ayrı bir izleme servisine hesap
açmaya gerek yok.

Kontrol iki şeye bakar: servis cevap veriyor mu (`ok:true`) ve kural setini
gerçekten yüklemiş mi (`rules_version` bildiriliyor mu). İkincisi önemli —
kural seti okunamazsa servis 200 dönüp doğrulama yapamayabilir.

**Aralığı 15 dakikanın altına çekmeyin.** Render'ın ücretsiz katmanı 15 dakika
atıl kalınca uykuya dalar; daha sık ping atmak izleme olmaktan çıkıp servisi
yapay olarak ayakta tutmaya döner. Uykuya dalma kabul edilmiş bir bedeldir,
dolanılacak bir engel değil.

Not: GitHub, 60 gün hiç etkinlik olmayan depolarda zamanlanmış iş akışlarını
devre dışı bırakır.
