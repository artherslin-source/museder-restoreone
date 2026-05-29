# Bug 調查報告：musederlabs.com Step 3 還原中斷（2.7.270）

| 項目 | 內容 |
|------|------|
| **站點** | https://musederlabs.com/ |
| **主機文件根目錄** | `/home/eo8lmixijvfj/public_html/musederlabs.com_OFF` |
| **外掛版本（SSH 已確認）** | **2.7.270**（`Version: 2.7.270` / `MUSEDER_RESTOREONE_BUILD_ID: 2.7.270`） |
| **PHP** | 8.3.31 |
| **備份檔** | `musederlabs.com-20251225005505-PIQo5C.zip`（約 857 MB；截圖 OCR 可能寫成 `PJQo5C`） |
| **SHA1** | `fb89f1309427d85b74fa1d20c00c4b9bcb3d2aaa` |
| **還原 Job ID** | `rjb_20260529_095335_5p10cg` |
| **嚴重度** | **P0** — 還原中途 UI 重置、後端 job 卡住、站點可能處於不一致狀態 |
| **調查日期** | 2026-05-29（UTC 取證至約 10:02） |
| **調查方式** | SSH 唯讀（**未改**主機／站點任何檔案與程式碼） |
| **前案** | Step 1 preflight（2.7.269）、2.7.270 Step 1 QA 驗收 PASS |

---

## 1. 問題摘要（使用者可見 — 與截圖一致）

使用者在 **Step 3 – Execute Restore** 執行還原（約 857 MB 備份 `PIQo5C.zip`）過程中，畫面**突然跳回初始待命狀態**：

| UI 區塊 | 顯示 | 語意 |
|---------|------|------|
| Step 3 狀態 | `Ready to start restore.` | 精靈認為還原**未進行** |
| 進度區 | `Waiting for action...` | 進度面板隱藏、回到待命文案 |
| Step 3 按鈕 | `Step 3 – Start Restore` 可再按 | `restoreInProgress === false` |
| Restore History | `No restore history found.` | 表格為空 |
| Step 2 選項 | **Files-only restore** 已勾選 | 與 job meta `files_only: true` 一致 |
| Restore scope | Full site（radio） | job meta `restore_scope: full`（與 files-only 並存） |

使用者描述為「還原階段 P3，在還原進程中中斷，視窗跳回如截圖」。

**重要：** 伺服器端調查顯示還原 job **並未成功完成**，也**未寫入 failed 結束紀錄**；後端仍為 `running` 且卡在 `restore-files` 階段。

---

## 2. 時間線（UTC，來自 `backup-lite-2026-05-29.log`）

| 時間 (UTC) | 事件 |
|------------|------|
| 09:52:12 | 外掛升級／啟用 **2.7.270** |
| 09:52:26–40 | 大檔 prepare；偵測 DB prefix `mxmc_` → 目標 `cwfv_` |
| 09:53:35 | `Restore job prepared` — `rjb_20260529_095335_5p10cg` |
| 09:53:38 | `Restore job validated` |
| 09:53:39–45 | Pre-restore snapshot 成功（`musederlabs.com-20260529095339-jcT6j2.zip`，約 27 MB） |
| 09:54:11 | `Restore files: self-protect skipped plugin files`（skipped 105）— **日誌最後一行** |
| 09:54:26 | job meta `updated_at`（進度 93%，見 §4）— **之後無新日誌** |

從 `restore_started_at` 到 `last_tick` 僅約 **31 秒**的 tick 活動；調查時（約 10:02 UTC）job 仍為未完成狀態。

---

## 3. 主機證據（2.7.270，唯讀）

### 3.1 Job meta（`jobs/rjb_20260529_095335_5p10cg.json`）

```json
{
  "stage": "restore-files",
  "progress": 93,
  "message": "Restoring WordPress core and site root files…",
  "completed": false,
  "started_at": 1780048425,
  "last_tick": 1780048456,
  "tick_source": "cron",
  "options": {
    "overwrite": true,
    "auto_backup": true,
    "wp_config_mode": "backup",
    "restore_scope": "full",
    "files_only": true,
    "pause_other_plugins": true,
    "safe_mode": true,
    "db_source_prefix": "mxmc_",
    "db_target_prefix": "cwfv_"
  },
  "checkpoints": {
    "zip_total_entries": 29005,
    "zip_files_phase": 1,
    "zip_index": 824,
    "zip_entry_offset": 90112,
    "zip_skipped_self": 105
  }
}
```

