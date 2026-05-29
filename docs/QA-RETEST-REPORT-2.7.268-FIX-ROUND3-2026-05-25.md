# QA 重測報告 — 2.7.268 第三輪（restore_token 接線後）

| 項目 | 內容 |
|------|------|
| **測試者** | Cursor Agent |
| **日期** | 2026-05-29 |
| **外掛版本** | **2.7.268**（未 bump） |
| **部署** | 工作區 `docker cp` 七檔（未封裝 ZIP） |
| **交接文件** | `docs/QA-HANDOFF-2.7.268-FIX-TO-QA-AGENT.md` |
| **前次報告** | `docs/QA-RETEST-REPORT-2.7.268-FIX-2026-05-28.md`（第二輪 Fail） |
| **Bug 調查** | `docs/BUG-INVESTIGATION-2026-05-29-qa-round3-restore-token-nopriv.md`（**BUG-QA-006**） |

**證據目錄：** `docs/qa-evidence/qa-retest-2.7.268-fix-round3-2026-05-25/`

---

## 1. 結果摘要

| 測試區塊 | 結果 | 說明 |
|----------|------|------|
| 部署七檔（qa-b1／qa-a2／wordpress） | **Pass** | `deploy.log` |
| 清除 B1 殘留 job | **Pass** | `active_job_id=(empty)` |
| Plugin Check（8080） | **Pass** | 0 ERROR；1 WARNING |
| 乾淨 WP_DEBUG smoke | **Pass** | 6/6 |
| R-S2 bootstrap | **Pass** | `rjb_20260528_181231_zhfdev` → 100% |
| **大站 B1 同站還原（BUG-QA-001）** | **Fail** | token 已捕獲；poll 仍 null；未 100% |
| BUG-QA-003 日誌 | **未驗證** | 還原未完成；grep 無 `MEDIA_PATHS_RECONCILE_DONE` |
| BUG-QA-005 腳本 | **Pass** | `-SkipBackup` 無 zip → throw |
| A→B 跨站（BUG-QA-002） | **未執行** | 001 未 Pass |
| sunpower T-SUN | **Skip** | GAP-QA-004 |

### 總判定

**第三輪重測 Fail。** 不建議簽核大站 E2E／封裝發佈。

**部分改善：** enqueue 回傳並捕獲 `restore_token`；磁碟 job 訊息曾進入 **`Restoring wp-content…`**（91%），優於第二輪長期 `Database import completed.`。但 **E2E 仍無法在 DB 匯入後 poll 到 job**，亦未無 resume 達 100%。

---

## 2. 環境拓撲（模擬線上）

| 代號 | URL | 角色 |
|------|-----|------|
| Clean | http://localhost:8080 | Plugin Check、smoke |
| QA-B1 | http://localhost:8083 | 大站同站還原（~27G docroot） |
| QA-A2 | http://localhost:8082 | 跨站（未測） |
| QA-A1 | http://localhost:8081 | R-S2 |

**備份：** `localhost-20260527173501-nKKj4r.zip`（≈5.14 GB）

**部署檔案（§4.3）：** `class-ui.php`、`class-restore-handler.php`、`class-restore-service.php`、`class-restore-token.php`、`class-restore-media-paths.php`、`helpers.php`、`admin.js`

---

## 3. 測試執行明細

### 3.1 測前準備

- `docker cp` 七檔至 qa-b1、qa-a2、wordpress
- 清除 `museder_restoreone_restore_service_active_job_id` 等 option
- 刪除第二輪殘留 job JSON
- B1 上確認 `verify_restore_progress_request` 已部署（grep count=1）

### 3.2 迴歸（Pass）

| 項目 | 證據 |
|------|------|
| Plugin Check | `plugin-check.txt` |
| Smoke 6/6 | `admin-smoke-clean.txt` |
| R-S2 | `r-s2-round12.log` |

### 3.3 大站 B1 E2E — BUG-QA-001（Fail）

**指令：**

```powershell
powershell -NoProfile -File tools\qa\run-heavy-site-full-e2e-browser.ps1 `
  -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'
