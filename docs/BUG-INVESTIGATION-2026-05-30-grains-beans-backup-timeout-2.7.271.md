# Bug 調查報告：grains-beans.com 備份超出預期時間無法完成（2.7.271）

| 項目 | 內容 |
|------|------|
| **站點** | https://www.grains-beans.com/ |
| **主機** | GoDaddy shared（`p3plzcpnl508056.prod.phx3.secureserver.net`） |
| **Docroot** | `/home/qj8hea4vdto3/public_html/grains-beans.com` |
| **外掛版本** | **2.7.271**（於 2026-05-30 16:16:45 +08 升級完成） |
| **PHP** | 8.3.30 |
| **情境** | 備份階段進度長時間停滯，使用者感知「超出預期時間、無法完成」 |
| **調查方式** | SSH 唯讀取證（未修改任何程式或檔案） |
| **調查日期** | 2026-05-30 |
| **嚴重度** | **P1** — 備份在 2.7.271 部署後無法在合理時間內完成 |

---

## 1. 問題摘要（使用者可見）

使用者在 **RestoreOne → Backups** 啟動備份後，進度條長時間處於 **packing（打包）** 階段，百分比緩慢上升或看似卡住，**數小時仍無「完成」**。期間使用者曾嘗試 **取消**，但 job 仍繼續寫 log。

**重要：** 這不是「備份引擎完全沒在跑」，而是 **2.7.271 在 ZipArchive 打包完成後觸發「驗證失敗 → PclZip 全量重打包」**，在該主機上重打包極慢，導致牆鐘時間遠超預期。

---

## 2. 環境摘要

| 項目 | 值 |
|------|-----|
| 站點大小（docroot） | ≈ 3.9 GB |
| `wp-content/uploads` | ≈ 2.2 GB |
| `wp-content/plugins` | ≈ 306 MB（29 個外掛目錄） |
| 磁碟可用 | 1.4 TB / 2.0 TB（**非磁碟滿**） |
| 外掛升級時間 | 2026-05-30 **16:16:45** `Museder RestoreOne version updated` → **2.7.271** |
| 升級前日誌版本 | 當日 00:00–15:21 仍為 **2.7.241** |

---

## 3. 時間線（2026-05-30，+08）

### 3.1 外掛升級

```
16:16:45  Museder RestoreOne version updated → 2.7.271
16:16:45  Legacy storage migration（logs rename_failed，非主因）
```

### 3.2 Job A — `e23db0fc-9b96-46ad-bc8a-6b1c08afc97a`

| 時間 | 事件 |
|------|------|
| 16:18:14 | 建立 job |
| 16:18:32 | Prepared：23,832 檔、≈1.90 GB、`balanced`、`smart_exclude: off` |
| 16:21:31–16:23:12 | ZipArchive packing（100 batches / 101s） |
| 16:23:14 | **Packing reached end pointer** — 23,832/23,832 檔已加入 |
| 16:23:43 | ZipArchive close（≈29s）→ finalize → **verify** |
| **16:23:43** | **`Archive verification failed; scheduling repack with PclZip`** |
| 16:23:46+ | 進入 **PclZip repack**，pointer 重置為 0，從頭重打 |

**Verify 失敗快照（log 原文）：**

```json
{
  "job_id": "e23db0fc-9b96-46ad-bc8a-6b1c08afc97a",
  "ok": false,
  "checked": 7,
  "missing": 5,
  "samples": [
    "wp-admin/index.php",
    "wp-includes/version.php",
    "wp-content/index.php",
    "missing_sample:wp-content/themes/index.php",
    "missing_sample:wp-content/uploads/woocommerce-placeholder-600x600.png"
  ]
}
```

### 3.3 Job B — `7fc0640b-9ed6-44c2-998c-1b3c2238a07a`（第二次手動備份）

| 時間 | 事件 |
|------|------|
| 18:23:59 | 建立 job（Job A 仍在 PclZip repack 中） |
| 18:24:19 | Prepared：23,850 檔、≈2.05 GB、`balanced`、`smart_exclude: on` |
| 18:25–18:29 | ZipArchive packing |
| **18:29:46** | **同一組 verify 失敗** → PclZip repack |
| 20:30:48 / 20:31:07 | 使用者 **Cancel**（log 有紀錄） |
| 20:30:56+ | **Cancel 後仍繼續 packing log**（in-flight 請求未即停） |

### 3.4 調查結束時 job 狀態（≈20:33 +08）

