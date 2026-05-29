<?php
define( 'MUSEDER_RESTOREONE_BOOTSTRAP_ROOT', '/var/www/html' );
require '/var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-bootstrap.php';
Museder_Restoreone_Restore_Bootstrap::register_wordpress_stubs();
$job_id = $argv[1] ?? '';
echo Museder_Restoreone_Restore_Bootstrap::generate_secret( $job_id ) . "\n";
