<?php
/**
 * AI Settings Page
 * 
 * Settings page for Museder AI features.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI Settings Page class.
 */
class Museder_AI_Settings_Page {

    const OPTION_KEY = 'museder_ai_settings';

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    /**
     * Add menu page.
     */
    public static function add_menu_page() {
        add_submenu_page(
            'backup-lite-dashboard',
            __( 'Museder AI Settings', 'museder-restoreone' ),
            __( 'AI Settings', 'museder-restoreone' ),
            'manage_options',
            'museder-restoreone-ai',
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * Register settings using WordPress Settings API.
     */
    public static function register_settings() {
        register_setting(
            'museder_ai_settings_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
                'default'           => [
                    'license_tier'   => 'free',
                    'ai_api_endpoint' => '',
                    'openai_api_key'  => '',
                    'alert_email'   => get_option( 'admin_email' ),
                    'dev_mode'      => false,
                    'force_high_risk' => false,
                ],
            ]
        );

        // License Tier section
        add_settings_section(
            'museder_ai_license_section',
            __( 'License Configuration', 'museder-restoreone' ),
            [ __CLASS__, 'render_license_section' ],
            'museder-restoreone-ai'
        );

        add_settings_field(
            'license_tier',
            __( 'License Tier', 'museder-restoreone' ),
            [ __CLASS__, 'render_license_tier_field' ],
            'museder-restoreone-ai',
            'museder_ai_license_section'
        );

        // API Configuration section
        add_settings_section(
            'museder_ai_api_section',
            __( 'API Configuration', 'museder-restoreone' ),
            [ __CLASS__, 'render_api_section' ],
            'museder-restoreone-ai'
        );

        add_settings_field(
            'ai_api_endpoint',
            __( 'AI API Endpoint', 'museder-restoreone' ),
            [ __CLASS__, 'render_api_endpoint_field' ],
            'museder-restoreone-ai',
            'museder_ai_api_section'
        );

        add_settings_field(
            'openai_api_key',
            __( 'OpenAI API Key', 'museder-restoreone' ),
            [ __CLASS__, 'render_openai_api_key_field' ],
            'museder-restoreone-ai',
            'museder_ai_api_section'
        );

        // Alert Configuration section
        add_settings_section(
            'museder_ai_alert_section',
            __( 'Alert Configuration', 'museder-restoreone' ),
            [ __CLASS__, 'render_alert_section' ],
            'museder-restoreone-ai'
        );

        add_settings_field(
            'alert_email',
            __( 'Alert Email', 'museder-restoreone' ),
            [ __CLASS__, 'render_alert_email_field' ],
            'museder-restoreone-ai',
            'museder_ai_alert_section'
        );

        // Developer Mode section (always register, but only visible to administrators in render)
        add_settings_section(
            'museder_ai_dev_section',
            __( 'Developer Mode', 'museder-restoreone' ),
            [ __CLASS__, 'render_dev_section' ],
            'museder-restoreone-ai'
        );

        add_settings_field(
            'dev_mode',
            __( 'Enable Developer Mode', 'museder-restoreone' ),
            [ __CLASS__, 'render_dev_mode_field' ],
            'museder-restoreone-ai',
            'museder_ai_dev_section'
        );

        add_settings_field(
            'force_high_risk',
            __( 'Force High Risk', 'museder-restoreone' ),
            [ __CLASS__, 'render_force_high_risk_field' ],
            'museder-restoreone-ai',
            'museder_ai_dev_section'
        );
    }

    /**
     * Sanitize settings.
     * 
     * @param array $input Raw input.
     * @return array Sanitized settings.
     */
    public static function sanitize_settings( $input ) {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $sanitized = [];

        // License tier
        $allowed_tiers = [ 'free', 'pro', 'agency' ];
        if ( isset( $input['license_tier'] ) && in_array( $input['license_tier'], $allowed_tiers, true ) ) {
            $sanitized['license_tier'] = $input['license_tier'];
        } else {
            $sanitized['license_tier'] = 'free';
        }

        // AI API endpoint
        if ( isset( $input['ai_api_endpoint'] ) ) {
            $sanitized['ai_api_endpoint'] = esc_url_raw( $input['ai_api_endpoint'] );
        } else {
            $sanitized['ai_api_endpoint'] = '';
        }

        // OpenAI API key
        if ( isset( $input['openai_api_key'] ) ) {
            $sanitized['openai_api_key'] = sanitize_text_field( $input['openai_api_key'] );
        } else {
            $sanitized['openai_api_key'] = '';
        }

        // Alert email
        if ( isset( $input['alert_email'] ) ) {
            $sanitized['alert_email'] = sanitize_email( $input['alert_email'] );
            if ( empty( $sanitized['alert_email'] ) ) {
                $sanitized['alert_email'] = get_option( 'admin_email' );
            }
        } else {
            $sanitized['alert_email'] = get_option( 'admin_email' );
        }

        // Developer mode (only for administrators)
        // Store to global option: backup_lite_enable_developer_mode
        if ( current_user_can( 'manage_options' ) ) {
            $dev_mode_enabled = isset( $input['dev_mode'] ) && $input['dev_mode'] === '1';
            // Store to global option
            update_option( 'backup_lite_enable_developer_mode', $dev_mode_enabled ? 1 : 0, false );
            // Also keep in AI settings for backward compatibility
            $sanitized['dev_mode'] = $dev_mode_enabled;
            $sanitized['force_high_risk'] = isset( $input['force_high_risk'] ) && $input['force_high_risk'] === '1';
        } else {
            // Non-admins cannot change dev mode settings
            $current = get_option( self::OPTION_KEY, [] );
            $sanitized['dev_mode'] = isset( $current['dev_mode'] ) ? (bool) $current['dev_mode'] : false;
            $sanitized['force_high_risk'] = isset( $current['force_high_risk'] ) ? (bool) $current['force_high_risk'] : false;
        }

        return $sanitized;
    }

    /**
     * Render license section description.
     */
    public static function render_license_section() {
        echo '<p>' . esc_html__( 'Configure your AI license tier. This determines which AI features are available.', 'museder-restoreone' ) . '</p>';
    }

    /**
     * Render license tier field.
     */
    public static function render_license_tier_field() {
        $settings = Museder_AI_Service::get_settings();
        $value = $settings['license_tier'];
        ?>
        <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[license_tier]" id="license_tier">
            <option value="free" <?php selected( $value, 'free' ); ?>><?php esc_html_e( 'Free', 'museder-restoreone' ); ?></option>
            <option value="pro" <?php selected( $value, 'pro' ); ?>><?php esc_html_e( 'Pro', 'museder-restoreone' ); ?></option>
            <option value="agency" <?php selected( $value, 'agency' ); ?>><?php esc_html_e( 'Agency', 'museder-restoreone' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( 'Select your license tier. This will be used to determine available AI features.', 'museder-restoreone' ); ?></p>
        <?php
    }

    /**
     * Render API section description.
     */
    public static function render_api_section() {
        echo '<p>' . esc_html__( 'Configure AI API endpoint. Leave empty to use default OpenAI Chat Completions API.', 'museder-restoreone' ) . '</p>';
    }

    /**
     * Render API endpoint field.
     */
    public static function render_api_endpoint_field() {
        $settings = Museder_AI_Service::get_settings();
        $value = $settings['ai_api_endpoint'];
        ?>
        <input type="url" 
               name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ai_api_endpoint]" 
               id="ai_api_endpoint" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="regular-text" 
               placeholder="https://api.openai.com/v1/chat/completions" />
        <p class="description"><?php esc_html_e( 'AI API endpoint URL. Leave empty to use default OpenAI Chat Completions API endpoint.', 'museder-restoreone' ); ?></p>
        <?php
    }

    /**
     * Render OpenAI API key field.
     */
    public static function render_openai_api_key_field() {
        $settings = Museder_AI_Service::get_settings();
        $value = $settings['openai_api_key'] ?? '';
        ?>
        <input type="password" 
               name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openai_api_key]" 
               id="openai_api_key" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="regular-text" 
               placeholder="sk-..." />
        <p class="description"><?php esc_html_e( 'This key is used only on this site to call the OpenAI API. Do not share it or commit it to Git.', 'museder-restoreone' ); ?></p>
        <?php
    }