| Job | status | stage | 進度 | pack_method | repack_attempted |
|-----|--------|-------|------|-------------|------------------|
| e23db0fc | running | packing | 7,098 / 23,832 檔（≈30%） | **pclzip** | true |
| 7fc0640b | running | packing | 7,164 / 23,850 檔（≈30%） | **pclzip** | true |

**PclZip 打包速率（實測 log）：** 每 tick 約 **33s** 處理 **10 檔**，AJAX time_budget **12s**（頻繁觸發 `Packing batch exceeded time budget`）。

**粗估剩餘時間（單一 job）：**

- 剩餘 ≈ 16,700 檔 ÷ 10 檔/tick × ≈36s/tick ≈ **16.7 小時**（且兩 job 並行搶資源可能更久）

---

## 4. 根因分析（鎖定 2.7.271 產品行為）

### 4.1 主根因 — ZipArchive 完成後 verify 失敗 → 強制 PclZip 全量重打包

**程式路徑（2.7.271）：** `includes/class-backup.php`

- `verify_archive_contains_wp_content()` 在 finalize 的 `verify` 步驟檢查 archive 是否含 `wp-admin/index.php`、`wp-includes/version.php`、`wp-content/index.php` 等。
- 若 `ok === false` 且 `repack_attempted` 為空 → log **`Archive verification failed; scheduling repack with PclZip`**，刪除原 archive、**pointer 歸零**、改 `pack_method: pclzip` 從頭重打。

**現場證據：**

1. 兩次獨立備份、**完全相同的 missing samples**（見 §3.2、§3.3）→ 非偶發單檔問題，而是 **ZipArchive 產出與 verify 邏輯在該主機上系統性不一致**，或 **close 後 archive 不可讀**。
2. Job A 在 verify 前 log 顯示 **23,832/23,832 檔已全部加入**（ZipArchive 自認完成），但 verify 仍判 FAIL。
3. 重打包改 PclZip 後，每 batch **~33s / 10 檔**，與 2.7.271 `class-backup-jobs.php` 的 AJAX **12s hard budget** 疊加，牆鐘時間爆炸。

**結論：** 使用者「備份無法在預期時間完成」的 **直接原因** 是 **2.7.271 的 post-pack verify + PclZip fallback**，而非磁碟滿或外掛未啟動。

### 4.2 促成因素 — 共享主機 + 站點体量

| 因素 | 影響 |
|------|------|
| ≈24k 檔 / ≈2 GB | PclZip 逐檔打包在 shared hosting 上極慢 |
| AJAX time_budget 12s | adaptive 將 batch 縮至 **10 檔 / 4MB**，加劇 tick 次數 |
| 2.2 GB uploads（含 WooCommerce 媒體） | 大檔使單 batch 常超時 |

### 4.3 次要問題（2.7.271 相關，建議一併修）

| ID | 現象 | 嚴重度 |
|----|------|--------|
| **BUG-GB-271-02** | 使用者 Cancel 後，log 仍出現 packing tick（in-flight 請求覆寫 cancel） | P2 |
| **BUG-GB-271-03** | Job B 在 Job A 仍在 repack 時可建立，**兩 job 並行 PclZip** 搶 I/O | P2 |
| **BUG-GB-271-04** | `backups/` 內出現 **未完成 zip**（`cpYEoi.zip` 僅 7,165 檔，缺 core 路徑），易誤以為備份成功 | P2 |
| **BUG-GB-271-05** | Verify 失敗時 **刪除** 已完成的 ZipArchive 產物，無法事後取證 archive 實際 entry 結構 | P3（調查性） |

### 4.4 非根因（已排除）

| 假設 | 結果 |
|------|------|
| 磁碟空間不足 | ❌ 1.4 TB 可用 |
| 外掛非 2.7.271 | ❌ 16:16 後 log 均為 2.7.271 |
| WooCommerce fatal 導致備份掛掉 | ❌ 備份 job 持續寫 log，stage=packing |
| WP-Cron 完全停擺 | ⚠️ 未見 DISABLE_WP_CRON；cron tick 有在跑（log 每 ~36s 一批） |

---

## 5. 與 2.7.271 程式碼的對應

