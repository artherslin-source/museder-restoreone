# Museder RestoreOne 外掛介紹頁 — 主題與內容

本文件為 **https://musederlabs.com/restoreone-plugin/** 撰寫，供網站主題或頁面建置使用。內容符合 WordPress 外掛說明慣例：如實描述功能、不誤導、不將商業/定價作為主軸。

---

## 頁面主題（Title / H1）

**建議標題：**  
**Museder RestoreOne — 輕量 WordPress 備份與還原外掛**

**副標（可選）：**  
專為相容性與單檔站點快照設計，提供清楚的還原流程。

**Meta description（約 140 字以內，符合 WP 簡短說明慣例）：**  
Museder RestoreOne 可建立完整 WordPress 備份（資料庫 + wp-content）為單一壓縮檔，並以三步驟精靈還原，適合虛擬主機環境。

---

## 主文區塊

### 開場說明

Museder RestoreOne 是一款輕量的 WordPress 備份與還原外掛，讓您將整個網站（資料庫與 `wp-content`）打包成單一壓縮檔，並透過引導式的三步驟精靈完成還原。

外掛針對虛擬主機環境設計，使用 WordPress 內建 API 進行資料庫備份與還原；壓縮則依賴 PHP 的 ZipArchive 或 WordPress 內建的 PclZip，無需額外系統指令即可運作。

### 主要功能

- **一鍵完整備份**  
  匯出資料庫、`meta.json` 與 `wp-content/` 至單一壓縮檔，可下載或於日後還原。

- **還原中心精靈**  
  三步驟流程：上傳與分析 → 檢視摘要與選項 → 執行還原，並提供即時進度與日誌。

- **分片上傳與驗證**  
  以分片上傳突破 `upload_max_filesize` / `post_max_size` 限制，並具重試與完整性檢查。

- **虛擬主機友善**  
  採用純 PHP 與 WordPress API，無需 `mysqldump`；壓縮依序嘗試 ZipArchive，無法使用時自動改用 PclZip。

- **排程與日誌**  
  可設定至少一組自動排程，並在後台檢視、下載或清理備份與還原的結構化日誌。

- **管理介面**  
  提供 Dashboard、備份、還原、排程、日誌與設定等畫面，含狀態訊息與響應式版面。

### 系統需求

- WordPress 5.8 以上  
- PHP 7.4 以上  
- 建議主機支援 ZipArchive（不支援時會使用 PclZip）

### 備份檔內容

每個備份壓縮檔包含：

- `database.ndjson` — 資料庫結構化匯出（外掛自有格式）  
- `meta.json` — 備份建立時間與方式等中繼資料  
- `wp-content/` — 佈景、外掛與上傳檔案  

足以在同一或另一台主機上還原網站。

### 隱私與外部服務

- **預設行為**：外掛預設不連線至任何外部服務，備份與還原均在您的主機上完成。  
- **可選功能**：若啟用並設定 PRO 相關功能（例如雲端儲存或 AI 分析），則可能連線至您所設定的服務（如 S3 相容儲存、OpenAI 等）；詳見外掛內「外部服務」說明或 WordPress.org 上的 readme。

### 取得與安裝

- 可自 **WordPress 外掛目錄** 搜尋「Museder RestoreOne」安裝，或自外掛頁面下載後上傳至 `wp-content/plugins/` 並啟用。  
- 安裝後於後台側邊選單進入 **Museder RestoreOne**，在「備份」或「還原」頁面即可建立第一次備份。

### 支援與說明

- 功能說明、常見問題與更新紀錄請以 **WordPress.org 外掛頁面** 為準。  
- 若有使用或相容性問題，建議至 WordPress.org 外掛支援論壇或依外掛說明尋求協助。

---

## 簡短 FAQ（可摺疊或精簡列出）

**備份檔有大小限制嗎？**  
單一檔案超過 2GB 會於備份時略過；總站點超過 2GB 仍可備份，只要單檔皆小於 2GB。

**需要 mysqldump 嗎？**  
不需要，資料庫備份與還原皆以純 PHP 透過 WordPress 資料庫 API 完成。

**沒有 ZipArchive 能用嗎？**  
可以，外掛會自動改用 WordPress 內建的 PclZip 建立與解壓縮檔。

**免費版會把資料傳到外部嗎？**  
不會。免費版完全在您的主機上執行，不會將備份內容或站點資料傳送至外部 API 或雲端。

**備份檔會對外公開嗎？**  
不會。下載與上傳由時效性 token 與站內金鑰保護，僅具後台存取權限的使用者可產生有效連結，連結會於短時間後過期。

---

## 使用本文件時請注意

- **標題與段落**：可直接作為 H1、H2、H3 與內文，依現有主題樣式套用。  
- **連結**：請在「取得與安裝」「支援與說明」等處加入實際的 WordPress.org 外掛頁連結（例如：`https://wordpress.org/plugins/museder-restoreone/`，以實際 slug 為準）。  
- **中立性**：本頁以介紹外掛功能與使用方式為主，未包含定價或升級推銷，符合將此 URL 作為 Plugin URI 的用法。  
- **一致性**：描述與 readme.txt、主外掛 header 說明保持一致，避免與 WordPress.org 審查或使用者認知衝突。