**解讀：**

- 實際只處理 **824 / 29005**（約 2.8%）ZIP 條目，但 `progress` 已顯示 **93%**（見 §5.3 進度計算）。
- `tick_source: cron` — 切片由 WP-Cron 驅動；`last_tick` 停止更新表示 **cron 切片未再執行**或執行失敗且未記錄。
- `files_only: true` — 跳過 DB 還原；但 `wp_config_mode: backup` 仍會從備份覆寫 `wp-config.php`（見 §5.4）。

### 3.2 Restore history（`restore-history.json`）

```json
[{
  "job_id": "rjb_20260529_095335_5p10cg",
  "file": "musederlabs.com-20251225005505-PIQo5C.zip",
  "result": "running",
  "restore_started_at": 1780048425,
  "restore_completed_at": 0
}]
```

**與截圖「無歷史」的關係：** History 表格由 **PHP 初次渲染**（`page-restore.php`），`admin.js` 的 `renderHistory()` 已停用。使用者在**同一頁 session** 內啟動還原時，表格不會即時更新；截圖顯示空表 **符合「未重新載入頁面」** 的行為，不一定是 history 檔案缺失。若調查時重新載入還原頁，應可看到 `running` 一筆（開發 agent 可驗證）。

### 3.3 鎖定與暫存

| 路徑 | 狀態 |
|------|------|
| `temp/rjb_20260529_095335_5p10cg/run.lock` | 存在（空檔，mtime 09:53:45 主機本地） |
| `jobs/rjb_20260529_095335_5p10cg/` | 目錄存在、為空 |
| `temp/rjb_20260529_095335_5p10cg/` | 約 8 KB |

### 3.4 `wp-config.php` 與表前綴

- 檔案 mtime：**2026-05-29 02:54**（與還原檔案階段同時）。
- 大小 **3729 bytes**，與備份 ZIP 內 `wp-config.php` 條目大小一致 → **已從備份覆寫**。
- 目前 `$table_prefix = 'mxmc_'`（備份來源 prefix）。
- 還原前站點使用 **`cwfv_`**（prepare 日誌 `target_prefix: cwfv_`）。
- **`files_only: true` 未還原 DB**，但 **wp-config 已改為 `mxmc_`** → 站點 DB 連線／表前綴可能與實際資料庫內容不一致（潛在 **站點損壞** 因素）。

### 3.5 錯誤日誌

- `backup-lite-2026-05-29.log`：31 行，**無 ERROR / failed / complete**。
- 站點 `error_log`：僅見調查用 PHP one-liner 的 parse error，**無還原 fatal 紀錄**。
- WP-CLI `wp option get`：**資料庫連線錯誤**（可能與 prefix／credentials 不一致有關；調查時未改任何設定）。

---

## 4. 根因分析（鎖定 2.7.270 相關）

### 4.1 【高信心】頁面注入的 `job` 缺少 `status` 欄位 → 重載後 UI 強制重置

**後端：**

- `Museder_Restoreone_Restore_Service::status()` 回傳 `stage`、`progress`、`completed` 等，**沒有** `status` 鍵。
- `Museder_Restoreone_Restore_Handler::map_restore_service_status_to_job()` 會推導 `status: 'running' | 'success' | …`，但 **僅用於 AJAX**（`job_status` 等）。
- `museder-restoreone.php` 的 `museder_restoreone_render_restore_page()` 將 **未映射** 的 `status()` 直接 merge 進 `MusederRestoreOneRestore.job`：

```php
'job' => $museder_restoreone_active_job
    ? array_merge( [ 'id' => $museder_restoreone_active_job_id ], $museder_restoreone_active_job )
    : null,
```

**前端（`admin.js`）：**

```javascript
var jobStatus = restoreData.job.status || '';
// ...
} else {
    // Unknown status or empty status - treat as failed to prevent auto-resume
    console.warn('[Backup Lite] Job has unknown or empty status on page load, resetting state');
    setProgress(0, '', false);  // → 「Waiting for action...」
    syncWizard();                 // → 「Ready to start restore.」
}
```

