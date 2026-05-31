# Bug 調查報告：shineching.com 第四次 Step 3 未顯示成功 + 前端仍掉圖（2.7.274，R4 現場）

| 項目 | 內容 |
|------|------|
| **站點** | https://shineching.com/ |
| **Docroot（唯一調查範圍）** | `/home/qj8hea4vdto3/public_html/shineching.com` |
| **主機** | GoDaddy shared，`132.148.179.46`，SSH 使用者 `qj8hea4vdto3` |
| **外掛版本（SSH 已確認）** | **2.7.274**（`Version` / `MUSEDER_RESTOREONE_VERSION` / `MUSEDER_RESTOREONE_BUILD_ID` 皆為 `2.7.274`） |
| **使用者可見症狀** | Step 3（P3）約 84% / 85% 附近出現 **403 Forbidden**，未正常顯示還原成功；前端多頁仍有綠色 placeholder / 掉圖 |
| **本次 Job ID** | `rjb_20260531_090830_wfhks9` |
| **還原來源檔** | `shineching.com-20260531013823-V6yYBa-1.zip`（ZIP meta 顯示來源備份由 **2.7.273** 產生） |
| **調查日期** | 2026-05-31（SSH 唯讀取證，未修改 docroot 任何檔案） |
| **結論嚴重度** | **P0/P1**：2.7.274 的 R3 修復有生效，但仍未完整處理 `post_content` / Elementor 內的舊媒體 URL 與尺寸變體，前端仍可大量掉圖 |

---

## 1. 一句話結論

第四次現場不是 R3 的 `MEDIA_PATHS_RECONCILE_DONE noop` 回歸；伺服器上的 2.7.274 修復碼確實已生效，job 後端也成功完成，`_wp_attached_file` 已由 **173/370 missing** 修到 **5/370 missing**。

但前端仍掉圖，根因是：**目前 media path reconcile 只從 `_wp_attached_file` 建立「原圖路徑」替換 pair，沒有完整處理 `post_content` / Elementor JSON 中的縮圖尺寸 URL（如 `-1024x768`、`-300x41`）與非 attachment header 圖 URL。** 這些內容仍指向 ZIP 內不存在的中文檔名，而磁碟上實際檔名是 sanitize 後的 ASCII 檔名。

---

## 2. 後端還原狀態

### 2.1 Job meta

| 欄位 | 值 |
|------|-----|
| `id` | `rjb_20260531_090830_wfhks9` |
| `stage` | `done` |
| `progress` | `100` |
| `completed` | `true` |
| `message` | `Restore completed successfully.` |
| `updated_at` | `2026-05-31 17:11:22` |
| `duration_seconds` | `163` |
| `tick_source` | `cron` |

`restore-history.json` 同步顯示：

```text
result=success
restore_completed_at=1780218682
duration_seconds=163
```

### 2.2 還原 log

本次 `backup-lite-2026-05-31.log` 重要行：

```text
[2026-05-31T17:09:25+08:00] Mid-restore plugin isolation enabled
[2026-05-31T17:11:07+08:00] Mid-restore plugin isolation released
[2026-05-31T17:11:20+08:00] MEDIA_PATHS_RECONCILE_DONE {"reason":"apply_pairs","fixed":168,"unresolved":5,"scanned":370,"pairs":336}
[2026-05-31T17:11:21+08:00] MEDIA_PATHS_VERIFY_WARN {"missing":5,"checked":370}
[2026-05-31T17:11:21+08:00] Restore completed. Active plugin list recorded for admin review.
```

**判讀：**

- `reason=apply_pairs`：R3 的 `build_index → noop` bug **未再發生**。
- `scanned=370`、`fixed=168`：media reconcile 真的有掃描並更新 attachment 主路徑。
- `verify_missing=5`：attachment 主路徑層面已剩 5 個缺失，不是使用者截圖中大面積掉圖的主要來源。

---

## 3. 伺服器實際程式碼狀態

SSH 讀取 production 外掛檔案確認：

| 檢查 | 結果 |
|------|------|
| `class-restore-media-paths.php` 是否含 `return self::progress_result( false );` | **是** |
| 是否含 `detect_upload_path_drift()` | **是** |
| `class-backup.php` 是否含 `BACKUP_MEDIA_PATH_DRIFT_DETECTED` | **是** |
| `assets/js/restore.js` 是否含 403 warning | **是** |
| `assets/js/restore.js` 是否含 completed toast `doneMessage` | **是** |

因此，本輪不是「主機仍跑舊包」。

---

## 4. 媒體主檔修復已生效

Live DB `_wp_attached_file` 統計：

