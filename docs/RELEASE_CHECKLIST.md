# Release Checklist

本清單用於 Museder RestoreOne GitHub Release 與 WordPress.org SVN 發佈前檢查。**完整 SOP：** [`docs/SOP-PLUGIN-DEVELOPMENT-AND-RELEASE.md`](SOP-PLUGIN-DEVELOPMENT-AND-RELEASE.md)。GitHub 是開發可信來源；WordPress.org SVN 只作 Lite 正式發佈。

## 1. Release Readiness

- [ ] 目前分支已同步最新 `main`。
- [ ] `git status` 沒有不相關變更。
- [ ] 版本號已更新：
  - [ ] `museder-restoreone.php` `Version`
  - [ ] `MUSEDER_RESTOREONE_VERSION`
  - [ ] `MUSEDER_RESTOREONE_BUILD_ID`（若本次需要）
  - [ ] `readme.txt` `Stable tag`
  - [ ] `readme.txt` changelog（`== Changelog ==` 新 `= X.Y.Z =` 置頂）
  - [ ] `readme.txt` **Upgrade Notice**（`== Upgrade Notice ==` 新 `= X.Y.Z =` 置頂）
- [ ] 若 Add-on 有變更，Add-on 版本號同步更新。
- [ ] `readme.txt` 只有一個清楚的 changelog 區塊。
- [ ] `readme.txt` Upgrade Notice 與 `Stable tag` 同版（WP.org 後台升級說明）。
- [ ] Plugin URI / Author URI 可公開連線。

## 2. WordPress.org Compliance

- [ ] Lite 不包含 `museder-restoreone-pro/`。
- [ ] Lite 不包含 license gate、trialware、quota/time lock、PRO-only local feature gate。
- [ ] Lite 預設不呼叫第三方服務；若有外部服務，`readme.txt` 已揭露用途、資料、時機、Terms、Privacy。
- [ ] 新增或修改的 AJAX / REST / form flow 都有 capability、nonce、sanitize、validate、escape。
- [ ] 新增檔案路徑使用 WordPress API 與 controlled path helper。
- [ ] 新增 DB query 使用 `$wpdb->prepare()` 或有最小範圍 PHPCS 註解與理由。
- [ ] 沒有新增 `backup_lite_*`，除非是明確 legacy migration。
- [ ] 沒有新增直接 `<script>` / `<style>` 到模板；使用 enqueue / inline APIs。

## 3. Package Checks

**封裝規範（必讀）：** [`docs/PACKAGING.md`](PACKAGING.md) — 含 ZIP 目錄結構、禁止的 Windows 壓縮方式、封裝後驗證。

執行：

```bash
bash create-package.sh
```

Windows 本機（無 bash 時）：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/package-lite-windows.ps1
```

- [ ] ZIP 內有 **`museder-restoreone/museder-restoreone.php`**（正斜線 `/`，非 `\`）
- [ ] **未**使用 `Compress-Archive` 或手動壓縮取代上述腳本
- [ ] 上傳 WP 後目錄為 `plugins/museder-restoreone/`，非 `plugins/museder-restoreone-{version}/`

若 Add-on 有變更：

```bash
bash create-package-pro.sh
```

Lite zip 內容只能包含必要執行檔：

- [ ] `assets/`
- [ ] `includes/`
- [ ] `templates/`
- [ ] `languages/`（若存在）
- [ ] `museder-restoreone.php`
- [ ] `readme.txt`
- [ ] `uninstall.php`（若存在）
- [ ] `download-handler.php`（若存在）

Lite zip 不得包含：

- [ ] `docs/`
- [ ] `tools/`
- [ ] `.cursor/`
- [ ] `.github/`
- [ ] `AGENTS.md`
- [ ] `logs/`
- [ ] `dist/` 舊 zip
- [ ] `museder-restoreone-pro/`
- [ ] AI 產物、審查信、測試報告、開發計畫

## 4. Tests

- [ ] 修改過的 PHP 檔通過 `php -l`。
- [ ] Plugin Check 目標為 0 errors / 0 warnings。
- [ ] 若 Plugin Check 有 false positive，已有最小範圍註解與審查可理解的說明。
- [ ] 乾淨 WordPress 安裝可啟用 Lite。
- [ ] `WP_DEBUG` true 下沒有 fatal error。
- [ ] 基本 smoke test：
  - [ ] Dashboard
  - [ ] Backups
  - [ ] Restore
  - [ ] Schedules
  - [ ] Logs
  - [ ] Settings

## 5. GitHub Release

### Test package from GitHub Actions

- [ ] 如需先試包，已在 GitHub Actions `Release` workflow 按 `Run workflow`。
- [ ] Workflow artifact `release-packages` 可下載。
- [ ] Lite package boundary check 通過。
- [ ] 手動測試封裝未建立正式 GitHub Release，未同步 SVN。

### Formal GitHub Release

- [ ] 合併到 `main` 前已 review diff。
- [ ] `main` 狀態乾淨。
- [ ] 已由人工明確授權本機 Cursor Agent 或 Cloud Agent 執行正式 tag 指令。
- [ ] 建立並推送版本 tag，例如：

```bash
git tag v2.7.263
git push origin v2.7.263
```

- [ ] GitHub Actions `Release` workflow 成功。
- [ ] Workflow artifact `release-packages` 已產生。
- [ ] GitHub Release artifacts 包含 Lite zip。
- [ ] 若 Add-on 原始碼存在且有變更，GitHub Release artifacts 包含 Add-on zip。
- [ ] Lite package boundary check 通過。
- [ ] Release notes 沒有誤導 WordPress.org Lite 使用者的 PRO / marketplace 文案。
- [ ] 已完成同步 WordPress.org SVN 的前一步；尚未執行 SVN 發佈。

## 6. WordPress.org SVN Release

- [ ] SVN checkout 已更新。
- [ ] `trunk/` 只放 Lite 可執行檔。
- [ ] 不上傳 zip 到 SVN。
- [ ] 不上傳 `.cursor/`、`.github/`、`AGENTS.md`、`docs/`、`tools/`、`dist/`。
- [ ] 從 `trunk` 複製到 `tags/<version>/`。
- [ ] `readme.txt` `Stable tag`、`Changelog`、`Upgrade Notice` 皆指向 `<version>`。
- [ ] 已建立 `docs/RELEASE-<version>-SVN-HANDOFF.md`（模板：`docs/templates/RELEASE-SVN-HANDOFF-TEMPLATE.md`）。
- [ ] SVN commit message 清楚，例如 `Release 2.7.263`。
- [ ] WordPress.org plugin page 顯示版本與 changelog 正確。

## 7. Post-Release

- [ ] GitHub `main` 與發佈版本一致。
- [ ] 本機 `git status` 乾淨或只剩明確不提交的 artifact。
- [ ] 若有使用者回報或 WordPress.org 通知，建立 issue / branch 處理。
- [ ] 若 release 過程調整了規範，更新 `docs/DEVELOPMENT_WORKFLOW.md` 或 `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`。

