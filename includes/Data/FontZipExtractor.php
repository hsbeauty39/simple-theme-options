<?php
/**
 * Scan ZIP archives for font files and extract them for import.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Data;

defined( 'ABSPATH' ) || exit;

final class FontZipExtractor {

	public const FONT_EXTENSIONS = array( 'woff2', 'woff', 'ttf', 'otf', 'eot' );

	private const MAX_FONT_FILES = 50;

	private const MAX_ENTRY_BYTES = 15 * 1024 * 1024;

	private const MAX_TOTAL_BYTES = 50 * 1024 * 1024;

	/**
	 * Whether the attachment is a ZIP archive we can scan.
	 */
	public static function is_zip_attachment( int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}
		$path = (string) get_attached_file( $attachment_id );
		if ( self::path_is_zip( $path ) ) {
			return true;
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( ! is_string( $mime ) || $mime === '' ) {
			return false;
		}
		$allowed = array(
			'application/zip',
			'application/x-zip-compressed',
			'application/x-zip',
			'multipart/x-zip',
			'application/octet-stream',
		);

		return in_array( $mime, $allowed, true );
	}

	public static function path_is_zip( string $path ): bool {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === 'zip';
	}

	public static function is_font_extension( string $extension ): bool {
		return in_array( strtolower( $extension ), self::FONT_EXTENSIONS, true );
	}

	/**
	 * List font entries inside a ZIP without extracting (metadata only).
	 *
	 * @return array<int, array{entry: string, extension: string}>
	 */
	public static function list_font_entries( string $zip_path ): array {
		if ( ! class_exists( 'ZipArchive' ) || ! is_readable( $zip_path ) ) {
			return array();
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return array();
		}

		$found = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) || ! isset( $stat['name'] ) ) {
				continue;
			}
			$entry = (string) $stat['name'];
			if ( self::should_skip_entry( $entry, $stat ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( ! self::is_font_extension( $ext ) ) {
				continue;
			}
			$found[] = array(
				'entry'     => $entry,
				'extension' => $ext,
			);
			if ( count( $found ) >= self::MAX_FONT_FILES ) {
				break;
			}
		}
		$zip->close();

		return $found;
	}

	/**
	 * Extract all font files from a ZIP to a temp directory.
	 *
	 * @return array{ok: bool, fonts: array<int, array{path: string, entry: string, extension: string}>, error: string, temp_dir: string}
	 */
	public static function extract_fonts( string $zip_path ): array {
		$empty = array(
			'ok'       => false,
			'fonts'    => array(),
			'error'    => '',
			'temp_dir' => '',
		);

		if ( ! class_exists( 'ZipArchive' ) ) {
			$empty['error'] = __( 'ZIP support is not available on this server (ZipArchive missing).', 'topten-simple-theme-options' );

			return $empty;
		}
		if ( ! is_readable( $zip_path ) ) {
			$empty['error'] = __( 'Could not read the ZIP file.', 'topten-simple-theme-options' );

			return $empty;
		}

		$entries = self::list_font_entries( $zip_path );
		if ( $entries === array() ) {
			$empty['error'] = __( 'No font files (WOFF2, WOFF, TTF, OTF, EOT) were found inside that ZIP.', 'topten-simple-theme-options' );

			return $empty;
		}

		$temp_dir = self::create_temp_dir();
		if ( $temp_dir === '' ) {
			$empty['error'] = __( 'Could not create a temporary folder for extraction.', 'topten-simple-theme-options' );

			return $empty;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			self::remove_dir( $temp_dir );
			$empty['error'] = __( 'Could not open the ZIP file.', 'topten-simple-theme-options' );

			return $empty;
		}

		$fonts       = array();
		$total_bytes = 0;
		$index       = 0;

		foreach ( $entries as $entry_row ) {
			$entry = $entry_row['entry'];
			$stat  = $zip->statName( $entry );
			if ( ! is_array( $stat ) ) {
				continue;
			}
			$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			if ( $size > self::MAX_ENTRY_BYTES || ( $total_bytes + $size ) > self::MAX_TOTAL_BYTES ) {
				continue;
			}

			$dest_name = self::unique_dest_name( $entry, $index );
			$dest_path = $temp_dir . '/' . $dest_name;
			if ( ! self::safe_extract_entry( $zip, $entry, $dest_path ) ) {
				continue;
			}
			if ( ! is_readable( $dest_path ) ) {
				continue;
			}

			$fonts[] = array(
				'path'      => $dest_path,
				'entry'     => $entry,
				'extension' => $entry_row['extension'],
			);
			$total_bytes += $size;
			++$index;
		}

		$zip->close();

		if ( $fonts === array() ) {
			self::remove_dir( $temp_dir );
			$empty['error'] = __( 'Font files were listed in the ZIP but could not be extracted.', 'topten-simple-theme-options' );

			return $empty;
		}

		return array(
			'ok'       => true,
			'fonts'    => $fonts,
			'error'    => '',
			'temp_dir' => $temp_dir,
		);
	}

	/**
	 * @param array{path: string, entry: string, extension: string} $font_row
	 */
	public static function sideload_extracted_font( array $font_row ): int {
		if ( ! isset( $font_row['path'] ) || ! is_readable( $font_row['path'] ) ) {
			return 0;
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$path = $font_row['path'];
		$name = isset( $font_row['entry'] ) ? basename( (string) $font_row['entry'] ) : basename( $path );
		$name = sanitize_file_name( $name );
		if ( $name === '' ) {
			$name = 'font.' . ( $font_row['extension'] ?? 'ttf' );
		}

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $path,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		return (int) $attachment_id;
	}

	public static function remove_dir( string $dir ): void {
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return;
		}
		$real = realpath( $dir );
		if ( ! is_string( $real ) || ! is_dir( $real ) ) {
			return;
		}
		$items = scandir( $real );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $real . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::remove_dir( $path );
			} elseif ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $real );
	}

	/**
	 * @param array<string, mixed> $stat
	 */
	private static function should_skip_entry( string $entry, array $stat ): bool {
		if ( $entry === '' || substr( $entry, -1 ) === '/' ) {
			return true;
		}
		if ( strpos( $entry, '__MACOSX/' ) === 0 || strpos( $entry, '/__MACOSX/' ) !== false ) {
			return true;
		}
		$base = basename( $entry );
		if ( $base === '' || $base[0] === '.' || $base === '.DS_Store' || $base === 'Thumbs.db' ) {
			return true;
		}
		$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
		if ( $size <= 0 || $size > self::MAX_ENTRY_BYTES ) {
			return true;
		}

		return false;
	}

	private static function create_temp_dir(): string {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return '';
		}
		$base = trailingslashit( $upload['basedir'] ) . 'sto-custom-fonts-tmp';
		if ( ! wp_mkdir_p( $base ) ) {
			return '';
		}
		$dir = $base . '/' . wp_generate_password( 16, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		return $dir;
	}

	private static function unique_dest_name( string $entry, int $index ): string {
		$base = sanitize_file_name( basename( $entry ) );
		if ( $base === '' ) {
			$ext  = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			$base = 'font-' . $index . '.' . ( $ext !== '' ? $ext : 'ttf' );
		}
		if ( $index > 0 ) {
			$ext_part  = pathinfo( $base, PATHINFO_EXTENSION );
			$name_part = pathinfo( $base, PATHINFO_FILENAME );
			$base      = $name_part . '-' . $index . ( $ext_part !== '' ? '.' . $ext_part : '' );
		}

		return $base;
	}

	private static function safe_extract_entry( \ZipArchive $zip, string $entry, string $dest_path ): bool {
		$dest_dir = dirname( $dest_path );
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return false;
		}
		$dest_real = realpath( $dest_dir );
		if ( ! is_string( $dest_real ) ) {
			return false;
		}
		$contents = $zip->getFromName( $entry );
		if ( ! is_string( $contents ) ) {
			return false;
		}
		$written = file_put_contents( $dest_path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return false;
		}
		$file_real = realpath( $dest_path );
		if ( ! is_string( $file_real ) || strpos( $file_real, $dest_real ) !== 0 ) {
			wp_delete_file( $dest_path );

			return false;
		}

		return true;
	}
}
