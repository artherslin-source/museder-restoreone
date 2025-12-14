# 備份與還原性能優化建議

本文檔提供符合 WordPress 規範的性能優化建議，用於提升備份和還原速度。

## 一、備份優化

### 1.1 文件遍歷優化

**當前問題：**
- 使用 `RecursiveIteratorIterator` 順序遍歷所有文件
- 每次備份都重新掃描整個目錄結構
- 沒有緩存機制

**優化建議：**

#### A. 實現文件列表緩存
```php
/**
 * 緩存文件列表，避免重複遍歷
 * 使用 WordPress Transients API（符合 WP 規範）
 */
private static function get_cached_file_manifest( $directories, $cache_key ) {
    $cached = get_transient( $cache_key );
    
    // 如果緩存存在且未過期（5分鐘內），直接使用
    if ( false !== $cached && isset( $cached['timestamp'] ) ) {
        $age = time() - $cached['timestamp'];
        if ( $age < 300 ) { // 5分鐘內有效
            return $cached['manifest'];
        }
    }
    
    // 重新掃描並緩存
    $manifest = self::build_manifest_from_directories( $directories );
    set_transient( $cache_key, [
        'manifest' => $manifest,
        'timestamp' => time(),
    ], 600 ); // 10分鐘過期
    
    return $manifest;
}
```

#### B. 使用更高效的迭代器選項
```php
// 優化：使用 FOLLOW_SYMLINKS 和 CATCH_GET_CHILD 提升性能
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $source,
        FilesystemIterator::SKIP_DOTS | 
        FilesystemIterator::FOLLOW_SYMLINKS |
        FilesystemIterator::CATCH_GET_CHILD
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);
```

### 1.2 批次處理優化

**當前設置：**
- `max_files = 200`
- `max_bytes = 52428800` (50MB)

**優化建議：**

#### A. 動態調整批次大小
```php
/**
 * 根據系統資源動態調整批次大小
 * 符合 WordPress 規範：使用 wp_is_writable 和 wp_raise_memory_limit
 */
private static function get_optimal_batch_size() {
    $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
    $available_memory = $memory_limit - memory_get_usage( true );
    
    // 根據可用內存調整批次大小
    if ( $available_memory > 512 * 1024 * 1024 ) { // > 512MB
        return [
            'max_files' => 500,
            'max_bytes' => 104857600, // 100MB
        ];
    } elseif ( $available_memory > 256 * 1024 * 1024 ) { // > 256MB
        return [
            'max_files' => 300,
            'max_bytes' => 73400320, // 70MB
        ];
    }
    
    // 默認值（較小內存環境）
    return [
        'max_files' => 200,
        'max_bytes' => 52428800, // 50MB
    ];
}
```

#### B. 優先處理小文件
```php
/**
 * 優先處理小文件，提升整體速度
 * 大文件單獨處理，避免阻塞
 */
private static function sort_manifest_by_size( $manifest ) {
    usort( $manifest, function( $a, $b ) {
        $size_a = isset( $a['size'] ) ? (int) $a['size'] : 0;
        $size_b = isset( $b['size'] ) ? (int) $b['size'] : 0;
        return $size_a <=> $size_b; // 小文件優先
    });
    return $manifest;
}
```

### 1.3 ZipArchive 壓縮優化

**優化建議：**

#### A. 調整壓縮級別
```php
/**
 * 使用較低的壓縮級別以提升速度
 * ZipArchive::CM_STORE = 不壓縮（最快）
 * ZipArchive::CM_DEFLATE = 標準壓縮（平衡）
 */
private static function add_file_to_zip_optimized( $zip, $file_path, $archive_path ) {
    // 對於大文件（> 10MB），使用不壓縮以提升速度
    $file_size = filesize( $file_path );
    $compression = ( $file_size > 10485760 ) 
        ? ZipArchive::CM_STORE 
        : ZipArchive::CM_DEFLATE;
    
    $zip->addFile( $file_path, $archive_path );
    $zip->setCompressionName( $archive_path, $compression );
}
```

