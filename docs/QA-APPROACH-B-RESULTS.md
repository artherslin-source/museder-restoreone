# 做法 B 通用還原驗收 — 結果矩陣

**執行日期：** 2026-05-25  
**Baseline：** `2.7.268` (`MUSEDER_RESTOREONE_BUILD_ID`)  
**規劃／初輪執行：** Cursor 測試 Agent（本機；Docker daemon 未運行）  
**環境計畫：** `docs/QA-APPROACH-B-ENVIRONMENTS.md`

**圖例：** `Pass` | `Fail` | `Blocked` | `Skip`

---

## T0–T5 執行前檢查

| # | 檢查 | 結果 | 證據 |
|---|------|------|------|
| T0 | 外掛版本 ≥ 2.7.268 | **Pass** | `museder-restoreone.php` Version `2.7.268`, BUILD_ID `2.7.268` |
| T1 | docroot 隔離 | **Blocked** | 需 QA docroot（Docker 8081–8085 或 cPanel `qa-restore-test/`）；本輪未啟動容器 |
| T2 | uploads 可寫 | **Blocked** | 依賴 T1 環境 |
| T3 | Cron loopback | **Blocked** | 依賴 T1 環境 |
| T4 | 測試封存 | **Blocked** | repo 內無 commit 之 FULL-S zip；`logs/test-one` 空 |
| T5 | 本結果表 | **Pass** | 本文件 |

---

## 情境矩陣（S1–S14）

| ID | 結果 | Build | Job ID | 證據 | Bug ID | 備註 |
|----|------|-------|--------|------|--------|------|
| S1 | **Blocked** | 2.7.268 | — | — | — | 需 QA-A1 + FULL-S E2E；sunpower 5/24 success 僅作**參考**，不代替本項 |
| S1-htaccess | **Blocked** | — | — | sunpower: Fail (Nginx) | BUG-SUN-002 | 見 `QA-SUNPOWEROFLIGHT-COMPLETION-REPORT` |
| S2 | **Fail** | 2.7.268 | — | 靜態審查 `class-restore-bootstrap.php` | **BUG-AB-001** | bootstrap `pause_other_plugins=false`；**未跑** POST 全量 E2E |
| S3 | **Blocked** | — | — | — | — | 需 QA-A2 fresh_wp UI + 還原 |
| S4 | **Pass（邏輯）** | 2.7.268 | — | `class-restore-preflight.php` L172–177 | — | sunpower field QA 已 Pass；**QA docroot 待重驗** |
| S4b | **Pass（邏輯）** | 2.7.268 | — | preflight overwrite+auto_backup | — | 同上 |
| S5 | **Pass（邏輯）** | 2.7.268 | — | `enter_mid_restore_plugin_isolation` 存在 | — | `db_then_files` + pause ON；QA-B1 實跑待辦 |
| S5-order | **Pass（邏輯）** | 2.7.268 | — | `normalize_options` populated→db_then_files | — | sunpower QA 已 Pass |
| S6 | **Blocked** | — | — | — | — | 需 QA-C1 + wp-config merge E2E |
| S7 | **Blocked** | — | — | — | — | 需 QA-C1 + wp-config keep E2E |
| S8a | **Blocked** | — | — | — | — | 需 CONTENT_ONLY + 有核心站 |
| S8b | **Blocked** | — | — | preflight L167–169 | — | empty_shell + content → blocked（程式邏輯符合規格） |
| S9 | **Pass（證據型）** | 2.7.267 | rjb_20260524_234454_5qjtfy | sunpower completion report | — | **禁止**生產重跑；QA-B1 建議重測 |
| S10 | **Blocked** | — | — | — | — | 需進行中 job + 取消 UI |
| S11 | **Blocked** | — | — | preflight WP version warning L188–196 | — | 邏輯存在；需 FULL-OLD-WP 實跑 |
| S12 | **Blocked** | — | — | — | — | 需 QA-C1 populated + files_then_db |
| S13 | **Fail（風險）** | 2.7.268 | — | 靜態：pause off 跳過 isolation | **BUG-AB-003** | 規格允許「註明風險」；建議 preflight 警告 |
| S14 | **Skip** | — | — | — | — | 無 multisite 環境 |

---

## Lane 執行狀態

| Lane | 環境 | 狀態 | 說明 |
|------|------|------|------|
| **A** | QA-A1, QA-A2 | **Blocked** | Docker Desktop 未運行；S2 靜態 Fail → 應先修 BUG-AB-001 再 E2E |
| **B** | QA-B1 | **Blocked** | 待建置 + FULL-S |
| **C** | QA-C1, QA-C2 | **Blocked** | 待 Lane B 基礎備份 |

---

## P0/P1 閘門

| 級別 | 本輪 | 動作 |
|------|------|------|
| P0 | 無 | — |
| P1 | **BUG-AB-001** Open | **停止 Lane A 之 S2** 直至修復並重測 |
| P2 | BUG-AB-002, BUG-AB-003 | Lane 完成後批次 |

---

## 證據包（本輪已收集）

### 靜態 / repo

- Version: `museder-restoreone.php` → `2.7.268`
- Preflight: `includes/class-restore-preflight.php`
- Isolation: `includes/class-restore-service.php` (`enter_mid_restore_plugin_isolation`, `maybe_enter_plugin_isolation_at_files_stage`)
- Bootstrap: `includes/class-restore-bootstrap.php` L705–713

### 外部（勿在生產重跑）

- `docs/QA-SUNPOWEROFLIGHT-COMPLETION-REPORT-2026-05-25.md`
- `docs/BUG-INVESTIGATION-2026-05-25-restore-stall-76pct-2.7.264.md`

---

## 下一步（解除 Blocked）

1. 啟動 **Docker Desktop** → `docker compose -f docker-compose.qa.yml up -d`
2. `powershell -File tools/qa/approach-b-provision.ps1 -Target all`
3. 在 QA-B1 建立 **FULL-S** 備份（小型全站 zip）
4. 修 **BUG-AB-001** → 重跑 **S2** → 繼續 Lane A/B/C
5. 每項更新本表 + `docs/qa-evidence/approach-b/{id}/`