| 行為 | 檔案 | 說明 |
|------|------|------|
| Verify 失敗 → PclZip repack | `includes/class-backup.php` ≈3352–3423 | 刪 archive、reset pointer、`repack_attempted=true` |
| Verify 檢查 core 路徑 + manifest 抽樣 | `includes/class-backup.php` `verify_archive_contains_wp_content()` ≈2319+ | 缺 `wp-admin/index.php` 等即 FAIL |
| AJAX 12s time budget + adaptive shrink | `includes/class-backup-jobs.php` ≈238–295 | 導致 `next_max_files:10` 長期不恢復 |
| Cancel cooperative check | `includes/class-backup-jobs.php` ≈301–317 | 現場顯示 cancel 後仍有 tick（race） |

---

## 6. 給開發 Agent 的調查問題（待程式面回答）

1. **為何 ZipArchive 報告 23,832/23,832 added，verify 卻找不到 `wp-admin/index.php`？**  
   - 是否 entry 路徑前綴（`./`、絕對路徑）與 `zip_archive_has_entry()` 不一致？  
   - 是否 `ZipArchive::close()` 在 29s+ 後產生 **central directory 不完整** 的 zip（GoDaddy + PHP 8.3.30）？

2. **Verify 抽樣 `woocommerce-placeholder-600x600.png` 缺失是否應判 FAIL？**  
   - 該檔可能已被刪除但仍在 manifest → 是否應降級為 warning 而非整包 repack？

3. **PclZip fallback 是否應保留原 ZipArchive 產物供診斷，或僅 repack 缺失 subset？**  
   - 現策略全量重打 ≈24k 檔，在 shared hosting 上不可接受。

4. **Cancel 與並行 job**  
   - 是否應在已有 `running` backup 時拒絕新 job？  
   - Cancel 後如何保證 in-flight PHP 請求不再寫入 job state？

---

## 7. 現場 artifacts（供開發重現／對照）

| 路徑 | 說明 |
|------|------|
| `wp-content/uploads/museder-restoreone/logs/backup-lite-2026-05-30.log` | 337 KB，含完整 verify / repack 鏈 |
| `wp-content/uploads/museder-restoreone/jobs/e23db0fc-*.json` | Job A state（running/pclzip） |
| `wp-content/uploads/museder-restoreone/jobs/7fc0640b-*.json` | Job B state（running/pclzip） |
| `wp-content/uploads/museder-restoreone/jobs/*-manifest.ndjson` | 23,850 行 manifest |
| `backups/www.grains-beans.com-20260530182359-cpYEoi.zip` | **未完成** partial（7,165 entries） |
| `backups/www.grains-beans.com-20260530161814-qy5wm4.zip` | 7,100 entries，含 `wp-admin/index.php`（可能為 ZipArchive 階段產物，非最終成功備份） |

**關鍵 log 行（搜尋字串）：**

- `Archive verify snapshot (after close).`
- `Archive verification failed; scheduling repack with PclZip.`
- `Packing batch exceeded time budget; shrinking batch limits`

---

## 8. 維運備註（非程式修改，僅調查結論）

- 調查時 **兩個 backup job 仍為 `running/packing`**，站點備份功能處於 **長時間 PclZip repack** 狀態。
- 使用者若需立即恢復備份能力，維運需評估：**Force Unlock / 清理 stuck job**、刪除 partial zip（**屬維運操作，本報告未執行**）。
- 升級至 2.7.271 **當日 16:16** 後首次備份即觸發 verify→repack 路徑；升級前（2.7.241）當日 log 無此 verify 失敗紀錄（**可能為 2.7.271 新 verify 邏輯或新 close/finalize 行為**）。

---

## 9. 結論（給 PM / 開發）

| 問題 | 判定 |
|------|------|
| 是否 2.7.271 產品 bug？ | **是（高信心）** — verify 失敗觸發 PclZip 全量重打包，在該環境下導致備份无法在合理時間完成 |
| 主機/磁碟問題？ | **否** |
| 使用者操作問題？ | **否** — 正常點備份；第二次備份時第一次仍在 repack |

**建議開發優先級：**

1. **P0** — 調查並修復 **ZipArchive 完成後 verify 誤判 FAIL**（或 close 後 archive 損壞）的根因  
2. **P1** — PclZip fallback 策略：避免全量重打 24k 檔；或 verify 失敗時保留 diagnostic  
3. **P2** — Cancel race、並行 job 阻擋、未完成 zip 不進 backups 列表  

---

## 10. 證據索引（本 repo）

| 檔案 | 內容 |
|------|------|
| `docs/qa-evidence/grains-beans-investigation-raw.txt` | SSH 原始輸出（job JSON + log tail） |

---

*調查完成：2026-05-30 · 唯讀 · 未修改 grains-beans.com 或主機任何檔案 · 外掲版本 2.7.271*
