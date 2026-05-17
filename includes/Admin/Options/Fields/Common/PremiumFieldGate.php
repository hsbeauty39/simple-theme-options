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
		return (bool) apply_filters( 'sto_premium_dev_unlock', false );
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
			'dynamic_object'    => __( 'Dynamic object', 'simple-theme-options' ),
			'advanced_repeater' => __( 'Advanced repeater', 'simple-theme-options' ),
			'google_map'        => __( 'Google map', 'simple-theme-options' ),
			'code_editor'       => __( 'Code editor', 'simple-theme-options' ),
			'tabs'              => __( 'Tabs', 'simple-theme-options' ),
			'accordion'         => __( 'Accordion', 'simple-theme-options' ),
			'group'             => __( 'Group', 'simple-theme-options' ),
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
		$cta_label   = __( 'Upgrade to Pro', 'simple-theme-options' );
		$type_label  = self::get_field_type_label( $field_type );
		$type_slug   = sanitize_key( $field_type );
		?>
		<div class="sto-premium-locked" data-sto-premium-locked="1" role="region" aria-label="<?php echo esc_attr__( 'Premium feature', 'simple-theme-options' ); ?>">
			<div class="sto-premium-locked__card">
				<p class="sto-premium-locked__badge"><?php echo esc_html__( 'Premium', 'simple-theme-options' ); ?></p>
				<?php if ( $type_label !== '' ) : ?>
					<p
						class="sto-premium-locked__field-type"
						<?php echo $type_slug !== '' ? ' data-sto-premium-field-type="' . esc_attr( $type_slug ) . '"' : ''; ?>
					>
						<?php
						printf(
							/* translators: %s: premium field type label, e.g. Dynamic object */
							esc_html__( 'Field type: %s', 'simple-theme-options' ),
							esc_html( $type_label )
						);
						?>
					</p>
				<?php endif; ?>
				<p class="sto-premium-locked__message">
					<?php echo esc_html__( 'This control is part of Simple Theme Options Pro. Upgrade to edit this field and unlock all premium field types.', 'simple-theme-options' ); ?>
				</p>
				<a class="sto-premium-locked__cta" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( $cta_label ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	public static function get_upgrade_url(): string {
		if ( function_exists( 'topten_sto' ) ) {
			$freemius = topten_sto();
			if ( is_object( $freemius ) && method_exists( $freemius, 'get_upgrade_url' ) ) {
				$url = (string) $freemius->get_upgrade_url();
				if ( $url !== '' ) {
					return $url;
				}
			}
			if ( is_object( $freemius ) && method_exists( $freemius, 'pricing_url' ) ) {
				$url = (string) $freemius->pricing_url();
				if ( $url !== '' ) {
					return $url;
				}
			}
		}

		/**
		 * @param string $url Fallback when Freemius URLs are unavailable.
		 */
		return (string) apply_filters( 'sto_premium_upgrade_url', '#' );
	}
}
