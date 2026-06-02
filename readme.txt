=== Museder RestoreOne ===
Contributors: artherslin
Tags: backup, migration, restore, site-backup, database-backup
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.7.275
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WordPress backup & restore plugin focused on compatibility, single-file site snapshots, and clean restore workflows.

== Description ==

Museder RestoreOne lets you create complete WordPress backups (database + `wp-content`) as a single archive, and restore them in a guided 3-step wizard.

It is designed for shared hosting environments and uses WordPress APIs for database backup/restore, with archive compression handled by `ZipArchive` or WordPress’ bundled PclZip.

**Key features**

* One-click full-site backup  
  Export the database, `meta.json`, and `wp-content/` into a single archive you can download or restore later.

* Restore Center wizard  
  A clear 3-step flow: upload & analyze → review summary & options → start restore with real-time progress and logs.

* Chunked uploads with validation  
  Bypass `upload_max_filesize` / `post_max_size` limits by uploading your archive in small chunks, with retries and integrity checks.

* Shared-hosting friendly  
  Uses pure PHP + WordPress APIs for database backup/restore, and falls back from `ZipArchive` to PclZip compression when needed.

* Schedules and logs  
  Create one or more automatic backup schedules (or run backups manually), then inspect, download, or clean up structured backup and restore logs.

* Neo-glass admin UI  
  Modern Dashboard, Backups, Restore, Schedules, Logs and Settings screens with clear calls-to-action, status messages, and responsive layout.

**Multisite**

This release is **not formally tested on WordPress Multisite**. For predictable results, use RestoreOne on **standard single-site** installs (one site per admin context). If you run a network, treat use as **experimental** until you have verified backups and restores on a staging clone.

== External services ==

This plugin does not use external services.

The **only programmatic outbound HTTP** the base plugin performs by default is an optional, short **non-blocking** request to **your own site’s** `wp-cron.php` (same host / local loopback) to encourage scheduled tasks to run. No third-party API is called for backups or restores.

All **admin JavaScript and CSS** for RestoreOne are loaded from files shipped under this plugin’s `assets/` directory (including vendored libraries under `assets/vendor/`). Optional add-ons, if you install them separately, may introduce their own network behavior; see each add-on’s readme. See the FAQ for more on the local `wp-cron.php` nudge.

== Privacy ==

**What this plugin stores on your server**

* **Backups** — Complete-site archives are written under your WordPress uploads area (typically `wp-content/uploads/museder-restoreone/backups/` or the path shown on the Backups screen). Each archive contains a database export (`database.ndjson`), `meta.json`, and a copy of `wp-content/` from your site at backup time.  
* **Logs** — Text logs for backup, restore, and related operations are stored under `wp-content/uploads/museder-restoreone/logs/` (see the **Logs** admin screen).  
* **Restore reports** — When you generate a restore report, files are stored under `wp-content/uploads/museder-restoreone/reports/` (or the path configured for reports on your install).  
* **Schedules and settings** — Options and scheduled events are stored in your WordPress database like other plugins.

**Diagnostics**

* **Version / build heartbeat** — After an upgrade, the plugin may write a one-line informational entry to the local **Logs** directory (same server, no remote host) noting the active plugin version and build id. This is for support troubleshooting only.

Exact folder names may vary with your uploads path or custom content directory; nothing is sent to a fixed external hostname by this plugin. The plugin resolves these locations using WordPress APIs (for example `wp_upload_dir()` and path helpers derived from your install) rather than hard-coded internal constants, so custom `wp-content` or uploads layouts can be reflected correctly where your host allows.

**Third parties**

The free plugin does **not** upload your backup contents, database, or logs to third-party APIs or clouds. That statement matches **External services** above and the FAQ entries on external data and local `wp-cron` loopback.

Optional **add-ons** (separate plugins or extensions, if you install and activate them) could send specific categories of data to remote storage or services only when you enable and configure those extensions; the base RestoreOne plugin does not do that on its own.

**Retention and deletion**

You can delete backup archives from the **Backups** screen, remove or download logs from **Logs**, and adjust retention-related options where provided.

**Uninstall (`uninstall.php`)** removes plugin-owned **options**, **transients** (including timeout rows), **dynamic job-lock option rows**, and **scheduled cron hooks** whose names start with `museder_restoreone_`. It does **not** delete backup ZIP archives, log files, restore reports, or other files under your uploads/storage tree; delete those manually from the Backups / Logs UI or your host if you no longer need them.

On **Multisite**, uninstall walks sites in **batches** (100 IDs per query) instead of loading the entire network at once. Very large networks should still use a **maintenance window** so uninstall is not interrupted by web-server timeouts.

== Installation ==

1. Upload the `museder-restoreone` folder (or ZIP) to the `/wp-content/plugins/` directory via FTP or through the “Upload Plugin” screen in your WordPress admin.
2. Activate the plugin through the “Plugins” menu in WordPress.
3. Go to the **Museder RestoreOne** menu in your admin sidebar.
4. Open the **Backups** or **Restore** page and create your first backup.

== Frequently Asked Questions ==

= What does the backup archive contain? =

Each backup archive includes:

* `database.ndjson` — a structured export of your WordPress database (plugin-owned format).  
* `meta.json` — metadata about when and how the backup was created.  
* `wp-content/` — your themes, plugins, and uploads.

Together, these files are enough to recreate your site on the same or another server.

= Does compatibility with third-party backup formats imply an official partnership? =

**No.** RestoreOne may document or implement **technical compatibility** with certain third-party archive or migration formats so you can move data between tools on **your own server**. That compatibility is **not** an endorsement, partnership, or affiliation with those projects unless explicitly stated elsewhere by the authors.

= Are there any file size limits? =

Yes. For safety and compatibility, **single files larger than 2GB are skipped** during backup. This means they will not be included in the backup ZIP and will not be restored.

Sites larger than 2GB in total size can still be backed up and restored successfully as long as each individual file is smaller than 2GB.

When files are skipped, the backup completion message shows the skip reasons and examples.

= What happens if mysqldump is not available? =

Museder RestoreOne does not require `mysqldump`. Database backup/restore is implemented in pure PHP using WordPress database APIs.

= What if ZipArchive is not enabled on my server? =

If your server does not have the `ZipArchive` PHP extension, the plugin will automatically use WordPress’ built-in PclZip library to create and extract archives.

PclZip can be **slower** and more memory- or disk-sensitive on very large sites than `ZipArchive`. If a host blocks reading WordPress core’s `wp-admin/includes/class-pclzip.php` (for example via `open_basedir`), backup or restore may fail with a clear error in **Logs** — use the `museder_restoreone_core_admin_include_path` filter if your layout is non-standard (see FAQ below).

= How does chunked restore upload work over REST? =

Large archive uploads use the plugin’s **authenticated** REST API (`museder-restoreone/v2`). Chunk bytes are streamed from the HTTP request body (`php://input`) **only for that request**, assembled into temporary files under your WordPress uploads area, and **never forwarded** to third-party URLs. Multipart uploads use PHP’s normal uploaded-file handling instead. All chunk routes require `manage_options` and a valid REST nonce.

