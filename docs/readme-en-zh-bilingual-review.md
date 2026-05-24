# Museder RestoreOne — readme 中英對照檢驗稿

> **來源：** `readme.txt`（行銷／說明區，至 Screenshots；Changelog 未翻譯）  
> **正式提交 WordPress.org 請用英文 `readme.txt`。**
>
> **2026-05 更新：** 公開 readme **暫不提及 Premium Extensions**（待日後付費擴充上線再寫）。  
> **完整中英對照（含 Changelog 摘要）請見：** [`readme-en-zh-full.md`](readme-en-zh-full.md)

---

## 命名定義（產品用語）

| 用語 | 英文（readme） | 繁體中文 |
|------|----------------|----------|
| 目錄外掛名稱 | **Museder RestoreOne – WP Backup & Restore** | Museder RestoreOne – WP 備份與還原 |
| 本體（WordPress.org 這一包） | **Museder RestoreOne** / **this plugin** / **the main plugin** | **Museder RestoreOne**（本外掛／主外掛） |
| 付費進階（未來） | **Premium Extensions**（optional, separate plugins） | **Premium Extensions**（進階擴充，選用、獨立外掛） |
| 不再使用 | ~~RestoreOne Lite~~、~~free plugin~~（指本體時）、~~add-on~~（對使用者改說 Premium Extensions） | — |

---

## 標頭與短描述

### Plugin header / readme 標題

**EN**

```
=== Museder RestoreOne – WP Backup & Restore ===
Contributors: artherslin
Tags: backup, migration, restore, clone, site-backup
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.7.263
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
```

**繁中**

```
=== Museder RestoreOne – WP Backup & Restore ===
貢獻者：artherslin
標籤：backup, migration, restore, clone, site-backup
需要 WordPress：5.8 以上
測試至：6.9
需要 PHP：7.4
Stable tag：2.7.263
授權：GPLv2 或更新版本
```

### Short description（短描述，≤150 字元）

**EN**

```
Large-site WordPress backup & restore with Museder RestoreOne—async jobs, chunked uploads, local migration. Premium Extensions optional.
```

**繁中**

```
大型 WordPress 站備份還原：Museder RestoreOne 提供非同步工作、分塊上傳、本機遷移。Premium Extensions 為選用。
```

### 主檔 Description（後台外掛列表）

**EN**

```
Large-site WordPress backup & restore—async jobs, chunked uploads, and local migration. Optional Premium Extensions.
```

**繁中**

```
大型站點備份還原：非同步工作、分塊上傳、本機遷移。可選用 Premium Extensions。
```

---

## == Description ==

**EN — opening**

**Museder RestoreOne** is built for **larger WordPress sites** on everyday hosting—and includes **large-site backup and restore in this plugin** (the version you install from WordPress.org), not behind a separate paid core product. Package your database and `wp-content` into **one downloadable archive**, run **background backup jobs** (not a single fragile browser request), and restore through a **guided wizard** with progress, logs, and safety checks.

**繁中 — 開頭**

**Museder RestoreOne** 專為一般主機上的 **大型 WordPress 網站** 設計，並在 **本外掛**（自 WordPress.org 安裝的版本）內提供 **大站備份與還原**，而非另需付費的「核心產品」。可將資料庫與 `wp-content` 打包成 **單一可下載封存檔**，以 **背景備份工作** 執行（不依賴一次脆弱的瀏覽器請求），並透過附進度、日誌與安全檢查的 **引導式精靈** 還原。

---

**EN — Looking for large-site backup without Premium Extensions?**

Many WordPress backup plugins reserve **chunked or large archive imports**, **direct site-to-site migration**, **multisite**, or **“unlimited” migration size** for **paid or premium tiers**. **Museder RestoreOne** includes the following **in the main plugin** for **local, on-server** workflows (download your archive, copy it to another host, restore with the wizard):

* **Background backup jobs** (async via WordPress cron)
* **Backup size estimation** before you commit to a long job
* **Chunked REST uploads** for multi‑gigabyte ZIPs on low `upload_max_filesize` hosts
* **Validate & dry-run** before a full restore
* **Multiple backup schedules** on the same site (local schedules; not gated on a Premium Extension)
* **Full-site restore / migration** using archives **you** store—no third-party cloud required

**Premium Extensions** (optional, separate plugins released over time) may add cloud destinations, encryption, or other extras. They are **not** required to back up, download, and restore a large site on servers you control.

**繁中 — 不必為大站去買 Premium Extensions？**

許多備份外掛把 **分塊／大型封存匯入**、**站對站直接遷移**、**Multisite** 或 **「無上限」遷移容量** 放在 **付費或 Premium 方案**。**Museder RestoreOne** 在 **主外掛** 中即包含下列 **本機、伺服器端** 能力（下載封存 → 複製到新主機 → 精靈還原）：

