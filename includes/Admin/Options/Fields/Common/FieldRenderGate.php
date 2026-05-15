<?php
/**
 * Per-import display location gate for field rendering.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Options\Fields\Common;

use SimpleThemeOptions\Admin\ThemeSettingsDisplayLocations;

defined( 'ABSPATH' ) || exit;

final class FieldRenderGate {

	/**
	 * @param array<string, mixed> $field
	 */
	public static function should_render_field( array $field ): bool {
		if ( ! isset( $field['id'] ) ) {
			return true;
		}

		$id = sanitize_key( (string) $field['id'] );
		if ( $id === '' ) {
			return true;
		}

		return ThemeSettingsDisplayLocations::instance()->is_field_visible_on_current_surface( $id );
	}
}
