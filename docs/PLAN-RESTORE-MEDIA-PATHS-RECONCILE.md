# 還原階段媒體路徑自動對帳 — 規格／計畫／實作／檢查

**狀態：** 2.7.268 已落地核心；本文件為完整流程與驗收基準  
**關聯：** `docs/BUG-INVESTIGATION-2026-05-28-sunpower-uploads-db-disk-mismatch.md`、`includes/class-restore-media-paths.php`  
**Bug ID：** BUG-SUN-005

---

## 0. 白話目標

還原後若出現「資料庫寫的路徑」與「硬碟實際檔名」不一致（常見：DB 含中文、zip 內檔案為 ASCII），外掛在**收尾階段自動改資料庫與相關設定**，減少破圖／404。無法唯一判定的**不亂改**，只記錄。

---

## 1. 規格（Specification）

### 1.1 問題定義

| 項目 | 說明 |
|------|------|
| **觸發條件** | 備份包內 `database.ndjson` 的 `_wp_attached_file` 等與 `wp-content/uploads/**` zip 條目路徑不一致 |
| **典型症狀** | 還原完成後 `<img>`、Elementor、widget 指向含中文的 uploads URL → HTTP 404；同目錄存在 ASCII 檔 |
| **非本規格範圍** | 檔案未進備份、CDN 外鏈、原站備份前已缺檔 |

### 1.2 功能需求（FR）

| ID | 需求 | 優先 |
|----|------|------|
| FR-1 | 全量還原（含 bootstrap）在 **cleanup** 自動執行媒體路徑對帳 | P0 |
| FR-2 | 以 **磁碟實檔** 為準更新 `_wp_attached_file` | P0 |
| FR-3 | 將 old→new 路徑套用到全庫文字欄（serialized-safe search-replace） | P0 |
| FR-4 | 對帳分片執行，不阻塞 shared hosting timeout | P0 |
| FR-5 | 記錄 `fixed` / `unresolved` / 日誌 `MEDIA_PATHS_RECONCILE_DONE` | P0 |
| FR-6 | 無法唯一對照時 **不寫入** 錯誤配對 | P0 |
| FR-7 | 可透過 filter 關閉：`museder_restoreone_reconcile_upload_paths` | P1 |
| FR-8 | 還原完成後 job meta 含 `media_paths` 摘要 | P1 |
| FR-9 | 收尾後抽樣驗證 `file_exists`，缺檔寫入 `verify_missing` | P1 |
| FR-10 | **不**在 UI／完成訊息暴露對帳細節；僅 log + job `media_paths` | P0 |

### 1.3 對照演算法（依序）

1. 路徑已存在於 `uploads` → 跳過  
2. 同目錄 + **ASCII fold 檔名** 唯一候選（`LOGO_曙光…` ↔ `LOGO_01-7`）  
3. 同目錄 + 副檔名 + **filesize**（`_wp_attachment_metadata.filesize`）  
4. 同目錄 + 同副檔名僅 **一個** 檔案（可 filter 關閉）  
5. 否則 → `unresolved++`，記錄最多 10 筆 sample  

### 1.4 管線位置

```text
… → restore-files → restore-extract-db → restore-db → search-replace（網域）
    → cleanup:
         flush_cache → delete_transients → flush_rewrite
         → fix_urls
         → reconcile_upload_paths   ← 本功能
         → restore_plugin_status → … → done
```

**前置：** `wp_upload_dir()` 可用、uploads 已解壓、DB 已匯入。

### 1.5 非功能需求（NFR）

- 單次 slice 預設與 cleanup 相同（約 10–25s）  
- 不修改原備份站（僅目標還原站）  
- WordPress.org：direct DB 僅 attachment meta；search-replace 沿用既有 PHPCS 區塊  
- 前綴：`Museder_Restoreone_Restore_Media_Paths`、`museder_restoreone_`

### 1.6 明確不做（本版）

- 備份階段改寫 ndjson（另案）  
- 還原時重新命名磁碟檔以遷就 DB  
- 保證 100% 附件修復（極端重新命名需人工）

---

## 2. 計畫（Plan）

### 2.1 里程碑

| 階段 | 內容 | 狀態 |
|------|------|------|
| M1 | `class-restore-media-paths.php` + cleanup 步驟 | ✅ |
| M2 | job meta `media_paths` + verify 階段；UI 僅顯示「Finalising restore…」 | ✅ |
| M3 | 還原 UI 顯示摘要 | **取消**（無感自動修復） |
| M4 | 備份 manifest 警告 | **取消**（還原 reconcile 已足夠） |

### 2.2 檔案清單

| 檔案 | 變更 |
|------|------|
| `includes/class-restore-media-paths.php` | 對帳核心、verify、report |
| `includes/class-restore-service.php` | cleanup case、`apply_path_replacements_for_restore()` |
| `museder-restoreone.php` | require 新類別 |
| `docs/PLAN-RESTORE-MEDIA-PATHS-RECONCILE.md` | 本文件 |
| `docs/BUG-LOG-SUNPOWER-2026-05.md` | BUG-SUN-005 |

### 2.3 風險與緩解

