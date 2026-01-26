# MUSEDER RestoreOne 2.7.228 審查/測試交付報告（2026-01-13）

## 版本與檔案位置
- **zip**：`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/museder-restoreone-2.7.228.zip`
- **解壓審查目錄**：`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work-2.7.228/`
- **Docker 測試環境**：`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/_wp-test-2.7.228/`（port `8091`）

---

## 審查結果（合規 + 實測）
### 合規掃描（靜態）
**整體方向大致符合**上一輪要求：
- **已禁用 shell/CLI**：`backup_lite_is_shell_available()`、`backup_lite_can_use_mysql_cli()`、`backup_lite_can_use_mysqldump()` 皆回傳 `false`（送審版硬化）。
- **`.wpress` 在送審版禁用**：Restore 端顯示不支援 `.wpress`（避免 tar/exec）。
- 未掃到 `wp-load.php` 探測載入與 `wp-includes/cron.php` 直接 include（本輪 grep 未命中）。

### 實測（小站 500MB~1GB）結論
此版本 **未通過**（P0：資料庫自動還原實際未生效，屬功能性致命問題），因此本輪 **不進行** 大站（>2GB）測試，改先交付修正清單。

---

## P0（必修）：`database.ndjson` 內容與 restore importer 不匹配，導致 DB 還原等於沒做

### 現象
- 備份流程產出的檔名為 **`database.ndjson`**，但內容是 **SQL 文本**（不是 NDJSON / JSON Lines）。
- 還原流程依副檔名 `.ndjson` 走 **NDJSON importer**（逐行 `json_decode()`），因此 SQL 行全部 decode 失敗被略過，DB 實際沒有被覆蓋還原。

### 證據（可重現）
1) **備份檔案已成功產生**
- 小站備份檔：`/var/www/html/wp-content/uploads/museder-restoreone/backups/localhost-20260113070916-o5Taa1.zip`
- 大小約：`723.73 MB`

2) **從備份 zip 讀出 `database.ndjson` 前幾行是 SQL**
- 在 `wpcli` 容器內執行：`unzip -p <zip> database.ndjson | head -n 20`
- 看到內容為：
  - `SET sql_mode = ...`
  - `-- Table structure for table ...`
  - `DROP TABLE IF EXISTS ...`
  - `CREATE TABLE ...`
  （這是 SQL dump，不是 NDJSON）

3) **DB 還原未覆蓋（驗證用 blogname）**
- 還原前先把 `blogname` 改成：`CHANGED TITLE`
- 還原完成後：
  - `wp option get blogname` 仍為 `CHANGED TITLE`
  - 直接查 DB：`SELECT option_value FROM wp_options WHERE option_name='blogname'` 仍為 `CHANGED TITLE`
=> 表示「資料庫內容沒有被還原回備份時狀態」。

### 根因（程式行為）
- `includes/class-restore.php`：
  - `import_database()` 以副檔名判斷：`ndjson` → `import_database_from_ndjson()`
  - `import_database_from_ndjson()` 逐行 `json_decode()`，只有 `{type:"meta"/"schema"/"row"...}` 這種 JSONL 才會處理
- 但目前 `database.ndjson` 實際是 SQL dump 文本，所以 importer 不會處理任何 row/schema。

### 建議修正（兩條路擇一；建議 A）
#### A) **真正輸出 NDJSON（推薦，保留自動 DB 還原）**
- 讓 `database.ndjson` 變成真正的 JSON Lines 格式：
  - `{"type":"meta","table_prefix":"wp_","generated_at":"..."}`
  - `{"type":"schema","table":"wp_options","create":"CREATE TABLE ..."}`
  - `{"type":"row","table":"wp_options","row":{...}}`
- 還原端現有 `import_database_from_ndjson()` 才會真正生效（並可用 `$wpdb->replace()`）。

#### B) **如果仍要輸出 SQL，就不要叫 .ndjson（會直接壞）**
- 把檔名改回 `database.sql`，並在 restore 流程清楚標註「SQL 備份需手動匯入 DB」
- 代價：WordPress.org 送審版將缺少「自動 DB 還原」能力（通常不可接受），且你們的測試目標要求自動還原。

### Patch 範圍（函式級）
- `includes/class-backup.php`
  - 產生 `database.ndjson` 的匯出路徑（目前顯然仍是 SQL 內容）：需要改為真正 NDJSON writer（逐表 schema + rows streaming）。
- `includes/class-restore.php`
  - `import_database()` / `import_database_from_ndjson()`：可加一層偵測（例如：若檔案前幾行不是 `{` 開頭 JSONL，立即報錯並中止，避免「假完成」）。
- `includes/class-restore-service.php`
  - `stage_import_database()`：若 `Backup_Lite_Restore::import_database()` 回傳成功但未實際匯入（例如 schema/row count=0），應視為失敗並提示可診斷訊息（避免誤導）。

---

## 小站測試紀錄（摘要）
### 測試堆疊
- WordPress 6.9 / PHP 8.2
- Elementor 3.34.1、Elementor Pro 3.34.0、PowerPack Pro 2.12.15、WooCommerce 10.4.3、Contact Form 7 6.1.4、RestoreOne 2.7.228
- 佈景：museder-blank-theme 1.0.0

### 小站資料量
- uploads 加入隨機檔：`small-650mb.bin`（約 650MB，不可壓縮）

### 備份結果
- 產出 `localhost-20260113070916-o5Taa1.zip`（約 723.73MB）
- log 顯示備份成功（duration 約 80s）

### 還原結果
- **檔案層**：`small-650mb.bin` 可被還原回來（檔案覆蓋 OK）
- **資料庫層**：blogname 未被覆蓋回備份狀態（DB 還原失敗）

---

## 後續建議（給另一個 AI 的實作指引）
- 先完成 **P0：真正 NDJSON 匯出** 或正確處理格式（推薦 A），再交回我這邊重跑：
  - 小站（500MB~1GB）備份→還原→驗證（DB + files）
  - 大站（>2GB）備份→還原→驗證

