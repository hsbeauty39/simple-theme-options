<?php
/**
 * Plugin Name:       Topten Simple Theme Options
 * Plugin URI:        https://github.com/hsbeauty39/simple-theme-options
 * Description:       Topten theme options panel with responsive fields, import/export, and custom fonts.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL v2 or later
 * Text Domain:       topten-simple-theme-options
 *
 * Recommended install folder: `battery-simple-theme-options/` (avoid wordpress.org slug `simple-theme-options`).
 * Do not use the wordpress.org folder name `simple-theme-options` (CHRS “Simple Tracking” collision).
 *
 * @package SimpleThemeOptions
 */

defined( 'ABSPATH' ) || exit;

define( 'STO_VERSION', '1.0.0' );
define( 'STO_PLUGIN_SLUG', 'battery-simple-theme-options' );
define( 'STO_PLUGIN_NAME', 'Topten Simple Theme Options' );
define( 'STO_TEXT_DOMAIN', 'topten-simple-theme-options' );
define( 'STO_FILE', __FILE__ );
define( 'STO_PATH', plugin_dir_path( __FILE__ ) );
define( 'STO_URL', plugin_dir_url( __FILE__ ) );
define( 'STO_INCLUDES', STO_PATH . 'includes/' );
define( 'STO_NAMESPACE', 'SimpleThemeOptions' );

require_once __DIR__ . '/vendor/autoload.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- STO bootstrap path; `sto_` prefix is the project convention.
$sto_freemius_bootstrap = STO_INCLUDES . 'FreemiusBootstrap.php';
if ( is_readable( $sto_freemius_bootstrap ) ) {
	require_once $sto_freemius_bootstrap;
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = STO_NAMESPACE . '\\';

		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';
		$file           = STO_INCLUDES . $relative_path;

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once STO_INCLUDES . 'functions.php';

/**
 * Main plugin accessor (bootstrap entry point).
 */
function simple_theme_options() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy bootstrap name; use Plugin::instance() in new code.
	return \SimpleThemeOptions\Plugin::instance();
}

add_action( 'plugins_loaded', 'simple_theme_options' );
