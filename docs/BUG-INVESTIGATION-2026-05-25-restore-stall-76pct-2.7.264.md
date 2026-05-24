# Bug Investigation Report: 2.7.264 Restore Stalls at ~76% (sunpoweroflight.com)

## Summary

Museder RestoreOne Lite `2.7.264` restore on a fresh WordPress install stalls during **Step 3** with the UI showing about **76%**. The restore job remains **`running`** and never completes.

**Primary root cause:** The restore pipeline imports the database **before** finishing `wp-content` file extraction. The NDJSON import restores `active_plugins` from the backup (27 plugins). WordPress then bootstraps those plugins on every subsequent request (WP-Cron loopback, admin-ajax tick, front-end). While files are still being extracted, **All in One SEO (AIOSEO)** is only partially present on disk (`all_in_one_seo_pack.php` exists but `app/init/init.php` is missing), causing a **PHP Fatal error** that kills the request. Restore processing cannot continue.

This is **not** a corrupt backup archive. The backup ZIP contains a complete AIOSEO tree including `app/init/init.php`.

**Issue classification:** This is a **plugin-side restore pipeline defect**, not a site misconfiguration or host fault. The target site is a normal fresh WordPress install; the backup archive is valid. The site merely presents a **high-risk combination** (fresh target + large multi-plugin backup) that exposes a long-standing architectural gap in RestoreOne.

No code has been changed as part of this investigation.

## Affected Context

| Field | Value |
|-------|-------|
| Target site | `https://sunpoweroflight.com/` (fresh install) |
| Plugin version | `2.7.264` (`build_id`: `2.7.264-1`) |
| PHP | `8.3.30` |
| Backup archive | `logs/150525-debug/sunpoweroflight.com-20260311014315-ptq9eY.zip` |
| Backup source plugin | `2.7.243` (from archive `meta.json`) |
| Job ID | `rjb_20260524_185357_embavh` |
| Restore history | `logs/150525-debug/restore-history.json` → `"result": "running"` |
| Plugin log | `logs/150525-debug/backup-lite-2026-05-25.log` |
| PHP error log | `logs/150525-debug/error_log` |

## User-Visible Symptoms

1. Installed `2.7.264` from WordPress.org, activated plugin.
2. Uploaded backup and started **Step 3 – Start Restore**.
3. Progress reached about **76%**, then stopped advancing.
4. Site became inaccessible (white screen / fatal error on front-end).
5. Restore never reported success; history still shows `running`.

## Artifact Findings

### Plugin log (`backup-lite-2026-05-25.log`)

Only five lines were captured (logging likely stopped when PHP fatals prevented further writes):

```
[2026-05-25T02:54:25+08:00] [INFO] Museder RestoreOne version updated. {"version":"2.7.264",...}
[2026-05-25T02:55:29+08:00] [INFO] Post-DB-import recovery: lock, active job, and cron re-established. {"job_id":"rjb_20260524_185357_embavh"}
[2026-05-25T02:55:51+08:00] [INFO] Restore files: self-protect skipped plugin files. {"skipped":43,"total":43,"prefix":"wp-content/plugins/museder-restoreone/"}
```

Interpretation:

- `Post-DB-import recovery` confirms **database import finished** and runtime state (lock, active job, cron) was re-established.
- `Restore files: self-protect skipped…` confirms **file-restore stage had started** (~22 seconds later).
- No further plugin log entries → processing likely died immediately after.

### PHP error log (`error_log`)

**Phase A – post-import cron noise (expected, non-fatal):**  
From `18:54:25 UTC`, WordPress tries to reschedule cron hooks for plugins now present in the restored DB (`really-simple-ssl`, `monsterinsights`, `wp-statistics`, etc.) with `invalid_schedule`. This proves the restored database is already live while files are still catching up.

**Phase B – fatal blocker (18:55:51 UTC onward):**

