#!/usr/bin/env bash
# Shared helpers for functional-test scripts (source with: source "$(dirname "$0")/lib.sh")
ft_root() {
  (cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
}

ft_wait_db() {
  local i
  for i in $(seq 1 60); do
    if docker compose exec -T db mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  return 1
}

ft_wait_wp_config() {
  local i
  for i in $(seq 1 60); do
    if docker compose exec -T wordpress bash -lc 'test -f /var/www/html/wp-config.php' >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  return 1
}

ft_sync_plugin_into_container() {
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
      --exclude=./phpcs.xml.dist \
      --exclude=./tools/phpcs \
      -cf - . | tar -C "$dst" -xf -
  '
}

# Ensure installed WordPress core matches a target version (default 7.0.3).
# Official Docker images may lag patch releases (e.g. image ships 7.0.2 while
# wordpress.org latest is 7.0.3); FT scripts should call this after core install.
ft_ensure_wp_core_version() {
  local want="${1:-${MR_FT_WP_CORE_VERSION:-7.0.3}}"
  local cur
  if ! docker compose run --rm wpcli core is-installed >/dev/null 2>&1; then
    echo "[ft] skip core version pin — WordPress not installed"
    return 0
  fi
  cur="$(docker compose run --rm wpcli core version 2>/dev/null | tr -d '\r' | tail -n 1 || true)"
  echo "[ft] WordPress core version: ${cur:-unknown} (want ${want})"
  if [[ "${cur}" == "${want}" ]]; then
    return 0
  fi
  echo "[ft] Updating WordPress core to ${want} …"
  docker compose run --rm wpcli core update --version="${want}" --force
  docker compose run --rm wpcli core update-db || true
  cur="$(docker compose run --rm wpcli core version 2>/dev/null | tr -d '\r' | tail -n 1 || true)"
  echo "[ft] WordPress core version after update: ${cur:-unknown}"
  if [[ "${cur}" != "${want}" ]]; then
    echo "[ft] ERROR: expected WordPress ${want}, got ${cur:-unknown}" >&2
    return 1
  fi
}
