# MUSEDER RestoreOne 2.7.227 審查/修正交付報告（2026-01-13）

目標：依 WordPress.org plugin review 慣例重點（尤其上一輪 P0：Unsafe SQL calls）對 `museder-restoreone-2.7.227.zip` 進行再審查，若未通過則輸出可交付給另一個 AI 實作的修正清單與 patch 範圍。

## 2.7.227 審查結果
此版本 **未通過**（P0：使用 Shell/CLI 執行資料庫匯入/匯出，並在無 CLI 時直接中止自動匯入）。

本輪因此 **不進行** 小站/大站備份還原實測（依既定流程：合規未通過即停止進入實測）。

---

## P0（必修，會卡 WP.org 審核）

### P0-1：Restore DB 匯入改成 **MySQL CLI（exec/shell_exec）**，且無 CLI 直接中止
**為何是 P0**
- WP.org reviewer 通常會拒絕 plugin 直接呼叫 `exec()`/`shell_exec()`/`system()` 等 shell 函式（安全性/可攜性/主機相容性問題）。
- 更嚴重的是：此版把 PHP 端 SQL import 路徑移除，導致 **沒有 MySQL CLI 的主機無法自動還原資料庫**（功能性回歸 + 不符合 plugin 目錄「在一般主機可用」的期待）。
- 命令列帶入 `-pDB_PASSWORD`（即使有 `escapeshellarg`）仍存在「密碼出現在 process list / 工具輸出」的風險，reviewer 通常會直接判定為不安全。

**證據**
- `includes/class-restore.php`：`import_database_with_cli()` 以 `mysql ... -p%s ... < %s` 執行匯入（含 `Backup_Lite_Backup::run_shell_command()`）。
- `includes/class-restore.php`：`import_database_sliced()` / `exec_import_sql_statement()` 直接 `throw`，宣告「Automatic database import requires MySQL CLI」。

**建議修正方向（可交付實作）**
- **移除/停用 MySQL CLI 匯入（不要 exec/mysql）**，回到「純 PHP」可用的匯入路徑。
- 針對 reviewer 先前的 **Unsafe SQL calls**：
  - **最佳解（推薦）**：調整備份 DB 匯出格式，使匯入可用 `$wpdb->insert()` / `$wpdb->update()` / `$wpdb->replace()`（底層會 prepare），避免把整段 SQL dump 當字串直接 `query()`。
    - 例如：備份時輸出「每表的 schema + rows（JSON lines / CSV / serialized rows）」；還原時以白名單表名 + 欄位名，逐列 insert（可 chunk）。
  - **次佳解（可能仍被 reviewer 挑）**：若短期內必須沿用 SQL dump，則需把 dump 限縮為自家可解析格式（例如只允許 `INSERT INTO table (cols) VALUES (...)`），匯入時解析 values 並用 `$wpdb->prepare()` 重建每筆 INSERT（或 `$wpdb->insert()`），而不是直接把整句 dump 丟進 `$wpdb->query()`。
- **保留目前的 statement risk-classifier**（`classify_restore_sql_statement_risk()`）很有幫助，但它必須搭配「不使用 shell」且「不執行未 prepare 的 dump 字串」的執行器。

**Patch 範圍（函式級）**
- `includes/class-restore.php`
  - `private static function import_database_with_cli( $sql_file )`：移除或完全停用
  - `public static function import_database_sliced(...)`：恢復可用（不要 throw）
  - `private static function exec_import_sql_statement(...)`：恢復可用（不要 throw），改成「解析 → `$wpdb->insert/prepare`」的安全路徑
  - `private static function import_database_with_php(...)`：需能完成 DB restore（目前呼叫 `exec_import_sql_statement()` 會因 throw 而失效）
- `includes/helpers.php`
  - `backup_lite_is_shell_available()` / `backup_lite_command_exists()` / `backup_lite_can_use_mysql_cli()`：若決策是全面禁用 shell，這些應降級為「永遠 false」或僅保留但不在 production flow 使用（避免 reviewer 看到即退件）。

