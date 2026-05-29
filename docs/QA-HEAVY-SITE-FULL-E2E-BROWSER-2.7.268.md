# 大站 FULL 備份／還原 E2E — 瀏覽器模擬（admin-ajax）

**版本：** `2.7.268`  
**日期：** 2026-05-28  
**環境：** QA-B1（`http://localhost:8083`）— WooCommerce + 17 wp.org 外掛 + Astra + **≈5.6GB** docroot  
**腳本：** `tools/qa/run-heavy-site-full-e2e-browser.ps1`、`tools/qa/resume-restore-browser.ps1`

---

## 1. 測試方法（模擬線上瀏覽器）

| 步驟 | 作法 |
|------|------|
| 登入 | `curl` POST `wp-login.php`（admin/admin），保存 `cookies.txt` |
| Nonce | GET `admin.php?page=museder-restoreone-backups`，解析 `MusederRestoreOneAdmin.nonce` |
| AJAX | POST `wp-admin/admin-ajax.php`，`credentials: same-origin` 等價（cookie 帶 session） |
| 備份 | `museder_restoreone_start_backup_job` → 輪詢 `continue_backup_job` + `get_backup_job_status` |
| 還原 | `restore_from_backup` → `restore_enqueue`（overwrite + autoBackup + full + db_then_files）→ `restore_tick` + `restore_job_status` |

**非** wp-cli phar 驅動 job（避免 BUG-AB-004 OOM）；與 admin.js 相同 action 名稱與 POST 欄位。

---

## 2. 備份 E2E

| 項目 | 值 |
|------|-----|
| Job ID | `1f9961f8-286e-448f-9b3d-991ff34b7303` |
| 模式 | `balanced` + `backup_smart_exclude=auto` |
| 處理量 | **25,923** 檔案 / **5,779,971,644** bytes（≈5.38 GiB） |
| 耗時 | ≈**2.5 分鐘**（3 次 poll：34% → 70% → 100%） |
| 產物 | `localhost-20260527173501-nKKj4r.zip`（≈5.52 GB，`file_size` 自 enqueue） |
| 結果 | **completed** |

證據：`docs/qa-evidence/release-2.7.268-2026-05/heavy-site-e2e-browser/backup-start.json`、`backup-done.json`

---

## 3. 還原 E2E

| 項目 | 值 |
|------|-----|
| 來源 | 上列 FULL 備份 |
| Job ID | `rjb_20260527_173843_8apfez` |
| 選項 | `overwrite=true`、`autoBackup=true`（還原前快照 `localhost-20260527173859-OyO1Fj.zip`）、`restoreScope=full`、`restoreOrder=db_then_files`、`pauseOtherPlugins=true` |
| Profile | `populated_wp` |
| 階段 | extract-db → import-db → **restore-files** → search-replace → cleanup → **done** |
| 結果 | **success**，progress **100%**，`Restore completed successfully.` |

### 3.1 驗證邏輯

1. 備份前設定 `blogname` 為 marker（`E2E-MARKER-20260528013458`）。
2. 備份完成後改為 `CORRUPTED-AFTER-BACKUP-*`。
3. 全量還原後 `blogname` 恢復為 **marker**（DB 還原正確）。

### 3.2 輪詢備註

- 還原在 DB 完成後曾出現 `job_status` 短暫回傳 `job: null`（job meta 仍為 `restore-files` 90%）；持續 `restore_tick` 可推進（與 admin.js `pushRestoreJobTick` 相同）。
- `tools/qa/resume-restore-browser.ps1` 續跑 **4 次 tick**：92% → 96% → 99% → **100% success**（≈30 秒）。
- 主腳本已更新：當 `job` 為 null 時改查 `history` 判定完成。

---

## 4. 還原後 smoke

| 項目 | 結果 |
|------|------|
| `admin-smoke-wpdebug.php` | **6/6**，`debug_log_fatal=no`（清空舊 debug.log 後） |
| 站點大小 | docroot **≈5.6G**（與測試前一致） |
| 外掛 | WooCommerce + 17 常用外掛 + museder-restoreone 共存 |

---

## 5. 總體判定

| 項目 | 判定 |
|------|------|
| 大站 FULL 備份（browser admin-ajax） | **Pass** |
| 大站 FULL 還原（browser admin-ajax + tick） | **Pass** |
| 還原後 admin UI（WP_DEBUG） | **Pass** |
| 建議 | 將 `restore_tick` 輪詢間隔／history  fallback 納入 `run-heavy-site-full-e2e-browser.ps1`（已部分更新）；正式 CI 可設 **MaxMinutes≥240** 給 5GB+ 檔案階段 |

---

## 6. 證據路徑

```
docs/qa-evidence/release-2.7.268-2026-05/heavy-site-e2e-browser/
  run.log
  cookies.txt
  backup-start.json / backup-done.json
  restore-prep.json / restore-enqueue.json
  backups-page.html
```

Job meta（容器內）：`wp-content/uploads/museder-restoreone/jobs/rjb_20260527_173843_8apfez.json`（`completed: true`, `stage: done`）

---

## 7. 重跑指令

```powershell
# 完整備份 + 還原
powershell -File tools/qa/run-heavy-site-full-e2e-browser.ps1

# 僅還原（已有 ZIP）
powershell -File tools/qa/run-heavy-site-full-e2e-browser.ps1 -SkipBackup -BackupZip 'localhost-....zip'

# 卡住時續推 restore_tick
powershell -File tools/qa/resume-restore-browser.ps1 -JobId 'rjb_...'
```

前置：QA-B1 大站已由 `tools/qa/provision-heavy-site-smoke.ps1` 建立。
