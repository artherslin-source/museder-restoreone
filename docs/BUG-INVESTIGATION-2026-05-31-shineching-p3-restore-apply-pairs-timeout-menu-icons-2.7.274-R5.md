# Bug 調查報告：shineching.com 第五次 Step 3 未完成 + 選單仍部分掉圖（2.7.274，R5 現場）

| 項目 | 內容 |
|------|------|
| **站點** | https://shineching.com/ |
| **Docroot（唯一調查範圍）** | `/home/qj8hea4vdto3/public_html/shineching.com` |
| **主機** | GoDaddy shared，`132.148.179.46`，SSH 使用者 `qj8hea4vdto3` |
| **外掛版本（SSH 已確認）** | **2.7.274**（`Version`、`MUSEDER_RESTOREONE_VERSION`、`MUSEDER_RESTOREONE_BUILD_ID` 皆為 `2.7.274`） |
| **使用者可見症狀** | Step 3（P3）再次出現 **403 Forbidden** modal，未顯示還原成功；Restore History 顯示 `Running`；前端多數圖片改善，但選單仍有部分掉圖 |
| **本次 Job ID** | `rjb_20260531_110324_xdmv9c` |
| **還原來源檔** | `shineching.com-20260531013823-V6yYBa-1.zip`（來源備份 meta 顯示由 2.7.273 產生） |
| **調查日期** | 2026-05-31（SSH 唯讀取證，未修改 production docroot） |
| **嚴重度** | **P0** — 還原卡在 cleanup 99%，後端未標記完成；**P1** — 選單圖示仍有 unresolved media |

---

## 1. 一句話結論

第五次失敗已不是 R4 的「內容 URL 完全沒納入」問題；R5 包已部署新的 content/variant reconcile 邏輯，且確實掃描出更多 replacement pairs。新的阻塞點是：

1. **P3 未完成根因：** `apply_pairs` 對 **1778 個 replacement pair** 進行一次性全資料表 search-replace，沒有切片 checkpoint；在 GoDaddy PHP 30 秒限制下觸發 fatal timeout，job 停在 `cleanup / reconcile_upload_paths / apply_pairs`，所以沒有成功訊息，Restore History 保持 `running`。
2. **選單仍部分掉圖根因：** attachment 主路徑已大幅修復，但仍有 5 個 unresolved attachment，其中 4 個就是 `主選單圖示_*`；來源 ZIP 內沒有 exact filename，磁碟僅有多個 sanitize 後的泛用候選（如 `200210x_ICON_01-*`、`_01.png`、`LOGO_01.png`），目前 heuristic 無法安全唯一配對。

---

## 2. 後端 Job 狀態

最新 job：

```text
JOB rjb_20260531_110324_xdmv9c
file_name=shineching.com-20260531013823-V6yYBa-1.zip
updated_at=2026-05-31 19:06:35
stage=cleanup
progress=99
message=Finalising restore…
completed=false
tick_source=cron
```

`restore-history.json`：

```text
result=running
restore_completed_at=0
duration_seconds=0
```

這與使用者截圖一相符：UI 已回到可操作區，但 history 仍顯示 Running，且沒有成功訊息。

---

## 3. 直接錯誤證據：PHP max_execution_time

production `error_log` 有本輪 fatal：

```text
[31-May-2026 11:07:05 UTC] PHP Fatal error:
Maximum execution time of 30 seconds exceeded in
/wp-content/plugins/museder-restoreone/includes/class-restore-service.php on line 5022
```

本機同分支對應行位於 `serialized_replace_recursive()` 的 exact string replacement：

```php
$value = str_replace( $pair['search'], $replace, $value );
```

因此，這不是前端 403 本身造成 job 失敗；403 是 UI 可見症狀，後端真正停住點是 **cleanup apply_pairs fatal timeout**。

---

## 4. Job 卡住的 checkpoint

`jobs/rjb_20260531_110324_xdmv9c.json` 取證：

| 欄位 | 值 |
|------|-----|
| `checkpoints.cleanup.step` | `reconcile_upload_paths` |
| `media_paths_phase` | **`apply_pairs`** |
| `media_paths_scanned` | 370 |
| `media_paths_fixed` | 168 |
| `media_paths_unresolved` | 5 |
| `media_paths_content_scanned` | **7834** |
| `media_paths_content_pairs` | **237** |
| `media_paths_pairs` | **1778 pairs** |
| Job JSON 大小 | 426,267 bytes |

