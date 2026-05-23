# Museder RestoreOne WordPress.org 開發指南

本指南彙整 `docs/000WP開發團隊/` 歷次 WordPress Plugin Directory 審查信、WordPress.org Plugin Handbook、Detailed Plugin Guidelines、Common Issues、Security API、HTTP API、readme 與 SVN 文件。未來所有 Museder RestoreOne Lite 與 Add-on 開發，都必須先符合本指南，再進行功能驗收。

## 核心原則

- WordPress.org 上的 Lite 套件必須是完整可用、GPL 相容、可讀、可維護的外掛，不得包含鎖住的本機 PRO 功能、license unlock、trialware、quota 或時間限制。
- PRO / Add-on 功能必須外掛化並由 WordPress.org 套件外發佈；Lite 只可用中性文字提及「optional add-on」，不得把付費功能程式碼包進 Lite zip 後再鎖住。
- SVN 是發佈系統，不是開發系統；只上傳已測試、可立即給使用者安裝的檔案。GitHub 可做開發，但 WordPress.org 發佈必須同步到 SVN `trunk` 與 version `tags/`。
- 審查團隊不是 QA；每封退審信列出的例子只是樣本。修正時必須全專案搜尋同類問題，並在乾淨 WordPress + `WP_DEBUG` 環境驗證。

## 發佈包邊界

- Lite zip 只包含執行必要檔：主 PHP、`readme.txt`、`uninstall.php`、`assets/`、`includes/`、`templates/`、`languages/`。
- 不得打包 `docs/`、`logs/`、`tools/`、`.git/`、`.github/`、`dist/`、AI 回覆、審查信、測試報告、開發計畫、zip 檔或本地 artifact。
- 第三方 library 必須 GPL 相容、來源清楚、版本更新、授權保留；避免引入 WordPress core 已內建的 library。
- 封裝後必須通過結構驗證：top-level 只能是 `museder-restoreone/`，且必須存在 `museder-restoreone/museder-restoreone.php`，不得出現 `museder-restoreone-<version>/museder-restoreone/...` double-wrap。
- 專案封裝 guardrail：
  - `tools/release/verify-lite-package-structure.sh`（Linux/macOS）
  - `tools/release/verify-lite-package-structure.ps1`（Windows）
- 所有可直接執行的 PHP 檔都必須在 `<?php` 後立即加：
  ```php
  if ( ! defined( 'ABSPATH' ) ) {
      exit;
  }
  ```

## 命名與前綴

- 新增任何 function、class、constant、option、transient、cron hook、AJAX action、REST namespace、script/style handle、JS global 都必須使用專案前綴。
- Lite 使用 `museder_restoreone_`、`MUSEDER_RESTOREONE_`、`Museder_Restoreone_`、REST `museder-restoreone/v*`、JS `MusederRestoreOne*`。
- Add-on 使用 `museder_restoreone_pro_`、`MUSEDER_RESTOREONE_PRO_`、`Museder_Restoreone_Pro*` 或明確 add-on 前綴。
- 不得新增 `backup_lite_*`。舊前綴只能出現在一次性 migration、legacy compatibility、或歷史資料讀取，且需註解原因。
- 不要用 `if ( ! function_exists() )` 包裝專案自有 function/class；此模式只保留給共享 library 或 polyfill。

## 安全輸入輸出

- 原則：sanitize early、validate always、escape late。
- `$_GET`、`$_POST`、`$_REQUEST`、REST params、JSON decode 後的值都必須逐欄處理；不得遍歷整個 request stack 來「全域清洗」。
- 常用對應：
  - 文字：`sanitize_text_field( wp_unslash( $value ) )`
  - textarea：`sanitize_textarea_field()`
  - key / enum：`sanitize_key()` + allowlist
  - 整數：`absint()` 或明確 cast 後 range check
  - 檔名：`sanitize_file_name()`
  - URL 儲存：`esc_url_raw()`；URL 輸出：`esc_url()`
  - email：`sanitize_email()`
  - HTML 輸出：`wp_kses()` / `wp_kses_post()`，不可用 `esc_html()` 假裝保留 HTML。
