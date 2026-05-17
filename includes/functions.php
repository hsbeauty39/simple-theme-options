<?php
/**
 * Template / theme helpers (global functions).
 *
 * @package SimpleThemeOptions
 */

defined( 'ABSPATH' ) || exit;

use SimpleThemeOptions\Admin\Options\Fields\Common\PremiumFieldGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\AlignmentControl\AlignmentControl;
use SimpleThemeOptions\Admin\Options\Fields\Dimension\Dimension;
use SimpleThemeOptions\Admin\Options\Fields\IconSelect\IconSelect;
use SimpleThemeOptions\Admin\Options\Fields\GalleryControl\GalleryControl;
use SimpleThemeOptions\Admin\Options\Fields\GoogleMapControl\GoogleMapControl;
use SimpleThemeOptions\Admin\Options\Fields\MultiTextControl\MultiTextControl;
use SimpleThemeOptions\Admin\Options\Fields\RadioListsControl\RadioListsControl;
use SimpleThemeOptions\Admin\Options\Fields\AdvancedRepeaterControl\AdvancedRepeaterControl;
use SimpleThemeOptions\Data\CustomFontsRegistry;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl;
use SimpleThemeOptions\ViewportOptions;

use SimpleThemeOptions\Admin\ThemeSettingsMetabox;
use SimpleThemeOptions\Admin\ThemeSettingsTermBox;

/**
 * Full saved option map (`sto_options`).
 *
 * @return array<string, mixed>
 */
function sto_get_options() {
	$raw = get_option( 'sto_options', array() );

	return is_array( $raw ) ? $raw : array();
}

/**
 * Whether this site may use premium STO field types (Freemius Pro / trial).
 */
function sto_can_use_premium_fields(): bool {
	return PremiumFieldGate::can_use_premium();
}

/**
 * One Theme Settings field by id (saved under `sto_options[ $field_id ]`).
 *
 * Same idea as Redux `get_option( 'opt_name', 'field_key' )` — STO uses one global map, so you only pass the field key.
 *
 * @param string $field_id Registered field `id` (e.g. from `Color::register`, `Input::register`, inner group `id`).
 * @param mixed  $default  Returned when the key is absent.
 * @return mixed Stored scalar, breakpoint map (`array`), JSON string for composite fields — whatever was saved.
 */
function sto_get_option( $field_id, $default = null ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return $default;
	}
	$opts = sto_get_options();

	return array_key_exists( $field_id, $opts ) ? $opts[ $field_id ] : $default;
}

/**
 * True when a **switcher / checkbox-like** STO value reads as enabled (`1`, `true`, `on`, `yes`).
 *
 * @param string $field_id Registered field id.
 * @param bool   $default  When the key does not exist in `sto_options`.
 */
function sto_option_is_on( $field_id, $default = false ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return (bool) $default;
	}
	$opts = sto_get_options();
	if ( ! array_key_exists( $field_id, $opts ) ) {
		return (bool) $default;
	}

	return in_array( (string) $opts[ $field_id ], array( '1', 'true', 'on', 'yes' ), true );
}

/**
 * Format a saved **color** field value for inline `background-color` (comma `rgb()` / `rgba()`, safe for `esc_attr()`).
 *
 * @param mixed  $stored       Value from `{@see sto_get_option()}`, or null / non-string falls back.
 * @param string $fallback_hex Hex fallback when `$stored` is empty or unrecognized (must be `#rgb` or `#rrggbb`).
 */