取樣 pair：

```json
{
  "search": "2020/01/元晶太陽能.png",
  "replace": "2020/01/.png"
}
```

```json
{
  "search": "2020/03/內壢-陸光五街-陳-1-1536x2048.jpg",
  "replace": "2020/03/---1-1536x2048.jpg"
}
```

**判讀：**

- R5 修復已把內容 URL / variant pair 加進來，pair 數由 R4 的 336 類型大幅增加到 1778。
- 但 `apply_pairs` 仍呼叫 `Museder_Restoreone_Restore_Service::apply_path_replacements_for_restore()`，接著 `run_search_replace()` 一次掃全站所有文字欄位。
- 這段沒有像 restore-files / scan_meta 一樣 sliced；在 shared hosting 30 秒限制下會被殺掉。

---

## 5. Cron / active job 狀態異常

唯讀查 WP options：

```text
active=false
lock={"job_id":"rjb_20260531_110324_xdmv9c","acquired_at":1780225454}
cron_hits=只剩 museder_restoreone_cleanup_cron daily
```

**判讀：**

- active job option 已不是 active，但 restore lock 還留著。
- 沒有看到可繼續處理此 restore job 的 scheduled event。
- 這會造成 UI 呈現「Ready to start restore / Force Unlock」與 Restore History `Running` 的矛盾狀態。

這是 fatal timeout 後的 cleanup 狀態恢復缺口。

---

## 6. 伺服器實際部署狀態

SSH 確認 production 不是舊包：

| 檔案 | 標記 | 結果 |
|------|------|------|
| `museder-restoreone.php` | Version / Build ID | `2.7.274` |
| `class-restore-media-paths.php` | `return self::progress_result( false );` | 有 |
| `class-restore-media-paths.php` | `detect_upload_path_drift` | 有 |
| `class-restore-media-paths.php` | content / variant / sizes 相關邏輯 | 有 |
| `restore.js` | 403 host-block warning | 有 |
| `restore.js` | `doneMessage` / `completionNotified` | 有 |

因此本輪不是「上傳錯舊 ZIP」。

---

## 7. 媒體修復現況

### 7.1 Attachment 層

```text
attachments_total=370
missing=5
pct=1.4
DRIFT {"scanned":370,"drift":0,"missing":5,"reason":"ok"}
```

R3 的大量 attachment missing 已修掉；本輪剩下的 5 個是 unresolved。

### 7.2 Content URL 層

因 job fatal 在 `apply_pairs`，內容替換未完成。現場仍可掃到：

```text
checked_refs=8788
missing_unique=4816
```

主要來源：

```text
posts.post_content 3873
option:elementor_remote_info_library 814
option:pp_templates_library 122
widget_media_image 4
```

這些數字代表 DB 文字內容中仍有大量 `uploads/...` URL 指向不存在的舊中文檔名；但其中許多應該是 R5 新增 pairs 要在 `apply_pairs` 階段替換，尚未完成即 timeout。

---

## 8. 選單仍部分掉圖

本輪 5 個 unresolved attachment：

```text
2020/02/需要準備的資料01.png
2020/02/主選單圖示_綠能知識01.png
2020/02/主選單圖示_熱水器01.png
2020/02/主選單圖示_優惠訊息01.png
2020/02/主選單圖示_太陽能光電01.png
```

其中 4 個明確是「主選單圖示」，與使用者截圖二的「選單仍有部分掉圖」吻合。

ZIP / disk 檢查：

- `zip_exact=false`
- `disk_exists=false`
- 同目錄只有大量 sanitize 後候選，例如：

```text
200210x_ICON_01-1.png
200210x_ICON_01-2.png
200210x_ICON_01-3.png
200210x_01.png
_01.png
LOGO_01.png
xICON_DOWN_01.png
```

**判讀：**

- 這 5 個不是 R4 那種可由 `_wp_attached_file` 唯一修正的 LINE_ALBUM case。
- 中文檔名被 sanitize 後變成非常泛用的 `_01.png` / `200210x_ICON_01-*` 類型，候選太多，現有 `ascii_fold_filename + size hint` 邏輯無法唯一配對。
- 即使 `apply_pairs` 不 timeout，這 5 個仍可能保持 unresolved，除非新增更可靠的 mapping 訊號。

