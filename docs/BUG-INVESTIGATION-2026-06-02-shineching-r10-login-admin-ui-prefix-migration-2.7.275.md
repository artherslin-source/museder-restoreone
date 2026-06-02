# Bug 調查報告：shineching.com 2.7.275 還原後登入/後台 403 與 P3 UI 未收斂

提交對象：開發 agent  
調查時間：2026-06-02（UTC+8）  
站點：`shineching.com`  
版本：Museder RestoreOne `2.7.275`  
Job ID：`rjb_20260601_162347_m4mbkl`  
調查方式：SSH 唯讀、DB 唯讀查詢、access/error log、job/history 檔、production 外掛 hash、本地 2.7.275 程式碼對照。未修改 production 檔案、DB、外掛、設定，也未終止任何 job。

---

## 1. 一句話結論

2.7.275 的後端還原 job 已成功完成，但還原後 WordPress 的資料庫 table prefix 狀態不一致：

- `wp-config.php` / WordPress runtime 使用目前主機前綴：`g7uy_`
- 還原後角色與使用者能力 key 仍停在備份來源前綴：`pa7a_`
- production 目前不存在 `g7uy_user_roles`、`g7uy_capabilities`、`g7uy_user_level`

因此使用者可以透過 cPanel SSO 或前端 cookie 看到前端登入/toolbar 狀態，但進入 `wp-admin` 時，WordPress 以 `g7uy_` 查能力，找不到 administrator capability，最後回 `403`。

本地程式碼根因也已對上：`includes/class-restore-service.php` 已有 `stage_migrate_db_prefix()`，理論上要把 `pa7a_*` option/meta key 改成 `g7uy_*`，但 `stage_import_database()` 在 DB import 成功後於 line 1932 直接 `return`，導致後面的 DB verify 與 prefix migration 全部變成不可達程式碼。即使移除該 `return`，目前 NDJSON import result 也沒有把 `source_prefix` / `target_prefix` 回傳給 service 層，後段 `$rewrite_from` / `$rewrite_to` 仍無法可靠運作。

---

## 2. 使用者觀察到的三個症狀

### 症狀 A：管理後台入口輸入帳密後無法登入

access log 顯示登入流程可 POST，但導回 `wp-admin` 後被拒：

```text
GET /wp-admin/admin.php?page=museder-restoreone-restore -> 302
GET /mu-admin/?redirect_to=...&reauth=1 -> 200
POST /mu-admin/ -> 302
GET /wp-admin/admin.php?page=museder-restoreone-restore -> 403
```

後續使用者目前 Chrome 視窗亦有 `GET /wp-admin/admin.php?page=... -> 403`。

### 症狀 B：前端管理員 menu 可見，但點擊後無法進入管理後台

截圖顯示前端 toolbar / Museder menu 可見，代表目前瀏覽器仍有某種登入或 SSO session 狀態。但 WordPress 後台進入時會重新檢查 capability，production DB 中沒有 `g7uy_capabilities`，所以 `current_user_can()` 會失敗，進入 `wp-admin` 被 403。

### 症狀 C：前一輪 P3 UI 最終沒有正確出現還原成功訊息

上一輪監控已確認：

- job `rjb_20260601_162347_m4mbkl` 後端 `stage=done`
- `progress=100`
- `completed=true`
- restore history `result=success`
- 耗時 167 秒，非 timeout
- REST `final-status` 長時間 HTTP 200

但使用者瀏覽器沒有看到正確 completed overlay。這次新證據顯示，完成後 production 的管理能力已壞掉，access log 在還原後 10:11:24 -0700 已出現從 restore/backups 頁面導向登入並在重新進 `wp-admin` 時 403。也就是：UI 原本應靠 REST-only loop 收斂，但 session/capability 在 DB import 後變成不可信，admin-ajax 開始 400/403、頁面 reauth，最終讓 P3 UI 無法穩定完成顯示。

---

## 3. Production 證據

### 3.1 版本與檔案

```text
PLUGIN_VERSION_HEADER=2.7.275
PLUGIN_VERSION_CONST=2.7.275
ADMIN_JS_SHA1=2903be141377dabbe0de9c41a97866a83e8e0667
wp-config.php mtime=2026-06-01 09:26:18 -0700
.htaccess mtime=2026-06-01 09:26:06 -0700
```

