# Museder RestoreOne 備份機制說明

## 概述

Museder RestoreOne 採用**異步批次處理**的備份機制，將備份過程分解為多個小批次，避免 PHP 執行時間限制和內存耗盡問題。備份過程分為三個主要階段：**準備階段**、**打包階段**、**完成階段**。

---

## 備份流程架構

### 1. 備份啟動流程

```
用戶點擊「Backup Site」
    ↓
前端發送 AJAX 請求 (backup_lite_start_backup_job)
    ↓
後端創建備份作業 (Backup_Lite_Backup_Jobs::create_job)
    ↓
準備異步備份作業 (Backup_Lite_Backup::prepare_async_job)
    ↓
返回作業 ID 給前端
    ↓
前端開始輪詢作業狀態 (每 3 秒)
```

### 2. 準備階段 (Preparing)

在 `prepare_async_job()` 中執行：

1. **生成備份檔案名稱**
   - 格式：`{網站網址}-{日期時間}-{隨機碼}.zip`
   - 例如：`musederlabs.com-20251212120000-abc123.zip`

2. **導出數據庫**
   - 優先使用 `mysqldump`（如果可用）
   - 備用方案：PHP 直接查詢導出
   - 輸出檔案：`database.sql`

3. **生成元數據檔案**
   - 包含備份資訊、選項、時間戳等
   - 輸出檔案：`meta.json`

4. **初始化 ZIP 檔案**
   - 先將 `database.sql` 和 `meta.json` 加入 ZIP
   - 建立空的 ZIP 檔案結構

5. **掃描文件清單 (File Manifest)**
   - 掃描需要備份的目錄：
     - `wp-content/themes` (優先)
     - `wp-content/plugins` (優先)
     - `wp-content/uploads` (優先)
     - `wp-content/mu-plugins`
     - `wp-content/languages`
     - `wp-content` 根目錄
   - 建立文件清單 JSON 檔案
   - 小站點（< 1000 文件）跳過緩存以減少開銷
   - 大站點（≥ 1000 文件）使用 5 分鐘緩存

6. **保存作業狀態**
   - 作業 ID、狀態、進度指標等
   - 保存到 `wp-content/uploads/backup-lite-jobs/{job-id}.json`

### 3. 打包階段 (Packing)

批次處理循環，每次處理一批文件：

1. **批次大小計算**
   - 根據可用內存動態調整：
     - **高內存環境** (>512MB)：800 文件 / 150MB
     - **中等內存環境** (>256MB)：500 文件 / 100MB
     - **低內存環境**：300 文件 / 70MB

2. **批次處理流程**
   ```
   從 manifest 讀取下一批文件
       ↓
   驗證文件存在性（使用 manifest 中的文件大小，避免重複 filesize() 調用）
       ↓
   跳過超大文件 (>2GB)
       ↓
   將文件加入 ZIP 檔案
       ↓
   壓縮優化：
     - 大文件 (>10MB)：使用 CM_STORE（無壓縮，更快）
     - 小文件 (≤10MB)：使用 CM_DEFLATE（標準壓縮）
       ↓
   更新進度指標
       ↓
   保存作業狀態
   ```

3. **處理方式**
   - **AJAX 模式**：前端每 3 秒輪詢狀態，必要時觸發 `backup_lite_continue_backup_job`
   - **Cron 模式**：WordPress Cron 在背景處理（批次更大：600 文件 / 120MB）

### 4. 完成階段 (Completed)

1. **最終化處理**
   - 關閉 ZIP 檔案
   - 計算最終檔案大小
   - 計算備份耗時

2. **清理臨時檔案**
   - 刪除臨時目錄
   - 刪除 manifest JSON 檔案

3. **記錄備份事件**
   - 寫入備份歷史記錄
   - 記錄到日誌檔案

---

## 核心技術特點

### 1. 異步處理機制

- **作業狀態管理**：使用 JSON 檔案保存作業狀態
- **鎖定機制**：防止並發處理同一作業
- **自動恢復**：如果作業中斷，可以從上次位置繼續

### 2. 性能優化

- **文件大小緩存**：優先使用 manifest 中的文件大小，減少 `filesize()` 調用
- **智能壓縮**：大文件不壓縮（更快），小文件壓縮（節省空間）
- **目錄掃描優化**：優先處理重要目錄（themes, plugins, uploads）
- **批次大小動態調整**：根據可用內存自動調整批次大小
- **文件清單緩存**：大站點使用 5 分鐘緩存

### 3. 錯誤處理

- **超時處理**：AJAX 請求設置 30-60 秒超時
- **網絡錯誤恢復**：網絡錯誤不會停止備份，自動重試
- **文件跳過**：自動跳過超大文件（>2GB）和無法訪問的文件
- **詳細日誌**：所有操作都記錄到日誌檔案

### 4. 文件排除機制

