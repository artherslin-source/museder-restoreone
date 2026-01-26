# MUSEDER RestoreOne 2.7.229 審查 + 功能測試報告（2026-01-13）

## 版本與檔案位置
- **外掛 zip**：`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/museder-restoreone-2.7.229.zip`
- **解壓審查目錄**：`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work-2.7.229/museder-restoreone/`
- **Docker 測試環境**：`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/_wp-test-2.7.229/`（WordPress 對外 port `8092`）

---

## A) WP.org 合規審查結論（是否可進入測試）
### 結論
**通過（允許進入功能測試）**。本輪重點風險點（shell/CLI、Unsafe SQL、直 include 核心檔案、nonce/cap）整體可接受；且 2.7.228 的 P0（`database.ndjson` 內容為 SQL、導致 DB 還原無效）在 2.7.229 已修正為真正 NDJSON 匯出/匯入並加上格式防呆。

### 審查重點摘錄（高風險項）
- **Shell/CLI**：送審版已硬關閉（`backup_lite_is_shell_available()` / `backup_lite_can_use_mysqldump()` / `backup_lite_can_use_mysql_cli()` 均回 `false`），未掃到 `exec()`/`shell_exec()` 等呼叫。
- **DB 匯出/匯入格式一致性**：備份端 `export_database_with_php()` 以 **JSON Lines** 逐行輸出 `meta/schema/row`；還原端 `import_database_from_ndjson()` 若第一行非 `{` 或 schema=0 會直接 fail，避免「假成功」。
- **Nonce/權限**：AJAX handlers 普遍有 `check_ajax_referer( Backup_Lite_UI::NONCE, 'nonce' )` + `current_user_can('manage_options')`（抽樣確認）。
- **Direct core includes（可能被 reviewer 追問但常見可接受）**：
  - `require_once ABSPATH . 'wp-admin/includes/file.php'`（多處；常見用途為 WP Filesystem / media upload）
  - `require_once ABSPATH . 'wp-includes/compat.php'`（僅在 `wp_generate_uuid4()` 不存在時補入）
  - `require_once $upgrade`（`wp-admin/includes/upgrade.php`，用於 `dbDelta()`）

> 註：WP.org guideline 通常不喜歡「任意 core 檔案 include」，但上述屬於較常見、具體且目的明確的 include，且在 admin/restore/backup 場景可被合理化。若要更保守，可再改成透過 WP API 間接使用或加 guard（例如 `is_admin()`/capability）。

---

## B) 功能測試（小站 500MB~1GB）
### 測試堆疊
- WordPress 6.9 / PHP 8.2（docker）
- Elementor 3.34.1、Elementor Pro 3.34.0、PowerPack Pro 2.12.15、WooCommerce 10.4.3、Contact Form 7 6.1.4
- 佈景：museder-blank-theme 1.0.0
- RestoreOne：2.7.229

### 測試資料
- uploads：`small-650mb.bin`（約 650MB，不可壓縮）
- DB markers：
  - `blogname=BEFORE_BACKUP_27229`
  - `museder_test_marker=before_backup`

### 備份結果（通過）
- 產出 zip：`localhost-20260113091131-WdM6TS.zip`（約 723.54MB）
- `database.ndjson` 抽樣前幾行為 JSONL（`meta/schema/row`）

### 還原驗證（通過）
- 還原前先變更：
  - `blogname=CHANGED_AFTER_BACKUP_27229`
  - `museder_test_marker=after_backup_changed`
  - 刪除 `small-650mb.bin`
- 還原後：
  - `blogname` 回到 `BEFORE_BACKUP_27229`
  - `museder_test_marker` 回到 `before_backup`
  - `small-650mb.bin` 存在且 sha1 一致（hash_match=YES）

---

## C) 功能測試（大站 >2GB）
### 重要觀察：單一檔案 >2GB 會被外掛跳過（設計上的 safety cap）
- `includes/class-backup.php` 內存在單檔 2GB 上限（`$max_file_size = 2147483648`），manifest 會直接略過 >2GB 檔案。
- 我實測用單檔 `large-2200mb.bin`（約 2.3GB）時：
  - **備份 zip 內不包含該檔案**
  - restore 後檔案也不會回來
- 建議：在 UI/文件中明確告知此限制，或在備份結果內列出 skipped 與原因（避免使用者誤以為完整備份）。

### 大站測試方法（總量 >2GB、每檔 <2GB）
- uploads：建立 `wp-content/uploads/test-data/large-set/part-{1..5}-400mb.bin`（每檔約 400MB；總量約 2.0GB）  
  搭配既有 `small-650mb.bin`（650MB）後，站點檔案總量 **>2GB** 且符合單檔上限。
- DB markers：
  - `blogname=BEFORE_BACKUP_LARGESET_27229`
  - `museder_test_marker_large=before_backup_largeset`

### 備份結果（通過）
- 產出 zip：`localhost-20260113092952-pzvz17.zip`（約 **2.7GB**）
- zip 內確認包含：
  - `wp-content/uploads/test-data/large-set/part-1-400mb.bin`
  - `wp-content/uploads/test-data/large-set/part-5-400mb.bin`

### 還原驗證（通過）
- 還原前先變更：
  - `blogname=CHANGED_AFTER_BACKUP_LARGESET_27229`
  - `museder_test_marker_large=after_backup_largeset_changed`
  - 刪除整個 `wp-content/uploads/test-data/large-set/`
- 還原後：
  - `blogname` 回到 `BEFORE_BACKUP_LARGESET_27229`
  - `museder_test_marker_large` 回到 `before_backup_largeset`
  - `large-set` 目錄內 5 檔皆回來；抽樣 `part-1` 與 `part-5` sha1 均與備份前一致（part1_hash_match=YES、part5_hash_match=YES）

---

## 結論
- **合規審查**：通過（可進入下一輪 reviewer 溝通/送審）
- **小站（<1GB）備份/還原**：通過（DB+files 均可回復，且有 hash 證據）
- **大站（>2GB）備份/還原**：通過（以多檔累積方式驗證 DB+files）
- **已知限制**：單檔 >2GB 會被跳過（建議在 UI/報告內明確提示並列出 skip 清單）
