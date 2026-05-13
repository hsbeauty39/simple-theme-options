<?php
namespace SimpleThemeOptions;

use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Maps viewport width (px) to a responsive option key and reads `sto_options[id]` by that key only.
 *
 * Minimum widths per tier are **built in** (Elementor-style floors), not editable in Theme Settings.
 * See {@see built_in_minimum_widths()}.
 */
final class ViewportOptions {

	public const OPTION_PRESET = 'sto_adv_vp_preset';

	public const EDGE_KEYS = array(
		'mobile' => 'sto_adv_vp_min_mobile',
		'xs'     => 'sto_adv_vp_min_xs',
		'sm'     => 'sto_adv_vp_min_sm',
		'md'     => 'sto_adv_vp_min_md',
		'lg'     => 'sto_adv_vp_min_lg',
		'xl'     => 'sto_adv_vp_min_xl',
		'xxl'    => 'sto_adv_vp_min_xxl',
	);

	/**
	 * Built-in minimum viewport widths (px) per breakpoint key — largest-first cascade in
	 * {@see resolve_breakpoint()}. Aligns with common Elementor-style bands (e.g. desktop from 1024).
	 *
	 * @return array<string, int>
	 */
	public static function built_in_minimum_widths() {
		return array(
			'mobile' => 320,
			'xs'     => 0,
			'sm'     => 576,
			'md'     => 768,
			'lg'     => 1024,
			'xl'     => 1200,
			'xxl'    => 1440,
		);
	}

	/**
	 * @return array<string, int> breakpoint => min width (px); disabled tiers use min <= 0.
	 */
	public static function default_minimums() {
		return self::built_in_minimum_widths();
	}

	/**
	 * @param array<string, mixed> $sto_options Deprecated; ignored — thresholds are always built-in.
	 * @return array<string, int> breakpoint => min width (enabled only; min > 0 or mobile)
	 */
	public static function get_minimums( array $sto_options ) {
		unset( $sto_options );
		$defs = self::built_in_minimum_widths();
		$out  = array();
		foreach ( array_keys( self::EDGE_KEYS ) as $bp ) {
			$n = (int) ( $defs[ $bp ] ?? 0 );
			if ( $bp === 'mobile' ) {
				$out[ $bp ] = max( 0, min( 4096, $n ) );
				continue;
			}
			if ( $n <= 0 ) {
				continue;
			}
			$out[ $bp ] = max( 1, min( 8192, $n ) );
		}

		return $out;
	}

	/**
	 * Breakpoint key for innerWidth / client width (px).
	 *
	 * @param int                  $width_px Viewport width in CSS pixels.
	 * @param array<string, mixed> $sto_options Full or partial `sto_options`.
	 */
	public static function resolve_breakpoint( $width_px, array $sto_options ) {
		$width_px = (int) $width_px;
		if ( $width_px < 1 ) {
			$width_px = 1;
		}

		$mins = self::get_minimums( $sto_options );
		if ( ! isset( $mins['mobile'] ) ) {
			$mins['mobile'] = self::default_minimums()['mobile'];
		}

		$mobile_floor = (int) $mins['mobile'];
		if ( $width_px < $mobile_floor ) {
			return 'mobile';
		}

		unset( $mins['mobile'] );

		$order = ResponsiveConfig::ALL_BREAKPOINTS;
		$pairs = array();
		foreach ( $order as $bp ) {
			if ( isset( $mins[ $bp ] ) && $mins[ $bp ] > 0 ) {
				$pairs[] = array( 'bp' => $bp, 'min' => (int) $mins[ $bp ] );
			}
		}
		usort(
			$pairs,
			static function ( $a, $b ) {
				return ( $b['min'] <=> $a['min'] );
			}
		);

		foreach ( $pairs as $row ) {
			if ( $width_px >= $row['min'] ) {
				return (string) $row['bp'];
			}
		}

		return 'mobile';
	}

	/**
	 * @param mixed                $stored Raw `sto_options[ $id ]` (scalar or breakpoint map).
	 * @param int                  $width_px
	 * @param array<string, mixed> $sto_options Used for breakpoint thresholds (may be same array as stored root).
	 * @return mixed
	 */
	public static function get_value_for_width( $stored, $width_px, array $sto_options, $default = null ) {
		$bp = self::resolve_breakpoint( $width_px, $sto_options );

		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			if ( array_key_exists( $bp, $stored ) ) {
				return $stored[ $bp ];
			}
			if ( $bp === 'mobile' ) {
				$leg = ResponsiveConfig::LEGACY_PHONE_KEY;
				if ( array_key_exists( $leg, $stored ) ) {
					return $stored[ $leg ];
				}
			}
			$eval = ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT;
			if ( array_key_exists( $eval, $stored ) ) {
				return $stored[ $eval ];
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

	/**
	 * @return array<string, string> option key => string value for DB (preset === default).
	 */
	public static function default_edge_option_row() {
		$row = array(
			self::OPTION_PRESET => 'default',
		);
		foreach ( self::EDGE_KEYS as $bp => $key ) {
			$defs           = self::default_minimums();
			$row[ $key ] = (string) (int) ( $defs[ $bp ] ?? 0 );
		}

		return $row;
	}

	/**
	 * Ensure monotonic non-decreasing mins (mobile → xxl) after save. Zero disables a tier (except mobile floor).
	 *
	 * @param array<string, mixed> $sto_options
	 * @return array<string, mixed>
	 */
	public static function normalize_saved_minimums( array $sto_options ) {
		$asc = array( 'mobile', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl' );
		$prev = 0;
		foreach ( $asc as $bp ) {
			if ( ! isset( self::EDGE_KEYS[ $bp ] ) ) {
				continue;
			}
			$key = self::EDGE_KEYS[ $bp ];
			if ( ! array_key_exists( $key, $sto_options ) ) {
				continue;
			}
			$v = (int) $sto_options[ $key ];
			if ( $bp === 'mobile' ) {
				$v                   = max( 0, min( 4096, $v ) );
				$prev                = $v;
				$sto_options[ $key ] = (string) $v;
				continue;
			}
			if ( $v <= 0 ) {
				$sto_options[ $key ] = '0';
				continue;
			}
			$v                   = max( $prev + 1, min( 8192, $v ) );
			$prev                = $v;
			$sto_options[ $key ] = (string) $v;
		}

		return $sto_options;
	}

	/**
	 * For localize_script / front-end: `mobileFloor` + `tiers` sorted by min descending for the same
	 * cascade as {@see resolve_breakpoint()}.
	 *
	 * @param array<string, mixed> $sto_options
	 * @return array{mobileFloor: int, tiers: array<int, array{bp: string, min: int}>}
	 */
	public static function get_js_config( array $sto_options ) {
		$mins        = self::get_minimums( $sto_options );
		$mobile_floor = (int) ( $mins['mobile'] ?? self::default_minimums()['mobile'] );
		unset( $mins['mobile'] );

		$pairs = array();
		foreach ( $mins as $bp => $min ) {
			if ( $min > 0 ) {
				$pairs[] = array(
					'bp'  => (string) $bp,
					'min' => (int) $min,
				);
			}
		}
		usort(
			$pairs,
			static function ( $a, $b ) {
				return ( $b['min'] <=> $a['min'] );
			}
		);

		return array(
			'mobileFloor' => $mobile_floor,
			'tiers'       => $pairs,
		);
	}
}
