<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Select;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSpacing;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Select {
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
		// Priority 18: before Typography (19) so section output follows typical registration (selects first, then typography, then groups at 21).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::SELECT_BLOCK, 2 );
	}

	/**
	 * Register one select field.
	 *
	 * Supported keys:
	 * - section_slug (required)
	 * - id (required)
	 * - title
	 * - description
	 * - options (required) [ value => label ]
	 * - default (string for single; array | comma list for multiple)
	 * - placeholder
	 * - class
	 * - wrapper_class
	 * - required (optional)
	 * - multiple (optional bool) — when true, the select stores an array of option keys; selected values render as blue Select2 chips with a "×" remove button.
	 * - max (optional int) — when `multiple` is true, max simultaneous selections (0 = unlimited; capped at 100).
	 * - tooltip: array( 'image' => URL ) — optional `'preloader'` only if you want a custom image/video instead of the built-in CSS spinner while loading; or shorthand `tooltip_image` / optional `tooltip_preloader`
	 * - optional **responsive** => `true` or non-empty array (opts in to per-breakpoint storage)
	 * - optional **responsive_defaults** => map of breakpoint slug => default option value (admin UI + reads before first save)
	 * - optional **device** => list of extra canonical breakpoint slugs; tabs = **union** of the default trio (`xxl`, `md`, `mobile`) and these keys (de-duplicated, **xxl → mobile** order). Omit or empty array for the default trio only.
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
	 * Register multiple fields in one call.
	 *
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
		$options      = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		if ( ! $section_slug || ! $field_id || empty( $options ) ) {
			return;
		}

		$multiple = ! empty( $field['multiple'] );
		$max_sel  = isset( $field['max'] ) ? (int) $field['max'] : 0;
		if ( $max_sel < 0 ) {
			$max_sel = 0;
		}
		if ( $max_sel > 100 ) {
			$max_sel = 100;
		}

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['multiple']      = $multiple;
		$field['max']           = $max_sel;
		$field['default']       = $multiple
			? $this->normalize_default_list( $field['default'] ?? array(), $options )
			: ( isset( $field['default'] ) ? (string) $field['default'] : '' );
		$field['placeholder']   = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$field['class']         = isset( $field['class'] ) ? (string) $field['class'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$bps                    = ResponsiveConfig::breakpoints_for_field( $field );
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
	 * @return array<int, string>|null
	 */
	public function get_responsive_breakpoints( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;

		return is_array( $field ) ? ( $field['responsive_breakpoints'] ?? null ) : null;
	}

	/**
	 * @param mixed $raw Posted value (scalar, array of option keys, or breakpoint map).
	 * @return string|array<int, string>|array<string, string|array<int, string>>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) || empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return ! empty( $field['multiple'] ) ? array() : '';
		}

		$multiple = ! empty( $field['multiple'] );
		$bps      = $field['responsive_breakpoints'] ?? null;

		if ( ! empty( $bps ) && is_array( $raw ) && $this->is_breakpoint_shaped_post( $raw, $bps ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp   = sanitize_key( (string) $bp );
				$cell = array_key_exists( $bp, $raw ) ? $raw[ $bp ] : ( $multiple ? array() : '' );
				if ( $multiple ) {
					$out[ $bp ] = $this->sanitize_multiple( $field, $cell );
				} else {
					$out[ $bp ] = $this->sanitize_scalar( $field, is_scalar( $cell ) ? (string) $cell : '' );
				}
			}

			return $out;
		}

		if ( $multiple ) {
			return $this->sanitize_multiple( $field, $raw );
		}

		return $this->sanitize_scalar( $field, is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) );
	}

	/**
	 * When a `<select multiple>` has no selection, PHP omits the key from POST. Mirrors
	 * {@see DynamicObject::merge_missing_multiple_dynamic_fields()} so cleared multi-select
	 * persists as an empty array (per breakpoint when responsive) instead of being
	 * forward-copied from prior storage.
	 *
	 * @param array<string, mixed>     $posted
	 * @param array<string, mixed>     $sanitized
	 * @param array<string, true>|null $only_field_ids_map When set (field id => true), only these ids are considered (partial section save).
	 */
	public function merge_missing_multiple_select_fields( $posted, array &$sanitized, ?array $only_field_ids_map = null ) {
		if ( ! is_array( $posted ) ) {
			$posted = array();
		}
		if ( is_array( $only_field_ids_map ) && $only_field_ids_map === array() ) {
			return;
		}
		foreach ( $this->fields_by_id as $fid => $cfg ) {
			if ( $only_field_ids_map !== null && ! isset( $only_field_ids_map[ $fid ] ) ) {
				continue;
			}
			if ( empty( $cfg['multiple'] ) ) {
				continue;
			}
			$bps = isset( $cfg['responsive_breakpoints'] ) && is_array( $cfg['responsive_breakpoints'] ) ? $cfg['responsive_breakpoints'] : null;
			if ( ! empty( $bps ) ) {
				$posted_f = isset( $posted[ $fid ] ) && is_array( $posted[ $fid ] ) ? $posted[ $fid ] : array();
				$san_f    = isset( $sanitized[ $fid ] ) && is_array( $sanitized[ $fid ] ) ? $sanitized[ $fid ] : array();
				foreach ( $bps as $bp ) {
					$bp = sanitize_key( (string) $bp );
					if ( array_key_exists( $bp, $san_f ) ) {
						continue;
					}
					if ( array_key_exists( $bp, $posted_f ) ) {
						continue;
					}
					if ( ! isset( $sanitized[ $fid ] ) || ! is_array( $sanitized[ $fid ] ) ) {
						$sanitized[ $fid ] = array();
					}
					$sanitized[ $fid ][ $bp ] = array();
				}
				continue;
			}
			if ( array_key_exists( $fid, $sanitized ) ) {
				continue;
			}
			if ( array_key_exists( $fid, $posted ) ) {
				continue;
			}
			$sanitized[ $fid ] = array();
		}
	}

	/**
	 * @param array<string, mixed> $field
	 * @param string               $raw
	 * @return string
	 */
	private function sanitize_scalar( array $field, $raw ) {
		$options = $field['options'];
		$default = isset( $field['default'] ) ? (string) $field['default'] : '';
		$v       = is_string( $raw ) ? $raw : '';

		foreach ( array_keys( $options ) as $ok ) {
			if ( (string) $ok === $v ) {
				return (string) $ok;
			}
		}

		foreach ( array_keys( $options ) as $ok ) {
			if ( (string) $ok === $default ) {
				return (string) $ok;
			}
		}

		return (string) array_key_first( $options );
	}

	/**
	 * Validate posted values against the field's `options` map, dedupe, respect optional `max`.
	 *
	 * @param array<string, mixed>      $field
	 * @param mixed                     $raw
	 * @return array<int, string>
	 */
	private function sanitize_multiple( array $field, $raw ) {
		$options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
		if ( empty( $options ) ) {
			return array();
		}
		$max  = isset( $field['max'] ) ? (int) $field['max'] : 0;
		$keys = array_flip( array_map( 'strval', array_keys( $options ) ) );
		$list = $this->parse_incoming_value_list( $raw );

		$out = array();
		foreach ( $list as $v ) {
			$v = (string) $v;
			if ( $v === '' || ! isset( $keys[ $v ] ) ) {
				continue;
			}
			if ( in_array( $v, $out, true ) ) {
				continue;
			}
			$out[] = $v;
			if ( $max > 0 && count( $out ) >= $max ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param mixed $value
	 * @return array<int, string>
	 */
	private function parse_incoming_value_list( $value ) {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_array( $value ) ) {
			$list = array();
			foreach ( $value as $v ) {
				if ( is_scalar( $v ) && (string) $v !== '' ) {
					$list[] = (string) $v;
				}
			}

			return $list;
		}
		if ( ! is_scalar( $value ) ) {
			return array();
		}
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return array();
		}
		if ( strpos( $value, ',' ) !== false ) {
			return array_map( 'trim', explode( ',', $value ) );
		}

		return array( $value );
	}

	/**
	 * @param mixed                $default
	 * @param array<string, mixed> $options
	 * @return array<int, string>
	 */
	private function normalize_default_list( $default, array $options ) {
		$keys = array_flip( array_map( 'strval', array_keys( $options ) ) );
		$list = $this->parse_incoming_value_list( $default );
		$out  = array();
		foreach ( $list as $v ) {
			$v = (string) $v;
			if ( $v === '' || ! isset( $keys[ $v ] ) || in_array( $v, $out, true ) ) {
				continue;
			}
			$out[] = $v;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $value
	 * @param array<int, string>   $bps
	 */
	private function is_breakpoint_shaped_post( $value, array $bps ) {
		if ( ! is_array( $value ) || $value === array() ) {
			return true;
		}
		$allowed = array_flip( $bps );
		foreach ( array_keys( $value ) as $k ) {
			if ( ! isset( $allowed[ sanitize_key( (string) $k ) ] ) ) {
				return false;
			}
		}

		return true;
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
			$this->render_field( $field, 'default' );
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
	 * Flat list of registered selects for search / admin UI (includes grouped fields).
	 *
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

		$this->render_field( $field, $context );
	}

	/**
	 * @param array<string, mixed> $field
	 * @param 'default'|'group_inner' $context
	 */
	private function render_field( $field, $context = 'default' ) {
		$field_id       = $field['id'];
		$title          = $field['title'];
		$description    = $field['description'];
		$placeholder    = $field['placeholder'];
		$default_value  = $field['default'];
		$wrapper_class  = $field['wrapper_class'];
		$select_class   = $field['class'];
		$required       = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$options        = is_array( $field['options'] ) ? $field['options'] : array();
		$required_json  = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$is_group_inner = ( 'group_inner' === $context );
		$tooltip       = FieldTitle::get_tooltip_config( $field );
		$bps_storage   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$multiple      = ! empty( $field['multiple'] );
		$max_sel       = isset( $field['max'] ) ? (int) $field['max'] : 0;

		$row_classes = array( 'sto-field-row', 'sto-field-row-select' );
		if ( $multiple ) {
			$row_classes[] = 'sto-field-row-select--multiple';
		}
		if ( $wrapper_class ) {
			$row_classes[] = $wrapper_class;
		}
		if ( $is_group_inner ) {
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
				<?php FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_group_inner, $toolbar_markup ); ?>
			<?php endif; ?>

			<?php if ( $tabs_pane_bp !== '' && $bps_storage ) : ?>
				<?php
				if ( $multiple ) {
					$cur_list = $this->get_option_list( $field_id, $default_value, $options, $tabs_pane_bp );
					$sel_nm   = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . '][]';
				} else {
					$value_map = $this->get_value_map( $field_id, (string) $default_value, $bps_storage );
					$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : (string) $default_value;
					$sel_nm    = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				}
				$sel_id = $field_id . '_' . $tabs_pane_bp;
				?>
				<div class="sto-select-wrap">
					<select
						id="<?php echo esc_attr( $sel_id ); ?>"
						name="<?php echo esc_attr( $sel_nm ); ?>"
						class="sto-input-select <?php echo esc_attr( $select_class ); ?>"
						<?php echo $multiple ? ' multiple="multiple"' : ''; ?>
						data-multiple="<?php echo $multiple ? '1' : '0'; ?>"
						data-max-selections="<?php echo (int) $max_sel; ?>"
						data-placeholder-text="<?php echo esc_attr( $placeholder ); ?>"
					>
						<?php if ( $multiple ) : ?>
							<option></option>
							<?php foreach ( $options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>"<?php echo in_array( (string) $value, $cur_list, true ) ? ' selected="selected"' : ''; ?>>
									<?php echo esc_html( (string) $label ); ?>
								</option>
							<?php endforeach; ?>
						<?php else : ?>
							<?php if ( $placeholder ) : ?>
								<option value=""><?php echo esc_html( $placeholder ); ?></option>
							<?php endif; ?>
							<?php foreach ( $options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $cur, (string) $value ); ?>>
									<?php echo esc_html( (string) $label ); ?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
				</div>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$sel_id  = $field_id . '_' . $bp;
						if ( $multiple ) {
							$cur_list = $this->get_option_list( $field_id, $default_value, $options, $bp );
							$sel_nm   = 'sto_options[' . $field_id . '][' . $bp . '][]';
						} else {
							$value_map = $this->get_value_map( $field_id, (string) $default_value, $bps_storage );
							$cur       = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : (string) $default_value;
							$sel_nm    = 'sto_options[' . $field_id . '][' . $bp . ']';
						}
						ResponsiveControl::render_pane_start( $bp, $visible );
						?>
						<div class="sto-select-wrap">
							<select
								id="<?php echo esc_attr( $sel_id ); ?>"
								name="<?php echo esc_attr( $sel_nm ); ?>"
								class="sto-input-select <?php echo esc_attr( $select_class ); ?>"
								<?php echo $multiple ? ' multiple="multiple"' : ''; ?>
								data-multiple="<?php echo $multiple ? '1' : '0'; ?>"
								data-max-selections="<?php echo (int) $max_sel; ?>"
								data-placeholder-text="<?php echo esc_attr( $placeholder ); ?>"
							>
								<?php if ( $multiple ) : ?>
									<option></option>
									<?php foreach ( $options as $value => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $value ); ?>"<?php echo in_array( (string) $value, $cur_list, true ) ? ' selected="selected"' : ''; ?>>
											<?php echo esc_html( (string) $label ); ?>
										</option>
									<?php endforeach; ?>
								<?php else : ?>
									<?php if ( $placeholder ) : ?>
										<option value=""><?php echo esc_html( $placeholder ); ?></option>
									<?php endif; ?>
									<?php foreach ( $options as $value => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $cur, (string) $value ); ?>>
											<?php echo esc_html( (string) $label ); ?>
										</option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
						</div>
						<?php
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				if ( $multiple ) {
					$current_list = $this->get_option_list( $field_id, $default_value, $options, null );
					$select_name  = 'sto_options[' . $field_id . '][]';
				} else {
					$current_value = $this->get_option_scalar( $field_id, (string) $default_value );
					$select_name   = 'sto_options[' . $field_id . ']';
				}
				?>
				<div class="sto-select-wrap">
					<select
						id="<?php echo esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( $select_name ); ?>"
						class="sto-input-select <?php echo esc_attr( $select_class ); ?>"
						<?php echo $multiple ? ' multiple="multiple"' : ''; ?>
						data-multiple="<?php echo $multiple ? '1' : '0'; ?>"
						data-max-selections="<?php echo (int) $max_sel; ?>"
						data-placeholder-text="<?php echo esc_attr( $placeholder ); ?>"
					>
						<?php if ( $multiple ) : ?>
							<option></option>
							<?php foreach ( $options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>"<?php echo in_array( (string) $value, $current_list, true ) ? ' selected="selected"' : ''; ?>>
									<?php echo esc_html( (string) $label ); ?>
								</option>
							<?php endforeach; ?>
						<?php else : ?>
							<?php if ( $placeholder ) : ?>
								<option value=""><?php echo esc_html( $placeholder ); ?></option>
							<?php endif; ?>
							<?php foreach ( $options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $current_value, (string) $value ); ?>>
									<?php echo esc_html( (string) $label ); ?>
								</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
				</div>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, $default_value, array $breakpoints ) {
		$per_breakpoint_defaults = array();
		$field_config            = $this->fields_by_id[ $field_id ] ?? null;
		if ( is_array( $field_config ) && isset( $field_config['responsive_defaults'] ) && is_array( $field_config['responsive_defaults'] ) ) {
			$per_breakpoint_defaults = $field_config['responsive_defaults'];
		}

		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return ResponsiveConfig::coerce_map( null, $breakpoints, (string) $default_value, $per_breakpoint_defaults );
		}

		return ResponsiveConfig::coerce_map( $saved_options[ $field_id ], $breakpoints, (string) $default_value, $per_breakpoint_defaults );
	}

	/**
	 * Stored list of option keys for a multi-select (filtered against the field's `options`).
	 *
	 * @param string                    $field_id
	 * @param array<int, string>|string $default_list
	 * @param array<string, mixed>      $options
	 * @param string|null               $bp_context Breakpoint key when the field is responsive; null = non-responsive read.
	 * @return array<int, string>
	 */
	private function get_option_list( $field_id, $default_list, array $options, $bp_context = null ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! array_key_exists( $field_id, $saved ) ) {
			$raw = $default_list;
		} else {
			$root = $saved[ $field_id ];
			if ( null !== $bp_context && '' !== $bp_context && is_array( $root ) && ResponsiveConfig::is_breakpoint_value_map( $root ) ) {
				$slice = ResponsiveConfig::raw_value_at_breakpoint( $root, sanitize_key( (string) $bp_context ) );
				$raw   = null !== $slice ? $slice : $default_list;
			} elseif ( is_array( $root ) && ResponsiveConfig::is_breakpoint_value_map( $root ) ) {
				$slice = ResponsiveConfig::raw_value_at_breakpoint( $root, ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT );
				$raw   = null !== $slice ? $slice : $default_list;
			} else {
				$raw = $root;
			}
		}

		$candidates = $this->parse_incoming_value_list( $raw );
		$keys       = array_flip( array_map( 'strval', array_keys( $options ) ) );
		$out        = array();
		foreach ( $candidates as $v ) {
			$v = (string) $v;
			if ( $v === '' || ! isset( $keys[ $v ] ) || in_array( $v, $out, true ) ) {
				continue;
			}
			$out[] = $v;
		}

		return $out;
	}

	/**
	 * @param string $field_id
	 * @param string $default_value
	 * @return string
	 */
	private function get_option_scalar( $field_id, $default_value ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) ) {
			return (string) $default_value;
		}

		if ( ! isset( $saved_options[ $field_id ] ) ) {
			return (string) $default_value;
		}

		$v = $saved_options[ $field_id ];
		if ( is_array( $v ) && ResponsiveConfig::is_breakpoint_value_map( $v ) ) {
			return ResponsiveConfig::value_for_required_eval( $v );
		}

		return (string) $v;
	}
}
