<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Dimension;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Multi-slot numeric dimensions (e.g. top / right / bottom / left) with shared **units** and optional **link**.
 * Stored as JSON: **`u`**, **`c`** (custom suffix when **`u`** = **`custom`**), **`linked`** (bool), **`values`** => map **side_key** → numeric string (empty allowed per side).
 *
 * Register with **`'type' => 'dimension'`** (or **`Dimension::register()`** standalone). Keys: **`section_slug`**, **`id`**, **`title`**, optional **`description`**, **`default`** (partial array merged),
 * **`sides`** => list of **`array( 'key' => 'top', 'label' => 'TOP' )`** (1–6 entries, unique keys), **`units`** => ordered subset of **`px`**, **`%`**, **`rem`**, **`em`**, **`custom`** (default all five),
 * **`min`**, **`max`**, **`step`**, optional **`unit_label`** (UI-only, same semantics as **Range** with **`custom`** only), optional **`show_link`** (default **true**),
 * conditional **`required`**, **`html_required`**, **`tooltip`**, **`wrapper_class`**, optional **`responsive`** + **`device`**.
 */
final class Dimension {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	public const UNITS = array( 'px', '%', 'rem', 'em', 'custom' );

	private const MAX_SIDES = 6;

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
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), 19.42, 2 );
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
	 * Build CSS **margin** / **padding**-style shorthand: four lengths with one unit (or **`v`+`c`** per side when **`custom`**).
	 *
	 * @param string $json_or_empty Stored JSON from **`sto_options`**.
	 * @return string e.g. `10px 0 10px 0`, `1rem 2rem 1rem 2rem`, or empty if all sides empty / invalid.
	 */
	public static function value_to_css_shorthand( $json_or_empty ) {
		$s = is_string( $json_or_empty ) ? trim( $json_or_empty ) : '';
		if ( $s === '' ) {
			return '';
		}
		$dec = json_decode( $s, true );
		if ( ! is_array( $dec ) || empty( $dec['values'] ) || ! is_array( $dec['values'] ) ) {
			return '';
		}
		$u = isset( $dec['u'] ) ? sanitize_key( (string) $dec['u'] ) : 'px';
		$c = isset( $dec['c'] ) ? preg_replace( '/[^a-zA-Z0-9%]/', '', (string) $dec['c'] ) : '';
		if ( ! in_array( $u, self::UNITS, true ) ) {
			$u = 'px';
		}
		$parts = array();
		foreach ( $dec['values'] as $v ) {
			$v = is_scalar( $v ) ? trim( (string) $v ) : '';
			if ( $v === '' || ! is_numeric( $v ) ) {
				$parts[] = '';
			} elseif ( $u === 'custom' ) {
				$parts[] = $v . $c;
			} elseif ( in_array( $u, array( 'px', '%', 'rem', 'em' ), true ) ) {
				$parts[] = $v . $u;
			} else {
				$parts[] = '';
			}
		}
		if ( $parts === array() || count( array_filter( $parts, 'strlen' ) ) === 0 ) {
			return '';
		}

		return implode( ' ', $parts );
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

		$sides = $this->normalize_sides( isset( $field['sides'] ) ? $field['sides'] : null );

		$units_in = isset( $field['units'] ) && is_array( $field['units'] ) ? $field['units'] : self::UNITS;
		$allowed  = array();
		foreach ( $units_in as $u ) {
			$uk = sanitize_key( (string) $u );
			if ( in_array( $uk, self::UNITS, true ) ) {
				$allowed[] = $uk;
			}
		}
		if ( empty( $allowed ) ) {
			$allowed = self::UNITS;
		}

		$min = isset( $field['min'] ) && is_numeric( $field['min'] ) ? (float) $field['min'] : 0.0;
		$max = isset( $field['max'] ) && is_numeric( $field['max'] ) ? (float) $field['max'] : 1000.0;
		if ( $max < $min ) {
			$tmp = $max;
			$max = $min;
			$min = $tmp;
		}
		$step = isset( $field['step'] ) && is_numeric( $field['step'] ) ? (float) $field['step'] : 1.0;
		if ( $step <= 0 ) {
			$step = 1.0;
		}

		$def_state = $this->normalize_default_state( isset( $field['default'] ) ? $field['default'] : null, $sides, $allowed );

		$field['section_slug']             = $section_slug;
		$field['id']                     = $field_id;
		$field['title']                  = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']            = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class']          = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']               = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']                  = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['html_required']          = ! empty( $field['html_required'] );
		$field['sides']                  = $sides;
		$field['units_allowed']          = $allowed;
		$field['min']                    = $min;
		$field['max']                    = $max;
		$field['step']                   = $step;
		$field['default_state']          = $def_state;
		$field['unit_label']             = $this->sanitize_unit_label( isset( $field['unit_label'] ) ? $field['unit_label'] : '' );
		$field['show_link']              = ! isset( $field['show_link'] ) || ! empty( $field['show_link'] );
		$field['responsive_breakpoints'] = ResponsiveConfig::breakpoints_for_field( $field );

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}
		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
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
			return wp_json_encode( array( 'u' => 'px', 'c' => '', 'linked' => true, 'values' => array() ) );
		}

		$sides   = isset( $field['sides'] ) && is_array( $field['sides'] ) ? $field['sides'] : $this->default_sides();
		$allowed = isset( $field['units_allowed'] ) && is_array( $field['units_allowed'] ) ? $field['units_allowed'] : self::UNITS;
		$min     = isset( $field['min'] ) ? (float) $field['min'] : 0.0;
		$max     = isset( $field['max'] ) ? (float) $field['max'] : 1000.0;
		$step    = isset( $field['step'] ) ? (float) $field['step'] : 1.0;
		$def     = isset( $field['default_state'] ) && is_array( $field['default_state'] ) ? $field['default_state'] : $this->empty_state( $sides, $allowed );

		$parsed = $this->parse_json_cell( is_string( $raw ) ? $raw : '', $sides, $allowed );
		if ( $parsed === null ) {
			$parsed = $def;
		}

		$parsed['u'] = in_array( $parsed['u'], $allowed, true ) ? $parsed['u'] : (string) ( $allowed[0] ?? 'px' );
		$parsed['c'] = $this->sanitize_custom_suffix( (string) $parsed['c'] );
		if ( $parsed['u'] !== 'custom' ) {
			$parsed['c'] = '';
		}

		$ul = isset( $field['unit_label'] ) ? (string) $field['unit_label'] : '';
		if ( $this->dimension_label_replaces_custom_suffix_ui( $allowed, $ul ) ) {
			$parsed['c'] = '';
		}

		if ( empty( $field['show_link'] ) ) {
			$parsed['linked'] = false;
		}

		$keys = array();
		foreach ( $sides as $row ) {
			$k = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( $k !== '' ) {
				$keys[] = $k;
			}
		}

		$vals = isset( $parsed['values'] ) && is_array( $parsed['values'] ) ? $parsed['values'] : array();
		$outv = array();
		foreach ( $keys as $k ) {
			$cell = isset( $vals[ $k ] ) ? trim( (string) $vals[ $k ] ) : '';
			if ( $cell === '' ) {
				$outv[ $k ] = '';
				continue;
			}
			if ( ! is_numeric( $cell ) ) {
				$fb = isset( $def['values'][ $k ] ) ? trim( (string) $def['values'][ $k ] ) : '';
				$outv[ $k ] = ( $fb !== '' && is_numeric( $fb ) ) ? $this->format_number_string( max( $min, min( $max, (float) $fb ) ), $step ) : '';
				continue;
			}
			$num        = max( $min, min( $max, (float) $cell ) );
			$num        = $this->round_to_step( $num, $step > 0 ? $step : 1.0 );
			$outv[ $k ] = $this->format_number_string( $num, $step );
		}

		if ( ! empty( $parsed['linked'] ) && count( $outv ) > 0 ) {
			$first = '';
			foreach ( $keys as $k ) {
				if ( isset( $outv[ $k ] ) && $outv[ $k ] !== '' ) {
					$first = $outv[ $k ];
					break;
				}
			}
			if ( $first !== '' ) {
				foreach ( $keys as $k ) {
					$outv[ $k ] = $first;
				}
			}
		}

		return wp_json_encode(
			array(
				'u'      => $parsed['u'],
				'c'      => $parsed['c'],
				'linked' => ! empty( $parsed['linked'] ),
				'values' => $outv,
			)
		);
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
		$sides = isset( $field['sides'] ) && is_array( $field['sides'] ) ? $field['sides'] : $this->default_sides();
		$keys  = array();
		foreach ( $sides as $row ) {
			$k = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( $k !== '' ) {
				$keys[] = $k;
			}
		}
		if ( $keys === array() ) {
			return true;
		}

		if ( is_array( $raw ) && ResponsiveConfig::is_breakpoint_value_map( $raw ) ) {
			$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : array();
			foreach ( $bps as $bp ) {
				$bp   = sanitize_key( (string) $bp );
				$cell = array_key_exists( $bp, $raw ) ? (string) $raw[ $bp ] : '';
				if ( $this->json_missing_required_side( $cell, $keys ) ) {
					return true;
				}
			}

			return false;
		}

		$cell = is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' );

		return $this->json_missing_required_side( $cell, $keys );
	}

	/**
	 * @param array<int, string> $keys
	 */
	private function json_missing_required_side( $json, array $keys ) {
		$dec = json_decode( is_string( $json ) ? $json : '', true );
		if ( ! is_array( $dec ) || empty( $dec['values'] ) || ! is_array( $dec['values'] ) ) {
			return true;
		}
		foreach ( $keys as $k ) {
			$v = isset( $dec['values'][ $k ] ) ? trim( (string) $dec['values'][ $k ] ) : '';
			if ( $v === '' || ! is_numeric( $v ) ) {
				return true;
			}
		}

		return false;
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

		$sides       = isset( $field['sides'] ) && is_array( $field['sides'] ) ? $field['sides'] : $this->default_sides();
		$allowed     = isset( $field['units_allowed'] ) && is_array( $field['units_allowed'] ) ? $field['units_allowed'] : self::UNITS;
		$units_json  = wp_json_encode( array_values( $allowed ) );
		$def_state   = isset( $field['default_state'] ) && is_array( $field['default_state'] ) ? $field['default_state'] : $this->empty_state( $sides, $allowed );
		$default_json = wp_json_encode( $def_state );
		$sides_json   = wp_json_encode( $sides );
		$min          = (float) $field['min'];
		$max          = (float) $field['max'];
		$step         = (float) $field['step'];
		$step_attr    = $this->step_html_attr( $step );
		$show_link    = ! empty( $field['show_link'] );
		$unit_label   = isset( $field['unit_label'] ) ? (string) $field['unit_label'] : '';
		$suppress_suffix = $this->dimension_label_replaces_custom_suffix_ui( $allowed, $unit_label );

		$is_inner = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-dimension' );
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
				$value_map = $this->get_value_map( $field_id, $def_state, $bps_storage, $field );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $def_state;
				$json      = wp_json_encode( $cur );
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$suffix     = $field_id . '_' . $tabs_pane_bp;
				$this->render_dimension_control( $suffix, $input_name, $json, $default_json, $sides, $sides_json, $min, $max, $step, $step_attr, $allowed, $units_json, $show_link, $unit_label, $suppress_suffix );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $def_state, $bps_storage, $field );
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$cur     = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $def_state;
						$json    = wp_json_encode( $cur );
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$suffix     = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_dimension_control( $suffix, $input_name, $json, $default_json, $sides, $sides_json, $min, $max, $step, $step_attr, $allowed, $units_json, $show_link, $unit_label, $suppress_suffix );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$cur  = $this->get_option_state( $field_id, $def_state, $field );
				$json = wp_json_encode( $cur );
				$this->render_dimension_control( $field_id, 'sto_options[' . $field_id . ']', $json, $default_json, $sides, $sides_json, $min, $max, $step, $step_attr, $allowed, $units_json, $show_link, $unit_label, $suppress_suffix );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array{key:string,label:string}> $sides
	 * @param array<int, string>                        $allowed
	 * @param string                                    $sides_json
	 * @param array<int, string>                        $allowed
	 */
	private function render_dimension_control( $suffix, $input_name, $current_json, $default_json, array $sides, $sides_json, $min, $max, $step, $step_attr, array $allowed, $units_json, $show_link, $unit_label, $suppress_suffix ) {
		$parsed = $this->parse_json_cell( $current_json, $sides, $allowed );
		if ( $parsed === null ) {
			$parsed = $this->empty_state( $sides, $allowed );
		}
		$active_u = in_array( $parsed['u'], $allowed, true ) ? $parsed['u'] : $allowed[0];
		$linked   = ! empty( $parsed['linked'] );
		$forced_u = 1 === count( $allowed ) ? (string) $allowed[0] : '';
		?>
		<div
			class="sto-dimension"
			data-sto-dimension="1"
			data-sto-dimension-min="<?php echo esc_attr( (string) $min ); ?>"
			data-sto-dimension-max="<?php echo esc_attr( (string) $max ); ?>"
			data-sto-dimension-step="<?php echo esc_attr( (string) $step ); ?>"
			data-sto-dimension-default="<?php echo esc_attr( $default_json ); ?>"
			data-sto-dimension-units="<?php echo esc_attr( $units_json ); ?>"
			data-sto-dimension-sides="<?php echo esc_attr( $sides_json ); ?>"
			<?php if ( ! $show_link ) : ?>
				data-sto-dimension-no-link="1"
			<?php endif; ?>
			<?php if ( $suppress_suffix ) : ?>
				data-sto-dimension-custom-suffix="0"
			<?php endif; ?>
			<?php if ( $forced_u !== '' ) : ?>
				data-sto-dimension-forced-unit="<?php echo esc_attr( $forced_u ); ?>"
			<?php endif; ?>
		>
			<?php
			$show_rail = ( $unit_label !== '' ) || ! $suppress_suffix;
			?>
			<div class="sto-dimension__row">
				<div class="sto-dimension__matrix" role="group" aria-label="<?php esc_attr_e( 'Dimension values', 'simple-theme-options' ); ?>">
				<?php
				$vals = isset( $parsed['values'] ) && is_array( $parsed['values'] ) ? $parsed['values'] : array();
				foreach ( $sides as $row ) :
					$k = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
					if ( $k === '' ) {
						continue;
					}
					$lab = isset( $row['label'] ) ? (string) $row['label'] : strtoupper( $k );
					$v   = isset( $vals[ $k ] ) ? trim( (string) $vals[ $k ] ) : '';
					$vid = 'sto-dimension-n-' . $suffix . '-' . $k;
					?>
				<div class="sto-dimension__cell">
					<input
						type="number"
						class="sto-dimension__input"
						id="<?php echo esc_attr( $vid ); ?>"
						data-sto-dimension-key="<?php echo esc_attr( $k ); ?>"
						min="<?php echo esc_attr( (string) $min ); ?>"
						max="<?php echo esc_attr( (string) $max ); ?>"
						step="<?php echo esc_attr( $step_attr ); ?>"
						value="<?php echo esc_attr( $v ); ?>"
						inputmode="decimal"
						aria-label="<?php echo esc_attr( $lab ); ?>"
					/>
					<span class="sto-dimension__slot-label"><?php echo esc_html( $lab ); ?></span>
				</div>
					<?php
				endforeach;
				if ( $show_link ) :
					?>
				<div class="sto-dimension__link-cell">
					<button
						type="button"
						class="sto-dimension__link<?php echo $linked ? ' sto-is-active' : ''; ?>"
						data-sto-dimension-link="1"
						aria-pressed="<?php echo $linked ? 'true' : 'false'; ?>"
						aria-label="<?php esc_attr_e( 'Link all sides to the same value', 'simple-theme-options' ); ?>"
						title="<?php esc_attr_e( 'Link values', 'simple-theme-options' ); ?>"
					><i class="fa-light <?php echo $linked ? 'fa-link' : 'fa-link-slash'; ?>" aria-hidden="true"></i></button>
				</div>
					<?php
				endif;
				?>
				</div>
			<?php if ( $show_rail ) : ?>
				<div class="sto-dimension__rail">
				<?php if ( $unit_label !== '' ) : ?>
					<span class="sto-dimension__unit-label"><?php echo esc_html( $unit_label ); ?></span>
				<?php endif; ?>
				<?php if ( ! $suppress_suffix ) : ?>
				<div class="sto-dimension__units" role="group" aria-label="<?php esc_attr_e( 'Unit', 'simple-theme-options' ); ?>">
					<?php if ( count( $allowed ) > 1 ) : ?>
						<?php foreach ( $allowed as $u ) : ?>
						<button
							type="button"
							class="sto-dimension__unit<?php echo $u === $active_u ? ' sto-is-active' : ''; ?>"
							data-sto-dimension-unit="<?php echo esc_attr( $u ); ?>"
						><?php echo esc_html( $this->format_unit_label( $u ) ); ?></button>
						<?php endforeach; ?>
					<?php else : ?>
						<span class="sto-dimension__unit-badge"><?php echo esc_html( $this->format_unit_label( $allowed[0] ) ); ?></span>
					<?php endif; ?>
				</div>
				<input
					type="text"
					class="sto-dimension__custom-suffix"
					id="<?php echo esc_attr( 'sto-dimension-c-' . $suffix ); ?>"
					value="<?php echo esc_attr( $parsed['c'] ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. vw', 'simple-theme-options' ); ?>"
					autocomplete="off"
					<?php echo ( $active_u === 'custom' && ! $suppress_suffix ) ? '' : ' hidden disabled'; ?>
				/>
				<?php endif; ?>
				</div>
			<?php endif; ?>
			</div>
			<input type="hidden" class="sto-dimension-value" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $current_json ); ?>" />
		</div>
		<?php
	}

	/**
	 * @return array<int, array{key:string,label:string}>
	 */
	private function default_sides() {
		return array(
			array( 'key' => 'top', 'label' => __( 'TOP', 'simple-theme-options' ) ),
			array( 'key' => 'right', 'label' => __( 'RIGHT', 'simple-theme-options' ) ),
			array( 'key' => 'bottom', 'label' => __( 'BOTTOM', 'simple-theme-options' ) ),
			array( 'key' => 'left', 'label' => __( 'LEFT', 'simple-theme-options' ) ),
		);
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array{key:string,label:string}>
	 */
	private function normalize_sides( $raw ) {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$k = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
				if ( $k === '' ) {
					continue;
				}
				$lab = isset( $row['label'] ) ? wp_strip_all_tags( (string) $row['label'] ) : strtoupper( $k );
				$out[] = array( 'key' => $k, 'label' => $lab );
				if ( count( $out ) >= self::MAX_SIDES ) {
					break;
				}
			}
		}
		if ( empty( $out ) ) {
			return $this->default_sides();
		}

		return $out;
	}

	/**
	 * @param array<int, array{key:string,label:string}> $sides
	 * @param array<int, string>                        $allowed
	 * @return array{u:string,c:string,linked:bool,values:array<string,string>}
	 */
	private function empty_state( array $sides, array $allowed ) {
		$vals = array();
		foreach ( $sides as $row ) {
			$k = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( $k !== '' ) {
				$vals[ $k ] = '';
			}
		}

		return array(
			'u'      => (string) ( $allowed[0] ?? 'px' ),
			'c'      => '',
			'linked' => true,
			'values' => $vals,
		);
	}

	/**
	 * @param mixed                                      $raw
	 * @param array<int, array{key:string,label:string}> $sides
	 * @param array<int, string>                        $allowed
	 * @return array{u:string,c:string,linked:bool,values:array<string,string>}
	 */
	private function normalize_default_state( $raw, array $sides, array $allowed ) {
		$base = $this->empty_state( $sides, $allowed );
		if ( ! is_array( $raw ) ) {
			return $base;
		}
		if ( isset( $raw['u'] ) ) {
			$uu = sanitize_key( (string) $raw['u'] );
			if ( in_array( $uu, $allowed, true ) ) {
				$base['u'] = $uu;
			}
		}
		if ( isset( $raw['c'] ) ) {
			$base['c'] = $this->sanitize_custom_suffix( (string) $raw['c'] );
		}
		if ( array_key_exists( 'linked', $raw ) ) {
			$base['linked'] = ! empty( $raw['linked'] );
		}
		if ( isset( $raw['values'] ) && is_array( $raw['values'] ) ) {
			foreach ( $base['values'] as $k => $_ ) {
				if ( isset( $raw['values'][ $k ] ) ) {
					$base['values'][ $k ] = trim( (string) $raw['values'][ $k ] );
				}
			}
		}

		return $base;
	}

	/**
	 * @param array<int, array{key:string,label:string}> $sides
	 * @param array<int, string>                        $allowed
	 * @return array<string, array{u:string,c:string,linked:bool,values:array<string,string>>>
	 */
	private function get_value_map( $field_id, array $default_state, array $breakpoints, array $field ) {
		$sides   = isset( $field['sides'] ) && is_array( $field['sides'] ) ? $field['sides'] : $this->default_sides();
		$allowed = isset( $field['units_allowed'] ) && is_array( $field['units_allowed'] ) ? $field['units_allowed'] : self::UNITS;

		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return $this->fill_breakpoint_states( $breakpoints, $default_state );
		}

		$stored = $saved_options[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$map = array();
			foreach ( $breakpoints as $bp ) {
				$bp   = sanitize_key( (string) $bp );
				$cell = isset( $stored[ $bp ] ) ? (string) $stored[ $bp ] : '';
				$t    = $this->parse_json_cell( $cell, $sides, $allowed );
				$map[ $bp ] = $t ? $t : $default_state;
			}

			return $map;
		}

		$scalar = is_scalar( $stored ) ? (string) $stored : '';
		$t0     = $this->parse_json_cell( $scalar, $sides, $allowed );
		$base   = $t0 ? $t0 : $default_state;

		return $this->fill_breakpoint_states( $breakpoints, $base );
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @param array{u:string,c:string,linked:bool,values:array<string,string>} $state
	 * @return array<string, array{u:string,c:string,linked:bool,values:array<string,string>>>
	 */
	private function fill_breakpoint_states( array $breakpoints, array $state ) {
		$out = array();
		foreach ( $breakpoints as $bp ) {
			$bp         = sanitize_key( (string) $bp );
			$out[ $bp ] = $state;
		}

		return $out;
	}

	/**
	 * @param array{u:string,c:string,linked:bool,values:array<string,string>} $default_state
	 * @return array{u:string,c:string,linked:bool,values:array<string,string>}
	 */
	private function get_option_state( $field_id, array $default_state, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return $default_state;
		}
		$st      = $saved_options[ $field_id ];
		$sides   = isset( $field['sides'] ) && is_array( $field['sides'] ) ? $field['sides'] : $this->default_sides();
		$allowed = isset( $field['units_allowed'] ) && is_array( $field['units_allowed'] ) ? $field['units_allowed'] : self::UNITS;
		if ( is_array( $st ) && ResponsiveConfig::is_breakpoint_value_map( $st ) ) {
			$slice = ResponsiveConfig::value_for_required_eval( $st );
			$t     = $this->parse_json_cell( is_scalar( $slice ) ? (string) $slice : '', $sides, $allowed );

			return $t ? $t : $default_state;
		}
		$t = $this->parse_json_cell( is_scalar( $st ) ? (string) $st : '', $sides, $allowed );

		return $t ? $t : $default_state;
	}

	/**
	 * @param string                                      $json
	 * @param array<int, array{key:string,label:string}> $sides
	 * @param array<int, string>                        $allowed
	 * @return array{u:string,c:string,linked:bool,values:array<string,string>}|null
	 */
	private function parse_json_cell( $json, array $sides, array $allowed ) {
		$json = is_string( $json ) ? trim( $json ) : '';
		if ( $json === '' ) {
			return $this->empty_state( $sides, $allowed );
		}
		$dec = json_decode( $json, true );
		if ( ! is_array( $dec ) ) {
			return null;
		}
		$u = isset( $dec['u'] ) ? sanitize_key( (string) $dec['u'] ) : (string) ( $allowed[0] ?? 'px' );
		if ( ! in_array( $u, $allowed, true ) ) {
			$u = (string) ( $allowed[0] ?? 'px' );
		}
		$c      = isset( $dec['c'] ) ? $this->sanitize_custom_suffix( (string) $dec['c'] ) : '';
		$linked = ! empty( $dec['linked'] );
		$vals   = isset( $dec['values'] ) && is_array( $dec['values'] ) ? $dec['values'] : array();
		$outv   = array();
		foreach ( $sides as $row ) {
			$k = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( $k === '' ) {
				continue;
			}
			$outv[ $k ] = isset( $vals[ $k ] ) ? trim( (string) $vals[ $k ] ) : '';
		}

		return array(
			'u'      => $u,
			'c'      => $u === 'custom' ? $c : '',
			'linked' => $linked,
			'values' => $outv,
		);
	}

	/**
	 * @param array<int, string> $allowed
	 * @param string             $unit_label
	 */
	private function dimension_label_replaces_custom_suffix_ui( array $allowed, $unit_label ) {
		return $unit_label !== '' && 1 === count( $allowed ) && isset( $allowed[0] ) && $allowed[0] === 'custom';
	}

	/**
	 * @param string $u
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
	 * @param mixed $raw
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
	 * @param string $c
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
