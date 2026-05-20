<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical stored JSON keys for Range and Dimension fields (read legacy compact keys on input).
 */
final class UnitFieldStoredJson {

	/**
	 * @param array<string, mixed> $decoded
	 * @param array<int, string>   $allowed
	 * @return array{value:string,unit:string,custom_suffix:string}
	 */
	public static function parse_range_decoded( array $decoded, array $allowed ) {
		$value = '';
		if ( array_key_exists( 'value', $decoded ) ) {
			$value = trim( (string) $decoded['value'] );
		} elseif ( array_key_exists( 'v', $decoded ) ) {
			$value = trim( (string) $decoded['v'] );
		}

		$unit = (string) ( $allowed[0] ?? 'px' );
		if ( array_key_exists( 'unit', $decoded ) ) {
			$unit = sanitize_key( (string) $decoded['unit'] );
		} elseif ( array_key_exists( 'u', $decoded ) ) {
			$unit = sanitize_key( (string) $decoded['u'] );
		}
		if ( ! in_array( $unit, $allowed, true ) ) {
			$unit = (string) ( $allowed[0] ?? 'px' );
		}

		$custom_suffix = '';
		if ( array_key_exists( 'custom_suffix', $decoded ) ) {
			$custom_suffix = (string) $decoded['custom_suffix'];
		} elseif ( array_key_exists( 'c', $decoded ) ) {
			$custom_suffix = (string) $decoded['c'];
		}
		if ( $unit !== 'custom' ) {
			$custom_suffix = '';
		}

		return array(
			'value'         => $value,
			'unit'          => $unit,
			'custom_suffix' => $custom_suffix,
		);
	}

	/**
	 * @param array{value:string,unit:string,custom_suffix:string} $tuple
	 * @return array{value:string,unit:string,custom_suffix:string}
	 */
	public static function encode_range_tuple( array $tuple ) {
		$unit = isset( $tuple['unit'] ) ? sanitize_key( (string) $tuple['unit'] ) : 'px';

		return array(
			'value'         => isset( $tuple['value'] ) ? trim( (string) $tuple['value'] ) : '',
			'unit'          => $unit,
			'custom_suffix' => $unit === 'custom' && isset( $tuple['custom_suffix'] ) ? (string) $tuple['custom_suffix'] : '',
		);
	}

	/**
	 * @param array<string, mixed> $decoded
	 * @param array<int, string>   $allowed
	 * @return array{unit:string,custom_suffix:string}
	 */
	public static function parse_dimension_unit_keys( array $decoded, array $allowed ) {
		$unit = (string) ( $allowed[0] ?? 'px' );
		if ( array_key_exists( 'unit', $decoded ) ) {
			$unit = sanitize_key( (string) $decoded['unit'] );
		} elseif ( array_key_exists( 'u', $decoded ) ) {
			$unit = sanitize_key( (string) $decoded['u'] );
		}
		if ( ! in_array( $unit, $allowed, true ) ) {
			$unit = (string) ( $allowed[0] ?? 'px' );
		}

		$custom_suffix = '';
		if ( array_key_exists( 'custom_suffix', $decoded ) ) {
			$custom_suffix = (string) $decoded['custom_suffix'];
		} elseif ( array_key_exists( 'c', $decoded ) ) {
			$custom_suffix = (string) $decoded['c'];
		}
		if ( $unit !== 'custom' ) {
			$custom_suffix = '';
		}

		return array(
			'unit'          => $unit,
			'custom_suffix' => $custom_suffix,
		);
	}

	/**
	 * @param array{unit:string,custom_suffix:string,linked:bool,values:array<string,string>} $state
	 * @return array{unit:string,custom_suffix:string,linked:bool,values:array<string,string>}
	 */
	public static function encode_dimension_state( array $state ) {
		$unit = isset( $state['unit'] ) ? sanitize_key( (string) $state['unit'] ) : 'px';

		return array(
			'unit'          => $unit,
			'custom_suffix' => $unit === 'custom' && isset( $state['custom_suffix'] ) ? (string) $state['custom_suffix'] : '',
			'linked'        => ! empty( $state['linked'] ),
			'values'        => isset( $state['values'] ) && is_array( $state['values'] ) ? $state['values'] : array(),
		);
	}

	/**
	 * Merge unit / custom_suffix from a partial default array (registration `default` key).
	 *
	 * @param array<string, mixed> $partial
	 * @param array{unit:string,custom_suffix:string,linked:bool,values:array<string,string>} $base
	 * @param array<int, string>   $allowed
	 * @return array{unit:string,custom_suffix:string,linked:bool,values:array<string,string>}
	 */
	public static function merge_dimension_default_partial( array $partial, array $base, array $allowed ) {
		$keys = self::parse_dimension_unit_keys( $partial, $allowed );
		if ( in_array( $keys['unit'], $allowed, true ) ) {
			$base['unit'] = $keys['unit'];
		}
		if ( array_key_exists( 'custom_suffix', $partial ) || array_key_exists( 'c', $partial ) ) {
			$base['custom_suffix'] = $keys['custom_suffix'];
		}
		if ( array_key_exists( 'linked', $partial ) || array_key_exists( 'link', $partial ) ) {
			$base['linked'] = ! empty( $partial['linked'] ) || ! empty( $partial['link'] );
		}
		if ( isset( $partial['values'] ) && is_array( $partial['values'] ) ) {
			foreach ( $base['values'] as $side_key => $_ ) {
				if ( isset( $partial['values'][ $side_key ] ) ) {
					$base['values'][ $side_key ] = trim( (string) $partial['values'][ $side_key ] );
				}
			}
		}
		// Legacy shammi-style top/right/bottom/left single-letter keys.
		$legacy_map = array(
			't' => 'top',
			'r' => 'right',
			'b' => 'bottom',
			'l' => 'left',
		);
		foreach ( $legacy_map as $legacy_key => $side_key ) {
			if ( isset( $partial[ $legacy_key ] ) && isset( $base['values'][ $side_key ] ) ) {
				$base['values'][ $side_key ] = trim( (string) $partial[ $legacy_key ] );
			}
		}

		return $base;
	}

	/**
	 * @param array<string, mixed> $partial
	 * @param array{value:string,unit:string,custom_suffix:string} $base
	 * @param array<int, string>   $allowed
	 * @return array{value:string,unit:string,custom_suffix:string}
	 */
	public static function merge_range_default_partial( array $partial, array $base, array $allowed ) {
		if ( array_key_exists( 'value', $partial ) ) {
			$base['value'] = trim( (string) $partial['value'] );
		} elseif ( array_key_exists( 'v', $partial ) ) {
			$base['value'] = trim( (string) $partial['v'] );
		}
		$keys = self::parse_range_decoded( $partial, $allowed );
		if ( in_array( $keys['unit'], $allowed, true ) ) {
			$base['unit'] = $keys['unit'];
		}
		if ( array_key_exists( 'custom_suffix', $partial ) || array_key_exists( 'c', $partial ) ) {
			$base['custom_suffix'] = $keys['custom_suffix'];
		}

		return $base;
	}
}
