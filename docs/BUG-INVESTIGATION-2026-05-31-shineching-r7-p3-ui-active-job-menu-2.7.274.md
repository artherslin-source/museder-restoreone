# Bug 調查報告：shineching.com 第七次 P3 403 / 成功訊息未顯示 / 選單圖示疑似未恢復（2.7.274，R7 現場）

調查時間：2026-05-31 23:34 起（UTC+8）  
站點：`shineching.com`  
外掛包：`/dist/museder-restoreone-2.7.274.zip`  
調查方式：SSH / DB / log / HTTP 唯讀取證；未修改 production 檔案、DB、外掛、其他站點。

## 1. 一句話結論

第七次與 R5 `apply_pairs` timeout 不同：**RestoreOne 2.7.274 後端 restore job 已成功完成**，`stage=done`、`progress=100`、history 記錄為 `success`。

但仍有兩個需要交給 dev agent 的產品問題：

1. **P3 成功訊息未穩定顯示：** restore 在 `2026-05-31 22:59:51 +08` 完成，但使用者瀏覽器在完成前後遇到 `admin-ajax.php` 403/400 與 `wp-login.php?interim-login=1` 403。前端把這類暫時性 auth / ajax 錯誤顯示成「unexpected error / 403」後，沒有可靠地回讀 job/history 並改顯示成功。
2. **來源備份內含 RestoreOne runtime stale options：** seed backup 本身帶有舊的 `museder_restoreone_active_job=900d4241-bc23-4cbf-b641-8426fc88aa6d` 與 `museder_restoreone_job_lock_900d4241-...`。本輪 restore 後這兩個 stale options 仍存在於目標 DB。這不是本次 restore service 的 active option，但會污染 RestoreOne admin runtime 狀態，需列入排除或完成後清理。

選單圖示方面，本輪沒有看到「檔案不存在」或「widget raw URL 未修正」的證據。`widget_media_image` 已改成存在的 ASCII 檔名，相關圖片 URL HTTP 200。首頁前端只輸出 `media_image-12/13`，這與 seed backup 原始 `_megamenu` 映射一致；若使用者期待其他舊 widget（`media_image-2..11`）也出現在選單，根因偏向「來源 Max Mega Menu grid 映射本來就沒有掛那些 widget」，不是 RestoreOne 檔案還原失敗。

## 2. 現場狀態

docroot 目前存在：

- `wp-config.php`
- `wp-content/`
- `wp-admin/`
- `wp-includes/`
- `index.php`
- `wp-load.php`

外掛版本確認：

- Plugin header `Version=2.7.274`
- `MUSEDER_RESTOREONE_VERSION=2.7.274`
- `MUSEDER_RESTOREONE_BUILD_ID=2.7.274`

本輪不是「WordPress core 檔案消失」的狀態；R6 發現的 Installatron deletion 風險沒有在這次取證時重演。

## 3. Restore job 證據

最新 restore job：

```text
job_id=rjb_20260531_145652_km3ppv
file=shineching.com-20260531013823-V6yYBa-1.zip
stage=done
progress=100
message=Restore completed successfully.
completed=true
updated_at=2026-05-31 22:59:51
tick_source=cron
```

History：

```json
{
  "job_id": "rjb_20260531_145652_km3ppv",
  "date": "2026-05-31 14:59:51",
  "file": "shineching.com-20260531013823-V6yYBa-1.zip",
  "result": "success",
  "restore_duration_seconds": 169
}
```

Media reconcile / R5 修復點：

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

RestoreOne log：

```text
[2026-05-31T22:59:50+08:00] [INFO] MEDIA_PATHS_RECONCILE_DONE {"job_id":"rjb_20260531_145652_km3ppv","reason":"apply_pairs","fixed":163,"unresolved":10,"scanned":370,"pairs":1768,"content_scanned":7834,"content_pairs":230,"widget_urls_fixed":1}
[2026-05-31T22:59:50+08:00] [WARNING] MEDIA_PATHS_VERIFY_WARN {"job_id":"rjb_20260531_145652_km3ppv","missing":10,"checked":370}
[2026-05-31T22:59:50+08:00] [INFO] Restore completed. Active plugin list recorded for admin review. {"active_plugins_count":21}
[2026-05-31T22:59:51+08:00] [INFO] Safe mode marker enabled after restore (no automatic plugin activation changes). {"previous_plugins_count":17}
```

結論：R5 的 `apply_pairs` slicing 在本輪 production 也有完成；這次 P3 UI 失敗不是 cleanup timeout。

## 4. P3 UI 403 / 未顯示成功

使用者截圖顯示 P3 約 97% 時出現 `403 Forbidden` modal。

access log 針對使用者 IP `27.51.15.75` 的關鍵時間線：

