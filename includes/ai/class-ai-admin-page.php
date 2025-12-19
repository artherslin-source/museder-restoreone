<?php
/**
 * AI admin integration (Dashboard card helper only; no menu pages).
 *
 * @package MusederRestoreOne
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_AI_Admin_Page {
    /**
     * Init hooks.
     *
     * @return void
     */
    public static function init(): void {
        // Intentionally minimal in Phase 0.
        // Dashboard card is rendered by PHP template and uses repository directly.
    }

    /**
     * Convenience helper for templates.
     *
     * @param int $limit Max number of reports.
     * @return array<int,array<string,mixed>>
     */
    public static function get_recent_reports( int $limit = 5 ): array {
        $repo = new Museder_AI_Report_Repository();
        return $repo->list_reports( $limit );
    }
}


