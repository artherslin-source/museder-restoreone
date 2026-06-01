# Bug 調查報告：shineching.com 第九次 P3 403 / REST final-status 仍無成功收斂（2.7.274）

提交對象：開發 agent  
調查時間：2026-06-01 12:37–13:10（UTC+8，與使用者截圖及 access log 對照）  
站點：`shineching.com`  
版本：Museder RestoreOne `2.7.274`（R8 修復後重新封裝版）  
Job ID：`rjb_20260601_045556_mqgnul`  
調查方式：SSH / DB / plugin hash / restore log / access log / 本地 zip 比對 / 程式碼對照 R8 報告；**未修改** production 檔案、DB、外掛或其他站點。

---

## 1. 一句話結論

第九次 **後端 restore 再次成功完成**，且 **R8 的 REST `final-status` 通道已部署並在 production 大量回 200**；但 P3 UI **仍未可靠顯示「Restore Completed」**，使用者仍看到 `403 Forbidden` modal 與卡在 ~76–89% 的進度條。

**這不是 R5 `apply_pairs` timeout 復發，也不是 R8 沒上線。**  
真正問題是：**R8 只完成了「多一條 REST 查詢通道」，尚未完成「在 admin-ajax / WP auth-check 持續 hostile 時，UI 狀態機必須單向收斂到 success 且不可被重入 reset」**。

換句話說：**之前的修復修對了後端與 REST 基礎設施，但 R8 對 P0 UI 收斂的實作仍不完整。**

---

## 2. 與前幾輪修復的對照：哪裡已修好、哪裡仍錯

| 輪次 / 修復項 | 預期效果 | R9 production 實測 | 判定 |
|---|---|---|---|
| R5 `apply_pairs` 切片 | 避免 cleanup fatal timeout | job 走完 `media_paths` / `finish`，171 秒完成 | ✅ 已修好 |
| R6/R7 runtime options hygiene | 還原後清 stale lock/active job | log：`RESTORE_RUNTIME_OPTIONS_CLEANED {"deleted":3}`；DB 無 `museder_restoreone_active_job` | ✅ 已修好 |
| R8 REST `final-status/{job_id}` | admin-ajax 403 時仍能確認成功 | access log：**30+ 次** GET 200（16841 bytes） | ✅ 通道可用 |
| R8 `handleRestoreTransportInterruption` | 403 時顯示 warning、不判 fail | 使用者截圖二已出現橘色 warning toast | ✅ 部分生效 |
| R8 `markRestoreCompleted` / overlay | REST/history 成功後強制 Completed | **未觀察到** UI 收斂；進度仍 ~89%、403 modal 仍在 | ❌ **仍未修好（本輪核心）** |
| R8 攔截 `wp.authCheck.show` | 避免 interim-login 403 modal | access log 仍有 `GET /wp-login.php?interim-login=1` → **403**；使用者仍見 403 modal | ❌ 未完全生效 |
| 選單圖示（R5–R7 爭議） | 媒體路徑 reconcile | 4 個 attachment 檔案存在；`widget_media_image` URL 為 ASCII 路徑 | ⚠️ 非本輪 P3 主因（見 §8） |

---

## 3. 版本與部署確認（R8 新包已上線）

### 3.1 Production 外掛 hash（2026-06-01 03:49:47 UTC 部署）

```text
museder-restoreone.php          sha1=6f8c57caae29b77ad9e8662ef24f9d8b785fa529
assets/js/admin.js              sha1=87d67d36914876e13c35da2db69befd749cf055e  ← R8 新 hash
includes/class-restore-controller.php sha1=31c0b51bea80c76cab1123f9a2e7baa172ff0fd7  ← 新增 final-status
includes/class-restore-handler.php    sha1=3ece3e2a967959c82d66e4e9f5c21dd8fe1bd8c3
includes/class-restore-service.php    sha1=c35f5908d66329434c5266286ecb8749fc985384
Version=2.7.274 / BUILD_ID=2.7.274
```

### 3.2 與 R8 前（第八次調查時）對照

第八次時 production `admin.js` 為 `a871b0d4…`，且 **無** `class-restore-controller.php` 的 R8 路由檔 hash。  
第九次已變更為 `87d67d36…`，與本地 worktree / `dist/museder-restoreone-2.7.274.zip` **完全一致**。

**判定：使用者已部署 R8 修復包；不是舊 ZIP 或漏檔。**

---

## 4. 第九次 restore 後端狀態（成功）