```
PHP Warning: require_once(.../wp-content/plugins/all-in-one-seo-pack/app/init/init.php): Failed to open stream: No such file or directory
PHP Fatal error: Failed opening required '.../app/init/init.php' in .../all_in_one_seo_pack.php on line 38
```

Stack traces show fatals on:

- `wp-cron.php` (restore loopback nudge)
- `index.php` / front-end (site down for visitors)

AIOSEO is **active in the database** but **incomplete on disk** — classic mid-restore partial plugin state.

### Restore history (`restore-history.json`)

```json
{
  "job_id": "rjb_20260524_185357_embavh",
  "file": "sunpoweroflight.com-20260311014315-ptq9eY.zip",
  "result": "running",
  "restore_completed_at": 0
}
```

Job never reached terminal state (`success` / `failed` / `cancelled`).

### Backup archive inspection

| Metric | Value |
|--------|-------|
| ZIP size | ~737 MB (`773,495,252` bytes) |
| ZIP entries | `22,490` |
| Declared files (`package.json`) | `20,742` |
| Active plugin folders in backup | **27** |
| `database.ndjson` size | ~192 MB |
| AIOSEO `app/init/init.php` in archive | **Present** |

Sample plugins in backup: `all-in-one-seo-pack`, `elementor`, `elementor-pro`, `really-simple-ssl`, `google-analytics-for-wordpress`, `wp-statistics`, `museder-restoreone`, etc.

## Timeline (UTC)

| Time (UTC) | Event |
|------------|-------|
| `18:53:57` | Restore job created (`rjb_20260524_185357_embavh`) |
| `18:54:25–18:54:31` | Restored plugins' cron hooks rescheduled → DB already imported enough for plugin options to load |
| `18:55:29` | `post_db_import_recovery()` — DB import complete; cron re-scheduled; `spawn_cron()` nudged |
| `18:55:51` | File restore batch begins (`self-protect skipped` for RestoreOne); **AIOSEO fatal on `wp-cron.php`** |
| `19:13:49+` | Repeated AIOSEO fatals on front-end requests — site remains broken |

Local plugin log timestamps are `+08:00` and align with the UTC error log when converted.

## Why the UI Showed ~76%

Backend progress mapping during early Step 3 (`includes/class-restore-service.php`):

| Stage | Server `progress` |
|-------|-------------------|
| `restore-extract-db` (queued) | `70` |
| Extracting `database.ndjson` | `75` |
| DB import prep / import | `80` → `90` |
| `restore-files` | `90`–`96` (zip entry ratio) |

The Step 3 UI smoother (`assets/js/restore.js`) caps stall-smoothing at **80%** for stage `restore-extract-db`. When server progress sits at **75%** and polling stalls, the display bar can creep toward **~76–80%** without reflecting later server-side jumps.

After the AIOSEO fatal, **admin-ajax status/tick requests also fatal**, so the UI freezes near the last displayed value (~76%) even though the server may have briefly reached the file-restore stage (`90%+`).

## Issue Classification: Plugin Problem vs Site Problem

### Verdict: **Plugin problem** (restore pipeline design), not a site problem

| Aspect | Finding | Owner |
|--------|---------|-------|
| Backup archive integrity | Valid; AIOSEO and other plugin files present in ZIP | N/A (not broken) |
| Plugin install source | WordPress.org `2.7.264`, normal activation | N/A |
| Host / PHP environment | PHP `8.3.30`, typical shared hosting; no misconfiguration indicated | N/A |
| User workflow | Standard Step 1–3 restore on a new site | N/A |
| Restore ordering | DB import applies `active_plugins` **before** `wp-content` files finish extracting | **RestoreOne bug** |
| Mid-restore plugin loading | No temporary isolation of third-party plugins during file restore | **RestoreOne bug** |
| Post-DB cron nudge | `post_db_import_recovery()` schedules and nudges WP-Cron while files are incomplete | **RestoreOne behavior** (amplifier) |
| Safe mode | Marker-only after restore completes; does not prevent mid-restore fatals | **RestoreOne limitation** |

