# Bug 調查報告：shineching.com 第八次 P3 403 / WP_AJAX_0 / 取消還原後仍混亂（2.7.274）

提交對象：開發 agent  
調查時間：2026-06-01 03:32 起（UTC+8）  
站點：`shineching.com`  
版本：Museder RestoreOne `2.7.274`  
調查方式：SSH / DB / log / access log / 本地 zip hash 比對；未修改 production 檔案、DB、外掛或其他站點。

## 1. 一句話結論

第八次後端 restore **仍然成功完成**，不是 `apply_pairs` timeout，也不是 runtime options cleanup 未部署。

本次更精準地證明：

1. production 跑的外掛檔案與本地 `dist/museder-restoreone-2.7.274.zip` 完全一致，不是舊包。
2. 開發 agent 針對 R7 做的 runtime option cleanup 已生效，log 有 `RESTORE_RUNTIME_OPTIONS_CLEANED {"deleted":3}`。
3. 真正仍未修好的核心問題是：**前端 UI 的狀態確認、history fallback、cancel request 都仍透過同一個 `admin-ajax.php` 通道；當該通道被 403/400/WP_AJAX_0 阻擋時，UI 無法確認後端已成功，且使用者按「取消還原」也只是讓 UI 更混亂，無法取消已由 cron 繼續完成的後端 job。**

這表示前一輪開發修復有修到 P1 runtime cleanup，但 **P0「P3 UI 在 admin-ajax/auth-check hostile environment 下可靠收斂成功」仍未修完**。

## 2. 版本與部署確認

production docroot 正常存在：

```text
wp-config.php
wp-content/
wp-admin/
wp-includes/
index.php
wp-load.php
```

production 外掛版本：

```text
Version=2.7.274
MUSEDER_RESTOREONE_VERSION=2.7.274
MUSEDER_RESTOREONE_BUILD_ID=2.7.274
```

production 主要檔案 hash：

```text
museder-restoreone.php sha1=6f8c57caae29b77ad9e8662ef24f9d8b785fa529
assets/js/admin.js sha1=a871b0d4ef3a2e32e1af8a3c7c60543331a5fe7a
assets/js/restore.js sha1=fd1a2d7020f87f2a58a96fe9a476d5b5f28b7133
includes/class-backup.php sha1=45cf449ae568ffebc853c4853e2d81b595ad856e
includes/class-restore-handler.php sha1=3ece3e2a967959c82d66e4e9f5c21dd8fe1bd8c3
includes/class-restore-service.php sha1=c35f5908d66329434c5266286ecb8749fc985384
```

本地 `dist/museder-restoreone-2.7.274.zip` 主要檔案 hash 完全相同：

```text
museder-restoreone/museder-restoreone.php sha1=6f8c57caae29b77ad9e8662ef24f9d8b785fa529
museder-restoreone/assets/js/admin.js sha1=a871b0d4ef3a2e32e1af8a3c7c60543331a5fe7a
museder-restoreone/assets/js/restore.js sha1=fd1a2d7020f87f2a58a96fe9a476d5b5f28b7133
museder-restoreone/includes/class-backup.php sha1=45cf449ae568ffebc853c4853e2d81b595ad856e
museder-restoreone/includes/class-restore-handler.php sha1=3ece3e2a967959c82d66e4e9f5c21dd8fe1bd8c3
museder-restoreone/includes/class-restore-service.php sha1=c35f5908d66329434c5266286ecb8749fc985384
```

判斷：這不是「上傳錯舊 ZIP」或「production 沒更新」。

## 3. 第八次 restore 後端狀態

最新 job：

```text
job_id=rjb_20260531_191911_f6rhrg
file=shineching.com-20260531013823-V6yYBa-1.zip
stage=done
progress=100
message=Restore completed successfully.
updated_at=2026-06-01 03:22:12
tick_source=cron
completed=true
cancelled=false
cleanup.step=finish
media_paths_phase=done
media_paths_fixed=163
media_paths_unresolved=10
media_paths_pairs_count=1768
```

History：