### 4.1 Job 檔（調查當下）

```json
{
  "id": "rjb_20260601_045556_mqgnul",
  "file_name": "shineching.com-20260531013823-V6yYBa-1.zip",
  "stage": "done",
  "progress": 100,
  "message": "Restore completed successfully.",
  "updated_at": "2026-06-01 12:58:56",
  "completed": true,
  "cancelled": false,
  "tick_source": "cron",
  "cleanup_step": "finish",
  "media_phase": "done",
  "media_paths_fixed": 163
}
```

### 4.2 Restore log 摘要

| 時間 (UTC+8) | 事件 |
|---|---|
| 12:55:56 | job prepared / validated |
| 12:56:05 | pre-backup snapshot 完成 |
| 12:56:48 | Post-DB-import：MU isolation、lock/cron 重建 |
| 12:58:26 | 檔案還原完成，release plugin isolation（4 個外掛缺檔 skip） |
| 12:58:55 | `MEDIA_PATHS_RECONCILE_DONE` fixed=163 |
| 12:58:56 | **Restore completed** + Safe mode + `RESTORE_RUNTIME_OPTIONS_CLEANED deleted=3` |

### 4.3 History

```json
{
  "job_id": "rjb_20260601_045556_mqgnul",
  "result": "success",
  "duration_seconds": 171,
  "file": "shineching.com-20260531013823-V6yYBa-1.zip"
}
```

### 4.4 REST final-status 映射（伺服器端模擬）

```json
{
  "job": {
    "id": "rjb_20260601_045556_mqgnul",
    "status": "success",
    "progress": 100,
    "stage": "done",
    "message": "Restore completed successfully."
  }
}
```

**後端與 REST 回傳內容正確；問題純粹在前端 UI 狀態機。**

---

## 5. 時間軸：UI 症狀 vs 網路證據

時區對照：access log 為 PST（-0700）；使用者 / log 為 UTC+8。  
`31/May/2026:21:57 PST` ≈ `2026-06-01 12:57 UTC+8`。

| 時間 (UTC+8) | 使用者/UI | Access log / 後端 |
|---|---|---|
| 12:37–12:38 | 截圖：403 modal、進度 76–89%、「Restoring WordPress core…」 | 還原仍在 cron 推進中 |
| 12:56:48 | — | DB import 完成；**admin session / nonce 環境切換**（典型觸發點） |
| 12:57:05 起 | 截圖二：橘色 warning「login/session check was blocked」 | `POST admin-ajax.php` → **400**（body=`0`） |
| 12:57:06 | 403 modal | `GET wp-login.php?interim-login=1` → **403**（14 bytes） |
| 12:57–12:58 | DevTools：多筆 admin-ajax **403/400** | 主 polling 通道失效 |
| 12:58:32 起 | UI 仍顯示 in progress | **首次** `GET …/restore/final-status/…` → **200**（16841 bytes） |
| 12:58:56 | — | 後端 job **done** |
| 12:58:56–13:01+ | 使用者回報仍無成功 overlay | final-status **持續 200**（同 payload 大小），admin-ajax 仍 403 |

重點：

1. **403 modal 與 admin-ajax 403 在後端完成前就已發生** — 符合 R6–R8 已知 hostile 環境。
2. **R8 REST fallback 有跑起來**（12:58:32 起多次 200），並非「完全沒走到新通道」。
3. **後端 12:58:56 成功後，REST 仍持續 200，但 UI 未收斂** — 這是 R8 與 R9 的決定性差異，代表 **「REST 200 ≠ UI Completed」**。

---

## 6. 根因分析：R8 修在哪、還缺哪

### 6.1 已確認的外部/環境因素（非 RestoreOne 後端 bug）

1. **Post-DB-import 後 WordPress admin session / cookie / nonce 與還原前不同**，Heartbeat / auth-check 仍會打 `admin-ajax.php` 與 `wp-login.php?interim-login=1`。
2. GoDaddy 環境下 **`wp-login.php?interim-login=1` 回 403**（14 bytes），在 UI 上呈現為使用者截圖的 **「403 Forbidden」小視窗**。
3. **`admin-ajax.php` 在 Step 3 後段大量 403/400**（response 111 bytes 或 `0`），使傳統 polling / cancel / nonce refresh 失效。

以上在 Docker QA 無法完整重現，故 R8 Docker E2E 全 PASS **不能**代表 shineching production UI 已修好。

