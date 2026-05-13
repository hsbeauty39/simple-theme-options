<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Static proxy for `sanitize_posted_value()` on field singletons (save path + custom importers).
 *
 * Instance logic must live on **`registry_sanitize_posted_value()`** so it does not
 * collide with this **`public static`** wrapper (same rule as **`FieldSingletonAccessors`**).
 */
trait FieldSanitizePostedProxy {

	/**
	 * @param string $field_id
	 * @param mixed  $raw
	 * @return mixed
	 */
	public static function sanitize_posted_value( $field_id, $raw ) {
		return static::instance()->registry_sanitize_posted_value( $field_id, $raw );
	}
}
