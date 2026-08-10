#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

echo "[docker] starting services..."
docker compose up -d db wordpress

echo "[docker] waiting for database to be ready..."
for i in {1..60}; do
  if docker compose exec -T db mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "[docker] waiting for WordPress container to be ready..."
for i in {1..60}; do
  if docker compose exec -T wordpress bash -lc 'test -f /var/www/html/wp-config.php' >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "[docker] waiting for WordPress to connect to DB..."
for i in {1..60}; do
  if docker compose run --rm wpcli db check >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "[wp] installing core (if not installed)..."
if ! docker compose run --rm wpcli core is-installed >/dev/null 2>&1; then
  docker compose run --rm wpcli core install \
    --url="http://localhost:8080" \
    --title="RestoreOne Test" \
    --admin_user="admin" \
    --admin_password="admin" \
    --admin_email="admin@example.com" \
    --skip-email
fi

# Official wordpress:7.0-php8.2-apache may lag latest patch (e.g. 7.0.2); pin to 7.0.3 for local/FT parity.
TARGET_WP="${MR_FT_WP_CORE_VERSION:-7.0.3}"
CUR_WP="$(docker compose run --rm wpcli core version 2>/dev/null | tr -d '\r' | tail -n 1 || true)"
echo "[wp] core version: ${CUR_WP:-unknown} (target ${TARGET_WP})"
if [[ -n "${CUR_WP}" && "${CUR_WP}" != "${TARGET_WP}" ]]; then
  echo "[wp] updating core to ${TARGET_WP}…"
  docker compose run --rm wpcli core update --version="${TARGET_WP}" --force || true
  docker compose run --rm wpcli core update-db || true
fi

echo "[wp] syncing plugin source into wp-content/plugins (excluding dev-only folders)..."
docker compose exec -T wordpress bash -lc '
  set -e
  src=/tmp/museder-restoreone-src
  dst=/var/www/html/wp-content/plugins/museder-restoreone
  rm -rf "$dst"
  mkdir -p "$dst"
  tar -C "$src" \
    --exclude=./.DS_Store \
    --exclude=./.github \
    --exclude=./tests \
    --exclude=./logs \
    --exclude=./docs \
    --exclude=./dist \
    --exclude=./release \
    --exclude=./tools \
    --exclude=./tmp \
    --exclude=./.worktrees \
    --exclude=./create-package.sh \
    --exclude=./docker-compose.yml \
    --exclude=./VERSION_DEVELOPMENT_HIGHLIGHTS.md \
    --exclude=./.git \
    --exclude=./.gitignore \
    --exclude=./.cursor \
    -cf - . | tar -C "$dst" -xf -
'

echo "[wp] activating plugin..."
docker compose run --rm wpcli plugin activate museder-restoreone

echo "[wp] installing theme + required plugins..."
docker compose run --rm wpcli theme install /tmp/test-one/museder-blank-theme.zip --activate

# Elementor (must be installed from wp.org per requirements).
docker compose run --rm wpcli plugin install elementor --activate

# Local premium plugins for test matrix.
docker compose run --rm wpcli plugin install /tmp/test-one/elementor-pro-3.34.0.zip --activate || true
docker compose run --rm wpcli plugin install /tmp/test-one/powerpack-elements-pro_2.12.15.zip --activate || true

# Common ecosystem plugins (from wp.org).
docker compose run --rm wpcli plugin install woocommerce wordpress-seo contact-form-7 classic-editor wordfence-login-security --activate

docker compose run --rm wpcli rewrite structure '/%postname%/' --hard
docker compose run --rm wpcli rewrite flush --hard

echo "[done] WordPress is ready at http://localhost:8080 (admin/admin)"

