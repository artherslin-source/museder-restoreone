# 回歸測試提案（第二輪）— BUG-AB-005

**對象：** 測試 Agent  
**版號：** 仍 **2.7.268**（未 bump）  
**前置：** 第一輪回歸 R-S2 Fail（`sanitize_key` fatal）  
**調查：** `docs/BUG-INVESTIGATION-2026-05-27-bootstrap-sanitize-key-R-S2.md`

---

## 0. 複製給測試 Agent（第三輪 — 僅 R-S2）

```text
第三輪：僅重跑 R-S2（QA-A1 empty_shell）。不必重測 R-S13 / R-S3-progress。

開發已修 BUG-AB-005 第二輪缺口：
- register_wordpress_stubs() 新增 wp_upload_dir()（basedir = {BOOTSTRAP_ROOT}/wp-content/uploads）
- bootstrap mode 下 get_legacy_storage_roots / get_legacy_log_dirs 回傳 []

部署：includes/class-restore-bootstrap.php + includes/helpers.php（或 dist zip，版號仍 2.7.268）。

通過標準：
- bootstrap POST 非 500，回傳 job_id
- job 100%，wp-admin / wp-load.php 存在
- pause_other_plugins=true（AB-001）
- 更新 QA-APPROACH-B-RESULTS.md S2、BUG-LOG AB-001/005 為 Verified
```

---

## 1. 修了什麼

| Bug | 修復 |
|-----|------|
| **BUG-AB-005（輪 1）** | `sanitize_key`、`get_bloginfo` stubs |
| **BUG-AB-005（輪 2）** | **`wp_upload_dir`** stub（basedir = `{BOOTSTRAP_ROOT}/wp-content/uploads`）；bootstrap 模式下 **略過 legacy** 備份目錄掃描 |

**原因：** POST 路徑在 wp-load 前會呼叫 `normalize_options` / `Restore_Service::prepare` → `get_all_backup_dirs` → `wp_upload_dir()`。

---

## 2. 必測

| ID | 動作 |
|----|------|
| **R-S2** | QA-A1：GET bootstrap 200 → **POST 啟動還原** → job 完成 |
| **AB-001 驗證** | job meta / 日誌顯示 `pause_other_plugins: true`，還原中無第三方外掛 Fatal |

**不必重測：** R-S13、R-S3-progress（已 Pass）。

---

## 3. 完成定義

- [ ] S2 矩陣列 → **Pass**
- [ ] BUG-AB-005 → **Verified**
- [ ] BUG-AB-001 → **Verified (E2E)**

### 第二輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| GET bootstrap | ✅ 200 |
| POST bootstrap | ❌ 500 — `wp_upload_dir()` undefined（`helpers.php:346`） |
| AB-005 `sanitize_key` | ✅ 已通過該關 |
| S2 / AB-001 / AB-005 Verified | ❌ **未達成** |

**下一步：** ~~開發補 stub~~ **已完成** → **第三輪** 僅重跑 R-S2。

### 第三輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| GET bootstrap | ✅ 200 |
| POST bootstrap | ❌ 500 — **`HOUR_IN_SECONDS`** undefined（`helpers.php:52` → `generate_job_id`） |
| wp_upload_dir / legacy skip | ✅ 已通過該關 |
| S2 / AB-001 / AB-005 Verified | ❌ **未達成** |

**下一步：** ~~開發補時間常數~~ **已完成** → **第四輪** 僅 R-S2。

### 第四輪（複製給測試 Agent）

```text
第四輪：僅重跑 R-S2（QA-A1）。部署 class-restore-bootstrap.php + helpers.php（版號仍 2.7.268）。

開發已修 R3 缺口：WP 時間常數、wp_date/wp_timezone/date_i18n stubs、bootstrap local_time 用 gmdate。

通過：POST 非 500 → job_id → job 100% → wp-load.php / wp-admin → pause_other_plugins=true。
通過後標 S2、AB-001、AB-005 為 Verified。
```

