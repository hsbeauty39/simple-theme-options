<?php
namespace SimpleThemeOptions\Admin\Options\Fields\DividerControl;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Composite **Divider** field: line **style** (Select2), **width** (Range-style slider + number + unit chips),
 * and **alignment** (segmented icon control). Stored as one JSON object per option key (or per breakpoint when `responsive` is set).
 *
 * Register with **`'type' => 'divider_control'`** (or **`DividerControl::register()`**). Keys: **`section_slug`**, **`id`**, **`title`**, optional **`description`**, **`default`** (partial **`style`**, **`width`** as Range tuple **`v`/`u`/`c`**, **`align`**),
 * optional **`styles`** => list of **`array( 'key' => 'solid', 'label' => '…', 'group' => '…' )`** (`group` enables `<optgroup>` rows), optional **`alignments`** => same shape with **`key`**, **`label`**, optional **`icon`** (Font Awesome class string),
 * **`width_units`** => ordered non-empty subset of **`%`**, **`px`** (default both), **`width_min`**, **`width_max`**, **`width_step`**, conditional **`required`**, **`html_required`**, **`tooltip`**, **`wrapper_class`**, optional **`responsive`** + **`device`**.
 */
final class DividerControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const DEFAULT_WIDTH_UNITS = array( '%', 'px' );

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $fields_by_section = array();

	/**
	 * @var array<string, true>
	 */
	private $registered_ids = array();

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private $fields_by_id = array();

	protected function init() {
		// After Dimension (19.42), before Tabs (19.45).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), 19.44, 2 );
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

		$styles    = $this->normalize_style_defs( isset( $field['styles'] ) ? $field['styles'] : null );
		$aligns    = $this->normalize_alignments( isset( $field['alignments'] ) ? $field['alignments'] : null );
		$style_map = $this->style_map_from_defs( $styles );
		$align_map = $this->align_map_from_defs( $aligns );

		$width_units = $this->normalize_width_units( isset( $field['width_units'] ) ? $field['width_units'] : null );
		$w_min       = isset( $field['width_min'] ) && is_numeric( $field['width_min'] ) ? (float) $field['width_min'] : 0.0;
		$w_max       = isset( $field['width_max'] ) && is_numeric( $field['width_max'] ) ? (float) $field['width_max'] : 500.0;
		$w_step      = isset( $field['width_step'] ) && is_numeric( $field['width_step'] ) ? (float) $field['width_step'] : 1.0;
		if ( $w_max < $w_min ) {
			$t       = $w_max;
			$w_max   = $w_min;
			$w_min   = $t;
		}
		if ( $w_step <= 0 ) {
			$w_step = 1.0;
		}

		$sk        = array_keys( $style_map );
		$ak        = array_keys( $align_map );
		$def_style = in_array( 'solid', $sk, true ) ? 'solid' : (string) ( $sk[0] ?? 'solid' );
		$def_align = in_array( 'center', $ak, true ) ? 'center' : (string) ( $ak[0] ?? 'center' );
		$def_w     = $this->normalize_default_width_tuple( isset( $field['default'] ) ? $field['default'] : null, $width_units, $def_style );

		$field['section_slug']             = $section_slug;
		$field['id']                       = $field_id;
		$field['title']                    = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']              = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class']            = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']                 = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']                    = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['html_required']            = ! empty( $field['html_required'] );
		$field['styles_list']              = $styles;
		$field['style_keys']               = array_keys( $style_map );
		$field['alignments_list']          = $aligns;
		$field['align_keys']               = array_keys( $align_map );
		$field['width_units_allowed']      = $width_units;
		$field['width_min']                = $w_min;
		$field['width_max']                = $w_max;
		$field['width_step']               = $w_step;
		$field['default_style']            = $def_style;
		$field['default_align']            = $def_align;
		$field['default_width_tuple']      = $def_w;
		$field['default_composite_json']   = wp_json_encode(
			array(
				'style' => $def_style,
				'width' => $def_w,
				'align' => $def_align,
			)
		);
		$field['responsive_breakpoints']   = ResponsiveConfig::breakpoints_for_field( $field );

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}
		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]           = true;
		$this->fields_by_id[ $field_id ]             = $field;
	}

	/**
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
	 * @param string $field_id
	 * @return array<int, string>|null
	 */
	public function get_responsive_breakpoints( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;

		return is_array( $field ) ? ( $field['responsive_breakpoints'] ?? null ) : null;
	}

	/**
	 * @param mixed $raw
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
				$out[ $bp ] = $this->sanitize_stored_value( is_scalar( $cell ) ? (string) $cell : '', $field );
			}

			return $out;
		}

		return $this->sanitize_stored_value( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ), $field );
	}

	/**
	 * @param string               $raw
	 * @param array<string, mixed>|null $field
	 * @return string JSON
	 */
	public function sanitize_stored_value( $raw, $field = null ) {
		if ( ! is_array( $field ) ) {
			return wp_json_encode(
				array(
					'style' => 'solid',
					'width' => array( 'v' => '100', 'u' => '%', 'c' => '' ),
					'align' => 'center',
				)
			);
		}

		$style_keys = isset( $field['style_keys'] ) && is_array( $field['style_keys'] ) ? $field['style_keys'] : array( 'solid' );
		$align_keys = isset( $field['align_keys'] ) && is_array( $field['align_keys'] ) ? $field['align_keys'] : array( 'center' );
		$def_style  = isset( $field['default_style'] ) ? sanitize_key( (string) $field['default_style'] ) : (string) ( $style_keys[0] ?? 'solid' );
		$def_align  = isset( $field['default_align'] ) ? sanitize_key( (string) $field['default_align'] ) : (string) ( $align_keys[0] ?? 'center' );
		$def_w      = isset( $field['default_width_tuple'] ) && is_array( $field['default_width_tuple'] ) ? $field['default_width_tuple'] : array( 'v' => '100', 'u' => '%', 'c' => '' );

		$dec = json_decode( is_string( $raw ) ? $raw : '', true );
		if ( ! is_array( $dec ) ) {
			$dec = array();
		}

		$style = isset( $dec['style'] ) ? sanitize_key( (string) $dec['style'] ) : '';
		if ( ! in_array( $style, $style_keys, true ) ) {
			$style = in_array( $def_style, $style_keys, true ) ? $def_style : (string) ( $style_keys[0] ?? 'solid' );
		}

		$align = isset( $dec['align'] ) ? sanitize_key( (string) $dec['align'] ) : '';
		if ( ! in_array( $align, $align_keys, true ) ) {
			$align = in_array( $def_align, $align_keys, true ) ? $def_align : (string) ( $align_keys[0] ?? 'center' );
		}

		$width_in = isset( $dec['width'] ) ? $dec['width'] : null;
		$w_raw    = '';
		if ( is_array( $width_in ) ) {
			$w_raw = wp_json_encode( $width_in );
		} elseif ( is_string( $width_in ) ) {
			$w_raw = $width_in;
		}

		$range_field = $this->build_range_proxy_field( $field, $def_w );
		$w_json      = Range::instance()->sanitize_stored_value( $w_raw, $range_field );
		$w_json      = $this->clamp_percent_width_json( $w_json );

		$wu    = isset( $field['width_units_allowed'] ) && is_array( $field['width_units_allowed'] ) ? $field['width_units_allowed'] : self::DEFAULT_WIDTH_UNITS;
		$w_arr = json_decode( $w_json, true );
		if ( ! is_array( $w_arr ) ) {
			$w_arr = array( 'v' => '', 'u' => (string) ( $wu[0] ?? '%' ), 'c' => '' );
		}

		return wp_json_encode(
			array(
				'style' => $style,
				'width' => $w_arr,
				'align' => $align,
			)
		);
	}

	/**
	 * Decode stored JSON and expose safe strings for theme output.
	 *
	 * @param string      $field_id       Option key.
	 * @param string|null $json_or_scalar When non-null, decode this string instead of reading options.
	 * @return array{style:string,align:string,width_css:string,raw:string}
	 */
	public function get_theme_layout( $field_id, $json_or_scalar = null ) {
		$field_id = sanitize_key( (string) $field_id );
		$empty    = array(
			'style'     => '',
			'align'     => '',
			'width_css' => '',
			'raw'       => '',
		);
		if ( $field_id === '' ) {
			return $empty;
		}

		$raw = '';
		if ( null !== $json_or_scalar && is_string( $json_or_scalar ) ) {
			$raw = $json_or_scalar;
		} else {
			$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
			if ( ! is_array( $opts ) || ! array_key_exists( $field_id, $opts ) ) {
				return $empty;
			}
			$stored = $opts[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$slice = ResponsiveConfig::value_for_required_eval( $stored );
				$raw   = is_scalar( $slice ) ? (string) $slice : '';
			} else {
				$raw = is_scalar( $stored ) ? (string) $stored : '';
			}
		}

		$field = $this->fields_by_id[ $field_id ] ?? null;
		if ( ! is_array( $field ) ) {
			return $empty;
		}

		$clean = $this->sanitize_stored_value( $raw, $field );
		$dec   = json_decode( $clean, true );
		if ( ! is_array( $dec ) ) {
			return $empty;
		}

		$style = isset( $dec['style'] ) ? sanitize_key( (string) $dec['style'] ) : '';
		$align = isset( $dec['align'] ) ? sanitize_key( (string) $dec['align'] ) : '';
		$w     = isset( $dec['width'] ) && is_array( $dec['width'] ) ? wp_json_encode( $dec['width'] ) : '';

		return array(
			'style'     => $style,
			'align'     => $align,
			'width_css' => Range::value_to_css( $w ),
			'raw'       => $clean,
		);
	}

	/**
	 * @param string               $section_slug
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
			$this->render_field_markup( $field, 'default' );
		}
	}

	/**
	 * @param array<string, mixed>    $field
	 * @param 'default'|'group_inner' $context
	 */
	public function render_field_markup( $field, $context = 'default' ) {
		if ( ! is_array( $field ) ) {
			return;
		}

		$field_id      = $field['id'];
		$title         = $field['title'];
		$description   = $field['description'];
		$wrapper_class = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$tooltip       = FieldTitle::get_tooltip_config( $field );
		$bps_storage   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );

		$def_json = isset( $field['default_composite_json'] ) ? (string) $field['default_composite_json'] : '{}';
		$styles   = isset( $field['styles_list'] ) && is_array( $field['styles_list'] ) ? $field['styles_list'] : array();
		$aligns   = isset( $field['alignments_list'] ) && is_array( $field['alignments_list'] ) ? $field['alignments_list'] : array();

		$is_inner = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-divider-control' );
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
				$value_map = $this->get_value_map( $field_id, $def_json, $bps_storage, $field );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $def_json;
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$suffix     = $field_id . '_' . $tabs_pane_bp;
				$this->render_divider_block( $field, $suffix, $input_name, $cur, $def_json, $styles, $aligns );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $def_json, $bps_storage, $field );
					foreach ( $bps_storage as $i => $bp ) :
						$bp         = sanitize_key( (string) $bp );
						$visible    = ( 0 === (int) $i );
						$cur        = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $def_json;
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$suffix     = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_divider_block( $field, $suffix, $input_name, $cur, $def_json, $styles, $aligns );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$cur = $this->get_option_json( $field_id, $def_json, $field );
				$this->render_divider_block( $field, $field_id, 'sto_options[' . $field_id . ']', $cur, $def_json, $styles, $aligns );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $field
	 * @param array<int, array<string, string>> $styles
	 * @param array<int, array<string, string>> $aligns
	 */
	private function render_divider_block( array $field, $suffix, $input_name, $current_json, $default_json, array $styles, array $aligns ) {
		$clean = $this->sanitize_stored_value( is_string( $current_json ) ? $current_json : '', $field );
		$dec   = json_decode( $clean, true );
		if ( ! is_array( $dec ) ) {
			$dec = json_decode( $default_json, true );
		}
		if ( ! is_array( $dec ) ) {
			$dec = array(
				'style' => 'solid',
				'width' => array( 'v' => '100', 'u' => '%', 'c' => '' ),
				'align' => 'center',
			);
		}
		$style_cur = isset( $dec['style'] ) ? sanitize_key( (string) $dec['style'] ) : '';
		$align_cur = isset( $dec['align'] ) ? sanitize_key( (string) $dec['align'] ) : '';
		$w_arr     = isset( $dec['width'] ) && is_array( $dec['width'] ) ? $dec['width'] : array( 'v' => '100', 'u' => '%', 'c' => '' );
		$w_json    = Range::instance()->sanitize_stored_value( wp_json_encode( $w_arr ), $this->build_range_proxy_field( $field, isset( $field['default_width_tuple'] ) && is_array( $field['default_width_tuple'] ) ? $field['default_width_tuple'] : array( 'v' => '100', 'u' => '%', 'c' => '' ) ) );

		$allowed   = isset( $field['width_units_allowed'] ) && is_array( $field['width_units_allowed'] ) ? $field['width_units_allowed'] : self::DEFAULT_WIDTH_UNITS;
		$units_json = wp_json_encode( array_values( $allowed ) );
		$min        = (float) $field['width_min'];
		$max        = (float) $field['width_max'];
		$step       = (float) $field['width_step'];
		$step_attr  = $this->step_html_attr( $step );
		$select_id  = 'sto-divider-style-' . $suffix;
		?>
		<div
			class="sto-divider"
			data-sto-divider="1"
			data-sto-divider-default="<?php echo esc_attr( $default_json ); ?>"
		>
			<div class="sto-divider__row sto-divider__row--style">
				<span class="sto-divider__label"><?php esc_html_e( 'Style', 'simple-theme-options' ); ?></span>
				<div class="sto-divider__control">
					<select id="<?php echo esc_attr( $select_id ); ?>" class="sto-input-select sto-divider__style" autocomplete="off" data-sto-divider-style="1" aria-label="<?php esc_attr_e( 'Divider line style', 'simple-theme-options' ); ?>">
						<?php echo $this->render_style_options_markup( $styles, $style_cur ); ?>
					</select>
				</div>
			</div>

			<div class="sto-divider__row sto-divider__row--width sto-field-row-range">
				<span class="sto-divider__label"><?php esc_html_e( 'Width', 'simple-theme-options' ); ?></span>
				<div class="sto-divider__control">
					<?php
					$def_w_tuple = isset( $field['default_width_tuple'] ) && is_array( $field['default_width_tuple'] ) ? $field['default_width_tuple'] : array( 'v' => '100', 'u' => '%', 'c' => '' );
					$width_def_json = Range::instance()->sanitize_stored_value( wp_json_encode( $def_w_tuple ), $this->build_range_proxy_field( $field, $def_w_tuple ) );
					$this->render_embedded_range(
						$suffix,
						$w_json,
						$width_def_json,
						$min,
						$max,
						$step,
						$step_attr,
						$allowed,
						$units_json
					);
					?>
				</div>
			</div>

			<div class="sto-divider__row sto-divider__row--align">
				<span class="sto-divider__label"><?php esc_html_e( 'Alignment', 'simple-theme-options' ); ?></span>
				<div class="sto-divider__control">
					<div class="sto-divider__align" role="radiogroup" aria-label="<?php esc_attr_e( 'Divider alignment', 'simple-theme-options' ); ?>">
						<?php foreach ( $aligns as $row ) : ?>
							<?php
							$k   = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
							$lab = isset( $row['label'] ) ? (string) $row['label'] : $k;
							$ico = isset( $row['icon'] ) ? trim( (string) $row['icon'] ) : '';
							if ( $k === '' ) {
								continue;
							}
							$on = ( (string) $align_cur === (string) $k );
							?>
							<button
								type="button"
								class="sto-divider__align-btn<?php echo $on ? ' sto-is-active' : ''; ?>"
								data-sto-divider-align="<?php echo esc_attr( $k ); ?>"
								aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"
								title="<?php echo esc_attr( $lab ); ?>"
							>
								<?php if ( $ico !== '' ) : ?>
									<i class="<?php echo esc_attr( $ico ); ?>" aria-hidden="true"></i>
								<?php else : ?>
									<span class="sto-divider__align-text"><?php echo esc_html( $lab ); ?></span>
								<?php endif; ?>
								<span class="screen-reader-text"><?php echo esc_html( $lab ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<input type="hidden" class="sto-divider-value" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $clean ); ?>" />
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, string>> $styles
	 */
	private function render_style_options_markup( array $styles, $current_key ) {
		ob_start();
		$groups = array();
		foreach ( $styles as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$k = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( $k === '' ) {
				continue;
			}
			$g = isset( $row['group'] ) ? (string) $row['group'] : '';
			if ( ! isset( $groups[ $g ] ) ) {
				$groups[ $g ] = array();
			}
			$groups[ $g ][] = $row;
		}
		foreach ( $groups as $glabel => $rows ) :
			if ( $glabel !== '' ) :
				?>
				<optgroup label="<?php echo esc_attr( $glabel ); ?>">
				<?php
			endif;
			foreach ( $rows as $row ) :
				$k   = sanitize_key( (string) $row['key'] );
				$lab = isset( $row['label'] ) ? (string) $row['label'] : $k;
				?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $current_key === (string) $k ); ?>><?php echo esc_html( $lab ); ?></option>
				<?php
			endforeach;
			if ( $glabel !== '' ) :
				?>
				</optgroup>
				<?php
			endif;
		endforeach;

		return (string) ob_get_clean();
	}

	/**
	 * @param string               $suffix
	 * @param string               $width_json
	 * @param string               $width_default_json
	 * @param float                $min
	 * @param float                $max
	 * @param float                $step
	 * @param string               $step_attr
	 * @param array<int, string>   $allowed
	 * @param string               $units_json
	 */
	private function render_embedded_range( $suffix, $width_json, $width_default_json, $min, $max, $step, $step_attr, array $allowed, $units_json ) {
		$tuple = json_decode( $width_json, true );
		if ( ! is_array( $tuple ) ) {
			$tuple = array( 'v' => '', 'u' => (string) ( $allowed[0] ?? '%' ), 'c' => '' );
		}
		$v_raw    = isset( $tuple['v'] ) ? trim( (string) $tuple['v'] ) : '';
		$active_u = isset( $tuple['u'] ) ? sanitize_key( (string) $tuple['u'] ) : (string) ( $allowed[0] ?? '%' );
		if ( ! in_array( $active_u, $allowed, true ) ) {
			$active_u = (string) ( $allowed[0] ?? '%' );
		}
		$c        = isset( $tuple['c'] ) ? (string) $tuple['c'] : '';
		$v_num    = is_numeric( $v_raw ) ? (float) $v_raw : $min;
		$v_num    = max( $min, min( $max, $v_num ) );
		$v_disp   = ( $v_raw !== '' && is_numeric( $v_raw ) ) ? (string) $v_num : '';
		$forced_u = 1 === count( $allowed ) ? (string) $allowed[0] : '';
		$range_id = 'sto-divider-range-r-' . $suffix;
		$num_id   = 'sto-divider-range-n-' . $suffix;
		?>
		<div
			class="sto-range"
			data-sto-range="1"
			data-sto-range-min="<?php echo esc_attr( (string) $min ); ?>"
			data-sto-range-max="<?php echo esc_attr( (string) $max ); ?>"
			data-sto-range-step="<?php echo esc_attr( (string) $step ); ?>"
			data-sto-range-default="<?php echo esc_attr( $width_default_json ); ?>"
			data-sto-range-units="<?php echo esc_attr( $units_json ); ?>"
			data-sto-range-custom-suffix="0"
			<?php if ( $forced_u !== '' ) : ?>
				data-sto-range-forced-unit="<?php echo esc_attr( $forced_u ); ?>"
			<?php endif; ?>
		>
			<div class="sto-range__row">
				<div class="sto-range__slider-wrap">
					<input
						type="range"
						class="sto-range__slider"
						id="<?php echo esc_attr( $range_id ); ?>"
						min="<?php echo esc_attr( (string) $min ); ?>"
						max="<?php echo esc_attr( (string) $max ); ?>"
						step="<?php echo esc_attr( $step_attr ); ?>"
						value="<?php echo esc_attr( $v_disp !== '' ? $v_disp : (string) $min ); ?>"
						<?php echo $v_disp === '' ? ' data-sto-range-empty="1"' : ''; ?>
						aria-valuemin="<?php echo esc_attr( (string) $min ); ?>"
						aria-valuemax="<?php echo esc_attr( (string) $max ); ?>"
					/>
				</div>
				<input
					type="number"
					class="sto-range__number"
					id="<?php echo esc_attr( $num_id ); ?>"
					min="<?php echo esc_attr( (string) $min ); ?>"
					max="<?php echo esc_attr( (string) $max ); ?>"
					step="<?php echo esc_attr( $step_attr ); ?>"
					value="<?php echo esc_attr( $v_disp ); ?>"
					inputmode="decimal"
					aria-label="<?php esc_attr_e( 'Width value', 'simple-theme-options' ); ?>"
				/>
				<div class="sto-range__units" role="group" aria-label="<?php esc_attr_e( 'Unit', 'simple-theme-options' ); ?>">
					<?php if ( count( $allowed ) > 1 ) : ?>
						<?php foreach ( $allowed as $u ) : ?>
							<button
								type="button"
								class="sto-range__unit<?php echo $u === $active_u ? ' sto-is-active' : ''; ?>"
								data-sto-range-unit="<?php echo esc_attr( $u ); ?>"
							><?php echo esc_html( strtoupper( $u ) ); ?></button>
						<?php endforeach; ?>
					<?php else : ?>
						<span class="sto-range__unit-badge"><?php echo esc_html( strtoupper( (string) $allowed[0] ) ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<input type="hidden" class="sto-range-value sto-divider__width-json" value="<?php echo esc_attr( $width_json ); ?>" autocomplete="off" />
		</div>
		<?php
	}

	/**
	 * @param string               $field_id
	 * @param string               $default_json
	 * @param array<int, string>   $breakpoints
	 * @param array<string, mixed> $field
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, $default_json, array $breakpoints, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return $this->fill_breakpoint_json( $breakpoints, $default_json );
		}
		$stored = $saved_options[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$map = array();
			foreach ( $breakpoints as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $stored[ $bp ] ) ? (string) $stored[ $bp ] : '';
				$map[ $bp ] = $cell !== '' ? $this->sanitize_stored_value( $cell, $field ) : $this->sanitize_stored_value( $default_json, $field );
			}

			return $map;
		}
		$scalar = is_scalar( $stored ) ? (string) $stored : '';
		$base   = $scalar !== '' ? $this->sanitize_stored_value( $scalar, $field ) : $this->sanitize_stored_value( $default_json, $field );

		return $this->fill_breakpoint_json( $breakpoints, $base );
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @param string             $json
	 * @return array<string, string>
	 */
	private function fill_breakpoint_json( array $breakpoints, $json ) {
		$out = array();
		foreach ( $breakpoints as $bp ) {
			$bp         = sanitize_key( (string) $bp );
			$out[ $bp ] = (string) $json;
		}

		return $out;
	}

	/**
	 * @param string               $field_id
	 * @param string               $default_json
	 * @param array<string, mixed> $field
	 * @return string
	 */
	private function get_option_json( $field_id, $default_json, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return $this->sanitize_stored_value( $default_json, $field );
		}
		$st = $saved_options[ $field_id ];
		if ( is_array( $st ) && ResponsiveConfig::is_breakpoint_value_map( $st ) ) {
			$slice = ResponsiveConfig::value_for_required_eval( $st );

			return $this->sanitize_stored_value( is_scalar( $slice ) ? (string) $slice : '', $field );
		}

		return $this->sanitize_stored_value( is_scalar( $st ) ? (string) $st : '', $field );
	}

	/**
	 * @param array<string, mixed> $field
	 * @param array{v:string,u:string,c:string} $def_w
	 * @return array<string, mixed>
	 */
	private function build_range_proxy_field( array $field, array $def_w ) {
		return array(
			'units_allowed'   => isset( $field['width_units_allowed'] ) && is_array( $field['width_units_allowed'] ) ? $field['width_units_allowed'] : self::DEFAULT_WIDTH_UNITS,
			'min'             => isset( $field['width_min'] ) ? (float) $field['width_min'] : 0.0,
			'max'             => isset( $field['width_max'] ) ? (float) $field['width_max'] : 500.0,
			'step'            => isset( $field['width_step'] ) ? (float) $field['width_step'] : 1.0,
			'default_tuple'   => $def_w,
			'unit_label'      => '',
		);
	}

	private function clamp_percent_width_json( $width_json ) {
		$d = json_decode( (string) $width_json, true );
		if ( ! is_array( $d ) ) {
			return (string) $width_json;
		}
		$u = isset( $d['u'] ) ? sanitize_key( (string) $d['u'] ) : 'px';
		$v = isset( $d['v'] ) ? trim( (string) $d['v'] ) : '';
		if ( $u === '%' && $v !== '' && is_numeric( $v ) ) {
			$n         = max( 0.0, min( 100.0, (float) $v ) );
			$d['v']    = (string) ( floor( $n ) === $n ? (int) $n : $n );
			$d['u']    = '%';
			$d['c']    = '';
			$width_json = wp_json_encode( $d );
		}

		return (string) $width_json;
	}

	/**
	 * @param float $step
	 * @return string
	 */
	private function step_html_attr( $step ) {
		if ( floor( $step ) === $step ) {
			return (string) (int) $step;
		}

		return rtrim( rtrim( sprintf( '%.6F', $step ), '0' ), '.' );
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array<string, string>>
	 */
	private function normalize_style_defs( $raw ) {
		$fallback = $this->default_style_defs();
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return $fallback;
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$k = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( $k === '' ) {
				continue;
			}
			$out[] = array(
				'key'   => $k,
				'label' => isset( $row['label'] ) ? wp_strip_all_tags( (string) $row['label'] ) : $k,
				'group' => isset( $row['group'] ) ? wp_strip_all_tags( (string) $row['group'] ) : '',
			);
		}

		return ! empty( $out ) ? $out : $fallback;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function default_style_defs() {
		$classic = __( 'Classic', 'simple-theme-options' );
		$pattern = __( 'Pattern', 'simple-theme-options' );

		return array(
			array( 'key' => 'none', 'label' => __( 'None', 'simple-theme-options' ), 'group' => $classic ),
			array( 'key' => 'solid', 'label' => __( 'Solid', 'simple-theme-options' ), 'group' => $classic ),
			array( 'key' => 'dashed', 'label' => __( 'Dashed', 'simple-theme-options' ), 'group' => $classic ),
			array( 'key' => 'dotted', 'label' => __( 'Dotted', 'simple-theme-options' ), 'group' => $classic ),
			array( 'key' => 'double', 'label' => __( 'Double', 'simple-theme-options' ), 'group' => $classic ),
			array( 'key' => 'zigzag', 'label' => __( 'Zigzag (demo)', 'simple-theme-options' ), 'group' => $pattern ),
			array( 'key' => 'curved', 'label' => __( 'Curved (demo)', 'simple-theme-options' ), 'group' => $pattern ),
		);
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array<string, string>>
	 */
	private function normalize_alignments( $raw ) {
		$fallback = $this->default_alignments();
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return $fallback;
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$k = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( $k === '' ) {
				continue;
			}
			$out[] = array(
				'key'   => $k,
				'label' => isset( $row['label'] ) ? wp_strip_all_tags( (string) $row['label'] ) : $k,
				'icon'  => isset( $row['icon'] ) ? preg_replace( '/[^a-zA-Z0-9_\- ]/', '', (string) $row['icon'] ) : '',
			);
		}

		return ! empty( $out ) ? $out : $fallback;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function default_alignments() {
		return array(
			array(
				'key'   => 'left',
				'label' => __( 'Align left', 'simple-theme-options' ),
				'icon'  => 'fa-light fa-align-left',
			),
			array(
				'key'   => 'center',
				'label' => __( 'Align center', 'simple-theme-options' ),
				'icon'  => 'fa-light fa-align-center',
			),
			array(
				'key'   => 'right',
				'label' => __( 'Align right', 'simple-theme-options' ),
				'icon'  => 'fa-light fa-align-right',
			),
		);
	}

	/**
	 * @param array<int, array<string, string>> $defs
	 * @return array<string, true>
	 */
	private function style_map_from_defs( array $defs ) {
		$map = array();
		foreach ( $defs as $row ) {
			if ( ! empty( $row['key'] ) ) {
				$map[ (string) $row['key'] ] = true;
			}
		}

		return $map;
	}

	/**
	 * @param array<int, array<string, string>> $defs
	 * @return array<string, true>
	 */
	private function align_map_from_defs( array $defs ) {
		$map = array();
		foreach ( $defs as $row ) {
			if ( ! empty( $row['key'] ) ) {
				$map[ (string) $row['key'] ] = true;
			}
		}

		return $map;
	}

	/**
	 * @param mixed $raw
	 * @param array<int, string> $width_units
	 * @param string               $def_style unused; reserved for width defaults keyed by style
	 * @return array{v:string,u:string,c:string}
	 */
	private function normalize_default_width_tuple( $raw, array $width_units, $def_style ) {
		unset( $def_style );
		$u0 = (string) ( $width_units[0] ?? '%' );
		$base = array( 'v' => '100', 'u' => $u0, 'c' => '' );
		if ( ! is_array( $raw ) || ! isset( $raw['width'] ) ) {
			return $base;
		}
		$w = $raw['width'];
		if ( is_array( $w ) ) {
			$tuple = array(
				'v' => isset( $w['v'] ) ? trim( (string) $w['v'] ) : '',
				'u' => isset( $w['u'] ) ? sanitize_key( (string) $w['u'] ) : $u0,
				'c' => isset( $w['c'] ) ? (string) $w['c'] : '',
			);
		} else {
			$tuple = array( 'v' => '', 'u' => $u0, 'c' => '' );
		}
		if ( ! in_array( $tuple['u'], $width_units, true ) ) {
			$tuple['u'] = $u0;
		}

		return $tuple;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, string>
	 */
	private function normalize_width_units( $raw ) {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $u ) {
				$uk = sanitize_key( (string) $u );
				if ( in_array( $uk, array( '%', 'px' ), true ) ) {
					$out[] = $uk;
				}
			}
		}
		if ( empty( $out ) ) {
			return self::DEFAULT_WIDTH_UNITS;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string               $section_slug
	 * @param array<string, mixed> $option_values
	 * @return array<int, string>
	 */
	public function collect_html_required_violations_for_section( $section_slug, array $option_values ) {
		$section_slug = sanitize_key( (string) $section_slug );
		if ( $section_slug === '' || empty( $this->fields_by_section[ $section_slug ] ) ) {
			return array();
		}
		$messages = array();
		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			if ( ! is_array( $field ) || empty( $field['html_required'] ) ) {
				continue;
			}
			$rules = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
			if ( ! RequiredVisibility::row_is_visible( $rules, $option_values ) ) {
				continue;
			}
			$fid = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			if ( $fid === '' ) {
				continue;
			}
			$raw = array_key_exists( $fid, $option_values ) ? $option_values[ $fid ] : null;
			if ( ! $this->is_html_required_value_empty( $field, $raw ) ) {
				continue;
			}
			$title = isset( $field['title'] ) ? trim( (string) $field['title'] ) : '';
			$label = $title !== '' ? $title : $fid;
			$messages[] = sprintf(
				/* translators: %s: field label */
				__( '“%s” must be filled in before this section can be saved.', 'simple-theme-options' ),
				$label
			);
		}

		return $messages;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param mixed                $raw
	 */
	private function is_html_required_value_empty( array $field, $raw ) {
		$clean = $this->coerce_raw_to_json_string( $field, $raw );
		$dec   = json_decode( $clean, true );
		if ( ! is_array( $dec ) ) {
			return true;
		}
		$style = isset( $dec['style'] ) ? sanitize_key( (string) $dec['style'] ) : '';
		if ( $style === '' || $style === 'none' ) {
			return true;
		}
		$w = isset( $dec['width'] ) && is_array( $dec['width'] ) ? $dec['width'] : array();
		$v = isset( $w['v'] ) ? trim( (string) $w['v'] ) : '';
		if ( $v === '' || ! is_numeric( $v ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param mixed                $raw
	 * @return string
	 */
	private function coerce_raw_to_json_string( array $field, $raw ) {
		$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$slice = ResponsiveConfig::value_for_required_eval( $raw );

			return is_scalar( $slice ) ? (string) $slice : '';
		}

		return is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' );
	}
}
