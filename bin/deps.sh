#!/usr/bin/env sh
# Bagimliliklari kurar, Strauss ile onekler ve otomatik yukleyiciyi uretir.
#
# NEDEN BU BETIK VAR
#
# Windows'ta Docker Desktop'in bind mount'u, PHP'nin RecursiveDirectoryIterator
# cagrilarinda buyuk dizinleri EKSIK dondurur. Ayni dizin icin ayni surecte:
#
#   glob()                    -> 99 dosya
#   scandir()                 -> 99 dosya
#   RecursiveDirectoryIterator -> 52 dosya   <-- sessizce buduyor
#
# Strauss, post-strauss.php ve "composer dump-autoload" dizinleri bu yineleyici
# ile gezer. Sonuc: onekli agaca sinif dosyalari HIC kopyalanmaz, hicbir arac
# hata vermez ve zincir exit 0 doner. Sorun ilk kez FA(3) uzerinde goruldu
# (99 sinifin 47'si dusmustu); ayni kayip horstoeko/zugferd'in extended
# profilinde de vardi (52 -> 12).
#
# Cozum: tum zinciri konteyner ici diskte (overlay fs) calistirip sonucu
# kopyalamak. Kabuk araclari (cp, tar, find) bind mount'u eksiksiz okur;
# kopyalama yonu guvenlidir.
#
# Kullanim: deps.sh <eklenti-dizini> [composer install ek argumanlari...]
set -e

[ -n "$1" ] || { echo "kullanim: deps.sh <eklenti-dizini> [install argumanlari]" >&2; exit 2; }
[ -d "$1" ] || { echo "HATA: dizin yok: $1" >&2; exit 2; }

# Yollar mutlaklastiriliyor: asagida calisma alanina gecildigi icin goreli
# yollar (ve $0'in dizini) orada yanlis yeri gosterirdi.
SCRIPT_DIR=$( cd "$( dirname "$0" )" && pwd )
PLUGIN_DIR=$( cd "$1" && pwd )
shift

WORK=/tmp/konform-deps
rm -rf "$WORK"
mkdir -p "$WORK"

# Kaynagi calisma alanina tasi. tar kullaniliyor cunku bind mount'u eksiksiz
# okudugu olculdu; PHP yineleyicisi okumaz.
( cd "$PLUGIN_DIR" && tar cf - --exclude=./vendor --exclude=./vendor-prefixed . ) | ( cd "$WORK" && tar xf - )

cd "$WORK"

# dump-autoload vendor-prefixed/ dizinini tarar; kurulum sirasinda henuz
# olusmadigi icin bos olarak hazirlanir.
mkdir -p vendor-prefixed

composer install --no-interaction "$@"
php vendor/bin/strauss
php "$SCRIPT_DIR/post-strauss.php" "$WORK"
composer dump-autoload --optimize --no-interaction "$@"

# Sonucu geri tasi. Once hedefi bosalt, sonra kopyala.
rm -rf "$PLUGIN_DIR/vendor" "$PLUGIN_DIR/vendor-prefixed"
cp -r "$WORK/vendor" "$PLUGIN_DIR/vendor"
cp -r "$WORK/vendor-prefixed" "$PLUGIN_DIR/vendor-prefixed"

# Kopyalamanin eksiksiz oldugunu dogrula. Sessiz kayip bu projede bir kez
# uretime kadar gitti; bir daha gitmesin.
for tree in vendor vendor-prefixed; do
  src=$( cd "$WORK/$tree" && find . -type f | wc -l )
  dst=$( cd "$PLUGIN_DIR/$tree" && find . -type f | wc -l )
  if [ "$src" != "$dst" ]; then
    echo "HATA: $tree eksik kopyalandi (calisma alani=$src, hedef=$dst)" >&2
    exit 1
  fi
  echo "$tree: $dst dosya"
done
