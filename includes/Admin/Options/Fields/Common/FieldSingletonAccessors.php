<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Static proxies for singleton field registry helpers.
 *
 * Instance implementations must live on **`registry_*`** methods so they do not
 * collide with these **`public static`** entry-points (PHP forbids a class from
 * mixing a static and an instance method with the same name).
 *
 * Prefer **`SomeField::get_field( $section_slug, $field_id )`** over reaching for
 * **`SomeField::instance()->registry_get_field( … )`** in themes and custom code.
 */
trait FieldSingletonAccessors {

	/**
	 * @param string $section_slug
	 * @param string $field_id
	 * @return array<string, mixed>|null
	 */
	public static function get_field( $section_slug, $field_id ) {
		return static::instance()->registry_get_field( $section_slug, $field_id );
	}

	/**
	 * @param string $section_slug
	 * @return array<int, string>
	 */
	public static function get_field_ids_for_section( $section_slug ) {
		return static::instance()->registry_get_field_ids_for_section( $section_slug );
	}

	/**
	 * @param string $field_id
	 * @return bool
	 */
	public static function is_registered_field_id( $field_id ) {
		return static::instance()->registry_is_registered_field_id( $field_id );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public static function get_all_fields_for_search() {
		return static::instance()->registry_get_all_fields_for_search();
	}
}