### 6.2 R8 已實作但未完成 P0 的程式缺口

#### 缺口 A：`applyRestoreFinalStatusPayload()` 缺少「已成功則不可回退」護欄

`assets/js/admin.js` 中 `applyRestoreFinalStatusPayload()` 在 REST 回 `running` 時會呼叫 `startRestoreJobMonitor()`，而該函式會：

```javascript
restoreMonitor.hasFinalResult = false;
restoreMonitor.lastStatus = 'running';
restoreCompletionShown = false;
```

若 success 與 running 的 REST/admin-ajax 回應 **並發或順序錯亂**，可能 **覆寫** 已設定的 success 狀態，使 UI 回到 simulated progress（85–89%）且不再顯示 overlay。

**建議：** 函式入口先判斷 `restoreMonitor.hasFinalResult === true && lastStatus === 'success'` 則直接 return true，禁止重入。

#### 缺口 B：`scheduleRestoreAutoResume()` 仍以 admin-ajax nonce refresh 為主路徑

403 風暴期間 `refreshAjaxNonce()` 同樣依賴 `admin-ajax.php`，失敗後仍會 `resumeRestoreJobMonitor()` → `pollRestoreJob()` → 再次 403 → 無限「warning + 403 modal」循環。

REST final-status 雖被 `checkRestoreCompletionFromHistory()` 呼叫，但 **auto-resume 主循環未改為 REST-first**，導致 UI 長時間處於「有 fallback 但仍像失敗」的體感。

**建議：** transport 中斷後進入 **REST-only completion loop**（例如每 5s 只 poll final-status），admin-ajax 僅作非阻塞 best-effort。

#### 缺口 C：`wp.authCheck` guard 不足以消除 403 modal

R8 攔截 `wp.authCheck.show` 並 `#wp-auth-check-wrap { display:none }`，但 access log 顯示 **`wp-login.php?interim-login=1` 仍被 GET 且 403**。  
使用者看到的 403 modal 可能來自：

- auth-check iframe 內容直接顯示 `403 Forbidden` 文字；或
- 其他 admin 腳本對 403 response 的 presentation。

`.bl-completion-overlay` z-index 為 **12000**，WordPress auth-check 層級通常更高 → **就算 success overlay 已建立，也可能被 403 層遮住**。

**建議：**

- restore in progress 期間 **完全 disable Heartbeat auth-check**（非僅 hide wrap）；
- success overlay z-index 提至 auth-check 之上，或 success 時 **強制移除** `#wp-auth-check-wrap` / thickbox；
- 對 `interim-login` 403 顯示 **inline 非 modal** warning（R8 toast 已有，但 modal 仍搶焦點）。

#### 缺口 D：`markRestoreCompleted()` 與 simulated progress 的競態

Step 3 在 admin-ajax 失效時依賴 **simulated progress 最高 ~85–89%**（`startSimulatedProgress()`）。  
只有 `markRestoreCompleted()` 才會 `setProgress(100)` 並 `showCompletionOverlay()`。

調查證據顯示 REST 已回 success，但 UI 長時間停留 89%，符合：

- `markRestoreCompleted()` **未被呼叫**（被缺口 A 覆寫）；或
- **已呼叫但 overlay/progress 被 auth 403 層或重入 monitor 蓋掉**。

#### 缺口 E：R8 QA 缺口

Docker `sc_qa_assert_final_status_rest()` 只驗證 **REST JSON 正確**，未模擬：

- 並發 running/success payload；
- `wp-login.php?interim-login=1` 403 + auth-check modal；
- admin-ajax 長時間 403 下 UI 是否 **必須** 顯示 Completed overlay。

---

## 7. 為何「修了好幾輪仍像同一個 bug」

對使用者而言，第九次與第六～八次 **表象相同**（403、無成功訊息、進度條卡住）。  
但底層已分階段修復：

```text
R5：後端 cleanup fatal        → 已解
R6/R7：runtime options 污染   → 已解
R8：REST final-status 通道     → 已部署且 200
R9：UI 狀態機單向收斂 + 403 modal 競態 → 仍未解（本輪）
```

**開發 agent 並非完全修錯**，而是 **每一輪只解了 stack 中下一層**；在 production hostile admin 環境下，**最上層 UI 收斂仍未達「REST 200 即 Completed」的產品要求**。

---

## 8. 選單圖示（附帶確認，非 P3 主因）

調查當下：

