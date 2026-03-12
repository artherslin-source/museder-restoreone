# WordPress Plugin Directory 審查回覆解讀報告

**外掛名稱：** Museder RestoreOne  
**審查版本：** 2.7.241  
**審查回覆日期：** 2026 年 1 月 24 日  
**Review ID：** R museder-restoreone/artherslin/1Dec25/T7 23Jan26/3.8RC2  

---

## 一、總體說明

WordPress.org 外掛審查團隊已手動檢查您提交的程式碼，**目前外掛尚未通過審核**。信中列出三類問題，需全部修正、自行測試後再上傳新版本，並回覆該郵件以繼續審查流程。

---

## 二、問題一：AI 產生內容（AI generated output）

### 官方說明

- 偵測到外掛內有**疑似由 AI 工具產生的檔案**（多為開發過程中的變更說明或摘要）。
- 這類檔案**不屬於外掛功能所需**，且作為文件往往零散、未維護，易造成使用者與審查者混淆。
- 建議保留使用 AI 工具協助開發，但請**移除不必要的 AI 產出檔案**，以保持外掛套件簡潔。

### 具體指出的檔案

| 路徑 | 說明 |
|------|------|
| `museder-restoreone/docs/wp-compliance-checklist.md` | 被標記為 AI 產出，需自發行套件中移除 |

### 建議作法

- **從外掛發行包中移除** `docs/wp-compliance-checklist.md`（例如在 `create-package.sh` 或發行清單中排除該檔）。
- 若此檔僅供內部開發/合規對照使用，可保留在版控（repository）中，但**不要打包進提交給 WordPress.org 的 zip**。
- 一併檢查 `docs/` 下其他檔案是否也像「開發筆記、AI 摘要」；若是且非使用者文件，建議一併不納入發行包。

---

## 三、問題二：函式／類別／常數／命名空間／選項名稱不夠專屬（Generic function/class/define/namespace/option names）

### 官方要求摘要

- 所有外掛的 **function、class、define、namespace、option/transient 名稱都必須具唯一性**，避免與其他外掛或佈景主題衝突。
- 建議作法：使用**至少四字元**的專屬前綴（例如以外掛名稱衍生的前綴）。
- **不可使用** `__`、`wp_`、單一 `_` 作為前綴（保留給 WordPress 核心）。
- 翻譯用的 `_n()`、`__()` 等核心函式不在此限；規範對象是**您為外掛自訂的名稱**。
- 不要依賴 `if (!function_exists('NAME'))` 來避免衝突：若別的外掛先載入同名函式，您的程式會失效。此方式僅建議用於**共用函式庫**。
- **Options 與 Transients 必須加前綴**，因為它們寫入共用儲存空間，未前綴易與他外掛衝突；且上線後再改 option 名稱會很棘手，故應從一開始就使用明確前綴。

### 審查結果中與本外掛相關的內容

1. **已有前綴的項目（22 個元素）**  
   使用的前綴包括：`museder`、`musederrestoreone`、`musederrestoreonev2`、`musederrestoreonepro`、`musederrestoreoneadmin`、`musederrestoreonereports`、`musederrestoreonerestore`、`musederrestoreoneadminui` 等。  
   → 這些符合「專屬、至少四字元」的要求，可維持或統一成一組更一致的前綴。

2. **`backup_lite` 前綴（146 個元素）**  
   → 審查方列出此前綴，可能認為「backup_lite」較通用，與他外掛撞名機率較高。建議評估是否改為更專屬的前綴（例如 `museder_restoreone_` 或 `musere_`），並全面替換，以降低衝突風險並符合「unique and distinct」的建議。

3. **Options 未加前綴的疑慮（重點）**  
   官方明確點出：
   - `includes/class-backup-jobs.php` 第 **962** 行：`add_option($key, $value, '', 'no');`
   - `includes/class-backup-jobs.php` 第 **979** 行：`add_option($key, $value, '', 'no');`  

   說明：**Options 與 Transients 都必須使用前綴。**

   補充說明：程式中 `$key` 來自 `get_option_lock_key( $job_id )`，其實作為 `'museder_restoreone_job_lock_' . sanitize_key( (string) $job_id )`，**已有前綴**。審查工具可能只靜態掃到 `add_option($key, ...)` 而無法追蹤 `$key` 來源。  
   建議：
   - 確認專案內**所有** `add_option`、`update_option`、`get_option`、`delete_option` 以及 set/get/delete transient 的 key 皆為**外掛專屬前綴**（如 `museder_restoreone_` 或貴團隊統一的前綴）。
   - 若有任何 option/transient 尚未加前綴，請一律改為帶前綴的 key。
   - 若已全部使用前綴，可在回覆審查郵件時簡短說明：option key 由 `get_option_lock_key()` 產生，且一律使用 `museder_restoreone_job_lock_` 前綴。

### 建議作法整理