```json
{
  "job_id": "rjb_20260531_191911_f6rhrg",
  "date": "2026-05-31 19:22:12",
  "file": "shineching.com-20260531013823-V6yYBa-1.zip",
  "result": "success",
  "restore_duration_seconds": 172
}
```

DB restore options：

```text
museder_restoreone_restore_post_complete_access=job_id rjb_20260531_191911_f6rhrg
museder_restoreone_safe_mode=1
```

本輪沒有殘留 R7 發現的舊：

```text
museder_restoreone_active_job=900d...
museder_restoreone_job_lock_900d...
```

## 4. Runtime options cleanup 已修到

`backup-lite-2026-06-01.log`：

```text
[2026-06-01T03:22:11+08:00] [INFO] Restore completed. Active plugin list recorded for admin review. {"active_plugins_count":21}
[2026-06-01T03:22:11+08:00] [INFO] Safe mode marker enabled after restore (no automatic plugin activation changes). {"previous_plugins_count":17}
[2026-06-01T03:22:12+08:00] [INFO] RESTORE_RUNTIME_OPTIONS_CLEANED {"job_id":"rjb_20260531_191911_f6rhrg","deleted":3}
```

判斷：前一輪 R7 報告中的 P1「runtime options 被來源備份帶回」已被新包修到至少一部分，且 production 已部署此修復。

但這沒有解決 P3 UI 403 的主問題。

## 5. 第八次 UI 失敗時間線

使用者截圖顯示：

- 約 03:20：P3 progress 在 72% 左右時出現 `403 Forbidden` modal。
- 約 03:23-03:26：DevTools Network 大量 `admin-ajax.php` 400/403，畫面提示「Your login/session check was blocked. Restore continues in the background...」。
- 約 03:27-03:28：使用者按下取消還原，畫面仍顯示「Ready to start restore」，但 progress 卡在 86%，並出現 `WP_AJAX_0` warning/error toast。
- 03:22:12 後端其實已完成 success。

access log 對照：

```text
03:19:11 +08 Restore job prepared
03:19:20 +08 pre-restore backup snapshot finished
03:20:01 +08 Post-DB-import recovery: lock, active job, and cron re-established
03:20:21 +08 GET /wp-login.php?interim-login=1&wp_lang=zh_TW 403
03:21:30 +08 admin-ajax.php 200 581
03:21:35 +08 admin-ajax.php 200 581
03:21:45 +08 admin-ajax.php 403 111
03:21:46 +08 admin-ajax.php 403 111
03:22:11 +08 Restore completed
03:22:12 +08 RESTORE_RUNTIME_OPTIONS_CLEANED
03:22:20 +08 admin-ajax.php 403 / 403
03:22:28-03:31:58 +08 大量 admin-ajax.php 400/403 混雜
03:32:22 +08 admin-ajax.php 200 48
03:32:22 +08 GET /wp-login.php?interim-login=1&wp_lang=zh_TW 403
03:32:36 +08 GET /wp-admin/admin.php?page=museder-restoreone-restore 302
03:32:37 +08 GET /mu-admin/?redirect_to=...&reauth=1 200
```

關鍵原始 log：

```text
27.51.15.75 [31/May/2026:12:21:45 -0700] "POST /wp-admin/admin-ajax.php" 403 111
27.51.15.75 [31/May/2026:12:21:46 -0700] "POST /wp-admin/admin-ajax.php" 403 111
27.51.15.75 [31/May/2026:12:22:20 -0700] "POST /wp-admin/admin-ajax.php" 403 111
27.51.15.75 [31/May/2026:12:22:30 -0700] "POST /wp-admin/admin-ajax.php" 400 1
27.51.15.75 [31/May/2026:12:26:20 -0700] "GET /wp-login.php?interim-login=1&wp_lang=zh_TW" 403 14
27.51.15.75 [31/May/2026:12:32:22 -0700] "GET /wp-login.php?interim-login=1&wp_lang=zh_TW" 403 14
27.51.15.75 [31/May/2026:12:32:36 -0700] "GET /wp-admin/admin.php?page=museder-restoreone-restore" 302
27.51.15.75 [31/May/2026:12:32:37 -0700] "GET /mu-admin/?redirect_to=...&reauth=1" 200
```

