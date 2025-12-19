<?php
/**
 * Free AI provider (mock, no external network calls).
 *
 * @package MusederRestoreOne
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_AI_Provider_Free implements Museder_AI_Provider_Interface {
    /**
     * {@inheritdoc}
     */
    public function scan( array $payload, array $context = [] ): array {
        $generated_at_gmt = function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        $items = [
            [
                'key'            => 'backup_frequency',
                'severity'       => 'high',
                'title'          => __( 'Backup frequency review', 'museder-restoreone' ),
                'description'    => __( 'Make sure you have at least one automated schedule and a recent successful backup.', 'museder-restoreone' ),
                'recommendation' => __( 'Create a daily schedule and verify the next run time on the Dashboard.', 'museder-restoreone' ),
            ],
            [
                'key'            => 'storage_hygiene',
                'severity'       => 'medium',
                'title'          => __( 'Storage hygiene', 'museder-restoreone' ),
                'description'    => __( 'Old archives and temporary files can bloat your storage and slow down operations.', 'museder-restoreone' ),
                'recommendation' => __( 'Keep only the backups you need and clean up temporary folders regularly.', 'museder-restoreone' ),
            ],
            [
                'key'            => 'restore_readiness',
                'severity'       => 'info',
                'title'          => __( 'Restore readiness', 'museder-restoreone' ),
                'description'    => __( 'A backup is only valuable if you can restore it quickly when needed.', 'museder-restoreone' ),
                'recommendation' => __( 'Run a restore dry-run on a staging site to validate your latest archive.', 'museder-restoreone' ),
            ],
        ];

        return [
            'meta'    => [
                'generated_at_gmt' => $generated_at_gmt,
                'provider'         => 'free',
                'version'          => defined( 'BACKUP_LITE_VERSION' ) ? BACKUP_LITE_VERSION : '',
            ],
            'summary' => __( 'Preview scan completed. Upgrade to Pro to unlock deeper analysis and advanced recommendations.', 'museder-restoreone' ),
            'items'   => $items,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_capabilities(): array {
        return [
            'mode'         => 'mock',
            'networkCalls' => false,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_limits(): array {
        $limit = (int) apply_filters( 'museder_ai_free_daily_limit', 3 );
        return [
            'dailyScans' => max( 0, $limit ),
        ];
    }
}


