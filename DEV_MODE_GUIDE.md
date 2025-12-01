# 🔧 開發者模式（Dev Mode）使用指南

## 📍 設定位置

開發者模式透過 **WordPress 根目錄的 `wp-config.php` 檔案**進行設定。

**檔案路徑**：`/wp-config.php`（WordPress 根目錄）

---

## 🚀 快速開始

### 步驟 1：找到 wp-config.php 檔案

1. 使用 FTP/SFTP 或檔案管理器連接到你的 WordPress 網站
2. 進入 WordPress 根目錄（通常包含 `wp-config.php`、`wp-content`、`wp-admin` 等資料夾）
3. 找到 `wp-config.php` 檔案

### 步驟 2：編輯 wp-config.php

1. **備份檔案**（重要！）
   - 在編輯前，先備份 `wp-config.php` 檔案

2. **找到設定區域**
   - 打開 `wp-config.php` 檔案
   - 找到 `/* That's all, stop editing! Happy publishing. */` 這行
   - **在這行之前**加入開發模式設定

3. **加入開發模式常數**

```php
/* That's all, stop editing! Happy publishing. */

// ============================================
// Museder RestoreOne 開發模式設定
// ============================================

// 開發模式：繞過 Free 版次數限制（僅用於開發/測試環境）
define( 'MUSERDER_DEV_MODE', true );

// 強制 High 風險：強制所有 AI 功能回傳 High 風險（用於測試 AI Alerts）
// 注意：此設定只有在 MUSERDER_DEV_MODE 為 true 時才會生效
define( 'MUSERDER_FORCE_HIGH_RISK', true );
```

4. **儲存檔案**

---

## ⚙️ 設定選項說明

### 1. MUSERDER_DEV_MODE（開發模式）

**用途**：繞過 Free 版的 AI 功能次數限制

**設定值**：
- `true` - 開啟開發模式
- `false` 或未設定 - 關閉開發模式（使用正常限制）

**效果**：
- ✅ Free tier 可以無限次使用 AI 功能
- ✅ 不會顯示「本月次數用完」錯誤訊息
- ✅ 不會顯示「Upgrade to Pro」提示
- ✅ 不會更新 Free tier 的使用記錄（不影響正式環境資料）

**適用場景**：
- 開發環境
- 測試環境
- 本地開發站

---

### 2. MUSERDER_FORCE_HIGH_RISK（強制 High 風險）

**用途**：強制所有 AI 功能回傳 High 風險，方便測試 AI Alerts

**設定值**：
- `true` - 開啟強制 High 風險
- `false` 或未設定 - 關閉（使用正常風險評估）

**重要限制**：
- ⚠️ **只有在 `MUSERDER_DEV_MODE` 為 `true` 時才會生效**
- ⚠️ 如果沒有開啟 `MUSERDER_DEV_MODE`，即使設定了 `MUSERDER_FORCE_HIGH_RISK` 也不會動作

**效果**：
- ✅ 所有 AI 功能（Site Scan、Backup Report、Error Log、Restore Guide）都會回傳 High 風險
- ✅ 穩定觸發 AI Alerts 的 Email 通知功能
- ✅ 方便測試不同 License Tier 下的 Alert 顯示

**適用場景**：
- 測試 AI Alerts 的 Email 寄送功能
- 測試前端 Alert 訊息顯示
- 驗證不同 License Tier 的 Alert 行為

---

## 📝 設定範例

### 範例 1：只開啟開發模式（繞過限制）

```php
// 開發模式：繞過 Free 版次數限制
define( 'MUSERDER_DEV_MODE', true );
// MUSERDER_FORCE_HIGH_RISK 未設定或為 false
```

**效果**：
- ✅ 可以無限次使用 AI 功能
- ✅ 使用正常的風險評估（Low/Medium/High）

---

### 範例 2：開啟開發模式 + 強制 High 風險

```php
// 開發模式：繞過 Free 版次數限制
define( 'MUSERDER_DEV_MODE', true );

// 強制 High 風險：測試 AI Alerts
define( 'MUSERDER_FORCE_HIGH_RISK', true );
```

**效果**：
- ✅ 可以無限次使用 AI 功能
- ✅ 所有 AI 功能都回傳 High 風險
- ✅ 穩定觸發 AI Alerts

---

### 範例 3：關閉開發模式（正式環境）

```php
// 開發模式：關閉（或註解掉）
// define( 'MUSERDER_DEV_MODE', true );

// 強制 High 風險：關閉（或註解掉）
// define( 'MUSERDER_FORCE_HIGH_RISK', true );
```

**效果**：
- ✅ 使用正常的 Free/Pro 限制機制
- ✅ 使用正常的風險評估

---

## 🧪 測試流程

### 測試 1：驗證開發模式（繞過限制）

1. **設定**：
   ```php
   define( 'MUSERDER_DEV_MODE', true );
   ```

2. **測試步驟**：
   - 在 AI Settings 中設定 License Tier 為 `free`
   - 進入 Dashboard → AI Site Scan
   - 連續點擊「Run AI Scan」多次（例如：5 次）

3. **預期結果**：
   - ✅ 每次都能成功執行
   - ✅ 不會出現「本月次數用完」錯誤
   - ✅ 不會顯示「Upgrade to Pro」提示

---

### 測試 2：驗證強制 High 風險

1. **設定**：
   ```php
   define( 'MUSERDER_DEV_MODE', true );
   define( 'MUSERDER_FORCE_HIGH_RISK', true );
   ```

