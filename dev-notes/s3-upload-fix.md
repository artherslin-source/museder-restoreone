# S3 上傳修復與架構改進

## 問題描述

「備份列表 → 點 Action：Upload to S3」會出現 500 錯誤，console 顯示：
```
Server returned non-JSON response. Please check logs for details.
```

log 中有類似錯誤：
```
Requests\Transport\Curl::request(): Argument #3 ($data) must be of type array|string, resource given
```

## 根本原因

`wp_remote_request()` 不接受 `resource` 作為 body 參數，只接受 `string` 或 `array`。之前的實作可能在某個地方將 `fopen()` 返回的 resource handle 直接傳遞給了 `wp_remote_request()`。

## 修復方案

### 短期修復（已完成）

1. **修正 `Backup_Lite_S3_Service::upload_backup()`**
   - 使用 `file_get_contents()` 讀取整個檔案為 string
   - 確保 body 參數是 string 類型，不是 resource
   - 在 `put_object_via_sigv4()` 中添加 body 類型驗證

2. **將 `put_object_via_sigv4()` 改為 `public static`**
   - 允許 `Backup_Lite_S3_Uploader` 類別調用此方法
   - 保持向後兼容性

3. **建立新的 `Backup_Lite_S3_Uploader` 類別**
   - 提供統一的 S3 上傳介面
   - 為未來 multipart upload 預留架構
   - 目前使用單檔上傳（single-part upload）

4. **更新 AJAX handler**
   - `ajax_upload_existing_backup()` 現在使用新的 `Backup_Lite_S3_Uploader` 類別
   - 保持向後兼容，如果新類別不可用則回退到舊方法

### 長期架構（預留）

`Backup_Lite_S3_Uploader` 類別已預留以下架構：

1. **Multipart Upload 支援**
   - 檔案大小超過 50MB 時應使用 multipart upload
   - 每個 chunk 大小為 5MB（符合 S3 規範：5MB - 5GB）
   - 流程：CreateMultipartUpload → UploadPart (多次) → CompleteMultipartUpload

2. **進度追蹤**
   - 上傳進度存儲在 `wp_options` 中
   - 支援可恢復的上傳（resumable upload）
   - 前端可通過 AJAX 查詢上傳進度

3. **錯誤處理**
   - 支援 AbortMultipartUpload 清理失敗的上傳
   - 所有錯誤返回 `WP_Error` 而不是 string

## 受影響的檔案

### 新增檔案
- `includes/class-backup-lite-s3-uploader.php` - 新的 S3 上傳類別

### 修改檔案
- `includes/class-backup-lite-s3-service.php`
  - 將 `put_object_via_sigv4()` 從 `protected static` 改為 `public static`
  - 添加 body 類型驗證（確保是 string，不是 resource）
  - 改進錯誤處理和日誌記錄

- `includes/class-ui.php`
  - 更新 `ajax_upload_existing_backup()` 使用新的 `Backup_Lite_S3_Uploader` 類別
  - 保持向後兼容性

- `museder-restoreone.php`
  - 載入新的 `Backup_Lite_S3_Uploader` 類別

## 舊的手動上傳 S3 流程 vs 新流程

### 舊流程（已修復）
```
AJAX Request
  → ajax_upload_existing_backup()
  → upload_backup_to_s3_unified()
  → Backup_Lite_Backup::upload_backup_to_s3()
  → Backup_Lite_S3_Service::upload_backup()
  → file_get_contents() (讀取整個檔案為 string)
  → put_object_via_sigv4()
  → wp_remote_request() (使用 string body)
```

### 新流程（當前）
```
AJAX Request
  → ajax_upload_existing_backup()
  → Backup_Lite_S3_Uploader::get_instance()
  → Backup_Lite_S3_Uploader::upload_backup_file()
  → Backup_Lite_S3_Uploader::upload_single_part()
  → file_get_contents() (讀取整個檔案為 string)
  → Backup_Lite_S3_Service::put_object_via_sigv4()
  → wp_remote_request() (使用 string body)
```

### 未來流程（multipart upload）
```
AJAX Request (多次)
  → ajax_upload_existing_backup()
  → Backup_Lite_S3_Uploader::get_instance()
  → Backup_Lite_S3_Uploader::upload_backup_file()
  → Backup_Lite_S3_Uploader::upload_multipart()
  → CreateMultipartUpload (第一次 AJAX)
  → UploadPart (多次 AJAX，每次上傳 5MB chunk)
  → CompleteMultipartUpload (最後一次 AJAX)
```

