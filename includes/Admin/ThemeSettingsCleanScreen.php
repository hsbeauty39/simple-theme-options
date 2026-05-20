<?php
namespace SimpleThemeOptions\Admin;

use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the Theme Settings admin page free of third-party notices and on-screen PHP noise.
 */
final class ThemeSettingsCleanScreen {
	use SingletonTrait;

	/**
	 * @var string
	 */
	private $hooked_slug = '';

	/**
	 * @param string $menu_slug Parent menu slug from add_menu_page (e.g. theme-settings).
	 */
	public function hook_clean_screen( $menu_slug ) {
		$menu_slug = sanitize_key( (string) $menu_slug );
		if ( ! $menu_slug || $this->hooked_slug === $menu_slug ) {
			return;
		}

		$this->hooked_slug = $menu_slug;
		add_action( 'load-toplevel_page_' . $menu_slug, array( $this, 'on_load_theme_settings_screen' ), 0 );
	}

	/**
	 * Runs after admin_init (plugins have registered notices) and before admin-header prints them.
	 */
	public function on_load_theme_settings_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! apply_filters( 'sto_theme_settings_suppress_external_notices', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
			return;
		}

		foreach ( array(
			'admin_notices',
			'network_admin_notices',
			'user_admin_notices',
			'all_admin_notices',
		) as $notice_hook ) {
			remove_all_actions( $notice_hook );
		}

		if ( ! apply_filters( 'sto_theme_settings_suppress_php_display_errors', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
			return;
		}

		if ( function_exists( 'wp_is_ini_value_changeable' ) && wp_is_ini_value_changeable( 'display_errors' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Suppress PHP notices on Theme Settings screen only.
			ini_set( 'display_errors', '0' );
		} elseif ( function_exists( 'ini_set' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
			@ini_set( 'display_errors', '0' );
		}
	}
}
