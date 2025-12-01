<?php
/**
 * Cloud Storage Settings Page
 * 
 * Settings page for Cloud Storage configuration.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cloud Storage Settings Page class.
 */
class Museder_Cloud_Settings_Page {

    const OPTION_KEY = 'backup_lite_s3_settings'; // @deprecated Use BACKUP_LITE_S3_SETTINGS_OPTION constant instead
    
    /**
     * Get S3 settings option key.
     * 
     * @return string
     */
    public static function get_option_key() {
        return defined( 'BACKUP_LITE_S3_SETTINGS_OPTION' ) ? BACKUP_LITE_S3_SETTINGS_OPTION : self::OPTION_KEY;
    }

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_post_backup_lite_s3_test_and_save', [ __CLASS__, 'handle_test_and_save' ] );
    }

    /**
     * Add menu page.
     */
    public static function add_menu_page() {
        add_submenu_page(
            'backup-lite-dashboard',
            __( 'Cloud Storage', 'museder-restoreone' ),
            __( 'Cloud Storage', 'museder-restoreone' ),
            'manage_options',
            'backup-lite-cloud',
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * Register settings using WordPress Settings API.
     */
    public static function register_settings() {
        $option_key = defined( 'BACKUP_LITE_S3_SETTINGS_OPTION' ) ? BACKUP_LITE_S3_SETTINGS_OPTION : self::OPTION_KEY;
        register_setting(
            'backup_lite_s3_settings_group',
            $option_key,
            [
                'type'              => 'array',
                'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
                'default'           => [
                    'enabled'               => false,
                    'mode'                  => 'simple',
                    'access_key_id'         => '',
                    'secret_access_key'     => '',
                    'region'                => 'ap-northeast-1',
                    'bucket'                => '',
                    'prefix'                => '',
                    'endpoint'              => '',
                    'use_path_style_endpoint' => false,
                ],
            ]
        );
    }

    /**
     * Sanitize settings.
     * 
     * @param array $input Raw input data.
     * @return array Sanitized settings.
     */
    public static function sanitize_settings( array $input ): array {
        $sanitized = [];
        $option_key = defined( 'BACKUP_LITE_S3_SETTINGS_OPTION' ) ? BACKUP_LITE_S3_SETTINGS_OPTION : self::OPTION_KEY;
        $existing = get_option( $option_key, [] );

        $sanitized['enabled'] = ! empty( $input['enabled'] );
        $sanitized['mode'] = isset( $input['mode'] ) && in_array( $input['mode'], [ 'simple', 'advanced' ], true )
            ? sanitize_text_field( $input['mode'] )
            : 'simple';
        $sanitized['access_key_id'] = isset( $input['access_key_id'] ) ? sanitize_text_field( $input['access_key_id'] ) : '';
        $sanitized['secret_access_key'] = isset( $input['secret_access_key'] ) && ! empty( $input['secret_access_key'] )
            ? sanitize_text_field( $input['secret_access_key'] )
            : ( isset( $existing['secret_access_key'] ) ? $existing['secret_access_key'] : '' );
        // Validate region: must be non-empty and from allowed list
        if ( isset( $input['region'] ) && ! empty( trim( $input['region'] ) ) ) {
            $region = sanitize_text_field( trim( $input['region'] ) );
            // Validate against known regions list if function exists
            if ( function_exists( 'backup_lite_get_s3_regions' ) ) {
                $regions = backup_lite_get_s3_regions();
                if ( isset( $regions[ $region ] ) ) {
                    $sanitized['region'] = $region;
                } else {
                    // Invalid region, use existing or default
                    $sanitized['region'] = isset( $existing['region'] ) && ! empty( $existing['region'] ) ? $existing['region'] : 'ap-northeast-1';
                }
            } else {
                $sanitized['region'] = $region;
            }
        } else {
            // No region provided, keep existing or use default
            $sanitized['region'] = isset( $existing['region'] ) && ! empty( $existing['region'] ) ? $existing['region'] : 'ap-northeast-1';
        }
        $sanitized['bucket'] = isset( $input['bucket'] ) ? sanitize_text_field( $input['bucket'] ) : '';
        $sanitized['prefix'] = isset( $input['prefix'] ) ? ltrim( sanitize_text_field( $input['prefix'] ), '/' ) : '';
        $sanitized['endpoint'] = isset( $input['endpoint'] ) && ! empty( $input['endpoint'] )
            ? esc_url_raw( $input['endpoint'] )
            : '';
        $sanitized['use_path_style_endpoint'] = ! empty( $input['use_path_style_endpoint'] );

        return $sanitized;
    }

    /**
     * Handle test and save action.
     * S3 cloud storage: Save settings first, then test connection.
     */
    public static function handle_test_and_save() {
        // (1) Permission and nonce check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage S3 settings.', 'museder-restoreone' ) );
        }

        check_admin_referer( 'backup_lite_s3_test_and_save' );

        // Check if a backup job is currently running
        $current_job = get_transient( 'backup_lite_current_job' );
        if ( $current_job && isset( $current_job['status'] ) && 'running' === $current_job['status'] ) {
            add_settings_error(
                'backup-lite',
                'backup_lite_job_running',
                esc_html__( 'A backup is currently running. Please try changing settings again after it completes.', 'museder-restoreone' ),
                'error'
            );
            // Do NOT update options if a job is running
            set_transient( 'settings_errors', get_settings_errors(), 30 );
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page' => 'backup-lite-cloud',
                        'settings-updated' => 'false',
                    ),
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        // (2) Get and sanitize form data
        $option_key = defined( 'BACKUP_LITE_S3_SETTINGS_OPTION' ) ? BACKUP_LITE_S3_SETTINGS_OPTION : self::OPTION_KEY;
        $existing = get_option( $option_key, [] );
        
        $new_settings = array(
            'enabled'               => isset( $_POST['backup_lite_s3_enabled'] ) ? ! empty( $_POST['backup_lite_s3_enabled'] ) : false,
            'mode'                  => isset( $_POST['backup_lite_s3_mode'] ) && in_array( $_POST['backup_lite_s3_mode'], [ 'simple', 'advanced' ], true )
                ? sanitize_text_field( wp_unslash( $_POST['backup_lite_s3_mode'] ) )
                : 'simple',
            'access_key_id'         => isset( $_POST['backup_lite_s3_access_key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_lite_s3_access_key_id'] ) ) : '',
            'secret_access_key'     => isset( $_POST['backup_lite_s3_secret_access_key'] ) && ! empty( $_POST['backup_lite_s3_secret_access_key'] )
                ? sanitize_text_field( wp_unslash( $_POST['backup_lite_s3_secret_access_key'] ) )
                : ( isset( $existing['secret_access_key'] ) ? $existing['secret_access_key'] : '' ),
            'region'                => isset( $_POST['backup_lite_s3_region'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_lite_s3_region'] ) ) : '',
            'bucket'                => isset( $_POST['backup_lite_s3_bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_lite_s3_bucket'] ) ) : '',
            'prefix'                => isset( $_POST['backup_lite_s3_folder_prefix'] ) ? ltrim( sanitize_text_field( wp_unslash( $_POST['backup_lite_s3_folder_prefix'] ) ), '/' ) : '',
            'endpoint'              => isset( $_POST['backup_lite_s3_endpoint'] ) && ! empty( $_POST['backup_lite_s3_endpoint'] )
                ? esc_url_raw( wp_unslash( $_POST['backup_lite_s3_endpoint'] ) )
                : '',
            'use_path_style_endpoint' => isset( $_POST['backup_lite_s3_use_path_style_endpoint'] ) ? ! empty( $_POST['backup_lite_s3_use_path_style_endpoint'] ) : false,
        );

        // (3) Save settings first (regardless of test result)
        update_option( $option_key, $new_settings, false );

        // (4) Test connection
        $test_result = null;
        if ( ! empty( $new_settings['access_key_id'] )
             && ! empty( $new_settings['secret_access_key'] )
             && ! empty( $new_settings['region'] )
             && ! empty( $new_settings['bucket'] )
        ) {
            if ( class_exists( 'Backup_Lite_S3_Service' ) ) {
                $test_result = Backup_Lite_S3_Service::test_connection( $new_settings );
            } else {
                $test_result = array(
                    'success' => false,
                    'message' => __( 'S3 service class not available.', 'museder-restoreone' ),
                );
            }
        } else {
            $test_result = array(
                'success' => false,
                'message' => __( 'S3 settings are incomplete. Please fill in all required fields.', 'museder-restoreone' ),
            );
        }

        // (5) Store result in transient for display after redirect
        if ( ! empty( $test_result['success'] ) ) {
            set_transient(
                'backup_lite_s3_test_result',
                array(
                    'type'    => 'success',
                    'message' => __( 'S3 connection test passed and settings have been saved.', 'museder-restoreone' ),
                ),
                30
            );
        } else {
            // Test failed: don't clear settings, just show error message
            $message = ! empty( $test_result['message'] ) ? $test_result['message'] : __( 'S3 connection test failed. Please double-check your credentials and bucket settings.', 'museder-restoreone' );
            
            // Sanitize error message to avoid exposing sensitive info
            if ( stripos( $message, 'secret' ) !== false || stripos( $message, 'key' ) !== false || stripos( $message, 'authorization' ) !== false ) {
                $message = __( 'S3 connection test failed. Please check your credentials and bucket settings.', 'museder-restoreone' );
            }
            
            set_transient(
                'backup_lite_s3_test_result',
                array(
                    'type'    => 'error',
                    'message' => $message,
                ),
                30
            );
            
            // Log error with additional context (detailed error info should already be logged in test_connection())
            // This log entry provides context that the failure occurred during settings save
            if ( function_exists( 'backup_lite_log' ) ) {
                $log_message = 'S3: test connection failed during settings save';
                // If test_result contains additional error info, append it (but sanitize first)
                if ( ! empty( $test_result['message'] ) ) {
                    $error_detail = $test_result['message'];
                    // Sanitize to avoid exposing sensitive info
                    $error_detail = preg_replace( '/secret[=\s:]+[^\s]+/i', 'secret=***', $error_detail );
                    $error_detail = preg_replace( '/key[=\s:]+[^\s]+/i', 'key=***', $error_detail );
                    $error_detail = preg_replace( '/authorization[=\s:]+[^\s]+/i', 'authorization=***', $error_detail );
                    $log_message .= sprintf( ' - user_message=%s', $error_detail );
                }
                backup_lite_log( 'error', $log_message );
            }
        }

        // Redirect back to settings page
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'backup-lite-cloud',
                    'settings-updated' => 'true',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Get list of S3 regions.
     *
     * @return array Associative array of region_code => label.
     */
    private static function get_s3_regions() {
        return array(
            'ap-east-2'      => 'Asia Pacific (Taipei) (ap-east-2)',
            'ap-east-1'      => 'Asia Pacific (Hong Kong) (ap-east-1)',
            'ap-south-1'     => 'Asia Pacific (Mumbai) (ap-south-1)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo) (ap-northeast-1)',
            'ap-northeast-2' => 'Asia Pacific (Seoul) (ap-northeast-2)',
            'ap-northeast-3' => 'Asia Pacific (Osaka) (ap-northeast-3)',
            'ap-southeast-1' => 'Asia Pacific (Singapore) (ap-southeast-1)',
            'ap-southeast-2' => 'Asia Pacific (Sydney) (ap-southeast-2)',
            'ap-southeast-4' => 'Asia Pacific (Melbourne) (ap-southeast-4)',
            'eu-central-1'   => 'Europe (Frankfurt) (eu-central-1)',
            'eu-west-1'      => 'Europe (Ireland) (eu-west-1)',
            'eu-west-2'      => 'Europe (London) (eu-west-2)',
            'us-east-1'       => 'US East (N. Virginia) (us-east-1)',
            'us-east-2'       => 'US East (Ohio) (us-east-2)',
            'us-west-1'       => 'US West (N. California) (us-west-1)',
            'us-west-2'       => 'US West (Oregon) (us-west-2)',
        );
    }

    /**
     * Render settings page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
        }

        // S3 cloud storage: quick setup UI - Display status notice
        $s3_ready = function_exists( 'backup_lite_is_s3_ready' ) && backup_lite_is_s3_ready();
        ?>
        <div class="wrap backup-lite-admin">
            <h1><?php esc_html_e( 'Cloud Storage', 'museder-restoreone' ); ?></h1>

            <?php
            // Display test result notice from transient
            $test_result = get_transient( 'backup_lite_s3_test_result' );
            if ( $test_result && is_array( $test_result ) ) {
                $notice_class = 'updated';
                if ( 'error' === $test_result['type'] ) {
                    $notice_class = 'error';
                } elseif ( 'success' === $test_result['type'] ) {
                    $notice_class = 'updated';
                }
                ?>
                <div class="notice notice-<?php echo esc_attr( $notice_class ); ?> is-dismissible">
                    <p><?php echo esc_html( $test_result['message'] ); ?></p>
                </div>
                <?php
                // Delete transient after displaying
                delete_transient( 'backup_lite_s3_test_result' );
            }
            
            // Also display standard settings errors if any
            if ( function_exists( 'settings_errors' ) ) {
                settings_errors();
            }
            ?>

            <?php if ( $s3_ready ) : ?>
                <div class="notice notice-success">
                    <p><?php esc_html_e( 'S3 cloud backups are enabled. You can choose to upload backups to S3 on the Backups page.', 'museder-restoreone' ); ?></p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'S3 cloud backups are currently disabled. Backups will only be stored locally until S3 is fully configured.', 'museder-restoreone' ); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // Get settings from option, no fake data
            $option_key = defined( 'BACKUP_LITE_S3_SETTINGS_OPTION' ) ? BACKUP_LITE_S3_SETTINGS_OPTION : self::OPTION_KEY;
            $settings = get_option( $option_key, array() );
            if ( ! is_array( $settings ) ) {
                $settings = array();
            }
            
            // Set defaults for display
            $enabled = isset( $settings['enabled'] ) ? (bool) $settings['enabled'] : false;
            $mode = isset( $settings['mode'] ) && in_array( $settings['mode'], [ 'simple', 'advanced' ], true ) ? $settings['mode'] : 'simple';
            $access_key_id = isset( $settings['access_key_id'] ) ? $settings['access_key_id'] : '';
            $region = isset( $settings['region'] ) ? $settings['region'] : '';
            $bucket = isset( $settings['bucket'] ) ? $settings['bucket'] : '';
            $prefix = isset( $settings['prefix'] ) ? $settings['prefix'] : '';
            $endpoint = isset( $settings['endpoint'] ) ? $settings['endpoint'] : '';
            $use_path_style_endpoint = isset( $settings['use_path_style_endpoint'] ) ? (bool) $settings['use_path_style_endpoint'] : false;
            ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'backup_lite_s3_test_and_save' ); ?>
                <input type="hidden" name="action" value="backup_lite_s3_test_and_save" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable S3 Cloud Backups', 'museder-restoreone' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="backup_lite_s3_enabled" value="1" <?php checked( $enabled ); ?> />
                                <?php esc_html_e( 'Enable S3 cloud backups', 'museder-restoreone' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Setup Mode', 'museder-restoreone' ); ?></th>
                        <td>
                            <label>
                                <input type="radio" name="backup_lite_s3_mode" value="simple" <?php checked( $mode, 'simple' ); ?> />
                                <?php esc_html_e( 'Simple setup (recommended)', 'museder-restoreone' ); ?>
                            </label>
                            <br />
                            <label>
                                <input type="radio" name="backup_lite_s3_mode" value="advanced" <?php checked( $mode, 'advanced' ); ?> />
                                <?php esc_html_e( 'Advanced S3 settings', 'museder-restoreone' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <div id="backup-lite-s3-simple-settings" style="<?php echo $mode === 'simple' ? '' : 'display: none;'; ?>">
                    <h2><?php esc_html_e( 'Simple Setup', 'museder-restoreone' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="s3_access_key_id"><?php esc_html_e( 'AWS Access Key ID', 'museder-restoreone' ); ?></label></th>
                            <td>
                                <input type="text" id="s3_access_key_id" name="backup_lite_s3_access_key_id" value="<?php echo esc_attr( $access_key_id ); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="s3_secret_access_key"><?php esc_html_e( 'AWS Secret Access Key', 'museder-restoreone' ); ?></label></th>
                            <td>
                                <input type="password" id="s3_secret_access_key" name="backup_lite_s3_secret_access_key" value="" class="regular-text" autocomplete="new-password" />
                                <p class="description"><?php esc_html_e( 'Leave blank to keep existing value.', 'museder-restoreone' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="s3_region"><?php esc_html_e( 'Region', 'museder-restoreone' ); ?></label></th>
                            <td>
                                <?php
                                $regions = self::get_s3_regions();
                                $selected_region = ! empty( $region ) ? $region : 'ap-northeast-1';
                                ?>
                                <select id="s3_region" name="backup_lite_s3_region" class="regular-text">
                                    <?php foreach ( $regions as $region_code => $region_label ) : ?>
                                        <option value="<?php echo esc_attr( $region_code ); ?>" <?php selected( $selected_region, $region_code ); ?>>
                                            <?php echo esc_html( $region_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="s3_bucket"><?php esc_html_e( 'Bucket Name', 'museder-restoreone' ); ?></label></th>
                            <td>
                                <input type="text" id="s3_bucket" name="backup_lite_s3_bucket" value="<?php echo esc_attr( $bucket ); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="s3_prefix"><?php esc_html_e( 'Folder prefix (optional)', 'museder-restoreone' ); ?></label></th>
                            <td>
                                <input type="text" id="s3_prefix" name="backup_lite_s3_folder_prefix" value="<?php echo esc_attr( $prefix ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., backups/mysite', 'museder-restoreone' ); ?>" />
                                <p class="description"><?php esc_html_e( 'Optional folder prefix for organizing backups in your bucket.', 'museder-restoreone' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="backup-lite-s3-advanced-settings" style="<?php echo $mode === 'advanced' ? '' : 'display: none;'; ?>">
                    <h2><?php esc_html_e( 'Advanced Settings', 'museder-restoreone' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="s3_endpoint"><?php esc_html_e( 'Custom endpoint (for S3-compatible providers)', 'museder-restoreone' ); ?></label></th>
                            <td>
                                <input type="url" id="s3_endpoint" name="backup_lite_s3_endpoint" value="<?php echo esc_attr( $endpoint ); ?>" class="regular-text" placeholder="https://s3.example.com" />
                                <p class="description"><?php esc_html_e( 'For S3-compatible storage providers (e.g., DigitalOcean Spaces, MinIO).', 'museder-restoreone' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Use path-style endpoint', 'museder-restoreone' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="backup_lite_s3_use_path_style_endpoint" value="1" <?php checked( $use_path_style_endpoint ); ?> />
                                    <?php esc_html_e( 'Use path-style endpoint (required for some S3-compatible providers)', 'museder-restoreone' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" name="backup_lite_s3_test_and_save" class="button button-primary">
                        <?php esc_html_e( 'Test & Save S3 Settings', 'museder-restoreone' ); ?>
                    </button>
                </p>
            </form>
        </div>

        <script>
        (function() {
            var modeRadios = document.querySelectorAll('input[name="backup_lite_s3_mode"]');
            var simpleDiv = document.getElementById('backup-lite-s3-simple-settings');
            var advancedDiv = document.getElementById('backup-lite-s3-advanced-settings');

            modeRadios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    if (this.value === 'simple') {
                        simpleDiv.style.display = '';
                        advancedDiv.style.display = 'none';
                    } else {
                        simpleDiv.style.display = 'none';
                        advancedDiv.style.display = '';
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
