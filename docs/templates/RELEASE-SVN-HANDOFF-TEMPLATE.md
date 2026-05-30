# Release {{VERSION}} — SVN 發佈手續

**Git：** `main` @ `{{COMMIT_SHA}}`，tag **`v{{VERSION}}`**

**封裝：** `dist/museder-restoreone-{{VERSION}}.zip`（{{ZIP_BYTES}} bytes，`BOUNDARY_CHECK=PASS`）

**摘要：** {{ONE_LINE_SUMMARY}}

## readme 五點確認

- [ ] `museder-restoreone.php` `Version: {{VERSION}}`
- [ ] `MUSEDER_RESTOREONE_VERSION` / `BUILD_ID` = `{{VERSION}}`
- [ ] `readme.txt` `Stable tag: {{VERSION}}`
- [ ] `readme.txt` `Changelog` → `= {{VERSION}} =`
- [ ] `readme.txt` `Upgrade Notice` → `= {{VERSION}} =`

## SVN 指令

```bash
svn co https://plugins.svn.wordpress.org/museder-restoreone museder-restoreone-svn
# 複製 Lite 檔案至 trunk/（見 docs/PACKAGING.md）
svn cp trunk tags/{{VERSION}}
svn commit -m "Release {{VERSION}}"
```

## readme-only  hotfix（若僅改 readme）

```bash
# 更新 trunk/readme.txt 與 tags/{{VERSION}}/readme.txt
svn commit -m "readme: {{VERSION}} upgrade notice / changelog fix"
```

## 發佈後驗證

- [ ] https://wordpress.org/plugins/museder-restoreone/ 顯示 {{VERSION}}
- [ ] Changelog / Upgrade Notice 正確
- [ ] 測試站更新外掛成功

## QA 證據（連結）

- {{QA_REPORT_PATH_OR_NONE}}

---

*複製本模板為 `docs/RELEASE-{{VERSION}}-SVN-HANDOFF.md` 並填寫佔位符。*
