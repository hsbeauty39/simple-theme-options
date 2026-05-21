<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package SimpleThemeOptions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$tracker_file = dirname( __FILE__ ) . '/includes/InstallationTracker.php';
if ( is_readable( $tracker_file ) ) {
	require_once $tracker_file;
	\SimpleThemeOptions\InstallationTracker::on_uninstall();
}