| 風險 | 緩解 |
|------|------|
| 誤配檔案 | 僅在唯一候選或 filesize 吻合時配對 |
| 大站 uploads 索引慢 | 分片；索引每 job 重建一次 |
| search-replace 全表耗時 | 僅對 collected pairs；沿用 sliced SR 若需擴充可後續 |
| 舊 job 無 `media_paths` checkpoint | 無 checkpoint 時從 `build_index` 開始 |

---

## 3. 實作（Implementation）

### 3.1 類別 API

| 方法 | 說明 |
|------|------|
| `reconcile_sliced( &$cleanup, $timeout, $start, $job_id )` | cleanup 入口；phase 狀態機 |
| `build_uploads_index( $basedir )` | 建索引 |
| `ascii_fold_filename( $name )` | 公開；單元測試／除錯用 |
| `build_report_from_cleanup( $cleanup )` | 產出寫入 job meta 的陣列 |

### 3.2 Cleanup checkpoint 鍵

| 鍵 | 用途 |
|----|------|
| `media_paths_phase` | `build_index` \| `scan_meta` \| `apply_pairs` \| `verify` \| `done` |
| `media_paths_last_meta_id` | postmeta 分批游標 |
| `media_paths_pairs` | search-replace 用 |
| `media_paths_fixed` / `unresolved` / `scanned` | 計數 |
| `media_paths_unresolved_samples` | 最多 10 筆 `{db,disk?}` |
| `media_paths_verify_*` | 驗證階段計數 |

### 3.3 Filters

```php
apply_filters( 'museder_restoreone_reconcile_upload_paths', true, $job_id, $cleanup );
apply_filters( 'museder_restoreone_media_paths_allow_single_dir_candidate', true, $job_id );
```

### 3.4 日誌事件

| 事件 | 層級 | 時機 |
|------|------|------|
| `MEDIA_PATHS_RECONCILE_DONE` | info | apply_pairs 完成 |
| `MEDIA_PATHS_VERIFY_WARN` | warning | verify 後仍有缺檔 |

### 3.5 Job meta 範例（完成後）

```json
"media_paths": {
  "fixed": 142,
  "unresolved": 8,
  "scanned": 405,
  "verify_checked": 200,
  "verify_missing": 3,
  "samples_unresolved": [
    { "db": "2020/02/主選單圖示_綠能知識01.png" }
  ]
}
```

---

## 4. 檢查（Verification / QA）

### 4.1 單元／靜態

```bash
php -l includes/class-restore-media-paths.php
php -l includes/class-restore-service.php
```

### 4.2 整合：sunpower 類備份

**前置：** 備份含 DB 中文 path + zip ASCII uploads（見調查文件抽樣）

| # | 步驟 | 通過標準 |
|---|------|----------|
| 1 | 部署 2.7.268+ 至測試站 | 含 `class-restore-media-paths.php` |
| 2 | bootstrap 或一般全量還原該 zip | job `completed: true` |
| 3 | 查 log | 有 `MEDIA_PATHS_RECONCILE_DONE`，`fixed > 0` |
| 4 | 讀 job json | 含 `media_paths` 物件 |
| 5 | SQL 抽樣 | `_wp_attached_file` 對應檔案 `file_exists` |
| 6 | 前台首頁／含圖文章 | 原 404 圖片 URL → 200（抽樣） |
| 7 | `unresolved` | 可 >0；不應大量誤配（人工看 5 張圖） |

### 4.3 WP-CLI 抽樣（還原完成後）

```bash
wp eval '
$u = wp_upload_dir();
$basedir = $u["basedir"];
$missing = 0; $n = 0;
$q = new WP_Query(["post_type"=>"attachment","posts_per_page"=>50,"fields"=>"ids"]);
foreach ($q->posts as $id) {
  $rel = get_post_meta($id, "_wp_attached_file", true);
  if (!$rel) continue;
  $n++;
  if (!file_exists($basedir . "/" . $rel)) $missing++;
}
echo "sampled=$n missing=$missing\n";
'
```

**通過：** `missing` 為 0 或僅已知 unresolved 列。

### 4.4 迴歸

| 案例 | 預期 |
|------|------|
| 新站還原、路徑一致 | `fixed=0`，無錯誤 |
| filter 關閉 reconcile | log 顯示 skipped |
| 僅 DB 還原 / files-only | uploads 索引為空或跳過，不 fatal |
| R-S2 bootstrap E2E | 仍 Pass（與 reconcile 無衝突） |

### 4.5 Plugin Check / 發佈

- 不 bump 版號時：重打 `dist/museder-restoreone-2.7.268.zip` 須含新檔  
- `wp plugin check` 不受本類別影響（無新 ERROR 預期）  
- 見 `docs/RELEASE_CHECKLIST.md`

---

## 5. 驗收簽核（Sign-off）

| 角色 | 項目 | 簽核 |
|------|------|------|
| Dev | M1+M2 程式合併、php -l | ☐ |
| QA | §4.2 sunpower 或同等備份還原 | ☐ |
| QA | §4.4 迴歸 | ☐ |
| PM | 白話：還原後自動修路徑、少數需人工 | ☐ |

---

## 6. 修訂紀錄

| 日期 | 說明 |
|------|------|
| 2026-05-28 | 初版：規格／計畫／檢查；M1 已實作 |
| 2026-05-28 | M2：verify 階段、job meta、完成訊息 |
| 2026-05-28 | 無感 UX：進度／完成訊息不顯示 media paths；M3/M4 標記取消 |
