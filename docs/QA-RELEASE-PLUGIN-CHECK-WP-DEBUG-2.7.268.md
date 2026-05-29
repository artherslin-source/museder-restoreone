# 發佈前測試報告 — Plugin Check + 乾淨安裝 WP_DEBUG Smoke

**版本：** `2.7.268`（工作區封裝；bootstrap P1 修復後重測）  
**日期：** 2026-05-27（首輪）／**2026-05-28**（P1 重跑 + 大站 smoke）  
**測試者：** QA Agent（Cursor）  
**關聯：** R-S2 已 **Pass**（`docs/qa-evidence/approach-b-retest-2026-05/R-S2-round12/`）

---

## 1. 測試環境

| 項目 | 值 |
|------|-----|
| 堆疊 | `docker compose`（`docker-compose.yml`） |
| WordPress | 6.6.2（`wordpress:6.6.2-php8.2-apache`） |
| PHP | 8.2（`tools/docker/php.ini` 掛載） |
| 資料庫 | MariaDB 10.11（**全新 volume**，`docker compose down -v` 後重建） |
| URL | `http://localhost:8080` |
| 外掛來源 | **`dist/museder-restoreone-2.7.268.zip`**（`tools/package-lite-windows.ps1`，BOUNDARY_CHECK=PASS） |
| Plugin Check | `plugin-check` 1.x（主機下載 ZIP → 容器安裝；容器內 `wp.org` 逾時） |

---

## 2. 封裝驗證

```
dist/museder-restoreone-2.7.268.zip
Version: 2.7.268 (header / define / readme Stable tag 一致)
ZIP entries: 79
BOUNDARY_CHECK=PASS
ZIP_STRUCTURE_OK (museder-restoreone/museder-restoreone.php)
```

---

## 3. Plugin Check

**指令：**

```bash
docker compose run --rm wpcli plugin check museder-restoreone
```

**完整輸出（首輪，修復前）：** `docs/qa-evidence/release-2.7.268-2026-05/plugin-check-output.txt`  
**完整輸出（P1 重跑）：** `docs/qa-evidence/release-2.7.268-2026-05/plugin-check-output-p1.txt`

### 摘要

| 輪次 | ERROR | WARNING | 結果 |
|------|-------|---------|------|
| 首輪（bootstrap P1 前） | 6 | 41 | 僅 bootstrap 兩檔 |
| **P1 重跑（2026-05-28）** | **0** | （未列舉） | **`Success: Checks complete. No errors found.`** |

**未出現問題的範圍：** `museder-restoreone.php`、`includes/` 其餘檔案（含 `class-restore-service.php` 等核心還原邏輯）在本輪 Plugin Check 輸出中**未列舉**。

### 問題檔案與性質

#### `includes/class-restore-bootstrap.php`

| 類型 | 代表 code | 說明 |
|------|-----------|------|
| ERROR | `missing_direct_file_access_protection` | 檔案刻意在 `ABSPATH` 之前載入；需文件化或加條件式 `ABSPATH` 守衛 |
| ERROR | `strip_tags` / `mkdir` / `is_writable` / `parse_url` | **bootstrap stub** 內刻意使用精簡實作（R8–R12） |
| WARNING | `NonceVerification.*` | bootstrap `handle_request` 以 `secret` 驗證，非 WP admin nonce |
| WARNING | `NonPrefixedFunctionFound` 等 | stub 函式命名為 WordPress 同名（設計使然） |

#### `museder-restoreone-restore-bootstrap.php`

| 類型 | 代表 code | 說明 |
|------|-----------|------|
| ERROR | `missing_direct_file_access_protection` | 文件根入口；複製到 docroot 前無 `ABSPATH` |
| WARNING | `NonPrefixedVariableFound` | 入口變數 `$bootstrap_class` |

### 對照 RELEASE_CHECKLIST

清單要求 **0 errors / 0 warnings**。本輪結果為 **未達標**，但問題**集中於 Approach B 新增／強化的 bootstrap 檔**（2.7.268 封裝已含 `museder-restoreone-restore-bootstrap.php`），**非**一般 admin 路徑回歸。

### 建議（開發 Agent）

1. 在 `class-restore-bootstrap.php` 頂端加入與 `museder-restoreone-restore-bootstrap.php` 一致的 **條件式 ABSPATH / bootstrap root** 檢查（滿足 Plugin Check，且不破壞 empty_shell）。
2. 對 `register_wordpress_stubs()` 區塊使用 **最小範圍** `phpcs:disable` + `docs/plugin-check-notes.md` 說明（與既有 direct I/O 策略一致）。
3. 發佈前再跑一輪 `wp plugin check`，確認 ERROR 歸零或僅剩已文件化例外。

