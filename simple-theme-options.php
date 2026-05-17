<?php
/**
 * Plugin Name:       Simple Theme Options
 * Plugin URI:        https://github.com/hsbeauty39/simple-theme-options
 * Description:       Simple theme options with autoload and singleton architecture.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL v2 or later
 * Text Domain:       topten-simple-theme-options
 * Update URI:        false
 *
 * Install this fork as `wp-content/plugins/battery-simple-theme-options/` (or any folder name
 * other than `simple-theme-options`). WordPress.org reuses slug `simple-theme-options` for
 * "Simple Tracking" by CHRS — same folder name pulls that plugin and breaks this codebase.
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
require_once __DIR__ . '/vendor/autoload.php';

if ( ! function_exists( 'topten_sto' ) ) {
    // Create a helper function for easy SDK access.
    function topten_sto() {
        global $topten_sto;

        if ( ! isset( $topten_sto ) ) {
            // Activate multisite network integration.
            if ( ! defined( 'WP_FS__PRODUCT_29793_MULTISITE' ) ) {
                define( 'WP_FS__PRODUCT_29793_MULTISITE', true );
            }

            // Include Freemius SDK.
            // SDK is auto-loaded through Composer

            $topten_sto = fs_dynamic_init( array(
                'id'                  => '29793',
                'slug'                => 'topten-simple-theme-options',
                'premium_slug'        => 'topten-simple-theme-options',
                'type'                => 'plugin',
                'public_key'          => 'pk_15f8fadad4d1dd98a828c539eaf31',
                'is_premium'          => true,
                'premium_suffix'      => 'Premium',
                // If your plugin is a serviceware, set this option to false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                // Automatically removed in the free version. If you're not using the
                // auto-generated free version, delete this line before uploading to wp.org.
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 3,
                    'is_require_payment' => true,
                ),
                'menu'                => array(
                    'first-path'     => 'plugins.php',
                    'support'        => false,
                ),
            ) );
        }

        return $topten_sto;
    }

    // Init Freemius.
    topten_sto();
    // Signal that SDK was initiated.
    do_action( 'topten_sto_loaded' );
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

function simple_theme_options() {
	return \SimpleThemeOptions\Plugin::instance();
}

add_action( 'plugins_loaded', 'simple_theme_options' );

