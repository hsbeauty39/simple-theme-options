<?php
/**
 * Report install / uninstall and administrator telemetry to Topten Track Plugin Installation.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions;

defined( 'ABSPATH' ) || exit;

final class InstallationTracker {

	public const PENDING_OPTION = 'sto_installation_tracker_pending';

	private const HEARTBEAT_OPTION = 'sto_telemetry_last_heartbeat';

	private const PROFILE_OPTION = 'sto_telemetry_last_admin_profile';

	/**
	 * Register deferred reporting and ongoing telemetry hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'flush_pending_event' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_report_admin_profile' ), 50 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_report_heartbeat' ), 55 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_report_theme_settings_open' ), 12 );
		add_action( 'sto_theme_settings_saved', array( __CLASS__, 'on_theme_settings_saved' ), 10, 3 );
	}

	public static function on_activation(): void {
		self::queue_or_report( 'install' );
	}

	public static function on_uninstall(): void {
		self::report( 'uninstall', array(), true );
	}

	public static function flush_pending_event(): void {
		$pending = get_option( self::PENDING_OPTION, '' );
		if ( ! is_string( $pending ) || ! in_array( $pending, array( 'install', 'uninstall' ), true ) ) {
			return;
		}

		if ( ! self::can_report() ) {
			return;
		}

		if ( self::report( $pending, array(), true ) ) {
			delete_option( self::PENDING_OPTION );
		}
	}

	/**
	 * @param string $menu_page_slug Menu page slug.
	 * @param string $section_slug   Leaf section slug.
	 * @param int    $user_id        Saving user ID.
	 */
	public static function on_theme_settings_saved( string $menu_page_slug, string $section_slug, int $user_id ): void {
		unset( $user_id );

		self::report(
			'settings_save',
			array(
				'key'     => $section_slug,
				'section' => $section_slug,
				'menu'    => $menu_page_slug,
			)
		);
	}

	public static function maybe_report_theme_settings_open(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || ! self::can_report() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( '' === $page || ! self::is_sto_admin_page( $page ) ) {
			return;
		}

		$user_id   = get_current_user_id();
		$flag_key  = 'sto_ts_open_' . $user_id . '_' . md5( $page );
		if ( get_transient( $flag_key ) ) {
			return;
		}
		set_transient( $flag_key, 1, DAY_IN_SECONDS );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : '';

		self::report(
			'theme_settings_open',
			array(
				'key'     => $section,
				'section' => $section,
				'menu'    => $page,
			)
		);
	}

	public static function maybe_report_admin_profile(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || ! self::can_report() ) {
			return;
		}

		$last = (int) get_option( self::PROFILE_OPTION, 0 );
		if ( time() - $last < DAY_IN_SECONDS ) {
			return;
		}

		if ( self::report( 'admin_profile', array( 'key' => 'daily_sync' ) ) ) {
			update_option( self::PROFILE_OPTION, time(), false );
		}
	}

	public static function maybe_report_heartbeat(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || ! self::can_report() ) {
			return;
		}

		$last = (int) get_option( self::HEARTBEAT_OPTION, 0 );
		if ( time() - $last < DAY_IN_SECONDS ) {
			return;
		}

		if ( self::report( 'heartbeat', array( 'key' => 'daily' ) ) ) {
			update_option( self::HEARTBEAT_OPTION, time(), false );
		}
	}

	/**
	 * @param string               $event_type Event slug.
	 * @param array<string, mixed> $context    Optional context.
	 * @param bool                 $blocking   Blocking HTTP request.
	 */
	private static function queue_or_report( string $event_type, array $context = array(), bool $blocking = true ): void {
		if ( self::can_report() && self::report( $event_type, $context, $blocking ) ) {
			delete_option( self::PENDING_OPTION );
			return;
		}

		update_option( self::PENDING_OPTION, $event_type, false );
	}

	private static function can_report(): bool {
		return '' !== self::get_endpoint() && '' !== self::get_api_key();
	}

	/**
	 * @param string               $event_type Event slug.
	 * @param array<string, mixed> $context    Context payload.
	 * @param bool                 $blocking   Wait for HTTP response.
	 * @return bool
	 */
	public static function report( string $event_type, array $context = array(), bool $blocking = false ): bool {
		$endpoint = self::get_endpoint();
		if ( '' === $endpoint ) {
			return false;
		}

		$api_key = self::get_api_key();
		if ( '' === $api_key ) {
			return false;
		}

		$body = self::build_payload( $event_type, $context );

		$args = array(
			'timeout'  => $blocking ? 8 : 3,
			'blocking' => $blocking,
			'headers'  => array(
				'Content-Type'       => 'application/json',
				'X-Topten-Track-Key' => $api_key,
			),
			'body'     => wp_json_encode( $body ),
		);

		/** @var array<string, mixed> $args */
		$args = apply_filters( 'sto_installation_tracker_request_args', $args, $event_type, $body );

		$response = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		return $status_code >= 200 && $status_code < 300;
	}

	/**
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $context    Context.
	 * @return array<string, mixed>
	 */
	private static function build_payload( string $event_type, array $context ): array {
		$site_hash = self::site_hash();
		$actor     = self::collect_actor();

		return array(
			'event_type'      => $event_type,
			'plugin_slug'     => self::detect_plugin_slug(),
			'plugin_version'  => defined( 'STO_VERSION' ) ? STO_VERSION : '',
			'site'            => self::collect_site( $site_hash ),
			'administrators'  => self::collect_administrators( $site_hash ),
			'actor'           => $actor,
			'context'         => $context,
			'reported_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * @param string $site_hash Site hash.
	 * @return array<string, mixed>
	 */
	private static function collect_site( string $site_hash ): array {
		$theme = wp_get_theme();

		return array(
			'hash'                => $site_hash,
			'url'                 => home_url( '/' ),
			'title'               => get_bloginfo( 'name' ),
			'admin_email'         => sanitize_email( get_bloginfo( 'admin_email' ) ),
			'locale'              => get_locale(),
			'timezone'            => wp_timezone_string(),
			'is_multisite'        => is_multisite(),
			'active_theme'        => $theme->get( 'Name' ) ?: $theme->get_stylesheet(),
			'woocommerce_active'  => class_exists( 'WooCommerce' ),
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
		);
	}

	/**
	 * @param string $site_hash Site hash.
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_administrators( string $site_hash ): array {
		$admin_users = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 25,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$rows = array();
		foreach ( $admin_users as $admin_user ) {
			if ( ! $admin_user instanceof \WP_User ) {
				continue;
			}
			$normalized = self::normalize_user_row( $admin_user, $site_hash );
			if ( $normalized ) {
				$rows[] = $normalized;
			}
		}

		return $rows;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function collect_actor(): ?array {
		$current_user = wp_get_current_user();
		if ( ! $current_user instanceof \WP_User || ! $current_user->exists() ) {
			return null;
		}

		return self::normalize_user_row( $current_user, self::site_hash() );
	}

	/**
	 * @param \WP_User $user      User object.
	 * @param string   $site_hash Site hash.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_user_row( \WP_User $user, string $site_hash ): ?array {
		$email = sanitize_email( $user->user_email );
		if ( '' === $email ) {
			return null;
		}

		return array(
			'hash'          => hash_hmac( 'sha256', strtolower( $email ), $site_hash ),
			'wp_user_id'    => (int) $user->ID,
			'user_login'    => $user->user_login,
			'user_email'    => $email,
			'display_name'  => $user->display_name,
			'first_name'    => get_user_meta( $user->ID, 'first_name', true ),
			'last_name'     => get_user_meta( $user->ID, 'last_name', true ),
			'roles'         => array_values( $user->roles ),
			'locale'        => get_user_meta( $user->ID, 'locale', true ),
			'registered_at' => $user->user_registered,
		);
	}

	private static function is_sto_admin_page( string $page ): bool {
		if ( ! class_exists( Admin\Options\Menu::class ) ) {
			return false;
		}

		$menu = Admin\Options\Menu::instance();
		return in_array( $page, $menu->get_registered_menu_slugs(), true );
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
