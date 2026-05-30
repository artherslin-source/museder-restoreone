# Release 2.7.274 — SVN 發佈手續

**Git：** tag **`v2.7.274`**

**封裝：** `dist/museder-restoreone-2.7.274.zip`（`BOUNDARY_CHECK=PASS`）

**摘要：** 修復 Step 3 全站還原切片解壓中途停止時半寫入 WordPress core → 後台 Parse error / critical error（shineching.com / 2.7.273 根因）。

## readme 六點確認

- [x] `museder-restoreone.php` `Version: 2.7.274`
- [x] `MUSEDER_RESTOREONE_VERSION` / `BUILD_ID` = `2.7.274`
- [x] `readme.txt` `Stable tag: 2.7.274`
- [x] `readme.txt` `Changelog` → `= 2.7.274 =`
- [x] `readme.txt` `Upgrade Notice` → `= 2.7.274 =`

## SVN 指令

```bash
svn co https://plugins.svn.wordpress.org/museder-restoreone museder-restoreone-svn
svn cp trunk tags/2.7.274
svn commit -m "Release 2.7.274"
```

## 驗證

```bash
php -l includes/class-restore-service.php
php tools/qa/verify-restore-slice-atomic-write.php
# 預期 RESTORE_SLICE_ATOMIC_QA=PASS
```

## 現場 QA

1. 大 ZIP 全站還原（含 wp-admin / wp-includes）
2. 還原進行中刷新 wp-admin — **不應**出現 critical error
3. phase 1 進度應從 ~85% 緩升（非長時間停在 85% 而 core 僅 6%）

## 維運備註（shineching.com）

已損壞站點需先用 pre-restore snapshot `WPKJjN.zip` 或手動還原完整 `class-wp-site-health.php`（131,241 bytes）後再部署 2.7.274 重試。
