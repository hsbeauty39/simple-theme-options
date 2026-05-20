<?php
namespace SimpleThemeOptions\Admin\Options\Fields\CheckboxControl;

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

final class CheckboxControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const MAX_OPTIONS = 24;

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
		// Same band as Switcher / Select so samples can order via boot sequence.
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::SELECT_BLOCK, 2 );
	}

	/**
	 * Register a stylish checkbox row: **single** (`1` / `0` via hidden input + tile) or **multi** (native
	 * checkboxes with `name="sto_options[id][]"` or per-breakpoint `[]`, visually hidden; tiles sync in JS).
	 *
	 * Keys: **section_slug**, **id**, **title**?, **description**?, **multiple** (bool, default false),
	 * **options** (required when `multiple` is true): value => label string **or** array with **label** (and optional **tooltip**),
	 * **default** — single: `1`|`0`; multi: array or comma string of option keys,
	 * **max** (multi, optional int 0 = unlimited, cap 100), **columns** (multi, optional 1–6 fixed grid columns; 0 = auto-fill),
	 * **labels** (single only): `array( 'on' => '…', 'off' => '…' )` tile captions,
	 * **wrapper_class**?, **required**?, **group**?, **tooltip**?, optional **responsive**, **device** (same as Select).
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

		$multiple = ! empty( $field['multiple'] );
		$raw_opts = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$options  = $multiple ? $this->normalize_options( $raw_opts ) : array();

		if ( $multiple && empty( $options ) ) {
			return;
		}

		$max_sel = isset( $field['max'] ) ? (int) $field['max'] : 0;
		if ( $max_sel < 0 ) {
			$max_sel = 0;
		}
		if ( $max_sel > 100 ) {
			$max_sel = 100;
		}

		$cols = isset( $field['columns'] ) ? (int) $field['columns'] : 0;
		if ( $cols < 0 ) {
			$cols = 0;
		}
		if ( $cols > 6 ) {
			$cols = 6;
		}

		$labels = isset( $field['labels'] ) && is_array( $field['labels'] ) ? $field['labels'] : array();
		$on_l   = isset( $labels['on'] ) ? (string) $labels['on'] : __( 'Enabled', 'topten-simple-theme-options' );
		$off_l  = isset( $labels['off'] ) ? (string) $labels['off'] : __( 'Disabled', 'topten-simple-theme-options' );

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['multiple']      = $multiple;
		$field['options']       = $options;
		$field['max']           = $max_sel;
		$field['columns']       = $cols;
		$field['default']       = $multiple
			? $this->normalize_default_list( $field['default'] ?? array(), $options )
			: $this->sanitize_single_stored( isset( $field['default'] ) ? (string) $field['default'] : '0' );
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
		$this->fields_by_id[ $field_id ]             = $field;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, array{label: string, tooltip: string}>
	 */
	private function normalize_options( $raw ) {
		$out   = array();
		$count = 0;
		foreach ( $raw as $value => $meta ) {
			if ( $count >= self::MAX_OPTIONS ) {
				break;
			}
			$key = sanitize_key( (string) $value );
			if ( $key === '' ) {
				continue;
			}
			if ( is_string( $meta ) ) {
				$out[ $key ] = array(
					'label'   => (string) $meta,
					'tooltip' => '',
				);
				++$count;
				continue;
			}
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$label = isset( $meta['label'] ) ? (string) $meta['label'] : $key;
			$tip   = isset( $meta['tooltip'] ) ? (string) $meta['tooltip'] : '';
			$out[ $key ] = array(
				'label'   => $label,
				'tooltip' => $tip,
			);
			++$count;
		}

		return $out;
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
	 * @param mixed $raw
	 * @return string|array<int, string>|array<string, string|array<int, string>>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return '0';
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
					$out[ $bp ] = $this->sanitize_single_stored( is_scalar( $cell ) ? (string) $cell : '' );
				}
			}

			return $out;
		}

		if ( $multiple ) {
			return $this->sanitize_multiple( $field, $raw );
		}

		return $this->sanitize_single_stored( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) );
	}

	/**
	 * When every multi-checkbox is unchecked, PHP omits the key (or breakpoint cell) from POST.
	 *
	 * @param array<string, mixed>     $posted
	 * @param array<string, mixed>     $sanitized
	 * @param array<string, true>|null $only_field_ids_map
	 */
	public function merge_missing_multiple_checkbox_fields( $posted, array &$sanitized, ?array $only_field_ids_map = null ) {
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
	 * @param string $raw
	 * @return string `1` or `0`
	 */
	public function sanitize_single_stored( $raw ) {
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

		$field_id      = $field['id'];
		$title         = $field['title'];
		$description   = $field['description'];
		$multiple      = ! empty( $field['multiple'] );
		$options       = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$max_sel       = isset( $field['max'] ) ? (int) $field['max'] : 0;
		$columns       = isset( $field['columns'] ) ? (int) $field['columns'] : 0;
		$labels        = isset( $field['labels'] ) && is_array( $field['labels'] ) ? $field['labels'] : array( 'on' => '', 'off' => '' );
		$on_label      = isset( $labels['on'] ) ? (string) $labels['on'] : __( 'Enabled', 'topten-simple-theme-options' );
		$off_label     = isset( $labels['off'] ) ? (string) $labels['off'] : __( 'Disabled', 'topten-simple-theme-options' );
		$default_single = '0';
		if ( ! $multiple && isset( $field['default'] ) ) {
			if ( is_array( $field['default'] ) ) {
				$first = reset( $field['default'] );
				$default_single = is_scalar( $first ) ? $this->sanitize_single_stored( (string) $first ) : '0';
			} else {
				$default_single = $this->sanitize_single_stored( (string) $field['default'] );
			}
		}
		$default_list   = $multiple && isset( $field['default'] ) && is_array( $field['default'] ) ? $field['default'] : array();
		$wrapper_class  = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required       = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json  = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$tooltip        = FieldTitle::get_tooltip_config( $field );
		$bps_storage    = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp   = ResponsiveConfig::parent_responsive_pane_bp( $field );

		$is_group_inner = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-checkbox' );
		if ( $multiple ) {
			$row_classes[] = 'sto-field-row-checkbox--multiple';
		} else {
			$row_classes[] = 'sto-field-row-checkbox--single';
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

		$data_cols = $columns > 0 ? (string) (int) $columns : '0';
		?>
		<div
			id="<?php echo esc_attr( 'sto-field-' . $field_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute string from FieldSpacing::row_margin_style_attr().
			echo FieldSpacing::row_margin_style_attr( $field, $context );
			?>
			data-sto-field-id="<?php echo esc_attr( $field_id ); ?>"
			data-sto-checkbox-control="1"
			data-sto-checkbox-mode="<?php echo esc_attr( $multiple ? 'multi' : 'single' ); ?>"
			<?php if ( $multiple && $max_sel > 0 ) : ?>
				data-sto-checkbox-max="<?php echo (int) $max_sel; ?>"
			<?php endif; ?>
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

			<?php if ( ! $multiple ) : ?>
				<?php
				if ( $tabs_pane_bp !== '' && $bps_storage ) {
					$value_map = $this->get_value_map_single( $field_id, $default_single, $bps_storage );
					$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $default_single;
					$suffix    = $field_id . '_' . $tabs_pane_bp;
					$name      = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
					$this->render_single_tile( $suffix, $name, $cur, $title, $on_label, $off_label );
				} elseif ( ! empty( $bps_storage ) ) {
					echo '<div class="sto-responsive">';
					ResponsiveControl::render_panes_open();
					$value_map = $this->get_value_map_single( $field_id, $default_single, $bps_storage );
					foreach ( $bps_storage as $i => $bp ) {
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$cur     = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $default_single;
						$suffix  = $field_id . '_' . $bp;
						$name    = 'sto_options[' . $field_id . '][' . $bp . ']';
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_single_tile( $suffix, $name, $cur, $title, $on_label, $off_label );
						ResponsiveControl::render_pane_end();
					}
					ResponsiveControl::render_panes_close();
					echo '</div>';
				} else {
					$cur = $this->get_option_scalar_single( $field_id, $default_single );
					$this->render_single_tile( $field_id, 'sto_options[' . $field_id . ']', $cur, $title, $on_label, $off_label );
				}
				?>
			<?php else : ?>
				<?php
				if ( $tabs_pane_bp !== '' && $bps_storage ) {
					$cur_list = $this->get_option_list( $field_id, $default_list, $options, $tabs_pane_bp );
					$name     = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . '][]';
					$this->render_multi_stack( $field_id, $options, $cur_list, $name, $tabs_pane_bp, (int) $columns );
				} elseif ( ! empty( $bps_storage ) ) {
					echo '<div class="sto-responsive">';
					ResponsiveControl::render_panes_open();
					foreach ( $bps_storage as $i => $bp ) {
						$bp       = sanitize_key( (string) $bp );
						$visible  = ( 0 === (int) $i );
						$cur_list = $this->get_option_list( $field_id, $default_list, $options, $bp );
						$name     = 'sto_options[' . $field_id . '][' . $bp . '][]';
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_multi_stack( $field_id, $options, $cur_list, $name, $bp, (int) $columns );
						ResponsiveControl::render_pane_end();
					}
					ResponsiveControl::render_panes_close();
					echo '</div>';
				} else {
					$cur_list = $this->get_option_list( $field_id, $default_list, $options, null );
					$name     = 'sto_options[' . $field_id . '][]';
					$this->render_multi_stack( $field_id, $options, $cur_list, $name, '', (int) $columns );
				}
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string $suffix       Id suffix (field id or field_bp).
	 * @param string $name        Full `name` attribute (without brackets for sto_options — pass full e.g. sto_options[x] or sto_options[x][bp]).
	 * @param string $current     `1`|`0`
	 * @param string $row_title   Row heading (fallback aria).
	 * @param string $on_label
	 * @param string $off_label
	 */
	private function render_single_tile( $suffix, $name, $current, $row_title, $on_label, $off_label ) {
		$is_on   = ( $current === '1' );
		$input_id = 'sto-checkbox-input-' . $suffix;
		$label_now = $is_on ? $on_label : $off_label;
		?>
		<div class="sto-checkbox-single">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $input_id ); ?>" value="<?php echo esc_attr( $current ); ?>" />
			<button
				type="button"
				class="sto-checkbox-tile sto-checkbox-tile--single<?php echo $is_on ? ' sto-checkbox-tile--checked' : ''; ?>"
				data-sto-checkbox-single
				data-sto-checkbox-for="<?php echo esc_attr( $input_id ); ?>"
				data-sto-label-on="<?php echo esc_attr( $on_label ); ?>"
				data-sto-label-off="<?php echo esc_attr( $off_label ); ?>"
				role="checkbox"
				aria-checked="<?php echo $is_on ? 'true' : 'false'; ?>"
				aria-label="<?php echo esc_attr( $row_title ? $row_title : $suffix ); ?>"
			>
				<span class="sto-checkbox-tile__box" aria-hidden="true">
					<svg class="sto-checkbox-tile__mark" width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M2.5 7.5L5.5 10.5L11.5 3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
				<span class="sto-checkbox-tile__caption"><?php echo esc_html( $label_now ); ?></span>
			</button>
		</div>
		<?php
	}

	/**
	 * @param array<string, array{label: string, tooltip: string}> $options
	 * @param array<int, string>                                   $selected
	 * @param string                                               $name_attr Full name including [] for multi.
	 * @param string                                               $bp_suffix Breakpoint key or empty.
	 * @param int                                                  $columns   0 = auto grid.
	 */
	private function render_multi_stack( $field_id, array $options, array $selected, $name_attr, $bp_suffix, $columns ) {
		$cols_attr = $columns > 0 ? (string) $columns : '0';
		?>
		<div class="sto-checkbox-stack" data-sto-checkbox-cols="<?php echo esc_attr( $cols_attr ); ?>">
			<?php
			foreach ( $options as $opt_key => $meta ) :
				$opt_key = (string) $opt_key;
				$label   = isset( $meta['label'] ) ? (string) $meta['label'] : $opt_key;
				$tip     = isset( $meta['tooltip'] ) ? trim( (string) $meta['tooltip'] ) : '';
				$checked = in_array( $opt_key, $selected, true );
				$id_part = $field_id . '_' . $opt_key . ( $bp_suffix !== '' ? '_' . $bp_suffix : '' );
				$cid     = 'sto-checkbox-native-' . $id_part;
				?>
				<div class="sto-checkbox-stack__item">
					<input
						type="checkbox"
						class="screen-reader-text sto-checkbox-native"
						name="<?php echo esc_attr( $name_attr ); ?>"
						id="<?php echo esc_attr( $cid ); ?>"
						value="<?php echo esc_attr( $opt_key ); ?>"
						<?php checked( $checked ); ?>
					/>
					<button
						type="button"
						class="sto-checkbox-tile sto-checkbox-tile--multi<?php echo $checked ? ' sto-checkbox-tile--checked' : ''; ?>"
						data-sto-checkbox-multi
						data-sto-checkbox-native="<?php echo esc_attr( $cid ); ?>"
						role="checkbox"
						aria-checked="<?php echo $checked ? 'true' : 'false'; ?>"
						<?php if ( $tip !== '' ) : ?>
							title="<?php echo esc_attr( $tip ); ?>"
						<?php endif; ?>
					>
						<span class="sto-checkbox-tile__box" aria-hidden="true">
							<svg class="sto-checkbox-tile__mark" width="12" height="12" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M2.5 7.5L5.5 10.5L11.5 3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</span>
						<span class="sto-checkbox-tile__caption"><?php echo esc_html( $label ); ?></span>
					</button>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @return array<string, string>
	 */
	private function get_value_map_single( $field_id, $default, array $breakpoints ) {
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
	private function get_option_scalar_single( $field_id, $default ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return $default;
		}
		$v = $saved_options[ $field_id ];
		if ( is_array( $v ) && ResponsiveConfig::is_breakpoint_value_map( $v ) ) {
			return $this->sanitize_single_stored( ResponsiveConfig::value_for_required_eval( $v ) );
		}

		return $this->sanitize_single_stored( (string) $v );
	}

	/**
	 * @param string                    $field_id
	 * @param array<int, string>|string $default_list
	 * @param array<string, mixed>      $options
	 * @param string|null               $bp_context
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
}
