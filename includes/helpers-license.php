<?php
/**
 * License and Developer Mode Helper Functions
 * 
 * Provides unified license tier and developer mode management for the plugin.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get raw license tier from settings (without considering Developer Mode).
 *
 * Expected values: 'free', 'pro', 'agency', etc.
 * If value is not in the allowed list, returns 'free'.
 *
 * @return string License tier ('free', 'pro', 'agency').
 */
function backup_lite_get_raw_license_tier() {
    // First check AI settings (legacy location)
    $ai_settings = get_option( 'museder_ai_settings', array() );
    if ( ! empty( $ai_settings['license_tier'] ) ) {
        $tier = $ai_settings['license_tier'];
    } else {
        // Fallback to dedicated option
        $tier = get_option( 'backup_lite_license_tier', 'free' );
    }

    $allowed = array( 'free', 'pro', 'agency' );
    if ( ! in_array( $tier, $allowed, true ) ) {
        $tier = 'free';
    }

    return $tier;
}

/**
 * Check if global Developer Mode is enabled.
 *
 * Priority order:
 * 1. If BACKUP_LITE_FORCE_DEV_MODE constant is defined and true, return true.
 * 2. Otherwise, read option 'backup_lite_enable_developer_mode' (boolean).
 *
 * @return bool True if Developer Mode is enabled.
 */
function backup_lite_is_developer_mode() {
    // Check constant first (highest priority)
    if ( defined( 'BACKUP_LITE_FORCE_DEV_MODE' ) && BACKUP_LITE_FORCE_DEV_MODE ) {
        return true;
    }

    // Check legacy constant for backward compatibility
    if ( defined( 'MUSERDER_DEV_MODE' ) && MUSERDER_DEV_MODE ) {
        return true;
    }

    // Check option
    return (bool) get_option( 'backup_lite_enable_developer_mode', false );
}

/**
 * Get effective license tier (considers Developer Mode).
 *
 * When Developer Mode is enabled, force return 'pro'.
 *
 * @return string Effective license tier ('free', 'pro', 'agency').
 */
function backup_lite_get_effective_license_tier() {
    $tier = backup_lite_get_raw_license_tier();

    if ( backup_lite_is_developer_mode() ) {
        return 'pro';
    }

    return $tier;
}

/**
 * Check if Pro features are available.
 *
 * Current rule: effective tier 'pro' or 'agency' is considered Pro.
 * Future higher tiers can be added here.
 *
 * @return bool True if Pro features are available.
 */
function backup_lite_has_pro_features() {
    $tier = backup_lite_get_effective_license_tier();

    return in_array( $tier, array( 'pro', 'agency' ), true );
}