`.htaccess` 沒有看到會阻擋 `wp-admin` 的異常規則，主要是一般 WordPress rewrite、wp-config deny、xmlrpc deny、LiteSpeed `noabort` 與 AIOSEO sitemap rewrite。

### 3.2 還原 job 已成功

```text
rjb_20260601_162347_m4mbkl.json
mtime=2026-06-01T16:26:43+00:00
stage=done
progress=100
completed=yes
message=Restore completed successfully.
```

restore history：

```json
{
  "job_id": "rjb_20260601_162347_m4mbkl",
  "date": "2026-06-01 16:26:43",
  "file": "shineching.com-20260531013823-V6yYBa-1.zip",
  "result": "success",
  "duration_seconds": 167
}
```

### 3.3 Prefix 狀態錯誤

production `wp-config.php` table prefix：

```text
TABLE_PREFIX=g7uy_
```

`g7uy_options` 存在，且 `siteurl/home` 正確：

```text
siteurl=https://shineching.com
home=https://shineching.com
active_plugins=... museder-restoreone/museder-restoreone.php ...
```

但 roles option 只有來源前綴：

```json
{"option_name":"pa7a_user_roles","len":"5520"}
```

沒有 `g7uy_user_roles`。

使用者能力 meta 也只有來源前綴：

```json
{"meta_key":"pa7a_capabilities","c":"3","users":"1,2,3"}
{"meta_key":"pa7a_user_level","c":"3","users":"1,2,3"}
```

三個 administrator 都掛在 `pa7a_capabilities`：

```json
{"ID":"1","user_login":"artherslin","meta_key":"pa7a_capabilities","meta_value":"a:1:{s:13:\"administrator\";s:1:\"1\";}"}
{"ID":"2","user_login":"sunpoweroflight","meta_key":"pa7a_capabilities","meta_value":"a:1:{s:13:\"administrator\";b:1;}"}
{"ID":"3","user_login":"yestw90312969@gmail.com","meta_key":"pa7a_capabilities","meta_value":"a:1:{s:13:\"administrator\";b:1;}"}
```

以目前 WordPress runtime 的 `g7uy_` 來看，查不到 administrator：

```text
---ADMIN_USERS---
<empty>
```

這是後台 403 的直接原因。

---

## 4. 程式碼根因

### 4.1 NDJSON DB import 會改寫 table name，但不會改寫 option/meta key

`includes/class-restore.php` 的 NDJSON import 會讀取 `meta.table_prefix` 作為 `$source_prefix`，並把 table 名稱從來源前綴改到 `$wpdb->prefix`：

```php
if ( 'meta' === $type ) {
    if ( isset( $obj['table_prefix'] ) ) {
        $maybe = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $obj['table_prefix'] );
        if ( '' !== $maybe ) {
            $source_prefix = $maybe;
        }
    }
    continue;
}
```

```php
if ( '' !== $source_prefix && '' !== $target_prefix && 0 === strpos( $table, $source_prefix ) ) {
    return $target_prefix . substr( $table, strlen( $source_prefix ) );
}
```

所以資料表被匯入到 `g7uy_options` / `g7uy_usermeta` 是合理的。

但 row 內部資料，例如：

- `option_name = pa7a_user_roles`
- `meta_key = pa7a_capabilities`
- `meta_key = pa7a_user_level`

並沒有在 `import_database_from_ndjson()` 內同步改成 `g7uy_*`。

### 4.2 service 層已有 prefix migration，但目前永遠跑不到

`includes/class-restore-service.php` 的 `stage_import_database()` 在 DB import 成功後寫入 stage/progress，然後直接 return：

```php
if ( ! empty( $result['success'] ) ) {
    ...
}
$meta['updated_at'] = current_time( 'mysql' );
self::write_job_meta( $job_id, $meta );

return;

if ( ! empty( $result['success'] ) ) {
    // Guard against "restore success but empty content" ...
```

`return` 後面的以下邏輯全部不可達：

- `verify_restored_database_core_tables()`
- 建立 `$meta['checkpoints']['prefix_migrate']`
- 切到 `stage='prefix-migrate'`
- 呼叫後續 `stage_migrate_db_prefix()`

