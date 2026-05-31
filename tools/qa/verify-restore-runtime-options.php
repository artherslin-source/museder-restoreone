<?php
define( 'ABSPATH', __DIR__ . '/../../' );

require_once __DIR__ . '/../../includes/class-restore.php';

$cases = [
	'museder_restoreone_active_job'                    => true,
	'museder_restoreone_job_lock_900d4241-bc23-4cbf'  => true,
	'museder_restoreone_restore_lock'                  => true,
	'museder_restoreone_restore_service_active_job_id' => true,
	'museder_restoreone_restore_token'                 => true,
	'museder_restoreone_restore_post_complete_access'  => true,
	'museder_restoreone_mid_restore_isolation'         => true,
	'_site_transient_museder_restoreone_restore_lock'  => true,
	'_site_transient_timeout_museder_restoreone_lock'  => true,
	'_transient_museder_restoreone_restore_lock'       => true,
	'siteurl'                                          => false,
	'home'                                             => false,
	'active_plugins'                                   => false,
	'widget_media_image'                               => false,
	'woocommerce_store_address'                        => false,
];

$failures = [];
foreach ( $cases as $option_name => $expected ) {
	$actual = Museder_Restoreone_Restore::is_restoreone_runtime_option_name( $option_name );
	if ( $actual !== $expected ) {
		$failures[] = sprintf(
			'%s expected %s got %s',
			$option_name,
			$expected ? 'true' : 'false',
			$actual ? 'true' : 'false'
		);
	}
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "restore runtime option filter: PASS\n";
