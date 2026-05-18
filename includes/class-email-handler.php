<?php
/**
 * Email handler for Museder RestoreOne notifications.
 *
 * @package Museder_Restoreone
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_Restoreone_Email_Handler {

    /**
     * Bootstraps hooks.
     */
    public static function init() {
        add_action( 'wp_ajax_museder_restoreone_test_email', [ __CLASS__, 'ajax_test_email' ] );
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
            $settings = Museder_Restoreone_Settings::get_settings();
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
            __( 'Museder RestoreOne Test Email', 'museder-restoreone' ),
            __( 'This is a test message from Museder RestoreOne. Your email notifications are working.', 'museder-restoreone' )
        );
    }

    /**
     * AJAX endpoint for sending test emails.
     */
    public static function ajax_test_email() {
        Museder_Restoreone_UI::verify_ajax_request();
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        $result = self::test_email();

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to send test email.', 'museder-restoreone' ) ] );
        }

        wp_send_json_success();
    }
}