= Will scheduled backups and email always run? =

Scheduled backups depend on **WordPress cron** (or your host’s **system cron** if `DISABLE_WP_CRON` is enabled). Email notifications depend on your server’s **`wp_mail`** configuration (SMTP plugin, host mail relay, etc.). If cron or mail is blocked, use **Settings → Send Test Email**, check **Logs**, and configure host cron / mail as needed.

= Can I run a full restore (execute) on a very large archive? =

Very large restores may hit **PHP time limits**, **web server timeouts**, or **disk space** constraints on shared hosting. The Restore wizard supports **validate** and **dry run** steps so you can verify an archive before a full **execute**. For huge sites, prefer a **staging clone** or **WP-CLI**-driven restore where your host allows long-running PHP.

= Where are the logs stored? =

All logs are stored under:

`wp-content/uploads/museder-restoreone/logs/`

You can view or download the latest logs directly from the **Logs** page in the Museder RestoreOne admin menu.

= What happens to plugins during restore? =

Optional **safe mode** (chosen in the Restore screen) saves a snapshot of the active plugin list and sets an admin notice so you can verify the site before clearing the marker. RestoreOne does **not** automatically deactivate or reactivate other plugins; you manage plugins in WordPress as usual. **Exit Safe Mode** only clears the marker and the stored snapshot.

= Does this plugin send data to external services? =

No. This plugin runs entirely on your server and does not send backup contents or site data to any external API or cloud service as part of the free base plugin.

The **Offline readiness / local rules** scan on the Dashboard uses **local heuristics** only (no remote AI service is invoked by the shipped free build).

= Does the plugin make HTTP requests to my own site? =

Sometimes. To help scheduled tasks run promptly, the plugin may send a short, non-blocking HTTP request to your own site's `wp-cron.php` (a local loopback). That stays on your server, does not transmit backup contents to third parties, and is a common WordPress pattern. If `DISABLE_WP_CRON` is enabled, your host may rely on system cron instead.

= Does this plugin expose my backup files publicly? =

No. Backup download and upload endpoints are protected by time-limited tokens and secret keys generated inside your WordPress site. Only users with access to your WordPress admin can generate valid links, and each link expires after a short period of time.

= Does Museder RestoreOne support WordPress Multisite? =

Not as a formally supported configuration in this release. The plugin is built and QA’d primarily for **single-site** WordPress. Multisite networks may behave differently across subsites, uploads paths, and roles; use on Multisite only after your own testing on a **staging copy** of the network.

= Can I use a custom languages directory? =

Yes, advanced sites can override the detected languages directory with the `museder_restoreone_languages_dir` filter. Return an absolute path without a trailing slash, or return an empty string to skip language-directory handling.

= Can I use a custom mu-plugins directory? =

Yes, advanced sites can override the detected must-use plugins directory with the `museder_restoreone_mu_plugins_dir` filter. Return an absolute path without a trailing slash, or return an empty string if your site does not use a must-use plugins directory.

= What if my WordPress core admin include files are in a non-standard location? =

Most sites do not need any changes. For unusual server layouts where core admin API files cannot be found automatically, developers can use the `museder_restoreone_core_admin_include_path` filter to return a readable absolute path for the requested core file. Invalid or unreadable values are ignored and the plugin falls back to its default resolution.

== Screenshots ==

1. Dashboard with environment compatibility, recent backups, and schedule overview.
2. Backups page showing available backups and the backup progress bar.
3. Restore Center 3-step wizard: upload & analyze, review options, execute restore.
4. Schedules page listing upcoming backup jobs and quick schedule builder.
5. Logs page with log file list and preview panel.
6. Settings page with general options and system diagnostics.

== Developer ==

Development source of truth is the public GitHub repository: https://github.com/artherslin-source/museder-restoreone

Contributors and release maintainers should follow the **Modification & Release SOP** (`docs/SOP-PLUGIN-DEVELOPMENT-AND-RELEASE.md` in the repo). Summary:

* **Branching:** work on `fix/`, `feature/`, or `agent/` branches from current `main`; avoid long-lived dirty `main`.
* **WordPress.org Lite:** fully functional, GPL, no license gates, trialware, or bundled Add-on/PRO code in the Lite package or SVN.
* **Security:** every sensitive AJAX/REST/form handler needs capability checks, nonces, sanitize/validate input, and escaped output.
* **Release version sync (six fields, same version):** `museder-restoreone.php` `Version`, `MUSEDER_RESTOREONE_VERSION`, `MUSEDER_RESTOREONE_BUILD_ID`, `readme.txt` `Stable tag`, new `Changelog` block, and new **`Upgrade Notice`** block (easy to forget — WP.org shows the notice to updaters).
* **Package Lite ZIP:** use `create-package.sh` or `tools/package-lite-windows.ps1` only; require `BOUNDARY_CHECK=PASS`. Do not use PowerShell `Compress-Archive`. Lite ZIP must not include `docs/`, `tools/`, `.cursor/`, `.github/`, `logs/`, or `museder-restoreone-pro/`.
* **GitHub release:** after explicit approval, tag `vX.Y.Z` on `main`; GitHub Actions builds release artifacts.
* **WordPress.org SVN:** commit Lite source files only (no zip); copy `trunk` to `tags/X.Y.Z`; fill `docs/RELEASE-X.Y.Z-SVN-HANDOFF.md` from the template before SVN commit.

Checklists: `docs/RELEASE_CHECKLIST.md`, `docs/PACKAGING.md`, `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`, `AGENTS.md`.

== Changelog ==

= 2.7.275 =
* Restore UI: Step 3 success is now a one-way state — once REST final-status confirms completion, the UI no longer re-enters admin-ajax polling or resets progress when a stale “running” payload arrives.
* Restore UI: when admin-ajax or wp-login interim-login is blocked (403/400), Step 3 switches to a REST-only completion loop instead of retrying nonce refresh and tight admin-ajax polling.
* Restore UI: suppresses WordPress auth-check / Heartbeat interim-login modals during restore and raises the completion overlay above auth-check layers so “Restore Completed” is visible on hostile shared hosts.
* Restore UI: long-running restores now use a wider history match window and strict `payload.job` parsing so Step 3 no longer gets stuck at “100% / Waiting for action” after the backend has already completed and cleaned active jobs.
* Restore UI: final-status polling now falls back from stale `/wp-json` routes to query-style `?rest_route=` URLs, detects “HTTP 200 HTML” non-JSON responses, and adds a read-only admin-ajax `restore_final_status` endpoint as a final completion source.
* Restore auth: restore token verification now uses a plugin-controlled stable HMAC secret so token checks survive `wp-config.php` salt replacement during restore (including post-complete read grants).
* Restore: prefix migration now performs collision-safe targeted key migration for WordPress role/capability keys (`*_user_roles`, `*_capabilities`, `*_user_level`) instead of broad prefix key renames that could fail with duplicate `option_name` entries on populated targets.

