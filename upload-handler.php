<?php
/**
 * Deprecated upload handler (stub).
 *
 * This file used to be a standalone upload endpoint. For WordPress.org compliance,
 * uploads are handled via WordPress routes (REST/admin-ajax) with capability and
 * nonce checks.
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Do not process uploads here. Uploads are handled via WordPress routes.
if ( function_exists( 'status_header' ) ) {
    status_header( 410 );
}
exit;