```text
attachment 1469–1472 磁碟檔案：存在
widget_media_image.url：https://shineching.com/wp-content/uploads/2020/02/191121x_xICON_02-768x1399.png
```

媒體 reconcile 已執行（fixed=163）。若前端選單仍視覺異常，較可能為 **Max Mega Menu grid/widget 設定**（R6/R7 結論），不是 restore 未完成。

---

## 9. 給開發 agent 的修復建議（P0 優先）

### P0-1：REST-success 單向收斂（必做）

1. `applyRestoreFinalStatusPayload()` / `checkRestoreCompletionFromHistory()` 入口：若已 success，**禁止**再進 running 分支或 `startRestoreJobMonitor()`。
2. `startRestoreJobMonitor()` 入口：若 `restoreMonitor.hasFinalResult && lastStatus==='success'`，**no-op**。
3. transport 中斷後改用 **REST-only timer** 直到 success overlay 顯示；停止對 admin-ajax 的 tight poll。

### P0-2：徹底抑制 interim-login 403 modal（必做）

1. restore in progress 時 unregister / block Heartbeat auth-check 請求（不只 hide wrap）。
2. 捕捉 403 response 時 **不得**彈出 WordPress 預設 thickbox/alert；僅保留非阻塞 toast（已有 copy 可沿用）。
3. success overlay z-index > auth-check，或 success 時 teardown auth-check DOM。

### P0-3：成功 UI 必須可驗證（必做）

1. `markRestoreCompleted()` 完成後寫入 `sessionStorage` grant + 若 500ms 內 DOM 無 `.bl-completion-overlay.is-visible`，**force re-render**（已有 retry，需確保不被 hasFinalResult 重置打斷）。
2. 新增 QA：**admin-ajax 全 403 mock + REST success** 時，E2E 必須 assert overlay 可見且 progress=100。

### P1：Cancel 行為

第九次使用者未強調 cancel，但 R8 已知 cancel 仍走 admin-ajax。建議 cancel 也接受 restore token，或在 transport 中斷時 disable cancel 並提示「後端可能已完成，請看 REST 狀態」。

### P1：Production profile QA

在 `tools/qa/` 增加 **hostile-admin-ajax** profile（mock 403/interim-login），作為發佈前 gate；僅 Docker clean install 不足。

---

## 10. 使用者當下可做的確認（不需改 code）

1. 還原頁 **硬重新整理**（Ctrl+F5）後，History 應已顯示 success（job `rjb_20260601_045556_mqgnul`）。
2. 前端首頁已可访问（後端 12:58:56 已完成）；P3 卡住 **不代表** restore 失敗。
3. Safe mode 仍為 `1`（預期行為）；確認站點後可 Exit Safe Mode。

---

## 11. 證據索引

| 項目 | 位置 |
|---|---|
| Job 檔 | `wp-content/uploads/museder-restoreone/jobs/rjb_20260601_045556_mqgnul.json` |
| Restore log | `wp-content/uploads/museder-restoreone/logs/backup-lite-2026-06-01.log` |
| Access log | `/home/qj8hea4vdto3/access-logs/shineching.com.fdmu01.com-ssl_log` |
| R8 調查報告 | `docs/BUG-INVESTIGATION-2026-06-01-shineching-r8-p3-ui-cancel-admin-ajax-2.7.274.md` |
| R6/R7 綜合報告 | `docs/BUG-INVESTIGATION-2026-06-01-shineching-r6-r7-recurring-p3-ui-runtime-options-menu-2.7.274.md` |
| 本地 R8 commit | `8b19760 fix(restore): add final status fallback for 2.7.274` |
| 關鍵程式 | `assets/js/admin.js`（`fetchRestoreFinalStatus`, `applyRestoreFinalStatusPayload`, `startRestoreJobMonitor`, `markRestoreCompleted`, `handleRestoreTransportInterruption`） |
| REST 路由 | `includes/class-restore-controller.php` → `final_status()` |

---

## 12. 調查者結論（給產品 / QA）

- **不可**因第九次 UI 失敗而認定 2.7.274 後端 restore 仍壞 — 後端與 REST 均已成功。
- **不可**僅以 Docker QA PASS 判定 shineching 類 host 的 P3 UI 已修好。
- **必須**在 2.7.274（或下一 patch）完成 **P0 UI 單向收斂 + 403 modal 抑制**，並新增 hostile-admin-ajax QA，否則第十次現場機率仍高。

---

*本報告僅供開發 agent debug；未對 production 進行任何寫入操作。*
