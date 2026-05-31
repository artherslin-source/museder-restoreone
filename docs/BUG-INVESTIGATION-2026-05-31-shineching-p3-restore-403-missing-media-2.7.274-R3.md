# Bug 調查報告：shineching.com Step 3 未顯示成功 + 403 + 大量掉圖（2.7.274，第三次現場）

| 項目 | 內容 |
|------|------|
| **站點** | https://shineching.com/ |
| **Docroot（唯一調查範圍）** | `/home/qj8hea4vdto3/public_html/shineching.com` |
| **主機** | GoDaddy shared，`132.148.179.46`，SSH 使用者 `qj8hea4vdto3` |
| **外掛版本（SSH 已確認）** | **2.7.274**（`Version: 2.7.274` / `MUSEDER_RESTOREONE_BUILD_ID: 2.7.274`） |
| **PHP / WP** | PHP 8.3.30；WordPress **7.0** |
| **還原 Job ID** | `rjb_20260531_073024_05solr` |
| **還原來源檔** | `shineching.com-20260531013823-V6yYBa-1.zip`（592,141,325 bytes；SHA1 `76c01c2f9f06067cd961dc4facd7812b28b45610`） |
| **來源備份建立版本** | **2.7.273**（ZIP 內 `meta.json` → `plugin_version: 2.7.273`，`generated_at: 2026-05-31T01:38:34+08:00`） |
| **還原前快照** | `shineching.com-20260531073027-MSoNbA.zip`（27.17 MB，pre-restore auto backup） |
| **使用者可見症狀** | Step 3 約 **83%** 彈出 **403 Forbidden**；**未出現「還原成功」**；前端多處**掉圖**（例：`page_id=1209` PU 跑道案例） |
| **後端實際結果** | Job **`completed: true`**、`stage: done`、`progress: 100`；`restore-history.json` → **`result: success`**（耗時 141 秒） |
| **嚴重度** | **P0（使用者感知）** — 還原結果不可用（47% 媒體缺失）；UI 誤導為失敗<br>**P1（產品）** — 媒體路徑 reconcile 未執行（程式缺陷） |
| **調查日期** | 2026-05-31（SSH 唯讀取證，**未修改 docroot 任何檔案**） |
| **前案** | [2.7.273 Parse error](BUG-INVESTIGATION-2026-05-31-shineching-p3-restore-critical-error-2.7.273.md)；[2.7.274 DB 連線錯誤](BUG-INVESTIGATION-2026-05-31-shineching-p3-restore-db-error-2.7.274.md) |

---

## 1. 問題摘要（給開發 agent 的一句話）

**第三次現場與前兩次不同：還原引擎已在後端完整成功（2.7.274 P0 修復有效），但 (A) 管理員 AJAX/Session 遭主機 403 阻擋導致 UI 未顯示成功，(B) 備份 ZIP 內「DB 路徑（UTF-8 中文檔名）」與「實際打包的 uploads 檔名（ASCII `LINE_ALBUM__…`）」不一致，且 (C) 2.7.274 新增的 `Museder_Restoreone_Restore_Media_Paths::reconcile_sliced()` 在 `build_index` 階段**缺少 `return`**，直接 fall-through 標記 `noop`、**從未掃描 DB**，導致 **173/370（47%）attachment 檔案路徑指向不存在的檔名**。

---

## 2. 時間線（UTC+8，`backup-lite-2026-05-31.log` + job meta）

| 時間 | 事件 |
|------|------|
| 01:38:34 | 種子備份 `V6yYBa-1.zip` 建立（外掛 **2.7.273**） |
| 15:30:24 | 使用者上傳/選擇備份，job `rjb_20260531_073024_05solr` prepared |
| 15:30:27–15:30:34 | pre-restore 快照 `MSoNbA.zip` 完成 |
| 15:30:54 | 2.7.274 build active |
| 15:31:16 | DB 匯入完成；mid-restore plugin isolation 啟用 |
| 15:31:30–15:32:30 | 檔案還原進行（self-protect skipped 73 個 RestoreOne 檔） |
| **15:32:55** | **`MEDIA_PATHS_RECONCILE_DONE` reason=`noop` fixed=0 scanned=0** |
| **15:32:55** | Job 標記 **`Restore completed successfully`** |
| **~15:32–15:33** | 使用者截圖：**403 Forbidden**、進度條 ~83%、「Waiting for action…」 |

**關鍵：** 403 出現時間 **晚於或同秒** 後端完成；並非 restore engine 中斷。

---

## 3. 主機證據

