<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Typography;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Data\TypographyFontsCatalog;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Typography {
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
		// Priority 20: after standalone Select (18) and Color (19), before Group (21).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), 20, 2 );
		add_action( 'wp_ajax_sto_typography_fonts', array( $this, 'ajax_font_catalog' ) );
	}

	/**
	 * Register a typography field (JSON: family, variant, subset, transform).
	 *
	 * Keys: section_slug, id, title, description?, default? (array), required? (array), group? (set by Group), optional **responsive** (`true` or non-empty array), optional **`device`** => breakpoint slug list.
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
	 * @param mixed $field
	 */
	private function register_field_config( $field ): void {
		if ( ! is_array( $field ) ) {
			return;
		}

		$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
		$field_id     = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
		$title        = isset( $field['title'] ) ? (string) $field['title'] : '';

		if ( ! $section_slug || ! $field_id ) {
			return;
		}

		$defaults = $this->parse_default( isset( $field['default'] ) ? $field['default'] : array() );

		$field['section_slug'] = $section_slug;
		$field['id']           = $field_id;
		$field['title']        = $title;
		$field['description']  = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['required']     = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']        = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['default']      = $defaults;

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

	/**
	 * @param array<string, mixed> $default
	 * @return array{family: string, variant: string, subset: string, transform: string}
	 */
	private function parse_default( $default ) {
		if ( ! is_array( $default ) ) {
			$default = array();
		}

		$variant = isset( $default['variant'] ) ? strtolower( (string) $default['variant'] ) : 'regular';
		if ( ! preg_match( '/^(regular|italic|\d{3}|\d{3}italic)$/', $variant ) ) {
			$variant = 'regular';
		}

		$subset = isset( $default['subset'] ) ? sanitize_key( (string) $default['subset'] ) : 'latin';
		if ( ! in_array( $subset, $this->get_subset_keys(), true ) ) {
			$subset = 'latin';
		}

		$transform = isset( $default['transform'] ) ? sanitize_key( (string) $default['transform'] ) : 'none';
		if ( $transform === '' ) {
			$transform = 'none';
		}

		return array(
			'family'    => isset( $default['family'] ) ? sanitize_text_field( (string) $default['family'] ) : '',
			'variant'   => $variant,
			'subset'    => $subset,
			'transform' => $transform,
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function get_subset_keys() {
		return array_keys( $this->get_subset_choices() );
	}

	/**
	 * @return array<string, string>
	 */
	private function get_subset_choices() {
		return array(
			'latin'      => __( 'Latin', 'simple-theme-options' ),
			'latin-ext'  => __( 'Latin Extended', 'simple-theme-options' ),
			'cyrillic'   => __( 'Cyrillic', 'simple-theme-options' ),
			'greek'      => __( 'Greek', 'simple-theme-options' ),
			'vietnamese' => __( 'Vietnamese', 'simple-theme-options' ),
			'arabic'     => __( 'Arabic', 'simple-theme-options' ),
		);
	}

	public function registry_is_registered_field_id( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );

		return $field_id && ! empty( $this->registered_ids[ $field_id ] );
	}

	/**
	 * @param string $raw JSON string from POST
	 * @return string JSON
	 */
	public function sanitize_stored_value( $raw ) {
		$data = json_decode( is_string( $raw ) ? $raw : '', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$map   = TypographyFontsCatalog::get_fonts_map();
		$clean = $this->parse_default( $data );

		if ( ! preg_match( '/^(regular|italic|\d{3}|\d{3}italic)$/', $clean['variant'] ) ) {
			$clean['variant'] = 'regular';
		}

		if ( $clean['family'] === '' || ! isset( $map[ $clean['family'] ] ) ) {
			$clean['family']  = '';
			$clean['variant'] = 'regular';
		} else {
			$variants = isset( $map[ $clean['family'] ]['variants'] ) && is_array( $map[ $clean['family'] ]['variants'] )
				? $map[ $clean['family'] ]['variants']
				: array( 'regular' );
			if ( ! in_array( $clean['variant'], $variants, true ) ) {
				$clean['variant'] = (string) $variants[0];
			}
		}

		$allowed_subset = $this->get_subset_keys();
		if ( ! in_array( $clean['subset'], $allowed_subset, true ) ) {
			$clean['subset'] = 'latin';
		}

		$allowed_transform = array( 'none', 'uppercase', 'lowercase', 'capitalize', 'inherit' );
		if ( ! in_array( $clean['transform'], $allowed_transform, true ) ) {
			$clean['transform'] = 'none';
		}

		return wp_json_encode( $clean );
	}

	/**
	 * @param string               $field_id
	 * @param string|array<mixed> $raw Posted value (JSON string or per-breakpoint map of JSON strings).
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
				$out[ $bp ] = $this->sanitize_stored_value( is_string( $cell ) ? $cell : '' );
			}

			return $out;
		}

		return $this->sanitize_stored_value( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) );
	}

	public function ajax_font_catalog() {
		check_ajax_referer( 'sto_typography_fonts', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		wp_send_json_success(
			array(
				'fonts' => TypographyFontsCatalog::get_fonts_list(),
			)
		);
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
	 * @param string               $field_id
	 * @param array<string, mixed> $parsed_defaults From {@see parse_default()}.
	 * @param string               $json_value      Sanitized JSON for hidden input.
	 * @param string               $id_suffix       Unique fragment for control `id`s (field id or field-bp).
	 * @param string               $input_name      `name` attribute for hidden input.
	 * @param string               $font_owner_id   Unique id for dynamic font `<link>` (avoid collisions across breakpoints).
	 */
	private function render_typography_stack( $field_id, array $parsed_defaults, $json_value, $id_suffix, $input_name, $font_owner_id ) {
		?>
			<div
				class="sto-typography"
				data-sto-typography
				data-field-id="<?php echo esc_attr( $field_id ); ?>"
				data-sto-font-owner="<?php echo esc_attr( $font_owner_id ); ?>"
				data-default="<?php echo esc_attr( wp_json_encode( $parsed_defaults ) ); ?>"
			>
				<div class="sto-typography-loading" aria-busy="true">
					<div class="sto-typography-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Loading font catalog', 'simple-theme-options' ); ?>">
						<div class="sto-typography-progress-bar"></div>
					</div>
				</div>
				<div class="sto-typography-body" hidden>
					<div class="sto-typography-panel">
						<div class="sto-typography-grid" role="group" aria-label="<?php esc_attr_e( 'Typography options', 'simple-theme-options' ); ?>">
							<div class="sto-typography-cell">
								<label class="screen-reader-text" for="<?php echo esc_attr( 'sto-typo-family-' . $id_suffix ); ?>"><?php esc_html_e( 'Font family', 'simple-theme-options' ); ?></label>
								<select
									id="<?php echo esc_attr( 'sto-typo-family-' . $id_suffix ); ?>"
									class="sto-typography-select sto-typography-family sto-input-select"
									data-sto-typography-family
								></select>
							</div>
							<div class="sto-typography-cell">
								<label class="screen-reader-text" for="<?php echo esc_attr( 'sto-typo-variant-' . $id_suffix ); ?>"><?php esc_html_e( 'Font style', 'simple-theme-options' ); ?></label>
								<select
									id="<?php echo esc_attr( 'sto-typo-variant-' . $id_suffix ); ?>"
									class="sto-typography-select sto-typography-variant sto-input-select"
									data-sto-typography-variant
								></select>
							</div>
							<div class="sto-typography-cell">
								<label class="screen-reader-text" for="<?php echo esc_attr( 'sto-typo-subset-' . $id_suffix ); ?>"><?php esc_html_e( 'Character subset', 'simple-theme-options' ); ?></label>
								<select
									id="<?php echo esc_attr( 'sto-typo-subset-' . $id_suffix ); ?>"
									class="sto-typography-select sto-typography-subset sto-input-select"
									data-sto-typography-subset
								>
									<option value=""><?php esc_html_e( 'Subset', 'simple-theme-options' ); ?></option>
									<?php foreach ( $this->get_subset_choices() as $val => $slabel ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $slabel ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="sto-typography-cell">
								<label class="screen-reader-text" for="<?php echo esc_attr( 'sto-typo-transform-' . $id_suffix ); ?>"><?php esc_html_e( 'Text transform', 'simple-theme-options' ); ?></label>
								<select
									id="<?php echo esc_attr( 'sto-typo-transform-' . $id_suffix ); ?>"
									class="sto-typography-select sto-typography-transform sto-input-select"
									data-sto-typography-transform
								>
									<option value=""><?php esc_html_e( 'Text transform', 'simple-theme-options' ); ?></option>
									<option value="none"><?php esc_html_e( 'None', 'simple-theme-options' ); ?></option>
									<option value="uppercase"><?php esc_html_e( 'Uppercase', 'simple-theme-options' ); ?></option>
									<option value="lowercase"><?php esc_html_e( 'Lowercase', 'simple-theme-options' ); ?></option>
									<option value="capitalize"><?php esc_html_e( 'Capitalize', 'simple-theme-options' ); ?></option>
									<option value="inherit"><?php esc_html_e( 'Inherit', 'simple-theme-options' ); ?></option>
								</select>
							</div>
						</div>
						<div class="sto-typography-preview" data-sto-typography-preview>
							<p class="sto-typography-preview-text"><?php echo esc_html( '1 2 3 4 5 6 7 8 9 0 A B C D E F G H I J K L M N O P Q R S T U V W X Y Z a b c d e f g h i j k l m n o p q r s t u v w x y z' ); ?></p>
						</div>
					</div>
				</div>
				<input type="hidden" class="sto-typography-value" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $json_value ); ?>" />
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
		$required      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$defaults_raw  = isset( $field['default'] ) && is_array( $field['default'] ) ? $field['default'] : array();
		$parsed_def    = $this->parse_default( $defaults_raw );
		$bps_storage   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$breakpoints   = ( $tabs_pane_bp !== '' && $bps_storage ) ? null : $bps_storage;

		if ( ! empty( $bps_storage ) ) {
			$value_map = $this->get_value_map( $field_id, $parsed_def, $bps_storage );
		} else {
			$value_arr  = $this->get_merged_value( $field_id, $defaults_raw );
			$json_value = wp_json_encode( $value_arr );
		}

		$is_inner = ( 'group_inner' === $context );
		$tooltip  = FieldTitle::get_tooltip_config( $field );

		$row_classes = array( 'sto-field-row', 'sto-field-row-typography' );
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
				$cur_json   = isset( $value_map[ $tabs_pane_bp ] ) ? (string) $value_map[ $tabs_pane_bp ] : wp_json_encode( $parsed_def );
				$id_suffix  = $field_id . '-' . $tabs_pane_bp;
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$font_owner = $field_id . '-' . $tabs_pane_bp;
				$this->render_typography_stack( $field_id, $parsed_def, $cur_json, $id_suffix, $input_name, $font_owner );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					foreach ( $bps_storage as $i => $bp ) :
						$bp           = sanitize_key( (string) $bp );
						$visible      = ( 0 === (int) $i );
						$cur_json     = isset( $value_map[ $bp ] ) ? (string) $value_map[ $bp ] : wp_json_encode( $parsed_def );
						$id_suffix    = $field_id . '-' . $bp;
						$input_name   = 'sto_options[' . $field_id . '][' . $bp . ']';
						$font_owner   = $field_id . '-' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_typography_stack( $field_id, $parsed_def, $cur_json, $id_suffix, $input_name, $font_owner );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$this->render_typography_stack(
					$field_id,
					$parsed_def,
					$json_value,
					$field_id,
					'sto_options[' . $field_id . ']',
					$field_id
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
	 * @param array<string, mixed> $parsed_defaults
	 * @param array<int, string>   $breakpoints
	 * @return array<string, string> Breakpoint => JSON string
	 */
	private function get_value_map( $field_id, array $parsed_defaults, array $breakpoints ) {
		$default_json  = wp_json_encode( $parsed_defaults );
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			$map = ResponsiveConfig::coerce_map( null, $breakpoints, $default_json );
		} else {
			$stored = $saved_options[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$map = ResponsiveConfig::coerce_map( $stored, $breakpoints, $default_json );
			} else {
				$scalar = '';
				if ( is_string( $stored ) ) {
					$scalar = $this->sanitize_stored_value( $stored );
				}
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
	 * @param string $field_id
	 * @param array<string, string> $defaults
	 * @return array{family: string, variant: string, subset: string, transform: string}
	 */
	private function get_merged_value( $field_id, $defaults ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			return $this->parse_default( $defaults );
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
			if ( is_array( $decoded ) ) {
				return $this->parse_default( array_merge( $defaults, $decoded ) );
			}

			return $this->parse_default( $defaults );
		}
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $this->parse_default( array_merge( $defaults, $decoded ) );
			}
		}

		return $this->parse_default( $defaults );
	}
}
