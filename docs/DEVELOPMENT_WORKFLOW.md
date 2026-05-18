# GitHub / Local / Cloud Agent Development Workflow

本文件定義 Museder RestoreOne 的長期開發分工。GitHub 是唯一可信來源；本機電腦與 Cursor Cloud Agent 都只是從 GitHub 同步出來的工作環境。若本機更換、增加第二台電腦，或偶爾交給 Cloud Agent 寫碼測試，都必須依此流程運作。

## 角色分工

| 角色 | 職責 | 不應承擔 |
| --- | --- | --- |
| GitHub | 唯一可信來源、版本歷史、branch/PR、tag、GitHub Release、CI 結果 | 不保存本地私密憑證或未整理的大型私有資料 |
| 目前本機 | 日常開發、人工驗證、WordPress 後台測試、GitHub Desktop 操作 | 不作為唯一主控來源；未 commit 的進度不可長期滯留 |
| 未來新電腦 | 從 GitHub clone 後接手開發與測試 | 不從舊本機手動搬運未追蹤狀態作為主流程 |
| Cursor Cloud Agent | 明確範圍的寫碼、修 bug、文件、測試、Plugin Check 類任務 | 不直接發佈 SVN、不保管 secrets、不直接推送高風險變更到 `main` |
| WordPress.org SVN | 正式 Lite 發佈通道 | 不作日常開發、不保存 zip、不保存開發文件 |

## Source of Truth

- GitHub `main` 是專案的唯一可信主線。
- 本機與 Cloud Agent 開始工作前都必須先從 GitHub 同步最新狀態。
- 所有重要規範、工作流與 Agent 指令都必須進 Git，不可只留在對話或某台電腦。
- 本地 `dist/*.zip`、歷史壓縮檔、測試站憑證、SVN 密碼、第三方 API key 不進 Git。

## Branch and PR Policy

### 分支命名

- 新功能：`feature/<short-topic>`
- 修 bug：`fix/<short-topic>`
- 文件/流程：`docs/<short-topic>`
- 發佈準備：`release/<version>`
- Cloud Agent 任務：優先使用 `agent/<short-topic>` 或任務建立的專屬 branch。

範例：

- `docs/development-workflow`
- `fix/restore-nonce-checks`
- `feature/backup-library-filter`
- `release/2.7.263`

### main 保護原則

- `main` 應保持可安裝、可測試、接近可發佈。
- 高風險變更不得直接在 `main` 上長時間開發。
- Cloud Agent 預設不得直接推送 `main`；應開 branch 或 PR，除非使用者明確要求。
- 合併前至少檢查 diff、確認 package 邊界、確認不含 dev-only 檔案。

### PR / 合併檢查

每次合併前確認：

- 變更是否符合 `AGENTS.md` 與 `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`。
- Lite 變更沒有引入 Add-on / PRO 檔案或鎖功能。
- 新增 REST/AJAX/form 是否有 capability、nonce、sanitize、validate、escape。
- 新增檔案是否應進 Git；大型二進位與 release zip 不應提交。
- 測試結果與未測項目有清楚記錄。

## Cursor Cloud Agent Usage

Cloud Agent 適合處理：

- 明確檔案範圍的 bug fix。
- Plugin Check / PHPCS 類問題修正。
- 文件、readme、workflow、release note 更新。
- 單一模組重構。
- 可用指令驗證的打包、lint、smoke test。

交辦 Cloud Agent 時，請提供：

- 任務目標。
- 允許修改的檔案或目錄。
- 不可修改的檔案或目錄。
- 測試或驗證指令。
- 是否允許 commit / push。
- 是否需要 PR。

建議任務模板：

```text
請在 branch `agent/<topic>` 上處理：
目標：
允許修改：
不可修改：
必跑檢查：
完成後：
限制：不得改 release zip、不得提交 secrets、不得直接推 main。
```

Cloud Agent 遇到以下情況必須停止詢問：

- 需要 GitHub token、WordPress.org SVN 密碼、S3/OpenAI key、商店授權或其他 secrets。
- 需要登入 WordPress 後台、處理 2FA、captcha、付款、正式發佈。
- 變更會影響 `main`、Git tag、GitHub Release、WordPress.org SVN。
- 任務需要決定 Lite 與 Add-on 邊界，但需求不清楚。

## Local Development Workflow

### 每次開始工作

```powershell
git status
git pull --ff-only
git switch -c feature/<topic>
```

若使用 GitHub Desktop，也要先 Fetch / Pull，再建立 branch。

### 開發中

- 小步提交，commit message 說明目的。
- 不把 `dist/*.zip`、本地 log、測試匯出、憑證放入 Git。
- 重要變更同步更新 `readme.txt`、changelog、相關 docs。
- 修改 WordPress.org 相關功能時，先讀 `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`。

### 完成後

```powershell
git status
git diff
git add <files>
git commit -m "..."
git push -u origin HEAD
```

若是協作或 Cloud Agent 變更，優先開 PR 或至少人工 review diff 後再合併。

## Testing Workflow

### 基本檢查

- PHP 語法檢查：針對修改過的 PHP 檔執行 `php -l`。
- 打包檢查：`bash create-package.sh`。
- Add-on 打包：只有在 Add-on 有變更時執行 `bash create-package-pro.sh`。
- WordPress 測試環境：可用 `tools/docker/setup.sh` 建立本地測試站。

### WordPress.org 合規檢查

