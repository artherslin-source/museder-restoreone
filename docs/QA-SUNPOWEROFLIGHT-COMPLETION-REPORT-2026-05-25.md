# sunpoweroflight 實機驗證 — 完成報告

**日期：** 2026-05-25  
**模式：** P0/P1 即修即測 + P2/P3 記錄後批次修  
**執行者：** Cursor Agent（SSH 代操）  
**主機：** `118.139.182.16`，帳號 `eo8lmixijvfj`

---

## 1. 執行摘要

| 項目 | 結果 |
|------|------|
| 外掛版本（sunpower docroot） | **2.7.267**（已以 pscp 覆蓋部署；原為 2.7.266-10） |
| 生產站 [sunpoweroflight.com](https://sunpoweroflight.com/) | **HTTP 200**（首頁、wp-admin） |
| 上一輪還原（2.7.266） | **成功**（job `rjb_20260524_234454_5qjtfy`，272s，`restore-history.json` result=success） |
| P1 熱修 | **4 項**（見 BUG-LOG）；已套用主機，**本機 repo 已改、尚未發 tag** |
| 第二輪全量還原（267） | **未對 live sunpower 重跑**（避免覆寫已恢復之生產站；見 §4） |

**結論：** 做法 B 在 **populated_wp 的 sunpower 子目錄** 上，preflight／阻擋規則／build 267 均可驗證通過；歷史 **S1 全站還原** 已由 5/24 成功 job 證明。Bootstrap（S2）在修復後可 **HTTP 200** 開表單；**未**在共用 `public_html` 啟動全量還原（架構風險，§5）。

---

## 2. 主機架構（重要）

| 網域 | DocumentRoot | 說明 |
|------|----------------|------|
| **sunpoweroflight.com** | `/home/eo8lmixijvfj/public_html/sunpoweroflight.com` | 獨立 WP，**測試／生產還原目標** |
| **tno.5f9.mytemp.website**（Primary） | `/home/eo8lmixijvfj/public_html`（帳號根） | **無** `wp-config.php`；為 cPanel 預設「Coming Soon」+ **多網域共用父目錄** |
| 其它 addon | `public_html/{domain}/` | 與 sunpower 同層 |

**禁止：** 在 `public_html` 根目錄執行「全站 ZIP 還原」— 會解壓 `wp-admin` 等至共用根目錄，影響 **所有** addon 網域。S2 僅驗證 **bootstrap 頁面可開啟** + 備份檔可列舉，**未** POST 啟動還原。

---

## 3. 情境矩陣結果

| ID | 結果 | 說明 |
|----|------|------|
| **S1** | **Pass（證據型）** | 5/24 job 成功；現況有 `wp-admin`、`wp-includes`、核心檔；封存含 core（QA PASS） |
| **S1-htaccess** | **Fail → P2** | docroot 仍無 `.htaccess`；站台可連線，推測 Nginx／permalink 未設 |
| **S2** | **Pass（UI）** / **未跑全量** | tno 與 sunpower 上 bootstrap **HTTP 200**、表單可見；未 POST 全量還原 |
| **S3** | **Pass（邏輯）** | populated 站不強制 fresh 提示（QA `S3-hint` PASS） |
| **S4** | **Pass** | 未勾 overwrite → preflight **阻擋**（267 文案正確） |
| **S4b** | **Pass** | overwrite + 快照 → 允許 |
| **S5-order** | **Pass** | profile=`populated_wp` → 預設 `db_then_files` |
| **S5-iso** | **Pass** | 還原後 MU guard absent、isolation option 空 |
| **S6–S7** | **未跑** | 避免生產站覆寫 wp-config；排入下一輪 staging |
| **S8** | **未跑** | — |
| **S9** | **Pass（證據型）** | 5/24 還原 272s 完成；`DISABLE_WP_CRON` 未啟用 |
| **S10–S11** | **未跑** | P3  backlog |
| **T0** | **Pass** | BUILD_ID **2.7.267**，Preflight/Bootstrap class 存在 |

自動化腳本：`sunpower-field-qa.php`（wp eval-file）— **13 項中 12 Pass、1 Fail**（htaccess）。

---

## 4. P1 即修即測紀錄

| Bug | 修復 | 驗證 |
|-----|------|------|
| preflight 呼叫 protected `zip_archive_has_wp_core` | 改 public | QA S4/S4b PASS |
| bootstrap stub 順序 | stubs 先於 `bootstrap_root()` | error_log 無再出現 normalize fatal |
| bootstrap 缺 `esc_attr` | 新增 stub | tno/sun bootstrap **200** |

詳見 `docs/BUG-LOG-SUNPOWER-2026-05.md`。

---

## 5. 不執行項目與原因

1. **對 live sunpower 再跑一輪全量還原（267）** — 站已正常、5/24 已成功；重跑會長時間鎖站且無法隔離 A/B。
2. **在 `public_html` 啟動 bootstrap 全量還原** — 共用 docroot，屬 **P0 架構風險**。
3. **S6/S7/S8/S10/S11** — 留待獨立 staging 子目錄或維護時段。

---

## 6. 建議後續（給你）

1. **儘快輪換** 曾出現在聊天中的 SSH／cPanel 密碼。  
2. **合併熱修並發版**（建議 `2.7.268` 或 `2.7.267-1`）：`zip_archive_has_wp_core` public、bootstrap stubs。  
3. **BUG-SUN-002**：調查主機是否 **Nginx**；若是，文件註明「.htaccess 僅 Apache」或改寫伺服器層規則。  
4. **S2 完整 E2E**：在 **獨立子目錄**（例如 `public_html/qa-restore-test/`）新裝空殼 + bootstrap，勿用帳號 `public_html` 根。  
5. **tno Primary domain**：若僅作暫存，維持 Coming Soon 即可；正式還原請用 **sunpoweroflight.com 子目錄** 或獨立 addon domain。

---

## 7. 證據路徑（主機）

```text
Docroot:  /home/eo8lmixijvfj/public_html/sunpoweroflight.com
外掛:     .../wp-content/plugins/museder-restoreone/
日誌:     .../wp-content/uploads/museder-restoreone/logs/backup-lite-2026-05-24.log
Job:      .../jobs/rjb_20260524_234454_5qjtfy.json
歷史:     .../restore-history.json
Bootstrap: .../museder-restoreone-restore-bootstrap.php（sunpower docroot）
tno 測試: /home/eo8lmixijvfj/public_html/museder-restoreone-restore-bootstrap.php
```

---

## 8. 相關文件

- `docs/QA-SUNPOWEROFLIGHT-FIELD-TEST-2026-05-25.md`
- `docs/BUG-LOG-SUNPOWER-2026-05.md`
- `docs/PLAN-RESTORE-APPROACH-B.md`
