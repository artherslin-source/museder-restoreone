# 回歸實測腳本

## 測試目標

驗證以下修正是否生效：
1. 還原時不再出現「Backup file not found or unreadable」錯誤
2. Restore History 的時間戳與 log 時間一致，且會隨 WordPress 時區設定變化
3. 下載備份功能正常運作

## 測試步驟

### 階段 1：台北時區測試（UTC+8）

1. **設定 WordPress 時區**
   - 進入 WordPress 後台：Settings → General
   - 將「Timezone」設定為「Taipei (UTC+8)」
   - 儲存設定

2. **建立新備份**
   - 進入 Museder RestoreOne → Backups
   - 點擊「Create Backup」
   - 等待備份完成，記下完成時間（例如：14:30:00）

3. **執行還原**
   - 進入 Museder RestoreOne → Restore
   - 在「Restore History」下方選擇剛剛建立的備份檔案
   - 點擊「Start Restore」
   - 確認：
     - ✅ 進度條正常運作，沒有錯誤 pop-up
     - ✅ 還原完成後，Restore History 最後一筆的時間與備份完成時間接近（允許幾秒差距）

4. **檢查時間一致性**
   - 檢查以下 5 個位置顯示的時間是否一致（允許秒數微小差異）：
     - **Backups → Created**：應顯示備份建立時間
     - **Dashboard → Recent Backups**：應顯示相同時間
     - **Dashboard → Latest Logs**：應顯示 log 時間
     - **Restore → Restore History**：應顯示還原開始時間（與 log 中「Site restore started」時間一致）
     - **Logs → Log Files → Last Modified**：應顯示 log 檔案修改時間

5. **對照 Log 檔案**
   - 進入 Museder RestoreOne → Logs
   - 下載最新的 log 檔案
   - 檢查 log 中「Restore completed successfully」的時間
   - 確認 Restore History 的時間與 log 時間只差時區轉換（例如：log 顯示 `2025-12-03T12:03:43+08:00`，Restore History 應顯示 `2025-12-03 12:03`）

### 階段 2：倫敦時區測試（UTC+0）

1. **更改時區設定**
   - 進入 WordPress 後台：Settings → General
   - 將「Timezone」設定為「London (UTC+0)」
   - 儲存設定

2. **快速測試**
   - 建立一個新備份（快速測試，不需要完整備份）
   - 執行還原
   - 確認：
     - ✅ Restore History 的時間會自動調整為倫敦時間（比台北時間慢 8 小時）
     - ✅ 所有時間顯示（Backups、Dashboard、Logs、Restore History）都一致

### 階段 3：洛杉磯時區測試（UTC-8）

1. **更改時區設定**
   - 進入 WordPress 後台：Settings → General
   - 將「Timezone」設定為「Los Angeles (UTC-8)」
   - 儲存設定

2. **快速測試**
   - 建立一個新備份（快速測試）
   - 執行還原
   - 確認：
     - ✅ Restore History 的時間會自動調整為洛杉磯時間（比台北時間慢 16 小時）
     - ✅ 所有時間顯示都一致

## 預期結果

### ✅ 成功標準

1. **還原功能**
   - 不再出現「Backup file not found or unreadable」錯誤
   - 還原流程能正常完成

2. **時間顯示**
   - Restore History 的時間與 log 時間一致（只差時區轉換）
   - 所有時間顯示（Backups、Dashboard、Logs、Restore History、Schedules）都使用 WordPress 設定的時區
   - 切換時區後，所有時間顯示會自動調整

3. **下載功能**
   - 備份完成後點擊「Download backup」能正常下載
   - Backups 列表中的「Download Selected」功能正常

### ❌ 失敗情況

如果出現以下情況，請記錄並回報：

1. 還原時出現「Backup file not found or unreadable」錯誤
2. Restore History 的時間與 log 時間不一致（超過 1 分鐘差距）
3. 切換時區後，時間顯示沒有變化
4. 下載備份時出現白畫面或錯誤

## 測試記錄範本

```
測試日期：2025-12-03
測試環境：WordPress 6.x, PHP 7.4+

階段 1：台北時區 (UTC+8)
- 備份建立時間：14:30:00
- Restore History 時間：14:30:05 ✅
- Log 時間：2025-12-03T14:30:05+08:00 ✅
- 時間一致性：✅ 所有位置時間一致

階段 2：倫敦時區 (UTC+0)
- Restore History 時間：06:30:05 ✅（比台北慢 8 小時）
- 時間一致性：✅ 所有位置時間一致

階段 3：洛杉磯時區 (UTC-8)
- Restore History 時間：22:30:05（前一天）✅（比台北慢 16 小時）
- 時間一致性：✅ 所有位置時間一致

問題記錄：
- 無
```


