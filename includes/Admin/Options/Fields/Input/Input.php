<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Input;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSpacing;

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

final class Input {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	public const INPUT_TYPES = array( 'text', 'number', 'textarea', 'editor', 'email', 'phone', 'search', 'password' );

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $fields_by_section = array();

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private $fields_by_id = array();

	public const MCE_TOOLBAR_SLOT = 'sto_input_toolbar_slot';

	/**
	 * Field id => toolbar_end config (editor fields only). Populated at register().
	 *
	 * @var array<string, array{label: string, tooltip: string, snippet: string}>
	 */
	private static $mce_toolbar_end_registry = array();

	/**
	 * @var bool
	 */
	private static $editor_assets_enqueued = false;

	protected function init() {
		// After Color (19), before Typography (20).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::INPUT, 2 );
		add_filter( 'mce_buttons', array( $this, 'filter_mce_buttons_append_toolbar_end' ), 99, 2 );
		add_filter( 'tiny_mce_before_init', array( $this, 'filter_tiny_mce_before_init_toolbar_end' ), 20, 2 );
		add_filter( 'tiny_mce_before_init', array( $this, 'filter_tinymce_preserve_html_for_repeater_editor' ), 25, 2 );
	}

	/**
	 * Single-line and rich-text inputs stored in `sto_options[id]` (string).
	 *
	 * Keys:
	 * - section_slug, id (required)
	 * - input_type: one of text|number|textarea|editor|email|phone|search|password — or set `type` to that value in groups
	 * - title?, description?, default? (string), placeholder? (all types; editor applies to underlying textarea),
	 *   class?, wrapper_class?, required? (conditional visibility JSON), tooltip?, group?
	 * - html_required? (bool, default false) — HTML5 required on the control (separate from conditional `required`).
	 * - number: optional min, max, step (numeric strings; empty min/max = no bound)
	 * - password: HTML **type="password"** with optional **show/hide** toggle; stored with **`sanitize_text_field`** (same as **text** — not encrypted at rest)
	 * - textarea: optional rows (int, default 5), cols (int, default 60)
	 * - editor: optional editor_height (int px, default **160**, min **100**), media_buttons (bool, default true), teeny (bool, default false),
	 *   drag_drop_upload (bool, default true), optional **toolbar_end** => array( **label** (short text), **tooltip**, **snippet** (HTML inserted on click) ) — appends a control after the kitchen-sink button on row 1
	 * - optional **responsive** (`true` or non-empty array) — per-breakpoint storage; optional **`device`** => list of slugs limits tabs (see `ResponsiveConfig`).
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
		$input_type   = $this->resolve_input_type( $field );

		if ( ! $section_slug || ! $field_id || $input_type === '' ) {
			return;
		}

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['input_type']    = $input_type;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['placeholder']   = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$field['class']         = isset( $field['class'] ) ? (string) $field['class'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';

		$field['default'] = isset( $field['default'] ) ? (string) $field['default'] : '';

		$field['min']   = $this->optional_numeric_string( $field['min'] ?? null );
		$field['max']   = $this->optional_numeric_string( $field['max'] ?? null );
		$field['step']  = $this->optional_numeric_string( $field['step'] ?? null );

		$rows = isset( $field['rows'] ) ? (int) $field['rows'] : 5;
		if ( $rows < 2 ) {
			$rows = 2;
		}
		if ( $rows > 40 ) {
			$rows = 40;
		}
		$field['rows'] = $rows;

		$cols = isset( $field['cols'] ) ? (int) $field['cols'] : 60;
		if ( $cols < 20 ) {
			$cols = 20;
		}
		if ( $cols > 200 ) {
			$cols = 200;
		}
		$field['cols'] = $cols;

		$h = isset( $field['editor_height'] ) ? (int) $field['editor_height'] : 160;
		if ( $h < 100 ) {
			$h = 100;
		}
		if ( $h > 800 ) {
			$h = 800;
		}
		$field['editor_height'] = $h;

		$field['media_buttons'] = ! isset( $field['media_buttons'] ) || ! empty( $field['media_buttons'] );
		$field['teeny']         = ! empty( $field['teeny'] );

		$maxlen = isset( $field['maxlength'] ) ? (int) $field['maxlength'] : 0;
		if ( $maxlen < 0 ) {
			$maxlen = 0;
		}
		$field['maxlength'] = $maxlen;

		$field['readonly'] = ! empty( $field['readonly'] );

		$field['html_required'] = ! empty( $field['html_required'] );

		$field['drag_drop_upload'] = ! isset( $field['drag_drop_upload'] ) || ! empty( $field['drag_drop_upload'] );

		$field['toolbar_end'] = null;
		if ( $input_type === 'editor' && isset( $field['toolbar_end'] ) && is_array( $field['toolbar_end'] ) ) {
			$tb   = $field['toolbar_end'];
			$lbl  = isset( $tb['label'] ) ? trim( (string) $tb['label'] ) : '';
			$tip  = isset( $tb['tooltip'] ) ? (string) $tb['tooltip'] : '';
			$snip = isset( $tb['snippet'] ) ? (string) $tb['snippet'] : '';
			if ( $lbl !== '' || $snip !== '' || $tip !== '' ) {
				if ( $lbl === '' ) {
					$lbl = '+';
				}
				$field['toolbar_end'] = array(
					'label'   => $lbl,
					'tooltip' => $tip,
					'snippet' => $snip,
				);
				self::$mce_toolbar_end_registry[ $field_id ] = $field['toolbar_end'];
			}
		}

		if ( $input_type === 'editor' && ! empty( $field['teeny'] ) && isset( self::$mce_toolbar_end_registry[ $field_id ] ) ) {
			unset( self::$mce_toolbar_end_registry[ $field_id ] );
			$field['toolbar_end'] = null;
		}

		$bps = ResponsiveConfig::breakpoints_for_field( $field );
		if ( $bps && $input_type === 'editor' ) {
			$bps = null;
		}
		$field['responsive_breakpoints'] = $bps;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->fields_by_id[ $field_id ]            = $field;
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
	 * Server-side checks for fields marked **`html_required`**: only when conditional **`required`**
	 * rules say the row would be visible (same semantics as admin JS `applyRequiredVisibility`).
	 *
	 * @param string               $section_slug    Leaf section being saved.
	 * @param array<string, mixed> $option_values   Full merged `sto_options` preview (all keys) so dependencies on other sections resolve.
	 * @return array<int, string> User-facing messages; empty = OK.
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
				__( '“%s” must be filled in before this section can be saved.', 'topten-simple-theme-options' ),
				$label
			);
		}

		return $messages;
	}

	/**
	 * @param array<string, mixed> $field Registered field row.
	 * @param mixed                $raw   Stored / preview value for this id.
	 */
	private function is_html_required_value_empty( array $field, $raw ) {
		if ( is_array( $raw ) && ResponsiveConfig::is_breakpoint_value_map( $raw ) ) {
			$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : array();
			foreach ( $bps as $bp ) {
				$bp   = sanitize_key( (string) $bp );
				$cell = array_key_exists( $bp, $raw ) ? $raw[ $bp ] : '';
				if ( $this->is_html_required_scalar_empty( $field, $cell ) ) {
					return true;
				}
			}

			return false;
		}

		return $this->is_html_required_scalar_empty( $field, $raw );
	}

