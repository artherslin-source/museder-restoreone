<?php
/**
 * Plugin Name: Museder RestoreOne Add-on
 * Plugin URI: https://musederlabs.com/restoreone-plugin/
 * Description: Optional add-on for Museder RestoreOne (cloud storage, advanced reports, AI scheduling, and related features).
 * Version: 2.7.262
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Jerry Lin
 * Author URI: https://profiles.wordpress.org/artherslin/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: museder-restoreone
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MUSEDER_RESTOREONE_PRO_VERSION', '2.7.262' );
define( 'MUSEDER_RESTOREONE_PRO_BUILD_ID', '2.7.262-1' );
define( 'MUSEDER_RESTOREONE_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'MUSEDER_RESTOREONE_PRO_URL', plugin_dir_url( __FILE__ ) );

require_once MUSEDER_RESTOREONE_PRO_PATH . 'includes/class-pro-addon.php';

register_activation_hook( __FILE__, [ 'Museder_Restoreone_Pro_Addon', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Museder_Restoreone_Pro_Addon', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'Museder_Restoreone_Pro_Addon', 'plugins_loaded' ], 20 );
