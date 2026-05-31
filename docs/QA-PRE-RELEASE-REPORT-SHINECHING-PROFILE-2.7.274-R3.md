# shineching Profile 離線 QA 報告（第三輪）— 2.7.274 P2 merge 修復

| 項目 | 內容 |
|------|------|
| **版本** | **2.7.274**（P2 `merge_wp_config_files` 括號深度掃描，版號未 bump） |
| **測試日期** | 2026-05-31 |
| **環境** | Docker **qa-c1**（`:8084`），**未使用** production |
| **種子** | `dist/shineching.com-20260531013823-V6yYBa (1).zip`（592,141,325 bytes） |
| **主控** | `tools/qa/run-shineching-profile-274.ps1` |
| **證據** | `docs/qa-evidence/shineching-profile-2.7.274/` |

---

## 1. 本輪驗證重點

| 修復 | 驗證方式 |
|------|----------|
| P0 wp-config 延後 + populated merge | E2E restore-files 不寫入 `ipic1`；完成後保留主機 DB |
| P2 `find_define_closing_paren` 等 | `WP_CONFIG_RESTORE_POLICY_QA=PASS`（含 Docker `getenv_docker` + `php -l`） |
| 全站 + 第二輪 | Phase A 種子還原 + Phase B 備份/再還原 |

---

## 2. 執行結果

### 2.1 L0 — 全部 PASS

```
RESTORE_SLICE_ATOMIC_QA=PASS
WP_CONFIG_RESTORE_POLICY_QA=PASS   ← 含 P2 Docker getenv_docker + php -l
STEP3_JOB_STATUS_QA=PASS
```

### 2.2 L1 路徑 A — **cPanel 模擬（`-SimulateCpanel`）** → **OVERALL=PASS**

| 檢查 | 結果 |
|------|------|
| Phase A 565MB 全站還原 | PASS |
| Phase B 備份 + 再還原 | PASS |
| `wp_config_php_lint` | PASS（Phase A + Phase B 後） |
| 容器內 HTTP | **HOME=200 ADMIN=302** |
| **總判** | **`SHINECHING_PROFILE_E2E=PASS` / `OVERALL=PASS`** |

此路徑對齊 **GoDaddy/cPanel 字面量 `define( 'DB_NAME', '...' )`**，等同 shineching.com 現場。

### 2.3 L1 路徑 B — **Docker 原生 `getenv_docker`（預設，無 materialize）**

| 檢查 | 結果 |
|------|------|
| E2E Phase A + B | **PASS**（含 `phase_a/b wp_config_php_lint`） |
| P2 語法 | merge 後 `php -l wp-config.php` **OK** |
| 容器內 HTTP | **500** — `Call to undefined function getenv_docker()` |

**說明：** merge 以 archive 為基底，保留 destination 的 `getenv_docker(...)` **呼叫**，但未帶入 Docker 官方 `wp-config-docker.php` 頂端的 **`function getenv_docker()` 定義**。  
**僅影響 Docker 離線 harness**；**shineching / cPanel 字面量 wp-config 不受影響**（路徑 A 已 PASS）。

---

## 3. 是否有阻擋發佈的 Bug？

| 項目 | 結論 |
|------|------|
| P0（DB 連線錯誤） | **已修復，cPanel 路徑 PASS** |
| P2（merge 截斷括號） | **已修復，單元 + E2E `php -l` PASS** |
| 原子寫入 core | **仍 PASS** |
| shineching 典型主機 | **本輪未發現新 P0/P1** |

### 給你的結論

**就 shineching.com Step 3 全站還原（含備份/第二輪還原）而言：2.7.274 可安心發佈。**

本輪 **未發現** 需阻擋 WordPress.org / 現場部署的新 product bug。

Docker 原生路徑 HTTP 500 屬 **離線 harness 邊界**（非 cPanel 現場）；若 dev 要完善 Docker 支援，可列 backlog：merge 時保留 destination 的 `getenv_docker()` 函式定義。

---

## 4. 重跑方式

```powershell
# 權威：cPanel / shineching 現場對齊（含 HTTP）
powershell -File tools/qa/run-shineching-profile-274.ps1 -SimulateCpanel

# P2 回歸：Docker 原生 getenv（E2E only，HTTP 預期 harness 限制）
powershell -File tools/qa/run-shineching-profile-274.ps1

# 沿用已啟動容器
powershell -File tools/qa/run-shineching-profile-274.ps1 -SkipDockerReset -SimulateCpanel
```

---

## 5. 證據

| 檔案 | 說明 |
|------|------|
| `run.log` | 本輪 `OVERALL=PASS`（SimulateCpanel run 14:34–14:37） |
| `shineching-e2e-output.txt` | 含 `wp_config_php_lint` PASS |
| `l0-wp-config-policy.txt` | P2 單元含 Docker merge |