	/**
	 * @param array<string, mixed> $field
	 * @param mixed                $raw
	 */
	private function is_html_required_scalar_empty( array $field, $raw ) {
		$type = isset( $field['input_type'] ) ? (string) $field['input_type'] : 'text';

		if ( $type === 'number' ) {
			if ( null === $raw || $raw === '' ) {
				return true;
			}

			return trim( (string) $raw ) === '';
		}

		if ( $type === 'editor' ) {
			$s     = is_string( $raw ) ? $raw : '';
			$plain = trim( str_replace( "\xc2\xa0", ' ', wp_strip_all_tags( $s ) ) );

			return $plain === '';
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
	 * Wrapper class for the input cell (password adds show/hide toggle layout).
	 */
	private function input_wrap_classes( string $input_type ): string {
		$classes = 'sto-input-wrap';
		if ( $input_type === 'password' ) {
			$classes .= ' sto-input-wrap--password';
		}

		return $classes;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function resolve_input_type( $field ) {
		if ( isset( $field['input_type'] ) ) {
			$t = sanitize_key( (string) $field['input_type'] );
			if ( in_array( $t, self::INPUT_TYPES, true ) ) {
				return $t;
			}
		}

		if ( isset( $field['type'] ) ) {
			$t = sanitize_key( (string) $field['type'] );
			if ( $t === 'input' && isset( $field['input_type'] ) ) {
				$it = sanitize_key( (string) $field['input_type'] );

				return in_array( $it, self::INPUT_TYPES, true ) ? $it : '';
			}
			if ( in_array( $t, self::INPUT_TYPES, true ) ) {
				return $t;
			}
		}

		return '';
	}

	/**
	 * @param mixed $value
	 * @return string|null null = omit attribute
	 */
	private function optional_numeric_string( $value ) {
		if ( $value === null || $value === '' ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			return (string) ( 0 + $value );
		}

		return null;
	}

	public function registry_is_registered_field_id( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );

		return $field_id && isset( $this->fields_by_id[ $field_id ] );
	}

	/**
	 * @param string $field_id
	 * @param mixed  $raw
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
				$out[ $bp ] = $this->sanitize_scalar_string( $field, is_scalar( $cell ) ? (string) $cell : '' );
			}

			return $out;
		}

		if ( is_array( $raw ) ) {
			return isset( $field['default'] ) ? (string) $field['default'] : '';
		}

		$str = is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' );

		return $this->sanitize_scalar_string( $field, $str );
	}

	/**
	 * @param string $field_id
	 * @param mixed  $raw
	 * @return string|array<string, string>
	 */
	public function sanitize_for_field( $field_id, $raw ) {
		return $this->registry_sanitize_posted_value( $field_id, $raw );
	}

	/**
	 * @param array<string, mixed> $field
	 * @param string               $str
	 * @return string
	 */
	private function sanitize_scalar_string( array $field, $str ) {
		$type    = isset( $field['input_type'] ) ? (string) $field['input_type'] : 'text';
		$default = isset( $field['default'] ) ? (string) $field['default'] : '';
		$str     = is_string( $str ) ? $str : '';

		switch ( $type ) {
			case 'number':
				return $this->sanitize_number_value( $str, $field, $default );

			case 'textarea':
				return sanitize_textarea_field( $str );

			case 'editor':
				return $this->sanitize_editor_html( $str );

			case 'email':
				$e = sanitize_email( $str );

				return $e ? $e : '';

			case 'phone':
				return $this->sanitize_phone_value( $str );

			case 'search':
			case 'password':
			case 'text':
			default:
				return sanitize_text_field( $str );
		}
	}

	/**
	 * @param string               $str
	 * @param array<string, mixed> $field
	 * @param string               $default
	 */
	private function sanitize_number_value( $str, $field, $default ) {
		$str = trim( (string) $str );
		if ( $str === '' ) {
			return '';
		}

		if ( ! is_numeric( $str ) ) {
			return $default;
		}

		$num = 0 + $str;
		$min = isset( $field['min'] ) && $field['min'] !== null && $field['min'] !== '' ? (float) $field['min'] : null;
		$max = isset( $field['max'] ) && $field['max'] !== null && $field['max'] !== '' ? (float) $field['max'] : null;

		if ( $min !== null && $num < $min ) {
			$num = $min;
		}
		if ( $max !== null && $num > $max ) {
			$num = $max;
		}

		$step = isset( $field['step'] ) && $field['step'] !== null && $field['step'] !== '' ? (float) $field['step'] : null;
		if ( $step !== null && $step > 0 ) {
			if ( $min !== null ) {
				$steps = (int) round( ( $num - $min ) / $step );
				$num   = $min + $steps * $step;
			} else {
				$num = round( $num / $step ) * $step;
			}
			if ( $max !== null && $num > $max ) {
				$num = $max;
			}
			if ( $min !== null && $num < $min ) {
				$num = $min;
			}
		}

		if ( floor( $num ) == $num && ( ! isset( $field['step'] ) || (float) $field['step'] === 1.0 || $field['step'] === '1' ) ) {
			return (string) (int) $num;
		}

		return rtrim( rtrim( sprintf( '%.10F', $num ), '0' ), '.' );
	}

	/**
	 * @param string $str
	 * @return string
	 */
	private function sanitize_phone_value( $str ) {
		$str = trim( (string) $str );
		if ( $str === '' ) {
			return '';
		}
		$clean = preg_replace( '/[^0-9+\-().\s]/', '', $str );

		return is_string( $clean ) ? $clean : '';
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
		$placeholder    = $field['placeholder'];
		$default_value  = $field['default'];
		$wrapper_class  = $field['wrapper_class'];
		$input_class    = $field['class'];
		$required       = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$input_type     = isset( $field['input_type'] ) ? (string) $field['input_type'] : 'text';
		$required_json  = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$is_group_inner = ( 'group_inner' === $context );
		$tooltip      = FieldTitle::get_tooltip_config( $field );
		$bps_storage  = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp = ResponsiveConfig::parent_responsive_pane_bp( $field );

		$row_classes = array( 'sto-field-row', 'sto-field-row-input', 'sto-field-row-input--' . sanitize_html_class( $input_type ) );
		if ( $input_type === 'editor' ) {
			$row_classes[] = 'sto-classic-editor-field';
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
			data-sto-input-type="<?php echo esc_attr( $input_type ); ?>"
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
				$value_map = $this->get_value_map_input( $field_id, $default_value, $bps_storage );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $default_value;
				$name      = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$dom_id    = $field_id . '_' . $tabs_pane_bp;
				?>
				<div class="<?php echo esc_attr( $this->input_wrap_classes( $input_type ) ); ?>">
					<?php
					if ( $input_type === 'editor' ) {
						$this->render_wp_editor( $field, $name, $cur );
					} elseif ( $input_type === 'textarea' ) {
						$this->render_textarea( $field, $name, $cur, $input_class, $placeholder, $dom_id );
					} else {
						$this->render_single_line_input( $field, $name, $cur, $input_type, $input_class, $placeholder, $dom_id );
					}
					?>
				</div>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map_input( $field_id, $default_value, $bps_storage );
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$cur     = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $default_value;
						$name    = 'sto_options[' . $field_id . '][' . $bp . ']';
						$dom_id  = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						?>
						<div class="<?php echo esc_attr( $this->input_wrap_classes( $input_type ) ); ?>">
							<?php
							if ( $input_type === 'textarea' ) {
								$this->render_textarea( $field, $name, $cur, $input_class, $placeholder, $dom_id );
							} else {
								$this->render_single_line_input( $field, $name, $cur, $input_type, $input_class, $placeholder, $dom_id );
							}
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
				$current_value = $this->get_option_value( $field_id, $default_value, $input_type );
				$name          = 'sto_options[' . $field_id . ']';
				?>
				<div class="<?php echo esc_attr( $this->input_wrap_classes( $input_type ) ); ?>">
					<?php
					if ( $input_type === 'editor' ) {
						$this->render_wp_editor( $field, $name, $current_value );
					} elseif ( $input_type === 'textarea' ) {
						$this->render_textarea( $field, $name, $current_value, $input_class, $placeholder );
					} else {
						$this->render_single_line_input( $field, $name, $current_value, $input_type, $input_class, $placeholder );
					}
					?>
				</div>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Classic editor for embedded contexts (e.g. advanced repeater rows).
	 *
	 * @param array<string, mixed> $field          Field config (`editor_height`, `media_buttons`, `teeny`, …).
	 * @param string               $textarea_name  `name` on the underlying textarea.
	 * @param string               $value          Stored HTML.
	 * @param string               $editor_dom_id  Unique DOM id for the editor instance (no `sto_wp_editor_` prefix).
	 */
	public function render_embedded_wp_editor( array $field, string $textarea_name, string $value, string $editor_dom_id ): void {
		$field_embed                  = $field;
		$field_embed['editor_dom_id'] = preg_replace( '/[^a-zA-Z0-9_-]/', '_', $editor_dom_id );
		$field_embed['input_type']    = 'editor';
		$field_embed['editor_preserve_html'] = true;
		if ( ! array_key_exists( 'media_buttons', $field_embed ) ) {
			$field_embed['media_buttons'] = true;
		}
		if ( ! array_key_exists( 'teeny', $field_embed ) ) {
			$field_embed['teeny'] = false;
		}
		$value = self::repair_editor_rn_corruption( $value );
		$value = self::normalize_editor_html_for_visual( $value );
		$this->render_wp_editor( $field_embed, $textarea_name, $value );
	}

	/**
	 * Sanitize HTML from a classic `wp_editor` leaf.
	 *
	 * @param string $raw               Markup (already unslashed from json_decode or leaf bucket; never wp_unslash twice).
	 * @param bool   $repair_rn_legacy  Repair literal `rn` / `rnrn` from older double-unslash saves.
	 */
	public function sanitize_editor_html( string $raw, bool $repair_rn_legacy = true ): string {
		$raw = $repair_rn_legacy ? self::repair_editor_rn_corruption( $raw ) : $raw;
		$raw = self::normalize_editor_html_for_visual( $raw );

		if ( current_user_can( 'unfiltered_html' ) ) {
			return (string) $raw;
		}

		return (string) wp_kses( $raw, self::get_classic_editor_allowed_html() );
	}

	/**
	 * Allowed HTML for classic `wp_editor` fields (tables, rowspan/colspan, class, etc.).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function get_classic_editor_allowed_html(): array {
		static $cached = null;

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$allowed     = wp_kses_allowed_html( 'post' );
		$extra_attrs = array(
			'class'       => true,
			'id'          => true,
			'role'        => true,
			'aria-label'  => true,
			'aria-hidden' => true,
			'data-*'      => true,
		);

		foreach ( $allowed as $tag => $attrs ) {
			if ( ! is_array( $attrs ) ) {
				continue;
			}
			$allowed[ $tag ] = array_merge( $attrs, $extra_attrs );
		}

		$extra_tags = array(
			'span'   => $extra_attrs,
			'section' => $extra_attrs,
			'article' => $extra_attrs,
			'header' => $extra_attrs,
			'footer' => $extra_attrs,
			'main'   => array_merge( $allowed['main'] ?? array(), $extra_attrs ),
			'colgroup' => array(
				'span'  => true,
				'class' => true,
				'id'    => true,
			),
			'col'    => array(
				'span'  => true,
				'class' => true,
				'id'    => true,
				'width' => true,
			),
		);

		foreach ( $extra_tags as $tag => $attrs ) {
			if ( isset( $allowed[ $tag ] ) && is_array( $allowed[ $tag ] ) ) {
				$allowed[ $tag ] = array_merge( $allowed[ $tag ], $attrs );
			} else {
				$allowed[ $tag ] = $attrs;
			}
		}

		/**
		 * Filter classic editor KSES allowlist (advanced repeater + Theme Settings `editor` fields).
		 *
		 * @param array<string, array<string, bool>> $allowed Tag => attribute allowlist.
		 */
		$cached = apply_filters( 'sto_classic_editor_kses_allowed_html', $allowed ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.

		return is_array( $cached ) ? $cached : $allowed;
	}

	/**
	 * Fix table rows without `<td>`/`<th>` so TinyMCE Visual mode keeps structure (not plain concatenated text).
	 */
	public static function normalize_editor_html_for_visual( string $html ): string {
		if ( $html === '' || stripos( $html, '<table' ) === false ) {
			return $html;
		}

		$normalized = (string) preg_replace_callback(
			'/<tr\b([^>]*)>(.*?)<\/tr>/is',
			static function ( array $match ): string {
				$attrs = $match[1];
				$inner = $match[2];
				if ( preg_match( '/<t[dh]\b/i', $inner ) ) {
					return $match[0];
				}
				$inner = trim( $inner );
				if ( $inner === '' ) {
					return '<tr' . $attrs . '></tr>';
				}

				return '<tr' . $attrs . '><td>' . $inner . '</td></tr>';
			},
			$html
		);

		return $normalized;
	}

	/**
	 * Repeater row editors use ids like `bbd_ptab_custom_tabs_0_tab_content` (not `sto_wp_editor_*`).
	 *
	 * @param array<string, mixed> $init      TinyMCE init.
	 * @param string               $editor_id Editor DOM id.
	 * @return array<string, mixed>
	 */
	public function filter_tinymce_preserve_html_for_repeater_editor( $init, $editor_id ) {
		if ( ! is_array( $init ) || ! is_string( $editor_id ) || $editor_id === '' ) {
			return is_array( $init ) ? $init : array();
		}
		if ( strpos( $editor_id, 'sto_wp_editor_' ) === 0 ) {
			return $init;
		}
		if ( ! preg_match( '/_\d+_[a-z0-9_]+$/i', $editor_id ) ) {
			return $init;
		}

		// Do not add TinyMCE `table` — it is not bundled in WordPress core and breaks the editor when missing.
		return array_merge(
			$init,
			array(
				'verify_html'       => false,
				'cleanup'           => false,
				'remove_linebreaks' => false,
				'convert_urls'      => false,
				'entity_encoding'   => 'raw',
				'valid_children'    => '+table[thead|tbody|tfoot|caption|colgroup|col|tr],+thead[tr],+tbody[tr],+tfoot[tr],+tr[td|th]',
			)
		);
	}

	/**
	 * Fix literal "rn" / "rnrn" where "\\r\\n" lost backslashes (extra wp_unslash on editor HTML).
	 */
	/**
	 * Whether classic editor HTML has visible text or embedded media (img-only rows must still save).
	 *
	 * @param string $html Stored or posted editor markup.
	 */
	public static function classic_editor_html_has_meaningful_content( string $html ): bool {
		$html = trim( $html );
		if ( $html === '' ) {
			return false;
		}

		if ( preg_match( '/<(img|picture|video|audio|iframe|embed|object|figure|svg)\b/i', $html ) ) {
			return true;
		}

		return trim( wp_strip_all_tags( $html ) ) !== '';
	}

	public static function repair_editor_rn_corruption( string $markup ): string {
		if ( $markup === '' || str_contains( $markup, '<!-- wp:' ) ) {
			return $markup;
		}

		if ( ! preg_match( '/rn/i', $markup ) ) {
			return $markup;
		}

		// Blocks that are only corrupted newline tokens.
		$markup = (string) preg_replace( '/<p>\s*(?:rn\s*)+<\/p>/iu', '', $markup );
		$markup = (string) preg_replace( '/<div>\s*(?:rn\s*)+<\/div>/iu', '', $markup );

		while ( str_contains( $markup, 'rnrn' ) ) {
			$markup = str_replace( 'rnrn', "\n\n", $markup );
		}

		$markup = (string) preg_replace( '/,rn(?=[a-zA-Z<])/u', ",\n", $markup );
		$markup = (string) preg_replace( '/rn(?=[a-zA-Z<])/u', "\n", $markup );
		$markup = (string) preg_replace( '/rn(?=\s*<\/?)/u', "\n", $markup );
		$markup = (string) preg_replace( '/rn(?=\s*$)/u', "\n", $markup );
		$markup = (string) preg_replace( '/(?:^|>|\s)(?:rn\s*){2,}(?=<|\s|$)/iu', "\n\n", $markup );

		return is_string( $markup ) ? $markup : '';
	}

	/**
	 * @param array<string, mixed> $field
	 * @param string               $name
	 * @param string               $value
	 */
	private function render_wp_editor( $field, $name, $value ) {
		$this->enqueue_editor_assets_once();

		$field_id = (string) ( $field['id'] ?? '' );
		$editor_id = isset( $field['editor_dom_id'] ) && (string) $field['editor_dom_id'] !== ''
			? (string) $field['editor_dom_id']
			: 'sto_wp_editor_' . $field_id;
		$height    = isset( $field['editor_height'] ) ? (int) $field['editor_height'] : 160;

		// Match core post editor: full TinyMCE + Quicktags (Visual / Code), Add Media, kitchen sink.
		// Use top-level editor_height so _WP_Editors::parse_settings() applies pixel height (not teeny).
		// Keep textarea_rows modest when height is small (rows are fallback when editor_height absent).
		$textarea_rows = max( 4, min( 20, (int) round( $height / 28 ) ) );

		$settings = array(
			'textarea_name'    => $name,
			'media_buttons'    => ! empty( $field['media_buttons'] ),
			'teeny'            => ! empty( $field['teeny'] ),
			'textarea_rows'    => $textarea_rows,
			'editor_height'    => $height,
			'drag_drop_upload' => ! empty( $field['drag_drop_upload'] ),
			'wpautop'          => ! empty( $field['editor_preserve_html'] ) ? false : true,
			'tinymce'          => array(
				'resize'             => 'vertical',
				'height'             => $height,
				'min_height'         => max( 50, (int) floor( $height * 0.45 ) ),
				'wp_autoresize_on'   => false,
				'add_unload_trigger' => false,
			),
			'quicktags'        => true,
		);

		if ( ! empty( $field['editor_preserve_html'] ) ) {
			$settings['tinymce'] = array_merge(
				$settings['tinymce'],
				array(
					'verify_html'       => false,
					'cleanup'           => false,
					'remove_linebreaks' => false,
					'convert_urls'      => false,
					'entity_encoding'   => 'raw',
				)
			);
		}

		$html_required = ! empty( $field['html_required'] );
		$placeholder    = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';

		$textarea_attrs = function ( $html ) use ( $html_required, $placeholder ) {
			$inject = '';
			if ( $html_required ) {
				$inject .= ' required="required"';
			}
			if ( $placeholder !== '' ) {
				$inject .= ' placeholder="' . esc_attr( $placeholder ) . '"';
			}
			if ( $inject === '' ) {
				return $html;
			}

			return (string) preg_replace( '/<textarea\b/', '<textarea' . $inject, $html, 1 );
		};

		add_filter( 'the_editor', $textarea_attrs, 10, 1 );
		wp_editor( $value, $editor_id, $settings );
		remove_filter( 'the_editor', $textarea_attrs, 10 );
	}

	private function enqueue_editor_assets_once() {
		if ( self::$editor_assets_enqueued ) {
			return;
		}
		self::$editor_assets_enqueued = true;

		if ( function_exists( 'wp_enqueue_editor' ) ) {
			wp_enqueue_editor();
		}
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Theme Settings screen only (matches Assets screen gate).
	 */
	private function is_theme_settings_screen() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->id ) && is_string( $screen->id ) && strpos( $screen->id, 'theme-settings' ) !== false ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return $page === 'theme-settings';
	}

	/**
	 * @param array<int, string> $buttons
	 * @param string              $editor_id
	 * @return array<int, string>
	 */
	public function filter_mce_buttons_append_toolbar_end( $buttons, $editor_id ) {
		if ( ! $this->is_theme_settings_screen() ) {
			return $buttons;
		}

		$editor_id = (string) $editor_id;
		if ( strpos( $editor_id, 'sto_wp_editor_' ) !== 0 ) {
			return $buttons;
		}

		$field_id = substr( $editor_id, strlen( 'sto_wp_editor_' ) );
		if ( $field_id === '' || empty( self::$mce_toolbar_end_registry[ $field_id ] ) ) {
			return $buttons;
		}

		if ( in_array( self::MCE_TOOLBAR_SLOT, $buttons, true ) ) {
			return $buttons;
		}

		$buttons[] = self::MCE_TOOLBAR_SLOT;

		return $buttons;
	}

	/**
	 * Register the toolbar-end control via TinyMCE `setup` (no external plugin file).
	 * External `mce_external_plugins` is unreliable for optional buttons on some setups.
	 *
	 * @param array<string, mixed> $mce_init
	 * @param string               $editor_id
	 * @return array<string, mixed>
	 */
	public function filter_tiny_mce_before_init_toolbar_end( $mce_init, $editor_id ) {
		if ( ! is_array( $mce_init ) || ! $this->is_theme_settings_screen() ) {
			return $mce_init;
		}

		$editor_id = (string) $editor_id;
		if ( strpos( $editor_id, 'sto_wp_editor_' ) !== 0 ) {
			return $mce_init;
		}

		$field_id = substr( $editor_id, strlen( 'sto_wp_editor_' ) );
		if ( $field_id === '' || empty( self::$mce_toolbar_end_registry[ $field_id ] ) ) {
			return $mce_init;
		}

		$cfg     = self::$mce_toolbar_end_registry[ $field_id ];
		$label   = isset( $cfg['label'] ) ? (string) $cfg['label'] : '+';
		$tooltip = isset( $cfg['tooltip'] ) ? (string) $cfg['tooltip'] : '';
		$snippet = isset( $cfg['snippet'] ) ? (string) $cfg['snippet'] : '';

		$slot = self::MCE_TOOLBAR_SLOT;
		// PreInit: toolbar items are registered before the first toolbar render.
		$body = 'editor.on("PreInit",function(){editor.addButton(' . wp_json_encode( $slot ) . ',{type:"button",text:' . wp_json_encode( $label ) . ',tooltip:' . wp_json_encode( $tooltip ) . ',classes:"sto-input-mce-toolbar-end-btn",onclick:function(){var s=' . wp_json_encode( $snippet ) . ';if(s){editor.insertContent(s);}}});});';

		$existing = isset( $mce_init['setup'] ) ? $mce_init['setup'] : '';
		if ( $existing === '' || $existing === null ) {
			$mce_init['setup'] = 'function(editor){' . $body . '}';
		} else {
			$mce_init['setup'] = 'function(editor){(' . $existing . ')(editor);' . $body . '}';
		}

		return $mce_init;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param string               $name
	 * @param string               $value
	 * @param string               $input_class
	 * @param string               $placeholder
	 * @param string|null          $dom_id Optional DOM id (per-breakpoint responsive fields).
	 */
	private function render_textarea( $field, $name, $value, $input_class, $placeholder, $dom_id = null ) {
		$rows = isset( $field['rows'] ) ? (int) $field['rows'] : 5;
		$cols = isset( $field['cols'] ) ? (int) $field['cols'] : 60;
		$id   = $dom_id !== null && $dom_id !== '' ? (string) $dom_id : (string) $field['id'];
		$attrs = array(
			'id'          => $id,
			'name'        => $name,
			'rows'        => (string) $rows,
			'cols'        => (string) $cols,
			'class'       => trim( 'sto-input-control sto-input-control--textarea ' . $input_class ),
			'placeholder' => $placeholder,
		);
		if ( ! empty( $field['readonly'] ) ) {
			$attrs['readonly'] = 'readonly';
		}
		if ( ! empty( $field['maxlength'] ) ) {
			$attrs['maxlength'] = (string) (int) $field['maxlength'];
		}
		if ( ! empty( $field['html_required'] ) ) {
			$attrs['required'] = 'required';
		}

		echo '<textarea';
		foreach ( $attrs as $k => $v ) {
			if ( $v === '' && $k !== 'placeholder' ) {
				continue;
			}
			echo ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		echo '>' . esc_textarea( $value ) . '</textarea>';
	}

	/**
	 * @param array<string, mixed> $field
	 * @param string               $name
	 * @param string               $value
	 * @param string               $input_type
	 * @param string               $input_class
	 * @param string               $placeholder
	 * @param string|null          $dom_id Optional DOM id (per-breakpoint responsive fields).
	 */
	private function render_single_line_input( $field, $name, $value, $input_type, $input_class, $placeholder, $dom_id = null ) {
		$html_type = $input_type;
		if ( $input_type === 'phone' ) {
			$html_type = 'tel';
		}

		$id = $dom_id !== null && $dom_id !== '' ? (string) $dom_id : (string) $field['id'];

		$attrs = array(
			'type'        => $html_type,
			'id'          => $id,
			'name'        => $name,
			'value'       => $value,
			'class'       => trim( 'sto-input-control sto-input-control--' . sanitize_html_class( $input_type ) . ' ' . $input_class ),
			'placeholder' => $placeholder,
			'autocomplete'=> ( $input_type === 'search' || $input_type === 'password' ) ? 'off' : '',
		);

		if ( $input_type === 'password' ) {
			$attrs['spellcheck']      = 'false';
			$attrs['autocapitalize'] = 'off';
		}

		if ( $input_type === 'number' ) {
			if ( isset( $field['min'] ) && $field['min'] !== null && $field['min'] !== '' ) {
				$attrs['min'] = (string) $field['min'];
			}
			if ( isset( $field['max'] ) && $field['max'] !== null && $field['max'] !== '' ) {
				$attrs['max'] = (string) $field['max'];
			}
			if ( isset( $field['step'] ) && $field['step'] !== null && $field['step'] !== '' ) {
				$attrs['step'] = (string) $field['step'];
			}
		}

		if ( ! empty( $field['maxlength'] ) ) {
			$attrs['maxlength'] = (string) (int) $field['maxlength'];
		}
		if ( ! empty( $field['readonly'] ) ) {
			$attrs['readonly'] = 'readonly';
		}
		if ( ! empty( $field['html_required'] ) ) {
			$attrs['required'] = 'required';
		}

		echo '<input';
		foreach ( $attrs as $k => $v ) {
			if ( $v === '' ) {
				continue;
			}
			echo ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		echo ' />';

		if ( $input_type === 'password' ) {
			echo '<button type="button" class="sto-input-password-toggle" data-sto-password-toggle="1" aria-pressed="false" aria-label="' . esc_attr__( 'Show password', 'topten-simple-theme-options' ) . '">';
			echo '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>';
			echo '</button>';
		}
	}

	/**
	 * @param array<int, string> $breakpoints
	 * @return array<string, string>
	 */
	private function get_value_map_input( $field_id, $default_value, array $breakpoints ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			return ResponsiveConfig::coerce_map( null, $breakpoints, (string) $default_value );
		}

		$stored = $saved_options[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			return ResponsiveConfig::coerce_map( $stored, $breakpoints, (string) $default_value );
		}

		$scalar = is_scalar( $stored ) ? (string) $stored : (string) $default_value;

		return ResponsiveConfig::coerce_map( null, $breakpoints, $scalar );
	}

	/**
	 * @param string $field_id
	 * @param string $default_value
	 * @param string $input_type
	 * @return string
	 */
	private function get_option_value( $field_id, $default_value, $input_type ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) ) {
			return $default_value;
		}

		if ( ! array_key_exists( $field_id, $saved_options ) ) {
			return $default_value;
		}

		$raw = $saved_options[ $field_id ];
		if ( is_array( $raw ) && ResponsiveConfig::is_breakpoint_value_map( $raw ) ) {
			return ResponsiveConfig::value_for_required_eval( $raw );
		}

		if ( is_array( $raw ) ) {
			return $default_value;
		}

		$stored = (string) $raw;

		if ( $input_type === 'editor' ) {
			return $stored;
		}

		return $stored;
	}
}
