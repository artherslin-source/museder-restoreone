<?php
/**
 * RestoreOne add-on bootstrap (detected by the free plugin via class_exists).
 *
 * @package Museder_Restoreone_Addon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marker + loader for the optional RestoreOne add-on plugin.
 */
class Museder_Restoreone_Pro_Addon {

	/**
	 * @return void
	 */
	public static function activate() {
		if ( ! self::base_plugin_ready() ) {
			deactivate_plugins( plugin_basename( MUSEDER_RESTOREONE_PRO_PATH . 'museder-restoreone-pro.php' ) );
			wp_die(
				esc_html__( 'Museder RestoreOne must be installed and active before enabling the Add-on.', 'museder-restoreone' ),
				esc_html__( 'Plugin dependency check', 'museder-restoreone' ),
				[ 'back_link' => true ]
			);
		}

		require_once MUSEDER_RESTOREONE_PRO_PATH . 'includes/class-pro.php';
		if ( class_exists( 'Museder_Restoreone_Pro' ) ) {
			Museder_Restoreone_Pro::activate();
		}
	}

	/**
	 * @return void
	 */
	public static function deactivate() {
		require_once MUSEDER_RESTOREONE_PRO_PATH . 'includes/class-pro.php';
		if ( class_exists( 'Museder_Restoreone_Pro' ) ) {
			Museder_Restoreone_Pro::deactivate();
		}
	}

	/**
	 * @return void
	 */
	public static function plugins_loaded() {
		if ( ! self::base_plugin_ready() ) {
			add_action( 'admin_notices', [ __CLASS__, 'admin_notice_missing_base' ] );
			return;
		}

		self::load_modules();
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_menus' ], 20 );
	}

	/**
	 * @return bool
	 */
	protected static function base_plugin_ready() {
		return function_exists( 'museder_restoreone_get_backup_dir' );
	}

	/**
	 * @return void
	 */
	public static function admin_notice_missing_base() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Museder RestoreOne Add-on requires the Museder RestoreOne plugin to be installed and active.', 'museder-restoreone' );
		echo '</p></div>';
	}

	/**
	 * @return void
	 */
	protected static function load_modules() {
		require_once MUSEDER_RESTOREONE_PRO_PATH . 'includes/class-pro.php';
		require_once MUSEDER_RESTOREONE_PRO_PATH . 'includes/class-cloud-service.php';
		require_once MUSEDER_RESTOREONE_PRO_PATH . 'includes/class-backup-lite-s3-service.php';

		Museder_Restoreone_Pro::init();

		$pro_files = [
			'includes/pro/ai-service.php',
			'includes/pro/ai-controller.php',
			'includes/pro/smart-retention.php',
			'includes/pro/advanced-filters.php',
			'includes/pro/health-score.php',
			'includes/pro/cloud-storage.php',
			'includes/pro/reports-service.php',
			'includes/pro/reports-controller.php',
		];

		foreach ( $pro_files as $file ) {
			$path = MUSEDER_RESTOREONE_PRO_PATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( class_exists( 'Museder_Restoreone_AI_Service' ) ) {
			Museder_Restoreone_AI_Service::init();
		}
		if ( class_exists( 'Museder_Restoreone_AI_Controller' ) ) {
			Museder_Restoreone_AI_Controller::init();
		}
		if ( class_exists( 'Museder_Restoreone_Smart_Retention' ) ) {
			Museder_Restoreone_Smart_Retention::init();
		}
		if ( class_exists( 'Museder_Restoreone_Advanced_Filters' ) ) {
			Museder_Restoreone_Advanced_Filters::init();
		}
		if ( class_exists( 'Museder_Restoreone_Health_Score' ) ) {
			Museder_Restoreone_Health_Score::init();
		}
		if ( class_exists( 'Museder_Restoreone_Cloud_Storage' ) ) {
			Museder_Restoreone_Cloud_Storage::init();
		}
		if ( class_exists( 'Museder_Restoreone_Reports_Service' ) ) {
			Museder_Restoreone_Reports_Service::init();
		}
		if ( class_exists( 'Museder_Restoreone_Reports_Controller' ) ) {
			Museder_Restoreone_Reports_Controller::init();
		}
	}

	/**
	 * @return void
	 */
	public static function register_admin_menus() {
		$parent = 'museder-restoreone-dashboard';

		add_submenu_page(
			$parent,
			__( 'RestoreOne Add-on', 'museder-restoreone' ),
			__( 'Add-on', 'museder-restoreone' ) . ' <span class="pro-badge" style="background:#facc15;color:#000;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600;margin-left:6px;">PRO</span>',
			'manage_options',
			'museder-restoreone-addon',
			[ __CLASS__, 'render_features' ]
		);

		add_submenu_page(
			$parent,
			__( 'Add-on Reports', 'museder-restoreone' ),
			__( 'Add-on Reports', 'museder-restoreone' ),
			'manage_options',
			'museder-restoreone-addon-reports',
			[ __CLASS__, 'render_reports' ]
		);
	}

	/**
	 * @return void
	 */
	public static function render_features() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
		}

		$museder_restoreone_is_pro = class_exists( 'Museder_Restoreone_Pro' ) && Museder_Restoreone_Pro::is_pro_active();
		include MUSEDER_RESTOREONE_PRO_PATH . 'templates/page-pro-features.php';
	}

	/**
	 * @return void
	 */
	public static function render_reports() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
		}

		$museder_restoreone_is_pro = class_exists( 'Museder_Restoreone_Pro' ) && Museder_Restoreone_Pro::is_pro_active();

		if ( defined( 'MUSEDER_RESTOREONE_URL' ) ) {
			wp_enqueue_script(
				'chartjs',
				MUSEDER_RESTOREONE_URL . 'assets/vendor/chart.4.5.1.min.js',
				[],
				'4.5.1',
				true
			);
		}

		wp_enqueue_script(
			'museder-restoreone-reports',
			MUSEDER_RESTOREONE_PRO_URL . 'assets/js/reports.js',
			[ 'jquery', 'chartjs' ],
			MUSEDER_RESTOREONE_PRO_VERSION,
			true
		);

		wp_localize_script(
			'museder-restoreone-reports',
			'MusederRestoreOneReports',
			[
				'restUrl' => esc_url_raw( rest_url( 'museder-restoreone/v2/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'isPro'   => (bool) $museder_restoreone_is_pro,
			]
		);

		include MUSEDER_RESTOREONE_PRO_PATH . 'templates/page-reports.php';
	}
}
