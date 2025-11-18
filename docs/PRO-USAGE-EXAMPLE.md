# Backup Lite PRO - 使用範例

## Phase A 完成項目

### 1. PRO 模式判斷機制

已建立 `includes/class-pro.php`，提供以下方法：

```php
// 檢查是否為 PRO 版本
$is_pro = Backup_Lite_Pro::is_pro_active();

// 檢查特定功能是否可用
$lock = Backup_Lite_Pro::pro_lock( 'ai_copilot' );
if ( ! $lock['enabled'] ) {
    // 功能被鎖定，顯示升級提示
    echo $lock['message']; // "This feature requires Backup Lite PRO."
}
```

### 2. 前端 UI 控制

#### CSS 類別

- `.pro-locked` - 套用於需要 PRO 的元素，會自動反灰並禁用點擊
- `.pro-badge` - PRO 標籤樣式（黃色背景）
- `.pro-cta` - 升級按鈕樣式（不受 `.pro-locked` 影響）

#### HTML 屬性

- `data-upgrade="pro"` - 點擊時自動彈出升級 Modal

### 3. 使用範例

#### 範例 1：按鈕鎖定

```html
<!-- Free 版：按鈕反灰，點擊彈出升級 Modal -->
<button class="bl-button <?php echo Backup_Lite_Pro::is_pro_active() ? '' : 'pro-locked'; ?>" 
        <?php echo Backup_Lite_Pro::is_pro_active() ? '' : 'data-upgrade="pro"'; ?>>
    AI Backup Copilot
    <?php if ( ! Backup_Lite_Pro::is_pro_active() ) : ?>
        <span class="pro-badge">PRO</span>
    <?php endif; ?>
</button>
```

#### 範例 2：選項鎖定

```html
<div class="bl-card <?php echo Backup_Lite_Pro::is_pro_active() ? '' : 'pro-locked'; ?>">
    <h3>
        Cloud Storage
        <?php if ( ! Backup_Lite_Pro::is_pro_active() ) : ?>
            <span class="pro-badge">PRO</span>
        <?php endif; ?>
    </h3>
    <select <?php echo Backup_Lite_Pro::is_pro_active() ? '' : 'data-upgrade="pro"'; ?>>
        <option>Google Drive</option>
        <option>Amazon S3</option>
        <option>Dropbox</option>
    </select>
</div>
```

#### 範例 3：完整功能區塊

```php
<?php
$feature_lock = Backup_Lite_Pro::pro_lock( 'smart_retention' );
?>
<div class="bl-card <?php echo $feature_lock['enabled'] ? '' : 'pro-locked'; ?>">
    <h3>
        Smart Retention
        <?php if ( ! $feature_lock['enabled'] ) : ?>
            <span class="pro-badge">PRO</span>
        <?php endif; ?>
    </h3>
    <p>Automatically manage backup retention based on AI recommendations.</p>
    <button class="bl-button <?php echo $feature_lock['enabled'] ? '' : 'pro-cta'; ?>"
            <?php echo $feature_lock['enabled'] ? '' : 'data-upgrade="pro"'; ?>>
        <?php echo $feature_lock['enabled'] ? 'Configure' : 'Upgrade to PRO'; ?>
    </button>
</div>
```

### 4. JavaScript 整合

前端已自動處理 `data-upgrade="pro"` 點擊事件，無需額外 JavaScript。

如需手動觸發升級 Modal：

```javascript
// 顯示升級 Modal
window.BackupLiteUI.showProModal();

// 隱藏升級 Modal
window.BackupLiteUI.hideProModal();

// 檢查 PRO 狀態（從 PHP 傳遞）
if ( window.BackupLitePro && window.BackupLitePro.isPro ) {
    // PRO 功能已啟用
}
```

### 5. 測試 PRO 模式

在 `wp-config.php` 中暫時啟用 PRO 模式（僅供開發測試）：

```php
define( 'BACKUP_LITE_PRO_ACTIVE', true );
```

或使用 PHP 代碼：

```php
Backup_Lite_Pro::activate();
```

停用：

```php
Backup_Lite_Pro::deactivate();
```

---

## 下一步：Phase B

Phase B 將建立 PRO Features 選單頁面與導航結構。

