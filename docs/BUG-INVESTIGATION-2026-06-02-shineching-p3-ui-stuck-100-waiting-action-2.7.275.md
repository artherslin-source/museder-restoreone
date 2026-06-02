# Bug 調查報告：shineching.com P3 UI 卡在 100%（Waiting for action）

提交對象：開發 agent  
調查時間：2026-06-02（UTC+8）  
站點：`shineching.com`  
版本：Museder RestoreOne `2.7.275`  
現象：Step 3 顯示 `Restore in Progress`、進度條 `100%`，文字仍是 `Waiting for action...`，Restore History 顯示 `Running`（使用者截圖）

---

## 1. 一句話結論

這次是 **前端收斂邏輯問題（UI convergence bug）**，不是後端還原引擎未完成。  
現場主機已無 active restore job，最後一筆 restore-history 是 `success`；但 `admin.js` 的多路 fallback 判斷在特定情境下會把完成態誤判為 running，導致 Step 3 畫面卡在 100% + Waiting。

---

## 2. 現場證據（唯讀）

### 2.1 主機端狀態已完成（非進行中）

SSH 唯讀查核（`fdmu01.com`）：

- plugin 版本：`2.7.275`
- active job option：空值（無進行中 job）
- `jobs/` 最新檔：`rjb_20260602_093248_i45juz.json`
- `restore-history.json` 最新：`result=success`、`restore_duration_seconds=257`
- `backup-lite-2026-06-02.log` 明確有：
  - `Restore completed. Active plugin list recorded for admin review.`
  - `RESTORE_RUNTIME_OPTIONS_CLEANED`

=> 後端已完成，UI 還卡住屬於前端狀態收斂失敗。

### 2.2 使用者截圖行為特徵

- Step 3 卡在 `Restore in Progress`
- 進度條 `100%`
- 文案 `Waiting for action...`
- DevTools Network 出現大量輪詢請求（含 301/200 混合）

這與前端 fallback 在 auth/transport 不穩時的行為一致（見第 4 節）。

---

## 3. 影響範圍

- 主要影響：Restore Center 的 Step 3 UI 終態呈現
- 不直接影響：後端 restore engine 實際完成與資料落地
- 風險：使用者誤判還原未完成，可能重複觸發、手動中止或進行不必要操作

---

## 4. 根因候選（按信心排序）

## A. 歷史匹配時間窗寫死 600 秒 / 300 秒，長任務易失配（高信心）

`assets/js/admin.js` 多處以固定時間窗判斷 history 是否屬於同一 job：

- `< 600`：
  - `findMatchingRestoreHistory()`  
  - `checkRestoreCompletionFromHistory()`（fallback）  
  - `pollRestoreJob()` 的 job-not-found 分支
- `< 300`：
  - 404 fallback
  - page-load recent history 判斷

當 restore 執行時間超過這些閾值（例如你截圖內看到 `13m 06s`），前端即使拿到 `history=success` 也可能被判定「不匹配」，進而持續保持 running UI。

關鍵代碼位置：

- `assets/js/admin.js`：`2567`, `2835`, `3620`, `4174`, `4298`, `5697`, `5715`

---

## B. `payload.job || payload` 會把「無 job 的包裝 payload」誤當 job 物件（高信心）

多處有：

```js
var job = normalizeRestoreJobPayload(payload.job || payload);
```

如果後端回的是 `{ job: null, history: [...] }`，這裡會把整個 `payload` 當成 job。  
後續 `if (!job)` 分支不會進，會造成錯誤流程（例如無法正確走 history-only completion 分支）。

關鍵代碼位置：

- `assets/js/admin.js`：`2587`, `2759`, `3591`, `5982`

---

## C. Restore 頁同時載入兩套控制器，狀態機複雜且可能互相干擾（中高信心）

目前 Restore 頁：

- 全域會載入 `assets/js/admin.js`（`includes/class-ui.php`）
- Restore 頁又額外載入 `assets/js/restore.js`（`museder-restoreone.php`）

兩套都在處理 Step3/輪詢/狀態，雖然主畫面主要由 `admin.js` 控制，但雙控制器並存增加 race condition 與狀態覆寫風險。

關鍵代碼位置：

