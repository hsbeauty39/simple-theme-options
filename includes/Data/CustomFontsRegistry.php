<?php
/**
 * Uploaded custom fonts (stored in `sto_custom_fonts`, merged into typography catalog).
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Data;

defined( 'ABSPATH' ) || exit;

final class CustomFontsRegistry {

	public const OPTION_KEY = 'sto_custom_fonts';

	public const AJAX_NONCE_ACTION = 'sto_custom_fonts';

	private const ALLOWED_WEIGHTS = array( 100, 200, 300, 400, 500, 600, 700, 800, 900 );

	private const ALLOWED_STYLES = array( 'normal', 'italic' );

	private const ALLOWED_CATEGORIES = array( 'sans-serif', 'serif', 'display', 'monospace', 'handwriting' );

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_faces(): array {
		$raw = get_option( self::OPTION_KEY, array() );

		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : array();
	}

	/**
	 * @return array<string, array{variants: array<int, string>, category: string, source: string}>
	 */
	public static function get_catalog_map(): array {
		$map = array();
		foreach ( self::get_faces() as $face ) {
			$family = isset( $face['family'] ) ? self::sanitize_family( (string) $face['family'] ) : '';
			if ( $family === '' ) {
				continue;
			}
			$variant = isset( $face['variant'] ) ? (string) $face['variant'] : self::weight_style_to_variant(
				isset( $face['weight'] ) ? (int) $face['weight'] : 400,
				isset( $face['style'] ) ? (string) $face['style'] : 'normal'
			);
			if ( ! isset( $map[ $family ] ) ) {
				$map[ $family ] = array(
					'variants' => array(),
					'category' => isset( $face['category'] ) ? self::sanitize_category( (string) $face['category'] ) : 'sans-serif',
					'source'   => 'custom',
				);
			}
			if ( ! in_array( $variant, $map[ $family ]['variants'], true ) ) {
				$map[ $family ]['variants'][] = $variant;
			}
		}
		foreach ( $map as $family => $meta ) {
			$map[ $family ]['variants'] = self::sort_variants( $meta['variants'] );
		}

		return $map;
	}

	/**
	 * @return array<int, array{family: string, variants: array<int, string>, category: string, source: string}>
	 */
	public static function get_catalog_list(): array {
		$list = array();
		foreach ( self::get_catalog_map() as $family => $meta ) {
			$list[] = array(
				'family'   => $family,
				'variants' => $meta['variants'],
				'category' => $meta['category'],
				'source'   => 'custom',
			);
		}

		return $list;
	}

	/**
	 * @return array<string, string> family => concatenated @font-face rules.
	 */
	public static function get_css_by_family(): array {
		$out     = array();
		$grouped = array();
		foreach ( self::get_faces() as $face ) {
			$family = isset( $face['family'] ) ? self::sanitize_family( (string) $face['family'] ) : '';
			if ( $family === '' ) {
				continue;
			}
			if ( ! isset( $grouped[ $family ] ) ) {
				$grouped[ $family ] = array();
			}
			$grouped[ $family ][] = $face;
		}
		foreach ( $grouped as $family => $faces ) {
			$css = self::build_font_face_css( $family, $faces );
			if ( $css !== '' ) {
				$out[ $family ] = $css;
			}
		}

		return $out;
	}

	public static function enqueue_front_styles(): void {
		$css = self::build_font_face_css_all();
		if ( $css === '' ) {
			return;
		}
		wp_register_style( 'sto-custom-fonts-front', false, array(), STO_VERSION );
		wp_enqueue_style( 'sto-custom-fonts-front' );
		wp_add_inline_style( 'sto-custom-fonts-front', $css );
	}

	public static function build_font_face_css_all(): string {
		$grouped = array();
		foreach ( self::get_faces() as $face ) {
			$family = isset( $face['family'] ) ? self::sanitize_family( (string) $face['family'] ) : '';
			if ( $family === '' ) {
				continue;
			}
			if ( ! isset( $grouped[ $family ] ) ) {
				$grouped[ $family ] = array();
			}
			$grouped[ $family ][] = $face;
		}
		$blocks = array();
		foreach ( $grouped as $family => $faces ) {
			$block = self::build_font_face_css( $family, $faces );
			if ( $block !== '' ) {
				$blocks[] = $block;
			}
		}

		return implode( "\n", $blocks );
	}

	/**
	 * @param array<int, array<string, mixed>> $faces
	 */
	public static function build_font_face_css( string $family, array $faces ): string {
		$rules = array();
		foreach ( $faces as $face ) {
			$url = '';
			if ( ! empty( $face['src_url'] ) && is_string( $face['src_url'] ) ) {
				$url = $face['src_url'];
			} else {
				$attachment_id = isset( $face['attachment_id'] ) ? (int) $face['attachment_id'] : 0;
				if ( $attachment_id > 0 ) {
					$url = (string) wp_get_attachment_url( $attachment_id );
				}
			}
			if ( $url === '' ) {
				continue;
			}
			$weight  = isset( $face['weight'] ) ? (int) $face['weight'] : 400;
			$style   = isset( $face['style'] ) ? (string) $face['style'] : 'normal';
			$format  = self::url_format_hint( $url );
			$escaped = esc_attr( $family );
			$rules[] = sprintf(
				"@font-face{font-family:'%s';src:url('%s') format('%s');font-weight:%d;font-style:%s;font-display:swap;}",
				$escaped,
				esc_url( $url ),
				esc_attr( $format ),
				$weight,
				esc_attr( $style )
			);
		}

		return implode( "\n", $rules );
	}

	/**
	 * Font file or ZIP archive acceptable for the custom-fonts uploader.
	 */
	public static function is_uploadable_attachment( int $attachment_id ): bool {
		return self::is_font_attachment( $attachment_id ) || FontZipExtractor::is_zip_attachment( $attachment_id );
	}

	/**
	 * Allowed extensions for drag-and-drop / direct upload.
	 *
	 * @return array<int, string>
	 */
	public static function get_allowed_upload_extensions(): array {
		return array_merge( FontZipExtractor::FONT_EXTENSIONS, array( 'zip' ) );
	}

	public static function is_allowed_upload_filename( string $filename ): bool {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		return in_array( $ext, self::get_allowed_upload_extensions(), true );
	}

	/**
	 * Upload a font or ZIP via `$_FILES` and register a media-library attachment.
	 *
	 * @param array<string, mixed> $file Single element from `$_FILES` (e.g. `$_FILES['file']`).
	 * @return array{ok: bool, attachment_id: int, message: string}
	 */
	public static function upload_font_file( array $file ): array {
		$fail = array(
			'ok'            => false,
			'attachment_id' => 0,
			'message'       => __( 'Could not upload that file.', 'topten-simple-theme-options' ),
		);

		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		if ( $name === '' || ! self::is_allowed_upload_filename( $name ) ) {
			$fail['message'] = __( 'Upload a WOFF2, WOFF, TTF, OTF, EOT font file, or a ZIP that contains fonts.', 'topten-simple-theme-options' );

			return $fail;
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( $error !== UPLOAD_ERR_OK ) {
			$fail['message'] = self::upload_error_message( $error );

			return $fail;
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'test_type' => true,
			)
		);

		if ( isset( $uploaded['error'] ) ) {
			$fail['message'] = (string) $uploaded['error'];

			return $fail;
		}

		$filepath = isset( $uploaded['file'] ) ? (string) $uploaded['file'] : '';
		if ( $filepath === '' || ! is_readable( $filepath ) ) {
			return $fail;
		}

		$mime_type = self::resolve_upload_mime_type( $name, $filepath );

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => sanitize_text_field( pathinfo( basename( $name ), PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $filepath );
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $filepath );
			$fail['message'] = is_wp_error( $attachment_id )
				? $attachment_id->get_error_message()
				: $fail['message'];

			return $fail;
		}

		$attachment_id = (int) $attachment_id;

		if ( FontZipExtractor::path_is_zip( $filepath ) ) {
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => 'application/zip',
				)
			);
		} elseif ( self::is_font_attachment( $attachment_id ) ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $filepath );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}

		if ( ! self::is_uploadable_attachment( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			$fail['message'] = __( 'That file type is not allowed for custom fonts.', 'topten-simple-theme-options' );

			return $fail;
		}

		return array(
			'ok'            => true,
			'attachment_id' => (int) $attachment_id,
			'message'       => '',
		);
	}

	private static function resolve_upload_mime_type( string $original_name, string $filepath ): string {
		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( $ext === 'zip' || FontZipExtractor::path_is_zip( $filepath ) ) {
			return 'application/zip';
		}
		$checked = wp_check_filetype( basename( $filepath ), self::allow_font_upload_mimes( array() ) );
		if ( is_array( $checked ) && ! empty( $checked['type'] ) ) {
			return (string) $checked['type'];
		}

		return '';
	}

	private static function upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'That file is too large for this server.', 'topten-simple-theme-options' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The file was only partially uploaded. Try again.', 'topten-simple-theme-options' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was uploaded.', 'topten-simple-theme-options' );
			default:
				return __( 'Could not upload that file.', 'topten-simple-theme-options' );
		}
	}

	/**
	 * Preview detected metadata for a font or every font inside a ZIP.
	 *
	 * @return array{
	 *   ok: bool,
	 *   is_zip: bool,
	 *   file_name: string,
	 *   fonts: array<int, array{file_name: string, family: string, weight: int, style: string, category: string, source: string}>,
	 *   note: string,
	 *   message: string
	 * }
	 */
	public static function preview_from_attachment( int $attachment_id ): array {
		$fail = array(
			'ok'        => false,
			'is_zip'    => false,
			'file_name' => '',
			'fonts'     => array(),
			'note'      => '',
			'message'   => __( 'That file is not a recognized font or ZIP archive.', 'topten-simple-theme-options' ),
		);

		if ( $attachment_id <= 0 || ! self::is_uploadable_attachment( $attachment_id ) ) {
			return $fail;
		}

		$file_path  = (string) get_attached_file( $attachment_id );
		$file_label = $file_path !== '' ? basename( $file_path ) : get_the_title( $attachment_id );

		if ( FontZipExtractor::is_zip_attachment( $attachment_id ) ) {
			return self::preview_from_zip( $file_path, $file_label, $attachment_id );
		}

		$detected = FontFileMetadata::detect_from_attachment( $attachment_id );
		$note     = self::woff2_note_for_path( $file_path );

		$fonts = array(
			array(
				'file_name' => $file_label,
				'family'    => $detected['family'],
				'weight'    => $detected['weight'],
				'style'     => $detected['style'],
				'category'  => $detected['category'],
				'source'    => $detected['source'],
			),
		);

		return array(
			'ok'           => true,
			'is_zip'       => false,
			'file_name'    => $file_label,
			'fonts'        => $fonts,
			'note'         => $note,
			'message'      => '',
			'duplicates'   => self::find_duplicates_in_upload( $fonts ),
			'live_preview' => self::build_pending_live_preview_for_font( $attachment_id, $detected ),
		);
	}

	/**
	 * Families, variants, and @font-face CSS for the live preview panel (imported fonts).
	 *
	 * @return array{families: array<int, array{family: string, variants: array<int, string>, category: string}>, cssByFamily: array<string, string>}
	 */
	public static function get_live_preview_data(): array {
		$families = array();
		foreach ( self::get_catalog_map() as $family => $meta ) {
			$families[] = array(
				'family'   => $family,
				'variants' => isset( $meta['variants'] ) && is_array( $meta['variants'] ) ? array_values( $meta['variants'] ) : array( 'regular' ),
				'category' => isset( $meta['category'] ) ? (string) $meta['category'] : 'sans-serif',
			);
		}
		usort(
			$families,
			static function ( $a, $b ) {
				return strcasecmp( (string) ( $a['family'] ?? '' ), (string) ( $b['family'] ?? '' ) );
			}
		);

		return array(
			'families'    => $families,
			'cssByFamily' => self::get_css_by_family(),
		);
	}

	public static function get_preview_transient_key( int $attachment_id ): string {
		return 'sto_cf_preview_' . $attachment_id;
	}

	/**
	 * @param array{family:string, weight:int, style:string, category:string} $detected
	 * @return array{families: array<int, array{family: string, variants: array<int, string>, category: string}>, cssByFamily: array<string, string>}
	 */
	public static function build_pending_live_preview_for_font( int $attachment_id, array $detected ): array {
		$url = (string) wp_get_attachment_url( $attachment_id );
		if ( $url === '' ) {
			return array(
				'families'    => array(),
				'cssByFamily' => array(),
			);
		}

		$family  = self::sanitize_family( (string) ( $detected['family'] ?? '' ) );
		$weight  = self::sanitize_weight( (int) ( $detected['weight'] ?? 400 ) );
		$style   = self::sanitize_style( (string) ( $detected['style'] ?? 'normal' ) );
		$variant = self::weight_style_to_variant( $weight, $style );
		$cat     = self::sanitize_category( (string) ( $detected['category'] ?? 'sans-serif' ) );

		$face = array(
			'family'        => $family,
			'weight'        => $weight,
			'style'         => $style,
			'variant'       => $variant,
			'attachment_id' => $attachment_id,
		);
		$css  = self::build_font_face_css( $family, array( $face ) );

		return array(
			'families'    => array(
				array(
					'family'   => $family,
					'variants' => array( $variant ),
					'category' => $cat,
				),
			),
			'cssByFamily' => $css !== '' ? array( $family => $css ) : array(),
		);
	}

	/**
	 * @param array<int, array{file_name: string, family: string, weight: int, style: string, category: string}> $fonts
	 * @param array{ok: bool, fonts: array<int, array{path: string, entry: string, extension: string}>, temp_dir: string} $extracted
	 * @return array{families: array<int, array{family: string, variants: array<int, string>, category: string}>, cssByFamily: array<string, string>}
	 */
	public static function build_pending_live_preview_for_zip( int $attachment_id, array $fonts, array $extracted ): array {
		$serve_files = array();
		$grouped     = array();

		foreach ( $extracted['fonts'] as $index => $row ) {
			$meta    = FontFileMetadata::detect_from_zip_font_row( $row, $extracted['fonts'] );
			$family  = self::sanitize_family( $meta['family'] );
			$key     = 'f' . $index;
			$weight  = self::sanitize_weight( $meta['weight'] );
			$style   = self::sanitize_style( $meta['style'] );
			$variant = self::weight_style_to_variant( $weight, $style );
			$cat     = self::sanitize_category( $meta['category'] );

			$serve_files[ $key ] = array(
				'path' => (string) ( $row['path'] ?? '' ),
				'ext'  => (string) ( $row['extension'] ?? '' ),
			);

			if ( ! isset( $grouped[ $family ] ) ) {
				$grouped[ $family ] = array(
					'category' => $cat,
					'variants' => array(),
					'faces'    => array(),
				);
			}
			if ( ! in_array( $variant, $grouped[ $family ]['variants'], true ) ) {
				$grouped[ $family ]['variants'][] = $variant;
			}
			$grouped[ $family ]['faces'][] = array(
				'family'  => $family,
				'weight'  => $weight,
				'style'   => $style,
				'src_url' => self::preview_font_serve_url( $attachment_id, $key ),
			);
		}

		set_transient(
			self::get_preview_transient_key( $attachment_id ),
			array(
				'temp_dir' => (string) ( $extracted['temp_dir'] ?? '' ),
				'files'    => $serve_files,
				'expires'  => time() + 30 * MINUTE_IN_SECONDS,
			),
			30 * MINUTE_IN_SECONDS
		);

		$css_by_family = array();
		$catalog       = array();
		foreach ( $grouped as $family => $bundle ) {
			$css = self::build_font_face_css( $family, $bundle['faces'] );
			if ( $css !== '' ) {
				$css_by_family[ $family ] = $css;
			}
			$catalog[] = array(
				'family'   => $family,
				'variants' => self::sort_variants_public( $bundle['variants'] ),
				'category' => $bundle['category'],
			);
		}
		usort(
			$catalog,
			static function ( $a, $b ) {
				return strcasecmp( (string) ( $a['family'] ?? '' ), (string) ( $b['family'] ?? '' ) );
			}
		);

		return array(
			'families'    => $catalog,
			'cssByFamily' => $css_by_family,
		);
	}

	public static function preview_font_serve_url( int $attachment_id, string $file_key ): string {
		return add_query_arg(
			array(
				'action'        => 'sto_custom_fonts_serve_preview_font',
				'nonce'         => wp_create_nonce( self::AJAX_NONCE_ACTION ),
				'attachment_id' => $attachment_id,
				'file_key'      => sanitize_key( $file_key ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * @return array{path: string, ext: string}|null
	 */
	public static function get_preview_serve_file( int $attachment_id, string $file_key ): ?array {
		$stored = get_transient( self::get_preview_transient_key( $attachment_id ) );
		if ( ! is_array( $stored ) || ! isset( $stored['files'][ $file_key ] ) || ! is_array( $stored['files'][ $file_key ] ) ) {
			return null;
		}
		$path = (string) ( $stored['files'][ $file_key ]['path'] ?? '' );
		$ext  = (string) ( $stored['files'][ $file_key ]['ext'] ?? '' );
		if ( $path === '' || ! is_readable( $path ) ) {
			return null;
		}

		return array(
			'path' => $path,
			'ext'  => $ext,
		);
	}

	public static function clear_preview_transient( int $attachment_id ): void {
		$key    = self::get_preview_transient_key( $attachment_id );
		$stored = get_transient( $key );
		if ( is_array( $stored ) && ! empty( $stored['temp_dir'] ) ) {
			FontZipExtractor::remove_dir( (string) $stored['temp_dir'] );
		}
		delete_transient( $key );
	}

	/**
	 * @param array<int, string> $variants
	 * @return array<int, string>
	 */
	public static function sort_variants_public( array $variants ): array {
		return self::sort_variants( $variants );
	}

	/**
	 * Import one font file or every font found inside a ZIP.
	 *
	 * @return array{ok: bool, faces: array<int, array<string, mixed>>, added_count: int, message: string}
	 */
	public static function add_faces_from_attachment( int $attachment_id, bool $replace_duplicates = false ): array {
		$fail = array(
			'ok'          => false,
			'faces'       => array(),
			'added_count' => 0,
			'message'     => __( 'Could not add the font. Upload a WOFF2, WOFF, TTF, OTF font, or a ZIP that contains font files.', 'topten-simple-theme-options' ),
		);

		if ( $attachment_id <= 0 || ! self::is_uploadable_attachment( $attachment_id ) ) {
			return $fail;
		}

		if ( FontZipExtractor::is_zip_attachment( $attachment_id ) ) {
			return self::add_faces_from_zip_attachment( $attachment_id, $replace_duplicates );
		}

		$detected = FontFileMetadata::detect_from_attachment( $attachment_id );
		$incoming = array(
			array(
				'family'    => $detected['family'],
				'weight'    => $detected['weight'],
				'style'     => $detected['style'],
				'file_name' => basename( (string) get_attached_file( $attachment_id ) ),
			),
		);
		$dupes    = self::find_duplicates_in_upload( $incoming );
		if ( $dupes !== array() && ! $replace_duplicates ) {
			return array(
				'ok'               => false,
				'faces'            => array(),
				'added_count'      => 0,
				'requires_replace' => true,
				'duplicates'       => $dupes,
				'message'          => self::duplicate_replace_prompt_message( $dupes ),
			);
		}

		$face = self::add_face_auto( $attachment_id, $replace_duplicates );
		if ( $face === null ) {
			return $fail;
		}

		$replaced = count( $dupes );
		$message  = $replaced > 0
			? __( 'Custom font replaced the existing face with the same family, weight, and style.', 'topten-simple-theme-options' )
			: __( 'Custom font added.', 'topten-simple-theme-options' );

		return array(
			'ok'          => true,
			'faces'       => array( $face ),
			'added_count' => 1,
			'replaced'    => $replaced,
			'message'     => $message,
		);
	}

	/**
	 * Add a face by auto-detecting metadata from the attachment file.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function add_face_auto( int $attachment_id, bool $replace_duplicates = false ) {
		if ( $attachment_id <= 0 || ! self::is_font_attachment( $attachment_id ) ) {
			return null;
		}

		$detected = FontFileMetadata::detect_from_attachment( $attachment_id );

		return self::store_face( $detected['family'], $detected['weight'], $detected['style'], $attachment_id, $detected['category'], $replace_duplicates );
	}

	/**
	 * Store a face using metadata already detected (e.g. from a ZIP scan).
	 *
	 * @param array{family:string, weight:int|string, style:string, category:string, source?:string} $meta
	 * @return array<string, mixed>|null
	 */
	public static function add_face_with_metadata( int $attachment_id, array $meta, bool $replace_duplicates = false ) {
		if ( $attachment_id <= 0 || ! self::is_font_attachment( $attachment_id ) ) {
			return null;
		}
		$meta = FontFileMetadata::normalize( $meta );

		return self::store_face( $meta['family'], $meta['weight'], $meta['style'], $attachment_id, $meta['category'], $replace_duplicates );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function add_face( string $family, int $weight, string $style, int $attachment_id, string $category = 'sans-serif', bool $replace_duplicates = false ) {
		if ( $attachment_id <= 0 || ! self::is_font_attachment( $attachment_id ) ) {
			return null;
		}

		return self::store_face( $family, $weight, $style, $attachment_id, $category, $replace_duplicates );
	}

	/**
	 * @param array<int, array{family?: string, weight?: int|string, style?: string, file_name?: string}> $incoming
	 * @return array<int, array{family: string, weight: int, style: string, file_name: string, existing_id: string}>
	 */
	public static function find_duplicates_in_upload( array $incoming ): array {
		$duplicates    = array();
		$seen_in_batch = array();

		foreach ( $incoming as $font ) {
			if ( ! is_array( $font ) ) {
				continue;
			}
			$family = self::sanitize_family( (string) ( $font['family'] ?? '' ) );
			if ( $family === '' ) {
				continue;
			}
			$weight = self::sanitize_weight( (int) ( $font['weight'] ?? 400 ) );
			$style  = self::sanitize_style( (string) ( $font['style'] ?? 'normal' ) );
			$key    = self::face_identity_key( $family, $weight, $style );

			$existing    = self::find_face_by_identity( $family, $weight, $style );
			$in_batch    = isset( $seen_in_batch[ $key ] );
			$is_duplicate = $existing !== null || $in_batch;

			if ( $is_duplicate ) {
				$duplicates[] = array(
					'family'      => $family,
					'weight'      => $weight,
					'style'       => $style,
					'file_name'   => (string) ( $font['file_name'] ?? '' ),
					'existing_id' => $existing !== null ? (string) ( $existing['id'] ?? '' ) : '',
				);
			}

			$seen_in_batch[ $key ] = true;
		}

		return $duplicates;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find_face_by_identity( string $family, int $weight, string $style ): ?array {
		$key = self::face_identity_key( $family, $weight, $style );
		foreach ( self::get_faces() as $face ) {
			if ( ! is_array( $face ) ) {
				continue;
			}
			if ( self::face_identity_key_from_face( $face ) === $key ) {
				return $face;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array{family: string, weight: int, style: string, file_name?: string}> $duplicates
	 */
	public static function duplicate_replace_prompt_message( array $duplicates ): string {
		$count = count( $duplicates );
		if ( $count <= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: %d: number of duplicate font faces */
			_n(
				'This upload includes %d font that already exists (same family, weight, and style). Replace the existing font?',
				'This upload includes %d fonts that already exist (same family, weight, and style). Replace those existing fonts?',
				$count,
				'topten-simple-theme-options'
			),
			$count
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function store_face( string $family, int $weight, string $style, int $attachment_id, string $category, bool $replace_duplicates = false ) {
		$family = self::sanitize_family( $family );
		if ( $family === '' ) {
			return null;
		}
		$weight = self::sanitize_weight( $weight );
		$style  = self::sanitize_style( $style );

		$faces = self::get_faces();
		$key   = self::face_identity_key( $family, $weight, $style );

		if ( ! $replace_duplicates ) {
			foreach ( $faces as $row ) {
				if ( is_array( $row ) && self::face_identity_key_from_face( $row ) === $key ) {
					return null;
				}
			}
		} else {
			$faces = array_values(
				array_filter(
					$faces,
					static function ( $row ) use ( $key ) {
						return ! is_array( $row ) || self::face_identity_key_from_face( $row ) !== $key;
					}
				)
			);
		}

		$face = array(
			'id'            => 'cf_' . strtolower( wp_generate_password( 12, false, false ) ),
			'family'        => $family,
			'category'      => self::sanitize_category( $category ),
			'weight'        => $weight,
			'style'         => $style,
			'variant'       => self::weight_style_to_variant( $weight, $style ),
			'attachment_id' => $attachment_id,
			'created_at'    => gmdate( 'c' ),
		);

		$faces[] = $face;
		update_option( self::OPTION_KEY, $faces, false );

		return $face;
	}

	private static function face_identity_key( string $family, int $weight, string $style ): string {
		return strtolower( self::sanitize_family( $family ) ) . '|' . self::sanitize_weight( $weight ) . '|' . self::sanitize_style( $style );
	}

	/**
	 * @param array<string, mixed> $face
	 */
	private static function face_identity_key_from_face( array $face ): string {
		return self::face_identity_key(
			(string) ( $face['family'] ?? '' ),
			(int) ( $face['weight'] ?? 400 ),
			(string) ( $face['style'] ?? 'normal' )
		);
	}

	/**
	 * Valid custom-font face id (`cf_` + alphanumeric). Preserves case for legacy rows.
	 */
	public static function sanitize_face_id( string $face_id ): string {
		$face_id = trim( (string) $face_id );
		if ( preg_match( '/^(cf_[a-zA-Z0-9]+)$/', $face_id ) ) {
			return $face_id;
		}

		return '';
	}

	public static function delete_face( string $face_id ): bool {
		return self::delete_faces( array( $face_id ) ) > 0;
	}

	/**
	 * @param array<int, string> $face_ids
	 */
	public static function delete_faces( array $face_ids ): int {
		$allowed = array();
		foreach ( $face_ids as $face_id ) {
			$face_id = self::sanitize_face_id( (string) $face_id );
			if ( $face_id !== '' ) {
				$allowed[ strtolower( $face_id ) ] = true;
			}
		}
		if ( $allowed === array() ) {
			return 0;
		}

		$faces  = self::get_faces();
		$before = count( $faces );
		$faces  = array_values(
			array_filter(
				$faces,
				static function ( $row ) use ( $allowed ) {
					if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
						return true;
					}
					$row_id = self::sanitize_face_id( (string) $row['id'] );
					if ( $row_id === '' ) {
						return true;
					}

					return ! isset( $allowed[ strtolower( $row_id ) ] );
				}
			)
		);
		$deleted = $before - count( $faces );
		if ( $deleted <= 0 ) {
			return 0;
		}
		update_option( self::OPTION_KEY, $faces, false );

		return $deleted;
	}

	public static function is_font_attachment( int $attachment_id ): bool {
		$mime = get_post_mime_type( $attachment_id );
		if ( ! is_string( $mime ) || $mime === '' ) {
			return false;
		}
		$allowed = array(
			'font/woff',
			'font/woff2',
			'application/font-woff',
			'application/font-woff2',
			'application/x-font-woff',
			'application/x-font-ttf',
			'application/x-font-truetype',
			'application/vnd.ms-fontobject',
			'font/ttf',
			'font/otf',
		);

		return in_array( $mime, $allowed, true );
	}

	public static function weight_style_to_variant( int $weight, string $style ): string {
		$weight = self::sanitize_weight( $weight );
		$style  = self::sanitize_style( $style );
		if ( $weight === 400 && $style === 'normal' ) {
			return 'regular';
		}
		if ( $weight === 400 && $style === 'italic' ) {
			return 'italic';
		}
		if ( $style === 'italic' ) {
			return (string) $weight . 'italic';
		}

		return (string) $weight;
	}

	public static function sanitize_family( string $family ): string {
		$family = wp_strip_all_tags( $family );
		$family = preg_replace( '/["\']/', '', $family );
		$family = trim( preg_replace( '/\s+/', ' ', $family ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $family, 0, 120 );
		}

		return substr( $family, 0, 120 );
	}

	public static function sanitize_weight( int $weight ): int {
		if ( ! in_array( $weight, self::ALLOWED_WEIGHTS, true ) ) {
			return 400;
		}

		return $weight;
	}

	public static function sanitize_style( string $style ): string {
		$style = strtolower( sanitize_key( $style ) );

		return in_array( $style, self::ALLOWED_STYLES, true ) ? $style : 'normal';
	}

	public static function sanitize_category( string $category ): string {
		$category = sanitize_key( $category );

		return in_array( $category, self::ALLOWED_CATEGORIES, true ) ? $category : 'sans-serif';
	}

	/**
	 * @param array<int, string> $variants
	 * @return array<int, string>
	 */
	private static function sort_variants( array $variants ): array {
		usort(
			$variants,
			static function ( $a, $b ) {
				$pa = self::variant_sort_key( (string) $a );
				$pb = self::variant_sort_key( (string) $b );
				if ( $pa[0] !== $pb[0] ) {
					return $pa[0] <=> $pb[0];
				}

				return $pa[1] <=> $pb[1];
			}
		);

		return array_values( array_unique( $variants ) );
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private static function variant_sort_key( string $variant ): array {
		if ( $variant === 'regular' ) {
			return array( 400, 0 );
		}
		if ( $variant === 'italic' ) {
			return array( 400, 1 );
		}
		if ( preg_match( '/^(\d{3})italic$/', $variant, $m ) ) {
			return array( (int) $m[1], 1 );
		}
		if ( preg_match( '/^\d{3}$/', $variant ) ) {
			return array( (int) $variant, 0 );
		}

		return array( 400, 0 );
	}

	private static function url_format_hint( string $url ): string {
		$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'woff2':
				return 'woff2';
			case 'woff':
				return 'woff';
			case 'ttf':
				return 'truetype';
			case 'otf':
				return 'opentype';
			case 'eot':
				return 'embedded-opentype';
			default:
				return 'woff2';
		}
	}

	/**
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public static function allow_font_upload_mimes( array $mimes ): array {
		$mimes['woff']  = 'font/woff|application/font-woff|application/x-font-woff';
		$mimes['woff2'] = 'font/woff2|application/font-woff2';
		$mimes['ttf']   = 'font/ttf|application/x-font-ttf|application/x-font-truetype';
		$mimes['otf']   = 'font/otf|application/x-font-opentype';
		$mimes['zip']   = 'application/zip|application/x-zip-compressed';

		return $mimes;
	}

	/**
	 * @return array{ok: bool, is_zip: bool, file_name: string, fonts: array<int, array<string, mixed>>, note: string, message: string}
	 */
	/**
	 * @param string $zip_path
	 * @param string $zip_label
	 * @param int    $attachment_id ZIP media attachment (for preview font URLs).
	 */
	private static function preview_from_zip( string $zip_path, string $zip_label, int $attachment_id = 0 ): array {
		$fail = array(
			'ok'        => false,
			'is_zip'    => true,
			'file_name' => $zip_label,
			'fonts'     => array(),
			'note'      => '',
			'message'   => __( 'No font files (WOFF2, WOFF, TTF, OTF, EOT) were found inside that ZIP.', 'topten-simple-theme-options' ),
		);

		if ( $zip_path === '' || ! is_readable( $zip_path ) ) {
			$fail['message'] = __( 'Could not read the ZIP file.', 'topten-simple-theme-options' );

			return $fail;
		}

		$extracted = FontZipExtractor::extract_fonts( $zip_path );
		if ( ! $extracted['ok'] ) {
			$fail['message'] = $extracted['error'] !== '' ? $extracted['error'] : $fail['message'];
			if ( $extracted['temp_dir'] !== '' ) {
				FontZipExtractor::remove_dir( $extracted['temp_dir'] );
			}

			return $fail;
		}

		$fonts = array();
		foreach ( $extracted['fonts'] as $row ) {
			$label   = isset( $row['entry'] ) ? basename( (string) $row['entry'] ) : basename( $row['path'] );
			$meta    = FontFileMetadata::detect_from_zip_font_row( $row, $extracted['fonts'] );
			$fonts[] = array(
				'file_name' => $label,
				'family'    => $meta['family'],
				'weight'    => $meta['weight'],
				'style'     => $meta['style'],
				'category'  => $meta['category'],
				'source'    => $meta['source'],
			);
		}

		$note = '';
		if ( FontFileMetadata::zip_needs_woff2_filename_notice( $fonts, $extracted['fonts'] ) ) {
			$note = __( 'Some WOFF2 files could not be read from the archive; weight and style were taken from file names. Add matching TTF or OTF files to the ZIP for full auto-detection.', 'topten-simple-theme-options' );
		}

		$live_preview = array(
			'families'    => array(),
			'cssByFamily' => array(),
		);
		if ( $fonts !== array() ) {
			$live_preview = self::build_pending_live_preview_for_zip( $attachment_id, $fonts, $extracted );
		} elseif ( $extracted['temp_dir'] !== '' ) {
			FontZipExtractor::remove_dir( $extracted['temp_dir'] );
		}

		if ( $fonts === array() ) {
			return $fail;
		}

		return array(
			'ok'           => true,
			'is_zip'       => true,
			'file_name'    => $zip_label,
			'fonts'        => $fonts,
			'note'         => $note,
			'message'      => '',
			'duplicates'   => self::find_duplicates_in_upload( $fonts ),
			'live_preview' => $live_preview,
		);
	}

	/**
	 * @return array{ok: bool, faces: array<int, array<string, mixed>>, added_count: int, message: string}
	 */
	private static function add_faces_from_zip_attachment( int $zip_attachment_id, bool $replace_duplicates = false ): array {
		$fail = array(
			'ok'          => false,
			'faces'       => array(),
			'added_count' => 0,
			'message'     => __( 'Could not import fonts from that ZIP.', 'topten-simple-theme-options' ),
		);

		$zip_path = (string) get_attached_file( $zip_attachment_id );
		if ( $zip_path === '' || ! is_readable( $zip_path ) ) {
			return $fail;
		}

		self::clear_preview_transient( $zip_attachment_id );

		$extracted = FontZipExtractor::extract_fonts( $zip_path );
		if ( ! $extracted['ok'] ) {
			$fail['message'] = $extracted['error'] !== '' ? $extracted['error'] : $fail['message'];
			if ( $extracted['temp_dir'] !== '' ) {
				FontZipExtractor::remove_dir( $extracted['temp_dir'] );
			}

			return $fail;
		}

		$incoming = array();
		foreach ( $extracted['fonts'] as $row ) {
			$meta       = FontFileMetadata::detect_from_zip_font_row( $row, $extracted['fonts'] );
			$incoming[] = array(
				'family'    => $meta['family'],
				'weight'    => $meta['weight'],
				'style'     => $meta['style'],
				'file_name' => isset( $row['entry'] ) ? basename( (string) $row['entry'] ) : basename( (string) ( $row['path'] ?? '' ) ),
			);
		}

		$dupes = self::find_duplicates_in_upload( $incoming );
		if ( $dupes !== array() && ! $replace_duplicates ) {
			if ( $extracted['temp_dir'] !== '' ) {
				FontZipExtractor::remove_dir( $extracted['temp_dir'] );
			}

			return array(
				'ok'               => false,
				'faces'            => array(),
				'added_count'      => 0,
				'requires_replace' => true,
				'duplicates'       => $dupes,
				'message'          => self::duplicate_replace_prompt_message( $dupes ),
			);
		}

		$added     = array();
		$skipped   = 0;
		$replaced  = 0;
		$seen_keys = array();

		foreach ( $extracted['fonts'] as $row ) {
			$meta = FontFileMetadata::detect_from_zip_font_row( $row, $extracted['fonts'] );

			$font_attachment_id = FontZipExtractor::sideload_extracted_font( $row );
			if ( $font_attachment_id <= 0 ) {
				++$skipped;
				continue;
			}
			$key_before = self::face_identity_key( $meta['family'], $meta['weight'], $meta['style'] );
			$had_saved  = self::find_face_by_identity( $meta['family'], $meta['weight'], $meta['style'] ) !== null;
			$face       = self::add_face_with_metadata( $font_attachment_id, $meta, $replace_duplicates );
			if ( $face === null ) {
				wp_delete_attachment( $font_attachment_id, true );
				++$skipped;
				continue;
			}
			if ( $replace_duplicates && ( $had_saved || isset( $seen_keys[ $key_before ] ) ) ) {
				++$replaced;
			}
			$seen_keys[ $key_before ] = true;
			$added[]                  = $face;
		}

		if ( $extracted['temp_dir'] !== '' ) {
			FontZipExtractor::remove_dir( $extracted['temp_dir'] );
		}

		if ( $added === array() ) {
			$fail['message'] = __( 'Font files were found in the ZIP but none could be imported.', 'topten-simple-theme-options' );

			return $fail;
		}

		$count = count( $added );
		if ( $replaced > 0 && $skipped > 0 ) {
			$message = sprintf(
				/* translators: 1: imported count, 2: replaced count, 3: skipped count */
				__( 'Imported %1$d font(s) from the ZIP (%2$d replaced existing). %3$d file(s) were skipped.', 'topten-simple-theme-options' ),
				$count,
				$replaced,
				$skipped
			);
		} elseif ( $replaced > 0 ) {
			$message = sprintf(
				/* translators: 1: imported count, 2: replaced count */
				__( 'Imported %1$d font(s) from the ZIP. %2$d replaced existing fonts.', 'topten-simple-theme-options' ),
				$count,
				$replaced
			);
		} elseif ( $skipped > 0 ) {
			$message = sprintf(
				/* translators: 1: number imported, 2: number skipped */
				__( 'Imported %1$d font(s) from the ZIP. %2$d file(s) were skipped.', 'topten-simple-theme-options' ),
				$count,
				$skipped
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of fonts imported */
				_n( 'Imported %d font from the ZIP.', 'Imported %d fonts from the ZIP.', $count, 'topten-simple-theme-options' ),
				$count
			);
		}

		return array(
			'ok'          => true,
			'faces'       => $added,
			'added_count' => $count,
			'replaced'    => $replaced,
			'message'     => $message,
		);
	}

	private static function woff2_note_for_path( string $file_path ): string {
		if ( strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) !== 'woff2' || function_exists( 'brotli_uncompress' ) ) {
			return '';
		}
		$meta = FontFileMetadata::detect_from_file( $file_path );
		if ( is_array( $meta ) && ( $meta['source'] ?? '' ) === 'sfnt' ) {
			return '';
		}

		return __( 'Weight and style for this WOFF2 file were read from the file name. Upload a TTF or OTF version for full auto-detection.', 'topten-simple-theme-options' );
	}
}
