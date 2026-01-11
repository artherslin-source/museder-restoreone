<?php
/**
 * Global settings handler for Backup Lite.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Settings {

    const OPTION_KEY = 'backup_lite_options';
    const LEGACY_OPTION_KEY = 'backup_lite_settings';

    /**
     * Initialise hooks.
     */
    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_init', [ __CLASS__, 'maybe_migrate_legacy_options' ] );
        add_action( 'wp_ajax_backup_lite_save_settings', [ __CLASS__, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_backup_lite_fetch_settings', [ __CLASS__, 'ajax_fetch_settings' ] );
        add_action( 'wp_ajax_backup_lite_verify_license', [ __CLASS__, 'ajax_verify_license' ] );
    }

    /**
     * Registers option for sanitisation.
     */
    public static function register_settings() {
        register_setting( 'backup_lite_settings_group', self::OPTION_KEY, [ __CLASS__, 'sanitize' ] );
    }

    /**
     * Ensure legacy option is migrated to the consolidated option.
     */
    public static function maybe_migrate_legacy_options() {
        $legacy = get_option( self::LEGACY_OPTION_KEY, null );
        $current = get_option( self::OPTION_KEY, null );

        if ( null === $legacy || ! is_array( $legacy ) || ! empty( $current ) ) {
            return;
        }

        $mapped = [
            'backup_directory'   => isset( $legacy['backup_dir'] ) ? $legacy['backup_dir'] : backup_lite_get_backup_dir(),
            'notification_email' => isset( $legacy['notify_email'] ) ? $legacy['notify_email'] : get_option( 'admin_email' ),
            'min_role'           => isset( $legacy['role'] ) ? $legacy['role'] : 'administrator',
        ];

        update_option( self::OPTION_KEY, self::sanitize( $mapped ) );
    }

    /**
     * Sanitise settings array.
     *
     * @param array $value Raw input.
     * @return array
     */
    public static function sanitize( $value ) {
        $value = is_array( $value ) ? $value : [];

        $roles        = self::get_available_roles();
        $default_role = 'administrator';
        $requested_role = isset( $value['min_role'] ) ? sanitize_key( $value['min_role'] ) : $default_role;
        if ( ! array_key_exists( $requested_role, $roles ) ) {
            $requested_role = $default_role;
        }

        $backup_directory = isset( $value['backup_directory'] ) ? sanitize_text_field( $value['backup_directory'] ) : backup_lite_get_backup_dir();
        $backup_directory = wp_normalize_path( $backup_directory );

        $ui_theme = $value['ui_theme'] ?? 'auto';
        $ui_theme = in_array( $ui_theme, [ 'auto', 'light', 'dark' ], true ) ? $ui_theme : 'auto';

        $backup_mode_default = $value['backup_mode_default'] ?? 'auto';
        $backup_mode_default = in_array( $backup_mode_default, [ 'auto', 'balanced', 'fast' ], true ) ? $backup_mode_default : 'auto';

        $backup_smart_exclude_default = $value['backup_smart_exclude_default'] ?? 'auto';
        $backup_smart_exclude_default = in_array( $backup_smart_exclude_default, [ 'auto', 'on', 'off' ], true ) ? $backup_smart_exclude_default : 'auto';

        $sanitized = [
            'backup_directory'          => $backup_directory,
            'notification_email'        => isset( $value['notification_email'] ) ? sanitize_email( $value['notification_email'] ) : get_option( 'admin_email' ),
            'min_role'                  => $requested_role,
            'ui_theme'                  => $ui_theme,
            'feature_restore_center_v2' => ! empty( $value['feature_restore_center_v2'] ),
            'feature_ui_animation'      => ! empty( $value['feature_ui_animation'] ),
            'feature_extended_log'      => ! empty( $value['feature_extended_log'] ),
            'feature_cloud_destinations' => false,
            'feature_advanced_filters'   => false,
            // Backup performance defaults (Free + Pro)
            'backup_mode_default'        => $backup_mode_default,
            'backup_smart_exclude_default' => $backup_smart_exclude_default,
            'backup_smart_exclude_threshold' => isset( $value['backup_smart_exclude_threshold'] ) ? absint( $value['backup_smart_exclude_threshold'] ) : 50000,
            'backup_custom_excludes'     => isset( $value['backup_custom_excludes'] ) ? sanitize_textarea_field( $value['backup_custom_excludes'] ) : '',
            'debug_mode'                 => false,
        ];

        // Clamp threshold to a sensible range to avoid pathological settings on shared hosting.
        $sanitized['backup_smart_exclude_threshold'] = max( 1000, min( 500000, (int) $sanitized['backup_smart_exclude_threshold'] ) );

        // PRO Settings
        if ( Backup_Lite_Pro::is_pro_active() ) {
            $sanitized['debug_mode'] = ! empty( $value['debug_mode'] );
            $sanitized['feature_cloud_destinations'] = ! empty( $value['feature_cloud_destinations'] );
            $sanitized['feature_advanced_filters']   = ! empty( $value['feature_advanced_filters'] );

            // AI Settings
            if ( isset( $value['ai_openai_key'] ) ) {
                $sanitized['ai_openai_key'] = sanitize_text_field( $value['ai_openai_key'] );
            }
            if ( isset( $value['ai_model'] ) ) {
                $models = [ 'gpt-4o-mini', 'gpt-4o', 'gpt-5' ];
                $sanitized['ai_model'] = in_array( $value['ai_model'], $models, true ) ? $value['ai_model'] : 'gpt-4o-mini';
            }
            if ( isset( $value['ai_temperature'] ) ) {
                $temp = floatval( $value['ai_temperature'] );
                $sanitized['ai_temperature'] = ( $temp >= 0 && $temp <= 2 ) ? $temp : 0.7;
            }
            $sanitized['ai_enabled']       = ! empty( $value['ai_enabled'] );
            $sanitized['ai_log_activity']  = ! empty( $value['ai_log_activity'] );

            // License Key (stored separately)
            if ( isset( $value['pro_license_key'] ) ) {
                $license = sanitize_text_field( $value['pro_license_key'] );
                if ( ! empty( $license ) ) {
                    update_option( Backup_Lite_Pro::OPTION_LICENSE, $license );
                }
            }
        } else {
            $sanitized['ai_openai_key']   = '';
            $sanitized['ai_model']        = 'gpt-4o-mini';
            $sanitized['ai_temperature']  = 0.7;
            $sanitized['ai_enabled']      = false;
            $sanitized['ai_log_activity'] = false;
        }

        // Force administrator as baseline default if legacy value stored as subscriber.
        if ( 'subscriber' === $sanitized['min_role'] ) {
            $sanitized['min_role'] = $default_role;
        }

        return $sanitized;
    }

    /**
     * Get available roles safely (works in admin-ajax contexts too).
     *
     * Some hosts/routes load a minimal admin stack where `get_editable_roles()` is not yet available.
     * In those cases, we load the core file that defines it, then fall back to `wp_roles()` if needed.
     *
     * @return array<string,mixed>
     */
    public static function get_available_roles(): array {
        if ( ! function_exists( 'get_editable_roles' ) ) {
            $user_file = trailingslashit( ABSPATH ) . 'wp-admin/includes/user.php';
            if ( file_exists( $user_file ) ) {
                require_once $user_file;
            }
        }

        if ( function_exists( 'get_editable_roles' ) ) {
            $roles = get_editable_roles();
            return is_array( $roles ) ? $roles : [];
        }

        if ( function_exists( 'wp_roles' ) ) {
            $wp_roles = wp_roles();
            if ( is_object( $wp_roles ) && isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ) {
                return $wp_roles->roles;
            }
        }

        // Final fallback: ensure at least administrator exists for validation.
        return [
            'administrator' => [],
        ];
    }

    /**
     * Returns all stored settings.
     *
     * @return array
     */
    public static function get_settings() {
        $defaults = [
            'backup_directory'            => backup_lite_get_backup_dir(),
            'notification_email'          => get_option( 'admin_email' ),
            'min_role'                    => 'administrator',
            'ui_theme'                    => 'auto',
            'debug_mode'                  => false,
            'feature_restore_center_v2'   => true,
            'feature_ui_animation'        => true,
            'feature_extended_log'        => false,
            'feature_cloud_destinations'  => false,
            'feature_advanced_filters'    => false,
            'backup_mode_default'         => 'auto',
            'backup_smart_exclude_default'=> 'auto',
            'backup_smart_exclude_threshold' => 50000,
            'backup_custom_excludes'      => '',
            'ai_openai_key'               => '',
            'ai_model'                    => 'gpt-4o-mini',
            'ai_temperature'             => 0.7,
            'ai_enabled'                 => false,
            'ai_log_activity'            => false,
        ];

        $stored = get_option( self::OPTION_KEY, [] );

        if ( ! is_array( $stored ) || empty( $stored ) ) {
            self::maybe_migrate_legacy_options();
            $stored = get_option( self::OPTION_KEY, [] );
        }

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        $sanitised = self::sanitize( $stored );

        // Merge PRO license key
        if ( Backup_Lite_Pro::is_pro_active() ) {
            $sanitised['pro_license_key'] = Backup_Lite_Pro::get_license_key();
        }

        return wp_parse_args( $sanitised, $defaults );
    }

    /**
     * AJAX: Save settings.
     */
    public static function ajax_save_settings() {
        Backup_Lite_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check
        check_ajax_referer( Backup_Lite_UI::NONCE, 'nonce' );

        // Nonce verified above
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() and check_ajax_referer() above
        $raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['settings'] ) )
            : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( empty( $raw_settings ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading JSON from php://input stream
            $data = file_get_contents( 'php://input' );
            $decoded = json_decode( $data, true );
            if ( ! is_array( $decoded ) ) {
                $decoded = [];
            }
        } else {
            $decoded = $raw_settings;
        }

        // Sanitize settings array (already sanitized above, but ensure consistency)
        if ( is_array( $decoded ) ) {
            $decoded = array_map( 'sanitize_text_field', $decoded );
        } else {
            $decoded = sanitize_text_field( $decoded );
        }

        $clean = self::sanitize( $decoded );
        update_option( self::OPTION_KEY, $clean );

        wp_send_json_success( [ 'settings' => $clean ] );
    }

    /**
     * AJAX: Fetch settings.
     */
    public static function ajax_fetch_settings() {
        Backup_Lite_UI::verify_ajax_request();

        wp_send_json_success( [ 'settings' => self::get_settings() ] );
    }

    /**
     * AJAX: Verify PRO license key.
     */
    public static function ajax_verify_license() {
        Backup_Lite_UI::verify_ajax_request();

        if ( ! Backup_Lite_Pro::is_pro_active() ) {
            wp_send_json_error( [
                'message' => __( 'PRO version is not active.', 'museder-restoreone' ),
            ] );
        }

        // Nonce verified in verify_ajax_request() above
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( empty( $license_key ) ) {
            wp_send_json_error( [
                'message' => __( 'License key is required.', 'museder-restoreone' ),
            ] );
        }

        // Future: Validate license key with remote server
        // For now, just store it
        update_option( Backup_Lite_Pro::OPTION_LICENSE, $license_key );

        wp_send_json_success( [
            'message' => __( 'License key saved. (Validation will be implemented in a future update.)', 'museder-restoreone' ),
        ] );
    }
}

