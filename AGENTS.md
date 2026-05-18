# Museder RestoreOne — Development Guide

## Overview

WordPress plugin for backup/restore operations. Pure PHP codebase (no npm/pip/composer). Development environment runs via Docker Compose (MariaDB + WordPress + WP-CLI).

## Cursor Cloud specific instructions

### Services

| Service | Container | Port | Purpose |
|---------|-----------|------|---------|
| MariaDB | workspace-db-1 | 3306 (internal) | WordPress database |
| WordPress | workspace-wordpress-1 | 8080 → 80 | WordPress + Apache with plugin mounted |
| WP-CLI | workspace-wpcli (ephemeral) | — | One-shot admin commands |

### Starting the environment

The update script handles `dockerd` startup and `docker compose up -d db wordpress`. After services are up, if WordPress core is not yet installed or plugin is not yet synced, run:

```bash
cd /workspace

# Install WP core (idempotent)
docker compose run --rm wpcli core is-installed 2>/dev/null || \
  docker compose run --rm wpcli core install \
    --url="http://localhost:8080" \
    --title="RestoreOne Test" \
    --admin_user="admin" \
    --admin_password="admin" \
    --admin_email="admin@example.com" \
    --skip-email

# Sync plugin source (run after code changes)
docker compose exec -T wordpress bash -lc '
  src=/tmp/museder-restoreone-src
  dst=/var/www/html/wp-content/plugins/museder-restoreone
  rm -rf "$dst" && mkdir -p "$dst"
  tar -C "$src" --exclude=./logs --exclude=./docs --exclude=./dist --exclude=./release --exclude=./.git --exclude=./.cursor -cf - . | tar -C "$dst" -xf -
'

# Activate plugin (idempotent)
docker compose run --rm wpcli plugin activate museder-restoreone
```

### Important caveats

- **Plugin sync required after code changes**: The repo is mounted read-only at `/tmp/museder-restoreone-src` in the WordPress container. After editing PHP files, re-run the sync command above to copy changes into the actual plugin directory.
- **No hot-reload**: PHP changes require the sync step; there's no file-watcher in place.
- **Test assets (`logs/test-one/`)**: Premium plugin ZIPs (Elementor Pro, PowerPack) are not in git. The full `tools/docker/setup.sh` will fail on those steps. Use the simplified setup above instead.
- **WordPress admin credentials**: `admin` / `admin` at `http://localhost:8080/wp-admin/`
- **PHP linting**: Run inside the container: `docker compose exec -T wordpress bash -c 'find /var/www/html/wp-content/plugins/museder-restoreone -name "*.php" -exec php -l {} \;'`
- **WP-CLI wrapper**: `tools/docker/wp.sh` is a convenience script for running WP-CLI commands.
- **DB connection wait**: On first boot, the WP-CLI `db check` can take up to 2 minutes for the first successful connection. Subsequent runs are instant.

### Packaging

To create a distributable ZIP for the plugin:

```bash
bash create-package.sh
```
