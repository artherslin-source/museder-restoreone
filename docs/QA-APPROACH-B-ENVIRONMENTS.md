# 做法 B 通用還原驗收 — 多環境建置計畫

**對應任務書：** `docs/QA-APPROACH-B-AGENT-HANDOFF.md`  
**Baseline：** `2.7.268`  
**更新：** 2026-05-25（測試規劃 Agent）

---

## 1. 總覽

本計畫定義 **5 個獨立 docroot**（模擬線上隔離站點），對應 Lane A/B/C 與選跑情境。每個環境具 **獨立 MySQL 資料庫**，禁止共用 `public_html` 根目錄或 sunpower **生產** docroot。

| 環境 ID | Profile | 用途 | Lane | 預設 URL（本機 Docker） |
|---------|---------|------|------|-------------------------|
| **QA-A1** | `empty_shell` | S1 全量、S2 bootstrap E2E | A | `http://localhost:8081` |
| **QA-A2** | `fresh_wp` | S3 新裝 WP + DB 覆寫警告 | A | `http://localhost:8082` |
| **QA-B1** | `populated_wp` | S4/S4b/S5/S9/S10/S11 | B | `http://localhost:8083` |
| **QA-C1** | `populated_wp` | S6 merge / S7 keep / S12 順序 | C | `http://localhost:8084` |
| **QA-C2** | `empty_shell` + `fresh_wp` | S8a/S8b content_only | C | `http://localhost:8085` |

**禁止環境（P0）：**

- `public_html/` 帳號根（多 addon 共用）
- `sunpoweroflight.com` 生產 docroot 全量覆寫還原（除非使用者明確授權）
- centraltaipei、ciouyinghao 等正式 addon

---

## 2. 封存資產（每環境必備）

| 代號 | 內容 | 用途 | 建議來源 |
|------|------|------|----------|
| **FULL-S** | 小型全站 ZIP（含 core + wp-content + database.ndjson） | S1–S3、S9、多數 B/C | 由 QA-B1 備份產出，或 `tools/qa/fixtures/build-mini-full.sh` |
| **FULL-L** | 中大型全站（可選，>200MB） | S9 關頁 Cron 壓力 | 本機 `logs/` 內測試 zip（**勿 commit**）；權限允許時複製 sunpower 封存至 QA docroot only |
| **CONTENT_ONLY** | 僅 wp-content，無 wp-admin/wp-includes | S8a/S8b | 從 FULL-S 剝離核心條目建置 |
| **FULL-OLD-WP** | 備份 meta 內 WP 版本與目的地差 ≥1 minor | S11 | 手動改 `meta.json` 或舊版測試站備份 |

**WordPress.org 封裝規則：** 部署 zip 須符合 `docs/PACKAGING.md`（`museder-restoreone/museder-restoreone.php`，路徑 `/`）。

---

## 3. 建置方式 A — Docker 多 docroot（本機／CI 推薦）

使用 repo 內 `docker-compose.qa.yml`（與主 `docker-compose.yml` 分離 volume）。

```bash
# 1) 啟動五站 + 五 DB
docker compose -f docker-compose.qa.yml up -d

# 2) 各站安裝 WP + 同步外掛 2.7.268
bash tools/qa/approach-b-provision.sh all

# 3) 依 Lane 執行矩陣
bash tools/qa/approach-b-run-lane.sh A   # S1,S2,S3
bash tools/qa/approach-b-run-lane.sh B   # S4,S5,S9,...
```

**Windows：** `powershell -File tools/qa/approach-b-provision.ps1 -Profile all`（需 Docker Desktop **已啟動**）。

各服務對應：

| Compose service | Host port | Volume | DB |
|-----------------|-----------|--------|-----|
| `qa-a1` | 8081 | `qa_a1_wp` | `qa_a1_db` |
| `qa-a2` | 8082 | `qa_a2_wp` | `qa_a2_db` |
| `qa-b1` | 8083 | `qa_b1_wp` | `qa_b1_db` |
| `qa-c1` | 8084 | `qa_c1_wp` | `qa_c1_db` |
| `qa-c2` | 8085 | `qa_c2_wp` | `qa_c2_db` |

---

## 4. 建置方式 B — cPanel 獨立子目錄（最接近線上）

```text
DocumentRoot:  {account}/public_html/qa-restore-test/
URL:           https://qa-restore-test.{your-domain}/  （addon 或子網域）
MySQL:         qa_restore_test（獨立 DB，勿與 sunpower 共用）
外掛路徑:      .../qa-restore-test/wp-content/plugins/museder-restoreone/
備份目錄:      .../wp-content/uploads/museder-restoreone/backups/
```

