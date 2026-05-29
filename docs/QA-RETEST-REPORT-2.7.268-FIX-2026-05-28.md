# QA 重測報告 — 2.7.268（BUG-QA-001～005 修復後）

| 項目 | 內容 |
|------|------|
| **測試者** | Cursor Agent |
| **日期** | 2026-05-28～2026-05-29 |
| **外掛版本** | **2.7.268**（未 bump） |
| **ZIP** | `dist/museder-restoreone-2.7.268.zip` |
| **ZIP SHA256** | `CEE9A0D41980544B88F2CD3965A446687E3660DAAC2CE7E686C4AB676FC0494B` |
| **修復依據** | `docs/BUG-FIX-2.7.268-QA-RESOLUTION-2026-05-28.md` |
| **重測計畫** | `docs/QA-RETEST-PLAN-2.7.268-FIX-2026-05-28.md` |
| **前次報告** | `docs/QA-TEST-REPORT-2.7.268-RESTORE-MEDIA-PATHS.md` |

**證據目錄：** `docs/qa-evidence/qa-retest-2.7.268-fix-2026-05-28/`

---

## 1. 結果摘要

| 測試區塊 | 結果 | 說明 |
|----------|------|------|
| 封裝 | **Pass** | 80 entries，`BOUNDARY_CHECK=PASS`，580319 bytes |
| Plugin Check（8080） | **Pass** | 0 ERROR；1 WARNING（SlowDBQuery） |
| 乾淨 WP_DEBUG smoke | **Pass** | 6/6，`debug_log_fatal=no` |
| R-S2 bootstrap 迴歸 | **Pass** | `job_id=rjb_20260528_161705_lsepvs` → 100% |
| **大站 B1 同站還原（關鍵重測）** | **Fail** | 卡 90% + `job: null`；**QA 手動中止** |
| 大站 A→B 跨站還原 | **未執行** | 依計畫等 001 通過後再跑 |
| sunpower T-SUN | **Skip** | 仍缺 zip（GAP-QA-004） |
| BUG-QA-005 腳本 | **Pass**（程式碼） | `-SkipBackup` 無 `-BackupZip` 會 `throw` |

### 總結判定

**修復重測未通過。** BUG-QA-001 核心現象與修復前一致：大站 5.14GB 同站還原在 DB 匯入後停在 **`restore-files` 90%**，AJAX 輪詢自 poll #2 起長期 **`job: null`**，**無法在不使用 `resume-restore-browser.ps1` 的情況下達 100%**。BUG-QA-003 因還原未完成而**無法驗證**日誌 `MEDIA_PATHS_RECONCILE_DONE`。

**不建議**以本輪結果簽核大站還原／跨站 E2E。靜態檢查與小站迴歸（Plugin Check、smoke、R-S2）仍 Pass。

---

## 2. 環境與部署

| 代號 | URL | 用途 |
|------|-----|------|
| Clean | http://localhost:8080 | Plugin Check、smoke |
| QA-B1 | http://localhost:8083 | 大站同站還原（~21G docroot） |
| QA-A2 | http://localhost:8082 | 跨站（本輪未測） |
| QA-A1 | http://localhost:8081 | R-S2 |

部署方式：自 `dist/museder-restoreone-2.7.268.zip` 解壓至各站 `plugins/museder-restoreone`（模擬正式封裝）。

還原用備份：`localhost-20260527173501-nKKj4r.zip`（≈5.14 GB）。

---

## 3. 依序測試明細

### 3.1 封裝

```text
tools/package-lite-windows.ps1
→ dist/museder-restoreone-2.7.268.zip
Size: 580319 bytes | entries: 80 | BOUNDARY_CHECK=PASS
```

證據：`package.log`、`zip-sha256.txt`

### 3.2 Plugin Check

```bash
docker compose run --rm wpcli plugin check museder-restoreone
```

**結果：** 0 ERROR

**WARNING（1）：** `includes/class-restore-media-paths.php:278` — `WordPress.DB.SlowDBQuery.slow_db_query_meta_value`

證據：`plugin-check.txt`

### 3.3 乾淨安裝 WP_DEBUG smoke

| 項目 | 結果 |
|------|------|
| `admin-smoke-wpdebug.php` | **6/6** |
| `debug_log_fatal` | **no** |

證據：`admin-smoke-clean.txt`

