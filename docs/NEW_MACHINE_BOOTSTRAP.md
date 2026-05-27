# New Machine Bootstrap

本文件用於目前本機淘汰、加入第二台電腦、或新工作站接手 Museder RestoreOne 時使用。原則：GitHub 是唯一可信來源，新電腦只從 GitHub clone，不從舊電腦手動搬運未追蹤開發狀態。

## 必備帳號與權限

- GitHub repository 存取權限。
- Cursor 登入帳號。
- WordPress.org 帳號與 SVN password（只有正式發佈者需要）。
- GitHub Desktop 登入（若使用 GUI）。
- Docker Desktop 權限（若要跑本地 WordPress 測試）。

不要把任何密碼、token、API key 寫入 repo。

## 必裝工具

Windows 建議：

- Git 或 GitHub Desktop。
- Cursor。
- PHP 7.4+（建議同時可用 PHP 8.2 測試）。
- Docker Desktop。
- Git Bash 或 WSL（用於 bash 打包腳本）。
- zip / unzip 工具。
- SVN client（正式 WordPress.org 發佈者需要，例如 TortoiseSVN 或 CLI svn）。

可選：

- Composer（若後續加入 PHPCS / WPCS 本地工具）。
- Node.js（若後續有前端 build 流程）。

## Clone 專案

```powershell
git clone https://github.com/artherslin-source/museder-restoreone.git
cd museder-restoreone
git status
```

若使用 GitHub Desktop：

1. File > Clone repository。
2. 選擇 `artherslin-source/museder-restoreone`。
3. Clone 完成後用 Cursor 開啟該資料夾。

## 首次閱讀

新電腦開工前先讀：

- `AGENTS.md`
- `docs/DEVELOPMENT_WORKFLOW.md`
- `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`
- `docs/RELEASE_CHECKLIST.md`
- `.cursor/skills/museder-wporg-compliance/SKILL.md`

## 基本驗證

確認檔案完整：

```powershell
git status
```

打包 Lite（規範見 `docs/PACKAGING.md`）：

```bash
bash create-package.sh
```

Windows 且無 bash 時：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/package-lite-windows.ps1
```

**禁止**使用 PowerShell `Compress-Archive` 建 Lite zip。

若 Add-on 原始碼存在且需要驗證：

```powershell
bash create-package-pro.sh
```

## 本地 WordPress 測試環境

啟動 Docker：

```powershell
docker compose up -d
```

執行測試站設定：

```powershell
bash tools/docker/setup.sh
```

預設站台：

- URL: `http://localhost:8080`
- Admin: `admin`
- Password: `admin`

注意：`tools/docker/setup.sh` 可能需要本機測試素材，例如 premium plugin zip 或測試 theme。如果缺少私有素材，先停止並確認來源，不要提交這些商業或私有檔案到 Git。

## 日常開工流程

```powershell
git pull --ff-only
git switch -c feature/<topic>
```

完成後：

```powershell
git status
git diff
git add <files>
git commit -m "..."
git push -u origin HEAD
```

若不熟悉 command line，可用 GitHub Desktop 做同等操作，但仍要遵守 branch / PR 流程。

## Cursor Cloud Agent 設定

Cloud Agent 不需要舊本機檔案；它會從 GitHub clone。

交辦前確認：

- GitHub 上已有最新 `AGENTS.md`、`.cursor/rules/`、`.cursor/skills/`。
- 任務明確指定 branch / PR 策略。
- 不需要 secrets，或 secrets 已用正式 Cloud Agent / GitHub secret 機制設定。
- 任務不要求直接發佈 WordPress.org SVN。

Cloud Agent 任務完成後，在本機或 GitHub review diff，再決定是否 merge。

## 正式發佈額外需求

只有負責發佈的人需要：

- WordPress.org SVN access。
- SVN password。
- 可用 SVN client。
- 能執行 `docs/RELEASE_CHECKLIST.md`。

SVN 只上傳 ready-to-use Lite 檔案，不上傳 zip、不上傳開發文件、不上傳 `.cursor/` 或 `AGENTS.md`。

## 遷移舊本機資料

只遷移必要資料：

- 未 push 的 Git commit：先 push 到 GitHub。
- 不進 Git 的歷史 zip：整理到本地備份或 GitHub Releases。
- WordPress.org 審查信：若要保留，放在私有備份；不要打包進 Lite zip。
- secrets：重新在新電腦或正式 secret 管理中設定，不要複製到 repo。

不要遷移：

- `logs/`
- `dist/*.zip` 作為原始碼的一部分
- `.git` 以外的手動覆蓋狀態
- 未知來源的商業外掛 zip

