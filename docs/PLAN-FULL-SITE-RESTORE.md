# 完整站點還原（ZIP）— 規劃與實作筆記

**狀態：** 開發中（2.7.267）  
**背景：** 主機實測（sunpoweroflight.com、chicleantech.com）從「新裝 WordPress + 還原」流程暴露缺口：備份 ZIP 已含 `wp-admin/`、`wp-includes/`、根目錄檔，但還原管線只解壓 `wp-content/`，導致核心與 `.htaccess` 缺失。

## 目標

1. **ZIP 還原**與**完整站點備份**對齊：還原 `wp-content/` 後，若封存內含核心目錄，再還原 `wp-admin/`、`wp-includes/` 與站點根目錄檔（`index.php`、`.htaccess` 等）。
2. **保留執行中還原外掛**：略過覆寫 `wp-content/plugins/museder-restoreone/`（self-protect）。
3. **`wp-config.php` 政策（Step 2）**：預設**從備份覆寫**（`restoreWpConfig` 勾選 → `skip_config=false`）。取消勾選則保留目的地 `wp-config.php`（遷站時 DB 已手動設定則用此項）。
4. **收尾**：若根目錄無 `.htaccess`，嘗試 `save_mod_rewrite_rules()` 或寫入標準 WordPress 區塊（Apache）。

## 非目標（本輪）

- 不要求「完全空目錄、零前置步驟」的一鍵還原（仍建議目標路徑可寫、PHP/DB 可用）。
- 不變更備份打包邏輯（`get_directory_map()` 已打包全站根）。
- 已合併 **release/2.7.265** 還原修復（MU 隔離、DB 後 active_plugins 隔離、安全外掛 reapply、restore token 等）。

## 實作摘要

| 項目 | 位置 |
|------|------|
| ZIP phase 0 | `wp-content/` → `extract_zip_prefix_sliced` |
| ZIP phase 1 | 核心 + 根檔 → `extract_zip_site_root_sliced` |
| 偵測核心 | `zip_archive_has_wp_core()` |
| checkpoint | `zip_files_phase`, `zip_has_wp_core` |
| `.htaccess` | `ensure_site_root_htaccess()` @ cleanup `flush_rewrite` |

## 驗收（B：WP-Cron loopback）

### 前置

- 部署含本變更的 Lite 套件（build `2.7.267-1`）。
- 使用**完整站點** ZIP（含 `wp-admin/index.php`），例如 `logs/150525-debug/` 內封存。

### 案例 A — sunpower（空 docroot 或僅最小 WP）

1. DocumentRoot 可寫；**不要**手動補 `.htaccess`。
2. 上傳封存 → 還原（`skipConfig` 維持預設，不覆寫 `wp-config.php`）。
3. 日誌應見 phase 0「Restoring wp-content…」與 phase 1「Restoring WordPress core…」。
4. 完成後：`wp-admin/`、`wp-includes/` 存在；若有 permalink，`.htaccess` 存在或已生成。
5. 前台 HTTP 200；必要時執行既有 safe-plugins / reapply（若已 merge 266）。

### 案例 B — chicleantech（docroot = `chicleantech.com_OFF`）

1. `--path` 指向實際 docroot。
2. 還原後 `/mu-admin/` 可達（依 MU 外掛）；根目錄 `.htaccess` 來自封存或 fallback。
3. 登入帳號以 DB 還原結果為準。

### 案例 C — 舊版僅 wp-content 的封存

- 無 `wp-admin/` → phase 1 跳過，行為與舊版相容。

## 做法 B（後續）

產品決策已寫入 **`docs/PLAN-RESTORE-APPROACH-B.md`**（優先 B3、Cron 為主、wp-config 三模式、已有資料站強制快照等）。本文件描述之 ZIP 兩階段檔案為做法 B 的 P1 子集。

## 相關文件

- `docs/PLAN-RESTORE-APPROACH-B.md`
- `docs/BUG-INVESTIGATION-2026-05-25-restore-stall-76pct-2.7.264.md`
- `includes/class-backup.php` — `get_directory_map()`, `verify_archive_contains_wp_content()`
