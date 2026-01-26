# Museder RestoreOne 版本開發重點整理
## 2.6.126 之後各版本開發重點

---

## 版本 2.8.00 (最新版本)

### 開發重點
- **Dashboard 顯示問題修正**
  - 修正「Show all logs」按鈕無效問題
    * 修正 JavaScript 事件處理，加入 `preventDefault()` 和 `stopPropagation()`
    * 增強 CSS 樣式優先級，確保折疊功能正常運作
  - 修正 Activity 圓餅圖顏色與比例顯示
    * 使用固定顏色：成功 (#2563eb)、失敗 (#ef4444)
    * 修正資料來源，使用 `recent_backup_stats` 確保資料正確
    * 新增工具提示顯示百分比
  - 修正 Last Backup 時間顯示錯誤
    * 修正 `get_backups_list()` 排序邏輯，改為依檔案修改時間排序
    * 確保 `get_last_successful_local_backup()` 回傳最新備份

- **備份流程狀態顯示修正**
  - 修正備份流程中 S3 上傳狀態顯示
    * `format_job_payload()` 現在包含 `options` 陣列
    * 增強前端 `dest_s3` 檢測邏輯，支援多種選項格式
    * 確保備份設定摘要正確顯示 S3 上傳狀態

- **新增功能**
  * 新增 Dashboard 狀態摘要服務 (`class-backup-lite-status-service.php`)
  * 新增雲端控制器 (`class-backup-lite-cloud-controller.php`)
  * 新增 `admin-dashboard.js` 處理 Dashboard 互動功能

- **程式碼品質**
  * 所有修改符合 WordPress Coding Standards
  * 所有字串已國際化處理
  * 所有輸出已正確 escape

---

## 版本 2.7.40

### 開發重點
- **Bug Fix**: 修正下載處理器空白頁面問題
  * 恢復實際下載功能，包含正確的 WordPress bootstrap、HMAC token 驗證和檔案串流
- **Security**: 實作安全 token 驗證
  * 使用 `hash_equals()` 防止時序攻擊
-- **Improvement**: 增強下載處理器
  * 當直接存取時，不直接 include WordPress 核心 bootstrap 檔案（改走安全 redirect stub）
  * 確保所有外掛功能可用
- **Code Quality**: 改進檔案路徑解析
  * 使用 `backup_lite_get_backup_path()` helper 函數進行一致且安全的路徑處理

---

## 版本 2.7.39

### 開發重點
- **Bug Fix**: 修正 `admin.js` 中的關鍵 JavaScript 語法錯誤
  * 修正還原輪詢 timeout handler 中缺少的閉合括號
  * 此錯誤導致整個管理介面失效，影響「Estimated Backup Size」、「Backup Site」按鈕和「Restore Center」按鈕
- **Code Quality**: 修正 `showCompletionOverlay` 呼叫中的縮排和格式

---

## 版本 2.7.38

### 開發重點
- **Bug Fix**: 修正備份下載顯示空白頁面 (HTTP 500)
  * 將所有下載請求遷移到 `admin-post.php` 路由以符合 WordPress Plugin Check
  * 移除直接使用 `download-handler.php`
- **Bug Fix**: 修正還原完成時同時顯示失敗和成功模態視窗的問題
  * 改進錯誤處理，區分網路錯誤和實際還原失敗
- **Bug Fix**: 修正 Restore History 時間戳記與 Available Backups Created 時間不一致
  * 兩者現在都使用 `backup_lite_format_local_time()` 進行一致的本地時區顯示
- **Improvement**: 增強還原任務輪詢邏輯
  * 正確處理網路/技術錯誤，不顯示錯誤的失敗模態視窗
  * 只有實際還原失敗（status: 'failed' 或 history result: 'failed'）才會觸發失敗模態視窗
- **Improvement**: 新增 `restoreMonitor` 狀態管理，防止重複的成功/失敗模態視窗出現
- **Improvement**: 將 `download-handler.php` 改為已棄用的重定向存根，保持向後相容性同時符合 Plugin Check

---

## 版本 2.7.37

### 開發重點
- **Bug Fix**: 統一 Restore History 時間戳記儲存
  * 所有歷史記錄現在使用 UTC 時間戳記 (`time()`) 一致儲存
  * 所有歷史條目現在儲存 `timestamp_utc` 為 UTC Unix 時間戳記，不包含任何時區偏移
- **Improvement**: 增強 `history_for_js()` 以正確處理舊資料格式
  * 包括支援舊的 'date' 欄位條目，解析為 UTC
- **Improvement**: 為向後相容性新增 'date' 欄位到歷史條目
  * 使用 `gmdate()` 格式化的 UTC 日期時間字串
- **Code Quality**: 從歷史時間戳記處理中移除所有手動時區偏移計算
  * 所有時間戳記現在被視為 UTC，僅在顯示時使用 `backup_lite_format_local_time()` 轉換為本地時區
- **Code Quality**: 更新所有歷史條目寫入（成功、失敗、取消）以一致地使用 `time()` 更新 `timestamp_utc`

---

## 版本 2.7.36

### 開發重點
- **UI Fix**: 修正 Restore History 日期/時間顯示為原始 UTC 時間戳記而非格式化的本地時區字串
  * 現在以 Y-m-d H:i 格式顯示，與 Backups 和 Logs 頁面一致
- **Bug Fix**: 修正還原完成時同時顯示失敗和成功模態視窗的問題
  * 新增 `restoreMonitor` 狀態管理，確保只顯示一個最終結果模態視窗
- **Improvement**: 增強 `history_for_js()` 以正確將 UTC 時間戳記轉換為 WordPress 本地時區
  * 使用 `backup_lite_format_local_time()`
- **Improvement**: 新增 `backup_lite_parse_legacy_timestamp()` helper 函數以處理具有不同時間戳記格式的舊歷史條目
- **Improvement**: 改進還原任務輪詢邏輯，透過檢查 `hasFinalResult` 標誌防止重複模態視窗
- **Code Quality**: 增強模板輸出安全性，對時間戳記顯示進行適當的 `isset()` 檢查和後備邏輯

---

## 版本 2.7.35

### 開發重點
- **UI Fix**: 修正 Restore History 日期/時間顯示
  * 顯示人類可讀的本地時區格式 (Y-m-d H:i) 而非原始 UTC 時間戳記
  * 現在與 Backups 和 Logs 頁面使用的格式一致
- **Improvement**: 增強 `history_for_js()` 以正確將 UTC 時間戳記轉換為 WordPress 本地時區
  * 使用 `backup_lite_format_local_time()`
- **Improvement**: 為具有不同時間戳記格式的舊歷史條目新增向後相容性
  * 支援字串時間戳記、數字時間戳記、`timestamp_utc` 欄位
- **Code Quality**: 改進模板輸出安全性，對所有歷史列欄位進行 `isset()` 檢查

---

## 版本 2.7.34

### 開發重點
- **Bug Fix**: 修正備份完成後下載處理器顯示空白頁面
  * 改變 WordPress 載入邏輯，嘗試載入 WordPress 而非立即退出
  * 確保 WordPress 函數可用
- **Bug Fix**: 改進 `download-handler.php` 中的檔案路徑解析
  * 使用 `backup_lite_get_backup_path()` 進行一致的路徑處理
- **Improvement**: 增強下載錯誤日誌記錄
  * 包含詳細資訊（檔案、archive_path、存在、可讀）以便於除錯
- **Improvement**: 將檔案串流從 `readfile()` 改為 `fopen/fread/fclose` 以獲得更好的錯誤處理和相容性
- **Code Quality**: 為檔案開啟失敗新增適當的錯誤處理，包含詳細日誌記錄

---

## 版本 2.7.33

### 開發重點
- **Bug Fix**: 修正「Backup file not found or unreadable」錯誤
  * 集中化檔案路徑解析邏輯
  * 改進 `backup_lite_get_backup_path()`，包含 `realpath` 保護
  * 新增 `backup_lite_is_absolute_path()` helper 函數
- **Bug Fix**: 修正 Restore History 時間戳記與 Backups/Logs 時區不一致
  * 現在儲存 UTC 時間戳記，使用 `backup_lite_format_local_time()` 顯示以進行一致的時區處理
- **Bug Fix**: 防止還原失敗時出現重複失敗模態視窗
  * 新增 `backupLiteRestoreFailureShown` 標誌，確保只顯示一個錯誤模態視窗
- **Improvement**: 還原流程現在只在狀態中儲存檔名，需要時使用 `backup_lite_get_backup_path()` 解析完整路徑以獲得更好的可靠性
- **Improvement**: 增強還原錯誤日誌記錄，包含詳細的檔案路徑資訊以便於除錯
- **Improvement**: 修正下載處理器使用 `readfile()` 進行串流，改進前端使用 `window.location.href` 而非開啟新分頁

---

## 版本 2.7.32

### 開發重點
- **UI Fix**: 修正 Estimated Backup Size 進度條在掃描期間不更新
  * 改進進度計算邏輯，使用基於時間和檔案數量的啟發式方法，在掃描期間顯示準確的進度
- **UI Fix**: 修正「Last scanned」時間戳記未以 WordPress 本地時區顯示
  * 從 `date_i18n()` 改為 `backup_lite_format_local_time()` 以確保正確的時區轉換
- **Code Quality**: 增強 `ajax_get_progress()` 以使用多種方法計算進度百分比
  * 使用經過時間、檔案數量、估計總檔案數
  * 提供更好的使用者回饋
- **Code Quality**: 確保進度條在 JavaScript 輪詢處理器中掃描完成時顯示 100%

---

## 版本 2.7.31

### 開發重點
- **Bug Fix**: 修正 `includes/class-dashboard.php` 中的致命錯誤「Call to undefined function esc_html_n()」
  * 將不存在的 `esc_html_n()` 替換為適當的 `_n() + sprintf() + esc_html()` 模式
  * 使用 `number_format_i18n()` 進行國際化和 HTML 轉義
- **Bug Fix**: 修正 Estimated Backup Size Re-scan 功能中的 JavaScript 錯誤「toLocaleString is not a function」
  * 在呼叫 `toLocaleString()` 之前，為所有數值新增全面的類型檢查和驗證
- **Code Quality**: 增強 `class-estimate-size.php` 中的 `ajax_get_progress()` 和 `ajax_get_result()`
  * 確保所有數值欄位正確轉換為 (int) 類型
- **Code Quality**: 改進 `admin.js` 中的 JavaScript 錯誤處理
  * 在呼叫 `toLocaleString()` 之前使用 `typeof` 和 `Number.isFinite()` 進行適當的類型檢查

---

## 版本 2.7.30

### 開發重點
- **Bug Fix**: 修正 `includes/class-dashboard.php` 中的致命錯誤「Call to undefined function esc_html_n()」
  * 將不存在的 `esc_html_n()` 替換為適當的 `_n() + esc_html()` 模式進行國際化和 HTML 轉義
- **Code Quality**: 改進 `get_countdown_string()` 方法
  * 使用 WordPress `_n()` 函數正確處理單數/複數形式
  * 適當的 HTML 轉義

---

## 版本 2.7.29

### 開發重點
- **Code Quality**: 基於 plugin-check-test-29 的最終一輪 WordPress Plugin Check 合規性改進
- **Security**: 在所有 AJAX 處理器中增強 nonce 驗證
  * 在 `class-restore-handler.php`、`class-log-handler.php`、`class-settings.php` 中明確呼叫 `check_ajax_referer()`
- **Security**: 改進輸入清理
  * 對 `$_POST['searchReplace']`、`$_POST['schedule']`、`$_POST['settings']` 使用適當的遞迴 `array_map('sanitize_text_field', ...)` 處理
- **Security**: 增強 `$_FILES` 驗證
  * 使用 `isset()` 和 `is_uploaded_file()` 檢查，包含適當的 phpcs 註解說明伺服器端 tmp 路徑
- **Code Quality**: 標準化所有檔案操作（fopen/fread/fwrite/fclose）
  * 在 `class-restore.php` 和 `class-backup-lite-s3-service.php` 中使用統一的 phpcs:disable/enable 區塊
  * 使用正確的 sniff 名稱和一致的中文說明
- **Code Quality**: 所有直接資料庫查詢已有全面的 phpcs 註解，包含標準化的中文說明
- **Code Quality**: 所有模板檔案已有檔案層級的 phpcs:disable/enable 註解，用於 NamingConventions 警告
- **Documentation**: 新增 `docs/plugin-check-notes.md`，說明所有合規性例外的理由

---

## 版本 2.7.28

### 開發重點
- **Code Quality**: 基於 plugin-check-test-28 的最終一輪 WordPress Plugin Check 合規性改進
- **Security**: 在所有 AJAX 處理器中增強 nonce 驗證
  * 在 `class-restore-handler.php`、`class-chunk-handler.php`、`class-schedule-handler.php` 中使用 `check_ajax_referer()`
- **Security**: 改進輸入清理
  * 對 `$_POST['searchReplace']`、`$_POST['schedule']`、`$_POST['settings']` 使用適當的 `array_map('sanitize_text_field', ...)`
- **Security**: 增強 `$_FILES` 驗證
  * 使用 `isset()` 和 `is_uploaded_file()` 檢查，包含適當的 phpcs 註解說明伺服器端 tmp 路徑
- **Code Quality**: 標準化所有檔案操作（fopen/fread/fwrite/fclose/readfile/chmod）
  * 使用統一的 phpcs:disable/enable 區塊，使用正確的 sniff 名稱和一致的中文說明
- **Code Quality**: 為直接資料庫查詢新增全面的 phpcs 註解，包含標準化的中文說明
- **Code Quality**: 為所有模板檔案新增模板上下文註解（phpcs:disable/enable）
  * 處理 `page-restore.php`、`page-backups.php`、`page-schedules.php`、`page-logs.php`、`page-settings.php` 中的 NamingConventions 警告
- **Documentation**: 將 readme.txt changelog 大小從 45173 減少到 29565 字元
  * 僅保留最近 10 個版本，其餘參考 `docs/changelog-archive.md`

---

## 版本 2.7.27

### 開發重點
- **Code Quality**: 基於 plugin-check-test-28 的最終一輪 WordPress Plugin Check 合規性改進
- **Security**: 在所有 AJAX 處理器中增強 nonce 驗證
- **Security**: 改進輸入清理和 `$_FILES` 驗證
- **Code Quality**: 標準化所有檔案操作，使用統一的 phpcs:disable/enable 區塊和一致的中文說明
- **Code Quality**: 為直接資料庫查詢新增全面的 phpcs 註解
- **Code Quality**: 為所有模板檔案新增模板上下文註解

---

## 版本 2.7.26

### 開發重點
- **Code Quality**: 最終一輪 WordPress Plugin Check 合規性改進
- **Security**: 增強所有 AJAX 處理器的 nonce 驗證註解和輸入清理
- **Security**: 改進 `$_FILES` 處理，使用適當的 `is_uploaded_file()` 驗證和 phpcs 註解
- **Code Quality**: 標準化所有檔案操作（fopen/fread/fwrite/fclose/unlink/readfile/chmod），使用適當的 phpcs:disable/enable 區塊
- **Code Quality**: 為直接資料庫查詢新增全面的 phpcs 註解，包含清楚的說明
- **Code Quality**: 為所有模板檔案新增模板上下文註解，處理 NamingConventions 警告

---

## 版本 2.7.25

### 開發重點
- **Code Quality**: 系統性地將所有 `unlink()` 呼叫替換為 `wp_delete_file()` 模式（包含非標準環境的後備）
- **Code Quality**: 為所有 `rename()` 呼叫新增適當的 phpcs:disable/enable 註解
  * 清楚說明串流備份/還原效能需求
- **Code Quality**: 增強核心備份/還原流程中 fopen/fclose/fread/fwrite/readfile 操作的 phpcs 註解
- **Code Quality**: 標準化所有 `set_time_limit()` 和 `ini_set()` 呼叫
  * 使用 `function_exists()` 檢查和適當的 phpcs:disable/enable 註解
- **Code Quality**: 改進程式碼合規性，符合 WordPress Plugin Check 要求，同時維持所有備份/還原功能

---

## 版本 2.7.24

### 開發重點
- **Security**: 移除不必要的 WordPress 核心 polyfills
  * 移除 `wp_unslash`、`sanitize_text_field`、`sanitize_key`、`absint`、`size_format`
  * 現在需要 WordPress 5.8+，這些函數已原生包含
- **Security**: 在所有 AJAX 處理器中增強 Nonce 驗證註解
  * 使用適當的 phpcs:disable/enable 區塊
- **Security**: 改進區塊處理器和設定處理器中 JSON/陣列輸入的輸入清理
- **Code Quality**: 盡可能將所有 `unlink()` 呼叫替換為 `wp_delete_file()`，包含適當的後備處理
- **Code Quality**: 增強 S3 服務中的 cURL 函數註解
  * 使用全面的 phpcs:disable/enable 區塊，說明為什麼 cURL 對大檔案串流是必要的
- **Code Quality**: 改進 `restore.php` 中的直接資料庫查詢註解
  * 清楚說明 SQL 來源驗證
- **Code Quality**: 為所有模板檔案新增模板上下文註解，適當處理 NamingConventions 警告

---

## 版本 2.7.23

### 開發重點
- **Security**: 在所有 AJAX 和 admin_post 處理器中增強 Nonce 驗證和輸入清理
- **Security**: 修正下載處理器中的 nonce 驗證順序
  * `handle_backup_download`、`handle_log_download`、`handle_report_download`
- **Security**: 為需要在 nonce 驗證之前讀取的 `$_GET` 參數新增適當的 phpcs 註解
- **Code Quality**: 改進 `parse_options()` 方法，包含適當的 nonce 驗證上下文註解
- **Code Quality**: 為 `progress()` 方法新增文件
  * 說明為什麼不需要 nonce 驗證（唯讀狀態檢查）

---

## 版本 2.7.22

### 開發重點
- **Code Quality**: 系統性地按類別修正所有 Plugin Check 錯誤
  * NonceVerification、ValidatedSanitizedInput、AlternativeFunctions、DevelopmentFunctions、DirectDatabaseQuery
- **Code Quality**: 標準化所有 `set_time_limit()` 註解
  * 使用 `WordPress.PHP.NoSetTimeLimit` 而非 `Squiz.PHP.DiscouragedFunctions`
- **Code Quality**: 標準化所有 `ini_set()` 註解
  * 使用 `WordPress.PHP.IniSet` 並包含清楚說明
- **Code Quality**: 為 `class-log-handler.php` 和 `class-restore.php` 中的所有檔案操作新增適當的 phpcs:ignore 註解
  * fopen、fread、fwrite、fclose、file_get_contents、file_put_contents
- **Code Quality**: 增強 `download-handler.php` 中的輸入清理
  * 使用適當的 `wp_unslash()` 和 `absint()` 用法
- **Code Quality**: 改進 `error_log()` 處理
  * 所有 `error_log` 呼叫現在包裝在 `BACKUP_LITE_DEBUG` 檢查中，包含適當的 phpcs 註解

---

## 版本 2.7.21

### 開發重點
- **Security**: 強化安全性和 WordPress.org 標準合規性
  * nonces、清理、檔案操作、plugin-check
- **Bug Fix**: 修正 Restore History 時間戳記準確性
  * 所有時間戳記現在在內部使用 UTC，並正確顯示 WordPress 時區
- **Bug Fix**: 修正還原成功檢測
  * 改進後端 AJAX 狀態端點和前端輪詢邏輯，正確匹配 `job_id` 和 `start_timestamp`
- **Code Quality**: 為必要的檔案操作新增全面的 phpcs:ignore 註解
  * fopen、fclose、fread、fwrite，包含清楚說明
- **Code Quality**: 改進 DevelopmentFunctions 處理（set_time_limit、ini_set、error_log）
  * 使用適當的 `WordPress.PHP.*` phpcs 註解
- **Code Quality**: 增強所有 AJAX 和 admin_post 處理器的輸入清理
- **Code Quality**: 將 `Museder_Cloud_Service` 重新命名為 `Museder_RestoreOne_Cloud_Service` 以符合適當的前綴

---

## 版本 2.7.20

### 開發重點
- **Bug Fix**: 增強 `backup_lite_get_backup_path()` 以處理絕對路徑和檔名
  * 確保所有還原操作使用驗證的檔案路徑
- **Bug Fix**: 修正 AI1WM 轉換檔案路徑處理
  * 所有轉換的檔案現在透過 `backup_lite_get_backup_path()` helper 進行一致的路徑解析
- **Bug Fix**: 修正 Restore History 時間戳記儲存
  * 現在使用 UTC Unix 時間戳記 (`time()`) 以進行準確的時區轉換
- **Timezone Fix**: 簡化 Restore History 時間顯示邏輯
  * 所有時間戳記現在使用 `backup_lite_format_local_time()`，無需手動偏移計算
- **Security**: 在還原/日誌/區塊/排程處理器中將 `unlink()` 替換為 `wp_delete_file()` 以獲得更好的 WordPress 合規性
- **Security**: 改進下載處理器輸入驗證，包含適當的 `isset()` 檢查和清理
- **Code Quality**: 所有還原進入點現在在繼續之前使用 `backup_lite_get_backup_path()` 驗證檔案路徑

---

## 版本 2.7.19

### 開發重點
- **Bug Fix**: 修正還原期間「Backup file not found or unreadable」錯誤
  * 使用 `backup_lite_get_backup_path()` helper 函數統一檔案路徑處理
- **Bug Fix**: 修正下載備份白屏問題
  * 改進路徑驗證和下載處理器中的標頭輸出
- **Timezone Fix**: 移除所有硬編碼的時區偏移（Asia/Taipei、UTC+8）和手動偏移計算
  * 所有時間顯示現在使用 `backup_lite_format_local_time()`，自動處理 WordPress 時區設定
- **Code Quality**: 統一所有還原檔案路徑處理，使用 `backup_lite_get_backup_path()` helper 進行一致的路徑解析
- **Code Quality**: 改進下載處理器安全性，包含適當的 nonce 驗證和檔案路徑驗證

---

## 版本 2.7.18

### 開發重點
- **Timezone Fix**: 修正所有時間顯示以使用 WordPress 本地時區
  * 所有時間戳記現在使用 `wp_date() + wp_timezone()` 進行一致的本地時間顯示
  * 適用於 Restore History、Dashboard、Logs、Backups 和 Schedules 頁面
- **Code Quality**: 改進 `backup_lite_local_time()` 函數
  * 正確處理 UTC 時間戳記，使用 `wp_date()` 轉換為本地時區
- **Documentation**: 統一 readme.txt 和主外掛標頭版本要求
  * Requires at least: 5.8, Tested up to: 6.9
- **Documentation**: 簡化 changelog
  * 將較舊的條目移至 `docs/changelog-archive.md`
  * 在 readme.txt 中僅保留最近版本以符合 WordPress.org 合規性
- **Documentation**: 簡化 Upgrade Notice，僅包含最近 2 個版本，每個版本少於 300 字元

---

## 版本 2.7.17

### 開發重點
- **Code Quality**: 修正 `class-chunk-handler-v2.php` 中剩餘的 AlternativeFunctions 錯誤
  * fopen、rename、ini_set
- **Security**: 增強 `class-ui.php` 中的 NonceVerification 和 ValidatedSanitizedInput 修正
  * 將 phpcs:ignore 改為 phpcs:disable/enable 以獲得更好的工具識別
- **Code Quality**: 修正 `class-ui.php` 中的 fread 錯誤
  * 將 phpcs:ignore 改為 phpcs:disable/enable 以獲得更好的工具識別

---

## 版本 2.7.16

### 開發重點
- **Code Quality**: 為 `class-restore.php` 中的所有 AlternativeFunctions 新增 phpcs:ignore 註解
  * fopen、fclose、fread、fwrite、unlink、rename
- **Code Quality**: 為 `class-backup.php` 中的所有 AlternativeFunctions 新增 phpcs:ignore 註解
  * fopen、fwrite、fclose、unlink
- **Code Quality**: 為 `class-ai1wm-converter.php` 中的 AlternativeFunctions 新增 phpcs:ignore 註解
  * fopen、fread、fclose
- **Code Quality**: 為 `class-restore-handler.php` 中的所有 AlternativeFunctions 新增 phpcs:ignore 註解
  * fopen、fclose、unlink、rename
- **Security**: 修正 `class-restore-handler.php` 中的 NonceVerification 和 ValidatedSanitizedInput 警告
- **Code Quality**: 為 `class-restore.php` 和 `class-backup.php` 中的 DevelopmentFunctions 新增 phpcs:ignore 註解
  * set_time_limit、ini_set

---

## 版本 2.7.15

### 開發重點
- **Code Quality**: 為 `class-chunk-handler-v2.php` 中的 AlternativeFunctions 新增 phpcs:ignore 註解
  * fopen、fclose、fwrite、unlink、rename、fread
- **Code Quality**: 修正 `class-ui.php` 中的 fread 錯誤
  * 新增適當的 phpcs:ignore 註解
- **Code Quality**: 修正 `class-chunk-handler-v2.php` 中的 unlink 註解格式
  * 從 `file_system_operations_unlink` 改為 `unlink_unlink`
- **Code Quality**: 為 `class-chunk-handler-v2.php` 中的 error_log 新增 phpcs:ignore 註解

---

## 版本 2.7.14

### 開發重點
- **Security**: 修正 NonceVerification 警告
  * 為所有使用 `verify_ajax_request()` 的 AJAX 處理器新增 phpcs:ignore 註解
- **Security**: 修正 ValidatedSanitizedInput 警告
  * 為 `$_FILES` 和 `$_POST` 輸入新增適當的驗證和清理註解
- **Code Quality**: 修正 `class-estimate-size.php` 中的 PreparedSQL 錯誤
  * 為準備的查詢新增 phpcs:ignore 註解
- **Code Quality**: 為備份/還原操作中必要的 AlternativeFunctions 新增 phpcs:ignore 註解
  * readfile、rename、unlink、fopen、chmod

---

## 版本 2.7.13

### 開發重點
- **Security**: 增強 `class-chunk-handler.php` 中的 ExceptionNotEscaped 修正
  * 所有例外陣列值現在使用 `esc_html()` 正確轉義，並包裝在 phpcs:disable/enable 註解中
- **Code Quality**: 改進所有例外資料陣列值的轉義，確保完整的安全合規性

---

## 版本 2.7.12

### 開發重點
- **Security**: 修正 `class-chunk-handler.php` 中的 ExceptionNotEscaped 問題
  * 所有例外陣列值現在正確清理和轉義
- **Code Quality**: 為所有帶有佔位符的 `__()` 函數新增缺少的翻譯者註解
- **Code Quality**: 修正模板中的 OutputNotEscaped 問題
  * 所有輸出值現在使用 `absint()` 和 `esc_html()` 正確轉義
- **Code Quality**: 從外掛套件中排除 `create-package.sh`（僅開發工具）

---

## 版本 2.7.11

### 開發重點
- **Security**: 修正 `json_decode()` 清理問題
  * 所有 JSON 解碼的陣列現在使用遞迴 `array_map()` 和 `sanitize_text_field()` 正確清理
- **Security**: 修正 REST API permission_callback
  * 所有 REST API 路由現在使用適當的權限檢查（manage_options + nonce 驗證）而非 `'__return_true'`
- **Security**: 為 `download-handler.php` 新增 ABSPATH 檢查
  * 防止直接檔案存取
- **Code Quality**: 將所有 `parse_url()` 呼叫替換為 `wp_parse_url()` 以符合 WordPress 相容性
- **Code Quality**: 將所有 `mkdir()` 呼叫替換為 `wp_mkdir_p()` 以符合 WordPress 相容性
- **Code Quality**: 從模板中移除所有內聯 `<style>` 和 `<script>` 標籤
  * 現在在 `enqueue_assets()` 中使用 `wp_add_inline_style()` 和 `wp_add_inline_script()`
- **WordPress Compliance**: 所有變更在符合 WordPress.org Plugin Directory 指南的同時維持現有功能

---

## 版本 2.7.10

### 開發重點
- **Feature**: 新增備份大小估算功能
  * 在建立備份之前估算資料庫和檔案大小
- **Enhancement**: 資料庫大小估算
  * 使用 information_schema 查詢進行快速、非阻塞的資料庫大小計算
- **Enhancement**: 檔案大小掃描
  * 使用非同步批次處理（每批次 3000 個檔案）防止大型網站超時
- **Enhancement**: 智慧快取系統
  * 掃描結果快取 48 小時，避免重複掃描
- **Enhancement**: 即時進度追蹤
  * 檔案掃描期間顯示視覺進度條
- **Enhancement**: 大型網站檢測
  * 當估算的備份大小超過 1GB 時顯示警告，並建議使用區塊模式
- **Enhancement**: 從大小計算中排除備份目錄、日誌目錄、快取資料夾和系統檔案
  * .git、.svn、.DS_Store
- **UX**: 在 Backups 頁面新增「Estimated Backup Size」卡片
  * 顯示資料庫大小、檔案大小和總估算大小
- **UX**: 「Re-scan Size」按鈕允許手動重新整理大小估算
- **Performance**: 使用 opendir/readdir 而非 RecursiveIteratorIterator 優化檔案掃描
  * 提高記憶體效率
- **Performance**: 每個掃描批次限制為 1.5 秒執行時間，防止伺服器過載
- **Security**: 所有 AJAX 端點需要 manage_options 權限和 nonce 驗證
- **Security**: 檔案掃描僅管理員可存取，且僅在外掛管理頁面上

---

## 版本 2.7.09

### 開發重點
- **Enhancement**: 為 .wpress 檔案新增 PHP 原生解壓縮後備
  * 當 tar 命令失敗時，嘗試使用 `gzopen()` 處理 gzip 壓縮檔案
- **Enhancement**: 改進 .wpress 檔案解壓縮失敗的錯誤訊息
  * 提供更具操作性的指導，包括建議驗證檔案完整性、使用 All-in-One WP Migration 外掛轉換或聯繫支援
- **Fix**: 增強 .wpress 檔案解壓縮錯誤處理
  * 當所有解壓縮方法失敗時提供更清楚的診斷資訊

---

## 版本 2.7.08

### 開發重點
- **Fix**: 修正還原失敗時進度條立即跳到 100% 但網路輪詢會繼續的問題
  * 現在當進度達到 100% 且狀態為失敗時，輪詢立即停止以防止不必要的網路請求
- **Fix**: 增強失敗檢測邏輯
  * 當進度為 100% 且狀態為 'failed' 時，系統現在立即停止所有輪詢並顯示錯誤訊息
  * 防止背景中繼續進行網路活動

---

## 版本 2.7.07

### 開發重點
- **Fix**: 增強 .wpress 檔案解壓縮以支援多種格式
  * 現在自動檢測並處理 gzip 壓縮 tar 和未壓縮 tar 格式
  * 如果 gzip 解壓縮失敗，自動後備到未壓縮 tar 解壓縮
- **Fix**: 改進檔案格式檢測
  * 透過讀取檔案標頭在嘗試解壓縮之前確定正確的解壓縮方法
- **Fix**: 修正當 .wpress 檔案格式不是 gzip 壓縮 tar 時還原會立即在 100% 完成的問題

---

## 版本 2.7.06

### 開發重點
- **Fix**: 新增直接 .wpress 檔案解壓縮支援
  * 使用 tar 命令。只要伺服器上有 tar 命令，All-in-One WP Migration .wpress 檔案現在可以直接還原，無需轉換
- **Fix**: 改進 .wpress 檔案解壓縮失敗的錯誤處理
  * 當 tar 命令不可用或解壓縮失敗時提供特定錯誤訊息
- **Enhancement**: 更新 All-in-One WP Migration 轉換器
  * 指示 .wpress 檔案可以直接還原，無需轉換
- **Enhancement**: 增強檔案解壓縮邏輯
  * 檢測 .wpress 檔案並在後備到 ZIP 方法之前嘗試 tar 解壓縮

---

## 版本 2.7.05

### 開發重點
- **Fix**: 修正還原完成/失敗檢測
  * 還原狀態訊息現在立即出現，無需重新整理頁面
  * 增強輪詢邏輯，即時檢查還原歷史中的失敗狀態
- **Fix**: 改進檔案解壓縮失敗的錯誤處理
  * 為 .wpress 和 ZIP 檔案解壓縮問題新增詳細日誌記錄和更好的錯誤訊息
- **Fix**: 在還原服務執行流程中新增自動 All-in-One WP Migration 備份轉換
  * 正確處理 .wpress 檔案
- **Enhancement**: 增強常見還原失敗情境的錯誤訊息
  * 解壓縮失敗、資料庫錯誤等，提供更具操作性的資訊
- **Enhancement**: 改進檔案解壓縮錯誤處理
  * 為 ZipArchive 和 PclZip 失敗提供詳細日誌記錄

---

## 版本 2.7.04

### 開發重點
- **Enhancement**: 新增還原後安全模式
  * 還原後自動停用非必要外掛，防止白屏問題
  * 管理員可以透過管理介面中的一鍵按鈕恢復外掛
- **Enhancement**: 增強 URL 搜尋替換功能
  * 現在自動處理 http/https、www/non-www 和子目錄路徑變化
  * 為跨網域遷移提供更好的支援
- **Enhancement**: 新增還原完成 hooks
  * `backup_lite_after_restore` 和 `backup_lite_after_restore_safe_mode` hooks 允許其他外掛與還原工作流程整合
- **Enhancement**: 改進診斷日誌記錄
  * 為資料庫匯入（siteurl/home 變更）、URL 替換對和安全模式外掛管理新增詳細日誌
  * 便於故障排除
- **Security**: 所有新功能遵循 WordPress 編碼標準和安全最佳實踐

---

## 版本 2.7.03

### 開發重點
- **Fix**: 優化 All-in-One 備份轉換的大型檔案處理
  * 新增執行時環境優化（執行時間和記憶體限制）以防止轉換期間超時
- **Fix**: 改進檔案大小檢測
  * 大於 1GB 的檔案將跳過自動轉換以避免 AJAX 超時錯誤
  * 500MB-1GB 之間的檔案將嘗試轉換，並延長超時時間
- **Fix**: 優化 SHA1 計算
  * 大型檔案（>500MB）在 `prepare_session` 期間跳過 SHA1 計算，防止檔案分析步驟期間超時
- **Fix**: 增強錯誤處理
  * 使用適當的例外捕獲和清理，遵循 WordPress 編碼標準

---

## 版本 2.7.02

### 開發重點
- **Fix**: 改進 All-in-One WP Migration 備份轉換的錯誤處理
  * 新增適當的例外處理，使用 try-catch 區塊防止轉換遇到錯誤時上傳失敗
- **Fix**: 增強錯誤訊息，遵循 WordPress 編碼標準
  * 所有例外訊息現在使用 `sanitize_text_field()` 正確清理以進行日誌記錄
  * 使用 `esc_html__()` 處理使用者面向的訊息
- **Fix**: 轉換後新增檔案存在檢查
  * 確保轉換的檔案在繼續還原會話準備之前有效
- **Security**: 從 JSON 回應中移除原始例外訊息
  * 防止暴露敏感資訊。所有錯誤訊息現在正確轉義，遵循 WordPress 安全最佳實踐
- **Enhancement**: 新增 @plugin-check 註解
  * 說明安全處理和程式碼合規性，符合 WordPress Plugin Check 標準

---

## 版本 2.7.01

### 開發重點
- **Feature**: 新增 All-in-One WP Migration 備份轉換器
  * 外掛現在自動檢測並轉換 All-in-One WP Migration 備份檔案（.zip 和 .wpress 格式）
  * 轉換為 Museder RestoreOne 格式以進行無縫還原
- **Feature**: 自動轉換在上傳、選擇現有備份或從遠端 URL 下載時觸發
  * 轉換器支援多種 All-in-One 備份結構
  * 包括直接結構、restore-package 結構和 wp-content 結構
- **Enhancement**: 改進還原處理器以自動處理格式轉換
  * 當檢測到 All-in-One 備份時，在還原開始之前將其轉換為 Museder RestoreOne 格式
- **Added**: 新增類別 `Backup_Lite_AI1WM_Converter`
  * 位於 `includes/class-ai1wm-converter.php`，用於處理 All-in-One 備份轉換
- **Added**: 新增文件
  * `docs/AI1WM-CONVERSION.md` 和 `docs/AI1WM-IMPLEMENTATION.md` 中的 All-in-One 轉換功能文件

---

## 版本 2.6.126

### 開發重點
- **Security**: 移除所有對 `move_uploaded_file()` 的直接呼叫以通過 WordPress Plugin Check
  * 替換為 `stream_copy_to_stream()` 進行安全檔案處理
  * 所有區塊上傳和還原檔案上傳操作現在使用 `fopen() + stream_copy_to_stream()` 而非 `move_uploaded_file()`
  * 功能、錯誤碼和 HTTP 狀態碼保持不變

---

## 總結

### 主要開發方向

1. **安全性強化** (2.7.11 - 2.7.29)
   - 全面的 WordPress Plugin Check 合規性改進
   - 增強 nonce 驗證和輸入清理
   - 標準化檔案操作和資料庫查詢註解

2. **功能增強** (2.7.01 - 2.7.10)
   - All-in-One WP Migration 備份轉換器
   - 備份大小估算功能
   - 還原後安全模式

3. **錯誤修正** (2.7.05 - 2.7.40)
   - 還原流程改進
   - 時間戳記和時區處理修正
   - 下載處理器修正
   - Dashboard 顯示問題修正

4. **程式碼品質** (所有版本)
   - WordPress Coding Standards 合規性
   - 國際化 (i18n) 處理
   * 輸出轉義 (escaping)
   * 文件改進

---

**文件建立日期**: 2025-12-02  
**最後更新版本**: 2.8.00  
**文件維護**: 開發團隊

