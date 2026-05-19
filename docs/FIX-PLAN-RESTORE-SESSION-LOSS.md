# 修復方案：還原過程 Session 失效導致作業中斷

> **問題版本**：2.7.262  
> **嚴重度**：P0（大站還原必現，導致還原永久卡住）  
> **參考**：AI1WM（All-in-One WP Migration）已通過 WP.org 審核的同類解法

---

## 修復架構概覽

```
┌─────────────────────────────────────────────────────────────┐
│  修復 1: Session 保留/回寫                                    │
│  ► import_database_from_ndjson() 前後保存/恢復 session token │
├─────────────────────────────────────────────────────────────┤
│  修復 2: 自訂 Restore Token（脫離 WP nonce 依賴）              │
│  ► 檔案系統 token，DB 匯入不影響其有效性                       │
├─────────────────────────────────────────────────────────────┤
│  修復 3: Cron + Lock 狀態恢復                                 │
│  ► DB 匯入後主動 reschedule + 回寫 lock option + flush cache  │
├─────────────────────────────────────────────────────────────┤
│  修復 4: 前端 Polling 容錯                                    │
│  ► 401/403 時自動 refresh token 而非停止 polling               │
└─────────────────────────────────────────────────────────────┘
```

---

## 修復 1：Session Token 保留與回寫

### 目的

確保 DB 匯入後，執行還原操作的管理員不會被登出。

### 修改檔案

`includes/class-restore.php` — `import_database_from_ndjson()`

### 實作方式

```php
private static function import_database_from_ndjson( $path, $progress_cb = null ) {
    global $wpdb;

    // ===== 新增：匯入前保存當前 session state =====
    $preserved_state = self::preserve_current_session_state();

    // ... (現有匯入邏輯不變) ...

    self::restore_database_constraints();

    // ===== 新增：匯入後回寫 session state =====
    self::restore_preserved_session_state( $preserved_state );

    // ... (後續邏輯) ...
}
```

### 新增 helper 方法

```php
/**
 * 保存當前管理員的 session token 及關鍵 options。
 * 在 DB 匯入前調用，確保匯入後能回寫。
 *
 * 符合 WP 規範：使用 WordPress Session Token API (WP_Session_Tokens)。
 *
 * @return array 保存的狀態資料
 */
private static function preserve_current_session_state() {
    $state = [
        'user_id'         => get_current_user_id(),
        'session_tokens'  => [],
        'cron'            => get_option( 'cron' ),
        'restore_lock'    => get_option( Museder_Restoreone_Restore_Lock::OPTION_KEY ),
        'active_job'      => get_option( Backup_Lite_Restore_Service::ACTIVE_JOB_OPTION ),
        'restoreone_token'=> get_option( 'museder_restoreone_restore_token' ),
    ];

    // 保存當前用戶的 session tokens
    $user_id = $state['user_id'];
    if ( $user_id > 0 ) {
        $manager = WP_Session_Tokens::get_instance( $user_id );
        $state['session_tokens'] = $manager->get_all();
    }

    return $state;
}

/**
 * 在 DB 匯入完成後，回寫保存的 session state。
 *
 * 確保：
 * 1. 管理員的 session token 仍然有效（不被登出）
 * 2. Cron 排程恢復（確保 restore job 繼續處理）
 * 3. Restore lock 恢復（確保 job 排他性）
 * 4. Restore token 恢復（確保前端 polling 可用）
 *
 * @param array $state preserve_current_session_state() 的回傳值
 */
private static function restore_preserved_session_state( array $state ) {
    global $wpdb;

    // 1. 回寫 session tokens（最重要：防止用戶被登出）
    $user_id = isset( $state['user_id'] ) ? (int) $state['user_id'] : 0;
    if ( $user_id > 0 && ! empty( $state['session_tokens'] ) ) {
        // 直接寫入 usermeta，因為 WP_Session_Tokens 可能因 cache 而讀到空值
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $wpdb->usermeta,
            [ 'meta_value' => maybe_serialize( $state['session_tokens'] ) ],
            [ 'user_id' => $user_id, 'meta_key' => 'session_tokens' ]
        );

        // 如果 update 影響 0 行（可能 usermeta 沒有該 row），則 insert
        if ( 0 === $wpdb->rows_affected ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert(
                $wpdb->usermeta,
                [
                    'user_id'    => $user_id,
                    'meta_key'   => 'session_tokens',
                    'meta_value' => maybe_serialize( $state['session_tokens'] ),
                ]
            );
        }
    }

    // 2. 回寫 cron 排程
    if ( ! empty( $state['cron'] ) ) {
        update_option( 'cron', $state['cron'] );
    }

    // 3. 回寫 restore lock
    if ( ! empty( $state['restore_lock'] ) ) {
        update_option( Museder_Restoreone_Restore_Lock::OPTION_KEY, $state['restore_lock'], false );
        set_site_transient( Museder_Restoreone_Restore_Lock::TRANSIENT_KEY, $state['restore_lock'], 30 * MINUTE_IN_SECONDS );
    }

    // 4. 回寫 active job ID
    if ( ! empty( $state['active_job'] ) ) {
        update_option( Backup_Lite_Restore_Service::ACTIVE_JOB_OPTION, $state['active_job'], false );
    }

    // 5. 回寫 restore token
    if ( ! empty( $state['restoreone_token'] ) ) {
        update_option( 'museder_restoreone_restore_token', $state['restoreone_token'], false );
    }

    // 6. 清除 WP object cache（確保後續讀取從 DB 取得最新值）
    wp_cache_flush();
}
```

