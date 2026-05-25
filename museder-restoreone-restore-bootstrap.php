<?php
/**
 * Museder RestoreOne — document-root restore bootstrap
 *
 * INSTALL: Copy this file to your WordPress site document root (same folder as wp-config.php
 * will appear after restore). Keep the plugin in wp-content/plugins/museder-restoreone/.
 *
 * Use when the destination has no WordPress core yet (empty docroot). The bootstrap drives
 * file-restore slices via loopback until wp-load.php exists; then WordPress cron continues.
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'MUSEDER_RESTOREONE_BOOTSTRAP_ROOT' ) ) {
    define( 'MUSEDER_RESTOREONE_BOOTSTRAP_ROOT', __DIR__ );
}

$bootstrap_class = MUSEDER_RESTOREONE_BOOTSTRAP_ROOT . '/wp-content/plugins/museder-restoreone/includes/class-restore-bootstrap.php';
if ( ! is_readable( $bootstrap_class ) ) {
    $bootstrap_class = __DIR__ . '/wp-content/plugins/museder-restoreone/includes/class-restore-bootstrap.php';
}
if ( ! is_readable( $bootstrap_class ) ) {
    header( 'Content-Type: text/plain; charset=utf-8', true, 500 );
    echo 'Museder RestoreOne bootstrap: plugin not found. Upload museder-restoreone to wp-content/plugins/ first.';
    exit;
}

require_once $bootstrap_class;

Museder_Restoreone_Restore_Bootstrap::handle_request();