= 2.7.274 =
* Restore: sliced ZIP extraction now writes to a sidecar `*.museder-restoreone-partial` file and atomically renames on completion so WordPress core files are never left half-written when a time slice ends (fixes admin critical errors mid-restore on shared hosting).
* Restore: phase-aware file-stage progress (wp-content vs core) uses filtered entry counts instead of whole-archive index ratios (fixes misleading ~85% at start of core extraction).
* Restore: stale-tick watchdog re-schedules WP-Cron when a running job has not progressed recently.
* Restore: defer `wp-config.php` until the file stage completes; on populated hosts with `db_then_files`, backup mode now merges live DB credentials and table prefix instead of overwriting mid-restore (fixes database connection errors after reload).
* Restore: wp-config merge uses parenthesis-aware parsing so `define()` values with nested calls (e.g. Docker `getenv_docker()`) are preserved without PHP parse errors.
* Restore: media path reconciliation now advances past index building and repairs UTF-8 database attachment paths that map to ASCII filenames on disk, preventing restored pages from showing missing images.
* Backup: detects and logs attachment DB-to-disk media path drift before packaging so future archives surface filename mismatches instead of silently preserving them.
* Restore: media path reconciliation now expands attachment pairs to thumbnail size variants (-WxH), scans post content and post meta for stale uploads URLs, and rewrites attachment metadata so Elementor and inline images resolve after restore.
* Restore: media path apply_pairs now runs as a time-sliced database search-replace with row checkpoints so large pair sets no longer hit PHP max_execution_time during cleanup on shared hosting.
* Restore: reject unsafe media path pairs (empty filename stems) and map menu-icon attachments to on-disk ICON filenames when ASCII-fold matching is ambiguous.
* Restore: rehydrate active restore jobs from the restore lock after fatal timeouts so cron and the admin UI can resume cleanup instead of leaving Restore History stuck on Running.
* Restore: Image Widget / Max Mega Menu media URLs are synchronized from fixed attachment paths after restore so serialized widget raw URLs no longer keep stale uploads filenames.
* Restore UI: auth-check, session, or admin-ajax 403/500 interruptions during Step 3 keep monitoring the background job and reconcile to success when history/status shows completion.
* Restore UI: reload the Restore page after a completed job to show the success state when host polling was blocked mid-run.
* Restore UI: completed jobs now show a success notification from status polling, and repeated 401/403 polling blocks show a clearer host-blocking message.
* Restore UI: admin-ajax 400/403 host interruptions during Step 3 now stay in background monitoring mode instead of resetting the wizard before the backend job can report success.
* Restore: excludes RestoreOne runtime locks, active-job pointers, restore tokens, and transients from backup export/import, then clears stale imported runtime options after restore completion.
* Restore UI: adds a token-based REST final-status confirmation path so Step 3 can show success even when admin-ajax/auth-check is blocked after the backend job completes.
* Restore UI: failed cancel requests no longer reset the wizard while cron may still be restoring; the UI now keeps the active job and rechecks final status.

= 2.7.273 =
* Backup: fix post-close archive verification false failures on large ZipArchive backups (entry-index lookup + core-path prefix fallback) so shared hosts no longer trigger a destructive full PclZip repack.
* Backup: remove automatic full-archive PclZip repack after verify failure; accept structurally sound archives and fail fast when the archive is truly incomplete.
* Backup: defer finalize/verify to the tick after `ZipArchive::close()` so the closed archive is readable before verification runs.

= 2.7.272 =
* Backup Auto mode: align “large site” detection with the Backups page estimate warning (>1 GB total) — Auto now enables Fast mode and Smart Exclude when size or cached estimate exceeds threshold, not only when file count exceeds 50,000.
* Backup UI: clarify Auto status when standard (Balanced) mode applies on sites below Auto thresholds.

= 2.7.271 =
* Restore Step 3: page bootstrap now uses `map_restore_service_status_to_job()` so reload during an active restore resumes monitoring instead of resetting to “Ready to start”.
* Restore Step 3: polling/history fallbacks keep in-progress UI on transient AJAX errors; restore history table updates during same-page sessions.
* Restore: files-only jobs force `wp_config_mode: keep` so `wp-config.php` is not overwritten when the database is skipped.
* Restore: ZIP file-stage progress no longer jumps to 93% at the start of core extraction.

= 2.7.270 =
* Restore Step 1: separate **analysis** from **execute** preflight — populated-site overwrite requirement is a Step 2 warning, not a Step 1 failure (fixes “Analysis complete” + empty Summary + “Analysis failed” on chunk finalize).
* Restore: profile detection ignores this plugin in active-plugin count and excludes `uploads/museder-restoreone/` from uploads heuristics (reduces test-site false “populated” classification).

= 2.7.269 =
* Restore Step 1: cache archive analysis after chunk finalize so **Load Info** on large backups reuses results instead of re-scanning the ZIP (fixes timeout on 500MB+ server files).
* Restore Step 1: fix wizard UI when Load Info fails — no more stale File Summary with "Analysis failed"; reuse valid session summary when the same backup was already analyzed.
* Restore upload: chunk finalize now calls `MusederRestoreOneUI.handleSummaryResponse` first (wizard unlock without page reload).

= 2.7.268 =
* Restore: **large-site reliability** — after database import, progress polling continues via a file-backed **restore token** (survives session/nonce loss during NDJSON restore).
* Restore: **nopriv AJAX** handlers for restore progress (`job_status`, `restore_tick`) so polling works when the WordPress login cookie is invalidated mid-restore.
* Restore: **post-complete read grant** so the UI can confirm 100% success after the token is revoked at job completion.
* Restore: **media path reconcile** — after restore, match uploads database paths to on-disk filenames (UTF-8 vs ASCII) and log `MEDIA_PATHS_RECONCILE_DONE`.
* Restore: stable job meta paths (canonical storage), multi-slice tick drain, and improved `restore_job_status` mapping after DB import.
* Restore: make `zip_archive_has_wp_core()` public so preflight can detect full-site archives without a fatal error (BUG-SUN-001).
* Restore bootstrap: register WordPress stubs before `bootstrap_root()`; add `esc_attr()` stub; define `trailingslashit` before `ABSPATH` (BUG-SUN-003, BUG-SUN-004).

= 2.7.267 =
* Restore (Approach B): **site profile** detection (existing / fresh / no core), preflight blocks, and Step 2 **restore order**, **scope**, **wp-config mode** (backup / keep / merge), and **pause other plugins** (default on).
* Restore: **populated sites** require a pre-restore snapshot; default order **database then files** (user can switch to files-first).
* Restore: **fresh / empty** profiles default to **files then database** with UI notices (including DB overwrite on fresh installs).
* Restore: **empty docroot** full-site restores can use **`museder-restoreone-restore-bootstrap.php`** (copy to site root) for loopback file slices before WordPress core exists.
* Restore: ZIP restores use a **two-phase file stage** (wp-content, then WordPress core and site root files when present in the archive).
* Restore: includes **2.7.265** mid-restore plugin isolation, restore token, and safe-plugin reapply after restore.
* Restore: writes a fallback **.htaccess** when missing after permalink flush (Apache).

