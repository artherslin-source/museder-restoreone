# Bug 調查報告：shineching.com 第六次 P3 403 / 成功訊息未顯示 / 選單圖示疑似未完全恢復（2.7.274，R6 現場）

| 項目 | 內容 |
|------|------|
| **站點** | https://shineching.com/ |
| **Docroot（調查範圍）** | `/home/qj8hea4vdto3/public_html/shineching.com` |
| **主機** | GoDaddy shared，`132.148.179.46` |
| **外掛版本（SSH 已確認）** | **2.7.274**（`Version`、`MUSEDER_RESTOREONE_VERSION`、`MUSEDER_RESTOREONE_BUILD_ID` 皆為 `2.7.274`） |
| **使用者症狀** | P3 顯示 403 / 未跳成功訊息；前端大多恢復但選單仍疑似掉圖 |
| **本次 Job** | `rjb_20260531_124625_nvxiph` |
| **還原來源檔** | `shineching.com-20260531013823-V6yYBa-1.zip` |
| **調查日期** | 2026-05-31（SSH 唯讀取證；未執行修復） |
| **嚴重度** | **P1**：RestoreOne job 已成功，但 UI 顯示失敗；**P0（非 RestoreOne 直接證據）**：21:35 後 docroot 核心檔案消失，站點開始 404 |

---

## 1. 一句話結論

第六次與 R5 不同：**RestoreOne 2.7.274 後端 job 已完成成功**，不是 `apply_pairs` timeout，也不是 restore engine 卡在 99%。

本輪可分成三個問題：

1. **P3 成功訊息未顯示 / 403 modal：** 後端還原在 20:50:09 成功寫入 history；但使用者瀏覽器在 20:47-20:49 期間遇到 `wp-login.php?interim-login=1` 403、`admin-ajax.php` 403/500，導致 UI 顯示「An unexpected error occurred」與 403 modal。產品層根因是：前端對長時間 restore execute / auth-check 403 的復原不足，沒有在後端已完成時穩定改顯示成功。
2. **選單圖示：** attachment meta 已被修到可存在的檔案，且 1469-1472 均指向磁碟存在的 `191121x_xICON_02-*`。但來源備份的 Max Mega Menu `widget_media_image` option 內仍有以 attachment id 搭配 raw `url` 欄位的舊中文檔名結構，這是選單仍可能顯示不完整的高風險區，需 dev agent 針對 widget/options raw URL 做專項檢查。
3. **21:35 後站點核心檔案消失：** access log 顯示 `Installatron Plugin/10.0.6/547` 呼叫 `/deleteme...php` 後，`wp-content/`、`wp-admin/`、`wp-includes/`、`wp-config.php` 已不在 docroot，之後首頁與圖片開始 404。這一段目前沒有證據指向 RestoreOne 2.7.274 直接刪除；更像主機/Installatron 清理動作，但已造成 production 站點不可用。

---

## 2. 後端 Job 狀態：已成功

`wp-content/uploads/museder-restoreone/jobs/rjb_20260531_124625_nvxiph.json`：

```text
file_name=shineching.com-20260531013823-V6yYBa-1.zip
stage=done
progress=100
message=Restore completed successfully.
updated_at=2026-05-31 20:50:08
tick_source=cron
completed=true
```

cleanup checkpoint：

```text
cleanup.step=finish
cleanup.media_paths_phase=done
cleanup.media_paths_scanned=370
cleanup.media_paths_fixed=163
cleanup.media_paths_unresolved=10
cleanup.media_paths_content_scanned=7834
cleanup.media_paths_content_pairs=230
cleanup.media_paths_pairs_count=1768
```

`restore-history.json`：

```text
result=success
job_id=rjb_20260531_124625_nvxiph
restore_started_at=1780231594
restore_completed_at=1780231809
duration_seconds=215
```

判讀：R5 的 `apply_pairs` timeout 根因在第六次沒有重現；`media_paths_phase=done` 表示切片 apply 已跑完。

---

## 3. RestoreOne log 證據

`wp-content/uploads/museder-restoreone/logs/backup-lite-2026-05-31.log`：

```text
[2026-05-31T12:46:25+00:00] [INFO] Restore job prepared. {"job_id":"rjb_20260531_124625_nvxiph","file":"shineching.com-20260531013823-V6yYBa-1.zip"}
[2026-05-31T20:47:16+08:00] [INFO] Mid-restore plugin isolation enabled (RestoreOne only until files finish).
[2026-05-31T20:47:16+08:00] [INFO] Post-DB-import recovery: lock, active job, and cron re-established.
[2026-05-31T20:48:53+08:00] [INFO] Mid-restore plugin isolation released; active_plugins restored from backup snapshot.
[2026-05-31T20:50:08+08:00] [INFO] MEDIA_PATHS_RECONCILE_DONE {"reason":"apply_pairs","fixed":163,"unresolved":10,"scanned":370,"pairs":1768,"content_scanned":7834,"content_pairs":230}
[2026-05-31T20:50:08+08:00] [WARNING] MEDIA_PATHS_VERIFY_WARN {"missing":10,"checked":370}
[2026-05-31T20:50:08+08:00] [INFO] Restore completed. Active plugin list recorded for admin review.
```

