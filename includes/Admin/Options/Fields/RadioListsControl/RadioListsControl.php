<?php
namespace SimpleThemeOptions\Admin\Options\Fields\RadioListsControl;

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
 * **Radio lists** repeater: ordered rows, each with optional **title** + one **choice** from shared **`options`**, stored as JSON in **`sto_options[id]`**.
 *
 * Register with **`'type' => 'radio_lists'`**. Keys: **`section_slug`**, **`id`**, **`title`**, **`options`** (required, same map shape as **ButtonGroup**),
 * optional **`default`** => **`array( array( 'title' => '…', 'value' => 'key' ), … )`**, optional **`max`** (int **`0`** = unlimited, hard cap **{@see MAX_ROWS}**),
 * optional **`show_row_titles`** (bool, default **true**), optional **`repeatable`** (bool, default **true** — when **false**, one fixed row only: no add / drag / remove; same JSON shape **`[{title,value}]`**), optional **`radio_layout`** => **`stack`** (default — option tiles in a **column**) or **`inline`** (tiles in a **row**, wrap),
 * **`description`**, conditional **`required`**, **`html_required`**, **`tooltip`**, **`wrapper_class`**, optional **`group`**.
 * Admin: **Add list**, **drag** handle reorders (**jQuery UI Sortable**), **delete** removes a row. **No per-breakpoint `responsive` slice** (same carve-out pattern as **`multi_text`**).
 */