發佈前目標：

- Plugin Check 0 errors；warnings 必須逐項確認或有明確註解。
- Clean install + `WP_DEBUG` true。
- Dashboard / Backups / Restore / Schedules / Logs / Settings 基本流程可操作。
- `readme.txt` 有正確 external services / privacy / changelog / stable tag。
- Lite zip 不含：
  - `docs/`
  - `tools/`
  - `.cursor/`
  - `.github/`
  - `AGENTS.md`
  - `logs/`
  - `dist/` 內舊 zip
  - `museder-restoreone-pro/`
  - AI 產物或退審信件

## Release Workflow

正式發佈前請使用 `docs/RELEASE_CHECKLIST.md` 逐項確認。本節只描述流程角色與順序。

### GitHub Test Package

測試封裝可以完全在 GitHub 上完成，不需要本機產出正式 zip：

1. 到 GitHub repository 的 Actions 頁面。
2. 選擇 `Release` workflow。
3. 按 `Run workflow`。
4. 視需求選擇是否嘗試打包 Add-on。
5. workflow 會執行 `bash create-package.sh`，並在 Add-on 原始碼存在時執行 `bash create-package-pro.sh`。
6. workflow 會檢查 Lite zip 邊界，確保沒有 `docs/`、`tools/`、`.cursor/`、`.github/`、`AGENTS.md`、`museder-restoreone-pro/` 等不應進 Lite 的內容。
7. 產出的 zip 會上傳為 GitHub Actions artifact，供人工下載檢查。

手動測試封裝不會建立 GitHub Release，也不會同步 WordPress.org SVN。

### GitHub Release

正式封裝由 GitHub Actions 依 tag 自動完成；本機或 Cloud Agent 只負責在你授權後推送正式 tag。

1. 確認版本號：`museder-restoreone.php`、`readme.txt`、package name、Git tag 必須一致。
2. 從乾淨 `main` 或 `release/<version>` 建立 release commit。
3. 人工確認後，由本機 Cursor Agent 或 Cloud Agent 代為執行正式發佈指令：

   ```bash
   git tag v2.7.263
   git push origin v2.7.263
   ```

4. 推送 tag 後，GitHub Actions 會執行 `.github/workflows/release.yml`：
   - `bash create-package.sh`
   - 若 Add-on 原始碼存在，執行 `bash create-package-pro.sh`
   - 檢查 Lite package 邊界
   - 上傳 workflow artifact
   - 建立 GitHub Release
   - 附上 `dist/museder-restoreone-*.zip`
   - 若存在，附上 `dist/museder-restoreone-pro-*.zip`
5. 人工檢查 GitHub Release artifact。
6. 到此為止即完成「同步 WordPress.org SVN 的前一步」。

### WordPress.org SVN Release

WordPress.org SVN 只作正式 Lite 發佈：

- 不把 zip 上傳到 SVN。
- 不把 `.cursor/`、`.github/`、`AGENTS.md`、`docs/`、`tools/`、`dist/` 放入 SVN。
- `trunk/` 放可立即使用的 Lite 檔案。
- `tags/<version>/` 從 `trunk` 複製產生。
- SVN commit 前再次確認 `readme.txt` `Stable tag` 指向新 tag。

建議 SVN 發佈前人工檢查：

```text
Lite package contents only:
- assets/
- includes/
- templates/
- languages/ (if present)
- museder-restoreone.php
- readme.txt
- uninstall.php (if present)
- download-handler.php (if present)
```

## New Machine Bootstrap

完整新電腦接手步驟請使用 `docs/NEW_MACHINE_BOOTSTRAP.md`。本節保留摘要。

在新電腦接手前安裝：

- Git 或 GitHub Desktop。
- Cursor。
- PHP 7.4+ 或專案測試需要的 PHP 版本。
- Docker Desktop（若使用本地 WordPress 測試環境）。
- Bash 環境（Git Bash、WSL 或相容 shell，用於 `create-package.sh`）。
- zip 工具。
- SVN client（只有正式 WordPress.org 發佈者需要）。

接手步驟：

```powershell
git clone https://github.com/artherslin-source/museder-restoreone.git
cd museder-restoreone
git status
```

然後閱讀：

- `AGENTS.md`
- `docs/DEVELOPMENT_WORKFLOW.md`
- `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`
- `.cursor/skills/museder-wporg-compliance/SKILL.md`

可選測試：

```powershell
bash create-package.sh
```

若要本地 WordPress 測試：

```powershell
docker compose up -d
bash tools/docker/setup.sh
```

## Secrets and Permissions

不得提交：

- GitHub PAT。
- WordPress.org SVN password。
- S3 / OpenAI / 授權服務 key。
- 私有測試站帳密。
- 商業外掛 zip 或授權。

Cloud Agent 需要 secrets 時，應使用 Cloud Agent / GitHub / CI 的正式 secret 設定，並限制任務可存取範圍。若尚未設定，Cloud Agent 應停止並請使用者設定。

## Routine Maintenance

每週或每個工作階段結束前：

- `git status` 應清楚，避免重要進度留在本地未提交。
- 已合併的本地 branch 可刪除。
- `dist/` 只作本地 artifact；正式歷史版本靠 GitHub Releases。
- 若有新的 WordPress.org 審查意見，先更新 `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md` 與 `.cursor/skills/museder-wporg-compliance/REFERENCE.md`，再改程式碼。

