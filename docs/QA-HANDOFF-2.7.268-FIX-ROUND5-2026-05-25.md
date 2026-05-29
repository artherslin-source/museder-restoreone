# QA 交接報告 — 2.7.268 第五輪（BUG-QA-007 post-complete poll）

| 項目 | 內容 |
|------|------|
| **對象** | 測試 Agent |
| **開發完成日** | 2026-05-25 |
| **外掛版本** | **2.7.268**（不 bump） |
| **前次 QA** | `docs/QA-RETEST-REPORT-2.7.268-FIX-ROUND4-2026-05-25.md` |
| **Bug 調查** | `docs/BUG-INVESTIGATION-2026-05-29-qa-round4-post-complete-token-revoke.md` |
| **修復說明** | `docs/BUG-FIX-2.7.268-QA-RESOLUTION-2026-05-28.md` § BUG-QA-007 |
| **建議證據目錄** | `docs/qa-evidence/qa-retest-2.7.268-fix-round5-2026-05-25/` |

---

## 1. 本輪修復摘要

第四輪：**006 Pass**（mid-restore nopriv）、引擎 **100%**、**003 Pass**；但 E2E **Fail** — 完成後 `revoke()` 使 poll #2 起 `invalid_restore_token`。

**第五輪修復：** `revoke()` 時寫入 **post-complete read grant**（`job_id` + `token_hash`，沿用 TTL）；`verify_restore_progress_request()` 在 token 檔已刪但 job **completed** 時，允許**唯讀** poll/tick（需同一 `restore_token`）。

---

## 2. 部署檔案（docker cp）

```
includes/class-restore-token.php
includes/class-ui.php
```

（第四輪三檔若已部署可保留；本輪**必更**上述兩檔。）

---

## 3. 測前準備

1. `tools/qa/clear-b1-restore-state.php`（已含清 post-complete grant）
2. 確認無殘留 E2E 背景 process
3. `docker cp` 兩檔至 qa-b1、qa-a2、wordpress

---

## 4. 第五輪測試計畫

| 序 | 測項 | Pass 標準 |
|----|------|-----------|
| 1 | **大站 B1 E2E** | **exit 0**；見 §5 |
| 2 | grep 日誌 | `MEDIA_PATHS_RECONCILE_DONE` |
| 3 | A→B 跨站 | 001/E2E Pass 後 |
| 4 | 封裝 | E2E Pass 後 |

```powershell
powershell -NoProfile -File tools\qa\run-heavy-site-full-e2e-browser.ps1 `
  -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'
```

**不得**使用 `resume-restore-browser.ps1`。

---

## 5. Pass 標準（E2E 嚴格）

| 檢查點 | Pass |
|--------|------|
| `Restore token captured` | 有 |
| poll #1 | `stage`／`progress` 有值 |
| poll #2+ | **非**長期 `invalid_restore_token`；完成後應見 `status=success progress=100` |
| 腳本 | **exit 0** |
| 引擎 | 無 resume 100% |
| 日誌 | `MEDIA_PATHS_RECONCILE_DONE` |

**手動對照（完成後）：** 死 session + 同一 token + job_id → JSON `success:true` + `job.status=success`。

---

## 6. 安全備註

- Post-complete 僅允許**已完成** job 的唯讀查詢
- 須提供與 enqueue 相同的 **raw restore_token**（hash 比對）
- 無 token 或錯 job_id → 仍 403
- Grant 隨原 token TTL 過期

---

## 7. 給測試 Agent 的一句話

**第四輪引擎已 100%，007 修的是完成後 poll 收斂；cp 兩檔、清 B1 後重跑 E2E，預期 exit 0，再跑 A2 + 封裝。**
