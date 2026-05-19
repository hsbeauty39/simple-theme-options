<?php
/**
 * Rich modern editor field — WordPress block editor (Gutenberg) inside Theme Settings.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Options\Fields\RichModernEditor;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSpacing;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\PremiumFieldGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Admin\ThemeSettingsMetabox;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Block-based rich text editor for STO admin panels.
 *
 * Stores serialized block markup (HTML comments) in `sto_options[id]` or per-breakpoint maps.
 */
final class RichModernEditor {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	/** @var int Max stored payload (512 KB). */
	private const MAX_BYTES = 524288;

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

	/** @var bool */
	private static $block_editor_assets_enqueued = false;

	protected function init() {
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::RICH_MODERN_EDITOR, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_block_editor_assets' ), 9 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'maybe_enqueue_block_editor_assets_block' ), 9 );
	}

	/**
	 * Register a rich modern editor field.
	 *
	 * Keys: section_slug, id, title?, description?, default? (string), placeholder?,
	 *       editor_height? (px, default 320), media_upload? (bool, default true),
	 *       allowed_blocks? (string[]|true — true = all core blocks),
	 *       responsive?, device?, wrapper_class?, required?, html_required?, group?,
	 *       tooltip?, tooltip_image?, tooltip_preloader?
	 *
	 * @param array<string, mixed> $field Field config.
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
	 * @param array<int, array<string, mixed>> $fields Field configs.
	 */
	public static function register_many( $fields ) {
		$instance = static::instance();
		FieldRegistrationDeferral::defer_or_run(
			static function () use ( $instance, $fields ) {
				if ( ! is_array( $fields ) ) {
					return;
				}

				foreach ( $fields as $field_config ) {
					$instance->register_field_config( $field_config );
				}
			}
		);
	}

