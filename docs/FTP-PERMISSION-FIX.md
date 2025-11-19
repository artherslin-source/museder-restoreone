# WordPress FTP 權限問題解決方案

## 問題說明

當嘗試刪除或更新 WordPress 外掛時，如果出現「連線資訊」對話框要求輸入 FTP 認證，這表示 WordPress 無法直接寫入 `wp-content/plugins/` 目錄。

這不是外掛的 bug，而是 WordPress 文件系統權限配置問題。

## 解決方案

### 方案 1：修正文件權限（推薦）

通過 FTP 或 cPanel 文件管理器，將以下目錄的擁有者設為網站運行用戶，並設置正確權限：

**通過 SSH（如果可用）：**
```bash
# 找到 WordPress 根目錄
cd /path/to/wordpress

# 修正 wp-content 目錄權限
chown -R www-data:www-data wp-content/
chmod -R 755 wp-content/
chmod -R 775 wp-content/plugins/
chmod -R 775 wp-content/themes/
chmod -R 775 wp-content/uploads/
```

**通過 cPanel 文件管理器：**
1. 登入 cPanel
2. 打開「文件管理器」
3. 找到 `wp-content/plugins/` 目錄
4. 右鍵點擊 → 「更改權限」
5. 設置為 `755`（目錄）或 `644`（文件）
6. 勾選「遞歸應用於所有子目錄和文件」

### 方案 2：在 wp-config.php 中定義文件系統方法

在 WordPress 根目錄的 `wp-config.php` 文件中，找到 `/* That's all, stop editing! Happy publishing. */` 這一行，**在這一行之前**添加以下代碼：

```php
/** 允許 WordPress 直接寫入文件，無需 FTP */
define('FS_METHOD', 'direct');
```

**完整範例：**
```php
// ... 其他配置 ...

/** 允許 WordPress 直接寫入文件，無需 FTP */
define('FS_METHOD', 'direct');

/* That's all, stop editing! Happy publishing. */
```

**注意：**
- 此方法要求文件權限已正確設置
- 如果權限不正確，此設置可能導致安全問題
- 建議優先使用方案 1 修正權限

### 方案 3：手動通過 FTP 刪除（臨時方案）

如果上述方案都無法實施，可以：

1. **通過 FTP 客戶端刪除：**
   - 使用 FileZilla 或其他 FTP 客戶端連接到服務器
   - 導航到 `wp-content/plugins/museder-restoreone/` 目錄
   - 刪除整個 `museder-restoreone` 資料夾

2. **通過 cPanel 文件管理器刪除：**
   - 登入 cPanel
   - 打開「文件管理器」
   - 導航到 `wp-content/plugins/`
   - 選擇 `museder-restoreone` 資料夾
   - 點擊「刪除」

## 為什麼會出現這個問題？

WordPress 在以下情況會要求 FTP 認證：

1. **文件權限不正確：** `wp-content/plugins/` 目錄的擁有者不是網站運行用戶
2. **安全限制：** 某些共享主機為了安全，限制 PHP 直接寫入文件
3. **SELinux 限制：** 某些服務器啟用了 SELinux，限制了文件寫入權限

## 推薦做法

1. **聯繫主機服務商：** 請他們協助設置正確的文件權限
2. **使用方案 1：** 修正文件權限是最安全和長久的解決方案
3. **避免使用方案 2：** 除非您確定文件權限已正確設置

## 相關資源

- [WordPress 官方文檔：編輯 wp-config.php](https://wordpress.org/support/article/editing-wp-config-php/)
- [WordPress 官方文檔：更改文件權限](https://wordpress.org/support/article/changing-file-permissions/)