### 3.4 R-S2 bootstrap（TC-08）

| 項目 | 結果 |
|------|------|
| 腳本 | `tools/qa/run-r-s2-round12.ps1` |
| Job | `rjb_20260528_161705_lsepvs` |
| 結果 | **PASS**（100%） |

證據：`r-s2-round12.log`

### 3.5 大站 B1 同站還原（BUG-QA-001 關鍵）

| 項目 | 內容 |
|------|------|
| 腳本 | `tools/qa/run-heavy-site-full-e2e-browser.ps1 -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'` |
| 開始 | 2026-05-29 00:17:58 |
| 中止 | 2026-05-29 01:55:33（**使用者要求手動停止**） |
| 執行時間 | 約 **98 分鐘**（多為空轉輪詢） |
| Job ID | `rjb_20260528_161822_xvjrvu` |

#### 時間軸

| 時間 | 事件 |
|------|------|
| 00:18:18 | SkipBackup 使用既有 5.14GB zip |
| 00:18:21 | `restore_from_backup` OK |
| 00:20:02 | Restore job 啟動 |
| 00:20:03 | **poll #1** — `stage=restore-db`, `progress=80`, `msg=Preparing database import…` |
| 00:20:18～01:55:33 | **poll #2～#1095** — 全部 `(no job object yet)` |
| 01:55:43 | 背景程序被 Stop-Process 終止（exit_code 4294967295） |

#### 磁碟 job 快照（中止當下）

證據：`heavy-b1-e2e/job-stopped-snapshot.json`

| 欄位 | 值 |
|------|-----|
| `stage` | `restore-files` |
| `progress` | **90** |
| `message` | `Database import completed.` |
| `completed` | `false` |
| `started_at` | 1779985202 |
| `last_tick` | **1779985208**（僅比 started_at 晚 ~6 秒，之後未再 tick） |
| `tick_source` | `ajax` |
| `media_paths` | **（欄位不存在 — 未完成 reconcile 收尾）** |

#### 輪詢 vs 修復預期對照

| 修復後 Pass 標準 | 本輪實測 |
|------------------|----------|
| 無需 resume 即 100% | **未達成**（90% 停滯後中止） |
| 輪詢穩定回 `job` 物件 | **未達成**（1094/1095 次 poll 為 null） |
| 訊息進入檔案還原（非長期 Database import completed） | **未達成** |
| 日誌 `MEDIA_PATHS_RECONCILE_DONE` | **未驗證**（還原未完成） |

#### 補充診斷

- `get_option('museder_restoreone_restore_service_active_job_id')` 仍為 `rjb_20260528_161822_xvjrvu`（active pointer 未清）。
- Job JSON 僅存在於 `/var/www/html/wp-content/uploads/museder-restoreone/jobs/`（單一路徑）。
- `upload_path` / `upload_url_path` 皆為空；`wp_upload_dir()['basedir']` 為標準 `…/wp-content/uploads`（本輪**未**觀察到 upload_path 漂移，但 `job_status` 仍 null — 根因可能不限於路徑 sync）。
- `backup-lite-2026-05-28.log` **無** `MEDIA_PATHS_RECONCILE_DONE` 字串。

**判定：BUG-QA-001 重測 Fail（回歸／修復未生效於此場景）。**

### 3.6 大站 A→B 跨站（BUG-QA-002）

**未執行。** 依 `QA-RETEST-PLAN` 需 BUG-QA-001 通過後才跑 `run-heavy-a2-restore-from-b1.ps1`。

**判定：Skip（blocked by 001）。**

### 3.7 sunpower（GAP-QA-004）

Repo 仍無 `sunpoweroflight.com-*.zip`。**Skip。**

### 3.8 BUG-QA-005 — E2E 腳本

`run-heavy-site-full-e2e-browser.ps1` 第 174～175 行：

```powershell
} elseif ($SkipBackup) {
    throw 'SkipBackup requires -BackupZip with a filename.zip ...'
```

**判定：Pass**（明確錯誤，不再依賴易壞的 wp eval glob）。

---

## 4. 修復項驗證對照