---

### P0-2：Backup DB 匯出仍使用 **mysqldump（exec/shell_exec/system）**
**為何是 P0**
- 同 P0-1：WP.org reviewer 常會拒絕 shell command 執行（即使有 fallback 也可能被要求移除）。
- 另外 `mysqldump` 在許多環境不可用/被禁用；plugin 目錄通常期待純 PHP 可運作。

**證據**
- `includes/class-backup.php`：`export_database_with_mysqldump()` 組 command，透過 `run_shell_command()` 執行。
- `includes/helpers.php`：提供 `backup_lite_can_use_mysqldump()` / `backup_lite_command_exists()` 支援偵測與執行。

**建議修正方向**
- WP.org 送審版建議 **強制只走 PHP 匯出**（保留 `export_database_with_php()`，移除/禁用 mysqldump 路徑）。
- 若一定要保留（不建議）：至少要把 shell 能力變成「明確 opt-in（常數/設定）」且預設 off；但仍可能被 reviewer 要求移除。

**Patch 範圍**
- `includes/class-backup.php`
  - `private static function export_database_with_mysqldump(...)`：停用或移除
  - 匯出流程選擇處（`$method = backup_lite_can_use_mysqldump() ? 'mysqldump' : 'php';` 這類邏輯）：改為永遠走 `php`
- `includes/helpers.php`
  - `backup_lite_can_use_mysqldump()` / `backup_lite_command_exists()`：同上，避免在送審版使用

---

## P1（建議修，容易被 reviewer 追問/要求調整）

### P1-1：WPRESS 解壓使用 `tar` + `exec/shell_exec`
**風險**
- 仍屬 shell 執行，可能被直接打回。
- 即使有 `escapeshellarg`，也增加攻擊面與主機相容性問題。

**證據**
- `includes/class-restore.php`：WPRESS extraction 嘗試 `tar -xzf` / `tar -xf`，透過 `exec()` / `shell_exec()`。

**建議修正方向**
- 送審版改成 **純 PHP 解壓**：
  - 若可接受功能調整：只支援 plugin 自家產生的 zip 格式（ZipArchive / PclZip），WPRESS 另提供「不支援」提示。
  - 若必須支援 tar：考慮使用 `PharData`（tar/tar.gz）或內建/隨 plugin 附帶的純 PHP tar 解壓 library（需評估體積與 license）。

**Patch 範圍**
- `includes/class-restore.php`：WPRESS extraction 相關區塊（目前以 `exec/shell_exec` 跑 `tar` 的那段）

---

## 2.7.227 相對於 2.7.226 的正向變更（可保留）
- **移除了「用 `$wpdb->query()` 直接執行 SQL dump」的路徑**（但改成 MySQL CLI，導致新的 P0）。
- 未發現回歸 `wp-load.php` 探測載入或直接 include `wp-includes/cron.php` 的問題（本輪掃描未命中）。
- 上傳原本的 `upload-handler.php`（native handler）回歸訊號本輪掃描未命中。

---

## 回覆 reviewer（英文模板，建議）

### Template A（承認問題 + 承諾改為純 PHP）
Subject: Follow-up: Remove shell/CLI execution and restore WP-compliant DB import/export

Hello Plugin Review Team,

Thank you for the feedback. In 2.7.227 we attempted to address the “Unsafe SQL calls” concern, but we recognize that using shell execution (mysql/mysqldump/tar via exec/shell_exec) is not appropriate for the WordPress.org directory requirements and can also break compatibility on typical hosting environments.

We will remove all shell/CLI execution paths and implement a pure-PHP database export/import flow. The restore path will no longer require MySQL CLI. For database writes we will use WordPress database APIs with prepared statements (e.g., $wpdb->insert/$wpdb->update or prepared queries) and strict allowlists for table/identifier handling.

We appreciate your time and will submit an updated build shortly.

Best regards,

---

## 檔案位置
- 2.7.227 zip：`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/museder-restoreone-2.7.227.zip`
- 解壓審查目錄：`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work-2.7.227/`

