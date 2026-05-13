<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Group;

use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\LayoutWidth;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\CheckboxControl\CheckboxControl;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\Accordion\Accordion;
use SimpleThemeOptions\Admin\Options\Fields\Tabs\Tabs;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Group {
	use SingletonTrait;

	/**
	 * Root groups per section: each item has id, title, description, required, nodes.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $groups_by_section = array();

	/**
	 * Flat index: section_slug => group_id => title + parent_id (for breadcrumbs / search).
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private $groups_index = array();

	protected function init() {
		add_action( 'sto_render_section_content', array( $this, 'render_groups' ), 21, 2 );
	}

	/**
	 * Register a grouped block of fields. `fields` may contain:
	 * - Select configs (same as Select::register minus section_slug) — must include `options`; optional **`responsive`**, **`device`** (merged with default breakpoint trio; see **`ResponsiveConfig::breakpoints_for_field()`**).
	 * - Typography: `type` => `typography`, plus `id`, `title`, optional `description`, `default`, `required`, optional **`responsive`**, **`device`**.
	 * - Color: `type` => `color`, plus `id`, `title`, optional `description`, `default` (hex), `palettes`, `required`, optional **`responsive`**, **`device`**.
	 * - Background control: `type` => `background_control`, plus `id`, `title`, optional `description`, `default` => array( `color`, `image_id` ), `palettes`, `alpha`, `required`, `tooltip`, optional **`responsive`**, **`device`**.
	 * - **Border:** `type` => **`border`**, **`id`**, **`title`**, optional **`description`**, optional **`default`** => array( **`radius`**, **`radius_unit`**, **`style`** (one of **`none`**, **`solid`**, **`dashed`**, **`dotted`**, **`double`**, **`groove`**, **`ridge`**, **`inset`**, **`outset`**), **`width`**, **`width_unit`**, **`color`** ), optional **`features`** => ordered subset of **`radius`**, **`style`**, **`width`**, **`color`** (default: all four; admin omits sections by passing fewer), optional **`min_radius`** / **`max_radius`** / **`min_width`** / **`max_width`** (integer clamps), optional **`radius_units`** => subset of **`px`**, **`%`**, **`em`**, **`rem`** (default **`array( 'px' )`** = locked chip), optional **`width_units`** => subset of **`px`**, **`em`**, **`rem`** (default **`array( 'px' )`**), **`alpha`** (bool, default **true**), **`palettes`**, **`required`**, **`tooltip`**, optional **`responsive`**, **`device`**. Stored as JSON in **`sto_options[id]`** (or **`sto_options[id][bp]`** when responsive).
	 * - **Shadow:** `type` => **`shadow`**, **`id`**, **`title`**, optional **`description`**, optional **`default`** => array( **`selector`** (CSS target — **set in PHP only**, not shown in admin), **`color`**, **`horizontal`**, **`vertical`**, **`blur`**, **`spread`**, **`position`** (**`outline`** | **`inset`**) ) or top-level **`selector`** string merged into defaults, optional **`popup`** (bool — **UI only**; when **true**, detailed controls open in a popover; when **false**, controls stay inline), optional **`palettes`**, **`required`**, **`tooltip`**, optional **`responsive`**, **`device`**. Stored as JSON in **`sto_options[id]`** (same responsive map pattern as Border when enabled).
	 * - Switcher: `type` => `switcher`, plus `id`, `title`, optional `description`, `default` (`1`|`0`), `labels`, `required`, `tooltip`, optional **`responsive`**, **`device`**.
	 * - **Checkbox / multi-check:** `type` => **`checkbox`**, **`id`**, **`title`**, optional **`description`**, **`multiple`** (bool). Single: **`default`** `1`|`0`, optional **`labels`** (`on` / `off`). Multi: **`options`** (value => label or `label`+`tooltip`), **`default`** (array of keys), optional **`max`**, **`columns`** (1–6), optional **`responsive`**, **`device`**.
	 * - Image select: `type` => `image_select`, plus `id`, `title`, `options` (value => `label` string or array with `label`, `preset`, optional `image`), `default`, `required`, `tooltip`, optional **`responsive`**, **`device`**.
	 * - Dynamic object: `type` => `dynamic_object`, plus `id`, `title`, `post_type`, optional `multiple` (bool), `max` (max selections), `placeholder`, `limit` (max posts per AJAX page, default **10**), `search_min_length` (default **3**), `post_status`, `default` (string or array of ids), `required`, `tooltip`, optional **`responsive`**, **`device`**.
	 * - Input: `type` => `text`|`number`|`textarea`|`editor`|`email`|`phone`|`search` (or `type` => `input` with `input_type` set to one of those), plus `id`, `title`, optional `description`, `default`, `placeholder` (all types; editor sets textarea placeholder), optional **`html_required`** (HTML5 `required`, separate from conditional `required`), `min`/`max`/`step` (number), `rows`/`cols` (textarea), `editor_height`/`media_buttons`/`teeny`/`drag_drop_upload` (editor), optional **`toolbar_end`** on **editor** => `array( 'label', 'tooltip', 'snippet' )` (TinyMCE row-1 after kitchen sink), conditional **`required`**, `tooltip`, optional **`responsive`**, **`device`** (not for **`editor`**).
	 * - **Button group:** `type` => **`button_group`**, `id`, `title`, **`options`** (value => label string **or** array with **`label`**, optional **`tooltip`** (plain text on segment **`?`** when no **`preview_image`**), optional **`preview_image`** URL shown in the same floating image popover as **`FieldTitle`** on segment **`?` hover**), optional **`default`**, `description`, conditional **`required`**, row **`tooltip`** / **`tooltip_image`** via **`FieldTitle`**, optional **`responsive`**, **`device`**.
	 * - **Range:** `type` => **`range`**, `id`, `title`, optional `description`, `default` (number, `760px`-style string, or array `v` / `u` / `c`), numeric `min` / `max` / `step`, optional **`units`** => ordered non-empty subset of **`px`**, **`%`**, **`rem`**, **`em`**, **`custom`** (default: all five; one entry = locked unit; **`custom`** alone = suffix field only), optional **`unit_label`** (UI-only text after the number, e.g. `PAGE`; with **`units` => array( 'custom' )** hides the suffix field and CUSTOM chip), conditional **`required`**, **`tooltip`**, optional **`responsive`**, **`device`**.
	 * - **Tabs:** `type` => **`tabs`**, **`id`**, **`title`**, **`tabs`**, **`fields`** — **`fields`** may mix **leaf** controls, **`type` => `accordion`**, **nested groups** (`id`, `title`, `fields`), and **nested `tabs`** (ids auto-prefixed per nesting level). Optional **`responsive`**, **`device`**. Stored keys: **`{tabs_id}_{tab_id}_{inner_id}`** (and scoped ids for nested blocks). **`sto-tabs.css`** stacks grid cells full-width at **960px**.
	 * - **Accordion:** `type` => **`accordion`**, **`id`**, **`title`**, **`panels`**, **`fields`** — each **`panels[]`** row: **`id`**, **`label`**, optional **`expanded`** / **`show`** / **`open`** (first truthy row starts open; otherwise all collapsed on load). **`fields`** may mix **leaf** fields, **`tabs`**, **`accordion`**, and **nested groups** (same composition rules as **Tabs** / tree builders). Optional **`responsive`**, **`device`**, **`description`**, **`required`**, **`tooltip`**. Stored keys: **`{accordion_id}_{panel_id}_{inner_id}`**. **`sto-accordion.css`** / **`sto-accordion.js`**.
	 * - Nested groups: array with `id`, `title`, `fields` (no top-level `options`), optional `description`, `required`, optional **`tooltip`** / **`tooltip_image`** (same shapes as **`FieldTitle::get_tooltip_config`**).
	 * - Root group: optional **`tooltip`** / **`tooltip_image`** / **`tooltip_preloader`** on **`$config`** for the panel `<h3>` heading.
	 *
	 * @param array<string, mixed> $config
	 */
	public static function register( $config ) {
		$instance = static::instance();
		FieldRegistrationDeferral::defer_or_run(
			function () use ( $instance, $config ) {
				$instance->register_group_config( $config );
			}
		);
	}

	/**
	 * @param mixed $config
	 */
	private function register_group_config( $config ): void {
		if ( ! is_array( $config ) ) {
			return;
		}

		$section_slug = isset( $config['section_slug'] ) ? sanitize_key( (string) $config['section_slug'] ) : '';
		$group_id     = isset( $config['id'] ) ? sanitize_key( (string) $config['id'] ) : '';
		$title        = isset( $config['title'] ) ? (string) $config['title'] : '';
		$description  = isset( $config['description'] ) ? (string) $config['description'] : '';
		$fields       = isset( $config['fields'] ) && is_array( $config['fields'] ) ? $config['fields'] : array();
		$required     = isset( $config['required'] ) && is_array( $config['required'] ) ? $config['required'] : array();

		if ( ! $section_slug || ! $group_id || $title === '' || empty( $fields ) ) {
			return;
		}

		$nodes = $this->build_nodes_for_parent( $section_slug, $group_id, $fields );
		if ( empty( $nodes ) ) {
			return;
		}

		$this->index_group( $section_slug, $group_id, $title, '' );

		if ( ! isset( $this->groups_by_section[ $section_slug ] ) ) {
			$this->groups_by_section[ $section_slug ] = array();
		}

		$row = array(
			'id'          => $group_id,
			'title'       => $title,
			'description' => $description,
			'required'    => $required,
			'nodes'       => $nodes,
		);
		if ( ! empty( $config['tooltip'] ) && is_array( $config['tooltip'] ) ) {
			$row['tooltip'] = $config['tooltip'];
		}
		if ( ! empty( $config['tooltip_image'] ) ) {
			$row['tooltip_image'] = (string) $config['tooltip_image'];
		}
		if ( ! empty( $config['tooltip_preloader'] ) ) {
			$row['tooltip_preloader'] = (string) $config['tooltip_preloader'];
		}

		$this->groups_by_section[ $section_slug ][] = $row;
	}

	/**
	 * @param array<int, mixed> $fields
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * Build registration + node tree for any parent group id (root group, nested subgroup, or composite id inside Accordion / Tabs).
	 *
	 * @param string               $section_slug
	 * @param string               $parent_group_id Sanitized group / synthetic parent id used as `group` on registered inners.
	 * @param array<int, mixed>    $fields
	 * @return array<int, array<string, mixed>>
	 */
	public function build_nodes_for_parent( $section_slug, $parent_group_id, $fields ) {
		$nodes = array();

		foreach ( $fields as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			// Parsed once per item; identical 12-column grammar used by Tabs inner items
			// (1-2, 1/3, 2-3, 1-1, full, integer 1–12). Default = 12 = full row.
			$span = LayoutWidth::parse_span( $item['width'] ?? null );

			if ( $this->is_tabs_item( $item ) ) {
				if ( Tabs::instance()->register_from_group( $item, $section_slug, $parent_group_id ) ) {
					$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
					if ( $fid ) {
						$nodes[] = array(
							'kind' => 'tabs',
							'id'   => $fid,
							'span' => $span,
						);
					}
				}
				continue;
			}

			if ( $this->is_accordion_item( $item ) ) {
				if ( Accordion::instance()->register_from_group( $item, $section_slug, $parent_group_id ) ) {
					$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
					if ( $fid ) {
						$nodes[] = array(
							'kind' => 'accordion',
							'id'   => $fid,
							'span' => $span,
						);
					}
				}
				continue;
			}

			if ( $this->is_typography_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				Typography::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid ) {
					$nodes[] = array(
						'kind' => 'typography',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_color_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				Color::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid ) {
					$nodes[] = array(
						'kind' => 'color',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_background_control_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				BackgroundControl::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid ) {
					$nodes[] = array(
						'kind' => 'background_control',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_border_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				BorderControl::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid ) {
					$nodes[] = array(
						'kind' => 'border',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_shadow_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				ShadowControl::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid && ShadowControl::get_field( $section_slug, $fid ) ) {
					$nodes[] = array(
						'kind' => 'shadow',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_code_editor_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				CodeEditor::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid && CodeEditor::get_field( $section_slug, $fid ) ) {
					$nodes[] = array(
						'kind' => 'code_editor',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_link_color_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				LinkColor::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid ) {
					$nodes[] = array(
						'kind' => 'link_color',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_switcher_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				Switcher::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid ) {
					$nodes[] = array(
						'kind' => 'switcher',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_checkbox_control_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				CheckboxControl::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid && CheckboxControl::get_field( $section_slug, $fid ) ) {
					$nodes[] = array(
						'kind' => 'checkbox',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_image_select_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				ImageSelect::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid ) {
					$nodes[] = array(
						'kind' => 'image_select',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_dynamic_object_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				DynamicObject::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid && DynamicObject::get_field( $section_slug, $fid ) ) {
					$nodes[] = array(
						'kind' => 'dynamic_object',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_input_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				Input::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid && Input::get_field( $section_slug, $fid ) ) {
					$nodes[] = array(
						'kind' => 'input',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_button_group_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				ButtonGroup::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid && ButtonGroup::get_field( $section_slug, $fid ) ) {
					$nodes[] = array(
						'kind' => 'button_group',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_range_item( $item ) ) {
				$item['section_slug'] = $section_slug;
				$item['group']        = $parent_group_id;
				Range::register( $item );
				$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				if ( $fid && Range::get_field( $section_slug, $fid ) ) {
					$nodes[] = array(
						'kind' => 'range',
						'id'   => $fid,
						'span' => $span,
					);
				}
				continue;
			}

			if ( $this->is_nested_group_item( $item ) ) {
				$child_id          = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
				$child_title       = isset( $item['title'] ) ? (string) $item['title'] : '';
				$child_description = isset( $item['description'] ) ? (string) $item['description'] : '';
				$child_required    = isset( $item['required'] ) && is_array( $item['required'] ) ? $item['required'] : array();
				$child_fields      = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();

				if ( ! $child_id || $child_title === '' || empty( $child_fields ) ) {
					continue;
				}

				$child_nodes = $this->build_nodes_for_parent( $section_slug, $child_id, $child_fields );
				if ( empty( $child_nodes ) ) {
					continue;
				}

				$this->index_group( $section_slug, $child_id, $child_title, $parent_group_id );

				$nested = array(
					'kind'        => 'group',
					'id'          => $child_id,
					'title'       => $child_title,
					'description' => $child_description,
					'required'    => $child_required,
					'nodes'       => $child_nodes,
					'span'        => $span,
				);
				if ( ! empty( $item['tooltip'] ) && is_array( $item['tooltip'] ) ) {
					$nested['tooltip'] = $item['tooltip'];
				}
				if ( ! empty( $item['tooltip_image'] ) ) {
					$nested['tooltip_image'] = (string) $item['tooltip_image'];
				}
				if ( ! empty( $item['tooltip_preloader'] ) ) {
					$nested['tooltip_preloader'] = (string) $item['tooltip_preloader'];
				}
				$nodes[] = $nested;
				continue;
			}

			$item['section_slug'] = $section_slug;
			$item['group']        = $parent_group_id;

			Select::register( $item );

			$fid = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
			if ( ! $fid || ! Select::get_field( $section_slug, $fid ) ) {
				continue;
			}

			$nodes[] = array(
				'kind' => 'field',
				'id'   => $fid,
				'span' => $span,
			);
		}

		return $nodes;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function is_typography_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && $item['type'] === 'typography' && ! empty( $item['id'] );
	}

	private function is_color_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && $item['type'] === 'color' && ! empty( $item['id'] );
	}

	private function is_background_control_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && $item['type'] === 'background_control' && ! empty( $item['id'] );
	}

	private function is_border_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && sanitize_key( (string) $item['type'] ) === 'border' && ! empty( $item['id'] );
	}

	private function is_shadow_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && sanitize_key( (string) $item['type'] ) === 'shadow' && ! empty( $item['id'] );
	}

	private function is_code_editor_item( $item ) {
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			return false;
		}
		$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';

		return in_array( $type, array( 'code_editor', 'codeeditor', 'code' ), true );
	}

	private function is_link_color_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && $item['type'] === 'link_color' && ! empty( $item['id'] );
	}

	private function is_switcher_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && $item['type'] === 'switcher' && ! empty( $item['id'] );
	}

	private function is_checkbox_control_item( $item ) {
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			return false;
		}
		if ( ! isset( $item['type'] ) || sanitize_key( (string) $item['type'] ) !== 'checkbox' ) {
			return false;
		}
		if ( ! empty( $item['multiple'] ) ) {
			return ! empty( $item['options'] ) && is_array( $item['options'] );
		}

		return true;
	}

	private function is_image_select_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && $item['type'] === 'image_select' && ! empty( $item['id'] ) && ! empty( $item['options'] ) && is_array( $item['options'] );
	}

	private function is_button_group_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && $item['type'] === 'button_group' && ! empty( $item['id'] ) && ! empty( $item['options'] ) && is_array( $item['options'] );
	}

	private function is_range_item( $item ) {
		return is_array( $item ) && isset( $item['type'] ) && sanitize_key( (string) $item['type'] ) === 'range' && ! empty( $item['id'] );
	}

	private function is_tabs_item( $item ) {
		return is_array( $item )
			&& isset( $item['type'] )
			&& sanitize_key( (string) $item['type'] ) === 'tabs'
			&& ! empty( $item['id'] )
			&& ! empty( $item['tabs'] )
			&& is_array( $item['tabs'] )
			&& ! empty( $item['fields'] )
			&& is_array( $item['fields'] );
	}

	private function is_accordion_item( $item ) {
		return is_array( $item )
			&& isset( $item['type'] )
			&& sanitize_key( (string) $item['type'] ) === 'accordion'
			&& ! empty( $item['id'] )
			&& ! empty( $item['panels'] )
			&& is_array( $item['panels'] )
			&& ! empty( $item['fields'] )
			&& is_array( $item['fields'] );
	}

	private function is_dynamic_object_item( $item ) {
		return is_array( $item )
			&& isset( $item['type'] )
			&& $item['type'] === 'dynamic_object'
			&& ! empty( $item['id'] )
			&& ! empty( $item['post_type'] );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function is_input_item( $item ) {
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			return false;
		}

		if ( isset( $item['type'] ) && sanitize_key( (string) $item['type'] ) === 'button_group' ) {
			return false;
		}

		if ( isset( $item['type'] ) && sanitize_key( (string) $item['type'] ) === 'checkbox' ) {
			return false;
		}

		if ( isset( $item['type'] ) && $item['type'] === 'input' ) {
			$it = isset( $item['input_type'] ) ? sanitize_key( (string) $item['input_type'] ) : '';

			return $it !== '' && in_array( $it, Input::INPUT_TYPES, true );
		}

		if ( ! isset( $item['type'] ) ) {
			return false;
		}

		$t = sanitize_key( (string) $item['type'] );

		return in_array( $t, Input::INPUT_TYPES, true );
	}

	private function is_nested_group_item( $item ) {
		if ( isset( $item['options'] ) && is_array( $item['options'] ) ) {
			return false;
		}

		return isset( $item['fields'] ) && is_array( $item['fields'] ) && ! empty( $item['id'] );
	}

	private function index_group( $section_slug, $group_id, $title, $parent_id ) {
		if ( ! isset( $this->groups_index[ $section_slug ] ) ) {
			$this->groups_index[ $section_slug ] = array();
		}

		$this->groups_index[ $section_slug ][ $group_id ] = array(
			'title'     => $title,
			'parent_id' => (string) $parent_id,
		);
	}

	/**
	 * Index a synthetic group row (e.g. accordion inner scope) for breadcrumbs / nav.
	 *
	 * @param string $section_slug
	 * @param string $group_id
	 * @param string $title
	 * @param string $parent_id Parent slug (hosting group or accordion base id).
	 */
	public function index_group_for_accordion( $section_slug, $group_id, $title, $parent_id ) {
		$this->index_group( $section_slug, $group_id, $title, $parent_id );
	}

	/**
	 * @param string $section_slug
	 * @param string $group_id
	 */
	public function get_group_title( $section_slug, $group_id ) {
		$section_slug = sanitize_key( (string) $section_slug );
		$group_id     = sanitize_key( (string) $group_id );

		if ( ! $section_slug || ! $group_id || empty( $this->groups_index[ $section_slug ][ $group_id ] ) ) {
			return '';
		}

		return (string) $this->groups_index[ $section_slug ][ $group_id ]['title'];
	}

	/**
	 * Breadcrumb titles from outermost parent to the given group (inclusive).
	 *
	 * @param string $section_slug
	 * @param string $group_id
	 * @return array<int, string>
	 */
	public function get_group_breadcrumb_titles( $section_slug, $group_id ) {
		$section_slug = sanitize_key( (string) $section_slug );
		$group_id     = sanitize_key( (string) $group_id );

		if ( ! $section_slug || ! $group_id ) {
			return array();
		}

		$chain = array();
		$id    = $group_id;

		while ( $id !== '' && isset( $this->groups_index[ $section_slug ][ $id ] ) ) {
			$meta   = $this->groups_index[ $section_slug ][ $id ];
			$chain[] = (string) $meta['title'];
			$id      = sanitize_key( (string) $meta['parent_id'] );
		}

		return array_reverse( $chain );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public function get_groups_for_search() {
		$out = array();

		foreach ( $this->groups_by_section as $section_slug => $roots ) {
			foreach ( $roots as $root ) {
				$this->append_group_rows_for_search( (string) $section_slug, $root, $out );
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $group
	 * @param array<int, array<string, string>> $out
	 */
	private function append_group_rows_for_search( $section_slug, $group, array &$out ) {
		if ( empty( $group['id'] ) ) {
			return;
		}

		$out[] = array(
			'section_slug' => $section_slug,
			'id'           => (string) $group['id'],
			'title'        => isset( $group['title'] ) ? (string) $group['title'] : '',
		);

		if ( empty( $group['nodes'] ) || ! is_array( $group['nodes'] ) ) {
			return;
		}

		foreach ( $group['nodes'] as $node ) {
			if ( is_array( $node ) && isset( $node['kind'] ) && $node['kind'] === 'group' ) {
				$this->append_group_rows_for_search(
					$section_slug,
					array(
						'id'    => $node['id'] ?? '',
						'title' => $node['title'] ?? '',
						'nodes' => $node['nodes'] ?? array(),
					),
					$out
				);
			}
		}
	}

	/**
	 * @param string               $section_slug
	 * @param array<string, mixed> $section
	 */
	public function render_groups( $section_slug, $section ) {
		$section_slug = sanitize_key( (string) $section_slug );

		if ( empty( $this->groups_by_section[ $section_slug ] ) ) {
			return;
		}

		foreach ( $this->groups_by_section[ $section_slug ] as $root ) {
			$this->render_group_branch( $section_slug, $root, 0 );
		}
	}

	/**
	 * @param array<string, mixed> $group
	 * @param int                    $depth 0 = root panel
	 */
	public function render_group_branch( $section_slug, $group, $depth ) {
		$required      = isset( $group['required'] ) && is_array( $group['required'] ) ? $group['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$description   = isset( $group['description'] ) ? (string) $group['description'] : '';

		$classes = array( 'sto-field-group' );
		if ( $depth > 0 ) {
			$classes[] = 'sto-field-group--nested';
		}
		if ( $depth >= 4 ) {
			$classes[] = 'sto-field-group--depth-deep';
		}
		?>
		<div
			id="<?php echo esc_attr( 'sto-group-' . $group['id'] ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-sto-group-id="<?php echo esc_attr( $group['id'] ); ?>"
			data-sto-group-depth="<?php echo (int) $depth; ?>"
			<?php if ( $required_json ) : ?>
				data-sto-required="<?php echo esc_attr( $required_json ); ?>"
			<?php endif; ?>
		>
			<?php
			$g_tooltip = FieldTitle::get_tooltip_config( $group );
			FieldTitle::render_heading( (string) $group['title'], 'group_panel', $g_tooltip, isset( $group['id'] ) ? (string) $group['id'] : '' );
			?>
			<?php if ( $description !== '' ) : ?>
				<p class="sto-field-group-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
			<div class="sto-field-group-inner">
				<?php
				if ( ! empty( $group['nodes'] ) && is_array( $group['nodes'] ) ) {
					foreach ( $group['nodes'] as $node ) {
						if ( ! is_array( $node ) || empty( $node['kind'] ) ) {
							continue;
						}
						// Span 1–12 with 12 = full row (default). `.sto-field-group-inner` is a
						// 12-column grid; widths <12 sit side-by-side, ≤960px collapses every cell
						// to one row each. See `LayoutWidth::parse_span()` for the grammar.
						$span = isset( $node['span'] ) ? (int) $node['span'] : 12;
						if ( $span < 1 ) {
							$span = 12;
						}
						if ( $span > 12 ) {
							$span = 12;
						}
						?>
						<div class="sto-field-group-cell sto-field-group-cell--span-<?php echo esc_attr( (string) $span ); ?>" data-sto-group-cell-span="<?php echo esc_attr( (string) $span ); ?>">
						<?php
						if ( $node['kind'] === 'field' && ! empty( $node['id'] ) ) {
							$field = Select::get_field( $section_slug, (string) $node['id'] );
							if ( $field ) {
								Select::instance()->render_field_markup( $field, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'typography' && ! empty( $node['id'] ) ) {
							$tfield = Typography::get_field( $section_slug, (string) $node['id'] );
							if ( $tfield ) {
								Typography::instance()->render_field_markup( $tfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'color' && ! empty( $node['id'] ) ) {
							$cfield = Color::get_field( $section_slug, (string) $node['id'] );
							if ( $cfield ) {
								Color::instance()->render_field_markup( $cfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'background_control' && ! empty( $node['id'] ) ) {
							$bcfield = BackgroundControl::get_field( $section_slug, (string) $node['id'] );
							if ( $bcfield ) {
								BackgroundControl::instance()->render_field_markup( $bcfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'border' && ! empty( $node['id'] ) ) {
							$bdfield = BorderControl::get_field( $section_slug, (string) $node['id'] );
							if ( $bdfield ) {
								BorderControl::instance()->render_field_markup( $bdfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'shadow' && ! empty( $node['id'] ) ) {
							$shfield = ShadowControl::get_field( $section_slug, (string) $node['id'] );
							if ( $shfield ) {
								ShadowControl::instance()->render_field_markup( $shfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'code_editor' && ! empty( $node['id'] ) ) {
							$cefield = CodeEditor::get_field( $section_slug, (string) $node['id'] );
							if ( $cefield ) {
								CodeEditor::instance()->render_field_markup( $cefield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'link_color' && ! empty( $node['id'] ) ) {
							$lcfield = LinkColor::get_field( $section_slug, (string) $node['id'] );
							if ( $lcfield ) {
								LinkColor::instance()->render_field_markup( $lcfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'switcher' && ! empty( $node['id'] ) ) {
							$sfield = Switcher::get_field( $section_slug, (string) $node['id'] );
							if ( $sfield ) {
								Switcher::instance()->render_field_markup( $sfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'checkbox' && ! empty( $node['id'] ) ) {
							$cbfield = CheckboxControl::get_field( $section_slug, (string) $node['id'] );
							if ( $cbfield ) {
								CheckboxControl::instance()->render_field_markup( $cbfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'image_select' && ! empty( $node['id'] ) ) {
							$ifield = ImageSelect::get_field( $section_slug, (string) $node['id'] );
							if ( $ifield ) {
								ImageSelect::instance()->render_field_markup( $ifield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'dynamic_object' && ! empty( $node['id'] ) ) {
							$dfield = DynamicObject::get_field( $section_slug, (string) $node['id'] );
							if ( $dfield ) {
								DynamicObject::instance()->render_field_markup( $dfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'input' && ! empty( $node['id'] ) ) {
							$ifield = Input::get_field( $section_slug, (string) $node['id'] );
							if ( $ifield ) {
								Input::instance()->render_field_markup( $ifield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'button_group' && ! empty( $node['id'] ) ) {
							$bgfield = ButtonGroup::get_field( $section_slug, (string) $node['id'] );
							if ( $bgfield ) {
								ButtonGroup::instance()->render_field_markup( $bgfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'range' && ! empty( $node['id'] ) ) {
							$rfield = Range::get_field( $section_slug, (string) $node['id'] );
							if ( $rfield ) {
								Range::instance()->render_field_markup( $rfield, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'tabs' && ! empty( $node['id'] ) ) {
							$tcfg = Tabs::instance()->get_config( $section_slug, (string) $node['id'] );
							if ( is_array( $tcfg ) ) {
								Tabs::instance()->render_field_markup( $tcfg, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'accordion' && ! empty( $node['id'] ) ) {
							$acfg = Accordion::instance()->get_config( $section_slug, (string) $node['id'] );
							if ( is_array( $acfg ) ) {
								Accordion::instance()->render_field_markup( $acfg, 'group_inner' );
							}
						} elseif ( $node['kind'] === 'group' ) {
							$child_group = array(
								'id'          => $node['id'] ?? '',
								'title'       => $node['title'] ?? '',
								'description' => isset( $node['description'] ) ? (string) $node['description'] : '',
								'required'    => isset( $node['required'] ) && is_array( $node['required'] ) ? $node['required'] : array(),
								'nodes'       => isset( $node['nodes'] ) && is_array( $node['nodes'] ) ? $node['nodes'] : array(),
							);
							if ( ! empty( $node['tooltip'] ) && is_array( $node['tooltip'] ) ) {
								$child_group['tooltip'] = $node['tooltip'];
							}
							if ( ! empty( $node['tooltip_image'] ) ) {
								$child_group['tooltip_image'] = (string) $node['tooltip_image'];
							}
							if ( ! empty( $node['tooltip_preloader'] ) ) {
								$child_group['tooltip_preloader'] = (string) $node['tooltip_preloader'];
							}
							$this->render_group_branch( $section_slug, $child_group, $depth + 1 );
						}
						?>
						</div>
						<?php
					}
				}
				?>
			</div>
		</div>
		<?php
	}
}
