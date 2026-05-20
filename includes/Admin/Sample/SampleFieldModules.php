<?php
namespace SimpleThemeOptions\Admin\Sample;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;

defined( 'ABSPATH' ) || exit;

/**
 * Boots sample Theme Settings field registrar classes and section navigation.
 *
 * Invoked from {@see \SimpleThemeOptions\Plugin::register_hooks()} after {@see \SimpleThemeOptions\Admin\Sample\Menu::instance()}
 * for users with the **manage_options** capability, so {@see \SimpleThemeOptions\Admin\Sample\Menu::init()} only registers the Theme Settings admin page
 * after {@see \SimpleThemeOptions\Admin\Sample\Menu} registers the packaged sample root ({@see \SimpleThemeOptions\Admin\Options\Menu::PACKAGED_DEMO_MENU_SLUG}).
 *
 * Field modules live under `includes/Admin/Sample/Fields/{Group}/{ClassName}.php`.
 * Each module is a **PascalCase** `.php` file whose basename matches the PHP class name
 * inside that folder (e.g. `Fields/General/General.php` → `…\Fields\General\General`).
 * Lowercase helpers (e.g. `accordion.php` loaders) are skipped. Classes must expose
 * `public static function instance()` (singleton pattern).
 */
final class SampleFieldModules {

	/**
	 * Boot every discovered field module, then register sidebar / submenu sections.
	 */
	public static function boot() {
		if ( ! OptionsMenu::instance()->is_demo_mode_enabled() ) {
			return;
		}

		self::boot_discovered_field_modules();
		Sections::instance()->register();
	}

	/**
	 * Boot sample field registrar singletons so the option registry is populated (e.g. admin-ajax export).
	 * Does not register sidebar sections; safe when demo mode is off.
	 */
	public static function boot_discovered_field_modules_for_registry(): void {
		self::boot_discovered_field_modules();
	}

	/**
	 * Instantiate each matching PHP file under Fields (one subdirectory, PascalCase basename).
	 */
	private static function boot_discovered_field_modules() {
		$base = __DIR__ . '/Fields';
		if ( ! is_dir( $base ) ) {
			return;
		}

		$paths = glob( $base . '/*/*.php', GLOB_NOSORT );
		if ( ! is_array( $paths ) ) {
			return;
		}

		sort( $paths, SORT_STRING );

		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			$file = basename( $path );
			if ( ! preg_match( '/^[A-Z][A-Za-z0-9_]*\.php$/', $file ) ) {
				continue;
			}

			$dir       = basename( dirname( $path ) );
			$classBase = basename( $path, '.php' );
			$fqcn      = __NAMESPACE__ . '\\Fields\\' . $dir . '\\' . $classBase;

			if ( ! class_exists( $fqcn ) ) {
				continue;
			}

			if ( ! is_callable( array( $fqcn, 'instance' ) ) ) {
				continue;
			}

			$fqcn::instance();
		}
	}
}