#### B. 使用 addFile 而非 addFromString
```php
// ✅ 優化：直接添加文件，避免加載到內存
$zip->addFile( $file_path, $archive_path );

// ❌ 避免：會將整個文件加載到內存
// $zip->addFromString( $archive_path, file_get_contents( $file_path ) );
```

### 1.4 資料庫導出優化

**優化建議：**

#### A. 使用 mysqldump 優化選項
```php
/**
 * 優化 mysqldump 命令參數
 * 符合 WordPress 規範：使用 escapeshellarg
 */
private static function export_database_with_mysqldump_optimized( $filepath ) {
    $db_user = escapeshellarg( DB_USER );
    $db_pass = escapeshellarg( DB_PASSWORD );
    $db_name = escapeshellarg( DB_NAME );
    $db_host = escapeshellarg( DB_HOST );
    $filepath_escaped = escapeshellarg( $filepath );
    
    // 優化選項：
    // --single-transaction: 保證一致性，不鎖表
    // --quick: 逐行導出，減少內存使用
    // --lock-tables=false: 不鎖表（配合 --single-transaction）
    // --skip-extended-insert: 單行插入，更安全但稍慢
    // --skip-comments: 跳過註釋，減少文件大小
    
    $command = sprintf(
        'mysqldump --single-transaction --quick --lock-tables=false --skip-comments -h %s -u %s -p%s %s > %s 2>&1',
        $db_host,
        $db_user,
        $db_pass,
        $db_name,
        $filepath_escaped
    );
    
    // 使用 WordPress 的 exec 替代方案（如果可用）
    // 或使用 wp_shell_exec（如果實現了）
}
```

#### B. PHP 導出優化（流式處理）
```php
/**
 * 使用流式處理，避免一次性加載所有數據到內存
 */
private static function export_database_with_php_optimized( $filepath ) {
    global $wpdb;
    
    // 使用 fopen + fwrite 而非 file_put_contents
    $handle = fopen( $filepath, 'wb' );
    if ( ! $handle ) {
        return false;
    }
    
    // 寫入 SQL 頭部
    fwrite( $handle, "-- WordPress Database Backup\n" );
    fwrite( $handle, "-- Generated: " . current_time( 'mysql' ) . "\n\n" );
    
    // 獲取所有表
    $tables = $wpdb->get_col( "SHOW TABLES" );
    
    foreach ( $tables as $table ) {
        // 獲取表結構
        $create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        fwrite( $handle, "\nDROP TABLE IF EXISTS `{$table}`;\n" );
        fwrite( $handle, $create_table[1] . ";\n\n" );
        
        // 分批獲取數據（避免內存溢出）
        $offset = 0;
        $batch_size = 1000;
        
        while ( true ) {
            $rows = $wpdb->get_results( 
                $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset ),
                ARRAY_A
            );
            
            if ( empty( $rows ) ) {
                break;
            }
            
            foreach ( $rows as $row ) {
                // 構建 INSERT 語句並寫入
                $values = array_map( function( $value ) use ( $wpdb ) {
                    return $wpdb->_real_escape( $value );
                }, array_values( $row ) );
                
                fwrite( $handle, sprintf(
                    "INSERT INTO `{$table}` VALUES (%s);\n",
                    "'" . implode( "','", $values ) . "'"
                ) );
            }
            
            $offset += $batch_size;
            
            // 定期刷新緩衝區
            if ( $offset % 10000 === 0 ) {
                fflush( $handle );
            }
        }
    }
    
    fclose( $handle );
    return true;
}
```

## 二、還原優化

### 2.1 檔案提取優化

**優化建議：**

#### A. 使用 ZipArchive::RDONLY 模式
```php
/**
 * 只讀模式打開，提升提取速度
 */
private static function extract_archive_optimized( $archive_file, $temp_dir ) {
    $zip = new ZipArchive();
    
    // 使用 RDONLY 模式，避免不必要的檢查
    if ( true !== $zip->open( $archive_file, ZipArchive::RDONLY ) ) {
        return false;
    }
    
    // 批量提取，而非逐個提取
    $extract_result = $zip->extractTo( $temp_dir );
    $zip->close();
    
    return $extract_result;
}
```