The operator did not misconfigure the site. **Any fresh target site** restoring a **large backup with many active plugins** can hit the same failure mode. sunpoweroflight.com is a reproducer, not the root cause.

### What is *not* the cause

- Corrupt or incomplete backup (ruled out by ZIP inspection).
- Wrong plugin package / nested ZIP layout (that was a separate `2.7.264` local-upload packaging issue on another test; this case used WordPress.org install).
- AIOSEO bug alone (AIOSEO fatals because RestoreOne left it **active but incomplete** on disk).
- Generic host “Cron broken” (Cron was triggered; it fatally crashed loading plugins).

## Why This Was Not Seen Before, But Happened on This Site Now

This defect is **latent in the architecture** (DB-before-files without plugin isolation). It does not manifest on every restore — only when several risk factors align. This incident combines nearly all of them.

### Risk factor matrix

| Factor | This incident (sunpoweroflight.com) | Lower-risk restores (often “worked before”) |
|--------|-------------------------------------|---------------------------------------------|
| Target site state | **Fresh WP** — disk had almost only RestoreOne before restore | Existing/staging site with plugin files already on disk |
| Active plugins in backup | **27 plugins** | Few plugins, or mostly inactive in backup |
| Backup size / file count | **~737 MB**, **22,490** ZIP entries | Small dev/staging backups |
| DB vs filesystem gap after import | **Maximum** — DB lists 27 active plugins; filesystem mostly empty | Smaller gap if files pre-exist |
| First plugin to fatal | **AIOSEO** — hard `require_once` on missing `app/init/init.php` early in bootstrap | Different plugin order / softer bootstrap |
| Cron loopback during file restore | **Yes** — `post_db_import_recovery()` + `spawn_cron()` right after DB import | Older builds or hosts where cron nudge was delayed/blocked |
| Symptom severity | Immediate **Fatal** → white screen + restore tick dies | Progress “slow/stuck” without immediate site death (less visible) |

### Why “fresh site + full backup” is the worst case

On a **new** install:

1. Before restore: `active_plugins` ≈ `[museder-restoreone/museder-restoreone.php]`; disk matches.
2. After DB import: `active_plugins` = **27 entries from backup**; most plugin directories **do not exist yet** on disk.
3. WordPress loads the full active list on every request → **partial-plugin fatal window is largest**.

On an **existing** site being overwritten, many plugin folders may already be present from a prior install or partial restore, so the same pipeline might **appear** to work even though the isolation gap still exists.

### Recent plugin change increased trigger probability

Commit `6870b45` (*“Preserve admin session tokens and recover runtime state around DB import”*) added `post_db_import_recovery()` in `includes/class-restore-service.php`. That fix correctly re-establishes restore lock, active job pointer, and cron after NDJSON import wipes `wp_options`.

**Side effect:** it also calls `spawn_cron()` → `museder_restoreone_nudge_wp_cron()` **immediately after DB import**, while `stage_restore_files` has not finished. That makes WordPress bootstrap (with all restored `active_plugins`) happen **earlier and more reliably** than before — which is good for session/cron recovery but **bad** when third-party plugin files are still missing.

Follow-up commit `f005c26` (*“fix(restore): prevent session loss on real shared hosting”*) is in the same area. Dev agent should treat mid-restore plugin isolation as complementary to these recovery fixes, not optional polish.

### Distinction from other recent incidents

| Incident | Site | Type | Relation to this bug |
|----------|------|------|----------------------|
| Backup cancel/resume @ ~95% | ciouyinghao.com | Backup job race / PclZip / cancel overwrite | **Different** root cause |
| Plugin file missing on activate | Local `2.7.264` zip upload | Incorrect release ZIP nesting | **Different** root cause |
| Restore stall @ ~76% | sunpoweroflight.com | DB-before-files + active plugins mid-restore | **This report** |

