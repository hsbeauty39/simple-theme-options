<?php
/**
 * Auto-detect family / weight / style / category from a font file.
 *
 * Supports SFNT-based formats by reading the `name` and `OS/2` tables:
 *   - TTF / OTF  → parsed directly.
 *   - WOFF       → per-table zlib decompression (requires `zlib`, always available in WP).
 *   - WOFF2      → only when the `brotli` PHP extension is available; otherwise the
 *                  parser falls back to filename heuristics (and any pre-existing
 *                  manual overrides).
 *
 * The parser is intentionally tolerant: any failure step degrades to a sensible
 * default so the upload flow never blocks on a malformed table.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Data;

defined( 'ABSPATH' ) || exit;

final class FontFileMetadata {

	/**
	 * Detect metadata from an attachment ID.
	 *
	 * @return array{family:string, weight:int, style:string, category:string, source:string}
	 */
	public static function detect_from_attachment( int $attachment_id ): array {
		$file = $attachment_id > 0 ? (string) get_attached_file( $attachment_id ) : '';
		$name = $file !== '' ? basename( $file ) : '';
		$meta = self::detect_from_file( $file );
		if ( $meta === null ) {
			$meta = self::infer_from_filename( $name );
		}
		if ( ( $meta['family'] ?? '' ) === '' ) {
			$meta['family'] = self::clean_family_from_filename( $name );
		}
		if ( ( $meta['family'] ?? '' ) === '' ) {
			$meta['family'] = __( 'Custom Font', 'topten-simple-theme-options' );
		}

		return self::normalize( $meta );
	}

	/**
	 * Detect metadata from a font file path.
	 *
	 * @return array{family:string, weight:int, style:string, category:string, source:string}|null
	 */
	public static function detect_from_file( string $file_path ): ?array {
		if ( $file_path === '' || ! is_readable( $file_path ) ) {
			return null;
		}

		$bin = @file_get_contents( $file_path );
		if ( ! is_string( $bin ) || $bin === '' ) {
			return null;
		}

		$ext  = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$sfnt = self::extract_tables( $bin, $ext );
		if ( ! is_array( $sfnt ) ) {
			return self::infer_from_filename( basename( $file_path ) );
		}

		$names  = isset( $sfnt['name'] ) ? self::parse_name_table( $sfnt['name'] ) : array();
		$os2    = isset( $sfnt['OS/2'] ) ? self::parse_os2_table( $sfnt['OS/2'] ) : array();
		$family = self::pick_family( $names );
		$sub    = self::pick_subfamily( $names );
		$weight = self::derive_weight( $os2, $sub );
		$style  = self::derive_style( $os2, $sub );
		$cat    = self::derive_category( $os2, $family . ' ' . $sub );

		if ( $family === '' ) {
			$family = self::clean_family_from_filename( basename( $file_path ) );
		}

		return array(
			'family'   => $family,
			'weight'   => $weight,
			'style'    => $style,
			'category' => $cat,
			'source'   => 'sfnt',
		);
	}

	/**
	 * Detect metadata for one font inside a ZIP, reusing TTF/OTF/WOFF siblings when WOFF2 cannot be parsed.
	 *
	 * @param array{path: string, entry: string, extension: string}          $row
	 * @param array<int, array{path: string, entry: string, extension: string}> $all_rows
	 * @return array{family:string, weight:int, style:string, category:string, source:string}
	 */
	public static function detect_from_zip_font_row( array $row, array $all_rows ): array {
		$label = isset( $row['entry'] ) ? basename( (string) $row['entry'] ) : basename( (string) ( $row['path'] ?? '' ) );
		$meta  = self::detect_from_file( (string) ( $row['path'] ?? '' ) );
		if ( is_array( $meta ) && ( $meta['source'] ?? '' ) === 'sfnt' ) {
			return self::normalize( $meta );
		}

		$sibling = self::find_zip_metadata_sibling( $row, $all_rows );
		if ( $sibling !== null ) {
			$sibling_meta = self::detect_from_file( (string) $sibling['path'] );
			if ( is_array( $sibling_meta ) && ( $sibling_meta['source'] ?? '' ) === 'sfnt' ) {
				return self::normalize( $sibling_meta );
			}
		}

		if ( ! is_array( $meta ) ) {
			$meta = self::infer_from_filename( $label );
		}

		if ( ( $meta['family'] ?? '' ) === '' ) {
			$meta['family'] = self::clean_family_from_filename( $label );
		}

		return self::normalize( $meta );
	}

	/**
	 * Whether a ZIP preview should mention limited WOFF2 parsing.
	 *
	 * @param array<int, array{family:string, weight:int, style:string, category:string, source:string}> $fonts
	 * @param array<int, array{path: string, entry: string, extension: string}>                            $rows
	 */
	public static function zip_needs_woff2_filename_notice( array $fonts, array $rows ): bool {
		if ( function_exists( 'brotli_uncompress' ) ) {
			return false;
		}
		foreach ( $rows as $index => $row ) {
			if ( ( $row['extension'] ?? '' ) !== 'woff2' ) {
				continue;
			}
			$meta = $fonts[ $index ] ?? array();
			if ( ( $meta['source'] ?? '' ) === 'filename' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Last-resort detection from the file name alone.
	 *
	 * @return array{family:string, weight:int, style:string, category:string, source:string}
	 */
	public static function infer_from_filename( string $filename ): array {
		$base   = self::clean_family_from_filename( $filename );
		$lower  = strtolower( str_replace( array( '-', '_', ' ' ), ' ', (string) $filename ) );
		$weight = 400;
		$style  = 'normal';

		$weight_map = array(
			100 => array( 'thin', 'hairline' ),
			200 => array( 'extralight', 'extra light', 'ultralight', 'ultra light' ),
			300 => array( 'light' ),
			400 => array( 'regular', 'normal', 'book' ),
			500 => array( 'medium' ),
			600 => array( 'semibold', 'semi bold', 'demibold', 'demi bold' ),
			700 => array( 'bold' ),
			800 => array( 'extrabold', 'extra bold', 'ultrabold', 'ultra bold', 'heavy' ),
			900 => array( 'black' ),
		);
		foreach ( $weight_map as $candidate_weight => $needles ) {
			foreach ( $needles as $needle ) {
				if ( strpos( $lower, $needle ) !== false ) {
					$weight = $candidate_weight;
					break 2;
				}
			}
		}
		if ( preg_match( '/(?<![a-z])(italic|oblique)(?![a-z])/i', $filename ) ) {
			$style = 'italic';
		}

		return array(
			'family'   => $base,
			'weight'   => $weight,
			'style'    => $style,
			'category' => 'sans-serif',
			'source'   => 'filename',
		);
	}

	/**
	 * @param array{family:string, weight:int|string, style:string, category:string, source?:string} $meta
	 * @return array{family:string, weight:int, style:string, category:string, source:string}
	 */
	public static function normalize( array $meta ): array {
		$family   = CustomFontsRegistry::sanitize_family( (string) ( $meta['family'] ?? '' ) );
		$weight   = CustomFontsRegistry::sanitize_weight( (int) ( $meta['weight'] ?? 400 ) );
		$style    = CustomFontsRegistry::sanitize_style( (string) ( $meta['style'] ?? 'normal' ) );
		$category = CustomFontsRegistry::sanitize_category( (string) ( $meta['category'] ?? 'sans-serif' ) );
		$source   = (string) ( $meta['source'] ?? 'sfnt' );

		return array(
			'family'   => $family,
			'weight'   => $weight,
			'style'    => $style,
			'category' => $category,
			'source'   => $source,
		);
	}

	/**
	 * Extract `name` and `OS/2` table buffers from a font binary.
	 *
	 * @return array<string,string>|null
	 */
	private static function extract_tables( string $bin, string $ext ): ?array {
		switch ( $ext ) {
			case 'ttf':
			case 'otf':
				return self::sfnt_tables( $bin );
			case 'woff':
				return self::woff_tables( $bin );
			case 'woff2':
				return self::woff2_tables( $bin );
		}

		return null;
	}

	/**
	 * @return array<string,string>|null
	 */
	private static function sfnt_tables( string $bin ): ?array {
		if ( strlen( $bin ) < 12 ) {
			return null;
		}
		$num_tables = self::read_u16( $bin, 4 );
		if ( $num_tables <= 0 || $num_tables > 1024 ) {
			return null;
		}
		$dir_offset = 12;
		if ( strlen( $bin ) < $dir_offset + ( $num_tables * 16 ) ) {
			return null;
		}

		$wanted = array( 'name' => true, 'OS/2' => true );
		$tables = array();
		for ( $i = 0; $i < $num_tables; $i++ ) {
			$entry  = $dir_offset + ( $i * 16 );
			$tag    = substr( $bin, $entry, 4 );
			$offset = self::read_u32( $bin, $entry + 8 );
			$length = self::read_u32( $bin, $entry + 12 );
			if ( ! isset( $wanted[ $tag ] ) ) {
				continue;
			}
			$slice = substr( $bin, $offset, $length );
			if ( is_string( $slice ) && $slice !== '' ) {
				$tables[ $tag ] = $slice;
			}
		}

		return $tables;
	}

	/**
	 * @return array<string,string>|null
	 */
	private static function woff_tables( string $bin ): ?array {
		if ( strlen( $bin ) < 44 || substr( $bin, 0, 4 ) !== 'wOFF' ) {
			return null;
		}
		$num_tables = self::read_u16( $bin, 12 );
		if ( $num_tables <= 0 || $num_tables > 1024 ) {
			return null;
		}
		$dir_offset = 44;
		if ( strlen( $bin ) < $dir_offset + ( $num_tables * 20 ) ) {
			return null;
		}

		$wanted = array( 'name' => true, 'OS/2' => true );
		$tables = array();
		for ( $i = 0; $i < $num_tables; $i++ ) {
			$entry        = $dir_offset + ( $i * 20 );
			$tag          = substr( $bin, $entry, 4 );
			$tbl_offset   = self::read_u32( $bin, $entry + 4 );
			$comp_length  = self::read_u32( $bin, $entry + 8 );
			$orig_length  = self::read_u32( $bin, $entry + 12 );
			if ( ! isset( $wanted[ $tag ] ) ) {
				continue;
			}
			$slice = substr( $bin, $tbl_offset, $comp_length );
			if ( ! is_string( $slice ) || $slice === '' ) {
				continue;
			}
			if ( $comp_length < $orig_length && function_exists( 'gzuncompress' ) ) {
				$inflated = @gzuncompress( $slice );
				if ( is_string( $inflated ) && $inflated !== '' ) {
					$slice = $inflated;
				}
			}
			$tables[ $tag ] = $slice;
		}

		return $tables;
	}

	/**
	 * @return array<string,string>|null
	 */
	private static function woff2_tables( string $bin ): ?array {
		if ( strlen( $bin ) < 48 || substr( $bin, 0, 4 ) !== 'wOF2' ) {
			return null;
		}
		if ( ! function_exists( 'brotli_uncompress' ) ) {
			return null;
		}
		$num_tables   = self::read_u16( $bin, 12 );
		$compressed_len = self::read_u32( $bin, 20 );
		if ( $num_tables <= 0 || $num_tables > 1024 || $compressed_len <= 0 ) {
			return null;
		}

		$known_tags = array(
			'cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ',
			'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT', 'EBLC', 'gasp',
			'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF',
			'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL',
			'SVG ', 'sbix', 'acnt', 'avar', 'bdat', 'bloc', 'bsln', 'cvar', 'fdsc',
			'feat', 'fmtx', 'fvar', 'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx',
			'opbd', 'prop', 'trak', 'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill',
		);

		$pos     = 48;
		$entries = array();
		$running = 0;
		for ( $i = 0; $i < $num_tables; $i++ ) {
			if ( $pos >= strlen( $bin ) ) {
				return null;
			}
			$flags = ord( $bin[ $pos ] );
			$pos++;
			$tag_index = $flags & 0x3F;
			$transform = ( $flags >> 6 ) & 0x03;
			if ( $tag_index === 0x3F ) {
				$tag = substr( $bin, $pos, 4 );
				$pos += 4;
			} else {
				$tag = isset( $known_tags[ $tag_index ] ) ? $known_tags[ $tag_index ] : null;
			}
			$orig_length = self::read_base128( $bin, $pos );
			if ( $orig_length === null ) {
				return null;
			}
			$length = $orig_length;
			$needs_transform_length = ( ( $tag === 'glyf' || $tag === 'loca' ) && $transform === 0 )
				|| ( $tag !== 'glyf' && $tag !== 'loca' && $transform !== 0 );
			if ( $needs_transform_length ) {
				$transform_length = self::read_base128( $bin, $pos );
				if ( $transform_length === null ) {
					return null;
				}
				$length = $transform_length;
			}
			$entries[] = array(
				'tag'    => $tag,
				'offset' => $running,
				'length' => $length,
			);
			$running += $length;
		}

		$compressed = substr( $bin, $pos, $compressed_len );
		if ( ! is_string( $compressed ) || $compressed === '' ) {
			return null;
		}
		$body = @brotli_uncompress( $compressed );
		if ( ! is_string( $body ) || $body === '' ) {
			return null;
		}

		$wanted = array( 'name' => true, 'OS/2' => true );
		$tables = array();
		foreach ( $entries as $entry ) {
			$tag = $entry['tag'];
			if ( ! is_string( $tag ) || ! isset( $wanted[ $tag ] ) ) {
				continue;
			}
			$slice = substr( $body, $entry['offset'], $entry['length'] );
			if ( is_string( $slice ) && $slice !== '' ) {
				$tables[ $tag ] = $slice;
			}
		}

		return $tables;
	}

	/**
	 * Parse the `name` table into a list of records keyed by nameID.
	 *
	 * @return array<int, array<int, string>>  nameID => list of decoded strings (preferred-first).
	 */
	private static function parse_name_table( string $bin ): array {
		if ( strlen( $bin ) < 6 ) {
			return array();
		}
		$count        = self::read_u16( $bin, 2 );
		$string_start = self::read_u16( $bin, 4 );
		if ( $count <= 0 ) {
			return array();
		}

		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$rec_offset = 6 + ( $i * 12 );
			if ( strlen( $bin ) < $rec_offset + 12 ) {
				break;
			}
			$platform_id = self::read_u16( $bin, $rec_offset );
			$encoding_id = self::read_u16( $bin, $rec_offset + 2 );
			$language_id = self::read_u16( $bin, $rec_offset + 4 );
			$name_id     = self::read_u16( $bin, $rec_offset + 6 );
			$length      = self::read_u16( $bin, $rec_offset + 8 );
			$offset      = self::read_u16( $bin, $rec_offset + 10 );
			$raw         = substr( $bin, $string_start + $offset, $length );
			if ( ! is_string( $raw ) || $raw === '' ) {
				continue;
			}
			$str = self::decode_name_string( $raw, $platform_id, $encoding_id );
			if ( $str === '' ) {
				continue;
			}
			$priority = self::name_priority( $platform_id, $encoding_id, $language_id );
			if ( ! isset( $out[ $name_id ] ) ) {
				$out[ $name_id ] = array();
			}
			$out[ $name_id ][ $priority ] = $str;
		}

		$final = array();
		foreach ( $out as $name_id => $records ) {
			ksort( $records );
			$final[ $name_id ] = array_values( $records );
		}

		return $final;
	}

	/**
	 * Parse the relevant fields of the OS/2 table. Tolerates short tables
	 * (Apple's original v0 is 68 bytes; MS v0 is 78 bytes; v1+ are larger).
	 *
	 * @return array<string, int|array<int,int>>
	 */
	private static function parse_os2_table( string $bin ): array {
		$len = strlen( $bin );
		if ( $len < 6 ) {
			return array();
		}
		$weight       = self::read_u16( $bin, 4 );
		$family_class = $len >= 31 ? ord( $bin[30] ) : 0;
		$panose       = array();
		if ( $len >= 42 ) {
			for ( $i = 0; $i < 10; $i++ ) {
				$panose[ $i ] = ord( $bin[ 32 + $i ] );
			}
		}
		$fs_selection = $len >= 64 ? self::read_u16( $bin, 62 ) : 0;

		return array(
			'weight'       => $weight,
			'family_class' => $family_class,
			'panose'       => $panose,
			'fs_selection' => $fs_selection,
		);
	}

	/**
	 * @param array<int, array<int, string>> $names
	 */
	private static function pick_family( array $names ): string {
		foreach ( array( 16, 1 ) as $id ) {
			if ( isset( $names[ $id ] ) && $names[ $id ] !== array() ) {
				return CustomFontsRegistry::sanitize_family( (string) $names[ $id ][0] );
			}
		}

		return '';
	}

	/**
	 * @param array<int, array<int, string>> $names
	 */
	private static function pick_subfamily( array $names ): string {
		foreach ( array( 17, 2 ) as $id ) {
			if ( isset( $names[ $id ] ) && $names[ $id ] !== array() ) {
				return trim( (string) $names[ $id ][0] );
			}
		}

		return '';
	}

	/**
	 * @param array<string, int|array<int,int>> $os2
	 */
	private static function derive_weight( array $os2, string $subfamily ): int {
		if ( isset( $os2['weight'] ) && (int) $os2['weight'] > 0 ) {
			$weight = (int) $os2['weight'];
			if ( $weight >= 1 && $weight <= 9 ) {
				$weight *= 100;
			}
			$weight = (int) ( round( $weight / 100 ) * 100 );
			if ( $weight >= 100 && $weight <= 900 ) {
				return $weight;
			}
		}
		$inferred = self::infer_from_filename( $subfamily );

		return (int) $inferred['weight'];
	}

	/**
	 * @param array<string, int|array<int,int>> $os2
	 */
	private static function derive_style( array $os2, string $subfamily ): string {
		if ( isset( $os2['fs_selection'] ) && ( ( (int) $os2['fs_selection'] ) & 0x01 ) === 1 ) {
			return 'italic';
		}
		if ( preg_match( '/italic|oblique/i', $subfamily ) ) {
			return 'italic';
		}

		return 'normal';
	}

	/**
	 * @param array<string, int|array<int,int>> $os2
	 */
	private static function derive_category( array $os2, string $hints ): string {
		$class  = isset( $os2['family_class'] ) ? (int) $os2['family_class'] : 0;
		$panose = isset( $os2['panose'] ) && is_array( $os2['panose'] ) ? $os2['panose'] : array();

		if ( isset( $panose[3] ) && (int) $panose[3] === 9 ) {
			return 'monospace';
		}
		if ( preg_match( '/\b(mono|code|console)\b/i', $hints ) ) {
			return 'monospace';
		}

		switch ( $class ) {
			case 1:
			case 2:
			case 3:
			case 4:
			case 5:
			case 7:
				return 'serif';
			case 8:
				return 'sans-serif';
			case 9:
				return 'display';
			case 10:
				return 'handwriting';
			case 12:
				return 'display';
		}

		if ( isset( $panose[0] ) ) {
			switch ( (int) $panose[0] ) {
				case 2:
					$serif = isset( $panose[1] ) ? (int) $panose[1] : 0;
					if ( $serif >= 11 && $serif <= 15 ) {
						return 'sans-serif';
					}
					if ( $serif >= 2 && $serif <= 10 ) {
						return 'serif';
					}
					break;
				case 3:
					return 'handwriting';
				case 4:
					return 'display';
			}
		}

		if ( preg_match( '/script|hand|brush/i', $hints ) ) {
			return 'handwriting';
		}
		if ( preg_match( '/serif/i', $hints ) && ! preg_match( '/sans/i', $hints ) ) {
			return 'serif';
		}
		if ( preg_match( '/display|poster|stencil|condensed/i', $hints ) ) {
			return 'display';
		}

		return 'sans-serif';
	}

	/**
	 * @param array{path: string, entry: string, extension: string}          $row
	 * @param array<int, array{path: string, entry: string, extension: string}> $all_rows
	 * @return array{path: string, entry: string, extension: string}|null
	 */
	private static function find_zip_metadata_sibling( array $row, array $all_rows ): ?array {
		$row_path = (string) ( $row['path'] ?? '' );
		$row_ext  = strtolower( (string) ( $row['extension'] ?? '' ) );
		$row_stem = self::normalize_font_stem(
			pathinfo( isset( $row['entry'] ) ? basename( (string) $row['entry'] ) : basename( $row_path ), PATHINFO_FILENAME )
		);
		if ( $row_stem === '' ) {
			return null;
		}

		$priority = array(
			'ttf'   => 1,
			'otf'   => 2,
			'woff'  => 3,
			'eot'   => 4,
			'woff2' => 5,
		);

		$best     = null;
		$best_pri = 999;

		foreach ( $all_rows as $other ) {
			$other_path = (string) ( $other['path'] ?? '' );
			if ( $other_path === '' || $other_path === $row_path ) {
				continue;
			}
			$other_ext = strtolower( (string) ( $other['extension'] ?? '' ) );
			if ( ! isset( $priority[ $other_ext ] ) ) {
				continue;
			}
			if ( $row_ext === 'woff2' && $other_ext === 'woff2' ) {
				continue;
			}
			$other_stem = self::normalize_font_stem(
				pathinfo( isset( $other['entry'] ) ? basename( (string) $other['entry'] ) : basename( $other_path ), PATHINFO_FILENAME )
			);
			if ( $other_stem !== $row_stem ) {
				continue;
			}
			if ( $priority[ $other_ext ] < $best_pri ) {
				$best_pri = $priority[ $other_ext ];
				$best     = $other;
			}
		}

		return $best;
	}

	private static function normalize_font_stem( string $stem ): string {
		$stem = strtolower( $stem );
		$stem = (string) preg_replace( '/[-_]+/', '-', $stem );
		$stem = trim( $stem, '-' );

		return $stem;
	}

	private static function clean_family_from_filename( string $filename ): string {
		$base   = pathinfo( $filename, PATHINFO_FILENAME );
		$base   = (string) preg_replace( '/[-_]+/', ' ', $base );
		$base   = (string) preg_replace_callback(
			'/([a-z])([A-Z])/',
			static function ( $m ) {
				return $m[1] . ' ' . $m[2];
			},
			$base
		);
		$tokens = preg_split( '/\s+/', trim( $base ) );
		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}
		$drop = array(
			'thin', 'hairline', 'extralight', 'extra', 'ultralight', 'ultra',
			'light', 'regular', 'normal', 'book', 'medium', 'semibold', 'semi',
			'demibold', 'demi', 'bold', 'extrabold', 'ultrabold', 'heavy',
			'black', 'italic', 'oblique', 'variable', 'vf', 'roman',
			'condensed', 'expanded', 'narrow', 'wide', 'display',
		);
		$keep = array();
		foreach ( $tokens as $token ) {
			$norm = strtolower( $token );
			if ( $norm === '' || in_array( $norm, $drop, true ) ) {
				continue;
			}
			$keep[] = $token;
		}
		$family = trim( implode( ' ', $keep ) );

		return CustomFontsRegistry::sanitize_family( $family );
	}

	private static function decode_name_string( string $raw, int $platform_id, int $encoding_id ): string {
		if ( $platform_id === 1 ) {
			$decoded = function_exists( 'iconv' ) ? @iconv( 'MACINTOSH', 'UTF-8//IGNORE', $raw ) : false;
			if ( is_string( $decoded ) && $decoded !== '' ) {
				return trim( $decoded );
			}

			return trim( preg_replace( '/[^\x20-\x7E]+/', '', $raw ) );
		}
		$decoded = function_exists( 'iconv' ) ? @iconv( 'UTF-16BE', 'UTF-8//IGNORE', $raw ) : false;
		if ( ! is_string( $decoded ) || $decoded === '' ) {
			$decoded = '';
			$len     = strlen( $raw );
			for ( $i = 0; $i + 1 < $len; $i += 2 ) {
				$decoded .= $raw[ $i + 1 ];
			}
		}

		return trim( $decoded );
	}

	private static function name_priority( int $platform, int $encoding, int $language ): int {
		if ( $platform === 3 && $encoding === 1 && $language === 0x0409 ) {
			return 0;
		}
		if ( $platform === 3 && $encoding === 1 ) {
			return 5;
		}
		if ( $platform === 0 ) {
			return 10;
		}
		if ( $platform === 1 && $language === 0 ) {
			return 20;
		}
		if ( $platform === 1 ) {
			return 30;
		}

		return 50;
	}

	private static function read_u16( string $bin, int $offset ): int {
		if ( strlen( $bin ) < $offset + 2 ) {
			return 0;
		}
		$bytes = unpack( 'n', substr( $bin, $offset, 2 ) );

		return is_array( $bytes ) ? (int) $bytes[1] : 0;
	}

	private static function read_u32( string $bin, int $offset ): int {
		if ( strlen( $bin ) < $offset + 4 ) {
			return 0;
		}
		$bytes = unpack( 'N', substr( $bin, $offset, 4 ) );

		return is_array( $bytes ) ? (int) $bytes[1] : 0;
	}

	/**
	 * Read a WOFF2 `255UInt16` variable-length integer at `$pos`, advancing it.
	 */
	private static function read_base128( string $bin, int &$pos ): ?int {
		$len = strlen( $bin );
		if ( $pos >= $len ) {
			return null;
		}
		$accumulator = 0;
		for ( $i = 0; $i < 5; $i++ ) {
			if ( $pos >= $len ) {
				return null;
			}
			$byte = ord( $bin[ $pos ] );
			$pos++;
			if ( $i === 0 && $byte === 0x80 ) {
				return null;
			}
			if ( ( $accumulator & 0xFE000000 ) !== 0 ) {
				return null;
			}
			$accumulator = ( $accumulator << 7 ) | ( $byte & 0x7F );
			if ( ( $byte & 0x80 ) === 0 ) {
				return $accumulator;
			}
		}

		return null;
	}
}
