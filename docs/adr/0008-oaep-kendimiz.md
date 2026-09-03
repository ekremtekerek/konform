# 0008 — RSA-OAEP kodlaması kendimiz yapılır, phpseclib eklenmez

Tarih: 3 Eylül 2026
Durum: **Kabul edildi**

## Bağlam

KSeF 2.0 her faturayı istemcide şifrelenmiş ister:

1. Rastgele AES-256 anahtarı ve IV üretilir, fatura **AES-256-CBC + PKCS#7**
   ile şifrelenir.
2. AES anahtarı Bakanlık'ın açık anahtarıyla **RSA-OAEP / SHA-256 /
   MGF1-SHA256** sarmalanır.

Birinci adım PHP'de sorunsuz: `openssl_encrypt()` bunu yapar.

İkinci adım sorunlu ve sebebi ölçüldü.

## Ölçüm

`openssl_public_encrypt()` varsayılan olarak OAEP'i **SHA-1** ile uygular.
Konteynerde doğrulandı: PHP'nin ürettiği blok, `openssl` komut satırıyla
`rsa_oaep_md:sha1` ile çözülüyor, `rsa_oaep_md:sha256` ile çözülmüyor.

Özeti seçmeye izin veren `digest_algo` parametresi **PHP 8.5.0**'da eklendi.
Eklenti ise `Requires PHP: 8.2` diyor. Yani yaygın barındırmaların çoğunda
(8.2–8.4) yerel OpenSSL bu şifrelemeyi yapamıyor ve KSeF SHA-1 ile
sarmalanmış bir anahtarı kabul etmiyor.

## Değerlendirilen seçenekler

**phpseclib eklemek.** Ölçüldü: `phpseclib/phpseclib` + iki paragonie paketi =
**362 PHP dosyası, 3,4 MB**. Eklentinin mevcut paketi ~4 MB; tek bir işlev
için boyutu neredeyse ikiye katlıyor. WordPress.org üzerinden dağıtılan bir
eklentide bu ciddi bir bedel.

**PHP 8.5 şartı koymak.** Polonya'nın zorunluluğu bugün yürürlükte; 8.5
yaygınlaşana kadar beklemek Polonya'yı yıllarca desteklememek demek.

**Kodlamayı kendimiz yapmak.** Seçilen yol.

## Karar

**EME-OAEP kodlaması (RFC 8017, 7.1.1) `Konform\Ksef\Encryption` içinde
yapılır; RSA işleminin kendisi OpenSSL'de kalır.** PHP 8.5 ve üstünde yerel
yol kullanılır.

"Kendi kriptonu yazma" kuralının bilinçli ve dar bir istisnasıdır. Gerekçe:

- **Yalnızca şifreleme var.** Çözme yok, dolayısıyla padding oracle yok.
- **Gizliye bağlı dallanma yok.** Açık anahtar ve rastgele tohumla çalışır.
- **Doğrulanabilir.** Yanlış kodlanmış bir OAEP bloğu sessizce geçmez:
  çözücü lHash'i ve `0x01` ayracını denetler, en ufak sapmada patlar. Yani
  OpenSSL'in kendi çözücüsüyle yapılan round-trip, kodlamanın bayt bayt
  doğru olduğunun güçlü kanıtıdır.

RSA matematiği, anahtar üretimi ve dolgu **doğrulaması** hiçbir zaman bize
ait değil.

## Testler

`EncryptionTest` şunları tutuyor:

- Kendi kodlamamız OpenSSL'in OAEP çözücüsü tarafından kabul ediliyor.
- 5 farklı uzunlukta × 5 tekrar round-trip. Tohum rastgele olduğu için tek
  geçiş kanıt sayılmıyor.
- Blok uzunluğu modülüse eşit, ilk bayt `0x00`.
- Aynı veri iki kez kodlanınca farklı blok çıkıyor.
- Anahtara sığmayan veri reddediliyor.

## Sınanmayan dalı sınamak

`wrap_key()` PHP sürümüne göre yol seçtiği için, geliştirme konteyneri 8.5
olduğundan **yerel yol hep kazanıyordu**; kullanıcıların çoğunun düştüğü dal
hiç çalışmıyordu.

Bu yüzden yol seçimi `wrap_key_using( ..., bool $native )` ile dışarıdan
verilebiliyor ve `test_both_wrapping_paths_produce_a_valid_key` ikisini de
zorluyor. Ortamın sürümü, hangi kodun sınandığını belirlememeli.

## Not

PHP 8.5 yaygınlaştığında kendi kodlamamız silinebilir. O gün gelene kadar iki
yol da aynı testten geçmek zorunda.