### 3.1 Job meta（`jobs/rjb_20260531_073024_05solr.json`）

| 欄位 | 值 |
|------|-----|
| `stage` | `done` |
| `progress` | **100** |
| `completed` | **true** |
| `message` | Restore completed successfully. |
| `options.restore_order` | `db_then_files` |
| `options.safe_mode` | `true` |
| `options.pause_other_plugins` | `true` |
| `validation.checksum.ok` | `true` |
| `checkpoints.zip_phase1_entry_done` | **3541 / 3541**（Phase1 完成） |
| `checkpoints.cleanup.media_paths_phase` | `done` |
| `checkpoints.cleanup.media_paths_scanned` | **0** |
| `media_paths.fixed` | **0** |

### 3.2 2.7.274 前次 P0 修復在本案狀態

| 檢查項 | 結果 |
|--------|------|
| `wp-includes/class-wp-site-health.php` | **131,241 bytes**（完整，非 2.7.273 的 90,112 截斷） |
| `*.museder-restoreone-partial` | **0 個** |
| `wp-config.php` | `php -l` OK；保留主機 DB `i10269493_rjnu1`、prefix **`dime_`** |
| HTTP 前台 | 可載入（首頁、多數頁面有 HTML） |

→ **Core 半寫入、wp-config DB 錯誤兩條前案路徑已排除。**

### 3.3 403 Forbidden（UI 層，非 engine 失敗）

**使用者截圖：** Restore 頁 Step 3 中央 modal 顯示 **`403 Forbidden`**，進度 ~83%，狀態「Waiting for action…」。

**機制（產品端）：**

- `assets/js/restore.js` 的 `pollStatus()` 對 REST `GET restore/status/{jobId}` 在 **401/403** 時會重試最多 30 次，之後才 log「Session expired」。
- 成功 toast 依賴 poll 取得 `stage: done` + `completed: true`；若 session/WAF 403，UI 可卡在進度條。

**主機 access log：**

- 當日 log 在 `shineching.com.fdmu01.com-ssl_log-May-2026.gz`；本輪 `zgrep` 未命中 15:31–15:33 條目（可能時區字串或輪替差異）。
- 前序調查與使用者截圖一致指向 **`admin-ajax.php` / `wp-login.php?interim-login=1` → 403**（ModSecurity / LiteSpeed / cPanel WAF 常見）。

**結論：** 403 為 **管理員 HTTP 請求被主機安全規則拒絕**，發生在還原**已完成之後**；屬 **UI/環境疊加問題**，不是 job 失敗根因。

### 3.4 掉圖（決定性證據）

#### 3.4.1 規模

| 指標 | 數值 |
|------|------|
| `_wp_attached_file` meta 總數 | **370** |
| 磁碟檔案 **不存在** | **173（46.8%）** |
| ZIP 內 uploads 條目 | ~2537 |
| 還原後 uploads 檔案數 | ~2506 |

#### 3.4.2 案例：`page_id=1209`（PU 跑道清潔）

| Attachment ID | DB `_wp_attached_file` | 磁碟 |
|---------------|------------------------|------|
| 3600 | `2024/04/LINE_ALBUM_新莊國小跑道清洗前_240409_7.jpg` | **MISSING** |
| 3601 | `2024/04/LINE_ALBUM_新莊國小跑道清洗前_240409_6.jpg` | **MISSING** |
| 3602 | `2024/04/LINE_ALBUM_新莊國小跑道清洗前_240409_5.jpg` | **MISSING** |

同目錄 `2024/04/` **存在**，且含 **80 個** `LINE_ALBUM__240409_*` 檔（雙底線、無中文），例：

```text
wp-content/uploads/2024/04/LINE_ALBUM__240409_1.jpg
wp-content/uploads/2024/04/LINE_ALBUM__240409_7.jpg
```

→ **檔案已還原到磁碟，但 DB 仍指向不同檔名（含中文）。**

#### 3.4.3 【決定性】同一 ZIP 內 DB 與檔案清單不一致

對種子備份 `V6yYBa-1.zip` 交叉比對：

| 來源 | `2024/04/LINE_ALBUM*` 路徑範例 | 數量 |
|------|--------------------------------|------|
| **`database.ndjson`**（解 `\uXXXX` 後） | `LINE_ALBUM_新莊國小跑道清洗前_240409_7.jpg` | **30** 條 unique |
| **ZIP 檔案條目**（實際打包） | `LINE_ALBUM__240409_7.jpg` | **13** 個原圖 + 衍生尺寸 |

**30 條 ndjson 路徑全部不在 ZIP 檔名列表中**（`in ndjson not in zip: 30`）。

