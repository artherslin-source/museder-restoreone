<?php
/**
 * Approach B — empty docroot bootstrap: drive restore slices before WordPress core exists.
 *
 * Copy museder-restoreone-restore-bootstrap.php from the plugin directory to the site document root.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MUSEDER_RESTOREONE_BOOTSTRAP_ROOT' ) ) {
    exit;
}

if ( ! class_exists( 'WP_Error' ) ) {
    /**
     * Minimal WP_Error for bootstrap-only context.
     */
    class WP_Error {
        /** @var string */
        protected $message = '';

        /**
         * @param string $code    Code.
         * @param string $message Message.
         */
        public function __construct( $code, $message ) {
            $this->message = (string) $message;
        }

        /**
         * @return string
         */
        public function get_error_message() {
            return $this->message;
        }
    }
}

class Museder_Restoreone_Restore_Bootstrap {

    const HANDOFF_FILENAME = 'bootstrap-handoff.json';
    const ACTIVE_JOB_FILENAME = 'bootstrap-active-job.txt';

    /**
     * Entry point for the document-root bootstrap script.
     *
     * @return void
     */
    public static function handle_request() {
        self::register_wordpress_stubs();

        if ( ! self::bootstrap_root() ) {
            self::render_error( 'Bootstrap root is not defined.' );
            return;
        }

        self::load_plugin_stack();

        $job_id = isset( $_REQUEST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['job_id'] ) ) : '';
        $secret = isset( $_REQUEST['secret'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['secret'] ) ) : '';

