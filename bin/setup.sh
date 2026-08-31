#!/usr/bin/env bash
# Konform - tek seferlik gelistirme ortami kurulumu.
# Kullanim: bash bin/setup.sh
set -euo pipefail

cd "$(dirname "$0")/.."
[ -f .env ] || cp .env.example .env
# shellcheck disable=SC1091
set -a; . ./.env; set +a

wp() { docker compose run --rm -T wpcli "$@"; }

echo "==> Konteynerler baslatiliyor"
docker compose up -d

echo "==> WordPress hazir olmasi bekleniyor"
until wp core is-installed --allow-root >/dev/null 2>&1 || wp core version >/dev/null 2>&1; do
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
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
wp language core install "${WP_LOCALE}" || true
wp site switch-language "${WP_LOCALE}" || true

echo "==> WooCommerce kuruluyor"
wp plugin install woocommerce --activate

echo "==> Magaza ulkesi: ${WC_STORE_COUNTRY} (regulasyon profili)"
wp option update woocommerce_default_country "${WC_STORE_COUNTRY}"
wp option update woocommerce_currency EUR
wp option update woocommerce_calc_taxes yes

echo "==> Konform etkinlestiriliyor"
wp plugin activate konform || echo "   (henuz etkinlestirilemedi - normal)"

echo
echo "Hazir:  ${WP_URL}/wp-admin"
echo "Giris:  ${WP_ADMIN_USER} / ${WP_ADMIN_PASSWORD}"
