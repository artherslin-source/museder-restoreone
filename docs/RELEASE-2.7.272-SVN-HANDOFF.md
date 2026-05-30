# Release 2.7.272 — SVN 發佈手續

**Git：** tag **`v2.7.272`**

**封裝：** `dist/museder-restoreone-2.7.272.zip`（`BOUNDARY_CHECK=PASS`）

**摘要：** Backup Auto 大站判定與 Backups 頁 >1 GB 警告對齊；Auto 不再僅依 50k 檔案數而忽略大容量站點。

## readme 六點確認

- [x] `museder-restoreone.php` `Version: 2.7.272`
- [x] `MUSEDER_RESTOREONE_VERSION` / `BUILD_ID` = `2.7.272`
- [x] `readme.txt` `Stable tag: 2.7.272`
- [x] `readme.txt` `Changelog` → `= 2.7.272 =`
- [x] `readme.txt` `Upgrade Notice` → `= 2.7.272 =`

## SVN 指令

```bash
svn co https://plugins.svn.wordpress.org/museder-restoreone museder-restoreone-svn
# 複製 Lite 檔案至 trunk/（見 docs/PACKAGING.md）
svn cp trunk tags/2.7.272
svn commit -m "Release 2.7.272"
```

## 驗證

```bash
php tools/qa/verify-backup-auto-mode-large-site.php
# 預期 BACKUP_AUTO_MODE_QA=PASS
```

## 現場 QA

1. Backups → Re-scan Size → 確認 > 1 GB 與「Large site detected」
2. Backup Mode / Smart Exclude 皆 **Auto** → 開始備份
3. 進度列應顯示 **Mode: Fast · Smart Exclude: On**
