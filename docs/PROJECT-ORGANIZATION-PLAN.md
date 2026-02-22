# Museder RestoreOne 專案整理方案（不影響開發與執行）

更新日期：2026-02-22  
目標：在**不影響外掛後續開發、打包、測試、執行**前提下，整理半年累積檔案，降低混亂與誤提交風險。

---

## 1) 目前目錄盤點（Top-level）

| 路徑 | 目前用途 | 是否核心 | 建議處理 |
|---|---|---:|---|
| `museder-restoreone.php` | 外掛主入口（header/bootstrapping） | ✅ | 保留原位 |
| `includes/` | 核心 PHP 業務邏輯（backup/restore/UI/API） | ✅ | 保留原位 |
| `assets/` | 後台 JS/CSS/vendor | ✅ | 保留原位 |
| `templates/` | 後台頁面模板 | ✅ | 保留原位 |
| `languages/` | i18n 字串（.pot） | ✅ | 保留原位 |
| `readme.txt` | WP.org 外掛說明文件 | ✅ | 保留原位 |
| `download-handler.php` | 歷史下載入口檔（已改 ABSPATH guard） | ⚠️ | 保留、標註 legacy |
| `create-package.sh` | 發行打包腳本（含預掃描） | ✅ | 保留原位 |
| `tools/` | 本地開發工具（Docker setup 等） | 🟡 | 保留，但禁止進 release zip |
| `docker-compose.yml` | 本地測試環境設定 | 🟡 | 保留，不進 release zip |
| `docs/` | 開發/審查/測試文件 | 🟡 | 保留，僅少數可進 release（建議：全部不進） |
| `logs/` | 審查回覆、測試、debug、參考資料 | 🟡 | 改為「內部歸檔區」，不進 release |
| `dist/` | 已打包 zip 成品 | 🟡 | 保留，但列入 Git 忽略 |
| `tmp/` | 暫存測試檔 | ❌ | 改為明確 temp，加入忽略 |
| `release/` | 空殼（.gitkeep） | ❌ | 可保留作釋出版暫存，或合併到 `dist/` |
| `.cursor/` | IDE 設定 | ❌ | 保留本機、忽略提交 |

---

## 2) 整理原則

1. **執行路徑零變動**：`museder-restoreone.php`、`includes/`、`assets/`、`templates/`、`languages/` 不搬移。  
2. **發行包最小化**：WP 提交 zip 僅包含外掛執行必要檔案。  
3. **開發與發行分層**：`docs/`、`logs/`、`tools/`、`dist/` 明確標記為 dev/review artifacts。  
4. **避免歷史資料消失**：先歸檔再刪除；盡量不用破壞性刪除。  

---

## 3) 建議目錄結構（最小改動版）

> 核心程式維持現狀；只做「語意化收納」

```text
museder-restoreone/
├─ assets/
├─ includes/
├─ templates/
├─ languages/
├─ museder-restoreone.php
├─ readme.txt
├─ download-handler.php
├─ create-package.sh
├─ docs/
│  ├─ review/              # WP 審查相關
│  ├─ engineering/         # 實作設計/機制說明
│  └─ testing/             # 測試步驟/診斷
├─ logs/
│  ├─ wp-review/           # 官方往返信件/解讀
│  ├─ audit/               # 稽核報告
│  ├─ debug/               # 除錯輸出
│  └─ refs/                # 第三方參考（如 ai1wm）
├─ tools/
│  └─ docker/
├─ dist/                   # 釋出 zip
└─ tmp/                    # 本機暫存
```

---

## 4) 搬移/歸檔規則（不影響程式）

### A. `docs/` 分類
- `WP-REVIEW*`、`plugin-check-*`、`wp-compliance-*` → `docs/review/`
- `BACKUP-MECHANISM.md`、`AI1WM-*`、`PERFORMANCE-*` → `docs/engineering/`
- `TESTING-*`、`DIAGNOSE-*`、`installation-and-testing.md` → `docs/testing/`

### B. `logs/` 分類
- `WordPress Plugin Directory_Review*.rtfd` → `logs/wp-review/`
- `MUSEDER_RESTOREONE_*_AUDIT*.md`、`*_FIX_REPORT*.md` → `logs/audit/`
- `13020*-debug`、`test-one` → `logs/debug/`
- `all-in-one-wp-migration/` → `logs/refs/ai1wm/`

### C. `dist/` 管理
- 只保留最近 2–3 個候選版 zip（其餘移至外部 archive 或雲端）
- 禁止把測試 zip (`demo-download-*`) 當作提交包

---

## 5) `.gitignore` 建議補強

建議至少包含：

```gitignore
# build/release artifacts
/dist/*.zip
/tmp/

# local tooling
/.cursor/
.DS_Store

# debug/runtime
/logs/debug/
```

> 若 `logs/` 需保留審查證據，請不要整個忽略；只忽略 `logs/debug/` 類即時產物。

---

## 6) 發行前防呆（必做）

1. 一律用 `create-package.sh` 打包（不要手工壓 zip）。  
2. 打包後檢查 zip 內容：不得包含 `tools/`, `logs/`, `dist/`, `tmp/`, `.cursor/`, `.git*`, `.DS_Store`, `*.md`。  
3. 以乾淨 WP + `WP_DEBUG=true` 安裝測試該 zip。  

---

## 7) 高風險項目（整理時避免踩雷）

- **不要搬 `includes/` 子檔路徑**：許多 `require_once` 可能使用相對路徑。  
- **不要修改 class/function 檔名大小寫**：macOS 不敏感，Linux 可能炸。  
- **不要把 `readme.txt` 改成 markdown 作為發行文件**：WP.org 讀 `readme.txt`。  
- **不要刪除審查證據**：先歸檔，保留可追溯性（特別是官方退件內容）。

---

## 8) 建議執行順序（30~60 分鐘可完成）

1. 建立 `docs/review|engineering|testing` 與 `logs/wp-review|audit|debug|refs`。  
2. 先搬移文件與 logs（不碰程式碼）。  
3. 補 `.gitignore` 規則。  
4. 跑一次打包與安裝驗證。  
5. 產出「整理完成清單」供後續 Cursor/團隊遵循。

---

## 9) 這份方案的定位

這是一份**零風險優先**的整理方案：先清楚、可維護、可追蹤，再進入功能修正與過審衝刺。
