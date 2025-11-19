# WordPress 外掛安裝錯誤修復指南

## 問題說明

當嘗試通過 WordPress 後台上傳和安裝外掛時，出現錯誤訊息：
**"無法複製檔案。museder-restoreone/includes/class-dashboard.php"** (Cannot copy file.)

這通常是文件權限問題，WordPress 無法將解壓縮的文件複製到 `wp-content/plugins/` 目錄。

## 解決方案

### 方案 1：修正文件權限（推薦）

**通過 FTP 或 cPanel 文件管理器：**

1. **檢查 `wp-content/plugins/` 目錄權限：**
   - 目錄權限應為 `755` 或 `775`
   - 文件權限應為 `644` 或 `664`

2. **修正權限：**
   - 通過 FTP 客戶端：右鍵點擊 `wp-content/plugins/` → 屬性 → 設置權限為 `755`
   - 通過 cPanel 文件管理器：選擇目錄 → 更改權限 → 設置為 `755`

3. **確保目錄擁有者正確：**
   - 目錄擁有者應為網站運行用戶（通常是 `www-data` 或 `nobody`）
   - 如果權限不正確，聯繫主機服務商協助修正

### 方案 2：手動上傳外掛（最可靠）

如果自動安裝失敗，可以手動上傳：

1. **下載外掛 ZIP 文件**

2. **解壓縮到本地電腦**

3. **通過 FTP 上傳：**
   - 使用 FileZilla 或其他 FTP 客戶端
   - 連接到服務器
   - 導航到 `wp-content/plugins/` 目錄
   - 上傳整個 `museder-restoreone` 資料夾
   - 確保所有文件和目錄都正確上傳

4. **通過 cPanel 文件管理器上傳：**
   - 登入 cPanel
   - 打開「文件管理器」
   - 導航到 `wp-content/plugins/`
   - 上傳 ZIP 文件
   - 右鍵點擊 ZIP 文件 → 「解壓縮」
   - 刪除 ZIP 文件（保留解壓縮的資料夾）

5. **在 WordPress 後台啟用外掛：**
   - 進入「外掛」→「已安裝的外掛」
   - 找到 "Museder RestoreOne"
   - 點擊「啟用」

### 方案 3：在 wp-config.php 中定義文件系統方法

如果權限問題持續，可以在 `wp-config.php` 中定義文件系統方法：

1. **打開 `wp-config.php`**

2. **找到這一行：**
   ```php
   /* That's all, stop editing! Happy publishing. */
   ```

3. **在這一行之前添加：**
   ```php
   /** 允許 WordPress 直接寫入文件，無需 FTP */
   define('FS_METHOD', 'direct');
   ```

4. **保存文件**

**注意：** 此方法要求文件權限已正確設置。如果權限不正確，此設置可能導致安全問題。

### 方案 4：使用 FTP 模式安裝

如果方案 3 不適用，可以讓 WordPress 使用 FTP 模式：

1. **在 `wp-config.php` 中添加：**
   ```php
   /** 使用 FTP 模式安裝外掛 */
   define('FS_METHOD', 'ftpext');
   define('FTP_BASE', '/path/to/wordpress/');
   define('FTP_CONTENT_DIR', '/path/to/wordpress/wp-content/');
   define('FTP_PLUGIN_DIR ', '/path/to/wordpress/wp-content/plugins/');
   ```

2. **當 WordPress 要求 FTP 認證時，輸入：**
   - FTP 主機：通常是 `localhost` 或您的 FTP 主機
   - FTP 用戶名：您的 FTP 用戶名
   - FTP 密碼：您的 FTP 密碼

## 驗證修復

安裝完成後：

1. **檢查外掛是否正確安裝：**
   - 進入「外掛」→「已安裝的外掛」
   - 確認 "Museder RestoreOne" 出現在列表中

2. **啟用外掛：**
   - 點擊「啟用」按鈕
   - 確認沒有錯誤訊息

3. **檢查外掛功能：**
   - 進入外掛設置頁面
   - 確認所有功能正常運作

## 常見問題

### Q: 為什麼會出現這個錯誤？

A: 通常是因為：
- `wp-content/plugins/` 目錄沒有寫入權限
- 目錄擁有者不是網站運行用戶
- PHP 無法直接寫入文件系統

### Q: 手動上傳後還需要做什麼？

A: 手動上傳後：
1. 在 WordPress 後台啟用外掛
2. 檢查外掛設置
3. 確認所有功能正常

### Q: 如何檢查文件權限？

A: 通過 SSH（如果可用）：
```bash
ls -la wp-content/plugins/
```

或通過 FTP 客戶端查看文件屬性。

## 相關資源

- [WordPress 官方文檔：手動安裝外掛](https://wordpress.org/support/article/managing-plugins/#manual-plugin-installation)
- [WordPress 官方文檔：文件權限](https://wordpress.org/support/article/changing-file-permissions/)

