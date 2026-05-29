# Bug 調查報告 — 第四輪 QA：還原完成後 token 撤銷導致 poll 無法收斂

| 項目 | 內容 |
|------|------|
| **Bug ID** | **BUG-QA-007**（建議） |
| **優先** | **P2**（阻擋 E2E exit 0；引擎本身已 100%） |
| **版本** | 2.7.268 |
| **QA 輪次** | 第四輪（2026-05-25） |
| **前置** | BUG-QA-006 nopriv 修復已部署 |
| **證據** | `docs/qa-evidence/qa-retest-2.7.268-fix-round4-2026-05-25/` |

---

## 1. 摘要

第四輪 **BUG-QA-006 修復有效**：DB 匯入後死 session + `restore_token` 可取得 JSON（非 `0`）；**poll #1** 見 `stage=restore-files progress=92`；磁碟 job **23 秒內 100% 完成**（**未使用 resume**）。

但還原完成時 `Restore_Token::revoke()` 刪除 token 檔後，**poll #2 起** `restore_tick`／`restore_job_status` 一律回 **`403 invalid_restore_token`**，E2E 的 `Wait-RestoreJob` 無法辨識完成，空轉至手動中止。

---

## 2. 時間軸（Job `rjb_20260528_183346_jmjqbh`）

| 時間 | 事件 |
|------|------|
| 02:35:24 | enqueue + `Restore token captured` |
| 02:35:39 | **poll #1** — `stage=restore-files progress=92` |
| 02:35:47 | 磁碟 job → **100% completed**（`updated_at`） |
| 02:35:48+ | **poll #2 起** — `(no job object yet)` + `invalid_restore_token` |
| — | token 檔已不存在（`revoke()`） |

---

## 3. 證據

### 3.1 死 session 對照（Pass — BUG-QA-006）

poll #1 前後，舊 cookies + token：

```json
{"success":true,"data":{"job":{"progress":92,"stage":"restore-files","message":"Restoring wp-content…"}}}
HTTP:200
```

檔案：`heavy-b1-e2e/manual-status-dead-session.txt`

### 3.2 完成後 poll（Fail — 本 Bug）

```text
restore_tick error: {"code":"invalid_restore_token",...}
restore poll #N (no job object yet)
```

檔案：`heavy-b1-e2e/run.log`（自 poll #2）

### 3.3 引擎終態（Pass）

```json
"stage": "done",
"progress": 100,
"completed": true,
"message": "Restore completed successfully."
```

檔案：`heavy-b1-e2e/job-meta-completed.json`

### 3.4 BUG-QA-003

```text
MEDIA_PATHS_RECONCILE_DONE {"job_id":"rjb_20260528_183346_jmjqbh",...}
```

檔案：`media-paths-log.txt`

---

## 4. 根因

| 項目 | 說明 |
|------|------|
| 觸發 | 還原成功收尾呼叫 `Restore_Token::revoke()` |
| 機制 | `verify_restore_progress_request()` 未登入且 token 無效 → **403**，不進入 `job_status` 讀取已完成 job／history |
| 影響 | 前端／E2E 在 token 撤銷後無法以 AJAX 確認 100%；`Wait-RestoreJob` 僅在 `st.data.job` 或 history success 時退出 |

**與 BUG-QA-006 的關係：** 006 解決 mid-restore dispatch；007 是 **post-complete 可觀測性** 缺口。

---

## 5. 建議修復

1. **`job_status`（nopriv）：** 當 token 已撤銷但 `job_id` 對應 job meta **`completed=true`** 時，回傳 **success + job/history**（唯讀，不洩漏進行中他 job）。
2. **或** 延後 `revoke()` 至前端確認／TTL 自然過期。
3. **E2E（過渡）：** `Wait-RestoreJob` 在連續 `invalid_restore_token` 時，以 WP-CLI 讀 job meta 或 re-login 查 history。

---

## 6. QA 判定

| 面向 | 判定 |
|------|------|
| 大站還原引擎 100% 無 resume | **Pass** |
| BUG-QA-006 nopriv mid-restore | **Pass** |
| BUG-QA-003 日誌 | **Pass** |
| 嚴格 E2E exit 0／poll #2+ 持續 stage | **Fail**（本 Bug） |

---

*供開發 Agent 第四輪後續修復或 E2E 調整。*