## 6. 目前程式缺口

### 6.1 Fallback 還是依賴同一個會被擋的 admin-ajax

`assets/js/admin.js` 中 `checkRestoreCompletionFromHistory(jobId)` 的意圖正確，但實作仍呼叫：

```text
action=museder_restoreone_restore_job_status
via admin-ajax.php
appendRestoreProgressAuth(formData, jobId)
```

也就是說：

- 正常 polling 用 `admin-ajax.php`。
- 發生 403/400 後，history fallback 仍用 `admin-ajax.php`。
- cancel restore 也用 `admin-ajax.php`。
- `wp-auth-check` 的 interim login 也被主機/登入路徑/WAF 擋成 403。

因此當 admin session / nonce / WAF / custom login / auth-check 進入不穩定狀態時，所有 UI 的復原路徑都一起失效。

這就是為什麼開發 agent 加了 fallback，現場仍失敗：**fallback 沒有真的換到獨立通道或可免登入 token 通道。**

### 6.2 403/400 被轉成 background warning，但沒有最終成功收斂

新包已出現 warning：

```text
Your login/session check was blocked. Restore continues in the background and this page will keep checking for completion.
```

但現場還是卡住，代表：

- warning 有顯示。
- 後端也成功了。
- 但 UI 沒有可靠拿到 success 並呼叫 `markRestoreCompleted()`。

所以修復只做到「不要立即當成失敗」，沒有做到「一定收斂到成功」。

### 6.3 取消還原按鈕造成更嚴重的 UI/後端語意分裂

使用者在看到多次 403/400 後按「取消還原」。

現場結果：

- 後端 job 最終是 `completed=true`、`cancelled=false`、history `success`。
- UI 顯示 progress 還在 86%，同時又顯示 `Ready to start restore`、`Start / Start Restore`、`Force Unlock`。
- toast 顯示 `WP_AJAX_0`。

這代表 cancel request 沒有成功取消後端 job，也沒有安全地告訴使用者：

> 取消請求無法送達，後端還原仍可能繼續；正在重新確認狀態。

目前 cancel error path 會 reset UI state，導致使用者以為還原已中止或可以重開，但後端 cron 仍持續完成。這是危險 UX。

## 7. 選單與媒體狀態

R8 取證：

```text
1469 主選單圖示_綠能知識01 -> 2020/02/191121x_xICON_02-150x150.png exists=true
1470 主選單圖示_熱水器01 -> 2020/02/191121x_xICON_02-165x300.png exists=true
1471 主選單圖示_優惠訊息01 -> 2020/02/191121x_xICON_02-562x1024.png exists=true
1472 主選單圖示_太陽能光電01 -> 2020/02/191121x_xICON_02-768x1399.png exists=true
2455 主選單圖示_01 -> 2023/01/_01.jpg exists=true
2774 主選單圖示_04-1 -> 2023/01/_04-1.jpg exists=true
```

`widget_media_image`：

```text
raw_len=6103
contains_zh=no
contains_191121=yes
media_image-2 attachment_id=1472 url=...191121x_xICON_02-768x1399.png file_exists=true
media_image-4 attachment_id=1470 url=...191121x_xICON_02-165x300.png file_exists=true
media_image-5 attachment_id=1469 url=...191121x_xICON_02-150x150.png file_exists=true
media_image-6 attachment_id=1471 url=...191121x_xICON_02-562x1024.png file_exists=true
media_image-12 attachment_id=2455 url=...2023/01/_01.jpg file_exists=true
media_image-13 attachment_id=2774 url=...2023/01/_04-1.jpg file_exists=true
```

判斷：第八次沒有看到「選單圖示檔案未修」或「widget raw URL 未修」的證據。選單視覺問題仍應沿用 R7 結論：需拆分 Max Mega Menu mapping / source data expectation，不應再混為 P3 403 的主因。

## 8. 根因判定

### 已修到