```text
31/May/2026:07:59:17 -0700 POST /wp-admin/admin-ajax.php 200 581
31/May/2026:07:59:22 -0700 POST /wp-admin/admin-ajax.php 200 581
31/May/2026:07:59:27 -0700 POST /wp-admin/admin-ajax.php 403 111
31/May/2026:07:59:28 -0700 POST /wp-admin/admin-ajax.php 403 111
31/May/2026:07:59:35 -0700 POST /wp-admin/admin-ajax.php 400 1
31/May/2026:07:59:49 -0700 POST /wp-admin/admin-ajax.php 400 1
31/May/2026:07:59:59 -0700 POST /wp-admin/admin-ajax.php 403 111
31/May/2026:08:00:03 -0700 POST /wp-admin/admin-ajax.php 200 48
31/May/2026:08:07:06 -0700 GET /wp-login.php?interim-login=1&wp_lang=zh_TW 403 14
```

job 完成時間是 `07:59:51 -0700`。也就是：

- 完成前 24 秒與 23 秒，UI polling 已遇到 403。
- 完成後仍持續出現 400/403。
- 8 分鐘後 WordPress auth check 的 interim login 也回 403。

同一時間 access log 有大量不同 IP 的 `POST /wp-login.php`，例如 `104.207.*`、`209.50.*`、`65.111.*`、`45.3.*` 等。這看起來像 login probing / brute-force 類流量，可能觸發主機/WAF/安全外掛對登入或 AJAX 的干擾。

產品層根因不是「restore 沒成功」，而是：

- 前端對 `admin-ajax.php` 403/400、`auth-check` 403 的復原不足。
- 後端已有 success history 與 post-complete access token，但 UI 沒有在錯誤後可靠 fallback 到 job status/history 成功狀態。
- 前端將暫時性 transport/auth 錯誤升級成 modal，讓使用者看到失敗，即使 cron 已完成 restore。

## 5. Runtime stale options

本輪 restore 後 DB 仍存在：

```text
museder_restoreone_active_job=900d4241-bc23-4cbf-b641-8426fc88aa6d
museder_restoreone_job_lock_900d4241-bc23-4cbf-b641-8426fc88aa6d=a:2:{s:2:"ts";i:1780162704;s:5:"token";s:36:"c11abd37-4d34-4451-b0b3-8e658da14119";}
museder_restoreone_restore_post_complete_access=job_id rjb_20260531_145652_km3ppv
```

對照 seed backup 的 `database.ndjson`，來源備份本身就包含同樣的 stale backup job runtime options：

```json
{"option_name":"museder_restoreone_active_job","option_value":"900d4241-bc23-4cbf-b641-8426fc88aa6d","autoload":"off"}
{"option_name":"museder_restoreone_job_lock_900d4241-bc23-4cbf-b641-8426fc88aa6d","option_value":"a:2:{s:2:\"ts\";i:1780162704;s:5:\"token\";s:36:\"c11abd37-4d34-4451-b0b3-8e658da14119\";}","autoload":"off"}
```

這兩個 option 是 RestoreOne runtime 狀態，不應該被還原成目標站目前狀態。雖然目前 `museder_restoreone_restore_service_active_job_id` 不存在，且本輪 restore job file 已完成，但 stale `museder_restoreone_active_job` / job lock 仍可能影響：

- admin UI localize 的 `activeJob`
- backup job 建立流程
- 使用者看到仍有作業或鎖的誤導
- 後續 backup / restore 操作的前置檢查

建議 dev agent 檢查 DB import / media reconcile / post-restore cleanup 是否應排除或清理：

- `museder_restoreone_active_job`
- `museder_restoreone_job_lock_%`
- 其他 RestoreOne transient/runtime lock/state options

## 6. 選單圖示取證

### 6.1 Attachment meta 與磁碟狀態

相關附件存在，且 `_wp_attached_file` 指向磁碟存在的檔案：

```text
1469 主選單圖示_綠能知識01 -> 2020/02/191121x_xICON_02-150x150.png exists=true
1470 主選單圖示_熱水器01 -> 2020/02/191121x_xICON_02-165x300.png exists=true
1471 主選單圖示_優惠訊息01 -> 2020/02/191121x_xICON_02-562x1024.png exists=true
1472 主選單圖示_太陽能光電01 -> 2020/02/191121x_xICON_02-768x1399.png exists=true
2455 主選單圖示_01 -> 2023/01/_01.jpg exists=true
2774 主選單圖示_04-1 -> 2023/01/_04-1.jpg exists=true
```

檔案本身也可讀、格式正確：