## 未來要補完「multipart upload」還欠哪些步驟

### 1. 實作 `upload_multipart()` 方法

需要實作以下功能：

- **CreateMultipartUpload**
  - 調用 S3 API 初始化 multipart upload
  - 獲取 `UploadId`
  - 將 `UploadId` 和上傳狀態存儲在 `wp_options` 中

- **UploadPart**
  - 將檔案分割成多個 chunk（每個 5-10MB）
  - 每次 AJAX 請求只上傳一個 chunk
  - 獲取每個 chunk 的 `ETag`
  - 將 `ETag` 和進度存儲在 `wp_options` 中

- **CompleteMultipartUpload**
  - 收集所有 chunk 的 `ETag`
  - 調用 S3 API 完成 multipart upload
  - 清理 `wp_options` 中的上傳狀態

- **AbortMultipartUpload**
  - 如果上傳失敗，清理 S3 上的未完成上傳
  - 清理 `wp_options` 中的上傳狀態

### 2. 前端進度顯示

- 在備份列表頁面顯示上傳進度條
- 定期通過 AJAX 查詢上傳進度
- 顯示當前上傳的 chunk 編號和總數

### 3. 可恢復上傳

- 如果上傳中斷，下次可以從上次中斷的地方繼續
- 需要存儲已上傳的 chunk 列表和 `ETag`

### 4. 錯誤處理

- 處理網路中斷
- 處理 S3 API 錯誤
- 自動重試失敗的 chunk

## 技術細節

### WordPress Coding Standards 遵循

- ✅ 所有輸入使用 `wp_unslash()` + `sanitize_text_field()` / `sanitize_file_name()`
- ✅ 所有輸出使用 `esc_html()`, `esc_attr()`, `esc_url()`
- ✅ 所有新增字串使用 `__()` / `esc_html__()` 搭配 `museder-restoreone` text domain
- ✅ 權限檢查：所有 AJAX handler 檢查 `current_user_can( 'manage_options' )`
- ✅ Nonce 驗證：所有 AJAX handler 使用 `check_ajax_referer()`
- ✅ 所有 AJAX handler 使用 `wp_send_json_success()` / `wp_send_json_error()`

### 安全性改進

- ✅ 驗證檔案路徑在備份目錄內
- ✅ 驗證檔案存在且可讀
- ✅ 所有錯誤訊息經過 sanitize
- ✅ 不暴露敏感資訊（secret key、authorization header）

### 錯誤處理

- ✅ 所有錯誤返回 `WP_Error` 而不是 string
- ✅ 所有異常使用 try-catch 捕獲
- ✅ 所有錯誤記錄到 plugin log

## 測試建議

1. **小檔案測試（< 50MB）**
   - 上傳一個小備份檔案到 S3
   - 確認上傳成功
   - 確認檔案在 S3 bucket 中

2. **大檔案測試（> 50MB，< 200MB）**
   - 上傳一個大備份檔案到 S3
   - 確認上傳成功（目前使用單檔上傳，會消耗較多記憶體）
   - 確認檔案在 S3 bucket 中

3. **錯誤處理測試**
   - 測試 S3 設定錯誤時的回應
   - 測試檔案不存在時的回應
   - 測試檔案不可讀時的回應

4. **未來 multipart upload 測試**
   - 測試大檔案（> 200MB）的 multipart upload
   - 測試上傳中斷後的可恢復性
   - 測試進度顯示是否正確

## 注意事項

1. **記憶體限制**
   - 目前使用 `file_get_contents()` 讀取整個檔案到記憶體
   - 對於大檔案（> 200MB），可能會遇到記憶體限制問題
   - 未來實作 multipart upload 後，這個問題會得到解決

2. **超時限制**
   - 目前 `wp_remote_request()` 的 timeout 設為 600 秒（10 分鐘）
   - 對於非常大的檔案，可能需要更長的 timeout
   - 未來 multipart upload 後，每個 chunk 的上傳時間會更短

3. **向後兼容性**
   - 新的 `Backup_Lite_S3_Uploader` 類別保持與舊方法的兼容
   - 如果新類別不可用，會自動回退到舊方法

## 參考資料

- [AWS S3 Multipart Upload](https://docs.aws.amazon.com/AmazonS3/latest/userguide/mpuoverview.html)
- [WordPress HTTP API](https://developer.wordpress.org/reference/functions/wp_remote_request/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)


