## 檢核與測試報告（第三方檢核）

- **外掛**：`museder-restoreone` v2.7.224（zip：`museder-restoreone-2.7.224.zip`）
- **相容性外掛（同時啟用測試）**：
  - Elementor（wp.org）
  - PowerPack Elements Pro 2.12.15（zip：`powerpack-elements-pro_2.12.15.zip`）
  - WooCommerce、Yoast SEO、Contact Form 7、Classic Editor、Wordfence Login Security（wp.org）
- **測試日期**：2026-01-12

---

## 1) 測試環境（可重現）

本次以 Docker 建立乾淨站台，並在同一站台內進行備份/還原（符合你要求「啟用狀態下測試」）。

- **WordPress**：6.9（容器 `wordpress:php8.2-apache`）
- **PHP**：8.2.30（容器內）
- **DB**：MariaDB 10.11.15
- **WP_DEBUG**：true（環境變數 `WORDPRESS_DEBUG=1`）
- **站台 URL**：`http://localhost:8089`
- **啟用外掛清單（還原後驗證仍啟用）**：
  - classic-editor 1.6.7
  - contact-form-7 6.1.4
  - elementor 3.34.1
  - museder-restoreone 2.7.224
  - powerpack-elements 2.12.15
  - woocommerce 10.4.3
  - wordfence-login-security 1.1.15
  - wordpress-seo 26.7

---

## 2) 依 WP 審核回覆規範逐條檢核（合規性）

以下對照你提供的 WP 審核信（`TXT.rtf`）列出的重點類別。

### A. 未揭露第三方/外部服務（External services disclosure）

- **結論**：**文件層面已補齊（看起來符合 WP 要求）**
- **證據**：`readme.txt` 明確列出「預設不使用外部服務」，以及 PRO 啟用才可能連到 Amazon S3 / OpenAI，並說明：
  - 用途（What for）
  - 傳送資料（What data）
  - 觸發時機（When）
  - 網域/端點（Domains/Endpoints）
  - Terms/Privacy 連結

參考（節錄）：`/Users/jerrylin/MusederLab/Wordpress-Plugin-test/_work/museder-restoreone/museder-restoreone/readme.txt` 內的 `== External services ==` 段落。

### B. 直接載入 WP 核心檔（Calling core loading files directly）

#### B1. 高風險：`upload-handler.php` 仍含 `wp-load.php` bootstrap 邏輯

- **結論**：我無法替你保證「一定不會被 WP 以此點退回」。因為 WP 審核信明確要求 **不要在外掛檔案內直接 include `wp-load.php`**，而本外掛仍存在相關程式碼片段。
- **原因**：`upload-handler.php` 一開始雖然有 `ABSPATH` 檢查，但接著仍嘗試尋找並 `require_once` `wp-load.php`（這會被靜態掃描或人工審查視為「直接載入核心檔」的模式）。

參考位置：

- `upload-handler.php`：`wp-load.php` 搜尋/載入（L24-L38）

#### B2. 低風險/可能可接受：`cron.php`/PclZip 等核心檔的「例外用法」

WP 審核信提到：若屬「例外情境」，可 `require_once` 後**立即使用該檔的函式**。

本外掛在 `Backup_Lite_Restore_Service::spawn_cron()` 會在 `spawn_cron()` 不存在時載入 `wp-includes/cron.php`，並立刻呼叫 `spawn_cron()`（屬於信中提到可能被接受的模式）。

參考位置：

- `includes/class-restore-service.php`：`spawn_cron()`（L635-L641）

> 備註：即便屬「例外」，仍建議你用 WP 官方推薦的方式評估是否能避免載入 `cron.php`（例如改用 WP 既有的排程/HTTP 觸發流程），以降低再次被點名的機率。

---

### C. 路徑/目錄判斷正確性（Determining locations correctly）

- **結論**：**實測結果顯示已符合 WP 的「寫入檔案應放 uploads」要求**。
- **證據（實測）**：外掛在站內建立以下目錄，且備份/還原/日誌都落在 uploads 之下：
  - `wp-content/uploads/museder-restoreone/backups/`
  - `wp-content/uploads/museder-restoreone/logs/`
  - `wp-content/uploads/museder-restoreone/jobs/`
  - `wp-content/uploads/museder-restoreone/temp/`

> 這點對 WP 審核非常關鍵：避免把可寫入檔案放在外掛目錄內。

---

### D. Nonces + 權限（AJAX/REST 的安全性）

- **結論**：主要入口點看起來是「manage_options + nonce」的標準組合，符合審核信要求。

**AJAX（admin-ajax.php）**

- `includes/class-ui.php` 的 `verify_ajax_request()` 會先 `current_user_can('manage_options')`，再 `check_ajax_referer(self::NONCE, 'nonce', false)`（一致的集中檢查）。

**REST（wp-json）**

- `includes/class-restore-controller.php` 的 REST routes 使用 `permission_callback`，內部同樣檢查：
  - `current_user_can('manage_options')`
  - `wp_verify_nonce($nonce, 'wp_rest')`（取 header `X-WP-Nonce`）

**admin-post.php（下載）**

