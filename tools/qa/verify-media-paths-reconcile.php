<?php
/**
 * Verify post-restore media path reconciliation advances past build_index and fixes
 * UTF-8 DB paths that map to ASCII on-disk upload filenames.
 *
 * Usage: php tools/qa/verify-media-paths-reconcile.php
 *
 * @package MusederRestoreOne
 */

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

$root = dirname( __DIR__, 2 );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$failures = 0;

function mro_media_qa_fail( $message ) {
	global $failures;
	fwrite( STDERR, "FAIL: {$message}\n" );
	++$failures;
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		unset( $tag );
		return $value;
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $path ) {
		return rtrim( (string) $path, "/\\" ) . '/';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return [ 'basedir' => $GLOBALS['mro_media_qa_uploads'] ];
	}
}

if ( ! function_exists( 'museder_restoreone_safe_path_join' ) ) {
	function museder_restoreone_safe_path_join( $base, $rel ) {
		$base = wp_normalize_path( rtrim( (string) $base, '/\\' ) );
		$path = wp_normalize_path( $base . '/' . ltrim( (string) $rel, '/\\' ) );
		return 0 === strpos( $path, $base . '/' ) ? $path : '';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $post_id, $key, $single );
		return [];
	}
}

if ( ! function_exists( 'museder_restoreone_log' ) ) {
	function museder_restoreone_log( $level, $message, $context = [] ) {
		$GLOBALS['mro_media_qa_logs'][] = [
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		];
	}
}

class Mro_Media_Qa_Wpdb {
	public $postmeta = 'wp_postmeta';
	public $rows = [];

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$value = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $value, $query, 1 );
		}
		return $query;
	}

	public function get_results( $query, $output = ARRAY_A ) {
		unset( $output );
		$after = 0;
		if ( preg_match( '/meta_id\s*>\s*(\d+)/', (string) $query, $match ) ) {
			$after = (int) $match[1];
		}
		$limit = 100;
		if ( preg_match( '/LIMIT\s+(\d+)/i', (string) $query, $match ) ) {
			$limit = (int) $match[1];
		}
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['meta_id'] <= $after ) {
				continue;
			}
			$out[] = $row;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		unset( $table, $format, $where_format );
		$meta_id = isset( $where['meta_id'] ) ? (int) $where['meta_id'] : 0;
		foreach ( $this->rows as &$row ) {
			if ( (int) $row['meta_id'] === $meta_id ) {
				$row['meta_value'] = (string) $data['meta_value'];
				return 1;
			}
		}
		return 0;
	}
}

require_once $root . '/includes/class-restore-media-paths.php';

$tmp = sys_get_temp_dir() . '/mro-media-paths-qa-' . getmypid();
$GLOBALS['mro_media_qa_uploads'] = $tmp . '/uploads';
$GLOBALS['mro_media_qa_logs']    = [];

$media_dir = $GLOBALS['mro_media_qa_uploads'] . '/2024/04';
if ( ! is_dir( $media_dir ) && ! mkdir( $media_dir, 0700, true ) ) {
	mro_media_qa_fail( 'could not create media fixture directory' );
} else {
	file_put_contents( $media_dir . '/LINE_ALBUM__240409_7.jpg', 'fixture' );

	$GLOBALS['wpdb'] = new Mro_Media_Qa_Wpdb();
	$GLOBALS['wpdb']->rows = [
		[
			'meta_id'    => 1,
			'post_id'    => 3600,
			'meta_value' => '2024/04/LINE_ALBUM_新莊國小跑道清洗前_240409_7.jpg',
		],
	];

	$drift_before = Museder_Restoreone_Restore_Media_Paths::detect_upload_path_drift( 10, 'qa_media_paths' );
	if ( ! is_array( $drift_before ) || 1 !== (int) ( $drift_before['drift'] ?? 0 ) ) {
		mro_media_qa_fail( 'drift detector should find the UTF-8 DB path before reconcile' );
	}

	$cleanup = [];
	$done    = false;
	for ( $i = 0; $i < 8; $i++ ) {
		$result = Museder_Restoreone_Restore_Media_Paths::reconcile_sliced( $cleanup, 5, microtime( true ), 'qa_media_paths' );
		if ( ! empty( $result['done'] ) ) {
			$done = true;
			break;
		}
	}

	if ( ! $done || 'done' !== ( $cleanup['media_paths_phase'] ?? '' ) ) {
		mro_media_qa_fail( 'reconcile_sliced should finish after progressing through scan/apply/verify' );
	}
	if ( empty( $cleanup['media_paths_scanned'] ) ) {
		mro_media_qa_fail( 'reconcile_sliced should scan attachment meta rows' );
	}
	if ( empty( $cleanup['media_paths_fixed'] ) ) {
		mro_media_qa_fail( 'reconcile_sliced should fix UTF-8 DB path to ASCII disk path' );
	}
	if ( '2024/04/LINE_ALBUM__240409_7.jpg' !== $GLOBALS['wpdb']->rows[0]['meta_value'] ) {
		mro_media_qa_fail( 'postmeta value should be updated to ASCII disk path' );
	}

	$noop_logged = false;
	foreach ( $GLOBALS['mro_media_qa_logs'] as $log ) {
		if ( 'MEDIA_PATHS_RECONCILE_DONE' === ( $log['message'] ?? '' )
			&& 'noop' === ( $log['context']['reason'] ?? '' ) ) {
			$noop_logged = true;
		}
	}
	if ( $noop_logged ) {
		mro_media_qa_fail( 'reconcile should not log noop when uploads and DB rows exist' );
	}

	$drift = Museder_Restoreone_Restore_Media_Paths::detect_upload_path_drift( 10, 'qa_media_paths' );
	if ( ! is_array( $drift ) || 0 !== (int) ( $drift['drift'] ?? -1 ) ) {
		mro_media_qa_fail( 'drift detector should report zero drift after reconcile fix' );
	}
}

if ( is_dir( $tmp ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}
	rmdir( $tmp );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "MEDIA_PATHS_RECONCILE_QA=FAIL ({$failures} checks)\n" );
	exit( 1 );
}

echo "MEDIA_PATHS_RECONCILE_QA=PASS\n";
