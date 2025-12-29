# WordPress.org / Plugin Check 工程檢核清單（備份/還原外掛）

本清單用於 Museder RestoreOne 在實作「大站備份/還原」功能時，持續符合 WordPress.org 規範與 Plugin Check。

資料來源：
- `WP規範.rtf`
- `docs/plugin-check-notes.md`
- AI1WM（All-in-One WP Migration）在 WP 生態常見的做法（作為參考基準）

---

## 1) Licensing / Directory

- **Plugin Header**：主檔必須包含並正確填寫
  - `License: GPLv2 or later`
  - `License URI: https://www.gnu.org/licenses/gpl-2.0.html`
- **第三方套件授權**：
  - 所有打包在外掛內的 library 必須是 **GPL 或 GPL-compatible**
  - 保留原作者授權/attribution（建議在檔頭保留原版 copyright）

---

## 2) 禁止混淆/加密程式碼（不要做）

- 不使用 `ionCube` / `Zend Guard`
- 不用 `base64` 方式隱藏 PHP 程式碼
- 不使用 `eval()`（或任何等價動態執行）
- include/require 的路徑不得由使用者輸入決定（只能是固定路徑或白名單）

---

## 3) HTTP / 外部資源 / 遠端服務

- **所有外部 HTTP** 必須用 WordPress HTTP API（`wp_remote_get/post`）
- 若功能可以在本地完成，**避免依賴外部線上服務**
  - 例如 AI1WM Unlimited 的遠端 WASM 服務（`service.wasm`）不建議引入 Free 版（合規/隱私/可用性風險）
- 若需要下載遠端資源（例如更新、授權檢查）：
  - 必須可停用或有明確條款
  - 失敗時不得阻斷已提供的核心功能

---

## 4) Telemetry / Tracking

- 若要收集任何 telemetry（例如錯誤統計、使用行為、性能資料）：
  - **必須 opt-in**（預設關閉）
  - UI 必須明確描述收集內容、用途、傳送目的地

---

## 5) Capabilities / Nonce / Permission

### REST API

- `permission_callback` 必須同時檢查：
  - `current_user_can('manage_options')`（或更細的 capability）
  - `X-WP-Nonce`（通常用 `wp_verify_nonce($nonce, 'wp_rest')`）

### AJAX

- 每個 `wp_ajax_*` handler 必須：
  - `current_user_can(...)`
  - `check_ajax_referer(...)`
- 若某方法只會被「已經驗證過 nonce 的上游函式」呼叫，才能：
  - `phpcs:disable WordPress.Security.NonceVerification.Missing`
  - 並附上註解說明「為何安全、驗證在哪裡」

---

## 6) Input Sanitization / Validation / Output Escaping

### Input

- `$_GET/$_POST/$_REQUEST`：`sanitize_text_field( wp_unslash(...) )`
- integer：`absint(...)`
- 檔名：`sanitize_file_name(...)`
- URL：`esc_url_raw(...)`
- array：`array_map('sanitize_text_field', wp_unslash(...))`
- `$_FILES['tmp_name']`：用 `is_uploaded_file()` 驗證即可（它是系統路徑，不是使用者文字輸入）；必要時加 `phpcs:ignore` 說明

### Output

- HTML text：`esc_html()`
- attribute：`esc_attr()`
- URL：`esc_url()`
- 允許 HTML：`wp_kses($html, $allowed_tags)`

---

## 7) File I/O（AlternativeFunctions）

備份/還原必須處理大檔案，WP_Filesystem 不適合 hot path；可使用 `fopen/fread/fwrite/stream_copy_to_stream`，但必須符合：

- **路徑約束**：所有讀寫必須落在 plugin 控制的目錄與 helper（例如 `backup_lite_get_backup_path()`、`backup_lite_get_jobs_dir()`）
- **不信任使用者輸入**：任何外部傳入 filename/paths 必須先 sanitize 並透過 helper 解析成白名單路徑
- **phpcs 註解**：
  - `phpcs:disable WordPress.WP.AlternativeFunctions.*` / `phpcs:enable ...`
  - 並註解理由（串流大檔、WP_Filesystem 不適用、路徑受控）
- **刪檔**：優先 `wp_delete_file($path)`；必要時 fallback `@unlink()` 並加註解
- **禁止寫入 Web root**：所有暫存/上傳/解包必須在 `wp-content/uploads/...` 或 plugin 自有目錄（透過 `wp_upload_dir()` 推導）

---

## 8) Database（DirectDatabaseQuery）

- 允許 direct query，但必須：
  - table 名稱只來自 `$wpdb->prefix/base_prefix`、`SHOW TABLES` 結果、或內部白名單
  - 若插入變數：用 `$wpdb->prepare()`（除非是匯入 SQL 檔那種 multi-statement）
  - 在必要區塊用 `phpcs:disable WordPress.DB.DirectDatabaseQuery.*` 並說明理由

---

## 9) Templates（PrefixAllGlobals）

- 後台 template 若使用短變數名，需在檔頭/檔尾加：
  - `phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound`
  - `phpcs:enable ...`
  - 並說明此檔為內部 template、變數由 controller 傳入、非通用 API

---

## 10) 大站功能的「合規落地」要點（本專案特別提醒）

- **切片續跑（time-slicing）**：使用 cron / 多段 request；不要靠瀏覽器長連線或無限 timeout 來硬撐
- **Resume 能力**：上傳/解包/寫檔/匯入 DB 必須可 checkpoint（offsets），避免單次 request 超時造成「半套」狀態
- **避免外部依賴**：Free 版不要引入遠端 WASM 服務；`.wpress` 解析需本地完成

### 10.1 備份檔可驗證性（避免「假成功」）

- **備份 ZIP 內建 metadata**：建議將 `package.json` 與 `manifest.ndjson` 放入 ZIP（可用於 verify/restore/診斷）
- **Finalize verify**：至少包含
  - `database.sql`、`meta.json`、`package.json`、`manifest.ndjson` 存在性檢查
  - `manifest_offset` 必須達到 EOF（確保 packing 已完整消化檔案清單）
  - 必要時抽樣 `locateName()` 驗證 ZIP 內檔案可被定位（避免 ZipArchive 假成功）

### 10.2 串流處理（NDJSON / legacy JSON）與 WP 規範

- **串流讀寫允許**：`fopen/fread/fgets/fwrite/fseek` 可用於大檔/大量資料（manifest、sql、archive），但必須
  - 檔案路徑落在 plugin-controlled 目錄（jobs/temp/backup dir）
  - 註解理由（效能/避免 OOM）並最小化 `phpcs:disable WordPress.WP.AlternativeFunctions.*`
- **legacy JSON 轉換**：若需從 `manifest.json` 轉換為 `manifest.ndjson`，避免 `file_get_contents + json_decode` 整包載入造成 OOM；建議用 streaming state machine 並 fail-fast

### 10.3 Search-Replace（serialized-safe）

- 若要做「搬站 URL 替換」，請注意：WP options/meta 常含 PHP serialized，**不可直接 str_replace 在 serialized 原字串上**
- 建議策略（AI1WM-style）：
  - 偵測 serialized → safely unserialize（禁用 object）→ 递迴替換 → 重新 serialize（確保長度欄位正確）
  - 任何 unserialize 異常需 fail-safe（不要 fatal），並可選擇保守略過該欄位