| Bug | 修復宣稱 | 重測結果 | 備註 |
|-----|----------|----------|------|
| **BUG-QA-001** | 雙路徑 job meta、DB 後 sync、drain slices、job_status 重試 | **Fail** | 與修復前相同卡點 |
| **BUG-QA-002** | 隨 001 解決 | **未測** | A2 未跑 |
| **BUG-QA-003** | `log_reconcile_done()` 一律寫日誌 | **未驗證** | 還原未跑完 |
| **BUG-QA-005** | SkipBackup 必帶 BackupZip | **Pass** | 程式碼確認 |
| **GAP-QA-004** | 需 sunpower zip | **Skip** | — |

---

## 5. 與前次 QA（修復前）對照

| 指標 | 修復前（2026-05-28 上午） | 本輪重測 |
|------|---------------------------|----------|
| Job 範例 | `rjb_20260528_101044_qwt1lo` | `rjb_20260528_161822_xvjrvu` |
| 卡住 stage/progress | `restore-files` / 90% | **相同** |
| job_status null | 是（長期） | **相同**（poll #2 起） |
| resume 可推進 | 是（4 tick → 100%） | **未測**（本輪未跑 resume） |
| ZIP SHA256 | `A7A3A509…` | `CEE9A0D…`（含修復程式碼） |

**結論：** 新 ZIP 已部署，但大站 E2E 行為**未見改善**。

---

## 6. 編排器與腳本問題（非產品 bug，但影響 QA）

| 問題 | 說明 |
|------|------|
| `run-qa-retest-2.7.268-fix.ps1` | Step 4 因 Docker stderr + `$ErrorActionPreference Stop` 中斷；後續改手動執行 |
| E2E 首次啟動 | Unicode `…` 導致 PowerShell parse error（已改 ASCII `...`） |
| 本輪 E2E | 使用者手動中止；證據見 `heavy-b1-e2e/run.log`（1095 行 poll） |

---

## 7. 缺陷狀態與建議（交開發）

### 7.1 仍開啟 — BUG-QA-001（P1）

**現象未解。** 建議下一輪調查：

1. **`Restore_Service::status()` 是否在 DB 匯入後 throw？** `job_status` 在 catch 內 sync 後仍可能回 null（見 `class-restore-handler.php` `job_status()`）。
2. **`last_tick` 僅 +6 秒：** `restore-files` slice／`drain_restore_stage_slices()` 是否未被 cron／loopback／連續 ajax 觸發？
3. **poll #1 成功、#2 起失敗：** 比對 DB 匯入前後 `status()` 與 `get_job_meta()` 回傳差異（可加 temporary debug log）。
4. **驗證修復程式是否載入：** B1 容器內 grep `sync_job_meta_paths_after_db_import`、`drain_restore_stage_slices` 是否存在於已部署外掛。

### 7.2 阻塞 — BUG-QA-002 / BUG-QA-003

待 001 通過後重跑 A2 與日誌 grep。

### 7.3 仍 Skip — GAP-QA-004

需提供 sunpower T-SUN zip 方能驗證 BUG-SUN-005 真實場景。

### 7.4 QA 環境

- B1 目前處於**中斷還原**狀態（90% job 殘留）；下次重測前建議 reset 或 resume/cleanup。
- 可選：以 `resume-restore-browser.ps1` 驗證「手動 tick 是否仍可推進」（區分「完全壞掉」vs「僅自動推進失效」）。

---

## 8. 證據索引

| 檔案 | 內容 |
|------|------|
| `package.log` | 封裝輸出 |
| `zip-sha256.txt` | ZIP 校驗 |
| `plugin-check.txt` | Plugin Check |
| `admin-smoke-clean.txt` | 6/6 smoke |
| `r-s2-round12.log` | R-S2 Pass |
| `orchestrator.log` | 編排器（Step 1～4 後中斷） |
| `heavy-b1-e2e/run.log` | 大站 E2E 完整輪詢 log |
| `heavy-b1-e2e/job-stopped-snapshot.json` | 中止當下 job JSON |

---

## 9. 簽核建議

| 面向 | 建議 |
|------|------|
| **2.7.268 大站還原 E2E** | **不 Pass** — 001 重測 Fail |
| **靜態／小站迴歸** | Pass — 可繼續開發，不宜僅憑此發佈大站還原修復 |
| **下一動** | 開發再修 001 → QA 第三輪重測（B1 無 resume 100% + A2 + 日誌 grep） |

---

*報告產生時間：2026-05-29（Asia/Taipei）。大站 E2E 於使用者指示下手動中止。*
