<?php
namespace SimpleThemeOptions\Admin\Options\Fields\ImageSelect;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class ImageSelect {
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
		// Before plain Select (18) so standalone image rows can appear first when booted first.
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), 17, 2 );
	}

	/**
	 * @param array<string, mixed> $field Keys: section_slug, id, title?, description?, default?, required?, wrapper_class?, group?,
	 * options (value => label string or array label/preset/image), optional **responsive** (`true` or non-empty array), optional **`device`** => breakpoint slug list to limit tabs,
	 * optional **`columns`** => int 1–12 (same count on every viewport) **or** keyed map limited to `xxl` (desktop / default), `md` (≤991px), `mobile` (≤600px); missing keys cascade up.
	 * Each option tile always shows a hover/focus text tooltip with the option **`label`** (so admin can identify thumbs when columns are narrow).
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

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['default']       = isset( $field['default'] ) ? (string) $field['default'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['options']       = $options;
		$field['columns_map']   = $this->normalize_columns( $field['columns'] ?? null );
		$field['show_labels']   = ! array_key_exists( 'show_labels', $field ) || (bool) $field['show_labels'];

		$bps = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]           = true;
		$this->fields_by_id[ $field_id ]             = $field;
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
		$keys    = array_keys( $options );
		$bps     = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;

		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->coerce_value( is_scalar( $cell ) ? (string) $cell : '', $keys );
			}

			return $out;
		}

		return $this->coerce_value( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ), $keys );
	}

	/**
	 * @param array<string, string|array<string, mixed>> $raw
	 * @return array<string, array{label: string, preset: string, image: string}>
	 */
	private function normalize_options( $raw ) {
		$out = array();
		foreach ( $raw as $value => $meta ) {
			$key = sanitize_key( (string) $value );
			if ( $key === '' ) {
				continue;
			}
			if ( is_string( $meta ) ) {
				$out[ $key ] = array(
					'label'  => (string) $meta,
					'preset' => $this->guess_preset_from_value( $key ),
					'image'  => '',
				);
				continue;
			}
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$label  = isset( $meta['label'] ) ? (string) $meta['label'] : $key;
			$image  = ! empty( $meta['image'] ) ? esc_url_raw( (string) $meta['image'] ) : '';
			$preset = isset( $meta['preset'] ) ? sanitize_key( (string) $meta['preset'] ) : '';
			if ( $preset === '' ) {
				$preset = $image !== '' ? 'custom' : $this->guess_preset_from_value( $key );
			}
			if ( ! $this->is_allowed_preset( $preset ) && $image === '' ) {
				$preset = 'full';
			}
			$out[ $key ] = array(
				'label'  => $label,
				'preset' => $preset,
				'image'  => $image,
			);
		}

		return $out;
	}

	private function guess_preset_from_value( $key ) {
		if ( in_array( $key, array( 'none', 'full', 'fullwidth', 'full_width' ), true ) ) {
			return 'full';
		}
		if ( in_array( $key, array( 'left', 'sidebar_left', 'sidebar-left' ), true ) ) {
			return 'sidebar_left';
		}
		if ( in_array( $key, array( 'right', 'sidebar_right', 'sidebar-right' ), true ) ) {
			return 'sidebar_right';
		}

		return 'full';
	}

	private function is_allowed_preset( $preset ) {
		return in_array(
			$preset,
			array( 'full', 'sidebar_left', 'sidebar_right', 'custom' ),
			true
		);
	}

	/**
	 * Normalize the optional **`columns`** config into a clamped map for the three admin-screen tiers
	 * used by **`sto-image-select.css`**: **`xxl`** (default / desktop), **`md`** (≤991px), **`mobile`** (≤600px).
	 * Larger / smaller canonical slugs (`xl`, `lg`, `sm`, `xs`, legacy `phone`) are collapsed onto the nearest tier.
	 *
	 * @param int|array<string, int|string>|null $raw Integer **1–12** for a single value on every tier, OR a keyed map.
	 * @return array{xxl: int, md: int, mobile: int}|null Null when no usable input was provided (CSS falls back to its built-in defaults).
	 */
	private function normalize_columns( $raw ) {
		$clamp = static function ( $n ) {
			$n = (int) $n;
			if ( $n < 1 ) {
				return 1;
			}
			if ( $n > 12 ) {
				return 12;
			}

			return $n;
		};

		if ( is_numeric( $raw ) ) {
			$n = $clamp( $raw );

			return array(
				'xxl'    => $n,
				'md'     => $n,
				'mobile' => $n,
			);
		}

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return null;
		}

		$buckets = array(
			'xxl'    => null,
			'md'     => null,
			'mobile' => null,
		);

		foreach ( $raw as $bp => $n ) {
			if ( ! is_numeric( $n ) ) {
				continue;
			}
			$bp = sanitize_key( (string) $bp );
			if ( 'phone' === $bp ) {
				$bp = 'mobile';
			}
			$tier = $this->columns_tier_for_breakpoint( $bp );
			if ( null === $tier ) {
				continue;
			}
			$buckets[ $tier ] = $clamp( $n );
		}

		if ( null === $buckets['xxl'] && null === $buckets['md'] && null === $buckets['mobile'] ) {
			return null;
		}

		// Cascade missing tiers from the next-larger one (xxl → md → mobile).
		if ( null === $buckets['md'] && null !== $buckets['xxl'] ) {
			$buckets['md'] = $buckets['xxl'];
		}
		if ( null === $buckets['mobile'] && null !== $buckets['md'] ) {
			$buckets['mobile'] = $buckets['md'];
		}
		// Backfill any remaining null upwards.
		if ( null === $buckets['xxl'] ) {
			$buckets['xxl'] = ( null !== $buckets['md'] ) ? $buckets['md'] : $buckets['mobile'];
		}
		if ( null === $buckets['md'] ) {
			$buckets['md'] = $buckets['xxl'];
		}
		if ( null === $buckets['mobile'] ) {
			$buckets['mobile'] = $buckets['md'];
		}

		return array(
			'xxl'    => (int) $buckets['xxl'],
			'md'     => (int) $buckets['md'],
			'mobile' => (int) $buckets['mobile'],
		);
	}

	/**
	 * Map any canonical breakpoint slug into one of the three admin-screen tiers used for columns.
	 */
	private function columns_tier_for_breakpoint( $bp ) {
		switch ( $bp ) {
			case 'xxl':
			case 'xl':
			case 'lg':
			case 'desktop':
				return 'xxl';
			case 'md':
			case 'tablet':
				return 'md';
			case 'sm':
			case 'xs':
			case 'mobile':
				return 'mobile';
		}

		return null;
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
	 * @param array<string, mixed> $field
	 * @param 'default'|'group_inner' $context
	 */
	public function render_field_markup( $field, $context = 'default' ) {
		if ( ! is_array( $field ) ) {
			return;
		}

		$field_id       = $field['id'];
		$title          = $field['title'];
		$description    = $field['description'];
		$default_value  = isset( $field['default'] ) ? (string) $field['default'] : '';
		$wrapper_class  = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required       = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json  = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$options        = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$tooltip        = FieldTitle::get_tooltip_config( $field );
		$bps_storage    = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp   = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$columns_map    = isset( $field['columns_map'] ) && is_array( $field['columns_map'] ) ? $field['columns_map'] : null;
		$show_labels    = ! array_key_exists( 'show_labels', $field ) || (bool) $field['show_labels'];

		$allowed_keys   = array_keys( $options );
		$is_group_inner = ( 'group_inner' === $context );
		$group_label    = $title !== '' ? $title : $field_id;

		$row_classes = array( 'sto-field-row', 'sto-field-row-image-select' );
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
		if ( null !== $columns_map ) {
			$row_classes[] = 'sto-field-row-image-select--has-columns';
		}
		if ( ! $show_labels ) {
			$row_classes[] = 'sto-field-row-image-select--no-labels';
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
				<?php FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_group_inner, $toolbar_markup ); ?>
			<?php endif; ?>

			<?php if ( $tabs_pane_bp !== '' && $bps_storage ) : ?>
				<?php
				$value_map = $this->get_value_map( $field_id, $default_value, $allowed_keys, $bps_storage );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? $value_map[ $tabs_pane_bp ] : $default_value;
				$cur       = $this->coerce_value( (string) $cur, $allowed_keys );
				$input_name  = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$id_fragment = $field_id . '_' . $tabs_pane_bp;
				$this->render_image_select_radios( $options, $cur, $input_name, $id_fragment, $group_label . ' — ' . strtoupper( $tabs_pane_bp ), $columns_map, $show_labels );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $default_value, $allowed_keys, $bps_storage );
					foreach ( $bps_storage as $i => $bp ) :
						$bp          = sanitize_key( (string) $bp );
						$visible     = ( 0 === (int) $i );
						$cur         = isset( $value_map[ $bp ] ) ? $value_map[ $bp ] : $default_value;
						$cur         = $this->coerce_value( (string) $cur, $allowed_keys );
						$input_name  = 'sto_options[' . $field_id . '][' . $bp . ']';
						$id_fragment = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_image_select_radios( $options, $cur, $input_name, $id_fragment, $group_label . ' — ' . strtoupper( $bp ), $columns_map, $show_labels );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$current_value = $this->get_option_value( $field_id, $default_value, $allowed_keys );
				if ( ! isset( $options[ $current_value ] ) && ! empty( $options ) ) {
					$current_value = (string) array_key_first( $options );
				}
				$input_name = 'sto_options[' . $field_id . ']';
				$this->render_image_select_radios( $options, $current_value, $input_name, $field_id, $group_label, $columns_map, $show_labels );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, array<string, string>>           $options
	 * @param string                                         $current_value
	 * @param string                                         $input_name
	 * @param string                                         $id_fragment Prefix for input ids (unique per breakpoint when responsive).
	 * @param string                                         $group_label
	 * @param array{xxl: int, md: int, mobile: int}|null     $columns_map Inline CSS-grid column count per admin-screen tier, or null for CSS defaults.
	 * @param bool                                           $show_labels Whether to print the visible label under each thumb (hover/focus tooltip is always added).
	 */
	private function render_image_select_radios( array $options, $current_value, $input_name, $id_fragment, $group_label, $columns_map = null, $show_labels = true ) {
		$style = '';
		if ( is_array( $columns_map ) ) {
			$parts = array();
			foreach ( array( 'xxl', 'md', 'mobile' ) as $tier ) {
				if ( isset( $columns_map[ $tier ] ) ) {
					$parts[] = '--sto-img-cols-' . $tier . ': ' . (int) $columns_map[ $tier ];
				}
			}
			if ( ! empty( $parts ) ) {
				$style = implode( '; ', $parts ) . ';';
			}
		}
		$grid_classes = array( 'sto-image-select' );
		if ( ! $show_labels ) {
			$grid_classes[] = 'sto-image-select--no-labels';
		}
		?>
			<div
				class="<?php echo esc_attr( implode( ' ', $grid_classes ) ); ?>"
				role="radiogroup"
				aria-label="<?php echo esc_attr( $group_label ); ?>"
				<?php if ( $style !== '' ) : ?>
					style="<?php echo esc_attr( $style ); ?>"
				<?php endif; ?>
			>
				<?php foreach ( $options as $value => $meta ) : ?>
					<?php
					$opt_label = isset( $meta['label'] ) ? (string) $meta['label'] : (string) $value;
					$preset    = isset( $meta['preset'] ) ? (string) $meta['preset'] : 'full';
					$image     = isset( $meta['image'] ) ? (string) $meta['image'] : '';
					$rid       = 'sto-image-select-' . $id_fragment . '-' . $value;
					$checked   = ( (string) $current_value === (string) $value );
					?>
					<label
						class="sto-image-select__item"
						for="<?php echo esc_attr( $rid ); ?>"
						data-sto-text-tip="<?php echo esc_attr( $opt_label ); ?>"
					>
						<input
							type="radio"
							id="<?php echo esc_attr( $rid ); ?>"
							name="<?php echo esc_attr( $input_name ); ?>"
							value="<?php echo esc_attr( (string) $value ); ?>"
							class="sto-image-select__input"
							data-sto-image-select-input
							aria-label="<?php echo esc_attr( $opt_label ); ?>"
							<?php checked( $checked ); ?>
						/>
						<span class="sto-image-select__card">
							<span class="sto-image-select__thumb" aria-hidden="true">
								<?php if ( $image !== '' ) : ?>
									<img src="<?php echo esc_url( $image ); ?>" alt="" class="sto-image-select__img" loading="lazy" decoding="async" />
								<?php else : ?>
									<?php
									echo wp_kses(
										$this->render_diagram_markup( $preset ),
										array(
											'span' => array(
												'class'            => true,
												'data-sto-preset' => true,
											),
										)
									);
									?>
								<?php endif; ?>
							</span>
							<?php if ( $show_labels ) : ?>
								<span class="sto-image-select__label"><?php echo esc_html( $opt_label ); ?></span>
							<?php endif; ?>
						</span>
					</label>
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
				return $this->coerce_value( ResponsiveConfig::value_for_required_eval( $st ), $allowed_keys );
			}

			return $this->coerce_value( (string) $st, $allowed_keys );
		}

		return $this->coerce_value( $default_value, $allowed_keys );
	}

	/**
	 * @param array<int, string> $allowed_keys
	 */
	private function coerce_value( $value, $allowed_keys ) {
		$v = sanitize_key( (string) $value );
		if ( $v && in_array( $v, $allowed_keys, true ) ) {
			return $v;
		}
		if ( ! empty( $allowed_keys ) ) {
			return (string) $allowed_keys[0];
		}

		return $v;
	}

	/**
	 * Minimal layout wireframe (gray content / white sidebar).
	 *
	 * @param string $preset full|sidebar_left|sidebar_right|custom
	 * @return string
	 */
	private function render_diagram_markup( $preset ) {
		$preset = sanitize_key( (string) $preset );
		if ( $preset === 'sidebar_left' ) {
			return '<span class="sto-image-select__diagram" data-sto-preset="sidebar_left"><span class="sto-image-select__bar sto-image-select__bar--sidebar"></span><span class="sto-image-select__bar sto-image-select__bar--content"></span></span>';
		}
		if ( $preset === 'sidebar_right' ) {
			return '<span class="sto-image-select__diagram" data-sto-preset="sidebar_right"><span class="sto-image-select__bar sto-image-select__bar--content"></span><span class="sto-image-select__bar sto-image-select__bar--sidebar"></span></span>';
		}

		return '<span class="sto-image-select__diagram" data-sto-preset="full"><span class="sto-image-select__bar sto-image-select__bar--solo"></span></span>';
	}
}