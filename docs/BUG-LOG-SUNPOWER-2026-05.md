# Bug Log — sunpoweroflight 實機驗證（2026-05）

**外掛 baseline：** 2.7.268（P1 已併入正式版）  
**主機：** 118.139.182.16  
**計畫：** `docs/QA-SUNPOWEROFLIGHT-FIELD-TEST-2026-05-25.md`

| ID | Sev | Scenario | 標題 | 狀態 | 備註 |
|----|-----|----------|------|------|------|
| BUG-SUN-001 | P1 | S4/S3 UI | `zip_archive_has_wp_core()` 為 protected，preflight 致命錯誤 | **Fixed（2.7.268）** | 改為 `public static` |
| BUG-SUN-002 | P2 | S1 | 還原後 docroot 無 `.htaccess` | **Open** | 前台/wp-admin 仍 200；`permalink_structure` 空，flush 無法生成；可能為 Nginx 主機 |
| BUG-SUN-003 | P1 | S2 | Bootstrap 在 stub 前呼叫 `wp_normalize_path` / `trailingslashit` | **Fixed（2.7.268）** | 調整 stub 順序 |
| BUG-SUN-004 | P1 | S2 | Bootstrap 缺少 `esc_attr()` stub | **Fixed（2.7.268）** | tno/sun bootstrap HTTP 200 |

---

### BUG-SUN-001 — preflight 呼叫 protected 方法

- **Scenario:** S4、還原 Step 1 分析
- **Severity:** P1
- **Build:** 2.7.267（初版）
- **Repro:** `Museder_Restoreone_Restore_Preflight::preflight()` → `zip_archive_has_wp_core()`
- **Expected / Actual:** 應可檢查封存 / 實際 PHP Fatal
- **Evidence:** `sunpower-field-qa.php` wp eval；`error_log` 2026-05-25 06:51 UTC
- **Fix:** `includes/class-restore-service.php` — 方法改為 `public static`
- **Verify:** QA script S4/S4b PASS @ 2.7.267 主機

### BUG-SUN-002 — 無 .htaccess

- **Scenario:** S1
- **Severity:** P2
- **Build:** 2.7.267
- **Repro:** 成功還原後 `test -f docroot/.htaccess`
- **Expected / Actual:** 應有 fallback 或 permalink 生成 / 仍缺失
- **Evidence:** QA `S1-htaccess` FAIL；`wp rewrite flush` 警告 rules empty
- **Fix:** 待評估（Nginx 主機是否改寫 readme／僅 Apache 寫入）
- **Verify:** —

### BUG-SUN-003 / 004 — Bootstrap stub

- **Scenario:** S2
- **Severity:** P1
- **Fix:** `register_wordpress_stubs()` 順序、`esc_attr`、路徑正規化
- **Verify:** `curl` bootstrap → **200**（tno 與 sunpower docroot）
