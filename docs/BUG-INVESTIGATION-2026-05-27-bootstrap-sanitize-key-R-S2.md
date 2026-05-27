# Bug Investigation Report: Bootstrap POST Fatal `sanitize_key()` (R-S2 Blocker)

## Summary

Approach B regression test **R-S2** (empty docroot bootstrap full restore E2E) fails on **POST** to `museder-restoreone-restore-bootstrap.php` with **HTTP 500**.

**Root cause:** After commit `ae87d28` (BUG-AB-001), bootstrap calls `Museder_Restoreone_Restore_Preflight::normalize_options()` before WordPress core is loaded. That method uses `sanitize_key()`, which is **not** defined in bootstrap WordPress stubs.

**Relation to AB-001:** AB-001 fix (`pause_other_plugins => true`) is correct but **exposes** this bootstrap stub gap because `normalize_options()` is now invoked on the POST path.

## Affected Context

| Field | Value |
|-------|-------|
| Build | `2.7.268` (`ae87d28`) |
| Environment | QA-A1 Docker (`localhost:8081`), empty_shell |
| Backup | `localhost-20260527083521-E02Tyj.zip` (FULL-S from QA-B1) |
| Bootstrap GET | **200** (form renders) |
| Bootstrap POST | **500** |

## Evidence

Apache error log (`museder-restoreone-qa-a1-1`):

```
PHP Fatal error: Call to undefined function sanitize_key()
  in .../includes/class-restore-preflight.php:70
Stack trace:
#0 .../class-restore-bootstrap.php(705): Museder_Restoreone_Restore_Preflight::normalize_options(Array)
#1 .../class-restore-bootstrap.php(62): ...::start_restore_from_bootstrap(...)
#2 .../museder-restoreone-restore-bootstrap.php(30): ...::handle_request()
```

`class-restore-bootstrap.php` `register_wordpress_stubs()` defines `sanitize_file_name`, `sanitize_text_field`, etc., but **not** `sanitize_key`.

## Code Path

1. `start_restore_from_bootstrap()` builds options array including `pause_other_plugins => true` (AB-001).
2. Calls `Museder_Restoreone_Restore_Preflight::normalize_options( $options )` (line ~705).
3. `normalize_options()` line 70: `sanitize_key( (string) $options['restore_profile'] )` → fatal.

## Proposed Fix (dev agent)

**Option A (minimal):** Add bootstrap stub:

```php
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
    }
}
```

**Option B:** After `wp-load.php` exists, defer `normalize_options()` until WP is loaded; before that use a bootstrap-safe options array without calling preflight.

Also audit `normalize_options()` / `preflight()` for other missing stubs (`get_bloginfo`, etc.) on bootstrap POST.

## Validation

1. QA-A1: bootstrap GET 200, POST returns job id (not 500).
2. Job reaches 100%; `wp-admin/index.php` and `wp-load.php` exist.
3. No Fatal in Apache log during restore.
4. Job meta shows `pause_other_plugins: true` (AB-001).

## Severity

**P1** — Blocks S2 / empty docroot bootstrap E2E; distinct from AB-001 isolation logic.

---

## Update — 2026-05-27 第二輪回歸（AB-005 部分修復後）

### 已修復（可確認）

- `register_wordpress_stubs()` 新增 **`sanitize_key`**、**`get_bloginfo`** → POST 不再於 `class-restore-preflight.php:70` fatal。

### 仍失敗（R-S2 未通過）

| 步驟 | 結果 |
|------|------|
| GET bootstrap | **200** |
| POST bootstrap | **500** |

**新 Fatal（同一 POST 路徑）：**

```
Call to undefined function wp_upload_dir()
  in includes/helpers.php:346
  museder_restoreone_get_legacy_storage_roots()
  → museder_restoreone_get_all_backup_dirs()
  → Restore_Service::prepare('existing', ...)
```

**說明：** `museder_restoreone_get_storage_root()` 在 `MUSEDER_RESTOREONE_BOOTSTRAP_ROOT` 已定義時可走 bootstrap 路徑，但 `get_all_backup_dirs()` 仍會呼叫 **legacy** 掃描，而 `get_legacy_storage_roots()` **無條件**呼叫 `wp_upload_dir()`。

### 建議修復（dev agent，擇一）

**A)** `register_wordpress_stubs()` 新增 `wp_upload_dir()`，回傳：

