# Release 2.7.273 — SVN 發佈手續

**Git：** tag **`v2.7.273`**

**封裝：** `dist/museder-restoreone-2.7.273.zip`（`BOUNDARY_CHECK=PASS`）

**摘要：** 修復大站 ZipArchive 打包完成後 verify 誤判 → 全量 PclZip 重打包導致備份數小時無法完成（grains-beans.com / 2.7.271 根因）。

## readme 六點確認

- [x] `museder-restoreone.php` `Version: 2.7.273`
- [x] `MUSEDER_RESTOREONE_VERSION` / `BUILD_ID` = `2.7.273`
- [x] `readme.txt` `Stable tag: 2.7.273`
- [x] `readme.txt` `Changelog` → `= 2.7.273 =`
- [x] `readme.txt` `Upgrade Notice` → `= 2.7.273 =`

## SVN 指令

```bash
svn co https://plugins.svn.wordpress.org/museder-restoreone museder-restoreone-svn
# 複製 Lite 檔案至 trunk/（見 docs/PACKAGING.md）
svn cp trunk tags/2.7.273
svn commit -m "Release 2.7.273"
```

## 驗證

```bash
php -l includes/class-backup.php
php -l includes/class-backup-jobs.php
php tools/qa/verify-backup-archive-verify.php
# 預期 BACKUP_ARCHIVE_VERIFY_QA=PASS（需 ZipArchive 擴充）
```

## 現場 QA

1. 大站（>1 GB / 20k+ 檔）啟動 full-site backup
2. ZipArchive packing 完成後應在數分鐘內 finalize 完成（**不**再出現 `Archive verification failed; scheduling repack with PclZip`）
3. 完成後 backups 列表出現完整 `.zip`，可下載並 restore 分析通過

## 維運備註（grains-beans.com）

部署 2.7.273 前若仍有 stuck `running/pclzip` job，需 Force Unlock / 清理 partial zip（調查報告 §8）。
