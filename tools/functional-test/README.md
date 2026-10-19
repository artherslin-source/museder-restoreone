# Functional tests (development repository only)

These scripts are **not** shipped in the WordPress.org plugin ZIP (`tools/` is excluded from packaging).

## Prerequisites

- Docker / Docker Compose
- For small stack: host port **8090** free by default, or set **`MR_FT_HOST_PORT`** (e.g. `8190`) + matching **`MR_FT_WP_URL`** (`tools/docker/docker-compose.ft-small-8090.yml` uses `${MR_FT_HOST_PORT:-8090}:80`)
- For default large checks: root `docker-compose.yml` on port **8080**, WordPress installed (`tools/docker/setup.sh`)

## Commands

| Script | Purpose |
|--------|---------|
| `./tools/functional-test/run-clean-small-site-ft.sh` | Fresh **WP 7.0.x** site (pinned to **7.0.3** via `ft_ensure_wp_core_version`), WooCommerce + common plugins, Plugin Check, regression, chunk REST smoke, cron list. Log under `reports/`. Override with `MR_FT_WP_CORE_VERSION`. |
| `./tools/functional-test/run-functional-test.sh` | Default stack: Plugin Check, regression, chunk smoke, optional `make-large-uploads.sh`. **PclZip forced** full backup runs on the small stack by default; on the default stack set `MR_FT_INCLUDE_PCLZIP=1` only when the install is small enough. |
| `MR_FT_RUN_LARGE=1 ./tools/functional-test/run-functional-test.sh` | Also runs **3GB sparse** file skip test (`wp-backup-skip-large-smoke.php`). Needs disk space. |
| `./tools/functional-test/scan-plugin-external-urls.sh` | Host-side `rg` scan of `wp_remote_*` / `curl_*` / selected `https://` literals in `includes/` + main plugin (writes `reports/ft-external-url-scan-*.log`). |
| `wp eval-file tools/functional-test/wp-ft-rest-permissions-smoke.php` | REST: unauthenticated + subscriber + nonce expectations for `/museder-restoreone/v1/ai/scan`. |
| `wp eval-file tools/functional-test/wp-ft-safe-path-join.php` | Asserts `museder_restoreone_safe_path_join()` rejects traversal / absolute escapes. |
| `wp eval-file tools/functional-test/wp-ft-uninstall-manifest.php` | Confirms root `uninstall.php` markers and absence of filesystem delete helpers. |

## UI/UX

After `run-clean-small-site-ft.sh`, open `http://localhost:8090/wp-admin` (use `tools/docker/mu-plugins/restoreone-e2e-login.php` when mounted). Check **Dashboard, Backups, Restore, Schedules, Logs, Settings** in **Light** and **Dark** UI theme; confirm no console errors from RestoreOne assets.

## WP-CLI one-offs

```bash
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-rest-chunk-smoke.php
docker compose run --rm wpcli --require=/tmp/museder-restoreone-src/tools/functional-test/force-no-ziparchive.php eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-pclzip-minimal-backup.php
```