final class RadioListsControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const MAX_ROWS = 50;

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
		// After ButtonGroup (17.5), before Select (18).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::RADIO_LISTS, 2 );
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
		$raw_options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		if ( ! $section_slug || ! $field_id || empty( $raw_options ) ) {
			return;
		}

		$options = $this->normalize_options( $raw_options );
		if ( empty( $options ) ) {
			return;
		}

		$field['section_slug']    = $section_slug;
		$field['id']              = $field_id;
		$field['title']           = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']     = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class']   = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']        = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']           = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['html_required']   = ! empty( $field['html_required'] );
		$field['show_row_titles'] = array_key_exists( 'show_row_titles', $field ) ? (bool) $field['show_row_titles'] : true;
		$field['repeatable']      = ! array_key_exists( 'repeatable', $field ) ? true : (bool) $field['repeatable'];

		$layout = isset( $field['radio_layout'] ) ? sanitize_key( (string) $field['radio_layout'] ) : 'stack';
		if ( $layout !== 'inline' ) {
			$layout = 'stack';
		}
		$field['radio_layout'] = $layout;

		$max = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		if ( $max > self::MAX_ROWS ) {
			$max = self::MAX_ROWS;
		}
		$field['max'] = $max;

		$allowed = array_keys( $options );
		$field['options']       = $options;
		$field['allowed_keys']  = $allowed;
		$default_cap            = ! empty( $field['repeatable'] ) ? $field['max'] : 1;
		$field['default_rows']  = $this->normalize_default_rows( isset( $field['default'] ) ? $field['default'] : array(), $options, $allowed, $default_cap );

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]            = $field;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<string, array<string, string>> $options
	 * @param array<int, string> $allowed_keys
	 * @param int                $max_cap
	 * @return array<int, array{title: string, value: string}>
	 */
	private function normalize_default_rows( $raw, array $options, array $allowed_keys, $max_cap ) {
		$first = (string) $allowed_keys[0];
		$out   = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$cap = ( is_int( $max_cap ) && $max_cap > 0 ) ? min( $max_cap, self::MAX_ROWS ) : self::MAX_ROWS;
		foreach ( $raw as $row ) {
			if ( count( $out ) >= $cap ) {
				break;
			}
			$title = '';
			$val   = $first;
			if ( is_array( $row ) ) {
				$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
				$vk    = isset( $row['value'] ) ? sanitize_key( (string) $row['value'] ) : '';
				if ( $vk !== '' && isset( $options[ $vk ] ) ) {
					$val = $vk;
				}
			} elseif ( is_string( $row ) || is_numeric( $row ) ) {
				$vk = sanitize_key( (string) $row );
				if ( $vk !== '' && isset( $options[ $vk ] ) ) {
					$val = $vk;
				}
			}
			$out[] = array(
				'title' => $title,
				'value' => $val,
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, array{label: string, tooltip: string, preview_image: string}>
	 */
	private function normalize_options( $raw ) {
		$out   = array();
		$count = 0;
		foreach ( $raw as $value => $meta ) {
			if ( $count >= 24 ) {
				break;
			}
			$value = sanitize_key( (string) $value );
			if ( $value === '' ) {
				continue;
			}
			$label = '';
			$tip   = '';
			$img   = '';
			if ( is_array( $meta ) ) {
				$label = isset( $meta['label'] ) ? (string) $meta['label'] : '';
				$tip   = isset( $meta['tooltip'] ) ? trim( (string) $meta['tooltip'] ) : '';
				$img   = isset( $meta['preview_image'] ) ? trim( (string) $meta['preview_image'] ) : '';
			} else {
				$label = (string) $meta;
			}
			if ( $label === '' ) {
				$label = $value;
			}
			$out[ $value ] = array(
				'label'         => $label,
				'tooltip'       => $tip,
				'preview_image' => $img,
			);
			++$count;
		}

		return $out;
	}

	/**
	 * @param mixed $raw
	 * @return string JSON
	 */
	public function sanitize_stored_value( $raw ) {
		$rows = $this->parse_rows( $raw );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
			$val   = isset( $row['value'] ) ? sanitize_key( (string) $row['value'] ) : '';
			$out[] = array(
				'title' => $title,
				'value' => $val,
			);
		}

		return wp_json_encode( array_values( $out ) );
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array<string, string>>
	 */
	private function parse_rows( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( $raw === '' ) {
				return array();
			}
			$decoded = json_decode( $raw, true );

			return is_array( $decoded ) ? $decoded : array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}

		return array();
	}

	/**
	 * @param string $field_id
	 * @param mixed  $raw
	 * @return string JSON
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) || empty( $field['options'] ) ) {
			return wp_json_encode( array() );
		}
		$options    = $field['options'];
		$repeatable = ! empty( $field['repeatable'] );
		$max        = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		$cap        = ! $repeatable
			? 1
			: ( ( $max > 0 ) ? min( $max, self::MAX_ROWS ) : self::MAX_ROWS );

		$parsed = $this->parse_rows( $raw );
		$out    = array();
		foreach ( $parsed as $row ) {
			if ( count( $out ) >= $cap ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
			$vk    = isset( $row['value'] ) ? sanitize_key( (string) $row['value'] ) : '';
			if ( $vk === '' || ! isset( $options[ $vk ] ) ) {
				$keys = array_keys( $options );
				$vk   = $keys ? (string) $keys[0] : '';
			}
			$out[] = array(
				'title' => $title,
				'value' => $vk,
			);
		}

		if ( ! $repeatable && $out === array() ) {
			$keys = array_keys( $options );
			$out[] = array(
				'title' => '',
				'value' => $keys ? (string) $keys[0] : '',
			);
		}

		return wp_json_encode( array_values( $out ) );
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

		$field_id       = $field['id'];
		$title          = $field['title'];
		$description    = $field['description'];
		$wrapper_class  = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required       = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json  = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$tooltip        = FieldTitle::get_tooltip_config( $field );
		$options        = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$max            = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		$default_rows   = isset( $field['default_rows'] ) && is_array( $field['default_rows'] ) ? $field['default_rows'] : array();
		$show_titles    = ! empty( $field['show_row_titles'] );
		$radio_layout   = isset( $field['radio_layout'] ) ? (string) $field['radio_layout'] : 'stack';
		$repeatable     = ! isset( $field['repeatable'] ) || $field['repeatable'];
		$is_inner       = ( 'group_inner' === $context );
		$group_label    = $title !== '' ? $title : $field_id;

		$row_classes = array( 'sto-field-row', 'sto-field-row-radio-lists' );
		if ( $wrapper_class ) {
			$row_classes[] = $wrapper_class;
		}
		if ( $is_inner ) {
			$row_classes[] = 'sto-field-row--in-group';
		}

		$json = $this->get_merged_json( $field_id, $default_rows );
		$rows = json_decode( (string) $json, true );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		if ( ! $repeatable ) {
			if ( $rows === array() ) {
				$keys = array_keys( $options );
				$rows = array(
					array(
						'title' => '',
						'value' => $keys ? (string) $keys[0] : '',
					),
				);
			} else {
				$rows = array_slice( array_values( $rows ), 0, 1 );
			}
		}

		$opts_for_js = array();
		foreach ( $options as $vk => $meta ) {
			$opts_for_js[ $vk ] = isset( $meta['label'] ) ? (string) $meta['label'] : (string) $vk;
		}

		$i18n = array(
			'addMore'   => __( 'Add list', 'simple-theme-options' ),
			'remove'    => __( 'Remove list', 'simple-theme-options' ),
			'drag'      => __( 'Drag to reorder', 'simple-theme-options' ),
			'rowTitlePh'=> __( 'List label (optional)', 'simple-theme-options' ),
			'rowTitleLbl'=> __( 'List label', 'simple-theme-options' ),
			'chooseLbl' => __( 'Choose one option', 'simple-theme-options' ),
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
				class="sto-radio-lists<?php echo $repeatable ? '' : ' sto-radio-lists--single'; ?>"
				data-sto-radio-lists="1"
				data-sto-radio-lists-repeatable="<?php echo esc_attr( $repeatable ? '1' : '0' ); ?>"
				data-sto-radio-lists-max="<?php echo esc_attr( (string) ( ! $repeatable ? 1 : ( $max > 0 ? $max : 0 ) ) ); ?>"
				data-sto-radio-lists-show-titles="<?php echo esc_attr( $show_titles ? '1' : '0' ); ?>"
				data-sto-radio-lists-layout="<?php echo esc_attr( $radio_layout ); ?>"
				data-sto-radio-lists-options="<?php echo esc_attr( wp_json_encode( $opts_for_js ) ); ?>"
				data-sto-radio-lists-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>"
				aria-label="<?php echo esc_attr( $group_label ); ?>"
			>
				<input
					type="hidden"
					class="sto-radio-lists__value"
					name="<?php echo esc_attr( $input_name ); ?>"
					value="<?php echo esc_attr( wp_json_encode( array_values( $rows ) ) ); ?>"
					autocomplete="off"
				/>
				<ul class="sto-radio-lists__list" data-sto-radio-lists-list>
					<?php foreach ( $rows as $idx => $row ) : ?>
						<?php
						$r_title = isset( $row['title'] ) ? (string) $row['title'] : '';
						$r_val   = isset( $row['value'] ) ? sanitize_key( (string) $row['value'] ) : '';
						if ( $r_val === '' || ! isset( $options[ $r_val ] ) ) {
							$keys  = array_keys( $options );
							$r_val = $keys ? (string) $keys[0] : '';
						}
						?>
						<li class="sto-radio-lists__item<?php echo $repeatable ? '' : ' sto-radio-lists__item--single'; ?>" data-sto-radio-lists-item>
							<?php if ( $repeatable ) : ?>
							<button
								type="button"
								class="sto-radio-lists__drag"
								data-sto-radio-lists-drag
								aria-label="<?php echo esc_attr( $i18n['drag'] ); ?>"
								title="<?php echo esc_attr( $i18n['drag'] ); ?>"
							><i class="fa-light fa-grip-dots-vertical" aria-hidden="true"></i></button>
							<?php endif; ?>
							<div class="sto-radio-lists__body">
								<?php if ( $show_titles ) : ?>
									<div class="sto-radio-lists__title-wrap">
										<input
											type="text"
											class="sto-radio-lists__title-input sto-input-text"
											data-sto-radio-lists-title
											value="<?php echo esc_attr( $r_title ); ?>"
											placeholder="<?php echo esc_attr( $i18n['rowTitlePh'] ); ?>"
											aria-label="<?php echo esc_attr( $i18n['rowTitleLbl'] ); ?>"
										/>
									</div>
								<?php endif; ?>
								<div
									class="sto-radio-lists__radios sto-radio-lists__radios--<?php echo esc_attr( $radio_layout ); ?>"
									role="radiogroup"
									aria-label="<?php echo esc_attr( $i18n['chooseLbl'] ); ?>"
								>
									<?php foreach ( $options as $ov => $meta ) : ?>
										<?php
										$opt_label = isset( $meta['label'] ) ? (string) $meta['label'] : (string) $ov;
										$rid       = 'sto-radio-lists-' . $field_id . '-' . (int) $idx . '-' . $ov;
										$checked   = ( (string) $r_val === (string) $ov );
										?>
										<label class="sto-radio-lists__opt<?php echo $checked ? ' sto-radio-lists__opt--checked' : ''; ?>" data-sto-radio-lists-opt>
											<input
												type="radio"
												class="sto-radio-lists__opt-input"
												data-sto-radio-lists-choice
												name="<?php echo esc_attr( 'sto_rl_' . $field_id . '_' . (int) $idx ); ?>"
												id="<?php echo esc_attr( $rid ); ?>"
												value="<?php echo esc_attr( (string) $ov ); ?>"
												<?php checked( $checked ); ?>
											/>
											<span class="sto-radio-lists__opt-ui" aria-hidden="true">
												<span class="sto-radio-lists__opt-dot"></span>
											</span>
											<span class="sto-radio-lists__opt-label"><?php echo esc_html( $opt_label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
							<?php if ( $repeatable ) : ?>
							<button
								type="button"
								class="sto-radio-lists__remove"
								data-sto-radio-lists-remove
								aria-label="<?php echo esc_attr( $i18n['remove'] ); ?>"
								title="<?php echo esc_attr( $i18n['remove'] ); ?>"
							><i class="fa-light fa-trash-can" aria-hidden="true"></i></button>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $repeatable ) : ?>
				<button type="button" class="button sto-radio-lists__add" data-sto-radio-lists-add>
					<?php echo esc_html( $i18n['addMore'] ); ?>
				</button>
				<?php endif; ?>
			</div>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string                                  $field_id
	 * @param array<int, array{title: string, value: string}> $default_rows
	 */
	private function get_merged_json( $field_id, array $default_rows ): string {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			return $this->registry_sanitize_posted_value( $field_id, $default_rows );
		}
		$stored = $saved[ $field_id ];

		return $this->registry_sanitize_posted_value(
			$field_id,
			is_string( $stored ) ? $stored : ( is_array( $stored ) ? wp_json_encode( $stored ) : '' )
		);
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
			$titles = isset( $field['title'] ) ? trim( (string) $field['title'] ) : '';
			$label  = $titles !== '' ? $titles : $fid;
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
		if ( ! is_array( $arr ) || $arr === array() ) {
			return true;
		}

		return false;
	}

	/**
	 * @param string      $field_id
	 * @param string|null $json_or_scalar
	 * @return array<int, array{title: string, value: string}>
	 */
	public function get_rows_for_field( $field_id, $json_or_scalar = null ): array {
		$field_id = sanitize_key( (string) $field_id );
		if ( $field_id === '' ) {
			return array();
		}
		$raw = $json_or_scalar;
		if ( null === $raw ) {
			$opts = get_option( 'sto_options', array() );
			$raw  = is_array( $opts ) && array_key_exists( $field_id, $opts ) ? $opts[ $field_id ] : '';
		}
		$json = is_string( $raw ) ? $raw : ( is_array( $raw ) ? wp_json_encode( $raw ) : '' );
		$rows = json_decode( static::sanitize_posted_value( $field_id, $json ), true );

		return is_array( $rows ) ? $rows : array();
	}
}