function sto_color_string_to_background_css( $stored, $fallback_hex = '#ffffff' ) {
	$fallback_hex = is_string( $fallback_hex ) ? trim( $fallback_hex ) : '#ffffff';

	$parse_css_alpha = static function ( $raw ) {
		if ( ! is_string( $raw ) ) {
			return 1.0;
		}
		$raw = strtolower( trim( $raw ) );
		if ( $raw === '' ) {
			return 1.0;
		}

		return substr( $raw, -1 ) === '%'
			? max( 0.0, min( 1.0, (float) trim( substr( $raw, 0, -1 ) ) / 100.0 ) )
			: max( 0.0, min( 1.0, (float) $raw ) );
	};

	$emit_rgb_or_rgba = static function ( $r, $g, $b, $a ) use ( $parse_css_alpha ) {
		if ( null === $a ) {
			$a_u = 1.0;
		} elseif ( is_string( $a ) ) {
			$a_u = $parse_css_alpha( $a );
		} else {
			$a_u = max( 0.0, min( 1.0, (float) $a ) );
		}

		if ( $a_u >= 0.999 ) {
			return sprintf( 'rgb(%d,%d,%d)', $r, $g, $b );
		}

		$as = (string) round( $a_u, 3 );
		$as = rtrim( rtrim( $as, '0' ), '.' );

		return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, $as === '' ? '0' : $as );
	};

	$hex_fallback_to_rgb = static function ( $hx ) {
		$hx = trim( (string) $hx );
		if ( $hx === '' || ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hx ) ) {
			return '';
		}
		$d = substr( $hx, 1 );
		if ( strlen( $d ) === 3 ) {
			$r = hexdec( str_repeat( $d[0], 2 ) );
			$g = hexdec( str_repeat( $d[1], 2 ) );
			$b = hexdec( str_repeat( $d[2], 2 ) );
		} else {
			$r = hexdec( substr( $d, 0, 2 ) );
			$g = hexdec( substr( $d, 2, 2 ) );
			$b = hexdec( substr( $d, 4, 2 ) );
		}

		return sprintf( 'rgb(%d,%d,%d)', $r, $g, $b );
	};

	$rescue = static function () use ( $fallback_hex, $hex_fallback_to_rgb ) {
		$o = $hex_fallback_to_rgb( $fallback_hex );

		return $o !== '' ? $o : 'rgb(255,255,255)';
	};

	if ( ! is_string( $stored ) ) {
		return $rescue();
	}
	$s = trim( $stored );
	if ( $s === '' ) {
		return $rescue();
	}
	// #RGBA shorthand (#f90e → opaque yellow + hex alpha digit).
	if ( preg_match( '/^#([0-9a-f]{4})$/i', $s, $qm ) ) {
		$h     = strtolower( $qm[1] );
		$digits = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2] . $h[3] . $h[3];
		$s     = '#' . $digits;
	}

	// #RRGGBBAA saved by wp-color-picker-alpha (themes must output comma rgba — `esc_attr`).
	if ( preg_match( '/^#([0-9a-f]{8})$/i', $s, $hm ) ) {
		$d   = strtolower( $hm[1] );
		$r   = max( 0, min( 255, hexdec( substr( $d, 0, 2 ) ) ) );
		$g   = max( 0, min( 255, hexdec( substr( $d, 2, 2 ) ) ) );
		$b   = max( 0, min( 255, hexdec( substr( $d, 4, 2 ) ) ) );
		$a_u = max( 0.0, min( 1.0, hexdec( substr( $d, 6, 2 ) ) / 255.0 ) );

		return $emit_rgb_or_rgba( $r, $g, $b, $a_u );
	}
	if ( preg_match( '/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/i', $s, $m ) ) {
		return sprintf(
			'rgb(%d,%d,%d)',
			max( 0, min( 255, (int) $m[1] ) ),
			max( 0, min( 255, (int) $m[2] ) ),
			max( 0, min( 255, (int) $m[3] ) )
		);
	}

	// Comma separates channels; fourth may be fractional or `%`.
	if ( preg_match( '/^rgba?\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*(?:,\s*((?:[0-9]*\.?[0-9]+)%|[0-9]*\.?[0-9]+)\s*)?\)$/i', $s, $m ) ) {
		$r = max( 0, min( 255, (int) $m[1] ) );
		$g = max( 0, min( 255, (int) $m[2] ) );
		$b = max( 0, min( 255, (int) $m[3] ) );

		return $emit_rgb_or_rgba( $r, $g, $b, isset( $m[4] ) ? $m[4] : 1.0 );
	}

	// rgb(255 255 6 / 0.75) — alpha **or** `50 %`.
	if ( preg_match( '/^rgba?\(\s*([0-9]{1,3})\s+([0-9]{1,3})\s+([0-9]{1,3})\s*(?:\/\s*((?:[0-9]*\.?[0-9]+)%|[0-9]*\.?[0-9]+))?\s*\)$/i', $s, $m ) ) {
		$r = max( 0, min( 255, (int) $m[1] ) );
		$g = max( 0, min( 255, (int) $m[2] ) );
		$b = max( 0, min( 255, (int) $m[3] ) );

		return $emit_rgb_or_rgba( $r, $g, $b, isset( $m[4] ) ? $m[4] : 1.0 );
	}

	if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $s ) ) {
		$out = $hex_fallback_to_rgb( $s );

		return $out !== '' ? $out : $rescue();
	}

	return $rescue();
}