    /**
     * Render alert section description.
     */
    public static function render_alert_section() {
        echo '<p>' . esc_html__( 'Configure email address for AI alerts and notifications.', 'museder-restoreone' ) . '</p>';
    }

    /**
     * Render alert email field.
     */
    public static function render_alert_email_field() {
        $settings = Museder_AI_Service::get_settings();
        $value = $settings['alert_email'];
        ?>
        <input type="email" 
               name="<?php echo esc_attr( self::OPTION_KEY ); ?>[alert_email]" 
               id="alert_email" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="regular-text" />
        <p class="description"><?php esc_html_e( 'Email address to receive AI-generated alerts and recommendations.', 'museder-restoreone' ); ?></p>
        <?php
    }

    /**
     * Render developer mode section description.
     */
    public static function render_dev_section() {
        // Only show to administrators
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Check both constant and option (constant takes priority)
        $dev_mode_from_constant = ( defined( 'BACKUP_LITE_FORCE_DEV_MODE' ) && BACKUP_LITE_FORCE_DEV_MODE ) || ( defined( 'MUSERDER_DEV_MODE' ) && MUSERDER_DEV_MODE );
        $force_high_risk_from_constant = defined( 'MUSERDER_FORCE_HIGH_RISK' ) && MUSERDER_FORCE_HIGH_RISK;
        
        // Check global developer mode option
        $dev_mode_from_option = function_exists( 'backup_lite_is_developer_mode' ) ? backup_lite_is_developer_mode() : false;
        
        $settings = Museder_AI_Service::get_settings();
        $force_high_risk_from_option = ! empty( $settings['force_high_risk'] );
        
        // Constant takes priority
        $dev_mode_enabled = $dev_mode_from_constant || $dev_mode_from_option;
        $force_high_risk_enabled = ( $force_high_risk_from_constant || $force_high_risk_from_option ) && $dev_mode_enabled;
        
        // Show warning if constant is set
        $has_constant_override = $dev_mode_from_constant || $force_high_risk_from_constant;
        
        ?>
        <?php if ( $has_constant_override ) : ?>
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 15px 0; border-radius: 4px;">
            <strong>⚠️ <?php esc_html_e( 'Note:', 'museder-restoreone' ); ?></strong>
            <p style="margin: 5px 0 0 0;">
                <?php esc_html_e( 'Developer mode settings are currently controlled by PHP constants in wp-config.php. The settings below will be ignored until the constants are removed.', 'museder-restoreone' ); ?>
            </p>
        </div>
        <?php endif; ?>
        <p><?php esc_html_e( 'Enable developer mode to bypass Free tier limits and test AI features. Force High Risk mode will make all AI functions return High risk for testing alerts.', 'museder-restoreone' ); ?></p>
        <?php
    }