2. **測試步驟**：
   - 在 AI Settings 中設定 License Tier 為 `pro`
   - 設定有效的 Alert Email
   - 執行 AI Site Scan 或 Backup AI Report

3. **預期結果**：
   - ✅ 結果顯示 High 風險（紅色 High badge）
   - ✅ 顯示綠色 Alert 訊息：「High risk detected. An alert email has been sent to {email}.」
   - ✅ 收到 Email 通知

---

### 測試 3：驗證關閉後恢復正常

1. **設定**：
   ```php
   // define( 'MUSERDER_DEV_MODE', true );  // 註解掉
   // define( 'MUSERDER_FORCE_HIGH_RISK', true );  // 註解掉
   ```

2. **測試步驟**：
   - 確保 License Tier 為 `free`
   - 執行 AI Site Scan 一次
   - 立即再執行一次

3. **預期結果**：
   - ✅ 第一次執行成功
   - ✅ 第二次執行顯示「本月次數用完」錯誤
   - ✅ 恢復正常的 Free 版限制

---

## ⚠️ 重要注意事項

### 1. 僅用於開發/測試環境

- ❌ **不要在正式網站上啟用開發模式**
- ✅ 僅在開發環境、測試環境、本地開發站使用

### 2. 檔案權限

- 確保 `wp-config.php` 檔案權限設定正確（建議：644）
- 避免被未授權存取

### 3. 版本控制

- 如果使用 Git，建議將 `wp-config.php` 加入 `.gitignore`
- 避免將開發模式設定提交到版本控制系統

### 4. 安全性

- 開發模式不會影響正式環境的資料（不會更新使用記錄）
- 但建議在正式環境中完全關閉開發模式

### 5. 強制 High 風險的限制

- `MUSERDER_FORCE_HIGH_RISK` 只有在 `MUSERDER_DEV_MODE` 為 `true` 時才會生效
- 這是安全機制，確保正式環境不會受到影響

---

## 🔍 如何確認設定是否生效？

### 方法 1：檢查 AI 功能行為

1. **開發模式生效**：
   - Free tier 可以無限次使用 AI 功能
   - 不會顯示「本月次數用完」錯誤

2. **強制 High 風險生效**：
   - 所有 AI 功能都回傳 High 風險
   - 每次執行都會觸發 AI Alerts

### 方法 2：使用 WordPress Debug（進階）

在 `wp-config.php` 中加入：

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

然後在 WordPress 後台執行以下 PHP 程式碼（例如透過 Code Snippets 外掛）：

```php
// 檢查開發模式設定
if ( defined( 'MUSERDER_DEV_MODE' ) && MUSERDER_DEV_MODE ) {
    error_log( 'MUSERDER_DEV_MODE: ENABLED' );
} else {
    error_log( 'MUSERDER_DEV_MODE: DISABLED' );
}

if ( defined( 'MUSERDER_FORCE_HIGH_RISK' ) && MUSERDER_FORCE_HIGH_RISK ) {
    error_log( 'MUSERDER_FORCE_HIGH_RISK: ENABLED' );
} else {
    error_log( 'MUSERDER_FORCE_HIGH_RISK: DISABLED' );
}
```

查看 `wp-content/debug.log` 確認設定狀態。

---

## 📋 常見問題

### Q: 設定後沒有生效？

**A:** 檢查以下項目：
1. 確認 `wp-config.php` 檔案已正確儲存
2. 確認常數定義在 `/* That's all, stop editing! */` 之前
3. 確認語法正確（沒有拼寫錯誤）
4. 清除 WordPress 快取（如果有使用快取外掛）
5. 重新載入 WordPress 後台頁面

---

### Q: 強制 High 風險沒有生效？

**A:** 檢查以下項目：
1. 確認 `MUSERDER_DEV_MODE` 已設定為 `true`
2. 確認 `MUSERDER_FORCE_HIGH_RISK` 已設定為 `true`
3. 強制 High 風險只有在開發模式下才會生效

---

### Q: 如何快速切換開關？

**A:** 使用註解方式：

```php
// 開啟
define( 'MUSERDER_DEV_MODE', true );
define( 'MUSERDER_FORCE_HIGH_RISK', true );

// 關閉（註解掉）
// define( 'MUSERDER_DEV_MODE', true );
// define( 'MUSERDER_FORCE_HIGH_RISK', true );
```

---

### Q: 設定後網站出現錯誤？

**A:** 
1. 立即還原 `wp-config.php` 檔案（使用備份）
2. 檢查 PHP 語法是否正確
3. 確認常數定義的位置正確
4. 查看 WordPress debug.log 找出錯誤原因

---

## 🎯 使用建議

### 開發階段

```php
// 開發模式：開啟
define( 'MUSERDER_DEV_MODE', true );

// 強制 High 風險：視需要開啟
// define( 'MUSERDER_FORCE_HIGH_RISK', true );  // 測試 AI Alerts 時才開
```

### 測試階段

```php
// 開發模式：開啟
define( 'MUSERDER_DEV_MODE', true );

// 強制 High 風險：開啟（測試 AI Alerts）
define( 'MUSERDER_FORCE_HIGH_RISK', true );
```

### 正式環境

```php
// 開發模式：關閉（或完全移除）
// define( 'MUSERDER_DEV_MODE', true );

// 強制 High 風險：關閉（或完全移除）
// define( 'MUSERDER_FORCE_HIGH_RISK', true );
```

---

## 📚 相關文件

- `QUICK_TEST_GUIDE.md` - AI Alerts 快速測試指南
- `TESTING_AI_ALERTS.md` - AI Alerts 完整測試文件

---

**祝開發順利！** 🚀