**子情境切換（同一主機、不同 wipe）：**

| 階段 | 操作 | Profile |
|------|------|---------|
| Lane A 前 | `rm -rf qa-restore-test/*` + 新 DB | empty_shell → fresh_wp |
| Lane B 前 | 標準 WP + 安裝 3+ 外掛 + 1 篇文章 | populated_wp |
| Lane C | 保留 B1 站做 S6/S7；另 wipe 子目錄做 S8b | 混合 |

**SSH 憑證：** 由維運提供，**勿寫入 Git**。

---

## 5. 各環境前置狀態腳本

### QA-A1（empty_shell）

1. docroot **無** `wp-admin`、`wp-includes`（可保留 `wp-content/uploads/museder-restoreone/backups/`）。
2. 複製 `museder-restoreone-restore-bootstrap.php` 至 docroot。
3. 放入 `FULL-S.zip` 至 backups。
4. T0：`Version` / `BUILD_ID` ≥ `2.7.268`。

### QA-A2（fresh_wp）

1. 標準 `wp core install`（admin/admin 或測試帳密）。
2. 預設文章 hello world 存在。
3. 外掛僅 RestoreOne + 必要測試外掛（可選 2–3 個 wp.org 外掛）。

### QA-B1（populated_wp）

1. WP 已安裝；≥1 篇已發布文章；uploads 有檔案。
2. `active_plugins` ≥ 3（建議 elementor + seo + 一個小型外掛）。
3. 執行一次 RestoreOne **備份** 產生 `FULL-S` 供後續還原。

### QA-C1 / QA-C2

- C1：同 B1，保留 `wp-config.php` 含正確 `DB_*`。
- C2：先跑 S8a（B1 上 content_only），wipe 後跑 S8b（empty_shell）。

---

## 6. 執行順序（與 HANDOFF 一致）

```text
Lane A (QA-A1, QA-A2):  S1 → S2 → S3
Lane B (QA-B1):         S4 → S4b → S5 → S9 → S10 → S11
Lane C (QA-C1, QA-C2): S6 → S7 → S8a/8b → S12 → S13 → S14
```

**規則：** Lane 內 **P0/P1 Fail** → 記 `docs/BUG-LOG-APPROACH-B-YYYY-MM.md` → 修復 → **重跑該項** 後才繼續。

---

## 7. 執行前檢查（T0–T5）

見 `QA-APPROACH-B-AGENT-HANDOFF.md` §3。每環境開始前執行：

```bash
wp --path=/var/www/html option get museder_restoreone_plugin_build_id
wp --path=/var/www/html eval-file tools/qa/approach-b-preflight-check.php
```

---

## 8. 證據收集

每項測試產出目錄（建議）：

```text
docs/qa-evidence/approach-b/{scenario-id}/{YYYYMMDD}/
  job-meta.json
  restore-history-snippet.json
  curl-headers.txt
  log-tail.txt
  notes.md
```

**勿 commit：** 含客戶機密、完整 error_log、大型 zip。

---

## 9. 與 sunpower 生產站的關係

| sunpower 已完成 | 本矩陣仍須在 QA docroot 執行 |
|-----------------|------------------------------|
| populated 還原成功（5/24 job） | S4/S5/S9 **實跑或 Blocked+引用** |
| bootstrap HTTP 200 | **S2 全量 POST**（非僅 UI） |
| 2.7.267 欄位 QA | 以 **2.7.268** 回歸 |

生產 sunpower：**禁止**再跑全量覆寫還原。

---

## 10. 目前執行狀態（規劃 Agent）

| 項目 | 狀態 |
|------|------|
| 環境建置計畫 | ✅ 本文件 |
| Docker 實機 E2E | ⏸ **Blocked** — 本機 Docker Desktop daemon 未運行 |
| cPanel QA docroot | ⏸ **Blocked** — 需維運 SSH／addon 建立 |
| 程式碼靜態審查 | ✅ 見 `BUG-LOG-APPROACH-B-2026-05.md` |
| sunpower 證據型 Pass | 📎 見 `QA-APPROACH-B-RESULTS.md` |

**解除 Blocked：** 啟動 Docker Desktop 後執行 `docker compose -f docker-compose.qa.yml up -d` 與 `tools/qa/approach-b-provision.ps1`。
