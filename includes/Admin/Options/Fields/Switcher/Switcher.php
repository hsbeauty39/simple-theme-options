<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Switcher;

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

final class Switcher {
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
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::SELECT_BLOCK, 2 );
	}

	/**
	 * Register a boolean switch stored as `1` or `0` in `sto_options[id]`, or per-breakpoint map when `responsive` is set.
	 *
	 * Keys: section_slug, id, title?, description?, default (`1`|`0`), labels? => array( 'on' => 'ON', 'off' => 'OFF' ),
	 * wrapper_class?, required?, group?, tooltip? (see FieldTitle::get_tooltip_config),
	 * optional **responsive** => `true` or non-empty array; optional **`device`** => breakpoint slug list to limit which tabs appear.
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

		$default = isset( $field['default'] ) ? (string) $field['default'] : '0';
		if ( $default !== '1' ) {
			$default = '0';
		}

		$labels = isset( $field['labels'] ) && is_array( $field['labels'] ) ? $field['labels'] : array();
		$on_l   = isset( $labels['on'] ) ? (string) $labels['on'] : __( 'ON', 'topten-simple-theme-options' );
		$off_l  = isset( $labels['off'] ) ? (string) $labels['off'] : __( 'OFF', 'topten-simple-theme-options' );

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['default']       = $default;
		$field['labels']        = array( 'on' => $on_l, 'off' => $off_l );
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
	 * @param mixed $raw Posted value (scalar or breakpoint map).
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$bps = $this->get_responsive_breakpoints( $field_id );
		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->sanitize_stored_value( is_scalar( $cell ) ? (string) $cell : '' );
			}

			return $out;
		}

		return $this->sanitize_stored_value( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) );
	}

	/**
	 * @param string $raw
	 * @return string `1` or `0`
	 */
	public function sanitize_stored_value( $raw ) {
		$v = is_string( $raw ) ? trim( $raw ) : '';

		return ( $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on' ) ? '1' : '0';
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

		$field_id       = $field['id'];
		$title          = $field['title'];
		$description    = $field['description'];
		$default        = isset( $field['default'] ) ? $this->sanitize_stored_value( (string) $field['default'] ) : '0';
		$labels         = isset( $field['labels'] ) && is_array( $field['labels'] ) ? $field['labels'] : array( 'on' => 'ON', 'off' => 'OFF' );
		$on_label       = isset( $labels['on'] ) ? (string) $labels['on'] : 'ON';
		$off_label      = isset( $labels['off'] ) ? (string) $labels['off'] : 'OFF';
		$wrapper_class  = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required       = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json  = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$tooltip       = FieldTitle::get_tooltip_config( $field );
		$bps_storage   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );

		$is_group_inner = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-switcher' );
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
				$value_map = $this->get_value_map( $field_id, $default, $bps_storage );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $default;
				$is_on     = ( $cur === '1' );
				$suffix    = $field_id . '_' . $tabs_pane_bp;
				$name      = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( 'sto-switcher-input-' . $suffix ); ?>" value="<?php echo esc_attr( $cur ); ?>" />
				<button
					type="button"
					class="sto-switcher<?php echo $is_on ? ' sto-switcher--on' : ''; ?>"
					data-sto-switcher
					data-sto-switcher-for="<?php echo esc_attr( $suffix ); ?>"
					aria-pressed="<?php echo $is_on ? 'true' : 'false'; ?>"
					aria-label="<?php echo esc_attr( $title ? $title : $field_id ); ?> — <?php echo esc_attr( strtoupper( $tabs_pane_bp ) ); ?>"
				>
					<span class="sto-switcher__track" aria-hidden="true">
						<span class="sto-switcher__knob"></span>
						<span class="sto-switcher__label sto-switcher__label--on"><?php echo esc_html( $on_label ); ?></span>
						<span class="sto-switcher__label sto-switcher__label--off"><?php echo esc_html( $off_label ); ?></span>
					</span>
				</button>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $default, $bps_storage );
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$cur     = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $default;
						$is_on   = ( $cur === '1' );
						$suffix  = $field_id . '_' . $bp;
						$name    = 'sto_options[' . $field_id . '][' . $bp . ']';
						ResponsiveControl::render_pane_start( $bp, $visible );
						?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( 'sto-switcher-input-' . $suffix ); ?>" value="<?php echo esc_attr( $cur ); ?>" />
						<button
							type="button"
							class="sto-switcher<?php echo $is_on ? ' sto-switcher--on' : ''; ?>"
							data-sto-switcher
							data-sto-switcher-for="<?php echo esc_attr( $suffix ); ?>"
							aria-pressed="<?php echo $is_on ? 'true' : 'false'; ?>"
							aria-label="<?php echo esc_attr( $title ? $title : $field_id ); ?> — <?php echo esc_attr( strtoupper( $bp ) ); ?>"
						>
							<span class="sto-switcher__track" aria-hidden="true">
								<span class="sto-switcher__knob"></span>
								<span class="sto-switcher__label sto-switcher__label--on"><?php echo esc_html( $on_label ); ?></span>
								<span class="sto-switcher__label sto-switcher__label--off"><?php echo esc_html( $off_label ); ?></span>
							</span>
						</button>
						<?php
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$current   = $this->get_option_scalar( $field_id, $default );
				$is_on     = ( $current === '1' );
				$input_name = 'sto_options[' . $field_id . ']';
				?>
				<input type="hidden" name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( 'sto-switcher-input-' . $field_id ); ?>" value="<?php echo esc_attr( $current ); ?>" />
				<button
					type="button"
					class="sto-switcher<?php echo $is_on ? ' sto-switcher--on' : ''; ?>"
					data-sto-switcher
					data-sto-switcher-for="<?php echo esc_attr( $field_id ); ?>"
					aria-pressed="<?php echo $is_on ? 'true' : 'false'; ?>"
					aria-label="<?php echo esc_attr( $title ? $title : $field_id ); ?>"
				>
					<span class="sto-switcher__track" aria-hidden="true">
						<span class="sto-switcher__knob"></span>
						<span class="sto-switcher__label sto-switcher__label--on"><?php echo esc_html( $on_label ); ?></span>
						<span class="sto-switcher__label sto-switcher__label--off"><?php echo esc_html( $off_label ); ?></span>
					</span>
				</button>
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
	private function get_value_map( $field_id, $default, array $breakpoints ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return ResponsiveConfig::coerce_map( null, $breakpoints, $default );
		}

		return ResponsiveConfig::coerce_map( $saved_options[ $field_id ], $breakpoints, $default );
	}

	/**
	 * @param string $field_id
	 * @param string $default
	 * @return string
	 */
	private function get_option_scalar( $field_id, $default ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) ) {
			return $default;
		}

		if ( ! isset( $saved_options[ $field_id ] ) ) {
			return $default;
		}

		$v = $saved_options[ $field_id ];
		if ( is_array( $v ) && ResponsiveConfig::is_breakpoint_value_map( $v ) ) {
			return $this->sanitize_stored_value( ResponsiveConfig::value_for_required_eval( $v ) );
		}

		return $this->sanitize_stored_value( (string) $v );
	}
}