### One-line answer for support / triage

> **Plugin bug**, exposed by restoring a **full multi-plugin backup onto a fresh site**; not caused by sunpoweroflight.com being misconfigured. The bug may have existed for a long time but was **unlikely to surface** on small backups, pre-populated targets, or before post-DB cron recovery made early bootstrap more aggressive.

## Root Cause Analysis

### Primary defect: DB import activates plugins before files exist

Restore order in `Museder_Restoreone_Restore_Service`:

1. Extract / import database (writes `active_plugins` from backup into `wp_options`).
2. `post_db_import_recovery()` re-schedules cron and calls `museder_restoreone_nudge_wp_cron()`.
3. Extract `wp-content/**` from ZIP in slices (`stage_restore_files` → `extract_zip_prefix_sliced`).

Between steps 1 and 3, WordPress considers **all 27 backed-up plugins active**, but most plugin directories/files are not yet extracted.

### Secondary amplifier: full WordPress bootstrap on cron/tick

`museder_restoreone_nudge_wp_cron()` (`includes/helpers.php`) sends a non-blocking loopback POST to `wp-cron.php`. That loads **all active plugins**. With incomplete AIOSEO → fatal → cron tick never runs `museder_restoreone_restore_service_process_job`.

The admin **restore tick** (`wp_ajax_museder_restoreone_restore_tick`) has the same vulnerability: it loads the full plugin stack.

Self-protection (`extract_zip_prefix_sliced`) only skips overwriting **`museder-restoreone`** mid-restore. It does **not** prevent other plugins from loading once the DB marks them active.

### Safe mode does not help mid-restore

`Museder_Restoreone_Restore::enter_safe_mode_after_import()` (`includes/class-restore.php`) runs only in the **cleanup/finish** pipeline. Comments explicitly state it **does not deactivate plugins**. It records a snapshot and sets a marker **after** restore completes.

Similarly, `restore_plugin_status()` is a no-op recorder (`includes/class-restore-service.php`) — no activation changes per WordPress.org policy.

### Why AIOSEO specifically surfaced first

Among 27 plugins, AIOSEO's bootstrap (`all_in_one_seo_pack.php` line 38) hard-`require_once`s `app/init/init.php` with no guard. Alphabetical / extraction order likely restored the main plugin file before the `app/` subtree, making it the first fatal. Other plugins could fail similarly on different sites or backup order.

## Code Areas to Inspect

| File | Relevance |
|------|-----------|
| `includes/class-restore-service.php` | `stage_import_database()`, `post_db_import_recovery()`, `stage_restore_files()`, `extract_zip_prefix_sliced()`, `spawn_cron()` |
| `includes/class-restore.php` | `import_database_from_ndjson()` captures `active_plugins`; `enter_safe_mode_after_import()` (post-finish only) |
| `includes/helpers.php` | `museder_restoreone_nudge_wp_cron()` loopback |
| `includes/class-restore-handler.php` | `restore_tick()` AJAX processor |
| `assets/js/restore.js` | Progress smoothing caps; UI can mask stage transitions |
| `logs/all-in-one-wp-migration/…` (reference) | AI1WM deactivates plugins during import (`ai1wm_deactivate_plugins`) — contrast |

## Proposed Fix Strategy (for dev agent)

### Phase 1 — Stop the bleeding (minimal, high impact)

After DB import and **before** file restore begins:

1. Snapshot `active_plugins` to `museder_restoreone_restored_active_plugins` (already partially done in legacy restore path).
2. **Temporarily reduce** `active_plugins` to only `museder-restoreone/museder-restoreone.php` (and multisite equivalents if applicable) until `restore-files` + `search-replace` complete.
3. In cleanup/finish, restore the saved plugin list (or leave for admin + safe-mode notice).

