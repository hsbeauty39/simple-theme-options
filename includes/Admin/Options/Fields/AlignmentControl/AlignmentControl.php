<?php
namespace SimpleThemeOptions\Admin\Options\Fields\AlignmentControl;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Segmented **alignment** control (icon and/or text buttons + optional per-option help / preview).
 * Stored as a single **sanitize_key** string in **`sto_options[id]`** (or per-breakpoint map when **`responsive`** is set).
 *
 * Register with **`'type' => 'alignment'`** (or **`AlignmentControl::register()`**). Keys: **`section_slug`**, **`id`**, **`title`**, **`options`** (required) — same map shape as **ButtonGroup** (**value** => **label** string **or** array with **`label`**, optional **`tooltip`**, **`preview_image`**, **`icon`** Font Awesome class string), optional **`default`**, **`description`**, conditional **`required`**, **`html_required`**, **`tooltip`**, **`wrapper_class`**, optional **`responsive`** + **`device`**,
 * optional **`orientation`** => **`horizontal`** (default) or **`vertical`**, **`density`** => **`default`** or **`compact`**, **`show_labels`** (bool, default **true** — when **false** and an **`icon`** is set, only the icon shows with a screen-reader label), **`allow_clear`** (bool — when **true**, an empty value may be saved and a clear control is shown).
 * Optional **`css_map`**: map **option key** => **CSS fragment** (e.g. **`justify-content`** value) for theme helpers — not used in admin UI.
 */
