#!/usr/bin/env bash
# Konform - tek seferlik gelistirme ortami kurulumu.
# Kullanim: bash bin/setup.sh
set -euo pipefail

cd "$(dirname "$0")/.."
[ -f .env ] || cp .env.example .env
# shellcheck disable=SC1091
set -a; . ./.env; set +a

# DIKKAT: wordpress:cli imaji komutu dogrudan exec eder, 'wp' onekini kendisi
# eklemez. Ayni sey composer servisi icin de gecerli.
wp() { docker compose run --rm -T wpcli wp "$@"; }

echo "==> Konteynerler baslatiliyor"
docker compose up -d

echo "==> WordPress hazir olmasi bekleniyor"
tries=0
until wp core version >/dev/null 2>&1; do
  tries=$(( tries + 1 ))
  if [ "$tries" -ge 30 ]; then
    echo "HATA: WordPress 60 saniyede yanit vermedi." >&2
    echo "      docker compose logs wordpress  ile inceleyin." >&2
    exit 1
  fi
  sleep 2
done

if wp core is-installed >/dev/null 2>&1; then
  echo "==> WordPress zaten kurulu, atlaniyor"
else
  echo "==> WordPress kuruluyor"
  wp core install \
    --url="${WP_URL}" \
    --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
fi

echo "==> Dil paketi: ${WP_LOCALE}"
wp language core install "${WP_LOCALE}" >/dev/null 2>&1 || true
wp site switch-language "${WP_LOCALE}" >/dev/null 2>&1 || true

if wp plugin is-installed woocommerce >/dev/null 2>&1; then
  echo "==> WooCommerce zaten kurulu"
  wp plugin activate woocommerce >/dev/null 2>&1 || true
else
  echo "==> WooCommerce kuruluyor (biraz surebilir)"
  wp plugin install woocommerce --activate
fi

echo "==> Magaza ulkesi: ${WC_STORE_COUNTRY} (regulasyon profili)"
wp option update woocommerce_default_country "${WC_STORE_COUNTRY}" >/dev/null
wp option update woocommerce_currency EUR >/dev/null
wp option update woocommerce_calc_taxes yes >/dev/null

echo "==> Konform etkinlestiriliyor"
wp plugin activate konform || echo "   UYARI: etkinlestirilemedi"

echo
echo "Hazir:  ${WP_URL}/wp-admin"
echo "Giris:  ${WP_ADMIN_USER} / ${WP_ADMIN_PASSWORD}"
wp plugin list --fields=name,status,version 2>/dev/null || true