* **背景備份工作**（WordPress cron 非同步）
* **備份前容量估算**
* **REST 分塊上傳**（適用 `upload_max_filesize` 很小的主機）
* **還原前 validate 與 dry-run**
* **多組本機備份排程**（不以 Premium Extension 鎖定）
* **全站還原／遷移**（封存由 **您** 自行保存，無需第三方雲端）

**Premium Extensions**（選用、日後獨立發佈的外掛）可提供雲端目的地、加密等，**並非** 在大站備份、下載與還原時的必要條件。

---

**EN — Large-site backup & restore (included in Museder RestoreOne)**

* **Background backup jobs** — async via cron, admin progress UI
* **Backup size estimation** — batched scan; UI guidance over **1 GB**
* **Chunked archive uploads** — authenticated REST API, retries, integrity checks
* **Validate & dry-run before execute**
* **Long-restore resilience** — restore token fallback; tolerant polling during DB import
* **Honest limits** — single files **>2 GB skipped**; total site can exceed 2 GB per file cap; staging/host limits may apply—see FAQ

Database: WordPress APIs in PHP—**no `mysqldump`**. Archives: `ZipArchive` or **PclZip**.

**繁中 — 大站備份還原（含於 Museder RestoreOne）**

* **背景備份工作** — cron 非同步，後台顯示進度
* **備份容量估算** — 分批掃描；超過 **1 GB** 有介面提示
* **分塊封存上傳** — 驗證過的 REST API、重試與完整性檢查
* **執行前 validate／dry-run**
* **長時間還原韌性** — restore token；DB 匯入時容錯輪詢
* **誠實限制** — 單檔 **>2 GB 略過**；總站可更大；極端情況仍需 staging／主機限制—見 FAQ

資料庫：PHP 內 WordPress API，**不需 mysqldump**。封存：`ZipArchive` 或 **PclZip**。

---

**EN — Who is it for?**

* Sites outgrowing another plugin’s **free tier** where large features are **Pro-only**
* Growing or large sites without shell / `mysqldump`
* Agencies / freelancers migrating with chunked uploads, estimates, validate/dry-run
* Anyone wanting backup → restore without Premium Extensions they do not need yet

**繁中 — 適合誰？**

* 其他外掛 **免費版不夠用、大站能力在 Pro** 的站點
* 成長中／大型站，無 shell／mysqldump
* 代管／接案遷移，需分塊上傳與上線前檢查
* 只要備份→還原流程、暫不需要 Premium Extensions 的使用者

---

**EN — More features / Multisite**

（一鍵全站備份、還原中心精靈、排程日誌、現代化後台 UI — 與先前繁中稿相同。）

Multisite: **not formally tested**; single-site recommended; network use **experimental**.

**繁中 — 更多功能／Multisite**

（功能條列同前。）

Multisite：**未正式測試**；建議單站；多站僅 **實驗性**。

---

## == External services ==

**EN**

This plugin does not use external services. Optional short non-blocking loopback to **your own** `wp-cron.php`. Admin assets from `assets/`. Optional **Premium Extensions** may add their own network behavior.

**繁中**

本外掛 **不使用外部服務**。僅可能對 **本站** `wp-cron.php` 做短暫非阻塞 loopback。管理介面資源來自 `assets/`。選用的 **Premium Extensions** 可能有各自的網路行為。

---

## == Privacy ==

**EN（摘要）**

Stores backups, logs, reports, schedules on **your server** under `wp-content/uploads/museder-restoreone/`. Does **not** upload to third-party clouds. **Premium Extensions** (if installed) may send data only when you configure them. Uninstall removes options/crons, **not** backup ZIPs/logs/reports.

**繁中（摘要）**

備份、日誌、報告、排程存於 **您伺服器** 的 `wp-content/uploads/museder-restoreone/`。不上傳至第三方雲端。**Premium Extensions** 僅在您設定後才可能傳送資料。解除安裝會清選項／cron，**不刪除** 封存與日誌檔。

---

## == Installation ==

**EN**

**From the WordPress.org Plugin Directory (recommended)**

