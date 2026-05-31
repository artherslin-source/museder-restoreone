# 綜合 Bug 調查報告：shineching.com R6/R7 反覆 P3 403 / 成功訊息未顯示 / 選單圖示爭議（2.7.274）

提交對象：開發 agent  
整理日期：2026-06-01  
站點：`shineching.com`  
版本：Museder RestoreOne `2.7.274`  
調查來源：

- `docs/BUG-INVESTIGATION-2026-05-31-shineching-r6-p3-ui-403-installatron-core-loss-menu-widgets-2.7.274.md`
- `docs/BUG-INVESTIGATION-2026-05-31-shineching-r7-p3-ui-active-job-menu-2.7.274.md`
- R5 歷史背景：`docs/BUG-INVESTIGATION-2026-05-31-shineching-p3-restore-apply-pairs-timeout-menu-icons-2.7.274-R5.md`

## 1. 結論：不是單一 bug 沒修，而是同一表象下有多個根因

使用者連續看到同一組症狀：

- P3 還原階段約 97% 顯示 `403 Forbidden` / unexpected error。
- 沒有正常跳出「還原成功」訊息。
- 前端選單圖示看起來仍不完整。

但 R5、R6、R7 的底層狀態不同：

| 輪次 | 後端 restore 是否成功 | P3 表象 | 實際根因 |
|---|---:|---|---|
| R5 | 否 | 403 / history running / 99% | `apply_pairs` 一次性全表 search-replace 在 GoDaddy 30 秒 PHP 限制下 fatal timeout |
| R6 | 是 | 403 / 無成功訊息 | 後端已成功，但前端遇到 `wp-login.php?interim-login=1` 403、`admin-ajax.php` 500/403 後沒有收斂回成功狀態 |
| R7 | 是 | 403 / 無成功訊息 | 同 R6：完成前後有 `admin-ajax.php` 403/400；另發現來源備份帶入 RestoreOne runtime stale options |

因此，R5 的後端 timeout 修復看起來有效；R6/R7 反覆發生的是另一個尚未完整修好的產品問題：**後端成功不等於 UI 可可靠告知成功**。

本報告建議開發 agent 一次處理三個修復面：

1. **P0：P3 UI 對 403/400/auth-check interruption 的成功復原。**
2. **P1：RestoreOne runtime options 不應被備份/還原污染目標站狀態。**
3. **P2：選單圖示驗收需拆成檔案、widget URL、Max Mega Menu mapping 三層，避免誤判。**

## 2. 已確認 R5 修復有效的部分

R5 根因是：

```text
cleanup.media_paths_phase=apply_pairs
media_paths_pairs=1778
PHP Fatal error: Maximum execution time of 30 seconds exceeded
stage=cleanup
progress=99
completed=false
history=running
```

R6/R7 取證都顯示 R5 的 slicing 修復已讓 cleanup 完成。

R6：

```text
job_id=rjb_20260531_124625_nvxiph
stage=done
progress=100
completed=true
history.result=success
cleanup.media_paths_phase=done
media_paths_pairs_count=1768
```

R7：

```text
job_id=rjb_20260531_145652_km3ppv
stage=done
progress=100
completed=true
history.result=success
cleanup.media_paths_phase=done
media_paths_pairs_count=1768
```

RestoreOne log in R7：

```text
[2026-05-31T22:59:50+08:00] [INFO] MEDIA_PATHS_RECONCILE_DONE {"job_id":"rjb_20260531_145652_km3ppv","reason":"apply_pairs","fixed":163,"unresolved":10,"scanned":370,"pairs":1768,"content_scanned":7834,"content_pairs":230,"widget_urls_fixed":1}
[2026-05-31T22:59:50+08:00] [INFO] Restore completed. Active plugin list recorded for admin review.
[2026-05-31T22:59:51+08:00] [INFO] Safe mode marker enabled after restore (no automatic plugin activation changes).
```

判斷：開發端不是完全沒修；但只修到 restore engine 能完成，沒有完成 production UI failure recovery。

## 3. P0：P3 UI 對 403/400/auth-check 的復原不足

### 3.1 現場證據

R6 access log：

```text
20:47:35  GET /wp-login.php?interim-login=1&wp_lang=zh_TW 403
20:48:45  POST /wp-admin/admin-ajax.php 500
20:48:46  POST /wp-admin/admin-ajax.php 403
20:50:08  RestoreOne 後端完成
20:50:35  起 /wp-admin/admin-ajax.php 每分鐘 200 48 bytes
21:03:12  /wp-admin/admin.php?page=museder-restoreone-restore 302 -> /mu-admin/?reauth=1
```

R7 access log：