This is a **restore safety measure**, not arbitrary manipulation of unrelated sites. Document in readme/FAQ.

Alternative: filter `pre_option_active_plugins` while restore lock is held — avoids writing DB twice but must be bulletproof across cron/AJAX/CLI.

### Phase 2 — Harden processing transport

- Keep `post_db_import_recovery()` lock/job/cron recovery (from `6870b45` / `f005c26`), but **pair it with plugin isolation** so nudged cron does not bootstrap 27 incomplete plugins.
- Consider **not** nudging `wp-cron.php` until file restore completes **unless** third-party plugins are temporarily stripped from `active_plugins` (or filtered via `pre_option_active_plugins`).
- Ensure `restore_tick` can progress even if other plugins would fatal (early bootstrap guard or dedicated minimal endpoint).

### Phase 3 — UX / observability

- Log stage + progress on every slice completion (not only milestones) so partial logs still tell the story.
- Map PHP stage `restore-db` to JS smoother cap (currently JS checks `restore-import-db`, PHP uses `restore-db` — cap mismatch).
- Surface fatal-loop detection: if `last_tick` unchanged for N minutes and error log pattern detected, mark job `failed` with actionable message.

## Manual Recovery (operator / support)

Until a code fix ships, on the broken site:

1. Via SFTP/SSH, **rename** `wp-content/plugins/all-in-one-seo-pack` to `all-in-one-seo-pack.disabled` (or remove from `active_plugins` in DB).
2. Optionally deactivate other heavy plugins the same way if further fatals appear.
3. Re-open RestoreOne admin; check if job `rjb_20260524_185357_embavh` can resume (tick/cron) or start a fresh restore after cleanup.
4. If WP admin remains inaccessible, edit `{prefix}options` row `active_plugins` via phpMyAdmin to only include `museder-restoreone/museder-restoreone.php`.

## Validation Plan

1. **Repro:** Fresh WP + RestoreOne `2.7.264`; restore a backup with **20+ active plugins** and large `wp-content/plugins` tree; confirm stall + AIOSEO fatal without fix.
2. **Fix verify:** Same backup completes Step 3; `restore-history.json` → `success`; front-end loads; plugin list restored or offered via safe-mode snapshot.
3. **Regression:** Small backup (few plugins) still restores; self-protect still preserves RestoreOne mid-restore; cancel still terminal.
4. **WP.org:** No license gates; plugin deactivation is scoped to active restore job only; escape/sanitize unchanged surfaces.

## Risk Assessment

| Risk | Severity |
|------|----------|
| Any multi-plugin backup to empty/fresh site | **High** — likely to hit partial-plugin fatals |
| Single-plugin or plugins-already-present site | Lower |
| Misleading ~76% UI while server already past DB import | Medium — support confusion |
| Site left broken mid-restore | **High** — requires manual SFTP/DB intervention |

## Conclusion

**Classification:** Plugin-side restore pipeline defect — **not** a sunpoweroflight.com site configuration issue.

**Mechanism:** The restore failed because **database state (active plugins) was applied before filesystem state (plugin files) was fully restored**, and WordPress then fatally crashed loading **All in One SEO** with missing files. RestoreOne's cron/AJAX continuation depends on a healthy WordPress bootstrap, so the job stalled at ~76% in the UI with history stuck at `running`.

**Why now / why this site:** A **fresh target** plus a **27-plugin, ~737 MB backup** maximizes the partial-plugin fatal window. Recent **`post_db_import_recovery()` + cron nudge** (commit `6870b45`) makes that window trigger sooner. The bug is latent in the architecture; this site is a clear reproducer, not an outlier caused by operator error.

**Recommended priority:** Implement **temporary plugin isolation between DB import and file-restore completion**, integrated with existing post-DB recovery fixes, following patterns used by other migration plugins (e.g. AI1WM deactivates plugins during import), while staying WordPress.org compliant.