/**
 * Read one **color** field and return a string safe for inline `background-color:`.
 *
 * @param string $field_id     Registered Color field option id.
 * @param string $fallback_hex `#rrggbb` when missing or unreadable stored value.
 */
function sto_color_field_background_css( $field_id, $fallback_hex = '#ffffff' ) {
	$field_id = sanitize_key( (string) $field_id );

	return sto_color_string_to_background_css(
		$field_id !== '' ? sto_get_option( $field_id ) : '',
		$fallback_hex
	);
}

/**
 * Option keys saved from the Theme Settings post editor metabox for one post (merged over global `sto_options` when reading with {@see sto_get_effective_options_for_post()}).
 *
 * @param int|null $post_id Post ID or null for current post in the loop.
 * @return array<string, mixed>
 */
function sto_get_post_theme_setting_overrides( $post_id = null ) {
	$post_id = null !== $post_id ? (int) $post_id : (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return array();
	}
	$m = get_post_meta( $post_id, ThemeSettingsMetabox::POST_SETTINGS_META_KEY, true );

	return is_array( $m ) ? $m : array();
}

/**
 * Global Theme Settings map merged with this post’s metabox overrides (post wins on overlapping keys).
 *
 * @param int|null $post_id Post ID or null for current post in the loop.
 * @return array<string, mixed>
 */
function sto_get_effective_options_for_post( $post_id = null ) {
	$base = sto_get_options();
	$post_id = null !== $post_id ? (int) $post_id : (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return $base;
	}

	return array_merge( $base, sto_get_post_theme_setting_overrides( $post_id ) );
}

/**
 * Option keys saved from Theme Settings on a taxonomy term (merged over global `sto_options`).
 *
 * @param int|null $term_id Term ID or null for the queried term.
 * @return array<string, mixed>
 */
function sto_get_term_theme_setting_overrides( $term_id = null ) {
	$term_id = null !== $term_id ? (int) $term_id : (int) get_queried_object_id();
	if ( $term_id <= 0 ) {
		return array();
	}
	$m = get_term_meta( $term_id, ThemeSettingsTermBox::TERM_SETTINGS_META_KEY, true );

	return is_array( $m ) ? $m : array();
}

/**
 * Global Theme Settings merged with a term’s overrides (term wins on overlapping keys).
 *
 * @param int|null $term_id Term ID or null for the queried term.
 * @return array<string, mixed>
 */
function sto_get_effective_options_for_term( $term_id = null ) {
	$base    = sto_get_options();
	$term_id = null !== $term_id ? (int) $term_id : (int) get_queried_object_id();
	if ( $term_id <= 0 ) {
		return $base;
	}

	return array_merge( $base, sto_get_term_theme_setting_overrides( $term_id ) );
}