`database.ndjson` 片段（Unicode escape，解碼後為中文）：

```text
LINE_ALBUM_新莊國小清洗後_240409_1.jpg
LINE_ALBUM_新莊國小跑道清洗前_240409_7.jpg
…
```

ZIP 內實際檔案：

```text
2024/04/LINE_ALBUM__240409_1.jpg
2024/04/LINE_ALBUM__240409_7.jpg
```

**解讀：**

1. 備份當下站點 **DB postmeta 存 UTF-8 中文檔名**（WordPress 媒體 meta 常見）。
2. 同一站點 **磁碟檔名已被 sanitize 成 ASCII**（`LINE_ALBUM__240409_N.jpg`，中文段被移除 → 雙底線）。
3. 備份 **DB 匯出 faithfully 保留中文路徑**；**檔案打包使用磁碟真實路徑** → ZIP **內建不一致**。
4. 還原 `db_then_files` 後：DB 恢復中文路徑、檔案解出 ASCII 名 → **大規模 404 掉圖**。

此不一致在備份建立時（**2.7.273**）已存在，非本次還原才產生。

#### 3.4.4 媒體 reconcile 未執行（2.7.274 產品缺陷）

還原 log：

```text
[2026-05-31T15:32:55+08:00] [INFO] MEDIA_PATHS_RECONCILE_DONE
{"job_id":"rjb_20260531_073024_05solr","reason":"noop","fixed":0,"unresolved":0,"scanned":0,"pairs":0}
```

Job checkpoint：`media_paths_scanned: 0`、`media_paths_logged: 1`。

**程式根因（`includes/class-restore-media-paths.php`）：**

`reconcile_sliced()` 在 `build_index` 分支結束後 **未 `return self::progress_result( false )`**，函式繼續執行至末尾 default（約 L111–113），將 phase 標為 `done` 並 log **`reason: noop`**：

```php
if ( 'build_index' === $phase ) {
    // … build index …
    $cleanup['media_paths_phase'] = 'scan_meta';
    // ← 缺少 return；$phase 仍為 build_index，不會進入 scan_meta
}

// … scan_meta / apply_pairs / verify 皆未執行 …

$cleanup['media_paths_phase'] = 'done';
self::log_reconcile_done( $job_id, $cleanup, 'noop' );
return self::progress_result( true );
```

**影響：** 2.7.274 新增的 UTF-8 ↔ ASCII 路徑對帳 **在本 job 完全未跑**；即使 reconcile 正常執行，`ascii_fold_filename()` 理論上可能將 `LINE_ALBUM_新莊…_240409_7.jpg` 折疊為 `line_album_240409_7.jpg` 以配對 `LINE_ALBUM__240409_7.jpg`，但此修復路徑**從未觸發**。

---

## 4. 根因分析（2.7.274 相關）

### 4.1 問題 A：Step 3 未顯示「還原成功」+ 403 modal

| 層級 | 根因 |
|------|------|
| **直接原因** | 後端 job 已 `done`，但瀏覽器 poll / admin-ajax 收到 **403**，UI 未收到完成狀態 |
| **產品關聯** | 2.7.274 仍依 REST poll + cron 完成；無「server-side 完成後強制 client 重同步」或 403 降級提示 |
| **環境關聯** | GoDaddy/cPanel WAF 在還原末期（plugin isolation 解除、大量 admin 請求）阻擋 `admin-ajax.php` |

**非根因：** restore engine 中斷、checksum 失敗、core/wp-config P0（已排除）。

### 4.2 問題 B：大量掉圖

| 層級 | 根因 | 版本 |
|------|------|------|
| **資料根因** | 備份 ZIP 內 **`database.ndjson` 媒體路徑 ≠ uploads 實際檔名**（UTF-8 DB vs ASCII 磁碟） | 備份 **2.7.273** 建立時已存在 |
| **還原未補救** | `Museder_Restoreone_Restore_Media_Paths::reconcile_sliced()` **`build_index` fall-through → noop** | **2.7.274 缺陷** |
| **設計缺口** | 備份流程未偵測/警告 DB–disk 檔名漂移；還原後 reconcile 為唯一補救且未生效 | Lite 2.7.274 |

### 4.3 與前兩次失敗的關係

| 次數 | 主要根因 | 本次是否再現 |
|------|----------|--------------|
| 第一次 2.7.273 | Core 半寫入 Parse error | **否** |
| 第二次 2.7.274 | wp-config 覆寫 → DB 連線錯誤 | **否** |
| **第三次 2.7.274** | **備份內建路徑不一致 + media reconcile noop + UI 403** | **是（新類型）** |