```text
191121x_xICON_02-150x150.png size=25807 perms=0644 image/png 150x150
191121x_xICON_02-768x1399.png size=758636 perms=0644 image/png 768x1399
2023/01/_01.jpg size=35607 perms=0644 image/jpeg 211x211
2023/01/_04-1.jpg size=58084 perms=0644 image/jpeg 211x211
```

HTTP 檢查：

```text
https://shineching.com/wp-content/uploads/2020/02/191121x_xICON_02-150x150.png -> 200 image/png
https://shineching.com/wp-content/uploads/2020/02/191121x_xICON_02-768x1399.png -> 200 image/png
https://shineching.com/wp-content/uploads/2023/01/_01.jpg -> 200 image/jpeg
https://shineching.com/wp-content/uploads/2023/01/_04-1.jpg -> 200 image/jpeg
https://shineching.com/wp-content/uploads/2020/02/LOGO_01.png -> 200 image/png
```

### 6.2 `widget_media_image`

還原後 `widget_media_image`：

```text
raw_len=6103
contains_zh=no
contains_191121=yes
contains_200210=no
```

主要 widget：

```text
media_image-2  attachment_id=1472 url=.../2020/02/191121x_xICON_02-768x1399.png file_exists=true
media_image-4  attachment_id=1470 url=.../2020/02/191121x_xICON_02-165x300.png file_exists=true
media_image-5  attachment_id=1469 url=.../2020/02/191121x_xICON_02-150x150.png file_exists=true
media_image-6  attachment_id=1471 url=.../2020/02/191121x_xICON_02-562x1024.png file_exists=true
media_image-12 attachment_id=2455 url=.../2023/01/_01.jpg file_exists=true
media_image-13 attachment_id=2774 url=.../2023/01/_04-1.jpg file_exists=true
```

這代表 R6 時擔心的 raw Chinese URL 問題，本輪已被 `widget_urls_fixed=1` 修正。

### 6.3 為什麼首頁仍只看到部分選單圖片

目前首頁 HTML 只輸出：

```text
COUNT 191121x_xICON = 0
COUNT _01.jpg = 2
COUNT _04-1.jpg = 2
COUNT 200210x_ICON = 0
```

Max Mega Menu `_megamenu` 映射顯示：

- nav menu item `1666` 的 grid 只掛 `media_image-12` 與 `media_image-13`
- nav menu item `1669` 的 grid 只掛 menu items，沒有掛 widget
- seed backup 原始 `database.ndjson` 中 `_megamenu` 映射也同樣只掛 `media_image-12` / `media_image-13`

所以目前看起來不是 RestoreOne 沒把 `media_image-2..11` 修好，而是來源備份的 Max Mega Menu grid 本來就沒有把這些 widget 放到首頁輸出的 mega menu 位置。RestoreOne 已修 widget data，但沒被 grid 引用的 widget 不會出現在前端。

## 7. 給 dev agent 的根因清單

### P1：P3 UI 對完成前後的 403/400 不具備成功復原

證據：

- 後端 job `stage=done`、history `success`
- 使用者端 `admin-ajax.php` 在完成前後出現 403/400
- `wp-login.php?interim-login=1` 後續也 403
- 截圖顯示 403 modal，而非成功訊息

建議調查方向：

- `assets/admin.js` / restore page polling 對 `museder_restoreone_restore_job_status` 的錯誤處理。
- 當 AJAX 403/400 發生時，是否應改成 warning + retry，而非立刻終止 UI。
- job 已完成時，是否可透過 history 或 `restore_post_complete_access` 補顯示成功。
- auth-check / heartbeat 403 是否不應覆蓋 restore job 的最終狀態。

### P2：RestoreOne runtime options 被 seed backup 還原回目標 DB

證據：

- seed backup 內含 `museder_restoreone_active_job=900d4241-...`
- restore 後目標 DB 仍有同值與 `museder_restoreone_job_lock_900d4241-...`

建議調查方向：

- DB restore 應排除 RestoreOne runtime state / locks。
- backup export 也可排除 runtime options，避免備份檔帶入舊狀態。
- post-restore cleanup 可掃掉非當前 job 的 RestoreOne stale options。

### P3：選單掉圖需改判定為「來源 Max Mega Menu mapping」或「視覺預期差異」

證據：

- attachment meta 正確
- widget raw URL 已修
- 圖片 HTTP 200
- frontend 只輸出 seed backup 原本有映射的 `media_image-12/13`

建議調查方向：

- 若產品目標是「所有 `mega-menu` sidebar widget 都應被掛回 grid」，需另做 Max Mega Menu mapping restore enhancement。
- 若目標只是還原來源備份內容，本輪沒有看到選單圖片檔案層面的未修 bug。

## 8. 本輪未做事項

- 未修改 production DB。
- 未修改 production 檔案。
- 未停用/啟用任何外掛。
- 未碰其他站點。
- 未按「Force Unlock」。

