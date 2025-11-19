# WordPress 臨時目錄錯誤修復指南

## 問題說明

當嘗試通過 WordPress 後台上傳和安裝外掛時，出現錯誤訊息：
**"找不到暫存資料夾。"** (Cannot find temporary folder.)

這是 WordPress 核心在嘗試解壓縮外掛 ZIP 文件時無法找到系統臨時目錄的問題。

## 解決方案

### 方案 1：在 wp-config.php 中定義臨時目錄（推薦）

1. **通過 FTP 或 cPanel 文件管理器**打開 WordPress 根目錄的 `wp-config.php` 文件

2. **找到這一行：**
   ```php
   /* That's all, stop editing! Happy publishing. */
   ```

3. **在這一行之前添加：**
   ```php
   /** 定義 WordPress 臨時目錄 */
   if ( ! defined( 'WP_TEMP_DIR' ) ) {
       define( 'WP_TEMP_DIR', WP_CONTENT_DIR . '/tmp' );
   }
   ```

4. **確保臨時目錄存在且有寫入權限：**
   - 通過 FTP 或 cPanel 文件管理器
   - 在 `wp-content/` 目錄下創建 `tmp` 資料夾
   - 設置權限為 `755`（目錄）或 `775`（如果需要寫入）

5. **保存文件**

### 方案 2：使用系統臨時目錄

如果方案 1 不適用，可以嘗試使用系統臨時目錄：

```php
/** 定義 WordPress 臨時目錄 */
if ( ! defined( 'WP_TEMP_DIR' ) ) {
    $temp_dir = sys_get_temp_dir();
    if ( is_dir( $temp_dir ) && is_writable( $temp_dir ) ) {
        define( 'WP_TEMP_DIR', $temp_dir );
    } else {
        define( 'WP_TEMP_DIR', WP_CONTENT_DIR . '/tmp' );
    }
}
```

### 方案 3：修正 PHP 配置（需要主機服務商協助）

如果上述方案都無效，可能需要聯繫主機服務商：

1. **檢查 PHP 配置：**
   - 確認 `upload_tmp_dir` 設置正確
   - 確認 `sys_get_temp_dir()` 返回的目錄存在且有寫入權限

2. **通過 cPanel 或主機控制面板：**
   - 檢查 PHP 配置
   - 確認臨時目錄設置

3. **聯繫主機服務商：**
   - 請他們協助設置正確的臨時目錄
   - 或提供可寫入的臨時目錄路徑

### 方案 4：手動上傳外掛（臨時方案）

如果無法修復臨時目錄問題，可以手動上傳外掛：

1. **通過 FTP 上傳：**
   - 下載外掛 ZIP 文件
   - 解壓縮到本地電腦
   - 通過 FTP 客戶端上傳到 `wp-content/plugins/` 目錄
   - 在 WordPress 後台啟用外掛

2. **通過 cPanel 文件管理器：**
   - 登入 cPanel
   - 打開「文件管理器」
   - 導航到 `wp-content/plugins/`
   - 上傳並解壓縮外掛文件

## 驗證修復

修復後，嘗試重新上傳外掛：

1. 進入 WordPress 後台
2. 外掛 → 安裝外掛 → 上傳外掛
3. 選擇外掛 ZIP 文件
4. 點擊「立即安裝」

如果不再出現錯誤訊息，表示修復成功。

## 相關資源

- [WordPress 官方文檔：編輯 wp-config.php](https://wordpress.org/support/article/editing-wp-config-php/)
- [WordPress 官方文檔：手動安裝外掛](https://wordpress.org/support/article/managing-plugins/#manual-plugin-installation)

