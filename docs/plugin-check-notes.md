# WordPress Plugin Check Compliance Notes

本文檔說明 Museder RestoreOne 外掛在 WordPress Plugin Check 合規性方面的處理策略。

## 一、檔案 I/O (AlternativeFunctions)

### 為什麼必須使用 direct file I/O

備份/還原外掛需要處理大型檔案（可能超過數 GB），必須使用串流讀寫以：
- 避免記憶體溢出
- 確保效能與穩定性
- 支援大檔案上傳/下載

`WP_Filesystem` API 在這種情境下：
- 無法有效處理串流操作
- 效能不足
- 在某些主機環境下不穩定

### 安全措施

所有檔案路徑都經過：
- `backup_lite_get_backup_path()` 或類似 helper 函式驗證
- `sanitize_file_name()` 處理
- 白名單目錄檢查
- 不接受直接的使用者輸入

### 處理方式

所有 `fopen/fread/fwrite/fclose` 操作都用 `phpcs:disable/enable` 區塊包起來，並附上說明註解。

## 二、Nonce 驗證與輸入 Sanitization

### Nonce 驗證策略

1. **AJAX Handler 入口點**：所有 `wp_ajax_*` 處理函式都在開頭加上 `check_ajax_referer( Backup_Lite_UI::NONCE, 'nonce' )`
2. **上游驗證**：如果函式只被已驗證 nonce 的內部方法呼叫（例如 `verify_ajax_request()`），則使用 `phpcs:disable WordPress.Security.NonceVerification.Missing` 並說明原因

### 輸入 Sanitization

1. **字串輸入**：使用 `sanitize_text_field( wp_unslash( $_POST['xxx'] ) )`
2. **數字輸入**：使用 `absint( wp_unslash( $_POST['id'] ) )`
3. **檔名輸入**：使用 `sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) )`
4. **陣列輸入**：使用 `array_map( 'sanitize_text_field', wp_unslash( $_POST['settings'] ) )`
5. **$_FILES['tmp_name']**：使用 `is_uploaded_file()` 驗證，並用 `phpcs:ignore` 說明這是系統提供的路徑，無需進一步 sanitization

### 處理的檔案

- `includes/class-restore-handler.php`
- `includes/class-log-handler.php`
- `includes/class-chunk-handler.php`
- `includes/class-schedule-handler.php`
- `includes/class-settings.php`

## 三、Direct Database Query

### 為什麼必須使用 direct DB query

備份/還原流程需要：
- 直接操作資料表結構（`SHOW TABLES`, `SHOW COLUMNS`, `ALTER TABLE`）
- 執行備份產生的 SQL 腳本（多語句腳本無法使用 `prepare()`）
- 繞過物件快取（結構操作不適合快取）

### 安全措施

所有 table 名稱都來自：
- `$wpdb->prefix` 或 `$wpdb->xxx`
- 內部白名單
- 從 `SHOW TABLES` 結果中取得並經過 `preg_replace` 過濾
- **不接受使用者輸入**

### 處理方式

所有 direct DB 查詢都用 `phpcs:disable WordPress.DB.DirectDatabaseQuery.*` 區塊包起來，並附上說明註解。

### 處理的檔案

- `includes/class-backup.php`
- `includes/class-restore.php`
- `includes/class-restore-service.php`

## 四、Template 變數命名 (PrefixAllGlobals)

### 為什麼使用 phpcs:disable

這些 template 檔案（`templates/page-*.php`）是內部後台專用畫面：
- 所有變數都由對應的 controller 傳入
- 不注入 PHP 全域命名空間
- 不提供為外部 API 使用
- 使用簡短變數名稱是為了 template 可讀性

### 處理方式

在每個 template 檔案的最開頭（`<?php` 後面）加上：
```php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// 說明：本檔為 Museder RestoreOne 的內部後台 template，變數皆由外掛 controller 傳入，不注入 PHP 全域命名空間，也不作為可重用 API。
```

在檔案最後加上：
```php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
```

### 處理的檔案

- `templates/page-backups.php`
- `templates/page-schedules.php`
- `templates/page-logs.php`
- `templates/page-settings.php`

## 總結

所有這些「例外」都是基於備份/還原外掛的特殊需求，並且都有適當的安全措施。所有相關程式碼都已經：
1. 加上適當的 `phpcs:disable/enable` 註解
2. 附上清楚的中文說明
3. 確保安全措施到位（nonce 驗證、輸入 sanitization、路徑驗證等）