```php
'basedir' => MUSEDER_RESTOREONE_BOOTSTRAP_ROOT . '/wp-content/uploads',
'baseurl' => '',
```

**B)** `museder_restoreone_get_legacy_storage_roots()`（及 legacy log dirs）在 `defined( 'MUSEDER_RESTOREONE_BOOTSTRAP_ROOT' ) && ! function_exists( 'wp_upload_dir' )` 時 **return []**。

### 證據

- `docs/qa-evidence/approach-b-retest-2026-05/R-S2-round2/bootstrap-post-status.txt` → `500`
- Apache：`R-S2-round2` 同目錄或 `docker logs museder-restoreone-qa-a1-1`（2026-05-27 10:36 UTC）

### 狀態

| Bug | 第二輪 |
|-----|--------|
| BUG-AB-005 | **Partial** — sanitize_key ✅；wp_upload_dir ❌（→ dev 已補 stub + legacy skip） |
| BUG-AB-001 E2E | **Blocked** — 仍無法啟動 job |

### 第三輪（2026-05-27 執行結果）

**已部署：** `class-restore-bootstrap.php`（含 `wp_upload_dir` stub）、`helpers.php`（legacy 在 `MUSEDER_RESTOREONE_BOOTSTRAP_MODE` 回傳 `[]`）。

| 步驟 | 結果 |
|------|------|
| GET bootstrap | **200** |
| POST bootstrap | **500** |

**Fatal：**

```
Undefined constant "HOUR_IN_SECONDS"
  in includes/helpers.php:52
  museder_restoreone_local_time('Ymd_His')
  → Restore_Service::generate_job_id()
  → Restore_Service::prepare('existing', ...)
```

**說明：** POST 已通過 preflight 與 backup 路徑解析，在 **`prepare()` 產生 job_id** 時失敗。Bootstrap 未定義 WP 時間常數，且 `museder_restoreone_local_time()` 走 `date_i18n` 分支（無 `wp_date()`）。

**建議修復（dev，擇一）：**

**A)** `register_wordpress_stubs()` 定義 `MINUTE_IN_SECONDS`、`HOUR_IN_SECONDS`、`DAY_IN_SECONDS`、`WEEK_IN_SECONDS`（與 WP core 相同數值）。

**B)** `museder_restoreone_local_time()` 在 `MUSEDER_RESTOREONE_BOOTSTRAP_MODE` 時直接 `gmdate( $format, $timestamp ?? time() )`。

**C)** bootstrap stub 提供 `wp_date()`。

**證據：** `docs/qa-evidence/approach-b-retest-2026-05/R-S2-round3/apache-fatal-hour-in-seconds.log`

| Bug | 第三輪 |
|-----|--------|
| BUG-AB-005 | **Partial** — wp_upload_dir + legacy skip ✅；**HOUR_IN_SECONDS** ❌ |
| BUG-AB-001 E2E | **Blocked** |

**第四輪：** 修時間常數或 local_time 後，僅重跑 R-S2。

---

## Update — 2026-05-27 第四輪回歸

### 已通過（相對第三輪）

- `HOUR_IN_SECONDS` / `wp_date` / bootstrap `local_time` ✅
- `prepare()` 在容器 CLI smoke 可產生 `job_id`（約 2s）
- 部分 POST 在 **2048M** 下曾回 **200**（但內容為錯誤頁，見下）

### 仍失敗

| 嘗試 | HTTP | 現象 |
|------|------|------|
| POST（1GB，輪 4 初） | **500** | Memory exhausted（`helpers.php:82`，POST 含 start + 25s slice） |
| POST（2GB ini） | **500** | **`get_site_transient()` undefined** — `class-restore-lock.php:149` |
| POST（2GB，清 jobs 後） | **500** | 同上；job 已寫入 `stage: validated` 後 `execute()` 失敗 |

**Fatal 堆疊：**

```
Call to undefined function get_site_transient()
  → Museder_Restoreone_Restore_Lock::current_lock()
  → Restore_Lock::is_locked()
  → Restore_Service::execute()
  → start_restore_from_bootstrap()
```

**根因（程式邏輯）：** `Restore_Lock::use_file_lock()` 僅在 `MUSEDER_RESTOREONE_BOOTSTRAP_MODE && ! function_exists( 'update_option' )` 時為 true。Bootstrap **已 stub `update_option`**，故走 transient 路徑，但 **未 stub `get_site_transient`**。