- `includes/class-ui.php`：`museder-restoreone-admin` enqueue
- `museder-restoreone.php`：`museder-restoreone-restore` enqueue

---

## D. admin-ajax 運輸層異常（401/403/404/0/301）時，UI 以「保守 running」策略退化，缺少強制終態收斂（中信心）

`admin.js` 的設計傾向「避免誤判失敗」，所以 transport 出問題時會 pause + retry + history fallback。  
若 fallback 再被 A/B 條件卡住，UI 就可能長時間停在 running。

關鍵代碼區：

- `handleRestoreTransportInterruption()`
- `checkRestoreCompletionFromHistory()`
- `pollRestoreJob()` error branches

---

## 5. 為何會出現「100% + Waiting for action」

`setProgress(percent, message, done)` 的顯示邏輯是：

- `percent=100` 會把 bar 拉到 100
- 但只要 `done=false` 且 `message` 不可靠/空值，文字可落到 `Awaiting/Waiting` 路徑

因此會出現使用者看到的矛盾畫面：**進度 100%，但狀態仍非完成**。

關鍵代碼位置：

- `assets/js/admin.js`：`setProgress()`（約 `5039` 起）

---

## 6. 可重現路徑（建議給開發 agent）

1. 用大備份檔讓 restore 超過 10 分鐘（>600 秒）
2. 在中後段製造輪詢不穩（例如 nonce 過期或 admin-ajax 間歇錯誤）
3. 觀察：
   - 後端 `jobs/*.json` 已 `stage=done`
   - `restore-history.json` 已 `result=success`
   - UI 仍停在 running / 100% / waiting

---

## 7. 建議的 debug 計畫（可直接執行）

1. **先加前端診斷 log（必要）**
   - 在 `applyRestoreFinalStatusPayload()`、`checkRestoreCompletionFromHistory()`、`pollRestoreJob()` 打印：
     - `jobId`, `jobStartRaw`, `historyTimestamp`, `deltaSec`
     - `payload.job` 是否為 null
     - `matches.length`
     - 最終分支 decision（completed/running/ignored）

2. **把時間窗常數化 + 放寬**
   - 用統一常數（例如 `RESTORE_HISTORY_MATCH_WINDOW_SEC = 7200`）
   - 暫時放大到 1~2 小時驗證是否收斂恢復

3. **修正 payload 解析**
   - 把 `payload.job || payload` 改為嚴格判斷：
     - `var job = payload && payload.job ? normalize... : null;`
   - 確保 history-only 回應會走 `if (!job)` 路徑

4. **定義終態優先規則**
   - 若任一路徑拿到：
     - `job.stage=done && progress>=100`
     - 或 history latest=`success`（且 job_id 明確命中）
   - 直接進 `markRestoreCompleted()`，不要再回 running

5. **評估是否單一化 Restore 頁控制器**
   - 明確規範 Restore 頁只由一個 JS 狀態機控制（降低競態）

---

## 8. 最小修復方向（不含實作）

### 必做（高優先）

- 移除硬編碼 `<600/<300` 的歷史匹配限制，改為可配置且更寬容窗口
- 修正 `payload.job || payload` 的錯誤 fallback

### 次要優化

- 在 UI 顯示「後端已完成，正在同步畫面」中介態，避免長時間 `Waiting for action`
- 將 completion 判斷收斂到單一函式，減少多分支重複邏輯

---

## 9. 給開發 agent 的驗收標準

修復後需同時滿足：

1. restore 實際完成後，Step3 在合理時間內收斂到 completed overlay  
2. 任務長於 10 分鐘仍能正確收斂（不再卡 running）
3. 即使 admin-ajax 曾 401/403/404/0，REST/history fallback 仍可落到 completed
4. 不會因 job cleanup（`job=null`）而失去完成判定
5. 既有失敗態（真正 failed）仍正確顯示 failed overlay（不可被 success 誤判）

---

## 10. 補充

本次調查為唯讀，不含 production 任何改動與終止操作。  
若要進一步做「可重現錄製版」調查，建議下輪同時保留：

- Browser HAR（含 XHR/fetch）
- console log（開啟上方第 7 節診斷訊息）
- 對應時間點的 `jobs/*.json` 與 `restore-history.json` 快照

