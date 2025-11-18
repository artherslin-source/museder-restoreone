<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Restore_Report {

    public static function write_txt( $job_id, $name, array $data ) {
        $path = self::report_path( $job_id, $name, 'txt' );
        $content = self::format_text( $data );
        file_put_contents( $path, $content );
        return $path;
    }

    public static function write_json( $job_id, $name, array $data ) {
        $path = self::report_path( $job_id, $name, 'json' );
        file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        return $path;
    }

    public static function download( $job_id, $name, $format ) {
        $path = self::report_path( $job_id, $name, $format );
        if ( ! file_exists( $path ) ) {
            return new WP_Error( 'backup_lite_missing_report', __( 'Report not found.', 'museder-restoreone' ), [ 'status' => 404 ] );
        }

        return $path;
    }

    protected static function report_path( $job_id, $name, $ext ) {
        $dir = trailingslashit( backup_lite_get_reports_dir() ) . $job_id;
        if ( ! file_exists( $dir ) ) {
            backup_lite_ensure_directory( $dir );
        }

        $sanitized = sanitize_key( $name );
        if ( empty( $sanitized ) ) {
            $sanitized = 'report';
        }

        $filename = $sanitized . '.' . strtolower( $ext );

        return trailingslashit( $dir ) . $filename;
    }

    protected static function format_text( array $data, $indent = 0 ) {
        $lines = [];
        $prefix = str_repeat( '  ', $indent );

        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $lines[] = sprintf( '%s%s:', $prefix, $key );
                $lines[] = self::format_text( $value, $indent + 1 );
            } else {
                $lines[] = sprintf( '%s%s: %s', $prefix, $key, $value );
            }
        }

        return implode( PHP_EOL, $lines ) . PHP_EOL;
    }
}