#### B. 並行提取大文件
```php
/**
 * 對於大文件，考慮使用並行提取
 * 注意：需要確保線程安全
 */
private static function extract_large_files_parallel( $zip, $temp_dir, $large_files ) {
    // 使用 WordPress 的 wp_schedule_event 或 spawn_cron
    // 但要注意：WordPress cron 是單線程的，真正的並行需要其他方案
    
    // 替代方案：優先提取小文件，大文件最後處理
    $small_files = [];
    $large_files_list = [];
    
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $stat = $zip->statIndex( $i );
        if ( $stat['size'] > 10485760 ) { // > 10MB
            $large_files_list[] = $stat['name'];
        } else {
            $small_files[] = $stat['name'];
        }
    }
    
    // 先提取小文件
    foreach ( $small_files as $file ) {
        $zip->extractTo( $temp_dir, $file );
    }
    
    // 再提取大文件
    foreach ( $large_files_list as $file ) {
        $zip->extractTo( $temp_dir, $file );
    }
}
```

### 2.2 資料庫導入優化

**優化建議：**

#### A. 使用 mysql CLI 優化選項
```php
/**
 * 優化 mysql 命令參數
 */
private static function import_database_with_cli_optimized( $sql_file ) {
    $db_user = escapeshellarg( DB_USER );
    $db_pass = escapeshellarg( DB_PASSWORD );
    $db_name = escapeshellarg( DB_NAME );
    $db_host = escapeshellarg( DB_HOST );
    $filepath_escaped = escapeshellarg( $sql_file );
    
    // 優化選項：
    // --default-character-set=utf8mb4: 指定字符集
    // --max_allowed_packet=256M: 增加包大小
    // --single-transaction: 事務模式
    // --quick: 逐行處理
    
    $command = sprintf(
        'mysql --default-character-set=utf8mb4 --max_allowed_packet=256M --single-transaction --quick -h %s -u %s -p%s %s < %s 2>&1',
        $db_host,
        $db_user,
        $db_pass,
        $db_name,
        $filepath_escaped
    );
    
    // 執行命令...
}
```

#### B. PHP 導入優化（分批執行）
```php
/**
 * 分批執行 SQL 語句，避免內存溢出
 */
private static function import_database_with_php_optimized( $sql_file, $progress_cb = null ) {
    global $wpdb;
    
    $handle = fopen( $sql_file, 'rb' );
    if ( ! $handle ) {
        return [ 'success' => false ];
    }
    
    $query = '';
    $line_number = 0;
    $executed_queries = 0;
    
    // 禁用自動提交，提升性能
    $wpdb->query( 'SET autocommit = 0' );
    $wpdb->query( 'START TRANSACTION' );
    
    while ( ( $line = fgets( $handle ) ) !== false ) {
        $line_number++;
        $line = trim( $line );
        
        // 跳過註釋和空行
        if ( empty( $line ) || strpos( $line, '--' ) === 0 || strpos( $line, '/*' ) === 0 ) {
            continue;
        }
        
        $query .= $line;
        
        // 檢查是否為完整語句（以分號結尾）
        if ( substr( $query, -1 ) === ';' ) {
            // 執行查詢
            $result = $wpdb->query( $query );
            
            if ( false === $result ) {
                $wpdb->query( 'ROLLBACK' );
                fclose( $handle );
                return [
                    'success' => false,
                    'line' => $line_number,
                    'error' => $wpdb->last_error,
                ];
            }
            
            $executed_queries++;
            $query = '';
            
            // 每 1000 條查詢提交一次，避免事務過大
            if ( $executed_queries % 1000 === 0 ) {
                $wpdb->query( 'COMMIT' );
                $wpdb->query( 'START TRANSACTION' );
                
                if ( is_callable( $progress_cb ) ) {
                    $progress = min( 90, 50 + ( $executed_queries / 10000 ) * 40 );
                    call_user_func( $progress_cb, $progress, sprintf( 
                        __( 'Imported %d queries…', 'museder-restoreone' ), 
                        $executed_queries 
                    ) );
                }
            }
        }
    }
    
    // 提交最後的事務
    $wpdb->query( 'COMMIT' );
    $wpdb->query( 'SET autocommit = 1' );
    
    fclose( $handle );
    return [ 'success' => true, 'queries' => $executed_queries ];
}
```

