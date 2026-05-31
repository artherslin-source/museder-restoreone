# shineching Profile 離線 QA 報告（第二輪）— 2.7.274 wp-config 修復

| 項目 | 內容 |
|------|------|
| **版本** | **2.7.274**（commit `34b9efb` wp-config 修復，版號未 bump） |
| **測試日期** | 2026-05-31 |
| **環境** | Docker **qa-c1**（`:8084`），**未使用** production 主機 |
| **種子備份** | `dist/shineching.com-20260531013823-V6yYBa (1).zip`（592,141,325 bytes） |
| **模擬現場** | populated_wp + `db_then_files` + `wp_config_mode=backup` + 備份內 `ipic1/pa7a_` vs 主機 `wordpress_c1/wp_`（materialize 字面量 wp-config，等同 GoDaddy/cPanel） |
| **主控** | `tools/qa/run-shineching-profile-274.ps1` |
| **E2E** | `tools/qa/shineching-profile-e2e.php` |
| **證據** | `docs/qa-evidence/shineching-profile-2.7.274/` |
| **前案** | [P0 DB 連線錯誤調查](BUG-INVESTIGATION-2026-05-31-shineching-p3-restore-db-error-2.7.274.md) |

---

## 1. 測試計畫摘要

| 階段 | 內容 |
|------|------|
| **L0** | 語法、`RESTORE_SLICE_ATOMIC_QA`、`WP_CONFIG_RESTORE_POLICY_QA`、`STEP3_JOB_STATUS_QA` |
| **Prep** | 重建 qa-c1 volume → 乾淨 WP → 同步 2.7.274 → **materialize wp-config 字面量**（模擬 cPanel） |
| **Phase A** | 565MB 種子**全站還原**；restore-files 中不得出現 `ipic1`；完成後主機 DB 連線 OK |
| **Phase B** | 還原後 **備份 + 第二輪全站還原**；blogname marker 驗證 |
| **HTTP** | 容器內 `curl 127.0.0.1`（避開備份 siteurl https 轉址） |

---

## 2. 執行結果

### 2.1 L0 — 全部 PASS

```
RESTORE_SLICE_ATOMIC_QA=PASS
WP_CONFIG_RESTORE_POLICY_QA=PASS
STEP3_JOB_STATUS_QA=PASS
```

### 2.2 L1 E2E — **SHINECHING_PROFILE_E2E=PASS**（權威 run：2026-05-31 05:19–05:23）

| 檢查 | 結果 |
|------|------|
| Phase A 全站還原 | `loop=3`, `stage=done` |
| restore-files 中途 wp-config | **未**含 `i10269493_ipic1` |
| Phase A 完成後 DB | `wpdb_check_connection_ok` |
| `class-wp-site-health.php` | **131,241 bytes** |
| Phase B 備份 + 再還原 | `SHINECHING_PROFILE_E2E=PASS` |
| 合併後 wp-config（磁碟） | `define( 'DB_NAME', 'wordpress_c1' );` + `php -l` OK |

### 2.3 HTTP smoke — PASS

```
HOME=200
ADMIN=302
```

（容器內；admin 302 為正常未登入導向）

---

## 3. 對照：第一輪 QA 為何 PASS、現場卻失敗？

| | 第一輪 Docker QA | shineching 現場 | 第二輪 Docker QA |
|--|------------------|-----------------|------------------|
| wp-config 中途解壓 | 舊版會覆寫 | **P0 失敗** | 修復後**延後 + merge** |
| 主機 vs 備份 DB 不一致 | 未 assert | **P0 失敗** | E2E **assert** 保留主機 DB |
| HTTP | 標為 harness 跳過 | DB 連線錯誤 | materialize + 容器內 **200** |

**結論：** 第一輪 PASS **不足以**代表 populated host；第二輪已補上 P0 情境。

---

## 4. 是否還有 Bug？

### 4.1 P0（shineching Step 3 DB 連線）— **已修復，本輪 PASS**

`db_then_files` + `wp_config_mode=backup` 不再於 restore-files 中途覆寫 wp-config；完成後 merge 保留現場 `DB_*` / `$table_prefix`。

### 4.2 P2（非阻擋發佈）— **建議 dev 後續修**

| 項目 | 說明 |
|------|------|
| **問題** | `merge_wp_config_files()` 正則 `[^)]+` 破壞 `getenv_docker(...)` 類巢狀括號 define |
| **影響** | Docker **未** materialize 時還原後 `php -l wp-config` 失敗 |
| **shineching / cPanel** | **不受影響**（字面量 `define( 'DB_NAME', '...' )`） |
| **報告** | [BUG-INVESTIGATION-2026-05-31-merge-wp-config-nested-parens-2.7.274-P2.md](BUG-INVESTIGATION-2026-05-31-merge-wp-config-nested-parens-2.7.274-P2.md) |

---

## 5. 發佈判斷

| 問題 | 判斷 |
|------|------|
| shineching P0（wp-config 中途覆寫 → DB 錯誤） | **已修復，離線 E2E 驗證 PASS** |
| 2.7.273 P0（core 半寫入） | **2.7.274 原子寫入仍 PASS** |
| 全站還原 + 備份 + 第二輪還原 | **PASS** |
| 是否「整個外掛 0 bug」 | **否** — 尚有 **P2** merge 正則邊界（不影響 shineching 典型主機） |

### 總結（給你的決策）

**就 shineching.com 現場 Step 3 兩次 P0 根因而言：2.7.274（含 wp-config 修復）可安心發佈。**

若你要求「全產品字面 0 bug」才發佈，請 dev 先修 P2 merge 正則；該項**不阻擋** GoDaddy/cPanel 字面量 wp-config 站點。

**建議現場驗收：** 先用 pre-restore 快照修復損壞站 → 部署 2.7.274 → 以同一備份 `V6yYBa` 再跑 Step 3（`wp_config_mode=backup`）。

---

## 6. 證據索引

| 檔案 | 說明 |
|------|------|
| `docs/qa-evidence/shineching-profile-2.7.274/run.log` | 主控日誌 |
| `docs/qa-evidence/shineching-profile-2.7.274/shineching-e2e-output.txt` | E2E 完整輸出 |
| `docs/qa-evidence/shineching-profile-2.7.274/l0-wp-config-policy.txt` | wp-config 單元 |
| `docs/qa-evidence/shineching-profile-2.7.274/materialize-wp-config.txt` | cPanel 模擬步驟 |

---

## 7. 執行方式（重跑）

```powershell
powershell -File tools/qa/run-shineching-profile-274.ps1
# 或沿用容器：
powershell -File tools/qa/run-shineching-profile-274.ps1 -SkipDockerReset
```

預估 **约 5–7 分鐘**（含 565MB 還原 ×2）。
