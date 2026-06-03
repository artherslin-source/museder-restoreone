<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File-system-based restore authentication token.
 *
 * Generated when a restore job starts (execute), stored as a file in the
 * plugin's temp directory.  Because it lives on disk rather than in
 * wp_options, it survives the DB DROP+REBUILD that happens during
 * import_database_from_ndjson().
 *
 * Used when WP nonces/sessions are invalid mid-restore (the normal
 * session tokens are destroyed when wp_usermeta is replaced by the backup).
 * Progress AJAX registers wp_ajax_nopriv_* handlers that accept this token only.
 *
 * Security model (mirrors AI1WM secret_key pattern, WP.org approved):
 * - Token is generated with wp_generate_password(64, false) — cryptographic.
 * - Stored as HMAC-SHA256 digest using plugin-controlled restore secret (not plaintext).
 * - Has a 2-hour TTL.
 * - Automatically revoked when restore completes or is cancelled.
 * - Post-complete: a short-lived grant allows read-only job_status/tick with the same token.
 * - Only serves as fallback; WP nonce is checked first when the user is logged in.
 */
class Museder_Restoreone_Restore_Token {

	const TOKEN_FILENAME       = '.restore-auth-token';
	const SECRET_FILENAME      = '.restore-auth-secret';
	const RAW_TOKEN_KEY        = 'raw_token';
	const TOKEN_TTL            = 2 * HOUR_IN_SECONDS;
	const POST_COMPLETE_OPTION = 'museder_restoreone_restore_post_complete_access';

	/**
	 * Generate and persist a new restore token.
	 *
	 * @param string $job_id Restore job identifier.
	 * @return string Raw token value (to be sent to the browser).
	 */
	public static function generate( $job_id ) {
		$raw_token = wp_generate_password( 64, false );
		$token_hash = self::stable_token_hash( $raw_token );
		$payload   = [
			'token'      => $token_hash, // legacy key kept for compatibility with re-injection flow.
			'token_hash' => $token_hash,
			self::RAW_TOKEN_KEY => $raw_token,
			'hash_alg'   => 'hmac_sha256_restore_secret_v1',
			'job_id'     => sanitize_text_field( $job_id ),
			'user_id'    => get_current_user_id(),
			'created_at' => time(),
			'expires_at' => time() + self::TOKEN_TTL,
		];

		$file = self::token_file_path();
		$dir  = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-controlled temp directory
		file_put_contents( $file, wp_json_encode( $payload ) );

		// Mirror a redacted payload to wp_options (will be re-injected after DB import by restore_session_after_import).
		$option_payload = self::redact_raw_token( $payload );
		update_option( 'museder_restoreone_restore_token', $option_payload, false );

		return $raw_token;
	}