### 2.3 文件還原優化

**優化建議：**

#### A. 批量文件操作
```php
/**
 * 使用批量操作減少系統調用
 */
private static function restore_files_batch( $source_dir, $target_dir, $files ) {
    // 分組文件，按目標目錄批量處理
    $files_by_dir = [];
    
    foreach ( $files as $file ) {
        $target_path = $target_dir . '/' . $file;
        $dir = dirname( $target_path );
        
        if ( ! isset( $files_by_dir[ $dir ] ) ) {
            $files_by_dir[ $dir ] = [];
        }
        
        $files_by_dir[ $dir ][] = $file;
    }
    
    // 按目錄批量處理
    foreach ( $files_by_dir as $dir => $dir_files ) {
        // 確保目錄存在
        wp_mkdir_p( $dir );
        
        // 批量複製文件
        foreach ( $dir_files as $file ) {
            $source = $source_dir . '/' . $file;
            $target = $target_dir . '/' . $file;
            
            if ( file_exists( $source ) ) {
                copy( $source, $target );
            }
        }
    }
}
```

#### B. 使用 WordPress 文件系統 API
```php
/**
 * 使用 WP_Filesystem，符合 WordPress 規範
 */
private static function restore_files_with_wp_filesystem( $source_dir, $target_dir ) {
    global $wp_filesystem;
    
    if ( empty( $wp_filesystem ) ) {
        require_once ABSPATH . '/wp-admin/includes/file.php';
        WP_Filesystem();
    }
    
    if ( ! $wp_filesystem ) {
        return false;
    }
    
    // 使用 WP_Filesystem 的批量操作
    $files = $wp_filesystem->dirlist( $source_dir, true, false );
    
    foreach ( $files as $file => $fileinfo ) {
        if ( 'f' === $fileinfo['type'] ) {
            $source = trailingslashit( $source_dir ) . $file;
            $target = trailingslashit( $target_dir ) . $file;
            
            $wp_filesystem->copy( $source, $target, true );
        }
    }
    
    return true;
}
```

## 三、通用優化

### 3.1 內存優化

**優化建議：**

#### A. 及時釋放內存
```php
/**
 * 在處理大文件後及時釋放內存
 */
private static function process_file_with_memory_management( $file_path ) {
    // 處理文件
    $content = file_get_contents( $file_path );
    // ... 處理邏輯 ...
    
    // 及時釋放
    unset( $content );
    
    // 強制垃圾回收（僅在必要時使用）
    if ( function_exists( 'gc_collect_cycles' ) ) {
        gc_collect_cycles();
    }
}
```

#### B. 使用 WordPress 內存管理
```php
/**
 * 使用 WordPress 的內存管理函數
 */
private static function optimize_memory() {
    // 使用 WordPress 標準函數
    if ( function_exists( 'wp_raise_memory_limit' ) ) {
        wp_raise_memory_limit( 'admin' );
        wp_raise_memory_limit( 'media' );
    }
    
    // 檢查當前內存使用
    $memory_usage = memory_get_usage( true );
    $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
    
    // 如果使用超過 80%，記錄警告
    if ( $memory_usage > $memory_limit * 0.8 ) {
        backup_lite_log( 'warning', 'High memory usage detected.', [
            'usage' => size_format( $memory_usage ),
            'limit' => size_format( $memory_limit ),
        ] );
    }
}
```

### 3.2 I/O 優化

**優化建議：**

