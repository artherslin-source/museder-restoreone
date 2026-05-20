# Museder RestoreOne — readme 完整中英對照（檢驗稿）

> **正式提交 WordPress.org 請用英文 `readme.txt`。**  
> 對應 **Stable tag: 2.7.263**（2026-05 修訂：Contributors、適合對象用語、Multisite 解除安裝說明、安全模式與 2GB 限制已對照程式碼）。

---

## 技術確認（給檢驗用）

| 項目 | 結論 | 程式依據 |
|------|------|----------|
| **單檔 2 GB 上限** | **有。** 備份打包時單一檔案 **> 2,147,483,648 位元組（2 GB）會略過**，不寫入 ZIP，還原也不會包含；**整站總量可超過 2 GB**（只要每個檔案未超限）。 | `includes/class-backup.php`（`$max_file_size = 2147483648`） |
| **安全模式** | 還原匯入後可啟用（精靈預設開啟）：**僅** 快照 `active_plugins`、設定 `museder_restoreone_safe_mode`、顯示後台通知；**不會** 自動停用／啟用其他外掛。**離開安全模式** 只刪除標記與快照。 | `includes/class-restore.php` `enter_safe_mode_after_import()` / `exit_safe_mode()` |
| **Multisite「每批 100 站」** | **不是** 備份／還原功能。僅在 **Multisite 網路「刪除」此外掛** 時，`uninstall.php` 以每次最多 **100 個 site ID** 分批對各子站清選項與 cron，避免一次載入整個網路 ID。 | `uninstall.php` `$mro_batch = 100` |

