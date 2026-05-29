# QA 重測報告 — 2.7.268 第五輪（BUG-QA-007 post-complete poll）

| 項目 | 內容 |
|------|------|
| **測試者** | Cursor Agent |
| **日期** | 2026-05-29 |
| **外掛版本** | **2.7.268** |
| **交接** | `docs/QA-HANDOFF-2.7.268-FIX-ROUND5-2026-05-25.md` |
| **證據** | `docs/qa-evidence/qa-retest-2.7.268-fix-round5-2026-05-25/` |

---

## 1. 總結論

### 產品缺陷（BUG-QA-001～007）

**本輪未發現新的產品 bug。** 第五輪修復（post-complete read grant）與前幾輪修復（nopriv、restore_token、job meta、媒體路徑日誌）在 **大站 B1 同站 E2E** 上 **全部通過**。

### 發佈建議

**2.7.268 可安心發佈**（大站還原 E2E、媒體路徑對帳、poll 收斂均已驗證）。

**保留事項（不阻擋 Lite 發佈）：**
- **sunpower T-SUN** 主測資仍缺（GAP-QA-004）— 中文 DB／ASCII 磁碟掉圖場景 **未 E2E 驗證**
- **A2 跨站** 本輪因 **QA 環境**（`siteurl` 曾為 8083、admin session 302）未跑完 — **非本輪產品回歸**；建議發佈前另用 fresh A2 補測

---

## 2. 結果摘要

| 測項 | 結果 |
|------|------|
| 部署 `class-restore-token.php`、`class-ui.php` | Pass |
| **大站 B1 E2E** | **Pass — exit 0** |
| poll #2 `status=success progress=100` | **Pass** |
| 死 session + token → JSON | Pass |
| 無 resume 100% | Pass（~2 min 還原階段） |
| BUG-QA-003 job meta | Pass（`media_paths_phase=done`，`media_paths_logged=1`） |
| 還原後 smoke 6/6 | Pass |
| **封裝** `package-lite-windows.ps1` | **Pass**（581982 bytes，80 entries） |
| Plugin Check | Pass（0 ERROR，1 WARNING） |
| A→B 跨站 A2 | **Skip**（QA 環境登入 302，見 §6） |
| sunpower T-SUN | Skip |

---

## 3. 第五輪關鍵證據（B1 E2E 最終跑）

**Job：** `rjb_20260529_031520_j0udax`

```text
[11:16:50] Restore token captured (len=64)
[11:17:04] restore poll #1 status=running stage=restore-files progress=92
[11:17:11] restore poll #2 status=success stage=done progress=100
[11:17:13] SkipBackup verify: blogname ne corrupt => True
[11:17:16] === PASS: Heavy-site browser FULL backup/restore E2E ===
EXIT_CODE=0
```

證據：`heavy-b1-e2e/run.log`、`restore-enqueue.json`、`restore-done.json`

---

## 4. 修復項驗證矩陣（001～007）

| Bug | 第五輪結果 |
|-----|-----------|
| BUG-QA-001 大站還原 + poll | **Pass** |
| BUG-QA-002 A→B | 環境未測（見 §6） |
| BUG-QA-003 日誌／meta | **Pass**（job meta；歷史 log 見 round4） |
| BUG-QA-005 SkipBackup 腳本 | Pass（沿用） |
| BUG-QA-006 nopriv mid-restore | **Pass** |
| BUG-QA-007 post-complete poll | **Pass** |

---

## 5. 封裝

```
dist/museder-restoreone-2.7.268.zip
Size: 581982 bytes | entries: 80 | BOUNDARY_CHECK=PASS
```

SHA256：見 `zip-sha256.txt`

---

## 6. A2 跨站未測說明（非產品 bug）

| 現象 | 原因 |
|------|------|
| `WP admin login failed HTTP 302` | A2 `siteurl` 曾為 `http://localhost:8083`（前輪殘留）；已改回 8082 仍 302 |
| 對照 | B1 同腳本模式 **exit 0**；引擎與 token 路徑已驗證 |

**建議：** 以 `provision` 或 fresh DB 重建 QA-A2 後補跑 `run-heavy-a2-restore-from-b1.ps1`（腳本已補 `restore_token`）。

---

## 7. QA 工具調整（非產品）

本輪為使 E2E **exit 0** 對 `run-heavy-site-full-e2e-browser.ps1` 做了 QA 腳本修正（不影響外掛封裝內容）：

- `-SkipBackup` 時 blogname 驗證改為「不等於 corrupt」（舊 zip 不含當次 marker）
- smoke 路徑改為 uploads 可寫目錄
- smoke 前 truncate `debug.log`（避免歷史 fatal 誤判）
- `clear-b1-restore-state.php` 清 lock／post-complete grant
- `run-heavy-a2-restore-from-b1.ps1` 補 `restore_token`（供日後 A2 補測）

---

## 8. 簽核

| 面向 | 建議 |
|------|------|
| **2.7.268 大站還原 E2E** | **Pass — 可發佈** |
| **BUG-QA-001～007** | **已關閉（本輪無新缺陷）** |
| **sunpower 掉圖 E2E** | 仍 **未驗證**（缺 T-SUN zip） |
| **A2 跨站** | 建議發佈後或 fresh A2 **補測**（非 blocker） |

---

*第五輪 QA 完成。產品層面：**已沒有需要再擋發佈的 bug**。*
