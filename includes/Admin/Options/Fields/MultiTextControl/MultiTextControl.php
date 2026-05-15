<?php
namespace SimpleThemeOptions\Admin\Options\Fields\MultiTextControl;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * **Multi text** / repeater: ordered list of single-line strings in **`sto_options[id]`** as JSON **`["a","b"]`**.
 *
 * Register with **`'type' => 'multi_text'`**. Keys: **`section_slug`**, **`id`**, **`title`**, optional **`default`**
 * => **`array( 'Line 1', 'Line 2' )`**, optional **`max`** (int **`0`** = unlimited, hard cap **{@see MAX_LINES}**),
 * **`placeholder`**, **`description`**, conditional **`required`**, **`html_required`**, **`tooltip`**, **`wrapper_class`**, optional **`group`**.
 * Admin: **Add more** appends a row, **drag** handle reorders (**jQuery UI Sortable**), **delete** removes a row.
 */
final class MultiTextControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const MAX_LINES = 100;

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
		// After Dimension (19.42), before GalleryControl (19.43).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::MULTI_TEXT, 2 );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function register( $field ) {
		$instance = static::instance();
		FieldRegistrationDeferral::defer_or_run(
			static function () use ( $instance, $field ) {
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
			static function () use ( $instance, $fields ) {
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

		$field['section_slug']    = $section_slug;
		$field['id']              = $field_id;
		$field['title']           = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['placeholder']   = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['html_required'] = ! empty( $field['html_required'] );

		$max = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		if ( $max > self::MAX_LINES ) {
			$max = self::MAX_LINES;
		}
		$field['max'] = $max;

		$field['default_lines'] = $this->normalize_lines( isset( $field['default'] ) ? $field['default'] : array(), $field['max'] );

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]             = $field;
	}

	/**
	 * @param mixed    $raw
	 * @param int|null $max_cap Optional max count (0 = unlimited up to MAX_LINES).
	 * @return array<int, string>
	 */
	private function normalize_lines( $raw, $max_cap ) {
		$list = array();
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$raw = $decoded;
				} else {
					$raw = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
				}
			} else {
				$raw = array();
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$cap = ( is_int( $max_cap ) && $max_cap > 0 ) ? min( $max_cap, self::MAX_LINES ) : self::MAX_LINES;
		foreach ( $raw as $line ) {
			if ( count( $list ) >= $cap ) {
				break;
			}
			$list[] = sanitize_text_field( is_scalar( $line ) ? (string) $line : '' );
		}

		return $list;
	}

	/**
	 * @param mixed $raw
	 * @return string JSON array of strings (may include empty strings for UI round-trip; trimmed on read helpers).
	 */
	public function sanitize_stored_value( $raw ) {
		$lines = array();
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$lines = $this->normalize_lines( $decoded, self::MAX_LINES );
				}
			}
		} elseif ( is_array( $raw ) ) {
			$lines = $this->normalize_lines( $raw, self::MAX_LINES );
		}

		return wp_json_encode( array_values( $lines ) );
	}

	/**
	 * @param string $field_id
	 * @param mixed  $raw
	 * @return string JSON
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return wp_json_encode( array() );
		}
		$max = isset( $field['max'] ) ? absint( $field['max'] ) : 0;

		return wp_json_encode( $this->normalize_lines( $raw, $max > 0 ? $max : null ) );
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
	 * @param string               $section_slug
	 * @param array<string, mixed> $section
	 */
	public function render_section_fields( $section_slug, $section ) {
		unset( $section );
		$section_slug = sanitize_key( (string) $section_slug );
		if ( empty( $this->fields_by_section[ $section_slug ] ) ) {
			return;
		}
		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			if ( ! empty( $field['group'] ) ) {
				continue;
			}
			if ( ! FieldRenderGate::should_render_field( $field ) ) {
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

		$field_id      = $field['id'];
		$title         = $field['title'];
		$description   = $field['description'];
		$wrapper_class = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$tooltip       = FieldTitle::get_tooltip_config( $field );
		$placeholder   = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$max             = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		$default_lines   = isset( $field['default_lines'] ) && is_array( $field['default_lines'] ) ? $field['default_lines'] : array();

		$is_inner    = ( 'group_inner' === $context );
		$group_label = $title !== '' ? $title : $field_id;

		$row_classes = array( 'sto-field-row', 'sto-field-row-multi-text' );
		if ( $wrapper_class ) {
			$row_classes[] = $wrapper_class;
		}
		if ( $is_inner ) {
			$row_classes[] = 'sto-field-row--in-group';
		}

		$json = $this->get_merged_json( $field_id, $default_lines );
		$lines = json_decode( (string) $json, true );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$i18n = array(
			'addMore'  => __( 'Add more', 'simple-theme-options' ),
			'remove'   => __( 'Remove line', 'simple-theme-options' ),
			'drag'     => __( 'Drag to reorder', 'simple-theme-options' ),
			'rowLabel' => __( 'Text line', 'simple-theme-options' ),
		);

		$input_name = 'sto_options[' . $field_id . ']';
		?>
		<div
			id="<?php echo esc_attr( 'sto-field-' . $field_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
			data-sto-field-id="<?php echo esc_attr( $field_id ); ?>"
			<?php if ( $required_json ) : ?>
				data-sto-required="<?php echo esc_attr( $required_json ); ?>"
			<?php endif; ?>
		>
			<?php if ( $title ) : ?>
				<?php FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_inner, '' ); ?>
			<?php endif; ?>

			<div
				class="sto-multi-text"
				data-sto-multi-text="1"
				data-sto-multi-text-max="<?php echo esc_attr( (string) ( $max > 0 ? $max : 0 ) ); ?>"
				data-sto-multi-text-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>"
				aria-label="<?php echo esc_attr( $group_label ); ?>"
			>
				<input
					type="hidden"
					class="sto-multi-text__value"
					name="<?php echo esc_attr( $input_name ); ?>"
					value="<?php echo esc_attr( wp_json_encode( array_values( $lines ) ) ); ?>"
					autocomplete="off"
				/>
				<ul class="sto-multi-text__list" data-sto-multi-text-list>
					<?php foreach ( $lines as $line ) : ?>
						<li class="sto-multi-text__item" data-sto-multi-text-item>
							<button
								type="button"
								class="sto-multi-text__drag"
								data-sto-multi-text-drag
								aria-label="<?php echo esc_attr( $i18n['drag'] ); ?>"
								title="<?php echo esc_attr( $i18n['drag'] ); ?>"
							><i class="fa-light fa-grip-dots-vertical" aria-hidden="true"></i></button>
							<input
								type="text"
								class="sto-multi-text__input sto-input-text"
								data-sto-multi-text-input
								value="<?php echo esc_attr( (string) $line ); ?>"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								aria-label="<?php echo esc_attr( $i18n['rowLabel'] ); ?>"
							/>
							<button
								type="button"
								class="sto-multi-text__remove"
								data-sto-multi-text-remove
								aria-label="<?php echo esc_attr( $i18n['remove'] ); ?>"
								title="<?php echo esc_attr( $i18n['remove'] ); ?>"
							><i class="fa-light fa-trash-can" aria-hidden="true"></i></button>
						</li>
					<?php endforeach; ?>
				</ul>
				<button type="button" class="button sto-multi-text__add" data-sto-multi-text-add>
					<?php echo esc_html( $i18n['addMore'] ); ?>
				</button>
			</div>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string            $field_id
	 * @param array<int|string> $default_lines
	 * @return string JSON
	 */
	private function get_merged_json( $field_id, array $default_lines ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			return wp_json_encode( array_values( $default_lines ) );
		}
		$stored = $saved[ $field_id ];

		return $this->sanitize_stored_value( is_string( $stored ) ? $stored : ( is_array( $stored ) ? $stored : '' ) );
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
			if ( ! $this->is_html_required_value_empty( $raw ) ) {
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
	 * @param mixed $raw
	 */
	private function is_html_required_value_empty( $raw ): bool {
		$str = is_string( $raw ) ? trim( $raw ) : '';
		if ( $str === '' ) {
			return true;
		}
		$arr = json_decode( $str, true );
		if ( ! is_array( $arr ) ) {
			return true;
		}
		foreach ( $arr as $line ) {
			if ( trim( (string) $line ) !== '' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ordered non-empty lines for theme output.
	 *
	 * @param string      $field_id
	 * @param string|null $json_or_scalar JSON string or null to read `sto_options`.
	 * @return array<int, string>
	 */
	public function get_lines_for_field( $field_id, $json_or_scalar = null ): array {
		$field_id = sanitize_key( (string) $field_id );
		if ( $field_id === '' ) {
			return array();
		}
		$raw = $json_or_scalar;
		if ( null === $raw ) {
			$opts = get_option( 'sto_options', array() );
			$raw  = is_array( $opts ) && array_key_exists( $field_id, $opts ) ? $opts[ $field_id ] : '';
		}
		$json = is_string( $raw ) ? $this->sanitize_stored_value( $raw ) : $this->sanitize_stored_value( is_array( $raw ) ? $raw : '' );
		$arr  = json_decode( $json, true );
		if ( ! is_array( $arr ) ) {
			return array();
		}
		$out = array();
		foreach ( $arr as $line ) {
			$t = trim( is_scalar( $line ) ? (string) $line : '' );
			if ( $t !== '' ) {
				$out[] = $t;
			}
		}

		return $out;
	}
}
