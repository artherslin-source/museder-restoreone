# All-in-One WP Migration 备份转换指南

本插件现在支持自动检测和转换 All-in-One WP Migration 外挂生成的备份文件，使其可以在此插件中还原。

## 支持的格式

- **ZIP 格式**：自动检测和转换
- **.wpress 格式**：检测到但需要手动转换（未来版本将支持自动转换）

## 自动转换

当你上传备份文件时，插件会自动：

1. 检测文件是否为 All-in-One WP Migration 格式
2. 如果是，自动转换为 Museder RestoreOne 格式
3. 保存转换后的文件到备份目录
4. 使用转换后的文件进行还原

## 转换过程

转换工具会：

1. **提取原始备份**：解压 All-in-One 备份文件
2. **重组结构**：将文件重组为 Museder RestoreOne 格式
   - 数据库文件：`database.sql`
   - WordPress 内容：`wp-content/` 目录
   - 元数据文件：`backup-lite-meta.json`
3. **创建新备份**：生成符合 Museder RestoreOne 格式的 ZIP 文件

## 支持的结构

转换工具支持以下 All-in-One 备份结构：

### 结构 1：直接结构
```
backup.zip
├── database.sql
├── plugins/
├── themes/
├── uploads/
└── mu-plugins/
```

### 结构 2：restore-package 结构
```
backup.zip
└── restore-package/
    ├── database.sql
    ├── plugins/
    ├── themes/
    ├── uploads/
    └── mu-plugins/
```

### 结构 3：wp-content 结构
```
backup.zip
├── database.sql
└── wp-content/
    ├── plugins/
    ├── themes/
    └── uploads/
```

## 使用方法

### 方法 1：直接上传（推荐）

1. 进入 **Museder RestoreOne > Restore** 页面
2. 点击"上传备份文件"
3. 选择 All-in-One WP Migration 备份文件（`.zip` 或 `.wpress`）
4. 插件会自动检测并转换
5. 转换完成后，按照正常还原流程操作

### 方法 2：使用现有备份文件

如果你已经将备份文件放在备份目录中：

1. 进入 **Museder RestoreOne > Restore** 页面
2. 选择"从现有备份还原"
3. 选择 All-in-One 备份文件
4. 插件会自动检测并转换

### 方法 3：从远程 URL 下载

1. 进入 **Museder RestoreOne > Restore** 页面
2. 选择"从 URL 还原"
3. 输入 All-in-One 备份文件的 URL
4. 插件下载后会自动检测并转换

## 注意事项

1. **网站网址未改变**：如果原始备份的网站网址和当前网站相同，转换过程会保留原始网址信息，还原时不需要 URL 替换

2. **转换日志**：转换过程会记录在插件日志中，如果转换失败，可以查看日志了解详情

3. **磁盘空间**：转换过程需要临时空间（约为备份文件大小的 2-3 倍），请确保有足够的磁盘空间

4. **转换时间**：大型备份文件的转换可能需要一些时间，请耐心等待

5. **失败处理**：如果自动转换失败，插件会尝试直接还原原始文件（某些格式可能兼容）

## 手动转换（高级用户）

如果你需要手动转换备份文件，可以使用命令行工具：

```php
require_once 'includes/class-ai1wm-converter.php';

$source = '/path/to/allinone-backup.zip';
$output = '/path/to/converted-backup.zip';

$result = Backup_Lite_AI1WM_Converter::convert( $source, $output );

if ( $result['success'] ) {
    echo "转换成功：" . $result['file'];
} else {
    echo "转换失败：" . $result['message'];
}
```

## 故障排除

### 问题：转换失败，提示"Database file not found"

**解决方案**：
- 确认备份文件中包含 `database.sql` 文件
- 检查文件结构是否符合支持的结构之一

### 问题：转换失败，提示"Unable to create temporary directory"

**解决方案**：
- 检查 `wp-content/uploads/museder-restoreone/temp/` 目录权限
- 确保有足够的磁盘空间

### 问题：转换成功但还原失败

**解决方案**：
- 查看插件日志文件了解详细错误
- 确认数据库文件格式正确
- 检查 wp-content 文件完整性

## 技术细节

### 转换后的文件结构

转换后的备份文件符合 Museder RestoreOne 标准格式：

```
converted-backup.zip
├── backup-lite-meta.json
├── database.sql
└── wp-content/
    ├── plugins/
    ├── themes/
    ├── uploads/
    └── ...
```

### 元数据文件

转换后会自动生成 `backup-lite-meta.json` 元数据文件，包含：

```json
{
    "plugin_version": "2.6.126",
    "wordpress_version": "unknown",
    "generated_at": "2025-01-XX...",
    "site_url": "https://example.com",
    "php_version": "7.4",
    "source_format": "ai1wm",
    "original_file": "backup.zip",
    "converted_at": "2025-01-XX...",
    "original_site_url": "https://original-site.com"
}
```

## 开发说明

转换类位于：`includes/class-ai1wm-converter.php`

主要方法：
- `is_ai1wm_backup()` - 检测是否为 All-in-One 备份
- `convert()` - 执行转换
- `reorganize_structure()` - 重组文件结构
- `create_metadata()` - 创建元数据文件

## 反馈

如果遇到问题或有改进建议，请联系开发团队。

