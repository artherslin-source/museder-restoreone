# 缺陷修復清單 — 2.7.268 QA（媒體路徑＋大站還原）2026-05-28

**對象：** 開發 Agent  
**依據：** `docs/QA-TEST-REPORT-2.7.268-RESTORE-MEDIA-PATHS.md`、`docs/QA-PRETEST-2.7.268-RESTORE-MEDIA-PATHS.md`  
**版本：** 2.7.268（`dist/museder-restoreone-2.7.268.zip`，SHA256 `A7A3A509…`）

---

## 結論（給 PM／開發）

**有需要修復的 bug／缺口：有。**  
媒體路徑對帳（BUG-SUN-005）**程式有接上**（job `media_paths`、`media_paths_phase=done`），但本輪**無法視為 sunpower 掉圖已驗證**；另有大站還原 **90% 卡住**與 **AJAX job 查詢失效**，會擋自動化與 A→B 還原。

| 優先 | 數量 | 說明 |
|------|------|------|
| **P0** | 1 | sunpower 場景未驗證（缺測資，非程式錯誤） |
| **P1** | 1 | 大站還原卡在 90% + `restore_job_status` 回 null |
| **P2** | 2 | 日誌可觀測性；QA 腳本 |
| **不列為本輪新 bug** | — | musederlabs Step1「Analysis failed」（另案，見 `docs/BUG-INVESTIGATION-2026-05-28-musederlabs-step1-analysis-failed.md`） |

---

## BUG-QA-001 — 大站還原卡在 90% `restore-files`，需手動 tick 才繼續

| 欄位 | 內容 |
|------|------|
| **優先** | **P1** |
| **類型** | 還原引擎／進度與 AJAX |
| **影響** | 5GB+ 全量還原；跨站 A→B；自動化 E2E |

### 現象

1. `restore_enqueue` 後 job 長時間停在 **`stage: restore-files`、`progress: 90`**、`message: Database import completed.`  
2. 前端／腳本輪詢 **`museder_restoreone_restore_job_status`** 常得到 **`job: null`**（job 檔仍存在）。  
3. 以 **`restore_tick` + slice** 手動推進後可於數十秒內跑完（B1 實測：4 次 poll → 100% success）。

### 重現（B1 同站，已重現）

- 環境：QA-B1（8083），備份 `localhost-20260527173501-nKKj4r.zip`（≈5.14GB）  
- 腳本：`tools/qa/run-heavy-site-full-e2e-browser.ps1 -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'`  
- Job 範例：`rjb_20260528_101044_qwt1lo`  
- 修復輪詢：`tools/qa/resume-restore-browser.ps1 -JobId rjb_20260528_101044_qwt1lo`

### 預期

- DB 匯入完成後 **檔案還原階段應自動推進**（cron／loopback／連續 tick），最終 **100%** 無需人工 resume。  
- `restore_job_status` 在 job 進行中應 **穩定回傳 job 物件**（或文件化 history fallback）。

### 證據

- `docs/qa-evidence/media-paths-reconcile-2.7.268-2026-05-28/job-final.json`（完成後）  
- `docs/qa-evidence/media-paths-reconcile-2.7.268-2026-05-28/b1-resume-log.txt`  
- `docs/qa-evidence/media-paths-reconcile-2.7.268-2026-05-28/b1-inplace-restore-console.log`（長時間 `no job`）

### 建議調查方向

- `restore-files` 切片是否在無 AJAX tick 時停滯；`last_tick` 是否過舊。  
- `job_status` 為 null 的條件（active job pointer、safe mode、mid-restore isolation）。  
- 對照 `docs/QA-HEAVY-SITE-FULL-E2E-BROWSER-2.7.268.md` 已知行為是否回歸。

---

## BUG-QA-002 — 跨站大站還原（A→B）無法完成；A2 無法 resume

| 欄位 | 內容 |
|------|------|
| **優先** | **P1**（依賴 BUG-QA-001） |
| **類型** | 還原＋後台可用性 |

### 現象

- QA-A2（8082）自 B1 複製 5.2GB zip 後 `restore_enqueue`，卡在 **90%**（job `rjb_20260528_084423_nirnp5`）。  
- `resume-restore-browser.ps1` 登入後 **`admin.php?page=museder-restoreone-backups` 回傳 HTML 長度 0**，無法取 nonce。  
- WP-CLI `eval-advance-restore-job.php` 200 次 slice 仍 **90%**（與僅 ajax tick 路徑行為不一致）。

### 預期

- 跨站全量還原應能完成或可透過與 B1 相同方式 resume。  
- 還原進行中後台至少 backups／restore 頁可載入以支援 tick。

### 證據

- `docs/qa-evidence/media-paths-reconcile-2.7.268-2026-05-28/heavy-a2-from-b1/`

---

## BUG-QA-003 — `MEDIA_PATHS_RECONCILE_DONE` 未寫入日誌（job 已有 `media_paths`）

| 欄位 | 內容 |
|------|------|
| **優先** | **P2** |
| **類型** | 可觀測性／BUG-SUN-005 |

### 現象

還原完成後：

- Job JSON：**有** `media_paths`、`checkpoints.media_paths_phase: done`（`fixed: 0`）。  
- `backup-lite-2026-05-28.log`：**無** `MEDIA_PATHS_RECONCILE_DONE` 字串。