```text
31/May/2026:07:59:17 -0700 POST /wp-admin/admin-ajax.php 200 581
31/May/2026:07:59:22 -0700 POST /wp-admin/admin-ajax.php 200 581
31/May/2026:07:59:27 -0700 POST /wp-admin/admin-ajax.php 403 111
31/May/2026:07:59:28 -0700 POST /wp-admin/admin-ajax.php 403 111
31/May/2026:07:59:35 -0700 POST /wp-admin/admin-ajax.php 400 1
31/May/2026:07:59:49 -0700 POST /wp-admin/admin-ajax.php 400 1
31/May/2026:07:59:51 -0700 RestoreOne 後端完成
31/May/2026:07:59:59 -0700 POST /wp-admin/admin-ajax.php 403 111
31/May/2026:08:07:06 -0700 GET /wp-login.php?interim-login=1&wp_lang=zh_TW 403 14
```

同時段 access log 有大量外部 IP 對 `wp-login.php` POST，疑似 login probing / brute-force 類流量，可能觸發主機/WAF/安全外掛干擾登入、auth-check 或 admin-ajax。

### 3.2 現有程式已有處理，但不夠可靠

目前 `assets/js/admin.js` 已有：

- `handleRestoreTransportInterruption()`
- `installRestoreAuthCheckGuard()`
- `checkRestoreCompletionFromHistory()`
- 403/400/404/500 fallback 與 nonce refresh

但 R6/R7 現場仍看到 modal 與未顯示成功，表示目前前端邏輯有缺口：

- 某些 403/400 沒被歸類成 recoverable interruption。
- `wp-auth-check` / interim-login 403 仍可能蓋出 WordPress modal 或造成使用者看到 403。
- fallback 到 history 的時機、job matching 條件或錯誤後 UI state 可能不夠穩。
- 後端已 `history.success` 時，前端仍可能停在 error/toast/modal，而不是強制切到 success overlay。

### 3.3 開發 agent 應修復的行為

P3 restore 一旦 job 已建立，UI 不應以單次 request 403/400/500 判定失敗。

建議修復規格：

1. `admin-ajax.php` polling 回 401/403/400/500、response body 為 `0`、或包含 `Forbidden` / `interim-login` 時：
   - 不顯示 fatal error overlay。
   - 不把 restore 標記為 failed。
   - 顯示非阻塞 warning：「連線/登入檢查被中斷，還原仍在背景執行」。
   - 立即改用 history/job token fallback 查最終狀態。

2. 若任何 fallback 取得：
   - job `stage=done`
   - job `completed=true`
   - history latest entry `result=success`

   必須強制：
   - `markRestoreCompleted()`
   - progress bar `100%`
   - 顯示 success toast / success overlay
   - 停止 error modal 或 auth-check modal

3. 若 polling 連續失敗但無法確認成功或失敗：
   - UI 應保持「背景還原監控中」而非「失敗」。
   - 提供「重新檢查狀態」而非要求使用者 Force Unlock。

4. `wp-auth-check` 在 restore in progress 時應完全被 suppress：
   - 不讓 `wp-login.php?interim-login=1` 的 403 modal 覆蓋 RestoreOne UI。
   - 只轉成 RestoreOne 自己的 warning + fallback polling。

## 4. P1：RestoreOne runtime options 被備份帶回目標站

### 4.1 現場證據

R6/R7 都看到 DB 裡有 stale option：

```text
museder_restoreone_active_job=900d4241-bc23-4cbf-b641-8426fc88aa6d
museder_restoreone_job_lock_900d4241-bc23-4cbf-b641-8426fc88aa6d=...
```

R7 進一步確認這些不是本輪 restore 產生，而是 seed backup `database.ndjson` 原本就帶入：

```json
{"option_name":"museder_restoreone_active_job","option_value":"900d4241-bc23-4cbf-b641-8426fc88aa6d","autoload":"off"}
{"option_name":"museder_restoreone_job_lock_900d4241-bc23-4cbf-b641-8426fc88aa6d","option_value":"a:2:{s:2:\"ts\";i:1780162704;s:5:\"token\";s:36:\"c11abd37-4d34-4451-b0b3-8e658da14119\";}","autoload":"off"}
```

### 4.2 程式缺口

目前 `includes/class-backup.php` 有排除部分 restore runtime options：

```text
museder_restoreone_restore_lock
museder_restoreone_restore_service_active_job_id
museder_restoreone_restore_token
_site_transient_museder_restoreone_restore_lock
_site_transient_timeout_museder_restoreone_restore_lock
```

但沒有排除 backup job runtime options：

```text
museder_restoreone_active_job
museder_restoreone_job_lock_%
```

這會讓「上一個站點/上一輪操作的 RestoreOne runtime 狀態」被保存到備份檔，再還原到目標站。

### 4.3 風險

即使不直接造成本輪 403，它會污染 admin runtime state：

