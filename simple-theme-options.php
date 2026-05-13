<?php
/**
 * Plugin Name:       Simple Theme Options
 * Description:       Simple theme options with autoload and singleton architecture.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL v2 or later
 * Text Domain:       simple-theme-options
 *
 * @package SimpleThemeOptions
 */

defined( 'ABSPATH' ) || exit;

define( 'STO_VERSION', '1.0.0' );
define( 'STO_FILE', __FILE__ );
define( 'STO_PATH', plugin_dir_path( __FILE__ ) );
define( 'STO_URL', plugin_dir_url( __FILE__ ) );
define( 'STO_INCLUDES', STO_PATH . 'includes/' );
define( 'STO_NAMESPACE', 'SimpleThemeOptions' );

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

function simple_theme_options() {
	return \SimpleThemeOptions\Plugin::instance();
}

add_action( 'plugins_loaded', 'simple_theme_options' );