= 2.7.262 =
* Backup reliability: when **PclZip** compatibility repack is active, the async job runner **no longer keeps a long-lived `ZipArchive` handle** on the same `.zip` file (PclZip and ZipArchive were both mutating the archive, making `close()` extremely slow on large sites and risking central-directory corruption).
* Backup verification: post-close archive checks now resolve ZIP entry names more robustly (`zip_archive_has_entry()` — forward slashes, optional `./` prefix, and `ZipArchive::FL_NOCASE` when available) so **false “verification failed”** results are less likely to trigger a full repack.

= 2.7.261 =
* Developer / review automation (not shipped in the WordPress.org ZIP): WP-CLI matrices for **all registered `wp_ajax_museder_restoreone_*` actions**, **REST routes** under `museder-restoreone/v1` + `v2`, and **`admin_post_museder_restoreone_*` downloads** (capability + nonce / referer expectations); nginx **reverse-proxy** smoke in front of the stock Apache WordPress image; **ZIP clean install** + Plugin Check; **Multisite** 2-site stack + network uninstall option/cron verification; **mail pipeline** smoke (`wp_mail` / `Email_Handler::test_email` reaches PHPMailer); **PHPCS** tooling (`phpcs.xml.dist` + `tools/phpcs` Composer kit) with `run-phpcs-summary.sh`.
* Documentation: `docs/2026-05-06__v2.7.261__readme-key-features__admin-ui-map.md` maps readme **Key features** to admin `page=` slugs for manual review.
* Maintenance: restore job AJAX handlers that “clean” output now use **`ob_clean()`** instead of **`ob_end_clean()`** so they flush stray bytes **without** popping the whole output-buffer stack (same idea for REST chunk `prepare_request_environment()`). Behavior for real browsers is unchanged; this avoids breaking nested buffers in automated tests and CLI.

= 2.7.260 =
* Security: REST chunk v2 **`upload_id`** limited to **UUID v4** (same format as `wp_generate_uuid4()`); `status` / `chunk` / `finalize` / `abort` and temp directory helpers reject garbage ids; cleanup skips non-UUID folders under `v2-uploads`.
* Security: **`museder_restoreone_get_chunk_path()`** second argument now uses **`museder_restoreone_safe_path_join()`** (no traversal via relative fragments).
* Security: **WPress** `safe_join()` final check uses **directory-prefix boundary** (aligned with other path helpers).
* Review UX: admin script globals **`MusederRestoreOneAddon`** (primary) with **`MusederRestoreOnePro`** kept as an alias for backward compatibility; strings unchanged.
* UI: fixed **`[data-bl-theme="dark"]`** selectors in `admin-style.css` that were escaped incorrectly so dark-theme list/table header styles apply.
* Multisite: **`uninstall.php`** processes sites in **batches** to reduce memory spikes on large networks (readme Privacy note updated).
* Developer: `docs/2026-05-06__v2.7.260__端點矩陣__REST-AJAX-AdminPost.md` — hook → capability → nonce / `permission_callback` matrix for reviewers.

= 2.7.259 =
* Security: `museder_restoreone_safe_path_join()` now uses a **trailing-slash directory prefix** check; log path resolution and admin download handlers (`logs`, `reports`, restore-job `database.sql`) validate **`realpath()` + prefix** to avoid ambiguous `strpos` matches.
* Review UX: neutral copy for optional **add-on** placeholders (`MusederRestoreOnePro` / admin UI modal); Dashboard “AI” strings describe **local / offline** scan behavior only.
* Documentation: readme **External services** (loopback `wp-cron.php`, local `assets/vendor` JS/CSS), **Privacy / uninstall** aligned with `uninstall.php`, FAQ on **third-party format compatibility** (no endorsement).
* Maintenance: added root **`uninstall.php`** (options, transients, job-lock rows, `museder_restoreone_*` crons only — **no** backup/log/report file deletion). `create-package.sh` ships `uninstall.php` in the ZIP.
* Developer tooling: `tools/functional-test/` adds REST permission smoke, `safe_path_join` traversal checks, external-URL scan script, and uninstall manifest verification (still **not** included in the WordPress.org ZIP).
* Vendor: restored **`assets/vendor/chart.4.5.1.min.js`** alongside `chart.4.5.1.js`; expanded `assets/vendor/README.txt` (versions, licenses, sources).

= 2.7.258 =
* Security: `museder_restoreone_get_backup_path()` now compares backup directory roots with a **trailing-slash boundary** after `realpath()` normalization, preventing ambiguous prefix matches between similarly named directories.
* Multisite: when WordPress Multisite is enabled, RestoreOne admin screens show a **non-blocking** notice that Multisite is **experimental** (readme stance unchanged).
* Documentation: FAQ entries for **PclZip performance**, **REST chunk / `php://input`**, **cron and mail dependencies**, and **large-archive restore limits**; Privacy notes optional local **build/version log** heartbeat after upgrades.
* Developer tooling: added `tools/functional-test/` scripts (not shipped in the WordPress.org ZIP) to reproduce small-site, large-site, PclZip-forced, chunk REST smoke, and cron listing checks via Docker/WP-CLI.
* Email: test email body no longer uses emoji (broader mail client compatibility).

= 2.7.257 =
* UI: Improved dark-theme contrast for Settings field labels, helper text under General Settings, Environment Compatibility success badges, and System Diagnostics uppercase labels (avoids light-theme label colors on dark cards).
* Documentation: Readme feature line for schedules now matches optional scheduling (no longer implies a minimum number of schedules).
* Maintenance: Removed unused legacy template `templates/restore-page.php` (Restore admin screen uses `page-restore.php` only) to avoid mixed-language placeholder strings in the distributed tree.

= 2.7.256 =
* WordPress.org review follow-up: core admin include paths are now built in segments (root + directory parts + whitelisted filename), avoiding a single literal core-relative include path while preserving graceful fallback behavior.
* Developer filters: added `museder_restoreone_core_admin_include_path` for non-standard WordPress directory layouts; invalid or unreadable filtered paths are ignored.
* Documentation: FAQ now explains custom languages, mu-plugins, and core admin include path filters for advanced non-standard installs.

= 2.7.255 =
* WordPress.org review: replaced hardcoded/internal WordPress path constants used for core includes and language/mu-plugin directory discovery with helper-based path resolution derived from WordPress APIs (`wp_upload_dir()`, plugin path helpers) and graceful fallbacks.
* Backup scope: language and mu-plugin directory prefixes are now resolved through plugin helpers and filters (`museder_restoreone_languages_dir`, `museder_restoreone_mu_plugins_dir`) instead of `WP_LANG_DIR` / `WPMU_PLUGIN_DIR`.
* Restore/upload helpers: core admin include loading is centralized in `museder_restoreone_get_core_admin_include_path()` and avoids `ABSPATH` path concatenation; missing core helpers fail gracefully instead of fataling.