- UI localize 的 `activeJob` 可能被舊 backup job 影響。
- backup job 建立流程可能以為已有 active job。
- lock 可能誤導使用者看到作業中或需要 unlock。
- 後續備份/還原前置檢查容易混亂。

### 4.4 開發 agent 應修復的行為

建議同時在 backup export 與 restore import/post-restore 做雙層防護：

1. Backup export 排除：
   - `museder_restoreone_active_job`
   - `museder_restoreone_job_lock_%`
   - `museder_restoreone_restore_service_active_job_id`
   - `museder_restoreone_restore_lock`
   - `museder_restoreone_restore_token`
   - `museder_restoreone_restore_post_complete_access`
   - `museder_restoreone_mid_restore_isolation`
   - `_site_transient_museder_restoreone_%`
   - `_site_transient_timeout_museder_restoreone_%`

2. Restore DB import 時不要匯入上述 runtime options。

3. Post-restore cleanup 再掃一次：
   - 刪除非當前 restore job 的 RestoreOne job lock / active options。
   - 保留當前 restore job 必要的 `restore_post_complete_access`，但應有 TTL，過期後清理。

4. 加 regression test：
   - 使用含 `museder_restoreone_active_job` / `museder_restoreone_job_lock_%` 的 fixture DB。
   - restore 完成後目標 DB 不應殘留這些舊值。

## 5. P2：選單圖示問題不是單一「檔案沒還原」

### 5.1 R5 的選單問題

R5 確實有 attachment unresolved：

```text
2020/02/主選單圖示_綠能知識01.png
2020/02/主選單圖示_熱水器01.png
2020/02/主選單圖示_優惠訊息01.png
2020/02/主選單圖示_太陽能光電01.png
```

當時 `_wp_attached_file` 尚未可靠配對。

### 5.2 R6/R7 的選單狀態已不同

R7 取證：

```text
1469 主選單圖示_綠能知識01 -> 2020/02/191121x_xICON_02-150x150.png exists=true
1470 主選單圖示_熱水器01 -> 2020/02/191121x_xICON_02-165x300.png exists=true
1471 主選單圖示_優惠訊息01 -> 2020/02/191121x_xICON_02-562x1024.png exists=true
1472 主選單圖示_太陽能光電01 -> 2020/02/191121x_xICON_02-768x1399.png exists=true
```

`widget_media_image` 也已修：

```text
contains_zh=no
contains_191121=yes
widget_urls_fixed=1
media_image-2  attachment_id=1472 url=.../191121x_xICON_02-768x1399.png file_exists=true
media_image-4  attachment_id=1470 url=.../191121x_xICON_02-165x300.png file_exists=true
media_image-5  attachment_id=1469 url=.../191121x_xICON_02-150x150.png file_exists=true
media_image-6  attachment_id=1471 url=.../191121x_xICON_02-562x1024.png file_exists=true
```

HTTP 也成功：

```text
191121x_xICON_02-150x150.png -> 200 image/png
191121x_xICON_02-768x1399.png -> 200 image/png
2023/01/_01.jpg -> 200 image/jpeg
2023/01/_04-1.jpg -> 200 image/jpeg
```

### 5.3 為何使用者仍覺得選單不完整

首頁 HTML 只輸出：

```text
COUNT 191121x_xICON = 0
COUNT _01.jpg = 2
COUNT _04-1.jpg = 2
COUNT 200210x_ICON = 0
```

Max Mega Menu `_megamenu` mapping 顯示：

- nav menu item `1666` 的 grid 只掛 `media_image-12` 與 `media_image-13`
- nav menu item `1669` 的 grid 只掛 menu items，沒有掛 widget
- seed backup 原始 `database.ndjson` 中 `_megamenu` mapping 也同樣只掛 `media_image-12` / `media_image-13`

判斷：R7 沒有看到「RestoreOne 沒把圖片檔修好」的證據。現在的爭議更像：

- 來源備份本身的 Max Mega Menu grid mapping 是否正確。
- 使用者期待的視覺結果是否和 seed backup 當時的實際 mapping 一致。
- RestoreOne 是否需要對 Max Mega Menu 做更深層「widget sidebar 與 grid item 關聯」修復。

### 5.4 開發 agent 應採取的驗收拆分

不要再用「選單掉圖」一個詞驗收，請拆成三層：

1. Attachment 層：
   - `_wp_attached_file` 非空。
   - `wp-content/uploads/<rel>` 存在。
   - HTTP 200。

2. Widget data 層：
   - `widget_media_image` 中 raw `url` 不再含不存在中文檔名。
   - `attachment_id` 與 `url` 指向同一個存在檔案。

3. Max Mega Menu mapping 層：
   - `_megamenu` serialized grid 是否掛到預期 widget id。
   - 若來源備份沒掛，RestoreOne 不應被判定為檔案還原失敗。
   - 若要自動修 mapping，這是新 enhancement，不是 R5/R6 的檔案還原 bug。

