---
name: A-plan remove upload-handler
overview: 將 Restore 檔案上傳流程完全改走既有 WP REST v2 端點（permission_callback + X-WP-Nonce），不再使用任何可被直接請求且含 wp-load.php bootstrap 痕跡的 upload-handler.php；由另一個 AI 實作改動，我方負責監督、驗收與小站/大站回歸測試。
todos:
  - id: force-rest-upload
    content: 修改前端 chunk 上傳流程，強制走 REST v2，移除/禁用 native upload-handler 分支
    status: pending
  - id: remove-uploadhandler-localize
    content: 後端不再對 JS 注入 uploadHandler/uploadSecret（class-ui.php）
    status: pending
    dependencies:
      - force-rest-upload
  - id: remove-or-stub-upload-handler
    content: 移除 upload-handler.php 或改成 404/410 stub，且不得含 wp-load.php/bootstrap 字串
    status: pending
    dependencies:
      - remove-uploadhandler-localize
  - id: verify-rest-chunk-compat
    content: 確認/修正 REST v2 chunk endpoints 支援 octet-stream + streaming + permission_callback
    status: pending
    dependencies:
      - force-rest-upload
  - id: update-readme
    content: 更新 readme 說明上傳走 REST、不直接載入核心檔（降低審核疑慮）
    status: pending
    dependencies:
      - remove-or-stub-upload-handler
  - id: supervisor-regression-tests
    content: 我方在 Docker WP 環境重跑小站/大站備份還原、並做 grep 合規掃描與網路請求驗證
    status: pending
    dependencies:
      - remove-or-stub-upload-handler
      - verify-rest-chunk-compat
      - update-readme
---

# 方案A落地計畫：移除 `upload-handler.php` bootstrap，改為全程走 WP REST（合規且保功能）

## 目標

- **合規目標**：外掛包內不再存在任何「直接 include `wp-load.php`/`wp-config.php`/`wp-blog-header.php`」的 bootstrap 模式（符合 WP 審核信 `Calling core loading files directly`）。
- **功能目標**：維持現有備份/還原功能（含大檔>1GB、分片上傳、續傳/重試、驗證/還原流程）。
- **實作分工**：
- **建置/改動**：由另一個外掛 AI 執行。
- **監督/驗收/測試**：由我執行（在本 repo 的 Docker WP 環境重跑小站+大站端到端測試）。

---

## 現況定位（問題來源）

- `upload-handler.php` 目前包含 `wp-load.php` 的 `require_once`（bootstrap）邏輯，這是 WP 審核信明確點名禁止的模式。
- 前端 `assets/js/chunk-upload-v2.js` 目前採用 **雙路徑**：
- 若 `MusederRestoreOneV2.uploadHandler` + `uploadSecret` 存在 → 走 native handler（`upload-handler.php`）
- 否則 → 走 REST v2 `/prepare`、`/chunk`、`/finalize`

**方案A核心**：讓上傳永遠走 REST v2，並從包內移除或「徹底廢棄」`upload-handler.php`。

---

## 要求另一個 AI 進行的改動（具體到檔案）

### 1) 前端：強制走 REST v2，上傳不再使用 native handler