1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for **Museder RestoreOne** (or open [Museder RestoreOne on WordPress.org](https://wordpress.org/plugins/museder-restoreone/) and click **Download**, then upload if your host blocks the in-dashboard installer).
3. Click **Install Now**, then **Activate**.
4. Open the **Museder RestoreOne** menu in your admin sidebar.
5. Go to **Backups** or **Restore** and create your first backup.

**Manual install (alternative)**

1. Download the plugin ZIP from WordPress.org or copy the `museder-restoreone` folder into `/wp-content/plugins/` (FTP/SFTP or **Plugins → Add New → Upload Plugin**).
2. Activate **Museder RestoreOne** under **Plugins**.
3. Open **Backups** or **Restore** and create your first backup.

**繁中**

**從 WordPress.org 外掛目錄安裝（建議）**

1. 後台前往 **外掛 → 安裝外掛**。
2. 搜尋 **Museder RestoreOne**（或開啟 [WordPress.org 上的外掛頁](https://wordpress.org/plugins/museder-restoreone/) 按 **下載**，若主機無法在目錄內安裝再上傳 ZIP）。
3. 按 **立即安裝**，再 **啟用**。
4. 開啟側欄 **Museder RestoreOne** 選單。
5. 進入 **Backups（備份）** 或 **Restore（還原）** 建立第一個備份。

**手動安裝（替代方式）**

1. 從 WordPress.org 下載 ZIP，或將 `museder-restoreone` 資料夾放到 `/wp-content/plugins/`（FTP/SFTP 或 **外掛 → 安裝外掝 → 上傳外掛**）。
2. 在 **外掛** 畫面啟用 **Museder RestoreOne**。
3. 開啟 **Backups** 或 **Restore** 建立第一個備份。

---

## == Frequently Asked Questions ==（FAQ 中英對照）

| # | EN（標題） | 繁中（標題） |
|---|------------|--------------|
| 1 | Do I need Premium Extensions for large-site backup and restore? | 大站備份還原是否需要 Premium Extensions？ |
| 2 | Is Museder RestoreOne suitable for large WordPress sites? | Museder RestoreOne 適合大型 WordPress 站嗎？ |
| 3 | Can I migrate my WordPress site to another host with Museder RestoreOne? | 能用 Museder RestoreOne 遷到新主機嗎？ |
| 4 | What does the backup archive contain? | 備份封存包含什麼？ |
| 5 | Third-party format compatibility = partnership? | 第三方格式相容是否代表合作？ |
| 6 | Are there any file size limits? | 有檔案大小限制嗎？ |
| 7 | Do I need mysqldump, SSH, or WP-CLI? | 需要 mysqldump、SSH 或 WP-CLI 嗎？ |
| 8 | What if ZipArchive is not enabled? | 沒有 ZipArchive 怎麼辦？ |
| 9 | How does chunked restore upload work over REST? | 分塊還原上傳 REST 如何運作？ |
| 10 | Will scheduled backups and email always run? | 排程備份與郵件一定會執行嗎？ |
| 11 | Can I run a full restore on a very large archive? | 超大封存能完整 execute 還原嗎？ |
| 12 | What is *not* included in Museder RestoreOne (vs Premium Extensions…)? | Museder RestoreOne「不包含」什麼？ |
| 13 | **What are Premium Extensions?** | **什麼是 Premium Extensions？** |
| 14–20 | Logs, safe mode, external services, wp-cron, public files, Multisite, filters | 日誌、安全模式、外部服務、wp-cron、公開存取、Multisite、篩選器 |

### FAQ 13 — What are Premium Extensions?（全文）

**EN**

**Premium Extensions** are optional, separate plugins (or extension packages) that add features beyond the core **Museder RestoreOne** plugin—such as cloud storage destinations, encryption, or other advanced workflows. They are **not** required for full-site backup, download, chunked upload, validate/dry-run, or local restore/migration using archives on servers you control. When an extension uses external services, its own readme will disclose data handling under WordPress.org rules.

**繁中**

**Premium Extensions** 為選用的 **獨立外掛**（或擴充套件），在核心 **Museder RestoreOne** 之外提供進階能力，例如雲端儲存、加密或其他進階流程。**並非** 全站備份、下載、分塊上傳、validate／dry-run 或本機封存還原／遷移的必要條件。若某擴充使用外部服務，其 readme 會依 WordPress.org 規定揭露資料處理方式。

---

## == Screenshots ==

| # | EN | 繁中 |
|---|----|------|
| 1 | Dashboard — environment checks, recent backups, schedule overview for large-site readiness | 儀表板 — 環境檢查、最近備份、排程概覽（大站就緒） |
| 2 | Backups — estimated site size, async backup job progress, full-site archive list | 備份 — 容量估算、非同步備份工作進度、封存列表 |
| 3 | Restore Center — chunked upload, validate/dry-run, 3-step wizard | 還原中心 — 分塊上傳、validate／dry-run、三步驟精靈 |
| 4 | Schedules — automatic backup jobs and quick schedule builder | 排程 — 自動備份與快速建立排程 |
| 5 | Logs — backup and restore log files with preview | 日誌 — 備份／還原日誌與預覽 |
| 6 | Settings — general options and system diagnostics for shared hosting | 設定 — 一般選項與共享主機診斷 |

---

## Changelog / Upgrade Notice

未納入本對照稿（維持英文，與倉庫 `readme.txt` 第 253 行起相同）。發佈時若需註明文案調整，可新增一則例如：

**EN:** Readme: align product naming—**Museder RestoreOne** as main plugin; optional **Premium Extensions**; expanded WordPress.org install steps.

**繁中:** Readme：統一產品命名—本體為 **Museder RestoreOne**；進階為選用 **Premium Extensions**；補充 WordPress.org 安裝步驟。