**建議修復（dev，擇一）：**

**A)** `use_file_lock()` 改為：僅判斷 `MUSEDER_RESTOREONE_BOOTSTRAP_MODE`（bootstrap 一律檔案鎖）。

**B)** `register_wordpress_stubs()` 實作 `get_site_transient` / `set_site_transient` / `delete_site_transient`（對應 bootstrap-options 或檔案）。

**證據：** `docs/qa-evidence/approach-b-retest-2026-05/R-S2-round4/`（`bootstrap-post2`、job `rjb_20260527_112145_536002.json`）

| Bug | 第四輪 |
|-----|--------|
| BUG-AB-005 | **Partial** — 時間常數 ✅；**restore lock / transient** ❌ |
| BUG-AB-001 E2E | **Blocked** |

**第五輪：** 修 lock 路徑後僅重跑 R-S2。

---

## Update — 2026-05-27 第五輪回歸

### 已通過

| 項目 | 結果 |
|------|------|
| `Restore_Lock::use_file_lock()` | ✅ 僅 `BOOTSTRAP_MODE`；`bootstrap-restore.lock` 已建立 |
| `execute()` 進入還原佇列 | ✅ job `rjb_20260527_112949_690058`，`stage: restore-files`，`progress: 70` |
| **`pause_other_plugins`（meta）** | ✅ `options.pause_other_plugins: true`（AB-001 設定已寫入 job meta） |

### 仍失敗（R-S2 未通過）

| 步驟 | HTTP | Fatal / 阻擋 |
|------|------|----------------|
| **POST** 啟動 | **500** | `wp_generate_password()` — `class-restore-token.php:36`（`execute()` 末段） |
| **GET poll**（手動 handoff 後） | 200 但無進度 | `plugin_basename()` — `class-restore-service.php:940`（`enter_mid_restore_plugin_isolation` / **AB-001 路徑**） |

**說明：** 檔案鎖修復有效；POST 在產生 restore token 前崩潰（未寫 `bootstrap-handoff.json`）。手動補 handoff 後 poll 會跑 `process_bootstrap_files_only_slice`，進入 **plugin isolation** 時缺 `plugin_basename()` stub。

### 建議修復（第六輪前，dev）

1. `register_wordpress_stubs()`：`wp_generate_password()`、`wp_hash()`（若 token 需要）、`plugin_basename()`
2. 或 bootstrap 模式略過 token 產生 / isolation 改用不依賴 WP plugin API 的路徑

### 證據

- `docs/qa-evidence/approach-b-retest-2026-05/R-S2-round5/`
- job meta：`jobs/rjb_20260527_112949_690058.json`（`pause_other_plugins: true`）

| Bug | 第五輪 |
|-----|--------|
| BUG-AB-005 | **Partial** — file lock ✅；stub 鏈仍缺 |
| BUG-AB-001 E2E | **Partial** — meta 已 true；還原 slice isolation **未跑通** |

**第六輪：** 補上述 stub 後重跑 R-S2（POST 非 500 → poll 至 100% → CORE_OK）。

---

## Update — 2026-05-27 第六輪回歸

### 已通過（相對第五輪）

| 項目 | 結果 |
|------|------|
| `bootstrap-handoff.json` | ✅ 自動寫入（`job_id` + `qa-secret-12345`） |
| `pause_other_plugins` | ✅ job meta `options.pause_other_plugins: true` |
| `wp_generate_password` / `plugin_basename` stubs | ✅ POST 可啟動還原、進入 isolation 與 zip 解檔 |
| **WP 核心檔** | ✅ `wp-load.php`、`wp-admin/index.php` 存在（poll 期間 **CORE_OK**） |
| job 進度 | 最高 **95%**（`restore-files`，`zip_index` 3283/3347） |

### 未達 R-S2 完成定義

| 項目 | 結果 |
|------|------|
| POST HTTP | ❌ **500**（同請求內 slice 繼續處理時 Fatal） |
| poll HTTP | ❌ **500**（`wp-load.php` 存在後 `load_wordpress()` 與 stub `is_multisite()` 衝突） |
| job 100% / `completed` | ❌ `completed: false`，stage 卡在 `restore-files` |

### 新 Fatal

1. **`ensure_job_tmp_directory()` protected** — `Preflight::apply_wp_config_policy()` 呼叫 protected 方法（`class-restore-preflight.php:317`），發生於 POST 同請求 slice（約 95% 前後）。