- 輸出時依 context 使用 `esc_html()`、`esc_attr()`、`esc_url()`、`esc_textarea()`、`wp_kses()`；翻譯輸出優先 `esc_html__()` / `esc_attr__()` / `esc_html_e()`。
- JSON 輸出使用 `wp_json_encode()`，不要直接 `json_encode()` 後 echo。

## Nonce、權限與 API

- 每個 `wp_ajax_*` handler 必須先做 `current_user_can()` 與 `check_ajax_referer()`，再讀取或處理輸入。
- 每個敏感 REST route 必須有 `permission_callback`，且同時檢查能力與 nonce；不可只檢查 nonce 非空。
- 對管理員功能，預設能力為 `manage_options`；若需更細權限，必須在設計中明確說明。
- `wp_verify_nonce()` 的 nonce 來源也要先 `wp_unslash()` + `sanitize_text_field()`。
- 不要把 nonce 條件寫成可被繞過的複雜 OR；驗證失敗與權限不足應明確 return `WP_Error` 或 `wp_send_json_error()`。
- 下載連結若使用 HMAC token 替代 nonce，必須包含 expires、固定 action、檔名白名單、`hash_equals()`，且所有參數需 sanitize / validate。

## 檔案、路徑與大檔 I/O

- 不得硬編碼 `wp-content`、`plugins`、`uploads` 路徑；使用 `plugin_dir_path()`、`plugin_dir_url()`、`plugins_url()`、`wp_upload_dir()`、`admin_url()`、`content_url()`。
- 使用 `ABSPATH`、`WP_CONTENT_DIR`、`WP_PLUGIN_DIR` 前要先問：是否可用 WordPress helper 取代？若必須使用 core include，需在函式內、固定路徑、`file_exists()` 後 `require_once`，並立即使用該 core function/class。
- 所有可寫資料都放在 `wp_upload_dir()['basedir']/museder-restoreone/` 底下；不得寫入外掛目錄或 web root。
- 所有外部傳入檔名/路徑都必須經 helper 解析成真實路徑，使用 `realpath()` 或 normalized prefix 檢查，確保在允許目錄內。
- 備份/還原大檔可使用 `fopen()`、`fread()`、`fwrite()`、`fgets()`、`stream_copy_to_stream()`，但必須局部 `phpcs:disable WordPress.WP.AlternativeFunctions.*` 並註解：大檔串流、WP_Filesystem 不適合、路徑已受控。
- 刪除檔案優先 `wp_delete_file()`；fallback `unlink()` 需有明確限制與註解。

## 資料庫與還原

- 所有可準備的 SQL 必須使用 `$wpdb->prepare()`；IN 條件要為每個元素建立 placeholder。
- SQL identifier（表名、欄位名）不可直接來自使用者輸入；只能來自 `$wpdb`、資料庫 introspection 後 allowlist、或內部固定白名單，並以專用 helper 驗證。
- 若處理備份檔中的 SQL / NDJSON restore payload，需把資料視為高風險輸入：先驗證格式、來源、表名/欄位 allowlist、批次大小，再執行。
- Direct DB / unprepared SQL 的 PHPCS ignore 只能貼在最小範圍，且註解必須說明為何無法 prepare、identifier 如何驗證、值是否已 prepare。
- 不得直接修改其他外掛啟用狀態，例如 `update_option( 'active_plugins', ... )`；復原流程若需安全模式，必須由使用者明確操作且不得暗中啟停其他外掛。

## 外部服務、隱私與 HTTP

