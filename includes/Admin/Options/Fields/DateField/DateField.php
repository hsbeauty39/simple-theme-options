<?php
namespace SimpleThemeOptions\Admin\Options\Fields\DateField;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Calendar date field (jQuery UI Datepicker). Stored as **Y-m-d** or empty string; responsive fields use a breakpoint map.
 *
 * Register with **`'type' => 'date'`**. Keys: **`section_slug`**, **`id`**, **`title`**, optional **`description`**, **`default`** (Y-m-d or `''`),
 * **`placeholder`**, **`min_date`** / **`max_date`** (inclusive bounds, Y-m-d), conditional **`required`**, **`html_required`**, **`tooltip`**, **`wrapper_class`**,
 * optional **`responsive`** + **`device`** (see {@see ResponsiveConfig}).
 */
final class DateField {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

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
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::DATE, 2 );
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

		$def = $this->sanitize_ymd_scalar( isset( $field['default'] ) ? $field['default'] : '', array() );

		$field['section_slug']             = $section_slug;
		$field['id']                       = $field_id;
		$field['title']                    = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']              = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class']            = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']                 = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']                    = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['placeholder']              = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$field['html_required']            = ! empty( $field['html_required'] );
		$field['default_ymd']              = $def;
		$field['min_date']                 = $this->sanitize_ymd_scalar( isset( $field['min_date'] ) ? $field['min_date'] : '', array() );
		$field['max_date']                 = $this->sanitize_ymd_scalar( isset( $field['max_date'] ) ? $field['max_date'] : '', array() );
		$field['responsive_breakpoints']   = ResponsiveConfig::breakpoints_for_field( $field );

		if ( $field['min_date'] !== '' && $field['max_date'] !== '' && strcmp( $field['min_date'], $field['max_date'] ) > 0 ) {
			$tmp                 = $field['min_date'];
			$field['min_date']   = $field['max_date'];
			$field['max_date']   = $tmp;
		}

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
	 * @return array<int, string>|null
	 */
	public function get_responsive_breakpoints( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;

		return is_array( $field ) ? ( $field['responsive_breakpoints'] ?? null ) : null;
	}

	/**
	 * @param mixed $raw Posted string, breakpoint map, or malformed array.
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
				$out[ $bp ] = $this->sanitize_ymd_scalar( is_scalar( $cell ) ? (string) $cell : '', $field );
			}

			return $out;
		}

		return $this->sanitize_ymd_scalar( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ), $field );
	}

	/**
	 * @param string               $raw
	 * @param array<string, mixed> $field
	 * @return string Empty or Y-m-d within optional min/max.
	 */
	public function sanitize_ymd_scalar( $raw, array $field ) {
		$s = is_string( $raw ) ? trim( $raw ) : '';
		if ( $s === '' ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
			return '';
		}
		$y = (int) $m[1];
		$mo = (int) $m[2];
		$d = (int) $m[3];
		if ( ! checkdate( $mo, $d, $y ) ) {
			return '';
		}
		$out = sprintf( '%04d-%02d-%02d', $y, $mo, $d );
		$min = isset( $field['min_date'] ) ? (string) $field['min_date'] : '';
		$max = isset( $field['max_date'] ) ? (string) $field['max_date'] : '';
		if ( $min !== '' && strcmp( $out, $min ) < 0 ) {
			return $min;
		}
		if ( $max !== '' && strcmp( $out, $max ) > 0 ) {
			return $max;
		}

		return $out;
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
		if ( is_array( $raw ) && ResponsiveConfig::is_breakpoint_value_map( $raw ) ) {
			$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : array();
			foreach ( $bps as $bp ) {
				$bp   = sanitize_key( (string) $bp );
				$cell = array_key_exists( $bp, $raw ) ? $raw[ $bp ] : '';
				if ( ! is_string( $cell ) || trim( $cell ) === '' ) {
					return true;
				}
			}

			return false;
		}

		if ( is_string( $raw ) ) {
			return trim( $raw ) === '';
		}
		if ( is_scalar( $raw ) ) {
			return trim( (string) $raw ) === '';
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
		$placeholder   = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$html_req      = ! empty( $field['html_required'] );
		$def_ymd       = isset( $field['default_ymd'] ) ? (string) $field['default_ymd'] : '';
		$min_d         = isset( $field['min_date'] ) ? (string) $field['min_date'] : '';
		$max_d         = isset( $field['max_date'] ) ? (string) $field['max_date'] : '';

		$is_inner = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-date' );
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
				$value_map = $this->get_value_map( $field_id, $def_ymd, $bps_storage, $field );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : '';
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$suffix     = $field_id . '_' . $tabs_pane_bp;
				$this->render_date_control( $suffix, $input_name, $cur, $placeholder, $html_req, $min_d, $max_d );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $def_ymd, $bps_storage, $field );
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$cur     = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : '';
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$suffix     = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_date_control( $suffix, $input_name, $cur, $placeholder, $html_req, $min_d, $max_d );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$cur = $this->get_option_scalar( $field_id, $def_ymd, $field );
				$this->render_date_control( $field_id, 'sto_options[' . $field_id . ']', $cur, $placeholder, $html_req, $min_d, $max_d );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string $suffix      DOM suffix (field id or field_bp).
	 * @param string $input_name  Full `name` for the hidden ISO input.
	 * @param string $current_ymd Canonical Y-m-d or empty.
	 * @param string $placeholder Display input placeholder.
	 * @param bool   $html_req    HTML5 required on hidden value.
	 * @param string $min_date    Y-m-d or empty.
	 * @param string $max_date    Y-m-d or empty.
	 */
	private function render_date_control( $suffix, $input_name, $current_ymd, $placeholder, $html_req, $min_date, $max_date ) {
		$current_ymd = $this->sanitize_ymd_scalar( (string) $current_ymd, array( 'min_date' => $min_date, 'max_date' => $max_date ) );
		$disp_id     = 'sto-date-d-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $suffix );
		$wrap_id     = 'sto-date-w-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $suffix );
		$data_min    = $min_date !== '' ? $min_date : '';
		$data_max    = $max_date !== '' ? $max_date : '';
		?>
		<div
			class="sto-date-field"
			id="<?php echo esc_attr( $wrap_id ); ?>"
			data-sto-date="1"
			<?php if ( $data_min !== '' ) : ?>
				data-sto-date-min="<?php echo esc_attr( $data_min ); ?>"
			<?php endif; ?>
			<?php if ( $data_max !== '' ) : ?>
				data-sto-date-max="<?php echo esc_attr( $data_max ); ?>"
			<?php endif; ?>
		>
			<div class="sto-input-wrap sto-date-field__shell">
				<div class="sto-date-field__control">
					<span class="sto-date-field__icon" aria-hidden="true"><i class="fa-light fa-calendar-days"></i></span>
					<input
						type="text"
						class="sto-date-field__display"
						id="<?php echo esc_attr( $disp_id ); ?>"
						value=""
						readonly="readonly"
						autocomplete="off"
						<?php if ( $placeholder !== '' ) : ?>
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
						<?php endif; ?>
						aria-label="<?php echo esc_attr__( 'Open calendar', 'simple-theme-options' ); ?>"
					/>
					<button type="button" class="sto-date-field__clear" aria-label="<?php esc_attr_e( 'Clear date', 'simple-theme-options' ); ?>">
						<span class="sto-date-field__clear-x" aria-hidden="true">&times;</span>
					</button>
				</div>
			</div>
			<input
				type="hidden"
				class="sto-date-field__value"
				name="<?php echo esc_attr( $input_name ); ?>"
				value="<?php echo esc_attr( $current_ymd ); ?>"
				<?php echo $html_req ? ' required' : ''; ?>
			/>
		</div>
		<?php
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, $default_ymd, array $breakpoints, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return ResponsiveConfig::coerce_map( null, $breakpoints, $default_ymd );
		}
		$stored = $saved_options[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$out = array();
			foreach ( $breakpoints as $bp ) {
				$bp    = sanitize_key( (string) $bp );
				$cell  = ResponsiveConfig::raw_value_at_breakpoint( $stored, $bp );
				$raw_s = ( null !== $cell && is_scalar( $cell ) ) ? (string) $cell : '';
				$out[ $bp ] = $this->sanitize_ymd_scalar( $raw_s, $field );
			}

			return $out;
		}
		$scalar = is_scalar( $stored ) ? (string) $stored : '';
		$base   = $this->sanitize_ymd_scalar( $scalar, $field );
		if ( $base === '' ) {
			$base = $default_ymd;
		}

		return ResponsiveConfig::coerce_map( $base, $breakpoints, $default_ymd );
	}

	/**
	 * @return string Y-m-d or empty
	 */
	private function get_option_scalar( $field_id, $default_ymd, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return $default_ymd;
		}
		$st = $saved_options[ $field_id ];
		if ( is_array( $st ) && ResponsiveConfig::is_breakpoint_value_map( $st ) ) {
			$slice = ResponsiveConfig::value_for_required_eval( $st );
			$ymd   = $this->sanitize_ymd_scalar( is_scalar( $slice ) ? (string) $slice : '', $field );

			return $ymd !== '' ? $ymd : $default_ymd;
		}
		$ymd = $this->sanitize_ymd_scalar( is_scalar( $st ) ? (string) $st : '', $field );

		return $ymd !== '' ? $ymd : $default_ymd;
	}
}
