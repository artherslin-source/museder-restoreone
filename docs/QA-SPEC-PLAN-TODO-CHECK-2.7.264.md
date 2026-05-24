# QA Spec / Plan / Todo / Check (2.7.264)

## Spec

### 背景

- 使用者回報 `2.7.264` 在實站仍出現兩個問題：
  - 備份耗時異常，長時間無法完成。
  - 進度條未能反映真實進度（體感卡住或不可信）。
- 本輪修正已加入：
  - `PclZip` 批次失敗時單檔降級重試。
  - `PclZip` 連續全失敗批次快速中止（fail-fast）。
  - 進度改以 `pointer` + `processed_files` 綜合計算，避免假性卡住。
  - Smart Exclude 啟用時，自動偵測與排除常見他牌備份工件目錄，並在 UI 顯示提示。

### 目標

- 驗證備份流程不再無限卡住。
- 驗證進度條與實際處理進度一致（至少單調前進且有合理狀態切換）。
- 驗證「自動偵測＋自動排除他牌備份工件」已生效且使用者可見。

### 驗收標準

- 任一測試備份任務不可出現「長時間無限 running 且無明確終態」。
- 進度顯示需符合：
  - preparing/packing/finalizing 狀態切換正確。
  - 百分比不應長時間固定不動但後端已消耗 pointer 的情況。
- Smart Exclude 為 `auto/on` 時：
  - job payload 出現 auto excluded count/labels。
  - UI 狀態列顯示 auto excluded 訊息。
  - log 有 auto-excluding 記錄。

### 非目標

- 本輪不更動版本號（維持 `2.7.264`）。
- 不進行 WordPress.org SVN 發佈流程，只做功能驗證。

---

## Plan

### P0 環境與基線確認

1. 安裝/確認 `php CLI` 可用。
2. 安裝本輪封裝 `dist/museder-restoreone-2.7.264.zip` 到測試站。
3. 清空或分隔本次測試 log 區段（記錄開始時間）。

### P1 主問題驗證（卡住與進度）

1. 執行「包含大型他牌備份工件」的備份案例（Smart Exclude=`auto`）。
2. 觀察 UI 進度、mode 狀態列、任務終態。
3. 核對 `backup-lite` log 內是否有：
   - auto excluding 訊息
   - pointer/packing 前進
   - 若失敗是否快速 fail 且有明確錯誤

### P2 對照案例驗證（排除開關有效性）

1. Smart Exclude=`off` 重跑（可縮小資料範圍，避免長時測試）。
2. 比對 `auto` 與 `off` 的差異：
   - auto excluded UI/日志應只在 `auto/on` 出現。
   - `off` 不應有 auto excluded 訊息。

### P3 回歸檢查

1. 驗證取消備份流程仍正常（可取消、可終態）。
2. 驗證封裝與安裝仍正常（已完成結構驗證，補實站安裝確認）。

---

## Todo

- [ ] 在測試站安裝最新封裝 `2.7.264`（本輪修正版）。
- [ ] 準備測試資料：至少保留一個他牌大型備份工件目錄（AI1WM/Updraft/BackWPup/Duplicator 任一）。
- [ ] 執行 TC-01（Smart Exclude=`auto`，主案例）。
- [ ] 執行 TC-02（Smart Exclude=`off`，對照案例）。
- [ ] 執行 TC-03（取消流程回歸）。
- [ ] 蒐集證據（截圖 + log 片段 + job id）。
- [ ] 填寫 Check 區結果（Pass/Fail + 備註）。

---

## Check

### TC-01 主案例：Smart Exclude=`auto`

**步驟**

1. Backups 頁面保持 `Smart Exclude = Auto`。
2. 啟動備份，記錄開始時間與 job id。
3. 觀察狀態列與進度條至終態。

**預期**

- UI 顯示 mode/smart exclude，且出現 auto excluded 提示（count 或 labels）。
- 進度條會前進，不應出現長時間假性卡住。
- 任務最終為 `completed` 或明確 `failed`（不得無限 running）。
- log 出現 auto excluding 記錄與對應 job id。

**證據**

- 截圖：進度條 + 狀態列。
- log：`Auto-excluding known backup artifact directories.` / job 終態行。

---

### TC-02 對照案例：Smart Exclude=`off`

**步驟**

1. 將 Smart Exclude 設為 `Off`。
2. 啟動備份，記錄 job id。

**預期**

- UI 不顯示 auto excluded 訊息。
- payload/log 不應有 auto excluded count/labels。

**證據**

- 截圖：模式列（顯示 Off 且無 auto excluded）。
- log：同 job id 下無 auto-excluding 記錄。

---

### TC-03 回歸案例：取消流程

**步驟**

1. 啟動備份後點擊取消。
2. 觀察 UI 與 log。

**預期**

- UI 顯示取消完成訊息。
- job 進入 `cancelled` 終態。
- 不應復活為 running。

**證據**

- 截圖：取消成功訊息。
- log：`Backup job cancelled by user.` 與終態確認。

---

## 測試結果填寫模板

- 測試日期：
- 測試站網址：
- 外掛版本：`2.7.264`
- 測試者：

### 結果摘要

- TC-01：`Pass / Fail`
- TC-02：`Pass / Fail`
- TC-03：`Pass / Fail`

### 問題紀錄（若 Fail）

- 現象：
- 發生步驟：
- job id：
- 關鍵 log：
- 截圖檔名：
- 建議修正方向：
