# Bug 調查報告 — 第三輪 QA Fail：restore_token 接線後 AJAX 仍回 `0`

| 項目 | 內容 |
|------|------|
| **Bug ID** | **BUG-QA-006**（建議） |
| **優先** | **P1** |
| **版本** | 2.7.268 |
| **QA 輪次** | 第三輪（2026-05-25） |
| **依據** | `docs/QA-HANDOFF-2.7.268-FIX-TO-QA-AGENT.md` |
| **證據** | `docs/qa-evidence/qa-retest-2.7.268-fix-round3-2026-05-25/` |

---

## 1. 摘要

第二輪開發已將 **file-backed `restore_token`** 接入 `restore_tick`／`restore_job_status` 與 E2E 腳本；第三輪 QA 確認 **enqueue 可捕獲 token（len=64）**，且 **CLI 上 `Restore_Token::verify()` 為 true**。

但大站 E2E 在 DB 匯入後 **poll 仍全為 `(no job object yet)`**。根因不是 token 雜湊錯誤，而是 **WordPress 在未登入狀態下不會 dispatch `wp_ajax_*` handler**——DB 匯入後 **auth cookie 失效**，`admin-ajax.php` 在 handler 執行前即回 **`0`（HTTP 400）**，`verify_restore_progress_request()` **從未被呼叫**。

**重新登入後**以同一 `restore_token` 呼叫 `restore_job_status`，**可正常回傳 job**（progress 91%、`Restoring wp-content…`）。

---

## 2. 重現步驟

1. 部署 §4.3 七檔至 QA-B1（8083）。
2. 清除殘留 active job（`tools/qa/clear-b1-restore-state.php` 或 handoff §5.1）。
3. 執行：
   ```powershell
   powershell -NoProfile -File tools\qa\run-heavy-site-full-e2e-browser.ps1 `
     -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'
   ```
4. 觀察 log：`Restore token captured` 後 **poll #1 起即 `(no job object yet)`**。

**Job 範例：** `rjb_20260528_181405_lo5twz`

---

## 3. 觀測證據

### 3.1 E2E log（摘錄）

```text
[2026-05-29 02:15:44] Restore token captured (len=64)
[2026-05-29 02:15:44] Restore job started: rjb_20260528_181405_lo5twz
[2026-05-29 02:15:57] restore poll #1 (no job object yet)
```

### 3.2 失效 session + token（模擬 E2E）

使用 enqueue 當下 cookies + `restore_token`：

```text
HTTP:400
0
```

檔案：`heavy-b1-e2e/manual-status-with-cookies.txt`

### 3.3 重新登入 + 同一 token（對照）

```json
{"success":true,"data":{"job":{"id":"rjb_20260528_181405_lo5twz","status":"running","progress":91,"message":"Restoring wp-content…","stage":"restore-files"}}}
```

檔案：`heavy-b1-e2e/manual-status-fresh-login.txt`

### 3.4 CLI token 驗證（通過）

```json
{"verify":true,"hash_match":true,"job_id":"rjb_20260528_181405_lo5twz"}
```

檔案：`heavy-b1-e2e/token-verify-cli.json`

Token 檔：`uploads/museder-restoreone/temp/.restore-auth-token` 存在且 `job_id` 綁定正確。

### 3.5 磁碟 job（E2E 停後）

| 欄位 | 值 |
|------|-----|
| stage | `restore-files` |
| progress | 91 |
| message | `Restoring wp-content…`（**優於第二輪**長期 `Database import completed.`） |
| last_tick | +1s 後停滯（無後續 tick） |
| zip_index | 13553 / 29996（部分檔案已解壓） |

檔案：`heavy-b1-e2e/job-meta-stuck-91.json`

---

## 4. 根因分析

| 層級 | 說明 |
|------|------|
| **觸發** | NDJSON DB 匯入覆寫 `wp_usermeta` → 瀏覽器 **WordPress auth cookie 失效** |
| **機制** | `restore_job_status`／`restore_tick` 僅註冊 **`wp_ajax_*`**（需已登入）；**無 `wp_ajax_nopriv_*`** |
| **結果** | 未登入請求在 WordPress core 層被拒，回 **`0`**；外掛內 token 驗證邏輯**未執行** |
| **第二輪修復缺口** | token 接在 handler **內部**，但未解決 **handler 能否被 dispatch** |
| **E2E 缺口** | 腳本僅在開頭 `Login-WpAdmin` 一次，DB 匯入後未 re-login |

**補充：** `restore_tick` 在 DB 匯入中途可能回 HTML「database tables unavailable」（`ajax-museder_restoreone_restore_tick-021546.txt`），屬過渡期現象；主因仍是 post-import session 失效。

---

## 5. 建議修復（交開發）

### 5.1 產品（建議 P1）

1. 為 **`museder_restoreone_restore_job_status`**、**`museder_restoreone_restore_tick`** 新增 **`wp_ajax_nopriv_*`** 掛鉤，或統一入口：
   - **僅**在 `restore_progress_token_is_valid( $job_id ) === true` 時允許；
   - **不得**在 nopriv 路徑使用僅 nonce 的寬鬆授權；
   - token 無效時維持 403／0，不洩漏 job 資訊。
2. `job_status`／`restore_tick` 在 token 有效時 **跳過** `current_user_can`（目前已無 `ensure_permission`，但 core 未 dispatch 才是 blocker）。
3. 前端 `admin.js`：poll 收到 `0` 或 `invalid_nonce` 時，若持有 `activeRestoreToken` 仍應走 nopriv 路徑（修復後）或提示 re-login。

### 5.2 E2E（可選 P2）

- `Wait-RestoreJob`：若連續 N 次 `(no job object yet)` 且 body 為 `0`，**重新 `Login-WpAdmin`** 後重試（過渡 QA 用；**不能**替代產品 nopriv 修復）。

### 5.3 驗證標準（第三輪 Pass 重跑）

- **不**使用 `resume-restore-browser.ps1`
- poll #2 起含 `stage=`／`progress=`
- 無 resume 即 100%
- grep `MEDIA_PATHS_RECONCILE_DONE`

---

## 6. 與已知 Bug 對照

| Bug | 第三輪狀態 |
|-----|-----------|
| BUG-QA-001 | **仍 Fail**（E2E 未 100%）；部分改善（訊息進入 `Restoring wp-content…`） |
| BUG-QA-002 | **未測** |
| BUG-QA-003 | **未驗證**（還原未完成） |
| BUG-QA-005 | **Pass** |
| **BUG-QA-006** | **本報告** — session + nopriv dispatch |

---

## 7. 相關程式位置

- `includes/class-restore-handler.php` — `add_action( 'wp_ajax_…restore_job_status' )`（無 nopriv）
- `includes/class-ui.php` — `verify_restore_progress_request()`、`restore_progress_token_is_valid()`
- `includes/class-restore-token.php` — file-backed token（verify 正常）
- `tools/qa/run-heavy-site-full-e2e-browser.ps1` — token 已附於 AJAX，但 cookies 死後無效

---

*報告產生：2026-05-29。供開發 Agent debug 使用。*