### 第六輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| handoff 自動寫入 | ✅ |
| `pause_other_plugins` | ✅ |
| wp-load / wp-admin | ✅ |
| POST 非 500 | ❌ |
| job 100% | ❌（95%） |
| Verified | ❌ **未達成** |

**下一步：** ~~修 tmp dir / is_multisite~~ **已完成** → **第七輪** R-S2。

### 第五輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| File lock (`bootstrap-restore.lock`) | ✅ |
| job meta `pause_other_plugins` | ✅ |
| POST | ❌ **500** — `wp_generate_password()` |
| poll / slice | ❌ `plugin_basename()`（isolation 路徑） |
| job 100% / CORE | ❌ |
| Verified | ❌ **未達成** |

**下一步：** ~~stub token / plugin_basename~~ **已完成** → **第六輪** 僅 R-S2。

### 第六輪（複製給測試 Agent）

```text
第六輪：僅重跑 R-S2（QA-A1）。部署 class-restore-bootstrap.php（建議整包 2.7.268，含 lock/helpers）。
memory_limit ≥ 2048M。

開發已補 stub：wp_generate_password、wp_hash/wp_salt、plugin_basename、WP_PLUGIN_DIR、get_current_user_id。

通過：POST 非 500 → 自動寫 bootstrap-handoff.json → poll 至 job 100% → wp-load.php + wp-admin → pause_other_plugins=true。
通過後標 S2、AB-001、AB-005 為 Verified。
```

### 第六輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| handoff 自動寫入 | ✅ |
| `pause_other_plugins` | ✅ |
| wp-load / wp-admin | ✅ |
| POST 非 500 | ❌ |
| job 100% | ❌（95%） |
| Verified | ❌ **未達成** |

**下一步：** ~~修 tmp dir / is_multisite~~ **已完成** → **第七輪** R-S2。

### 第七輪（複製給測試 Agent）

```text
第七輪：僅重跑 R-S2（QA-A1，memory_limit ≥ 2048M）。
部署：class-restore-bootstrap.php、class-restore-service.php、class-restore-preflight.php（或整包 2.7.268）。

開發已修：
- Restore_Service::get_job_tmp_directory()（Preflight wp-config 政策）
- 移除 is_multisite stub；wp-load 存在時先 load_wordpress() 再跳過衝突 stub

通過：POST 非 500 → poll 至 job 100% → pause_other_plugins=true。
通過後標 S2、AB-001、AB-005 為 Verified。
```

### 第七輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| 檔案階段 / handoff / pause | ✅ |
| poll HTTP | ✅ 200 |
| POST 非 500 | ❌ `is_wp_error` redeclare |
| job 100% | ❌ 75% `restore-extract-db`（poll `WP_Error` class 衝突） |
| Verified | ❌ **未達成** |

**下一步：** ~~WP_Error / is_wp_error 與 wp-load 順序~~ **已完成** → **第八輪** R-S2。

### 第八輪（複製給測試 Agent）

```text
第八輪：僅重跑 R-S2（QA-A1，memory_limit ≥ 2048M）。
部署：class-restore-bootstrap.php、class-restore-service.php（或整包 2.7.268）。

開發已修：
- 不再定義 minimal WP_Error 類名；改用 Museder_Restoreone_Bootstrap_WP_Error
- 移除 is_wp_error stub；wp-load 存在時 bootstrap_prepare_runtime() 先載入 core
- POST 同請求在 wp-load 出現後以 process_job_slice 繼續 DB 階段

通過：POST 非 500 → poll 至 job 100% completed:true → pause_other_plugins=true。
通過後標 S2、AB-001、AB-005 為 Verified。
```

### 第八輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| 檔案 / handoff / pause / CORE | ✅ |
| POST | ❌ `apply_filters` redeclare |
| job 100% | ❌ 75% |
| Verified | ❌ **未達成** |

**下一步：** ~~同請求 stub+core~~ **已完成** → **第九輪** R-S2。