**結論：** 只要使用者在還原進行中 **重新載入** 還原頁（或 session 恢復觸發同一初始化路徑），即使後端 job 仍為 `running`，前端也會 **刻意不恢復監控** 並重置 Step 3。此為 **2.7.270 仍存在的頁面載入／AJAX 回應不一致**（映射函式已有，頁面注入未用）。

---

### 4.2 【高信心】同一頁 session 內 History 不更新 → 截圖「無歷史」

- `templates/page-restore.php` 以 `$museder_restoreone_history_rows` **伺服器端渲染**。
- `admin.js` L5114–5116：`renderHistory(restoreData.history)` **已註解停用**。
- 還原開始後 `restore-history.json` 會寫入 `running`，但 **不會刷新 DOM**。

**結論：** 截圖空白 History **不能** 證明後端未寫入 history；與本次取證 `result: running` **不矛盾**。

---

### 4.3 【高信心】後端還原 job 卡住 — cron 切片在 `restore-files` 停止

| 觀察 | 推論 |
|------|------|
| `completed: false`、`stage: restore-files` | 還原未結束 |
| `last_tick` 停滯、`tick_source: cron` | WP-Cron／loopback 未再推進切片 |
| 日誌無後續、無 exception | 可能 silent fail、process 被 kill、或 cron 未觸發 |
| `zip_index: 824` / `29005` | 大量檔案尚未解壓 |

**可能促成因素（需開發 agent 在 2.7.270 程式內驗證，本次未改碼）：**

1. **於還原進行中覆寫 `wp-admin`／`wp-includes`／`wp-config.php`**，導致後續 admin-ajax／cron HTTP 請求失敗。
2. 主機 **PHP `max_execution_time`、LiteSpeed 連線限制、磁碟 I/O** 對 857 MB／29k 檔案解壓不足。
3. `pause_other_plugins` 與 safe mode 交互後，cron 觸發路徑異常（需對照 `spawn_cron`／`museder_restoreone_nudge_wp_cron`）。

---

### 4.4 【中信心】輪詢錯誤路徑在特定條件下重置 UI（同一頁、未重載）

`admin.js` 在 `pollRestoreJob` 的 `catch` 中，若 `activeRestoreJobId !== jobId` 且進度 &lt; 100%，會 **立即** `setProgress(0)` 並 `restoreInProgress = false`（L3928–3941）。

另：`checkRestoreCompletionFromHistory()` 對 history `result === 'running'` **無保留監控邏輯**（僅處理 success／failed），網路錯誤後 fallback 無法讓 UI 維持「進行中」。

還原 core 檔案期間的 **AJAX 500／timeout** 可能觸發上述路徑，造成使用者所見「進行中突然跳回待命」**無需整頁重載**。

---

### 4.5 【中信心】`files_only` + `wp_config_mode: backup` 造成站點不一致

- `should_skip_wp_config_in_zip()` 僅在 `wp_config_mode === keep` 時跳過；本案為 **`backup`** → ZIP 內 `wp-config.php` **有還原**。
- `files_only: true` **不還原 DB**，但 wp-config 的 `$table_prefix` 已變為備份的 **`mxmc_`**，與還原前 **`cwfv_`** 實際資料不符。
- 可能導致後續 admin／WP-CLI 連線異常，進而讓 **輪詢與 cron 更容易失敗**。

---

### 4.6 【設計問題】進度 93% 與實際解壓進度脫節

`class-restore-service.php` 在 `zip_files_phase === 1` 時：

```php
$phase_base = 90 + ( $zip_phase * $phase_span );  // = 93
$pct = $zip_index / $zip_total;                   // 824/29005 ≈ 2.8%
$meta['progress'] = min( 96, $phase_base + floor( $pct * $phase_span ) );  // ≈ 93
```

進入 core 還原階段時進度條 **起跳至 93%**，易讓使用者以為「快完成」，與後端實際工作量嚴重不符。此為 **UX／進度模型** 問題，可能加劇「停住像當機」的感知。

---

## 5. 與 2.7.270 的關聯