| 指標 | 數值 |
|------|------|
| attachment 總數 | 370 |
| missing | **5** |
| missing ratio | **1.4%** |
| drift detector | `drift=0`、`missing=5` |

R3 現場為 **173/370（46.8%）missing**；本次已大幅下降。

使用者前一輪重點案例也已修正：

```text
ATTACH 3600 OK 2024/04/LINE_ALBUM__240409_7.jpg
ATTACH 3601 OK 2024/04/LINE_ALBUM__240409_6.jpg
ATTACH 3602 OK 2024/04/LINE_ALBUM__240409_5.jpg
```

---

## 5. 新發現：前端內容 URL 仍大量指向不存在檔名

### 5.1 DB 文字內容掃描

唯讀掃描 `posts.post_content`、`postmeta.meta_value`、部分 options 中的 `uploads/` URL：

```text
checked_refs=7621
missing_refs=2119
```

此數字是「DB 文字引用」層級，包含重複頁面、Elementor JSON、舊 shortcode / inline HTML，不等同 attachment 數；但足以證明：**前端仍有大量內容 URL 未被 media reconcile 替換。**

### 5.2 截圖相符頁面：經銷品牌 header 圖

使用者截圖第三張位於「經銷品牌」頁面。現場 DB 中相關頁面：

```text
PAGE 1433 aaa-內頁大標題01-經銷品牌
  MISSING 2020/02/200210\u66d9\u5149x\u7db2\u7ad9_\u7d93\u92b7\u54c1\u724c01.png

PAGE 1435 aaa-內頁大標題01-經銷品牌
  MISSING 2020/02/200210\u66d9\u5149x\u7db2\u7ad9_\u7d93\u92b7\u54c1\u724c01.png
```

解碼後為：

```text
2020/02/200210曙光x網站_經銷品牌01.png
```

但磁碟 `2020/02/` 內實際存在的是 sanitize 後檔名：

```text
200210x_01.png
200210x_01-300x38.png
200210x_01-300x41.png
200210x_01-150x73.png
...
```

**判讀：** 這類 header 圖 URL 不一定由 `_wp_attached_file` 主路徑 pair 覆蓋，因此 reconcile 完成後仍 missing。

### 5.3 截圖相符頁面：案例卡片 / 太陽能模組圖

DB 文字內容仍保留舊中文尺寸圖 URL，例如：

```text
posts 1202 案例實績-太陽能模組
  2023/04/LINE_ALBUM_案2-清洗後_230402_5-1024x768.jpg
  2023/04/LINE_ALBUM_案1-清洗後_230402_2-1024x768.jpg

posts 1215 案例實績
  2023/04/LINE_ALBUM_案2-清洗後_230402_5-1024x768.jpg
  2023/04/LINE_ALBUM_案1-清洗後_230402_2-1024x768.jpg
  2023/04/LINE_ALBUM_檢測照片_230402_8-1024x1024.jpg
```

但 ZIP / 磁碟上的對應檔名是 ASCII sanitize 版本：

```text
wp-content/uploads/2023/04/LINE_ALBUM_2-_230402_5-1024x768.jpg
wp-content/uploads/2023/04/LINE_ALBUM_1-_230402_2-1024x768.jpg
...
```

**判讀：** 目前 pair 只包含 attachment `_wp_attached_file` 的原圖路徑，例如：

```text
LINE_ALBUM_案2-清洗後_230402_5.jpg → LINE_ALBUM_2-_230402_5.jpg
```

但頁面實際引用的是尺寸變體：

```text
LINE_ALBUM_案2-清洗後_230402_5-1024x768.jpg
```

所以 `str_replace()` 不會命中，前端仍指向不存在檔案。

---

## 6. 程式層根因定位

### 6.1 `class-restore-media-paths.php`

目前 `scan_attached_file_meta_sliced()` 只掃：

```sql
WHERE meta_key = '_wp_attached_file'
```

找到 match 後只建立兩種 pair：

```php
$pairs[] = [
    'search'  => $rel,
    'replace' => $match,
];

$pairs[] = [
    'search'  => 'wp-content/uploads/' . $rel,
    'replace' => 'wp-content/uploads/' . $match,
];
```

這只能替換「原圖完整檔名」，不能覆蓋：

- WordPress / Elementor 寫死在 `post_content` 的 `-1024x768`、`-300x225`、`-300x41` 等尺寸檔。
- 沒有對應 `_wp_attached_file` row 或已被歷史內容直接寫入的 header / banner 圖。
- 只存在於 `post_content` / Elementor JSON 的 URL，而不是 attachment 主路徑。

