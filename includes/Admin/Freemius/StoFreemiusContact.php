<?php
/**
 * STO-branded Freemius Contact Us screen (replaces default iframe embed).
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Freemius;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces {@see fs_get_template()} `contact.php` output via Freemius filter `templates/contact.php`.
 */
final class StoFreemiusContact {

	public static function boot(): void {
		if ( ! function_exists( 'topten_sto' ) ) {
			return;
		}

		$freemius = topten_sto();
		if ( ! is_object( $freemius ) || ! method_exists( $freemius, 'add_filter' ) ) {
			return;
		}

		$freemius->add_filter( 'templates/contact.php', array( self::class, 'filter_contact_template' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ), 99 );
	}

	/**
	 * @param string $default_markup Freemius iframe template HTML.
	 */
	public static function filter_contact_template( string $default_markup ): string {
		unset( $default_markup );

		ob_start();
		$template = STO_INCLUDES . 'Admin/Freemius/templates/sto-contact.php';
		if ( is_readable( $template ) ) {
			include $template;
		}

		return (string) ob_get_clean();
	}

	public static function enqueue_assets( string $hook_suffix = '' ): void {
		unset( $hook_suffix );

		if ( ! is_admin() || ! self::is_contact_admin_screen() ) {
			return;
		}

		$fontawesome = STO_URL . 'assets/admin/css/fontawesome.css';
		wp_enqueue_style(
			'sto-fontawesome',
			$fontawesome,
			array(),
			defined( 'STO_VERSION' ) ? STO_VERSION : '1.0.0'
		);

		$contact_css = STO_URL . 'assets/admin/css/sto-freemius-contact.css';
		$version     = defined( 'STO_VERSION' ) ? STO_VERSION : '1.0.0';
		$path        = STO_PATH . 'assets/admin/css/sto-freemius-contact.css';
		if ( is_readable( $path ) ) {
			$mtime = (int) filemtime( $path );
			if ( $mtime > 0 ) {
				$version .= '.' . $mtime;
			}
		}

		wp_enqueue_style(
			'sto-freemius-contact',
			$contact_css,
			array( 'sto-fontawesome' ),
			$version
		);
	}

	public static function is_contact_admin_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( $page === '' ) {
			return false;
		}

		$contact_slug = self::get_contact_admin_page_slug();
		if ( $contact_slug !== '' ) {
			return $page === $contact_slug;
		}

		return str_contains( $page, 'contact' );
	}

	public static function get_contact_admin_page_slug(): string {
		if ( ! function_exists( 'topten_sto' ) ) {
			return '';
		}

		$freemius = topten_sto();
		if ( ! is_object( $freemius ) || ! method_exists( $freemius, 'contact_url' ) ) {
			return '';
		}

		$query_string = (string) wp_parse_url( (string) $freemius->contact_url(), PHP_URL_QUERY );
		if ( $query_string === '' ) {
			return '';
		}

		parse_str( $query_string, $query_args );

		return isset( $query_args['page'] ) ? sanitize_key( (string) $query_args['page'] ) : '';
	}

	/**
	 * Standalone Freemius contact form URL (opens on freemius.com — full form, no wp-admin iframe).
	 *
	 * @param string $topic Optional Freemius topic slug (e.g. `bug`, `technical_support`).
	 */
	public static function get_standalone_contact_url( string $topic = '' ): string {
		if ( ! function_exists( 'topten_sto' ) || ! class_exists( '\FS_Contact_Form_Manager' ) ) {
			return '#';
		}

		$freemius = topten_sto();
		if ( ! is_object( $freemius ) ) {
			return '#';
		}

		$query_params = \FS_Contact_Form_Manager::instance()->get_query_params( $freemius );
		$topic        = sanitize_key( $topic );
		if ( $topic !== '' ) {
			$query_params['topic'] = $topic;
		}

		$query_params['is_standalone'] = 'true';
		$query_params['parent_url']    = admin_url( add_query_arg( '', '' ) );

		if ( ! defined( 'WP_FS__ADDRESS' ) ) {
			return '#';
		}

		return WP_FS__ADDRESS . '/contact/?' . http_build_query( $query_params );
	}
}
