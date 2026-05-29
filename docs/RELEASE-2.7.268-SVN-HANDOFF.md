# Release 2.7.268 — SVN 發佈手續（本機 Agent 未完成項）

**Git：** `main` @ `0031995`，tag **`v2.7.268`** 已 force-push 至 `origin`（含完整 QA 修復，取代先前僅 bootstrap 的 tag）。

**封裝（本機已驗證）：**

```
dist/museder-restoreone-2.7.268.zip
585252 bytes | 80 entries | BOUNDARY_CHECK=PASS
```

**本機環境限制：** 未安裝 `svn` CLI，SVN 發佈需在你有憑證的機器上執行。

---

## 1. WordPress.org SVN 步驟

```bash
svn co https://plugins.svn.wordpress.org/museder-restoreone museder-restoreone-svn
cd museder-restoreone-svn
```

自 **Git `main`（tag v2.7.268）** 複製 Lite 可執行檔至 `trunk/`（**不要**上傳 zip、`docs/`、`tools/`、`.cursor/`）：

- `assets/`
- `includes/`
- `templates/`
- `languages/`（若有）
- `museder-restoreone.php`
- `readme.txt`
- `uninstall.php`、`download-handler.php`（若有）
- `museder-restoreone-restore-bootstrap.php`（若 SVN trunk 已收錄此檔）

確認 `readme.txt`：

- `Stable tag: 2.7.268`
- Changelog `= 2.7.268 =` 含 restore token／nopriv／media paths 條目

```bash
svn status
svn cp trunk tags/2.7.268
svn commit -m "Release 2.7.268"
```

---

## 2. 發佈後驗證

- [ ] https://wordpress.org/plugins/museder-restoreone/ 顯示 **2.7.268**
- [ ] Changelog 正確
- [ ] 乾淨站安裝 smoke（可選）

---

## 3. 非 blocker 補測（發佈後）

- Fresh QA-A2：`run-heavy-a2-restore-from-b1.ps1`
- sunpower T-SUN（GAP-QA-004）

---

*產生：2026-05-29。Git/tag 已完成；SVN 待人工。*
