<?php
namespace SimpleThemeOptions\Admin\Options\Fields\AdvancedRepeaterControl;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSpacing;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\PremiumFieldGate;
use SimpleThemeOptions\Admin\Options\Fields\IconSelect\IconSelect;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * **Advanced repeater** — ordered **items** (JSON in **`sto_options[id]`**), each item a map of **logical sub-keys**
 * built from a **schema** (`fields`). Supports **nested repeaters**, **`fieldset`** grouping (nested object), and
 * scalar leaves: **`text`**, **`number`**, **`textarea`**, **`editor`** (classic `wp_editor` — not inside repeaters on WooCommerce Product data), **`select`**, **`switcher`**, **`icon_select`**. Admin: **Add item**, **drag**
 * reorder (**jQuery UI Sortable**), **collapse / expand** per item, **remove** row. New rows match the same **collapsed /
 * expanded** default as the initial markup. Optional **`default_collapsed`** (bool) on **`register()`** — when **true**
 * (default), every root and nested item renders **collapsed** until the user expands it. Set **`default_collapsed` =>
 * false** for legacy “open by default” behaviour. Optional **`repeater_title_view`** (schema leaf **`id`**) shows that
 * field’s value in the collapsed row header when non-empty, otherwise **Item N** / **Nested item N**. **No per-breakpoint `responsive` maps**
 * on sub-fields inside the repeater in this version (values are scalars or nested JSON only — carve-out documented
 * in project rules / README). Conditional **`required`** on the **repeater row** uses global **`sto_options`** only.
 * Inner schema leaves may declare **`required`** (OR-of-AND groups keyed by sibling leaf ids); admin visibility is
 * evaluated per row in **`sto-advanced-repeater-field.js`** via **`data-sto-adv-rep-required`**. **`html_required`** on
 * schema leaves is enforced when saving.
 */