	/**
	 * Verify a raw token value.
	 *
	 * @param string $raw_token Token from the client header/param.
	 * @param string $job_id    Optional job ID for extra binding.
	 * @return bool
	 */
	public static function verify( $raw_token, $job_id = '' ) {
		$file = self::token_file_path();
		if ( ! file_exists( $file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- reading plugin-controlled token file
		$raw     = file_get_contents( $file );
		$payload = json_decode( $raw, true );

		if ( ! is_array( $payload ) || ( empty( $payload['token'] ) && empty( $payload['token_hash'] ) ) ) {
			return false;
		}

		// Expiry check.
		if ( isset( $payload['expires_at'] ) && time() > (int) $payload['expires_at'] ) {
			self::revoke();
			return false;
		}

		$stored_hash = '';
		if ( ! empty( $payload['token_hash'] ) ) {
			$stored_hash = (string) $payload['token_hash'];
		} elseif ( ! empty( $payload['token'] ) ) {
			$stored_hash = (string) $payload['token'];
		}
		if ( '' === $stored_hash ) {
			return false;
		}
		$expected_hash = self::stable_token_hash( $raw_token );
		$legacy_hash   = wp_hash( $raw_token );
		$hash_ok       = hash_equals( $stored_hash, $expected_hash ) || hash_equals( $stored_hash, $legacy_hash );
		if ( ! $hash_ok ) {
			return false;
		}

		// Optional job binding.
		if ( '' !== $job_id && isset( $payload['job_id'] ) && $payload['job_id'] !== $job_id ) {
			return false;
		}

		return true;
	}

	/**
	 * Read-only access after revoke(): same raw token + completed job meta.
	 *
	 * @param string $raw_token Token from the client header/param.
	 * @param string $job_id    Bound restore job id.
	 * @return bool
	 */
	public static function verify_post_complete_read( $raw_token, $job_id = '' ) {
		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id || '' === (string) $raw_token ) {
			return false;
		}

		$grant = get_option( self::POST_COMPLETE_OPTION, [] );
		if ( ! is_array( $grant ) || empty( $grant['token_hash'] ) || empty( $grant['job_id'] ) ) {
			return false;
		}

		if ( isset( $grant['expires_at'] ) && time() > (int) $grant['expires_at'] ) {
			delete_option( self::POST_COMPLETE_OPTION );
			return false;
		}

		if ( ! hash_equals( (string) $grant['job_id'], $job_id ) ) {
			return false;
		}

		$expected_hash = self::stable_token_hash( $raw_token );
		$legacy_hash   = wp_hash( $raw_token );
		$grant_hash    = (string) $grant['token_hash'];
		$grant_ok      = hash_equals( $grant_hash, $expected_hash ) || hash_equals( $grant_hash, $legacy_hash );
		if ( ! $grant_ok ) {
			return false;
		}

		if ( ! class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
			return false;
		}

		try {
			$meta = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
		} catch ( Exception $e ) {
			unset( $e );
			return false;
		}

		return ! empty( $meta['completed'] );
	}

	/**
	 * Revoke the current token (call on restore complete/cancel).
	 */
	public static function revoke() {
		$file = self::token_file_path();
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- reading plugin-controlled token file
			$raw     = file_get_contents( $file );
			$payload = json_decode( $raw, true );
			if ( is_array( $payload ) && ( ! empty( $payload['token_hash'] ) || ! empty( $payload['token'] ) ) && ! empty( $payload['job_id'] ) ) {
				$expires = isset( $payload['expires_at'] ) ? (int) $payload['expires_at'] : ( time() + self::TOKEN_TTL );
				$grant_hash = ! empty( $payload['token_hash'] )
					? (string) $payload['token_hash']
					: (string) $payload['token'];
				update_option(
					self::POST_COMPLETE_OPTION,
					[
						'job_id'     => sanitize_text_field( (string) $payload['job_id'] ),
						'token_hash' => $grant_hash,
						'hash_alg'   => isset( $payload['hash_alg'] ) ? (string) $payload['hash_alg'] : 'legacy_wp_hash',
						'expires_at' => $expires,
					],
					false
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- plugin-controlled temp file
			@unlink( $file );
		}
		delete_option( 'museder_restoreone_restore_token' );
	}

	/**
	 * Return current raw token for active job continuity on restore page reload.
	 *
	 * @param string $job_id Restore job id.
	 * @return string
	 */
	public static function get_raw_token_for_job( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id ) {
			return '';
		}

		$payload = self::read_token_payload();
		if ( ! is_array( $payload ) ) {
			return '';
		}
		if ( isset( $payload['expires_at'] ) && time() > (int) $payload['expires_at'] ) {
			self::revoke();
			return '';
		}
		if ( empty( $payload['job_id'] ) || ! hash_equals( (string) $payload['job_id'], $job_id ) ) {
			return '';
		}
		if ( empty( $payload[ self::RAW_TOKEN_KEY ] ) ) {
			return '';
		}
		$raw = (string) $payload[ self::RAW_TOKEN_KEY ];
		return '' !== $raw ? $raw : '';
	}

	/**
	 * Return payload safe for options persistence (no raw token).
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public static function payload_for_option( array $payload ) {
		return self::redact_raw_token( $payload );
	}

	/**
	 * Clear post-complete read grant (QA / manual reset).
	 */
	public static function clear_post_complete_access() {
		delete_option( self::POST_COMPLETE_OPTION );
	}

	/**
	 * Return the raw token for embedding in JS (if one is active and not expired).
	 *
	 * @return string Empty string when no valid token exists.
	 */
	public static function get_active_raw_token() {
		$file = self::token_file_path();
		if ( ! file_exists( $file ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$raw     = file_get_contents( $file );
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || empty( $payload['token'] ) ) {
			return '';
		}
		if ( isset( $payload['expires_at'] ) && time() > (int) $payload['expires_at'] ) {
			self::revoke();
			return '';
		}

		// We cannot recover the raw token from the hash.
		// The raw token was only available at generate() time.
		// Return empty — the frontend receives it via the execute() response.
		return '';
	}

	/**
	 * Absolute path to the token file (inside .htaccess-protected temp dir).
	 *
	 * @return string
	 */
	private static function token_file_path() {
		if ( function_exists( 'museder_restoreone_get_canonical_storage_base' ) ) {
			$base = trailingslashit( museder_restoreone_get_canonical_storage_base() ) . 'temp';
		} else {
			$upload_dir = wp_upload_dir();
			$base       = trailingslashit( $upload_dir['basedir'] ) . 'museder-restoreone/temp';
		}

		if ( function_exists( 'museder_restoreone_ensure_directory' ) ) {
			museder_restoreone_ensure_directory( $base );
		}

		return trailingslashit( wp_normalize_path( $base ) ) . self::TOKEN_FILENAME;
	}

	/**
	 * Load token payload from file.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function read_token_payload() {
		$file = self::token_file_path();
		if ( ! file_exists( $file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- plugin-controlled token file
		$raw = file_get_contents( $file );
		$payload = json_decode( (string) $raw, true );
		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Remove raw token before persisting to options table.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private static function redact_raw_token( array $payload ) {
		if ( isset( $payload[ self::RAW_TOKEN_KEY ] ) ) {
			unset( $payload[ self::RAW_TOKEN_KEY ] );
		}
		return $payload;
	}

	/**
	 * Stable token digest that survives wp-config salt changes during restore.
	 *
	 * @param string $raw_token
	 * @return string
	 */
	private static function stable_token_hash( $raw_token ) {
		$secret = self::token_secret();
		return hash_hmac( 'sha256', (string) $raw_token, $secret );
	}

	/**
	 * Get or create restore token secret in plugin-controlled storage.
	 *
	 * @return string
	 */
	private static function token_secret() {
		$file = self::secret_file_path();
		$dir  = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- plugin-controlled secret file
			$current = trim( (string) file_get_contents( $file ) );
			if ( '' !== $current ) {
				return $current;
			}
		}

		$secret = wp_generate_password( 96, true, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-controlled secret file
		file_put_contents( $file, $secret );
		return $secret;
	}

	/**
	 * Absolute path to the token secret file.
	 *
	 * @return string
	 */
	private static function secret_file_path() {
		$token_path = self::token_file_path();
		return trailingslashit( dirname( $token_path ) ) . self::SECRET_FILENAME;
	}

	/**
	 * Snapshot runtime auth files before file-restore can overwrite them from backup.
	 *
	 * @return array{secret:string,token:string}
	 */
	public static function backup_runtime_auth_files() {
		$backup = [
			'secret' => '',
			'token'  => '',
		];

		$secret_file = self::secret_file_path();
		if ( file_exists( $secret_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- plugin-controlled secret file
			$backup['secret'] = (string) file_get_contents( $secret_file );
		}

		$token_file = self::token_file_path();
		if ( file_exists( $token_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- plugin-controlled token file
			$backup['token'] = (string) file_get_contents( $token_file );
		}

		return $backup;
	}

	/**
	 * Restore runtime auth files captured before file-restore.
	 *
	 * @param array<string, string> $backup Return value from backup_runtime_auth_files().
	 * @return void
	 */
	public static function restore_runtime_auth_files( array $backup ) {
		if ( empty( $backup ) ) {
			return;
		}

		$dir = dirname( self::token_file_path() );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! empty( $backup['secret'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-controlled secret file
			file_put_contents( self::secret_file_path(), (string) $backup['secret'] );
		}

		if ( ! empty( $backup['token'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plugin-controlled token file
			file_put_contents( self::token_file_path(), (string) $backup['token'] );
		}
	}

	/**
	 * Whether a backup archive entry targets live restore auth files.
	 *
	 * @param string $entry_name Archive entry path.
	 * @return bool
	 */
	public static function archive_entry_is_runtime_auth_file( $entry_name ) {
		$name     = wp_normalize_path( (string) $entry_name );
		$basename = basename( $name );
		if ( ! in_array( $basename, [ self::SECRET_FILENAME, self::TOKEN_FILENAME ], true ) ) {
			return false;
		}

		return false !== strpos( $name, 'uploads/museder-restoreone/temp/' )
			|| false !== strpos( $name, 'museder-restoreone/temp/' );
	}
}