1. `apply_pairs` slicing：後端不再 timeout，job 成功。
2. media path / widget URL 修復：`widget_urls_fixed=1`，選單相關檔案存在。
3. RestoreOne runtime stale options cleanup：`RESTORE_RUNTIME_OPTIONS_CLEANED deleted=3`。
4. 部署一致性：production 與本地 2.7.274 zip hash 一致。

### 未修到

P0：UI/status/cancel 在 `admin-ajax.php` 不可靠時沒有獨立成功確認通道。

更具體：

- `checkRestoreCompletionFromHistory()` 還是走 `admin-ajax.php`。
- `cancelRestoreProcess()` 還是走 `admin-ajax.php`，失敗後卻 reset UI。
- `wp-auth-check` 403 仍會出現 modal 或造成 session blocked flow。
- 後端已寫入 `restore_post_complete_access`，但前端沒有可靠地用它避開 WP login/session/nonce 問題完成最終狀態確認。

## 9. 給開發 agent 的一次性修復建議

### P0-1：新增獨立 status/history confirmation 通道

目前不能再只靠 `admin-ajax.php`。

建議提供一個 token-based endpoint，可在 restore job 已建立後用 file token 或 `restore_post_complete_access` 查詢：

- job status
- latest restore history
- safe mode marker
- post-complete message

要求：

- 不依賴 WP admin session。
- 不依賴 WordPress nonce。
- 僅允許查詢目前 job id，token hash 要用 `hash_equals()`。
- token TTL 短、只讀、不可觸發破壞性行為。

當 `admin-ajax.php` 401/403/400/500/WP_AJAX_0 時，前端應切到此通道確認完成。

### P0-2：成功收斂規則

只要任一通道取得：

```text
job.completed=true
job.stage=done
history.result=success for current job_id
```

就必須：

- 停止 monitor。
- progress 設 100%。
- 隱藏/關閉 403 modal 或 auth-check overlay。
- 顯示 Restore Completed success overlay。
- 禁用 cancel restore。
- 不再顯示 Force Unlock。

### P0-3：取消還原語意修正

若 cancel request 送出失敗，尤其是：

```text
403
400
WP_AJAX_0
network/auth/session error
```

不得直接 reset UI 成可重新開始。

應改成：

- 顯示：「取消請求無法確認，後端還原可能仍在執行，正在重新檢查狀態。」
- 保留 active job id。
- 立即用獨立 status/history confirmation 通道查詢。
- 若後端已 success，顯示 success。
- 若後端仍 running，保持 monitor。
- 若後端真的 cancelled，才顯示 cancelled。

### P0-4：auth-check modal 完整攔截

`wp-login.php?interim-login=1` 403 仍在截圖與 access log 中出現。

restore in progress 時：

- 禁止 WordPress auth-check modal 蓋在 restore UI 上。
- 將 auth-check failure 轉為 RestoreOne non-blocking warning。
- 不讓它改變 final restore result。

### P1：QA 必須新增 hostile admin-ajax 測試

需要測試以下場景：

1. restore backend success，但最後 60 秒 `admin-ajax.php` 全部 403。
2. restore backend success，但 `wp-login.php?interim-login=1` 403。
3. 使用者在 backend success 前按 Cancel，但 cancel endpoint 回 `WP_AJAX_0`。
4. fallback status endpoint 可在 admin session 失效時查到 success。
5. UI 不顯示 Force Unlock，最終顯示 success overlay。

## 10. 第八次最終判斷

第八次不是舊包、不是後端還原失敗、不是 R5 timeout、也不是 R7 runtime stale options 未清。

第八次是前一輪 P0 未被真正修完：

> 開發 agent 讓 UI 在 403/400 時「不要立即判失敗」，但沒有提供一條不依賴 `admin-ajax.php` 的成功確認路徑；因此 admin-ajax/auth-check 一旦持續被擋，前端永遠無法可靠得知後端已成功，按取消也只會造成 UI 狀態與後端 cron 狀態分裂。

本輪應要求開發 agent 補上獨立 token-based final status/history confirmation，並修正 cancel error path。否則即使 restore engine 已成功，使用者仍會持續看到「第九次同樣失敗」。

