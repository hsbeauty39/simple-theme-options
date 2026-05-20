<?php
namespace SimpleThemeOptions\Admin\Options\Fields\GalleryControl;

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

/**
 * Media **gallery** control: ordered list of **attachment image** IDs in **`sto_options[id]`** as JSON **`["12","34"]`**
 * (or per-breakpoint map of JSON strings when **`responsive`** is set).
 *
 * Register with **`'type' => 'gallery'`** (or **`GalleryControl::register()`**). Keys: **`section_slug`**, **`id`**, **`title`**,
 * optional **`default`** => **`array( 101, 102, 103 )`** of **image attachment IDs** (invalid / non-image IDs dropped), optional **`max`**
 * (int **`0`** = unlimited, otherwise capped at **100**), **`description`**, conditional **`required`**, **`html_required`**,
 * **`tooltip`**, **`wrapper_class`**, optional **`responsive`** + **`device`**. Admin: **`wp.media`** multi-select, thumbnails,
 * per-item remove, optional **jQuery UI Sortable** reorder, **Clear all** in header. Boot **`GalleryControl::instance()`**
 * before **`Group::register()`** when used inside groups / tabs / accordion.
 */
final class GalleryControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const MAX_IDS = 100;

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
		// After MultiTextControl (19.435), before AlignmentControl (19.44).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::GALLERY, 2 );
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
		FieldSpacing::normalize_config( $field );


		$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
		$field_id     = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';

		if ( ! $section_slug || ! $field_id ) {
			return;
		}

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description'] = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['html_required'] = ! empty( $field['html_required'] );

		$max = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		if ( $max > self::MAX_IDS ) {
			$max = self::MAX_IDS;
		}
		$field['max'] = $max;

		$default_ids = $this->normalize_id_list( isset( $field['default'] ) ? $field['default'] : array(), $field['max'] );
		$field['default_ids'] = $default_ids;

		$bps = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]            = $field;
	}

	/**
	 * @param mixed $raw
	 * @param int   $max 0 = unlimited (still hard-capped at MAX_IDS).
	 * @return array<int, string> Unique attachment id strings.
	 */
	private function normalize_id_list( $raw, $max ) {
		$list = array();
		if ( is_string( $raw ) ) {
			$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$cap = $max > 0 ? $max : self::MAX_IDS;
		foreach ( $raw as $v ) {
			if ( count( $list ) >= $cap ) {
				break;
			}
			$id = absint( $v );
			if ( $id < 1 ) {
				continue;
			}
			if ( ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			$key = (string) $id;
			if ( isset( $list[ $key ] ) ) {
				continue;
			}
			$list[ $key ] = $key;
		}

		return array_values( $list );
	}

	/**
	 * @param mixed $raw
	 * @return string JSON array of id strings.
	 */
	public function sanitize_stored_value( $raw ) {
		$ids = array();
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$ids = $this->normalize_id_list( $decoded, self::MAX_IDS );
				} elseif ( strpos( $raw, '[' ) === false ) {
					$ids = $this->normalize_id_list( $raw, self::MAX_IDS );
				}
			}
		} elseif ( is_array( $raw ) ) {
			$ids = $this->normalize_id_list( $raw, self::MAX_IDS );
		}

		return wp_json_encode( array_values( $ids ) );
	}

	/**
	 * @param string               $field_id
	 * @param string|array<mixed> $raw Posted JSON string or per-breakpoint map.
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return wp_json_encode( array() );
		}
		$max = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;

		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->sanitize_cell( $cell, $max );
			}

			return $out;
		}

		return $this->sanitize_cell( $raw, $max );
	}

	/**
	 * @param mixed $cell
	 * @param int   $max
	 * @return string JSON
	 */
	private function sanitize_cell( $cell, $max ) {
		$ids = array();
		if ( is_string( $cell ) ) {
			$cell = trim( $cell );
			if ( $cell !== '' ) {
				$decoded = json_decode( $cell, true );
				if ( is_array( $decoded ) ) {
					$ids = $this->normalize_id_list( $decoded, $max );
				}
			}
		} elseif ( is_array( $cell ) ) {
			$ids = $this->normalize_id_list( $cell, $max );
		}

		return wp_json_encode( array_values( $ids ) );
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
		$default_ids   = isset( $field['default_ids'] ) && is_array( $field['default_ids'] ) ? $field['default_ids'] : array();
		$max           = isset( $field['max'] ) ? absint( $field['max'] ) : 0;

		$is_inner    = ( 'group_inner' === $context );
		$group_label = $title !== '' ? $title : $field_id;

		$row_classes = array( 'sto-field-row', 'sto-field-row-gallery' );
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

		$i18n = array(
			'frameTitle'  => __( 'Add images to gallery', 'topten-simple-theme-options' ),
			'frameButton' => __( 'Add to gallery', 'topten-simple-theme-options' ),
			'add'         => __( 'Add images', 'topten-simple-theme-options' ),
			'clearAll'    => __( 'Clear all images', 'topten-simple-theme-options' ),
			'removeOne'   => __( 'Remove image from gallery', 'topten-simple-theme-options' ),
			'empty'       => __( 'No images selected', 'topten-simple-theme-options' ),
			/* translators: %d: image count */
			'count'       => __( '%d image selected', 'topten-simple-theme-options' ),
			/* translators: %d: image count */
			'countPlural' => __( '%d images selected', 'topten-simple-theme-options' ),
		);

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
				<?php FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_inner, $toolbar_markup ); ?>
			<?php endif; ?>

			<?php if ( $tabs_pane_bp !== '' && $bps_storage ) : ?>
				<?php
				$value_map = $this->get_value_map( $field_id, $default_ids, $bps_storage );
				$json      = isset( $value_map[ $tabs_pane_bp ] ) ? (string) $value_map[ $tabs_pane_bp ] : wp_json_encode( $default_ids );
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$id_suffix  = $field_id . '_' . $tabs_pane_bp;
				$this->render_gallery_widget( $id_suffix, $input_name, $json, $max, $group_label . ' — ' . strtoupper( $tabs_pane_bp ), $i18n );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $default_ids, $bps_storage );
					foreach ( $bps_storage as $i => $bp ) :
						$bp         = sanitize_key( (string) $bp );
						$visible    = ( 0 === (int) $i );
						$json       = isset( $value_map[ $bp ] ) ? (string) $value_map[ $bp ] : wp_json_encode( $default_ids );
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$id_suffix  = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_gallery_widget( $id_suffix, $input_name, $json, $max, $group_label . ' — ' . strtoupper( $bp ), $i18n );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$json       = $this->get_merged_json( $field_id, $default_ids );
				$input_name = 'sto_options[' . $field_id . ']';
				$this->render_gallery_widget( $field_id, $input_name, $json, $max, $group_label, $i18n );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string               $field_id
	 * @param array<int, string>   $default_ids
	 * @param array<int, string>   $breakpoints
	 * @return array<string, string> Breakpoint => JSON
	 */
	private function get_value_map( $field_id, array $default_ids, array $breakpoints ) {
		$default_json = wp_json_encode( $default_ids );
		$saved        = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			$map = ResponsiveConfig::coerce_map( null, $breakpoints, $default_json );
		} else {
			$stored = $saved[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$map = ResponsiveConfig::coerce_map( $stored, $breakpoints, $default_json );
			} else {
				$scalar = is_string( $stored ) ? $this->sanitize_stored_value( $stored ) : $default_json;
				$map    = ResponsiveConfig::coerce_map( null, $breakpoints, $scalar );
			}
		}
		$max = isset( $this->fields_by_id[ $field_id ]['max'] ) ? absint( $this->fields_by_id[ $field_id ]['max'] ) : 0;
		foreach ( $map as $bp => $json_str ) {
			$ids = json_decode( (string) $json_str, true );
			$map[ $bp ] = wp_json_encode( $this->normalize_id_list( is_array( $ids ) ? $ids : array(), $max ) );
		}

		return $map;
	}

	/**
	 * @param string             $field_id
	 * @param array<int, string> $default_ids
	 * @return string JSON
	 */
	private function get_merged_json( $field_id, array $default_ids ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			return wp_json_encode( $default_ids );
		}
		$stored = $saved[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$slice = ResponsiveConfig::value_for_required_eval( $stored );

			return is_string( $slice ) ? $this->sanitize_stored_value( $slice ) : wp_json_encode( $default_ids );
		}

		return $this->sanitize_stored_value( is_string( $stored ) ? $stored : '' );
	}

	/**
	 * @param array<string, string> $i18n
	 */
	private function render_gallery_widget( $id_suffix, $input_name, $json_value, $max, $group_label, array $i18n ) {
		$ids = json_decode( (string) $json_value, true );
		$ids = $this->normalize_id_list( is_array( $ids ) ? $ids : array(), $max );
		$n   = count( $ids );
		$count_label = $n === 0
			? (string) $i18n['empty']
			: (string) ( $n === 1 ? sprintf( $i18n['count'], $n ) : sprintf( $i18n['countPlural'], $n ) );

		$wrap_id = 'sto-gallery-' . $id_suffix;
		?>
		<div
			class="sto-gallery"
			id="<?php echo esc_attr( $wrap_id ); ?>"
			data-sto-gallery="1"
			data-sto-gallery-max="<?php echo esc_attr( (string) ( $max > 0 ? $max : 0 ) ); ?>"
			data-sto-gallery-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>"
			aria-label="<?php echo esc_attr( $group_label ); ?>"
		>
			<div class="sto-gallery__chrome">
				<div class="sto-gallery__head">
					<span class="sto-gallery__count" data-sto-gallery-count><?php echo esc_html( $count_label ); ?></span>
					<div class="sto-gallery__head-actions">
						<button
							type="button"
							class="sto-gallery__clear"
							data-sto-gallery-clear
							<?php echo $n < 1 ? ' hidden' : ''; ?>
							aria-label="<?php echo esc_attr( $i18n['clearAll'] ); ?>"
							title="<?php echo esc_attr( $i18n['clearAll'] ); ?>"
						><i class="fa-light fa-trash-can" aria-hidden="true"></i></button>
					</div>
				</div>
				<div class="sto-gallery__body">
					<ul class="sto-gallery__list" data-sto-gallery-list>
						<li class="sto-gallery__slot sto-gallery__slot--add">
							<button
								type="button"
								class="sto-gallery__add"
								data-sto-gallery-add
								aria-label="<?php echo esc_attr( $i18n['add'] ); ?>"
								title="<?php echo esc_attr( $i18n['add'] ); ?>"
							>
								<span class="sto-gallery__add-ring" aria-hidden="true"><span class="sto-gallery__add-plus">+</span></span>
							</button>
						</li>
						<?php foreach ( $ids as $aid ) : ?>
							<?php
							$aid_int = absint( $aid );
							$thumb   = '';
							if ( $aid_int && wp_attachment_is_image( $aid_int ) ) {
								$t = wp_get_attachment_image_src( $aid_int, 'thumbnail' );
								if ( is_array( $t ) && ! empty( $t[0] ) ) {
									$thumb = (string) $t[0];
								}
							}
							?>
							<li class="sto-gallery__slot sto-gallery__slot--thumb" data-sto-gallery-item="<?php echo esc_attr( (string) $aid_int ); ?>">
								<span class="sto-gallery__thumb-wrap">
									<img src="<?php echo $thumb !== '' ? esc_url( $thumb ) : ''; ?>" alt="" class="sto-gallery__thumb" width="80" height="80" loading="lazy" decoding="async" />
								</span>
								<button
									type="button"
									class="sto-gallery__remove"
									data-sto-gallery-remove
									aria-label="<?php echo esc_attr( $i18n['removeOne'] ); ?>"
								><i class="fa-light fa-xmark" aria-hidden="true"></i></button>
							</li>
						<?php endforeach; ?>
					</ul>
					<input type="hidden" class="sto-gallery-value" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( wp_json_encode( $ids ) ); ?>" autocomplete="off" />
				</div>
			</div>
		</div>
		<?php
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
				__( '“%s” must be filled in before this section can be saved.', 'topten-simple-theme-options' ),
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

			return $this->ids_json_is_empty( is_string( $slice ) ? $slice : '' );
		}

		return $this->ids_json_is_empty( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) );
	}

	private function ids_json_is_empty( $json_str ) {
		$ids = json_decode( (string) $json_str, true );
		if ( ! is_array( $ids ) ) {
			return true;
		}
		$ids = array_filter( array_map( 'absint', $ids ) );

		return empty( $ids );
	}

	/**
	 * Ordered attachment IDs for a registered gallery field (theme use).
	 *
	 * @param string      $field_id       Option key.
	 * @param string|null $json_or_scalar When non-null, decode this JSON instead of reading options.
	 * @return array<int, int>
	 */
	public function get_attachment_ids_for_field( $field_id, $json_or_scalar = null ) {
		$field_id = sanitize_key( (string) $field_id );
		if ( $field_id === '' || ! isset( $this->fields_by_id[ $field_id ] ) ) {
			return array();
		}
		$field = $this->fields_by_id[ $field_id ];
		$max   = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		$raw   = '';
		if ( null !== $json_or_scalar && is_string( $json_or_scalar ) ) {
			$raw = $json_or_scalar;
		} else {
			$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
			if ( ! is_array( $opts ) || ! array_key_exists( $field_id, $opts ) ) {
				return array();
			}
			$stored = $opts[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$slice = ResponsiveConfig::value_for_required_eval( $stored );
				$raw   = is_string( $slice ) ? $slice : '';
			} else {
				$raw = is_string( $stored ) ? $stored : '';
			}
		}
		$list = json_decode( (string) $raw, true );
		$out  = $this->normalize_id_list( is_array( $list ) ? $list : array(), $max );

		return array_map( 'absint', $out );
	}
}
