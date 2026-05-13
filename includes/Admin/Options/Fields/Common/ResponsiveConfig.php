<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Breakpoint keys for optional per-field responsive values in `sto_options[id]`.
 *
 * When `responsive` is enabled on a field, the stored value is an associative array
 * with one entry per **shown** breakpoint (see {@see breakpoints_for_field()}). Omitting
 * **`device`** uses {@see DEFAULT_RESPONSIVE_TABS} (PC / tablet / mobile — `xxl`, `md`, `mobile`).
 * Pass **`device`** as a non-empty list of extra canonical slugs: tabs are the **union** of the default
 * trio and those slugs (de-duplicated, order {@see ALL_BREAKPOINTS}). Example: **`lg`**, **`md`**, **`mobile`**
 * yields **`xxl`**, **`lg`**, **`md`**, **`mobile`**. Legacy scalar values apply to every breakpoint on read. Legacy storage key
 * `phone` is read as `mobile`. Older saves used **`lg`** as the desktop key for the default trio; when **`xxl`** is absent, **`raw_value_at_breakpoint()`** treats **`lg`** as the **`xxl`** slice on read (save still writes **`xxl`**).
 *
 * Viewport width → breakpoint is fixed in {@see \SimpleThemeOptions\ViewportOptions} (Elementor-style
 * floors); there is no per-site Advanced UI for thresholds.
 *
 * Conditional `required` rules evaluate using {@see value_for_required_eval()} (PC / desktop slice).
 */
final class ResponsiveConfig {

	public const ALL_BREAKPOINTS = array( 'xxl', 'xl', 'lg', 'md', 'sm', 'xs', 'mobile' );

	/**
	 * Default responsive tabs when `responsive` is true and `device` is omitted / empty:
	 * desktop (PC), tablet, mobile — same order as {@see ALL_BREAKPOINTS} (largest first).
	 */
	public const DEFAULT_RESPONSIVE_TABS = array( 'xxl', 'md', 'mobile' );

	/**
	 * Legacy smallest-breakpoint key in older saved `sto_options` maps.
	 */
	public const LEGACY_PHONE_KEY = 'phone';

	/**
	 * Breakpoint whose value `RequiredVisibility` / save validation prefer when reading a stored
	 * breakpoint map without an active tab (POST). Admin conditional **`required`** in **`main.js`**
	 * always evaluates dependencies at this slice (see **`getOptionFieldValue( key, '' )`**), not the
	 * currently selected responsive tab. Matches the **PC / desktop** tab in {@see DEFAULT_RESPONSIVE_TABS}.
	 */
	public const REQUIRED_EVAL_BREAKPOINT = 'xxl';

	/**
	 * Render-only field config key: a parent row (e.g. responsive **Tabs** device pane) already
	 * scopes the viewport. Inner responsive fields omit nested {@see ResponsiveControl} and bind
	 * one input per this breakpoint only (still stored under `sto_options[id][bp]`).
	 */
	public const PARENT_RESPONSIVE_PANE_BP = 'sto_parent_responsive_pane_bp';

	/**
	 * Registration marker on inner fields registered by **Tabs** — must not be output by
	 * section `render_section_fields` hooks on field classes; only **Tabs** renders them inside tab panes.
	 */
	public const TABS_INNER_FIELD = 'sto_tabs_inner';

	/**
	 * Registration marker on inner fields registered by **Accordion** — same skip rules as {@see TABS_INNER_FIELD}.
	 */
	public const ACCORDION_INNER_FIELD = 'sto_accordion_inner';

	/**
	 * True when the field is a **Tabs** or **Accordion** inner clone (skip standalone section hooks).
	 *
	 * @param array<string, mixed> $field
	 */
	public static function is_composite_inner_field( array $field ) {
		return ! empty( $field[ self::TABS_INNER_FIELD ] ) || ! empty( $field[ self::ACCORDION_INNER_FIELD ] );
	}

	/**
	 * Whether `responsive` opts the field into per-breakpoint storage/UI.
	 *
	 * @param mixed $raw `true` | non-empty array (values ignored for opt-in) | empty = off
	 * @return array<int, string>|null Non-empty full canonical list, or null if responsive is off
	 */
	public static function normalize_breakpoints( $raw ) {
		if ( $raw === true || $raw === 1 || $raw === '1' ) {
			return self::ALL_BREAKPOINTS;
		}
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			// Any non-empty array opts in; tab list comes from {@see breakpoints_for_field()} (default + optional `device` merge).
			return self::ALL_BREAKPOINTS;
		}

