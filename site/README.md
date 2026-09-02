# Açılış sayfası

Tek dosya, bağımlılıksız: `index.html`. Yazı tipleri Google Fonts'tan gelir,
başka hiçbir dış kaynak yoktur.

## Nerede barındırılır

Herhangi bir statik barındırmada çalışır. En kolayı GitHub Pages:
depo ayarlarından Pages → Source: `main` dalı, klasör `/site`.

Kendi alan adınıza koyacaksanız dosyayı olduğu gibi kopyalamak yeterlidir.

## Neyi anlatıyor

Konumlandırma kasıtlıdır ve rakip araştırmasına dayanır (bkz.
`docs/adr/0005-iletim-yapilacak-mi.md` ve `readme.txt`): sayfa "biz de
e-fatura yapıyoruz" demiyor — o yarışta genişlikte kaybediyoruz — **"faturanız
reddedilecek mi, kesmeden önce söyleyelim"** diyor.

Sayfa bir SaaS şablonu gibi değil, **ürünün kendi ekranı gibi** kurulmuştur:
başlıkta soru, altında ön uçuş raporunun verdict satırı, sonra eklentinin
render ettiği biçimde üç gerçek bulgu kartı. Ziyaretçi ürünü anlatan bir metin
değil, ürünün çıktısını görür.

"Ne yapmıyoruz" bölümü tam bir bölüm olarak durur ve kısaltılmamalıdır. En
güçlü rakip faturayı ağa gönderiyor; biz göndermiyoruz. Alıcının bunu satın
aldıktan sonra öğrenmesi hem iade hem kötü değerlendirme demektir.

## Güncellenmesi gerekenler

- WordPress.org onaylandığında: eklenti dizini bağlantısı eklenir, "awaiting
  review" cümlesi kaldırılır.
- Fiyat veya plan değişirse tablo elle güncellenir.