= 2.7.254 =
* REST: Chunk upload (`includes/class-chunk-handler-v2.php`) `permission_check` now uses the same two-step REST nonce pattern as v2 restore (`check_permissions`): `X-WP-Nonce` then `rest_nonce` parameter, empty token vs `wp_verify_nonce` as separate `WP_Error` branches; HTTP **401** for invalid/missing nonce and shared `museder_restoreone_invalid_nonce` / `museder_restoreone_forbidden` codes with v2 restore.
* REST: Free AI REST `permission_check` nonce failures now return **401** with `museder_restoreone_invalid_nonce` (aligned with v2 restore; same user-facing message).
* Readme: added **== Privacy ==** (data locations, third parties, optional add-ons, retention) and explicit **Multisite** stance in Description + FAQ.
* Security hygiene: added `index.php` sentinels under `includes/`, `includes/wpress/`, `templates/`, `assets/` (+ `assets/css/`, `assets/js/`), and `languages/` to avoid directory listing on misconfigured hosts.

= 2.7.253 =
* Compliance / Plugin Check: PclZip fallback now loads WordPress core’s PclZip file instead of shipping a duplicate `includes/vendor/pclzip` copy, so the broader `plugin-check.ruleset.xml` scan is not dominated by third-party PHPCS violations in bundled library code.
* Documentation: Clarifies that changelog lines mentioning `tests/` or `tools/docker/` refer to the public development repository only; those paths are not part of the distributed plugin ZIP from WordPress.org.

= 2.7.252 =
* Developer / WordPress Plugin Check: Report download `wp_die()` branches use per-status literal `response` codes with inline `esc_html()` / `esc_html__()` so `OutputNotEscaped` passes under Plugin Check.
* Developer: `tests/php-regression/final_review_248_regression.php` uses `esc_html()` on CLI output and wraps checks in `museder_restoreone_final_review_248_regression_run()` to satisfy prefix / escaping static analysis.
* Docker sync (`tools/docker/setup.sh`): exclude root `.DS_Store` from the plugin tarball so Plugin Check does not flag hidden files in `wp-content/plugins`.

= 2.7.251 =
* WordPress Plugin Check: Report download error path now passes HTTP status to `wp_die()` via the `response` args array (avoids `OutputNotEscaped` on a dynamic third-argument integer).

= 2.7.250 =
* Security: NDJSON database import now applies the same table prefix allow-list as the SQL restore path before `DROP TABLE` / `replace()`; disallowed names are skipped and logged.
* Stability: `get_ai_recommendations()` checks `class_exists( 'Museder_Restoreone_AI_Service' )` before calling it (avoids fatal if an add-on filter is misconfigured).
* WordPress.org review: AI schedule recommendation errors use neutral codes/messages (`addon_not_active`, `addon_service_missing`) instead of `pro_required`.
* REST (v2 restore): `check_permissions` validates `X-WP-Nonce` / `_wpnonce` in two steps (empty check, then `wp_verify_nonce`), matching the AI REST controller pattern.

= 2.7.249 =
* WordPress.org strict review: AJAX `museder_restoreone_refresh_nonce` now requires a valid existing nonce before issuing a new one; admin JS sends the current nonce on refresh.
* AI (free): removed daily scan quota / `remaining` / `dailyScans` from the hosted build (local preview only; no trialware-style limits in API responses).
* Safe mode: readme, Restore/Dashboard notices, restore options help text, and admin toasts now match implementation — snapshot + marker only; **Exit Safe Mode** clears the marker without claiming automatic plugin activation changes.

= 2.7.248 =
* WordPress.org review: `add_option()` job locks now use an explicitly prefixed `$option_key` built from `OPTION_LOCK_PREFIX` at the call site (addresses static analysis / human review feedback on dynamic option names).
* WordPress.org guidelines: removed the free-tier limit of a single backup schedule; multiple local schedules are allowed for all users.
* Schedules: cron pattern, exclude paths, and retention policy fields are saved for all installs (local features; not gated on a separate add-on).
* Backups: optional backup labels apply to archive names and metadata for all users; encryption and cloud destination metadata/upload remain add-on scoped, with a `class_exists()` guard on cloud upload.
* Admin log download: `check_admin_referer()` runs immediately after resolving the log basename and before reading the file from disk.

= 2.7.247 =
* Security & WordPress.org review: Added explicit `check_ajax_referer()` calls in admin AJAX handlers (UI, restore, logs, settings, email) so tooling and reviewers can see nonce verification in each handler.
* Backup download (`admin-post`): For nonce-based links, `check_admin_referer()` now runs before reading `$_GET['file']`; signed-token downloads unchanged. Clearer error when the filename is missing after a valid nonce.
* Report download: Replaced missing Pro controller with `Museder_Restoreone_Restore_Report::download()` plus `check_admin_referer( 'museder_restoreone_download_report' )`, path confinement under the reports directory, and safe streaming headers.
* Backup jobs: Clarified comments for dynamic `add_option()` lock keys (no invalid PHPCS ignore).

= 2.7.246 =
* Admin UI: improved text contrast when using dark appearance (`data-bl-theme="dark"`) — Restore Center step cards, glass cards, and status colors align with theme tokens (`--text-dark`, `--surface`, `--glass-*`).
* Restore Center: progress track uses a deeper neutral background; percentage label uses a subtle text shadow so it stays readable at low fill levels.
* Safe mode notices (Restore + Dashboard) use `var(--text-muted)` so body copy follows the active theme.
* Legacy restore wizard (restore.css): completed-step label uses a brighter green in dark mode.

= 2.7.245 =
* WordPress.org review follow-up: readme — single Changelog section (removed duplicate header); FAQ documents local `wp-cron.php` loopback requests. Code — `trigger_restore_job()` formatting in class-restore-handler.php; AI REST API namespace aligned to `museder-restoreone/v1` for consistency with the plugin slug.

= 2.7.244 =
* WordPress.org review hardening: removed bundled PRO activation, license verification, embedded PRO modules, and review-facing upgrade messaging from the free plugin package. Free build now keeps only the core backup, restore, schedule, logs, and settings experience, while preserving a clean add-on detection boundary for a separate plugin.

= 2.7.243 =
* Schedule handler: GLOB_BRACE fallback for PHP builds that omit it; retention apply_retention_rules file_exists check before filemtime to avoid warnings. Restore page: Exit Safe Mode button id unified to museder-restoreone-exit-safe-mode-btn with JS fallback for backup-lite-exit-safe-mode-btn. Small-site flow verified (backup, restore, settings, schedules, safe mode exit).

= 2.7.242 =
* WP.org compliance: Plugin Check 1.7.0 clean (0 errors, 0 warnings). Security and request handling (nonce/capability, sanitize/validate, json whitelist). Path and storage under wp_upload_dir. Removed direct core includes where possible; ABSPATH guards. Naming: menu/REST/JS prefixes unified to museder-restoreone. Readme external services (S3, OpenAI); Plugin URI updated.

= 2.7.223 =
* Compliance: Reworked deprecated download handler to avoid bootstrapping WordPress and route downloads via admin-post.php.
* Compliance: Documented external services with plain Terms/Privacy URLs for review tooling.
* Security: Added explicit nonce checks in key AJAX handlers for clearer automated detection.
* Security: Hardened restore SQL import with a conservative allow/deny statement strategy.
* Compatibility: Reduced reliance on hard-coded WP_* directory constants by using wp_upload_dir()-derived paths where possible.