		return null;
	}

	/**
	 * Optional subset of breakpoints for responsive fields (`device` registration key).
	 *
	 * Order follows {@see ALL_BREAKPOINTS} (largest-first). Invalid keys dropped. Legacy `phone` maps to `mobile`.
	 * Empty or all-invalid input returns null ({@see breakpoints_for_field()} then uses only {@see DEFAULT_RESPONSIVE_TABS}).
	 *
	 * @param mixed $device List of breakpoint slugs, or null / empty array to use no subset filter.
	 * @return array<int, string>|null Non-empty ordered subset, or null when unset / empty / only invalid keys.
	 */
	public static function ordered_device_subset( $device ) {
		if ( ! is_array( $device ) || array() === $device ) {
			return null;
		}
		$want = array();
		foreach ( $device as $k ) {
			$k = sanitize_key( (string) $k );
			if ( self::LEGACY_PHONE_KEY === $k ) {
				$k = 'mobile';
			}
			if ( $k !== '' ) {
				$want[ $k ] = true;
			}
		}
		if ( empty( $want ) ) {
			return null;
		}
		$out = array();
		foreach ( self::ALL_BREAKPOINTS as $bp ) {
			if ( ! empty( $want[ $bp ] ) ) {
				$out[] = $bp;
			}
		}

		return ! empty( $out ) ? $out : null;
	}

	/**
	 * Union of {@see DEFAULT_RESPONSIVE_TABS} and validated `device` keys, de-duplicated, largest-first.
	 *
	 * @param array<int, string> $device_ordered From {@see ordered_device_subset()}.
	 * @return array<int, string>
	 */
	private static function merge_default_tabs_with_device_subset( array $device_ordered ) {
		$want = array();
		foreach ( self::DEFAULT_RESPONSIVE_TABS as $bp ) {
			$want[ sanitize_key( (string) $bp ) ] = true;
		}
		foreach ( $device_ordered as $bp ) {
			$want[ sanitize_key( (string) $bp ) ] = true;
		}
		$out = array();
		foreach ( self::ALL_BREAKPOINTS as $bp ) {
			if ( ! empty( $want[ $bp ] ) ) {
				$out[] = $bp;
			}
		}

		return $out;
	}

	/**
	 * Breakpoint tabs / storage keys for one field.
	 *
	 * @param array<string, mixed> $field Must include `responsive` to enable. Optional `device` => non-empty list
	 *                                    of canonical slugs is **merged** with {@see DEFAULT_RESPONSIVE_TABS}
	 *                                    (union, {@see ALL_BREAKPOINTS} order). Empty or all-invalid `device` uses only the default trio.
	 * @return array<int, string>|null
	 */
	public static function breakpoints_for_field( array $field ) {
		$bps = self::normalize_breakpoints( $field['responsive'] ?? null );
		if ( null === $bps ) {
			return null;
		}
		$subset = self::ordered_device_subset( $field['device'] ?? null );
		if ( null !== $subset && array() !== $subset ) {
			return self::merge_default_tabs_with_device_subset( $subset );
		}

		return self::DEFAULT_RESPONSIVE_TABS;
	}

	/**
	 * When {@see PARENT_RESPONSIVE_PANE_BP} is set and matches `responsive_breakpoints`, renderers
	 * suppress nested breakpoint toolbars and show one control for that slice only.
	 * **`Tabs`** / **`Accordion`** do not set this on their registered inners (each inner field owns full responsive UI).
	 *
	 * @param array<string, mixed> $field Field config (may include render-only parent key).
	 * @return string Sanitized breakpoint slug or '' when not in parent-pane-only mode.
	 */
	public static function parent_responsive_pane_bp( array $field ) {
		$raw = isset( $field[ self::PARENT_RESPONSIVE_PANE_BP ] ) ? (string) $field[ self::PARENT_RESPONSIVE_PANE_BP ] : '';
		if ( $raw === '' ) {
			return '';
		}
		$bp = sanitize_key( $raw );
		if ( $bp === '' ) {
			return '';
		}
		$list = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : array();
		foreach ( $list as $allowed ) {
			if ( sanitize_key( (string) $allowed ) === $bp ) {
				return $bp;
			}
		}

		return '';
	}

	/**
	 * @return array<string, int>
	 */
	private static function storage_map_keys_flip() {
		static $flip = null;
		if ( null === $flip ) {
			$keys   = self::ALL_BREAKPOINTS;
			$keys[] = self::LEGACY_PHONE_KEY;
			$flip   = array_flip( $keys );
		}

		return $flip;
	}

	/**
	 * Whether an array looks like a breakpoint map (keys are canonical breakpoints and/or legacy `phone`).
	 *
	 * @param array<string, mixed> $arr
	 */
	public static function is_breakpoint_value_map( array $arr ) {
		if ( empty( $arr ) ) {
			return false;
		}
		$allowed = self::storage_map_keys_flip();
		foreach ( array_keys( $arr ) as $k ) {
			$k = sanitize_key( (string) $k );
			if ( ! isset( $allowed[ $k ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Raw value for one breakpoint from a stored map (handles legacy `phone` → `mobile`, and
	 * legacy default-trio desktop key `lg` → `xxl` when `xxl` is not stored).
	 *
	 * @param array<string, mixed> $stored
	 * @param string               $bp
	 * @return mixed|null
	 */
	public static function raw_value_at_breakpoint( array $stored, $bp ) {
		$bp = sanitize_key( (string) $bp );
		if ( isset( $stored[ $bp ] ) ) {
			return $stored[ $bp ];
		}
		if ( $bp === 'mobile' && isset( $stored[ self::LEGACY_PHONE_KEY ] ) ) {
			return $stored[ self::LEGACY_PHONE_KEY ];
		}
		if ( 'xxl' === $bp && ! array_key_exists( 'xxl', $stored ) && array_key_exists( 'lg', $stored ) ) {
			return $stored['lg'];
		}

		return null;
	}

	/**
	 * String to compare for conditional `required` / PHP visibility when the option is a breakpoint map.
	 * Prefers {@see REQUIRED_EVAL_BREAKPOINT} when present; otherwise falls back in order **lg**, **md**,
	 * **xl**, … so maps with only **lg / md / mobile** (desktop under **lg**) still resolve.
	 *
	 * @param array<string, mixed> $map
	 */
	public static function value_for_required_eval( array $map ) {
		$bp = self::REQUIRED_EVAL_BREAKPOINT;
		if ( isset( $map[ $bp ] ) && is_scalar( $map[ $bp ] ) ) {
			return (string) $map[ $bp ];
		}
		foreach ( array( 'lg', 'md', 'xl', 'sm', 'xs', 'mobile' ) as $alt ) {
			if ( isset( $map[ $alt ] ) && is_scalar( $map[ $alt ] ) ) {
				return (string) $map[ $alt ];
			}
		}
		if ( isset( $map[ self::LEGACY_PHONE_KEY ] ) && is_scalar( $map[ self::LEGACY_PHONE_KEY ] ) ) {
			return (string) $map[ self::LEGACY_PHONE_KEY ];
		}
		$first = reset( $map );

		return is_scalar( $first ) ? (string) $first : '';
	}

	/**
	 * @param mixed                $stored Value from `sto_options`
	 * @param array<int, string>   $breakpoints
	 * @param string               $default_scalar Default for each missing key
	 * @return array<string, string>
	 */
	public static function coerce_map( $stored, array $breakpoints, $default_scalar ) {
		$default_scalar = (string) $default_scalar;
		$out            = array();
		if ( is_array( $stored ) && self::is_breakpoint_value_map( $stored ) ) {
			foreach ( $breakpoints as $bp ) {
				$bp = sanitize_key( (string) $bp );
				$rv = self::raw_value_at_breakpoint( $stored, $bp );
				$out[ $bp ] = null !== $rv ? (string) $rv : $default_scalar;
			}

			return $out;
		}

		$scalar = is_scalar( $stored ) ? (string) $stored : $default_scalar;
		foreach ( $breakpoints as $bp ) {
			$out[ sanitize_key( (string) $bp ) ] = $scalar;
		}

		return $out;
	}
}