- 為 function、class、define、namespace、option、transient 訂定**單一、至少四字元、與外掛強關聯**的前綴（例如 `musere_`、`museder_restoreone_`）。
- 全面搜尋並替換：特別是 **option/transient 的 key**，以及使用 **`backup_lite`** 的 146 處，評估改為新前綴。
- 不要依賴 `function_exists` 來「覆蓋」命名衝突，應從命名前綴根本避免衝突。
- 再次用 Plugin Check / PHPCS + WPCS 掃描，確保沒有遺漏的未前綴 option/transient 或函式/類別名稱。

---

## 四、問題三：允許直接存取外掛 PHP 檔案（Allowing direct file access to plugin files）

### 官方說明

- **直接存取**：使用者在瀏覽器網址列輸入某個 PHP 檔的完整路徑，或對該檔直接送 POST 請求。
- 若檔案僅含 class/function 定義，風險較低；若含有**可執行邏輯**（例如呼叫函式、建立實例、include 其他檔案），在未經 WordPress 載入的情況下被直接執行，風險難以預估且可能偏高。
- 防範方式：在**所有可能被直接觸發執行的 PHP 檔案**開頭（在 `<?php` 與 namespace 之後、其餘程式碼之前）加入：

```php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
```

### 具體指出的檔案

| 檔案 | 行號 | 說明 |
|------|------|------|
| `download-handler.php` | 19 | 被列為需加入直接存取防護的範例 |

### 目前程式狀況與建議

- 目前 `download-handler.php` 在約第 18–19 行有：`if ( ! defined( 'ABSPATH' ) ) { ... }`，但在 **ABSPATH 未定義時**（即被直接存取時）會執行一大段**重導向與邏輯**（讀取 `$_GET`、處理 file/expires/token 等）。
- 官方要求是：**直接存取時應立即 `exit`，不執行任何業務邏輯**。因此當前「直接存取時跑重導向」的寫法不符合「direct file access」防護的預期。
- **建議作法**：
  - 在檔案最前面（`<?php` 與 namespace 之後）改為：  
    `if ( ! defined( 'ABSPATH' ) ) exit;`  
    即：一旦發現不是經由 WordPress 載入（未定義 `ABSPATH`），立即結束，不執行後面任何程式碼。
  - 舊版相容的「下載／重導向」邏輯應改由 **WordPress 環境內**處理（例如透過 `admin-post.php?action=...` 或 hook），而不是在直接開啟該 PHP 檔時執行。如此既符合規範，也避免直接存取時的不可預期行為。

---

## 五、審查團隊的後續提醒與檢查清單

信中請您：

1. **完整讀懂本信**：理解每個問題、必要時查文件或做一點研究，以便正確修正並在日後維護時避免類似狀況。
2. **完成下列檢查清單**（回覆時可一併確認）：
   - ✔ 已根據回饋與自行檢查，修正外掛內所有問題；並使用 Plugin Check、PHPCS + WPCS 等工具協助找出問題。
   - ✔ 已在**乾淨的 WordPress 安裝**上、且 **WP_DEBUG 設為 true** 的環境中測試更新後的外掛，且未跳過此步驟。
   - ✔ 了解若遺漏問題或未確實測試，審查可能被拒絕。
   - ✔ 已到「Add your plugin」上傳更新版本；審查期間可持續更新，團隊會以最新版本為準。
   - ✔ 已回覆本郵件，簡潔說明修正重點或審查團隊需要知道的脈絡（無需逐條列舉所有變更）。

3. **一次修完再回傳**：為加快流程、減輕志工負擔，請盡量在同一版中處理完所有提到的事項後再送出。

4. **可能有誤報**：審查方表明可能會有 false positives，若有疑義可去信說明並附簡短範例，請求釐清。

---

## 六、建議修正優先順序（實作面）

| 優先 | 項目 | 動作摘要 |
|------|------|----------|
| 1 | AI 產出檔案 | 從發行 zip 中移除 `docs/wp-compliance-checklist.md`（必要時一併檢視 `docs/` 其他檔案是否納入發行）。 |
| 2 | 直接檔案存取 | 在 `download-handler.php` 開頭改為「未定義 ABSPATH 則立即 exit」，將舊版下載/重導向邏輯改由 WordPress 內（如 admin-post）處理。 |
| 3 | Option/Transient 前綴 | 確認所有 option/transient key 皆使用外掛專屬前綴；若 962/979 的 `$key` 已來自 `get_option_lock_key()` 且前綴正確，可於回覆中簡短說明。 |
| 4 | 命名前綴一致性 | 評估將 `backup_lite`（146 處）改為更專屬前綴，並統一 function/class/define/namespace 前綴（至少四字元、與外掛相關）。 |

---

## 七、參考連結（來自審查信）

- Make WordPress Plugins: https://make.wordpress.org/plugins/
- Detailed Plugin Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Plugin Check: https://wordpress.org/plugins/plugin-check/

---

*本報告為對 2026-01-24 WordPress Plugin Directory 審查回覆信的解讀與整理，供內部修正與回覆時使用。*