        if ( isset( $_POST['museder_bootstrap_start'] ) && '' !== $secret ) {
            $backup = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['backup'] ) ) : '';
            $started = self::start_restore_from_bootstrap( $backup, $secret );
            if ( is_wp_error( $started ) ) {
                self::render_page( '', $secret, $started->get_error_message() );
                return;
            }
            $job_id = (string) $started['job_id'];
        }

        if ( '' === $job_id ) {
            $handoff = self::read_handoff();
            $job_id  = isset( $handoff['job_id'] ) ? (string) $handoff['job_id'] : '';
            if ( '' === $secret && ! empty( $handoff['secret'] ) ) {
                $secret = (string) $handoff['secret'];
            }
        }

        if ( '' === $job_id ) {
            self::render_page( '', $secret );
            return;
        }

        if ( ! self::verify_secret( $job_id, $secret ) ) {
            self::render_error( 'Invalid or missing bootstrap secret.' );
            return;
        }

        $max_seconds = 25;
        $completed   = false;
        $message     = '';
        $progress    = 0;

        if ( self::wordpress_is_loadable() ) {
            self::load_wordpress();
            if ( class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
                $end = time() + $max_seconds;
                while ( time() < $end ) {
                    $result = Museder_Restoreone_Restore_Service::process_job_slice( $job_id, 10, false, 'bootstrap' );
                    $meta   = isset( $result['meta'] ) && is_array( $result['meta'] ) ? $result['meta'] : Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
                    $progress = isset( $meta['progress'] ) ? (int) $meta['progress'] : 0;
                    $message  = isset( $meta['message'] ) ? (string) $meta['message'] : '';
                    if ( ! empty( $meta['completed'] ) ) {
                        $completed = true;
                        break;
                    }
                    if ( isset( $meta['stage'] ) && in_array( $meta['stage'], [ 'failed', 'cancelled' ], true ) ) {
                        $completed = true;
                        break;
                    }
                }
                Museder_Restoreone_Restore_Service::spawn_cron_public();
            }
        } else {
            $result = Museder_Restoreone_Restore_Service::process_bootstrap_files_only_slice( $job_id, $max_seconds );
            $meta   = isset( $result['meta'] ) && is_array( $result['meta'] ) ? $result['meta'] : [];
            $progress = isset( $meta['progress'] ) ? (int) $meta['progress'] : 0;
            $message  = isset( $meta['message'] ) ? (string) $meta['message'] : '';
            if ( ! empty( $meta['completed'] ) || ( isset( $meta['stage'] ) && in_array( $meta['stage'], [ 'failed', 'cancelled' ], true ) ) ) {
                $completed = true;
            } elseif ( self::wordpress_is_loadable() ) {
                self::load_wordpress();
                Museder_Restoreone_Restore_Service::spawn_cron_public();
            }
        }

        if ( ! $completed ) {
            self::spawn_loopback( $job_id, $secret );
        }

        self::render_page( $job_id, $secret, '', $progress, $message, $completed );
    }

    /**
     * @return string Normalized document root or empty.
     */
    public static function bootstrap_root() {
        if ( ! defined( 'MUSEDER_RESTOREONE_BOOTSTRAP_ROOT' ) ) {
            return '';
        }
        $root = rtrim( (string) MUSEDER_RESTOREONE_BOOTSTRAP_ROOT, "/\\\n\r\t " );
        if ( function_exists( 'wp_normalize_path' ) ) {
            return wp_normalize_path( $root );
        }
        return str_replace( '\\', '/', $root );
    }

    /**
     * @return bool
     */
    public static function is_bootstrap_context() {
        return '' !== self::bootstrap_root() || ( defined( 'MUSEDER_RESTOREONE_BOOTSTRAP_MODE' ) && MUSEDER_RESTOREONE_BOOTSTRAP_MODE );
    }

    /**
     * @return bool
     */
    public static function wordpress_is_loadable() {
        $root = self::bootstrap_root();
        if ( '' === $root ) {
            return false;
        }
        return is_readable( $root . '/wp-load.php' );
    }

    /**
     * @return void
     */
    public static function load_wordpress() {
        if ( defined( 'ABSPATH' ) && function_exists( 'wp_get_environment_type' ) ) {
            return;
        }
        $root = self::bootstrap_root();
        if ( '' === $root || ! is_readable( $root . '/wp-load.php' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- loading core bootstrap
        require_once $root . '/wp-load.php';
    }

    /**
     * @return void
     */
    public static function register_wordpress_stubs() {
        if ( ! defined( 'MUSEDER_RESTOREONE_BOOTSTRAP_MODE' ) ) {
            define( 'MUSEDER_RESTOREONE_BOOTSTRAP_MODE', true );
        }
        if ( ! function_exists( 'trailingslashit' ) ) {
            /**
             * @param string $string String.
             * @return string
             */
            function trailingslashit( $string ) {
                return rtrim( (string) $string, "/\\\n\r\t " ) . '/';
            }
        }
        if ( ! defined( 'ABSPATH' ) ) {
            $root = self::bootstrap_root();
            define( 'ABSPATH', '' !== $root ? trailingslashit( $root ) : '' );
        }
        if ( ! function_exists( '__' ) ) {
            /**
             * @param string $text Text.
             * @param string $domain Domain.
             * @return string
             */
            function __( $text, $domain = 'default' ) {
                return (string) $text;
            }
        }
        if ( ! function_exists( 'esc_html__' ) ) {
            /**
             * @param string $text Text.
             * @param string $domain Domain.
             * @return string
             */
            function esc_html__( $text, $domain = 'default' ) {
                return (string) $text;
            }
        }
        if ( ! function_exists( 'esc_html' ) ) {
            /**
             * @param string $text Text.
             * @return string
             */
            function esc_html( $text ) {
                return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
            }
        }
        if ( ! function_exists( 'esc_attr' ) ) {
            /**
             * @param string $text Text.
             * @return string
             */
            function esc_attr( $text ) {
                return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
            }
        }
        if ( ! function_exists( 'current_time' ) ) {
            /**
             * @param string $type Type.
             * @param bool   $gmt  GMT.
             * @return int|string
             */
            function current_time( $type, $gmt = false ) {
                if ( 'timestamp' === $type ) {
                    return $gmt ? time() : time();
                }
                return gmdate( 'Y-m-d H:i:s', time() );
            }
        }
        if ( ! function_exists( 'wp_parse_args' ) ) {
            /**
             * @param array $args     Args.
             * @param array $defaults Defaults.
             * @return array
             */
            function wp_parse_args( $args, $defaults = [] ) {
                if ( is_object( $args ) ) {
                    $args = get_object_vars( $args );
                }
                if ( ! is_array( $args ) ) {
                    $args = [];
                }
                if ( ! is_array( $defaults ) ) {
                    $defaults = [];
                }
                return array_merge( $defaults, $args );
            }
        }
        if ( ! function_exists( 'wp_json_encode' ) ) {
            /**
             * @param mixed $data Data.
             * @param int   $opts Options.
             * @return string|false
             */
            function wp_json_encode( $data, $options = 0 ) {
                return json_encode( $data, $options );
            }
        }
        if ( ! function_exists( 'wp_normalize_path' ) ) {
            /**
             * @param string $path Path.
             * @return string
             */
            function wp_normalize_path( $path ) {
                $path = str_replace( '\\', '/', (string) $path );
                return preg_replace( '|(?<=.)/+|', '/', $path );
            }
        }
        if ( ! function_exists( 'sanitize_file_name' ) ) {
            /**
             * @param string $filename Filename.
             * @return string
             */
            function sanitize_file_name( $filename ) {
                $filename = preg_replace( '/[^a-zA-Z0-9._\-]/', '', (string) $filename );
                return (string) $filename;
            }
        }
        if ( ! function_exists( 'sanitize_text_field' ) ) {
            /**
             * @param string $str String.
             * @return string
             */
            function sanitize_text_field( $str ) {
                return trim( wp_strip_all_tags( (string) $str ) );
            }
        }
        if ( ! function_exists( 'wp_strip_all_tags' ) ) {
            /**
             * @param string $string String.
             * @return string
             */
            function wp_strip_all_tags( $string ) {
                return strip_tags( (string) $string );
            }
        }
        if ( ! function_exists( 'wp_unslash' ) ) {
            /**
             * @param mixed $value Value.
             * @return mixed
             */
            function wp_unslash( $value ) {
                return is_string( $value ) ? stripslashes( $value ) : $value;
            }
        }
        if ( ! function_exists( 'wp_mkdir_p' ) ) {
            /**
             * @param string $target Target dir.
             * @return bool
             */
            function wp_mkdir_p( $target ) {
                $target = wp_normalize_path( (string) $target );
                if ( file_exists( $target ) ) {
                    return is_dir( $target );
                }
                return mkdir( $target, 0755, true );
            }
        }
        if ( ! function_exists( 'wp_is_writable' ) ) {
            /**
             * @param string $path Path.
             * @return bool
             */
            function wp_is_writable( $path ) {
                return is_writable( $path );
            }
        }
        if ( ! function_exists( 'apply_filters' ) ) {
            /**
             * @param string $tag  Tag.
             * @param mixed  $value Value.
             * @return mixed
             */
            function apply_filters( $tag, $value ) {
                return $value;
            }
        }
        if ( ! function_exists( 'wp_rand' ) ) {
            /**
             * @param int $min Min.
             * @param int $max Max.
             * @return int
             */
            function wp_rand( $min = 0, $max = 0 ) {
                return random_int( (int) $min, (int) $max );
            }
        }
        if ( ! function_exists( 'add_query_arg' ) ) {
            /**
             * @param array  $args Args.
             * @param string $url  URL.
             * @return string
             */
            function add_query_arg( $args, $url ) {
                $sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
                $pairs = [];
                foreach ( $args as $key => $value ) {
                    $pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
                }
                return $url . $sep . implode( '&', $pairs );
            }
        }
        if ( ! function_exists( 'wp_parse_url' ) ) {
            /**
             * @param string $url URL.
             * @return array<string, mixed>
             */
            function wp_parse_url( $url ) {
                $parts = parse_url( $url );
                return is_array( $parts ) ? $parts : [];
            }
        }
        if ( ! function_exists( 'is_wp_error' ) ) {
            /**
             * @param mixed $thing Thing.
             * @return bool
             */
            function is_wp_error( $thing ) {
                return is_object( $thing ) && class_exists( 'WP_Error' ) && $thing instanceof WP_Error;
            }
        }
        if ( ! function_exists( 'get_option' ) ) {
            /**
             * @param string $option  Option name.
             * @param mixed  $default Default.
             * @return mixed
             */
            function get_option( $option, $default = false ) {
                $path = museder_restoreone_bootstrap_option_path( $option );
                if ( ! is_readable( $path ) ) {
                    return $default;
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $raw = file_get_contents( $path );
                $val = json_decode( (string) $raw, true );
                return null === $val ? $default : $val;
            }
        }
        if ( ! function_exists( 'update_option' ) ) {
            /**
             * @param string $option Option.
             * @param mixed  $value  Value.
             * @return bool
             */
            function update_option( $option, $value ) {
                $path = museder_restoreone_bootstrap_option_path( $option );
                $dir  = dirname( $path );
                if ( ! is_dir( $dir ) ) {
                    wp_mkdir_p( $dir );
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                return false !== file_put_contents( $path, wp_json_encode( $value ), LOCK_EX );
            }
        }
        if ( ! function_exists( 'delete_option' ) ) {
            /**
             * @param string $option Option.
             * @return bool
             */
            function delete_option( $option ) {
                $path = museder_restoreone_bootstrap_option_path( $option );
                if ( file_exists( $path ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    return unlink( $path );
                }
                return true;
            }
        }
        if ( ! function_exists( 'museder_restoreone_bootstrap_option_path' ) ) {
            /**
             * @param string $option Option key.
             * @return string
             */
            function museder_restoreone_bootstrap_option_path( $option ) {
                $root = function_exists( 'museder_restoreone_get_storage_root' ) ? museder_restoreone_get_storage_root() : [ 'path' => '' ];
                $base = trailingslashit( isset( $root['path'] ) ? (string) $root['path'] : '' ) . 'bootstrap-options';
                return $base . '/' . preg_replace( '/[^a-zA-Z0-9._-]/', '_', (string) $option ) . '.json';
            }
        }
    }

    /**
     * @return void
     */
    public static function load_plugin_stack() {
        $plugin = self::resolve_plugin_path();
        if ( '' === $plugin || ! is_readable( $plugin ) ) {
            return;
        }

        if ( ! defined( 'MUSEDER_RESTOREONE_PATH' ) ) {
            define( 'MUSEDER_RESTOREONE_PATH', trailingslashit( dirname( $plugin ) ) );
        }
        if ( ! defined( 'MUSEDER_RESTOREONE_VERSION' ) ) {
            define( 'MUSEDER_RESTOREONE_VERSION', '2.7.268' );
        }

        require_once MUSEDER_RESTOREONE_PATH . 'includes/helpers.php';
        require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-lock.php';
        require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-token.php';
        require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-preflight.php';
        require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-service.php';
    }

    /**
     * @return string Plugin main file path or empty.
     */
    public static function resolve_plugin_path() {
        $root = self::bootstrap_root();
        if ( '' === $root ) {
            return '';
        }
        $candidates = [
            $root . '/wp-content/plugins/museder-restoreone/museder-restoreone.php',
            dirname( __DIR__ ) . '/museder-restoreone.php',
        ];
        foreach ( $candidates as $path ) {
            if ( is_readable( $path ) ) {
                return wp_normalize_path( $path );
            }
        }
        return '';
    }

    /**
     * @return string Handoff file path.
     */
    public static function handoff_path() {
        return trailingslashit( museder_restoreone_get_storage_root()['path'] ) . self::HANDOFF_FILENAME;
    }

    /**
     * @param string $job_id Job ID.
     * @param string $secret Secret.
     * @return void
     */
    public static function write_handoff( $job_id, $secret = '' ) {
        if ( '' === $secret ) {
            $secret = self::generate_secret( $job_id );
        }
        $payload = [
            'job_id'        => (string) $job_id,
            'secret'        => (string) $secret,
            'bootstrap_url' => self::bootstrap_url(),
            'updated_at'    => gmdate( 'c' ),
        ];
        $path = self::handoff_path();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $path, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
        self::write_active_job_pointer( $job_id );
    }

    /**
     * @return array<string, mixed>
     */
    public static function read_handoff() {
        $path = self::handoff_path();
        if ( ! is_readable( $path ) ) {
            return [];
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents( $path );
        $data = json_decode( (string) $raw, true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * @param string $job_id Job ID.
     * @return string
     */
    public static function generate_secret( $job_id ) {
        return hash_hmac( 'sha256', (string) $job_id . '|bootstrap', (string) ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'museder-restoreone-bootstrap' ) );
    }

    /**
     * @param string $job_id  Job ID.
     * @param string $secret Secret.
     * @return bool
     */
    public static function verify_secret( $job_id, $secret ) {
        if ( '' === $job_id || '' === $secret ) {
            return false;
        }
        $handoff = self::read_handoff();
        if ( ! empty( $handoff['secret'] ) && hash_equals( (string) $handoff['secret'], (string) $secret ) ) {
            return (string) ( $handoff['job_id'] ?? '' ) === (string) $job_id;
        }
        return hash_equals( self::generate_secret( $job_id ), (string) $secret );
    }

    /**
     * @return string Public bootstrap URL.
     */
    public static function bootstrap_url() {
        if ( function_exists( 'home_url' ) ) {
            return home_url( '/museder-restoreone-restore-bootstrap.php' );
        }
        if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
            $scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
            return $scheme . '://' . sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) . '/museder-restoreone-restore-bootstrap.php';
        }
        return '/museder-restoreone-restore-bootstrap.php';
    }

    /**
     * @param string $job_id Job ID.
     * @return void
     */
    public static function write_active_job_pointer( $job_id ) {
        $path = trailingslashit( museder_restoreone_get_storage_root()['path'] ) . self::ACTIVE_JOB_FILENAME;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $path, (string) $job_id, LOCK_EX );
    }

    /**
     * @param string $job_id Job ID.
     * @param string $secret Secret.
     * @return void
     */
    public static function spawn_loopback( $job_id, $secret ) {
        $url = add_query_arg(
            [
                'job_id' => rawurlencode( (string) $job_id ),
                'secret' => rawurlencode( (string) $secret ),
                'tick'   => '1',
            ],
            self::bootstrap_url()
        );

        if ( function_exists( 'wp_remote_post' ) ) {
            wp_remote_post(
                $url,
                [
                    'timeout'    => 0.01,
                    'blocking'   => false,
                    'user-agent' => 'Museder RestoreOne Bootstrap',
                ]
            );
            return;
        }

        $parts = wp_parse_url( $url );
        $host  = isset( $parts['host'] ) ? $parts['host'] : '127.0.0.1';
        $port  = isset( $parts['port'] ) ? (int) $parts['port'] : ( ( isset( $parts['scheme'] ) && 'https' === $parts['scheme'] ) ? 443 : 80 );
        $path  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
        $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
        $target = $scheme . '://' . $host . ( $port && 80 !== $port && 443 !== $port ? ':' . $port : '' ) . $path;
        $ctx = stream_context_create(
            [
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 0.2,
                ],
            ]
        );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        @file_get_contents( $target, false, $ctx );
    }

    /**
     * Nudge bootstrap from WordPress admin/cron context.
     *
     * @param string $job_id Job ID.
     * @return void
     */
    public static function nudge_from_wordpress( $job_id ) {
        $handoff = self::read_handoff();
        if ( empty( $handoff['job_id'] ) || (string) $handoff['job_id'] !== (string) $job_id ) {
            $secret = self::generate_secret( $job_id );
            self::write_handoff( $job_id, $secret );
            $handoff = self::read_handoff();
        }
        $secret = isset( $handoff['secret'] ) ? (string) $handoff['secret'] : self::generate_secret( $job_id );
        $root_script = trailingslashit( self::bootstrap_root_from_wp() ) . 'museder-restoreone-restore-bootstrap.php';
        if ( ! file_exists( $root_script ) ) {
            return;
        }
        self::spawn_loopback( $job_id, $secret );
    }

    /**
     * @return string
     */
    public static function bootstrap_root_from_wp() {
        if ( function_exists( 'museder_restoreone_get_wp_root_dir' ) ) {
            return museder_restoreone_get_wp_root_dir();
        }
        return '';
    }

    /**
     * Whether this job should use bootstrap handoff.
     *
     * @param array<string, mixed> $options Job options.
     * @param string               $profile Restore profile.
     * @return bool
     */
    public static function should_use_bootstrap_handoff( array $options, $profile ) {
        if ( ! empty( $options['bootstrap_mode'] ) ) {
            return true;
        }
        return Museder_Restoreone_Restore_Preflight::PROFILE_EMPTY === (string) $profile;
    }

    /**
     * Start a restore from the bootstrap page (no WP admin).
     *
     * @param string $backup_basename Backup zip basename in uploads storage backups/.
     * @param string $secret          Shared secret from operator.
     * @return array{job_id:string}|WP_Error
     */
    public static function start_restore_from_bootstrap( $backup_basename, $secret ) {
        if ( '' === $backup_basename ) {
            return new WP_Error( 'museder_bootstrap', 'Backup file name is required.' );
        }
        if ( strlen( (string) $secret ) < 8 ) {
            return new WP_Error( 'museder_bootstrap', 'Bootstrap secret must be at least 8 characters.' );
        }

        if ( ! class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
            return new WP_Error( 'museder_bootstrap', 'Restore service is not available.' );
        }

        $options = Museder_Restoreone_Restore_Preflight::normalize_options(
            [
                'restore_profile'    => Museder_Restoreone_Restore_Preflight::PROFILE_EMPTY,
                'restore_order'      => Museder_Restoreone_Restore_Preflight::ORDER_FILES_THEN_DB,
                'auto_backup'        => false,
                'overwrite'          => true,
                'pause_other_plugins'=> false,
                'bootstrap_mode'     => true,
            ]
        );

        try {
            $prep   = Museder_Restoreone_Restore_Service::prepare( 'existing', $backup_basename );
            $job_id = (string) $prep['job_id'];
            Museder_Restoreone_Restore_Service::mark_job_validated_for_bootstrap( $job_id );
            $options['bootstrap_secret'] = $secret;
            Museder_Restoreone_Restore_Service::execute( $job_id, $options );
            self::write_handoff( $job_id, $secret );
            self::spawn_loopback( $job_id, $secret );
            return [ 'job_id' => $job_id ];
        } catch ( Exception $e ) {
            return new WP_Error( 'museder_bootstrap', $e->getMessage() );
        }
    }

    /**
     * @param string $job_id     Job ID.
     * @param string $secret     Secret.
     * @param string $error      Error message.
     * @param int    $progress   Progress.
     * @param string $message    Status message.
     * @param bool   $completed  Completed flag.
     * @return void
     */
    protected static function render_page( $job_id = '', $secret = '', $error = '', $progress = 0, $message = '', $completed = false ) {
        header( 'Content-Type: text/html; charset=utf-8' );
        if ( ! $completed && '' !== $job_id ) {
            header( 'Refresh: 15' );
        }
        $backups = [];
        $dir     = function_exists( 'museder_restoreone_get_backup_dir' ) ? museder_restoreone_get_backup_dir() : '';
        if ( $dir && is_dir( $dir ) ) {
            $files = glob( trailingslashit( $dir ) . '*.zip' );
            if ( is_array( $files ) ) {
                $backups = array_map( 'basename', $files );
            }
        }
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Museder RestoreOne Bootstrap</title></head><body style="font-family:sans-serif;max-width:720px;margin:2rem auto;">';
        echo '<h1>Museder RestoreOne — Restore Bootstrap</h1>';
        if ( '' !== $error ) {
            echo '<p style="color:#b32d2e;">' . esc_html( $error ) . '</p>';
        }
        if ( '' !== $job_id ) {
            echo '<p><strong>Job:</strong> ' . esc_html( $job_id ) . '</p>';
            echo '<p><strong>Progress:</strong> ' . esc_html( (string) $progress ) . '%</p>';
            if ( '' !== $message ) {
                echo '<p>' . esc_html( $message ) . '</p>';
            }
            if ( $completed ) {
                echo '<p><strong>Status:</strong> finished (open your site or wp-admin when ready).</p>';
            } else {
                echo '<p>This page refreshes every 15 seconds until the restore completes. You may close the tab; WP-Cron or this bootstrap URL will continue slices.</p>';
            }
        } else {
            echo '<p>Upload the plugin to <code>wp-content/plugins/museder-restoreone</code>, copy <code>museder-restoreone-restore-bootstrap.php</code> to this site root, place a <code>.zip</code> backup under <code>wp-content/uploads/museder-restoreone/backups/</code>, then start below.</p>';
            echo '<form method="post"><input type="hidden" name="museder_bootstrap_start" value="1">';
            echo '<p><label>Backup file: <select name="backup">';
            foreach ( $backups as $file ) {
                echo '<option value="' . esc_attr( $file ) . '">' . esc_html( $file ) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label>Bootstrap secret (min 8 chars): <input type="password" name="secret" required minlength="8" style="width:100%;"></label></p>';
            echo '<p><button type="submit">Start full-site restore (files first)</button></p></form>';
        }
        echo '</body></html>';
    }

    /**
     * @param string $message Message.
     * @return void
     */
    protected static function render_error( $message ) {
        self::render_page( '', '', $message );
    }
}
