# Bug Log — Approach B QA Matrix (2026-05)

**Baseline:** `2.7.268` (`ae87d28`)  
**Regression proposal:** `docs/QA-APPROACH-B-RETEST-PROPOSAL-2026-05.md`

---

## Summary

| ID | Sev | Status | Title |
|----|-----|--------|-------|
| BUG-AB-001 | P1 | **Fixed — E2E blocked by AB-005** | Bootstrap `pause_other_plugins` → true |
| BUG-AB-002 | P2 | **Verified** | `restore.js` cap for `restore-db` |
| BUG-AB-003 | P2 | **Verified** | Preflight warning when pause off + db_then_files |
| BUG-AB-004 | P2 | Open (QA infra) | WP-CLI phar OOM with plugin active; use container `php` |
| BUG-AB-005 | **P1** | **Fixed（2.7.268，未 bump）** | Bootstrap POST: missing `sanitize_key` / `get_bloginfo` stubs |

---

## BUG-AB-001 — Bootstrap plugin isolation (P1)

| | |
|---|---|
| **Fix** | `pause_other_plugins => true` in `class-restore-bootstrap.php` ~711 (`ae87d28`) |
| **Static** | ✅ Confirmed in repo |
| **R-S2 E2E** | ⏳ **Pending re-test** — AB-005 fixed (`sanitize_key`, `get_bloginfo` stubs) |
| **Next** | Re-test R-S2 (QA-A1 bootstrap POST → job 100%) |

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

## BUG-AB-005 — Bootstrap `sanitize_key` fatal (P1)

| | |
|---|---|
| **Report** | `docs/BUG-INVESTIGATION-2026-05-27-bootstrap-sanitize-key-R-S2.md` |
| **Trigger** | Bootstrap POST → `normalize_options()` / `preflight()` before `wp-load.php` |
| **Fix** | `register_wordpress_stubs()`: add `sanitize_key` + `get_bloginfo` (version from `wp-includes/version.php` when present) |
| **Verify** | QA-A1: POST bootstrap → job id, not 500; R-S2 E2E |

---

## Closed / external

| ID | Note |
|----|------|
| BUG-SUN-002 | `.htaccess` on Nginx — P2 |
| 2.7.264 zip nesting | Packaging — not Approach B |