### 4.3 即使移除早退，prefix migration 仍缺必要資料

不可達區塊使用：

```php
if ( is_string( $rewrite_to ) && '' !== $rewrite_to ) { ... }
...
if ( $rewrite_from && $rewrite_to && $rewrite_from !== $rewrite_to ) { ... }
```

但 `stage_import_database()` 目前沒有定義 `$rewrite_from` / `$rewrite_to`，而 `Museder_Restoreone_Restore::import_database()` 的成功結果只回傳：

```php
$result['success'] = true;
$result['message'] = __( 'Database restore completed successfully.', 'museder-restoreone' );
$result['code']    = 'database_restored';
if ( ! empty( $active_plugins ) ) {
    $result['active_plugins'] = $active_plugins;
}
```

因此開發 agent 不能只移除 `return`；還需要讓 NDJSON import 回傳 `source_prefix` / `target_prefix`，或把 prefix-dependent key migration 放到 `import_database_from_ndjson()` 內，在 `$source_prefix` / `$target_prefix` 還在 scope 內時完成。

### 4.4 `stage_migrate_db_prefix()` 本身方向正確

該函式已明確處理正確目標：

- `wp_options.option_name`: `{from}_user_roles` → `{to}_user_roles`
- `wp_usermeta.meta_key`: `{from}_capabilities`、`{from}_user_level` → `{to}_capabilities`、`{to}_user_level`

但因 4.2/4.3，它未在本輪 production job 生效。

---

## 5. UI 未出現成功訊息的根因關聯

2.7.275 的 UI 修復點存在，production `admin.js` hash 也正確。`assets/js/admin.js` 中：

- `fetchRestoreFinalStatus()` 走 REST token
- `applyRestoreFinalStatusPayload()` 看到 `job.status=success` 或 `stage=done/progress>=100` 會呼叫 `markRestoreCompleted()`
- `markRestoreCompleted()` 會設定 success lock、停止 REST-only loop、顯示 overlay

production access log 也顯示 `final-status` 連續回 200。

但這次的 DB prefix bug 讓還原後的 WordPress capability 壞掉，接著發生：

```text
POST /admin-ajax.php -> 400
GET /wp-admin/admin.php?page=museder-restoreone-restore -> 302
GET /mu-admin/?redirect_to=...&reauth=1 -> 200
POST /mu-admin/ -> 302
GET /wp-admin/admin.php?page=museder-restoreone-restore -> 403
```

也就是 UI 層碰到的不是單純 R9 的 hostile auth-check，而是「restore 完成後目前登入者失去 admin capability」。即使 REST final-status 能查到 success，頁面生命週期、WP reauth、admin-ajax 400/403、後台權限檢查會把使用者帶離或阻斷管理頁，導致 success overlay 不可靠。

結論：P3 UI 未收斂是次生症狀；本輪最優先根因是 DB prefix-dependent keys 沒有遷移。

---

## 6. 為什麼 2.7.275 Docker QA 沒抓到

`docs/QA-SHINECHING-PROFILE-2.7.275.md` 驗證了：

- 後端 job `stage=done`
- REST final-status success
- media paths
- 備份後再還原
- UI guard 靜態檢查

但沒有明確驗證還原後目前 runtime prefix 下的 WordPress 權限資料：

- `{$wpdb->prefix}user_roles` 必須存在
- 至少一個 admin user 必須有 `{$wpdb->prefix}capabilities` 且含 `administrator`
- `wp-admin` 在登入後不可回 403

此外 `tools/qa/verify-restore-ui-convergence-guards.php` 是靜態檢查，只確認 `admin.js` 有 UI success lock / REST-only loop / auth-check guard 字串，無法驗證 DB prefix migration 或登入後台權限。

---

## 7. 修復建議（給開發 agent）

### P0：修正 DB prefix-dependent key migration

建議方向：

1. 在 `Museder_Restoreone_Restore::import_database_from_ndjson()` 成功回傳中加入：
   - `source_prefix`
   - `target_prefix`
   - `prefix_rewrite_applied`

2. 在 `Museder_Restoreone_Restore_Service::stage_import_database()` 成功後：
   - 不要在 line 1932 早退掉 verify/migrate
   - 若 `source_prefix !== target_prefix`，進入 `stage='prefix-migrate'`
   - 讓下一 tick 執行 `stage_migrate_db_prefix()`

