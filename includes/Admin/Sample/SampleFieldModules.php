<?php
namespace SimpleThemeOptions\Admin\Sample;

defined( 'ABSPATH' ) || exit;

/**
 * Boots sample Theme Settings field registrar classes and section navigation.
 *
 * Invoked from {@see \SimpleThemeOptions\Plugin::register_hooks()} before {@see \SimpleThemeOptions\Admin\Sample\Menu::instance()}
 * for users with the **manage_options** capability, so {@see \SimpleThemeOptions\Admin\Sample\Menu::init()} only registers the Theme Settings admin page.
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
		self::boot_discovered_field_modules();
		Sections::instance()->register();
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
