<?php
namespace SimpleThemeOptions\Admin\Options\Fields\BackgroundControl;

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
 * Background color + image (media library) in one option value (JSON).
 */
final class BackgroundControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

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
		// After Color (19), before Input (19.5).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::BACKGROUND, 2 );
	}

	/**
	 * Keys: section_slug, id, title, description?, default? (array: color, image_id), required?, palettes?, alpha?, class?, wrapper_class?, tooltip?, optional **responsive** (`true` or non-empty array), optional **`device`** => breakpoint slug list.
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

		$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
		$field_id     = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';

		if ( ! $section_slug || ! $field_id ) {
			return;
		}

		$field['section_slug']  = $section_slug;
		$field['id']              = $field_id;
		$field['title']           = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']     = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['class']           = isset( $field['class'] ) ? (string) $field['class'] : '';
		$field['wrapper_class']   = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']        = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']           = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['palettes']        = isset( $field['palettes'] ) && is_array( $field['palettes'] ) ? $field['palettes'] : array();
		$field['default']         = $this->parse_default_array( isset( $field['default'] ) ? $field['default'] : array() );
		if ( ! array_key_exists( 'alpha', $field ) ) {
			$field['alpha'] = true;
		} else {
			$field['alpha'] = (bool) $field['alpha'];
		}

		$bps                             = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
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
	 * @param array<string, mixed> $defaults
	 * @return array{color: string, image_id: string}
	 */
	private function parse_default_array( $defaults ) {
		if ( ! is_array( $defaults ) ) {
			$defaults = array();
		}
		$color_raw = isset( $defaults['color'] ) ? (string) $defaults['color'] : '#ffffff';
		$color     = Color::instance()->sanitize_stored_value( $color_raw );
		if ( $color === '' ) {
			$color = '#ffffff';
		}
		$img = isset( $defaults['image_id'] ) ? absint( $defaults['image_id'] ) : 0;

		return array(
			'color'    => $color,
			'image_id' => $img > 0 ? (string) $img : '',
		);
	}

	/**
	 * @param string $raw JSON from POST
	 * @return string JSON
	 */
	public function sanitize_stored_value( $raw ) {
		$data = json_decode( is_string( $raw ) ? $raw : '', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$color = Color::instance()->sanitize_stored_value( isset( $data['color'] ) ? (string) $data['color'] : '' );
		if ( $color === '' ) {
			$color = '#ffffff';
		}

		$image_id = isset( $data['image_id'] ) ? absint( $data['image_id'] ) : 0;
		if ( $image_id > 0 && ! wp_attachment_is_image( $image_id ) ) {
			$image_id = 0;
		}

		$clean = array(
			'color'    => $color,
			'image_id' => $image_id > 0 ? (string) $image_id : '',
		);

		return wp_json_encode( $clean );
	}

	/**
	 * @param string               $field_id
	 * @param string|array<mixed> $raw Posted JSON string or per-breakpoint map of JSON strings.
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return $this->sanitize_stored_value( '' );
		}
		$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->sanitize_stored_value( is_string( $cell ) ? $cell : '' );
			}

			return $out;
		}

		return $this->sanitize_stored_value( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) );
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
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $decoded
	 * @return array{color: string, image_id: string}
	 */
	private function merge_decoded_background( array $base, array $decoded ) {
		$color = Color::instance()->sanitize_stored_value( isset( $decoded['color'] ) ? (string) $decoded['color'] : '' );
		if ( $color === '' ) {
			$color = $base['color'];
		}

		$image_id = isset( $decoded['image_id'] ) ? absint( $decoded['image_id'] ) : 0;
		if ( $image_id > 0 && ! wp_attachment_is_image( $image_id ) ) {
			$image_id = 0;
		}

		return array(
			'color'    => $color,
			'image_id' => $image_id > 0 ? (string) $image_id : '',
		);
	}

	/**
	 * @param string               $field_id
	 * @param array<string, mixed> $defaults Raw default array from field registration.
	 * @param array<int, string>   $breakpoints
	 * @return array<string, string> Breakpoint => JSON string
	 */
	private function get_value_map( $field_id, array $defaults, array $breakpoints ) {
		$default_arr  = $this->parse_default_array( $defaults );
		$default_json = wp_json_encode( $default_arr );

		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			$map = ResponsiveConfig::coerce_map( null, $breakpoints, $default_json );
		} else {
			$stored = $saved[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$map = ResponsiveConfig::coerce_map( $stored, $breakpoints, $default_json );
			} else {
				$scalar = is_string( $stored ) ? $this->sanitize_stored_value( $stored ) : '';
				if ( $scalar === '' ) {
					$scalar = $default_json;
				}
				$map = ResponsiveConfig::coerce_map( null, $breakpoints, $scalar );
			}
		}
		foreach ( $map as $bp => $json_str ) {
			$map[ $bp ] = $this->sanitize_stored_value( (string) $json_str );
		}

		return $map;
	}

	/**
	 * @param string               $json_value
	 * @param string               $color_input_id
	 * @param string               $hidden_name
	 * @param string               $default_color
	 * @param bool                 $use_alpha
	 * @param string               $input_class
	 * @param string               $palettes_json
	 * @param string               $field_id      For data-field-id on wrapper.
	 */
	private function render_background_widget( $field_id, $json_value, $color_input_id, $hidden_name, $default_color, $use_alpha, $input_class, $palettes_json ) {
		$data = json_decode( (string) $json_value, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$current_color = Color::instance()->sanitize_stored_value( isset( $data['color'] ) ? (string) $data['color'] : '' );
		if ( $current_color === '' ) {
			$current_color = $default_color;
		}
		$image_id = isset( $data['image_id'] ) ? absint( $data['image_id'] ) : 0;
		$thumb    = '';
		if ( $image_id && wp_attachment_is_image( $image_id ) ) {
			$t = wp_get_attachment_image_src( $image_id, 'thumbnail' );
			if ( is_array( $t ) && ! empty( $t[0] ) ) {
				$thumb = (string) $t[0];
			}
		}
		?>
			<div
				class="sto-background-control"
				data-sto-background-control
				data-field-id="<?php echo esc_attr( $field_id ); ?>"
			>
				<div class="sto-background-control__controls">
					<div
						class="sto-color-wrap<?php echo $use_alpha ? ' sto-color--alpha' : ''; ?>"
						<?php if ( $palettes_json ) : ?>
							data-sto-palettes="<?php echo esc_attr( $palettes_json ); ?>"
						<?php endif; ?>
					>
						<input
							type="text"
							id="<?php echo esc_attr( $color_input_id ); ?>"
							value="<?php echo esc_attr( $current_color ); ?>"
							class="sto-color-input sto-background-control-color <?php echo esc_attr( $input_class ); ?>"
							data-default-color="<?php echo esc_attr( $default_color ); ?>"
							data-sto-default="<?php echo esc_attr( $default_color ); ?>"
							<?php if ( $use_alpha ) : ?>
								data-alpha-enabled="true"
								data-alpha-color-type="octohex"
								data-type="full"
								data-alpha-custom-width="0"
							<?php endif; ?>
							autocomplete="off"
						/>
						<button type="button" class="sto-color-reset" aria-label="<?php esc_attr_e( 'Reset to default color', 'simple-theme-options' ); ?>" title="<?php esc_attr_e( 'Reset to default', 'simple-theme-options' ); ?>">
							<i class="fa-light fa-arrow-rotate-left" aria-hidden="true"></i>
						</button>
					</div>
					<button
						type="button"
						class="sto-background-control-upload"
						data-sto-frame-title="<?php echo esc_attr( __( 'Select background image', 'simple-theme-options' ) ); ?>"
						data-sto-frame-button="<?php echo esc_attr( __( 'Use image', 'simple-theme-options' ) ); ?>"
					>
						<i class="fa-light fa-cloud-arrow-up" aria-hidden="true"></i>
						<span class="sto-background-control-upload__label"><?php esc_html_e( 'Upload', 'simple-theme-options' ); ?></span>
					</button>
				</div>
				<div class="sto-background-control__preview"<?php echo $thumb === '' ? ' hidden' : ''; ?>>
					<img
						src="<?php echo $thumb !== '' ? esc_url( $thumb ) : ''; ?>"
						alt=""
						class="sto-background-control__thumb"
						width="60"
						height="60"
						loading="lazy"
						decoding="async"
					/>
				</div>
				<input type="hidden" class="sto-background-control-value" name="<?php echo esc_attr( $hidden_name ); ?>" value="<?php echo esc_attr( $json_value ); ?>" />
			</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $field
	 * @param 'default'|'group_inner' $context
	 */
	public function render_field_markup( $field, $context = 'default' ) {
		if ( ! is_array( $field ) ) {
			return;
		}

		$field_id      = $field['id'];
		$title         = $field['title'];
		$description   = $field['description'];
		$defaults      = isset( $field['default'] ) && is_array( $field['default'] ) ? $field['default'] : array();
		$bps_storage   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$breakpoints   = ( $tabs_pane_bp !== '' && $bps_storage ) ? null : $bps_storage;

		if ( ! empty( $bps_storage ) ) {
			$value_map = $this->get_value_map( $field_id, $defaults, $bps_storage );
		} else {
			$value_arr  = $this->get_merged_value( $field_id, $defaults );
			$json_value = wp_json_encode( $value_arr );
		}

		$required      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$use_alpha     = ! empty( $field['alpha'] );
		$wrapper_class = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$input_class   = isset( $field['class'] ) ? (string) $field['class'] : '';
		$palettes      = isset( $field['palettes'] ) && is_array( $field['palettes'] ) ? $field['palettes'] : array();
		$palettes_clean = array();
		foreach ( $palettes as $p ) {
			$ph = Color::instance()->sanitize_stored_value( (string) $p );
			if ( $ph !== '' ) {
				$palettes_clean[] = $ph;
			}
		}
		$palettes_json = ! empty( $palettes_clean ) ? wp_json_encode( $palettes_clean ) : '';

		$default_arr   = $this->parse_default_array( $defaults );
		$default_color = Color::instance()->sanitize_stored_value( (string) $default_arr['color'] );
		if ( $default_color === '' ) {
			$default_color = '#ffffff';
		}

		$is_inner = ( 'group_inner' === $context );
		$tooltip  = FieldTitle::get_tooltip_config( $field );

		$row_classes = array( 'sto-field-row', 'sto-field-row-color', 'sto-field-row-background-control' );
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
				$json_one       = isset( $value_map[ $tabs_pane_bp ] ) ? (string) $value_map[ $tabs_pane_bp ] : wp_json_encode( $default_arr );
				$color_input_id = 'sto_bg_color_' . $field_id . '_' . $tabs_pane_bp;
				$hidden_name    = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$this->render_background_widget( $field_id, $json_one, $color_input_id, $hidden_name, $default_color, $use_alpha, $input_class, $palettes_json );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					foreach ( $bps_storage as $i => $bp ) :
						$bp            = sanitize_key( (string) $bp );
						$visible       = ( 0 === (int) $i );
						$json_one      = isset( $value_map[ $bp ] ) ? (string) $value_map[ $bp ] : wp_json_encode( $default_arr );
						$color_input_id = 'sto_bg_color_' . $field_id . '_' . $bp;
						$hidden_name   = 'sto_options[' . $field_id . '][' . $bp . ']';
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_background_widget( $field_id, $json_one, $color_input_id, $hidden_name, $default_color, $use_alpha, $input_class, $palettes_json );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$this->render_background_widget(
					$field_id,
					$json_value,
					'sto_bg_color_' . $field_id,
					'sto_options[' . $field_id . ']',
					$default_color,
					$use_alpha,
					$input_class,
					$palettes_json
				);
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string               $field_id
	 * @param array<string, mixed> $defaults
	 * @return array{color: string, image_id: string}
	 */
	private function get_merged_value( $field_id, $defaults ) {
		$base = $this->parse_default_array( $defaults );

		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			return $base;
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

			return is_array( $decoded ) ? $this->merge_decoded_background( $base, $decoded ) : $base;
		}

		if ( ! is_string( $raw ) ) {
			return $base;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return $base;
		}

		return $this->merge_decoded_background( $base, $decoded );
	}
}
