# Backup Lite v2.3.2 上架說明與安裝測試步驟

## 1. 準備環境

- WordPress 6.8.3 以上。
- PHP 7.4 ~ 8.3。
- 具備 `wp-content/uploads` 讀寫權限。
- 可選：mysqldump、mysql、ZipArchive，用於最快速的備份/還原。

## 2. 外掛安裝

1. 於專案根目錄執行：
   ```bash
   php scripts/build-package.php
   ```
   會產生 `backup-lite-v2.3.2.zip`。
2. 登入 WordPress 後台，前往「外掛 → 安裝外掛 → 上傳外掛」。
3. 上傳 `backup-lite-v2.3.2.zip` 並啟用外掛。
4. 於「工具 → Backup Lite」確認新版介面（含進度條與速度/ETA 顯示）。

## 3. 功能驗收建議流程

### 3.1 完整環境（具備 mysqldump / ZipArchive）

1. 點擊「Backup Site」，確認成功訊息並可下載 `.zip` 單一備份檔。
2. 確認「Available Backups」出現新的備份記錄與下載連結。
3. 下載備份檔，解壓後確認包含 `database.sql`、`meta.json`、`wp-content/`。
4. 於「Logs」區塊下載最新 log，確認備份流程記錄存在。

### 3.2 分段上傳還原

1. 在「Restore Site」區塊選擇一個 `.zip/.wpress` 備份檔。
2. 送出後觀察進度條：應顯示「正在上傳第 X/總 Y 片段」「速度」「預估剩餘時間」與 SHA1 對照。
3. 若伺服端回傳缺片列表，界面會自動提示「補傳缺片並續傳」，確認流程可續傳成功。
4. 上傳完畢後應顯示「正在合併檔案 → 正在還原 → 還原完成」。
5. 完成後檢查網站資料庫與 `wp-content` 是否被覆蓋。

### 3.3 受限環境（無 mysqldump / 無 ZipArchive）

1. 暫時停用或移除 mysqldump、ZipArchive（共享主機常見限制）。
2. 再次執行「Backup Site」，應仍產生 `.zip` 備份檔（由 PHP / PclZip fallback）。
3. 上傳該備份檔並進行還原，確認流程仍可成功並於 log 顯示 fallback 資訊。

### 3.4 權限與安全性

1. 以非管理員角色登入，確認無法存取「Backup Lite」頁面與 AJAX API。
2. 嘗試移除 nonce 或偽造請求，應回傳錯誤訊息並記錄 log。

## 4. 自動化測試

在專案根目錄執行：
```bash
php tests/run-tests.php
```
或使用 Docker：
```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/run-tests.php
```
- 測試結果輸出於 `tests/output/report-v1.4.0-pro-sec.json`。
- 報告包含完整環境備份、分段上傳（100MB/500MB/2GB 模擬）與受限環境測試結果。

額外驗證 `verify_zip()`：
```bash
php tests/run-verify-zip.php
```
- 會產生 `tests/output/verify-zip-report.json`，確認 100MB / 500MB / 2GB 分片合併後皆可成功開啟。

## 5. 錯誤排查 / 設定建議

- 建議伺服器參數：`max_execution_time >= 600`、`memory_limit >= 512M`、允許 `set_time_limit()`。
- 若前端出現 `stage=merge / error=sha1_mismatch`：代表上傳檔案損毀，請重新上傳或檢查網路中斷。
- 若前端出現 `stage=merge / error=zip_verification_failed`：伺服端驗證 zip 失敗，請查看 `wp-content/uploads/backup-lite-logs/restore.log` 的 `zip_error_code`。
- 若前端出現 `stage=restore / error=zip_open_failed`：ZipArchive 解壓失敗，外掛會自動改用 PclZip；若仍失敗，請檢查檔案權限與磁碟空間。
- 全部錯誤都會記錄在 `restore.log` 與 `debug.log`，便於比對 JSON 回傳。

## 6. 發佈前檢查清單

- [ ] 測試報告所有情境皆成功。
- [ ] `backup-lite-v1.4.0-pro-sec.zip` 已重新產生並附於套件。
- [ ] `readme.txt` 與 `backup-lite.php` 版本號一致（1.4.0）。
- [ ] 「Backup Site / Restore Site」與分段上傳流程皆完成驗收。
- [ ] `tests/output/report-v1.4.0-pro-sec.json` 與 `sha1-validation-report.json` 已更新。

完成以上步驟後，即可提交 WordPress.org 審核或部署至客戶站台。