3. 或者更簡潔：直接在 NDJSON import 完成後、session restore 前/後，在同一函式內用已知 `$source_prefix` / `$target_prefix` 執行 prefix-dependent key migration。這可避免 service 層拿不到 source/target prefix。

必須處理：

- `options.option_name`
  - `{from}_user_roles` → `{to}_user_roles`
- `usermeta.meta_key`
  - `{from}_capabilities` → `{to}_capabilities`
  - `{from}_user_level` → `{to}_user_level`
- serialized values 若長度相同可直接 replace；長度不同不可盲目 replace serialized string，需用反序列化/遞迴轉換或只改 key。

本輪 `pa7a_` 與 `g7uy_` 長度相同，但修復不能依賴長度相同。

### P0：補 DB verify guard

完成 DB import 後，不能只驗證 `options/posts` table 存在。必須驗證 WordPress 可用性：

- `SELECT option_value FROM {$prefix}options WHERE option_name='{$prefix}user_roles'`
- `SELECT COUNT(*) FROM {$prefix}usermeta WHERE meta_key='{$prefix}capabilities' AND meta_value LIKE '%administrator%'`
- 若為 0，不得標記 restore success；應 fail with actionable message 或自動執行 prefix migration 後再驗證。

### P1：補 QA

新增 production profile / Docker E2E assertion：

```text
assert option {$prefix}user_roles exists
assert no stale {$source_prefix}_user_roles remains when source_prefix != target_prefix
assert at least one administrator has {$prefix}capabilities
assert /wp-admin/ after login-capable context does not return 403
```

也建議新增靜態檢查，防止 `stage_import_database()` 中 DB verify/prefix migration 再次變成 unreachable code。

### P1：UI 收斂防線補強

DB prefix 修好後仍建議保留/加強 R9 UI guard，但本輪應把 UI 問題降為次要：

- REST final-status 成功後應立即停止 admin-ajax tick/status loop。
- `markRestoreCompleted()` 成功後，不應再觸發 reauth/admin-ajax 重新覆寫 running 狀態。
- 如果頁面已經因 reauth/403 離開，應在重新登入後由 history 顯示明確 completed 狀態。

---

## 8. 建議驗收清單

### 單元 / 靜態

- 建立一個 NDJSON fixture：source prefix `pa7a_`，target prefix `g7uy_`
- import 後確認：
  - tables 是 `g7uy_options` / `g7uy_usermeta`
  - option key 是 `g7uy_user_roles`
  - usermeta key 是 `g7uy_capabilities` / `g7uy_user_level`
  - 沒有殘留 `pa7a_user_roles` 作為唯一角色來源

### Docker E2E

- 用 `shineching.com-20260531013823-V6yYBa-1.zip` 跑 clean restore
- restore done 後直接查 DB prefix/capability
- 確認至少一個 administrator 可通過目前 `$wpdb->prefix` 辨識
- browser 或 HTTP 層確認後台不再是 403

### Regression

- source prefix = target prefix 時不應重複改寫
- source/target prefix 長度不同時不應破壞 serialized data
- files-only restore 不應執行 DB prefix migration
- SQL manual import fallback 不應誤標 completed 或誤做 prefix migration

---

## 9. 開發 agent 任務摘要

請在 `2.7.276` 或下一修復版處理：

1. 修復 `stage_import_database()` 中 DB verify / prefix migration 不可達的控制流程。
2. 讓 NDJSON import 暴露或直接使用 `source_prefix` / `target_prefix`。
3. 確保 `pa7a_user_roles`、`pa7a_capabilities`、`pa7a_user_level` 會在還原到 `g7uy_` runtime 時遷移為 `g7uy_*`。
4. 補上「還原後目前 `$wpdb->prefix` 下至少一個 administrator」的 QA gate。
5. 保留 2.7.275 的 UI success lock / REST-only loop，但把本輪 P3 UI 問題視為 DB capability 失效的次生症狀一起驗證。

本輪 production 現況不建議繼續觸發 restore 或終止 job；job 已成功，問題在還原完成後的 WordPress 權限資料不一致。