- `includes/class-ui.php` 透過 `admin_post_backup_lite_download_backup` 走 `handle_backup_download()`，使用 `check_admin_referer(...)` 或 legacy token 驗證。

---

### E. 資料 Sanitization / Escaping / Validation

- **結論**：大多數輸入點有使用 `sanitize_*`/`absint`/`sanitize_file_name`，輸出端常見也有 `esc_html__()` 等；整體方向符合 WP 建議。
- **仍需注意的點**：
  - `upload-handler.php` 作為「直接 endpoint」的特殊檔案，安全性採用 secret header（不是 nonce），這在 WP plugin review 常見會被要求改成走 WP REST/AJAX 入口（讓 WP 權限與 nonce 流程一致）。

---

### F. 命名與 options/transients 前綴（避免衝突）

- **結論**：整體大量使用 `backup_lite_` / `BACKUP_LITE_` 前綴，看起來有試著避免衝突。
- **備註**：WP 審核信也點名「options/transients 必須前綴」，建議你再跑一次 Plugin Check/PHPCS 全量掃描，確保沒有漏網的動態 key 或未前綴 option。

---

### G. 直接存取（Direct file access）

- **結論**：多數 PHP 檔案在開頭有 `if ( ! defined('ABSPATH') ) exit;` 類似防護。
- **備註**：`download-handler.php` 已改為「相容 stub → 轉導 admin-post」，方向正確。

---

### H. SQL 安全（restore import）

- **結論**：還原 SQL 的路徑屬「執行整段 SQL dump」類型，因此很難用 `$wpdb->prepare()` 包住整句；外掛採用「保守 allow/deny」的風險分類與阻擋某些高風險 statement。
- **風險說明**：WP 靜態掃描工具可能仍會把 `$wpdb->query( $prepared )` 標成 Direct DB / NotPrepared，即使你有合理理由與防護策略。這屬於「可能被審核挑戰的灰區」。

---

## 3) 功能性測試（備份/還原）

### 3.1 小站（<1GB）

**資料集內容**

- 建立 Elementor 測試頁（含 `_elementor_data` meta）
- 建立 WooCommerce 商品
- 建立 Contact Form 7 表單
- 匯入 3 張 jpg 圖片

**備份**

- 產出檔：`localhost-20260112100146-kEFNA4.zip`
- UI 顯示大小：約 **69.05 MB**
- UI 顯示耗時：約 **00m 37**

**還原**

- 還原 job：`rjb_20260112_101720_1n4r4j`
- 結果：**Restore completed successfully**

**還原後驗證**

- 前台可開啟（含 Elementor 測試頁、商品頁）
- 外掛仍維持啟用（見 1) 的 active plugins list）
- Safe Mode：還原後曾自動進入 safe mode（顯示提示），已可透過後台按鈕正常退出並恢復外掛。

### 3.2 大站（>1GB）

**資料集內容**

- 在 `wp-content/uploads/large-site/` 生成 **24 個 50MB** 的隨機檔案（合計約 **1.2GB**，不可壓縮），模擬大站媒體負載。

**備份**

- 產出檔：`localhost-20260112103454-lWQ1VE.zip`
- UI 顯示大小：約 **1.24 GB**（檔案實際大小約 1.3G）
- 內部 job 記錄耗時：約 **115 秒**

**還原**

- 還原 job：`rjb_20260112_104514_yi71qp`
- 結果：**Restore completed successfully**

**還原後驗證**

- `uploads/large-site` 仍存在且 `du -sh` 約 **1.2G**
- Elementor 測試頁、WooCommerce 商品頁可打開
- 外掛仍維持啟用
- Safe Mode：同樣可正常退出

---

## 4) 我給你的最終判斷（白話結論）

### 4.1 「功能」結論

- **備份與還原功能**：在「小站」與「大站(>1GB)」情境下，且在 Elementor + PowerPack + WooCommerce 等外掛同時啟用時，**均可成功完成備份與還原**。
- **大站備份檔大小**：確實產生 >1GB 的備份檔（約 1.24GB/1.3G），符合你要的情境。

### 4.2 「合規」結論

我不能替你下「完全沒有違反 WP 規定」的保證，原因是：

- `upload-handler.php` 仍包含 `wp-load.php` 直接載入的程式碼片段，這正好落在 WP 審核信明確點名的禁區（即使你認為路徑上可能不會走到，審核仍可能以存在該模式為由要求修正）。

其餘類別（外部服務揭露、nonce/權限、uploads 目錄寫入、admin-post 下載、REST permission_callback 等）整體方向看起來是朝合規調整。

---

## 5) 建議（最小修正方向）

- **優先修正**：移除/改寫 `upload-handler.php` 的 `wp-load.php` 載入邏輯，讓上傳流程改走 WP REST/AJAX（或至少避免任何 `wp-load.php`/`wp-config.php` 的 bootstrap 片段出現在 plugin 檔案內）。
- **建議補強**：針對 SQL restore 的 direct query 相關點，準備一段給審核團隊的說明（為何不能 prepare、已做哪些 allow/deny 防護與風險阻擋），降低被再次點名的機率。