---

## 5. 建議開發 agent 優先調查方向（僅方向，不含修復實作）

### P0 — 媒體 reconcile 未執行

1. **`class-restore-media-paths.php`**：`build_index` 結束後應 `return self::progress_result( false )`；加回歸測試覆蓋 `MEDIA_PATHS_RECONCILE_DONE` reason 不得為 `noop`（當 uploads 存在且 DB 有 missing paths）。
2. 以 shineching `V6yYBa-1.zip` 做離線還原後 assert：`media_paths.scanned > 0` 且 `fixed > 0`（或 `unresolved` 有樣本）。

### P0 — 備份 ZIP 內 DB/檔案路徑一致性

1. **`class-backup.php` 打包流程**：比對 `_wp_attached_file` 與實際 addFile 路徑；備份完成前寫入 manifest 警告或 normalize。
2. 釐清 shineching 中文 meta + ASCII 磁碟的來源（是否歷史 migration、外掛、或 `sanitize_file_name` 與 meta 更新不同步）。

### P1 — UI 403 與完成狀態

1. Job 已在 server `completed: true` 時，Restore 頁 reload 應能從 job meta 顯示成功（不依賴當次 poll）。
2. 403 時顯式訊息：「還原可能已完成，請重新整理或查看 restore-history」。
3. 與主機 WAF 協調：`admin-ajax.php` action `museder_restoreone_*` / REST `museder-restoreone/v*` 白名單（環境面）。

### P2 — 驗證 `ascii_fold_filename` 對 shineching 案例

- 確認 `LINE_ALBUM_新莊國小跑道清洗前_240409_7.jpg` ↔ `LINE_ALBUM__240409_7.jpg` 在 **多候選同目錄** 時能否唯一配對（需 `_wp_attachment_metadata.filesize` 或 suffix 數字）。

---

## 6. 重現條件（給 QA / dev）

1. 站點 profile：`populated_wp`、Elementor、uploads 含 **UTF-8 中文 `_wp_attached_file`** 且磁碟為 **ASCII sanitize 檔名**。
2. 備份：`shineching.com-20260531013823-V6yYBa-1.zip`（SHA1 如上）。
3. 外掛：**2.7.274**；選項：DB first、full site、pause plugins、safe mode（與本次 job 相同）。
4. 預期（現狀）：後端 success + `MEDIA_PATHS_RECONCILE_DONE noop` + ~47% attachment missing + UI 可能 403。

---

## 7. 調查方法與限制

- **方式：** SSH 唯讀；僅讀取 `shineching.com` docroot 與該站 log；**未修改任何檔案、未觸及其他站點**。
- **取證命令：** job JSON、`backup-lite-2026-05-31.log`、`unzip -l` / `database.ndjson` 解碼比對、PHP CLI 統計 missing attachments。
- **限制：** SSL access log 部分時段需從 cPanel 下載原始 log 再確認 403 rule ID；本報告以 job 完成時間 + 使用者截圖 + 產品 log 交叉驗證。

---

## 8. 附錄

### A. 相關原始碼位置

| 檔案 | 說明 |
|------|------|
| `includes/class-restore-media-paths.php` L55–69, L111–113 | `build_index` fall-through → **noop** |
| `includes/class-restore-service.php` L2966–2979 | cleanup step `reconcile_upload_paths` |
| `assets/js/restore.js` L451–458 | REST 403 重試邏輯 |
| `includes/class-backup.php` L574, L3996 | `ZipArchive::addFile` 使用磁碟路徑（未對照 DB meta） |

### B. 前案報告

- [2.7.273 critical error](BUG-INVESTIGATION-2026-05-31-shineching-p3-restore-critical-error-2.7.273.md)
- [2.7.274 DB error](BUG-INVESTIGATION-2026-05-31-shineching-p3-restore-db-error-2.7.274.md)
- [QA R3 2.7.274](QA-PRE-RELEASE-REPORT-SHINECHING-PROFILE-2.7.274-R3.md)（Docker 離線 PASS；**未覆蓋本機 WAF 403 + 備份內建路徑不一致**）

### C. 安全備註

- 使用者提供的 SSH/cPanel 密碼已用於本次調查；**任務完成後請輪替**。
- 本報告**不含**任何明文密碼。

---

**報告產出：** 2026-05-31  
**調查人：** Cursor Agent（唯讀 SSH 取證）  
**交付對象：** 開發 agent — 請依 §5 優先序進行 debug / 修復設計