= 2.7.220 =
* WP.org compliance hardening (nonce/cap checks, sanitization/escaping, uploads storage under wp_upload_dir).
* S3: migrate cURL usage to WordPress HTTP API (wp_remote_request) with multipart upload support.
* Restore reliability fixes (mysqldump stderr handling, file ops portability, progress UI smoothing).

= 2.7.218 =
* Internal testing build.

= 2.7.17 =
* Code Quality: Fixed remaining AlternativeFunctions errors in class-chunk-handler-v2.php (fopen, rename, ini_set)
* Security: Enhanced NonceVerification and ValidatedSanitizedInput fixes in class-ui.php - changed phpcs:ignore to phpcs:disable/enable for better tool recognition
* Code Quality: Fixed fread error in class-ui.php - changed phpcs:ignore to phpcs:disable/enable for better tool recognition

= 2.7.16 =
* Code Quality: Added phpcs:ignore comments for all AlternativeFunctions in class-restore.php (fopen, fclose, fread, fwrite, unlink, rename)
* Code Quality: Added phpcs:ignore comments for all AlternativeFunctions in class-backup.php (fopen, fwrite, fclose, unlink)
* Code Quality: Added phpcs:ignore comments for AlternativeFunctions in class-ai1wm-converter.php (fopen, fread, fclose)
* Code Quality: Added phpcs:ignore comments for all AlternativeFunctions in class-restore-handler.php (fopen, fclose, unlink, rename)
* Security: Fixed NonceVerification and ValidatedSanitizedInput warnings in class-restore-handler.php
* Code Quality: Added phpcs:ignore comments for DevelopmentFunctions (set_time_limit, ini_set) in class-restore.php and class-backup.php

= 2.7.15 =
* Code Quality: Added phpcs:ignore comments for AlternativeFunctions in class-chunk-handler-v2.php (fopen, fclose, fwrite, unlink, rename, fread)
* Code Quality: Fixed fread error in class-ui.php - added proper phpcs:ignore comment
* Code Quality: Fixed unlink comment format in class-chunk-handler-v2.php - changed from file_system_operations_unlink to unlink_unlink
* Code Quality: Added phpcs:ignore comment for error_log in class-chunk-handler-v2.php

= 2.7.14 =
* Security: Fixed NonceVerification warnings - added phpcs:ignore comments for all AJAX handlers that use verify_ajax_request()
* Security: Fixed ValidatedSanitizedInput warnings - added proper validation and sanitization comments for $_FILES and $_POST inputs
* Code Quality: Fixed PreparedSQL error in class-estimate-size.php - added phpcs:ignore comment for prepared query
* Code Quality: Added phpcs:ignore comments for necessary AlternativeFunctions (readfile, rename, unlink, fopen, chmod) in backup/restore operations

= 2.7.13 =
* Security: Enhanced ExceptionNotEscaped fixes in class-chunk-handler.php - all exception array values are now properly escaped using esc_html() and wrapped with phpcs:disable/enable comments
* Code Quality: Improved escaping for all exception data array values to ensure complete security compliance

= 2.7.12 =
* Security: Fixed ExceptionNotEscaped issues in class-chunk-handler.php - all exception array values are now properly sanitized and escaped
* Code Quality: Added missing translators comments for all __() functions with placeholders
* Code Quality: Fixed OutputNotEscaped issues in templates - all output values are now properly escaped using absint() and esc_html()
* Code Quality: Excluded create-package.sh from plugin package (development tool only)

= 2.7.11 =
* Security: Fixed json_decode() sanitization issues - all JSON-decoded arrays are now properly sanitized using recursive array_map() and sanitize_text_field()
* Security: Fixed REST API permission_callback - all REST API routes now use proper permission checks (manage_options + nonce verification) instead of '__return_true'
* Security: Added ABSPATH checks to download-handler.php to prevent direct file access
* Code Quality: Replaced all parse_url() calls with wp_parse_url() for WordPress compatibility
* Code Quality: Replaced all mkdir() calls with wp_mkdir_p() for WordPress compatibility
* Code Quality: Removed all inline <style> and <script> tags from templates - now using wp_add_inline_style() and wp_add_inline_script() in enqueue_assets()
* WordPress Compliance: All changes maintain existing functionality while meeting WordPress.org Plugin Directory guidelines

= 2.7.10 =
* Feature: Added Backup Size Estimation feature - estimate database and file sizes before creating backups
* Enhancement: Database size estimation using information_schema queries for fast, non-blocking database size calculation
* Enhancement: File size scanning with asynchronous batch processing (3000 files per batch) to prevent timeouts on large sites
* Enhancement: Smart caching system - scan results cached for 48 hours to avoid repeated scans
* Enhancement: Real-time progress tracking with visual progress bar during file scanning
* Enhancement: Large site detection - shows warning when estimated backup size exceeds 1GB with recommendations for chunk mode
* Enhancement: Excludes backup directories, log directories, cache folders, and system files (.git, .svn, .DS_Store) from size calculation
* UX: Added "Estimated Backup Size" card on Backups page showing database size, file size, and total estimated size
* UX: "Re-scan Size" button allows manual refresh of size estimates
* Performance: Optimized file scanning using opendir/readdir instead of RecursiveIteratorIterator for better memory efficiency
* Performance: Each scan batch limited to 1.5 seconds execution time to prevent server overload
* Security: All AJAX endpoints require manage_options capability and nonce verification
* Security: File scanning only accessible to administrators and only on plugin admin pages

= 2.7.09 =
* Enhancement: Added PHP native extraction fallback for .wpress files when tar command fails. Attempts to use gzopen() for gzip-compressed files.
* Enhancement: Improved error messages for .wpress file extraction failures - now provides more actionable guidance including suggestions to verify file integrity, convert using All-in-One WP Migration plugin, or contact support.
* Fix: Enhanced .wpress file extraction error handling to provide clearer diagnostic information when all extraction methods fail.

= 2.7.08 =
* Fix: Fixed issue where progress bar would immediately jump to 100% when restore fails, but network polling would continue. Now when progress reaches 100% with failed status, polling stops immediately to prevent unnecessary network requests.
* Fix: Enhanced failure detection logic - when progress is 100% and status is 'failed', the system now immediately stops all polling and displays the error message, preventing continued network activity in the background.

= 2.7.07 =
* Fix: Enhanced .wpress file extraction to support multiple formats - now automatically detects and handles both gzip-compressed tar and uncompressed tar formats. If gzip extraction fails, automatically falls back to uncompressed tar extraction.
* Fix: Improved file format detection by reading file headers to determine the correct extraction method before attempting extraction.
* Fix: Fixed issue where restore would immediately complete at 100% when .wpress file format was not gzip-compressed tar.

