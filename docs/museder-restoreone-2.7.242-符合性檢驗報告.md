# museder-restoreone-2.7.242 符合性檢驗報告

**檢驗對象：** 實體檔案 `museder-restoreone-2.7.242.zip`  
**檢驗依據：** 詳讀 000WP開發團隊 內退審信與 WP 官方規範後，逐項對照 [WP官方審核要求與規範彙整.md](./WP官方審核要求與規範彙整.md) 之審核重點。  
**檢驗方式：** 對 zip 解壓後之內容進行靜態檢查（檔案結構、主檔 header、readme、關鍵程式碼搜尋）。

---

## 一、發行包與 metadata

| # | 退審／規範要求 | 是否符合 | 檢驗結果 |
|---|----------------|----------|----------|
| 1.1 | 發行 zip 不含 `docs/` 或 AI／內部開發用檔案 | ✅ 符合 | 解壓後僅有 `museder-restoreone/` 下之 assets、includes、languages、templates、download-handler.php、museder-restoreone.php、readme.txt；**無 docs/ 目錄**。 |
| 1.2 | Plugin URI 可連線 | ✅ 符合 | 主檔宣告 `Plugin URI: https://musederlabs.com/restoreone-plugin/`。本環境以 curl 請求該 URL，**HTTP 200**，可連線。 |
| 1.3 | 主檔 Version 與 readme Stable tag 一致 | ✅ 符合 | 主檔 `Version: 2.7.242`，readme `Stable tag: 2.7.242`，一致。 |
| 1.4 | readme 具「External services」段落，載明服務、資料、時機、ToS／Privacy 連結 | ✅ 符合 | readme 含「== External services ==」；說明預設不使用；並載明 **Amazon S3**（用途、傳送資料、時機、Domains、Terms/Privacy 連結）與 **OpenAI**（同上）。 |

---

## 二、安全性（Nonce／權限／Sanitize／Escape／直接存取）

| # | 退審／規範要求 | 是否符合 | 檢驗結果 |
|---|----------------|----------|----------|
| 2.1 | 可被直接請求的 PHP 檔有 `if ( ! defined( 'ABSPATH' ) ) exit;` | ✅ 符合 | 主檔、download-handler.php、includes 與 templates 下之 PHP 檔均含 ABSPATH 檢查。僅 `includes/vendor/pclzip/class-pclzip.php` 無（第三方庫，非外掛對外入口）。 |
| 2.2 | 不直接 include wp-load.php／wp-config.php／wp-blog-header.php | ✅ 符合 | 搜尋結果：**無** 對上述核心載入檔的 include／require。 |
| 2.3 | 使用 $_POST／$_GET 的 AJAX／handler 有 nonce 與權限檢查 | ✅ 符合 | 多個 handler 中可見 `wp_verify_nonce`／`check_ajax_referer`／`current_user_can` 等使用；Plugin Check 對本版報告為 0 errors（見 [Plugin-Check-測試報告.md](./Plugin-Check-測試報告.md)）。 |
| 2.4 | 輸入 sanitize／validate，輸出 escape | ⚠️ 1 則警告 | Plugin Check 仍報 **1 warning**：`includes/class-settings.php` 第 233 行使用 `$_POST['settings']` 未經 sanitize（該處有 nonce 與註解，但工具仍標示）。建議補上對 `$_POST['settings']` 之適當 sanitize 以消除警告。 |

---

## 三、核心檔載入與路徑

| # | 退審／規範要求 | 是否符合 | 檢驗結果 |
|---|----------------|----------|----------|
| 3.1 | 不直接載入核心「載入檔」；若 require_once 核心檔，僅在需用函式內、且僅授權可觸及 | ✅ 符合 | 未見 require wp-load/wp-config。有 `require_once ABSPATH . 'wp-admin/includes/file.php'`（class-restore-handler.php、class-ui.php），屬後台 includes，且於函式內載入，符合退審說明。 |
| 3.2 | set_time_limit／ini_set 僅在需長時間執行的函式內 | ✅ 符合 | `set_time_limit` 出現於 class-restore-service、class-ai1wm-converter、class-restore-handler、class-backup、class-ui 之**函式／方法內**（如還原、備份、轉檔流程），非在 init 或建構子頂層。 |
| 3.3 | 路徑／URL 優先使用 plugin_dir_path、wp_upload_dir 等 | ✅ 主檔符合 | 主檔已定義 `MUSEDER_RESTOREONE_PATH`（plugin_dir_path）、`MUSEDER_RESTOREONE_URL`（plugin_dir_url）。其餘檔案仍有部分使用 ABSPATH／WP_CONTENT_DIR，屬歷次退審曾點名之「建議改進」項，非單一阻斷項。 |

---

## 四、輸入處理與 option 前綴

