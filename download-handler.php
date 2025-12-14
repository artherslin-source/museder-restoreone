<?php
/**
 * Deprecated download handler (stub).
 *
 * This file used to be a direct-access download endpoint. For WordPress.org compliance,
 * downloads are now handled via WordPress `admin-post.php` routes (see
 * `admin_post_backup_lite_download_backup`).
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Do not process downloads here. Downloads are handled via WordPress routes.
if ( function_exists( 'status_header' ) ) {
    status_header( 410 );
}
exit;