/**
 * Breakpoint key for a viewport width using built-in tier floors (Elementor-style).
 *
 * @param int                  $width_px   Viewport inner width in CSS pixels.
 * @param array<string, mixed>|null $sto_options Optional; passed for API compatibility (ignored for thresholds).
 * @return string One of: mobile, xs, sm, md, lg, xl, xxl.
 */
function sto_get_viewport_breakpoint( $width_px, $sto_options = null ) {
	$opts = null !== $sto_options && is_array( $sto_options ) ? $sto_options : sto_get_options();

	return ViewportOptions::resolve_breakpoint( (int) $width_px, $opts );
}

/**
 * Effective value for one option id at a given viewport width.
 *
 * Pass only the option id; the correct breakpoint slice is chosen from the stored map using
 * built-in viewport tiers. Non-responsive values are returned as stored.
 *
 * @param string   $id             Option key in `sto_options`.
 * @param int|null $viewport_width CSS pixels, or null to use the admin “eval” breakpoint (`xxl` / PC for maps).
 * @param mixed    $default        When the id is missing or the map has no usable entry.
 * @return mixed
 */
function sto_get_responsive_option( $id, $viewport_width = null, $default = null ) {
	$opts = sto_get_options();
	$id   = sanitize_key( (string) $id );
	if ( $id === '' ) {
		return $default;
	}
	if ( ! array_key_exists( $id, $opts ) ) {
		return $default;
	}
	$stored = $opts[ $id ];

	if ( null === $viewport_width ) {
		$viewport_width = (int) apply_filters( 'sto_default_viewport_width_for_read', 0 );
	}
	if ( $viewport_width < 1 ) {
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$bp = ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT;
			if ( array_key_exists( $bp, $stored ) ) {
				return $stored[ $bp ];
			}
			$rv = ResponsiveConfig::raw_value_at_breakpoint( $stored, $bp );
			if ( null !== $rv ) {
				return $rv;
			}
			$first = reset( $stored );

			return false !== $first ? $first : $default;
		}

		return null !== $stored ? $stored : $default;
	}

	return ViewportOptions::get_value_for_width( $stored, (int) $viewport_width, $opts, $default );
}

/**
 * Resolve a responsive option into a full breakpoint map (largest → smallest).
 *
 * Explicit tab values override; smaller breakpoints inherit the last larger value until
 * the next override (matches STO admin tabs). Scalar storage repeats on every breakpoint.
 *
 * @param string                    $field_id                 Option key in `sto_options`.
 * @param array<string, scalar>     $defaults_per_breakpoint  Fallback per slug, e.g. `array( 'xxl' => '4', 'mobile' => '1' )`.
 * @param array<int, string>|null   $breakpoint_order         Defaults to {@see ResponsiveConfig::ALL_BREAKPOINTS}.
 * @return array<string, string>
 */
function sto_resolve_responsive_breakpoint_map( $field_id, array $defaults_per_breakpoint = array(), $breakpoint_order = null ) {
	$field_id = sanitize_key( (string) $field_id );
	$bps      = is_array( $breakpoint_order ) && array() !== $breakpoint_order
		? array_values( array_map( 'sanitize_key', $breakpoint_order ) )
		: ResponsiveConfig::ALL_BREAKPOINTS;

	$stored = ( $field_id !== '' && function_exists( 'sto_get_option' ) )
		? sto_get_option( $field_id, null )
		: null;

	$out   = array();
	$carry = null;

	if ( ! is_array( $stored ) || ! ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
		$scalar = is_scalar( $stored ) ? trim( (string) $stored ) : '';
		if ( '' === $scalar && isset( $defaults_per_breakpoint['xxl'] ) && is_scalar( $defaults_per_breakpoint['xxl'] ) ) {
			$scalar = trim( (string) $defaults_per_breakpoint['xxl'] );
		}

		foreach ( $bps as $bp ) {
			if ( array_key_exists( $bp, $defaults_per_breakpoint ) && is_scalar( $defaults_per_breakpoint[ $bp ] ) ) {
				$out[ $bp ] = trim( (string) $defaults_per_breakpoint[ $bp ] );
				continue;
			}
			$out[ $bp ] = '' !== $scalar ? $scalar : '4';
		}

		return $out;
	}

	foreach ( $bps as $bp ) {
		$raw = ResponsiveConfig::raw_value_at_breakpoint( $stored, $bp );
		if ( null !== $raw && is_scalar( $raw ) && '' !== trim( (string) $raw ) ) {
			$carry = trim( (string) $raw );
		} elseif ( null === $carry ) {
			$carry = ( array_key_exists( $bp, $defaults_per_breakpoint ) && is_scalar( $defaults_per_breakpoint[ $bp ] ) )
				? trim( (string) $defaults_per_breakpoint[ $bp ] )
				: '';
		}
		$out[ $bp ] = $carry;
	}

	return $out;
}

