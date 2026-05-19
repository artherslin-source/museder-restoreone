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
 * - The real download logic lives in admin-post.php?action=museder_restoreone_download_backup.
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// WordPress is loaded; redirect to the official admin-post download handler.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- this is a redirect stub; validation happens in admin-post handler
$file    = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
$expires = isset( $_GET['expires'] ) ? absint( wp_unslash( $_GET['expires'] ) ) : 0;
$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

if ( '' === $file ) {
    wp_die( esc_html__( 'Invalid backup file.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 400 );
}

$url = admin_url( 'admin-post.php?action=museder_restoreone_download_backup&file=' . rawurlencode( $file ) );
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
