# QA 交接報告 — 2.7.268 修復（第三輪重測）

| 項目 | 內容 |
|------|------|
| **對象** | 測試 Agent |
| **開發完成日** | 2026-05-25（第二輪修復接線完成） |
| **外掛版本** | **2.7.268**（**不 bump**） |
| **前次 QA 報告** | `docs/QA-RETEST-REPORT-2.7.268-FIX-2026-05-28.md`（**Fail**） |
| **修復說明（開發）** | `docs/BUG-FIX-2.7.268-QA-RESOLUTION-2026-05-28.md` |
| **缺陷清單** | `docs/BUG-FIX-BACKLOG-2.7.268-QA-2026-05-28.md` |
| **建議證據目錄** | `docs/qa-evidence/qa-retest-2.7.268-fix-round3-2026-05-25/` |

---

## 1. 執行摘要

第二輪 QA 重測後，**BUG-QA-001 核心現象未解**：大站 5.14GB 同站還原在 DB 匯入後卡在 **`restore-files` 90%**，AJAX 自 poll #2 起長期回 **`job: null`**，需手動 `resume-restore-browser.ps1` 才能推進。

**本輪（第二輪開發）根因已確認：** NDJSON 資料庫匯入會重建 `wp_usermeta` 等，導致瀏覽器持有的 **WordPress AJAX nonce 失效**；`restore_tick`／`restore_job_status` 回 **403 `invalid_nonce`**，E2E 腳本誤判為「無 job」。外掛內已有 **file-backed `restore_token`**（`Museder_Restoreone_Restore_Token`），但先前**未接到** tick／status 與前端。

**本輪修復重點：** 將 `restore_token` 貫穿 enqueue → 前端 poll/tick → AJAX 驗證；並保留第一輪的 job meta 雙路徑、slice drain、媒體路徑日誌等改進。

**第三輪 QA 目標：** 驗證 BUG-QA-001／003／005；001 通過後再跑 BUG-QA-002（A→B 跨站）。

---

## 2. 問題與根因對照

### 2.1 第二輪 QA 觀測（Fail 基線）

| 項目 | 值 |
|------|-----|
| Job 範例 | `rjb_20260528_161822_xvjrvu` |
| 卡住位置 | `stage=restore-files`, `progress=90`, `message=Database import completed.` |
| Poll 行為 | poll #1 有 job（`restore-db` 80%）；**#2～1095 全 `job: null`** |
| `last_tick` | 僅 +6s 後停滯 |
| upload_path | **未漂移**；job JSON 在標準 jobs 路徑 |
| 手動 resume | 可於數十秒內推至 100% |

### 2.2 根因（依優先序）

| # | 根因 | 說明 |
|---|------|------|
| **1（主因）** | DB 匯入後 nonce 失效 | NDJSON 還原覆寫 session／usermeta → `verify_ajax_request()` 403 → 前端／E2E 視為 `job: null` |
| **2** | restore_token 未接線 | Token 類別已存在，未用於 `restore_tick`／`restore_job_status`；enqueue 未強制回傳給前端 |
| **3（次要）** | 單次 tick  slice 不足 | 大站檔案階段需同請求內多次 `process_job_slice`（第一輪已部分處理） |
| **4（次要）** | job meta 路徑 | upload_path 漂移時 meta 找不到（第一輪雙路徑已處理；本案例未觸發） |

---

## 3. 修復清單

### 3.1 第一輪（2026-05-28）— 基礎設施

| Bug | 修復 |
|-----|------|
| BUG-QA-001（部分） | `museder_restoreone_get_canonical_storage_base()`／`get_canonical_jobs_dir()`；job meta **雙路徑**寫入；`drain_restore_stage_slices()` 同 slice 多段推進 |
| BUG-SUN-005 | `class-restore-media-paths.php` — uploads DB↔磁碟 reconcile；`MEDIA_PATHS_RECONCILE_DONE` 日誌 |
| BUG-QA-003 | `log_reconcile_done()` 於所有完成路徑寫入日誌 |
| BUG-QA-005 | `SkipBackup` 無 `-BackupZip` 時明確 `throw` |