= 2.7.06 =
* Fix: Added direct .wpress file extraction support using tar command. All-in-One WP Migration .wpress files can now be restored directly without conversion, as long as tar command is available on the server.
* Fix: Improved error handling for .wpress file extraction failures - provides specific error messages when tar command is unavailable or extraction fails.
* Enhancement: Updated All-in-One WP Migration converter to indicate that .wpress files can be restored directly without conversion.
* Enhancement: Enhanced archive extraction logic to detect .wpress files and attempt tar extraction before falling back to ZIP methods.

= 2.7.05 =
* Fix: Fixed restore completion/failure detection - restore status messages now appear immediately without requiring page refresh. Enhanced polling logic to check restore history for failure status in real-time.
* Fix: Improved error handling for archive extraction failures - added detailed logging and better error messages for .wpress and ZIP file extraction issues.
* Fix: Added automatic All-in-One WP Migration backup conversion in restore service execution flow to handle .wpress files properly.
* Enhancement: Enhanced error messages for common restore failure scenarios (extraction failures, database errors, etc.) with more actionable information.
* Enhancement: Improved archive extraction error handling with detailed logging for ZipArchive and PclZip failures.

= 2.7.04 =
* Enhancement: Added Safe Mode after restore — records active plugins and shows an admin notice; Exit Safe Mode clears the marker (no automatic plugin activation changes).
* Enhancement: Enhanced URL search-replace functionality - now handles http/https, www/non-www, and subdirectory path variations automatically for better cross-domain migration support.
* Enhancement: Added restore completion hooks - `backup_lite_after_restore` and `backup_lite_after_restore_safe_mode` hooks allow other plugins to integrate with restore workflow.
* Enhancement: Improved diagnostic logging - added detailed logs for database import (siteurl/home changes), URL replacement pairs, and safe mode marker handling for easier troubleshooting.
* Security: All new features follow WordPress coding standards and security best practices.

= 2.7.03 =
* Fix: Optimized large file processing for All-in-One backup conversion. Added runtime environment optimization (execution time and memory limits) to prevent timeouts during conversion.
* Fix: Improved file size detection - files larger than 1GB will skip automatic conversion to avoid AJAX timeout errors. Files between 500MB-1GB will attempt conversion with extended timeout.
* Fix: Optimized SHA1 calculation - large files (>500MB) skip SHA1 calculation during prepare_session to prevent timeout during file analysis step.
* Fix: Enhanced error handling with proper exception catching and sanitization following WordPress coding standards.

= 2.7.02 =
* Fix: Improved error handling for All-in-One WP Migration backup conversion. Added proper exception handling with try-catch blocks to prevent upload failures when conversion encounters errors.
* Fix: Enhanced error messages following WordPress coding standards. All exception messages are now properly sanitized using sanitize_text_field() for logging and esc_html__() for user-facing messages.
* Fix: Added file existence checks after conversion to ensure converted files are valid before proceeding with restore session preparation.
* Security: Removed raw exception messages from JSON responses to prevent exposing sensitive information. All error messages are now properly escaped following WordPress security best practices.
* Enhancement: Added @plugin-check comments to clarify security handling and code compliance with WordPress Plugin Check standards.

= 2.7.01 =
* Feature: Added All-in-One WP Migration backup converter. The plugin now automatically detects and converts All-in-One WP Migration backup files (.zip and .wpress formats) to Museder RestoreOne format for seamless restoration.
* Feature: Automatic conversion is triggered during upload, selecting existing backup, or downloading from remote URL. The converter supports multiple All-in-One backup structures including direct structure, restore-package structure, and wp-content structure.
* Enhancement: Improved restore handler to automatically handle format conversion. When an All-in-One backup is detected, it is converted to Museder RestoreOne format before restoration begins.
* Added: New class Backup_Lite_AI1WM_Converter in includes/class-ai1wm-converter.php for handling All-in-One backup conversion.
* Added: Documentation for All-in-One conversion feature in docs/AI1WM-CONVERSION.md and docs/AI1WM-IMPLEMENTATION.md.

= 2.6.126 =
* Security: Removed all direct calls to move_uploaded_file() to pass WordPress Plugin Check. Replaced with stream_copy_to_stream() for secure file handling. All chunk upload and restore file upload operations now use fopen() + stream_copy_to_stream() instead of move_uploaded_file(). Functionality, error codes, and HTTP status codes remain unchanged.

= 2.6.125 =
* Updated plugin header: Plugin URI and Author URI set for WordPress.org; plugin name, description, author, and WordPress version requirements updated.

= 2.6.124 =
* Fixed backup file size issue: Resolved problem where backup archives were incorrectly including other backup files (causing 540MB+ backups). Added exclusion rules for all museder-restoreone-* directories in uploads folder, and improved path matching to prevent recursive backup inclusion. Backup files (.zip, .wpress) in uploads directory are now properly excluded.

= 2.6.123 =
* Fixed download handler fatal error: Resolved issue where download-handler.php was using WordPress functions (wp_unslash, sanitize_file_name) before WordPress was loaded, causing HTTP 500 errors. Now properly loads WordPress first, then processes parameters. Added error handling and fallback mechanisms for better reliability.

= 2.6.122 =
* WordPress Plugin Check compliance: Final round of fixes for remaining security warnings. Added phpcs:ignore comments for ExceptionNotEscaped, replaced parse_url() with wp_parse_url(), replaced is_writable() with wp_is_writable(), and added proper phpcs:ignore comments for $_FILES, $_POST, and Direct DB Query warnings.

= 2.6.121 =
* WordPress Plugin Check compliance: Fixed all WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings in includes/class-chunk-handler.php. All dynamic variables in exception messages are now properly escaped using esc_html() before being passed to sprintf().

= 2.6.120 =
* WordPress Plugin Check compliance: Fixed all WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings in includes/class-chunk-handler.php. All exception messages now properly use sanitize_text_field() for variable sanitization and esc_html__() with sprintf() for message formatting.

= 2.6.119 =
* WordPress Plugin Check compliance: Fixed all remaining WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings in includes/class-chunk-handler.php. All exception messages now properly use esc_html__() for base strings and esc_html( (string) $var ) for dynamic variables. Added @plugin-check: escaped comments to all exception throws.

= 2.6.118 =
* WordPress Plugin Check compliance: Continued improvements for file system operations and exception handling.

= 2.6.117 =
* WordPress Plugin Check compliance: Fixed WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings in includes/class-chunk-handler.php. Exception messages are now properly escaped using esc_html() and sanitize_text_field().
* WordPress Plugin Check compliance: Added phpcs:ignore comments for file system operations (fopen, fclose, rename, unlink) in includes/class-chunk-handler.php. These operations are required for backup/restore functionality and paths are validated by plugin helpers.

= 2.6.116 =
* WordPress Plugin Check compliance: Fixed all WordPress.WP.I18n.TextDomainMismatch errors. Unified all translation functions to use 'museder-restoreone' as the text domain throughout the entire plugin (replaced 'museder-restoreone-1' in 40+ files).
* WordPress Plugin Check compliance: Added translators comments for all translation strings containing placeholders (%s, %d, %1$s, etc.) in includes/class-chunk-handler.php and includes/pro/ai-service.php to resolve WordPress.WP.I18n.MissingTranslatorsComment warnings.

