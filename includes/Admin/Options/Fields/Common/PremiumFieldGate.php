<?php
/**
 * Freemius premium field gating — locked placeholder in the field body when Pro is inactive.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Options\Fields\Common;

use SimpleThemeOptions\Admin\Options\Fields\Group\Group;

defined( 'ABSPATH' ) || exit;

/**
 * Premium-only STO field types (see project rules).
 */
final class PremiumFieldGate {

	/**
	 * Declarative `type` / Group node `kind` values that require Pro.
	 *
	 * @var array<int, string>
	 */
	public const PREMIUM_FIELD_TYPES = array(
		'dynamic_object',
		'advanced_repeater',
		'google_map',
		'code_editor',
		'tabs',
		'accordion',
		'group',
	);

	/** @var string */
	private static $render_section_slug = '';

	/**
	 * Reset per-section render scope (call before rendering a leaf section).
	 */
	public static function begin_section_render( string $section_slug ): void {
		self::$render_section_slug = sanitize_key( $section_slug );
	}

	/**
	 * Whether the current site may use premium field UI (Freemius).
	 */
	public static function can_use_premium(): bool {
		try {
			if ( function_exists( 'topten_sto' ) ) {
				$freemius = topten_sto();
				if ( is_object( $freemius ) && method_exists( $freemius, 'can_use_premium_code__premium_only' ) ) {
					return (bool) $freemius->can_use_premium_code__premium_only();
				}
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}

		/**
		 * Local development override when Freemius is not initialized.
		 *
		 * @param bool $unlocked Default false.
		 */
		return (bool) apply_filters( 'sto_premium_dev_unlock', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
	}

	public static function is_locked(): bool {
		return ! self::can_use_premium();
	}

	/**
	 * Whether any root group panels are registered for a leaf section slug.
	 */
	public static function section_has_registered_groups( string $section_slug ): bool {
		if ( ! class_exists( Group::class ) ) {
			return false;
		}

		return Group::instance()->section_has_groups( $section_slug );
	}

	/**
	 * @param string $field_type Field `type` or group node `kind`.
	 */
	public static function is_premium_field_type( string $field_type ): bool {
		$field_type = sanitize_key( $field_type );

		return $field_type !== '' && in_array( $field_type, self::PREMIUM_FIELD_TYPES, true );
	}

	/**
	 * Human label for a premium field type slug (for locked-state UI).
	 *
	 * @param string $field_type Field `type` or group node `kind`.
	 */
	public static function get_field_type_label( string $field_type ): string {
		$field_type = sanitize_key( $field_type );

		$labels = array(
			'dynamic_object'    => __( 'Dynamic object', 'topten-simple-theme-options' ),
			'advanced_repeater' => __( 'Advanced repeater', 'topten-simple-theme-options' ),
			'google_map'        => __( 'Google map', 'topten-simple-theme-options' ),
			'code_editor'       => __( 'Code editor', 'topten-simple-theme-options' ),
			'rich_modern_editor' => __( 'Rich modern editor', 'topten-simple-theme-options' ),
			'tabs'              => __( 'Tabs', 'topten-simple-theme-options' ),
			'accordion'         => __( 'Accordion', 'topten-simple-theme-options' ),
			'group'             => __( 'Group', 'topten-simple-theme-options' ),
		);

		if ( isset( $labels[ $field_type ] ) ) {
			return $labels[ $field_type ];
		}

		if ( $field_type === '' ) {
			return '';
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $field_type ) );
	}

	/**
	 * When locked, renders the gradient upsell card and returns true (skip real controls).
	 *
	 * @param string $field_label Admin-facing field title.
	 * @param string $field_type  Field type slug (see PREMIUM_FIELD_TYPES).
	 */
	public static function render_controls_or_locked_placeholder( string $field_label = '', string $field_type = '' ): bool {
		if ( ! self::is_locked() ) {
			return false;
		}

		self::render_locked_body( $field_label, $field_type );

		return true;
	}

	/**
	 * Gradient card + upgrade CTA (field body area — below title, above description).
	 *
	 * @param string $field_label Admin-facing field title.
	 * @param string $field_type  Field type slug.
	 */
	public static function render_locked_body( string $field_label = '', string $field_type = '' ): void {
		$upgrade_url = self::get_upgrade_url();
		$cta_label   = __( 'Upgrade to Pro', 'topten-simple-theme-options' );
		$type_label  = self::get_field_type_label( $field_type );
		$type_slug   = sanitize_key( $field_type );
		?>
		<div class="sto-premium-locked" data-sto-premium-locked="1" role="region" aria-label="<?php echo esc_attr__( 'Premium feature', 'topten-simple-theme-options' ); ?>">
			<div class="sto-premium-locked__card">
				<p class="sto-premium-locked__badge"><?php echo esc_html__( 'Premium', 'topten-simple-theme-options' ); ?></p>
				<?php if ( $type_label !== '' ) : ?>
					<p
						class="sto-premium-locked__field-type"
						<?php echo $type_slug !== '' ? ' data-sto-premium-field-type="' . esc_attr( $type_slug ) . '"' : ''; ?>
					>
						<?php
						printf(
							/* translators: %s: premium field type label, e.g. Dynamic object */
							esc_html__( 'Field type: %s', 'topten-simple-theme-options' ),
							esc_html( $type_label )
						);
						?>
					</p>
				<?php endif; ?>
				<p class="sto-premium-locked__message">
					<?php echo esc_html__( 'This control is part of Topten Simple Theme Options Pro. Upgrade to edit this field and unlock all premium field types.', 'topten-simple-theme-options' ); ?>
				</p>
				<a class="sto-premium-locked__cta" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( $cta_label ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Whether to show the full-width upgrade banner above Theme Settings panels.
	 */
	public static function should_show_panel_banner(): bool {
		if ( self::can_use_premium() ) {
			return false;
		}

		/**
		 * @param bool $show Default true when Pro is inactive.
		 */
		return (bool) apply_filters( 'sto_show_premium_panel_banner', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
	}

	/**
	 * Full-width upgrade alert above the panel title row (Theme Settings root screens only).
	 *
	 * @param string $panel_heading Registered menu label (e.g. UAEBattery).
	 */
	public static function render_panel_banner( string $panel_heading = '' ): void {
		if ( ! self::should_show_panel_banner() ) {
			return;
		}

		$upgrade_url   = self::get_upgrade_url();
		$panel_heading = trim( $panel_heading );
		$headline      = $panel_heading !== ''
			? sprintf(
				/* translators: %s: Theme Settings menu title, e.g. UAEBattery */
				__( 'Unlock %s with Topten Pro', 'topten-simple-theme-options' ),
				$panel_heading
			)
			: __( 'Unlock Topten Simple Theme Options Pro', 'topten-simple-theme-options' );

		$cta_label = __( 'Purchase Premium', 'topten-simple-theme-options' );
		?>
		<div
			class="sto-premium-panel-banner"
			role="region"
			aria-label="<?php echo esc_attr__( 'Upgrade to Premium', 'topten-simple-theme-options' ); ?>"
			data-sto-premium-panel-banner="1"
		>
			<div class="sto-premium-panel-banner__glow" aria-hidden="true"></div>
			<div class="sto-premium-panel-banner__inner">
				<div class="sto-premium-panel-banner__copy">
					<p class="sto-premium-panel-banner__eyebrow">
						<i class="fa-light fa-sparkles sto-premium-panel-banner__eyebrow-icon" aria-hidden="true"></i>
						<?php echo esc_html__( 'Premium', 'topten-simple-theme-options' ); ?>
					</p>
					<h2 class="sto-premium-panel-banner__title"><?php echo esc_html( $headline ); ?></h2>
					<p class="sto-premium-panel-banner__lead">
						<?php echo esc_html__( 'Upgrade to edit premium field types, nested groups, repeaters, code editors, and every Pro control across Theme Settings.', 'topten-simple-theme-options' ); ?>
					</p>
					<ul class="sto-premium-panel-banner__features">
						<li><?php echo esc_html__( 'Groups & nested panels', 'topten-simple-theme-options' ); ?></li>
						<li><?php echo esc_html__( 'Advanced repeater', 'topten-simple-theme-options' ); ?></li>
						<li><?php echo esc_html__( 'Code editor & dynamic objects', 'topten-simple-theme-options' ); ?></li>
					</ul>
				</div>
				<div class="sto-premium-panel-banner__actions">
					<a
						class="sto-premium-panel-banner__cta"
						href="<?php echo esc_url( $upgrade_url ); ?>"
						target="_blank"
						rel="noopener noreferrer"
					>
						<?php echo esc_html( $cta_label ); ?>
						<i class="fa-light fa-arrow-up-right-from-square sto-premium-panel-banner__cta-icon" aria-hidden="true"></i>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Gradient “Upgrade to Pro” row at the bottom of the in-page Theme Settings sidebar.
	 */
	public static function render_sidebar_upgrade_cta(): void {
		if ( self::can_use_premium() ) {
			return;
		}

		$upgrade_url = self::get_upgrade_url();
		if ( $upgrade_url === '' || $upgrade_url === '#' ) {
			return;
		}

		$cta_label = __( 'Upgrade to Pro', 'topten-simple-theme-options' );
		?>
		<li class="sto-option-panel-sidebar-item sto-option-panel-sidebar-item--upgrade-cta">
			<a
				class="sto-option-panel-sidebar-item-link sto-upgrade-cta"
				href="<?php echo esc_url( $upgrade_url ); ?>"
				data-sto-skip-spa-nav="1"
			>
				<i class="sto-option-panel-icon fa-light fa-rocket-launch" aria-hidden="true"></i>
				<span class="sto-option-panel-sidebar-item-title"><?php echo esc_html( $cta_label ); ?></span>
			</a>
		</li>
		<?php
	}

	public static function get_upgrade_url(): string {
		if ( function_exists( 'topten_sto' ) ) {
			$freemius = topten_sto();
			if ( is_object( $freemius ) && method_exists( $freemius, 'pricing_url' ) ) {
				$url = (string) $freemius->pricing_url();
				if ( $url !== '' && $url !== '#' ) {
					return $url;
				}
			}
			if ( is_object( $freemius ) && method_exists( $freemius, 'get_upgrade_url' ) ) {
				$url = (string) $freemius->get_upgrade_url();
				if ( $url !== '' && $url !== '#' ) {
					return $url;
				}
			}
		}

		$pricing_slug = class_exists( \SimpleThemeOptions\Admin\Freemius\StoFreemiusPricing::class )
			? \SimpleThemeOptions\Admin\Freemius\StoFreemiusPricing::get_pricing_admin_page_slug()
			: '';
		if ( $pricing_slug !== '' ) {
			return (string) admin_url( 'admin.php?page=' . rawurlencode( $pricing_slug ) );
		}

		/**
		 * @param string $url Fallback when Freemius URLs are unavailable.
		 */
		return (string) apply_filters( 'sto_premium_upgrade_url', '#' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
	}
}