### 6.2 `class-restore-service.php`

`apply_path_replacements_for_restore()` 會做全表文字替換，且支援 serialized data；問題不是它沒掃全表，而是 **pair 不完整**。

目前替換邏輯本質上是 exact string replacement：

```php
$value = str_replace( $pair['search'], $replace, $value );
```

若 pair 是：

```text
2023/04/LINE_ALBUM_案2-清洗後_230402_5.jpg
```

就不會替換：

```text
2023/04/LINE_ALBUM_案2-清洗後_230402_5-1024x768.jpg
```

---

## 7. Step 3 未顯示成功 / 403

### 7.1 後端已成功

本次 job 在 17:11:22 後端完成；使用者截圖 17:09–17:10 顯示 84% / 85%，屬完成前後 UI poll / 403 問題，不是 engine failure。

### 7.2 `restore.js` 新邏輯已在主機

伺服器上的 `assets/js/restore.js` 已包含：

```text
Status polling is being blocked by the host...
var doneMessage = data.message || 'Restore completed successfully.';
```

### 7.3 為何仍看見 raw 403 modal

本輪沒有在 cPanel access log 中抓到 17:09–17:10 的詳細 403 rule ID（目前可讀 log 只有月度 gzip），但使用者截圖明確顯示 browser modal `403 Forbidden`。

合理推論仍與 R3 相同：

- 主機 WAF / session / `wp-login.php?interim-login=1` 或 admin request 被 403。
- 403 發生在 UI 層，job 後端由 cron 繼續跑完。
- `restore.js` 的 warning 是 log/toast 類型，若 403 是 WordPress interim-login iframe / modal 或其他 admin request 回應，仍可能被瀏覽器顯示為 raw 403，未被 REST poll handler 接管。

---

## 8. 與 R3 / Docker QA 的差異

R4 Docker QA 驗證了：

```text
media_paths_scanned=370
media_paths_fixed=168
attachment_missing=5/370
attachment_3600_ok
post_restore_drift=0
```

這些在 production R4 **全部一致**。

Docker QA 沒抓到本輪剩餘 bug，原因是當時 E2E assert 僅檢查：

- `_wp_attached_file`
- attachment 3600
- drift detector

但沒有掃：

- `post_content` 內所有 `uploads/` URL 是否存在。
- Elementor `_elementor_data` 中的尺寸變體 URL。
- header / banner / card image 的 inline URL。

---

## 9. 給開發 agent 的 debug 方向（僅調查結論，不提供修改實作）

1. **擴充 media path reconcile 的 pair 生成範圍**
   - 對每個 `_wp_attached_file` old/new base pair，同步衍生 `-WIDTHxHEIGHT` variant pair。
   - 或掃描 `_wp_attachment_metadata['sizes']`，建立各尺寸 old/new pair。

2. **新增 content-level missing URL 掃描**
   - 還原後掃 `post_content`、`postmeta.meta_value`、Elementor JSON 內 `uploads/` URL。
   - 對 missing URL 以 `ascii_fold_filename()` + 同目錄 candidate + 尺寸 suffix 嘗試配對。

3. **補強 QA**
   - 在 `shineching-profile-e2e.php` 加入 DB 文字內容 URL 存在性 assert。
   - 至少覆蓋：
     - `page 1433/1435` 經銷品牌 header 圖。
     - `page 1202/1215` 案例卡片尺寸圖。
     - 全站 `post_content` / Elementor refs missing ratio。

4. **UI 403**
   - 確認 raw 403 是否來自 REST poll、admin-ajax、interim login iframe、或 nonce refresh。
   - 若不是 REST poll，`restore.js` 現有 403 handler 可能無法捕捉。

---

## 10. 本輪未做事項

- 未修改 production 主機任何檔案。
- 未手動修 DB / 圖片路徑。
- 未碰其他站點。
- 未提出或套用修復碼。

---

## 11. 附錄：關鍵證據摘要

```text
JOB rjb_20260531_090830_wfhks9
stage=done
progress=100
completed=true
message=Restore completed successfully.

MEDIA_PATHS_RECONCILE_DONE reason=apply_pairs fixed=168 unresolved=5 scanned=370 pairs=336
MEDIA_PATHS_VERIFY_WARN missing=5 checked=370

attachments_total=370 missing=5 pct=1.4
DRIFT {"scanned":370,"drift":0,"missing":5,"reason":"ok"}

DB textual refs:
checked_refs=7621
missing_refs=2119
```

**報告產出：** 2026-05-31  
**調查方式：** SSH 唯讀取證  
**交付對象：** 開發 agent
