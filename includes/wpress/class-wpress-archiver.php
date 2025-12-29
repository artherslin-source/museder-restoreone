<?php
/**
 * WPRESS Engine - Archiver base class (AI1WM-compatible archive format)
 *
 * Archive layout:
 * - repeated 4377-byte headers + file content
 * - trailing EOF block: pack('a4377','')
 *
 * Header pack format:
 * - name   a255  (filename only)
 * - size   a14   (size of file content bytes in archive; encrypted size if enabled)
 * - mtime  a12   (unix timestamp)
 * - prefix a4096 (path, '.' or relative path)
 *
 * License: GPLv2 or later (this project)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Backup_Lite_Wpress_Archiver {
	public const HEADER_BYTES = 4377;

	/** @var string */
	protected $file_name;

	/** @var resource */
	protected $file_handle;

	/** @var array */
	protected $block_format = array(
		'a255',  // filename
		'a14',   // size of file contents
		'a12',   // last time modified
		'a4096', // path
	);

	/** @var string */
	protected $eof;

	/**
	 * @throws Backup_Lite_Wpress_Not_Accessible_Exception
	 * @throws Backup_Lite_Wpress_Not_Seekable_Exception
	 */
	public function __construct( string $file_name, bool $write = false ) {
		$this->file_name = $file_name;
		$this->eof       = pack( 'a4377', '' );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_fopen, WordPress.WP.AlternativeFunctions.file_system_read_fseek
		// Reason: streaming access for large archives; paths are controlled by plugin and validated by callers.
		if ( $write ) {
			$this->file_handle = @fopen( $file_name, 'cb' );
			if ( $this->file_handle === false ) {
				throw new Backup_Lite_Wpress_Not_Accessible_Exception(
					sprintf( __( 'Could not open file for writing. File: %s', 'museder-restoreone' ), $this->file_name )
				);
			}

			if ( @fseek( $this->file_handle, 0, SEEK_END ) === -1 ) {
				throw new Backup_Lite_Wpress_Not_Seekable_Exception(
					sprintf( __( 'Could not seek to end of file. File: %s', 'museder-restoreone' ), $this->file_name )
				);
			}
		} else {
			$this->file_handle = @fopen( $file_name, 'rb' );
			if ( $this->file_handle === false ) {
				throw new Backup_Lite_Wpress_Not_Accessible_Exception(
					sprintf( __( 'Could not open file for reading. File: %s', 'museder-restoreone' ), $this->file_name )
				);
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_read_fopen, WordPress.WP.AlternativeFunctions.file_system_read_fseek
	}

	/**
	 * @throws Backup_Lite_Wpress_Not_Seekable_Exception
	 */
	public function set_file_pointer( int $offset ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_fseek
		if ( @fseek( $this->file_handle, $offset, SEEK_SET ) === -1 ) {
			throw new Backup_Lite_Wpress_Not_Seekable_Exception(
				sprintf( __( 'Could not seek to offset of file. File: %s Offset: %d', 'museder-restoreone' ), $this->file_name, $offset )
			);
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_read_fseek
	}

	/**
	 * @throws Backup_Lite_Wpress_Not_Tellable_Exception
	 */
	public function get_file_pointer(): int {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_ftell
		$offset = @ftell( $this->file_handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_read_ftell
		if ( $offset === false ) {
			throw new Backup_Lite_Wpress_Not_Tellable_Exception(
				sprintf( __( 'Could not tell offset of file. File: %s', 'museder-restoreone' ), $this->file_name )
			);
		}

		return (int) $offset;
	}

	/**
	 * Validate archive file completeness (trailing EOF marker).
	 */
	public function is_valid(): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_ftell, WordPress.WP.AlternativeFunctions.file_system_read_fseek, WordPress.WP.AlternativeFunctions.file_system_read_fread
		$offset = @ftell( $this->file_handle );
		if ( $offset === false ) {
			return false;
		}

		if ( @fseek( $this->file_handle, - self::HEADER_BYTES, SEEK_END ) === -1 ) {
			return false;
		}

		if ( @fread( $this->file_handle, self::HEADER_BYTES ) !== $this->eof ) {
			return false;
		}

		if ( @fseek( $this->file_handle, $offset, SEEK_SET ) === -1 ) {
			return false;
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_read_ftell, WordPress.WP.AlternativeFunctions.file_system_read_fseek, WordPress.WP.AlternativeFunctions.file_system_read_fread

		return true;
	}

	/**
	 * @throws Backup_Lite_Wpress_Not_Tellable_Exception
	 * @throws Backup_Lite_Wpress_Not_Truncatable_Exception
	 */
	public function truncate(): void {
		$offset = $this->get_file_pointer();

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_filesize, WordPress.WP.AlternativeFunctions.file_system_read_ftruncate
		if ( @filesize( $this->file_name ) > $offset ) {
			if ( @ftruncate( $this->file_handle, $offset ) === false ) {
				throw new Backup_Lite_Wpress_Not_Truncatable_Exception(
					sprintf( __( 'Could not truncate file. File: %s', 'museder-restoreone' ), $this->file_name )
				);
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_read_filesize, WordPress.WP.AlternativeFunctions.file_system_read_ftruncate
	}

	/**
	 * @throws Backup_Lite_Wpress_Not_Closable_Exception
	 */
	public function close(): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_fclose
		if ( @fclose( $this->file_handle ) === false ) {
			throw new Backup_Lite_Wpress_Not_Closable_Exception(
				sprintf( __( 'Could not close file. File: %s', 'museder-restoreone' ), $this->file_name )
			);
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_read_fclose
	}

	protected function replace_forward_slash_with_directory_separator( string $path ): string {
		return str_replace( '/', DIRECTORY_SEPARATOR, $path );
	}

	protected function escape_windows_directory_separator( string $path ): string {
		return preg_replace( '/[\\\\]+/', '\\\\\\\\', $path );
	}
}


