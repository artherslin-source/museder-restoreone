<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Upload_Secret {

    const SECRET_FILENAME = 'upload-secret.php';

    public static function init() {
        static $initialized = false;

        if ( $initialized ) {
            return;
        }

        $initialized = true;

        $secret = self::ensure_secret();

        if ( ! defined( 'BACKUP_LITE_UPLOAD_SECRET' ) ) {
            define( 'BACKUP_LITE_UPLOAD_SECRET', $secret );
        }

    }

    public static function get_secret() {
        if ( defined( 'BACKUP_LITE_UPLOAD_SECRET' ) ) {
            return BACKUP_LITE_UPLOAD_SECRET;
        }

        return self::ensure_secret();
    }

    private static function ensure_secret() {
        $path = self::get_secret_file_path();

        if ( ! file_exists( $path ) ) {
            self::write_secret( $path, self::generate_secret() );
        }

        $secret = include $path;

        if ( ! self::is_valid_secret( $secret ) ) {
            $secret = self::generate_secret();
            self::write_secret( $path, $secret );
        }

        return $secret;
    }

    private static function get_secret_file_path() {
        $root = backup_lite_get_storage_root();
        $dir  = trailingslashit( $root['path'] );
        backup_lite_ensure_directory( $dir );

        return $dir . self::SECRET_FILENAME;
    }

    private static function write_secret( $path, $secret ) {
        $contents = "<?php\nreturn '" . addslashes( $secret ) . "';\n";
        file_put_contents( $path, $contents, LOCK_EX );
        @chmod( $path, 0640 );
    }

    private static function generate_secret() {
        if ( function_exists( 'wp_generate_password' ) ) {
            return wp_generate_password( 64, false, false );
        }

        try {
            return bin2hex( random_bytes( 32 ) );
        } catch ( Exception $e ) {
            return sha1( uniqid( 'museder-restoreone', true ) );
        }
    }

    private static function is_valid_secret( $secret ) {
        return is_string( $secret ) && strlen( $secret ) >= 32;
    }

}