- **檔案**：[`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work/museder-restoreone/museder-restoreone/assets/js/chunk-upload-v2.js`](file:///Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work/museder-restoreone/museder-restoreone/assets/js/chunk-upload-v2.js)
- **改動要點**：
- 移除或禁用 `useNativeHandler` 分支（例如強制 `useNativeHandler=false`），讓 chunk 上傳永遠走 REST 的 `restRequest('chunk', ...)` 路徑。
- 保留原本 retry/resume 行為（`status` 查詢、`next_missing`、`processedChunks`）。

**驗收**：瀏覽器 network 不再有任何對 `.../wp-content/plugins/museder-restoreone/upload-handler.php` 的請求。

### 2) 後端：不再對 JS 注入 `uploadHandler/uploadSecret`

- **檔案**：[`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work/museder-restoreone/museder-restoreone/includes/class-ui.php`](file:///Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work/museder-restoreone/museder-restoreone/includes/class-ui.php)
- **改動要點**：
- `wp_localize_script('backup-lite-chunk-upload-v2','MusederRestoreOneV2', ...)` 中：
  - 移除 `uploadHandler` 與 `uploadSecret` 欄位（或永遠填空字串）。
- 僅保留 REST 所需的：`restUrl`、`nonce`。

**驗收**：`window.MusederRestoreOneV2` 內不再包含 `uploadHandler` / `uploadSecret`。

### 3) 移除或徹底廢棄 `upload-handler.php`

- **檔案**：[`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work/museder-restoreone/museder-restoreone/upload-handler.php`](file:///Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work/museder-restoreone/museder-restoreone/upload-handler.php)

二選一（建議 A）：

- **A. 直接從 plugin package 移除該檔案**（最乾淨，避免審核掃描命中）。
- **B. 保留檔案但改為「不可用 stub」**：
- 只留下：`if ( ! defined('ABSPATH') ) { header('HTTP/1.1 404 Not Found'); exit; }`
- 並在 ABSPATH 已定義時也直接返回 404/410（不做任何事）。
- **禁止**出現 `wp-load.php`/`require_once`/bootstrap 相關字串。

**驗收**：`grep -R "wp-load\.php"` 在外掛整包內沒有任何命中（含註解/字串）。

### 4) 確保 REST v2 chunk 上傳端點能支撐大檔

- **檔案**：
- [`.../includes/class-chunk-handler-v2.php`](file:///Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work/museder-restoreone/museder-restoreone/includes/class-chunk-handler-v2.php)
- [`.../includes/class-restore-controller.php`](file:///Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work/museder-restoreone/museder-restoreone/includes/class-restore-controller.php)
- **改動要點**（如已有則只需確認）：
- `permission_callback` 必須嚴格：`current_user_can('manage_options')` + `wp_verify_nonce( X-WP-Nonce, 'wp_rest')`。
- `/chunk` route 必須可接受 `application/octet-stream` 且使用 streaming（避免一次把 chunk 全吃進 memory）。
- `/finalize` 必須可合併 chunks 並把最終檔案放到 uploads 目錄的 plugin slug 之下（你們目前是 `wp-content/uploads/museder-restoreone/...`）。

**驗收**：用 >1GB 檔案走 REST chunk upload + finalize，可成功進入還原流程並完成。

### 5) 文件/說明更新（避免審核疑慮）

- **檔案**：`readme.txt`
- **改動要點**：
- 在 FAQ 或 restore 說明加一句：
  - “Upload/restore uses WordPress REST API endpoints and does not load WordPress core files directly.”
- 若保留任何 legacy 檔案（stub），明確註記已停用。

---

## 我方（監督/測試方）驗收標準（必達）

### 合規掃描（必達）

- `grep -R "wp-load\.php\|wp-config\.php\|wp-blog-header\.php"` 在外掛包內：
- **不得**出現 `wp-load.php`（特別是 `upload-handler.php`）。
- 若 readme/docs 提到 `wp-config.php`（教學文件）可接受，但不可是「外掛程式碼中 include」。

### 功能回歸（必達）

在相同測試堆疊（Elementor + PowerPack + WooCommerce + Yoast + CF7 + Classic Editor + Wordfence Login Security）下：

- **小站**：備份→還原成功；Elementor 測試頁、商品頁可開啟；外掛啟用狀態維持。
- **大站**：
- 生成約 1.2~1.5GB 不可壓縮檔（uploads/large-site）
- 備份成功產出 >1GB zip
- 還原成功；`uploads/large-site` 仍存在且 `du -sh` >1GB

### 行為驗證（必達）

- 瀏覽器 Network：還原 upload 過程中 **沒有**任何對 `upload-handler.php` 的 request。
- Restore wizard 仍正常（Step 1/3 能走完）。

---

## 風險與緩解

- **風險：某些主機會擋 REST/loopback**
- 緩解：REST 已有 permission_callback；必要時可在 UI 增加「fallback: admin-post chunk endpoint」做 B 方案。
- **風險：大檔上傳會被 server timeout**
- 緩解：維持 chunk size 小（例如 2MB），並讓後端用 stream 寫檔，避免 memory 爆。

---

## 交付物

- 新版 plugin zip（移除/廢棄 `upload-handler.php`）
- 變更摘要（哪些檔案、為什麼改、如何驗證合規）
- 我方驗收報告（小站/大站測試紀錄 + 合規 grep 結果）