	/**
	 * @param mixed $field Raw field config.
	 */
	private function register_field_config( $field ): void {
		if ( ! is_array( $field ) ) {
			return;
		}

		FieldSpacing::normalize_config( $field );

		$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
		$field_id     = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';

		if ( $section_slug === '' || $field_id === '' ) {
			return;
		}

		$editor_height = isset( $field['editor_height'] ) && is_numeric( $field['editor_height'] ) ? (int) $field['editor_height'] : 320;
		if ( $editor_height < 160 ) {
			$editor_height = 160;
		}
		if ( $editor_height > 1200 ) {
			$editor_height = 1200;
		}

		$allowed_blocks = true;
		if ( array_key_exists( 'allowed_blocks', $field ) ) {
			if ( is_array( $field['allowed_blocks'] ) ) {
				$allowed_blocks = array_values(
					array_filter(
						array_map(
							static function ( $block_name ) {
								return sanitize_text_field( (string) $block_name );
							},
							$field['allowed_blocks']
						)
					)
				);
			} else {
				$allowed_blocks = (bool) $field['allowed_blocks'];
			}
		}

		$field['section_slug']              = $section_slug;
		$field['id']                        = $field_id;
		$field['title']                     = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']               = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['placeholder']               = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$field['default']                   = isset( $field['default'] ) && is_scalar( $field['default'] ) ? (string) $field['default'] : '';
		$field['wrapper_class']             = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']                  = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['html_required']             = ! empty( $field['html_required'] );
		$field['group']                     = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['editor_height']             = $editor_height;
		$field['media_upload']              = ! array_key_exists( 'media_upload', $field ) || (bool) $field['media_upload'];
		$field['allowed_blocks']            = $allowed_blocks;
		$field['responsive_breakpoints']    = ResponsiveConfig::breakpoints_for_field( $field );

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]            = $field;
	}

	/**
	 * @param string $section_slug Section slug.
	 * @return array<int, string>
	 */
	public function registry_get_field_ids_for_section( $section_slug ) {
		$section_slug = sanitize_key( (string) $section_slug );
		if ( $section_slug === '' || empty( $this->fields_by_section[ $section_slug ] ) ) {
			return array();
		}

		$field_ids = array();
		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			$field_id = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			if ( $field_id !== '' ) {
				$field_ids[] = $field_id;
			}
		}

		return array_values( array_unique( $field_ids ) );
	}

	/**
	 * @param string $field_id Field id.
	 */
	public function registry_is_registered_field_id( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );

		return $field_id !== '' && isset( $this->registered_ids[ $field_id ] );
	}

	/**
	 * @param string $field_id Field id.
	 * @return array<int, string>|null
	 */
	public function get_responsive_breakpoints( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;

		return is_array( $field ) ? ( $field['responsive_breakpoints'] ?? null ) : null;
	}

	/**
	 * @param string               $section_slug  Leaf section slug.
	 * @param array<string, mixed> $option_values Merged preview of sto_options.
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

			$field_option_key = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			if ( $field_option_key === '' ) {
				continue;
			}

			$row_value_raw = array_key_exists( $field_option_key, $option_values ) ? $option_values[ $field_option_key ] : null;
			if ( $this->field_value_is_nonempty_for_required( $field, $row_value_raw ) ) {
				continue;
			}

			$heading_title     = isset( $field['title'] ) ? trim( (string) $field['title'] ) : '';
			$error_field_label = $heading_title !== '' ? $heading_title : $field_option_key;

			$messages[] = sprintf(
				/* translators: %s: field label */
				__( '“%s” must be filled in before this section can be saved.', 'topten-simple-theme-options' ),
				$error_field_label
			);
		}

		return $messages;
	}

	/**
	 * @param array<string, mixed> $field     Registered field.
	 * @param mixed                $raw_value Stored scalar or breakpoint map.
	 */
	private function field_value_is_nonempty_for_required( array $field, $raw_value ): bool {
		if ( is_array( $raw_value ) && ResponsiveConfig::is_breakpoint_value_map( $raw_value ) ) {
			$breakpoints_for_field = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] )
				? $field['responsive_breakpoints']
				: array();

			foreach ( $breakpoints_for_field as $breakpoint_key ) {
				$breakpoint_key      = sanitize_key( (string) $breakpoint_key );
				$breakpoint_cell_raw = isset( $raw_value[ $breakpoint_key ] ) ? $raw_value[ $breakpoint_key ] : '';
				if ( $this->stored_value_has_meaningful_content( is_scalar( $breakpoint_cell_raw ) ? (string) $breakpoint_cell_raw : '' ) ) {
					return true;
				}
			}

			return false;
		}

		return $this->stored_value_has_meaningful_content( is_scalar( $raw_value ) ? (string) $raw_value : '' );
	}

	/**
	 * @param string $stored_value Serialized block markup or legacy HTML.
	 */
	private function stored_value_has_meaningful_content( string $stored_value ): bool {
		$stored_value = trim( $stored_value );
		if ( $stored_value === '' ) {
			return false;
		}

		if ( function_exists( 'parse_blocks' ) ) {
			$parsed_blocks = parse_blocks( $stored_value );
			if ( is_array( $parsed_blocks ) ) {
				foreach ( $parsed_blocks as $parsed_block ) {
					if ( ! is_array( $parsed_block ) ) {
						continue;
					}
					$inner_html = isset( $parsed_block['innerHTML'] ) ? trim( (string) $parsed_block['innerHTML'] ) : '';
					if ( $inner_html !== '' ) {
						return true;
					}
					if ( ! empty( $parsed_block['innerBlocks'] ) && is_array( $parsed_block['innerBlocks'] ) ) {
						return true;
					}
				}

				return false;
			}
		}

		return trim( wp_strip_all_tags( $stored_value ) ) !== '';
	}

	/**
	 * @param string $field_id Field id.
	 * @param mixed  $raw      Posted value.
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return '';
		}

		$breakpoints = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] )
			? $field['responsive_breakpoints']
			: null;

		if ( ! empty( $breakpoints ) && is_array( $raw ) ) {
			$sanitized_map = array();
			foreach ( $breakpoints as $breakpoint_key ) {
				$breakpoint_key = sanitize_key( (string) $breakpoint_key );
				$cell_raw       = isset( $raw[ $breakpoint_key ] ) ? $raw[ $breakpoint_key ] : '';
				$sanitized_map[ $breakpoint_key ] = $this->sanitize_stored_value(
					is_scalar( $cell_raw ) ? (string) $cell_raw : '',
					$field
				);
			}

			return $sanitized_map;
		}

		return $this->sanitize_stored_value( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ), $field );
	}

	/**
	 * @param string               $raw   Posted markup.
	 * @param array<string, mixed> $field Field config.
	 */
	public function sanitize_stored_value( string $raw, array $field ): string {
		unset( $field );

		$raw = str_replace( "\0", '', $raw );
		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );

		if ( strlen( $raw ) > self::MAX_BYTES ) {
			$raw = substr( $raw, 0, self::MAX_BYTES );
		}

		if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$parsed_blocks = parse_blocks( $raw );
			if ( is_array( $parsed_blocks ) ) {
				return (string) serialize_blocks( $parsed_blocks );
			}
		}

		return (string) wp_kses_post( wp_unslash( $raw ) );
	}

	/**
	 * @param string               $section_slug Section slug.
	 * @param array<string, mixed> $section      Section config (unused).
	 */
	public function render_section_fields( $section_slug, $section ) {
		unset( $section );

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
	 * @param string $section_slug Section slug.
	 * @param string $field_id     Field id.
	 * @return array<string, mixed>|null
	 */
	public function registry_get_field( $section_slug, $field_id ) {
		$section_slug = sanitize_key( (string) $section_slug );
		$field_id     = sanitize_key( (string) $field_id );
		if ( $section_slug === '' || $field_id === '' || empty( $this->fields_by_section[ $section_slug ] ) ) {
			return null;
		}

		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			$registered_id = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			if ( $registered_id === $field_id ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public function registry_get_all_fields_for_search() {
		$search_rows = array();

		foreach ( $this->fields_by_section as $section_slug => $fields ) {
			foreach ( $fields as $field ) {
				$title = isset( $field['title'] ) ? trim( (string) $field['title'] ) : '';
				if ( $title === '' ) {
					continue;
				}

				$search_rows[] = array(
					'section_slug' => (string) $section_slug,
					'id'           => isset( $field['id'] ) ? (string) $field['id'] : '',
					'title'        => $title,
					'group'        => ! empty( $field['group'] ) ? (string) $field['group'] : '',
				);
			}
		}

		return $search_rows;
	}

	/**
	 * @param array<string, mixed>    $field   Field config.
	 * @param 'default'|'group_inner' $context Render context.
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
		$breakpoints   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] )
			? $field['responsive_breakpoints']
			: null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$is_inner      = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-rich-modern-editor' );
		if ( $wrapper_class !== '' ) {
			$row_classes[] = $wrapper_class;
		}
		if ( $is_inner ) {
			$row_classes[] = 'sto-field-row--in-group';
		}
		if ( $tabs_pane_bp !== '' ) {
			$row_classes[] = 'sto-field-row--tabs-pane-slice';
		}
		if ( ! empty( $breakpoints ) && $tabs_pane_bp === '' ) {
			$row_classes[] = 'sto-field-row--responsive';
		}

		$eval_breakpoint = ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT;
		if ( ! empty( $breakpoints ) && ! in_array( $eval_breakpoint, $breakpoints, true ) ) {
			$eval_breakpoint = (string) $breakpoints[0];
		}
		$toolbar_markup = ( $tabs_pane_bp === '' && ! empty( $breakpoints ) )
			? ResponsiveControl::toolbar_markup( $breakpoints, $field_id )
			: '';
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
			<?php if ( ! empty( $breakpoints ) && $tabs_pane_bp === '' ) : ?>
				data-sto-responsive="1"
				data-sto-active-bp="<?php echo esc_attr( (string) $breakpoints[0] ); ?>"
				data-sto-require-eval-bp="<?php echo esc_attr( $eval_breakpoint ); ?>"
			<?php endif; ?>
		>
			<?php if ( $title !== '' || $toolbar_markup !== '' ) : ?>
				<?php FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_inner, $toolbar_markup ); ?>
			<?php endif; ?>

			<?php if ( PremiumFieldGate::render_controls_or_locked_placeholder( $title, 'rich_modern_editor' ) ) : ?>
			<?php elseif ( $tabs_pane_bp !== '' && $breakpoints ) : ?>
				<?php
				$value_map  = $this->get_value_map( $field_id, $breakpoints, $field );
				$current    = isset( $value_map[ $tabs_pane_bp ] ) ? (string) $value_map[ $tabs_pane_bp ] : (string) $field['default'];
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$suffix     = $field_id . '_' . $tabs_pane_bp;
				$this->render_editor_control( $suffix, $input_name, $current, $field );
				?>
			<?php elseif ( ! empty( $breakpoints ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $breakpoints, $field );
					foreach ( $breakpoints as $index => $breakpoint_key ) :
						$breakpoint_key = sanitize_key( (string) $breakpoint_key );
						$is_visible     = ( 0 === (int) $index );
						$current        = isset( $value_map[ $breakpoint_key ] ) ? (string) $value_map[ $breakpoint_key ] : (string) $field['default'];
						$input_name     = 'sto_options[' . $field_id . '][' . $breakpoint_key . ']';
						$suffix         = $field_id . '_' . $breakpoint_key;
						ResponsiveControl::render_pane_start( $breakpoint_key, $is_visible );
						$this->render_editor_control( $suffix, $input_name, $current, $field );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$current = $this->get_option_value( $field_id, (string) $field['default'] );
				$this->render_editor_control( $field_id, 'sto_options[' . $field_id . ']', $current, $field );
				?>
			<?php endif; ?>

			<?php if ( $description !== '' && ! PremiumFieldGate::is_locked() ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string               $suffix     Unique DOM suffix.
	 * @param string               $input_name Posted input name.
	 * @param string               $value      Current stored markup.
	 * @param array<string, mixed> $field      Field config.
	 */
	private function render_editor_control( string $suffix, string $input_name, string $value, array $field ): void {
		$textarea_id    = 'sto-rich-modern-editor-' . $suffix;
		$editor_height  = (int) $field['editor_height'];
		$placeholder    = (string) $field['placeholder'];
		$media_upload   = ! empty( $field['media_upload'] );
		$allowed_blocks = $field['allowed_blocks'];
		$allowed_json   = wp_json_encode( $allowed_blocks );
		$html_required  = ! empty( $field['html_required'] );
		?>
		<div
			class="sto-rich-modern-editor"
			data-sto-rich-modern-editor="1"
			data-sto-editor-height="<?php echo esc_attr( (string) $editor_height ); ?>"
			data-sto-media-upload="<?php echo esc_attr( $media_upload ? '1' : '0' ); ?>"
			<?php if ( is_string( $allowed_json ) ) : ?>
				data-sto-allowed-blocks="<?php echo esc_attr( $allowed_json ); ?>"
			<?php endif; ?>
			style="--sto-rich-modern-editor-min-height: <?php echo esc_attr( (string) $editor_height ); ?>px;"
		>
			<div class="sto-rich-modern-editor__mount" aria-label="<?php esc_attr_e( 'Block editor', 'topten-simple-theme-options' ); ?>"></div>
			<textarea
				id="<?php echo esc_attr( $textarea_id ); ?>"
				class="sto-rich-modern-editor__input"
				name="<?php echo esc_attr( $input_name ); ?>"
				rows="8"
				<?php if ( $placeholder !== '' ) : ?>
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
				<?php endif; ?>
				<?php if ( $html_required ) : ?>
					required
				<?php endif; ?>
			><?php echo esc_textarea( $value ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * @param string               $field_id    Field id.
	 * @param array<int, string>   $breakpoints Breakpoint keys.
	 * @param array<string, mixed> $field       Field config.
	 * @return array<string, string>
	 */
	private function get_value_map( string $field_id, array $breakpoints, array $field ): array {
		$value_map = array();
		$stored    = OptionsMenu::get_option_value_for_field( $field_id );

		if ( is_array( $stored ) ) {
			foreach ( $breakpoints as $breakpoint_key ) {
				$breakpoint_key = sanitize_key( (string) $breakpoint_key );
				$value_map[ $breakpoint_key ] = isset( $stored[ $breakpoint_key ] )
					? (string) $stored[ $breakpoint_key ]
					: (string) $field['default'];
			}

			return $value_map;
		}

		$fallback = is_scalar( $stored ) ? (string) $stored : (string) $field['default'];
		foreach ( $breakpoints as $breakpoint_key ) {
			$breakpoint_key              = sanitize_key( (string) $breakpoint_key );
			$value_map[ $breakpoint_key ] = $fallback;
		}

		return $value_map;
	}

	/**
	 * @param string $field_id     Field id.
	 * @param string $default_value Default markup.
	 */
	private function get_option_value( string $field_id, string $default_value ): string {
		$stored = OptionsMenu::get_option_value_for_field( $field_id );
		if ( is_scalar( $stored ) ) {
			return (string) $stored;
		}

		return $default_value;
	}

	/**
	 * @param mixed $hook_suffix Admin hook suffix.
	 */
	public function maybe_enqueue_block_editor_assets( $hook_suffix = '' ): void {
		if ( empty( $this->fields_by_id ) || ! $this->should_prime_for_screen( is_string( $hook_suffix ) ? $hook_suffix : '' ) ) {
			return;
		}

		$this->enqueue_block_editor_packages();
	}

	public function maybe_enqueue_block_editor_assets_block(): void {
		if ( empty( $this->fields_by_id ) ) {
			return;
		}

		$this->enqueue_block_editor_packages();
	}

	private function should_prime_for_screen( string $hook_suffix ): bool {
		if ( $hook_suffix !== '' && in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return true;
		}

		if ( class_exists( OptionsMenu::class ) && OptionsMenu::instance()->is_theme_settings_admin_screen( $hook_suffix ) ) {
			return true;
		}

		if ( class_exists( ThemeSettingsMetabox::class ) && ThemeSettingsMetabox::instance()->is_metabox_admin_screen( $hook_suffix ) ) {
			return true;
		}

		return false;
	}

	private function enqueue_block_editor_packages(): void {
		if ( self::$block_editor_assets_enqueued ) {
			return;
		}

		self::$block_editor_assets_enqueued = true;

		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		$scripts = array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-data', 'wp-compose', 'wp-hooks', 'wp-block-editor', 'wp-block-library', 'wp-format-library', 'wp-rich-text', 'wp-keycodes' );
		foreach ( $scripts as $script_handle ) {
			if ( wp_script_is( $script_handle, 'registered' ) ) {
				wp_enqueue_script( $script_handle );
			}
		}

		$styles = array( 'wp-components', 'wp-block-editor', 'wp-edit-blocks' );
		foreach ( $styles as $style_handle ) {
			if ( wp_style_is( $style_handle, 'registered' ) ) {
				wp_enqueue_style( $style_handle );
			}
		}
	}
}