/**
 * Compiled `box-shadow` value for a registered **Shadow** field (value only, no property name).
 *
 * @param string $field_id Option key registered with {@see \SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl::register()}.
 * @return string e.g. `0 2px 10px 0 rgba(0,0,0,0.12)` or `inset …`.
 */
function sto_get_shadow_box_shadow( $field_id ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return 'none';
	}

	return ShadowControl::instance()->value_to_css_box_shadow( $field_id );
}

/**
 * Full CSS rule for a **Shadow** field: `selector { box-shadow: … }`, or empty string when selector is blank.
 *
 * @param string $field_id Option key.
 * @return string Safe for `wp_add_inline_style` when the admin-entered selector passed server sanitization.
 */
function sto_get_shadow_css_rule( $field_id ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return '';
	}

	return ShadowControl::instance()->get_css_rule_string( $field_id );
}

/**
 * Compiled `background-image` gradient value for a registered **Gradient** field (value only, no property name).
 *
 * @param string $field_id Option key registered with {@see \SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl::register()}.
 * @return string e.g. `linear-gradient(180deg, #2271b1 0%, #fff 100%)` or `none`.
 */
function sto_get_gradient_background_image( $field_id ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return 'none';
	}

	return GradientControl::instance()->value_to_css_background_image( $field_id );
}

/**
 * CSS spacing shorthand from a **Dimension** field (`margin` / `padding` style: four lengths).
 *
 * @param string      $field_id       Option key in `sto_options`.
 * @param string|null $json_or_scalar When non-null, use this JSON string instead of reading from options (e.g. preview).
 * @return string e.g. `10px 0 12px 0` or empty when unset / invalid.
 */
function sto_get_dimension_shorthand( $field_id, $json_or_scalar = null ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return '';
	}
	if ( null !== $json_or_scalar && is_string( $json_or_scalar ) ) {
		return Dimension::value_to_css_shorthand( $json_or_scalar );
	}
	$opts = sto_get_options();
	if ( ! array_key_exists( $field_id, $opts ) ) {
		return '';
	}
	$stored = $opts[ $field_id ];
	if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
		$slice = ResponsiveConfig::value_for_required_eval( $stored );

		return is_scalar( $slice ) ? Dimension::value_to_css_shorthand( (string) $slice ) : '';
	}

	return is_scalar( $stored ) ? Dimension::value_to_css_shorthand( (string) $stored ) : '';
}

/**
 * Sanitized Font Awesome class string for a registered **Icon select** field.
 *
 * @param string      $field_id       Option key in `sto_options`.
 * @param string|null $scalar_or_null When non-null, coerce this string instead of reading options.
 * @return string e.g. `fa-light fa-house`.
 */
function sto_get_icon_select_field( $field_id, $scalar_or_null = null ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return '';
	}

	return IconSelect::instance()->get_icon_class_for_field( $field_id, $scalar_or_null );
}

/**
 * CSS fragment from a registered **Alignment** field’s **`css_map`** for the current (or overridden) stored key.
 *
 * @param string      $field_id       Option key in `sto_options`.
 * @param string|null $json_or_scalar When non-null, use this scalar key instead of reading `sto_options` (preview / import).
 * @return string e.g. `center` for `justify-content` — whatever you registered per option key.
 */