**Contributors 欄位：** 須填 WordPress.org 帳號 slug（目前為 `artherslin`）。目錄顯示名稱請在 [個人資料](https://wordpress.org/support/users/artherslin/edit/) 設定 Display name（例如 Adrian Lin）。

---

## 標頭與短描述

### Plugin header

| English | 繁體中文 |
|---------|----------|
| === Museder RestoreOne – WP Backup & Restore === | === Museder RestoreOne – WP 備份與還原 === |
| Contributors: artherslin | 貢獻者：artherslin（WordPress.org 帳號 slug） |
| Tags: backup, migration, restore, clone, site-backup | 標籤：backup, migration, restore, clone, site-backup |
| Requires at least: 5.8 | 需要 WordPress 5.8 以上 |
| Tested up to: 6.9 | 測試至 6.9 |
| Requires PHP: 7.4 | 需要 PHP 7.4 |
| Stable tag: 2.7.263 | 穩定版 2.7.263 |
| License: GPLv2 or later | GPLv2 或更新版本 |

### Short description

**EN**

```
Large-site WordPress backup & restore with Museder RestoreOne—asynchronous jobs, chunked uploads, and local migration.
```

**繁中**

```
大型 WordPress 站備份還原：Museder RestoreOne 提供非同步工作、分塊上傳與本機遷移。
```

---

## == Description ==

**EN**

**Museder RestoreOne** is built for **larger WordPress sites** on everyday hosting—and includes **large-site backup and restore in this plugin** (the version you install from WordPress.org), not behind a separate paid core product. Package your database and `wp-content` into **one downloadable archive**, run **background backup jobs** (not a single fragile browser request), and restore through a **guided wizard** with progress, logs, and safety checks.

**Looking for large-site backup without a paid upgrade?**

Many WordPress backup plugins reserve **chunked or large archive imports**, **direct site-to-site migration**, **multisite**, or **“unlimited” migration size** for **paid or premium tiers**. **Museder RestoreOne** includes the following **in this plugin** for **local, on-server** workflows (download your archive, copy it to another host, restore with the wizard):

* **Background backup jobs** (async via WordPress cron)
* **Backup size estimation** before you commit to a long job
* **Chunked REST uploads** for multi‑gigabyte ZIPs on low `upload_max_filesize` hosts
* **Validate & dry-run** before a full restore
* **Multiple backup schedules** on the same site (local schedules included in this plugin)
* **Full-site restore / migration** using archives **you** store—no third-party cloud required

**Large-site backup & restore (included in Museder RestoreOne)**

* **Background backup jobs** — Full-site backups can run as **async jobs** processed via WordPress cron, with progress in the admin instead of relying on one long page load.
* **Backup size estimation** — Scan database and `wp-content` in **batches** (thousands of files per pass) so you can see estimated size before starting a large backup; sites over **1 GB** get guidance in the UI.
* **Chunked archive uploads** — Upload multi‑gigabyte backup ZIPs over the **authenticated REST API** in small chunks with retries and integrity checks—useful when `upload_max_filesize` / `post_max_size` are low.
* **Validate & dry-run before execute** — Review an archive and run checks before a full restore on production.
* **Long-restore resilience** — Restore progress can continue when admin sessions or nonces expire mid-job (restore token fallback and tolerant polling during database import).
* **Honest limits** — **Single files over 2 GB are skipped** for stability; **total site size can exceed 2 GB** when each file is under the cap. Very large restores may still need staging, higher PHP limits, or host cron—see FAQ.

Database work uses WordPress APIs in PHP—**no `mysqldump` required**. Archives use `ZipArchive` or WordPress’ bundled **PclZip** when the ZIP extension is unavailable.

**Who is it for?**

* **Shared-hosting sites** that need **full-site backup and restore for larger installs** when their **current backup setup’s free plan** no longer covers big uploads, long-running jobs, or migration helpers that many tools reserve for **paid plans**—but you still want those capabilities on everyday hosting.
* **Growing or large WordPress sites** (media-heavy, many plugins, multi‑GB `wp-content`) without shell access or `mysqldump`.
* **Agencies and freelancers** who migrate clients with **chunked uploads**, size estimates, and validate/dry-run before go-live.
* **Anyone who wants a focused backup → restore workflow** on shared hosting without extra complexity.

**More features**

* **One-click full-site backup** — Export the database, `meta.json`, and `wp-content/` into a single archive you can download, store locally, or restore later.
* **Restore Center wizard (migration & recovery)** — Upload & analyze → review summary & options → execute restore with real-time progress and logs.
* **Schedules and logs** — Automatic backup schedules (or manual runs), structured logs, download, and cleanup from the admin.
* **Modern admin UI** — Dashboard, Backups, Restore, Schedules, Logs, and Settings with clear status and responsive layout.

**Multisite**

This release is **not formally tested on WordPress Multisite**. For predictable results, use Museder RestoreOne on **standard single-site** installs (one site per admin context). If you run a network, treat use as **experimental** until you have verified backups and restores on a staging clone.

**繁中**

**Museder RestoreOne** 專為一般主機上的 **大型 WordPress 網站** 設計，並在 **本外掛**（自 WordPress.org 安裝）內提供 **大站備份與還原**，而非另需付費的「核心產品」。可將資料庫與 `wp-content` 打包成 **單一可下載封存**，以 **背景備份工作** 執行，並透過附進度、日誌與安全檢查的 **引導式精靈** 還原。

**想要大站備份、又不想付費升級？**

許多備份外掛把 **分塊／大型封存匯入**、**站對站直接遷移**、**Multisite** 或 **「無上限」遷移容量** 放在 **付費或 Premium 方案**。**Museder RestoreOne** 在 **本外掛** 中即包含下列 **本機、伺服器端** 能力（下載封存 → 複製到新主機 → 精靈還原）：

* **背景備份工作**（WordPress cron 非同步）
* **備份前容量估算**
* **REST 分塊上傳**（適用 `upload_max_filesize` 很小的主機）
* **還原前 validate 與 dry-run**
* **多組本機備份排程**（含於本外掛）
* **全站還原／遷移**（封存由 **您** 自行保存，無需第三方雲端）

**大站備份還原（含於 Museder RestoreOne）**

* **背景備份工作** — cron 非同步，後台顯示進度。
* **備份容量估算** — 分批掃描；超過 **1 GB** 時介面提示。
* **分塊封存上傳** — 驗證過的 REST API、重試與完整性檢查。
* **執行前 validate／dry-run**
* **長時間還原韌性** — session／nonce 過期時 restore token 與容錯輪詢。
* **誠實限制** — **單一檔案超過 2 GB 會略過**（程式上限 2,147,483,648 位元組）；**整站總量可超過 2 GB**（各檔未超限即可）。極端情況仍需 staging／主機限制—見 FAQ。

資料庫以 PHP 內 WordPress API 處理，**不需 mysqldump**。封存使用 `ZipArchive` 或 **PclZip**。

**適合誰？**

* **共享主機上的網站**，需要 **大型站全站備份還原**，而 **目前使用的備份方案免費額度** 已無法涵蓋大型上傳、長時間工作或僅在付費方案才提供的遷移輔助，但仍希望在一般主機上完成這些工作。
* **成長中／大型 WordPress 站**（媒體多、外掛多、多 GB 的 `wp-content`），無 shell 或 `mysqldump`。
* **代管／接案** 需分塊上傳、容量估算、上線前 validate／dry-run。
* 只要 **備份→還原** 流程、不想增加複雜度的使用者。

**更多功能** — 一鍵全站備份、還原中心精靈、排程日誌、現代化後台六頁。

**Multisite** — 本版 **未正式測試**；建議 **單站**；多站請在 **staging** 驗證後再視為 **實驗性** 使用。

---

## == External services ==

**EN**

This plugin does not use external services.

The **only programmatic outbound HTTP** the base plugin performs by default is an optional, short **non-blocking** request to **your own site’s** `wp-cron.php` (same host / local loopback) to encourage scheduled tasks to run. No third-party API is called for backups or restores.

All **admin JavaScript and CSS** for Museder RestoreOne are loaded from files shipped under this plugin’s `assets/` directory (including vendored libraries under `assets/vendor/`). See the FAQ for more on the local `wp-cron.php` nudge.

**繁中**

本外掛 **不使用外部服務**。預設僅可能對 **本站** `wp-cron.php` 發送短暫 **非阻塞** HTTP（本機 loopback）。備份與還原 **不呼叫** 第三方 API。管理介面 **JS／CSS** 來自 `assets/`（含 `assets/vendor/`）。詳見 FAQ。

---

## == Privacy ==

**EN — What this plugin stores on your server**

* **Backups** — `wp-content/uploads/museder-restoreone/backups/`（或 Backups 畫面路徑）；含 `database.ndjson`、`meta.json`、`wp-content/` 副本。
* **Logs** — `wp-content/uploads/museder-restoreone/logs/`
* **Restore reports** — `wp-content/uploads/museder-restoreone/reports/`
* **Schedules and settings** — WordPress 資料庫

**Diagnostics** — 升級後可能在本機 Logs 寫入一行版本／build（不對外）。

**Third parties** — 不上傳備份、資料庫或日誌至第三方。

**Retention and deletion** — 可於 Backups／Logs 刪除。**解除安裝** 移除選項、transients、job-lock 列、`museder_restoreone_*` cron，**不刪除** 封存 ZIP、日誌、報告（請手動刪除）。

**Multisite uninstall** — On **WordPress Multisite**, if you **delete** this plugin from the network, `uninstall.php` runs the same options/cron cleanup **on each subsite**, loading site IDs in **batches of 100** so a very large network does not pull every site into memory at once. This applies only at **plugin uninstall**—not to backup or restore. Very large networks should still use a **maintenance window**.

**繁中**

**伺服器上儲存的資料** — 同上（備份／日誌／報告／排程設定）。

**第三方** — 不上傳至第三方 API 或雲端。

**保留與刪除** — 可於介面刪除；解除安裝清資料庫內外掛資料，**不刪** 封存與日誌檔。

**Multisite 解除安裝** — 僅在 **Multisite 網路「刪除」此外掛** 時，`uninstall.php` 會對 **每個子站** 清選項與 cron，並以 **每次最多 100 個 site ID** 分批查詢，避免一次載入整個網路。**與備份／還原無關。** 超大網路建議安排 **維護時段**。

---

## == Installation ==

**EN — From the WordPress.org Plugin Directory (recommended)**

1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for **Museder RestoreOne** (or open [Museder RestoreOne on WordPress.org](https://wordpress.org/plugins/museder-restoreone/) and click **Download**, then upload if your host blocks the in-dashboard installer).
3. Click **Install Now**, then **Activate**.
4. Open the **Museder RestoreOne** menu in your admin sidebar.
5. Go to **Backups** or **Restore** and create your first backup.

**EN — Manual install (alternative)**

1. Download the plugin ZIP from WordPress.org or copy the `museder-restoreone` folder into `/wp-content/plugins/` (FTP/SFTP or **Plugins → Add New → Upload Plugin**).
2. Activate **Museder RestoreOne** under **Plugins**.
3. Open **Backups** or **Restore** and create your first backup.

**繁中 — 從 WordPress.org 外掛目錄安裝（建議）**

1. 後台 **外掛 → 安裝外掛**。
2. 搜尋 **Museder RestoreOne**（或開啟 [WordPress.org 外掛頁](https://wordpress.org/plugins/museder-restoreone/) 下載；無法在目錄內安裝再上傳 ZIP）。
3. **立即安裝** → **啟用**。
4. 開啟側欄 **Museder RestoreOne**。
5. 進入 **Backups** 或 **Restore** 建立第一個備份。

**繁中 — 手動安裝（替代）**

1. 從 WordPress.org 下載 ZIP，或將 `museder-restoreone` 放到 `/wp-content/plugins/`（FTP/SFTP 或 **上傳外掛**）。
2. 啟用 **Museder RestoreOne**。
3. 開啟 **Backups** 或 **Restore** 建立第一個備份。

---

## == Frequently Asked Questions ==

### 1. Do I need a paid plan for large-site backup and restore? / 大站備份還原需要付費方案嗎？

**EN:** **No.** Includes background jobs, size estimation, chunked uploads, validate/dry-run, local schedules, and full-site restore on **your** servers. Many backup plugins move chunked imports, push migration, multisite, or unlimited size into **paid tiers**—compare the Description checklist to your needs.

**繁中：** **不需要。** 已含背景工作、估算、分塊上傳、validate／dry-run、本機排程與全站還原。許多備份工具將上述能力放在付費層—請對照說明區清單。

### 2. Is Museder RestoreOne suitable for large WordPress sites? / 適合大型站嗎？

**EN:** **Yes—with realistic expectations.** Async backups, estimation, ZipArchive/PclZip, chunked REST, validate/dry-run, restore token, Logs. **Limits:** each **individual file over 2 GB is skipped** at backup time; total site can be larger if every file stays under 2 GB.

**繁中：** **適合，但需合理預期。** **備份時單檔超過 2 GB 會略過**（程式實作上限）；各檔未超限時 **整站總量可大於 2 GB**。

### 3. Can I migrate to another host? / 能遷到新主機嗎？

**EN:** Yes. Source: full backup + download/copy. Destination: Restore Center. No third-party cloud. Use chunked upload, validate/dry-run, staging before DNS.

**繁中：** 可以。來源站備份並下載／複製封存；目的站用還原中心。不經第三方雲端。大站請分塊上傳、validate／dry-run、DNS 前在 staging 測試。

### 4. What does the backup archive contain? / 封存包含什麼？

**EN:** `database.ndjson`, `meta.json`, `wp-content/`.

**繁中：** `database.ndjson`、`meta.json`、`wp-content/`。

### 5. Third-party format compatibility = partnership? / 第三方格式相容等於合作嗎？

**EN:** **No.** Technical compatibility on **your server** only—not endorsement.

**繁中：** **否。** 僅技術相容，不代表合作或背書。

### 6. Are there any file size limits? / 有檔案大小限制嗎？

**EN:** **Single files larger than 2 GB are skipped** during backup (not in ZIP, not restored). **Total site size can exceed 2 GB** if each file is under 2 GB. Backups screen estimates size; skip reasons shown on completion.

**繁中：** **備份時單檔大於 2 GB 會略過**（不進 ZIP、不會還原）。**整站可超過 2 GB**（各檔 ≤2 GB）。備份畫面可估算；完成時顯示略過原因。

### 7. Do I need mysqldump, SSH, or WP-CLI? / 需要 mysqldump、SSH 或 WP-CLI 嗎？

**EN:** **No.** PHP WordPress DB APIs.

**繁中：** **不需要。**

### 8. What if ZipArchive is not enabled? / 沒有 ZipArchive？

**EN:** Uses PclZip automatically. Slower on large sites; `open_basedir` may block—see Logs; `museder_restoreone_core_admin_include_path` filter.

**繁中：** 自動改用 PclZip。大站較慢；可能被 `open_basedir` 阻擋—見日誌與篩選器。

### 9. How does chunked restore upload work over REST? / REST 分塊上傳？

**EN:** `museder-restoreone/v2`; `php://input` per request; temp under uploads; never to third parties; `manage_options` + REST nonce.

**繁中：** 同上（驗證 REST、本機暫存、不轉發第三方）。

### 10. Will scheduled backups and email always run? / 排程與郵件？

**EN:** Depends on WP cron / system cron and `wp_mail`. **Settings → Send Test Email**, Logs.

**繁中：** 依 cron 與 `wp_mail`；可用測試信與日誌排查。

### 11. Can I run a full restore on a very large archive? / 超大封存完整還原？

**EN:** May hit PHP/time/disk limits. Use validate/dry-run; staging or WP-CLI where allowed.

**繁中：** 可能逾時或磁碟不足；請 validate／dry-run、staging 或 WP-CLI。

### 12. What is *not* included (vs typical paid upsells)? / 「不包含」什麼？

**EN:** Local archives focus. Not: primary offsite cloud; dashboard push without moving files; incremental-only/real-time; formally supported Multisite (experimental here).

**繁中：** 聚焦本機封存。不包含：以託管雲端為主、不搬檔的儀表板推送、僅增量／即時、正式 Multisite（本版實驗）。

### 13. Where are the logs stored? / 日誌在哪？

**EN:** `wp-content/uploads/museder-restoreone/logs/` — **Logs** menu.

**繁中：** 同上。

### 14. What happens to plugins during restore? / 還原時外掛與安全模式？

**EN:** Optional **safe mode** (on by default in Restore wizard unless off) **after** import: snapshot `museder_restoreone_prev_active_plugins`, marker `museder_restoreone_safe_mode`, admin notices. **Does not** deactivate/reactivate other plugins. **Exit Safe Mode** deletes marker + snapshot only.

**繁中：** 選用 **安全模式**（還原精靈預設開啟，可關閉），**還原匯入後**：快照使用中外掛清單、設定安全模式標記、顯示儀表板／還原頁通知。**不會** 自動停啟其他外掛。**離開安全模式** 僅刪除標記與快照。

### 15. Does this plugin send data to external services? / 會傳到外部嗎？

**EN:** **No.** Dashboard scan uses **local heuristics** only.

**繁中：** **不會。** 儀表板掃描僅本機規則。

### 16. HTTP requests to my own site? / 對自己網站發 HTTP？

**EN:** Sometimes non-blocking loopback to `wp-cron.php`.

**繁中：** 有時對 `wp-cron.php` 短暫 loopback。

### 17. Are backup files exposed publicly? / 備份會公開嗎？

**EN:** **No.** Time-limited tokens; admin only.

**繁中：** **不會。** 時效 token；僅管理員。

### 18. WordPress Multisite support? / Multisite？

**EN:** Not formally supported; **single-site** primary; Multisite **experimental**—test on staging.

**繁中：** 未正式支援；以單站為主；多站實驗性。

### 19–21. Custom path filters / 自訂路徑篩選器

| Filter | EN | 繁中 |
|--------|----|------|
| `museder_restoreone_languages_dir` | Override languages directory | 覆寫語言目錄 |
| `museder_restoreone_mu_plugins_dir` | Override mu-plugins directory | 覆寫 must-use 外掛目錄 |
| `museder_restoreone_core_admin_include_path` | Non-standard core admin include path | 非標準核心 admin include 路徑 |

---

## == Screenshots ==

| # | EN | 繁中 |
|---|----|------|
| 1 | Dashboard — environment checks, recent backups, and schedule overview for large-site readiness. | 儀表板 — 環境檢查、最近備份、排程概覽 |
| 2 | Backups — estimated site size, async backup job progress, and full-site archive list. | 備份 — 容量估算、非同步備份、封存列表 |
| 3 | Restore Center — chunked upload, validate/dry-run, and 3-step restore wizard with progress. | 還原中心 — 分塊上傳、validate／dry-run、三步驟精靈 |
| 4 | Schedules — automatic backup jobs and quick schedule builder. | 排程 — 自動備份與快速建立 |
| 5 | Logs — backup and restore log files with preview. | 日誌 — 備份／還原日誌與預覽 |
| 6 | Settings — general options and system diagnostics for shared hosting. | 設定 — 一般選項與共享主機診斷 |

---

## == Changelog == / == Upgrade Notice ==

英文原文與 **Upgrade Notice** 請直接對照倉庫 `readme.txt` 第 243–596 行（歷史條目維持英文，未逐條翻譯）。

**最新版 Upgrade Notice（2.7.263）**

| EN | 繁中 |
|----|------|
| Fixes restores that **stopped mid-job** after database import when the admin session was invalidated (session preserved, restore token fallback, resilient progress polling). Recommended for **large-site restores**. | 修正資料庫匯入後 session 失效導致還原**中途停止**（保留 session、restore token、韌性輪詢）。建議**大站還原**使用者更新。 |

---

*文件結束*
