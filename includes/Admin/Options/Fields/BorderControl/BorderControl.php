<?php
namespace SimpleThemeOptions\Admin\Options\Fields\BorderControl;

use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Composite "Border" field — radius + style + width + color packaged into one option key (JSON).
 *
 * Stored shape:
 *   {
 *     "radius":      "0",
 *     "radius_unit": "px",
 *     "style":       "none",
 *     "width":       "1",
 *     "width_unit":  "px",
 *     "color":       "#000000"
 *   }
 *
 * Per-breakpoint (when `responsive` is set): the same JSON string is saved under `sto_options[id][bp]`.
 *
 * UI: row title + reset (↻) button, then an inline "Edit settings" pill that toggles a floating popover
 * containing the four sub-controls. Hidden JSON input `sto_options[id]` (or `sto_options[id][bp]`)
 * carries the value to the save handler — `getOptionFieldValue` reads that name like Typography /
 * BackgroundControl, so conditional `required` and HTML5 form submit both work unchanged.
 */
final class BorderControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const ALLOWED_STYLES       = array( 'none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset' );
	private const ALLOWED_RADIUS_UNITS = array( 'px', '%', 'em', 'rem' );
	private const ALLOWED_WIDTH_UNITS  = array( 'px', 'em', 'rem' );
	private const DEFAULT_MIN_RADIUS   = 0;
	private const DEFAULT_MAX_RADIUS   = 100;
	private const DEFAULT_MIN_WIDTH    = 0;
	private const DEFAULT_MAX_WIDTH    = 20;
	private const ALL_FEATURES         = array( 'radius', 'style', 'width', 'color' );

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
		// After BackgroundControl (19.25), before Range (19.35).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), 19.30, 2 );
	}

	/**
	 * @param array<string, mixed> $field Keys:
	 *   - `section_slug` (required, standalone only)
	 *   - `id` (required)
	 *   - `title`, `description`
	 *   - `default` => array( `radius`, `radius_unit`, `style`, `width`, `width_unit`, `color` )
	 *   - `features` => ordered subset of `radius`, `style`, `width`, `color` (default: all four)
	 *   - `min_radius`, `max_radius`, `min_width`, `max_width`
	 *   - `radius_units` => subset of `px`, `%`, `em`, `rem` (default `array( 'px' )`)
	 *   - `width_units` => subset of `px`, `em`, `rem` (default `array( 'px' )`)
	 *   - `alpha` (bool, default `true`)
	 *   - `palettes` => list of hex / rgba presets passed to wp-color-picker-alpha
	 *   - `required`, `tooltip`, `wrapper_class`
	 *   - `responsive` (`true` or non-empty array) + optional `device` list
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

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['alpha']         = ! array_key_exists( 'alpha', $field ) || (bool) $field['alpha'];

		$field['features']     = $this->normalize_features( $field['features'] ?? null );
		$field['radius_units'] = $this->normalize_unit_list( $field['radius_units'] ?? null, self::ALLOWED_RADIUS_UNITS );
		$field['width_units']  = $this->normalize_unit_list( $field['width_units'] ?? null, self::ALLOWED_WIDTH_UNITS );
		$field['min_radius']   = $this->normalize_int( $field['min_radius'] ?? null, self::DEFAULT_MIN_RADIUS );
		$field['max_radius']   = $this->normalize_int( $field['max_radius'] ?? null, self::DEFAULT_MAX_RADIUS );
		$field['min_width']    = $this->normalize_int( $field['min_width'] ?? null, self::DEFAULT_MIN_WIDTH );
		$field['max_width']    = $this->normalize_int( $field['max_width'] ?? null, self::DEFAULT_MAX_WIDTH );
		if ( $field['max_radius'] < $field['min_radius'] ) {
			$field['max_radius'] = $field['min_radius'];
		}
		if ( $field['max_width'] < $field['min_width'] ) {
			$field['max_width'] = $field['min_width'];
		}

		$palettes = isset( $field['palettes'] ) && is_array( $field['palettes'] ) ? $field['palettes'] : array();
		$clean    = array();
		foreach ( $palettes as $p ) {
			$hex = Color::instance()->sanitize_stored_value( (string) $p );
			if ( $hex !== '' ) {
				$clean[] = $hex;
			}
		}
		$field['palettes'] = $clean;

		$field['default'] = $this->parse_default_array( isset( $field['default'] ) ? $field['default'] : array() );

		$bps                             = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

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
	 * Render hook for `sto_render_section_content`.
	 *
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
	 * @param mixed $raw Posted JSON string (or per-breakpoint map of JSON strings).
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return $this->encode_default();
		}
		$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->sanitize_stored_value( is_string( $cell ) ? $cell : '', $field );
			}

			return $out;
		}

		return $this->sanitize_stored_value( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ), $field );
	}

	/**
	 * Sanitize one JSON cell using the field's registered constraints.
	 *
	 * @param string                    $raw_json
	 * @param array<string, mixed>|null $field
	 * @return string Sanitized JSON string.
	 */
	public function sanitize_stored_value( $raw_json, $field = null ) {
		$data = json_decode( is_string( $raw_json ) ? $raw_json : '', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$defaults = $this->parse_default_array( $field && isset( $field['default'] ) && is_array( $field['default'] ) ? $field['default'] : array() );

		$min_r = $field && isset( $field['min_radius'] ) ? (int) $field['min_radius'] : self::DEFAULT_MIN_RADIUS;
		$max_r = $field && isset( $field['max_radius'] ) ? (int) $field['max_radius'] : self::DEFAULT_MAX_RADIUS;
		$min_w = $field && isset( $field['min_width'] ) ? (int) $field['min_width'] : self::DEFAULT_MIN_WIDTH;
		$max_w = $field && isset( $field['max_width'] ) ? (int) $field['max_width'] : self::DEFAULT_MAX_WIDTH;

		$radius_units = $field && isset( $field['radius_units'] ) && is_array( $field['radius_units'] ) ? $field['radius_units'] : array( 'px' );
		$width_units  = $field && isset( $field['width_units'] ) && is_array( $field['width_units'] ) ? $field['width_units'] : array( 'px' );

		$radius      = isset( $data['radius'] ) ? $this->clamp_number( (string) $data['radius'], $min_r, $max_r, (string) $defaults['radius'] ) : (string) $defaults['radius'];
		$radius_unit = isset( $data['radius_unit'] ) && in_array( (string) $data['radius_unit'], $radius_units, true ) ? (string) $data['radius_unit'] : ( in_array( (string) $defaults['radius_unit'], $radius_units, true ) ? (string) $defaults['radius_unit'] : (string) $radius_units[0] );

		$style = isset( $data['style'] ) ? sanitize_key( (string) $data['style'] ) : (string) $defaults['style'];
		if ( ! in_array( $style, self::ALLOWED_STYLES, true ) ) {
			$style = (string) $defaults['style'];
		}

		$width      = isset( $data['width'] ) ? $this->clamp_number( (string) $data['width'], $min_w, $max_w, (string) $defaults['width'] ) : (string) $defaults['width'];
		$width_unit = isset( $data['width_unit'] ) && in_array( (string) $data['width_unit'], $width_units, true ) ? (string) $data['width_unit'] : ( in_array( (string) $defaults['width_unit'], $width_units, true ) ? (string) $defaults['width_unit'] : (string) $width_units[0] );

		$color = isset( $data['color'] ) ? Color::instance()->sanitize_stored_value( (string) $data['color'] ) : '';
		if ( $color === '' ) {
			$color = (string) $defaults['color'];
		}

		return (string) wp_json_encode(
			array(
				'radius'      => $radius,
				'radius_unit' => $radius_unit,
				'style'       => $style,
				'width'       => $width,
				'width_unit'  => $width_unit,
				'color'       => $color,
			)
		);
	}

	/**
	 * @param string $field_id
	 * @return string CSS shorthand-ish: e.g. `1px solid #000` (no radius — that's a separate property).
	 *                Reads the eval / `xxl` slice when the value is a breakpoint map.
	 */
	public function value_to_css_border( $field_id ) {
		$arr = $this->get_value_array( $field_id );
		if ( ! is_array( $arr ) || empty( $arr['style'] ) || 'none' === $arr['style'] ) {
			return 'none';
		}
		$w = ( (string) $arr['width'] ) . ( (string) $arr['width_unit'] );
		$c = (string) $arr['color'];

		return trim( $w . ' ' . $arr['style'] . ( $c !== '' ? ' ' . $c : '' ) );
	}

	/**
	 * @param string $field_id
	 * @return array{radius: string, radius_unit: string, style: string, width: string, width_unit: string, color: string}|null
	 */
	public function get_value_array( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return null;
		}
		$defaults = $this->parse_default_array( $field['default'] ?? array() );

		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			return $defaults;
		}
		$raw = $saved[ $field_id ];
		if ( is_array( $raw ) && ResponsiveConfig::is_breakpoint_value_map( $raw ) ) {
			$slice = ResponsiveConfig::raw_value_at_breakpoint( $raw, ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT );
			if ( null === $slice ) {
				$first = reset( $raw );
				$slice = is_scalar( $first ) ? (string) $first : '';
			} else {
				$slice = is_scalar( $slice ) ? (string) $slice : '';
			}
			$decoded = json_decode( $slice, true );

			return is_array( $decoded ) ? $this->merge_decoded( $defaults, $decoded ) : $defaults;
		}
		if ( ! is_string( $raw ) ) {
			return $defaults;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return $defaults;
		}

		return $this->merge_decoded( $defaults, $decoded );
	}

	/**
	 * @param array<string, mixed> $field
	 * @param 'default'|'group_inner' $context
	 */
	public function render_field_markup( $field, $context = 'default' ) {
		if ( ! is_array( $field ) ) {
			return;
		}

		$field_id      = (string) $field['id'];
		$title         = (string) $field['title'];
		$description   = (string) $field['description'];
		$wrapper_class = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$tooltip       = FieldTitle::get_tooltip_config( $field );
		$defaults      = $this->parse_default_array( $field['default'] ?? array() );
		$bps_storage   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$is_inner      = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-border' );
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

		// Reset trigger lives in the leading column so it sits right after the title (matches the screenshots).
		$reset_html = $this->build_reset_markup( $field_id, $defaults );

		$trailing_html = ( $tabs_pane_bp === '' && ! empty( $bps_storage ) ) ? ResponsiveControl::toolbar_markup( $bps_storage, $field_id ) : '';
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
			<?php
			// FieldTitle expects a `?` help control immediately after the title; we append the reset button as
			// part of the leading column by hooking it via a tiny inline wrapper after FieldTitle prints.
			ob_start();
			FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_inner, $trailing_html );
			$heading_html = (string) ob_get_clean();
			$heading_html = $this->inject_reset_into_leading( $heading_html, $reset_html );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted plugin-generated markup; reset button uses esc_attr internally.
			echo $heading_html;
			?>

			<?php if ( $tabs_pane_bp !== '' && $bps_storage ) : ?>
				<?php
				$value_map = $this->get_value_map( $field_id, $defaults, $bps_storage );
				$json_one  = isset( $value_map[ $tabs_pane_bp ] ) ? (string) $value_map[ $tabs_pane_bp ] : (string) wp_json_encode( $defaults );
				$this->render_widget( $field, $json_one, 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']', $field_id . '_' . $tabs_pane_bp );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $defaults, $bps_storage );
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$json_one = isset( $value_map[ $bp ] ) ? (string) $value_map[ $bp ] : (string) wp_json_encode( $defaults );
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_widget( $field, $json_one, 'sto_options[' . $field_id . '][' . $bp . ']', $field_id . '_' . $bp );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$json_value = (string) wp_json_encode( $this->merge_decoded( $defaults, $this->get_decoded_for_field( $field_id ) ) );
				$this->render_widget( $field, $json_value, 'sto_options[' . $field_id . ']', $field_id );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One "Edit settings" button + popover panel + hidden JSON input.
	 *
	 * @param array<string, mixed> $field
	 * @param string               $json_value
	 * @param string               $hidden_name
	 * @param string               $id_fragment Unique per pane / breakpoint to make sub-input ids unique.
	 */
	private function render_widget( array $field, $json_value, $hidden_name, $id_fragment ) {
		$features     = isset( $field['features'] ) && is_array( $field['features'] ) ? $field['features'] : self::ALL_FEATURES;
		$defaults     = $this->parse_default_array( $field['default'] ?? array() );
		$cur          = $this->merge_decoded( $defaults, json_decode( (string) $json_value, true ) ?: array() );
		$use_alpha    = ! empty( $field['alpha'] );
		$radius_units = isset( $field['radius_units'] ) && is_array( $field['radius_units'] ) ? $field['radius_units'] : array( 'px' );
		$width_units  = isset( $field['width_units'] ) && is_array( $field['width_units'] ) ? $field['width_units'] : array( 'px' );
		$min_r        = isset( $field['min_radius'] ) ? (int) $field['min_radius'] : self::DEFAULT_MIN_RADIUS;
		$max_r        = isset( $field['max_radius'] ) ? (int) $field['max_radius'] : self::DEFAULT_MAX_RADIUS;
		$min_w        = isset( $field['min_width'] ) ? (int) $field['min_width'] : self::DEFAULT_MIN_WIDTH;
		$max_w        = isset( $field['max_width'] ) ? (int) $field['max_width'] : self::DEFAULT_MAX_WIDTH;
		$palettes     = isset( $field['palettes'] ) && is_array( $field['palettes'] ) ? $field['palettes'] : array();
		$palettes_json = ! empty( $palettes ) ? (string) wp_json_encode( $palettes ) : '';
		$default_color = $defaults['color'] !== '' ? $defaults['color'] : '#000000';
		$id_fragment   = sanitize_key( $id_fragment );

		$style_labels = array(
			'none'   => __( 'None', 'simple-theme-options' ),
			'solid'  => __( 'Solid', 'simple-theme-options' ),
			'dashed' => __( 'Dashed', 'simple-theme-options' ),
			'dotted' => __( 'Dotted', 'simple-theme-options' ),
			'double' => __( 'Double', 'simple-theme-options' ),
			'groove' => __( 'Groove', 'simple-theme-options' ),
			'ridge'  => __( 'Ridge', 'simple-theme-options' ),
			'inset'  => __( 'Inset', 'simple-theme-options' ),
			'outset' => __( 'Outset', 'simple-theme-options' ),
		);
		?>
		<div
			class="sto-border-control"
			data-sto-border-control
			data-field-id="<?php echo esc_attr( $id_fragment ); ?>"
			data-sto-border-defaults="<?php echo esc_attr( (string) wp_json_encode( $defaults ) ); ?>"
		>
			<button
				type="button"
				class="sto-border-edit button"
				data-sto-border-edit-toggle
				aria-expanded="false"
				aria-haspopup="dialog"
			>
				<i class="fa-light fa-gear" aria-hidden="true"></i>
				<span class="sto-border-edit__label"><?php esc_html_e( 'Edit settings', 'simple-theme-options' ); ?></span>
			</button>

			<div class="sto-border-popover" role="dialog" aria-label="<?php esc_attr_e( 'Border settings', 'simple-theme-options' ); ?>" hidden>
				<?php if ( in_array( 'radius', $features, true ) ) : ?>
					<div class="sto-border-popover__row" data-sto-border-section="radius">
						<div class="sto-border-popover__label"><?php esc_html_e( 'Border radius', 'simple-theme-options' ); ?></div>
						<div class="sto-border-popover__inputs">
							<input
								type="range"
								class="sto-border-popover__slider"
								data-sto-border-input="radius"
								min="<?php echo esc_attr( (string) $min_r ); ?>"
								max="<?php echo esc_attr( (string) $max_r ); ?>"
								step="1"
								value="<?php echo esc_attr( $this->clamp_number( (string) $cur['radius'], $min_r, $max_r, (string) $defaults['radius'] ) ); ?>"
								aria-label="<?php esc_attr_e( 'Border radius slider', 'simple-theme-options' ); ?>"
							/>
							<input
								type="number"
								class="sto-border-popover__number"
								data-sto-border-input="radius"
								min="<?php echo esc_attr( (string) $min_r ); ?>"
								max="<?php echo esc_attr( (string) $max_r ); ?>"
								step="1"
								value="<?php echo esc_attr( $this->clamp_number( (string) $cur['radius'], $min_r, $max_r, (string) $defaults['radius'] ) ); ?>"
								aria-label="<?php esc_attr_e( 'Border radius number', 'simple-theme-options' ); ?>"
							/>
							<?php $this->render_unit_chip( 'radius', $cur['radius_unit'], $radius_units ); ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( in_array( 'style', $features, true ) ) : ?>
					<div class="sto-border-popover__row" data-sto-border-section="style">
						<div class="sto-border-popover__label"><?php esc_html_e( 'Border style', 'simple-theme-options' ); ?></div>
						<select
							class="sto-border-popover__select"
							data-sto-border-input="style"
							aria-label="<?php esc_attr_e( 'Border style', 'simple-theme-options' ); ?>"
						>
							<?php foreach ( self::ALLOWED_STYLES as $sk ) : ?>
								<option value="<?php echo esc_attr( $sk ); ?>" <?php selected( (string) $cur['style'], $sk ); ?>>
									<?php echo esc_html( isset( $style_labels[ $sk ] ) ? (string) $style_labels[ $sk ] : ucfirst( $sk ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<?php if ( in_array( 'width', $features, true ) ) : ?>
					<div class="sto-border-popover__row" data-sto-border-section="width">
						<div class="sto-border-popover__label"><?php esc_html_e( 'Border width', 'simple-theme-options' ); ?></div>
						<div class="sto-border-popover__inputs">
							<input
								type="range"
								class="sto-border-popover__slider"
								data-sto-border-input="width"
								min="<?php echo esc_attr( (string) $min_w ); ?>"
								max="<?php echo esc_attr( (string) $max_w ); ?>"
								step="1"
								value="<?php echo esc_attr( $this->clamp_number( (string) $cur['width'], $min_w, $max_w, (string) $defaults['width'] ) ); ?>"
								aria-label="<?php esc_attr_e( 'Border width slider', 'simple-theme-options' ); ?>"
							/>
							<input
								type="number"
								class="sto-border-popover__number"
								data-sto-border-input="width"
								min="<?php echo esc_attr( (string) $min_w ); ?>"
								max="<?php echo esc_attr( (string) $max_w ); ?>"
								step="1"
								value="<?php echo esc_attr( $this->clamp_number( (string) $cur['width'], $min_w, $max_w, (string) $defaults['width'] ) ); ?>"
								aria-label="<?php esc_attr_e( 'Border width number', 'simple-theme-options' ); ?>"
							/>
							<?php $this->render_unit_chip( 'width', $cur['width_unit'], $width_units ); ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( in_array( 'color', $features, true ) ) : ?>
					<div class="sto-border-popover__row" data-sto-border-section="color">
						<div class="sto-border-popover__label"><?php esc_html_e( 'Border color', 'simple-theme-options' ); ?></div>
						<div
							class="sto-color-wrap<?php echo $use_alpha ? ' sto-color--alpha' : ''; ?>"
							<?php if ( $palettes_json ) : ?>
								data-sto-palettes="<?php echo esc_attr( $palettes_json ); ?>"
							<?php endif; ?>
						>
							<input
								type="text"
								class="sto-color-input sto-border-popover__color"
								data-sto-border-input="color"
								data-default-color="<?php echo esc_attr( $default_color ); ?>"
								data-sto-default="<?php echo esc_attr( $default_color ); ?>"
								<?php if ( $use_alpha ) : ?>
									data-alpha-enabled="true"
									data-type="full"
									data-alpha-custom-width="0"
								<?php endif; ?>
								value="<?php echo esc_attr( (string) $cur['color'] ); ?>"
								autocomplete="off"
							/>
							<button
								type="button"
								class="sto-color-reset"
								aria-label="<?php esc_attr_e( 'Reset border color', 'simple-theme-options' ); ?>"
								title="<?php esc_attr_e( 'Reset to default', 'simple-theme-options' ); ?>"
							>
								<i class="fa-light fa-arrow-rotate-left" aria-hidden="true"></i>
							</button>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<input
				type="hidden"
				class="sto-border-value"
				name="<?php echo esc_attr( $hidden_name ); ?>"
				value="<?php echo esc_attr( (string) wp_json_encode( $cur ) ); ?>"
			/>
		</div>
		<?php
	}

	/**
	 * Single-unit fields render a static chip; multi-unit fields render a small toggle button list.
	 * The selected unit is mirrored back into the hidden JSON by `sto-border-control.js`.
	 *
	 * @param string             $kind    Either `radius` or `width` (drives the hidden-input key suffix).
	 * @param string             $current Currently selected unit value.
	 * @param array<int, string> $units   Allow-list of units configured for this section.
	 */
	private function render_unit_chip( $kind, $current, array $units ) {
		$current = (string) $current;
		if ( ! in_array( $current, $units, true ) ) {
			$current = (string) $units[0];
		}
		if ( count( $units ) === 1 ) {
			?>
			<span class="sto-border-popover__unit sto-border-popover__unit--locked" data-sto-border-input="<?php echo esc_attr( $kind . '_unit' ); ?>" data-sto-border-unit-value="<?php echo esc_attr( $current ); ?>">
				<?php echo esc_html( strtoupper( $current ) ); ?>
			</span>
			<?php

			return;
		}
		?>
		<span class="sto-border-popover__unit sto-border-popover__unit--toggle" role="group" aria-label="<?php echo esc_attr( ucfirst( $kind ) . ' unit' ); ?>" data-sto-border-input="<?php echo esc_attr( $kind . '_unit' ); ?>">
			<?php foreach ( $units as $u ) : ?>
				<?php $active = ( $u === $current ); ?>
				<button
					type="button"
					class="sto-border-popover__unit-btn<?php echo $active ? ' is-active' : ''; ?>"
					data-sto-border-unit-value="<?php echo esc_attr( $u ); ?>"
					aria-pressed="<?php echo $active ? 'true' : 'false'; ?>"
				><?php echo esc_html( strtoupper( $u ) ); ?></button>
			<?php endforeach; ?>
		</span>
		<?php
	}

	/**
	 * Reset-to-default markup; rendered inside the title row's leading column (after the title text).
	 */
	private function build_reset_markup( $field_id, array $defaults ) {
		$defaults_json = (string) wp_json_encode( $defaults );

		return sprintf(
			'<button type="button" class="sto-border-row-reset" data-sto-border-row-reset data-sto-border-defaults="%s" aria-label="%s" title="%s"><i class="fa-light fa-arrow-rotate-left" aria-hidden="true"></i></button>',
			esc_attr( $defaults_json ),
			esc_attr__( 'Reset border to default', 'simple-theme-options' ),
			esc_attr__( 'Reset to default', 'simple-theme-options' )
		);
	}

	/**
	 * `FieldTitle::render_heading` keeps the `?` help control inside `__leading`; we splice the reset
	 * button into the **end** of that container so it sits right after the title (and after the help
	 * control when one is present).
	 *
	 * @param string $heading_html
	 * @param string $reset_html
	 * @return string
	 */
	private function inject_reset_into_leading( $heading_html, $reset_html ) {
		if ( $heading_html === '' || $reset_html === '' ) {
			return $heading_html;
		}
		// First closing `</div>` after a `__leading` opening tag belongs to the leading container.
		$pos = strpos( $heading_html, 'sto-field-title-row__leading' );
		if ( false === $pos ) {
			return $heading_html;
		}
		$close = strpos( $heading_html, '</div>', $pos );
		if ( false === $close ) {
			return $heading_html;
		}

		return substr( $heading_html, 0, $close ) . $reset_html . substr( $heading_html, $close );
	}

	/**
	 * @param array<string, mixed> $defaults
	 * @return array{radius: string, radius_unit: string, style: string, width: string, width_unit: string, color: string}
	 */
	private function parse_default_array( $defaults ) {
		if ( ! is_array( $defaults ) ) {
			$defaults = array();
		}
		$radius      = isset( $defaults['radius'] ) ? (string) $defaults['radius'] : '0';
		$radius_unit = isset( $defaults['radius_unit'] ) ? sanitize_key( (string) $defaults['radius_unit'] ) : 'px';
		if ( ! in_array( $radius_unit, self::ALLOWED_RADIUS_UNITS, true ) ) {
			$radius_unit = 'px';
		}
		$style = isset( $defaults['style'] ) ? sanitize_key( (string) $defaults['style'] ) : 'none';
		if ( ! in_array( $style, self::ALLOWED_STYLES, true ) ) {
			$style = 'none';
		}
		$width      = isset( $defaults['width'] ) ? (string) $defaults['width'] : '1';
		$width_unit = isset( $defaults['width_unit'] ) ? sanitize_key( (string) $defaults['width_unit'] ) : 'px';
		if ( ! in_array( $width_unit, self::ALLOWED_WIDTH_UNITS, true ) ) {
			$width_unit = 'px';
		}
		$color = Color::instance()->sanitize_stored_value( isset( $defaults['color'] ) ? (string) $defaults['color'] : '' );
		if ( $color === '' ) {
			$color = '#000000';
		}

		return array(
			'radius'      => $radius,
			'radius_unit' => $radius_unit,
			'style'       => $style,
			'width'       => $width,
			'width_unit'  => $width_unit,
			'color'       => $color,
		);
	}

	/**
	 * @param array{radius: string, radius_unit: string, style: string, width: string, width_unit: string, color: string} $base
	 * @param array<string, mixed>                                                                                        $decoded
	 * @return array{radius: string, radius_unit: string, style: string, width: string, width_unit: string, color: string}
	 */
	private function merge_decoded( array $base, array $decoded ) {
		$out = $base;
		if ( isset( $decoded['radius'] ) ) {
			$out['radius'] = (string) $decoded['radius'];
		}
		if ( isset( $decoded['radius_unit'] ) && in_array( (string) $decoded['radius_unit'], self::ALLOWED_RADIUS_UNITS, true ) ) {
			$out['radius_unit'] = (string) $decoded['radius_unit'];
		}
		if ( isset( $decoded['style'] ) && in_array( sanitize_key( (string) $decoded['style'] ), self::ALLOWED_STYLES, true ) ) {
			$out['style'] = sanitize_key( (string) $decoded['style'] );
		}
		if ( isset( $decoded['width'] ) ) {
			$out['width'] = (string) $decoded['width'];
		}
		if ( isset( $decoded['width_unit'] ) && in_array( (string) $decoded['width_unit'], self::ALLOWED_WIDTH_UNITS, true ) ) {
			$out['width_unit'] = (string) $decoded['width_unit'];
		}
		if ( isset( $decoded['color'] ) ) {
			$c = Color::instance()->sanitize_stored_value( (string) $decoded['color'] );
			if ( $c !== '' ) {
				$out['color'] = $c;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $defaults
	 * @param array<int, string>   $breakpoints
	 * @return array<string, string> Map of bp => JSON string
	 */
	private function get_value_map( $field_id, array $defaults, array $breakpoints ) {
		$default_json = (string) wp_json_encode( $defaults );
		$saved        = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			$map = ResponsiveConfig::coerce_map( null, $breakpoints, $default_json );
		} else {
			$stored = $saved[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$map = ResponsiveConfig::coerce_map( $stored, $breakpoints, $default_json );
			} else {
				$scalar = is_string( $stored ) ? (string) $stored : '';
				$map    = ResponsiveConfig::coerce_map( null, $breakpoints, $scalar !== '' ? $scalar : $default_json );
			}
		}

		$field = $this->fields_by_id[ $field_id ] ?? null;
		foreach ( $map as $bp => $json_str ) {
			$map[ $bp ] = $this->sanitize_stored_value( (string) $json_str, is_array( $field ) ? $field : null );
		}

		return $map;
	}

	/**
	 * @param string $field_id
	 * @return array<string, mixed>
	 */
	private function get_decoded_for_field( $field_id ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			return array();
		}
		$raw = $saved[ $field_id ];
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );

			return is_array( $decoded ) ? $decoded : array();
		}
		if ( is_array( $raw ) && ResponsiveConfig::is_breakpoint_value_map( $raw ) ) {
			$slice = ResponsiveConfig::raw_value_at_breakpoint( $raw, ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT );
			if ( null === $slice ) {
				$first = reset( $raw );
				$slice = is_scalar( $first ) ? (string) $first : '';
			} else {
				$slice = is_scalar( $slice ) ? (string) $slice : '';
			}
			$decoded = json_decode( $slice, true );

			return is_array( $decoded ) ? $decoded : array();
		}

		return array();
	}

	/**
	 * @return string JSON string of the all-defaults shape.
	 */
	private function encode_default() {
		return (string) wp_json_encode( $this->parse_default_array( array() ) );
	}

	/**
	 * @param mixed $raw
	 * @return array<int, string>
	 */
	private function normalize_features( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return self::ALL_FEATURES;
		}
		$want = array();
		foreach ( $raw as $f ) {
			$k = sanitize_key( (string) $f );
			if ( in_array( $k, self::ALL_FEATURES, true ) && ! in_array( $k, $want, true ) ) {
				$want[] = $k;
			}
		}

		return ! empty( $want ) ? $want : self::ALL_FEATURES;
	}

	/**
	 * @param mixed                $raw
	 * @param array<int, string>   $allowed
	 * @return array<int, string>
	 */
	private function normalize_unit_list( $raw, array $allowed ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array( $allowed[0] );
		}
		$want = array();
		foreach ( $raw as $u ) {
			$k = (string) $u;
			if ( in_array( $k, $allowed, true ) && ! in_array( $k, $want, true ) ) {
				$want[] = $k;
			}
		}

		return ! empty( $want ) ? $want : array( $allowed[0] );
	}

	/**
	 * @param mixed $raw
	 * @param int   $fallback
	 * @return int
	 */
	private function normalize_int( $raw, $fallback ) {
		if ( $raw === null || $raw === '' ) {
			return (int) $fallback;
		}
		if ( ! is_numeric( $raw ) ) {
			return (int) $fallback;
		}

		return (int) $raw;
	}

	/**
	 * Clamp a numeric string into `[min, max]`; non-numeric → fallback.
	 */
	private function clamp_number( $raw, $min, $max, $fallback ) {
		if ( ! is_numeric( $raw ) ) {
			return (string) $fallback;
		}
		$n = (float) $raw;
		if ( $n < $min ) {
			$n = $min;
		}
		if ( $n > $max ) {
			$n = $max;
		}
		if ( (float) (int) $n === $n ) {
			return (string) (int) $n;
		}

		return (string) $n;
	}
}
