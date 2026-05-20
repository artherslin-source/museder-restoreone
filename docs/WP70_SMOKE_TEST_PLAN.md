# Museder RestoreOne - WordPress 7.0 Smoke Test Plan

## 1) 目標與範圍

本計畫用於 WordPress 7.0 發布前的快速高覆蓋驗證，涵蓋：

- 代碼面（hook/route/permission/nonce 註冊完整性）
- 功能面（備份、還原、排程、log、設定、Email）
- 規範面（WP.org Lite package、敏感端點安全要求）
- 頁面面（六大 admin 頁 callback 可安全渲染）
- UI 面（WP 7.0 Modern 後台視覺與互動 smoke）

此計畫分為：

- 自動化 smoke：可重複、可機器執行（必跑）
- 手動 smoke：功能與 UI 實際互動驗證（必跑）

---

## 2) 測試環境基線

- WordPress: 7.0
- PHP: 7.4 / 8.2（至少一組）
- Database: MariaDB 10.11+
- `WP_DEBUG`: true
- Plugin 安裝來源：本 repo 目前工作樹

建議最小矩陣：

- A 組：WP 7.0 + PHP 8.2（主驗收）
- B 組：WP 7.0 + PHP 7.4（最低支援回歸）

---

## 3) 一鍵自動化 smoke（代碼/規範/頁面）

### 執行方式

```bash
bash tools/docker/wp70-smoke.sh
```

可選參數：

```bash
bash tools/docker/wp70-smoke.sh --skip-setup
```

### 自動化檢查項目

- WordPress 版本已為 7.0
- 外掛可成功啟用
- `WP_DEBUG` 已開啟
- REST route 註冊與安全條件：
  - namespace 需為 `museder-restoreone/v1` 或 `museder-restoreone/v2`
  - `permission_callback` 不得為 `__return_true`
- AJAX action 註冊數量與關鍵 action 存在
- 六大頁面 callback 可在 admin 權限下渲染（不發生 fatal）
  - Dashboard / Backups / Restore / Schedules / Logs / Settings
- Plugin header/readme `Tested up to` 一致性檢查（目前要求 7.0）
- Lite zip 邊界檢查（不得含 `museder-restoreone-pro/`, `docs/`, `tools/`, `logs/`）

> 備註：自動化 smoke 屬「快速守門」，不是完整 E2E。

---

## 4) 手動功能 smoke（功能）

### 4.1 Dashboard

- 可正常進入，無 PHP warning/fatal
- Recent Backups / Schedule Overview / Activity 正常顯示
- Offline readiness scan 可執行並回傳結果

### 4.2 Backups

- 可建立新備份（手動觸發）
- 備份完成後出現在列表
- 下載連結有效（含 nonce/token 路徑）
- 刪除單筆與多筆正常

### 4.3 Restore

- Step 1 上傳或選取既有備份可分析成功
- Step 2 選項可設定（overwrite/safe mode/files-only 等）
- Step 3 執行 restore，進度更新與完成狀態正常
- Safe mode notice 顯示與 Exit Safe Mode 可操作

### 4.4 Schedules

- 可新增/編輯/刪除排程
- Start now 可觸發手動執行
- 排程狀態切換 enabled/disabled 正常

### 4.5 Logs

- 可列出最新 logs
- 可預覽/下載/刪除 log
- 下載路徑 nonce 失效時顯示正確錯誤

### 4.6 Settings + Email

- 設定可儲存並重新載入
- Storage folder 切換正常
- Test email 可發送（`wp_mail` 有回傳）

---

## 5) 手動規範 smoke（WP.org 合規）

- Lite package 不含 `museder-restoreone-pro/`
- 無 locked local pro / quota / trial 限制
- 敏感 AJAX/REST 實測需驗證：
  - 非 admin 或缺 nonce 請求應拒絕（401/403）
- 輸入與輸出：
  - 管理頁無明顯未 escaped 的輸出
- `readme.txt` / plugin header：
  - `Tested up to: 7.0`
  - `Requires PHP: 7.4`
- Plugin Check（建議目標 0 errors / 0 warnings）

---

## 6) 手動 UI smoke（WP 7.0 Modern）

在 WP 7.0 預設 Modern 後台主題下，逐頁確認：

- 色彩對比（特別是 dark mode 與 badge/status）
- 卡片/按鈕/表格在不同頁面視覺一致性
- RWD（至少桌面寬度 + 窄視窗）
- 互動元件：
  - modal、toast、stepper、progress bar、details/summary
- 無明顯 CSS 破版、重疊、截斷、不可點擊

建議保留截圖：

- Dashboard
- Backups
- Restore（Step 1/2/3）
- Schedules（列表 + modal）
- Logs
- Settings

---

## 7) 驗收標準（Go / No-Go）

### Go

- 自動化 smoke 全數通過
- 六大手動功能 smoke 通過
- 規範 smoke 無 blocker
- UI 無 blocker（允許輕微非阻塞視覺差異）

### No-Go

- 有 fatal / restore 無法完成 / backup 無法建立
- 敏感端點可被未授權呼叫
- Lite 包含禁止內容（如 `museder-restoreone-pro/`）
- `Tested up to` 未更新為 7.0（若要宣告 7.0 相容）

---

## 8) 結果記錄模板

```text
Date:
Tester:
Environment:
- WP:
- PHP:
- DB:

Automation:
- tools/docker/wp70-smoke.sh: PASS / FAIL

Manual Functional:
- Dashboard: PASS / FAIL
- Backups: PASS / FAIL
- Restore: PASS / FAIL
- Schedules: PASS / FAIL
- Logs: PASS / FAIL
- Settings/Email: PASS / FAIL

Compliance:
- Package boundary: PASS / FAIL
- Endpoint security spot-check: PASS / FAIL
- Tested up to 7.0: PASS / FAIL
- Plugin Check: PASS / FAIL

UI (Modern):
- Dashboard: PASS / FAIL
- Backups: PASS / FAIL
- Restore: PASS / FAIL
- Schedules: PASS / FAIL
- Logs: PASS / FAIL
- Settings: PASS / FAIL

Blockers:
- ...

Decision:
- GO / NO-GO
```