判讀：後端恢復、cron、plugin isolation release、media reconcile、history 成功都已發生。

---

## 4. P3 403 / 未顯示成功訊息

access log 時間線：

```text
20:46:25-20:48:45  多次 /wp-admin/admin-ajax.php 200
20:47:35           /wp-login.php?interim-login=1&wp_lang=zh_TW 403
20:48:45           /wp-admin/admin-ajax.php 500
20:48:46           /wp-admin/admin-ajax.php 403
20:50:08           RestoreOne 後端完成
20:50:35 起        /wp-admin/admin-ajax.php 每分鐘 200 48 bytes（疑似 heartbeat / keep-alive 類）
21:03:12           /wp-admin/admin.php?page=museder-restoreone-restore 302 -> /mu-admin/?reauth=1
```

關鍵 access log：

```text
27.51.15.75 - - [31/May/2026:05:47:35 -0700] "GET /wp-login.php?interim-login=1&wp_lang=zh_TW HTTP/2.0" 403 14
27.51.15.75 - - [31/May/2026:05:48:45 -0700] "POST /wp-admin/admin-ajax.php HTTP/2.0" 500 -
27.51.15.75 - - [31/May/2026:05:48:46 -0700] "POST /wp-admin/admin-ajax.php HTTP/2.0" 403 111
```

判讀：

- 使用者截圖中的小 modal「403 Forbidden」與 WordPress auth-check iframe 很吻合：`wp-login.php?interim-login=1` 被主機或自訂登入路徑阻擋。
- 同一時間 restore 後端並未中止，cron 持續推進並完成。
- 產品問題不在 restore engine；而是 UI 在遇到中途 auth-check / AJAX 403 後，沒有把後端後續成功狀態收斂回成功訊息。

建議 dev agent 聚焦：

- restore execute request 失敗 / 403 時，不應立即把整體 restore 判為失敗；需繼續用 job status/history/token polling 查後端結果。
- 若 `wp-login.php?interim-login=1` 403，避免 WordPress auth-check modal 蓋住 RestoreOne 完成流程，或在 restore page 禁用/攔截 auth-check 造成的誤導。
- 完成時應以 job meta/history 為準，而非以某一次 admin-ajax / REST 回應為唯一成功來源。

---

## 5. active option 狀態異常

DB options 取證時，仍有舊 active job option：

```text
museder_restoreone_active_job=900d4241-bc23-4cbf-b641-8426fc88aa6d
museder_restoreone_job_lock_900d4241-bc23-4cbf-b641-8426fc88aa6d=...
```

但實際 restore job 是：

```text
rjb_20260531_124625_nvxiph
```

判讀：

- 這個 UUID 型 active job 看起來不像本次 restore job id，可能是舊備份 job 或殘留 job lock。
- 目前尚未證明它直接造成第六次 403；但它是狀態清理風險，dev agent 應檢查 backup job 與 restore job 是否共用 / 汙染 active option。

---

## 6. 媒體路徑與選單圖示

### 6.1 attachment meta 現況（還原完成後、docroot 尚完整時取證）

```text
1469 主選單圖示_綠能知識01 -> 2020/02/191121x_xICON_02-150x150.png exists=true
1470 主選單圖示_熱水器01   -> 2020/02/191121x_xICON_02-165x300.png exists=true
1471 主選單圖示_優惠訊息01 -> 2020/02/191121x_xICON_02-562x1024.png exists=true
1472 主選單圖示_太陽能光電01 -> 2020/02/191121x_xICON_02-768x1399.png exists=true
```

因此「attachment 本身不存在」已不是第六次選單問題的主要根因。

### 6.2 仍 unresolved 的 10 個 media

`media_paths_unresolved_samples`：

```text
2020/01/元晶太陽能.png
2020/01/友達光電.jpg
2020/01/瑞智.png
2020/02/蘋果儷中黑.ttf
2020/02/需要準備的資料01.png
2020/03/女兒牆方式.jpg
2020/03/全興加工型.jpg
2020/03/斜屋頂上方.jpg
2020/03/斜屋頂架高.jpg
2020/03/騎馬式安裝.jpg
```

這 10 個不是 R5 報告中鎖定的 1469-1472 選單圖示。

### 6.3 Max Mega Menu widget raw URL 風險

來源備份 `database.ndjson` 的 `widget_media_image` option 內，Max Mega Menu media widgets 同時保存：

- `attachment_id`
- raw `url`

範例：

```text
attachment_id=1472
url=https://shineching.com/wp-content/uploads/2020/02/主選單圖示_太陽能光電01.png

attachment_id=1470
url=https://shineching.com/wp-content/uploads/2020/02/主選單圖示_熱水器01.png

attachment_id=1469
url=https://shineching.com/wp-content/uploads/2020/02/主選單圖示_綠能知識01.png

attachment_id=1471
url=https://shineching.com/wp-content/uploads/2020/02/主選單圖示_優惠訊息01.png
```

