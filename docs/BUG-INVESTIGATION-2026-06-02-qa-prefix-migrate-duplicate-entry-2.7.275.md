# Bug 調查報告：2.7.275 QA 回歸失敗（prefix-migrate Duplicate entry）

提交對象：開發 agent  
調查日期：2026-06-02（UTC+8）  
測試環境：本機 Docker（`qa-c1` / `qa-c1-db`），**未觸碰 production 主機與站點**  
測試腳本：`tools/qa/run-shineching-profile-274.ps1 -SkipDockerReset -SimulateCpanel`  
版本：`2.7.275`（最新修復後、版本號不變）

---

## 1. 結論（先講結果）

本輪 **有阻斷級 bug**，不可發佈。

雖然 L0 靜態/單元守門檢查全部 PASS，但在 L1 實際 restore 流程中，`prefix-migrate` 階段觸發資料庫唯一鍵衝突：

- `Duplicate entry 'wp_attachment_pages_enabled' for key 'option_name'`
- 還原 job 進入 `stage=failed`

這代表最新修復尚未完全可用；同時也發現 QA 腳本/測試判斷存在「誤判 PASS」問題（有 FAIL 行卻仍輸出 `SHINECHING_PROFILE_E2E=PASS`）。

---

## 2. 測試範圍與目標

依需求執行：

1. 搭建隔離 WP 測試環境（Docker clean QA 站）
2. 用最新 `2.7.275` 進行一輪 restore + backup + round2 restore
3. 關注近 30 輪重點 bug（還原穩定性、wp-config merge、Step3 UI 收斂、防 timeout、prefix/capabilities、media reconcile）
4. 有 bug 則產出完整調查報告

---

## 3. 主要證據

### 3.1 L0 守門檢查全 PASS

在 `run.log` 可見：

- `L0_atomic_write_unit PASS`
- `L0_wp_config_policy PASS`
- `L0_step3_job_status PASS`
- `L0_media_paths_reconcile PASS`
- `L0_restore_runtime_options PASS`
- `L0_restore_ui_convergence_guards PASS`
- `L0_version_275 PASS`

### 3.2 L1 E2E 實際失敗（核心）

證據檔：`docs/qa-evidence/shineching-profile-2.7.275/shineching-e2e-output.txt`

關鍵片段：

```text
WordPress database error Duplicate entry 'wp_attachment_pages_enabled' for key 'option_name'
... made by ... Museder_Restoreone_Restore_Service::stage_migrate_db_prefix
```

同檔內還可見：

```text
FAIL phase_a_seed_restore bad_final_stage failed
FAIL phase_a_r8_final_status final_status_job_not_done stage=failed
...
FAIL phase_b_restore bad_final_stage failed
```

### 3.3 失敗後 DB 狀態（prefix key 混亂）

本輪 QA DB 查詢結果：

`wp_options` 仍有多個 `pa7a_%` key（包括 `pa7a_user_roles`）：

```text
pa7a_attachment_pages_enabled
...
pa7a_user_roles
```

`wp_usermeta` 已有 `wp_capabilities` / `wp_user_level`：

```text
wp_capabilities    3
wp_user_level      3
```

=> 遷移只做了一部分，且在 options key 全量 rename 時撞鍵失敗，流程中斷。

### 3.4 QA 腳本誤判 PASS（次要但重要）

同一份 `shineching-e2e-output.txt` 明確有多個 `FAIL`，但檔尾仍是：

```text
SHINECHING_PROFILE_E2E=PASS
```

`run.log` 也因此記錄：

```text
PASS L1_shineching_e2e ... SHINECHING_PROFILE_E2E=PASS
OVERALL=PASS
```

=> 測試框架存在 false-positive 風險，會掩蓋真實回歸失敗。

---

## 4. 根因分析

### 根因 A（產品邏輯）：prefix-migrate 對 `wp_options` 做過寬 rename，遇到既有同名 key 直接撞唯一鍵

