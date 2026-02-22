# Museder RestoreOne — WP 審查差距分析（Gap Analysis）

更新日期：2026-02-22  
範圍：依專案內既有審查文件與報告，整理「已修 / 未修 / 仍有風險」項目，並提出可執行優先順序。

參考來源（專案內）：
- `docs/WP-REVIEW-260124-解讀報告.md`
- `docs/WP-REVIEW-260124-修正完成報告.md`
- `docs/wp-compliance-checklist.md`
- `logs/WordPress Plugin Directory_Review in Progress_ 260111-01.rtfd/TXT.rtf`
- `logs/MUSEDER_RESTOREONE_AUDIT_REPORT_2026-01-12.md`

---

## 1) 核心結論（先看）

目前專案已針對 2026-01-24 審查重點做過一輪修正，方向正確；但要提升「一次過審」機率，仍需補上三件事：

1. **證據一致性**：文件聲稱已修，需用當前程式碼與打包結果再驗證一次（避免「文件已修、實際回退」）。
2. **命名前綴收斂**：歷史 `backup_lite*` 與 `museder_restoreone*` 混用風險，需要最終盤點。
3. **提審包治理**：嚴格排除 dev/review 檔，避免再次被「非功能檔案」退件。

---

## 2) 逐項 Gap 狀態

## A. AI generated output / 非必要文件夾帶

**審查關注**  
- 不應把 AI 產出的內部說明檔一起塞進提交 zip。

**目前狀態**：**部分完成（需複驗）**
- 文件指出 `docs/wp-compliance-checklist.md` 已在打包時排除。
- 目前 `create-package.sh` 看起來是「只複製必要目錄與檔案」的白名單模式，理論上已降低風險。

**Gap**
- 仍需每次 release 自動驗證「zip 內容白名單」；避免未來新增腳本或手工打包時回歸。

**建議動作**
- 固化 release gate：CI 或本地腳本強制檢查 zip 內容。

---

## B. Generic naming（函式/類別/option/transient 前綴）

**審查關注**  
- 需具唯一性前綴；特別是 options/transients。

**目前狀態**：**部分完成（高風險）**
- 文件稱已做 `backup_lite*` → `museder_restoreone*` 大規模改名與一次性遷移。
- 審查曾點名 `add_option($key,...)`，雖可能是靜態掃描誤判，仍需可說明證據鏈。

**Gap**
- 半年開發歷史下，最容易遺留「舊 key 仍被讀寫」或「新舊前綴混用」。

**建議動作**
- 執行全域掃描（functions/hooks/options/transients/constants）產出清單，標記舊前綴是否只存在遷移碼。
- 對 `add_option/update_option/get_option/set_transient/...` 做 whitelist 檢查，確保 key 一律專屬前綴。

---

## C. Direct file access（可直接執行 PHP 檔）

**審查關注**  
- 直接存取時必須立即退出，不能跑業務邏輯。

**目前狀態**：**看似完成（需 smoke test）**
- 文件稱 `download-handler.php` 已改為 `if ( ! defined( 'ABSPATH' ) ) exit;`。

**Gap**
- 除 `download-handler.php` 外，仍應掃描全專案是否有其他可執行 PHP 檔未做 guard。

**建議動作**
- 以腳本列出所有 PHP 檔頭 guard 狀態；對含 executable code 的檔案補齊。

---

## D. Nonce + Capability + Input Sanitization

**審查關注**  
- 每個 AJAX/REST 入口都要 nonce + 權限；輸入需 sanitize，輸出需 escape。

**目前狀態**：**中度風險（需回歸測試）**
- 歷史報告顯示已補多處檢查，但審查信曾列出多個 method 缺 nonce 的實例。

**Gap**
- 每次重構都可能打破一致性；若只有「人工信心」而無機械檢查，容易再退件。

**建議動作**
- 建立「入口點矩陣」（AJAX action / REST route / admin-post）→ 對應 capability、nonce、sanitize、回應格式。
- 在 PR/release 前跑 Plugin Check + PHPCS（WPCS）並保存報告。

---

## E. 檔案寫入位置（uploads 規範）

**審查關注**  
- 插件產生檔案應寫入 uploads（透過 `wp_upload_dir()` 推導）。

**目前狀態**：**大致完成（低~中風險）**
- 歷史稽核有證據顯示備份/還原/日誌已落在 uploads 子目錄。

**Gap**
- 還原流程通常含多路徑例外分支，需確認沒有 fallback 寫回 plugin 目錄。

**建議動作**
- 針對 backup/restore flow 各跑一次 e2e，驗證實際落盤路徑。

---

## F. 外部服務揭露（readme 合規）

**審查關注**  
- 若使用第三方/外部服務，必須在 readme 清楚揭露用途、資料、條款。

**目前狀態**：**可能未完全收斂（中~高風險）**
- 最早退件曾明確點到 external service disclosure。
- 專案內仍有 AI/雲端相關模組（`includes/ai`, `includes/pro/cloud*`），需再次核對 readme 是否完整揭露。

**Gap**
- 功能存在但 readme 沒同步，是常見再退件原因。

**建議動作**
- 按功能逐條核對 readme：傳送資料種類、觸發時機、服務端點、隱私/條款連結。

---

## G. Plugin Check 警告治理

**目前狀態**：**有進展但未封頂**
- 文件中提到 Plugin Check ERROR=0、仍有 WARNING（如 restore DB 寫入相關）。

**Gap**
- 即使 warning 可合理解釋，也要準備審查回信口徑與對應註解，降低志工審查成本。

**建議動作**
- 對每個 warning 準備「安全性/必要性說明 + 代碼註解 + 測試證據」。

---

## 3) 高風險 Top 5（先修這些）

1. **前綴混用殘留（`backup_lite*` vs `museder_restoreone*`）**  
2. **readme 外部服務揭露不完整**  
3. **入口點 nonce/capability 有漏網之魚**  
4. **提審 zip 夾帶非功能檔案**  
5. **可直接存取 PHP 檔 guard 不一致**

---

## 4) 建議修正優先順序（過審導向）

### P0（今天就可做）
- 固化 release gate（zip 白名單檢查）
- 全域掃描 option/transient key 與舊前綴殘留
- 產出入口點矩陣（AJAX/REST/admin-post）

### P1（1~2 天）
- 補齊 readme 外部服務揭露
- 全專案 direct access guard 掃描與修補
- 執行 Plugin Check + PHPCS 並留存結果

### P2（提交前）
- 乾淨站台 + `WP_DEBUG=true` 完整安裝/備份/還原 smoke test
- 生成最終提交包與「審查回信摘要模板」

---

## 5) 提交策略建議（避免反覆退件）

- 不再用「修一點就送」；改為「**一次性 checklist 達標再送**」。
- 每次提交附簡短變更摘要，對照審查項目（讓志工可快速對焦）。
- 任何「可能是誤報」都附上可驗證證據（程式片段 + 路徑 + 測試結果）。

---

## 6) 本分析結語

這個案子不是做不出來，而是缺一個穩定的**合規交付流程**。  
只要把「掃描 → 修正 → 證據 → 打包 gate」固定化，就能大幅降低下一輪退件機率，同時保住外掛功能目標。
