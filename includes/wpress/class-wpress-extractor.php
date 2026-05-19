<?php
/**
 * WPRESS Engine - Extractor (AI1WM-compatible)
 *
 * Provides:
 * - list_files()
 * - get_total_files_count()/get_total_files_size()
 * - extract_by_files_array() (time-sliced via $timeout_seconds)
 *
 * Security hardening (WP.org-friendly):
 * - path traversal guard: extracted paths must remain under $location
 *
 * License: GPLv2 or later (this project)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Museder_Restoreone_Wpress_Extractor extends Museder_Restoreone_Wpress_Archiver {
	/** @var int|null */
	protected $total_files_count = null;

	/** @var int|null */
	protected $total_files_size = null;

	/** @var string|null */
	private $decryption_password = null;

	public function __construct( string $file_name ) {
		parent::__construct( $file_name, false );
	}

	public function set_decryption_password( ?string $password ): void {
		$this->decryption_password = $password ? (string) $password : null;
	}

	/**
	 * List file headers (does not read file contents).
	 *
	 * @throws Museder_Restoreone_Wpress_Not_Seekable_Exception
	 */
	public function list_files(): array {
		$files = array();

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_ftell
		if ( @fseek( $this->file_handle, 0, SEEK_SET ) === -1 ) {
			throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
				sprintf(
					/* translators: %s: archive file path */
					esc_html__( 'Could not seek to beginning of file. File: %s', 'museder-restoreone' ),
					esc_html( $this->file_name )
				)
			);
		}

		while ( $block = @fread( $this->file_handle, self::HEADER_BYTES ) ) {
			if ( $block === $this->eof ) {
				continue;
			}

			$data = $this->get_data_from_block( $block );
			if ( $data ) {
				$data['offset'] = (int) @ftell( $this->file_handle );

				if ( @fseek( $this->file_handle, $data['size'], SEEK_CUR ) === -1 ) {
					throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
						sprintf(
							/* translators: 1: archive file path, 2: offset */
							esc_html__( 'Could not seek to offset of file. File: %1$s Offset: %2$d', 'museder-restoreone' ),
							esc_html( $this->file_name ),
							(int) $data['size']
						)
					);
				}

				$files[] = $data;
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_ftell

		return $files;
	}

	/**
	 * @throws Museder_Restoreone_Wpress_Not_Seekable_Exception
	 */
	public function get_total_files_count(): int {
		if ( $this->total_files_count === null ) {
			$this->compute_totals();
		}

		return (int) $this->total_files_count;
	}

	/**
	 * @throws Museder_Restoreone_Wpress_Not_Seekable_Exception
	 */
	public function get_total_files_size(): int {
		if ( $this->total_files_size === null ) {
			$this->compute_totals();
		}

		return (int) $this->total_files_size;
	}

	/**
	 * Extract specific files (by prefix) from archive, with optional time-slicing.
	 *
	 * Notes:
	 * - $include_files / $exclude_files are treated as prefix paths (AI1WM style).
	 * - $file_offset is a per-file offset (bytes already written for current file), used for resume.
	 *
	 * @throws Museder_Restoreone_Wpress_Not_Directory_Exception
	 * @throws Museder_Restoreone_Wpress_Not_Seekable_Exception
	 * @throws Museder_Restoreone_Wpress_Not_Readable_Exception
	 * @throws Museder_Restoreone_Wpress_Quota_Exceeded_Exception
	 * @throws Museder_Restoreone_Wpress_Path_Traversal_Exception
	 */
	public function extract_by_files_array(
		string $location,
		array $include_files = array(),
		array $exclude_files = array(),
		array $exclude_extensions = array(),
		int &$file_written = 0,
		int &$file_offset = 0,
		int $timeout_seconds = 10
	): bool {
		if ( ! is_dir( $location ) ) {
			throw new Museder_Restoreone_Wpress_Not_Directory_Exception(
				sprintf(
					/* translators: %s: directory path */
					esc_html__( 'Location is not a directory: %s', 'museder-restoreone' ),
					esc_html( $location )
				)
			);
		}

		$location = $this->replace_forward_slash_with_directory_separator( $location );

		$completed = true;
		$start     = microtime( true );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( $file_offset > 0 ) {
			if ( @fseek( $this->file_handle, - $file_offset - self::HEADER_BYTES, SEEK_CUR ) === -1 ) {
				throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
					sprintf(
						/* translators: 1: archive file path, 2: offset */
						esc_html__( 'Could not seek to offset of file. File: %1$s Offset: %2$d', 'museder-restoreone' ),
						esc_html( $this->file_name ),
						(int) ( - $file_offset - self::HEADER_BYTES )
					)
				);
			}
		}

		while ( ( $block = @fread( $this->file_handle, self::HEADER_BYTES ) ) ) {
			if ( $block === $this->eof ) {
				@fseek( $this->file_handle, 1, SEEK_END );
				@fgetc( $this->file_handle );
				break;
			}

			$data = $this->get_data_from_block( $block );
			if ( ! $data ) {
				continue;
			}

			$archive_rel_name = $data['filename'];
			$archive_rel_path = $data['path'];
			$file_size        = (int) $data['size'];
			$file_mtime       = (int) $data['mtime'];

			$should_include = $this->matches_prefix_any( $archive_rel_name, $include_files );
			$should_include = $should_include && ! $this->matches_prefix_any( $archive_rel_name, $exclude_files );
			$should_include = $should_include && ! $this->matches_extension_any( $archive_rel_name, $exclude_extensions );

			if ( $should_include ) {
				$dest_rel_path = $archive_rel_path ? ( $archive_rel_path . DIRECTORY_SEPARATOR ) : '';
				$dest_rel_dir  = $dest_rel_path;
				$dest_rel_file = $archive_rel_name;

				$dest_dir  = $this->safe_join( $location, $dest_rel_dir );
				$dest_file = $this->safe_join( $location, $dest_rel_file );

				if ( ! is_dir( $dest_dir ) ) {
					wp_mkdir_p( $dest_dir );
				}

				$file_written = 0;
				if ( $this->extract_to( $dest_file, $file_size, $file_mtime, $file_written, $file_offset, $timeout_seconds, $start ) ) {
					$file_offset = 0;
				} else {
					$completed = false;
					break;
				}
			} else {
				if ( @fseek( $this->file_handle, $file_size, SEEK_CUR ) === -1 ) {
					throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
						sprintf(
							/* translators: 1: archive file path, 2: offset */
							esc_html__( 'Could not seek to offset of file. File: %1$s Offset: %2$d', 'museder-restoreone' ),
							esc_html( $this->file_name ),
							(int) $file_size
						)
					);
				}
			}

			if ( $timeout_seconds > 0 && ( microtime( true ) - $start ) > $timeout_seconds ) {
				$completed = false;
				break;
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread

		return $completed;
	}

	/**
	 * Incremental extractor with resume offsets (cron/time-sliced friendly).
	 *
	 * Offsets:
	 * - $archive_offset: absolute byte offset at the BEGINNING of the current file header (4377 bytes)
	 * - $file_offset: bytes already written for the current file content (archive bytes; encrypted bytes if enabled)
	 *
	 * On completion:
	 * - returns true AND positions offsets at the next header (or EOF)
	 * On slice break:
	 * - returns false AND preserves offsets to resume from same header with updated $file_offset
	 *
	 * @throws Museder_Restoreone_Wpress_Not_Directory_Exception
	 * @throws Museder_Restoreone_Wpress_Not_Seekable_Exception
	 * @throws Museder_Restoreone_Wpress_Not_Readable_Exception
	 * @throws Museder_Restoreone_Wpress_Quota_Exceeded_Exception
	 * @throws Museder_Restoreone_Wpress_Path_Traversal_Exception
	 */
	public function extract_filtered_sliced(
		string $location,
		array $include_files,
		array $exclude_files,
		array $exclude_extensions,
		int &$archive_offset,
		int &$file_offset,
		int &$processed_bytes,
		int $timeout_seconds = 10
	): bool {
		if ( ! is_dir( $location ) ) {
			throw new Museder_Restoreone_Wpress_Not_Directory_Exception(
				sprintf(
					/* translators: %s: directory path */
					esc_html__( 'Location is not a directory: %s', 'museder-restoreone' ),
					esc_html( $location )
				)
			);
		}

		$location = $this->replace_forward_slash_with_directory_separator( $location );

		$start     = microtime( true );
		$completed = true;

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_ftell
		if ( @fseek( $this->file_handle, $archive_offset, SEEK_SET ) === -1 ) {
			throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
				sprintf(
					/* translators: 1: archive file path, 2: offset */
					esc_html__( 'Could not seek to offset of file. File: %1$s Offset: %2$d', 'museder-restoreone' ),
					esc_html( $this->file_name ),
					(int) $archive_offset
				)
			);
		}

		while ( true ) {
			$header_pos = (int) @ftell( $this->file_handle );

			$block = @fread( $this->file_handle, self::HEADER_BYTES );
			if ( ! $block ) {
				break;
			}

			if ( $block === $this->eof ) {
				// Move to EOF and stop.
				$archive_offset = $header_pos;
				$file_offset    = 0;
				break;
			}

			$data = $this->get_data_from_block( $block );
			if ( ! $data ) {
				$archive_offset = (int) @ftell( $this->file_handle );
				continue;
			}

			$archive_rel_name = $data['filename'];
			$archive_rel_path = $data['path'];
			$file_size        = (int) $data['size'];
			$file_mtime       = (int) $data['mtime'];

			$should_include = true;
			if ( ! empty( $include_files ) ) {
				$should_include = $this->matches_prefix_any( $archive_rel_name, $include_files );
			}
			$should_include = $should_include && ! $this->matches_prefix_any( $archive_rel_name, $exclude_files );
			$should_include = $should_include && ! $this->matches_extension_any( $archive_rel_name, $exclude_extensions );

			if ( $should_include ) {
				$dest_rel_dir  = $archive_rel_path ? ( $archive_rel_path . DIRECTORY_SEPARATOR ) : '';
				$dest_rel_file = $archive_rel_name;

				$dest_dir  = $this->safe_join( $location, $dest_rel_dir );
				$dest_file = $this->safe_join( $location, $dest_rel_file );

				if ( ! is_dir( $dest_dir ) ) {
					wp_mkdir_p( $dest_dir );
				}

				$file_written = 0;
				$ok           = $this->extract_to( $dest_file, $file_size, $file_mtime, $file_written, $file_offset, $timeout_seconds, $start );
				$processed_bytes += (int) $file_written;

				if ( $ok ) {
					// Move to next header (current position is at end of file content).
					$file_offset    = 0;
					$archive_offset = (int) @ftell( $this->file_handle );
				} else {
					// Slice ended mid-file; resume from the same header position.
					$archive_offset = $header_pos;
					$completed      = false;
					break;
				}
			} else {
				// Skip file content. If we had a non-zero file_offset, skip the remaining bytes.
				$skip = $file_size - (int) $file_offset;
				if ( $skip < 0 ) {
					$skip = 0;
				}
				if ( @fseek( $this->file_handle, $skip, SEEK_CUR ) === -1 ) {
					throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
						sprintf(
							/* translators: 1: archive file path, 2: offset */
							esc_html__( 'Could not seek to offset of file. File: %1$s Offset: %2$d', 'museder-restoreone' ),
							esc_html( $this->file_name ),
							(int) $skip
						)
					);
				}
				$file_offset    = 0;
				$archive_offset = (int) @ftell( $this->file_handle );
			}

			if ( $timeout_seconds > 0 && ( microtime( true ) - $start ) > $timeout_seconds ) {
				$completed = false;
				break;
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_ftell

		return $completed;
	}

	public function get_permissions_for_directory(): int {
		if ( defined( 'FS_CHMOD_DIR' ) ) {
			return FS_CHMOD_DIR;
		}
		return 0755;
	}

	public function get_permissions_for_file(): int {
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			return FS_CHMOD_FILE;
		}
		return 0644;
	}

	private function compute_totals(): void {
		$this->total_files_count = 0;
		$this->total_files_size  = 0;

		// Large archive streaming requires direct file operations for performance and compatibility.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( @fseek( $this->file_handle, 0, SEEK_SET ) === -1 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Streaming archive handle.
			throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
				sprintf(
					/* translators: %s: archive file path */
					esc_html__( 'Could not seek to beginning of file. File: %s', 'museder-restoreone' ),
					esc_html( $this->file_name )
				)
			);
		}

		while ( $block = @fread( $this->file_handle, self::HEADER_BYTES ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming archive handle.
			if ( $block === $this->eof ) {
				continue;
			}

			$data = $this->get_data_from_block( $block );
			if ( $data ) {
				$this->total_files_count += 1;
				$this->total_files_size  += (int) $data['size'];

				if ( @fseek( $this->file_handle, (int) $data['size'], SEEK_CUR ) === -1 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Streaming archive handle.
					throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
						sprintf(
							/* translators: 1: archive file path, 2: file offset in bytes */
							esc_html__( 'Could not seek to offset of file. File: %1$s Offset: %2$d', 'museder-restoreone' ),
							esc_html( $this->file_name ),
							(int) $data['size']
						)
					);
				}
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread
	}

	/**
	 * Extract bytes for a single file.
	 *
	 * @throws Museder_Restoreone_Wpress_Not_Seekable_Exception
	 * @throws Museder_Restoreone_Wpress_Not_Readable_Exception
	 * @throws Museder_Restoreone_Wpress_Quota_Exceeded_Exception
	 * @throws Museder_Restoreone_Wpress_Not_Decryptable_Exception
	 */
	private function extract_to(
		string $dest_file,
		int $file_size,
		int $file_mtime,
		int &$file_written = 0,
		int &$file_offset = 0,
		int $timeout_seconds = 10,
		float $start_time = 0.0
	): bool {
		$file_written = 0;
		$completed    = true;

		// Large archive streaming requires direct file operations for performance and compatibility.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		if ( $file_offset > 0 ) {
			if ( @fseek( $this->file_handle, $file_offset, SEEK_CUR ) === -1 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Streaming archive handle.
				throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
					sprintf(
						/* translators: 1: archive file path, 2: file offset in bytes */
						esc_html__( 'Could not seek to offset of file. File: %1$s Offset: %2$d', 'museder-restoreone' ),
						esc_html( $this->file_name ),
						(int) $file_offset
					)
				);
			}
		}

		$file_size -= $file_offset;

		$file_handle = @fopen( $dest_file, ( $file_offset === 0 ? 'wb' : 'ab' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming large archive extraction.
		if ( $file_handle ) {
			while ( $file_size > 0 ) {
				$chunk_size = $file_size > 512000 ? 512000 : $file_size;

				// AI1WM encryption: each 512000 plaintext chunk becomes iv_length + 512000 + 16 padding.
				if ( ! empty( $this->decryption_password ) && basename( $dest_file ) !== 'package.json' ) {
					if ( $file_size > 512000 ) {
						$iv_len     = Museder_Restoreone_Wpress_Crypto::iv_length();
						$chunk_size = $chunk_size + ( $iv_len * 2 );
						$chunk_size = $chunk_size > $file_size ? $file_size : $chunk_size;
					}
				}

				$file_content = @fread( $this->file_handle, $chunk_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming archive handle.
				if ( $file_content === false ) {
					throw new Museder_Restoreone_Wpress_Not_Readable_Exception(
						sprintf(
							/* translators: %s: archive file path */
							esc_html__( 'Could not read content from file. File: %s', 'museder-restoreone' ),
							esc_html( $this->file_name )
						)
					);
				}

				$file_size -= $chunk_size;

				if ( ! empty( $this->decryption_password ) && basename( $dest_file ) !== 'package.json' ) {
					$file_content = Museder_Restoreone_Wpress_Crypto::decrypt_bytes( $file_content, (string) $this->decryption_password );
				}

				$written = @fwrite( $file_handle, $file_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming large archive extraction.
				if ( $written === false || strlen( $file_content ) !== $written ) {
					throw new Museder_Restoreone_Wpress_Quota_Exceeded_Exception(
						sprintf(
							/* translators: %s: destination file path */
							esc_html__( 'Out of disk space. Could not write content to file. File: %s', 'museder-restoreone' ),
							esc_html( $dest_file )
						)
					);
				}

				$file_written += $chunk_size;

				if ( $timeout_seconds > 0 && ( microtime( true ) - $start_time ) > $timeout_seconds ) {
					$completed = false;
					break;
				}
			}

			$file_offset += $file_written;

			@fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming large archive extraction.

			if ( $completed ) {
				@touch( $dest_file, $file_mtime ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Best-effort mtime restore.
				@chmod( $dest_file, $this->get_permissions_for_file() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best-effort permission restore.
			}
		} else {
			// No file permissions, skip remaining bytes.
			if ( @fseek( $this->file_handle, $file_size, SEEK_CUR ) === -1 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Streaming archive handle.
				throw new Museder_Restoreone_Wpress_Not_Seekable_Exception(
					sprintf(
						/* translators: 1: archive file path, 2: file offset in bytes */
						esc_html__( 'Could not seek to offset of file. File: %1$s Offset: %2$d', 'museder-restoreone' ),
						esc_html( $this->file_name ),
						(int) $file_size
					)
				);
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		return $completed;
	}

	private function get_data_from_block( string $block ) {
		$format = array(
			$this->block_format[0] . 'filename/',
			$this->block_format[1] . 'size/',
			$this->block_format[2] . 'mtime/',
			$this->block_format[3] . 'path',
		);
		$format = implode( '', $format );

		$data = unpack( $format, $block );
		if ( ! $data ) {
			return false;
		}

		$data['filename'] = trim( (string) $data['filename'] );
		$data['size']     = (int) trim( (string) $data['size'] );
		$data['mtime']    = (int) trim( (string) $data['mtime'] );
		$data['path']     = trim( (string) $data['path'] );

		$data['filename'] = ( $data['path'] === '.' ? $data['filename'] : $data['path'] . DIRECTORY_SEPARATOR . $data['filename'] );
		$data['path']     = ( $data['path'] === '.' ? '' : $data['path'] );

		$data['filename'] = $this->replace_forward_slash_with_directory_separator( $data['filename'] );
		$data['path']     = $this->replace_forward_slash_with_directory_separator( $data['path'] );

		return $data;
	}

	private function matches_prefix_any( string $filename, array $prefixes ): bool {
		if ( empty( $prefixes ) ) {
			return false;
		}

		foreach ( $prefixes as $prefix ) {
			$prefix = (string) $prefix;
			if ( $prefix === '' ) {
				continue;
			}
			$prefix = $this->replace_forward_slash_with_directory_separator( $prefix );
			if ( strpos( $filename . DIRECTORY_SEPARATOR, $prefix . DIRECTORY_SEPARATOR ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	private function matches_extension_any( string $filename, array $extensions ): bool {
		if ( empty( $extensions ) ) {
			return false;
		}

		foreach ( $extensions as $ext ) {
			$ext = (string) $ext;
			if ( $ext === '' ) {
				continue;
			}
			if ( strrpos( $filename, $ext ) === ( strlen( $filename ) - strlen( $ext ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prevent path traversal; ensures the joined path stays under base directory.
	 *
	 * @throws Museder_Restoreone_Wpress_Path_Traversal_Exception
	 */
	private function safe_join( string $base_dir, string $relative ): string {
		$base_dir = rtrim( $base_dir, "/\\ \t\n\r\0\x0B" );
		$rel      = ltrim( (string) $relative, "/\\ \t\n\r\0\x0B" );

		$norm_base = wp_normalize_path( $base_dir );
		$norm_rel  = wp_normalize_path( $rel );

		// Disallow absolute paths and traversal.
		if ( $norm_rel === '' ) {
			$norm_rel = '.';
		}

		if ( path_is_absolute( $norm_rel ) ) {
			throw new Museder_Restoreone_Wpress_Path_Traversal_Exception( esc_html__( 'Unsafe absolute path in archive.', 'museder-restoreone' ) );
		}

		foreach ( explode( '/', $norm_rel ) as $seg ) {
			if ( $seg === '..' ) {
				throw new Museder_Restoreone_Wpress_Path_Traversal_Exception( esc_html__( 'Unsafe path traversal in archive.', 'museder-restoreone' ) );
			}
		}

		$joined = wp_normalize_path( $norm_base . '/' . $norm_rel );

		$base_prefix = trailingslashit( wp_normalize_path( $norm_base ) );
		$joined_norm = wp_normalize_path( $joined );
		if ( $joined_norm !== untrailingslashit( $base_prefix ) && 0 !== strpos( $joined_norm, $base_prefix ) ) {
			throw new Museder_Restoreone_Wpress_Path_Traversal_Exception( esc_html__( 'Unsafe extract path (outside base directory).', 'museder-restoreone' ) );
		}

		return str_replace( '/', DIRECTORY_SEPARATOR, $joined );
	}
}


