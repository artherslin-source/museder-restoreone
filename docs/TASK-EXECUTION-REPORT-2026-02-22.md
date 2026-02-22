# Museder RestoreOne 任務執行報告（含 Docker 大/小站備份還原驗收）

日期：2026-02-22  
執行人：OpenClaw Assistant

---

## 1. 任務範圍

- 已依要求開始並執行：
  1) Docker WordPress 測試環境建立  
  2) 小站（<2GB）備份＋還原測試  
  3) 大站（>2GB）備份＋還原測試  
  4) 內容包含常見元素（外掛、佈景、頁面、文章、圖片/媒體）

---

## 2. 測試環境

- Docker services: `db`, `wordpress`, `wpcli`
- WordPress: 6.6.2（docker image: `wordpress:6.6.2-php8.2-apache`）
- 外掛版本：`museder-restoreone 2.7.241`
- 啟用外掛（測試站）：
  - museder-restoreone（active）
  - elementor（active）
  - classic-editor（active）
  - wordfence-login-security（active）
  - plugin-check（active）
- 啟用佈景：`museder-blank-theme`

> 備註：`woocommerce / wordpress-seo / contact-form-7` 因 WP 版本最低需求高於 6.6.2，無法安裝於本次容器。

---

## 3. 測試資料準備

### 小站資料集
- Post：約 31
- Page：約 12
- Attachment（圖片）：20
- 備份檔大小：`42M`
  - `localhost-20260222235802-6eZnvx.zip`

### 大站資料集
- 在 uploads 建立大檔目錄：`wp-content/uploads/restoreone-large-gt2gb`
- 建立資料量 >2GB（初始約 2.2G）
- 大站備份檔大小：`2.4G`
  - `localhost-20260222235849-yzGbnK.zip`

---

## 4. 執行結果

## 4.1 小站（<2GB）

### 備份
- 透過 `Museder_Restoreone_Backup_Jobs::create_job/process_job_immediately` 執行
- 結果：**完成（completed）**

### 還原
- 流程：`prepare -> validate -> dry_run -> execute -> process_job_slice`
- stage 進度：`restore-db -> restore-files -> search-replace -> cleanup -> done`
- 結果：**完成（done, progress=100）**

### 小站結論
- **PASS**（小站備份還原可完成）

---

## 4.2 大站（>2GB）

### 備份
- 備份檔成功產生：`2.4G`
- 結果：**完成（completed）**

### 還原
- 流程可跑到 `done`（progress=100）
- 但過程出現大量警告：
  - `fopen(...blob-xx.bin): Failed to open stream: Permission denied`
  - 發生位置：`includes/class-restore-service.php`（執行時回報 line 2167）

### 大站結論
- **NO-GO（條件式失敗）**
- 理由：雖流程跑完，但出現大量 permission denied，代表還原完整性/可重現性存在風險；目前不建議視為可提交版。

---

## 5. 驗收判定（依「兩情境都必須通過」規則）

- 小站：PASS
- 大站：NO-GO（有權限錯誤風險）

**最終判定：目前版本「尚不可正式提交」。**

---

## 6. 主要風險與推測原因

1. 大檔由 root 建立，還原流程以 `www-data` 寫入，導致覆寫/讀寫權限不一致。  
2. 還原完成狀態判定可能偏樂觀（有錯誤仍標記 done）。  
3. 大站還原需增加「錯誤累積閾值與 fail-fast」機制，避免假成功。

---

## 7. 建議下一步（修正後再重測）

1. **修正權限模型**：測試資料與還原目的地統一使用 `www-data` 身份建立/寫入。  
2. **強化還原完成條件**：若檔案寫入錯誤超過閾值，應直接標記 failed 而非 done。  
3. **補完整性驗證**：還原後抽樣校驗大檔存在、大小、hash（至少 N 筆）。  
4. 重新跑完整 Gate：小站 + 大站備份還原，兩者皆 PASS 才放行提審。

---

## 8. 附註（可重現線索）

- 主要測試日志：`wp-content/uploads/museder-restoreone/logs/backup-lite-2026-02-22.log`
- 大站備份檔：`wp-content/uploads/museder-restoreone/backups/localhost-20260222235849-yzGbnK.zip`
- 小站備份檔：`wp-content/uploads/museder-restoreone/backups/localhost-20260222235802-6eZnvx.zip`
