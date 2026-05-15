<?php
namespace SimpleThemeOptions\Admin\Options\Fields\DateTimeField;

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
 * Date + time field (jQuery UI Datepicker + native time input). Stored as **`Y-m-d H:i`** (24-hour) or empty; responsive = breakpoint map.
 *
 * Register with **`'type' => 'datetime'`**. Keys: **`section_slug`**, **`id`**, **`title`**, optional **`description`**, **`default`** (`Y-m-d H:i` or `''`),
 * **`placeholder`** (date text), optional **`min_date`** / **`max_date`** (Y-m-d, applied to the date portion), optional **`time_step`** (seconds for **`<input type="time">`**
 * `step`, default **60**), conditional **`required`**, **`html_required`**, **`tooltip`**, **`wrapper_class`**, optional **`responsive`** + **`device`**.
 */
final class DateTimeField {
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
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::DATETIME, 2 );
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

		$bounds = array(
			'min_date' => $this->sanitize_ymd_only( isset( $field['min_date'] ) ? $field['min_date'] : '', array() ),
			'max_date' => $this->sanitize_ymd_only( isset( $field['max_date'] ) ? $field['max_date'] : '', array() ),
		);
		$def    = $this->sanitize_datetime_scalar( isset( $field['default'] ) ? $field['default'] : '', $bounds );

		$field['section_slug']             = $section_slug;
		$field['id']                     = $field_id;
		$field['title']                  = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']            = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class']          = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']               = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']                  = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['placeholder']            = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$field['html_required']          = ! empty( $field['html_required'] );
		$field['default_datetime']       = $def;
		$field['min_date']               = $bounds['min_date'];
		$field['max_date']               = $bounds['max_date'];
		$field['responsive_breakpoints'] = ResponsiveConfig::breakpoints_for_field( $field );

		$step = isset( $field['time_step'] ) ? (int) $field['time_step'] : 60;
		if ( $step < 60 ) {
			$step = 60;
		}
		if ( $step > 86400 ) {
			$step = 86400;
		}
		$field['time_step'] = $step;

		if ( $field['min_date'] !== '' && $field['max_date'] !== '' && strcmp( $field['min_date'], $field['max_date'] ) > 0 ) {
			$tmp               = $field['min_date'];
			$field['min_date'] = $field['max_date'];
			$field['max_date'] = $tmp;
		}

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}
		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]            = $field;
	}

	/**
	 * @param string               $raw
	 * @param array<string, mixed> $bounds Keys min_date, max_date (Y-m-d or '').
	 * @return string Y-m-d or ''
	 */
	private function sanitize_ymd_only( $raw, array $bounds ) {
		$s = is_string( $raw ) ? trim( $raw ) : '';
		if ( $s === '' ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
			return '';
		}
		$y  = (int) $m[1];
		$mo = (int) $m[2];
		$d  = (int) $m[3];
		if ( ! checkdate( $mo, $d, $y ) ) {
			return '';
		}
		$out = sprintf( '%04d-%02d-%02d', $y, $mo, $d );
		$min = isset( $bounds['min_date'] ) ? (string) $bounds['min_date'] : '';
		$max = isset( $bounds['max_date'] ) ? (string) $bounds['max_date'] : '';
		if ( $min !== '' && strcmp( $out, $min ) < 0 ) {
			return $min;
		}
		if ( $max !== '' && strcmp( $out, $max ) > 0 ) {
			return $max;
		}

		return $out;
	}

	/**
	 * @param string               $raw
	 * @param array<string, mixed> $field Or bounds-only array with min_date, max_date.
	 * @return string Empty or Y-m-d H:i
	 */
	public function sanitize_datetime_scalar( $raw, array $field ) {
		$s = is_string( $raw ) ? trim( $raw ) : '';
		if ( $s === '' ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})$/', $s, $m ) ) {
			return '';
		}
		$date = $m[1];
		$hh   = (int) $m[2];
		$mm   = (int) $m[3];
		if ( $hh < 0 || $hh > 23 || $mm < 0 || $mm > 59 ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm ) ) {
			return '';
		}
		if ( ! checkdate( (int) $dm[2], (int) $dm[3], (int) $dm[1] ) ) {
			return '';
		}

		$min = isset( $field['min_date'] ) ? (string) $field['min_date'] : '';
		$max = isset( $field['max_date'] ) ? (string) $field['max_date'] : '';
		if ( $min !== '' && strcmp( $date, $min ) < 0 ) {
			$date = $min;
		}
		if ( $max !== '' && strcmp( $date, $max ) > 0 ) {
			$date = $max;
		}

		return $date . ' ' . sprintf( '%02d:%02d', $hh, $mm );
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
	 * @param mixed $raw Posted string or breakpoint map.
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
				$out[ $bp ] = $this->sanitize_datetime_scalar( is_scalar( $cell ) ? (string) $cell : '', $field );
			}

			return $out;
		}

		return $this->sanitize_datetime_scalar( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ), $field );
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
		$def_dt        = isset( $field['default_datetime'] ) ? (string) $field['default_datetime'] : '';
		$min_d         = isset( $field['min_date'] ) ? (string) $field['min_date'] : '';
		$max_d         = isset( $field['max_date'] ) ? (string) $field['max_date'] : '';
		$time_step     = isset( $field['time_step'] ) ? (int) $field['time_step'] : 60;

		$is_inner = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-datetime' );
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
				$value_map = $this->get_value_map( $field_id, $def_dt, $bps_storage, $field );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : '';
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$suffix     = $field_id . '_' . $tabs_pane_bp;
				$this->render_datetime_control( $suffix, $input_name, $cur, $placeholder, $html_req, $min_d, $max_d, $time_step );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $def_dt, $bps_storage, $field );
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$cur     = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : '';
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$suffix     = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_datetime_control( $suffix, $input_name, $cur, $placeholder, $html_req, $min_d, $max_d, $time_step );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$cur = $this->get_option_scalar( $field_id, $def_dt, $field );
				$this->render_datetime_control( $field_id, 'sto_options[' . $field_id . ']', $cur, $placeholder, $html_req, $min_d, $max_d, $time_step );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string $suffix
	 * @param string $input_name
	 * @param string $current_dt  Y-m-d H:i or empty.
	 * @param string $placeholder
	 * @param bool   $html_req
	 * @param string $min_date
	 * @param string $max_date
	 * @param int    $time_step   Seconds for HTML time step (>= 60).
	 */
	private function render_datetime_control( $suffix, $input_name, $current_dt, $placeholder, $html_req, $min_date, $max_date, $time_step ) {
		$current_dt = $this->sanitize_datetime_scalar(
			(string) $current_dt,
			array(
				'min_date' => $min_date,
				'max_date' => $max_date,
				'time_step' => $time_step,
			)
		);
		$time_val = '00:00';
		if ( $current_dt !== '' && preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2})$/', $current_dt, $tm ) ) {
			$time_val = $tm[2] . ':' . $tm[3];
		}
		$disp_id  = 'sto-dt-d-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $suffix );
		$time_id  = 'sto-dt-t-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $suffix );
		$wrap_id  = 'sto-dt-w-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $suffix );
		$data_min = $min_date !== '' ? $min_date : '';
		$data_max = $max_date !== '' ? $max_date : '';
		$step     = max( 60, (int) $time_step );
		?>
		<div
			class="sto-datetime-field"
			id="<?php echo esc_attr( $wrap_id ); ?>"
			data-sto-datetime="1"
			data-sto-time-step="<?php echo esc_attr( (string) $step ); ?>"
			<?php if ( $data_min !== '' ) : ?>
				data-sto-date-min="<?php echo esc_attr( $data_min ); ?>"
			<?php endif; ?>
			<?php if ( $data_max !== '' ) : ?>
				data-sto-date-max="<?php echo esc_attr( $data_max ); ?>"
			<?php endif; ?>
		>
			<div class="sto-input-wrap sto-datetime-field__shell">
				<div class="sto-datetime-field__control">
					<span class="sto-datetime-field__icon" aria-hidden="true"><i class="fa-light fa-clock"></i></span>
					<input
						type="text"
						class="sto-datetime-field__date"
						id="<?php echo esc_attr( $disp_id ); ?>"
						value=""
						readonly="readonly"
						autocomplete="off"
						<?php if ( $placeholder !== '' ) : ?>
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
						<?php endif; ?>
						aria-label="<?php echo esc_attr__( 'Pick date', 'simple-theme-options' ); ?>"
					/>
					<input
						type="time"
						class="sto-datetime-field__time"
						id="<?php echo esc_attr( $time_id ); ?>"
						step="<?php echo esc_attr( (string) $step ); ?>"
						value="<?php echo esc_attr( $time_val ); ?>"
						aria-label="<?php echo esc_attr__( 'Pick time', 'simple-theme-options' ); ?>"
					/>
					<button type="button" class="sto-datetime-field__clear" aria-label="<?php esc_attr_e( 'Clear date and time', 'simple-theme-options' ); ?>">
						<span class="sto-datetime-field__clear-x" aria-hidden="true">&times;</span>
					</button>
				</div>
			</div>
			<input
				type="hidden"
				class="sto-datetime-field__value"
				name="<?php echo esc_attr( $input_name ); ?>"
				value="<?php echo esc_attr( $current_dt ); ?>"
				<?php echo $html_req ? ' required' : ''; ?>
			/>
		</div>
		<?php
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, $default_dt, array $breakpoints, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return ResponsiveConfig::coerce_map( null, $breakpoints, $default_dt );
		}
		$stored = $saved_options[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$out = array();
			foreach ( $breakpoints as $bp ) {
				$bp    = sanitize_key( (string) $bp );
				$cell  = ResponsiveConfig::raw_value_at_breakpoint( $stored, $bp );
				$raw_s = ( null !== $cell && is_scalar( $cell ) ) ? (string) $cell : '';
				$out[ $bp ] = $this->sanitize_datetime_scalar( $raw_s, $field );
			}

			return $out;
		}
		$scalar = is_scalar( $stored ) ? (string) $stored : '';
		$base   = $this->sanitize_datetime_scalar( $scalar, $field );
		if ( $base === '' ) {
			$base = $default_dt;
		}

		return ResponsiveConfig::coerce_map( $base, $breakpoints, $default_dt );
	}

	/**
	 * @return string Y-m-d H:i or empty
	 */
	private function get_option_scalar( $field_id, $default_dt, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return $default_dt;
		}
		$st = $saved_options[ $field_id ];
		if ( is_array( $st ) && ResponsiveConfig::is_breakpoint_value_map( $st ) ) {
			$slice = ResponsiveConfig::value_for_required_eval( $st );
			$dt    = $this->sanitize_datetime_scalar( is_scalar( $slice ) ? (string) $slice : '', $field );

			return $dt !== '' ? $dt : $default_dt;
		}
		$dt = $this->sanitize_datetime_scalar( is_scalar( $st ) ? (string) $st : '', $field );

		return $dt !== '' ? $dt : $default_dt;
	}
}