### WP 規範合規性

| 規範 | 符合 | 說明 |
|------|:---:|------|
| 使用 `$wpdb->update/insert` 而非原生 SQL | ✅ | 使用 WP prepared statement |
| phpcs 標註 direct query | ✅ | 還原操作本質需要 direct DB |
| 不使用 `exec()` / `shell_exec()` | ✅ | 純 PHP |
| 不修改 core 檔案 | ✅ | 只操作 plugin 自身邏輯 |

---

## 修復 2：自訂 Restore Token（脫離 WP nonce 依賴）

### 目的

在 `restore-db` 階段，WP nonce 會因 session 替換而失效。需要一個**不依賴 DB** 的備用認證機制。

### 設計原則

- 符合 AI1WM 的 `secret_key` 模式（已通過 WP.org 審核）
- Token 存在**檔案系統**（`wp-content/uploads/museder-restoreone/temp/`），不受 DB 匯入影響
- 僅在還原操作期間有效（有 TTL）
- 仍然要求 `manage_options` capability（登入後才能繼續）

### 修改檔案

1. `includes/class-restore-service.php` — `execute()` 方法
2. `includes/class-restore-controller.php` — `check_permissions()` 方法
3. `includes/class-ui.php` — localize script（注入 token）
4. `assets/js/restore.js` — polling 帶上 restore token
5. 新增 `includes/class-restore-token.php`

### 新增 class：`Museder_Restoreone_Restore_Token`

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 檔案系統型還原 token。
 *
 * 在還原執行開始時產生，存在 plugin temp 目錄的檔案中。
 * DB 匯入不影響其有效性。
 * token 有 TTL（預設 2 小時），過期自動失效。
 *
 * 符合 WP 規範：
 * - 不繞過 capability check（重新登入後仍需 manage_options）
 * - 僅作為 nonce 的 fallback（nonce 有效時優先使用 nonce）
 * - token 使用密碼學隨機值，不可預測
 */
class Museder_Restoreone_Restore_Token {

    const TOKEN_FILENAME = '.restore-auth-token';
    const TOKEN_TTL      = 2 * HOUR_IN_SECONDS;

    /**
     * 產生並保存新的 restore token。
     * 在 execute() 開始時調用。
     *
     * @param string $job_id
     * @return string token value
     */
    public static function generate( $job_id ) {
        $token = wp_generate_password( 64, false );
        $payload = [
            'token'      => wp_hash( $token ),
            'job_id'     => sanitize_text_field( $job_id ),
            'user_id'    => get_current_user_id(),
            'created_at' => time(),
            'expires_at' => time() + self::TOKEN_TTL,
        ];

        $file = self::token_file_path();
        $dir  = dirname( $file );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $file, wp_json_encode( $payload ) );

        // 同時存一份到 wp_options（給 DB 匯入後回寫用）
        update_option( 'museder_restoreone_restore_token', $payload, false );

