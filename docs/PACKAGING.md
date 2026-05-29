# Lite 封裝規範（Museder RestoreOne）

本文件為 **WordPress.org Lite ZIP** 的唯一封裝規則說明。Agent、本機開發、CI 皆須遵守。

**關聯：** `create-package.sh`（Linux/macOS/Git Bash 標準）、`tools/package-lite-windows.ps1`（Windows）、`docs/RELEASE_CHECKLIST.md`、`.github/workflows/release.yml`

---

## 1. 標準作法（一律優先）

| 環境 | 指令 | 說明 |
|------|------|------|
| Linux / macOS / Git Bash | `bash create-package.sh` | **唯一標準**；與 GitHub Actions `Release` workflow 相同 |
| Windows（無 bash） | `powershell -File tools/package-lite-windows.ps1` | 邏輯與 `create-package.sh` 相同，並做額外 ZIP 結構檢查 |
| 不確定時 | GitHub Actions → **Release** → Run workflow | 在 Ubuntu 上跑 `create-package.sh`，最可靠 |

**禁止**在未讀本文件前，自行用其它工具「隨便壓一個 zip」上傳 WordPress 或客戶主機。

---

## 2. ZIP 目錄結構（硬性要求）

壓縮檔**檔名**可以是 `museder-restoreone-2.7.268.zip`（含版本號），但 **ZIP 內第一層目錄必須是外掛 slug**：

```text
museder-restoreone/
  museder-restoreone.php          ← 主檔（必須存在）
  readme.txt
  museder-restoreone-restore-bootstrap.php
  uninstall.php
  download-handler.php
  assets/
  includes/
  templates/
  languages/                      ← 若有
```

解壓到 `wp-content/plugins/` 後必須得到：

```text
wp-content/plugins/museder-restoreone/museder-restoreone.php
```

**不得**出現下列任一情況：

- ZIP 內路徑使用反斜線 `\`（見 §4）
- 雙層 slug：`museder-restoreone-2.7.268/museder-restoreone/...`（常因錯誤 zip + 以檔名當資料夾）
- 主檔在 zip 根目錄、沒有 `museder-restoreone/` 父目錄

---

## 3. 允許納入 / 禁止納入

與 `docs/RELEASE_CHECKLIST.md` §3 相同。

**可納入：** `assets/`、`includes/`、`templates/`、`languages/`（若有）、`museder-restoreone.php`、`readme.txt`、`museder-restoreone-restore-bootstrap.php`、`uninstall.php`、`download-handler.php`

**禁止納入：** `docs/`、`tools/`、`.cursor/`、`.github/`、`AGENTS.md`、`logs/`、`dist/`、`museder-restoreone-pro/`、AI 產物、審查信、開發計畫

---

## 4. Windows 封裝陷阱（2026-05-26 事故）

### 現象

使用 **PowerShell `Compress-Archive`** 或部分 **`.NET ZipFile::CreateFromDirectory`** 在 Windows 建 zip 時，ZIP 內 entry 名稱可能變成：

```text
museder-restoreone\museder-restoreone.php
museder-restoreone\assets\css\admin.css
```

（反斜線 `\` 是**檔名的一部分**，不是目錄分隔符。）

在 **Linux / cPanel / WordPress 上傳解壓** 後：

- 不會建立 `museder-restoreone/` 子目錄樹
- 所有檔案擠在錯誤的父目錄（例如 `museder-restoreone-2.7.268/`）且檔名含 `\`
- WordPress 啟用時報錯：**「外掛檔案不存在。」**
- URL 可能像：`plugin=museder-restoreone-2.7.268/museder-restoreone/museder-restoreone.php`

### 禁止（Windows）

| 方式 | 原因 |
|------|------|
| `Compress-Archive` | 常產生含 `\` 的 entry，Linux 不相容 |
| 手動右鍵「傳送到 → 壓縮的資料夾」再改檔名 | 結構不可控 |
| 未驗證就上傳的 zip | 同上 |

### Windows 允許

| 方式 | 說明 |
|------|------|
| `bash create-package.sh` | Git Bash / WSL 下與 CI 一致（**首選**） |
| `tools/package-lite-windows.ps1` | 使用 **`tar -a -c -f …`** 建 zip（Windows 10+ 內建 `tar.exe`） |
| GitHub Actions Release artifact | 無本機 bash 時用此 zip |

---

## 5. 封裝後必做驗證（每次）

不論誰建的 zip，發佈或上傳前至少確認：

1. **主檔路徑**（擇一）  
   - 本機：`tools/package-lite-windows.ps1` 結尾應顯示 `ZIP_STRUCTURE_OK`  
   - 或手動：zip 內必須有 **`museder-restoreone/museder-restoreone.php`**（正斜線 `/`）

2. **無反斜線 entry**  
   - zip 內**不得**有任何 entry 名稱包含 `\`

3. **邊界**  
   - 不含 §3 禁止路徑（CI 的 `release.yml` boundary check 同邏輯）

4. **版號一致**  
   - `museder-restoreone.php` `Version`、`MUSEDER_RESTOREONE_VERSION`、`readme.txt` `Stable tag`、zip 檔名中的版本一致

5. **（建議）試解壓**  
   - 在 Linux 或 WSL：`unzip -l dist/museder-restoreone-*.zip | head`  
   - 確認列表為 `museder-restoreone/...` 且為 `/`

---

## 6. 上傳 WordPress 後台時

1. 使用 **`dist/museder-restoreone-{version}.zip`**（內容結構見 §2），不要改 zip 內層資料夾名稱。  
2. 上傳後外掛目錄應為 **`wp-content/plugins/museder-restoreone/`**，不是 `museder-restoreone-2.7.268/`。  
3. 若曾裝錯，刪除錯誤目錄後再上傳正確 zip，或 SSH：`unzip -o zip -d wp-content/plugins`。

---

## 7. 變更本規範時

若調整 `create-package.sh` 或 Windows 腳本，**必須同步更新本文件**與 `docs/RELEASE_CHECKLIST.md` 的 Package 小節。
