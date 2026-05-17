<?php
namespace SimpleThemeOptions\Admin\Options\Fields\GradientControl;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;

use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * CSS linear / radial gradient editor: preview + stop rail, **floating color dock** at the active pin (WordPress color picker), Stop %, Flip, type, angle, 2–N stops (default **24**, cap **32** via `max_stops`), optional `palettes` swatches in the dock, optional popover (`popup`) or inline UI.
 *
 * Stored JSON (one string per option key, or per breakpoint when `responsive`):
 *   {
 *     "type": "linear",
 *     "angle": "135",
 *     "stops": [
 *       { "color": "#2271b1", "position": "0" },
 *       { "color": "#ffffff", "position": "100" }
 *     ]
 *   }
 *
 * `type`: `linear` | `radial`. `angle`: degrees string (linear only; ignored for radial in CSS output).
 *
 * `popup` (register key, not in JSON): when true, summary + **Edit gradient** opens a popover; when false, controls render inline. Stops: click the **gradient bar** to add (up to **`max_stops`**, default **24**, max **32**); drag **pins**; color UI opens in a **dock** anchored to the active pin; optional **`palettes`** shows suggestion swatches under the picker.
 */
final class GradientControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const MIN_STOPS = 2;
	private const MAX_STOPS_CAP = 32;

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
		// After ShadowControl (19.31), before Range (19.35).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::GRADIENT, 2 );
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

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['alpha']         = ! array_key_exists( 'alpha', $field ) || (bool) $field['alpha'];
		$field['popup']         = ! empty( $field['popup'] );

		$max_stops = isset( $field['max_stops'] ) ? (int) $field['max_stops'] : 24;
		if ( $max_stops < self::MIN_STOPS ) {
			$max_stops = self::MIN_STOPS;
		}
		if ( $max_stops > self::MAX_STOPS_CAP ) {
			$max_stops = self::MAX_STOPS_CAP;
		}
		$field['max_stops'] = $max_stops;

		$palettes = isset( $field['palettes'] ) && is_array( $field['palettes'] ) ? $field['palettes'] : array();
		$clean    = array();
		foreach ( $palettes as $p ) {
			$hex = Color::instance()->sanitize_stored_value( (string) $p );
			if ( $hex !== '' ) {
				$clean[] = $hex;
			}
		}
		$field['palettes'] = $clean;

		$field['default'] = $this->parse_default_array( isset( $field['default'] ) && is_array( $field['default'] ) ? $field['default'] : array() );

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
	 * @param mixed $raw
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
	 * @param string                    $raw_json
	 * @param array<string, mixed>|null $field
	 */
	public function sanitize_stored_value( $raw_json, $field = null ) {
		$data = json_decode( is_string( $raw_json ) ? $raw_json : '', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$defaults = $this->parse_default_array( $field && isset( $field['default'] ) && is_array( $field['default'] ) ? $field['default'] : array() );
		$max      = $field && isset( $field['max_stops'] ) ? (int) $field['max_stops'] : 24;
		if ( $max < self::MIN_STOPS ) {
			$max = self::MIN_STOPS;
		}
		if ( $max > self::MAX_STOPS_CAP ) {
			$max = self::MAX_STOPS_CAP;
		}

		$type = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : (string) $defaults['type'];
		if ( $type !== 'radial' ) {
			$type = 'linear';
		}

		$angle = isset( $data['angle'] ) ? $this->clamp_angle_string( (string) $data['angle'], (string) $defaults['angle'] ) : (string) $defaults['angle'];

		$stops_in = isset( $data['stops'] ) && is_array( $data['stops'] ) ? $data['stops'] : array();
		$stops    = $this->sanitize_stops_list( $stops_in, $defaults['stops'], $max );

		return (string) wp_json_encode(
			array(
				'type'   => $type,
				'angle'  => $angle,
				'stops'  => $stops,
			)
		);
	}

	/**
	 * @param array<int, mixed>        $raw_stops
	 * @param array<int, array<string, string>> $fallback_stops
	 * @return array<int, array<string, string>>
	 */
	private function sanitize_stops_list( array $raw_stops, array $fallback_stops, $max_stops ) {
		$out = array();
		foreach ( $raw_stops as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$c = isset( $row['color'] ) ? Color::instance()->sanitize_stored_value( (string) $row['color'] ) : '';
			$p = isset( $row['position'] ) ? $this->clamp_position_string( (string) $row['position'] ) : '';
			if ( $c === '' || $p === '' ) {
				continue;
			}
			$out[] = array(
				'color'    => $c,
				'position' => $p,
			);
			if ( count( $out ) >= $max_stops ) {
				break;
			}
		}

		if ( count( $out ) < self::MIN_STOPS ) {
			$out = $fallback_stops;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return ( (float) $a['position'] ) <=> ( (float) $b['position'] );
			}
		);

		return array_values( $out );
	}

	private function clamp_angle_string( $raw, $fallback ) {
		if ( ! is_numeric( $raw ) ) {
			return (string) $fallback;
		}
		$n = (int) round( (float) $raw );
		$n = ( ( $n % 360 ) + 360 ) % 360;

		return (string) $n;
	}

	private function clamp_position_string( $raw ) {
		if ( ! is_numeric( $raw ) ) {
			return '0';
		}
		$n = (float) $raw;
		if ( $n < 0 ) {
			$n = 0;
		}
		if ( $n > 100 ) {
			$n = 100;
		}

		return (string) round( $n, 2 );
	}

	/**
	 * @param array<string, string> $row Merged value row (type, angle, stops as list of color/position).
	 * @return string Value suitable for `background-image:` (no property name).
	 */
	public function compile_css_gradient_value( array $row ) {
		$defaults = $this->parse_default_array( array() );
		$row      = $this->merge_decoded( $defaults, $row );
		$type     = (string) $row['type'];
		$parts    = array();
		foreach ( $row['stops'] as $stop ) {
			if ( ! is_array( $stop ) ) {
				continue;
			}
			$c = isset( $stop['color'] ) ? (string) $stop['color'] : '';
			$p = isset( $stop['position'] ) ? (string) $stop['position'] : '';
			if ( $c === '' ) {
				continue;
			}
			$parts[] = trim( $c . ' ' . $p . '%' );
		}
		if ( count( $parts ) < self::MIN_STOPS ) {
			return 'linear-gradient(180deg, #2271b1 0%, #ffffff 100%)';
		}
		$blob = implode( ', ', $parts );
		if ( $type === 'radial' ) {
			return 'radial-gradient(circle at center, ' . $blob . ')';
		}
		$angle = (int) $row['angle'];

		return 'linear-gradient(' . $angle . 'deg, ' . $blob . ')';
	}

	/**
	 * Horizontal stop rail (thin bar under pins): same stops as the gradient, always left → right.
	 * Avoids painting the full angled/radial gradient on a 14px-tall strip (looked like page-wide bars).
	 *
	 * @param array<string, string> $row Merged value row.
	 * @return string Value suitable for `background-image:` (no property name).
	 */
	public function compile_css_stop_rail_value( array $row ) {
		$defaults = $this->parse_default_array( array() );
		$row      = $this->merge_decoded( $defaults, $row );
		$parts    = array();
		foreach ( $row['stops'] as $stop ) {
			if ( ! is_array( $stop ) ) {
				continue;
			}
			$c = isset( $stop['color'] ) ? (string) $stop['color'] : '';
			$p = isset( $stop['position'] ) ? (string) $stop['position'] : '';
			if ( $c === '' ) {
				continue;
			}
			$parts[] = trim( $c . ' ' . $p . '%' );
		}
		if ( count( $parts ) < self::MIN_STOPS ) {
			return 'linear-gradient(90deg, #2271b1 0%, #ffffff 100%)';
		}

		return 'linear-gradient(90deg, ' . implode( ', ', $parts ) . ')';
	}

	/**
	 * Saved value at eval breakpoint → CSS `background-image` value only.
	 */
	public function value_to_css_background_image( $field_id ) {
		$arr = $this->get_value_array( $field_id );
		if ( ! is_array( $arr ) ) {
			return 'none';
		}

		return $this->compile_css_gradient_value( $arr );
	}

	/**
	 * @param string $field_id
	 * @return array<string, mixed>|null
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

		$row_classes = array( 'sto-field-row', 'sto-field-row-gradient' );
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
			ob_start();
			FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_inner, $trailing_html );
			$heading_html = (string) ob_get_clean();
			$heading_html = $this->inject_reset_into_leading( $heading_html, $reset_html );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
						$bp       = sanitize_key( (string) $bp );
						$visible  = ( 0 === (int) $i );
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
	 * @param array<string, mixed> $field
	 * @param string               $json_value
	 * @param string               $hidden_name
	 * @param string               $id_fragment
	 */
	private function render_widget( array $field, $json_value, $hidden_name, $id_fragment ) {
		$defaults     = $this->parse_default_array( $field['default'] ?? array() );
		$cur          = $this->merge_decoded( $defaults, json_decode( (string) $json_value, true ) ?: array() );
		$use_popup    = ! empty( $field['popup'] );
		$use_alpha    = ! empty( $field['alpha'] );
		$palettes      = isset( $field['palettes'] ) && is_array( $field['palettes'] ) ? $field['palettes'] : array();
		$palettes_json = ! empty( $palettes ) ? (string) wp_json_encode( $palettes ) : '';
		$id_fragment    = sanitize_key( $id_fragment );
		$max_stops      = (int) $field['max_stops'];
		$preview_css    = $this->compile_css_gradient_value( $cur );
		?>
		<div
			class="sto-gradient-control"
			data-sto-gradient-control="1"
			data-sto-gradient-popup="<?php echo $use_popup ? '1' : '0'; ?>"
			data-field-id="<?php echo esc_attr( $id_fragment ); ?>"
			data-sto-gradient-max-stops="<?php echo esc_attr( (string) $max_stops ); ?>"
			data-sto-gradient-defaults="<?php echo esc_attr( (string) wp_json_encode( $defaults ) ); ?>"
		>
			<?php if ( $use_popup ) : ?>
				<div class="sto-gradient-summary sto-gradient-summary--compact">
					<div class="sto-gradient-preview" data-sto-gradient-preview style="background-image: <?php echo esc_attr( $preview_css ); ?>;"></div>
					<button
						type="button"
						class="sto-gradient-edit button button-primary"
						data-sto-gradient-edit-toggle
						aria-expanded="false"
						aria-haspopup="dialog"
					>
						<i class="fa-light fa-pencil" aria-hidden="true"></i>
						<span class="sto-gradient-edit__label"><?php esc_html_e( 'Edit gradient', 'simple-theme-options' ); ?></span>
					</button>
				</div>
				<div class="sto-gradient-popover" role="dialog" aria-label="<?php esc_attr_e( 'Gradient settings', 'simple-theme-options' ); ?>" hidden>
					<?php $this->render_gradient_controls_body( $cur, $use_alpha, $palettes_json, $id_fragment, $max_stops ); ?>
				</div>
			<?php else : ?>
				<div class="sto-gradient-inline">
					<div class="sto-gradient-preview sto-gradient-preview--inline" data-sto-gradient-preview style="background-image: <?php echo esc_attr( $preview_css ); ?>;"></div>
					<?php $this->render_gradient_controls_body( $cur, $use_alpha, $palettes_json, $id_fragment, $max_stops ); ?>
				</div>
			<?php endif; ?>

			<input
				type="hidden"
				class="sto-gradient-value"
				name="<?php echo esc_attr( $hidden_name ); ?>"
				value="<?php echo esc_attr( (string) wp_json_encode( $cur ) ); ?>"
			/>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $cur
	 */
	private function render_gradient_controls_body( array $cur, $use_alpha, $palettes_json, $id_fragment, $max_stops ) {
		$rail_bar_css = $this->compile_css_stop_rail_value( $cur );
		$type  = (string) $cur['type'];
		$angle = (string) $cur['angle'];
		$stops = isset( $cur['stops'] ) && is_array( $cur['stops'] ) ? $cur['stops'] : array();
		$first = isset( $stops[0] ) && is_array( $stops[0] ) ? $stops[0] : array( 'color' => '#2271b1', 'position' => '0' );
		$fc    = isset( $first['color'] ) ? (string) $first['color'] : '#2271b1';
		$fp    = isset( $first['position'] ) ? (string) $first['position'] : '0';
		$acid  = 'sto-gradient-active-' . $id_fragment;
		?>
		<div class="sto-gradient-ui" data-sto-gradient-ui="1">
			<div class="sto-gradient-viz" aria-label="<?php esc_attr_e( 'Gradient preview and stops', 'simple-theme-options' ); ?>">
				<div class="sto-gradient-viz__track">
					<div class="sto-gradient-viz__preview" data-sto-gradient-preview></div>
					<div class="sto-gradient-viz__rail" data-sto-gradient-rail>
						<div
							class="sto-gradient-color-dock sto-gradient-color-dock--idle"
							data-sto-gradient-color-dock
							role="dialog"
							aria-label="<?php esc_attr_e( 'Stop color', 'simple-theme-options' ); ?>"
							aria-hidden="true"
						>
							<div class="sto-gradient-color-dock__chrome">
								<div
									class="sto-color-wrap sto-gradient-active-color-wrap<?php echo $use_alpha ? ' sto-color--alpha' : ''; ?>"
									<?php if ( $palettes_json ) : ?>
										data-sto-palettes="<?php echo esc_attr( $palettes_json ); ?>"
									<?php endif; ?>
								>
									<input
										type="text"
										id="<?php echo esc_attr( $acid ); ?>"
										class="sto-color-input sto-gradient-active-color"
										data-sto-gradient-active-color
										data-default-color="<?php echo esc_attr( $fc ); ?>"
										data-sto-default="<?php echo esc_attr( $fc ); ?>"
										<?php if ( $use_alpha ) : ?>
											data-alpha-enabled="true"
											data-alpha-color-type="octohex"
											data-type="full"
											data-alpha-custom-width="0"
										<?php endif; ?>
										value="<?php echo esc_attr( $fc ); ?>"
										autocomplete="off"
									/>
									<button
										type="button"
										class="sto-color-reset"
										aria-label="<?php esc_attr_e( 'Reset active stop color', 'simple-theme-options' ); ?>"
										title="<?php esc_attr_e( 'Reset to default', 'simple-theme-options' ); ?>"
									>
										<i class="fa-light fa-arrow-rotate-left" aria-hidden="true"></i>
									</button>
								</div>
								<?php if ( $palettes_json ) : ?>
									<div
										class="sto-gradient-dock-palette"
										data-sto-gradient-dock-palette
										data-sto-gradient-palette-label="<?php esc_attr_e( 'Suggested colors', 'simple-theme-options' ); ?>"
									></div>
								<?php endif; ?>
							</div>
						</div>
						<div class="sto-gradient-viz__pins" data-sto-gradient-pins role="tablist" aria-label="<?php esc_attr_e( 'Color stops', 'simple-theme-options' ); ?>"></div>
						<div class="sto-gradient-viz__barwrap">
							<div class="sto-gradient-viz__bar" data-sto-gradient-bar style="background-image: <?php echo esc_attr( $rail_bar_css ); ?>;"></div>
							<button
								type="button"
								class="sto-gradient-viz__hit"
								data-sto-gradient-hit
								tabindex="0"
								aria-label="<?php esc_attr_e( 'Add a stop on the gradient bar, or tap near an existing handle to select it', 'simple-theme-options' ); ?>"
							></button>
						</div>
					</div>
				</div>
			</div>

			<button type="button" class="sto-gradient-flip button button-primary" data-sto-gradient-flip>
				<i class="fa-light fa-arrows-left-right" aria-hidden="true"></i>
				<span><?php esc_html_e( 'Flip', 'simple-theme-options' ); ?></span>
			</button>

			<div class="sto-gradient-editor-grid sto-gradient-editor-grid--stop-only">
				<div class="sto-gradient-editor-cell sto-gradient-editor-cell--full">
					<div class="sto-gradient-field-label"><?php esc_html_e( 'Stop', 'simple-theme-options' ); ?></div>
					<label class="sto-gradient-stop-percent">
						<span class="screen-reader-text"><?php esc_html_e( 'Stop position percent', 'simple-theme-options' ); ?></span>
						<input
							type="number"
							class="sto-gradient-active-position sto-gradient-input-soft"
							data-sto-gradient-active-position
							min="0"
							max="100"
							step="1"
							value="<?php echo esc_attr( $fp ); ?>"
						/>
						<span class="sto-gradient-stop-percent__suffix" aria-hidden="true">%</span>
					</label>
				</div>
			</div>

			<div class="sto-gradient-footer-grid">
				<div class="sto-gradient-editor-cell">
					<div class="sto-gradient-field-label"><?php esc_html_e( 'Type', 'simple-theme-options' ); ?></div>
					<select class="sto-gradient-type-select sto-input-select sto-gradient-input-soft" data-sto-gradient-input="type" aria-label="<?php esc_attr_e( 'Gradient type', 'simple-theme-options' ); ?>">
						<option value="linear" <?php selected( $type, 'linear' ); ?>><?php esc_html_e( 'Linear', 'simple-theme-options' ); ?></option>
						<option value="radial" <?php selected( $type, 'radial' ); ?>><?php esc_html_e( 'Radial', 'simple-theme-options' ); ?></option>
					</select>
				</div>
				<div class="sto-gradient-editor-cell sto-gradient-angle-cell" data-sto-gradient-section="angle" <?php echo $type === 'radial' ? ' hidden' : ''; ?>>
					<div class="sto-gradient-field-label"><?php esc_html_e( 'Angle', 'simple-theme-options' ); ?></div>
					<div class="sto-gradient-angle-wrap">
						<input
							type="number"
							class="sto-gradient-angle-input sto-gradient-input-soft"
							data-sto-gradient-input="angle"
							min="0"
							max="359"
							step="1"
							value="<?php echo esc_attr( $angle ); ?>"
							aria-label="<?php esc_attr_e( 'Gradient angle in degrees', 'simple-theme-options' ); ?>"
						/>
						<span class="sto-gradient-angle-suffix" aria-hidden="true"><?php esc_html_e( 'DEG', 'simple-theme-options' ); ?></span>
					</div>
				</div>
			</div>

			<div class="sto-gradient-toolbar">
				<button type="button" class="button button-small sto-gradient-remove-stop" data-sto-gradient-remove-stop hidden><?php esc_html_e( 'Remove stop', 'simple-theme-options' ); ?></button>
			</div>
		</div>
		<?php
	}

	private function build_reset_markup( $field_id, array $defaults ) {
		$defaults_json = (string) wp_json_encode( $defaults );

		return sprintf(
			'<button type="button" class="sto-gradient-row-reset" data-sto-gradient-row-reset data-sto-gradient-defaults="%s" aria-label="%s" title="%s"><i class="fa-light fa-arrow-rotate-left" aria-hidden="true"></i></button>',
			esc_attr( $defaults_json ),
			esc_attr__( 'Reset gradient to default', 'simple-theme-options' ),
			esc_attr__( 'Reset to default', 'simple-theme-options' )
		);
	}

	private function inject_reset_into_leading( $heading_html, $reset_html ) {
		if ( $heading_html === '' || $reset_html === '' ) {
			return $heading_html;
		}
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
	 * @return array<string, mixed>
	 */
	private function parse_default_array( $defaults ) {
		if ( ! is_array( $defaults ) ) {
			$defaults = array();
		}
		$type = isset( $defaults['type'] ) ? sanitize_key( (string) $defaults['type'] ) : 'linear';
		if ( $type !== 'radial' ) {
			$type = 'linear';
		}
		$angle = isset( $defaults['angle'] ) ? $this->clamp_angle_string( (string) $defaults['angle'], '180' ) : '180';

		$stops_raw = isset( $defaults['stops'] ) && is_array( $defaults['stops'] ) ? $defaults['stops'] : array();
		$stops     = array();
		foreach ( $stops_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$c = isset( $row['color'] ) ? Color::instance()->sanitize_stored_value( (string) $row['color'] ) : '';
			$p = isset( $row['position'] ) ? $this->clamp_position_string( (string) $row['position'] ) : '';
			if ( $c === '' ) {
				continue;
			}
			if ( $p === '' ) {
				$p = '0';
			}
			$stops[] = array(
				'color'    => $c,
				'position' => $p,
			);
		}
		if ( count( $stops ) < self::MIN_STOPS ) {
			$stops = array(
				array(
					'color'    => '#2271b1',
					'position' => '0',
				),
				array(
					'color'    => '#ffffff',
					'position' => '100',
				),
			);
		}

		return array(
			'type'  => $type,
			'angle' => $angle,
			'stops' => array_values( $stops ),
		);
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $decoded
	 * @return array<string, mixed>
	 */
	private function merge_decoded( array $base, array $decoded ) {
		$out = $base;
		if ( isset( $decoded['type'] ) ) {
			$t = sanitize_key( (string) $decoded['type'] );
			$out['type'] = ( $t === 'radial' ) ? 'radial' : 'linear';
		}
		if ( isset( $decoded['angle'] ) ) {
			$out['angle'] = $this->clamp_angle_string( (string) $decoded['angle'], (string) $base['angle'] );
		}
		if ( isset( $decoded['stops'] ) && is_array( $decoded['stops'] ) ) {
			$merged = array();
			foreach ( $decoded['stops'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$c = isset( $row['color'] ) ? Color::instance()->sanitize_stored_value( (string) $row['color'] ) : '';
				$p = isset( $row['position'] ) ? $this->clamp_position_string( (string) $row['position'] ) : '';
				if ( $c === '' || $p === '' ) {
					continue;
				}
				$merged[] = array(
					'color'    => $c,
					'position' => $p,
				);
			}
			if ( count( $merged ) >= self::MIN_STOPS ) {
				$out['stops'] = $merged;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>   $defaults
	 * @param array<int, string>     $breakpoints
	 * @return array<string, string>
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
		if ( ! is_string( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private function encode_default() {
		$d = $this->parse_default_array( array() );

		return (string) wp_json_encode( $d );
	}
}