2. **`Cannot redeclare is_multisite()`** — bootstrap stub 已定義 `is_multisite()`，之後 `require wp-load.php` 時 core 再次定義。建議：**勿 stub `is_multisite`**，或僅在 `! wordpress_is_loadable()` 時註冊 stubs / `load_wordpress()` 前不註冊會與 core 衝突的函式。

### 證據

`docs/qa-evidence/approach-b-retest-2026-05/R-S2-round6/`（job `rjb_20260527_115715_gdrdfh`）

| Bug | 第六輪 |
|-----|--------|
| BUG-AB-005 | **Partial** — token/isolation stubs ✅；wp-load 後 poll ❌ |
| BUG-AB-001 | **Partial** — meta `pause_other_plugins: true`；E2E 未完成 |
| **新** | `ensure_job_tmp_directory` visibility；`is_multisite` stub vs core |

**第七輪：** 修上述兩項後重跑 R-S2。

### 第七輪（2026-05-27 執行結果）

| 項目 | 結果 |
|------|------|
| `get_job_tmp_directory` / 無 `is_multisite` stub | ✅ 檔案階段完成（`Files restored. Preparing database…`） |
| `bootstrap-handoff.json` | ✅ |
| `pause_other_plugins` | ✅ meta |
| POST | ❌ **500** — `is_wp_error()` redeclare（同請求內 wp-load 出現後） |
| poll | ❌ HTTP **200** 但 job **卡住** 75% — `Cannot declare class WP_Error`（minimal class vs core） |
| job 100% | ❌ `restore-extract-db`, `completed: false` |

**建議（第八輪）：** 勿在 `wp-load` 可用前定義 minimal `WP_Error` / `is_wp_error` stub；或 `wordpress_is_loadable()` 時全程 `load_wordpress()` 且不再載入會衝突的 bootstrap 定義。

證據：`docs/qa-evidence/approach-b-retest-2026-05/R-S2-round7/`（job `rjb_20260527_124930_7h5jhc`）

| Verified | ❌ |

**第八輪（dev 修復後待測）：** 見 `class-restore-bootstrap.php` — `Museder_Restoreone_Bootstrap_WP_Error`、`bootstrap_prepare_runtime()`、`maybe_load_wordpress()`；移除 `is_wp_error` stub。

---

## Update — 2026-05-27 第八輪回歸

| 項目 | 結果 |
|------|------|
| WP_Error / is_wp_error | ✅ 未再出現 |
| 檔案階段 / handoff / pause / CORE | ✅ |
| POST | ❌ **500** — `Cannot redeclare apply_filters()`（stub 後同請求 `load_wordpress()`） |
| poll | ❌ **302**；job **75%** `restore-extract-db` |

**根因：** `register_wordpress_stubs()` 定義 `apply_filters()`；檔案 slice 完成後第八輪在**同一 POST** 呼叫 `maybe_load_wordpress()` → core `plugin.php` Fatal。

**第九輪修復：** `MUSEDER_RESTOREONE_BOOTSTRAP_STUBS_ACTIVE` — stub 請求內**禁止** `load_wordpress()`；DB 階段改由**下一輪 poll**（無 stub、先載 core）。`WP_USE_THEMES=false`、`DOING_CRON=true` 減少 poll 302。

### 第九輪（dev 修復後待測）

- `bootstrap_stubs_are_active()` + `maybe_load_wordpress()` / `load_wordpress()` 守衛
- POST 檔案完成後僅 `spawn_loopback`，不在同請求跑 `process_job_slice`
- 測試 Agent：僅重跑 **R-S2**（QA-A1，≥ 2048M）

### 第八輪（2026-05-27 執行結果）

| 項目 | 結果 |
|------|------|
| 部署 | ✅ 容器內 `class-restore-bootstrap.php` 含 `Museder_Restoreone_Bootstrap_WP_Error`（grep ≥1） |
| 檔案階段 / handoff | ✅ `bootstrap-handoff.json`；`wp-load.php` + `wp-admin`（**CORE_OK**） |
| `pause_other_plugins` | ✅ job meta `true` |
| POST | ❌ **HTTP 500** |
| poll | ❌ **HTTP 302**（WP 已存在時可能導向登入）；job **未前進**，仍 **75%** |
| job 100% / `completed` | ❌ `restore-extract-db`, `completed: false` |

