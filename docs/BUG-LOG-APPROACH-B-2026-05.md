# Bug Log — Approach B QA Matrix (2026-05)

**Baseline under test:** `2.7.268`  
**Related:** `docs/QA-APPROACH-B-RESULTS.md`, `docs/BUG-INVESTIGATION-2026-05-25-restore-stall-76pct-2.7.264.md`

---

## Summary

| ID | Sev | Status | Title |
|----|-----|--------|-------|
| BUG-AB-001 | **P1** | **Fixed（2.7.268，未 bump）** | Bootstrap restore defaults `pause_other_plugins=false` — isolation skipped at files stage |
| BUG-AB-002 | P2 | **Fixed（2.7.268，未 bump）** | `restore.js` smoother cap key `restore-import-db` ≠ PHP stage `restore-db` |
| BUG-AB-003 | P2 | **Fixed（2.7.268，未 bump）** | S13 path: preflight warning when `db_then_files` + pause off on full restore |

**Fixed in baseline (regression only — verify on QA docroot):**

| Ref | Fix | Verify in |
|-----|-----|-----------|
| sunpower P1 | `zip_archive_has_wp_core` public | S4/S8 preflight |
| sunpower P1 | mid-restore plugin isolation after DB (`db_then_files`) | S5 on QA-B1 |
| 2.7.264 incident | `enter_mid_restore_plugin_isolation` + MU guard | S5, fresh_wp `db_then_files` |

---

## BUG-AB-001 — Bootstrap does not enable plugin isolation (P1)

### Severity

**P1** — Blocks reliable **S2** (empty docroot bootstrap E2E) on multi-plugin archives when WordPress becomes bootable mid-restore.

### Reproduction (code path)

1. `Museder_Restoreone_Restore_Bootstrap::start_restore_from_bootstrap()` sets:

```php
'pause_other_plugins' => false,
```

(`includes/class-restore-bootstrap.php` ~711)

2. `maybe_enter_plugin_isolation_at_files_stage()` **returns immediately** when `pause_other_plugins` is empty:

```php
if ( empty( $options['pause_other_plugins'] ) ) {
    return;
}
```

(`includes/class-restore-service.php` ~2181)

3. Bootstrap uses `files_then_db` + `empty_shell`, so isolation after DB import is also **skipped** (`should_enter_plugin_isolation_after_db_import` only for `db_then_files`).

### Expected

Approach B spec: isolation from **P1 (files start)** through **P4** for risky paths; Step 2 default pause ON; empty_shell full restore should not bootstrap-load incomplete third-party plugins.

### Actual

Bootstrap path explicitly disables pause → **no** `enter_mid_restore_plugin_isolation()` at files stage.

### Impact

- **S2** E2E may fatal or stall if `wp-cron.php` / loopback loads restored `active_plugins` before plugin tree is complete (same class as sunpower 5/25 incident, different entry point).
- UI bootstrap POST is not equivalent to admin Step 2 with default pause checked.

### Suggested fix (dev agent)

- Set `'pause_other_plugins' => true` for bootstrap full-site restores; or
- Call `enter_mid_restore_plugin_isolation()` for `PROFILE_EMPTY` regardless of checkbox; or
- Document bootstrap as **files_only safe** and block multi-plugin FULL until pause enabled.

### Verify

- QA-A1: S2 POST full restore with FULL-S zip → job 100%, no AIOSEO-style fatal in error log.

---

## BUG-AB-002 — Progress smoother stage name mismatch (P2)

### Severity

**P2** — UX / misleading progress during `restore-db` stage.

### Detail

`assets/js/restore.js` `getStageCap()` checks `restore-import-db`, but PHP job meta uses stage `restore-db`. Smoother cap falls through to **99** instead of **80**, so stall-smoothing may show unrealistic progress.

### Suggested fix

Align JS cap with `restore-db` (or map stage names in API response).

### Verify

- S3: during DB import, display progress does not drift toward 99% while server reports 80–90.

---

## BUG-AB-003 — S13 documented risk: pause off + db_then_files (P2)

### Severity

**P2** — Documented advanced path; regression of pre-268 sunpower failure mode.

### Detail

When user **unchecks** “pause other plugins” on a **populated** site with default **`db_then_files`**:

- DB import activates full `active_plugins`
- `post_db_import_recovery()` nudges cron
- File restore not complete → third-party plugin fatal possible

### Expected per spec

S13: “能跑完（文件註明風險）” — should complete OR fail gracefully with clear message.

### Suggested fix

- Preflight warning when `!pause_other_plugins && db_then_files && archive has many plugins`
- Or force pause when `db_then_files` + populated profile

### Verify

- QA-B1 S13 with pause unchecked: either Pass with warning or Blocked with explicit UI copy.

---

## Closed / external (do not reopen without QA docroot)

| ID | Note |
|----|------|
| BUG-SUN-002 | Missing `.htaccess` on Nginx host — P2, sunpower S1-htaccess |
| 2.7.264 zip nesting | Local package layout — not Approach B matrix |
