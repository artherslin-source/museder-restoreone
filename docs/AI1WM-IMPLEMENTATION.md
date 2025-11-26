# All-in-One WP Migration 备份转换功能实现说明

## 概述

已完成的功能允许 Museder RestoreOne 插件自动检测和转换 All-in-One WP Migration 外挂生成的备份文件，使其可以在本插件中直接还原。

## 实现的功能

### 1. 自动检测
- 自动检测 `.zip` 和 `.wpress` 格式的 All-in-One 备份文件
- 通过文件结构特征识别 All-in-One 格式

### 2. 自动转换
- 在上传、选择现有备份或从 URL 下载时自动触发转换
- 将 All-in-One 格式转换为 Museder RestoreOne 标准格式

### 3. 转换过程
- 提取原始备份文件
- 重组文件结构为标准格式
- 创建元数据文件
- 生成新的备份 ZIP 文件

## 文件变更

### 新增文件

1. **`includes/class-ai1wm-converter.php`**
   - All-in-One WP Migration 备份转换器主类
   - 包含检测、转换、重组等功能

2. **`docs/AI1WM-CONVERSION.md`**
   - 用户使用指南
   - 说明如何转换和使用 All-in-One 备份

3. **`docs/AI1WM-IMPLEMENTATION.md`**
   - 技术实现说明（本文件）

### 修改文件

1. **`includes/class-restore-handler.php`**
   - 在 `upload()` 方法中添加自动转换逻辑
   - 在 `restore_from_backup()` 方法中添加自动转换逻辑
   - 在 `restore_remote()` 方法中添加自动转换逻辑

## 主要功能类

### Backup_Lite_AI1WM_Converter

主要方法：

- `is_ai1wm_backup( $file_path )` - 检测是否为 All-in-One 备份
- `convert( $source_file, $output_file )` - 执行转换
- `reorganize_structure( $source_dir, $target_dir )` - 重组文件结构
- `create_metadata( $dir, $source_file )` - 创建元数据文件

## 支持的结构

转换器支持以下 All-in-One 备份结构：

1. **直接结构**：`database.sql` + `plugins/`, `themes/`, `uploads/`
2. **restore-package 结构**：`restore-package/` 目录包含所有文件
3. **wp-content 结构**：包含 `wp-content/` 目录

## 转换流程

```
上传文件
  ↓
检测是否为 All-in-One 格式
  ↓ (是)
提取原始备份
  ↓
重组文件结构
  ├── 定位 database.sql
  ├── 重组 wp-content/ 目录
  └── 创建标准目录结构
  ↓
创建元数据文件
  ├── backup-lite-meta.json
  ├── 提取原始网站 URL
  └── 记录转换信息
  ↓
生成新备份文件
  ↓
删除原始文件（如果转换成功）
  ↓
使用转换后的文件进行还原
```

## 使用场景

### 场景 1：从 All-in-One 迁移到 Museder RestoreOne

用户之前使用 All-in-One WP Migration 创建备份，现在想改用 Museder RestoreOne：

1. 上传 All-in-One 备份文件
2. 插件自动检测并转换
3. 使用转换后的文件还原网站

### 场景 2：网站网址未改变

如果原始备份的网站网址与当前网站相同：
- 转换过程保留原始网址信息
- 还原时不需要 URL 替换
- 可以完整还原网站状态

## 技术细节

### 文件检测逻辑

```php
// 检查文件扩展名
if ( 'wpress' === $ext ) return true;

// 检查 ZIP 文件结构
if ( 'zip' === $ext ) {
    // 检查是否有 database.sql
    // 检查是否有 plugins/, themes/, uploads/ 目录
    // 检查是否有 restore-package/ 结构
}
```

### 文件重组逻辑

```php
// 定位数据库文件
$database_file = locate_database_file( $source_dir );

// 重组 wp-content 结构
if ( is_dir( 'wp-content' ) ) {
    copy_directly();
} elseif ( is_dir( 'restore-package/wp-content' ) ) {
    copy_from_restore_package();
} else {
    merge_individual_directories();
}
```

### 元数据提取

从数据库 SQL 文件中提取：
- 原始网站 URL
- WordPress 版本（如果可能）
- 其他元数据信息

## 错误处理

转换过程中的错误处理：

1. **转换失败**：保留原始文件，尝试直接还原
2. **文件缺失**：记录错误，返回详细错误信息
3. **磁盘空间不足**：提前检查，返回明确错误

## 日志记录

所有转换操作都会记录到插件日志中：

- 检测到 All-in-One 备份
- 转换成功/失败
- 转换过程中的详细信息

## 性能考虑

1. **大文件处理**：使用流式处理，避免内存溢出
2. **临时文件**：转换完成后自动清理
3. **磁盘空间**：转换前检查可用空间

## 未来改进

1. **.wpress 格式支持**：目前仅检测，未来将支持直接转换
2. **增量转换**：如果文件已经转换过，跳过转换过程
3. **批量转换**：支持一次转换多个备份文件
4. **转换进度显示**：为大文件转换添加进度条

## 测试建议

建议测试以下场景：

1. **标准结构**：包含 database.sql 和 wp-content/ 的备份
2. **restore-package 结构**：包含 restore-package/ 目录的备份
3. **直接结构**：plugins/, themes/, uploads/ 在根目录的备份
4. **大文件**：超过 500MB 的备份文件
5. **压缩数据库**：包含 database.sql.gz 的备份
6. **网站网址相同**：原始网址和当前网址相同的场景
7. **网站网址不同**：需要 URL 替换的场景

## 兼容性

- **WordPress 版本**：5.8+
- **PHP 版本**：7.4+
- **All-in-One WP Migration**：支持常见的备份格式

## 已知限制

1. **.wpress 格式**：目前仅检测，不支持自动转换
2. **加密备份**：不支持加密的 All-in-One 备份
3. **部分备份**：不支持仅包含数据库或仅包含文件的备份

## 总结

此功能实现了 All-in-One WP Migration 备份到 Museder RestoreOne 格式的自动转换，使用户可以无缝从其他备份插件迁移到本插件，同时保持备份的完整性和可用性。

