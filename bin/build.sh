#!/usr/bin/env bash
# Konform - dagitim arsivi uretir (WordPress.org / Freemius).
#
# Kullanim: bash bin/build.sh [surum]
#
# Uretilen zip'in kokunde "konform/" dizini bulunur; WordPress eklenti
# arsivlerinin beklenen bicimi budur.
#
# Hazirlik dizini depo icindedir (build/), boylece composer servisinin var
# olan /repo baglantisi kullanilir ve ek mount gerekmez.
set -euo pipefail

# Git Bash konteyner icindeki yollari Windows yoluna cevirmeye calisir.
export MSYS_NO_PATHCONV=1

cd "$(dirname "$0")/.."

BUILD="build"
STAGE="$BUILD/konform"

VERSION="${1:-$(grep -oE '^ \* Version: +[0-9A-Za-z.-]+' plugin/konform/konform.php | awk '{print $3}')}"

echo "==> Konform $VERSION paketleniyor"

rm -rf "$BUILD"
mkdir -p "$STAGE"

echo "==> Kaynak kopyalaniyor"
tar -cf - -C plugin/konform \
  --exclude=vendor \
  --exclude=vendor-prefixed \
  --exclude=tests \
  --exclude=.gitignore \
  . | tar -xf - -C "$STAGE"

compose() { docker compose run --rm -T -w "/repo/$1" composer "${@:2}"; }

# composer dump-autoload vendor-prefixed/ dizinini tarar; kurulum sirasinda
# henuz olusmadigi icin bos olarak hazirlanir.
mkdir -p "$STAGE/vendor-prefixed"

echo "==> Bagimliliklar kuruluyor"
compose "$STAGE" composer install --no-interaction >/dev/null

echo "==> Bagimliliklar izole ediliyor (Strauss)"
compose "$STAGE" php vendor/bin/strauss >/dev/null
compose "." php bin/post-strauss.php "/repo/$STAGE" >/dev/null

echo "==> Gelistirme paketleri temizleniyor"
# vendor/ icinde KALMASI gerekenler: onekLENMEYEN uretim paketleri.
# Freemius SDK bunlardan biridir - SDK surum tahkimi global sinifi paylasmaya
# dayanir, oneklenirse lisanslama bozulur. Bu yuzden "composer disinda her seyi
# sil" yerine uretim listesinden onekli olanlari cikararak calisiyoruz.
KEEP="$BUILD/.keep-list"
compose "$STAGE" composer show --no-dev --name-only --no-interaction 2>/dev/null   | tr -d '' | awk 'NF' > "$KEEP"

find "$STAGE/vendor" -mindepth 1 -maxdepth 1 -type d -not -name composer | while read -r dir; do
  vendor_name="$(basename "$dir")"
  for pkg in $(ls "$dir" 2>/dev/null); do
    full="$vendor_name/$pkg"
    if grep -qx "$full" "$KEEP" 2>/dev/null && [ ! -d "$STAGE/vendor-prefixed/$full" ]; then
      continue
    fi
    rm -rf "$dir/$pkg"
  done
  rmdir "$dir" 2>/dev/null || true
done
rm -f "$KEEP"
compose "$STAGE" composer dump-autoload --no-dev --optimize --no-interaction >/dev/null

rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

echo "==> Arsivleniyor"
# zip her makinede kurulu degil (Git Bash'te yok, GNU tar zip uretemez);
# konteynerdekini kullaniyoruz.
compose "$BUILD" sh -c "zip -qr konform-$VERSION.zip konform"

echo
echo "Hazir: $BUILD/konform-$VERSION.zip"
printf "  boyut : %s KB\n" "$(du -k "$BUILD/konform-$VERSION.zip" | cut -f1)"
printf "  dosya : %s\n" "$(unzip -l "$BUILD/konform-$VERSION.zip" | tail -1 | awk '{print $2}')"
