# Release 2.7.276 — SVN 發佈手續

**Git：** tag **`v2.7.276`**

**封裝：** `dist/museder-restoreone-2.7.276.zip`（`BOUNDARY_CHECK=PASS`）

**摘要：** 修復全站還原完成後 **Exit Safe Mode** 因 session 失效、HMAC secret 被 uploads 覆寫、或 completion overlay 未綁定 job/token 而顯示 generic error（shineching.com 2.7.275 現場根因）。

## readme 五點確認

- [x] `museder-restoreone.php` `Version: 2.7.276`
- [x] `MUSEDER_RESTOREONE_VERSION` / `BUILD_ID` = `2.7.276`
- [x] `readme.txt` `Stable tag: 2.7.276`
- [x] `readme.txt` `Changelog` → `= 2.7.276 =`
- [x] `readme.txt` `Upgrade Notice` → `= 2.7.276 =`

## SVN 指令

```bash
svn co https://plugins.svn.wordpress.org/museder-restoreone museder-restoreone-svn
# 複製 Lite 檔案至 trunk/（見 docs/PACKAGING.md）
svn cp trunk tags/2.7.276
svn commit -m "Release 2.7.276"
```

## 發佈前驗證

```bash
php -l includes/class-restore-handler.php
php -l includes/class-restore-token.php
php -l includes/class-restore-service.php
powershell -ExecutionPolicy Bypass -File tools/package-lite-windows.ps1
# 預期 BOUNDARY_CHECK=PASS
```

## 現場 QA（shineching.com）

腳本：`tools/qa/run-shineching-exit-safe-mode-patch-deploy.ps1`  
報告：`docs/QA-FIELD-REPORT-2026-06-03-shineching-exit-safe-mode-patch-2.7.275.md`

1. CLI E2E job `rjb_20260603_163224_m3jtce` — **PASS**（post-complete read + exit_safe_mode grant）
2. 瀏覽器 UI：Safe Mode 開啟 → 還原完成 → **Exit Safe Mode** 應成功 toast + reload（非 `An unexpected error occurred`）

## 維運備註

- 僅 patch 4 檔部署時主機版號仍顯示 2.7.275；正式發佈請用本 zip 或 bump 後全量部署。
- 勿用 `shineching-field-deploy.sh` 內硬編 `table_prefix`；shineching 現行 prefix 為 `v4rg_`。

## QA 證據

- 調查：[`docs/BUG-INVESTIGATION-2026-06-03-shineching-exit-safe-mode-manual-retest-2.7.275.md`](BUG-INVESTIGATION-2026-06-03-shineching-exit-safe-mode-manual-retest-2.7.275.md)
- 現場報告：[`docs/QA-FIELD-REPORT-2026-06-03-shineching-exit-safe-mode-patch-2.7.275.md`](QA-FIELD-REPORT-2026-06-03-shineching-exit-safe-mode-patch-2.7.275.md)
- 證據目錄（本地，未入 git）：`docs/qa-evidence/shineching-exit-safe-mode-patch-20260603/`
