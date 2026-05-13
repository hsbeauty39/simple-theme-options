<?php
namespace SimpleThemeOptions\Admin\Options\Fields\ShadowControl;

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
 * Box-shadow composite: locked CSS selector (from registration) + color + offsets + blur + spread + outline/inset.
 *
 * Stored JSON (one string per option key, or per breakpoint when `responsive`):
 *   {
 *     "selector": ".my-card",
 *     "color": "rgba(0,0,0,0.12)",
 *     "horizontal": "0",
 *     "vertical": "2",
 *     "blur": "10",
 *     "spread": "0",
 *     "position": "outline"
 *   }
 *
 * **`selector`** is set only from PHP when you register the field — put it on the **same array level** as
 * **`section_slug`**, **`id`**, **`default`**, **`popup`**, etc. (recommended). You may also put **`selector`**
 * inside **`default`**; both are merged. It is **not** editable in the admin UI; posted values cannot override it.
 *
 * `position`: `outline` (default box-shadow) or `inset` (inset keyword).
 *
 * `popup` (register key, not in JSON): when true, color / offsets / blur / spread / position open in a popover like Border;
 * when false, those controls render inline in the row.
 */
final class ShadowControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const DEFAULT_MIN_OFFSET = -200;
	private const DEFAULT_MAX_OFFSET = 200;
	private const DEFAULT_MIN_BLUR   = 0;
	private const DEFAULT_MAX_BLUR   = 200;
	private const DEFAULT_MIN_SPREAD = -100;
	private const DEFAULT_MAX_SPREAD = 100;

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
		// After BorderControl (19.30), before Range (19.35).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), 19.31, 2 );
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

		$field['min_horizontal'] = $this->normalize_int( $field['min_horizontal'] ?? null, self::DEFAULT_MIN_OFFSET );
		$field['max_horizontal'] = $this->normalize_int( $field['max_horizontal'] ?? null, self::DEFAULT_MAX_OFFSET );
		$field['min_vertical']   = $this->normalize_int( $field['min_vertical'] ?? null, self::DEFAULT_MIN_OFFSET );
		$field['max_vertical']   = $this->normalize_int( $field['max_vertical'] ?? null, self::DEFAULT_MAX_OFFSET );
		$field['min_blur']       = $this->normalize_int( $field['min_blur'] ?? null, self::DEFAULT_MIN_BLUR );
		$field['max_blur']       = $this->normalize_int( $field['max_blur'] ?? null, self::DEFAULT_MAX_BLUR );
		$field['min_spread']     = $this->normalize_int( $field['min_spread'] ?? null, self::DEFAULT_MIN_SPREAD );
		$field['max_spread']     = $this->normalize_int( $field['max_spread'] ?? null, self::DEFAULT_MAX_SPREAD );

		if ( $field['max_horizontal'] < $field['min_horizontal'] ) {
			$field['max_horizontal'] = $field['min_horizontal'];
		}
		if ( $field['max_vertical'] < $field['min_vertical'] ) {
			$field['max_vertical'] = $field['min_vertical'];
		}
		if ( $field['max_blur'] < $field['min_blur'] ) {
			$field['max_blur'] = $field['min_blur'];
		}
		if ( $field['max_spread'] < $field['min_spread'] ) {
			$field['max_spread'] = $field['min_spread'];
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
		if ( isset( $field['selector'] ) ) {
			$field['default']['selector'] = $this->sanitize_selector( (string) $field['selector'] );
		}
		unset( $field['selector'] );

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

		$min_h = $field && isset( $field['min_horizontal'] ) ? (int) $field['min_horizontal'] : self::DEFAULT_MIN_OFFSET;
		$max_h = $field && isset( $field['max_horizontal'] ) ? (int) $field['max_horizontal'] : self::DEFAULT_MAX_OFFSET;
		$min_v = $field && isset( $field['min_vertical'] ) ? (int) $field['min_vertical'] : self::DEFAULT_MIN_OFFSET;
		$max_v = $field && isset( $field['max_vertical'] ) ? (int) $field['max_vertical'] : self::DEFAULT_MAX_OFFSET;
		$min_b = $field && isset( $field['min_blur'] ) ? (int) $field['min_blur'] : self::DEFAULT_MIN_BLUR;
		$max_b = $field && isset( $field['max_blur'] ) ? (int) $field['max_blur'] : self::DEFAULT_MAX_BLUR;
		$min_s = $field && isset( $field['min_spread'] ) ? (int) $field['min_spread'] : self::DEFAULT_MIN_SPREAD;
		$max_s = $field && isset( $field['max_spread'] ) ? (int) $field['max_spread'] : self::DEFAULT_MAX_SPREAD;

		$selector = (string) $defaults['selector'];

		$color = isset( $data['color'] ) ? Color::instance()->sanitize_stored_value( (string) $data['color'] ) : '';
		if ( $color === '' ) {
			$color = (string) $defaults['color'];
		}

		$h = isset( $data['horizontal'] ) ? $this->clamp_int_string( (string) $data['horizontal'], $min_h, $max_h, (string) $defaults['horizontal'] ) : (string) $defaults['horizontal'];
		$v = isset( $data['vertical'] ) ? $this->clamp_int_string( (string) $data['vertical'], $min_v, $max_v, (string) $defaults['vertical'] ) : (string) $defaults['vertical'];
		$b = isset( $data['blur'] ) ? $this->clamp_int_string( (string) $data['blur'], $min_b, $max_b, (string) $defaults['blur'] ) : (string) $defaults['blur'];
		$s = isset( $data['spread'] ) ? $this->clamp_int_string( (string) $data['spread'], $min_s, $max_s, (string) $defaults['spread'] ) : (string) $defaults['spread'];

		$pos = isset( $data['position'] ) ? sanitize_key( (string) $data['position'] ) : (string) $defaults['position'];
		if ( $pos !== 'inset' ) {
			$pos = 'outline';
		}

		return (string) wp_json_encode(
			array(
				'selector'   => $selector,
				'color'      => $color,
				'horizontal' => $h,
				'vertical'   => $v,
				'blur'       => $b,
				'spread'     => $s,
				'position'   => $pos,
			)
		);
	}

	/**
	 * Build `box-shadow` value only (no `box-shadow:` prefix), e.g. `0 2px 10px 0 rgba(0,0,0,.1)` or `inset …`.
	 *
	 * @param array<string, string> $row
	 */
	public function compile_box_shadow_value( array $row ) {
		$defaults = $this->parse_default_array( array() );
		$row      = $this->merge_decoded( $defaults, $row );

		$inset = ( isset( $row['position'] ) && (string) $row['position'] === 'inset' ) ? 'inset ' : '';

		$h = (int) $row['horizontal'];
		$v = (int) $row['vertical'];
		$b = (int) $row['blur'];
		$s = (int) $row['spread'];
		$c = (string) $row['color'];

		return trim( $inset . $h . 'px ' . $v . 'px ' . $b . 'px ' . $s . 'px ' . $c );
	}

	/**
	 * Saved value at eval breakpoint → CSS `box-shadow` value string.
	 */
	public function value_to_css_box_shadow( $field_id ) {
		$arr = $this->get_value_array( $field_id );
		if ( ! is_array( $arr ) ) {
			return 'none';
		}

		return $this->compile_box_shadow_value( $arr );
	}

	/**
	 * Full CSS rule `selector { box-shadow: … }` or empty string when selector is blank.
	 */
	public function get_css_rule_string( $field_id ) {
		$arr = $this->get_value_array( $field_id );
		if ( ! is_array( $arr ) || (string) $arr['selector'] === '' ) {
			return '';
		}
		$sel = (string) $arr['selector'];
		$val = $this->compile_box_shadow_value( $arr );

		return $sel . ' { box-shadow: ' . $val . '; }';
	}

	/**
	 * @param string $field_id
	 * @return array<string, string>|null
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

		$row_classes = array( 'sto-field-row', 'sto-field-row-shadow' );
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
		$defaults    = $this->parse_default_array( $field['default'] ?? array() );
		$cur         = $this->merge_decoded( $defaults, json_decode( (string) $json_value, true ) ?: array() );
		$use_popup   = ! empty( $field['popup'] );
		$use_alpha   = ! empty( $field['alpha'] );
		$palettes     = isset( $field['palettes'] ) && is_array( $field['palettes'] ) ? $field['palettes'] : array();
		$palettes_json = ! empty( $palettes ) ? (string) wp_json_encode( $palettes ) : '';
		$default_color = (string) $defaults['color'];
		$id_fragment   = sanitize_key( $id_fragment );

		$min_h = (int) $field['min_horizontal'];
		$max_h = (int) $field['max_horizontal'];
		$min_v = (int) $field['min_vertical'];
		$max_v = (int) $field['max_vertical'];
		$min_b = (int) $field['min_blur'];
		$max_b = (int) $field['max_blur'];
		$min_s = (int) $field['min_spread'];
		$max_s = (int) $field['max_spread'];
		?>
		<div
			class="sto-shadow-control"
			data-sto-shadow-control="1"
			data-sto-shadow-popup="<?php echo $use_popup ? '1' : '0'; ?>"
			data-field-id="<?php echo esc_attr( $id_fragment ); ?>"
			data-sto-shadow-defaults="<?php echo esc_attr( (string) wp_json_encode( $defaults ) ); ?>"
		>
			<?php if ( $use_popup ) : ?>
				<div class="sto-shadow-summary sto-shadow-summary--compact">
					<button
						type="button"
						class="sto-shadow-edit button"
						data-sto-shadow-edit-toggle
						aria-expanded="false"
						aria-haspopup="dialog"
					>
						<i class="fa-light fa-pencil" aria-hidden="true"></i>
						<span class="sto-shadow-edit__label"><?php esc_html_e( 'Edit shadow', 'simple-theme-options' ); ?></span>
					</button>
				</div>
				<div class="sto-shadow-popover" role="dialog" aria-label="<?php esc_attr_e( 'Shadow settings', 'simple-theme-options' ); ?>" hidden>
					<?php $this->render_shadow_controls_body( $cur, $default_color, $use_alpha, $palettes_json, $min_h, $max_h, $min_v, $max_v, $min_b, $max_b, $min_s, $max_s ); ?>
				</div>
			<?php else : ?>
				<div class="sto-shadow-inline">
					<?php $this->render_shadow_controls_body( $cur, $default_color, $use_alpha, $palettes_json, $min_h, $max_h, $min_v, $max_v, $min_b, $max_b, $min_s, $max_s ); ?>
				</div>
			<?php endif; ?>

			<input
				type="hidden"
				class="sto-shadow-value"
				name="<?php echo esc_attr( $hidden_name ); ?>"
				value="<?php echo esc_attr( (string) wp_json_encode( $cur ) ); ?>"
			/>
		</div>
		<?php
	}

	/**
	 * @param array<string, string> $cur
	 */
	private function render_shadow_controls_body( array $cur, $default_color, $use_alpha, $palettes_json, $min_h, $max_h, $min_v, $max_v, $min_b, $max_b, $min_s, $max_s ) {
		?>
		<div class="sto-shadow-popover__row" data-sto-shadow-section="color">
			<div class="sto-shadow-popover__label"><?php esc_html_e( 'Color', 'simple-theme-options' ); ?></div>
			<div
				class="sto-color-wrap<?php echo $use_alpha ? ' sto-color--alpha' : ''; ?> sto-shadow-color-wrap"
				<?php if ( $palettes_json ) : ?>
					data-sto-palettes="<?php echo esc_attr( $palettes_json ); ?>"
				<?php endif; ?>
			>
				<input
					type="text"
					class="sto-color-input sto-shadow-popover__color"
					data-sto-shadow-input="color"
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
					aria-label="<?php esc_attr_e( 'Reset shadow color', 'simple-theme-options' ); ?>"
					title="<?php esc_attr_e( 'Reset to default', 'simple-theme-options' ); ?>"
				>
					<i class="fa-light fa-arrow-rotate-left" aria-hidden="true"></i>
				</button>
			</div>
		</div>

		<?php
		$this->render_slider_pair( __( 'Horizontal', 'simple-theme-options' ), 'horizontal', (int) $cur['horizontal'], $min_h, $max_h );
		$this->render_slider_pair( __( 'Vertical', 'simple-theme-options' ), 'vertical', (int) $cur['vertical'], $min_v, $max_v );
		$this->render_slider_pair( __( 'Blur', 'simple-theme-options' ), 'blur', (int) $cur['blur'], $min_b, $max_b );
		$this->render_slider_pair( __( 'Spread', 'simple-theme-options' ), 'spread', (int) $cur['spread'], $min_s, $max_s );
		?>

		<div class="sto-shadow-popover__row" data-sto-shadow-section="position">
			<div class="sto-shadow-popover__label"><?php esc_html_e( 'Position', 'simple-theme-options' ); ?></div>
			<select class="sto-shadow-popover__select" data-sto-shadow-input="position" aria-label="<?php esc_attr_e( 'Shadow position', 'simple-theme-options' ); ?>">
				<option value="outline" <?php selected( (string) $cur['position'], 'outline' ); ?>><?php esc_html_e( 'Outline', 'simple-theme-options' ); ?></option>
				<option value="inset" <?php selected( (string) $cur['position'], 'inset' ); ?>><?php esc_html_e( 'Inset', 'simple-theme-options' ); ?></option>
			</select>
		</div>
		<?php
	}

	/**
	 * @param string $label
	 * @param string $key horizontal|vertical|blur|spread
	 */
	private function render_slider_pair( $label, $key, $current, $min, $max ) {
		$key   = sanitize_key( $key );
		$c     = (int) $current;
		$c     = max( (int) $min, min( (int) $max, $c ) );
		$label = (string) $label;
		?>
		<div class="sto-shadow-popover__row" data-sto-shadow-section="<?php echo esc_attr( $key ); ?>">
			<div class="sto-shadow-popover__label"><?php echo esc_html( $label ); ?></div>
			<div class="sto-shadow-popover__inputs">
				<input
					type="range"
					class="sto-shadow-popover__slider"
					data-sto-shadow-input="<?php echo esc_attr( $key ); ?>"
					min="<?php echo esc_attr( (string) $min ); ?>"
					max="<?php echo esc_attr( (string) $max ); ?>"
					step="1"
					value="<?php echo esc_attr( (string) $c ); ?>"
					aria-label="<?php echo esc_attr( $label ); ?>"
				/>
				<input
					type="number"
					class="sto-shadow-popover__number"
					data-sto-shadow-input="<?php echo esc_attr( $key ); ?>"
					min="<?php echo esc_attr( (string) $min ); ?>"
					max="<?php echo esc_attr( (string) $max ); ?>"
					step="1"
					value="<?php echo esc_attr( (string) $c ); ?>"
				/>
			</div>
		</div>
		<?php
	}

	private function build_reset_markup( $field_id, array $defaults ) {
		$defaults_json = (string) wp_json_encode( $defaults );

		return sprintf(
			'<button type="button" class="sto-shadow-row-reset" data-sto-shadow-row-reset data-sto-shadow-defaults="%s" aria-label="%s" title="%s"><i class="fa-light fa-arrow-rotate-left" aria-hidden="true"></i></button>',
			esc_attr( $defaults_json ),
			esc_attr__( 'Reset shadow to default', 'simple-theme-options' ),
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
	 * @return array<string, string>
	 */
	private function parse_default_array( $defaults ) {
		if ( ! is_array( $defaults ) ) {
			$defaults = array();
		}
		$selector = $this->sanitize_selector( isset( $defaults['selector'] ) ? (string) $defaults['selector'] : '' );
		$color    = isset( $defaults['color'] ) ? Color::instance()->sanitize_stored_value( (string) $defaults['color'] ) : '';
		if ( $color === '' ) {
			$color = 'rgba(0, 0, 0, 0.12)';
		}
		$h = isset( $defaults['horizontal'] ) ? (string) (int) $defaults['horizontal'] : '0';
		$v = isset( $defaults['vertical'] ) ? (string) (int) $defaults['vertical'] : '2';
		$b = isset( $defaults['blur'] ) ? (string) (int) $defaults['blur'] : '10';
		$s = isset( $defaults['spread'] ) ? (string) (int) $defaults['spread'] : '0';
		$p = isset( $defaults['position'] ) ? sanitize_key( (string) $defaults['position'] ) : 'outline';
		if ( $p !== 'inset' ) {
			$p = 'outline';
		}

		return array(
			'selector'   => $selector,
			'color'      => $color,
			'horizontal' => $h,
			'vertical'   => $v,
			'blur'       => $b,
			'spread'     => $s,
			'position'   => $p,
		);
	}

	/**
	 * @param array<string, string> $base
	 * @param array<string, mixed>  $decoded
	 * @return array<string, string>
	 */
	private function merge_decoded( array $base, array $decoded ) {
		$out = $base;
		if ( isset( $decoded['color'] ) ) {
			$c = Color::instance()->sanitize_stored_value( (string) $decoded['color'] );
			if ( $c !== '' ) {
				$out['color'] = $c;
			}
		}
		foreach ( array( 'horizontal', 'vertical', 'blur', 'spread' ) as $k ) {
			if ( isset( $decoded[ $k ] ) ) {
				$out[ $k ] = (string) (int) $decoded[ $k ];
			}
		}
		if ( isset( $decoded['position'] ) ) {
			$pk = sanitize_key( (string) $decoded['position'] );
			$out['position'] = ( $pk === 'inset' ) ? 'inset' : 'outline';
		}

		return $out;
	}

	private function sanitize_selector( $raw ) {
		$s = trim( wp_strip_all_tags( (string) $raw ) );
		if ( $s === '' ) {
			return '';
		}
		if ( strlen( $s ) > 400 ) {
			$s = substr( $s, 0, 400 );
		}
		// Block obvious injection into a `<style>` block: no braces, semicolons, backslashes, or angle brackets.
		if ( preg_match( '/[<>{};\\\\]/', $s ) ) {
			return '';
		}

		return preg_replace( '/[^a-zA-Z0-9#._\s:,\-\[\]="\'()]/', '', $s );
	}

	private function clamp_int_string( $raw, $min, $max, $fallback ) {
		if ( ! is_numeric( $raw ) ) {
			return (string) (int) $fallback;
		}
		$n = (int) $raw;

		return (string) max( (int) $min, min( (int) $max, $n ) );
	}

	private function normalize_int( $val, $fallback ) {
		if ( null === $val || $val === '' ) {
			return (int) $fallback;
		}

		return (int) $val;
	}

	/**
	 * @param array<string, string>   $defaults
	 * @param array<int, string>      $breakpoints
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
