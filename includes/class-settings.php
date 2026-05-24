<?php
/**
 * Global settings handler for Museder RestoreOne.
 *
 * @package Museder_Restoreone
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_Restoreone_Settings {

    const OPTION_KEY = 'museder_restoreone_options';
    const LEGACY_OPTION_KEY = 'museder_restoreone_settings';

    /**
     * Initialise hooks.
     */
    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_init', [ __CLASS__, 'maybe_migrate_legacy_options' ] );
        add_action( 'wp_ajax_museder_restoreone_save_settings', [ __CLASS__, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_museder_restoreone_fetch_settings', [ __CLASS__, 'ajax_fetch_settings' ] );
    }

    /**
     * Registers option for sanitisation.
     */
    public static function register_settings() {
        register_setting( 'museder_restoreone_settings_group', self::OPTION_KEY, [ __CLASS__, 'sanitize' ] );
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

        $legacy_backup_dir = isset( $legacy['backup_dir'] ) ? (string) $legacy['backup_dir'] : '';
        $mapped            = [
            'backup_storage_subdir' => museder_restoreone_subdir_from_absolute_backup_path( $legacy_backup_dir ),
            'backup_directory'      => $legacy_backup_dir,
            'notification_email'    => isset( $legacy['notify_email'] ) ? $legacy['notify_email'] : get_option( 'admin_email' ),
            'min_role'              => isset( $legacy['role'] ) ? $legacy['role'] : 'administrator',
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

        $storage_root = museder_restoreone_get_storage_root();
        $storage_path = wp_normalize_path( $storage_root['path'] );

        $backup_subdir = '';
        if ( isset( $value['backup_storage_subdir'] ) ) {
            $backup_subdir = museder_restoreone_normalize_storage_subdir( (string) $value['backup_storage_subdir'] );
        } elseif ( isset( $value['backup_directory'] ) ) {
            $backup_subdir = museder_restoreone_subdir_from_absolute_backup_path( (string) $value['backup_directory'] );
        } else {
            $backup_subdir = 'backups';
        }

        $backup_directory = wp_normalize_path( trailingslashit( $storage_path ) . $backup_subdir );

        $ui_theme = $value['ui_theme'] ?? 'auto';
        $ui_theme = in_array( $ui_theme, [ 'auto', 'light', 'dark' ], true ) ? $ui_theme : 'auto';

        $backup_mode_default = $value['backup_mode_default'] ?? 'auto';
        $backup_mode_default = in_array( $backup_mode_default, [ 'auto', 'balanced', 'fast' ], true ) ? $backup_mode_default : 'auto';

        $backup_smart_exclude_default = $value['backup_smart_exclude_default'] ?? 'auto';
        $backup_smart_exclude_default = in_array( $backup_smart_exclude_default, [ 'auto', 'on', 'off' ], true ) ? $backup_smart_exclude_default : 'auto';

        $sanitized = [
            'backup_storage_subdir'     => $backup_subdir,
            'backup_directory'          => $backup_directory,
            'notification_email'        => isset( $value['notification_email'] ) ? sanitize_email( $value['notification_email'] ) : get_option( 'admin_email' ),
            'min_role'                  => $requested_role,
            'ui_theme'                  => $ui_theme,
            'feature_restore_center_v2' => ! empty( $value['feature_restore_center_v2'] ),
            'feature_ui_animation'      => ! empty( $value['feature_ui_animation'] ),
            'feature_extended_log'      => ! empty( $value['feature_extended_log'] ),
            // Backup performance defaults
            'backup_mode_default'        => $backup_mode_default,
            'backup_smart_exclude_default' => $backup_smart_exclude_default,
            'backup_smart_exclude_threshold' => isset( $value['backup_smart_exclude_threshold'] ) ? absint( $value['backup_smart_exclude_threshold'] ) : 50000,
            'backup_custom_excludes'     => isset( $value['backup_custom_excludes'] ) ? sanitize_textarea_field( $value['backup_custom_excludes'] ) : '',
            'debug_mode'                 => false,
        ];

        // Clamp threshold to a sensible range to avoid pathological settings on shared hosting.
        $sanitized['backup_smart_exclude_threshold'] = max( 1000, min( 500000, (int) $sanitized['backup_smart_exclude_threshold'] ) );

        // Force administrator as baseline default if legacy value stored as subscriber.
        if ( 'subscriber' === $sanitized['min_role'] ) {
            $sanitized['min_role'] = $default_role;
        }

        return $sanitized;
    }

    /**
     * Get available roles safely (works in admin-ajax contexts too).
     *
     * Uses wp_roles() only to avoid loading WordPress core files (WP.org compliance).
     *
     * @return array<string,mixed>
     */
    public static function get_available_roles(): array {
        if ( function_exists( 'wp_roles' ) ) {
            $wp_roles = wp_roles();
            if ( is_object( $wp_roles ) && isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ) {
                return $wp_roles->roles;
            }
        }

        if ( function_exists( 'get_editable_roles' ) ) {
            $roles = get_editable_roles();
            return is_array( $roles ) ? $roles : [];
        }

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
        $storage_root = museder_restoreone_get_storage_root();
        $storage_path = wp_normalize_path( $storage_root['path'] );

        $defaults = [
            'backup_storage_subdir'       => 'backups',
            'backup_directory'            => trailingslashit( $storage_path ) . 'backups',
            'notification_email'          => get_option( 'admin_email' ),
            'min_role'                    => 'administrator',
            'ui_theme'                    => 'auto',
            'debug_mode'                  => false,
            'feature_restore_center_v2'   => true,
            'feature_ui_animation'        => true,
            'feature_extended_log'        => false,
            'backup_mode_default'         => 'auto',
            'backup_smart_exclude_default'=> 'auto',
            'backup_smart_exclude_threshold' => 50000,
            'backup_custom_excludes'      => '',
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

        return wp_parse_args( $sanitised, $defaults );
    }

    /**
     * AJAX: Save settings.
     */
    public static function ajax_save_settings() {
        Museder_Restoreone_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        $decoded = [];

        // Nonce verified above. Only process the explicit `settings` field instead of reading the whole request body.
        $raw_settings_json  = filter_input( INPUT_POST, 'settings', FILTER_UNSAFE_RAW );
        $raw_settings_array = filter_input( INPUT_POST, 'settings', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );

        if ( is_string( $raw_settings_json ) && '' !== $raw_settings_json ) {
            $decoded = json_decode( sanitize_textarea_field( wp_unslash( $raw_settings_json ) ), true );
        } elseif ( is_array( $raw_settings_array ) ) {
            $decoded = wp_unslash( $raw_settings_array );
        }

        if ( ! is_array( $decoded ) ) {
            $decoded = [];
        }

        // Whitelist and sanitize via schema (self::sanitize only keeps allowed keys and sanitizes per type).
        $clean = self::sanitize( $decoded );
        update_option( self::OPTION_KEY, $clean );

        wp_send_json_success( [ 'settings' => $clean ] );
    }

    /**
     * AJAX: Fetch settings.
     */
    public static function ajax_fetch_settings() {
        Museder_Restoreone_UI::verify_ajax_request();
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        wp_send_json_success( [ 'settings' => self::get_settings() ] );
    }

}