這是選單仍掉圖的高風險根因：RestoreOne 已更新 attachment meta，但若 serialized option 中的 raw `url` 沒被同步改寫，Max Mega Menu 仍可能輸出舊 URL。

另外，第六次前端 access log 顯示首頁當時實際載入的選單圖片只有：

```text
/wp-content/uploads/2023/01/_01.jpg 200
/wp-content/uploads/2023/01/_04-1.jpg 200
```

這代表現場畫面與 widget/mega menu options 的輸出仍需 dev agent 針對 `widget_media_image`、Max Mega Menu widget mapping、serialized option replacement 做專項復現。

---

## 7. 21:35 後站點核心檔案消失（非 RestoreOne 直接證據，但已造成 production 404）

調查過程中再次列 docroot 時，`shineching.com` 已只剩少數檔案：

```text
404.html
error_log
google1bbb3cb8b3e68c22.html
wp-compress-images-report-*.txt
wp-scan-cleanup-report-*.txt
.htaccess.bak-*
.well-known/
```

以下檔案 / 目錄已不存在：

```text
wp-config.php
wp-content/
wp-admin/
wp-includes/
index.php
wp-load.php
```

access log 顯示轉折點：

```text
[31/May/2026:06:35:13 -0700] "POST /deleteme.cha6133be8003544b47a925a096f525012b.php?n=1&m=4 HTTP/2.0" 200 3422 "-" "Installatron Plugin/10.0.6/547"
```

之後：

```text
[31/May/2026:06:37:17 -0700] "GET /wp-content/uploads/2022/12/LOGO_01-2.png HTTP/1.1" 404
[31/May/2026:06:37:18 -0700] "GET /wp-login.php HTTP/1.1" 404
[31/May/2026:06:37:25 -0700] "GET / HTTP/1.1" 404
```

判讀：

- 20:50 RestoreOne 成功完成後，21:03 仍可載入首頁和圖片。
- 21:35 出現 Installatron `deleteme...php` 呼叫後，21:37 開始首頁 / login / uploads 404。
- 這段目前沒有證據指向 RestoreOne job 直接刪除核心檔案；更像主機安裝器 / Installatron cleanup 或移除流程。
- 但對使用者而言，現場已進入 P0：docroot 不完整，需先由主機/人工恢復檔案或重跑還原前，後續產品 UI 測試無法再以該站為準。

---

## 8. 對 dev agent 的 Debug 任務建議

### A. P3 UI 403 / 成功訊息未顯示

請建立測試：後端 job 從 cron 完成，但前端 execute / admin-ajax / auth-check 在途中回 403 或 500。

預期產品行為：

- 若 status/history 顯示 `completed=true` / `result=success`，UI 必須顯示成功。
- 403/500 不應永久蓋掉後端成功狀態。
- Restore page 應避免 WordPress `interim-login` iframe 403 造成誤導性 modal，或至少將其轉成「session/auth check 被阻擋，但還原仍在背景繼續」。

### B. Max Mega Menu / widget raw URL replacement

請在 Docker shineching profile 補測：

- `widget_media_image` option 中每個 `attachment_id` 對應的 `url` 是否同步改為可存在檔案。
- 特別是 1469-1472、1466-1468、1649、1661 等 mega menu widgets。
- 不只檢查 `_wp_attached_file`，也要檢查 serialized option raw URL、前端 HTML 輸出、HTTP 200。

### C. active option 清理

請檢查：

- backup job 的 UUID active option 是否會殘留到 restore page。
- `museder_restoreone_active_job` 是否被 backup / restore 共用而互相汙染。
- 完成 restore 後是否應清理 stale UUID job lock。

### D. Installatron 介入

此項可能不屬於 RestoreOne 2.7.274，但建議標註為環境風險：

- 還原完成後是否觸發或保留了 `deleteme.*.php` 安裝器腳本。
- 主機 Installatron 是否偵測到不一致並執行 cleanup。
- 若 RestoreOne 還原會恢復舊 `deleteme.*.php` 檔案，需評估是否應在 restore 後警告或隔離這類安裝器臨時腳本。

---

## 9. 最終判定

- **R5 apply_pairs timeout：未重現，已修復。**
- **第六次 P3 未顯示成功：仍是產品 UI 韌性問題**，後端成功但前端被 auth-check/admin-ajax 403 誤導。
- **選單圖示：attachment meta 已修，但 Max Mega Menu serialized widget raw URL 仍是高風險根因**，需補測和修正。
- **站點 21:35 後 404：由 Installatron `deleteme...php` 觸發的外部/主機層事件證據最強，目前不能歸因為 RestoreOne 2.7.274 直接刪除。**

本報告可提交給 dev agent 針對 A/B/C 做產品 debug；D 請同時交由主機/環境側確認。
