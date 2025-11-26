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