(Older changelog entries are maintained in the project repository.)

== Upgrade Notice ==

= 2.7.275 =
Fixes Restore Step 3 on shared hosts where admin-ajax and wp-login interim-login return 403 after DB import: the UI now converges to “Restore Completed” via REST-only polling, query-style REST fallback, and read-only AJAX final-status fallback even when `/wp-json` becomes stale or returns HTML. Also stabilizes restore-token verification across `wp-config.php` salt changes, fixes long-running restores that could show 100% while still stuck on “Waiting for action,” and hardens prefix migration against duplicate-key failures. Strongly recommended if Step 3 shows 403 errors while the backend restore actually succeeds.

= 2.7.274 =
Fixes full-site restores that could break wp-admin with a critical error when core file extraction was interrupted mid-slice, and improves Step 3 success recovery when shared hosts block admin-ajax/auth-check polling or cancel confirmation. Strongly recommended before restoring over live WordPress core on shared hosting.

= 2.7.273 =
Fixes large-site backups that never finish after ZipArchive packing completes (false verify failure → hours-long PclZip repack). Recommended if backups stall near 100% on shared hosting.

= 2.7.272 =
Fixes Backup Auto mode staying on Balanced with Smart Exclude Off when the site shows “Large site detected” (>1 GB). Recommended if Auto backup does not switch to Fast on large sites.

= 2.7.271 =
Fixes Restore Step 3 UI resetting during active jobs; recommended if you reload the restore page mid-restore. Also keeps wp-config unchanged on files-only restores and improves large-backup file-stage progress accuracy.

= 2.7.270 =
Fixes Restore Step 1 showing “Analysis failed” after a successful chunk upload on sites that need “Overwrite existing data” in Step 2. Recommended if large backup analysis completes but the wizard stays failed with an empty summary.

= 2.7.269 =
Fixes Restore Step 1 on large server backups (Load Info timeout and stale summary after analysis). Recommended if 500MB+ backups fail at Step 1 or require a page reload to unlock Step 2.

= 2.7.268 =
Improves large-site restore reliability: progress polling survives session loss via restore token, nopriv progress handlers, and post-complete read grant. Recommended for long restores on production sites.

= 2.7.267 =
Adds restore site profile detection, preflight warnings, restore order/scope/wp-config options, and bootstrap support for empty document roots. Recommended before full-site restores on existing or fresh installs.

= 2.7.262 =
Fixes large-site backup jobs that could appear **stuck near 95%** after a failed post-close verification (PclZip repack conflicting with an open ZipArchive handle). Recommended if you run **large full-site backups** on production.

= 2.7.261 =
No behavior change intended for production sites; this release mainly adds reviewer-oriented automation in the development repository (endpoint matrices, nginx+Apache smoke, ZIP install check, Multisite uninstall verification, mail/PHPCS tooling) and readme↔admin UI mapping for manual checks.

= 2.7.260 =
Stricter REST chunk session ids; safer chunk/WPress paths; neutral `MusederRestoreOneAddon` global (Pro alias retained); dark-theme CSS selector fix; batched Multisite uninstall.

= 2.7.259 =
Path-boundary hardening for helpers and download handlers; neutral add-on/“AI” admin copy; `uninstall.php` for clean option/cron removal without deleting your backups; functional-test helpers in the development repository.

= 2.7.258 =
Tightens backup-path directory prefix checks; Multisite admin notice; expanded FAQ for chunk uploads, PclZip, cron/mail, and large restores; optional functional-test tooling in the development repository.

= 2.7.257 =
UI contrast fixes for dark theme on Settings and dashboard status badges; readme schedule wording aligned with optional schedules; legacy unused restore template removed.

= 2.7.256 =
Further reduces review risk around path determination: segmented core admin include path resolution, a documented override filter for non-standard layouts, and FAQ guidance for custom language / mu-plugin paths.

= 2.7.255 =
Addresses WordPress.org feedback on determining plugin/content directories correctly: no `ABSPATH`-based core includes, no `WP_LANG_DIR` / `WPMU_PLUGIN_DIR` in backup scope; centralized core include helper with graceful failures. Recommended before resubmitting to the Plugin Directory.

= 2.7.254 =
REST nonce handling aligned across Chunk, AI, and v2 restore; readme adds Privacy and Multisite statements; directory index sentinels. Recommended before WordPress.org resubmission.

= 2.7.253 =
Loads PclZip from WordPress core (no bundled duplicate library); readme clarifies dev-only paths. Recommended before running full Plugin Check ruleset or resubmitting to WordPress.org.

= 2.7.252 =
Plugin Check–clean report download handling, regression test script escaping, and Docker exclude for `.DS_Store`. Recommended before resubmitting Plugin Check results.

= 2.7.251 =
Plugin Check / escaping fix for report download `wp_die()` status handling. Recommended update before WordPress.org Plugin Check resubmission.

= 2.7.250 =
Tighter NDJSON import table policy, safer AI recommendations hook, REST nonce checks aligned with AI routes. Recommended update before directory resubmission.

= 2.7.249 =
Stricter admin AJAX nonce refresh, AI scan response without quota fields, safe mode copy aligned with actual behavior. Recommended before WordPress.org resubmission.

= 2.7.248 =
Review-driven fixes: visible prefixed option keys for job locks, unlimited local schedules, backup labels for everyone, safer log download nonce order. Recommended before resubmitting to WordPress.org.

= 2.7.247 =
Security and directory-review hardening: clearer nonce checks in AJAX handlers, safer backup download order, working admin report download handler. Recommended update before WordPress.org resubmission.

= 2.7.246 =
UI polish: better dark-theme contrast on Restore Center and related cards; clearer restore progress label. Recommended update for admin readability.

= 2.7.245 =
Readme and review polish: unified changelog, FAQ for local wp-cron loopback, code formatting and AI REST namespace alignment. Update recommended before WordPress.org resubmission.

= 2.7.21 =
Security & compliance: Hardened security with comprehensive nonce verification, input sanitization, and WordPress.org standards compliance. Fixed Restore History timestamp accuracy and restore success detection. Update recommended for all users.

= 2.7.20 =
Critical fixes: Enhanced file path validation for restore operations, fixed Restore History timestamp accuracy, improved security with wp_delete_file(). Update recommended for all users.

= 2.7.19 =
Bug fixes: Fixed restore file path errors and download white screen issue. Removed hardcoded timezone offsets - all time displays now respect WordPress timezone settings. Update recommended if experiencing restore or download issues.

= 2.7.18 =
Timezone fix: All time displays now correctly use WordPress local timezone. Restore History, Dashboard, Logs, and Schedules pages now show accurate local times. Update recommended if timestamps are incorrect.

= 2.7.17 =
Code quality and security improvements: Fixed remaining AlternativeFunctions errors, enhanced NonceVerification and ValidatedSanitizedInput fixes. Improved tool recognition for phpcs comments. Update recommended for better WordPress Plugin Check compliance.
