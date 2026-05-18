<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Feature availability helper.
 *
 * Keeps optional feature checks centralized without shipping upgrade prompts.
 */
class Museder_Restoreone_Pro {

    const OPTION_KEY = 'museder_restoreone_pro_active';
    const OPTION_LICENSE = 'museder_restoreone_pro_license_key';

    /**
     * Check if PRO version is active.
     * 
     * @return bool
     */
    public static function is_pro_active() {
        $active = get_option( self::OPTION_KEY, false );
        
        // For development/testing, you can enable via constant
        if ( defined( 'MUSEDER_RESTOREONE_PRO_ACTIVE' ) && MUSEDER_RESTOREONE_PRO_ACTIVE ) {
            return true;
        }
        
        // Future: Validate license key here
        // For now, return the option value
        return (bool) $active;
    }

    /**
     * Get feature availability status.
     *
     * @param string $feature Feature identifier.
     * @return array
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
            'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
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
     * No public upgrade URL is exposed in this build.
     *
     * @return string
     */
    public static function get_upgrade_url() {
        return '';
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

