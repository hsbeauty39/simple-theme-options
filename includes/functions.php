<?php
/**
 * Template / theme helpers (global functions).
 *
 * @package SimpleThemeOptions
 */

defined( 'ABSPATH' ) || exit;

use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\ViewportOptions;

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
