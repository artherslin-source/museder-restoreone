# QA 缺陷修復說明 — 2.7.268（2026-05-28）

**版號：** 2.7.268（不 bump）  
**依據：** `docs/BUG-FIX-BACKLOG-2.7.268-QA-2026-05-28.md`

---

## BUG-QA-001／002 — 大站還原卡 90%、`job_status` 為 null

### 根因（第二輪重測後確認）

1. **DB 匯入後 WP nonce／session 失效（主因）：** NDJSON 會重建 `wp_usermeta` 等，瀏覽器仍送舊 `nonce` → `verify_ajax_request()` 回 **403 `invalid_nonce`**。E2E 腳本把 `success:false` 當成「no job」，表現為 **poll #2 起長期 `job: null`**。`restore_tick` 同樣失敗 → **`last_tick` 只 +6 秒**後停滯。  
2. **檔案型 restore token 已存在但未接上：** `Museder_Restoreone_Restore_Token` 設計為匯入後備援，先前未用於 `restore_tick`／`job_status`，前端也未傳 `restore_token`。  
3. **次要：** job meta 雙路徑、同 slice 多段 drain（第一輪已加，不足以單獨修復）。

### 修復（第二輪 2026-05-29）

| 項目 | 實作 |
|------|------|
| **Token 驗證** | `Museder_Restoreone_UI::verify_restore_progress_request()` — token 或 nonce |
| **AJAX** | `restore_tick`、`restore_job_status` 改用上述驗證（不再僅依賴 nonce） |
| **Enqueue** | 回傳頂層 `restore_token`；`admin.js` 存 `activeRestoreToken` 並附於 poll/tick |
| **E2E** | `run-heavy-site-full-e2e-browser.ps1` 傳 `restore_token` |
| **Tick 預算** | 單次 `restore_tick` 在 slice 秒數內迴圈多次 `process_job_slice` |
| **穩定路徑** | job meta 雙寫 + temp `restore-job.meta.json`；token 檔用 canonical temp |

### QA 重測通過標準

- `run-heavy-site-full-e2e-browser.ps1 -SkipBackup -BackupZip '…'` **無需** `resume-restore-browser.ps1` 即 100%。  
- 輪詢期間 `restore_job_status` **穩定** 回 `job` 物件（`stage`/`progress` 會變）。  
- 訊息在進入檔案還原後應變為「Restoring wp-content…」等（非長期停在 Database import completed）。

---

## BUG-QA-003 — 日誌無 `MEDIA_PATHS_RECONCILE_DONE`

### 根因

reconcile 在 `uploads_missing` 或 filter 跳過時直接標記 `done`，未經 `apply_pairs`，故無日誌；或 `fixed=0` 時 QA 僅 grep 訊息字串而 reconcile 已完成但未記錄。

### 修復

`log_reconcile_done()` 單一入口，於所有完成路徑寫入 `MEDIA_PATHS_RECONCILE_DONE`（context 含 `reason`、`fixed`、`scanned`）。

---

## BUG-QA-005 — E2E 腳本

`SkipBackup` 未帶 `-BackupZip` 時改為**明確錯誤**，不再用易被 PowerShell 破壞的 `wp eval glob`。

---

## 未含

- **GAP-QA-004：** 需 QA 提供 sunpower zip。  
- **musederlabs Step1：** 另案。

---

## BUG-QA-006 — DB 匯入後 auth cookie 失效，nopriv AJAX 未 dispatch

### 根因（第三輪 QA 確認）

1. NDJSON 匯入後 **WordPress auth cookie 失效** → `is_user_logged_in()` 為 false。  
2. `restore_job_status`／`restore_tick` 僅註冊 **`wp_ajax_*`**；未登入時 core 回 **`0`**，handler **未執行**。  
3. 第二輪 token 接在 handler **內部**，無法解決 dispatch 問題。  
4. CLI `Restore_Token::verify()` 為 true；**重登入 + 同一 token** 可正常 poll。

### 修復（第四輪 2026-05-25）

| 項目 | 實作 |
|------|------|
| **nopriv 掛鉤** | `restore_job_status`、`restore_tick`、`trigger_restore_job` 新增 `wp_ajax_nopriv_*` |
| **授權** | `verify_restore_progress_request()`：未登入時 **僅** 接受有效 `restore_token`（403 `invalid_restore_token`） |
| **trigger** | `trigger_restore_job` 改用與 tick/status 相同驗證 |
| **前端** | `trigger_restore_job`、history fallback poll 附 `restore_token` |

### QA 重測通過標準

同 BUG-QA-001；另：死 session + token 的 `restore_job_status` 應回 **JSON**（非 `0`）。

---

## BUG-QA-007 — 完成後 `revoke()` 導致 poll 403

### 根因（第四輪 QA 確認）

還原成功時 `Restore_Token::revoke()` 刪除 token 檔；poll #2 起 `verify_restore_progress_request()` 回 **403 `invalid_restore_token`**，E2E 無法收斂 exit 0（引擎已 100%）。

### 修復（第五輪 2026-05-25）

| 項目 | 實作 |
|------|------|
| **Post-complete grant** | `revoke()` 前將 `job_id` + `token_hash` 寫入 `museder_restoreone_restore_post_complete_access`（沿用原 TTL） |
| **唯讀驗證** | `Restore_Token::verify_post_complete_read()` — 同一 raw token + `completed` job meta |
| **授權鏈** | `restore_post_complete_read_is_valid()` 接入 `verify_restore_progress_request()` |
| **QA 清理** | `clear-b1-restore-state.php` 清除 post-complete option |

### QA 重測通過標準

- E2E `run-heavy-site-full-e2e-browser.ps1` **exit 0**
- poll #2 起可見 `status=success`／`progress=100`（非 `invalid_restore_token` 空轉）