function sto_get_alignment_css_fragment( $field_id, $json_or_scalar = null ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return '';
	}

	return AlignmentControl::instance()->get_css_fragment_for_value( $field_id, $json_or_scalar );
}

/**
 * Ordered list of **image attachment IDs** from a registered **Gallery** field (`sto_options[id]` JSON or per-breakpoint map).
 *
 * @param string      $field_id       Option key in `sto_options`.
 * @param string|null $json_or_scalar When non-null, parse this JSON string instead of reading `sto_options` (preview / import).
 * @return array<int, int> Attachment IDs (empty when unset or invalid).
 */
function sto_get_gallery_attachment_ids( $field_id, $json_or_scalar = null ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return array();
	}

	return GalleryControl::instance()->get_attachment_ids_for_field( $field_id, $json_or_scalar );
}

/**
 * Lines from a registered **`multi_text`** field (`sto_options[id]` JSON array of strings).
 *
 * @param string      $field_id       Option key in `sto_options`.
 * @param string|null $json_or_scalar When non-null, parse this JSON string instead of reading `sto_options`.
 * @return array<int, string> Ordered lines (empty strings omitted).
 */
function sto_get_multi_text_lines( $field_id, $json_or_scalar = null ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return array();
	}

	return MultiTextControl::instance()->get_lines_for_field( $field_id, $json_or_scalar );
}

/**
 * Rows from a registered **`radio_lists`** field (`sto_options[id]` JSON array of `{ title, value }`).
 *
 * @param string      $field_id       Option key in `sto_options`.
 * @param string|null $json_or_scalar When non-null, parse this JSON string instead of reading `sto_options`.
 * @return array<int, array{title: string, value: string}> Ordered rows (`value` is a registered option key).
 */
function sto_get_radio_lists_rows( $field_id, $json_or_scalar = null ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return array();
	}

	return RadioListsControl::instance()->get_rows_for_field( $field_id, $json_or_scalar );
}

/**
 * Items from a registered **`advanced_repeater`** field (`sto_options[id]` JSON array of associative row objects; nested repeaters are arrays of objects under their key).
 *
 * @param string      $field_id       Option key in `sto_options`.
 * @param string|null $json_or_scalar When non-null, parse this JSON string instead of reading `sto_options`.
 * @return array<int, array<string, mixed>> Ordered items (sanitized to the field schema).
 */
function sto_get_advanced_repeater_items( $field_id, $json_or_scalar = null ) {
	$field_id = sanitize_key( (string) $field_id );
	if ( $field_id === '' ) {
		return array();
	}

	return AdvancedRepeaterControl::instance()->get_items_for_field( $field_id, $json_or_scalar );
}

/**
 * All uploaded custom font faces (`sto_custom_fonts` option).
 *
 * @return array<int, array<string, mixed>>
 */
function sto_get_custom_fonts() {
	return CustomFontsRegistry::get_faces();
}

/**
 * `@font-face` CSS for all custom fonts (front-end and admin typography preview).
 */
function sto_get_custom_fonts_css(): string {
	return CustomFontsRegistry::build_font_face_css_all();
}

/**
 * Location payload from a registered **`google_map`** field (`sto_options[id]` JSON or per-breakpoint map). Map data comes from OpenStreetMap / Nominatim in admin; the stored shape is the same for themes.
 *
 * @param string      $field_id       Option key in `sto_options`.
 * @param string|null $json_or_scalar When non-null, parse this JSON string instead of reading `sto_options` (preview / import).
 * @return array<string, string> Keys: formatted_address, address, street, city, state, zip, country, lat, lng.
 */
function sto_get_google_map_field( $field_id, $json_or_scalar = null ) {
	$field_id = sanitize_key( (string) $field_id );

	return GoogleMapControl::instance()->get_map_payload_for_field( $field_id, $json_or_scalar );
}
