#!/usr/bin/env sh
# Otomatik yukleyiciyi konteyner ici diskte uretip sonucu geri kopyalar.
#
# NEDEN AYRI BIR BETIK
#
# "composer dump-autoload" siniflari RecursiveDirectoryIterator ile tarar ve
# Windows'ta Docker Desktop'in bind mount'u bu yineleyicide buyuk dizinleri
# EKSIK dondurur (ayni dizinde scandir 99, yineleyici 52 gorebiliyor).
#
# Bu, burada teorik bir risk degil: Konform\Vendor\* siniflarinin psr-4
# karsiligi YOKTUR, yalnizca classmap uzerinden cozulurler. Budanmis bir
# classmap, calisma aninda "class not found" demektir.
#
# Ayrintili teshis ve olcumler icin bkz. bin/deps.sh
#
# Kullanim: dump-autoload.sh <eklenti-dizini> [dump-autoload ek argumanlari...]
set -e

[ -n "$1" ] || { echo "kullanim: dump-autoload.sh <eklenti-dizini> [argumanlar]" >&2; exit 2; }
[ -d "$1" ] || { echo "HATA: dizin yok: $1" >&2; exit 2; }

PLUGIN_DIR=$( cd "$1" && pwd )
shift

WORK=/tmp/konform-dump
rm -rf "$WORK"
mkdir -p "$WORK"

# Kabuk araclari bind mount'u eksiksiz okur; PHP yineleyicisi okumaz.
( cd "$PLUGIN_DIR" && tar cf - . ) | ( cd "$WORK" && tar xf - )

cd "$WORK"
composer dump-autoload --no-interaction "$@"

# Yalnizca uretilen otomatik yukleyici geri tasinir; paketlere dokunulmaz.
rm -rf "$PLUGIN_DIR/vendor/composer"
cp -r "$WORK/vendor/composer" "$PLUGIN_DIR/vendor/composer"

src=$( cd "$WORK/vendor/composer" && find . -type f | wc -l )
dst=$( cd "$PLUGIN_DIR/vendor/composer" && find . -type f | wc -l )

if [ "$src" != "$dst" ]; then
  echo "HATA: otomatik yukleyici eksik kopyalandi (calisma alani=$src, hedef=$dst)" >&2
  exit 1
fi

echo "otomatik yukleyici: $dst dosya"