final class AdvancedRepeaterControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	public const MAX_ROWS        = 50;
	public const MAX_NEST_DEPTH  = 5;
	public const MAX_SCHEMA_LEN = 60;

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

	/**
	 * While rendering WooCommerce Product data STO panels, also emit `name` on repeater leaves.
	 *
	 * @var bool
	 */
	private static $wc_product_data_leaf_submit_names = false;

	protected function init() {
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::ADVANCED_REPEATER, 2 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'inject_repeater_leaf_bucket_into_sto_options_post' ), 5 );
	}

	/**
	 * WooCommerce product save: rebuild `sto_options[repeater_id]` JSON from per-leaf POST names
	 * before Menu persistence runs (hidden JSON is often stale when block editors were not flushed).
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function inject_repeater_leaf_bucket_into_sto_options_post( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['sto_options'] ) || ! is_array( $_POST['sto_options'] ) ) {
			$_POST['sto_options'] = array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$bucket = array();
		if ( isset( $_POST['sto_options_adv_rep_leaves'] ) && is_array( $_POST['sto_options_adv_rep_leaves'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$bucket = wp_unslash( $_POST['sto_options_adv_rep_leaves'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! is_array( $bucket ) ) {
				$bucket = array();
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce product save.
		$posted_sto_options = is_array( $_POST['sto_options'] ) ? wp_unslash( $_POST['sto_options'] ) : array();
		$field_ids          = array();
		foreach ( array_keys( $bucket ) as $bucket_field_id ) {
			$field_ids[] = sanitize_key( (string) $bucket_field_id );
		}
		foreach ( array_keys( $posted_sto_options ) as $posted_field_id ) {
			$field_ids[] = sanitize_key( (string) $posted_field_id );
		}
		$field_ids = array_values( array_unique( array_filter( $field_ids ) ) );

		foreach ( $field_ids as $field_id ) {
			if ( $field_id === '' || ! $this->registry_is_registered_field_id( $field_id ) ) {
				continue;
			}

			$field = $this->fields_by_id[ $field_id ] ?? null;
			if ( ! is_array( $field ) || empty( $field['schema'] ) ) {
				continue;
			}

			$schema = $field['schema'];
			$rows   = isset( $bucket[ $field_id ] ) && is_array( $bucket[ $field_id ] ) ? $bucket[ $field_id ] : array();

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce product save.
			$existing_raw = isset( $_POST['sto_options'][ $field_id ] ) ? wp_unslash( $_POST['sto_options'][ $field_id ] ) : '';
			$json_rows    = array();
			$parsed_json  = $this->parse_items( $existing_raw );
			foreach ( $parsed_json as $json_row ) {
				if ( is_array( $json_row ) ) {
					$json_rows[] = $this->sanitize_item_for_schema(
						$schema,
						$this->normalize_adv_rep_row_for_schema( $schema, $json_row ),
						0,
						$field_id
					);
				}
			}

			$dom_rows = array();
			foreach ( $rows as $row_data ) {
				if ( is_array( $row_data ) ) {
					$dom_rows[] = $this->sanitize_item_for_schema(
						$schema,
						$this->normalize_adv_rep_row_for_schema( $schema, $row_data ),
						0,
						$field_id
					);
				}
			}

			if ( $dom_rows !== array() && $json_rows !== array() ) {
				$merged_rows = $this->merge_repeater_row_sets( $schema, $json_rows, $dom_rows );
			} elseif ( $dom_rows !== array() ) {
				$merged_rows = $dom_rows;
			} else {
				$merged_rows = $json_rows;
			}

			$merged_rows = $this->filter_storage_rows( $schema, $merged_rows );

			if ( $merged_rows === array() && $dom_rows !== array() ) {
				$merged_rows = $this->filter_storage_rows( $schema, $dom_rows );
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$_POST['sto_options'][ $field_id ] = wp_json_encode( array_values( $merged_rows ) );
		}
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
		FieldSpacing::normalize_config( $field );


		$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
		$field_id     = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
		$raw_fields   = isset( $field['fields'] ) && is_array( $field['fields'] ) ? $field['fields'] : array();

		if ( ! $section_slug || ! $field_id || $raw_fields === array() ) {
			return;
		}

		$schema = $this->normalize_schema( $raw_fields, 0 );
		if ( $schema === array() ) {
			return;
		}

		$wc_disallows_editor = $this->section_slug_uses_wc_product_data( $section_slug )
			&& $this->schema_includes_editor_leaf( $schema );
		if ( $wc_disallows_editor && function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong(
				'AdvancedRepeaterControl::register',
				sprintf(
					/* translators: 1: repeater field id, 2: section slug */
					esc_html__( 'Advanced repeater "%1$s" on WooCommerce Product data (section "%2$s") must not use `editor` leaves. Use `textarea` instead.', 'topten-simple-theme-options' ),
					esc_html( $field_id ),
					esc_html( $section_slug )
				),
				'1.0.0'
			);
		}

		$field['section_slug']   = $section_slug;
		$field['id']             = $field_id;
		$field['title']          = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']    = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class']  = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']       = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']          = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['html_required']  = ! empty( $field['html_required'] );
		$field['schema']         = $schema;
		$max                     = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		if ( $max > self::MAX_ROWS ) {
			$max = self::MAX_ROWS;
		}
		$field['max']            = $max;
		$field['default_rows']        = $this->normalize_default_items( isset( $field['default'] ) ? $field['default'] : array(), $schema, $max );
		$field['default_collapsed']   = array_key_exists( 'default_collapsed', $field ) ? (bool) $field['default_collapsed'] : true;
		$field['repeater_title_view']   = isset( $field['repeater_title_view'] ) ? sanitize_key( (string) $field['repeater_title_view'] ) : '';
		$field['wc_disallows_editor_in_repeater'] = $wc_disallows_editor;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]            = $field;
	}

	/**
	 * @param array<int, mixed> $raw
	 * @param array<int, array<string, mixed>> $schema
	 * @param int                $max_cap 0 = unlimited (capped at MAX_ROWS)
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_default_items( $raw, array $schema, $max_cap ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$cap = ( $max_cap > 0 ) ? min( $max_cap, self::MAX_ROWS ) : self::MAX_ROWS;
		foreach ( $raw as $row ) {
			if ( count( $out ) >= $cap ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = $this->sanitize_item_for_schema( $schema, $row, 0 );
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $schema Normalized schema nodes.
	 */
	private function schema_includes_editor_leaf( array $schema ): bool {
		foreach ( $schema as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = isset( $node['type'] ) ? (string) $node['type'] : '';
			if ( $type === 'editor' ) {
				return true;
			}
			if ( in_array( $type, array( 'fieldset', 'advanced_repeater' ), true ) && ! empty( $node['fields'] ) && is_array( $node['fields'] ) ) {
				if ( $this->schema_includes_editor_leaf( $node['fields'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function section_slug_uses_wc_product_data( string $section_slug ): bool {
		$section_slug = sanitize_key( $section_slug );
		if ( $section_slug === '' || ! class_exists( OptionsMenu::class ) ) {
			return false;
		}

		return OptionsMenu::instance()->get_menu_page_slug_for_leaf_section( $section_slug ) !== '';
	}

	private function repeater_disallows_editor_leaves( string $field_id ): bool {
		$field_id = sanitize_key( $field_id );
		if ( $field_id === '' ) {
			return false;
		}

		$field = $this->fields_by_id[ $field_id ] ?? null;

		return is_array( $field ) && ! empty( $field['wc_disallows_editor_in_repeater'] );
	}

	private function render_wc_repeater_editor_disallowed_notice(): void {
		?>
		<div class="notice notice-warning inline sto-adv-rep__editor-policy-notice">
			<p>
				<?php
				esc_html_e(
					'WP Editor fields are not supported inside repeaters on WooCommerce Product data tabs. Use a textarea field instead.',
					'topten-simple-theme-options'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * @param array<int, mixed> $raw_fields
	 * @param int               $depth
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_schema( $raw_fields, $depth ) {
		if ( $depth > self::MAX_NEST_DEPTH || ! is_array( $raw_fields ) ) {
			return array();
		}
		$out   = array();
		$count = 0;
		foreach ( $raw_fields as $item ) {
			if ( $count >= self::MAX_SCHEMA_LEN ) {
				break;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';
			$id   = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
			if ( $type === '' || $id === '' ) {
				continue;
			}

			if ( $type === 'fieldset' ) {
				$inner = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
				$sub   = $this->normalize_schema( $inner, $depth + 1 );
				if ( $sub === array() ) {
					continue;
				}
				$out[] = array(
					'type'        => 'fieldset',
					'id'          => $id,
					'title'       => isset( $item['title'] ) ? (string) $item['title'] : '',
					'description' => isset( $item['description'] ) ? (string) $item['description'] : '',
					'fields'      => $sub,
				);
				++$count;
				continue;
			}

			if ( $type === 'advanced_repeater' ) {
				$inner = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
				$sub   = $this->normalize_schema( $inner, $depth + 1 );
				if ( $sub === array() ) {
					continue;
				}
				$mx = isset( $item['max'] ) ? absint( $item['max'] ) : 0;
				if ( $mx > self::MAX_ROWS ) {
					$mx = self::MAX_ROWS;
				}
				$def_rows = $this->normalize_default_items( isset( $item['default'] ) ? $item['default'] : array(), $sub, $mx );
				$out[]    = array(
					'type'               => 'advanced_repeater',
					'id'                 => $id,
					'title'              => isset( $item['title'] ) ? (string) $item['title'] : '',
					'description'        => isset( $item['description'] ) ? (string) $item['description'] : '',
					'fields'             => $sub,
					'max'                => $mx,
					'default_rows'       => $def_rows,
					'html_required'      => ! empty( $item['html_required'] ),
					'repeater_title_view' => isset( $item['repeater_title_view'] ) ? sanitize_key( (string) $item['repeater_title_view'] ) : '',
				);
				++$count;
				continue;
			}

			if ( $type === 'switcher' ) {
				$def_sw = isset( $item['default'] ) && (string) $item['default'] === '1' ? '1' : '0';
				$out[]  = array(
					'type'          => 'switcher',
					'id'            => $id,
					'title'         => isset( $item['title'] ) ? (string) $item['title'] : '',
					'description'   => isset( $item['description'] ) ? (string) $item['description'] : '',
					'default'       => $def_sw,
					'required'      => $this->normalize_leaf_required( $item ),
					'html_required' => ! empty( $item['html_required'] ),
				);
				++$count;
				continue;
			}

			if ( in_array( $type, array( 'text', 'number', 'textarea' ), true ) ) {
				$out[] = array(
					'type'          => $type,
					'id'            => $id,
					'title'         => isset( $item['title'] ) ? (string) $item['title'] : '',
					'description'   => isset( $item['description'] ) ? (string) $item['description'] : '',
					'placeholder'   => isset( $item['placeholder'] ) ? (string) $item['placeholder'] : '',
					'default'       => isset( $item['default'] ) ? (string) $item['default'] : '',
					'min'           => isset( $item['min'] ) ? (string) $item['min'] : '',
					'max'           => isset( $item['max'] ) ? (string) $item['max'] : '',
					'step'          => isset( $item['step'] ) ? (string) $item['step'] : '',
					'required'      => $this->normalize_leaf_required( $item ),
					'html_required' => ! empty( $item['html_required'] ),
				);
				++$count;
				continue;
			}

			if ( $type === 'select' ) {
				$opts = isset( $item['options'] ) && is_array( $item['options'] ) ? $this->normalize_select_options( $item['options'] ) : array();
				if ( $opts === array() ) {
					continue;
				}
				$keys         = array_keys( $opts );
				$def          = isset( $item['default'] ) ? sanitize_key( (string) $item['default'] ) : '';
				$default_key  = ( $def !== '' && isset( $opts[ $def ] ) ) ? $def : (string) $keys[0];
				$out[]        = array(
					'type'          => 'select',
					'id'            => $id,
					'title'         => isset( $item['title'] ) ? (string) $item['title'] : '',
					'description'   => isset( $item['description'] ) ? (string) $item['description'] : '',
					'placeholder'   => isset( $item['placeholder'] ) ? (string) $item['placeholder'] : '',
					'options'       => $opts,
					'default'       => $default_key,
					'required'      => $this->normalize_leaf_required( $item ),
					'html_required' => ! empty( $item['html_required'] ),
				);
				++$count;
				continue;
			}

			if ( $type === 'editor' ) {
				$editor_height = isset( $item['editor_height'] ) && is_numeric( $item['editor_height'] ) ? (int) $item['editor_height'] : 280;
				if ( $editor_height < 100 ) {
					$editor_height = 100;
				}
				if ( $editor_height > 1200 ) {
					$editor_height = 1200;
				}
				$out[] = array(
					'type'          => 'editor',
					'id'            => $id,
					'title'         => isset( $item['title'] ) ? (string) $item['title'] : '',
					'description'   => isset( $item['description'] ) ? (string) $item['description'] : '',
					'default'       => isset( $item['default'] ) && is_scalar( $item['default'] ) ? (string) $item['default'] : '',
					'editor_height' => $editor_height,
					'media_buttons' => ! array_key_exists( 'media_buttons', $item ) || (bool) $item['media_buttons'],
					'teeny'         => ! empty( $item['teeny'] ),
					'required'      => $this->normalize_leaf_required( $item ),
					'html_required' => ! empty( $item['html_required'] ),
				);
				++$count;
				continue;
			}

			if ( in_array( $type, array( 'rich_modern_editor', 'richmoderneditor', 'block_editor', 'gutenberg' ), true ) ) {
				if ( function_exists( '_doing_it_wrong' ) ) {
					_doing_it_wrong(
						'AdvancedRepeaterControl',
						esc_html__( 'The rich_modern_editor field type was removed. Use textarea or a standalone classic editor field outside the repeater.', 'topten-simple-theme-options' ),
						'1.0.0'
					);
				}
				continue;
			}

			if ( $type === 'icon_select' ) {
				$allow_clear_ic = ! empty( $item['allow_clear'] );
				$raw_icon_def   = isset( $item['default'] ) ? (string) $item['default'] : '';
				$resolved_def   = IconSelect::coerce_advanced_repeater_leaf_value( $raw_icon_def, $allow_clear_ic, '' );
				$out[]          = array(
					'type'          => 'icon_select',
					'id'            => $id,
					'title'         => isset( $item['title'] ) ? (string) $item['title'] : '',
					'description'   => isset( $item['description'] ) ? (string) $item['description'] : '',
					'default'       => $resolved_def,
					'allow_clear'   => $allow_clear_ic,
					'html_required' => ! empty( $item['html_required'] ),
				);
				++$count;
				continue;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, string>
	 */
	private function normalize_select_options( $raw ) {
		$out = array();
		foreach ( $raw as $k => $lab ) {
			$k = sanitize_key( (string) $k );
			if ( $k === '' ) {
				continue;
			}
			if ( is_array( $lab ) && isset( $lab['label'] ) ) {
				$out[ $k ] = (string) $lab['label'];
			} else {
				$out[ $k ] = (string) $lab;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $item Raw schema leaf from register().
	 * @return array<int|string, mixed>
	 */
	private function normalize_leaf_required( array $item ): array {
		if ( ! isset( $item['required'] ) || ! is_array( $item['required'] ) ) {
			return array();
		}

		return $item['required'];
	}

	/**
	 * @param array<string, mixed> $node Normalized schema leaf.
	 */
	private function leaf_adv_rep_required_attr( array $node ): string {
		if ( empty( $node['required'] ) || ! is_array( $node['required'] ) ) {
			return '';
		}

		$json = wp_json_encode( $node['required'] );
		if ( ! is_string( $json ) || $json === '' ) {
			return '';
		}

		return ' data-sto-adv-rep-required="' . esc_attr( $json ) . '"';
	}

	/**
	 * @param array<int, array<string, mixed>> $schema
	 * @return array<string, mixed>
	 */
	private function empty_item_for_schema( array $schema ): array {
		$row = array();
		foreach ( $schema as $node ) {
			$t  = $node['type'];
			$id = $node['id'];
			if ( in_array( $t, array( 'text', 'number', 'textarea', 'editor', 'switcher' ), true ) ) {
				$row[ $id ] = isset( $node['default'] ) ? (string) $node['default'] : ( $t === 'switcher' ? '0' : '' );
			} elseif ( $t === 'select' ) {
				$row[ $id ] = isset( $node['default'] ) ? (string) $node['default'] : '';
			} elseif ( $t === 'icon_select' ) {
				$row[ $id ] = isset( $node['default'] ) ? (string) $node['default'] : '';
			} elseif ( $t === 'fieldset' ) {
				$row[ $id ] = $this->empty_item_for_schema( $node['fields'] );
			} elseif ( $t === 'advanced_repeater' ) {
				$inner_default = isset( $node['default_rows'] ) && is_array( $node['default_rows'] ) ? $node['default_rows'] : array();
				if ( $inner_default !== array() ) {
					$san = array();
					foreach ( $inner_default as $ir ) {
						$san[] = $this->sanitize_item_for_schema( $node['fields'], is_array( $ir ) ? $ir : array(), 0 );
					}
					$row[ $id ] = $san;
				} else {
					$row[ $id ] = array( $this->empty_item_for_schema( $node['fields'] ) );
				}
			}
		}

		return $row;
	}

	/**
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<string, mixed>             $row
	 * @param int                              $depth
	 * @return array<string, mixed>
	 */
	private function sanitize_item_for_schema( array $schema, array $row, $depth, string $root_repeater_field_id = '' ) {
		if ( $depth > self::MAX_NEST_DEPTH ) {
			return array();
		}
		$out = $this->empty_item_for_schema( $schema );
		foreach ( $schema as $node ) {
			$id = $node['id'];
			$t  = $node['type'];
			if ( ! array_key_exists( $id, $row ) ) {
				continue;
			}
			$val = $row[ $id ];
			if ( $t === 'fieldset' && is_array( $val ) ) {
				$out[ $id ] = $this->sanitize_item_for_schema( $node['fields'], $val, $depth + 1, $root_repeater_field_id );
				continue;
			}
			if ( $t === 'advanced_repeater' ) {
				$cap = isset( $node['max'] ) && (int) $node['max'] > 0 ? min( (int) $node['max'], self::MAX_ROWS ) : self::MAX_ROWS;
				$lst = is_array( $val ) ? $val : array();
				$acc = array();
				foreach ( $lst as $inner_row ) {
					if ( count( $acc ) >= $cap ) {
						break;
					}
					if ( is_array( $inner_row ) ) {
						$acc[] = $this->sanitize_item_for_schema( $node['fields'], $inner_row, $depth + 1, $root_repeater_field_id );
					}
				}
				if ( $acc === array() ) {
					$acc[] = $this->empty_item_for_schema( $node['fields'] );
				}
				$out[ $id ] = $acc;
				continue;
			}
			if ( in_array( $t, array( 'text', 'textarea' ), true ) ) {
				$out[ $id ] = sanitize_text_field( is_scalar( $val ) ? wp_unslash( (string) $val ) : '' );
				continue;
			}
			if ( $t === 'number' ) {
				$out[ $id ] = $this->sanitize_number_leaf( $node, is_scalar( $val ) ? (string) $val : '' );
				continue;
			}
			if ( $t === 'switcher' ) {
				$out[ $id ] = ( (string) $val === '1' ) ? '1' : '0';
				continue;
			}
			if ( $t === 'select' ) {
				$vk = sanitize_key( is_scalar( $val ) ? (string) $val : '' );
				if ( $vk === '' || ! isset( $node['options'][ $vk ] ) ) {
					$keys       = array_keys( $node['options'] );
					$out[ $id ] = $keys ? (string) $keys[0] : '';
				} else {
					$out[ $id ] = $vk;
				}
				continue;
			}
			if ( $t === 'icon_select' ) {
				$out[ $id ] = IconSelect::coerce_advanced_repeater_leaf_value(
					is_scalar( $val ) ? (string) $val : '',
					! empty( $node['allow_clear'] ),
					isset( $node['default'] ) ? (string) $node['default'] : ''
				);
				continue;
			}
			if ( $t === 'editor' ) {
				if ( $root_repeater_field_id !== '' && $this->repeater_disallows_editor_leaves( $root_repeater_field_id ) ) {
					continue;
				}
				$markup = is_scalar( $val ) ? (string) $val : '';
				// Do not wp_unslash markup here — leaf bucket / json_decode values are already unslashed;
				// stripslashes on "\r\n" sequences becomes literal "rn".
				$out[ $id ] = Input::instance()->sanitize_editor_html( $markup );
				continue;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private function sanitize_number_leaf( array $node, string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( ! is_numeric( $raw ) ) {
			return '';
		}
		$n = 0 + $raw;
		if ( isset( $node['min'] ) && $node['min'] !== '' && is_numeric( $node['min'] ) ) {
			$n = max( $n, (float) $node['min'] );
		}
		if ( isset( $node['max'] ) && $node['max'] !== '' && is_numeric( $node['max'] ) ) {
			$n = min( $n, (float) $node['max'] );
		}

		return (string) $n;
	}

	/**
	 * Rows with no meaningful leaf values (e.g. blank custom-tab name) are UI placeholders — skip on save/validation.
	 *
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<string, mixed>             $row
	 */
	private function row_is_storage_empty( array $schema, array $row ): bool {
		foreach ( $schema as $node ) {
			$id = $node['id'];
			$t  = $node['type'];
			if ( $t === 'fieldset' ) {
				$sub = isset( $row[ $id ] ) && is_array( $row[ $id ] ) ? $row[ $id ] : array();
				if ( ! $this->row_is_storage_empty( $node['fields'], $sub ) ) {
					return false;
				}
				continue;
			}
			if ( $t === 'advanced_repeater' ) {
				$lst = isset( $row[ $id ] ) && is_array( $row[ $id ] ) ? $row[ $id ] : array();
				foreach ( $lst as $inner ) {
					if ( is_array( $inner ) && ! $this->row_is_storage_empty( $node['fields'], $inner ) ) {
						return false;
					}
				}
				continue;
			}
			$val = isset( $row[ $id ] ) ? $row[ $id ] : '';
			if ( $t === 'switcher' ) {
				if ( (string) $val === '1' ) {
					return false;
				}
				continue;
			}
			if ( $t === 'editor' ) {
				$html = is_scalar( $val ) ? (string) $val : '';
				if ( Input::classic_editor_html_has_meaningful_content( $html ) ) {
					return false;
				}
				continue;
			}
			if ( is_string( $val ) && trim( $val ) !== '' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_storage_rows( array $schema, array $rows ): array {
		$kept = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || $this->row_is_storage_empty( $schema, $row ) ) {
				continue;
			}
			$kept[] = $row;
		}

		return $kept;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_items( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( $raw === '' ) {
				return array();
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				$decoded = json_decode( wp_unslash( $raw ), true );
			}

			return is_array( $decoded ) ? $decoded : array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}

		return array();
	}

	/**
	 * Enable per-leaf `name` attributes for the next WC Product data panel render pass.
	 */
	public static function enable_wc_product_data_leaf_submit_names(): void {
		self::$wc_product_data_leaf_submit_names = true;
	}

	/**
	 * Disable per-leaf `name` attributes after WC Product data panel render.
	 */
	public static function disable_wc_product_data_leaf_submit_names(): void {
		self::$wc_product_data_leaf_submit_names = false;
	}

	/**
	 * Whether repeater leaves should also POST under `sto_options_adv_rep_leaves` (WooCommerce product data).
	 */
	private function should_render_adv_rep_leaf_submit_names(): bool {
		return self::$wc_product_data_leaf_submit_names || (bool) doing_action( 'woocommerce_product_data_panels' );
	}

	/**
	 * `name` attribute for a root repeater row leaf (backup when hidden JSON is stale).
	 *
	 * @param string $field_id   Repeater field id.
	 * @param int    $row_index  0-based row index.
	 * @param string $leaf_id    Schema leaf id.
	 */
	private function submit_leaf_name_attr( string $field_id, int $row_index, string $leaf_id ): string {
		$field_id  = sanitize_key( $field_id );
		$leaf_id   = sanitize_key( $leaf_id );
		$row_index = max( 0, $row_index );
		if ( $field_id === '' || $leaf_id === '' ) {
			return '';
		}

		return sprintf(
			' name="%s"',
			esc_attr(
				sprintf(
					'sto_options_adv_rep_leaves[%s][%d][%s]',
					$field_id,
					$row_index,
					$leaf_id
				)
			)
		);
	}

	/**
	 * Prefer leaf POST bucket when it carries more data than parsed hidden JSON.
	 *
	 * @param string                           $field_id
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<int, array<string, mixed>> $json_rows
	 * @return array<int, array<string, mixed>>
	 */
	private function merge_posted_leaf_rows_from_request( string $field_id, array $schema, array $json_rows ): array {
		$dom_rows = $this->build_rows_from_adv_rep_leaf_bucket( $field_id, $schema );
		if ( $dom_rows === array() ) {
			return $json_rows;
		}

		if ( $json_rows === array() ) {
			return $dom_rows;
		}

		return $this->merge_repeater_row_sets( $schema, $json_rows, $dom_rows );
	}

	/**
	 * Rows posted as `sto_options_adv_rep_leaves[field_id][row][leaf]` (WooCommerce product data backup).
	 *
	 * @param string                           $field_id Repeater field id.
	 * @param array<int, array<string, mixed>> $schema   Normalized schema.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_rows_from_adv_rep_leaf_bucket( string $field_id, array $schema ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce product save; capability checked upstream.
		if ( ! isset( $_POST['sto_options_adv_rep_leaves'] ) || ! is_array( $_POST['sto_options_adv_rep_leaves'] ) ) {
			return array();
		}

		$field_id = sanitize_key( $field_id );
		$bucket   = wp_unslash( $_POST['sto_options_adv_rep_leaves'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rows sanitized via sanitize_item_for_schema().
		if ( $field_id === '' || ! isset( $bucket[ $field_id ] ) || ! is_array( $bucket[ $field_id ] ) ) {
			return array();
		}

		$dom_rows = array();
		foreach ( $bucket[ $field_id ] as $row_data ) {
			if ( ! is_array( $row_data ) ) {
				continue;
			}
			$dom_rows[] = $this->sanitize_item_for_schema(
				$schema,
				$this->normalize_adv_rep_row_for_schema( $schema, $row_data ),
				0
			);
		}

		return $dom_rows;
	}

	/**
	 * WC leaf POST uses flat keys; fieldset schemas expect nested blocks (e.g. spec_row.spec_name).
	 *
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<string, mixed>             $row
	 * @return array<string, mixed>
	 */
	private function normalize_adv_rep_row_for_schema( array $schema, array $row ): array {
		if ( $row === array() ) {
			return $row;
		}

		$normalized = array();

		foreach ( $schema as $node ) {
			$node_id = isset( $node['id'] ) ? sanitize_key( (string) $node['id'] ) : '';
			$type    = isset( $node['type'] ) ? sanitize_key( (string) $node['type'] ) : '';
			if ( $node_id === '' ) {
				continue;
			}

			if ( $type === 'fieldset' && ! empty( $node['fields'] ) && is_array( $node['fields'] ) ) {
				$block = array();
				if ( isset( $row[ $node_id ] ) && is_array( $row[ $node_id ] ) ) {
					$block = $this->normalize_adv_rep_row_for_schema( $node['fields'], $row[ $node_id ] );
				}
				foreach ( $node['fields'] as $inner ) {
					if ( ! is_array( $inner ) ) {
						continue;
					}
					$inner_id = isset( $inner['id'] ) ? sanitize_key( (string) $inner['id'] ) : '';
					if ( $inner_id === '' || ! array_key_exists( $inner_id, $row ) ) {
						continue;
					}
					$block[ $inner_id ] = $row[ $inner_id ];
				}
				if ( $block !== array() ) {
					$normalized[ $node_id ] = $block;
				}
				continue;
			}

			if ( $type === 'advanced_repeater' ) {
				$nested_list = isset( $row[ $node_id ] ) && is_array( $row[ $node_id ] ) ? $row[ $node_id ] : array();
				if ( $nested_list !== array() && ! empty( $node['fields'] ) && is_array( $node['fields'] ) ) {
					$nested_schema = $node['fields'];
					$normalized_nested = array();
					foreach ( $nested_list as $nested_row ) {
						if ( is_array( $nested_row ) ) {
							$normalized_nested[] = $this->normalize_adv_rep_row_for_schema( $nested_schema, $nested_row );
						}
					}
					if ( $normalized_nested !== array() ) {
						$normalized[ $node_id ] = $normalized_nested;
					}
				}
				continue;
			}

			if ( array_key_exists( $node_id, $row ) ) {
				$normalized[ $node_id ] = $row[ $node_id ];
			}
		}

		return $normalized !== array() ? $normalized : $row;
	}

	/**
	 * When JSON and leaf POST both carry editor HTML, keep the richest markup (Visual mirror vs hidden JSON).
	 *
	 * @param string ...$candidates Raw HTML candidates.
	 */
	private function pick_richest_editor_html_for_merge( string ...$candidates ): string {
		$best_html = '';

		foreach ( $candidates as $candidate_html ) {
			$candidate_html = is_scalar( $candidate_html ) ? (string) $candidate_html : '';
			if ( ! Input::classic_editor_html_has_meaningful_content( $candidate_html ) ) {
				continue;
			}
			if ( $best_html === '' || strlen( trim( $candidate_html ) ) > strlen( trim( $best_html ) ) ) {
				$best_html = $candidate_html;
			}
		}

		if ( $best_html !== '' ) {
			return $best_html;
		}

		foreach ( $candidates as $candidate_html ) {
			$candidate_html = is_scalar( $candidate_html ) ? trim( (string) $candidate_html ) : '';
			if ( $candidate_html !== '' && strlen( $candidate_html ) > strlen( trim( $best_html ) ) ) {
				$best_html = $candidate_html;
			}
		}

		return $best_html;
	}

	/**
	 * Merge hidden JSON rows with per-leaf POST values (prefer non-empty leaf per row).
	 *
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<int, array<string, mixed>> $json_rows
	 * @param array<int, array<string, mixed>> $dom_rows
	 * @return array<int, array<string, mixed>>
	 */
	private function merge_repeater_row_sets( array $schema, array $json_rows, array $dom_rows ): array {
		$row_count = max( count( $json_rows ), count( $dom_rows ) );
		if ( $row_count <= 0 ) {
			return array();
		}

		$merged = array();
		for ( $row_index = 0; $row_index < $row_count; $row_index++ ) {
			$json_row = isset( $json_rows[ $row_index ] ) && is_array( $json_rows[ $row_index ] ) ? $json_rows[ $row_index ] : array();
			$dom_row  = isset( $dom_rows[ $row_index ] ) && is_array( $dom_rows[ $row_index ] ) ? $dom_rows[ $row_index ] : array();
			$json_row = $this->normalize_adv_rep_row_for_schema( $schema, $json_row );
			$dom_row  = $this->normalize_adv_rep_row_for_schema( $schema, $dom_row );
			$merged[] = $this->merge_repeater_row_leaves( $schema, $json_row, $dom_row );
		}

		return $merged;
	}

	/**
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<string, mixed>             $json_row
	 * @param array<string, mixed>             $dom_row
	 * @return array<string, mixed>
	 */
	private function merge_repeater_row_leaves( array $schema, array $json_row, array $dom_row ): array {
		$merged = $json_row;
		foreach ( $schema as $node ) {
			$leaf_id = isset( $node['id'] ) ? sanitize_key( (string) $node['id'] ) : '';
			$type    = isset( $node['type'] ) ? sanitize_key( (string) $node['type'] ) : '';
			if ( $leaf_id === '' ) {
				continue;
			}

			if ( $type === 'fieldset' && ! empty( $node['fields'] ) && is_array( $node['fields'] ) ) {
				$json_sub = isset( $json_row[ $leaf_id ] ) && is_array( $json_row[ $leaf_id ] ) ? $json_row[ $leaf_id ] : array();
				$dom_sub  = isset( $dom_row[ $leaf_id ] ) && is_array( $dom_row[ $leaf_id ] ) ? $dom_row[ $leaf_id ] : array();
				$json_sub = $this->normalize_adv_rep_row_for_schema( $node['fields'], $json_sub );
				$dom_sub  = $this->normalize_adv_rep_row_for_schema( $node['fields'], $dom_sub );
				$merged[ $leaf_id ] = $this->merge_repeater_row_leaves( $node['fields'], $json_sub, $dom_sub );
				continue;
			}

			if ( $type === 'advanced_repeater' ) {
				continue;
			}

			$dom_value  = isset( $dom_row[ $leaf_id ] ) ? $dom_row[ $leaf_id ] : '';
			$json_value = isset( $json_row[ $leaf_id ] ) ? $json_row[ $leaf_id ] : '';

			if ( $type === 'editor' ) {
				$dom_html  = is_scalar( $dom_value ) ? (string) $dom_value : '';
				$json_html = is_scalar( $json_value ) ? (string) $json_value : '';
				$pick_html = $this->pick_richest_editor_html_for_merge( $dom_html, $json_html );
				if ( Input::classic_editor_html_has_meaningful_content( $pick_html ) ) {
					$merged[ $leaf_id ] = Input::instance()->sanitize_editor_html( $pick_html );
				}
				continue;
			}

			$dom_string = is_scalar( $dom_value ) ? trim( (string) $dom_value ) : '';
			if ( $dom_string !== '' ) {
				$merged[ $leaf_id ] = (string) $dom_value;
			}
		}

		return $merged;
	}

	/**
	 * @param string $field_id
	 * @param mixed  $raw
	 * @return string JSON
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) || empty( $field['schema'] ) ) {
			return wp_json_encode( array() );
		}
		$schema = $field['schema'];
		$max    = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		$cap    = ( $max > 0 ) ? min( $max, self::MAX_ROWS ) : self::MAX_ROWS;

		$parsed    = $this->parse_items( $raw );
		$json_rows = array();
		foreach ( $parsed as $row ) {
			if ( count( $json_rows ) >= $cap ) {
				break;
			}
			if ( is_array( $row ) ) {
				$json_rows[] = $this->sanitize_item_for_schema(
					$schema,
					$this->normalize_adv_rep_row_for_schema( $schema, $row ),
					0
				);
			}
		}

		$leaf_rows = $this->build_rows_from_adv_rep_leaf_bucket( $field_id, $schema );
		if ( $leaf_rows !== array() && $json_rows === array() ) {
			$out = $leaf_rows;
		} elseif ( $leaf_rows !== array() && $json_rows !== array() ) {
			$out = $this->merge_repeater_row_sets( $schema, $json_rows, $leaf_rows );
		} else {
			$out = $json_rows;
		}

		if ( $out === array() && $leaf_rows !== array() ) {
			$out = $leaf_rows;
		}

		$out = $this->filter_storage_rows( $schema, $out );

		return wp_json_encode( array_values( $out ) );
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
		if ( ! is_array( $field ) || empty( $field['schema'] ) ) {
			return;
		}

		$field_id      = $field['id'];
		$title         = $field['title'];
		$description   = $field['description'];
		$wrapper_class = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$tooltip       = FieldTitle::get_tooltip_config( $field );
		$schema        = $field['schema'];
		$max           = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		$default_rows       = isset( $field['default_rows'] ) && is_array( $field['default_rows'] ) ? $field['default_rows'] : array();
		$default_collapsed  = ! empty( $field['default_collapsed'] );
		$repeater_title_view = isset( $field['repeater_title_view'] ) ? sanitize_key( (string) $field['repeater_title_view'] ) : '';
		$is_inner           = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-advanced-repeater' );
		if ( $wrapper_class ) {
			$row_classes[] = $wrapper_class;
		}
		if ( $is_inner ) {
			$row_classes[] = 'sto-field-row--in-group';
		}

		$json  = $this->get_merged_json( $field_id, $default_rows );
		$items = json_decode( (string) $json, true );
		if ( ! is_array( $items ) || $items === array() ) {
			$items = array( $this->empty_item_for_schema( $schema ) );
		}

		$i18n = array(
			'addItem'         => __( 'Add item', 'topten-simple-theme-options' ),
			'remove'          => __( 'Remove item', 'topten-simple-theme-options' ),
			'drag'            => __( 'Drag to reorder', 'topten-simple-theme-options' ),
			'collapse'        => __( 'Collapse', 'topten-simple-theme-options' ),
			'expand'          => __( 'Expand', 'topten-simple-theme-options' ),
			'itemLabel'       => __( 'Item', 'topten-simple-theme-options' ),
			'nestedItemLabel' => __( 'Nested item', 'topten-simple-theme-options' ),
		);

		$input_name = 'sto_options[' . $field_id . ']';
		$max_attr   = (string) ( $max > 0 ? $max : 0 );
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
		>
			<?php if ( $title ) : ?>
				<?php FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_inner, '' ); ?>
			<?php endif; ?>

			<?php if ( PremiumFieldGate::render_controls_or_locked_placeholder( $title, 'advanced_repeater' ) ) : ?>
			<?php else : ?>

			<?php
			if ( ! empty( $field['wc_disallows_editor_in_repeater'] ) && $this->should_render_adv_rep_leaf_submit_names() ) {
				$this->render_wc_repeater_editor_disallowed_notice();
			}
			?>

			<div
				class="sto-adv-rep"
				data-sto-adv-rep="1"
				<?php if ( $this->should_render_adv_rep_leaf_submit_names() ) : ?>
					data-sto-adv-rep-submit-leaves="1"
				<?php endif; ?>
				data-sto-adv-rep-max="<?php echo esc_attr( $max_attr ); ?>"
				<?php if ( $repeater_title_view !== '' ) : ?>
					data-sto-adv-rep-title-view="<?php echo esc_attr( $repeater_title_view ); ?>"
				<?php endif; ?>
				data-sto-adv-rep-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>"
			>
				<input
					type="hidden"
					class="sto-adv-rep__value"
					name="<?php echo esc_attr( $input_name ); ?>"
					value="<?php echo esc_attr( wp_json_encode( array_values( $items ) ) ); ?>"
					autocomplete="off"
				/>
				<ul class="sto-adv-rep__list" data-sto-adv-rep-list>
					<?php foreach ( $items as $idx => $item_row ) : ?>
						<?php
						if ( ! is_array( $item_row ) ) {
							$item_row = array();
						}
						$item_row = $this->sanitize_item_for_schema( $schema, $item_row, 0, $field_id );
						$row_exp  = ! $default_collapsed;
						?>
						<li class="sto-adv-rep__item" data-sto-adv-rep-item>
							<div class="sto-adv-rep__head">
								<button type="button" class="sto-adv-rep__drag" data-sto-adv-rep-drag aria-label="<?php echo esc_attr( $i18n['drag'] ); ?>">
									<i class="fa-light fa-grip-dots-vertical" aria-hidden="true"></i>
								</button>
								<button type="button" class="sto-adv-rep__toggle" data-sto-adv-rep-toggle aria-expanded="<?php echo $row_exp ? 'true' : 'false'; ?>">
									<span class="sto-adv-rep__toggle-text"><?php echo esc_html( $this->format_row_toggle_label( $i18n['itemLabel'], (int) $idx, $repeater_title_view, $item_row ) ); ?></span>
									<i class="fa-light fa-chevron-<?php echo $row_exp ? 'up' : 'down'; ?> sto-adv-rep__chev" aria-hidden="true"></i>
								</button>
								<button type="button" class="sto-adv-rep__remove" data-sto-adv-rep-remove aria-label="<?php echo esc_attr( $i18n['remove'] ); ?>">
									<i class="fa-light fa-trash-can" aria-hidden="true"></i>
								</button>
							</div>
							<div class="sto-adv-rep__body" data-sto-adv-rep-body<?php echo $row_exp ? '' : ' style="display:none;"'; ?>>
								<?php $this->render_schema_nodes( $schema, $item_row, (string) $field_id . '_' . (int) $idx, $field_id, 0, $default_collapsed, (int) $idx ); ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<button type="button" class="button sto-adv-rep__add" data-sto-adv-rep-add><?php echo esc_html( $i18n['addItem'] ); ?></button>
			</div>

			<?php endif; ?>

			<?php if ( $description && ! PremiumFieldGate::is_locked() ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<string, mixed>             $values
	 * @param string                           $html_id_prefix
	 * @param string                           $field_id Root option id
	 * @param int                              $depth
	 * @param bool                             $default_collapsed When true, nested repeater rows render collapsed (same as root).
	 */
	private function render_schema_nodes( array $schema, array $values, string $html_id_prefix, string $field_id, int $depth, bool $default_collapsed = true, int $submit_row_index = -1 ) {
		foreach ( $schema as $node ) {
			$id    = $node['id'];
			$type  = $node['type'];
			$val   = array_key_exists( $id, $values ) ? $values[ $id ] : null;
			$title = isset( $node['title'] ) ? (string) $node['title'] : '';

			if ( $type === 'fieldset' ) {
				$inner_vals = is_array( $val ) ? $val : array();
				?>
				<div class="sto-adv-rep__fieldset" data-sto-adv-rep-fieldset="<?php echo esc_attr( $id ); ?>">
					<?php if ( $title !== '' ) : ?>
						<div class="sto-adv-rep__fieldset-title"><?php echo esc_html( $title ); ?></div>
					<?php endif; ?>
					<?php
					$this->render_schema_nodes( $node['fields'], $inner_vals, $html_id_prefix . '_' . $id, $field_id, $depth + 1, $default_collapsed, $submit_row_index );
					?>
				</div>
				<?php
				continue;
			}

			if ( $type === 'advanced_repeater' ) {
				$inner_list = is_array( $val ) ? $val : array();
				if ( $inner_list === array() ) {
					$inner_list = array( $this->empty_item_for_schema( $node['fields'] ) );
				}
				$mx                  = isset( $node['max'] ) ? absint( $node['max'] ) : 0;
				$nested_title_view   = isset( $node['repeater_title_view'] ) ? sanitize_key( (string) $node['repeater_title_view'] ) : '';
				?>
				<div
					class="sto-adv-rep sto-adv-rep--nested"
					data-sto-adv-rep="1"
					data-sto-adv-rep-nested="1"
					data-sto-adv-rep-nested-key="<?php echo esc_attr( $id ); ?>"
					data-sto-adv-rep-max="<?php echo esc_attr( (string) ( $mx > 0 ? $mx : 0 ) ); ?>"
					<?php if ( $nested_title_view !== '' ) : ?>
						data-sto-adv-rep-title-view="<?php echo esc_attr( $nested_title_view ); ?>"
					<?php endif; ?>
				>
					<?php if ( $title !== '' ) : ?>
						<div class="sto-adv-rep__nested-label"><?php echo esc_html( $title ); ?></div>
					<?php endif; ?>
					<ul class="sto-adv-rep__list" data-sto-adv-rep-list data-sto-adv-rep-sublist="1">
						<?php foreach ( $inner_list as $j => $inner_row ) : ?>
							<?php
							if ( ! is_array( $inner_row ) ) {
								$inner_row = array();
							}
							$inner_row = $this->sanitize_item_for_schema( $node['fields'], $inner_row, 0 );
							$nexp      = ! $default_collapsed;
							?>
							<li class="sto-adv-rep__item sto-adv-rep__item--nested" data-sto-adv-rep-item>
								<div class="sto-adv-rep__head">
									<button type="button" class="sto-adv-rep__drag" data-sto-adv-rep-drag aria-label="<?php echo esc_attr__( 'Drag to reorder', 'topten-simple-theme-options' ); ?>">
										<i class="fa-light fa-grip-dots-vertical" aria-hidden="true"></i>
									</button>
									<button type="button" class="sto-adv-rep__toggle" data-sto-adv-rep-toggle aria-expanded="<?php echo $nexp ? 'true' : 'false'; ?>">
										<span class="sto-adv-rep__toggle-text" data-sto-adv-rep-nested-label="1"><?php echo esc_html( $this->format_row_toggle_label( __( 'Nested item', 'topten-simple-theme-options' ), (int) $j, $nested_title_view, $inner_row ) ); ?></span>
										<i class="fa-light fa-chevron-<?php echo $nexp ? 'up' : 'down'; ?> sto-adv-rep__chev" aria-hidden="true"></i>
									</button>
									<button type="button" class="sto-adv-rep__remove" data-sto-adv-rep-remove aria-label="<?php echo esc_attr__( 'Remove item', 'topten-simple-theme-options' ); ?>">
										<i class="fa-light fa-trash-can" aria-hidden="true"></i>
									</button>
								</div>
								<div class="sto-adv-rep__body" data-sto-adv-rep-body<?php echo $nexp ? '' : ' style="display:none;"'; ?>>
									<?php $this->render_schema_nodes( $node['fields'], $inner_row, $html_id_prefix . '_' . $id . '_' . (int) $j, $field_id, $depth + 1, $default_collapsed, -1 ); ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
					<button type="button" class="button sto-adv-rep__add" data-sto-adv-rep-add><?php echo esc_html__( 'Add item', 'topten-simple-theme-options' ); ?></button>
				</div>
				<?php
				continue;
			}

			$fid   = $html_id_prefix . '_' . $id;
			$vstr  = is_scalar( $val ) ? (string) $val : '';
			$desc  = isset( $node['description'] ) ? (string) $node['description'] : '';
			$ph    = isset( $node['placeholder'] ) ? (string) $node['placeholder'] : '';

			if ( in_array( $type, array( 'text', 'number', 'textarea' ), true ) ) {
				$field_wrap_class = 'sto-adv-rep__field' . ( $type === 'textarea' ? ' sto-adv-rep__field--textarea' : '' );
				$ctrl_mod         = $type === 'number' ? 'number' : ( $type === 'textarea' ? 'textarea' : 'text' );
				$leaf_name_attr   = ( $submit_row_index >= 0 && $this->should_render_adv_rep_leaf_submit_names() )
					? $this->submit_leaf_name_attr( $field_id, $submit_row_index, $id )
					: '';
				?>
				<div class="<?php echo esc_attr( $field_wrap_class ); ?>" data-sto-adv-rep-leaf data-sto-adv-rep-key="<?php echo esc_attr( $id ); ?>" data-sto-adv-rep-kind="<?php echo esc_attr( $type ); ?>"<?php echo $this->leaf_adv_rep_required_attr( $node ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php if ( $title !== '' ) : ?>
						<label class="sto-adv-rep__label" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $title ); ?></label>
					<?php endif; ?>
					<div class="sto-input-wrap">
						<?php if ( $type === 'textarea' ) : ?>
							<textarea id="<?php echo esc_attr( $fid ); ?>" class="sto-input-control sto-input-control--textarea sto-adv-rep__input" rows="3" data-sto-adv-rep-input<?php echo $leaf_name_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea( $vstr ); ?></textarea>
						<?php else : ?>
							<input
								id="<?php echo esc_attr( $fid ); ?>"
								type="<?php echo esc_attr( $type === 'number' ? 'number' : 'text' ); ?>"
								class="sto-input-control sto-input-control--<?php echo esc_attr( $ctrl_mod ); ?> sto-adv-rep__input"
								value="<?php echo esc_attr( $vstr ); ?>"
								data-sto-adv-rep-input<?php echo $leaf_name_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo $type === 'number' && isset( $node['min'] ) && $node['min'] !== '' ? ' min="' . esc_attr( (string) $node['min'] ) . '"' : ''; ?>
								<?php echo $type === 'number' && isset( $node['max'] ) && $node['max'] !== '' ? ' max="' . esc_attr( (string) $node['max'] ) . '"' : ''; ?>
								<?php echo $type === 'number' && isset( $node['step'] ) && $node['step'] !== '' ? ' step="' . esc_attr( (string) $node['step'] ) . '"' : ''; ?>
								<?php echo $ph !== '' ? ' placeholder="' . esc_attr( $ph ) . '"' : ''; ?>
							/>
						<?php endif; ?>
					</div>
					<?php if ( $desc !== '' ) : ?>
						<p class="sto-field-description sto-adv-rep__hint"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
				</div>
				<?php
				continue;
			}

			if ( $type === 'switcher' ) {
				$on = ( $vstr === '1' );
				$sw_on  = __( 'ON', 'topten-simple-theme-options' );
				$sw_off = __( 'OFF', 'topten-simple-theme-options' );
				?>
				<div class="sto-adv-rep__field sto-adv-rep__field--switcher" data-sto-adv-rep-leaf data-sto-adv-rep-key="<?php echo esc_attr( $id ); ?>" data-sto-adv-rep-kind="switcher"<?php echo $this->leaf_adv_rep_required_attr( $node ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php if ( $title !== '' ) : ?>
						<span class="sto-adv-rep__label"><?php echo esc_html( $title ); ?></span>
					<?php endif; ?>
					<label class="sto-switcher sto-adv-rep__switcher<?php echo $on ? ' sto-switcher--on' : ''; ?>">
						<input
							type="checkbox"
							class="sto-adv-rep__switcher-input screen-reader-text"
							data-sto-adv-rep-switcher
							<?php checked( $on ); ?>
							aria-label="<?php echo esc_attr( $title !== '' ? $title : $id ); ?>"
						/>
						<span class="sto-switcher__track" aria-hidden="true">
							<span class="sto-switcher__knob"></span>
							<span class="sto-switcher__label sto-switcher__label--on"><?php echo esc_html( $sw_on ); ?></span>
							<span class="sto-switcher__label sto-switcher__label--off"><?php echo esc_html( $sw_off ); ?></span>
						</span>
					</label>
				</div>
				<?php
				continue;
			}

			if ( $type === 'select' ) {
				?>
				<div class="sto-adv-rep__field" data-sto-adv-rep-leaf data-sto-adv-rep-key="<?php echo esc_attr( $id ); ?>" data-sto-adv-rep-kind="select"<?php echo $this->leaf_adv_rep_required_attr( $node ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php if ( $title !== '' ) : ?>
						<label class="sto-adv-rep__label" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $title ); ?></label>
					<?php endif; ?>
					<div class="sto-select-wrap">
						<select id="<?php echo esc_attr( $fid ); ?>" class="sto-input-select sto-adv-rep__select" data-sto-adv-rep-select>
							<?php if ( $ph !== '' ) : ?>
								<option value=""><?php echo esc_html( $ph ); ?></option>
							<?php endif; ?>
							<?php foreach ( $node['options'] as $ok => $olab ) : ?>
								<option value="<?php echo esc_attr( (string) $ok ); ?>" <?php selected( (string) $vstr, (string) $ok ); ?>><?php echo esc_html( (string) $olab ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php if ( $desc !== '' ) : ?>
						<p class="sto-field-description sto-adv-rep__hint"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
				</div>
				<?php
				continue;
			}

			if ( $type === 'editor' ) {
				?>
				<div class="sto-adv-rep__field sto-adv-rep__field--editor sto-classic-editor-field" data-sto-adv-rep-leaf data-sto-adv-rep-key="<?php echo esc_attr( $id ); ?>" data-sto-adv-rep-kind="editor"<?php echo $this->leaf_adv_rep_required_attr( $node ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php if ( $title !== '' ) : ?>
						<span class="sto-adv-rep__label"><?php echo esc_html( $title ); ?></span>
					<?php endif; ?>
					<?php if ( $desc !== '' ) : ?>
						<p class="sto-field-description sto-adv-rep__hint"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
					<?php if ( $this->repeater_disallows_editor_leaves( $field_id ) ) : ?>
						<?php $this->render_wc_repeater_editor_disallowed_notice(); ?>
						<p class="sto-field-description sto-adv-rep__hint">
							<?php
							esc_html_e(
								'This leaf is ignored on save. Replace `editor` with `textarea` in your repeater schema.',
								'topten-simple-theme-options'
							);
							?>
						</p>
					<?php else : ?>
						<?php
						$textarea_name = '';
						if ( $submit_row_index >= 0 && $this->should_render_adv_rep_leaf_submit_names() ) {
							$textarea_name = sprintf(
								'sto_options_adv_rep_leaves[%s][%d][%s]',
								$field_id,
								$submit_row_index,
								$id
							);
						}
						?>
						<div class="sto-input-wrap sto-adv-rep__editor-wrap">
							<?php
							Input::instance()->render_embedded_wp_editor( $node, $textarea_name, $vstr, $fid );
							?>
						</div>
					<?php endif; ?>
				</div>
				<?php
				continue;
			}

			if ( $type === 'icon_select' ) {
				$allow_ic = ! empty( $node['allow_clear'] );
				$def_ic   = isset( $node['default'] ) ? (string) $node['default'] : '';
				$vc       = IconSelect::coerce_advanced_repeater_leaf_value( $vstr, $allow_ic, $def_ic );
				?>
				<div class="sto-adv-rep__field sto-adv-rep__field--icon-select sto-field-row-icon-select" data-sto-adv-rep-leaf data-sto-adv-rep-key="<?php echo esc_attr( $id ); ?>" data-sto-adv-rep-kind="icon_select">
					<?php if ( $title !== '' ) : ?>
						<span class="sto-adv-rep__label"><?php echo esc_html( $title ); ?></span>
					<?php endif; ?>
					<?php
					IconSelect::render_embedded_widget_markup(
						$fid,
						null,
						$vc,
						( $title !== '' ? $title : $id ),
						$allow_ic,
						$def_ic
					);
					?>
					<?php if ( $desc !== '' ) : ?>
						<p class="sto-field-description sto-adv-rep__hint"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
				</div>
				<?php
			}
		}
	}

	/**
	 * @param string                                  $field_id
	 * @param array<int, array<string, mixed>> $default_rows
	 */
	private function get_merged_json( $field_id, array $default_rows ): string {
		$post_id = $this->resolve_product_edit_post_id_for_repeater_read();
		if ( $post_id > 0 && function_exists( 'sto_get_post_option' ) ) {
			$from_post = sto_get_post_option( $field_id, $post_id, null );
			if ( null !== $from_post ) {
				return $this->registry_sanitize_posted_value(
					$field_id,
					is_string( $from_post ) ? $from_post : ( is_array( $from_post ) ? wp_json_encode( $from_post ) : '' )
				);
			}
		}

		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			return $default_rows !== array() ? wp_json_encode( array_values( $default_rows ) ) : $this->registry_sanitize_posted_value( $field_id, array() );
		}
		$stored = $saved[ $field_id ];

		return $this->registry_sanitize_posted_value(
			$field_id,
			is_string( $stored ) ? $stored : ( is_array( $stored ) ? wp_json_encode( $stored ) : '' )
		);
	}

	/**
	 * Product edit screen: read repeater JSON from per-post Theme Settings meta when present.
	 */
	private function resolve_product_edit_post_id_for_repeater_read(): int {
		if ( ! is_admin() ) {
			return 0;
		}

		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $post_id > 0 && get_post_type( $post_id ) === 'product' ) {
				return $post_id;
			}
		}

		global $post, $thepostid;
		if ( $post instanceof \WP_Post && $post->post_type === 'product' ) {
			return (int) $post->ID;
		}
		if ( ! empty( $thepostid ) ) {
			$post_id = (int) $thepostid;
			if ( $post_id > 0 && get_post_type( $post_id ) === 'product' ) {
				return $post_id;
			}
		}

		return 0;
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
			if ( ! is_array( $field ) || empty( $field['schema'] ) ) {
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
			$msg = $this->collect_html_required_messages_for_field( $field, $raw );
			foreach ( $msg as $m ) {
				$messages[] = $m;
			}
		}

		return $messages;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param mixed                $raw
	 * @return array<int, string>
	 */
	private function collect_html_required_messages_for_field( array $field, $raw ): array {
		$schema = $field['schema'];
		$items = $this->parse_items( is_string( $raw ) ? $raw : ( is_array( $raw ) ? wp_json_encode( $raw ) : '' ) );
		$items = $this->filter_storage_rows( $schema, $items );
		$out   = array();
		foreach ( $items as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$this->walk_html_required( $schema, $row, (int) $idx, $field['title'] ?? $field['id'], $out );
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<string, mixed>             $row
	 * @param int                              $row_index
	 * @param string                           $field_label
	 * @param array<int, string>               $out
	 */
	private function walk_html_required( array $schema, array $row, int $row_index, string $field_label, array &$out ) {
		foreach ( $schema as $node ) {
			$id = $node['id'];
			$t  = $node['type'];
			if ( $t === 'fieldset' ) {
				$sub = isset( $row[ $id ] ) && is_array( $row[ $id ] ) ? $row[ $id ] : array();
				$this->walk_html_required( $node['fields'], $sub, $row_index, $field_label, $out );
				continue;
			}
			if ( $t === 'advanced_repeater' ) {
				$lst = isset( $row[ $id ] ) && is_array( $row[ $id ] ) ? $row[ $id ] : array();
				$ti  = isset( $node['title'] ) ? (string) $node['title'] : $id;
				foreach ( $lst as $j => $inner ) {
					if ( is_array( $inner ) ) {
						$this->walk_html_required( $node['fields'], $inner, $j, $field_label . ' › ' . $ti, $out );
					}
				}
				continue;
			}
			if ( empty( $node['html_required'] ) ) {
				continue;
			}
			$val = isset( $row[ $id ] ) ? $row[ $id ] : '';
			$empty = ( is_string( $val ) && trim( $val ) === '' ) || $val === null;
			if ( $t === 'editor' ) {
				$empty = ! Input::classic_editor_html_has_meaningful_content( is_scalar( $val ) ? (string) $val : '' );
			}
			if ( $empty ) {
				if ( $t === 'text' && ! $this->repeater_row_has_meaningful_sibling_content( $schema, $row, $id ) ) {
					continue;
				}
				$stitle = isset( $node['title'] ) ? trim( (string) $node['title'] ) : $id;
				$out[]  = sprintf(
					/* translators: 1: repeater field title, 2: row number, 3: inner field title */
					__( '“%1$s” (row %2$d) — “%3$s” must be filled in before this section can be saved.', 'topten-simple-theme-options' ),
					$field_label,
					$row_index + 1,
					$stitle
				);
			}
		}
	}

	/**
	 * Whether another leaf in the same repeater row has non-empty stored content (e.g. block markup).
	 *
	 * @param array<int, array<string, mixed>> $schema
	 * @param array<string, mixed>             $row
	 * @param string                           $skip_leaf_id Leaf being validated.
	 */
	private function repeater_row_has_meaningful_sibling_content( array $schema, array $row, string $skip_leaf_id ): bool {
		foreach ( $schema as $node ) {
			$leaf_id = isset( $node['id'] ) ? sanitize_key( (string) $node['id'] ) : '';
			$type    = isset( $node['type'] ) ? sanitize_key( (string) $node['type'] ) : '';
			if ( $leaf_id === '' || $leaf_id === $skip_leaf_id ) {
				continue;
			}
			$stored_value = isset( $row[ $leaf_id ] ) ? $row[ $leaf_id ] : '';
			if ( $type === 'editor' ) {
				$html = is_scalar( $stored_value ) ? (string) $stored_value : '';
				if ( Input::classic_editor_html_has_meaningful_content( $html ) ) {
					return true;
				}
				continue;
			}
			if ( is_string( $stored_value ) && trim( $stored_value ) !== '' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string               $section_slug
	 * @param string               $field_id
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
	 * True when any registered repeater **`schema`** (including nested) uses **`icon_select`** leaves — used to localize the icon manifest even without standalone **`IconSelect`** fields.
	 */
	public function registry_schema_contains_icon_select(): bool {
		foreach ( $this->fields_by_id as $field ) {
			if ( empty( $field['schema'] ) || ! is_array( $field['schema'] ) ) {
				continue;
			}
			if ( $this->schema_tree_contains_icon_select( $field['schema'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $schema
	 */
	private function schema_tree_contains_icon_select( array $schema ): bool {
		foreach ( $schema as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$t = isset( $node['type'] ) ? sanitize_key( (string) $node['type'] ) : '';
			if ( $t === 'icon_select' ) {
				return true;
			}
			if ( $t === 'fieldset' && ! empty( $node['fields'] ) && is_array( $node['fields'] ) ) {
				if ( $this->schema_tree_contains_icon_select( $node['fields'] ) ) {
					return true;
				}
			}
			if ( $t === 'advanced_repeater' && ! empty( $node['fields'] ) && is_array( $node['fields'] ) ) {
				if ( $this->schema_tree_contains_icon_select( $node['fields'] ) ) {
					return true;
				}
			}
		}

		return false;
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
	 * @param string      $field_id
	 * @param string|null $json_or_scalar
	 * @return array<int, array<string, mixed>>
	 */
	public function get_items_for_field( $field_id, $json_or_scalar = null ): array {
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

		$parsed = json_decode( static::sanitize_posted_value( $field_id, $json ), true );

		return is_array( $parsed ) ? $parsed : array();
	}

	/**
	 * Collapsed row label: title-view field value, or "{prefix} {n}".
	 *
	 * @param string               $fallback_prefix e.g. "Item" or "Nested item".
	 * @param int                  $index           0-based row index.
	 * @param string               $title_view_key  Schema leaf id (empty = index only).
	 * @param array<string, mixed> $item_row
	 */
	private function format_row_toggle_label( string $fallback_prefix, int $index, string $title_view_key, array $item_row ): string {
		$title_view_key = sanitize_key( $title_view_key );
		if ( $title_view_key !== '' ) {
			$custom = $this->format_title_view_value( $item_row, $title_view_key );
			if ( $custom !== '' ) {
				return $custom;
			}
		}

		return trim( $fallback_prefix ) . ' ' . ( (int) $index + 1 );
	}

	/**
	 * @param array<string, mixed> $item_row
	 */
	private function format_title_view_value( array $item_row, string $title_view_key ): string {
		$raw = $this->find_item_value_by_key( $item_row, $title_view_key );
		if ( is_array( $raw ) ) {
			return '';
		}

		$text = trim( wp_strip_all_tags( (string) $raw ) );
		if ( $text === '' ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) > 100 ) {
				return mb_substr( $text, 0, 97 ) . '...';
			}

			return $text;
		}

		if ( strlen( $text ) > 100 ) {
			return substr( $text, 0, 97 ) . '...';
		}

		return $text;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return mixed|null
	 */
	private function find_item_value_by_key( array $data, string $key ) {
		if ( array_key_exists( $key, $data ) ) {
			return $data[ $key ];
		}

		foreach ( $data as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			$found = $this->find_item_value_by_key( $value, $key );
			if ( null !== $found && '' !== $found && array() !== $found ) {
				return $found;
			}
		}

		return null;
	}
}
