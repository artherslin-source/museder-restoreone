# QA 重測報告 — 2.7.268 第四輪（BUG-QA-006 nopriv 修復）

| 項目 | 內容 |
|------|------|
| **測試者** | Cursor Agent |
| **日期** | 2026-05-29 |
| **外掛版本** | **2.7.268**（未 bump） |
| **部署** | `docker cp` 三檔：`class-restore-handler.php`、`class-ui.php`、`admin.js` |
| **交接** | `docs/QA-HANDOFF-2.7.268-FIX-ROUND4-2026-05-25.md` |
| **前次** | 第三輪 Fail（BUG-QA-006） |
| **Bug 調查** | `docs/BUG-INVESTIGATION-2026-05-29-qa-round4-post-complete-token-revoke.md`（**BUG-QA-007**） |

**證據目錄：** `docs/qa-evidence/qa-retest-2.7.268-fix-round4-2026-05-25/`

---

## 1. 結果摘要

| 測試區塊 | 結果 | 說明 |
|----------|------|------|
| 部署 + 清 B1 lock/job | **Pass** | 含 `museder_restoreone_restore_lock` |
| **BUG-QA-006** nopriv + 死 session JSON | **Pass** | 非 `0`；HTTP 200 |
| **大站還原引擎 100%** | **Pass** | ~23s 完成；**未用 resume** |
| **E2E 腳本 exit 0** | **Fail** | poll #2 起 `invalid_restore_token` 空轉 |
| **BUG-QA-001 嚴格 poll 標準** | **Fail** | 僅 poll #1 有 stage；#2+ null |
| **BUG-QA-003** 日誌 | **Pass** | `MEDIA_PATHS_RECONCILE_DONE` |
| A→B 跨站 | **未執行** | E2E 未 Pass |
| sunpower | **Skip** | GAP-QA-004 |

### 總判定

**有條件 Pass（引擎／006／003）；嚴格 E2E 自動化 Fail。**

- **BUG-QA-006 修復有效**：DB 匯入後 dead session + token 可 poll/tick（第四輪核心目標）。
- **大站 5.14GB 同站還原已在首次 poll 週期內 100%**（job `rjb_20260528_183346_jmjqbh`）。
- **新缺口 BUG-QA-007**：完成後 `revoke()` 使後續 poll 403，E2E 無法收斂 exit 0。

**建議：** 可簽核 **還原引擎 + nopriv mid-restore**；**暫不**簽核 **E2E 腳本 Pass** 與發佈前自動化，待 007 或 E2E 調整。

---

## 2. 環境與測前

| 代號 | URL | 用途 |
|------|-----|------|
| QA-B1 | http://localhost:8083 | 大站 E2E |
| QA-A2 | http://localhost:8082 | 跨站（未測） |
| Clean | http://localhost:8080 | （本輪未重跑 Plugin Check） |

**測前：**
- `tools/qa/clear-b1-restore-state.php` 擴充：清 lock、revoke token、刪 `jobs/*.json`
- 重置 `run.log`；確認無殘留 E2E process
- 首次 E2E 因 **restore lock** 失敗 → 清 lock 後重跑

---

## 3. 大站 B1 E2E 明細

**指令：**

```powershell
powershell -NoProfile -File tools\qa\run-heavy-site-full-e2e-browser.ps1 `
  -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'
```

**未使用** `resume-restore-browser.ps1`。

### 3.1 對照 handoff §5

| 檢查點 | Pass 標準 | 實測 |
|--------|-----------|------|
| `Restore token captured` | 有 | **有**（len=64） |
| poll #2 起 stage/progress | 非長期 null | **Fail** — #1 有；#2+ null |
| 死 session + token → JSON | 非 `0` | **Pass**（見 manual-status-dead-session.txt） |
| 無 resume 100% | 完成 | **Pass**（磁碟 job，~23s） |
| 訊息 | Restoring wp-content… | **Pass**（poll #1） |
| `MEDIA_PATHS_RECONCILE_DONE` | grep 有 | **Pass** |
| E2E exit 0 | 0 | **Fail**（手動中止 poll 空轉） |

### 3.2 關鍵 log 摘錄

```text
[02:35:24] Restore token captured (len=64)
[02:35:39] restore poll #1 status=running stage=restore-files progress=92 msg=Restoring wp-content…
[02:35:48] restore poll #2 (no job object yet)
[02:35:55] restore_tick error: invalid_restore_token
…
```

Job 完成時間 **02:35:47**（與 poll #2 開始幾乎同時）→ token 已 revoke。

---

## 4. 修復項驗證

| Bug | 第四輪結果 |
|-----|-----------|
| **BUG-QA-006** | **Pass** — nopriv dispatch + token-only 授權 |
| **BUG-QA-001**（引擎） | **Pass** — 100% 無 resume |
| **BUG-QA-001**（E2E 嚴格） | **Fail** — poll 迴圈 |
| **BUG-QA-003** | **Pass** |
| **BUG-QA-007**（新） | **Fail** — 完成後 poll；見調查報告 |
| **BUG-QA-002** | Skip |
| **GAP-QA-004** | Skip |

---

## 5. 與第三輪對照

| 指標 | 第三輪 | 第四輪 |
|------|--------|--------|
| 死 session AJAX | `0` / HTTP 400 | **JSON 200** |
| poll #1 | 曾成功後全 null | **stage=restore-files 92%** |
| 引擎 100% | 否（卡 90%） | **是（23s）** |
| MEDIA_PATHS 日誌 | 無 | **有** |
| E2E exit 0 | Fail | **仍 Fail**（不同根因） |

---

## 6. 證據索引

| 檔案 | 內容 |
|------|------|
| `deploy.log` / `b1-cleanup.log` | 部署與清狀態 |
| `heavy-b1-e2e/run.log` | E2E 輪詢 |
| `heavy-b1-e2e/restore-enqueue.json` | token |
| `heavy-b1-e2e/manual-status-dead-session.txt` | 006 對照 Pass |
| `heavy-b1-e2e/job-meta-completed.json` | 100% job |
| `media-paths-log.txt` | BUG-QA-003 |

---

## 7. 給開發 Agent

1. **006 可視為已修** — 保留 nopriv + token-only 設計。
2. **007**：完成後仍須讓 client 能確認 success（job_status 讀 completed meta 或延後 revoke）。
3. **QA 工具**：`clear-b1-restore-state.php` 已擴充清 lock；建議納入 handoff 測前步驟。
4. **007 修復後** 重跑 E2E 至 exit 0，再跑 A2 + 封裝。

---

## 8. 簽核建議

| 面向 | 建議 |
|------|------|
| BUG-QA-006 / 大站還原引擎 | **有條件 Pass** |
| E2E 自動化／發佈 gate | **Hold**（007） |
| sunpower E2E | 仍 **未驗證** |

---

*報告產生：2026-05-29。*
