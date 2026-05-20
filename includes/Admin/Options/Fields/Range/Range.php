<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Range;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSpacing;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\UnitFieldStoredJson;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Range + numeric value with configurable units. Stored as JSON per field or per breakpoint.
 *
 * **Default** may be a bare number string (e.g. **`'5'`**): **`unit`** is taken from the first **`units`** entry (so **`units` => array( 'custom' )** + **`'default' => '5'`** is enough; no need for a full **`value` / `unit` / `custom_suffix`** array).
 *
 * Built-in unit keys: **px**, **%**, **rem**, **em**, **custom** (suffix in **`custom_suffix`**, e.g. `vw`, `ch`). Legacy stored keys **`v`**, **`u`**, **`c`** are read on load only.
 * Register **`units`** as any non-empty ordered subset (e.g. only `rem`, or `px` + `em`, or `custom` alone).
 * Optional **`unit_label`**: plain-text suffix after the number in the admin UI (e.g. `PAGE` for “pages visited”); not stored in JSON. With **`units` => `custom` only** and a label, the CUSTOM chip and suffix field are hidden (**`custom_suffix`** stays empty).
 */
final class Range {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	/** Canonical keys (validation order when `units` is omitted). */
	public const UNITS = array( 'px', '%', 'rem', 'em', 'custom' );

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
		// After Background (19.25), before Input (19.5).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::RANGE, 2 );
	}

	/**
	 * Register a range field.
	 *
	 * Keys: section_slug, id, title?, description?, **default** — scalar **`760`**, **`760px`**, **`5`** (number alone uses the first **`units`** entry as **`unit`**), or array **`value` / `unit` / `custom_suffix`**,
	 * min (default 0), max (default 1000), step (default 1), **units?** => ordered non-empty subset of **px**, **%**, **rem**, **em**, **custom** (default: all five). One entry = locked unit (no toggles). **`custom`** alone = suffix field only.
	 * **unit_label?** => optional short string shown after the number (not saved; use with dimensionless counts). With **`units` => array( 'custom' )** and **unit_label**, the suffix input and CUSTOM badge are omitted.
	 * wrapper_class?, required?, group?, tooltip?, optional responsive + device (see ResponsiveConfig).
	 *
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
		FieldSpacing::normalize_config( $field );


		$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
		$field_id     = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';

		if ( ! $section_slug || ! $field_id ) {
			return;
		}

		$units_in = isset( $field['units'] ) && is_array( $field['units'] ) ? $field['units'] : self::UNITS;
		$allowed    = array();
		foreach ( $units_in as $u ) {
			$uk = sanitize_key( (string) $u );
			if ( in_array( $uk, self::UNITS, true ) ) {
				$allowed[] = $uk;
			}
		}
		if ( empty( $allowed ) ) {
			$allowed = self::UNITS;
		}

		$min   = isset( $field['min'] ) && is_numeric( $field['min'] ) ? (float) $field['min'] : 0.0;
		$max   = isset( $field['max'] ) && is_numeric( $field['max'] ) ? (float) $field['max'] : 1000.0;
		if ( $max < $min ) {
			$tmp = $max;
			$max = $min;
			$min = $tmp;
		}

		$step = isset( $field['step'] ) && is_numeric( $field['step'] ) ? (float) $field['step'] : 1.0;
		if ( $step <= 0 ) {
			$step = 1.0;
		}

		$def_tuple = $this->normalize_default_tuple( isset( $field['default'] ) ? $field['default'] : null, $allowed );

		$field['section_slug']           = $section_slug;
		$field['id']                     = $field_id;
		$field['title']                  = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']          = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class']        = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']             = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']                = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['units_allowed']        = $allowed;
		$field['min']                  = $min;
		$field['max']                  = $max;
		$field['step']                 = $step;
		$field['default_tuple']        = $def_tuple;
		$field['responsive_breakpoints'] = ResponsiveConfig::breakpoints_for_field( $field );
		$field['unit_label']           = $this->sanitize_unit_label( isset( $field['unit_label'] ) ? $field['unit_label'] : '' );

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]            = $field;
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
	 * @return array<int, string>|null
	 */
	public function get_responsive_breakpoints( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;

		return is_array( $field ) ? ( $field['responsive_breakpoints'] ?? null ) : null;
	}

	/**
	 * @param mixed $raw Posted value (JSON string, breakpoint map of strings, or array from malformed post).
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
	 * @param array<string, mixed>|null $field Field definition (required for clamp / units).
	 * @return string JSON {value,unit,custom_suffix}
	 */
	public function sanitize_stored_value( $raw, $field = null ) {
		if ( ! is_array( $field ) ) {
			return wp_json_encode( UnitFieldStoredJson::encode_range_tuple( array( 'value' => '', 'unit' => 'px', 'custom_suffix' => '' ) ) );
		}

		$allowed = isset( $field['units_allowed'] ) && is_array( $field['units_allowed'] ) ? $field['units_allowed'] : self::UNITS;
		$min     = isset( $field['min'] ) ? (float) $field['min'] : 0.0;
		$max     = isset( $field['max'] ) ? (float) $field['max'] : 1000.0;
		$step    = isset( $field['step'] ) ? (float) $field['step'] : 1.0;
		$def     = isset( $field['default_tuple'] ) && is_array( $field['default_tuple'] ) ? $field['default_tuple'] : array( 'value' => '', 'unit' => (string) ( $allowed[0] ?? 'px' ), 'custom_suffix' => '' );

		$tuple = $this->parse_json_cell( is_string( $raw ) ? $raw : '', $allowed );
		if ( $tuple === null ) {
			$tuple = $def;
		}

		$tuple['unit'] = in_array( $tuple['unit'], $allowed, true ) ? $tuple['unit'] : (string) ( $allowed[0] ?? 'px' );
		$tuple['custom_suffix'] = $this->sanitize_custom_suffix( (string) $tuple['custom_suffix'] );

		$value_str = trim( (string) $tuple['value'] );
		if ( $value_str === '' ) {
			$out = UnitFieldStoredJson::encode_range_tuple(
				array(
					'value'         => '',
					'unit'          => $tuple['unit'],
					'custom_suffix' => $tuple['unit'] === 'custom' ? $tuple['custom_suffix'] : '',
				)
			);
			$ul = isset( $field['unit_label'] ) ? (string) $field['unit_label'] : '';
			if ( $this->range_label_replaces_custom_suffix_ui( $allowed, $ul ) ) {
				$out['custom_suffix'] = '';
			}

			return wp_json_encode( $out );
		}

		if ( ! is_numeric( $value_str ) ) {
			$fallback = $def;
			if ( ! is_numeric( (string) $fallback['value'] ) ) {
				$fallback['value'] = '';
			}

			$fb = UnitFieldStoredJson::encode_range_tuple(
				array(
					'value'         => (string) $fallback['value'],
					'unit'          => in_array( $fallback['unit'], $allowed, true ) ? $fallback['unit'] : (string) ( $allowed[0] ?? 'px' ),
					'custom_suffix' => $fallback['unit'] === 'custom' ? $this->sanitize_custom_suffix( (string) $fallback['custom_suffix'] ) : '',
				)
			);
			$ul = isset( $field['unit_label'] ) ? (string) $field['unit_label'] : '';
			if ( $this->range_label_replaces_custom_suffix_ui( $allowed, $ul ) ) {
				$fb['custom_suffix'] = '';
			}

			return wp_json_encode( $fb );
		}

		$num = (float) $value_str;
		$num = max( $min, min( $max, $num ) );
		$num = $this->round_to_step( $num, $step > 0 ? $step : 1.0 );

		$out = UnitFieldStoredJson::encode_range_tuple(
			array(
				'value'         => $this->format_number_string( $num, $step ),
				'unit'          => $tuple['unit'],
				'custom_suffix' => $tuple['unit'] === 'custom' ? $tuple['custom_suffix'] : '',
			)
		);

		$ul = isset( $field['unit_label'] ) ? (string) $field['unit_label'] : '';
		if ( $this->range_label_replaces_custom_suffix_ui( $allowed, $ul ) ) {
			$out['custom_suffix'] = '';
		}

		return wp_json_encode( $out );
	}

	/**
	 * Turn stored JSON into a CSS length (e.g. `760px`, `12rem`, `50%`, `10vw` when unit is custom and suffix is `vw`).
	 * Custom unit with empty suffix returns the numeric string only (not a CSS length); use decoded **`value`** for counts (e.g. with **`unit_label`** in admin).
	 *
	 * @param string $json_or_empty
	 * @return string
	 */
	public static function value_to_css( $json_or_empty ) {
		$s = is_string( $json_or_empty ) ? trim( $json_or_empty ) : '';
		if ( $s === '' ) {
			return '';
		}
		$dec = json_decode( $s, true );
		if ( ! is_array( $dec ) ) {
			return '';
		}
		$tuple = UnitFieldStoredJson::parse_range_decoded( $dec, self::UNITS );
		$value = $tuple['value'];
		$unit  = $tuple['unit'];
		$custom_suffix = $tuple['custom_suffix'];
		if ( $value === '' ) {
			return '';
		}
		if ( $unit === 'custom' ) {
			return $value . $custom_suffix;
		}
		if ( in_array( $unit, array( 'px', '%', 'rem', 'em' ), true ) ) {
			return $value . $unit;
		}

		return '';
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

		$min          = (float) $field['min'];
		$max          = (float) $field['max'];
		$step         = (float) $field['step'];
		$allowed      = isset( $field['units_allowed'] ) && is_array( $field['units_allowed'] ) ? $field['units_allowed'] : self::UNITS;
		$units_json   = wp_json_encode( array_values( $allowed ) );
		$def_tuple    = isset( $field['default_tuple'] ) && is_array( $field['default_tuple'] ) ? $field['default_tuple'] : array( 'value' => '', 'unit' => (string) ( $allowed[0] ?? 'px' ), 'custom_suffix' => '' );
		$default_json = wp_json_encode( UnitFieldStoredJson::encode_range_tuple( $def_tuple ) );

		$is_inner = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-range' );
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
			class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute string from FieldSpacing::row_margin_style_attr().
			echo FieldSpacing::row_margin_style_attr( $field, $context );
			?>
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
				$value_map = $this->get_value_map( $field_id, $def_tuple, $bps_storage, $field );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $def_tuple;
				$json      = wp_json_encode( UnitFieldStoredJson::encode_range_tuple( $cur ) );
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$suffix     = $field_id . '_' . $tabs_pane_bp;
				$this->render_range_control( $suffix, $input_name, $json, $default_json, $min, $max, $step, $allowed, $units_json, (string) ( $field['unit_label'] ?? '' ) );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $def_tuple, $bps_storage, $field );
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$cur     = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $def_tuple;
						$json    = wp_json_encode( UnitFieldStoredJson::encode_range_tuple( $cur ) );
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$suffix     = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_range_control( $suffix, $input_name, $json, $default_json, $min, $max, $step, $allowed, $units_json, (string) ( $field['unit_label'] ?? '' ) );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$cur = $this->get_option_tuple( $field_id, $def_tuple, $field );
				$json = wp_json_encode( UnitFieldStoredJson::encode_range_tuple( $cur ) );
				$this->render_range_control( $field_id, 'sto_options[' . $field_id . ']', $json, $default_json, $min, $max, $step, $allowed, $units_json, (string) ( $field['unit_label'] ?? '' ) );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string               $suffix        Unique DOM suffix (field id or field_bp).
	 * @param string               $input_name   Full name attribute (or base without brackets for non-responsive — we pass full).
	 * @param string               $current_json JSON value.
	 * @param string               $default_json Default JSON for reset.
	 * @param float                $min
	 * @param float                $max
	 * @param float                $step
	 * @param array<int, string>   $allowed
	 * @param string               $units_json
	 * @param string               $unit_label   Optional UI-only label after the number (not saved).
	 */
	private function render_range_control( $suffix, $input_name, $current_json, $default_json, $min, $max, $step, array $allowed, $units_json, $unit_label = '' ) {
		$tuple = $this->parse_json_cell( $current_json, $allowed );
		if ( $tuple === null ) {
			$tuple = array( 'value' => '', 'unit' => (string) ( $allowed[0] ?? 'px' ), 'custom_suffix' => '' );
		}
		$value_num    = is_numeric( $tuple['value'] ) ? (float) $tuple['value'] : $min;
		$value_num    = max( $min, min( $max, $value_num ) );
		$value_display = $tuple['value'] !== '' && is_numeric( $tuple['value'] ) ? $this->format_number_string( $value_num, $step ) : '';
		$active_unit = in_array( $tuple['unit'], $allowed, true ) ? $tuple['unit'] : $allowed[0];
		$step_attr = $this->step_html_attr( $step );
		$range_id = 'sto-range-r-' . $suffix;
		$num_id   = 'sto-range-n-' . $suffix;
		$suf_id   = 'sto-range-c-' . $suffix;
		$forced_unit = 1 === count( $allowed ) ? (string) $allowed[0] : '';
		$unit_label = is_string( $unit_label ) ? $unit_label : '';
		$suppress_suffix = $this->range_label_replaces_custom_suffix_ui( $allowed, $unit_label );
		?>
		<div
			class="sto-range"
			data-sto-range="1"
			data-sto-range-min="<?php echo esc_attr( (string) $min ); ?>"
			data-sto-range-max="<?php echo esc_attr( (string) $max ); ?>"
			data-sto-range-step="<?php echo esc_attr( (string) $step ); ?>"
			data-sto-range-default="<?php echo esc_attr( $default_json ); ?>"
			data-sto-range-units="<?php echo esc_attr( $units_json ); ?>"
			<?php if ( $suppress_suffix ) : ?>
				data-sto-range-custom-suffix="0"
			<?php endif; ?>
			<?php if ( $forced_unit !== '' ) : ?>
				data-sto-range-forced-unit="<?php echo esc_attr( $forced_unit ); ?>"
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
					value="<?php echo esc_attr( $value_display !== '' ? $value_display : (string) $min ); ?>"
					<?php echo $value_display === '' ? ' data-sto-range-empty="1"' : ''; ?>
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
					value="<?php echo esc_attr( $value_display ); ?>"
					inputmode="decimal"
					aria-label="<?php esc_attr_e( 'Value', 'topten-simple-theme-options' ); ?>"
				/>
				<?php if ( $unit_label !== '' ) : ?>
					<span class="sto-range__unit-label"><?php echo esc_html( $unit_label ); ?></span>
				<?php endif; ?>
				<?php if ( ! $suppress_suffix ) : ?>
				<div class="sto-range__units" role="group" aria-label="<?php esc_attr_e( 'Unit', 'topten-simple-theme-options' ); ?>">
					<?php if ( count( $allowed ) > 1 ) : ?>
						<?php foreach ( $allowed as $unit_option ) : ?>
						<button
							type="button"
							class="sto-range__unit<?php echo $unit_option === $active_unit ? ' sto-is-active' : ''; ?>"
							data-sto-range-unit="<?php echo esc_attr( $unit_option ); ?>"
						><?php echo esc_html( $this->format_unit_label( $unit_option ) ); ?></button>
						<?php endforeach; ?>
					<?php elseif ( 1 === count( $allowed ) ) : ?>
						<span class="sto-range__unit-badge"><?php echo esc_html( $this->format_unit_label( $allowed[0] ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<input
					type="text"
					class="sto-range__custom-suffix"
					id="<?php echo esc_attr( $suf_id ); ?>"
					value="<?php echo esc_attr( $tuple['custom_suffix'] ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. vw', 'topten-simple-theme-options' ); ?>"
					autocomplete="off"
					<?php echo ( $active_unit === 'custom' && ! $suppress_suffix ) ? '' : ' hidden disabled'; ?>
				/>
			</div>
			<input type="hidden" class="sto-range-value" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $current_json ); ?>" />
		</div>
		<?php
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @return array<string, array{value:string,unit:string,custom_suffix:string}>
	 */
	private function get_value_map( $field_id, array $default_tuple, array $breakpoints, array $field ) {
		$allowed = isset( $field['units_allowed'] ) && is_array( $field['units_allowed'] ) ? $field['units_allowed'] : self::UNITS;

		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return $this->fill_breakpoint_tuples( $breakpoints, $default_tuple );
		}

		$stored = $saved_options[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$map = array();
			foreach ( $breakpoints as $bp ) {
				$bp   = sanitize_key( (string) $bp );
				$cell = isset( $stored[ $bp ] ) ? (string) $stored[ $bp ] : '';
				$t    = $this->parse_json_cell( $cell, $allowed );
				$map[ $bp ] = $t ? $t : $default_tuple;
			}

			return $map;
		}

		$scalar = is_scalar( $stored ) ? (string) $stored : '';
		$t0     = $this->parse_json_cell( $scalar, $allowed );
		$base   = $t0 ? $t0 : $default_tuple;

		return $this->fill_breakpoint_tuples( $breakpoints, $base );
	}

	/**
	 * @param array<int, string>                 $breakpoints
	 * @param array{value:string,unit:string,custom_suffix:string} $tuple
	 * @return array<string, array{value:string,unit:string,custom_suffix:string}>
	 */
	private function fill_breakpoint_tuples( array $breakpoints, array $tuple ) {
		$out = array();
		foreach ( $breakpoints as $bp ) {
			$bp         = sanitize_key( (string) $bp );
			$out[ $bp ] = $tuple;
		}

		return $out;
	}

	/**
	 * @param array{value:string,unit:string,custom_suffix:string} $default_tuple
	 * @return array{value:string,unit:string,custom_suffix:string}
	 */
	private function get_option_tuple( $field_id, array $default_tuple, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return $default_tuple;
		}

		$st = $saved_options[ $field_id ];
		$allowed = isset( $field['units_allowed'] ) && is_array( $field['units_allowed'] ) ? $field['units_allowed'] : self::UNITS;
		if ( is_array( $st ) && ResponsiveConfig::is_breakpoint_value_map( $st ) ) {
			$slice = ResponsiveConfig::value_for_required_eval( $st );
			$t     = $this->parse_json_cell( is_scalar( $slice ) ? (string) $slice : '', $allowed );

			return $t ? $t : $default_tuple;
		}

		$t = $this->parse_json_cell( is_scalar( $st ) ? (string) $st : '', $allowed );

		return $t ? $t : $default_tuple;
	}

	/**
	 * Optional UI-only text after the number (e.g. "PAGE"). Not persisted.
	 *
	 * @param mixed $raw
	 * @return string
	 */
	private function sanitize_unit_label( $raw ) {
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return '';
		}
		$s = trim( wp_strip_all_tags( (string) $raw ) );
		if ( function_exists( 'mb_substr' ) ) {
			$s = mb_substr( $s, 0, 64, 'UTF-8' );
		} elseif ( strlen( $s ) > 64 ) {
			$s = substr( $s, 0, 64 );
		}

		return $s;
	}

	/**
	 * Custom-only + unit_label: hide suffix field and unit badge; still store u=custom, c="".
	 *
	 * @param array<int, string> $allowed
	 * @param string             $unit_label
	 * @return bool
	 */
	private function range_label_replaces_custom_suffix_ui( array $allowed, $unit_label ) {
		return $unit_label !== '' && 1 === count( $allowed ) && isset( $allowed[0] ) && $allowed[0] === 'custom';
	}

	/**
	 * Uppercase unit chip label (`%` stays as `%`).
	 *
	 * @param string $u
	 * @return string
	 */
	private function format_unit_label( $u ) {
		$u = sanitize_key( (string) $u );
		if ( $u === '%' ) {
			return '%';
		}
		if ( $u === 'custom' ) {
			return 'CUSTOM';
		}

		return strtoupper( $u );
	}

	/**
	 * @param mixed                $raw
	 * @param array<int, string>   $allowed
	 * @return array{value:string,unit:string,custom_suffix:string}|null
	 */
	private function normalize_default_tuple( $raw, array $allowed ) {
		$base = array( 'value' => '', 'unit' => (string) ( $allowed[0] ?? 'px' ), 'custom_suffix' => '' );

		if ( is_array( $raw ) ) {
			$base = UnitFieldStoredJson::merge_range_default_partial( $raw, $base, $allowed );
			$base['custom_suffix'] = $this->sanitize_custom_suffix( (string) $base['custom_suffix'] );
		} elseif ( is_scalar( $raw ) ) {
			$s = trim( (string) $raw );
			if ( preg_match( '/^(-?[0-9]*\.?[0-9]+)\s*(px|%|rem|em)?$/i', $s, $m ) ) {
				$default_unit = (string) ( $allowed[0] ?? 'px' );
				$base['value'] = $m[1];
				$parsed_unit   = isset( $m[2] ) && $m[2] !== '' ? strtolower( $m[2] ) : $default_unit;
				if ( $parsed_unit === 'percent' ) {
					$parsed_unit = '%';
				}
				$base['unit'] = in_array( $parsed_unit, $allowed, true ) ? $parsed_unit : $default_unit;
			} elseif ( $s !== '' ) {
				$try = json_decode( $s, true );
				if ( is_array( $try ) ) {
					return $this->normalize_default_tuple( $try, $allowed );
				}
			}
		}

		return $base;
	}

	/**
	 * @param string             $json
	 * @param array<int, string> $allowed
	 * @return array{value:string,unit:string,custom_suffix:string}|null
	 */
	private function parse_json_cell( $json, $allowed ) {
		$json = is_string( $json ) ? trim( $json ) : '';
		if ( $json === '' ) {
			return array( 'value' => '', 'unit' => (string) ( $allowed[0] ?? 'px' ), 'custom_suffix' => '' );
		}

		$dec = json_decode( $json, true );
		if ( ! is_array( $dec ) ) {
			return null;
		}

		$tuple = UnitFieldStoredJson::parse_range_decoded( $dec, $allowed );
		$tuple['custom_suffix'] = $this->sanitize_custom_suffix( (string) $tuple['custom_suffix'] );

		return $tuple;
	}

	/**
	 * @param string $c
	 * @return string
	 */
	private function sanitize_custom_suffix( $c ) {
		$c = preg_replace( '/[^a-zA-Z0-9%]/', '', is_string( $c ) ? $c : '' );
		if ( ! is_string( $c ) ) {
			return '';
		}

		return strlen( $c ) > 12 ? substr( $c, 0, 12 ) : $c;
	}

	/**
	 * @param float $num
	 * @param float $step
	 * @return float
	 */
	private function round_to_step( $num, $step ) {
		if ( $step <= 0 ) {
			return $num;
		}
		$inv = round( $num / $step );

		return $inv * $step;
	}

	/**
	 * @param float $num
	 * @param float $step
	 * @return string
	 */
	private function format_number_string( $num, $step ) {
		if ( floor( $step ) === $step && floor( $num ) === $num ) {
			return (string) (int) $num;
		}

		$s = (string) round( $num, 4 );
		$s = rtrim( rtrim( $s, '0' ), '.' );

		return $s === '' ? '0' : $s;
	}

	/**
	 * @param float $step
	 * @return string
	 */
	private function step_html_attr( $step ) {
		if ( $step <= 0 ) {
			return '1';
		}
		if ( $step < 0.0001 ) {
			return 'any';
		}

		return (string) $step;
	}
}