**Job ID：** `rjb_20260527_131640_zaaf9y`

**新 Fatal（第七輪 `WP_Error` / `is_wp_error` 已避開，同類問題延續）：**

```
PHP Fatal error: Cannot redeclare apply_filters()
  (previously declared in .../class-restore-bootstrap.php:610)
  in .../wp-includes/plugin.php on line 209
```

**根因（與第七輪相同模式）：**

1. `bootstrap_prepare_runtime()` 在 **POST 開頭** `wp-load.php` 尚不存在 → `register_wordpress_stubs()` 註冊 `apply_filters()`（約 L604–612）。
2. 檔案 slice 解出核心後，同請求內 `maybe_load_wordpress()` → `require wp-load.php` → core 再定義 `apply_filters()` → **Fatal**。
3. POST 在 ~75%（`restore-extract-db`）中斷；後續 poll 無法推進 DB（job `updated_at` 停在 13:16:42）。

**第八輪 dev 修復評估：**

| 修復項 | 狀態 |
|--------|------|
| `Museder_Restoreone_Bootstrap_WP_Error` / 無 `is_wp_error` stub | ✅ 未再出現 `WP_Error` / `is_wp_error` redeclare |
| `bootstrap_prepare_runtime()` 先 `maybe_load_wordpress()` | ⚠️ 僅在 **請求開始時** wp-load 已存在才有效；empty_shell POST **仍先註冊 stubs** |
| POST 同請求接 DB slice | ❌ 被 `apply_filters` redeclare 阻斷 |

**建議（第九輪 dev）：**

1. **勿 stub 與 `wp-includes/plugin.php` 同名函式**（至少 `apply_filters`；並稽核 `get_option` / `update_option` / `add_filter` 等同請求載入 core 時會衝突者）。
2. **同請求內 wp-load 出現後載入 core：** 僅在「本請求從未註冊過會與 core 衝突的 stub」時才 `require wp-load.php`；PHP 無法 unload 已宣告函式 → 實務上需 **(A)** 檔案階段結束後以 **新 HTTP 請求 / spawn** 跑 DB（無 stub 的乾淨 process），或 **(B)** 檔案階段程式改為不依賴 `apply_filters` 等 hook API，直到 core 載入。
3. **poll：** `wordpress_is_loadable()` 為 true 時 `bootstrap_prepare_runtime()` 應 **只** `maybe_load_wordpress()`、**不** 再 `register_wordpress_stubs()`（現行邏輯已如此）；驗證 poll URL 在 core 存在時回 **200 JSON** 而非 302 登入頁。

證據：`docs/qa-evidence/approach-b-retest-2026-05/R-S2-round8/`（`bootstrap-post-status.txt`、job meta、`poll-*.html`）

| Verified | ❌ |

### 第九輪（2026-05-27 執行結果）

| 項目 | 結果 |
|------|------|
| 兩階段請求（POST 不載 core） | ✅ **POST HTTP 200**（不再 `apply_filters` Fatal） |
| 檔案階段 / handoff / CORE | ✅ `bootstrap-handoff.json`；`wp-load.php` + wp-admin |
| `pause_other_plugins` | ✅ meta `true` |
| POST 後進度 | ✅ 約 **75%**，`restore-extract-db`（符合預期） |
| Poll 推進 DB → 100% | ❌ job `updated_at` 停在 POST 後，**`completed: false`** |
| 新 Fatal（POST 後） | ✅ 無（舊 Fatal 為歷史 log） |

**Job ID：** `rjb_20260527_135530_fww5cf`

**Poll 行為（新阻塞）：**

1. Poll 請求（無 stub）→ `bootstrap_prepare_runtime()` → `maybe_load_wordpress()` 成功載入 core。
2. WordPress 偵測資料庫尚未安裝 → **302** `Location: …/wp-admin/install.php`（`X-Redirect-By: WordPress`）。
3. `curl -L` 最終 **HTTP 200** 但內容為 **WordPress 安裝精靈**，非 bootstrap 進度頁 → **`process_job_slice` 未執行**。
4. 容器內 `curl` 對 bootstrap URL：**302**，body 0 bytes；job meta 無變化。

```
HTTP/1.1 302 Found
X-Redirect-By: WordPress
Location: http://127.0.0.1/wp-admin/install.php
```

**與第八輪對照：**