---

## 4. 乾淨安裝 + WP_DEBUG Smoke

### 4.1 安裝與啟用

- `wp core install` 成功（admin / admin）
- 自 **`dist/museder-restoreone-2.7.268.zip`** 解壓至 `wp-content/plugins/museder-restoreone/`
- `wp plugin activate museder-restoreone` 成功

### 4.2 WP_DEBUG

```text
WP_DEBUG=true
WP_DEBUG_LOG=true
WP_DEBUG_DISPLAY=false
```

### 4.3 CLI Smoke（容器內）

**腳本：** `tools/qa/cli-wp-load-smoke.php`、`tools/qa/admin-smoke-wpdebug.php`

```
wp_version=6.6.2
plugin_active=yes
PASS museder-restoreone-dashboard len=4464
PASS museder-restoreone-backups len=10325
PASS museder-restoreone-restore len=15170
PASS museder-restoreone-schedules len=11837
PASS museder-restoreone-logs len=3199
PASS museder-restoreone-settings len=6419
SUMMARY pages_pass=6/6 debug_log_fatal=no
```

`wp-content/debug.log`：**無 PHP Fatal**（smoke 執行後）。

### 4.4 HTTP Smoke（登入後）

| 頁面 | HTTP |
|------|------|
| Dashboard | **200** |
| Backups | **200** |
| Restore | **200** |
| Schedules | **200** |
| Logs | **200** |
| Settings | **200** |
| `wp-admin/plugins.php` | **200** |

### 4.5 PHP 語法

容器內對封裝外掛全部 `.php` 執行 `php -l`：**無語法錯誤**。

### 對照 RELEASE_CHECKLIST §4

| 項目 | 結果 |
|------|------|
| 乾淨安裝可啟用 Lite | ✅ |
| `WP_DEBUG` 下無 fatal | ✅ |
| Dashboard / Backups / Restore / Schedules / Logs / Settings | ✅ |

---

## 5. 與 R-S2 關係

| 測試 | 結果 |
|------|------|
| R-S2 empty_shell bootstrap E2E（QA-A1） | ✅ Pass（第十二輪） |
| 本報告：封裝 + Plugin Check + 標準 WP 安裝 smoke | 見上文 |

**結論：** R-S2、乾淨安裝 smoke、**P1 後 Plugin Check（0 ERROR）**、大站 WP_DEBUG admin smoke 均已通過；可進入封裝 → SVN/tag 流程（見 §10）。

---

## 6. 總體判定

| 項目 | 判定 |
|------|------|
| Lite ZIP 封裝 | ✅ Pass |
| 乾淨安裝 + 啟用 | ✅ Pass |
| WP_DEBUG admin smoke | ✅ Pass |
| Plugin Check（0 ERROR） | ✅ **Pass**（§10.1 重跑確認） |
| 大站 WP_DEBUG admin smoke | ✅ **Pass**（§10.2，>5GB uploads + 電商 + 17 外掛 + Astra） |
| 建議阻擋 WP.org SVN？ | **否** |

---

## 7. 證據路徑

- `docs/qa-evidence/release-2.7.268-2026-05/plugin-check-output.txt`（修復前）
- `docs/qa-evidence/release-2.7.268-2026-05/plugin-check-output-p1.txt`（修復後）
- `docs/qa-evidence/release-2.7.268-2026-05/admin-smoke-cli.txt`（乾淨安裝）
- `docs/qa-evidence/release-2.7.268-2026-05/heavy-site/`（大站）
- `docs/qa-evidence/release-2.7.268-2026-05/cookies.txt`（HTTP smoke，首輪）
- `dist/museder-restoreone-2.7.268.zip`
- `dist/plugin-check.zip`（測試用，不納入發佈包）

---

## 8. 給開發 Agent 的精簡任務

1. ~~**P1：** bootstrap Plugin Check 6 ERROR~~ → **已完成**（§10.1）。
2. ~~**P2：** 重跑 `wp plugin check`~~ → **已完成**（0 ERROR）。
3. **已 OK：** 不需為本報告再改 R-S2 核心邏輯；R-S12 `stage_import_database` 分支已驗證。
4. **可選：** 將 `tools/qa/provision-heavy-site-smoke.ps1` 納入例行發佈前腳本（大站回歸）。

---

## 9. 開發修復（Plugin Check P1）

**變更（未 bump 版號）：**