---

## 9. Step 3 403 的定位

使用者截圖仍顯示 raw `403 Forbidden` modal。這次後端沒有完成，因為 apply_pairs fatal timeout；所以 403 不是唯一問題。

更準確的關係：

1. 還原進入 cleanup 99%。
2. `apply_pairs` 在 HTTP / cron slice 中執行過久。
3. 主機 PHP 30 秒 fatal timeout。
4. UI poll / admin request 同時或稍後出現 403 / Forbidden modal。
5. Job 未寫入 completion，History 保持 Running。

R5 的 `restore.js` 403 warning 已部署，但若 403 來自 interim login iframe / admin request / WAF modal，而非 REST poll response，JS poll handler 仍無法完整接管使用者看到的 modal。

---

## 10. 與前幾輪差異

| 輪次 | 主要問題 | 本輪狀態 |
|------|----------|----------|
| R3 | `media_paths` build_index fall-through → `noop` | 已修，不再發生 |
| R4 | content / Elementor URL 未納入 replacement | R5 已納入，pair 數增加 |
| **R5** | **新增大量 pairs 後，apply_pairs 非切片，全表 search-replace 超時** | **本輪根因** |
| R5 選單掉圖 | 5 個主選單/資料圖示 unresolved | 仍存在 |

---

## 11. 給開發 agent 的 debug 方向（僅調查，不含修復實作）

### P0：`apply_pairs` 必須切片化

目前 `apply_path_replacements_for_restore()` → `run_search_replace()` 一次跑完所有 tables / rows / fields / pairs。R5 現場 1778 pairs 在 GoDaddy 30 秒必然高風險。

建議開發 agent 研究：

- 將 apply_pairs 拆成 checkpoint：
  - table index
  - row primary key / offset
  - field index
  - pair batch index
- 每 slice 更新 job meta，避免 fatal 後不可續跑。
- 對 `str_replace()` 前先做 cheap prefilter（例如 row contains any relevant substring / uploads）。
- 限縮掃描表與欄位：優先 posts/postmeta/options，而非所有 text/varchar 欄位全表。

### P0：fatal 後狀態恢復

本輪 active=false、lock 殘留、history=running、cron 無 restore event。需研究 fatal / timeout 後：

- 如何讓 job 可續跑。
- 如何避免 UI 變成 ready + history running 的矛盾狀態。
- 如何在下次 admin load / cron tick rehydrate active job。

### P1：選單圖示 unresolved mapping

5 個 unresolved 中 4 個是 `主選單圖示_*`。需研究：

- 是否可從 attachment metadata（尺寸、mime、title、guid、post parent、Elementor/menu context）取得唯一候選。
- 對高度模糊 sanitize 結果（`_01.png`、`200210x_ICON_01-*`）是否應回報「無法安全修復」而非猜測。
- QA 應將這 5 個列為 expected unresolved 或建立新 heuristics。

### P1：QA 補強

R5 QA 應加入：

- 模擬 1778+ pairs 的 apply timeout / sliced resume。
- 確認 `apply_pairs` 不會單次超過 30 秒。
- 確認 `restore-history.json` 最終不是 running。
- 確認 unresolved menu icon samples 有明確報告。

---

## 12. 本輪未做事項

- 未修改 production docroot。
- 未手動 Force Unlock。
- 未手動執行 restore slice 或 cron。
- 未修 DB / 圖片路徑。
- 未觸及其他站點。

---

## 13. 關鍵證據摘要

```text
stage=cleanup
progress=99
message=Finalising restore…
completed=false
media_paths_phase=apply_pairs
media_paths_pairs=1778
media_paths_content_scanned=7834
media_paths_content_pairs=237

Fatal:
Maximum execution time of 30 seconds exceeded
class-restore-service.php line 5022

active=false
lock={"job_id":"rjb_20260531_110324_xdmv9c",...}
history=result running

attachments_total=370
missing=5
missing samples include:
主選單圖示_綠能知識01.png
主選單圖示_熱水器01.png
主選單圖示_優惠訊息01.png
主選單圖示_太陽能光電01.png
```

**報告產出：** 2026-05-31  
**調查方式：** SSH 唯讀取證  
**交付對象：** 開發 agent