## 6. R6 特殊外部因素：Installatron 刪除 docroot

R6 有一段重要外部事件：

```text
[31/May/2026:06:35:13 -0700] "POST /deleteme.cha6133be8003544b47a925a096f525012b.php?n=1&m=4 HTTP/2.0" 200 ... "Installatron Plugin/10.0.6/547"
```

之後：

```text
GET /wp-content/uploads/2022/12/LOGO_01-2.png 404
GET /wp-login.php 404
GET / 404
```

當時 docroot 中 `wp-config.php`、`wp-content/`、`wp-admin/`、`wp-includes/` 等消失。

目前沒有證據指向 RestoreOne 直接刪除 core；更像主機/Installatron cleanup 或移除流程。這不是本次開發 agent 的主要修復點，但會干擾 QA 判斷。後續 production 測試需確認 Installatron 不會在 restore 後自動清理目標 docroot。

## 7. 為什麼會「修了好幾輪還像沒修」

根本原因是修復與驗收方式被同一表象誤導：

1. 「P3 403」曾經是後端 timeout 的連帶症狀，但 R6/R7 已變成 UI/auth interruption 問題。
2. Docker QA 驗證了 restore engine，沒有模擬 GoDaddy/WAF/auth-check/外部 login probing。
3. 選單問題從 attachment 缺檔，變成 widget raw URL，再變成 Max Mega Menu mapping/視覺期待差異。
4. RestoreOne runtime state 被備份帶入是後來才被明確定位的新缺口。

所以不是「同一個根因一直沒修」，而是「同一個使用者可見失敗畫面下，根因逐層浮現；開發端每次只修一層，沒有一次把整個 failure mode 納入驗收」。

## 8. 建議一次性修復清單

### 必修 P0：P3 成功收斂

- `admin-ajax.php` 401/403/400/500 / `0` / Forbidden / interim-login during restore：一律 treat as recoverable transport interruption。
- 不顯示 fatal error overlay，不把 restore 判為 failed。
- 使用 job status + history + post-complete token fallback。
- 一旦 history 或 job 顯示 success，強制 success UI。
- suppress WordPress `wp-auth-check` modal during restore monitor。
- 增加「重新檢查狀態」按鈕或自動重查，不引導 Force Unlock。

### 必修 P1：Runtime options hygiene

- backup export 排除 RestoreOne runtime state。
- restore import 排除 RestoreOne runtime state。
- post-restore cleanup 清掉非當前 job 的 RestoreOne locks / active pointers。
- 加 fixture regression test。

### 必修 P1：Production-like QA

新增測試場景，不只 Docker happy path：

- job 後端完成，但最後 30 秒 polling 回 403。
- job 後端完成，但 `wp-login.php?interim-login=1` 回 403。
- job 後端完成，但 status endpoint 先回 400/403，history 已 success。
- seed DB 帶 `museder_restoreone_active_job` / `museder_restoreone_job_lock_%`。
- Max Mega Menu `widget_media_image` raw URL 含中文檔名。

### 視需求 P2：Max Mega Menu mapping enhancement

- 先建立「來源備份實際 mapping」與「使用者期待畫面」的對照。
- 若來源備份本身沒有掛 widget，不能再算作檔案還原 bug。
- 若產品要自動修復 mapping，需獨立設計，不要混入 media path reconciliation。

## 9. 驗收標準

開發 agent 完成後，至少要通過以下驗收：

1. 在模擬 `admin-ajax.php` 403/400 的情境下，restore 後端完成後 UI 仍顯示成功。
2. 在模擬 `wp-login.php?interim-login=1` 403 的情境下，不出現 WordPress auth-check 403 modal；RestoreOne 顯示 warning，最後顯示成功。
3. 還原含 stale RestoreOne options 的備份後，目標 DB 不殘留：
   - `museder_restoreone_active_job`
   - `museder_restoreone_job_lock_%`
   - 舊的 restore active job / lock / token / transient runtime state
4. `widget_media_image` 中 `主選單圖示_*` raw URL 被改成存在的 ASCII 檔案。
5. 1469-1472 attachment 檔案存在且 HTTP 200。
6. 報告清楚列出 Max Mega Menu mapping 是否與來源備份一致；若一致但視覺仍不符，標記為來源資料/產品 enhancement，而非 restore failure。

## 10. 提交建議

不要單獨提交 R7 報告作為唯一需求。R7 報告很重要，但它只描述第七次現場。

建議提交本綜合報告，並附上 R6/R7 原始報告作為證據附件：

- 本報告：開發 agent 的主任務與驗收標準。
- R6 報告：證明後端成功但 UI 403、以及 Installatron 外部干擾。
- R7 報告：證明 UI 403 重演、runtime stale options、選單圖示三層拆解。

