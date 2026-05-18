<?php
/**
 * WPRESS Engine Crypto (AI1WM-compatible)
 *
 * AI1WM encrypts each 512KB chunk independently:
 * - plaintext chunk (512000 bytes, aligned to AES block size 16)
 * - encrypted bytes = iv (16) + ciphertext (plaintext + 16 padding)
 *
 * We implement compatible encrypt/decrypt helpers so we can restore encrypted .wpress
 * without external services.
 *
 * License: GPLv2 or later (this project)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Museder_Restoreone_Wpress_Crypto {
	public const CIPHER_NAME = 'AES-256-CBC';

	/**
	 * A deterministic sign text used for password validation (stored as encrypted signature).
	 * This doesn't need to match AI1WM's constant for our own backups, but keeping a stable
	 * value makes validation predictable.
	 */
	public const SIGN_TEXT = 'MUSEDER_RESTOREONE_WPRESS_ENCRYPTION_CHECK';

	/**
	 * AI1WM signature constant used to validate decryption password for AI1WM-encrypted backups.
	 * Source: AI1WM `AI1WM_SIGN_TEXT` (ServMask Inc).
	 */
	public const AI1WM_SIGN_TEXT = '"How long do you want these messages to remain secret? I want them to remain secret for as long as men are capable of evil." - Neal Stephenson';

	public static function can_encrypt(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_random_pseudo_bytes' )
			&& function_exists( 'openssl_cipher_iv_length' )
			&& function_exists( 'sha1' )
			&& in_array( self::CIPHER_NAME, array_map( 'strtoupper', openssl_get_cipher_methods() ), true );
	}

	public static function can_decrypt(): bool {
		return function_exists( 'openssl_decrypt' )
			&& function_exists( 'openssl_random_pseudo_bytes' )
			&& function_exists( 'openssl_cipher_iv_length' )
			&& function_exists( 'sha1' )
			&& in_array( self::CIPHER_NAME, array_map( 'strtoupper', openssl_get_cipher_methods() ), true );
	}

	/**
	 * @throws Museder_Restoreone_Wpress_Not_Encryptable_Exception
	 */
	public static function iv_length(): int {
		$iv_length = openssl_cipher_iv_length( self::CIPHER_NAME );
		if ( $iv_length === false ) {
			throw new Museder_Restoreone_Wpress_Not_Encryptable_Exception(
				esc_html__( 'Could not obtain cipher IV length. The process cannot continue.', 'museder-restoreone' )
			);
		}

		return (int) $iv_length;
	}

	/**
	 * AI1WM derives the cipher key as: substr( sha1(password, true), 0, iv_length )
	 *
	 * @throws Museder_Restoreone_Wpress_Not_Encryptable_Exception
	 */
	private static function derive_key( string $password ): string {
		$iv_length = self::iv_length();
		return substr( sha1( $password, true ), 0, $iv_length );
	}

	/**
	 * Encrypt bytes (returns iv + ciphertext).
	 *
	 * @throws Museder_Restoreone_Wpress_Not_Encryptable_Exception
	 */
	public static function encrypt_bytes( string $bytes, string $password ): string {
		if ( ! self::can_encrypt() ) {
			throw new Museder_Restoreone_Wpress_Not_Encryptable_Exception(
				esc_html__( 'This server cannot encrypt backups (OpenSSL missing).', 'museder-restoreone' )
			);
		}

		$key = self::derive_key( $password );

		$iv = openssl_random_pseudo_bytes( self::iv_length() );
		if ( $iv === false ) {
			throw new Museder_Restoreone_Wpress_Not_Encryptable_Exception(
				esc_html__( 'Could not generate random bytes. The process cannot continue.', 'museder-restoreone' )
			);
		}

		// phpcs:ignore PHPCompatibility.FunctionUse.NewFunctionParameters, PHPCompatibility.Constants.NewConstants
		$ciphertext = openssl_encrypt( $bytes, self::CIPHER_NAME, $key, OPENSSL_RAW_DATA, $iv );
		if ( $ciphertext === false ) {
			throw new Museder_Restoreone_Wpress_Not_Encryptable_Exception(
				esc_html__( 'Could not encrypt data. The process cannot continue.', 'museder-restoreone' )
			);
		}

		return $iv . $ciphertext;
	}

	/**
	 * Decrypt bytes (expects iv + ciphertext).
	 *
	 * @throws Museder_Restoreone_Wpress_Not_Encryptable_Exception
	 * @throws Museder_Restoreone_Wpress_Not_Decryptable_Exception
	 */
	public static function decrypt_bytes( string $encrypted_bytes, string $password ): string {
		if ( ! self::can_decrypt() ) {
			throw new Museder_Restoreone_Wpress_Not_Decryptable_Exception(
				esc_html__( 'This server cannot decrypt backups (OpenSSL missing).', 'museder-restoreone' )
			);
		}

		$iv_length = self::iv_length();
		if ( strlen( $encrypted_bytes ) < $iv_length ) {
			throw new Museder_Restoreone_Wpress_Not_Decryptable_Exception(
				esc_html__( 'Encrypted data is too short. The process cannot continue.', 'museder-restoreone' )
			);
		}

		$key = self::derive_key( $password );
		$iv  = substr( $encrypted_bytes, 0, $iv_length );

		// phpcs:ignore PHPCompatibility.Constants.NewConstants, PHPCompatibility.FunctionUse.NewFunctionParameters
		$plaintext = openssl_decrypt( substr( $encrypted_bytes, $iv_length ), self::CIPHER_NAME, $key, OPENSSL_RAW_DATA, $iv );
		if ( $plaintext === false ) {
			throw new Museder_Restoreone_Wpress_Not_Decryptable_Exception(
				esc_html__( 'Could not decrypt data. The process cannot continue.', 'museder-restoreone' )
			);
		}

		return $plaintext;
	}

	/**
	 * Validates an encrypted signature (base64 string) using a password.
	 */
	public static function is_password_valid( string $encrypted_signature_b64, string $password ): bool {
		try {
			$raw = base64_decode( $encrypted_signature_b64, true );
			if ( $raw === false ) {
				return false;
			}

			return self::decrypt_bytes( $raw, $password ) === self::SIGN_TEXT;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Validate AI1WM encrypted signature.
	 */
	public static function is_ai1wm_password_valid( string $encrypted_signature_b64, string $password ): bool {
		try {
			$raw = base64_decode( $encrypted_signature_b64, true );
			if ( $raw === false ) {
				return false;
			}
			return self::decrypt_bytes( $raw, $password ) === self::AI1WM_SIGN_TEXT;
		} catch ( Exception $e ) {
			return false;
		}
	}
}


