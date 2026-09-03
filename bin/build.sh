#!/usr/bin/env bash
# Konform - dagitim arsivi uretir (WordPress.org / Freemius).
#
# Kullanim: bash bin/build.sh [surum] [--premium]
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

# Iki varyant uretilir ve ayrimi tek bir bayrak belirler:
#
#   bash bin/build.sh              -> ucretsiz surum, WordPress.org'a gider
#   bash bin/build.sh --premium    -> ucretli surum, Freemius'a gider
#
# Fark yalnizca Freemius SDK'sinin is_premium bayragidir. Bu bayrak bir OZELLIK
# KAPISI DEGILDIR - o yanilgiya dusulmustu, olcumle duzeltildi:
#
#   can_use_premium_code() { return is_trial() || has_features_enabled_license(); }
#
# Yani lisansi olan bir kullanicida Pro, is_premium kapali olsa da acilir.
#
# Bayragin isi, calisan yapinin hangisi oldugunu isaretlemektir. SDK guncelleme
# isteginde "is_premium() || _can_download_premium()" diye bakar; premium paket
# musterinin indirdigi urundur. Freemius'a yuklenen zip bu yuzden premium
# olmalidir - aksi halde surumler ucretsiz yapi olarak dagitilir.
PREMIUM=0
ARGS=()

for arg in "$@"; do
  if [ "$arg" = "--premium" ]; then
    PREMIUM=1
  else
    ARGS+=("$arg")
  fi
done

VERSION="${ARGS[0]:-$(grep -oE '^ \* Version: +[0-9A-Za-z.-]+' plugin/konform/konform.php | awk '{print $3}')}"

if [ "$PREMIUM" = "1" ]; then
  SUFFIX="-premium"
  echo "==> Konform $VERSION PREMIUM paketleniyor"
else
  SUFFIX=""
  echo "==> Konform $VERSION paketleniyor"
fi

# Yalnizca hazirlik dizini silinir, build/ dizini degil: ucretsiz ve premium
# varyantlar art arda uretiliyor ve ilkinin zip'i ikincisini uretirken
# silinmemeli.
#
# Windows'ta dosya kilidi yuzunden silme gecici olarak basarisiz olabilir ve
# set -e tum yapiyi dusurur. Birkac kez denenir.
for attempt in 1 2 3; do
  rm -rf "$STAGE" 2>/dev/null || true
  [ -d "$STAGE" ] || break
  sleep 2
done

if [ -d "$STAGE" ]; then
  echo "HATA: $STAGE silinemedi. Docker konteynerleri dosyayi tutuyor olabilir." >&2
  exit 1
fi

mkdir -p "$STAGE"

echo "==> Kaynak kopyalaniyor"
tar -cf - -C plugin/konform \
  --exclude=vendor \
  --exclude=vendor-prefixed \
  --exclude=tests \
  --exclude=.gitignore \
  --exclude=phpunit.xml.dist \
  --exclude=.phpunit.cache \
  . | tar -xf - -C "$STAGE"

if [ "$PREMIUM" = "1" ]; then
  # Yalnizca HAZIRLIK dizinindeki kopya degistirilir; depodaki kaynak
  # ucretsiz surumdur ve oyle kalir.
  FS_FILE="$STAGE/src/License/Freemius.php"

  sed -i "s/'is_premium'       => false,/'is_premium'       => true,/" "$FS_FILE"

  if ! grep -q "'is_premium'       => true," "$FS_FILE"; then
    echo "HATA: is_premium bayragi degistirilemedi. Freemius.php'deki hizalama degismis olabilir." >&2
    exit 1
  fi

  echo "==> is_premium bayragi acildi"
fi

compose() { docker compose run --rm -T -w "/repo/$1" composer "${@:2}"; }

# Kurulum, Strauss ve otomatik yukleyici uretimi bilerek deps.sh uzerinden
# calisir: hepsi dizinleri PHP'nin RecursiveDirectoryIterator'uyla gezer ve
# Windows bind mount'u o yineleyicide buyuk dizinleri eksik dondurur. Dogrudan
# calistirilirlarsa sinif dosyalari sessizce pakete girmez. Bkz. bin/deps.sh
echo "==> Bagimliliklar kuruluyor ve izole ediliyor (Strauss)"
compose "." sh bin/deps.sh "/repo/$STAGE" >/dev/null

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

# vendor/composer/ yukaridaki dongude atlanir, cunku otomatik yukleyici
# dosyalarini barindirir ve onlar kalmalidir. Ama ayni dizinde Strauss'un
# cektigi composer paketleri de vardir (composer/composer, composer/pcre,
# composer/semver ...). Atlanan dizin hic taranmadigi icin Composer'in kendisi
# eklentiyle birlikte dagitiliyordu. Burada yalnizca ALT DIZINLER, yani
# paketler degerlendirilir; dosyalara dokunulmaz.
find "$STAGE/vendor/composer" -mindepth 1 -maxdepth 1 -type d | while read -r pkg_dir; do
  full="composer/$(basename "$pkg_dir")"
  if grep -qx "$full" "$KEEP" 2>/dev/null && [ ! -d "$STAGE/vendor-prefixed/$full" ]; then
    continue
  fi
  rm -rf "$pkg_dir"
done

rm -f "$KEEP"
# Temizlikten sonra classmap yeniden uretilmeli. Bu adim da izole calisir:
# Konform\Vendor\* siniflarinin psr-4 karsiligi yoktur, yalnizca classmap'ten
# cozulurler; budanmis bir classmap calisma aninda olumcul hatadir.
compose "." sh bin/dump-autoload.sh "/repo/$STAGE" --no-dev --optimize >/dev/null

# composer.lock gitmez; kilit dosyasi gelistirme artefaktidir ve buyuktur.
#
# composer.json ise KALIR. WordPress.org'un otomatik taramasi, vendor/ dizini
# olup composer.json olmayan pakete "missing_composer_json_file" uyarisi
# veriyor - inceleyen kisi vendor/ icinde ne oldugunu dogrulayamiyor.
# Dosyayi birakmak uyariyi dolanmak degil, tam da taramanin istedigi seyi
# vermek. Bagimliliklarin vendor-prefixed/ altinda oneklendigini de
# composer.json'daki strauss bolumu acikliyor.
rm -f "$STAGE/composer.lock"

echo "==> Arsivleniyor"
# zip her makinede kurulu degil (Git Bash'te yok, GNU tar zip uretemez);
# konteynerdekini kullaniyoruz.
compose "$BUILD" sh -c "zip -qr konform-$VERSION$SUFFIX.zip konform"

echo
echo "Hazir: $BUILD/konform-$VERSION$SUFFIX.zip"
printf "  boyut : %s KB\n" "$(du -k "$BUILD/konform-$VERSION$SUFFIX.zip" | cut -f1)"
printf "  dosya : %s\n" "$(unzip -l "$BUILD/konform-$VERSION$SUFFIX.zip" | tail -1 | awk '{print $2}')"