| | 第八輪 | 第九輪 |
|---|--------|--------|
| POST | 500 `apply_filters` | **200** |
| 同請求載入 core | Fatal | **已禁止**（`bootstrap_stubs_are_active`） |
| Poll | 302 / 卡 75% | 仍 **302→install.php**，卡 75% |

**建議（第十輪 dev）：**

1. DB 還原完成前載入 `wp-load.php` 時，避免觸發 `wp-admin/install.php` 導向（例如 bootstrap 專用常數／hook、或延後 `load_wordpress()` 直到 DB slice 可用獨立路徑）。
2. 確保 poll／loopback 在 DB 階段仍執行 `Museder_Restoreone_Restore_Service::process_job_slice()`（回應須為 bootstrap HTML，含 job 進度）。
3. QA runner：poll 使用 `curl -L` 並斷言 body 含 bootstrap 標記，勿僅以 HTTP 200 判定（安裝頁亦為 200）。

證據：`docs/qa-evidence/approach-b-retest-2026-05/R-S2-round9/`

| Verified | ❌ |

---

## Update — 2026-05-27 第九輪回歸（執行）／第十輪修復

### 第九輪已確認

| 項目 | 結果 |
|------|------|
| POST | ✅ **200**（`apply_filters` 同請求衝突已解） |
| 檔案 / handoff / pause | ✅ |
| POST 後 | **75%** `restore-extract-db`（預期） |

### 第九輪新阻塞

Poll 載入 `wp-load.php` 後，core 偵測 DB 未安裝 → **302** `wp-admin/install.php` → `process_job_slice` 未執行。

### 第十輪修復（dev）

- `load_wordpress()`：bootstrap 模式下於 `require wp-load.php` **之前** 定義 **`WP_INSTALLING`**（core `wp_not_installed()` 會略過 install 導向）
- `render_page()`：`status_header(200)` + `X-Museder-Restoreone-Bootstrap: 1` 供 QA 斷言

### 第十輪（2026-05-27 執行結果）

| 項目 | 結果 |
|------|------|
| `WP_INSTALLING` 部署 | ✅ 容器內 `grep WP_INSTALLING` ≥ 1 |
| install.php 導向 | ✅ **已避開**（poll `HEAD` **200**，非 302→install） |
| 檔案階段 / handoff / pause | ✅（job 目錄與 meta 已建立） |
| POST | ❌ **HTTP 500** |
| Poll GET | ❌ **HTTP 500**（進入 `process_job_slice` 後 Fatal） |
| job 100% / `completed` | ❌ **75%** `restore-extract-db` |

**Job ID：** `rjb_20260527_142429_nuopha`

**Fatal 1 — POST（stub 路徑，`render_page` 在 core 未載入）：**

```
Call to undefined function status_header()
  in class-restore-bootstrap.php:1053
  ← render_page() ← handle_request() line 128
```

第九輪 POST 為 **200**；第十輪在 `render_page()` 新增 `status_header(200)`，但 POST 結束時仍為 **stub 請求**（未 `load_wordpress()`），`status_header()` 尚不存在。

**Fatal 2 — Poll（`WP_INSTALLING` 生效後進入 DB slice）：**

```
Class "Museder_Restoreone_Restore" not found
  in class-restore-service.php:1766
  ← stage_import_database() ← process_job_slice() line 96
```

`load_plugin_stack()` 僅 `require` helpers / lock / token / preflight / **restore-service**，**未**載入 `includes/class-restore.php`（`Museder_Restoreone_Restore`）。Poll 已能載入 core 並呼叫 `process_job_slice`，但在 `restore-extract-db` 階段缺類別。

**附帶：** poll 期間 `wp_options` 表不存在時 `INSERT INTO wp_options`（cron）產生 DB notice（非主 Fatal）。

**建議（第十一輪 dev）：**

1. `render_page()`：`status_header()` 改 `function_exists( 'status_header' ) ? status_header( 200 ) : header( 'HTTP/1.1 200 OK' );`（或 stub 路徑略過）。
2. `load_plugin_stack()`：bootstrap poll 跑 DB 前 `require_once` `class-restore.php`（及 `stage_import_database` 依賴之檔案）。
3. 修後重跑 `tools/qa/run-r-s2-round10.ps1`；斷言 `X-Museder-Restoreone-Bootstrap: 1`、job `completed:true`。

證據：`docs/qa-evidence/approach-b-retest-2026-05/R-S2-round10/`

