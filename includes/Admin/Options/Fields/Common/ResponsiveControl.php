<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: breakpoint tabs + pane wrappers for responsive option rows.
 *
 * Tab buttons use a short native **`title`** on hover (e.g. **XXL**, **Tablet**).
 */
final class ResponsiveControl {

	/**
	 * Buffered toolbar HTML for use in {@see FieldTitle::render_heading()} (right of title).
	 *
	 * @param array<int, string> $breakpoints
	 * @param string             $unique_suffix Field id — keeps `id` attributes unique per row.
	 * @return string
	 */
	public static function toolbar_markup( array $breakpoints, $unique_suffix = '' ) {
		if ( empty( $breakpoints ) ) {
			return '';
		}
		ob_start();
		self::render_toolbar( $breakpoints, $unique_suffix );

		return (string) ob_get_clean();
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @param string               $unique_suffix Sanitized field id for unique control ids.
	 */
	public static function render_toolbar( array $breakpoints, $unique_suffix = '' ) {
		if ( empty( $breakpoints ) ) {
			return;
		}
		$sfx    = $unique_suffix !== '' ? sanitize_key( (string) $unique_suffix ) . '-' : '';
		$labels = self::aria_labels();
		$icons  = self::icon_classes();
		?>
		<div class="sto-responsive__toolbar sto-responsive__toolbar--icons" role="tablist" aria-label="<?php esc_attr_e( 'Responsive breakpoints', 'topten-simple-theme-options' ); ?>">
			<?php foreach ( $breakpoints as $i => $bp ) : ?>
				<?php
				$bp        = sanitize_key( (string) $bp );
				$is_first  = ( 0 === (int) $i );
				$aria      = isset( $labels[ $bp ] ) ? $labels[ $bp ] : $bp;
				$short     = self::tab_short_title( $bp );
				$icon_cls  = isset( $icons[ $bp ] ) ? $icons[ $bp ] : 'fa-light fa-display';
				$tab_id    = 'sto-rsp-tab-' . $sfx . $bp;
				?>
				<button
					type="button"
					class="sto-responsive__tab<?php echo $is_first ? ' sto-is-active' : ''; ?>"
					role="tab"
					data-sto-responsive-tab="<?php echo esc_attr( $bp ); ?>"
					aria-selected="<?php echo $is_first ? 'true' : 'false'; ?>"
					aria-label="<?php echo esc_attr( $aria ); ?>"
					title="<?php echo esc_attr( $short ); ?>"
					id="<?php echo esc_attr( $tab_id ); ?>"
				>
					<i class="<?php echo esc_attr( $icon_cls ); ?>" aria-hidden="true"></i>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, string> $breakpoints
	 */
	public static function render_panes_open() {
		?>
		<div class="sto-responsive__panes">
		<?php
	}

	/**
	 * @param string $bp
	 * @param bool   $visible
	 */
	public static function render_pane_start( $bp, $visible ) {
		$bp = sanitize_key( (string) $bp );
		?>
			<div
				class="sto-responsive__pane<?php echo $visible ? ' sto-is-active' : ''; ?>"
				role="tabpanel"
				data-sto-responsive-pane="<?php echo esc_attr( $bp ); ?>"
				<?php echo $visible ? '' : ' hidden'; ?>
			>
		<?php
	}

	public static function render_pane_end() {
		?>
			</div>
		<?php
	}

	public static function render_panes_close() {
		?>
		</div>
		<?php
	}

	/**
	 * Font Awesome **fa-light** classes (Theme Settings already loads FA).
	 *
	 * @return array<string, string>
	 */
	private static function icon_classes() {
		return array(
			'xxl'   => 'fa-light fa-display',
			'xl'    => 'fa-light fa-desktop',
			'lg'    => 'fa-light fa-laptop',
			'md'    => 'fa-light fa-tablet-screen-button',
			'sm'    => 'fa-light fa-tablet',
			'xs'    => 'fa-light fa-mobile-screen',
			'mobile' => 'fa-light fa-mobile-screen-button',
		);
	}

	/**
	 * Accessible names for icon-only tabs.
	 *
	 * @return array<string, string>
	 */
	private static function aria_labels() {
		return array(
			'xxl'    => __( 'Desktop (PC)', 'topten-simple-theme-options' ),
			'xl'     => __( 'Extra large screens', 'topten-simple-theme-options' ),
			'lg'     => __( 'Large screens', 'topten-simple-theme-options' ),
			'md'     => __( 'Tablet', 'topten-simple-theme-options' ),
			'sm'     => __( 'Small screens', 'topten-simple-theme-options' ),
			'xs'     => __( 'Extra small screens', 'topten-simple-theme-options' ),
			'mobile' => __( 'Mobile', 'topten-simple-theme-options' ),
		);
	}

	/**
	 * Short label for the native **`title`** hover hint (one or two words / acronym).
	 *
	 * @param string $bp Breakpoint slug.
	 */
	private static function tab_short_title( $bp ) {
		$bp = sanitize_key( (string) $bp );
		$map = array(
			'xxl'    => __( 'XXL', 'topten-simple-theme-options' ),
			'xl'     => __( 'XL', 'topten-simple-theme-options' ),
			'lg'     => __( 'LG', 'topten-simple-theme-options' ),
			'md'     => __( 'Tablet', 'topten-simple-theme-options' ),
			'sm'     => __( 'SM', 'topten-simple-theme-options' ),
			'xs'     => __( 'XS', 'topten-simple-theme-options' ),
			'mobile' => __( 'Mobile', 'topten-simple-theme-options' ),
		);

		return isset( $map[ $bp ] ) ? (string) $map[ $bp ] : strtoupper( $bp );
	}
}