final class AlignmentControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const MAX_OPTIONS = 12;

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
		// After GalleryControl (19.43), before Tabs (19.45).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), 19.44, 2 );
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
		$field['html_required'] = ! empty( $field['html_required'] );
		$field['options']       = $options;

		$ori = isset( $field['orientation'] ) ? sanitize_key( (string) $field['orientation'] ) : 'horizontal';
		$field['orientation'] = in_array( $ori, array( 'horizontal', 'vertical' ), true ) ? $ori : 'horizontal';

		$den = isset( $field['density'] ) ? sanitize_key( (string) $field['density'] ) : 'default';
		$field['density'] = in_array( $den, array( 'default', 'compact' ), true ) ? $den : 'default';

		$field['show_labels'] = ! array_key_exists( 'show_labels', $field ) || ! empty( $field['show_labels'] );
		$field['allow_clear'] = ! empty( $field['allow_clear'] );

		$css_map = isset( $field['css_map'] ) && is_array( $field['css_map'] ) ? $field['css_map'] : array();
		$clean   = array();
		foreach ( $css_map as $k => $frag ) {
			$kk = sanitize_key( (string) $k );
			if ( $kk !== '' && isset( $options[ $kk ] ) ) {
				$clean[ $kk ] = sanitize_text_field( (string) $frag );
			}
		}
		$field['css_map'] = $clean;

		$bps = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

		$default = isset( $field['default'] ) ? sanitize_key( (string) $field['default'] ) : '';
		if ( $default !== '' && ! isset( $options[ $default ] ) ) {
			$default = '';
		}
		if ( $default === '' && ! $field['allow_clear'] ) {
			$default = (string) $keys[0];
		}
		$field['default'] = $default;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]            = $field;
	}

	/**
	 * @param array<string, array{label:string,tooltip:string,preview_image:string,icon:string}> $raw
	 * @return array<string, array{label:string,tooltip:string,preview_image:string,icon:string}>
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
					'label'         => (string) $meta,
					'tooltip'       => '',
					'preview_image' => '',
					'icon'          => '',
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
			$icon    = isset( $meta['icon'] ) ? preg_replace( '/[^a-zA-Z0-9_\- ]/', '', (string) $meta['icon'] ) : '';
			$out[ $key ] = array(
				'label'         => $label,
				'tooltip'       => $tip,
				'preview_image' => $preview,
				'icon'          => trim( $icon ),
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
	 * @param string $field_id
	 * @return array<int, string>|null
	 */
	public function get_responsive_breakpoints( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;

		return is_array( $field ) ? ( $field['responsive_breakpoints'] ?? null ) : null;
	}

	/**
	 * @param mixed $raw
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) || empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return '';
		}
		$options     = $field['options'];
		$allow_clear = ! empty( $field['allow_clear'] );
		$bps         = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;

		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->sanitize_for_field_raw( $cell, $options, $allow_clear );
			}

			return $out;
		}

		return $this->sanitize_for_field_raw( $raw, $options, $allow_clear );
	}

	/**
	 * @param mixed $raw
	 * @param array<string, array<string, string>> $options
	 * @param bool                                 $allow_clear
	 * @return string
	 */
	private function sanitize_for_field_raw( $raw, array $options, $allow_clear ) {
		$keys = array_keys( $options );
		if ( empty( $keys ) ) {
			return '';
		}
		$v = is_string( $raw ) ? trim( $raw ) : '';
		$v = sanitize_key( $v );
		if ( $v === '' && $allow_clear ) {
			return '';
		}
		if ( $v !== '' && isset( $options[ $v ] ) ) {
			return $v;
		}

		return $allow_clear ? '' : (string) $keys[0];
	}

	/**
	 * Resolved CSS fragment from **`css_map`** for a stored value (theme use).
	 *
	 * @param string      $field_id Registered field id.
	 * @param string|null $json_or_scalar When non-null, treat as stored scalar (not JSON).
	 * @return string
	 */
	public function get_css_fragment_for_value( $field_id, $json_or_scalar = null ) {
		$field_id = sanitize_key( (string) $field_id );
		if ( $field_id === '' || ! isset( $this->fields_by_id[ $field_id ] ) ) {
			return '';
		}
		$field = $this->fields_by_id[ $field_id ];
		$val   = '';
		if ( null !== $json_or_scalar && is_string( $json_or_scalar ) ) {
			$val = sanitize_key( $json_or_scalar );
		} else {
			$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
			if ( ! is_array( $opts ) || ! array_key_exists( $field_id, $opts ) ) {
				return '';
			}
			$stored = $opts[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$slice = ResponsiveConfig::value_for_required_eval( $stored );
				$val   = is_scalar( $slice ) ? sanitize_key( (string) $slice ) : '';
			} else {
				$val = is_scalar( $stored ) ? sanitize_key( (string) $stored ) : '';
			}
		}
		if ( $val === '' ) {
			return '';
		}
		$map = isset( $field['css_map'] ) && is_array( $field['css_map'] ) ? $field['css_map'] : array();

		return isset( $map[ $val ] ) ? (string) $map[ $val ] : '';
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
		$wrapper_class  = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required       = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json  = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$options        = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$tooltip        = FieldTitle::get_tooltip_config( $field );
		$bps_storage    = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp   = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$default_value  = isset( $field['default'] ) ? (string) $field['default'] : '';
		$allowed_keys   = array_keys( $options );
		$orientation    = isset( $field['orientation'] ) ? (string) $field['orientation'] : 'horizontal';
		$density        = isset( $field['density'] ) ? (string) $field['density'] : 'default';
		$show_labels    = ! empty( $field['show_labels'] );
		$allow_clear    = ! empty( $field['allow_clear'] );

		$is_inner    = ( 'group_inner' === $context );
		$group_label = $title !== '' ? $title : $field_id;

		$row_classes = array( 'sto-field-row', 'sto-field-row-alignment' );
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
		if ( $orientation === 'vertical' ) {
			$row_classes[] = 'sto-field-row-alignment--vertical';
		}
		if ( $density === 'compact' ) {
			$row_classes[] = 'sto-field-row-alignment--compact';
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
				$value_map = $this->get_value_map( $field_id, $default_value, $allowed_keys, $bps_storage, $field );
				$current   = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $default_value;
				$current   = $this->coerce_value( (string) $current, $allowed_keys, $field );
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$id_suffix  = $field_id . '_' . $tabs_pane_bp;
				$this->render_alignment_control( $id_suffix, $input_name, $current, $options, $group_label . ' — ' . strtoupper( $tabs_pane_bp ), $show_labels, $allow_clear );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $default_value, $allowed_keys, $bps_storage, $field );
					foreach ( $bps_storage as $i => $bp ) :
						$bp         = sanitize_key( (string) $bp );
						$visible    = ( 0 === (int) $i );
						$current    = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $default_value;
						$current    = $this->coerce_value( (string) $current, $allowed_keys, $field );
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$id_suffix  = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_alignment_control( $id_suffix, $input_name, $current, $options, $group_label . ' — ' . strtoupper( $bp ), $show_labels, $allow_clear );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$current    = $this->get_option_value( $field_id, $default_value, $allowed_keys, $field );
				$input_name = 'sto_options[' . $field_id . ']';
				$this->render_alignment_control( $field_id, $input_name, $current, $options, $group_label, $show_labels, $allow_clear );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string                               $id_suffix
	 * @param string                               $input_name
	 * @param string                               $current
	 * @param array<string, array<string, string>> $options
	 * @param string                               $group_label
	 * @param bool                                 $show_labels
	 * @param bool                                 $allow_clear
	 */
	private function render_alignment_control( $id_suffix, $input_name, $current, array $options, $group_label, $show_labels, $allow_clear ) {
		$wrap_id = 'sto-alignment-' . $id_suffix;
		?>
		<div
			class="sto-alignment"
			id="<?php echo esc_attr( $wrap_id ); ?>"
			data-sto-alignment="1"
			<?php if ( $allow_clear ) : ?>
				data-sto-alignment-allow-clear="1"
			<?php endif; ?>
		>
			<div class="sto-alignment__toolbar" role="radiogroup" aria-label="<?php echo esc_attr( $group_label ); ?>">
				<?php foreach ( $options as $value => $meta ) : ?>
					<?php
					$opt_label = isset( $meta['label'] ) ? (string) $meta['label'] : (string) $value;
					$opt_tip   = isset( $meta['tooltip'] ) ? trim( (string) $meta['tooltip'] ) : '';
					$preview   = isset( $meta['preview_image'] ) ? (string) $meta['preview_image'] : '';
					$icon      = isset( $meta['icon'] ) ? trim( (string) $meta['icon'] ) : '';
					$on        = ( (string) $current === (string) $value );
					$show_hint = ( $opt_tip !== '' || $preview !== '' );
					$show_text = $show_labels || $icon === '';
					if ( $preview !== '' && $opt_tip !== '' ) {
						$aria_help = sprintf(
							/* translators: 1: option label, 2: help text */
							__( 'Preview for %1$s. %2$s', 'simple-theme-options' ),
							$opt_label,
							$opt_tip
						);
					} elseif ( $opt_tip !== '' ) {
						$aria_help = sprintf(
							/* translators: 1: option label, 2: help text */
							__( 'Help for %1$s: %2$s', 'simple-theme-options' ),
							$opt_label,
							$opt_tip
						);
					} else {
						$aria_help = sprintf(
							/* translators: %s: option label */
							__( 'Show layout preview for %s', 'simple-theme-options' ),
							$opt_label
						);
					}
					?>
					<div class="sto-alignment__segment<?php echo $on ? ' sto-alignment__segment--selected' : ''; ?><?php echo $show_hint ? ' sto-alignment__segment--has-hint' : ''; ?>" data-sto-alignment-segment>
						<button
							type="button"
							class="sto-alignment__choice<?php echo $on ? ' sto-is-active' : ''; ?>"
							data-sto-alignment-value="<?php echo esc_attr( (string) $value ); ?>"
							aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"
							id="<?php echo esc_attr( 'sto-alignment-btn-' . $id_suffix . '-' . $value ); ?>"
						>
							<?php if ( $icon !== '' ) : ?>
								<span class="sto-alignment__icon" aria-hidden="true"><i class="<?php echo esc_attr( $icon ); ?>"></i></span>
							<?php endif; ?>
							<?php if ( $show_text ) : ?>
								<span class="sto-alignment__label"><?php echo esc_html( $opt_label ); ?></span>
							<?php else : ?>
								<span class="screen-reader-text"><?php echo esc_html( $opt_label ); ?></span>
							<?php endif; ?>
						</button>
						<?php if ( $show_hint ) : ?>
							<button
								type="button"
								class="sto-alignment__hint"
								<?php if ( $opt_tip !== '' && $preview === '' ) : ?>
									data-sto-text-tip="<?php echo esc_attr( $opt_tip ); ?>"
								<?php endif; ?>
								<?php if ( $preview !== '' ) : ?>
									data-sto-tooltip-image="<?php echo esc_attr( $preview ); ?>"
								<?php endif; ?>
								aria-label="<?php echo esc_attr( $aria_help ); ?>"
							>
								<span class="sto-alignment__hint-glyph" aria-hidden="true">?</span>
							</button>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<?php if ( $allow_clear ) : ?>
					<div class="sto-alignment__clear-wrap">
						<button
							type="button"
							class="sto-alignment__clear"
							data-sto-alignment-clear="1"
							aria-label="<?php esc_attr_e( 'Clear selection', 'simple-theme-options' ); ?>"
							title="<?php esc_attr_e( 'Clear', 'simple-theme-options' ); ?>"
						><i class="fa-light fa-xmark" aria-hidden="true"></i><span class="screen-reader-text"><?php esc_html_e( 'Clear', 'simple-theme-options' ); ?></span></button>
					</div>
				<?php endif; ?>
			</div>
			<input type="hidden" class="sto-alignment-value" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $current ); ?>" autocomplete="off" />
		</div>
		<?php
	}

	/**
	 * @param string               $field_id
	 * @param string               $default_value
	 * @param array<int, string>   $allowed_keys
	 * @param array<int, string>   $breakpoints
	 * @param array<string, mixed> $field
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, $default_value, array $allowed_keys, array $breakpoints, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) || ! isset( $saved_options[ $field_id ] ) ) {
			$scalar = $this->coerce_value( $default_value, $allowed_keys, $field );

			return ResponsiveConfig::coerce_map( null, $breakpoints, $scalar );
		}

		$stored = $saved_options[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$scalar_default = $this->coerce_value( $default_value, $allowed_keys, $field );
			$map            = ResponsiveConfig::coerce_map( $stored, $breakpoints, $scalar_default );
			foreach ( $map as $bp => $val ) {
				$map[ $bp ] = $this->coerce_value( (string) $val, $allowed_keys, $field );
			}

			return $map;
		}

		$scalar = $this->coerce_value( (string) $stored, $allowed_keys, $field );

		return ResponsiveConfig::coerce_map( null, $breakpoints, $scalar );
	}

	/**
	 * @param string               $field_id
	 * @param string               $default_value
	 * @param array<int, string>   $allowed_keys
	 * @param array<string, mixed> $field
	 * @return string
	 */
	private function get_option_value( $field_id, $default_value, array $allowed_keys, array $field ) {
		$saved_options = get_option( 'sto_options', array() );
		if ( ! is_array( $saved_options ) ) {
			return $this->coerce_value( $default_value, $allowed_keys, $field );
		}
		if ( isset( $saved_options[ $field_id ] ) ) {
			$st = $saved_options[ $field_id ];
			if ( is_array( $st ) && ResponsiveConfig::is_breakpoint_value_map( $st ) ) {
				return $this->coerce_value( ResponsiveConfig::value_for_required_eval( $st ), $allowed_keys, $field );
			}

			return $this->coerce_value( (string) $st, $allowed_keys, $field );
		}

		return $this->coerce_value( $default_value, $allowed_keys, $field );
	}

	/**
	 * @param string               $value
	 * @param array<int, string>   $allowed_keys
	 * @param array<string, mixed> $field
	 * @return string
	 */
	private function coerce_value( $value, array $allowed_keys, array $field ) {
		$allow_clear = ! empty( $field['allow_clear'] );
		$v           = sanitize_key( (string) $value );
		if ( $v === '' && $allow_clear ) {
			return '';
		}
		if ( $v !== '' && in_array( $v, $allowed_keys, true ) ) {
			return $v;
		}
		if ( ! empty( $allowed_keys ) ) {
			return $allow_clear ? '' : (string) $allowed_keys[0];
		}

		return '';
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
		$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$slice = ResponsiveConfig::value_for_required_eval( $raw );

			return $this->scalar_is_empty_for_required( $field, is_scalar( $slice ) ? (string) $slice : '' );
		}

		return $this->scalar_is_empty_for_required( $field, is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) );
	}

	/**
	 * @param array<string, mixed> $field
	 * @param string                 $scalar
	 */
	private function scalar_is_empty_for_required( array $field, $scalar ) {
		$allow_clear = ! empty( $field['allow_clear'] );
		if ( $allow_clear ) {
			return trim( (string) $scalar ) === '';
		}
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$keys    = array_keys( $options );
		$v       = sanitize_key( (string) $scalar );

		return $v === '' || ! in_array( $v, $keys, true );
	}
}
