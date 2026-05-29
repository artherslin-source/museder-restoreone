# QA 交接報告 — 2.7.268 第四輪（BUG-QA-006 nopriv 修復）

| 項目 | 內容 |
|------|------|
| **對象** | 測試 Agent |
| **開發完成日** | 2026-05-25 |
| **外掛版本** | **2.7.268**（不 bump） |
| **前次 QA** | `docs/QA-RETEST-REPORT-2.7.268-FIX-ROUND3-2026-05-25.md`（Fail — BUG-QA-006） |
| **Bug 調查** | `docs/BUG-INVESTIGATION-2026-05-29-qa-round3-restore-token-nopriv.md` |
| **修復說明** | `docs/BUG-FIX-2.7.268-QA-RESOLUTION-2026-05-28.md` § BUG-QA-006 |
| **建議證據目錄** | `docs/qa-evidence/qa-retest-2.7.268-fix-round4-2026-05-25/` |

---

## 1. 本輪修復摘要

第三輪 Fail 根因：**DB 匯入後 auth cookie 失效**，WordPress 對未登入 `admin-ajax` 回 **`0`**，`wp_ajax_*` handler **不 dispatch**，`restore_token` 驗證從未執行。

**第四輪修復：**

1. 為 `restore_job_status`、`restore_tick`、`trigger_restore_job` 註冊 **`wp_ajax_nopriv_*`**
2. `verify_restore_progress_request()`：未登入時 **僅** 接受有效 `restore_token`（403 `invalid_restore_token`，不洩漏 job）
3. 前端 `trigger_restore_job`、history fallback 亦附 `restore_token`

---

## 2. 部署檔案（docker cp）

```
includes/class-restore-handler.php
includes/class-ui.php
assets/js/admin.js
```

（其餘第三輪七檔若已部署可保留；本輪**必更**上述三檔。）

---

## 3. 測前準備

1. 清除 B1 殘留 job（同第三輪 handoff §5.1 或 `tools/qa/clear-b1-restore-state.php`）
2. 確認 **無殘留 E2E 背景 process**
3. 部署三檔至 qa-b1、qa-a2、wordpress

---

## 4. 第四輪測試計畫

| 序 | 測項 | Pass 標準 |
|----|------|-----------|
| 1 | R-S2（可選） | 100% |
| 2 | **大站 B1 E2E** | 見 §5 |
| 3 | grep 日誌 | `MEDIA_PATHS_RECONCILE_DONE` |
| 4 | A→B 跨站 | 001 Pass 後 |
| 5 | 封裝 | 001 Pass 後 |

**關鍵指令：**

```powershell
powershell -NoProfile -File tools\qa\run-heavy-site-full-e2e-browser.ps1 `
  -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'
```

**不得**使用 `resume-restore-browser.ps1`。

---

## 5. BUG-QA-001 Pass 標準（第四輪）

| 檢查點 | Pass |
|--------|------|
| `Restore token captured` | 有 |
| poll #2 起 | `stage=`／`progress=`，**非**長期 `(no job object yet)` |
| 死 session 對照 | 同一 cookies + token → **JSON**（非 HTTP 400 / `0`） |
| 完成 | 無 resume **100%** |
| 訊息 | 進入 `Restoring wp-content…` 等 |
| 日誌 | `MEDIA_PATHS_RECONCILE_DONE` |

**手動對照（可選）：**

```powershell
# 使用 enqueue 的 token + 舊 cookies（不 re-login）
curl.exe -s -b cookies.txt -w "\nHTTP:%{http_code}\n" -X POST http://localhost:8083/wp-admin/admin-ajax.php `
  --data-urlencode action=museder_restoreone_restore_job_status `
  --data-urlencode job_id=JOB_ID `
  --data-urlencode restore_token=TOKEN
```

預期：`success:true` + `data.job`，非 `0`。

---

## 6. 安全備註（審查用）

- nopriv 路徑 **不接受** 僅 nonce 的授權
- token 無效 → 403，不回 job 內容
- token 綁定 job_id、2h TTL、wp_hash 儲存（既有 `Restore_Token` 模型）

---

## 7. 給測試 Agent 的一句話

**第三輪 token 已通但 handler 未 dispatch；第四輪已加 nopriv + token-only。請 cp 三檔、清 B1 job 後重跑 B1 E2E，確認死 session 下 poll 仍得 JSON 且 100% 完成。**