    /**
     * Render developer mode field.
     */
    public static function render_dev_mode_field() {
        // Only show to administrators
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Check if constant is set (takes priority)
        $constant_enabled = ( defined( 'BACKUP_LITE_FORCE_DEV_MODE' ) && BACKUP_LITE_FORCE_DEV_MODE ) || ( defined( 'MUSERDER_DEV_MODE' ) && MUSERDER_DEV_MODE );
        
        // Check global developer mode option
        $option_enabled = function_exists( 'backup_lite_is_developer_mode' ) ? backup_lite_is_developer_mode() : false;
        
        // Constant takes priority
        $is_enabled = $constant_enabled || $option_enabled;
        $is_disabled_by_constant = $constant_enabled;
        
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[dev_mode]" 
                   value="1" 
                   id="dev_mode"
                   <?php checked( $option_enabled ); ?>
                   <?php disabled( $is_disabled_by_constant ); ?> />
            <?php esc_html_e( 'Enable Developer Mode (bypass Free tier limits)', 'museder-restoreone' ); ?>
        </label>
        <?php if ( $is_disabled_by_constant ) : ?>
            <p class="description" style="color: #dc3232;">
                <?php esc_html_e( 'This setting is controlled by BACKUP_LITE_FORCE_DEV_MODE or MUSERDER_DEV_MODE constant in wp-config.php.', 'museder-restoreone' ); ?>
            </p>
        <?php else : ?>
            <p class="description">
                <?php esc_html_e( 'When enabled, Free tier users can use AI features unlimited times. Only enable on development/testing sites.', 'museder-restoreone' ); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render force high risk field.
     */
    public static function render_force_high_risk_field() {
        // Only show to administrators
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Check if constant is set (takes priority)
        $constant_enabled = defined( 'MUSERDER_FORCE_HIGH_RISK' ) && MUSERDER_FORCE_HIGH_RISK;
        
        // Check global developer mode
        $dev_mode_enabled = function_exists( 'backup_lite_is_developer_mode' ) ? backup_lite_is_developer_mode() : false;
        $option_enabled = ! empty( $settings['force_high_risk'] );
        
        // Constant takes priority
        $is_enabled = $constant_enabled || $option_enabled;
        $is_disabled_by_constant = $constant_enabled;
        $is_disabled_by_dev_mode = ! $dev_mode_enabled;
        
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[force_high_risk]" 
                   value="1" 
                   id="force_high_risk"
                   <?php checked( $option_enabled ); ?>
                   <?php disabled( $is_disabled_by_constant || $is_disabled_by_dev_mode ); ?> />
            <?php esc_html_e( 'Force High Risk (for testing AI Alerts)', 'museder-restoreone' ); ?>
        </label>
        <?php if ( $is_disabled_by_constant ) : ?>
            <p class="description" style="color: #dc3232;">
                <?php esc_html_e( 'This setting is controlled by MUSERDER_FORCE_HIGH_RISK constant in wp-config.php.', 'museder-restoreone' ); ?>
            </p>
        <?php elseif ( $is_disabled_by_dev_mode ) : ?>
            <p class="description" style="color: #dc3232;">
                <?php esc_html_e( 'Please enable Developer Mode first.', 'museder-restoreone' ); ?>
            </p>
        <?php else : ?>
            <p class="description">
                <?php esc_html_e( 'When enabled, all AI functions will return High risk for testing alert notifications. Requires Developer Mode to be enabled.', 'museder-restoreone' ); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render settings page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
        }

        // Check if settings were saved
        if ( isset( $_GET['settings-updated'] ) ) {
            add_settings_error(
                'museder_ai_settings',
                'settings_updated',
                __( 'Settings saved successfully.', 'museder-restoreone' ),
                'success'
            );
        }

        settings_errors( 'museder_ai_settings' );
        ?>
        <div class="wrap backup-lite-admin">
            <h1 class="backup-lite-page-title">🤖 <?php esc_html_e( 'Museder AI Settings', 'museder-restoreone' ); ?></h1>
            <p class="backup-lite-page-description">
                <?php esc_html_e( 'Museder RestoreOne will use these settings for AI-based features.', 'museder-restoreone' ); ?>
                <br>
                <?php esc_html_e( 'For now, the AI responses are demo only (no external calls yet).', 'museder-restoreone' ); ?>
            </p>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'museder_ai_settings_group' );
                do_settings_sections( 'museder-restoreone-ai' );
                submit_button( __( 'Save Changes', 'museder-restoreone' ) );
                ?>
            </form>
        </div>
        <?php
    }
}

