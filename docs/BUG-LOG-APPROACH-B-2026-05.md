# Bug Log — Approach B QA Matrix (2026-05)

**Baseline:** `2.7.268` (`ae87d28`)  
**Regression proposal:** `docs/QA-APPROACH-B-RETEST-PROPOSAL-2026-05.md`

---

## Summary

| ID | Sev | Status | Title |
|----|-----|--------|-------|
| BUG-AB-001 | P1 | **Verified** | R-S2 R12 E2E `pause_other_plugins: true` |
| BUG-AB-002 | P2 | **Verified** | `restore.js` cap for `restore-db` |
| BUG-AB-003 | P2 | **Verified** | Preflight warning when pause off + db_then_files |
| BUG-AB-004 | P2 | Open (QA infra) | WP-CLI phar OOM with plugin active; use container `php` |
| BUG-AB-005 | **P1** | **Verified** | R12：R-S2 E2E Pass（`rjb_20260527_153349_owtrvd`） |

---

## BUG-AB-001 — Bootstrap plugin isolation (P1)

| | |
|---|---|
| **Fix** | `pause_other_plugins => true` in `class-restore-bootstrap.php` ~711 (`ae87d28`) |
| **Static** | ✅ Confirmed in repo |
| **R-S2 E2E** | ✅ **Pass**（第十二輪） |
| **Next** | — |

---

## BUG-AB-002 — Progress smoother stage cap (P2)

| | |
|---|---|
| **Fix** | `restore.js` `getStageCap()` includes `restore-db` → cap 80 |
| **Verification** | ✅ Code review (`assets/js/restore.js` ~491) |
| **R-S3-progress** | **Pass（靜態）** — 需 UI E2E 時在 QA-A2/B1 還原頁觀察 |

---

## BUG-AB-003 — S13 preflight warning (P2)

| | |
|---|---|
| **Fix** | Warning in `class-restore-preflight.php` when db_then_files + !pause + full scope |
| **Verification** | ✅ **Pass** |
| **Evidence** | `docs/qa-evidence/approach-b-retest-2026-05/R-S13/preflight-output.json`; CLI `tools/qa/run-preflight-s13-cli.php` |

---

## BUG-AB-004 — WP-CLI OOM in Docker QA (P2, infra)

| | |
|---|---|
| **Symptom** | After `wp plugin activate museder-restoreone`, `wp` commands exhaust 1GB in **wp-cli phar** |
| **Workaround** | Use `php /tmp/run-*.php` with `require wp-load.php` inside **web container** (Apache PHP works — B1 front 200) |
| **Note** | Deploy **packaged** zip (`dist/museder-restoreone-2.7.268.zip`), not full repo `tar` into plugins (8MB dev tree) |
| **Retest script** | `approach-b-retest.ps1` updated to mount `php.ini` on `docker run wp` |

---

## BUG-AB-005 — Bootstrap stubs incomplete (P1)

| | |
|---|---|
| **Report** | `docs/BUG-INVESTIGATION-2026-05-27-bootstrap-sanitize-key-R-S2.md` |
| **Fix（已合）** | `sanitize_key`, `get_bloginfo`, `wp_upload_dir` stubs；legacy skip in bootstrap mode |
| **Fix（R4）** | `MINUTE/HOUR/DAY/WEEK_IN_SECONDS`；`wp_date`/`wp_timezone`/`date_i18n` stubs；bootstrap `local_time`/`format_local_time` → `gmdate` |
| **R-S2 第三輪** | POST 500 `HOUR_IN_SECONDS` — `R-S2-round3/` |
| **R-S2 第四輪** | POST 500 `get_site_transient` — `R-S2-round4/`（job 可到 `validated`） |
| **Fix（R5）** | `Restore_Lock::use_file_lock()` — bootstrap 一律檔案鎖 ✅ |
| **R-S2 第五輪** | POST 500 `wp_generate_password`；poll `plugin_basename` — `R-S2-round5/` |
| **Fix（R6）** | `wp_generate_password`、`plugin_basename`、`wp_hash` 等 stubs |
| **R-S2 第六輪** | handoff/CORE/meta ✅；POST `ensure_job_tmp_directory` protected；poll `is_multisite` redeclare — `R-S2-round6/` |
| **Fix（R7）** | `get_job_tmp_directory()`；移除 `is_multisite` stub；`wp-load` 存在時先載入 core |
| **R-S2 第七輪** | POST 500 `is_wp_error`；poll `WP_Error` class 衝突；job 75% — `R-S2-round7/` |
| **Fix（R8）** | `Museder_Restoreone_Bootstrap_WP_Error`；移除 `is_wp_error` stub；`bootstrap_prepare_runtime()` / `maybe_load_wordpress()` |
| **R-S2 第八輪** | POST 500 `apply_filters` redeclare（L610 vs `plugin.php`）；poll 302；job 75% — `R-S2-round8/` job `rjb_20260527_131640_zaaf9y` |
| **Fix（R9）** | `BOOTSTRAP_STUBS_ACTIVE`；stub 請求禁止同請求 `load_wordpress`；poll `DOING_CRON` |
| **R-S2 第九輪** | POST **200** ✅；poll 302→`wp-admin/install.php`；job 75% — `R-S2-round9/` job `rjb_20260527_135530_fww5cf` |
| **Fix（R10）** | `WP_INSTALLING` before `wp-load`；`X-Museder-Restoreone-Bootstrap` header |
| **R-S2 第十輪** | install✅；POST 500 `status_header`；poll 500 `Restore` class — `R-S2-round10/` |
| **Fix（R11）** | `send_bootstrap_response_headers()`；`class-restore.php` in stack |
| **R-S2 第十一輪** | POST/poll 200；80–90% 迴圈 — `R-S2-round11/` |
| **Fix（R12）** | `stage_import_database()`：`files_then_db` → `search-replace`（非 `restore-files`） |
| **R-S2 第十二輪** | ✅ **Pass** — `completed:true` 100% — `R-S2-round12/` job `rjb_20260527_153349_owtrvd` |
| **Verify（完成定義）** | POST 非 500、job 100%、`pause_other_plugins=true` |

---

## Closed / external

| ID | Note |
|----|------|
| BUG-SUN-002 | `.htaccess` on Nginx — P2 |
| 2.7.264 zip nesting | Packaging — not Approach B |