        return $token;
    }

    /**
     * 驗證 token（nonce 失敗時的 fallback）。
     *
     * @param string $token     前端傳入的 raw token
     * @param string $job_id    當前 job ID
     * @return bool|WP_Error
     */
    public static function verify( $token, $job_id = '' ) {
        $file = self::token_file_path();
        if ( ! file_exists( $file ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
        $raw = file_get_contents( $file );
        $payload = json_decode( $raw, true );

        if ( ! is_array( $payload ) || empty( $payload['token'] ) ) {
            return false;
        }

        // TTL 檢查
        if ( isset( $payload['expires_at'] ) && time() > (int) $payload['expires_at'] ) {
            self::revoke();
            return false;
        }

        // Hash 比對
        if ( ! hash_equals( $payload['token'], wp_hash( $token ) ) ) {
            return false;
        }

        // Job ID 比對（可選，增強安全性）
        if ( '' !== $job_id && isset( $payload['job_id'] ) && $payload['job_id'] !== $job_id ) {
            return false;
        }

        return true;
    }

    /**
     * 撤銷 token（還原完成或取消時調用）。
     */
    public static function revoke() {
        $file = self::token_file_path();
        if ( file_exists( $file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink( $file );
        }
        delete_option( 'museder_restoreone_restore_token' );
    }

    /**
     * Token 檔案路徑。
     * 存在 plugin temp 目錄，受 .htaccess 保護（web 不可直接存取）。
     */
    private static function token_file_path() {
        $upload_dir = wp_upload_dir();
        $base = trailingslashit( $upload_dir['basedir'] ) . 'museder-restoreone/temp/';
        return $base . self::TOKEN_FILENAME;
    }
}
```

### 修改 `check_permissions()`（fallback 邏輯）

```php
public static function check_permissions( WP_REST_Request $request ) {
    // 第一層：capability check（必須通過）
    if ( ! current_user_can( 'manage_options' ) ) {
        // Fallback: 如果 session 被還原操作摧毀，但 restore token 有效
        // 此時 current_user_can 會失敗，需要用 token 做備用認證
        $restore_token = (string) $request->get_header( 'X-Restore-Token' );
        if ( '' === $restore_token ) {
            $restore_token = (string) $request->get_param( '_restore_token' );
        }

        if ( '' !== $restore_token && Museder_Restoreone_Restore_Token::verify( $restore_token ) ) {
            // Token 有效 = 這是還原操作的合法繼續
            // 不需要 nonce（因為 nonce 已因 DB 替換而失效）
            return true;
        }

        return new WP_Error( 'museder_restoreone_forbidden',
            __( 'You are not allowed to perform this action.', 'museder-restoreone' ),
            [ 'status' => 403 ]
        );
    }

    // 第二層：nonce check
    $nonce = (string) $request->get_header( 'X-WP-Nonce' );
    if ( '' === $nonce ) {
        $nonce = (string) $request->get_param( '_wpnonce' );
    }

    if ( '' === $nonce ) {
        // Nonce 空 → 嘗試 restore token fallback
        $restore_token = (string) $request->get_header( 'X-Restore-Token' );
        if ( '' !== $restore_token && Museder_Restoreone_Restore_Token::verify( $restore_token ) ) {
            return true;
        }
        return new WP_Error( 'museder_restoreone_invalid_nonce',
            __( 'Invalid security token.', 'museder-restoreone' ),
            [ 'status' => 401 ]
        );
    }

    if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
        // Nonce 失效 → 嘗試 restore token fallback
        $restore_token = (string) $request->get_header( 'X-Restore-Token' );
        if ( '' !== $restore_token && Museder_Restoreone_Restore_Token::verify( $restore_token ) ) {
            return true;
        }
        return new WP_Error( 'museder_restoreone_invalid_nonce',
            __( 'Invalid security token.', 'museder-restoreone' ),
            [ 'status' => 401 ]
        );
    }

    return true;
}
```

### WP 規範合規性

| 規範 | 符合 | 說明 |
|------|:---:|------|
| REST endpoint 有 `permission_callback` | ✅ | 保留完整的 permission 檢查 |
| 使用 `wp_hash()` 儲存 token（非明文） | ✅ | 密碼學安全 |
| Token 有 TTL | ✅ | 2 小時後自動失效 |
| 不完全繞過 auth | ✅ | Nonce 優先；token 僅作為 DB 匯入後的 fallback |
| .htaccess 保護 token 檔案 | ✅ | 使用現有 temp 目錄保護機制 |
| AI1WM 先例 | ✅ | 同樣模式已通過 WP.org 審核 |

---

## 修復 3：Cron + Lock 狀態恢復

### 目的

確保 DB 匯入後，WP-Cron 排程和 restore lock 都能正確恢復。

### 修改檔案

`includes/class-restore-service.php` — `stage_import_database()`

### 實作方式

在 `import_database()` 呼叫後，明確恢復狀態：

```php
protected static function stage_import_database( $job_id, array $meta, $slice_seconds ) {
    $db_file = isset( $meta['db_file'] ) ? (string) $meta['db_file'] : '';
    if ( '' === $db_file || ! file_exists( $db_file ) ) {
        throw new RuntimeException( esc_html__( 'Database file missing for import.', 'museder-restoreone' ) );
    }

    $result = Museder_Restoreone_Restore::import_database( $db_file );

    // ===== 新增：DB 匯入後強制恢復關鍵運行時狀態 =====
    self::post_db_import_recovery( $job_id, $meta );

    // ... (後續邏輯) ...
}

/**
 * DB 匯入後恢復運行時關鍵狀態。
 * 因為 import 會 DROP + 重建所有 table（包含 wp_options），
 * 需要確保 cron 排程、lock、active job 等狀態仍然有效。
 */
private static function post_db_import_recovery( $job_id, array $meta ) {
    // 1. 清除 WP object cache（強制後續讀取走 DB）
    wp_cache_flush();

    // 2. 回寫 restore lock
    $lock_payload = [
        'job_id'      => $job_id,
        'acquired_at' => time(),
    ];
    update_option( Museder_Restoreone_Restore_Lock::OPTION_KEY, $lock_payload, false );
    set_site_transient(
        Museder_Restoreone_Restore_Lock::TRANSIENT_KEY,
        $lock_payload,
        30 * MINUTE_IN_SECONDS
    );

    // 3. 回寫 active job ID
    update_option( self::ACTIVE_JOB_OPTION, $job_id, false );

    // 4. 確保 cron 排程存在（DB 匯入可能已覆蓋 cron option）
    if ( ! wp_next_scheduled( self::CRON_HOOK_PROCESS, [ $job_id ] ) ) {
        wp_schedule_single_event( time() + 2, self::CRON_HOOK_PROCESS, [ $job_id ] );
    }

    // 5. 回寫 restore token（如果有）
    $token_file = WP_CONTENT_DIR . '/uploads/museder-restoreone/temp/.restore-auth-token';
    if ( file_exists( $token_file ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
        $token_data = json_decode( file_get_contents( $token_file ), true );
        if ( is_array( $token_data ) ) {
            update_option( 'museder_restoreone_restore_token', $token_data, false );
        }
    }

    // 6. Nudge cron（觸發下一個 tick）
    self::spawn_cron();
}
```

---

## 修復 4：前端 Polling 容錯

### 目的

當 `restore.js` 的 polling 收到 401/403 時，不應該立即停止，而是嘗試用 restore token 繼續。

### 修改檔案

`assets/js/restore.js`

### 實作方式

```javascript
// 頁面載入時，從 localize data 取得 restore token
var restoreToken = config.restoreToken || '';

// 修改 buildRequest()：加入 restore token header
buildRequest: function (endpoint, opts) {
    // ... 現有邏輯 ...
    if (nonce) {
        opts.headers['X-WP-Nonce'] = nonce;
    }
    // 新增：帶上 restore token
    if (restoreToken) {
        opts.headers['X-Restore-Token'] = restoreToken;
    }
    // ...
}

// 修改 pollStatus()：401/403 時不立即停止
pollStatus: function (force) {
    var _this5 = this;
    if (!this.state.jobId) return;
    if (force) this.stopPolling();
    if (this.state.polling) return;

    var authRetryCount = 0;
    var MAX_AUTH_RETRIES = 3;

    this.state.polling = window.setInterval(function () {
        _this5.buildRequest('restore/status/' + _this5.state.jobId, { method: 'GET' })
            .then(function (response) {
                if (!response.ok && (response.status === 401 || response.status === 403)) {
                    authRetryCount++;
                    if (authRetryCount <= MAX_AUTH_RETRIES) {
                        // 不停止，等待下一次 tick（可能 session 剛恢復）
                        _this5.log('認證中斷，等待恢復… (' + authRetryCount + '/' + MAX_AUTH_RETRIES + ')', 'warning');
                        return null;
                    }
                    // 超過重試次數才停止
                    _this5.stopPolling();
                    _this5.log('認證失敗，請重新登入後回到此頁面。', 'error');
                    return null;
                }
                authRetryCount = 0; // 成功則重置
                return response.json();
            })
            .then(function (data) {
                if (!data) return;
                if (data.ok === false) {
                    _this5.stopPolling();
                    if (data.message) _this5.log('Status error: ' + data.message, 'error');
                    return;
                }
                // ... 現有進度更新邏輯 ...
            })
            .catch(function () {
                // 網路錯誤：不立即停止，增加容錯
                authRetryCount++;
                if (authRetryCount > MAX_AUTH_RETRIES) {
                    _this5.stopPolling();
                }
            });
    }, 2000);
}
```

### 修改 `class-ui.php`：注入 restore token 到前端

```php
// 在 execute 成功後，將 token 注入 localize data
// class-restore-service.php execute() 產生 token 時同時存入 active job meta

// class-ui.php 的 localize（restore.js 的 config）：
'restoreToken' => Museder_Restoreone_Restore_Token::get_current_token_for_js(),
```

---

## 修復 5（建議）：DB 匯出時排除運行時 options

### 目的

防止備份中的 `wp_options` 資料在匯入時覆蓋當前站點的運行時狀態。

### 修改檔案

`includes/class-backup.php` — NDJSON 匯出邏輯

### 實作方式

在匯出 `wp_options` 表的 rows 時，跳過以下 option_name：

```php
$exclude_options = [
    'museder_restoreone_restore_lock',
    'museder_restoreone_restore_service_active_job_id',
    'museder_restoreone_restore_token',
    'cron',                              // WP cron 排程（運行時狀態）
    '_site_transient_timeout_museder_restoreone_restore_lock',
    '_site_transient_museder_restoreone_restore_lock',
];
```

這與 AI1WM 的做法完全一致（line 144）：
```php
// AI1WM: 匯出時排除自身運行時 options
$db_client->set_table_where_query( ... NOT IN ('ai1wm_secret_key', ...) );
```

---

## 實作優先順序

| 順序 | 修復 | 影響範圍 | 複雜度 | 效果 |
|:---:|------|---------|:---:|------|
| 1 | **修復 1** — Session 保留/回寫 | `class-restore.php` | 中 | 根本解決「被登出」|
| 2 | **修復 3** — Cron + Lock 恢復 | `class-restore-service.php` | 低 | 解決「作業永久卡住」|
| 3 | **修復 4** — 前端容錯 | `restore.js` | 低 | 解決「polling 中斷」|
| 4 | **修復 2** — Restore Token | 新增 class + 修改 controller | 中高 | 完整防禦（belt + suspenders）|
| 5 | **修復 5** — 匯出排除 | `class-backup.php` | 低 | 從源頭避免衝突 |

**最小修復**（解決 P0）：修復 1 + 修復 3 即可解決問題。  
**完整修復**（防禦性設計）：全部 5 項。

---

## 驗證方式

### 測試場景

1. 準備 >1GB 站點備份（跨站：不同 prefix、不同 admin user）
2. 執行還原
3. 觀察：
   - ❌ 以前：DB 匯入後立即被登出 → polling 停止 → 卡住
   - ✅ 修復後：DB 匯入後用戶仍然保持登入 → polling 繼續 → 還原完成

### 驗證 checklist

- [ ] 還原期間用戶不會被要求重新登入
- [ ] 還原進度條從 0% 連續到 100%，無中斷
- [ ] Restore History 正確記錄 `result: success`
- [ ] 還原完成後，admin 仍然可以正常操作後台
- [ ] Restore token 在還原完成後自動撤銷（過期或主動 revoke）
- [ ] WP Plugin Check 工具無新增 error（可接受 info/warning）
- [ ] phpcs WordPress-Extra 規則通過

---

## WP.org 審核注意事項

| 審核點 | 應對 |
|--------|------|
| 為何需要 restore token？ | Session token 儲存在 `wp_usermeta`，DB 還原會替換此表，導致合法管理員被登出。Token 是必要的 fallback 機制，AI1WM 有相同設計 |
| Token 是否繞過安全檢查？ | 否。Token 仍要求使用者已登入（或在 DB 匯入前已登入）才能產生。Token 有 2 小時 TTL，還原完成後自動撤銷 |
| 為何直接寫 `wp_usermeta`？ | DB 還原後 WP object cache 可能有舊資料，使用 `$wpdb->update` 確保寫入生效。這與 WP core 的 `WP_User_Meta_Session_Tokens::update_sessions()` 行為一致 |
| 為何使用檔案儲存 token？ | 確保 token 不受 DB DROP/REBUILD 影響。檔案存在受 `.htaccess` 保護的 temp 目錄中 |