| 面向 | 說明 |
|------|------|
| Step 1 preflight | 2.7.270 已修復並通過 QA（本案 Step 1–2 已成功到 Step 3） |
| **Step 3 回歸** | 本案為 **還原執行／監控／頁面恢復** 路徑，與 Step 1 修復無直接重疊 |
| 版本指紋 | 日誌明確 `2.7.270`；job／history 皆為本次 session 產物 |
| 疑似 2.7.270 缺陷 | ① 頁面 `job` 未 `map_restore_service_status_to_job`；② running history 不驅動 UI；③ 大檔 ZIP phase-1 進度模型；④ files-only 仍覆寫 wp-config 的策略 |

---

## 6. 建議開發 Agent 驗證項目（僅調查方向，**非修復方案**）

1. **重現頁面重載：** active job 存在時載入還原頁，確認 `MusederRestoreOneRestore.job.status` 是否為 `undefined`，以及是否命中 `admin.js` L5156–5170。
2. **統一 job 形狀：** 比對 `map_restore_service_status_to_job()` 是否應用於 `museder_restoreone_render_restore_page()` 與所有 REST／AJAX 出口。
3. **卡住 job：** 對 `rjb_20260529_095335_5p10cg`（或本地 857 MB fixture）追蹤 cron tick、`run.lock`、切片例外處理為何無日誌。
4. **files_only + wp-config：** 釐清產品預期 — files-only 是否應強制 `wp_config_mode: keep` 或 merge。
5. **進度模型：** phase 切換時避免進度回跳／虛高（824/29005 不應顯示 93%）。
6. **History UX：** 還原開始後是否應 AJAX 刷新 history，或恢復 `renderHistory`。
7. **輪詢 fallback：** `result: running` 時應恢復 `startRestoreJobMonitor`，而非靜默或 reset。
8. **站點修復（維運）：** 主機上 job 仍 `running`、prefix 可能不一致 — 需人工決策是否 rollback 至 `jcT6j2` snapshot（**超出本次調查範圍**）。

---

## 7. 調查時站點狀態（供維運參考）

- 還原 job **`rjb_20260529_095335_5p10cg` 仍為未完成**（`completed: false`）。
- Pre-restore snapshot 可用：`musederlabs.com-20260529095339-jcT6j2.zip`（約 27 MB，09:53:45 UTC）。
- 備份來源檔仍在：`musederlabs.com-20251225005505-PIQo5C.zip`。
- **不建議** 在未釐清 prefix／job 鎖狀態下再次按 Step 3；必要時先 Force Unlock／清理 stuck job（維運操作，非本次調查執行項）。

---

## 8. 相關程式位置索引

| 檔案 | 行為 |
|------|------|
| `museder-restoreone.php` ~L396–450 | 頁面注入 `history`、`job`（**job 未映射 status**） |
| `includes/class-restore-handler.php` ~L2505–2534 | `map_restore_service_status_to_job()` |
| `includes/class-restore-handler.php` ~L809–856 | AJAX `job_status`（**有映射**） |
| `includes/class-restore-service.php` ~L3952–3968 | `status()` 回傳形狀 |
| `includes/class-restore-service.php` ~L2436–2439 | ZIP phase-1 進度計算 |
| `includes/class-restore-preflight.php` ~L346–348 | `should_skip_wp_config_in_zip()` |
| `assets/js/admin.js` ~L4397–4452 | `syncWizard()` → Ready to start restore |
| `assets/js/admin.js` ~L4562–4646 | `setProgress(0)` → Waiting for action |
| `assets/js/admin.js` ~L5119–5171 | 頁面載入 job 狀態分支 |
| `assets/js/admin.js` ~L3928–3941 | 輪詢錯誤且無 active job 時重置 |
| `templates/page-restore.php` ~L361–439 | History 伺服器渲染 |

---

## 9. 結論（給開發 Agent 的一句話）

**2.7.270 在 musederlabs 上的 Step 3 失敗，是「後端大檔還原 job 在 `restore-files`（cron 切片）停滯」與「前端在頁面重載或輪詢異常時將進行中 job 誤判為未知狀態而重置 UI」疊加造成；截圖空白 History 主要來自 History 僅初次渲染、非後端無紀錄。優先修復頁面 `job.status` 注入一致性，並調查 cron 切片在 core 檔案還原期間停止的原因及 `files_only` 仍覆寫 `wp-config` 的影響。**

---

*本報告為唯讀調查產出；未包含主機密碼；未對 musederlabs.com 進行任何寫入或設定變更。*
