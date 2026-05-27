# 回歸測試提案（第二輪）— BUG-AB-005

**對象：** 測試 Agent  
**版號：** 仍 **2.7.268**（未 bump）  
**前置：** 第一輪回歸 R-S2 Fail（`sanitize_key` fatal）  
**調查：** `docs/BUG-INVESTIGATION-2026-05-27-bootstrap-sanitize-key-R-S2.md`

---

## 0. 複製給測試 Agent

```text
請重跑 docs/QA-APPROACH-B-RETEST-PROPOSAL-2026-05.md 中的 R-S2（及 AB-001 E2E 驗證）。

開發已修 BUG-AB-005：bootstrap stub 新增 sanitize_key、get_bloginfo。
環境：QA-A1 Docker，部署最新 class-restore-bootstrap.php（或整包 2.7.268 zip）。

通過標準：
- bootstrap POST 非 500，回傳 job_id
- job 100%，wp-admin / wp-load.php 存在
- pause_other_plugins=true（AB-001）
- 更新 QA-APPROACH-B-RESULTS.md S2、BUG-LOG AB-001/005 為 Verified
```

---

## 1. 修了什麼

| Bug | 修復 |
|-----|------|
| **BUG-AB-005** | `register_wordpress_stubs()` 新增 **`sanitize_key`**、**`get_bloginfo('version')`**（無核心時回傳空字串，跳過版本警告） |

**原因：** AB-001 讓 bootstrap POST 走 `normalize_options()` + `execute()` → `preflight()`，在 **wp-load.php 之前** 呼叫了未 stub 的 WP 函式。

---

## 2. 必測

| ID | 動作 |
|----|------|
| **R-S2** | QA-A1：GET bootstrap 200 → **POST 啟動還原** → job 完成 |
| **AB-001 驗證** | job meta / 日誌顯示 `pause_other_plugins: true`，還原中無第三方外掛 Fatal |

**不必重測：** R-S13、R-S3-progress（已 Pass）。

---

## 3. 完成定義

- [ ] S2 矩陣列 → **Pass**
- [ ] BUG-AB-005 → **Verified**
- [ ] BUG-AB-001 → **Verified (E2E)**
