# 歷程演化索引（Evolution Index）

**用途**：標示「哪份文件仍有效／被誰取代／與誰衝突」。  
**更新**：2026-08-08（對齊 Free **2.7.262**）。

圖例：**現行**＝應遵循｜**歷史**＝證據保留｜**衝突／過時**＝勿當現況｜**supersedes**＝取代前者。

---

## 1) 知識庫本體（本目錄）

| 產物 | 狀態 | 說明 |
|---|---|---|
| `docs/knowledge/指引__operating-guidelines.md` | **現行** | 日常操作準則 |
| `docs/knowledge/心得__lessons-learned.md` | **現行** | 濃縮教訓 |
| `docs/knowledge/EVOLUTION-INDEX.md` | **現行** | 本索引 |
| `.cursor/skills/museder-restoreone-release-ops/SKILL.md` | **現行** | Agent 可執行流程 |
| `docs/knowledge/session-digests/2026-08-08__v2.7.262__session-digest.md` | **歷史＋現行摘要** | 本輪正式站 95%／升版封裝（不用目錄名 `archive/`，以免被根 `.gitignore` 忽略） |

與 [`docs/PROJECT-ORGANIZATION-PLAN.md`](../PROJECT-ORGANIZATION-PLAN.md)：**相容**（知識庫落在建議的 `docs/` 語意分層，未搬動執行路徑）。

---

## 2) WP.org 審核／合規文件鏈

| 時期／檔案 | 狀態 | 繼承關係 |
|---|---|---|
| `docs/wp-compliance-checklist.md` | **現行（基線）** | 工程合規總表；細節補強見下列各版 |
| `docs/plugin-check-notes.md`、`plugin-check-summary-2.7.46.md` | **歷史** | 早期 Plugin Check 筆記 |
| `docs/WP-REVIEW-*`、`WP官方審核*`、`WP官方退審260423-01*`、`WP審核-路徑判定2.4*` | **歷史＋仍有效的規範引用** | 人審往返；路徑準則已被後續 helper 實作吸收 |
| `docs/2026-05-06__v2.7.257__修正指引__工程風險*` | **歷史** | 工程風險序 02 |
| `docs/2026-05-06__v2.7.258__修正指引__WP退審風險*` | **現行參考（P0 清單）** | 權限／路徑／trialware／連外；多數項已在 259–261 落地 |
| `docs/2026-05-06__v2.7.259__修正指引__殘餘退審*` | **現行參考** | 殘餘項；實作見 uninstall／路徑邊界 |
| `docs/2026-05-06__v2.7.260__端點矩陣*` | **現行參考** | REST／AJAX／admin_post 矩陣 |
| `docs/2026-05-06__v2.7.261__*` | **現行參考** | 自動化測試說明、readme↔UI map |
| `docs/260506-01/*` | **歷史** | 256 輪交付包 |
| `reports/museder-restoreone-2.7.259|260|261-validation.md` | **歷史證據** | 驗證紀錄；261 已含 8080 大站 |
| `reports/museder-restoreone-2.7.262-validation.md` | **現行證據** | 本輪修復摘要 |

**衝突註記**：各版「修正指引」若仍寫「尚無 uninstall.php」等——以 **259+ 實際程式與 readme Privacy** 為準（舊指引不刪，僅標歷史）。

---

## 3) 備份機制／效能文件

| 檔案 | 狀態 | 說明 |
|---|---|---|
| `docs/BACKUP-MECHANISM.md`、`PERFORMANCE-OPTIMIZATION.md`、`AI1WM-*` | **歷史設計** | 機制背景仍有用；**finalize／repack／Zip+PclZip 並存禁則**以 2.7.262 程式與本知識庫為準 |
| `tools/functional-test/README.md` | **現行** | FT 指令權威 |
| `run-functional-test.sh` pin `COMPOSE_FILE` | **現行** | **supersedes**「隨意 docker compose 而不固定專案／檔案」的舊習慣 |

---

## 4) 版本敘事衝突（重要）

| 檔案 | 狀態 | 衝突內容 |
|---|---|---|
| `VERSION_DEVELOPMENT_HIGHLIGHTS.md` | **衝突／過時** | 開頭仍寫「2.8.00 最新」、S3／Pro Dashboard 等；**與現行 Free 2.7.262 樹不一致**。勿當發行說明。後續應改寫或加「archived narrative」橫幅。 |
| `readme.txt` Changelog / Stable tag | **現行產品敘事** | 以插件目錄／發行 ZIP 為準 |
| `docs/plans/*pro*` | **歷史規劃** | Pro 兩階段計畫；Free 樹已移除多數 Pro 頁面／服務——規劃≠現況 |
| `docs/restoreone-plugin-page-content.md` | **行銷草稿** | 發佈前需與 readme 對表，避免過度承諾 |

---

## 5) 版本里程碑（濃縮時間線）

| 版本 | 主題 | 知識點 |
|---|---|---|
| ≤2.7.244 | 早期審核／符合性 | 報告在 `docs/`、`logs/` |
| 2.7.247–252 | 加嚴安全／驗收 | `logs/museder-restoreone-2.7.24x-*` |
| 2.7.255–256 | 路徑判定／core include | 路徑指引 2.4；分段 core admin include |
| 2.7.257–259 | 工程＋退審殘餘 | uninstall、路徑邊界、中性 add-on 文案 |
| 2.7.260 | 端點／chunk UUID | 矩陣文件 |
| 2.7.261 | FT 矩陣＋大小站證據 | 8080 大站跑通；mail From 陷阱 |
| **2.7.262** | **正式站大站 95% 卡關** | PclZip＋ZipArchive handle；verify 假陰性；封裝 `dist/museder-restoreone-2.7.262.zip` |
| **2.7.263** | **WP 7.0 相容標示** | Tested up to **7.0**；Docker → `wordpress:7.0-php8.2-apache`；FT pin **7.0.3**；`reports/museder-restoreone-2.7.263-validation.md` |

---

## 6) Skill 與全域請神規則

| 來源 | 關係 |
|---|---|
| 專案 Skill：`museder-restoreone-release-ops` | **現行**；處理本外掛發行／FT／審核作業 |
| 全域 `karpathy-guidelines`／gstack／Matt Pocock | 不衝突；寫碼時可併用。本 Skill **不**覆寫請神切換指令 |
| `docs/PROJECT-ORGANIZATION-PLAN.md` 建議忽略 `.cursor/` | **部分衝突**：本倉庫選擇**提交**專案 Skill 以便 GitHub 共享。`.gitignore` 目前未忽略 `.cursor/`——以「提交專案 Skill」為現行決策（2026-08-08） |

---

## 7) 磁碟／產物衛生（歷程教訓）

| 路徑 | 處置 |
|---|---|
| `dist/demo-download-*.zip`（數 GB） | 勿進 git；可刪或外置 |
| `logs/test-one/*.zip`、plugin-check zip | 本機測試資產；已在 ignore／排除打包 |
| `reports/*.log` | 可進庫作證據，但避免巨型 log |