### 3.2 第二輪（本輪）— restore_token 接線

| 項目 | 檔案 | 變更 |
|------|------|------|
| Token 驗證 | `includes/class-ui.php` | `verify_restore_progress_request()`、`restore_progress_token_is_valid()` — **token 或 nonce** |
| AJAX 端點 | `includes/class-restore-handler.php` | `restore_tick`、`restore_job_status` 改用上述驗證；tick 在 slice 秒數內**迴圈多次** slice |
| Enqueue 回傳 | `includes/class-restore-service.php` | 頂層 `restore_token`；job meta 含 `temp/{job_id}/restore-job.meta.json` |
| Token 檔路徑 | `includes/class-restore-token.php` | 改用 canonical temp 目錄 |
| 前端 | `assets/js/admin.js` | `activeRestoreToken`、`appendRestoreProgressAuth()` 附於 poll/tick |
| E2E | `tools/qa/run-heavy-site-full-e2e-browser.ps1` | 捕獲 enqueue 的 `restore_token` 並附於 AJAX；`slice=12` |

---

## 4. 變更檔案（部署用）

### 4.1 必須同步至容器（第三輪最低集合）

```
includes/class-ui.php
includes/class-restore-handler.php
includes/class-restore-service.php
includes/class-restore-token.php
includes/class-restore-media-paths.php
includes/helpers.php
assets/js/admin.js
```

### 4.2 主機端（執行 E2E 用，不需 cp 進容器）

```
tools/qa/run-heavy-site-full-e2e-browser.ps1
tools/qa/run-qa-retest-2.7.268-fix.ps1   # 可選：完整編排
```

### 4.3 部署方式

**第三輪重測不必先封裝 ZIP。** 自工作區 `docker cp` 至 QA 容器即可（與 R-S2 round12 相同模式）。

```powershell
$Repo = 'C:\Users\fdmur\Documents\GitHub\museder-restoreone'
$Plugin = '/var/www/html/wp-content/plugins/museder-restoreone'
$files = @(
  @{ src='includes\class-ui.php'; dst="$Plugin/includes/class-ui.php" },
  @{ src='includes\class-restore-handler.php'; dst="$Plugin/includes/class-restore-handler.php" },
  @{ src='includes\class-restore-service.php'; dst="$Plugin/includes/class-restore-service.php" },
  @{ src='includes\class-restore-token.php'; dst="$Plugin/includes/class-restore-token.php" },
  @{ src='includes\class-restore-media-paths.php'; dst="$Plugin/includes/class-restore-media-paths.php" },
  @{ src='includes\helpers.php'; dst="$Plugin/includes/helpers.php" },
  @{ src='assets\js\admin.js'; dst="$Plugin/assets/js/admin.js" }
)
foreach ($svc in @('qa-b1','qa-a2','wordpress')) {
  foreach ($f in $files) {
    docker compose -f docker-compose.qa.yml cp (Join-Path $Repo $f.src) "${svc}:$($f.dst)"
  }
  if ($svc -eq 'wordpress') {
    docker compose cp (Join-Path $Repo $files[0].src) "wordpress:$($files[0].dst)"  # 或對 clean 站逐一 cp
  }
}
```

> **注意：** Clean 站（8080）若用 `docker-compose.yml` 的 `wordpress` 服務，路徑同上。封裝／ZIP 驗證留待 **001 Pass 後**發佈簽核前再跑。

---

## 5. 測前準備

### 5.1 清除 B1 殘留 90% job（第二輪 Fail 留下）

第二輪 job `rjb_20260528_161822_xvjrvu` 可能仍占 active pointer。重測前建議：

```powershell
docker compose -f docker-compose.qa.yml exec -T qa-b1 wp option delete museder_restoreone_restore_service_active_job_id --allow-root
docker compose -f docker-compose.qa.yml exec -T qa-b1 wp option delete museder_restoreone_restore_token --allow-root
# 可選：刪除殘留 temp/jobs（需確認無其他進行中工作）
```

