<?php
namespace SimpleThemeOptions\Admin\Options\Fields\ButtonGroup;

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

final class ButtonGroup {
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
		// After ImageSelect (17), before Select (18).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::BUTTON_GROUP, 2 );
	}

	/**
	 * Segmented control stored as a single string in `sto_options[id]`.
	 *
	 * Keys: section_slug, id (required), title?, description?, default?, required?, wrapper_class?, group?,
	 * tooltip? / tooltip_image? (row heading — see FieldTitle),
	 * options (required): map **value** => **label** string **or** array with **label**, optional **tooltip** (plain text),
	 * optional **preview_image** (URL; same floating image popover as **FieldTitle** on `?` hover, not an inline strip),
	 * optional **responsive** => `true` or non-empty array; optional **`device`** => list of canonical breakpoints to show only those tabs (order follows plugin default).
	 *
	 * Other fields may depend on this with **required** => array( **this_id** => **value** ) (same as Select / Switcher).
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
		$raw_options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		if ( ! $section_slug || ! $field_id || empty( $raw_options ) ) {
			return;
		}

		$options = $this->normalize_options( $raw_options );
		if ( empty( $options ) ) {
			return;
		}

		$keys = array_keys( $options );

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['options']       = $options;

		$bps = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

		$default = isset( $field['default'] ) ? sanitize_key( (string) $field['default'] ) : '';
		if ( $default === '' || ! isset( $options[ $default ] ) ) {
			$default = (string) $keys[0];
		}
		$field['default'] = $default;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]              = $field;
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
	 * @param mixed $raw Posted value (scalar or breakpoint map).
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) || empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return '';
		}
		$options = $field['options'];
		$bps     = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;

		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->sanitize_for_field_raw( $cell, $options );
			}

			return $out;
		}

		return $this->sanitize_for_field_raw( $raw, $options );
	}

	/**
	 * @param array<string, string|array<string, mixed>> $raw
	 * @return array<string, array{label: string, tooltip: string, preview_image: string}>
	 */
	private function normalize_options( $raw ) {
		$out   = array();
		$count = 0;
		foreach ( $raw as $value => $meta ) {
			if ( $count >= 12 ) {
				break;
			}
			$key = sanitize_key( (string) $value );
			if ( $key === '' ) {
				continue;
			}
			if ( is_string( $meta ) ) {
				$out[ $key ] = array(
					'label'         => (string) $meta,
					'tooltip'       => '',
					'preview_image' => '',
				);
				++$count;
				continue;
			}
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$label   = isset( $meta['label'] ) ? (string) $meta['label'] : $key;
			$tip     = isset( $meta['tooltip'] ) ? (string) $meta['tooltip'] : '';
			$preview = ! empty( $meta['preview_image'] ) ? esc_url_raw( (string) $meta['preview_image'] ) : '';
			$out[ $key ] = array(
				'label'         => $label,
				'tooltip'       => $tip,
				'preview_image' => $preview,
			);
			++$count;
		}

		return $out;
	}

	/**
	 * @param string $field_id
	 * @param mixed  $raw
	 * @return string
	 */
	public function sanitize_for_field( $field_id, $raw ) {
		return $this->registry_sanitize_posted_value( $field_id, $raw );
	}

	/**
	 * @param mixed $raw
	 * @param array<string, array<string, string>> $options
	 * @return string
	 */
	private function sanitize_for_field_raw( $raw, $options ) {
		$keys = array_keys( $options );
		if ( empty( $keys ) ) {
			return '';
		}
		$v = is_string( $raw ) ? sanitize_key( $raw ) : '';

		return ( $v !== '' && isset( $options[ $v ] ) ) ? $v : (string) $keys[0];
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
		$default_value = isset( $field['default'] ) ? (string) $field['default'] : '';
		$wrapper_class = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$options       = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$tooltip       = FieldTitle::get_tooltip_config( $field );
		$bps_storage   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );

		$allowed_keys = array_keys( $options );
		$is_group_inner = ( 'group_inner' === $context );
		$group_label    = $title !== '' ? $title : $field_id;

		$row_classes = array( 'sto-field-row', 'sto-field-row-button-group' );
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
				$value_map = $this->get_value_map( $field_id, $default_value, $allowed_keys, $bps_storage );
				$current    = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $default_value;
				$current    = $this->coerce_value( (string) $current, $allowed_keys );
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$id_suffix  = $field_id . '_' . $tabs_pane_bp;
				?>
				<div class="sto-input-wrap sto-button-group-wrap" data-sto-button-group-wrap>
					<?php
					$this->render_button_group_radios(
						$id_suffix,
						$input_name,
						$current,
						$options,
						$group_label . ' — ' . strtoupper( $tabs_pane_bp )
					);
					?>
				</div>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $default_value, $allowed_keys, $bps_storage );
					foreach ( $bps_storage as $i => $bp ) :
						$bp         = sanitize_key( (string) $bp );
						$visible    = ( 0 === (int) $i );
						$current    = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $default_value;
						$current    = $this->coerce_value( (string) $current, $allowed_keys );
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$id_suffix  = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						?>
						<div class="sto-input-wrap sto-button-group-wrap" data-sto-button-group-wrap>
							<?php
							$this->render_button_group_radios(
								$id_suffix,
								$input_name,
								$current,
								$options,
								$group_label . ' — ' . strtoupper( $bp )
							);
							?>
						</div>
						<?php
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$current    = $this->get_option_value( $field_id, $default_value, $allowed_keys );
				$input_name = 'sto_options[' . $field_id . ']';
				?>
				<div class="sto-input-wrap sto-button-group-wrap" data-sto-button-group-wrap>
					<?php $this->render_button_group_radios( $field_id, $input_name, $current, $options, $group_label ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string               $id_suffix Unique fragment for input ids (field id, or field_bp).
	 * @param string               $input_name Full `name` attribute for radios in this group.
	 * @param string               $current Selected value key.
	 * @param array<string, array<string, string>> $options
	 * @param string               $group_label aria-label for the radiogroup.
	 */
	private function render_button_group_radios( $id_suffix, $input_name, $current, array $options, $group_label ) {
		?>
		<div
			class="sto-button-group"
			role="radiogroup"
			aria-label="<?php echo esc_attr( $group_label ); ?>"
		>
			<?php foreach ( $options as $value => $meta ) : ?>
				<?php
				$opt_label = isset( $meta['label'] ) ? (string) $meta['label'] : (string) $value;
				$opt_tip   = isset( $meta['tooltip'] ) ? trim( (string) $meta['tooltip'] ) : '';
				$preview   = isset( $meta['preview_image'] ) ? (string) $meta['preview_image'] : '';
				$rid       = 'sto-button-group-' . $id_suffix . '-' . $value;
				$checked   = ( (string) $current === (string) $value );
				$show_hint = ( $opt_tip !== '' || $preview !== '' );
				if ( $preview !== '' && $opt_tip !== '' ) {
					$aria_help = sprintf(
						/* translators: 1: option label, 2: help text */
						__( 'Preview for %1$s. %2$s', 'topten-simple-theme-options' ),
						$opt_label,
						$opt_tip
					);
				} elseif ( $opt_tip !== '' ) {
					$aria_help = sprintf(
						/* translators: 1: option label, 2: help text */
						__( 'Help for %1$s: %2$s', 'topten-simple-theme-options' ),
						$opt_label,
						$opt_tip
					);
				} else {
					$aria_help = sprintf(
						/* translators: %s: option label */
						__( 'Show layout preview for %s', 'topten-simple-theme-options' ),
						$opt_label
					);
				}
				?>
				<div class="sto-button-group__segment<?php echo $checked ? ' sto-button-group__segment--selected' : ''; ?><?php echo $show_hint ? ' sto-button-group__segment--has-hint' : ''; ?>" data-sto-bg-segment>
					<label class="sto-button-group__choice">
						<input
							type="radio"
							id="<?php echo esc_attr( $rid ); ?>"
							name="<?php echo esc_attr( $input_name ); ?>"
							value="<?php echo esc_attr( (string) $value ); ?>"
							class="sto-button-group__input"
							data-sto-button-group-input
							<?php checked( $checked ); ?>
						/>
						<span class="sto-button-group__label"><?php echo esc_html( $opt_label ); ?></span>
					</label>
					<?php if ( $show_hint ) : ?>
						<button
							type="button"
							class="sto-button-group__hint"
							<?php if ( $opt_tip !== '' && $preview === '' ) : ?>
								data-sto-text-tip="<?php echo esc_attr( $opt_tip ); ?>"
							<?php endif; ?>
							<?php if ( $preview !== '' ) : ?>
								data-sto-tooltip-image="<?php echo esc_attr( $preview ); ?>"
							<?php endif; ?>
							aria-label="<?php echo esc_attr( $aria_help ); ?>"
						>
							<span class="sto-button-group__hint-glyph" aria-hidden="true">?</span>
						</button>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, string> $allowed_keys
	 * @param array<int, string> $breakpoints
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, $default_value, array $allowed_keys, array $breakpoints ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			$scalar = $this->coerce_value( $default_value, $allowed_keys );

			return ResponsiveConfig::coerce_map( null, $breakpoints, $scalar );
		}

		$stored = $saved_options[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$scalar_default = $this->coerce_value( $default_value, $allowed_keys );
			$map            = ResponsiveConfig::coerce_map( $stored, $breakpoints, $scalar_default );
			foreach ( $map as $bp => $val ) {
				$map[ $bp ] = $this->coerce_value( (string) $val, $allowed_keys );
			}

			return $map;
		}

		$scalar = $this->coerce_value( (string) $stored, $allowed_keys );

		return ResponsiveConfig::coerce_map( null, $breakpoints, $scalar );
	}

	/**
	 * @param string        $field_id
	 * @param string        $default_value
	 * @param array<int, string> $allowed_keys
	 * @return string
	 */
	private function get_option_value( $field_id, $default_value, $allowed_keys ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) ) {
			return $this->coerce_value( $default_value, $allowed_keys );
		}
		if ( isset( $saved_options[ $field_id ] ) ) {
			$st = $saved_options[ $field_id ];
			if ( is_array( $st ) && ResponsiveConfig::is_breakpoint_value_map( $st ) ) {
				$coerced = $this->coerce_value( ResponsiveConfig::value_for_required_eval( $st ), $allowed_keys );

				return $coerced !== '' ? $coerced : $this->coerce_value( $default_value, $allowed_keys );
			}

			$coerced = $this->coerce_value( (string) $st, $allowed_keys );

			return $coerced !== '' ? $coerced : $this->coerce_value( $default_value, $allowed_keys );
		}

		return $this->coerce_value( $default_value, $allowed_keys );
	}

	/**
	 * @param string        $value
	 * @param array<int, string> $allowed_keys
	 */
	private function coerce_value( $value, $allowed_keys ) {
		$v = sanitize_key( (string) $value );
		if ( $v !== '' && in_array( $v, $allowed_keys, true ) ) {
			return $v;
		}
		if ( ! empty( $allowed_keys ) ) {
			return (string) $allowed_keys[0];
		}

		return '';
	}
}