程式 `class-restore-media-paths.php` 在 `apply_pairs` 階段應呼叫 `museder_restoreone_log(…, 'MEDIA_PATHS_RECONCILE_DONE', …)`。

### 預期

依 `QA-PRETEST` TC-02：完成 cleanup 後日誌應有該事件（`fixed` 可為 0）。

### 建議

- 確認 reconcile 是否略過 `apply_pairs` 仍標記 `done`。  
- 若 `scanned=0` 為預期，仍應記錄 reconcile 完成以便 sunpower 除錯。

---

## GAP-QA-004 — sunpower（T-SUN）掉圖修復未做 E2E 驗證

| 欄位 | 內容 |
|------|------|
| **優先** | **P0（簽核缺口，非程式碼回歸）** |
| **類型** | 測試覆蓋 |

### 說明

- repo **無** `sunpoweroflight.com-20260311014315-ptq9eY.zip`。  
- 大站備份 `fixed: 0` **不能**證明中文 DB／ASCII 磁碟已修復。  
- **發佈 sunpower 修復聲明前**需補 TC-01～04。

### 請求

提供 T-SUN 至 QA（勿 commit）後重跑 `QA-PRETEST` §6 TC-01～05。

---

## BUG-QA-005 — E2E 腳本 `SkipBackup` 未帶檔名時 WP-CLI eval 語法錯誤

| 欄位 | 內容 |
|------|------|
| **優先** | **P2** |
| **類型** | QA 工具 |

### 現象

`run-heavy-site-full-e2e-browser.ps1 -SkipBackup`（未傳 `-BackupZip`）在 PowerShell 下觸發：

`Parse error: unexpected token "*"`（`wp eval` 內 `glob(...)` 被 shell 破壞）。

### 修復建議

- 強制要求 `-BackupZip`，或改為 `wp eval-file`／PHP 取最新 zip。  
- 與本輪手動加 `-BackupZip localhost-20260527173501-nKKj4r.zip` 可避開。

---

## 本輪未確認需修（勿與上列混淆）

| 項目 | 狀態 |
|------|------|
| BUG-SUN-005 對帳邏輯是否存在 | **已存在**（cleanup 寫入 `media_paths`） |
| Plugin Check | **0 ERROR** |
| 乾淨 smoke | **Pass** |
| R-S2 round12 | **Pass** |
| UI 顯示 media paths 細節 | **Pass**（無感） |
| musederlabs Step1 Analysis failed | **另案**，本輪未回歸 |

---

## 建議修復順序（開發 Agent）

1. **BUG-QA-001**（還原 90%／job_status null）— 解鎖大站與自動化。  
2. **BUG-QA-002**（A2 resume／跨站）— 與 001 一併驗證。  
3. **BUG-QA-003**（日誌）— 方便 sunpower 上線後支援除錯。  
4. **GAP-QA-004** — QA 補測（需測資）。  
5. **BUG-QA-005** — 腳本（可順便）。

---

## 開發修復紀錄（2026-05-28，版號仍 2.7.268）

| ID | 狀態 | 修復摘要 |
|----|------|----------|
| **BUG-QA-001** | **第二輪已修（待 QA 第三輪）** | 根因：DB 匯入後 nonce 失效；已接 `restore_token` 至 tick/status + JS/E2E + 多 slice tick |
| **BUG-QA-002** | **預期隨 001** | 待第三輪 A2 |
| **BUG-QA-003** | **已修** | `log_reconcile_done()` 於 filter 跳過、uploads 缺失、apply_pairs、verify 完成皆寫入 `MEDIA_PATHS_RECONCILE_DONE`（含 `fixed=0`） |
| **GAP-QA-004** | **未改程式** | 仍缺 T-SUN 測資 |
| **BUG-QA-005** | **已修** | `run-heavy-site-full-e2e-browser.ps1`：`SkipBackup` 強制 `-BackupZip` |

**變更檔案：** `includes/helpers.php`、`includes/class-restore-service.php`、`includes/class-restore-handler.php`、`includes/class-restore-media-paths.php`、`tools/qa/run-heavy-site-full-e2e-browser.ps1`

---

## 修復後建議回歸

```powershell
# 封裝
.\tools\package-lite-windows.ps1

# Plugin Check + smoke（8080）
docker compose run --rm wpcli plugin check museder-restoreone

# 大站同站（應無需手動 resume 即 100%）
powershell -File tools\qa\run-heavy-site-full-e2e-browser.ps1 -SkipBackup -BackupZip 'localhost-20260527173501-nKKj4r.zip'

# R-S2
powershell -File tools\qa\run-r-s2-round12.ps1

# sunpower（有 zip 後）
# 依 QA-PRETEST TC-01～05
```

---

## 相關文件

| 文件 | 用途 |
|------|------|
| `docs/QA-TEST-REPORT-2.7.268-RESTORE-MEDIA-PATHS.md` | 完整測試報告 |
| `docs/QA-PRETEST-2.7.268-RESTORE-MEDIA-PATHS.md` | 測試規格 |
| `docs/qa-evidence/media-paths-reconcile-2.7.268-2026-05-28/` | 證據 |