#### A. 使用緩衝區
```php
/**
 * 使用輸出緩衝區減少 I/O 操作
 */
private static function write_with_buffer( $file_path, $data ) {
    $handle = fopen( $file_path, 'wb' );
    if ( ! $handle ) {
        return false;
    }
    
    // 設置緩衝區大小（64KB）
    if ( function_exists( 'stream_set_write_buffer' ) ) {
        stream_set_write_buffer( $handle, 65536 );
    }
    
    fwrite( $handle, $data );
    fclose( $handle );
    
    return true;
}
```

#### B. 減少文件系統調用
```php
/**
 * 緩存文件狀態，減少 stat 調用
 */
private static $file_stat_cache = [];

private static function get_file_stat_cached( $file_path ) {
    if ( ! isset( self::$file_stat_cache[ $file_path ] ) ) {
        self::$file_stat_cache[ $file_path ] = [
            'exists' => file_exists( $file_path ),
            'size' => file_exists( $file_path ) ? filesize( $file_path ) : 0,
            'mtime' => file_exists( $file_path ) ? filemtime( $file_path ) : 0,
        ];
    }
    
    return self::$file_stat_cache[ $file_path ];
}
```

### 3.3 進度追蹤優化

**優化建議：**

#### A. 減少進度更新頻率
```php
/**
 * 減少進度更新頻率，避免過多數據庫寫入
 */
private static function update_progress_optimized( $job_id, $progress, $message ) {
    static $last_update = 0;
    static $last_progress = 0;
    
    $now = time();
    $progress_diff = abs( $progress - $last_progress );
    
    // 只在進度變化 > 1% 或距離上次更新 > 2 秒時更新
    if ( $progress_diff > 1 || ( $now - $last_update ) > 2 ) {
        self::write_job_meta( $job_id, [
            'progress' => $progress,
            'message' => $message,
        ] );
        
        $last_update = $now;
        $last_progress = $progress;
    }
}
```

## 四、實施優先級

### 高優先級（立即實施）
1. ✅ 動態調整批次大小
2. ✅ 優化資料庫導出/導入（使用 CLI 優化選項）
3. ✅ 減少進度更新頻率
4. ✅ 使用 ZipArchive::CM_STORE 處理大文件

### 中優先級（短期實施）
1. ⚠️ 實現文件列表緩存
2. ⚠️ 優化文件遍歷（使用更高效的迭代器選項）
3. ⚠️ 分批執行 SQL 導入
4. ⚠️ 使用 WP_Filesystem API

### 低優先級（長期優化）
1. 📋 實現並行處理（需要更複雜的架構）
2. 📋 實現智能文件過濾（提前跳過不需要的文件）
3. 📋 實現增量備份（僅備份變更的文件）

## 五、注意事項

1. **符合 WordPress 規範**：
   - 使用 `wp_raise_memory_limit()` 而非直接 `ini_set()`
   - 使用 `escapeshellarg()` 處理 shell 命令參數
   - 使用 `wp_mkdir_p()` 創建目錄
   - 使用 `WP_Filesystem` API 進行文件操作

2. **向後兼容**：
   - 所有優化都應該是可選的，有 fallback 機制
   - 不破壞現有功能

3. **錯誤處理**：
   - 所有優化都應該有完整的錯誤處理
   - 記錄性能指標，便於調試

4. **測試**：
   - 在不同環境下測試（不同 PHP 版本、不同內存限制）
   - 測試大文件、大量文件的處理

## 六、性能監控

建議添加性能監控點：

```php
/**
 * 記錄性能指標
 */
private static function log_performance_metrics( $operation, $start_time, $end_time, $data = [] ) {
    $duration = $end_time - $start_time;
    $memory_peak = memory_get_peak_usage( true );
    
    backup_lite_log( 'info', "Performance: {$operation}", array_merge( [
        'duration_seconds' => round( $duration, 2 ),
        'memory_peak' => size_format( $memory_peak ),
    ], $data ) );
}
```

## 七、參考資源

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [ZipArchive Documentation](https://www.php.net/manual/en/class.ziparchive.php)
- [WordPress Filesystem API](https://developer.wordpress.org/reference/classes/wp_filesystem_base/)
- [MySQL Optimization](https://dev.mysql.com/doc/refman/8.0/en/optimization.html)