`includes/class-restore-service.php` 的 `stage_migrate_db_prefix()` 在 `options_keys` phase 使用：

```sql
UPDATE wp_options
SET option_name = CONCAT(:to, SUBSTRING(option_name, :start))
WHERE option_name LIKE :from_prefix
LIMIT 500
```

當來源有 `pa7a_attachment_pages_enabled`，目標站已存在 `wp_attachment_pages_enabled` 時，會觸發 duplicate key error，導致 restore 失敗。

這是典型「全前綴 rename」策略在 populated target 上的碰撞問題。

### 根因 B（測試邏輯）：E2E fail 計數/結論判定失真

`shineching-profile-e2e.php` 印出 FAIL 訊息後，最終仍輸出 PASS，表示 fail 計數匯總邏輯有缺陷（或被流程覆寫），導致自動化總結不可信。

---

## 5. 對近 30 輪重點 bug 的回歸判定

| 類別 | 本輪狀態 | 判定 |
|---|---|---|
| 還原流程可完成（P3 done） | 實際 job 進入 `failed` | ❌ 回歸失敗 |
| prefix/capability 修復（最新重點） | 發生 `stage_migrate_db_prefix` duplicate，遷移中斷 | ❌ 新修復仍有缺陷 |
| Step3 UI guard / REST-only convergence（靜態） | guard 檢查存在 | ✅ 靜態存在 |
| runtime options cleanup（靜態） | QA check PASS | ✅ 靜態存在 |
| wp-config policy（靜態） | QA check PASS | ✅ 靜態存在 |
| media paths reconcile（實際 restore 後） | 因 restore fail 連帶大量 FAIL | ⚠️ 本輪無法成立驗證 |
| QA 判定可靠性 | 有 FAIL 仍總結 PASS | ❌ 測試框架 bug |

---

## 6. 建議修復方向

### P0（先修）：`stage_migrate_db_prefix()` 避免 options key 碰撞

建議改為「精準鍵遷移」而非 `from_%` 全量 rename：

- 必改鍵：
  - `{from}_user_roles` -> `{to}_user_roles`
  - `{from}_capabilities` -> `{to}_capabilities`（usermeta）
  - `{from}_user_level` -> `{to}_user_level`（usermeta）
- 其他 `{from}_*` 鍵不得盲目全改；至少要 collision-aware（存在目標鍵時 merge/skip/compare）

### P0（同步修）：restore pipeline 錯誤處理要可觀測

- 在 job meta 中明確記錄 collision key 與 SQL 錯誤碼
- 失敗訊息需包含可行動上下文（哪個 key 撞名）

### P1（測試）：修正 E2E false-positive

- `shineching-profile-e2e.php` 必須保證「任何 FAIL => 非 0 exit code」
- `run-shineching-profile-274.ps1` 不應只靠 `SHINECHING_PROFILE_E2E=PASS` 字串判定；也要掃描 `FAIL\t`
- 建議新增硬性 assertion：
  - 最終 `phase_a/phase_b` stage 必須 `done|rollback-done`
  - 若 `stage=failed` 直接 fail pipeline

---

## 7. 重現步驟（給開發 agent）

1. 清空 QA volumes，啟動 `qa-c1`/`qa-c1-db`
2. 執行：`tools/qa/run-shineching-profile-274.ps1 -SkipDockerReset -SimulateCpanel`
3. 查看：
   - `docs/qa-evidence/shineching-profile-2.7.275/shineching-e2e-output.txt`
   - `docs/qa-evidence/shineching-profile-2.7.275/run.log`
4. 可見 duplicate entry 及 stage failed，同時結論卻誤判 PASS

---

## 8. 發佈建議

目前結論：**不建議發佈**。

需先完成：

1. prefix migration collision 修復
2. E2E PASS/FAIL 判定修復
3. 重新跑一次完整隔離 QA（含 SimulateCpanel）且無 FAIL

