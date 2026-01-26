<?php
/**
 * Download handler for backup files.
 *
 * 注意：這個檔案僅為舊版下載連結相容，實際邏輯已遷移到 admin-post.php。
 * 此檔案中的變數（例如 $wp_load, $plugin_path, $file, $mime 等）皆為此檔案內部使用，
 * 作用範圍僅限此檔案，並非在 WordPress 全域命名空間中到處使用的真正「全域變數」。
 * 為了維持向後相容性，我們在此關閉 PrefixAllGlobals 警告。
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

/**
 * IMPORTANT:
 * - Do not bootstrap WordPress from a directly-accessed plugin file.
 * - This file is a deprecated redirect stub kept only for backward compatibility.
 * - The real download logic lives in admin-post.php?action=backup_lite_download_backup.
 */
if ( ! defined( 'ABSPATH' ) ) {
    // WordPress not loaded. We do NOT include core bootstrap files here.
    // Best-effort: redirect legacy links (file/expires/token) to admin-post.php.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- redirect stub runs without WordPress loaded; no nonce API available here
    // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw values are sanitized below
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- WordPress not loaded; wp_unslash() unavailable (we use stripslashes() below)
    $file    = isset( $_GET['file'] ) ? (string) $_GET['file'] : '';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- WordPress not loaded; wp_unslash() unavailable (we use stripslashes() below)
    $expires = isset( $_GET['expires'] ) ? (string) $_GET['expires'] : '';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- WordPress not loaded; wp_unslash() unavailable (we use stripslashes() below)
    $token   = isset( $_GET['token'] ) ? (string) $_GET['token'] : '';
    // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    // Best-effort unslash (WordPress is not loaded, so wp_unslash() is not available).
    $file    = is_string( $file ) ? stripslashes( $file ) : '';
    $expires = is_string( $expires ) ? stripslashes( $expires ) : '';
    $token   = is_string( $token ) ? stripslashes( $token ) : '';

    // Minimal sanitization without WP functions.
    $file    = preg_replace( '/[^a-zA-Z0-9._-]/', '', basename( $file ) );
    $expires = preg_replace( '/[^0-9]/', '', $expires );
    $token   = preg_replace( '/[^a-fA-F0-9]/', '', $token );

    if ( '' !== $file ) {
        $qs = 'action=backup_lite_download_backup&file=' . rawurlencode( $file );
        if ( '' !== $expires && '' !== $token ) {
            $qs .= '&expires=' . rawurlencode( $expires ) . '&token=' . rawurlencode( $token );
        }
        header( 'Location: /wp-admin/admin-post.php?' . $qs, true, 302 );
        exit;
    }

    header( 'HTTP/1.1 403 Forbidden' );
    exit;
}

// WordPress is loaded; redirect to the official admin-post download handler.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- this is a redirect stub; validation happens in admin-post handler
$file    = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
$expires = isset( $_GET['expires'] ) ? absint( wp_unslash( $_GET['expires'] ) ) : 0;
$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

if ( '' === $file ) {
    wp_die( esc_html__( 'Invalid backup file.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 400 );
}

$url = admin_url( 'admin-post.php?action=backup_lite_download_backup&file=' . rawurlencode( $file ) );
if ( $expires > 0 && '' !== $token ) {
    $url = add_query_arg(
        [
            'expires' => $expires,
            'token'   => $token,
        ],
        $url
    );
}

wp_safe_redirect( $url, 302 );
exit;