自動排除以下內容：
- `.git`, `node_modules`, `vendor` 等開發目錄
- `.cache`, `temp`, `tmp`, `logs` 等臨時目錄
- `debug.log`, `error_log` 等日誌檔案
- 超大文件（>2GB）
- 備份目錄本身

---

## 前端交互流程

### 輪詢機制

```javascript
// 每 3 秒輪詢一次作業狀態
setInterval(pollBackupJobStatus, 3000);

// 如果作業停止處理，觸發繼續處理
if (!job.processing && job.id) {
    maybeNudgeBackupJob(job.id);
}
```

### 超時處理

- **輪詢請求**：30 秒超時
- **初始請求**：60 秒超時
- **網絡錯誤**：自動重試，不中斷備份

### 進度顯示

- 實時更新進度條（0-100%）
- 顯示已處理文件數 / 總文件數
- 顯示已處理大小 / 總大小
- 顯示已用時間

---

## 數據庫導出方式

### 優先方案：mysqldump

```bash
mysqldump --single-transaction --quick --lock-tables=false \
  --skip-comments --no-tablespaces \
  -h{host} -u{user} -p{password} {database} > database.sql
```

**優點**：
- 速度快
- 內存占用低
- 支持大數據庫

### 備用方案：PHP 直接查詢

如果 `mysqldump` 不可用，使用 PHP 逐表導出：

1. 獲取所有表名
2. 對每個表：
   - 導出表結構（`SHOW CREATE TABLE`）
   - 分批導出數據（每次 500 行）
   - 使用 64KB 寫入緩衝區

---

## 文件清單 (Manifest) 結構

```json
{
  "count": 1234,
  "bytes": 52428800,
  "files": [
    {
      "path": "/path/to/file.php",
      "target": "wp-content/themes/theme/file.php",
      "size": 1024
    },
    ...
  ]
}
```

- **count**：文件總數
- **bytes**：總大小（字節）
- **files**：文件列表，每個文件包含：
  - `path`：絕對路徑
  - `target`：在 ZIP 中的相對路徑
  - `size`：文件大小（字節）

---

## 備份檔案結構

```
backup-archive.zip
├── database.sql          # 數據庫導出
├── meta.json            # 元數據
├── wp-content/
│   ├── themes/
│   ├── plugins/
│   ├── uploads/
│   ├── mu-plugins/
│   └── languages/
└── ...
```

---

## 作業狀態管理

### 作業狀態值

- `pending`：等待處理
- `preparing`：準備中（導出數據庫、生成清單）
- `packing`：打包中（處理文件）
- `completed`：已完成
- `failed`：失敗
- `cancelled`：已取消

### 作業狀態檔案

位置：`wp-content/uploads/backup-lite-jobs/{job-id}.json`

內容：
```json
{
  "id": "uuid-here",
  "status": "packing",
  "stage": "packing",
  "message": "Processing files…",
  "pointer": 500,
  "processed_files": 500,
  "processed_bytes": 52428800,
  "total_files": 2000,
  "total_bytes": 104857600,
  "archive_path": "/path/to/archive.zip",
  "started_at": 1234567890,
  "last_activity": 1234567890
}
```

---

## 性能指標

### 批次處理速度

- **小文件**（<10MB）：約 50-100 文件/秒
- **大文件**（>10MB）：約 10-20 文件/秒（無壓縮）

### 內存使用

- **準備階段**：約 50-100MB
- **批次處理**：約 100-200MB（根據批次大小）
- **峰值**：約 300-500MB

### 典型備份時間

- **小型網站**（<1000 文件，<100MB）：1-5 分鐘
- **中型網站**（1000-10000 文件，100MB-1GB）：5-30 分鐘
- **大型網站**（>10000 文件，>1GB）：30 分鐘以上

---

## 故障恢復

### 自動恢復機制

1. **作業中斷**：下次輪詢時自動繼續
2. **網絡錯誤**：自動重試，不停止備份
3. **文件缺失**：跳過該文件，繼續處理

### 手動恢復

如果備份卡住，可以：
1. 刷新頁面（作業狀態會自動恢復）
2. 點擊「Cancel Backup」取消
3. 重新啟動備份

---

## 總結

Museder RestoreOne 的備份機制採用**異步批次處理**，具有以下優勢：

1. ✅ **避免超時**：分批處理，不會因 PHP 執行時間限制而中斷
2. ✅ **內存友好**：動態調整批次大小，避免內存耗盡
3. ✅ **性能優化**：智能壓縮、文件大小緩存、目錄優先級
4. ✅ **錯誤恢復**：自動重試、跳過問題文件、詳細日誌
5. ✅ **用戶體驗**：實時進度顯示、可取消、自動恢復

這種設計使得備份過程既穩定又高效，能夠處理從小型到大型的各種 WordPress 網站。