| Verified | ❌ |

### 第十一輪（2026-05-27 執行結果）

| 項目 | 結果 |
|------|------|
| POST | ✅ **HTTP 200** |
| Poll | ✅ **HTTP 200**，bootstrap 頁（非 install） |
| `X-Museder-Restoreone-Bootstrap: 1` | ✅（見 `poll-final-headers.txt`） |
| handoff / CORE / `pause_other_plugins` | ✅ |
| job `completed:true` / 100% | ❌ 逾 **30+ 分鐘**仍 `completed:false`，進度 **80–90%** 擺盪 |

**Job ID：** `rjb_20260527_143421_bvoj9n`

**第十輪回歸：** `status_header` stub 問題 ✅；`Museder_Restoreone_Restore` 未載入 ✅。

**新阻塞：** DB 訊息曾顯示 `Database import completed.`，但 meta 長期 `restore-db`/`restore-files`、`db_offset: 0`，無法進入完成態。可能為 bootstrap 下 NDJSON 匯入／階段轉換／`completed` 標記邏輯問題（需 dev 追查）。

證據：`docs/qa-evidence/approach-b-retest-2026-05/R-S2-round11/`（含 `job-snapshot-*.json`、`r-s2-summary.json`）

| Verified | ❌ |

**第十二輪建議：** 調查 bootstrap poll 下 job 無法 `completed:true`；修後重跑 `tools/qa/run-r-s2-round11.ps1`。

### 第十二輪（dev 修復 + QA — **R-S2 PASS**）

**根因：** `empty_shell` + `files_then_db`（bootstrap 預設）在 `stage_import_database()` 成功後一律設 `stage=restore-files`。檔案已在 POST 階段還原完畢；poll 再跑 `stage_restore_files()` → `complete_files_stage_and_advance()` 又設回 `restore-extract-db` → `restore-db`，形成 **80% / 90% 無限迴圈**。`db_offset` 維持 0 屬預期（NDJSON 走 `import_database()` 全量匯入，非 sliced SQL）。

**修復：** `includes/class-restore-service.php` — DB 匯入成功（及 manual SQL 路徑）依 `restore_order` 分支：`files_then_db` → `search-replace`（96%）；`db_then_files` → `restore-files`（90%）。

**執行結果（2026-05-27）：**

| 項目 | 結果 |
|------|------|
| POST | ✅ **HTTP 200** |
| Poll | ✅ **HTTP 200** + `X-Museder-Restoreone-Bootstrap: 1` |
| job | ✅ **`completed: true`**，`progress: 100`**，`stage: done`** |
| `pause_other_plugins` | ✅ meta `true` |
| CORE | ✅ `wp-load.php` + wp-admin |
| 80/90% 迴圈 | ✅ **已消除**（約 3s 內完成：15:33:49→15:33:52） |

**Job ID：** `rjb_20260527_153349_owtrvd`

**腳本：** `tools/qa/run-r-s2-round12.ps1`（部署含 R12 `class-restore-service.php`）。首輪 poll 即完成；`docker logs` stderr 觸發腳本非零結束，**不影響** E2E 結果。

證據：`docs/qa-evidence/approach-b-retest-2026-05/R-S2-round12/`

| Verified | ✅ **S2、BUG-AB-001、BUG-AB-005**（R-S2 E2E） |

### 第六輪（dev 修復後待測）

- Stubs：`wp_generate_password`、`wp_hash`/`wp_salt`、`plugin_basename`、`get_current_user_id`、`is_multisite`；定義 `WP_PLUGIN_DIR`
- 測試 Agent：僅重跑 **R-S2**（QA-A1，memory ≥ 2048M）

### 第五輪（dev 修復後待測）

- `Restore_Lock::use_file_lock()`：bootstrap 模式**一律**檔案鎖（不再要求 `! update_option`）
- 測試 Agent：僅重跑 **R-S2** on QA-A1（建議 PHP memory ≥ 2048M）

### 第四輪（dev 修復後待測）

- `register_wordpress_stubs()`：`MINUTE/HOUR/DAY/WEEK_IN_SECONDS`；`wp_date` / `wp_timezone` / `date_i18n` stubs
- `museder_restoreone_local_time()` / `format_local_time()`：bootstrap mode 使用 `gmdate()`
- 測試 Agent：僅重跑 **R-S2** on QA-A1
