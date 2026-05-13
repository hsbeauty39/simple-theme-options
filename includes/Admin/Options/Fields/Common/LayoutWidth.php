<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Shared `width` → 12-column span parser used by both **Tabs** inner items and **Group** inner items
 * (and any future container that wants the same grid-cell grammar).
 *
 * Accepted inputs:
 *   - **`null`** / **empty string** → **12** (full width, one cell per visual row).
 *   - **Integer / numeric string `1`–`12`** → returned verbatim, clamped to **[1, 12]**.
 *   - **`'full'`** / **`'1-1'`** / **`'1/1'`** / **`'100%'`** → **12**.
 *   - **`'N-D'`** / **`'N/D'`** (e.g. **`1-2`**, **`1/3`**, **`2-3`**) → **`round( 12 * N / D )`**, clamped to **[1, 12]**.
 *   - Anything else falls back to **12** so a malformed value never breaks the layout.
 *
 * Per the project rule: **keep each visual row's spans summing to ≤ 12** or the grid wraps
 * unpredictably (e.g. **`1-2 + 1-2 + 1-3`** = **6 + 6 + 4** = **16** wraps mid-row). Containers
 * stack to **one column per row** at **≤ 960px** so the value is never lost on narrow screens.
 */
final class LayoutWidth {

	/**
	 * @param mixed $raw Width descriptor as accepted by Tabs / Group inner items.
	 * @return int Grid span in the range **`1..12`** (default **`12`** when input is empty / invalid).
	 */
	public static function parse_span( $raw ) {
		if ( $raw === null || $raw === '' ) {
			return 12;
		}

		if ( is_numeric( $raw ) ) {
			$n = (int) $raw;
			if ( $n >= 1 && $n <= 12 ) {
				return $n;
			}
			if ( $n > 12 ) {
				return 12;
			}
			if ( $n < 1 ) {
				return 1;
			}
		}

		$s = strtolower( trim( (string) $raw ) );
		if ( $s === '' ) {
			return 12;
		}
		if ( $s === 'full' || $s === '1-1' || $s === '1/1' || $s === '100%' ) {
			return 12;
		}

		if ( preg_match( '/^(\d+)\s*[-\/]\s*(\d+)$/', $s, $m ) ) {
			$n   = (int) $m[1];
			$den = (int) $m[2];
			if ( $den < 1 || $n < 1 || $n > $den ) {
				return 12;
			}
			$span = (int) round( 12 * ( $n / $den ) );

			return max( 1, min( 12, $span ) );
		}

		return 12;
	}

	/**
	 * Quick check whether any inner item explicitly declares a non-default width.
	 *
	 * Useful when a container only wants to opt-in to grid mode if at least one child needs it.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return bool
	 */
	public static function any_explicit_width( array $items ) {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( array_key_exists( 'width', $item ) && $item['width'] !== '' && $item['width'] !== null ) {
				return true;
			}
		}

		return false;
	}
}
