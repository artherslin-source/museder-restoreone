# WordPress Plugin Directory 審查回覆（2026-01-24）修正完成報告

**外掛**：Museder RestoreOne  
**版本**：2.7.241  
**對應審查信**：`WordPress Plugin Directory] Review in Progress: Museder RestoreOne`（2026-01-24）  
**目標**：依審查信列出之問題逐一修正，完成必要驗證，產出提交前報告。

---

## 1) 修正摘要（對照審查信）

### 1.1 AI 產出檔案（AI generated output）

**審查要求**：移除不必要的 AI 產出檔案（信中點名 `docs/wp-compliance-checklist.md`）。

**已完成修正**：

- 更新打包腳本 [`/Users/jerrylin/MusederLab/museder-restoreone/create-package.sh`](../../create-package.sh)  
  - 打包時會在複製 `docs/` 後 **移除** `docs/wp-compliance-checklist.md`（僅排除於發行 zip，不影響 repo 內部文件保存）。

**打包驗證**：

- 已成功產生發行包：`/Users/jerrylin/MusederLab/museder-restoreone/dist/museder-restoreone-2.7.241.zip`
- 檢查 zip 內容：**不包含** `docs/wp-compliance-checklist.md`、`.DS_Store`、`tmp-test.zip`、`tools/`、`create-package.sh`

---

### 1.2 命名/前綴（Generic function/class/define/namespace/option names）

**審查要求**：

- 外掛自訂的 **function、class、define、namespace、option/transient 名稱** 必須有明確且專屬的前綴，避免與其他外掛衝突。
- 審查信指出目前有大量 `backup_lite` 前綴元素（146 個），且 options/transients 必須有前綴。
- 審查信同時點名 `includes/class-backup-jobs.php` 的 `add_option($key, ...)` 行為，要求確認 option key 有前綴。

**已完成修正**：

1. **全域前綴全面改名**（使其更專屬於本外掛）  
   - `backup_lite_*` → `museder_restoreone_*`（函式、hooks、AJAX action、option/transient key 等）  
   - `Backup_Lite_*` → `Museder_Restoreone_*`（類別）  
   - `BACKUP_LITE_*` → `MUSEDER_RESTOREONE_*`（常數）  

2. **Options/Transients 全面改用外掛專屬 key**  
   - 例如：`backup_lite_options` → `museder_restoreone_options`  
   - 例如：`backup_lite_restore_lock` → `museder_restoreone_restore_lock`  
   - 例如：`backup_lite_wp_cron_nudge_ts`（transient）→ `museder_restoreone_wp_cron_nudge_ts`

3. **一次性資料遷移（避免既有用戶資料遺失）**  
   - 已在主檔 [`/Users/jerrylin/MusederLab/museder-restoreone/museder-restoreone.php`](../../museder-restoreone.php) 新增  
     `museder_restoreone_migrate_legacy_keys_once()`  
   - 啟動流程 `museder_restoreone_bootstrap()` 最前段會呼叫該遷移函式，將常見舊版 `backup_lite_*` option/transient key **搬移至** `museder_restoreone_*`，並清除部分舊 cron hook，避免孤兒事件。

4. **審查信點名的 add_option($key, ...)**  
   - `includes/class-backup-jobs.php` 內部鎖定用 option key 仍為 **外掛專屬**：`museder_restoreone_job_lock_...`  
   - 審查工具可能無法追蹤 `$key` 的來源而誤報；目前 key 已具專屬前綴。

---

### 1.3 直接存取 PHP 檔案（Allowing direct file access）

**審查要求**：可被直接存取且含可執行邏輯的 PHP 檔案，開頭需加入：

```php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
```

**已完成修正**：

- 更新 [`/Users/jerrylin/MusederLab/museder-restoreone/download-handler.php`](../../download-handler.php)  
  - 直接存取（未定義 `ABSPATH`）時 **立即 exit**  
  - 移除「直接存取時仍執行 redirect/處理 GET 參數」的行為，符合審查要求

**驗證（Docker 測試）**：

- 直接請求 `http://localhost:8080/wp-content/plugins/museder-restoreone/download-handler.php` 回應 **200**（空內容），確認沒有執行 redirect/業務邏輯。

---

## 2) 驗證結果（依審查信檢查清單）

### 2.1 Plugin Check

在 Docker 測試站台中安裝並啟用 `plugin-check` 後執行：

- `wp plugin check museder-restoreone`

結果：

- **ERROR：0**（已排除 `.DS_Store`、`tmp-test.zip` 等不允許檔案，並修正 docker 同步排除規則，使檢查針對「實際提交內容」）  
- **WARNING：2**（同一位置）  
  - `includes/class-restore.php` 對 `$wpdb->replace()` 的提示（屬還原匯入流程的必要寫入）。  
  - 這是預期行為，屬於 restore/import 的核心功能。若審查團隊要求「必須 0 warning」，再採取進一步策略（例如改為 batch + cache 或追加更明確的例外註解/說明給審查團隊）。

### 2.2 WP_DEBUG 測試

已在 Docker 站台設定：

- `WP_DEBUG = true`
- `WP_DEBUG_LOG = true`
- `WP_DEBUG_DISPLAY = false`

並確認外掛可啟用、頁面可載入、核心路徑不出現 PHP fatal。

### 2.3 打包檢查（提交 WordPress.org 用 zip）

已產生：`dist/museder-restoreone-2.7.241.zip`  
確認 zip 內 **不包含**：

- AI 產出檔：`docs/wp-compliance-checklist.md`
- `.DS_Store` / `tmp-test.zip`
- `tools/` / `create-package.sh` / `docker-compose.yml` / `.gitignore` 等非外掛功能檔案

---

## 3) 回覆審查信建議用語（可直接貼回信）

- 已移除 AI 產出檔案（`docs/wp-compliance-checklist.md`）於提交 zip 中。  
- 已將所有自訂 function/class/define/option/transient key 由通用的 `backup_lite` 前綴改為更專屬的 `museder_restoreone` 前綴，並加入一次性 option/transient 遷移，避免既有用戶資料遺失。  
- `download-handler.php` 已依規範加入 `if ( ! defined( 'ABSPATH' ) ) exit;`，確保直接存取不執行任何可執行邏輯。  
- 已在乾淨安裝環境並開啟 `WP_DEBUG` 進行測試，並使用 Plugin Check 進行檢驗。

---

## 4) 附註：測試環境/腳本調整（僅開發用）

為使 Docker 測試環境中的外掛目錄更貼近 WordPress.org 提交內容，已調整：

- `/Users/jerrylin/MusederLab/museder-restoreone/tools/docker/setup.sh`  
  - 同步外掛程式碼到容器時排除 dev-only 檔案/資料夾（例如 `tools/`、`.gitignore`、`create-package.sh` 等）  

此調整不影響 WordPress.org 提交 zip；提交仍以 `dist/museder-restoreone-2.7.241.zip` 為準。

