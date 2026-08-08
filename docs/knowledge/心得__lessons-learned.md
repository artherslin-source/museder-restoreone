# 心得（Lessons Learned）— Museder RestoreOne Free

**範圍**：約 2.7.24x → **2.7.262**（WP.org 審核往返、大小站 FT、正式站大站備份卡關）。  
**狀態**：現行濃縮；細節證據見 `docs/` 各版修正指引與 `reports/`、`logs/`。

---

## 1. WordPress.org 審核是「誠實＋安全」比「功能多」重要

- **Trialware / 付費牆觀感**會直接踩紅線：Free 必須能走完 readme 承諾的備份／還原／排程。Pro／加購只宜「可選附加」，不可阻斷主流程。  
- **未揭露的對外連線**等同風險：預設應只剩本站 `wp-cron.php` loopback；任何新 `wp_remote_*` 都要同步改 readme External services / Privacy。  
- **路徑／下載／chunk**：`realpath` + **尾隨斜線目錄邊界**比單純 `strpos` 前綴更安全；審核與實測都反覆踩這點。  
- **Plugin Check 0 errors ≠ 人審通過**：自動化是門檻，不是終點。

## 2. 開發倉庫 vs 發行包必須嚴格分層

- `tools/`、`docs/`、`logs/`、`reports/`、`dist/`、巨型 demo ZIP **永不**進 WP.org ZIP。  
- `create-package.sh` 的預掃描是最後防線；本機 `dist/demo-download-*.zip`（數 GB）曾幾乎吃掉整個工作區磁碟——應忽略／外置。  
- `ft_sync`／打包排除清單要同步排除 `phpcs.xml.dist`、`tools/phpcs`，避免 Plugin Check 誤掃開發工具。

## 3. Docker 功能測試：埠與 compose 專案名是「隱形狀態」

- 小站（8090／8290…）與大站（**8080** 根目錄 `docker-compose.yml`）混跑時，繼承的 `COMPOSE_FILE`／`COMPOSE_PROJECT_NAME` 會指錯 volume，甚至重建空站。  
- **大站測試應固定**：`COMPOSE_FILE=$ROOT/docker-compose.yml` + `COMPOSE_PROJECT_NAME=museder-restoreone`。  
- 郵件煙測：Docker 常無 MTA；重點是走到 `phpmailer_init`。`wordpress@localhost` 的 From 會讓 `is_email` 失敗、在 `setFrom` 就返回，**永遠觸發不到** `phpmailer_init`——煙測內需暫時正規化 From。

## 4. 大站備份「卡在 95%」常是收尾／重包，不是 UI 假死

- UI 進度模型：packing **10–95%**，finalizing **95–99%**。卡在約 95% 多半在關 ZIP、驗證、寫 metadata。  
- **2.7.262 根因（正式站 log `140514-debug`）**：  
  1. 第一次 ZipArchive 打包指標已完成；  
  2. 關檔後驗證 `locateName` 誤判缺檔 → 觸發 **PclZip 整包重包**；  
  3. 重包時仍長時間開著 **同一個檔案的 ZipArchive handle** → 每 tick `close()` 極慢（30–50s），體感卡死。  
- **修正方向**：PclZip 模式禁止重用 ZipArchive 長連線；驗證用較寬鬆但仍安全的條目比對（`./`、斜線、`FL_NOCASE`）。

## 5. Free／Pro 拆分與文案

- Free 樹應避免載入 Pro 雲端／付費解鎖實作；UI 用「optional add-on」中性文案，`upgradeUrl` 空字串時不要做出假導購牆。  
- `VERSION_DEVELOPMENT_HIGHLIGHTS.md` 仍殘留舊「2.8 / S3 / Pro Dashboard」敘事——**勿當現行產品真相**（見演化索引）。

## 6. 證據文化

- 每個審核補強輪次：修正指引（給開發 AI）→ 程式 → `reports/*-validation.md` + FT log。  
- 正式站問題優先要 **外掛 log**（含 `Archive verify snapshot`、`repack`、`ZipArchive` close），再猜前端。