確認無 active job：

```powershell
docker compose -f docker-compose.qa.yml exec -T qa-b1 wp eval 'echo class_exists("Museder_Restoreone_Restore_Service") ? Museder_Restoreone_Restore_Service::get_active_job_id() : "n/a";' --allow-root
```

### 5.2 環境

| 代號 | URL | 用途 |
|------|-----|------|
| Clean | http://localhost:8080 | Plugin Check、smoke |
| QA-B1 | http://localhost:8083 | **大站同站還原（關鍵）** |
| QA-A2 | http://localhost:8082 | 跨站（001 通過後） |
| QA-A1 | http://localhost:8081 | R-S2 迴歸 |

備份檔：`localhost-20260527173501-nKKj4r.zip`（≈5.14 GB，應已在 B1 `uploads/museder-restoreone/backups/`）。

---

## 6. 第三輪測試計畫

### 6.1 建議順序

| 序 | 測項 | 指令 | 依賴 |
|----|------|------|------|
| 0 | 部署工作區修復 | 見 §4.3 | — |
| 1 | 清除殘留 job | 見 §5.1 | 0 |
| 2 | R-S2 迴歸（可選） | `tools/qa/run-r-s2-round12.ps1` | 0 |
| 3 | **大站 B1 同站還原** | 見 §6.2 | 0, 1 |
| 4 | 日誌 grep | 見 §6.3 | 3 完成 |
| 5 | A→B 跨站 | `run-heavy-a2-restore-from-b1.ps1` | **3 Pass** |
| 6 | Plugin Check + smoke | `run-qa-retest-2.7.268-fix.ps1 -SkipHeavy` 或手動 | 可平行 |
| 7 | sunpower T-SUN | — | **Skip**（GAP-QA-004） |

### 6.2 關鍵指令 — BUG-QA-001

```powershell
cd C:\Users\fdmur\Documents\GitHub\museder-restoreone
powershell -NoProfile -File tools\qa\run-heavy-site-full-e2e-browser.ps1 `
  -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'
```

**硬性要求：**

- **不得**呼叫 `tools/qa/resume-restore-browser.ps1`
- 腳本 exit code **0**
- `run.log` 含 **`Restore token captured`**

### 6.3 日誌 — BUG-QA-003

```powershell
docker compose -f docker-compose.qa.yml exec -T qa-b1 `
  grep MEDIA_PATHS /var/www/html/wp-content/uploads/museder-restoreone/logs/backup-lite-*.log
```

預期至少一行含 **`MEDIA_PATHS_RECONCILE_DONE`**。

### 6.4 BUG-QA-005 抽測

```powershell
powershell -NoProfile -File tools\qa\run-heavy-site-full-e2e-browser.ps1 -SkipBackup
# 預期：throw，訊息含 SkipBackup requires -BackupZip
```

---

## 7. Pass / Fail 判定標準

### 7.1 BUG-QA-001（P1 — 必須 Pass）

| 檢查點 | Pass | Fail |
|--------|------|------|
| Enqueue | log 有 `Restore token captured (len=…)` | `WARN: no restore_token` 或缺 token |
| Poll #2 起 | `restore poll #N` 含 **stage=**、**progress=**（非長期 `no job object yet`） | 與第二輪相同：#2 起全 null |
| 進度 | 最終 **100%**／`status=success`，**無 resume** | 卡 90% 或 timeout |
| 訊息 | DB 完成後進入 **Restoring wp-content…** 等檔案階段文案 | 長期停在 `Database import completed.` |
| `last_tick` | 持續更新直至完成 | +6s 後停滯 |
| 還原驗證 | `blogname` 回到備份 marker；smoke **6/6** | verify 或 smoke 失敗 |

### 7.2 BUG-QA-002（P1 — 001 Pass 後）