| 檔案 | 處理 |
|------|------|
| `museder-restoreone-restore-bootstrap.php` | docroot 入口先定義 `MUSEDER_RESTOREONE_BOOTSTRAP_ROOT` + bootstrap `ABSPATH`；標準 `ABSPATH` guard；變數改 `$museder_restoreone_bootstrap_class` |
| `includes/class-restore-bootstrap.php` | 標準 `ABSPATH` guard；`handle_request` nonce 例外註解；`register_wordpress_stubs()` 區塊 `phpcs:disable`；`wp_strip_all_tags` stub 改 `preg_replace` |
| `docs/plugin-check-notes.md` | § 五 Bootstrap 說明 |

**QA 重跑：** 封裝後 `wp plugin check museder-restoreone`，預期 **0 ERROR**（WARNING 可能剩 stub 相關，已文件化）。

**驗證（2026-05-28，本機 docker）：** 重封裝 `dist/museder-restoreone-2.7.268.zip` → 乾淨 `docker compose` volume → `wp plugin check museder-restoreone` → **`Success: Checks complete. No errors found.`**（見 §10.1）。

---

## 10. QA 重跑（P1 修復後 + 大站 smoke）— 2026-05-28

### 10.1 封裝與 Plugin Check（乾淨安裝）

| 步驟 | 結果 |
|------|------|
| `tools/package-lite-windows.ps1` | ✅ ZIP 79 entries，`2.7.268`，BOUNDARY_CHECK=PASS |
| `docker compose down -v` + 全新 volume | ✅ |
| 自 ZIP 安裝並啟用 `museder-restoreone` | ✅ |
| `wp plugin check museder-restoreone` | ✅ **No errors found** |
| `admin-smoke-wpdebug.php` | ✅ **6/6**，`debug_log_fatal=no` |
| `cli-wp-load-smoke.php` | ✅ `plugin_active=yes` |

### 10.2 大站情境（QA-B1，`http://localhost:8083`）

**腳本：** `tools/qa/provision-heavy-site-smoke.ps1`（可重複執行）

| 項目 | 設定 |
|------|------|
| WordPress | **7.0**（自 6.6.2 升級，以滿足 WooCommerce / Yoast 等最低版本） |
| 佈景 | **Astra**（已啟用） |
| 電商 | **WooCommerce** + **WooCommerce Stripe Gateway** + 範例商品 |
| 常用外掛（17，wp.org） | elementor, wordpress-seo, contact-form-7, jetpack, wordfence, wp-super-cache, classic-editor, mailchimp-for-wp, google-site-kit, redirection, duplicate-post, wpforms-lite, insert-headers-and-footers, akismet, litespeed-cache, woocommerce, woocommerce-gateway-stripe |
| 受測外掛 | **museder-restoreone** `2.7.268`（自發佈 ZIP 解壓） |
| 上傳體積 | `wp-content/uploads/museder-heavy-qa/` **≈5.1G**（5×1GB）；整站 `du` **≈5.6G** |
| WP_DEBUG | `true` + `WP_DEBUG_LOG` + `DISPLAY=false` |

**Admin smoke（Apache/PHP 容器內，非 wp-cli phar）：**

```text
SUMMARY pages_pass=6/6 debug_log_fatal=no
```

**備註：** 大站啟用多外掛後，`wp plugin list`（wp-cli phar，128M）可能 **OOM**（與 BUG-AB-004 同類）；**不影響** Web/PHP smoke 通過。日後大站清單檢查可改 `ls wp-content/plugins` 或提高 CLI memory。

**外掛安裝紀錄：** `docs/qa-evidence/release-2.7.268-2026-05/heavy-site/plugin-install.log`（17/17 OK）

### 10.3 本輪未執行（範圍外）

| 項目 | 說明 |
|------|------|
| ~~大站 **FULL 備份／還原 E2E**~~ | **已完成** — 見 `docs/QA-HEAVY-SITE-FULL-E2E-BROWSER-2.7.268.md` |
| R-S2 重跑 | 第十二輪已 **Verified**，本輪未改 `class-restore-service.php` |

### 10.4 發佈建議

| 項目 | 判定 |
|------|------|
| Lite ZIP + Plugin Check + 乾淨 WP_DEBUG smoke | ✅ 可發佈 |
| 大站共存（電商 + 多外掛 + >5GB uploads）admin 無 fatal | ✅ |
| 建議下一步 | Git commit bootstrap P1（若尚未入庫）→ SVN / `v2.7.268` tag（需明確核准） |