- Lite 預設不得呼叫第三方服務；目前允許的預設外連只有同站 `wp-cron.php` 非阻塞 loopback，並需在 `readme.txt` 的 External services / FAQ / Privacy 清楚說明。
- 任一第三方服務（例如 S3、AI、授權、遙測）若進入可發佈套件，必須先確認是否屬於合法 serviceware：外部服務提供實質處理、功能不能合理地完全本機完成、使用者明確設定或 opt-in。
- 任何外部服務都要在 `readme.txt` 說清楚：服務是什麼、用途、送出哪些資料、何時送出、服務條款與隱私政策連結。
- 所有 HTTP 請求使用 WordPress HTTP API（`wp_remote_get()`、`wp_remote_post()`、`wp_remote_request()`）；不得用自寫 cURL。
- 不得從第三方系統載入可執行 PHP/JS 作為外掛功能；所有 admin JS/CSS 必須隨外掛打包並用 enqueue 載入。
- 遙測、追蹤、錯誤回報必須預設關閉、明確 opt-in，且 readme / UI 說明資料與目的地。

## UI、資產與國際化

- JS/CSS 必須透過 `wp_enqueue_script()`、`wp_enqueue_style()`、`wp_add_inline_script()`、`wp_add_inline_style()`；模板中不得直接輸出 `<script>` / `<style>`，除非有極小且必要的例外並註明。
- `wp_localize_script()` 的 object name 與 handle 必須使用專案前綴。
- 所有使用者可見字串必須可翻譯，text domain 為 `museder-restoreone`。
- readme 與 Plugin URI / Author URI 必須可公開連線；不可使用不穩定或 TLS 有問題的 URL。
- readme 只能有一個清楚的 Changelog 結構；Stable tag、主檔 Version、tag 目錄必須一致。

## WordPress.org 發佈流程

- GitHub 可保存開發歷史；WordPress.org SVN 只放 ready-to-use 發佈檔，不放 zip。
- SVN `trunk/` 放最新工作版，`tags/<version>/` 放正式發佈版；release 時從 `trunk` 複製到 tag。
- 每次正式發佈必須提高主檔 `Version` 與 `readme.txt` `Stable tag`，且 PHP `version_compare()` 判定新版本大於舊版本。
- 發佈前至少執行：
  - 打包腳本確認 dev-only 目錄不進 zip
  - Plugin Check（目標 0 errors / 0 warnings；若有 false positive，須有文件與最小範圍註解）
  - 乾淨 WordPress 安裝 + `WP_DEBUG` smoke test
  - Dashboard / Backups / Restore / Schedules / Logs / Settings 基本流程
  - Lite zip 不含 `museder-restoreone-pro/`、`docs/`、`logs/`、`tools/`

## 本專案特殊禁區

- 不要把 `museder-restoreone-pro/` 打包進 WordPress.org Lite zip。
- 不要恢復內建 license gate、PRO-only local feature gate、或免費版 schedule 數量限制。
- 不要把 `docs/000WP開發團隊/`、AI 產物、退審整理、GitHub workflow、測試報告放進外掛發佈包。
- 不要把路徑修正退回 `ABSPATH . 'wp-admin/...'`、`WP_CONTENT_DIR . '/uploads'` 這類硬編碼寫法，除非在固定 core include helper 中有完整理由。
- 不要新增公開 REST endpoint 使用 `__return_true`，除非資料確實公開且註解說明。

## 開發前檢查

每次寫碼前先回答：

1. 這個功能是否屬於 Lite 完整功能，還是 Add-on？若是 Add-on，不得放進 Lite zip。
2. 是否新增輸入來源？若是，nonce/capability、sanitize、validate、escape 是否都設計好？
3. 是否新增檔案/路徑/HTTP/DB/cron/option/transient？是否使用專案前綴與 WordPress API？
4. 是否會被 Plugin Check 標示？若會，能否改寫避免？若不能，註解是否最小且可被審查理解？
5. 是否影響發佈包？打包腳本是否仍排除 dev-only 與 PRO 內容？

