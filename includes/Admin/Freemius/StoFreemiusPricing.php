<?php
/**
 * STO-branded Freemius Plans & Pricing screen (wrap + theme overrides).
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Freemius;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps Freemius Pricing 2.0 app and applies STO design tokens via {@see get_inline_theme_css()}.
 */
final class StoFreemiusPricing {

	public static function boot(): void {
		if ( ! function_exists( 'topten_sto' ) ) {
			return;
		}

		$freemius = topten_sto();
		if ( ! is_object( $freemius ) || ! method_exists( $freemius, 'add_filter' ) ) {
			return;
		}

		$freemius->add_filter( 'templates/pricing.php', array( self::class, 'filter_pricing_template' ), 10, 1 );
		add_filter( 'admin_body_class', array( self::class, 'filter_admin_body_class' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ), 99 );
	}

	/**
	 * @param string $classes Space-separated admin body classes.
	 */
	public static function filter_admin_body_class( string $classes ): string {
		if ( self::is_pricing_admin_screen() ) {
			$classes .= ' sto-fs-pricing-screen';
		}

		return $classes;
	}

	/**
	 * @param string $default_markup Freemius pricing.php output.
	 */
	public static function filter_pricing_template( string $default_markup ): string {
		ob_start();
		self::render_shell_open();
		$shell_open = (string) ob_get_clean();

		return self::get_inline_theme_css() . $shell_open . $default_markup . '</div></div></div>';
	}

	public static function enqueue_assets( string $hook_suffix = '' ): void {
		unset( $hook_suffix );

		if ( ! is_admin() || ! self::is_pricing_admin_screen() ) {
			return;
		}

		$fontawesome = STO_URL . 'assets/admin/css/fontawesome.css';
		wp_enqueue_style(
			'sto-fontawesome',
			$fontawesome,
			array(),
			defined( 'STO_VERSION' ) ? STO_VERSION : '1.0.0'
		);

		$pricing_css = STO_PATH . 'assets/admin/css/sto-freemius-pricing.css';
		$version     = defined( 'STO_VERSION' ) ? STO_VERSION : '1.0.0';
		if ( is_readable( $pricing_css ) ) {
			$mtime = (int) filemtime( $pricing_css );
			if ( $mtime > 0 ) {
				$version .= '.' . $mtime;
			}
		}

		wp_enqueue_style(
			'sto-freemius-pricing',
			STO_URL . 'assets/admin/css/sto-freemius-pricing.css',
			array( 'sto-fontawesome', 'fs_common' ),
			$version
		);
	}

	public static function is_pricing_admin_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( $page === '' ) {
			return false;
		}

		$pricing_slug = self::get_pricing_admin_page_slug();
		if ( $pricing_slug !== '' ) {
			return $page === $pricing_slug;
		}

		return str_contains( $page, 'pricing' );
	}

	public static function get_pricing_admin_page_slug(): string {
		if ( ! function_exists( 'topten_sto' ) ) {
			return '';
		}

		$freemius = topten_sto();
		if ( ! is_object( $freemius ) || ! method_exists( $freemius, 'pricing_url' ) ) {
			return '';
		}

		$query_string = (string) wp_parse_url( (string) $freemius->pricing_url(), PHP_URL_QUERY );
		if ( $query_string === '' ) {
			return '';
		}

		parse_str( $query_string, $query_args );

		return isset( $query_args['page'] ) ? sanitize_key( (string) $query_args['page'] ) : '';
	}

	private static function render_shell_open(): void {
		$freemius     = function_exists( 'topten_sto' ) ? topten_sto() : null;
		$plugin_label = is_object( $freemius ) && method_exists( $freemius, 'get_plugin_name' )
			? (string) $freemius->get_plugin_name()
			: __( 'Topten Simple Theme Options', 'topten-simple-theme-options' );

		$contact_url = is_object( $freemius ) && method_exists( $freemius, 'contact_url' )
			? (string) $freemius->contact_url()
			: '';
		?>
		<div id="sto-fs-pricing-shell" class="sto-fs-pricing-shell">
			<div class="sto-option-panel-wrapper sto-option-panel-wrapper--freemius-pricing">
				<div class="sto-option-panel-head sto-fs-pricing-head">
					<span class="sto-fs-pricing-head__icon" aria-hidden="true">
						<i class="fa-light fa-bolt"></i>
					</span>
					<div class="sto-fs-pricing-head__copy">
						<p class="sto-fs-pricing-head__eyebrow"><?php echo esc_html( $plugin_label ); ?></p>
						<h1 class="sto-option-panel-title"><?php esc_html_e( 'Plans & Pricing', 'topten-simple-theme-options' ); ?></h1>
						<p class="sto-fs-pricing-head__lead">
							<?php esc_html_e( 'Unlock premium field types, groups, repeaters, and priority support. Choose a plan below — checkout is secure and takes just a few minutes.', 'topten-simple-theme-options' ); ?>
						</p>
					</div>
					<?php if ( $contact_url !== '' && $contact_url !== '#' ) : ?>
						<a class="sto-fs-pricing-head__link" href="<?php echo esc_url( $contact_url ); ?>">
							<?php esc_html_e( 'Questions? Contact us', 'topten-simple-theme-options' ); ?>
							<i class="fa-light fa-arrow-up-right-from-square" aria-hidden="true"></i>
						</a>
					<?php endif; ?>
				</div>
				<div class="sto-fs-pricing-body">
		<?php
	}

	private static function get_inline_theme_css(): string {
		return '<style id="sto-freemius-pricing-theme">'
			. '#sto-fs-pricing-shell #fs_pricing_app, .sto-fs-pricing-shell #fs_pricing_app {'
			. '--fs-ds-blue-50:#f5f3ff;--fs-ds-blue-100:#ede9fe;--fs-ds-blue-200:#ddd6fe;'
			. '--fs-ds-blue-300:#c4b5fd;--fs-ds-blue-400:#a78bfa;--fs-ds-blue-500:#8b5cf6;'
			. '--fs-ds-blue-600:#7c3aed;--fs-ds-blue-700:#6d28d9;--fs-ds-blue-800:#5b21b6;--fs-ds-blue-900:#4c1d95;'
			. '--fs-ds-theme-package-popular-background:linear-gradient(135deg,#6d28d9 0%,#be185d 55%,#ea580c 100%);'
			. '}'
			. '</style>';
	}
}
