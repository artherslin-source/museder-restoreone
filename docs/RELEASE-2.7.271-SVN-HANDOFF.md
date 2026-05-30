# Release 2.7.271 — SVN 發佈手續

**Git：** `main` @ `63e0ee5`，tag **`v2.7.271`**

**封裝：** `dist/museder-restoreone-2.7.271.zip`（588378 bytes，`BOUNDARY_CHECK=PASS`）

**摘要：** Step 3 還原中重載頁 UI 誤重置；files-only 保留 wp-config；History/輪詢 fallback；ZIP core 進度修正。

## readme 六點確認

- [x] `museder-restoreone.php` `Version: 2.7.271`
- [x] `MUSEDER_RESTOREONE_VERSION` / `BUILD_ID` = `2.7.271`
- [x] `readme.txt` `Stable tag: 2.7.271`
- [x] `readme.txt` `Changelog` → `= 2.7.271 =`
- [x] `readme.txt` `Upgrade Notice` → `= 2.7.271 =`（267–270 一併補齊）

## SVN 指令

```bash
svn co https://plugins.svn.wordpress.org/museder-restoreone museder-restoreone-svn
# 複製 Lite 檔案至 trunk/（見 docs/PACKAGING.md）
svn cp trunk tags/2.7.271
svn commit -m "Release 2.7.271"
```

若 trunk 已為 2.7.271 僅缺 readme Upgrade Notice：

```bash
# 更新 trunk/readme.txt 與 tags/2.7.271/readme.txt
svn commit -m "readme: 2.7.271 upgrade notice and 267-270 notices"
```

## QA 證據

- `docs/QA-RETEST-REPORT-2.7.271-STEP3-RESTORE.md`
- `docs/QA-PRE-RELEASE-FINAL-REPORT-2.7.271-ROUND2.md`
- `docs/BUG-INVESTIGATION-2026-05-29-musederlabs-step3-restore-interrupted-2.7.270.md`
