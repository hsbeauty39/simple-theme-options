<?php
/**
 * Report install / uninstall events to Topten Track Plugin Installation.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions;

defined( 'ABSPATH' ) || exit;

final class InstallationTracker {

	public const PENDING_OPTION = 'sto_installation_tracker_pending';

	/**
	 * Register deferred reporting (after other plugins register tracker filters).
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'flush_pending_event' ), 20 );
	}

	/**
	 * Register activation reporting.
	 */
	public static function on_activation(): void {
		self::queue_or_report( 'install' );
	}

	/**
	 * Report uninstall (called from uninstall.php).
	 */
	public static function on_uninstall(): void {
		self::report( 'uninstall', true );
	}

	/**
	 * Retry install report on next request when tracker was not ready during activation.
	 */
	public static function flush_pending_event(): void {
		$pending = get_option( self::PENDING_OPTION, '' );
		if ( ! is_string( $pending ) || ! in_array( $pending, array( 'install', 'uninstall' ), true ) ) {
			return;
		}

		if ( ! self::can_report() ) {
			return;
		}

		if ( self::report( $pending, true ) ) {
			delete_option( self::PENDING_OPTION );
		}
	}

	/**
	 * @param string $event_type install|uninstall.
	 */
	private static function queue_or_report( string $event_type ): void {
		if ( self::can_report() && self::report( $event_type, true ) ) {
			delete_option( self::PENDING_OPTION );
			return;
		}

		update_option( self::PENDING_OPTION, $event_type, false );
	}

	private static function can_report(): bool {
		return '' !== self::get_endpoint() && '' !== self::get_api_key();
	}

	/**
	 * @param string $event_type   install|uninstall.
	 * @param bool   $blocking     Wait for HTTP response (recommended for install/uninstall).
	 * @return bool True when the HTTP layer accepted the request (2xx or transport OK).
	 */
	public static function report( string $event_type, bool $blocking = false ): bool {
		if ( ! in_array( $event_type, array( 'install', 'uninstall' ), true ) ) {
			return false;
		}

		$endpoint = self::get_endpoint();
		if ( '' === $endpoint ) {
			return false;
		}

		$api_key = self::get_api_key();
		if ( '' === $api_key ) {
			return false;
		}

		$plugin_slug = self::detect_plugin_slug();

		$body = array(
			'event_type'     => $event_type,
			'plugin_slug'    => $plugin_slug,
			'plugin_version' => defined( 'STO_VERSION' ) ? STO_VERSION : '',
			'site_hash'      => self::site_hash(),
			'site_url'       => home_url( '/' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'reported_at'    => gmdate( 'c' ),
		);

		$args = array(
			'timeout'  => $blocking ? 8 : 3,
			'blocking' => $blocking,
			'headers'  => array(
				'Content-Type'       => 'application/json',
				'X-Topten-Track-Key' => $api_key,
			),
			'body'     => wp_json_encode( $body ),
		);

		/**
		 * Adjust remote POST args before reporting install/uninstall.
		 *
		 * @param array<string, mixed> $args HTTP args.
		 * @param string               $event_type Event slug.
		 * @param array<string, mixed> $body JSON body.
		 */
		$args = apply_filters( 'sto_installation_tracker_request_args', $args, $event_type, $body );

		$response = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		return $status_code >= 200 && $status_code < 300;
	}

	private static function get_endpoint(): string {
		if ( defined( 'STO_INSTALLATION_TRACKER_ENDPOINT' ) && is_string( STO_INSTALLATION_TRACKER_ENDPOINT ) ) {
			$constant_endpoint = trim( STO_INSTALLATION_TRACKER_ENDPOINT );
			if ( '' !== $constant_endpoint ) {
				return esc_url_raw( $constant_endpoint );
			}
		}

		$endpoint = apply_filters( 'sto_installation_tracker_endpoint', '' );

		return is_string( $endpoint ) ? esc_url_raw( $endpoint ) : '';
	}

	private static function get_api_key(): string {
		if ( defined( 'STO_INSTALLATION_TRACKER_API_KEY' ) && is_string( STO_INSTALLATION_TRACKER_API_KEY ) ) {
			$constant_key = trim( STO_INSTALLATION_TRACKER_API_KEY );
			if ( '' !== $constant_key ) {
				return sanitize_text_field( $constant_key );
			}
		}

		$api_key = apply_filters( 'sto_installation_tracker_api_key', '' );

		return is_string( $api_key ) ? sanitize_text_field( $api_key ) : '';
	}

	private static function detect_plugin_slug(): string {
		if ( defined( 'STO_FILE' ) ) {
			$plugin_basename = plugin_basename( STO_FILE );
			$plugin_directory  = dirname( $plugin_basename );
			if ( is_string( $plugin_directory ) && '.' !== $plugin_directory && '' !== $plugin_directory ) {
				return sanitize_key( $plugin_directory );
			}
		}

		if ( defined( 'STO_PLUGIN_SLUG' ) && is_string( STO_PLUGIN_SLUG ) && '' !== STO_PLUGIN_SLUG ) {
			return sanitize_key( STO_PLUGIN_SLUG );
		}

		return 'topten-simple-theme-options';
	}

	private static function site_hash(): string {
		$site_url = home_url( '/' );
		$salt     = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'sto-track';

		return hash_hmac( 'sha256', strtolower( untrailingslashit( $site_url ) ), $salt );
	}
}
