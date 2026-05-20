<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Optional registration key **`space`** — extra margin above a field row or group panel (admin UI only).
 *
 * Examples: **`'space' => '3px'`**, **`'space' => '1.5rem'`**, **`'space' => 16`** (treated as **px**).
 * Not stored in **`sto_options`**; not a field type.
 */
final class FieldSpacing {

	/**
	 * Parse **`space`** into a safe CSS length for **`margin-top`** (empty when invalid).
	 *
	 * @param mixed $raw
	 */
	public static function parse_margin_top( $raw ): string {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			$number = (float) $raw;
			if ( $number < 0 || $number > 500 ) {
				return '';
			}

			return self::format_number( $number ) . 'px';
		}

		if ( ! is_string( $raw ) ) {
			return '';
		}

		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}

		if ( is_numeric( $raw ) ) {
			$number = (float) $raw;
			if ( $number < 0 || $number > 500 ) {
				return '';
			}

			return self::format_number( $number ) . 'px';
		}

		if ( ! preg_match( '/^(\d+(?:\.\d+)?)(px|%|rem|em|vw|vh|ch)?$/i', $raw, $matches ) ) {
			return '';
		}

		$number = (float) $matches[1];
		if ( $number < 0 || $number > 500 ) {
			return '';
		}

		$unit = isset( $matches[2] ) && $matches[2] !== '' ? strtolower( $matches[2] ) : 'px';
		$allowed_units = array( 'px', '%', 'rem', 'em', 'vw', 'vh', 'ch' );
		if ( ! in_array( $unit, $allowed_units, true ) ) {
			return '';
		}

		return self::format_number( $number ) . $unit;
	}

	/**
	 * Normalize **`space`** on a field / group registration array → **`space_css`** (when set).
	 *
	 * @param array<string, mixed> $config
	 */
	public static function normalize_config( array &$config ): void {
		if ( ! array_key_exists( 'space', $config ) ) {
			return;
		}

		$parsed = self::parse_margin_top( $config['space'] );
		if ( $parsed !== '' ) {
			$config['space_css'] = $parsed;
		}
	}

	/**
	 * Inline **`style`** attribute for a field row (**`margin-top`**).
	 *
	 * @param array<string, mixed>    $field
	 * @param 'default'|'group_inner' $context
	 */
	public static function row_margin_style_attr( $field, string $context = 'default' ): string {
		unset( $context );

		if ( ! is_array( $field ) ) {
			return '';
		}

		$css = isset( $field['space_css'] ) ? trim( (string) $field['space_css'] ) : '';
		if ( $css === '' ) {
			return '';
		}

		return ' style="margin-top:' . esc_attr( $css ) . '"';
	}

	/**
	 * Inline **`style`** for a group panel or grid cell.
	 *
	 * @param array<string, mixed> $config
	 */
	public static function block_margin_style_attr( array $config ): string {
		$css = isset( $config['space_css'] ) ? trim( (string) $config['space_css'] ) : '';
		if ( $css === '' ) {
			return '';
		}

		return ' style="margin-top:' . esc_attr( $css ) . '"';
	}

	/**
	 * @param float $number
	 */
	private static function format_number( float $number ): string {
		if ( abs( $number - round( $number ) ) < 0.00001 ) {
			return (string) (int) round( $number );
		}

		$formatted = (string) round( $number, 4 );
		$formatted = rtrim( rtrim( $formatted, '0' ), '.' );

		return $formatted === '' ? '0' : $formatted;
	}
}