### 第九輪（複製給測試 Agent）

```text
第九輪：僅重跑 R-S2（QA-A1，memory_limit ≥ 2048M）。
部署 class-restore-bootstrap.php、class-restore-service.php（2.7.268）。

開發已修：
- MUSEDER_RESTOREONE_BOOTSTRAP_STUBS_ACTIVE：有 stub 的請求（POST 開頭）不再同請求 require wp-load
- 檔案階段完成後僅 spawn_loopback；DB 由下一輪 poll（先載 core、無 stub）執行
- load_wordpress：WP_USE_THEMES=false、DOING_CRON=true

通過：POST 非 500 → poll 至 job 100% → pause_other_plugins=true。
```

### 第九輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| POST | ✅ **200** |
| 75% / restore-extract-db | ✅（預期） |
| poll | ❌ **302→install.php**；job 未前進 |
| Verified | ❌ **未達成** |

**下一步：** ~~install 導向~~ **已完成** → **第十輪** 重跑 `run-r-s2-round9.ps1`。

### 第十輪（複製給測試 Agent）

```text
第十輪：重跑 tools/qa/run-r-s2-round9.ps1（QA-A1，≥2048M）。
部署 class-restore-bootstrap.php（2.7.268）。

開發已修：load_wordpress() 前定義 WP_INSTALLING，避免 DB 還原前 302 至 wp-admin/install.php。
斷言：poll 首包非 install.php；body 含 X-Museder-Restoreone-Bootstrap 或 bootstrap 標題；job 至 100%。
```

### 第十輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| WP_INSTALLING / 無 install 302 | ✅ |
| POST | ❌ 500 `status_header()` |
| Poll | ❌ 500 缺 `Museder_Restoreone_Restore` |
| Verified | ❌ **未達成** |

**下一步：** ~~status_header + class-restore.php~~ **已完成** → **第十一輪** 重跑 `run-r-s2-round10.ps1`。

### 第十一輪（複製給測試 Agent）

```text
第十一輪：重跑 tools/qa/run-r-s2-round10.ps1（QA-A1，≥2048M）。
部署 class-restore-bootstrap.php（2.7.268）。

開發已修：
- send_bootstrap_response_headers()（stub POST 不依賴 status_header）
- load_plugin_stack() 含 includes/class-restore.php

斷言：POST 200；poll 200 + X-Museder-Restoreone-Bootstrap；job completed:true。
```

### 第四輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| 時間常數 / `local_time` | ✅ |
| POST | ❌ **500** — `get_site_transient()`（lock 未走 file 模式） |
| Verified | ❌ **未達成** |

**下一步：** ~~`use_file_lock()` bootstrap 一律 true~~ **已完成** → **第五輪** 僅 R-S2。

### 第五輪（複製給測試 Agent）

```text
第五輪：僅重跑 R-S2（QA-A1 empty_shell）。部署 includes/class-restore-lock.php（建議連同 bootstrap/helpers 整包，版號仍 2.7.268）。

開發已修：Restore_Lock::use_file_lock() 在 MUSEDER_RESTOREONE_BOOTSTRAP_MODE 一律使用檔案鎖（不再因 update_option stub 誤走 transient）。

建議容器 PHP memory_limit ≥ 2048M（R4 曾 1GB OOM）。

通過：POST 非 500 → job_id → job 100% → wp-load.php / wp-admin → pause_other_plugins=true。
通過後標 S2、AB-001、AB-005 為 Verified。
```

### 第六輪執行結果（2026-05-27）

| 檢查 | 結果 |
|------|------|
| handoff 自動寫入 | ✅ |
| `pause_other_plugins` | ✅ |
| wp-load / wp-admin | ✅ |
| POST 非 500 | ❌ |
| job 100% | ❌（95%） |
| Verified | ❌ **未達成** |

**下一步：** ~~修 tmp dir / is_multisite~~ **已完成** → **第七輪** R-S2。
