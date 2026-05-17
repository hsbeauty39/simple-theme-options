<?php
/**
 * Resolve registered field defaults for Theme Settings reset actions.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Options;

use SimpleThemeOptions\Admin\Options\Fields\AdvancedRepeaterControl\AdvancedRepeaterControl;
use SimpleThemeOptions\Admin\Options\Fields\AlignmentControl\AlignmentControl;
use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Admin\Options\Fields\CheckboxControl\CheckboxControl;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\DateField\DateField;
use SimpleThemeOptions\Admin\Options\Fields\DateTimeField\DateTimeField;
use SimpleThemeOptions\Admin\Options\Fields\Dimension\Dimension;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\GalleryControl\GalleryControl;
use SimpleThemeOptions\Admin\Options\Fields\GoogleMapControl\GoogleMapControl;
use SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl;
use SimpleThemeOptions\Admin\Options\Fields\IconSelect\IconSelect;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Admin\Options\Fields\MultiTextControl\MultiTextControl;
use SimpleThemeOptions\Admin\Options\Fields\RadioListsControl\RadioListsControl;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;

defined( 'ABSPATH' ) || exit;

/**
 * Builds sanitized default `sto_options` values from field registration config.
 */
final class ThemeSettingsDefaults {

	/**
	 * @return array<int, class-string>
	 */
	private static function field_registry_classes(): array {
		return array(
			Typography::class,
			BackgroundControl::class,
			BorderControl::class,
			ShadowControl::class,
			GradientControl::class,
			LinkColor::class,
			Color::class,
			Switcher::class,
			CheckboxControl::class,
			Select::class,
			ImageSelect::class,
			DynamicObject::class,
			Input::class,
			DateField::class,
			DateTimeField::class,
			Dimension::class,
			IconSelect::class,
			GalleryControl::class,
			MultiTextControl::class,
			RadioListsControl::class,
			AdvancedRepeaterControl::class,
			GoogleMapControl::class,
			AlignmentControl::class,
			Range::class,
			CodeEditor::class,
			ButtonGroup::class,
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function section_slugs_to_scan( Menu $menu, string $prefer_section_slug = '' ): array {
		$prefer_section_slug = sanitize_key( $prefer_section_slug );
		$sections            = array();

		if ( $prefer_section_slug !== '' ) {
			$sections[] = $prefer_section_slug;
		}

		foreach ( $menu->get_leaf_sections_for_navigation() as $leaf ) {
			$slug = isset( $leaf['slug'] ) ? sanitize_key( (string) $leaf['slug'] ) : '';
			if ( $slug !== '' ) {
				$sections[] = $slug;
			}
		}

		return array_values( array_unique( $sections ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find_field_config( string $option_key, Menu $menu, string $prefer_section_slug = '' ): ?array {
		$option_key = sanitize_key( $option_key );
		if ( $option_key === '' ) {
			return null;
		}

		foreach ( self::field_registry_classes() as $class_name ) {
			if ( ! $class_name::is_registered_field_id( $option_key ) ) {
				continue;
			}

			foreach ( self::section_slugs_to_scan( $menu, $prefer_section_slug ) as $section_slug ) {
				$field = $class_name::get_field( $section_slug, $option_key );
				if ( is_array( $field ) ) {
					return $field;
				}
			}
		}

		return null;
	}

	/**
	 * Raw default from registration (breakpoint map when responsive).
	 *
	 * @param array<string, mixed>|null $field
	 * @return mixed
	 */
	public static function raw_default_from_field( ?array $field ) {
		if ( ! is_array( $field ) ) {
			return '';
		}

		$raw = array_key_exists( 'default', $field ) ? $field['default'] : '';

		$breakpoints = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] )
			? $field['responsive_breakpoints']
			: null;

		if ( empty( $breakpoints ) ) {
			return $raw;
		}

		if ( is_array( $raw ) && ResponsiveConfig::is_breakpoint_value_map( $raw ) ) {
			return $raw;
		}

		$per_breakpoint = array();
		if ( is_array( $raw ) && ! ResponsiveConfig::is_breakpoint_value_map( $raw ) ) {
			$per_breakpoint = $raw;
			$scalar         = '';
		} else {
			$scalar = is_scalar( $raw ) ? (string) $raw : '';
		}

		return ResponsiveConfig::coerce_map( null, $breakpoints, $scalar, $per_breakpoint );
	}

	/**
	 * Sanitized value ready for `sto_options[ $key ]`.
	 *
	 * @param string $option_key
	 * @param mixed  $raw
	 * @return mixed
	 */
	public static function sanitize_default_value( string $option_key, $raw ) {
		$option_key = sanitize_key( $option_key );
		if ( $option_key === '' ) {
			return '';
		}

		if ( Typography::is_registered_field_id( $option_key ) ) {
			return Typography::sanitize_posted_value( $option_key, $raw );
		}

		if ( BackgroundControl::is_registered_field_id( $option_key ) ) {
			return BackgroundControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( BorderControl::is_registered_field_id( $option_key ) ) {
			return BorderControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( ShadowControl::is_registered_field_id( $option_key ) ) {
			return ShadowControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( GradientControl::is_registered_field_id( $option_key ) ) {
			return GradientControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( LinkColor::is_registered_field_id( $option_key ) ) {
			return LinkColor::sanitize_posted_value( $option_key, $raw );
		}

		if ( Color::is_registered_field_id( $option_key ) ) {
			return Color::sanitize_posted_value( $option_key, $raw );
		}

		if ( Switcher::is_registered_field_id( $option_key ) ) {
			return Switcher::sanitize_posted_value( $option_key, $raw );
		}

		if ( CheckboxControl::is_registered_field_id( $option_key ) ) {
			return CheckboxControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( Select::is_registered_field_id( $option_key ) ) {
			return Select::sanitize_posted_value( $option_key, $raw );
		}

		if ( ImageSelect::is_registered_field_id( $option_key ) ) {
			return ImageSelect::sanitize_posted_value( $option_key, $raw );
		}

		if ( DynamicObject::is_registered_field_id( $option_key ) ) {
			return DynamicObject::instance()->sanitize_for_field( $option_key, $raw );
		}

		if ( Input::is_registered_field_id( $option_key ) ) {
			return Input::sanitize_posted_value( $option_key, $raw );
		}

		if ( DateField::is_registered_field_id( $option_key ) ) {
			return DateField::sanitize_posted_value( $option_key, $raw );
		}

		if ( DateTimeField::is_registered_field_id( $option_key ) ) {
			return DateTimeField::sanitize_posted_value( $option_key, $raw );
		}

		if ( Dimension::is_registered_field_id( $option_key ) ) {
			return Dimension::sanitize_posted_value( $option_key, $raw );
		}

		if ( IconSelect::is_registered_field_id( $option_key ) ) {
			return IconSelect::sanitize_posted_value( $option_key, $raw );
		}

		if ( GalleryControl::is_registered_field_id( $option_key ) ) {
			return GalleryControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( MultiTextControl::is_registered_field_id( $option_key ) ) {
			return MultiTextControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( RadioListsControl::is_registered_field_id( $option_key ) ) {
			return RadioListsControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( AdvancedRepeaterControl::is_registered_field_id( $option_key ) ) {
			return AdvancedRepeaterControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( GoogleMapControl::is_registered_field_id( $option_key ) ) {
			return GoogleMapControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( AlignmentControl::is_registered_field_id( $option_key ) ) {
			return AlignmentControl::sanitize_posted_value( $option_key, $raw );
		}

		if ( Range::is_registered_field_id( $option_key ) ) {
			return Range::sanitize_posted_value( $option_key, $raw );
		}

		if ( CodeEditor::is_registered_field_id( $option_key ) ) {
			return CodeEditor::sanitize_posted_value( $option_key, $raw );
		}

		if ( ButtonGroup::is_registered_field_id( $option_key ) ) {
			return ButtonGroup::sanitize_posted_value( $option_key, $raw );
		}

		if ( is_array( $raw ) ) {
			return array_map( 'sanitize_text_field', $raw );
		}

		return sanitize_text_field( (string) $raw );
	}

	/**
	 * @param string $option_key
	 * @param Menu   $menu
	 * @param string $prefer_section_slug
	 * @return mixed
	 */
	public static function sanitized_default_for_option_key( string $option_key, Menu $menu, string $prefer_section_slug = '' ) {
		$field = self::find_field_config( $option_key, $menu, $prefer_section_slug );
		$raw   = self::raw_default_from_field( $field );

		return self::sanitize_default_value( $option_key, $raw );
	}
}
