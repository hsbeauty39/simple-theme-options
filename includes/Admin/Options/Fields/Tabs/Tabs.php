<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Tabs;

use SimpleThemeOptions\Admin\Options\Fields\Accordion\Accordion;
use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\LayoutWidth;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\Group\Group;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\CheckboxControl\CheckboxControl;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Tabbed panel: one title row (with optional tooltip) + horizontal tabs; each tab repeats the same inner field set.
 * With **`responsive`**, each **inner** field renders its own **`ResponsiveControl`** toolbar + panes (independent breakpoint selection per column / control).
 *
 * Inner field ids in config are logical (e.g. `btn_url`); stored keys are **`{tabs_id}_{tab_key}_{logical_id}`** (flat `sto_options`), same save path as other fields.
 * May be registered **standalone** (`Tabs::register()`) or **inside a Group** (`type` => `tabs`). Optional **`responsive`** / **`device`** (same as Select/Range): each inner composite field is registered with per-breakpoint storage.
 *
 * **`fields`** may mix **leaf** controls, **`type` => `accordion`**, and **nested groups** (shape: **`id`**, **`title`**, **`fields`**, no **`options`**). Nested **Tabs** ids are prefixed with **`{tabs_id}_{tab_key}_`** when reusing short ids. Nested **Accordion** uses **`Accordion::register_nested()`**.
 *
 * Optional **`width`** on each inner item: **`1-2`**, **`1/2`**, **`1-3`**, **`2-3`**, **`1-1`** / **`full`** → 12-column grid (stacks full width on small screens).
 */
final class Tabs {
	use SingletonTrait;

	private const MAX_TABS       = 15;
	private const MAX_TREE_NODES = 80;
	private const MAX_NEST_DEPTH   = 6;

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $fields_by_section = array();

	/**
	 * `section_slug|tabs_id` => panel config
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $configs_by_key = array();

	/**
	 * @var array<string, true>
	 */
	private $registered_ids = array();

