<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Color;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Color {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $fields_by_section = array();

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private $fields_by_id = array();

	/**
	 * @var array<string, true>
	 */
	private $registered_ids = array();

	protected function init() {
		// After Select (18), before Typography (20) and Group (21).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::COLOR, 2 );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function register( $field ) {
		$instance = static::instance();
		FieldRegistrationDeferral::defer_or_run(
			function () use ( $instance, $field ) {
				$instance->register_field_config( $field );
			}
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $fields
	 */
	public static function register_many( $fields ) {
		$instance = static::instance();
		FieldRegistrationDeferral::defer_or_run(
			function () use ( $instance, $fields ) {
				if ( ! is_array( $fields ) ) {
					return;
				}

				foreach ( $fields as $f ) {
					$instance->register_field_config( $f );
				}
			}
		);
	}

	/**
	 * @param mixed $field
	 */
	private function register_field_config( $field ): void {
		if ( ! is_array( $field ) ) {
			return;
		}

		$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
		$field_id     = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';

		if ( ! $section_slug || ! $field_id ) {
			return;
		}

		$field['section_slug'] = $section_slug;
		$field['id']           = $field_id;
		$field['title']        = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']  = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['default']      = isset( $field['default'] ) ? (string) $field['default'] : '';
		$field['class']        = isset( $field['class'] ) ? (string) $field['class'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']     = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']        = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['palettes']     = isset( $field['palettes'] ) && is_array( $field['palettes'] ) ? $field['palettes'] : array();
		$palette_ui_raw       = isset( $field['palette_ui'] ) ? sanitize_key( (string) $field['palette_ui'] ) : 'classic';
		$palette_ui_allowed   = array( 'classic', 'advanced', 'advanced-circles', 'advanced-dense' );
		$field['palette_ui']  = in_array( $palette_ui_raw, $palette_ui_allowed, true ) ? $palette_ui_raw : 'classic';
		if ( ! array_key_exists( 'alpha', $field ) ) {
			$field['alpha'] = true;
		} else {
			$field['alpha'] = (bool) $field['alpha'];
		}

		$bps = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]           = true;
		$this->fields_by_id[ $field_id ]             = $field;
	}

	/**
	 * Option keys registered for a leaf section (standalone + group-inner rows).
	 *
	 * @param string $section_slug
	 * @return array<int, string>
	 */
	public function registry_get_field_ids_for_section( $section_slug ) {
		$section_slug = sanitize_key( (string) $section_slug );
		if ( $section_slug === '' || empty( $this->fields_by_section[ $section_slug ] ) ) {
			return array();
		}
		$ids = array();
		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			$id = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			if ( $id !== '' ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	public function registry_is_registered_field_id( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );

		return $field_id && ! empty( $this->registered_ids[ $field_id ] );
	}

	/**
	 * @param mixed $raw Posted value (scalar or breakpoint map).
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return '';
		}
		$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;

		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->sanitize_stored_value( is_scalar( $cell ) ? (string) $cell : '' );
			}

			return $out;
		}

		return $this->sanitize_stored_value( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) );
	}

	/**
	 * @param string $raw
	 * @return string Hex #rrggbb, rgb()/rgba(), or empty string
	 */
	public function sanitize_stored_value( $raw ) {
		$value = is_string( $raw ) ? trim( $raw ) : '';
		if ( $value === '' || $value === '#' ) {
			return '';
		}

		// rgba( n, n, n, a ) or rgb( n, n, n )
		if ( preg_match( '/^rgba?\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*(?:,\s*([0-9]*\.?[0-9]+)\s*)?\)$/i', $value, $m ) ) {
			$r = max( 0, min( 255, (int) $m[1] ) );
			$g = max( 0, min( 255, (int) $m[2] ) );
			$b = max( 0, min( 255, (int) $m[3] ) );
			$a = isset( $m[4] ) && $m[4] !== '' ? (float) $m[4] : 1.0;
			$a = max( 0.0, min( 1.0, $a ) );

			if ( $a >= 0.999 ) {
				return sprintf( 'rgb(%d,%d,%d)', $r, $g, $b );
			}

			$as = (string) round( $a, 3 );
			$as = rtrim( rtrim( $as, '0' ), '.' );

			return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, $as === '' ? '0' : $as );
		}

		// #RRGGBBAA (wp-color-picker-alpha / octohex)
		if ( preg_match( '/^#([0-9a-f]{8})$/i', $value, $m ) ) {
			$full  = $m[1];
			$rgb   = substr( $full, 0, 6 );
			$alpha = hexdec( substr( $full, 6, 2 ) );
			$a     = max( 0.0, min( 1.0, $alpha / 255 ) );
			$r     = hexdec( substr( $rgb, 0, 2 ) );
			$g     = hexdec( substr( $rgb, 2, 2 ) );
			$b     = hexdec( substr( $rgb, 4, 2 ) );
			if ( $a >= 0.999 ) {
				return '#' . strtolower( $rgb );
			}
			$as = (string) round( $a, 3 );
			$as = rtrim( rtrim( $as, '0' ), '.' );

			return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, $as === '' ? '0' : $as );
		}

		if ( preg_match( '/^#([0-9a-f]{3})$/i', $value, $m ) ) {
			$h     = $m[1];
			$value = '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}

		$san = sanitize_hex_color( $value );

		return $san ? strtolower( $san ) : '';
	}

	/**
	 * @param string $section_slug
	 * @param array<string, mixed> $section
	 */
	public function render_section_fields( $section_slug, $section ) {
		$section_slug = sanitize_key( (string) $section_slug );
		if ( empty( $this->fields_by_section[ $section_slug ] ) ) {
			return;
		}

		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			if ( ! empty( $field['group'] ) || ResponsiveConfig::is_composite_inner_field( $field ) ) {
				continue;
			}
			if ( ! FieldRenderGate::should_render_field( $field ) ) {
				continue;
			}
			$this->render_field_markup( $field, 'default' );
		}
	}

	/**
	 * @param string $section_slug
	 * @param string $field_id
	 * @return array<string, mixed>|null
	 */
	public function registry_get_field( $section_slug, $field_id ) {
		$section_slug = sanitize_key( (string) $section_slug );
		$field_id     = sanitize_key( (string) $field_id );

		if ( ! $section_slug || ! $field_id || empty( $this->fields_by_section[ $section_slug ] ) ) {
			return null;
		}

		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			if ( isset( $field['id'] ) && $field['id'] === $field_id ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public function registry_get_all_fields_for_search() {
		$out = array();

		foreach ( $this->fields_by_section as $section_slug => $fields ) {
			foreach ( $fields as $field ) {
				$title = isset( $field['title'] ) ? trim( (string) $field['title'] ) : '';
				if ( $title === '' ) {
					continue;
				}

				$out[] = array(
					'section_slug' => (string) $section_slug,
					'id'           => isset( $field['id'] ) ? (string) $field['id'] : '',
					'title'        => $title,
					'group'        => ! empty( $field['group'] ) ? (string) $field['group'] : '',
				);
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param 'default'|'group_inner' $context
	 */
	public function render_field_markup( $field, $context = 'default' ) {
		if ( ! is_array( $field ) ) {
			return;
		}

		$field_id       = $field['id'];
		$title          = $field['title'];
		$description    = $field['description'];
		$default_raw    = isset( $field['default'] ) ? (string) $field['default'] : '';
		$default_color  = $this->sanitize_stored_value( $default_raw );
		if ( $default_color === '' ) {
			$default_color = '#ffffff';
		}
		$use_alpha      = ! empty( $field['alpha'] );
		$wrapper_class  = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$input_class    = isset( $field['class'] ) ? (string) $field['class'] : '';
		$required       = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json  = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$palettes       = isset( $field['palettes'] ) && is_array( $field['palettes'] ) ? $field['palettes'] : array();
		$palettes_clean = array();
		foreach ( $palettes as $p ) {
			$ph = $this->sanitize_stored_value( (string) $p );
			if ( $ph !== '' ) {
				$palettes_clean[] = $ph;
			}
		}
		$palette_ui = isset( $field['palette_ui'] ) ? sanitize_key( (string) $field['palette_ui'] ) : 'classic';
		if ( 'classic' !== $palette_ui && empty( $palettes_clean ) ) {
			$palettes_clean = self::built_in_advanced_palettes();
		}
		$palettes_json = ! empty( $palettes_clean ) ? wp_json_encode( $palettes_clean ) : '';

		$breakpoints   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$bps_storage   = $breakpoints;
		$breakpoints   = ( $tabs_pane_bp !== '' && $bps_storage ) ? null : $bps_storage;

		$is_inner   = ( 'group_inner' === $context );
		$tooltip    = FieldTitle::get_tooltip_config( $field );

		$row_classes = array( 'sto-field-row', 'sto-field-row-color' );
		if ( $wrapper_class ) {
			$row_classes[] = $wrapper_class;
		}
		if ( $is_inner ) {
			$row_classes[] = 'sto-field-row--in-group';
		}
		if ( $tabs_pane_bp !== '' ) {
			$row_classes[] = 'sto-field-row--tabs-pane-slice';
		}
		if ( ! empty( $bps_storage ) && $tabs_pane_bp === '' ) {
			$row_classes[] = 'sto-field-row--responsive';
		}

		$eval_bp = ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT;
		if ( ! empty( $bps_storage ) && ! in_array( $eval_bp, $bps_storage, true ) ) {
			$eval_bp = (string) $bps_storage[0];
		}
		$toolbar_markup = ( $tabs_pane_bp === '' && ! empty( $bps_storage ) ) ? ResponsiveControl::toolbar_markup( $bps_storage, $field_id ) : '';
		?>
		<div
			id="<?php echo esc_attr( 'sto-field-' . $field_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
			data-sto-field-id="<?php echo esc_attr( $field_id ); ?>"
			<?php if ( $required_json ) : ?>
				data-sto-required="<?php echo esc_attr( $required_json ); ?>"
			<?php endif; ?>
			<?php if ( ! empty( $bps_storage ) && $tabs_pane_bp === '' ) : ?>
				data-sto-responsive="1"
				data-sto-active-bp="<?php echo esc_attr( (string) $bps_storage[0] ); ?>"
				data-sto-require-eval-bp="<?php echo esc_attr( $eval_bp ); ?>"
			<?php endif; ?>
		>
			<?php if ( $title || $toolbar_markup !== '' ) : ?>
				<?php FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_inner, $toolbar_markup ); ?>
			<?php endif; ?>

			<?php if ( $tabs_pane_bp !== '' && $bps_storage ) : ?>
				<?php
				$value_map = $this->get_value_map( $field_id, $default_color, $bps_storage );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $default_color;
				if ( $cur === '' ) {
					$cur = $default_color;
				}
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$input_id   = $field_id . '_' . $tabs_pane_bp;
				$this->render_color_control( $input_id, $input_name, $cur, $default_color, $use_alpha, $input_class, $palettes_json, $palette_ui );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $default_color, $bps_storage );
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$cur     = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $default_color;
						if ( $cur === '' ) {
							$cur = $default_color;
						}
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$input_id   = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_color_control( $input_id, $input_name, $cur, $default_color, $use_alpha, $input_class, $palettes_json, $palette_ui );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$current = $this->get_option_value( $field_id, $default_color );
				if ( $current === '' ) {
					$current = $default_color;
				}
				$input_name = 'sto_options[' . $field_id . ']';
				$this->render_color_control( $field_id, $input_name, $current, $default_color, $use_alpha, $input_class, $palettes_json, $palette_ui );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string $input_id   DOM id for the text input.
	 * @param string $input_name `name` attribute.
	 * @param string $current    Sanitized current color.
	 * @param string $default_color
	 * @param bool   $use_alpha
	 * @param string $input_class
	 * @param string $palettes_json JSON or empty.
	 * @param string $palette_ui    classic|advanced|advanced-circles|advanced-dense
	 */
	private function render_color_control( $input_id, $input_name, $current, $default_color, $use_alpha, $input_class, $palettes_json, $palette_ui = 'classic' ) {
		$palette_ui = sanitize_key( (string) $palette_ui );
		if ( ! in_array( $palette_ui, array( 'classic', 'advanced', 'advanced-circles', 'advanced-dense' ), true ) ) {
			$palette_ui = 'classic';
		}
		$wrap_classes = array( 'sto-color-wrap' );
		if ( $use_alpha ) {
			$wrap_classes[] = 'sto-color--alpha';
		}
		if ( 'classic' !== $palette_ui ) {
			$wrap_classes[] = 'sto-color-wrap--palette-ui';
			if ( 'advanced-circles' === $palette_ui ) {
				$wrap_classes[] = 'sto-color-wrap--palette-circles';
			} elseif ( 'advanced-dense' === $palette_ui ) {
				$wrap_classes[] = 'sto-color-wrap--palette-dense';
			} else {
				$wrap_classes[] = 'sto-color-wrap--palette-advanced';
			}
		}
		?>
			<div
				class="<?php echo esc_attr( implode( ' ', $wrap_classes ) ); ?>"
				<?php if ( 'classic' !== $palette_ui ) : ?>
					data-sto-palette-ui="<?php echo esc_attr( $palette_ui ); ?>"
				<?php endif; ?>
				<?php if ( $palettes_json ) : ?>
					data-sto-palettes="<?php echo esc_attr( $palettes_json ); ?>"
				<?php endif; ?>
			>
				<input
					type="text"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $input_name ); ?>"
					value="<?php echo esc_attr( $current ); ?>"
					class="sto-color-input <?php echo esc_attr( $input_class ); ?>"
					data-default-color="<?php echo esc_attr( $default_color ); ?>"
					data-sto-default="<?php echo esc_attr( $default_color ); ?>"
					<?php if ( $use_alpha ) : ?>
						data-alpha-enabled="true"
						data-type="full"
						data-alpha-custom-width="0"
					<?php endif; ?>
					autocomplete="off"
				/>
				<button type="button" class="sto-color-reset" aria-label="<?php esc_attr_e( 'Reset to default color', 'simple-theme-options' ); ?>" title="<?php esc_attr_e( 'Reset to default', 'simple-theme-options' ); ?>">
					<i class="fa-light fa-arrow-rotate-left" aria-hidden="true"></i>
				</button>
			</div>
		<?php
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, $default_color, array $breakpoints ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return ResponsiveConfig::coerce_map( null, $breakpoints, $default_color );
		}

		$stored = $saved_options[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$map = ResponsiveConfig::coerce_map( $stored, $breakpoints, $default_color );
			foreach ( $map as $bp => $val ) {
				$san = $this->sanitize_stored_value( (string) $val );
				$map[ $bp ] = $san !== '' ? $san : $default_color;
			}

			return $map;
		}

		$scalar = $this->sanitize_stored_value( is_scalar( $stored ) ? (string) $stored : '' );

		return ResponsiveConfig::coerce_map( null, $breakpoints, $scalar !== '' ? $scalar : $default_color );
	}

	/**
	 * @param string $field_id
	 * @param string $default_hex
	 * @return string
	 */
	private function get_option_value( $field_id, $default_color ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) ) {
			return $default_color;
		}

		if ( isset( $saved_options[ $field_id ] ) ) {
			$st = $saved_options[ $field_id ];
			if ( is_array( $st ) && ResponsiveConfig::is_breakpoint_value_map( $st ) ) {
				$v = $this->sanitize_stored_value( ResponsiveConfig::value_for_required_eval( $st ) );

				return $v !== '' ? $v : $default_color;
			}

			$v = $this->sanitize_stored_value( is_scalar( $st ) ? (string) $st : '' );

			return $v !== '' ? $v : $default_color;
		}

		return $default_color;
	}

	/**
	 * Curated hex list for advanced palette UIs when `palette_ui` is non-classic and `palettes` is empty.
	 *
	 * @return array<int, string>
	 */
	private static function built_in_advanced_palettes(): array {
		$p = array(
			'#000000', '#141414', '#2b2b2b', '#404040', '#5a5a5a', '#787878', '#9e9e9e', '#bdbdbd', '#e0e0e0', '#f5f5f5', '#ffffff',
			'#e53935', '#d81b60', '#8e24aa', '#5e35b1', '#3949ab', '#1e88e5', '#039be5', '#00acc1', '#00897b', '#43a047',
			'#7cb342', '#c0ca33', '#fdd835', '#ffb300', '#fb8c00', '#f4511e', '#5d4037', '#78909c',
			'#ffebee', '#fce4ec', '#f3e5f5', '#ede7f6', '#e8eaf6', '#e3f2fd', '#e0f7fa', '#e0f2f1', '#e8f5e9', '#fff8e1', '#fbe9e7',
			'#880e4f', '#4a148c', '#1a237e', '#0d47a1', '#01579b', '#004d40', '#1b5e20', '#bf360c', '#3e2723',
		);

		/**
		 * Filters the built-in preset list used when `palette_ui` is `advanced*` and no custom `palettes` were passed.
		 *
		 * @param array<int, string> $p Hex or rgb colors (sanitized on save).
		 */
		return apply_filters( 'sto_color_built_in_advanced_palettes', $p );
	}
}
