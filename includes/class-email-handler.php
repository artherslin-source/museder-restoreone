<?php
/**
 * Email handler for Backup Lite notifications.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Email_Handler {

    /**
     * Bootstraps hooks.
     */
    public static function init() {
        add_action( 'wp_ajax_backup_lite_test_email', [ __CLASS__, 'ajax_test_email' ] );
    }

    /**
     * Sends an email using the configured settings.
     *
     * @param string      $subject Email subject.
     * @param string      $message Email body.
     * @param string|null $to      Optional recipient override.
     *
     * @return bool
     */
    public static function send( $subject, $message, $to = null ) {
        if ( null === $to ) {
            $settings = Backup_Lite_Settings::get_settings();
            $to       = ! empty( $settings['notification_email'] ) ? $settings['notification_email'] : get_option( 'admin_email' );
        }

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        return wp_mail( $to, $subject, wpautop( $message ), $headers );
    }

    /**
     * Sends a test email using current settings.
     *
     * @return bool
     */
    public static function test_email() {
        return self::send(
            __( 'Backup Lite Test Email', 'museder-restoreone' ),
            __( '✅ This is a test message from Backup Lite. Your email notifications are working.', 'museder-restoreone' )
        );
    }

    /**
     * AJAX endpoint for sending test emails.
     */
    public static function ajax_test_email() {
        Backup_Lite_UI::verify_ajax_request();

        $result = self::test_email();

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to send test email.', 'museder-restoreone' ) ] );
        }

        wp_send_json_success();
    }
}