A2 跨站全量還原同 §7.1 標準；後台 backups 頁可載入以取 nonce（或同樣依賴 restore_token）。

### 7.3 BUG-QA-003（P2）

grep 見 `MEDIA_PATHS_RECONCILE_DONE`（context 含 `reason`、`fixed`、`scanned`）。

### 7.4 靜態檢查（迴歸）

| 項目 | Pass |
|------|------|
| Plugin Check | 0 ERROR |
| WP_DEBUG smoke | 6/6，`debug_log_fatal=no` |
| R-S2 | job 100% |

### 7.5 總判定

- **001 Fail** → 整輪 **Fail**，不簽核大站 E2E；回報開發並附 poll log 前 20 行 + enqueue JSON
- **001 Pass + 003 Pass** → 可進 A2、再跑封裝簽核
- **GAP-QA-004** 不阻擋 001／002，但 sunpower 掉圖仍 **未驗證**

---

## 8. 失敗時必收證據

寫入 `docs/qa-evidence/qa-retest-2.7.268-fix-round3-2026-05-25/`：

| 檔案 | 來源 |
|------|------|
| `heavy-b1-e2e/run.log` | E2E 主 log |
| `heavy-b1-e2e/restore-enqueue.json` | 含 `restore_token` 欄位 |
| `heavy-b1-e2e/restore-done.json` | 終態 job |
| `heavy-b1-e2e/cookies.txt` | （可 redact） |
| `media-paths-log.txt` | grep 輸出 |
| `job-meta.json` | `wp-content/uploads/museder-restoreone/jobs/{job_id}.json` |
| `active-job-option.txt` | `wp option get museder_restoreone_restore_service_active_job_id` |

若 poll 仍 null，額外收：

```powershell
# 手動帶 token 測 status（需從 enqueue 複製 token）
curl.exe -s -b cookies.txt -X POST http://localhost:8083/wp-admin/admin-ajax.php `
  --data-urlencode action=museder_restoreone_restore_job_status `
  --data-urlencode job_id=JOB_ID `
  --data-urlencode restore_token=TOKEN
```

確認回應是 **403 invalid_nonce**（舊版）還是 **success + job**（修復後）。

---

## 9. 未含／Skip

| 項目 | 說明 |
|------|------|
| **GAP-QA-004** | sunpower zip 仍缺；T-SUN **Skip** |
| **musederlabs Step1** | Analysis failed — 另案，見 `docs/BUG-INVESTIGATION-2026-05-28-musederlabs-step1-analysis-failed.md` |
| **版號 bump** | 維持 2.7.268 |
| **封裝 ZIP** | 第三輪不必；001 Pass 後再跑 `tools/package-lite-windows.ps1` |

---

## 10. 參考文件索引

| 文件 | 用途 |
|------|------|
| `docs/QA-TEST-REPORT-2.7.268-RESTORE-MEDIA-PATHS.md` | 第一輪 QA |
| `docs/QA-RETEST-REPORT-2.7.268-FIX-2026-05-28.md` | 第二輪 QA Fail |
| `docs/QA-RETEST-PLAN-2.7.268-FIX-2026-05-28.md` | 原重測計畫 |
| `docs/BUG-FIX-2.7.268-QA-RESOLUTION-2026-05-28.md` | 開發修復細節 |
| `docs/PLAN-RESTORE-MEDIA-PATHS-RECONCILE.md` | BUG-SUN-005 規格 |
| `docs/QA-HEAVY-SITE-FULL-E2E-BROWSER-2.7.268.md` | 大站 E2E 說明 |

---

## 11. 給測試 Agent 的一句話

**第二輪 Fail 的主因是 DB 匯入後 nonce 死、restore_token 未接線；本輪已接好。請用工作區 `docker cp` 部署（不必封裝），清掉 B1 殘留 90% job 後跑 `run-heavy-site-full-e2e-browser.ps1 -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'`，確認 poll #2 起 job 非 null 且無 resume 即 100%。**
