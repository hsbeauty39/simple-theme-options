<?php
namespace SimpleThemeOptions\Admin\Options;

use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors {@see assets/admin/js/main.js} `normalizeRequiredGroups` + `doesGroupMatch` so PHP can
 * tell whether a field row would be visible (and thus subject to server-side required checks).
 */
final class RequiredVisibility {

	/**
	 * @param array<string, mixed> $option_values Same shape as `sto_options` (merged preview).
	 */
	public static function row_is_visible( $required, array $option_values ) {
		if ( ! is_array( $required ) || $required === array() ) {
			return true;
		}

		$groups = self::normalize_groups( $required );
		if ( $groups === array() ) {
			return true;
		}

		foreach ( $groups as $group ) {
			if ( is_array( $group ) && self::group_matches( $group, $option_values ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int|string, mixed> $required
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_groups( array $required ) {
		$keys = array_keys( $required );
		$n    = count( $required );
		$is_list = $n > 0 && $keys === range( 0, $n - 1 );

		if ( ! $is_list ) {
			return array( $required );
		}

		$out = array();
		foreach ( $required as $item ) {
			if ( is_array( $item ) && $item !== array() ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $group AND of fieldKey => expected (string compare).
	 * @param array<string, mixed> $option_values
	 */
	private static function group_matches( array $group, array $option_values ) {
		$checked = 0;
		foreach ( $group as $field_key => $expected ) {
			$fk = sanitize_key( (string) $field_key );
			if ( $fk === '' ) {
				continue;
			}
			++$checked;
			$current = self::value_as_string( array_key_exists( $fk, $option_values ) ? $option_values[ $fk ] : null );
			if ( (string) $current !== (string) $expected ) {
				return false;
			}
		}

		return $checked > 0;
	}

	/**
	 * @param mixed $value
	 */
	private static function value_as_string( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_array( $value ) && ResponsiveConfig::is_breakpoint_value_map( $value ) ) {
			return ResponsiveConfig::value_for_required_eval( $value );
		}
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $v ) {
				if ( is_scalar( $v ) && (string) $v !== '' ) {
					$parts[] = (string) $v;
				}
			}

			return implode( ',', $parts );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_object( $value ) ) {
			return '';
		}

		return (string) $value;
	}
}