```

**未使用** `resume-restore-browser.ps1`。

| 檢查點 | 預期 | 實測 |
|--------|------|------|
| `Restore token captured` | 有 | **有**（len=64） |
| poll #2 起 job 非 null | stage/progress 變化 | **Fail** — poll #1 起 `(no job object yet)` |
| 無 resume 100% | success | **Fail** — 測試中斷；job 卡 91% |
| 訊息進入檔案還原 | Restoring wp-content… | **部分** — 磁碟 job 有；E2E poll 看不到 |
| `MEDIA_PATHS_RECONCILE_DONE` | grep 有 | **無**（`media-paths-log.txt` 空） |

**Job ID：** `rjb_20260528_181405_lo5twz`

**備註：** 測試中發現 **第二輪未停乾淨的 E2E 程序** 與新一輪並寫 `run.log`（poll #1304+）；已終止舊 process 後以本 job 為準。詳見 Bug 調查報告。

### 3.4 BUG-QA-005

`-SkipBackup` 無 `-BackupZip` → **throw**（訊息含 `SkipBackup requires -BackupZip`）。  
證據：`bug-qa-005-skipbackup-no-zip.txt`

### 3.5 未執行

- `run-heavy-a2-restore-from-b1.ps1`（001 blocked）
- 封裝 ZIP 簽核（handoff：001 Pass 後再做）
- sunpower T-SUN

---

## 4. 根因（QA 結論）

第二輪開發假設：**token 接入 handler 即可在 nonce 死後繼續 poll**。

第三輪實測：**DB 匯入後 WordPress auth cookie 失效 → `admin-ajax` 對未登入請求回 `0`（HTTP 400）→ `wp_ajax_*` handler 未 dispatch → token 驗證未執行。**

**對照實驗：** 同一 token，**重新登入後** `restore_job_status` 回 **success + job**；CLI `Restore_Token::verify()` 亦為 **true**。

→ 詳 **`docs/BUG-INVESTIGATION-2026-05-29-qa-round3-restore-token-nopriv.md`**（建議 **BUG-QA-006**：需 `wp_ajax_nopriv_*` 或等效機制）。

---

## 5. 修復項驗證矩陣

| Bug | 第三輪結果 |
|-----|-----------|
| BUG-QA-001 | **Fail** |
| BUG-QA-002 | Skip |
| BUG-QA-003 | 未驗證 |
| BUG-QA-005 | **Pass** |
| BUG-QA-006（新） | **Fail** — 見調查報告 |
| GAP-QA-004 | Skip |

---

## 6. 證據索引

| 路徑 | 內容 |
|------|------|
| `deploy.log` | docker cp 紀錄 |
| `plugin-check.txt` / `admin-smoke-clean.txt` / `r-s2-round12.log` | 迴歸 |
| `heavy-b1-e2e/run.log` | E2E 輪詢（含 token captured） |
| `heavy-b1-e2e/restore-enqueue.json` | enqueue + restore_token |
| `heavy-b1-e2e/job-meta-stuck-91.json` | 中止時 job |
| `heavy-b1-e2e/manual-status-with-cookies.txt` | 死 session → `0` |
| `heavy-b1-e2e/manual-status-fresh-login.txt` | 重登入 → JSON job |
| `heavy-b1-e2e/token-verify-cli.json` | CLI verify=true |
| `media-paths-log.txt` | grep（空） |
| `bug-qa-005-skipbackup-no-zip.txt` | QA-005 |

---

## 7. 給開發 Agent 的下一步

1. 依 **BUG-QA-006** 調查報告實作 **nopriv + token-only** dispatch（或等效方案）。
2. 修復後 QA **第四輪**：清 B1 → 重跑 §6.2 指令 → 001 Pass 後 A2 + grep + 封裝。
3. 測前確認 **無殘留 E2E 背景 process**；可 truncate 或分檔 `run.log` 避免混淆。

---

## 8. 簽核建議

| 面向 | 建議 |
|------|------|
| 大站還原 E2E | **不 Pass** |
| 靜態／R-S2 | Pass |
| 發佈 2.7.268 | **不建議**（001 未解） |

---

*報告產生：2026-05-29（Asia/Taipei）。*