	protected function init() {
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), 19.45, 2 );
	}

	/**
	 * Register a standalone tabs field (same shape as Group inner `tabs` item, plus **`section_slug`**).
	 *
	 * @param array<string, mixed> $field
	 */
	public static function register( array $field ) {
		$instance = static::instance();
		FieldRegistrationDeferral::defer_or_run(
			function () use ( $instance, $field ) {
				if ( ! is_array( $field ) ) {
					return;
				}
				$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
				if ( $section_slug === '' ) {
					return;
				}
				$instance->register_tabs_field( $field, $section_slug, '', false, null, true );
			}
		);
	}

	/**
	 * Register a tabs panel from a Group `fields` item.
	 *
	 * Keys: `type` => **`tabs`**, **`id`**, **`title`**, **`tabs`**, **`fields`**, optional **`responsive`**, **`device`**, **`description`**, **`required`**, **`tooltip`** / **`tooltip_image`**, **`wrapper_class`**.
	 *
	 * @param array<string, mixed> $item
	 * @param string               $section_slug
	 * @param string               $parent_group_id
	 * @return bool
	 */
	public function register_from_group( array $item, $section_slug, $parent_group_id ) {
		return $this->register_tabs_field(
			$item,
			sanitize_key( (string) $section_slug ),
			sanitize_key( (string) $parent_group_id ),
			true,
			null,
			true
		);
	}

	/**
	 * @param array<string, mixed> $item
	 * @param string               $section_slug
	 * @param string               $parent_group_id Empty when standalone outer tabs.
	 * @param bool                 $from_group
	 * @param string|null          $field_group_for_inners Optional `group` stamped on registered inners (nested tabs).
	 * @param bool                 $enqueue_standalone When false, omit from **`fields_by_section`** (nested tabs).
	 * @return bool
	 */
	private function register_tabs_field( array $item, $section_slug, $parent_group_id, $from_group, $field_group_for_inners, $enqueue_standalone ) {
		$base_id = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
		if ( ! $section_slug || ! $base_id ) {
			return false;
		}
		if ( $from_group && $parent_group_id === '' ) {
			return false;
		}

		$config_key = $section_slug . '|' . $base_id;
		if ( ! empty( $this->registered_ids[ $config_key ] ) ) {
			return false;
		}

		$title = isset( $item['title'] ) ? (string) $item['title'] : '';
		if ( $title === '' ) {
			return false;
		}

		$tabs_raw = isset( $item['tabs'] ) && is_array( $item['tabs'] ) ? $item['tabs'] : array();
		$fields   = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
		if ( empty( $tabs_raw ) || empty( $fields ) ) {
			return false;
		}

		$tab_defs = array();
		foreach ( $tabs_raw as $trow ) {
			if ( ! is_array( $trow ) ) {
				continue;
			}
			$tk = isset( $trow['id'] ) ? sanitize_key( (string) $trow['id'] ) : '';
			$lb = isset( $trow['label'] ) ? (string) $trow['label'] : '';
			if ( $tk === '' || $lb === '' ) {
				continue;
			}
			$tab_defs[] = array(
				'id'    => $tk,
				'label' => $lb,
			);
			if ( count( $tab_defs ) >= self::MAX_TABS ) {
				break;
			}
		}

		if ( empty( $tab_defs ) ) {
			return false;
		}

		$g_for_reg = $field_group_for_inners !== null && $field_group_for_inners !== ''
			? sanitize_key( (string) $field_group_for_inners )
			: ( $from_group ? $parent_group_id : $base_id );

		$tab_trees = array();
		$counter   = 0;
		foreach ( $tab_defs as $tab ) {
			$tk   = $tab['id'];
			$tree = $this->register_tabs_field_tree( $fields, $section_slug, $base_id, $tk, $g_for_reg, $item, 0, $counter );
			if ( empty( $tree ) ) {
				return false;
			}
			$tab_trees[ $tk ] = $tree;
		}

		$config = array(
			'section_slug'            => $section_slug,
			'id'                      => $base_id,
			'title'                   => $title,
			'description'             => isset( $item['description'] ) ? (string) $item['description'] : '',
			'required'                => isset( $item['required'] ) && is_array( $item['required'] ) ? $item['required'] : array(),
			'tooltip'                 => isset( $item['tooltip'] ) ? $item['tooltip'] : null,
			'tooltip_image'           => isset( $item['tooltip_image'] ) ? (string) $item['tooltip_image'] : '',
			'tooltip_preloader'       => isset( $item['tooltip_preloader'] ) ? (string) $item['tooltip_preloader'] : '',
			'tab_defs'                => $tab_defs,
			'tab_trees'               => $tab_trees,
			'wrapper_class'           => isset( $item['wrapper_class'] ) ? (string) $item['wrapper_class'] : '',
			'responsive_breakpoints'  => ResponsiveConfig::breakpoints_for_field( $item ),
			'from_group'              => $from_group,
			'group'                   => $parent_group_id,
		);

		$this->configs_by_key[ $config_key ]  = $config;
		$this->registered_ids[ $config_key ] = true;

		if ( $enqueue_standalone && ! $from_group ) {
			if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
				$this->fields_by_section[ $section_slug ] = array();
			}
			$this->fields_by_section[ $section_slug ][] = $config;
		}

		return true;
	}

	/**
	 * @param array<int, mixed>      $fields
	 * @param string                 $section_slug
	 * @param string                 $tabs_base_id
	 * @param string                 $tab_key
	 * @param string                 $group_for_inners
	 * @param array<string, mixed>   $tabs_parent_cfg
	 * @param int                    $depth
	 * @param int                    $counter
	 * @return array<int, array<string, mixed>>
	 */
	private function register_tabs_field_tree( array $fields, $section_slug, $tabs_base_id, $tab_key, $group_for_inners, array $tabs_parent_cfg, $depth, &$counter ) {
		$nodes = array();
		foreach ( $fields as $inner ) {
			if ( ! is_array( $inner ) || $counter >= self::MAX_TREE_NODES ) {
				break;
			}

			$span = $this->parse_layout_width( isset( $inner['width'] ) ? $inner['width'] : null );

			if ( $this->is_tabs_item_shape( $inner ) && $depth < self::MAX_NEST_DEPTH ) {
				$t       = $inner;
				$logical = isset( $t['id'] ) ? sanitize_key( (string) $t['id'] ) : '';
				if ( $logical === '' ) {
					continue;
				}
				$t['id'] = $this->scoped_tabs_block_id( $tabs_base_id, $tab_key, $logical );
				if ( ! $this->register_tabs_field( $t, $section_slug, $group_for_inners, true, $t['id'], false ) ) {
					continue;
				}
				$nodes[] = array(
					'node' => 'tabs',
					'id'   => $t['id'],
					'span' => $span,
				);
				++$counter;
				continue;
			}

			if ( $this->is_accordion_item_shape( $inner ) && $depth < self::MAX_NEST_DEPTH ) {
				$a       = $inner;
				$logical = isset( $a['id'] ) ? sanitize_key( (string) $a['id'] ) : '';
				if ( $logical === '' ) {
					continue;
				}
				$a['id'] = $this->scoped_tabs_block_id( $tabs_base_id, $tab_key, $logical );
				if ( ! Accordion::instance()->register_nested( $a, $section_slug, $group_for_inners, $a['id'], false ) ) {
					continue;
				}
				$nodes[] = array(
					'node' => 'accordion',
					'id'   => $a['id'],
					'span' => $span,
				);
				++$counter;
				continue;
			}

			if ( $this->is_nested_subgroup_shape( $inner ) ) {
				$child_id          = $this->scoped_tabs_block_id( $tabs_base_id, $tab_key, sanitize_key( (string) $inner['id'] ) );
				$child_title       = isset( $inner['title'] ) ? (string) $inner['title'] : '';
				$child_description = isset( $inner['description'] ) ? (string) $inner['description'] : '';
				$child_required    = isset( $inner['required'] ) && is_array( $inner['required'] ) ? $inner['required'] : array();
				$child_fields      = isset( $inner['fields'] ) && is_array( $inner['fields'] ) ? $inner['fields'] : array();
				if ( $child_title === '' || empty( $child_fields ) ) {
					continue;
				}
				$child_nodes = Group::instance()->build_nodes_for_parent( $section_slug, $child_id, $child_fields );
				if ( empty( $child_nodes ) ) {
					continue;
				}
				Group::instance()->index_group_for_accordion( $section_slug, $child_id, $child_title, $tabs_base_id );

				$nested = array(
					'node'        => 'group',
					'id'          => $child_id,
					'title'       => $child_title,
					'description' => $child_description,
					'required'    => $child_required,
					'nodes'       => $child_nodes,
					'span'        => $span,
				);
				if ( ! empty( $inner['tooltip'] ) && is_array( $inner['tooltip'] ) ) {
					$nested['tooltip'] = $inner['tooltip'];
				}
				if ( ! empty( $inner['tooltip_image'] ) ) {
					$nested['tooltip_image'] = (string) $inner['tooltip_image'];
				}
				if ( ! empty( $inner['tooltip_preloader'] ) ) {
					$nested['tooltip_preloader'] = (string) $inner['tooltip_preloader'];
				}
				$nodes[] = $nested;
				++$counter;
				continue;
			}

			$logical_id = isset( $inner['id'] ) ? sanitize_key( (string) $inner['id'] ) : '';
			if ( $logical_id === '' ) {
				continue;
			}

			$clone = $inner;
			unset( $clone['width'], $clone['layout'] );

			$composite = $tabs_base_id . '_' . $tab_key . '_' . $logical_id;
			$clone_reg = $clone;
			$clone_reg['id']           = $composite;
			$clone_reg['section_slug'] = $section_slug;
			$clone_reg['group']        = $group_for_inners;
			$clone_reg[ ResponsiveConfig::TABS_INNER_FIELD ] = true;
			$this->inherit_responsive_from_tabs_parent( $tabs_parent_cfg, $clone_reg );

			$kind = $this->register_inner_field( $clone_reg );
			if ( $kind === '' ) {
				continue;
			}

			$nodes[] = array(
				'node'    => 'leaf',
				'kind'    => $kind,
				'logical' => $logical_id,
				'span'    => $span,
			);
			++$counter;
		}

		return $nodes;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function is_tabs_item_shape( $item ) {
		return is_array( $item )
			&& isset( $item['type'] )
			&& sanitize_key( (string) $item['type'] ) === 'tabs'
			&& ! empty( $item['id'] )
			&& ! empty( $item['tabs'] )
			&& is_array( $item['tabs'] )
			&& ! empty( $item['fields'] )
			&& is_array( $item['fields'] );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function is_accordion_item_shape( $item ) {
		return is_array( $item )
			&& isset( $item['type'] )
			&& sanitize_key( (string) $item['type'] ) === 'accordion'
			&& ! empty( $item['id'] )
			&& ! empty( $item['panels'] )
			&& is_array( $item['panels'] )
			&& ! empty( $item['fields'] )
			&& is_array( $item['fields'] );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function is_nested_subgroup_shape( $item ) {
		if ( ! is_array( $item ) || ( isset( $item['type'] ) && sanitize_key( (string) $item['type'] ) === 'tabs' ) ) {
			return false;
		}
		if ( isset( $item['type'] ) && sanitize_key( (string) $item['type'] ) === 'accordion' ) {
			return false;
		}
		if ( isset( $item['options'] ) && is_array( $item['options'] ) ) {
			return false;
		}

		return isset( $item['fields'] ) && is_array( $item['fields'] ) && ! empty( $item['id'] );
	}

	/**
	 * @param string $tabs_base_id
	 * @param string $tab_key
	 * @param string $logical
	 */
	private function scoped_tabs_block_id( $tabs_base_id, $tab_key, $logical ) {
		return sanitize_key( (string) $tabs_base_id ) . '_' . sanitize_key( (string) $tab_key ) . '_' . sanitize_key( (string) $logical );
	}

	/**
	 * @param array<string, mixed> $parent Tabs field config.
	 * @param array<string, mixed> $clone_reg Inner field config (modified in place).
	 */
	private function inherit_responsive_from_tabs_parent( array $parent, array &$clone_reg ) {
		if ( empty( $parent['responsive'] ) ) {
			return;
		}
		if ( ! empty( $clone_reg['responsive'] ) ) {
			return;
		}
		$clone_reg['responsive'] = $parent['responsive'];
		$clone_reg['device']     = isset( $parent['device'] ) && is_array( $parent['device'] ) ? $parent['device'] : array();
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
			if ( ! is_array( $field ) || ! empty( $field['from_group'] ) ) {
				continue;
			}
			$this->render_field_markup( $field, 'default' );
		}
	}

	/**
	 * Backwards-compatible alias — delegates to the shared 12-column grammar in
	 * **`Common\LayoutWidth::parse_span()`**, which is also consumed by **Group** inner items.
	 * Kept as an instance method so any third-party caller of **`Tabs::instance()->parse_layout_width()`**
	 * keeps working unchanged.
	 *
	 * @param mixed $raw e.g. `1-2`, `1/3`, `2/3`, `full`, integer 1–12, `1-1`, `100%`.
	 * @return int Grid span in **`1..12`** (defaults to **12** when empty / invalid).
	 */
	public function parse_layout_width( $raw ) {
		return LayoutWidth::parse_span( $raw );
	}

	/**
	 * @param array<string, mixed> $inner
	 * @return bool
	 */
	private function is_input_like( array $inner ) {
		if ( isset( $inner['type'] ) && sanitize_key( (string) $inner['type'] ) === 'button_group' ) {
			return false;
		}
		if ( isset( $inner['type'] ) && sanitize_key( (string) $inner['type'] ) === 'input' ) {
			$it = isset( $inner['input_type'] ) ? sanitize_key( (string) $inner['input_type'] ) : '';

			return $it !== '' && in_array( $it, Input::INPUT_TYPES, true );
		}
		if ( ! isset( $inner['type'] ) ) {
			return false;
		}
		$t = sanitize_key( (string) $inner['type'] );

		return in_array( $t, Input::INPUT_TYPES, true );
	}

	/**
	 * @param array<string, mixed> $inner
	 */
	private function is_code_editor_type( array $inner ) {
		if ( empty( $inner['id'] ) ) {
			return false;
		}
		$type = isset( $inner['type'] ) ? sanitize_key( (string) $inner['type'] ) : '';

		return in_array( $type, array( 'code_editor', 'codeeditor', 'code' ), true );
	}

	/**
	 * @param array<string, mixed> $clone_reg
	 * @return string Registered kind or empty on failure
	 */
	private function register_inner_field( array $clone_reg ) {
		$section = $clone_reg['section_slug'];
		$fid     = isset( $clone_reg['id'] ) ? sanitize_key( (string) $clone_reg['id'] ) : '';
		if ( ! $fid ) {
			return '';
		}

		if ( isset( $clone_reg['type'] ) && $clone_reg['type'] === 'typography' ) {
			Typography::register( $clone_reg );

			return Typography::get_field( $section, $fid ) ? 'typography' : '';
		}
		if ( isset( $clone_reg['type'] ) && $clone_reg['type'] === 'color' ) {
			Color::register( $clone_reg );

			return Color::get_field( $section, $fid ) ? 'color' : '';
		}
		if ( isset( $clone_reg['type'] ) && $clone_reg['type'] === 'background_control' ) {
			BackgroundControl::register( $clone_reg );

			return BackgroundControl::get_field( $section, $fid ) ? 'background_control' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'border' ) {
			BorderControl::register( $clone_reg );

			return BorderControl::get_field( $section, $fid ) ? 'border' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'shadow' ) {
			ShadowControl::register( $clone_reg );

			return ShadowControl::get_field( $section, $fid ) ? 'shadow' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'gradient' ) {
			GradientControl::register( $clone_reg );

			return GradientControl::get_field( $section, $fid ) ? 'gradient' : '';
		}
		if ( isset( $clone_reg['type'] ) && $clone_reg['type'] === 'switcher' ) {
			Switcher::register( $clone_reg );

			return Switcher::get_field( $section, $fid ) ? 'switcher' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'checkbox' ) {
			CheckboxControl::register( $clone_reg );

			return CheckboxControl::get_field( $section, $fid ) ? 'checkbox' : '';
		}
		if ( isset( $clone_reg['type'] ) && $clone_reg['type'] === 'image_select' && ! empty( $clone_reg['options'] ) && is_array( $clone_reg['options'] ) ) {
			ImageSelect::register( $clone_reg );

			return ImageSelect::get_field( $section, $fid ) ? 'image_select' : '';
		}
		if ( isset( $clone_reg['type'] ) && $clone_reg['type'] === 'dynamic_object' && ! empty( $clone_reg['post_type'] ) ) {
			DynamicObject::register( $clone_reg );

			return DynamicObject::get_field( $section, $fid ) ? 'dynamic_object' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'range' ) {
			Range::register( $clone_reg );

			return Range::get_field( $section, $fid ) ? 'range' : '';
		}
		if ( isset( $clone_reg['type'] ) && $clone_reg['type'] === 'button_group' && ! empty( $clone_reg['options'] ) && is_array( $clone_reg['options'] ) ) {
			ButtonGroup::register( $clone_reg );

			return ButtonGroup::get_field( $section, $fid ) ? 'button_group' : '';
		}
		if ( $this->is_code_editor_type( $clone_reg ) ) {
			CodeEditor::register( $clone_reg );

			return CodeEditor::get_field( $section, $fid ) ? 'code_editor' : '';
		}
		if ( isset( $clone_reg['type'] ) && $clone_reg['type'] === 'link_color' ) {
			LinkColor::register( $clone_reg );

			return LinkColor::get_field( $section, $fid ) ? 'link_color' : '';
		}

		$ty = isset( $clone_reg['type'] ) ? sanitize_key( (string) $clone_reg['type'] ) : '';
		if ( ! empty( $clone_reg['options'] ) && is_array( $clone_reg['options'] ) && ( $ty === '' || $ty === 'select' ) ) {
			Select::register( $clone_reg );

			return Select::get_field( $section, $fid ) ? 'select' : '';
		}

		if ( $this->is_input_like( $clone_reg ) ) {
			Input::register( $clone_reg );

			return Input::get_field( $section, $fid ) ? 'input' : '';
		}

		return '';
	}

	/**
	 * @param string $section_slug
	 * @param string $tabs_id
	 * @return array<string, mixed>|null
	 */
	public function get_config( $section_slug, $tabs_id ) {
		$section_slug = sanitize_key( (string) $section_slug );
		$tabs_id      = sanitize_key( (string) $tabs_id );
		$key          = $section_slug . '|' . $tabs_id;

		return $this->configs_by_key[ $key ] ?? null;
	}

	/**
	 * @param string $section_slug
	 * @param string $tabs_id
	 */
	public function render_panel( $section_slug, $tabs_id ) {
		$config = $this->get_config( sanitize_key( (string) $section_slug ), sanitize_key( (string) $tabs_id ) );
		if ( is_array( $config ) ) {
			$this->render_field_markup( $config, 'group_inner' );
		}
	}

	/**
	 * @param array<string, mixed>    $config Stored tabs panel config.
	 * @param 'default'|'group_inner' $context Row chrome: standalone section vs group inner.
	 */
	public function render_field_markup( array $config, $context = 'default' ) {
		$section_slug = isset( $config['section_slug'] ) ? sanitize_key( (string) $config['section_slug'] ) : '';
		$tabs_id      = isset( $config['id'] ) ? sanitize_key( (string) $config['id'] ) : '';
		if ( ! $section_slug || ! $tabs_id || empty( $config['tab_defs'] ) || empty( $config['tab_trees'] ) ) {
			return;
		}

		$required      = isset( $config['required'] ) && is_array( $config['required'] ) ? $config['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$desc          = isset( $config['description'] ) ? (string) $config['description'] : '';

		$tooltip_cfg = FieldTitle::get_tooltip_config( $config );
		if ( ! $tooltip_cfg && ! empty( $config['tooltip_image'] ) ) {
			$tooltip_cfg = FieldTitle::get_tooltip_config(
				array(
					'tooltip_image'     => $config['tooltip_image'],
					'tooltip_preloader' => $config['tooltip_preloader'] ?? '',
				)
			);
		}

		$breakpoints = isset( $config['responsive_breakpoints'] ) && is_array( $config['responsive_breakpoints'] ) ? $config['responsive_breakpoints'] : array();
		$breakpoints = array_values( array_filter( array_map( 'sanitize_key', $breakpoints ) ) );

		$is_row_inner = ( 'group_inner' === $context );
		$from_group  = ! empty( $config['from_group'] );
		$inner_ctx    = $from_group ? 'group_inner' : 'default';

		$row_classes = array( 'sto-field-row', 'sto-field-row-tabs' );
		$wc          = isset( $config['wrapper_class'] ) ? trim( (string) $config['wrapper_class'] ) : '';
		if ( $wc !== '' ) {
			$row_classes[] = $wc;
		}
		if ( $is_row_inner ) {
			$row_classes[] = 'sto-field-row--in-group';
		}
		if ( ! empty( $breakpoints ) ) {
			$row_classes[] = 'sto-field-row--responsive';
		}

		$tab_defs        = $config['tab_defs'];
		$tab_list_main   = empty( $breakpoints ) ? $this->build_tab_list_markup( $tabs_id, $tab_defs, '' ) : '';
		$tab_list_master = '';
		if ( ! empty( $breakpoints ) ) {
			$tab_list_master = $this->build_tab_list_markup( $tabs_id, $tab_defs, '', true, '' );
		}
		$heading_trail = empty( $breakpoints ) ? $tab_list_main : '';

		$title = isset( $config['title'] ) ? (string) $config['title'] : '';
		?>
		<div
			id="<?php echo esc_attr( 'sto-field-tabs-' . $tabs_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
			data-sto-field-id="<?php echo esc_attr( $tabs_id ); ?>"
			<?php if ( empty( $breakpoints ) ) : ?>
				data-sto-tabs="1"
				data-sto-tabs-base="<?php echo esc_attr( $tabs_id ); ?>"
			<?php endif; ?>
			<?php if ( $required_json ) : ?>
				data-sto-required="<?php echo esc_attr( $required_json ); ?>"
			<?php endif; ?>
			<?php if ( ! empty( $breakpoints ) ) : ?>
				data-sto-tabs-master="1"
			<?php endif; ?>
		>
			<?php
			if ( $title || $heading_trail !== '' || $tab_list_master !== '' ) {
				FieldTitle::render_heading( $title, $context, $tooltip_cfg, $tabs_id, false, $heading_trail, $tab_list_master );
			}

			$dom_base = $this->tabs_dom_base( $tabs_id, '' );
			$this->render_tabs_stack( $section_slug, $tabs_id, $config, $inner_ctx, $dom_base, null );

			if ( $desc !== '' ) {
				echo '<p class="sto-field-description">' . esc_html( $desc ) . '</p>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param string               $section_slug
	 * @param string               $tabs_id
	 * @param array<string, mixed> $config
	 * @param 'default'|'group_inner' $inner_ctx
	 * @param string               $dom_base Sanitized base for tab/pane element ids.
	 * @param string|null          $parent_device_bp When set, inner responsive fields render one slice only (no nested device toolbar). **`Tabs`** passes **`null`** so each inner field owns its own breakpoint UI.
	 */
	private function render_tabs_stack( $section_slug, $tabs_id, array $config, $inner_ctx, $dom_base, $parent_device_bp = null ) {
		$tab_defs   = $config['tab_defs'];
		$tab_trees  = isset( $config['tab_trees'] ) && is_array( $config['tab_trees'] ) ? $config['tab_trees'] : array();
		?>
		<div class="sto-tabs" id="<?php echo esc_attr( $dom_base ); ?>">
			<div class="sto-tabs__body">
				<?php
				foreach ( $tab_defs as $ti => $tab ) :
					$tk      = $tab['id'];
					$pane_id = $dom_base . '-pane-' . $tk;
					$hidden  = 0 !== (int) $ti;
					$inner_tree = isset( $tab_trees[ $tk ] ) && is_array( $tab_trees[ $tk ] ) ? $tab_trees[ $tk ] : array();
					?>
					<div
						class="sto-tabs__pane"
						id="<?php echo esc_attr( $pane_id ); ?>"
						role="tabpanel"
						data-sto-tabs-pane="<?php echo esc_attr( $tk ); ?>"
						<?php echo $hidden ? 'hidden' : ''; ?>
					>
						<div class="sto-tabs__grid">
							<?php
							foreach ( $inner_tree as $tpl ) {
								$this->render_tabs_tree_node( $section_slug, $tabs_id, $tk, $tpl, $inner_ctx, $parent_device_bp );
							}
							?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $tpl
	 * @param 'default'|'group_inner' $inner_ctx
	 * @param string|null          $parent_device_bp
	 */
	private function render_tabs_tree_node( $section_slug, $tabs_id, $tab_key, array $tpl, $inner_ctx, $parent_device_bp ) {
		$span = isset( $tpl['span'] ) ? (int) $tpl['span'] : 12;
		if ( $span < 1 ) {
			$span = 12;
		}
		if ( $span > 12 ) {
			$span = 12;
		}

		$node = isset( $tpl['node'] ) ? (string) $tpl['node'] : '';

		if ( $node === 'leaf' ) {
			$logical = isset( $tpl['logical'] ) ? sanitize_key( (string) $tpl['logical'] ) : '';
			$kind    = isset( $tpl['kind'] ) ? (string) $tpl['kind'] : '';
			if ( $logical === '' || $kind === '' ) {
				return;
			}
			$cid = $tabs_id . '_' . $tab_key . '_' . $logical;
			?>
			<div class="sto-tabs__cell sto-tabs__cell--span-<?php echo esc_attr( (string) $span ); ?>">
				<?php $this->render_inner_by_kind( $section_slug, $cid, $kind, $inner_ctx, $parent_device_bp ); ?>
			</div>
			<?php
			return;
		}

		if ( $node === 'tabs' && ! empty( $tpl['id'] ) ) {
			$tid = sanitize_key( (string) $tpl['id'] );
			$tc  = $this->get_config( $section_slug, $tid );
			?>
			<div class="sto-tabs__cell sto-tabs__cell--span-<?php echo esc_attr( (string) $span ); ?> sto-tabs__cell--embed">
				<?php
				if ( is_array( $tc ) ) {
					$this->render_field_markup( $tc, $inner_ctx );
				}
				?>
			</div>
			<?php
			return;
		}

		if ( $node === 'accordion' && ! empty( $tpl['id'] ) ) {
			$aid = sanitize_key( (string) $tpl['id'] );
			$ac  = Accordion::instance()->get_config( $section_slug, $aid );
			?>
			<div class="sto-tabs__cell sto-tabs__cell--span-<?php echo esc_attr( (string) $span ); ?> sto-tabs__cell--embed">
				<?php
				if ( is_array( $ac ) ) {
					Accordion::instance()->render_field_markup( $ac, $inner_ctx );
				}
				?>
			</div>
			<?php
			return;
		}

		if ( $node === 'group' ) {
			$child_group = array(
				'id'          => $tpl['id'] ?? '',
				'title'       => $tpl['title'] ?? '',
				'description' => isset( $tpl['description'] ) ? (string) $tpl['description'] : '',
				'required'    => isset( $tpl['required'] ) && is_array( $tpl['required'] ) ? $tpl['required'] : array(),
				'nodes'       => isset( $tpl['nodes'] ) && is_array( $tpl['nodes'] ) ? $tpl['nodes'] : array(),
			);
			if ( ! empty( $tpl['tooltip'] ) && is_array( $tpl['tooltip'] ) ) {
				$child_group['tooltip'] = $tpl['tooltip'];
			}
			if ( ! empty( $tpl['tooltip_image'] ) ) {
				$child_group['tooltip_image'] = (string) $tpl['tooltip_image'];
			}
			if ( ! empty( $tpl['tooltip_preloader'] ) ) {
				$child_group['tooltip_preloader'] = (string) $tpl['tooltip_preloader'];
			}
			?>
			<div class="sto-tabs__cell sto-tabs__cell--span-<?php echo esc_attr( (string) $span ); ?> sto-tabs__cell--embed">
				<?php Group::instance()->render_group_branch( $section_slug, $child_group, 1 ); ?>
			</div>
			<?php
		}
	}

	/**
	 * @param string $tabs_id
	 * @param string $suffix_extra Sanitized breakpoint or empty for non-responsive.
	 */
	private function tabs_dom_base( $tabs_id, $suffix_extra ) {
		$tabs_id = preg_replace( '/[^a-z0-9_-]/i', '', (string) $tabs_id );
		$sfx     = $suffix_extra !== '' ? '-' . sanitize_key( (string) $suffix_extra ) : '';

		return 'sto-tabs-' . $tabs_id . $sfx;
	}

	/**
	 * @param string               $section_slug
	 * @param array<int, array{id:string,label:string}> $tab_defs
	 * @param string               $suffix_extra Optional suffix for unique ids (e.g. breakpoint).
	 * @param bool                 $master_sync When true, toolbar drives every breakpoint slice (see **`sto-tabs.js`**); buttons use **`data-sto-sync-tab`** instead of **`data-sto-tabs-target`**.
	 * @param string               $master_aria_bp First breakpoint slug (for **`aria-controls`** on master buttons).
	 * @return string
	 */
	private function build_tab_list_markup( $tabs_id, array $tab_defs, $suffix_extra = '', $master_sync = false, $master_aria_bp = '' ) {
		$master_sync = (bool) $master_sync;
		if ( $master_sync ) {
			$tabs_clean = preg_replace( '/[^a-z0-9_-]/i', '', (string) $tabs_id );
			$dom_base   = 'sto-tabs-master-' . $tabs_clean;
			$pane_base  = $this->tabs_dom_base( $tabs_id, '' );
		} else {
			$dom_base = $this->tabs_dom_base( $tabs_id, (string) $suffix_extra );
		}
		ob_start();
		?>
		<div class="sto-tabs__toolbar<?php echo $master_sync ? ' sto-tabs__toolbar--sto-master' : ''; ?>" role="tablist" aria-label="<?php esc_attr_e( 'Tabs', 'simple-theme-options' ); ?>">
			<?php foreach ( $tab_defs as $ti => $tab ) : ?>
				<?php
				$tk      = $tab['id'];
				$active  = 0 === (int) $ti;
				if ( $master_sync ) {
					$pane_id = $pane_base . '-pane-' . $tk;
				} else {
					$pane_id = $dom_base . '-pane-' . $tk;
				}
				?>
				<button
					type="button"
					class="sto-tabs__tab<?php echo $active ? ' sto-is-active' : ''; ?><?php echo $master_sync ? ' sto-tabs__tab--master-sync' : ''; ?>"
					role="tab"
					id="<?php echo esc_attr( $dom_base . '-tab-' . $tk ); ?>"
					<?php if ( $master_sync ) : ?>
						data-sto-sync-tab="<?php echo esc_attr( $tk ); ?>"
					<?php else : ?>
						data-sto-tabs-target="<?php echo esc_attr( $pane_id ); ?>"
					<?php endif; ?>
					aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
					aria-controls="<?php echo esc_attr( $pane_id ); ?>"
					tabindex="<?php echo $active ? '0' : '-1'; ?>"
				><?php echo esc_html( $tab['label'] ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed>|null $f
	 * @param string|null               $parent_device_bp Parent responsive Tabs pane key.
	 * @return array<string, mixed>|null
	 */
	private function with_tabs_responsive_pane_bp( $f, $parent_device_bp ) {
		if ( ! is_array( $f ) || $parent_device_bp === null || $parent_device_bp === '' ) {
			return $f;
		}
		$bp = sanitize_key( (string) $parent_device_bp );
		if ( $bp === '' || empty( $f['responsive_breakpoints'] ) || ! is_array( $f['responsive_breakpoints'] ) ) {
			return $f;
		}
		$list = array_map( 'sanitize_key', $f['responsive_breakpoints'] );
		if ( ! in_array( $bp, $list, true ) ) {
			return $f;
		}
		$out                                              = $f;
		$out[ ResponsiveConfig::PARENT_RESPONSIVE_PANE_BP ] = $bp;

		return $out;
	}

	/**
	 * @param string                    $section_slug
	 * @param string                    $composite_id
	 * @param string                    $kind
	 * @param 'default'|'group_inner'   $inner_ctx
	 * @param string|null               $parent_device_bp When set (responsive Tabs), suppress nested responsive chrome on inner fields.
	 */
	private function render_inner_by_kind( $section_slug, $composite_id, $kind, $inner_ctx = 'group_inner', $parent_device_bp = null ) {
		switch ( $kind ) {
			case 'typography':
				$f = Typography::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					Typography::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'color':
				$f = Color::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					Color::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'background_control':
				$f = BackgroundControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					BackgroundControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'border':
				$f = BorderControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					BorderControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'shadow':
				$f = ShadowControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					ShadowControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'gradient':
				$f = GradientControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					GradientControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'switcher':
				$f = Switcher::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					Switcher::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'checkbox':
				$f = CheckboxControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					CheckboxControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'image_select':
				$f = ImageSelect::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					ImageSelect::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'dynamic_object':
				$f = DynamicObject::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					DynamicObject::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'button_group':
				$f = ButtonGroup::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					ButtonGroup::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'range':
				$f = Range::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					Range::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'select':
				$f = Select::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					Select::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'code_editor':
				$f = CodeEditor::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					CodeEditor::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'link_color':
				$f = LinkColor::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					LinkColor::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			default:
				$f = Input::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_tabs_responsive_pane_bp( $f, $parent_device_bp );
					Input::instance()->render_field_markup( $f, $inner_ctx );
				}
		}
	}
}
