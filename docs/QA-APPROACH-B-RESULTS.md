# 做法 B 通用還原驗收 — 結果矩陣

**最後更新：** 2026-05-27（**R-S2 第十二輪 — PASS**；S2 / AB-001 / AB-005 Verified）  
**Baseline：** `2.7.268`  
**環境：** `docker-compose.qa.yml`（QA-A1…C2）  
**初輪：** 2026-05-25（Docker 未跑）｜**回歸：** `docs/QA-APPROACH-B-RETEST-PROPOSAL-2026-05.md`

---

## 回歸輪結果（R-*）

| ID | 結果 | Build | Job ID | 證據 | Bug ID | 備註 |
|----|------|-------|--------|------|--------|------|
| **R-S2** | **Pass** | 2.7.268 | rjb_20260527_153349_owtrvd | R12: POST/poll 200；`completed` 100%（`R-S2-round12/`） | **BUG-AB-005** | ✅ Verified |
| **R-S3-progress** | **Pass（靜態）** | 2.7.268 | — | `restore.js` 含 `restore-db` cap 80 | BUG-AB-002 | UI 實跑待辦 |
| **R-S13** | **Pass** | 2.7.268 | — | `docs/qa-evidence/approach-b-retest-2026-05/R-S13/` | BUG-AB-003 | populated_wp + pause off + db_then_files 有警告、未阻擋 |

### R-S2 證據摘要

- QA-A1：備份 `localhost-20260527083521-E02Tyj.zip`；已 `docker cp` 最新 `class-restore-bootstrap.php`（含 `sanitize_key` stub）
- GET bootstrap → **200**
- **第五輪 POST** → **500**（`wp_generate_password`）；job meta 含 **`pause_other_plugins: true`**
- poll slice → **`plugin_basename`**（AB-001 isolation 路徑）
- 證據：`docs/qa-evidence/approach-b-retest-2026-05/R-S2-round5/`
- 調查報告：`docs/BUG-INVESTIGATION-2026-05-27-bootstrap-sanitize-key-R-S2.md`（含第二輪更新）

### R-S13 證據摘要

```json
{
  "ok": true,
  "warning_hit": true,
  "blocked": false,
  "profile": "populated_wp",
  "warnings": [
    "…pre-restore snapshot…",
    "Restore order is \"database first\" with other plugins left enabled…"
  ]
}
```

---

## T0–T5（回歸輪）

| # | 檢查 | 結果 |
|---|------|------|
| T0 | 2.7.268 / ae87d28 | **Pass** |
| T1 | QA docroot 隔離 | **Pass**（Docker 8081–8085） |
| T2 | uploads 可寫 | **Pass**（B1 備份成功） |
| T3 | Cron loopback | **Pass**（R-S2 bootstrap poll 驅動完成） |
| T4 | FULL-S 封存 | **Pass**（B1 產出 `localhost-20260527083521-E02Tyj.zip`） |

---

## 情境矩陣（S1–S14）— 合併初輪 + 回歸

| ID | 結果 | Build | 備註 |
|----|------|-------|------|
| S1 | Blocked | 2.7.268 | 可於 QA-A1 做 post-R-S2 smoke |
| S2 | **Pass** | 2.7.268 | R12：empty_shell bootstrap E2E 完成 |
| S3 | Blocked | — | 待 A2 環境 |
| S4 / S4b | Pass（邏輯） | 2.7.268 | 建議 B1 實跑一輪 |
| S5 | Pass（邏輯） | 2.7.268 | 待 B1 實跑 |
| S6–S8 | Blocked | — | — |
| S9 | Pass（證據型） | 2.7.267 | sunpower；禁止生產重跑 |
| S10–S12 | Blocked | — | — |
| S13 | **Pass** | 2.7.268 | 回歸 R-S13 |
| S14 | Skip | — | — |

---

## 回歸輪完成定義（RETEST-PROPOSAL §7）

| 項目 | 狀態 |
|------|------|
| R-S2 E2E Pass | ✅ **Pass**（R12） |
| R-S13 警告 Pass | ✅ |
| R-S3-progress | ✅ 靜態 |
| BUG-AB-001/002/003/005 Verified | 001/005 ✅（R-S2）；002/003 ✅ |
| 結果表已更新 | ✅ |

---

## 下一步

1. 開發修 **BUG-AB-005**（bootstrap stub 或延後 `normalize_options`）
2. 重跑 **R-S2** → 通過後更新 AB-001 為 **Verified (E2E)**
3. 更新 `approach-b-retest.ps1`：A1 部署用 **packaged zip**；備份/評估用 **container php**（避開 BUG-AB-004）
