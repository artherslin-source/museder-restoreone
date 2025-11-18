<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Backup Lite PRO Management Class
 * 
 * Handles PRO version detection, feature locking, and upgrade prompts.
 */
class Backup_Lite_Pro {

    const OPTION_KEY = 'backup_lite_pro_active';
    const OPTION_LICENSE = 'backup_lite_pro_license_key';

    /**
     * Check if PRO version is active.
     * 
     * @return bool
     */
    public static function is_pro_active() {
        $active = get_option( self::OPTION_KEY, false );
        
        // For development/testing, you can enable via constant
        if ( defined( 'BACKUP_LITE_PRO_ACTIVE' ) && BACKUP_LITE_PRO_ACTIVE ) {
            return true;
        }
        
        // Future: Validate license key here
        // For now, return the option value
        return (bool) $active;
    }

    /**
     * Get PRO lock status for a feature.
     * 
     * @param string $feature Feature identifier (e.g., 'ai_copilot', 'cloud_storage').
     * @return array {
     *     @type bool   $enabled Whether the feature is enabled.
     *     @type string $reason  Lock reason code.
     *     @type string $message User-facing message.
     * }
     */
    public static function pro_lock( $feature ) {
        if ( self::is_pro_active() ) {
            return [
                'enabled' => true,
                'reason'  => '',
                'message' => '',
            ];
        }

        return [
            'enabled' => false,
            'reason'  => 'pro_required',
            'message' => __( 'This feature requires Museder RestoreOne PRO.', 'museder-restoreone' ),
        ];
    }

    /**
     * Check if a specific feature is available.
     * 
     * @param string $feature Feature identifier.
     * @return bool
     */
    public static function is_feature_available( $feature ) {
        $lock = self::pro_lock( $feature );
        return $lock['enabled'];
    }

    /**
     * Get upgrade URL (placeholder for future implementation).
     * 
     * @return string
     */
    public static function get_upgrade_url() {
        return apply_filters( 'backup_lite_pro_upgrade_url', 'https://musederlabs.com/' );
    }

    /**
     * Activate PRO mode (for testing or license activation).
     * 
     * @param string $license_key Optional license key.
     * @return bool
     */
    public static function activate( $license_key = '' ) {
        // Future: Validate license key with remote server
        update_option( self::OPTION_KEY, true );
        
        if ( ! empty( $license_key ) ) {
            update_option( self::OPTION_LICENSE, sanitize_text_field( $license_key ) );
        }
        
        return true;
    }

    /**
     * Deactivate PRO mode.
     * 
     * @return bool
     */
    public static function deactivate() {
        delete_option( self::OPTION_KEY );
        delete_option( self::OPTION_LICENSE );
        return true;
    }

    /**
     * Get current license key (if any).
     * 
     * @return string
     */
    public static function get_license_key() {
        return get_option( self::OPTION_LICENSE, '' );
    }

    /**
     * Initialize PRO class.
     */
    public static function init() {
        // Future: Add license validation hooks, cron jobs, etc.
    }
}