| # | 退審／規範要求 | 是否符合 | 檢驗結果 |
|---|----------------|----------|----------|
| 4.1 | 不整包處理 php://input；只處理必要欄位 | ⚠️ 部分不符 | **2 處** 仍使用 `file_get_contents( 'php://input' )`：`includes/class-settings.php:241`、`includes/class-schedule-handler.php:966`。260206 要求改為只解析並處理必要欄位；目前為部分不符，建議改為僅讀取／解析所需欄位。 |
| 4.2 | option／transient 名稱具前綴且建議靜態可見 | ⚠️ 靜態可見性不足 | `includes/class-backup-jobs.php` 第 962、979 行為 `add_option( $key, ... )`，`$key` 來自 `self::get_option_lock_key( $job_id )`，該方法回傳 **`museder_restoreone_job_lock_` + sanitize_key( $job_id )**，實際具前綴。但審查方依**靜態分析**可能仍標示「option 名稱非明顯前綴」。建議以常數或註解讓前綴在程式碼中一目了然，以通過工具／人工覆核。 |

---

## 五、資料庫與命名

| # | 退審／規範要求 | 是否符合 | 檢驗結果 |
|---|----------------|----------|----------|
| 5.1 | 查詢使用 $wpdb->prepare()，變數不直接拼接 SQL | ✅ 符合 | Plugin Check 未報 SQL 相關 error；退審信曾點名處已有註解或修正。 |
| 5.2 | 函式／類別／namespace／option 具唯一前綴（至少 4 字元） | ✅ 符合 | 外掛使用 museder、museder_restoreone、MUSEDER_RESTOREONE、Backup_Lite 等前綴；option 實際為 museder_restoreone_job_lock_*（見上）。 |

---

## 六、WP 官方規範條文對應（摘要）

| 條文 | 是否符合 | 說明 |
|------|----------|------|
| §2 開發者責任／第三方服務條款 | ✅ | readme 已說明外部服務並附 ToS/Privacy。 |
| §4 程式碼可讀 | ✅ | 無混淆、可讀。 |
| §6／§7 服務與追蹤文件化 | ✅ | External services 段落完整。 |
| §13 使用 WP 內建程式庫 | ✅ | 未重複打包 WP 已內建之程式庫。 |
| §15 版本號遞增 | ✅ | 2.7.242 符合。 |
| §16 送審時為完整外掛 | ✅ | zip 含完整外掛目錄與必要檔案。 |

---

## 七、Plugin Check 結果（對本 zip 之對應）

- 專案內以**同版外掛**（2.7.242）於 WordPress 環境執行 Plugin Check 之結果見 [Plugin-Check-測試報告.md](./Plugin-Check-測試報告.md)。  
- 結果：**0 errors、1 warning**（class-settings.php 之 InputNotSanitized）。  
- 據此，**museder-restoreone-2.7.242.zip 在「Plugin Check」層面無阻斷送審之 error**，僅 1 則建議修正之 warning。

---

## 八、總結：是否符合要求和規範

| 類別 | 結果 |
|------|------|
| **發行包與 metadata** | ✅ **符合**（無 docs/、Plugin URI 可連、版本一致、External services 完整）。 |
| **安全性（直接存取、核心載入、nonce/權限）** | ✅ **符合**（ABSPATH 齊全、無違規核心載入、nonce/權限已實作）；僅 1 則 sanitize 警告建議處理。 |
| **PHP 限制與路徑** | ✅ **符合**（set_time_limit 僅在函式內；主檔使用建議 API）。 |
| **輸入處理** | ⚠️ **部分不符**（2 處 php://input 整包讀取，建議改為只處理必要欄位）。 |
| **option 前綴** | ⚠️ **實質符合、靜態可見性不足**（實際有前綴，建議讓審查／工具易於辨識）。 |
| **資料庫與命名** | ✅ **符合**。 |
| **WP 官方規範（§2、4、6、7、13、15、16）** | ✅ **符合**。 |

**結論：**  
在「詳讀 000WP開發團隊 退審信與 WP 官方規範」之前提下，對 **museder-restoreone-2.7.242.zip** 之檢驗結果為：

- **多數項目符合**，且**無**明顯阻斷送審之違規（如 Plugin URI 無效、缺少 External services、直接載入 wp-load、無 ABSPATH、無 nonce 等）。
- **建議送審前改進**：  
  1. 消除 Plugin Check 之 1 則 warning（`$_POST['settings']` sanitize）。  
  2. 將 2 處 `file_get_contents( 'php://input' )` 改為只解析並處理必要欄位，以完全符合 260206 要求。  
  3. 讓 option 名稱前綴在 class-backup-jobs 中靜態可見（常數或註解），降低再次被點名之風險。

以上檢驗均針對實體檔案 **museder-restoreone-2.7.242.zip** 解壓後之內容進行。
