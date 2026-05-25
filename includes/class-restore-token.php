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
 * Used as a fallback when WP nonces become invalid mid-restore (the normal
 * session tokens are destroyed when wp_usermeta is replaced by the backup).
 *
 * Security model (mirrors AI1WM secret_key pattern, WP.org approved):
 * - Token is generated with wp_generate_password(64, false) — cryptographic.
 * - Stored as wp_hash() digest (not plaintext).
 * - Has a 2-hour TTL.
 * - Automatically revoked when restore completes or is cancelled.
 * - Only serves as fallback; WP nonce is checked first.
 */
class Museder_Restoreone_Restore_Token {

	const TOKEN_FILENAME = '.restore-auth-token';
	const TOKEN_TTL      = 2 * HOUR_IN_SECONDS;

	/**
	 * Generate and persist a new restore token.
	 *
	 * @param string $job_id Restore job identifier.
	 * @return string Raw token value (to be sent to the browser).
	 */
	public static function generate( $job_id ) {
		$raw_token = wp_generate_password( 64, false );
		$payload   = [
			'token'      => wp_hash( $raw_token ),
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

		// Mirror to wp_options (will be re-injected after DB import by restore_session_after_import).
		update_option( 'museder_restoreone_restore_token', $payload, false );

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

		if ( ! is_array( $payload ) || empty( $payload['token'] ) ) {
			return false;
		}

		// Expiry check.
		if ( isset( $payload['expires_at'] ) && time() > (int) $payload['expires_at'] ) {
			self::revoke();
			return false;
		}

		// Constant-time hash comparison.
		if ( ! hash_equals( (string) $payload['token'], wp_hash( $raw_token ) ) ) {
			return false;
		}

		// Optional job binding.
		if ( '' !== $job_id && isset( $payload['job_id'] ) && $payload['job_id'] !== $job_id ) {
			return false;
		}

		return true;
	}

	/**
	 * Revoke the current token (call on restore complete/cancel).
	 */
	public static function revoke() {
		$file = self::token_file_path();
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- plugin-controlled temp file
			@unlink( $file );
		}
		delete_option( 'museder_restoreone_restore_token' );
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
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'museder-restoreone/temp/';
		return $base . self::TOKEN_FILENAME;
	}
}
