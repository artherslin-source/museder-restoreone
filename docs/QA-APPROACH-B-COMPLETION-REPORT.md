# 做法 B 通用還原驗收 — 階段完成報告（初輪）

**日期：** 2026-05-25  
**Baseline：** Museder RestoreOne Lite `2.7.268`  
**任務書：** `docs/QA-APPROACH-B-AGENT-HANDOFF.md`

---

## 1. 執行摘要

| 項目 | 結果 |
|------|------|
| 多環境建置計畫 | ✅ 已完成 → `docs/QA-APPROACH-B-ENVIRONMENTS.md` + `docker-compose.qa.yml` |
| Docker QA 五站實機 E2E | ⏸ **Blocked**（本機 Docker daemon 未運行） |
| cPanel `qa-restore-test/` | ⏸ **Blocked**（需維運 SSH，憑證不入庫） |
| 結果矩陣 | ✅ `docs/QA-APPROACH-B-RESULTS.md` |
| 新 Bug 清單 | ✅ `docs/BUG-LOG-APPROACH-B-2026-05.md`（3 項，含 1×P1） |
| 階段完成定義（HANDOFF §11） | ❌ **未達成** — S1–S11 尚未在 QA docroot 全 Pass |

**結論：** 本輪完成 **規劃、環境架構、靜態審查與 sunpower 證據對照**；**未**宣稱「做法 B 通用驗收完成」。開發 Agent 應先處理 **BUG-AB-001**，再於 Docker QA 環境重跑 Lane A（尤其 **S2**）。

---

## 2. 交付物清單

| 檔案 | 狀態 |
|------|------|
| `docs/QA-APPROACH-B-ENVIRONMENTS.md` | ✅ 新建 |
| `docs/QA-APPROACH-B-RESULTS.md` | ✅ 新建 |
| `docs/QA-APPROACH-B-COMPLETION-REPORT.md` | ✅ 本文件 |
| `docs/BUG-LOG-APPROACH-B-2026-05.md` | ✅ 新建 |
| `docker-compose.qa.yml` | ✅ 新建（5 獨立 docroot） |
| `tools/qa/approach-b-provision.ps1` | ✅ 新建 |

---

## 3. 五環境模擬設計（A/B/C 對照）

| 環境 | 模擬線上情境 | 必跑案例 |
|------|----------------|----------|
| **QA-A1** `localhost:8081` | 空 docroot / 主機只裝外掛 | S1, **S2** |
| **QA-A2** `localhost:8082` | 新裝 WP、幾乎無內容 | S3 |
| **QA-B1** `localhost:8083` | 已有文章與多外掛的生產型小站 | S4, S4b, S5, S9, S10, S11 |
| **QA-C1** `localhost:8084` | 同 B1，專做設定檔／順序 | S6, S7, S12 |
| **QA-C2** `localhost:8085` | content_only 對照 | S8a, S8b |

與 **生產 sunpower** 的關係：sunpower 僅提供 **populated 成功 job** 與 **preflight Pass** 之參考證據；**不可替代** QA-A1 的 S2 bootstrap 全量 POST。

---

## 4. 本輪測試發現（供開發 Agent）

### P1 — BUG-AB-001（優先）

Bootstrap 啟動還原時 `pause_other_plugins => false`，導致 `maybe_enter_plugin_isolation_at_files_stage()` 直接 return，**S2 空 docroot 全量還原**可能再次出現「DB/檔案順序 + 未隔離外掛」fatal（與 `BUG-INVESTIGATION-2026-05-25-restore-stall-76pct` 同類）。

**建議修復後驗收：** QA-A1、S2、FULL-S、POST bootstrap 表單至 job 100%。

### P2 — BUG-AB-002 / BUG-AB-003

- 進度條 stage 名稱不一致（UX）
- 使用者關閉 pause + `db_then_files` 的已知風險需 preflight 警告

---

## 5. 未執行與原因

| 項目 | 原因 |
|------|------|
| 全矩陣 E2E | Docker Desktop 未啟動；無 SSH QA docroot |
| 生產 sunpower 再還原 | HANDOFF 禁止 |
| `public_html` 根目錄還原 | P0 架構風險，禁止 |
| S14 Multisite | 無環境，Skip |

---

## 6. 建議執行順序（下一輪 Agent／人工）

1. 合併修復 **BUG-AB-001**（可與 2.7.264 隔離修復同一 PR 討論）
2. 啟動 `docker-compose.qa.yml` → provision → QA-B1 備份產出 FULL-S
3. Lane A：**S2** → S1 → S3
4. Lane B：S4 → S4b → S5 → S9
5. Lane C：S6–S8、S12–S13
6. 更新 `QA-APPROACH-B-RESULTS.md`；若 S1–S11 全 Pass → 將本報告改為「階段完成」

---

## 7. 給開發 Agent 的修復 Prompt 起點

```
請讀 docs/BUG-LOG-APPROACH-B-2026-05.md（優先 BUG-AB-001）
與 docs/BUG-INVESTIGATION-2026-05-25-restore-stall-76pct-2.7.264.md（回歸背景）。
修復後在 docker-compose.qa.yml QA-A1 驗證 S2 bootstrap 全量 POST。
```

---

## 8. 階段完成檢查清單（HANDOFF §11）

- [ ] S1–S11 皆 Pass 或 Blocked（有原因）
- [ ] **S2 全量 E2E** 在 QA docroot **Pass**
- [ ] P0/P1 bug 清零或 Won't fix
- [x] 完成報告已寫入本文件（**初輪／未通過驗收**）
- [x] 未在生產 sunpower / 共用 public_html 根造成事故
