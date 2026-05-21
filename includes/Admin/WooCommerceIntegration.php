<?php
/**
 * Register WooCommerce hooks after WooCommerce has booted (avoids early textdomain notices on WP 6.7+).
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin;

defined( 'ABSPATH' ) || exit;

final class WooCommerceIntegration {

	/**
	 * Run callback once WooCommerce is loaded, or on `woocommerce_init`.
	 *
	 * @param callable $callback Callback.
	 * @param int      $priority Hook priority on `woocommerce_init`.
	 */
	public static function when_ready( callable $callback, int $priority = 5 ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		if ( did_action( 'woocommerce_init' ) ) {
			$callback();
			return;
		}

		add_action( 'woocommerce_init', $callback, $priority );
	}

	/**
	 * @param string   $hook          WooCommerce action hook.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted arguments.
	 */
	public static function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		self::when_ready(
			static function () use ( $hook, $callback, $priority, $accepted_args ) {
				add_action( $hook, $callback, $priority, $accepted_args );
			}
		);
	}
}
