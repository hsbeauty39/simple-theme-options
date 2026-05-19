<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Accordion;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSpacing;

use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\RichModernEditor\RichModernEditor;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\PremiumFieldGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\LayoutWidth;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\Group\Group;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\DateField\DateField;
use SimpleThemeOptions\Admin\Options\Fields\DateTimeField\DateTimeField;
use SimpleThemeOptions\Admin\Options\Fields\AlignmentControl\AlignmentControl;
use SimpleThemeOptions\Admin\Options\Fields\Dimension\Dimension;
use SimpleThemeOptions\Admin\Options\Fields\IconSelect\IconSelect;
use SimpleThemeOptions\Admin\Options\Fields\GalleryControl\GalleryControl;
use SimpleThemeOptions\Admin\Options\Fields\MultiTextControl\MultiTextControl;
use SimpleThemeOptions\Admin\Options\Fields\RadioListsControl\RadioListsControl;
use SimpleThemeOptions\Admin\Options\Fields\AdvancedRepeaterControl\AdvancedRepeaterControl;
use SimpleThemeOptions\Admin\Options\Fields\GoogleMapControl\GoogleMapControl;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\CheckboxControl\CheckboxControl;
use SimpleThemeOptions\Admin\Options\Fields\Tabs\Tabs;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Accordion: vertical panels sharing one **`fields`** tree (repeated per panel with panel-scoped ids).
 * **`fields`** may mix **leaf** controls, **`type` => `tabs`**, **`type` => `accordion`**, and **nested groups**
 * (shape: **`id`**, **`title`**, **`fields`**, no top-level **`options`**). Each leaf keeps **`ResponsiveControl`**
 * when **`responsive`** is set on the accordion row (or on the leaf). Nested **Tabs** / **Accordion** ids are
 * auto-prefixed with **`{accordion_id}_{panel_id}_`** when you reuse short ids across panels.
 *
 * **`panels`**: each entry **`id`**, **`label`**, and optional **`expanded`**, **`show`**, or **`open`** (truthy) — only the **first** flagged panel starts **open**; if none are set, **all start collapsed**. Clicking the **active** header again collapses the section (**`sto-accordion.js`**).
 *
 * Stored keys for leaf composites: **`{accordion_id}_{panel_id}_{logical_id}`**. Nested **Group** subtree
 * uses normal **`Group::build_nodes_for_parent()`** ids (scoped group id = **`{accordion_id}_{panel_id}_{group_id}`**).
 */
final class Accordion {
	use SingletonTrait;

	private const MAX_PANELS       = 15;
	private const MAX_TREE_NODES   = 80;
	private const MAX_NEST_DEPTH   = 6;

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $fields_by_section = array();

	/**
	 * `section_slug|accordion_id` => panel config
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $configs_by_key = array();

	/**
	 * @var array<string, true>
	 */
	private $registered_ids = array();

	protected function init() {
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::ACCORDION, 2 );
	}

	/**
	 * Whether a **`panels[]`** row asks for that panel to be open on first paint (aliases: **`expanded`**, **`show`**, **`open`**).
	 * Only the first matching panel in list order is applied at registration time.
	 *
	 * @param array<string, mixed> $prow
	 */
	private function panel_requests_open( array $prow ) {
		foreach ( array( 'expanded', 'show', 'open' ) as $k ) {
			if ( ! isset( $prow[ $k ] ) ) {
				continue;
			}
			$v = $prow[ $k ];
			if ( $v === true || $v === 1 || $v === '1' || $v === 'yes' || $v === 'on' ) {
				return true;
			}
		}

		return false;
	}

	/**
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
				$instance->register_accordion_field( $field, $section_slug, '', false, null, true );
			}
		);
	}

	/**
	 * @param array<string, mixed> $item
	 * @param string               $section_slug
	 * @param string               $parent_group_id
	 * @return bool
	 */
	public function register_from_group( array $item, $section_slug, $parent_group_id ) {
		return $this->register_accordion_field(
			$item,
			sanitize_key( (string) $section_slug ),
			sanitize_key( (string) $parent_group_id ),
			true,
			null,
			true
		);
	}

	/**
	 * Register accordion nested under **Tabs** / another **Accordion** (scoped inner `group` on leaves).
	 *
	 * @param array<string, mixed> $item
	 * @param string                 $section_slug
	 * @param string                 $parent_group_id Hosting context (WP group or synthetic parent id).
	 * @param string                 $inner_group_scope Stamped as `group` on registered leaf fields (scoped accordion id).
	 * @param bool                   $enqueue_standalone When false, omit from standalone section render list.
	 * @return bool
	 */
	public function register_nested( array $item, $section_slug, $parent_group_id, $inner_group_scope, $enqueue_standalone ) {
		return $this->register_accordion_field(
			$item,
			sanitize_key( (string) $section_slug ),
			sanitize_key( (string) $parent_group_id ),
			true,
			sanitize_key( (string) $inner_group_scope ),
			(bool) $enqueue_standalone
		);
	}

	/**
	 * @param array<string, mixed> $item
	 * @param string               $section_slug
	 * @param string               $parent_group_id Empty when standalone outer accordion.
	 * @param bool                 $from_group        Outer accordion sits inside **Group**.
	 * @param string|null          $field_group_for_inners Optional `group` stamped on registered inners (nested accordion under another accordion).
	 * @param bool                 $enqueue_standalone When false, do not append to **`fields_by_section`** (nested accordion).
	 * @return bool
	 */
	private function register_accordion_field( array $item, $section_slug, $parent_group_id, $from_group, $field_group_for_inners, $enqueue_standalone ) {
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

		$panels_raw = isset( $item['panels'] ) && is_array( $item['panels'] ) ? $item['panels'] : array();
		$fields     = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $item['fields'] : array();
		if ( empty( $panels_raw ) || empty( $fields ) ) {
			return false;
		}

		$panel_defs              = array();
		$default_open_panel_id   = null;
		foreach ( $panels_raw as $prow ) {
			if ( ! is_array( $prow ) ) {
				continue;
			}
			$pk = isset( $prow['id'] ) ? sanitize_key( (string) $prow['id'] ) : '';
			$lb = isset( $prow['label'] ) ? (string) $prow['label'] : '';
			if ( $pk === '' || $lb === '' ) {
				continue;
			}
			if ( $this->panel_requests_open( $prow ) && $default_open_panel_id === null ) {
				$default_open_panel_id = $pk;
			}
			$panel_defs[] = array(
				'id'    => $pk,
				'label' => $lb,
			);
			if ( count( $panel_defs ) >= self::MAX_PANELS ) {
				break;
			}
		}

		if ( empty( $panel_defs ) ) {
			return false;
		}

		$g_for_reg = $field_group_for_inners !== null && $field_group_for_inners !== ''
			? sanitize_key( (string) $field_group_for_inners )
			: ( $from_group ? $parent_group_id : $base_id );

		$panel_trees = array();
		$counter     = 0;
		foreach ( $panel_defs as $panel ) {
			$pk    = $panel['id'];
			$tree  = $this->register_field_tree( $fields, $section_slug, $base_id, $pk, $g_for_reg, $item, 0, $counter );
			if ( empty( $tree ) ) {
				return false;
			}
			$panel_trees[ $pk ] = $tree;
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
			'panel_defs'               => $panel_defs,
			'default_open_panel_id'    => $default_open_panel_id,
			'panel_trees'              => $panel_trees,
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
	 * @param string                 $accordion_base_id
	 * @param string                 $panel_pk
	 * @param string                 $group_for_inners `group` key on leaf / tabs clones.
	 * @param array<string, mixed>  $accordion_parent_cfg Outer accordion item (for responsive inherit).
	 * @param int                    $depth
	 * @param int                    $counter
	 * @return array<int, array<string, mixed>>
	 */
	private function register_field_tree( array $fields, $section_slug, $accordion_base_id, $panel_pk, $group_for_inners, array $accordion_parent_cfg, $depth, &$counter ) {
		$nodes = array();
		foreach ( $fields as $inner ) {
			if ( ! is_array( $inner ) || $counter >= self::MAX_TREE_NODES ) {
				break;
			}

			$span = $this->parse_layout_width( isset( $inner['width'] ) ? $inner['width'] : null );

			if ( $this->is_tabs_item_shape( $inner ) ) {
				$t          = $inner;
				$logical    = isset( $t['id'] ) ? sanitize_key( (string) $t['id'] ) : '';
				if ( $logical === '' ) {
					continue;
				}
				$t['id'] = $this->scoped_block_id( $accordion_base_id, $panel_pk, $logical );
				if ( ! Tabs::instance()->register_from_group( $t, $section_slug, $group_for_inners ) ) {
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
				$a['id'] = $this->scoped_block_id( $accordion_base_id, $panel_pk, $logical );
				if ( ! $this->register_nested( $a, $section_slug, $group_for_inners, $a['id'], false ) ) {
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
				$child_id          = $this->scoped_block_id( $accordion_base_id, $panel_pk, sanitize_key( (string) $inner['id'] ) );
				$child_title       = isset( $inner['title'] ) ? (string) $inner['title'] : '';
				$child_description = isset( $inner['description'] ) ? (string) $inner['description'] : '';
				$child_required    = isset( $inner['required'] ) && is_array( $inner['required'] ) ? $inner['required'] : array();
				$child_fields        = isset( $inner['fields'] ) && is_array( $inner['fields'] ) ? $inner['fields'] : array();
				if ( $child_title === '' || empty( $child_fields ) ) {
					continue;
				}
				$child_nodes = Group::instance()->build_nodes_for_parent( $section_slug, $child_id, $child_fields );
				if ( empty( $child_nodes ) ) {
					continue;
				}
				Group::instance()->index_group_for_accordion( $section_slug, $child_id, $child_title, $accordion_base_id );

				$nested = array(
					'node'          => 'group',
					'id'            => $child_id,
					'title'         => $child_title,
					'description'   => $child_description,
					'required'      => $child_required,
					'nodes'         => $child_nodes,
					'span'          => $span,
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

			$composite = $accordion_base_id . '_' . $panel_pk . '_' . $logical_id;
			$clone_reg = $clone;
			$clone_reg['id']           = $composite;
			$clone_reg['section_slug'] = $section_slug;
			$clone_reg['group']        = $group_for_inners;
			$clone_reg[ ResponsiveConfig::ACCORDION_INNER_FIELD ] = true;
			$this->inherit_responsive_from_accordion_parent( $accordion_parent_cfg, $clone_reg );

			$kind = $this->register_inner_field( $clone_reg );
			if ( $kind === '' ) {
				continue;
			}

			$nodes[] = array(
				'node'   => 'leaf',
				'kind'   => $kind,
				'logical'=> $logical_id,
				'span'   => $span,
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
	 * @param string $accordion_base_id
	 * @param string $panel_pk
	 * @param string $logical
	 */
	private function scoped_block_id( $accordion_base_id, $panel_pk, $logical ) {
		return sanitize_key( (string) $accordion_base_id ) . '_' . sanitize_key( (string) $panel_pk ) . '_' . sanitize_key( (string) $logical );
	}

	/**
	 * @param array<string, mixed> $parent Accordion field config.
	 * @param array<string, mixed> $clone_reg Inner field config (modified in place).
	 */
	private function inherit_responsive_from_accordion_parent( array $parent, array &$clone_reg ) {
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
			if ( ! FieldRenderGate::should_render_field( $field ) ) {
				continue;
			}
			$this->render_field_markup( $field, 'default' );
		}
	}

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
		if ( isset( $inner['type'] ) && sanitize_key( (string) $inner['type'] ) === 'radio_lists' ) {
			return false;
		}
		if ( isset( $inner['type'] ) && sanitize_key( (string) $inner['type'] ) === 'advanced_repeater' ) {
			return false;
		}
		if ( isset( $inner['type'] ) && $inner['type'] === 'input' ) {
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
	 * @param array<string, mixed> $clone_reg
	 * @return string Registered kind or empty on failure
	 */
	private function register_inner_field( array $clone_reg ) {
		FieldSpacing::normalize_config( $clone_reg );

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
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'date' ) {
			DateField::register( $clone_reg );

			return DateField::get_field( $section, $fid ) ? 'date' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'datetime' ) {
			DateTimeField::register( $clone_reg );

			return DateTimeField::get_field( $section, $fid ) ? 'datetime' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'dimension' ) {
			Dimension::register( $clone_reg );

			return Dimension::get_field( $section, $fid ) ? 'dimension' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'icon_select' ) {
			IconSelect::register( $clone_reg );

			return IconSelect::get_field( $section, $fid ) ? 'icon_select' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'gallery' ) {
			GalleryControl::register( $clone_reg );

			return GalleryControl::get_field( $section, $fid ) ? 'gallery' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'multi_text' ) {
			MultiTextControl::register( $clone_reg );

			return MultiTextControl::get_field( $section, $fid ) ? 'multi_text' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'radio_lists' && ! empty( $clone_reg['options'] ) && is_array( $clone_reg['options'] ) ) {
			RadioListsControl::register( $clone_reg );

			return RadioListsControl::get_field( $section, $fid ) ? 'radio_lists' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'advanced_repeater' && ! empty( $clone_reg['fields'] ) && is_array( $clone_reg['fields'] ) ) {
			AdvancedRepeaterControl::register( $clone_reg );

			return AdvancedRepeaterControl::get_field( $section, $fid ) ? 'advanced_repeater' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'google_map' ) {
			GoogleMapControl::register( $clone_reg );

			return GoogleMapControl::get_field( $section, $fid ) ? 'google_map' : '';
		}
		if ( isset( $clone_reg['type'] ) && sanitize_key( (string) $clone_reg['type'] ) === 'alignment' && ! empty( $clone_reg['options'] ) && is_array( $clone_reg['options'] ) ) {
			AlignmentControl::register( $clone_reg );

			return AlignmentControl::get_field( $section, $fid ) ? 'alignment' : '';
		}
		if ( isset( $clone_reg['type'] ) && $clone_reg['type'] === 'button_group' && ! empty( $clone_reg['options'] ) && is_array( $clone_reg['options'] ) ) {
			ButtonGroup::register( $clone_reg );

			return ButtonGroup::get_field( $section, $fid ) ? 'button_group' : '';
		}
		if ( $this->is_code_editor_type( $clone_reg ) ) {
			CodeEditor::register( $clone_reg );

			return CodeEditor::get_field( $section, $fid ) ? 'code_editor' : '';
		}
		if ( $this->is_rich_modern_editor_type( $clone_reg ) ) {
			RichModernEditor::register( $clone_reg );

			return RichModernEditor::get_field( $section, $fid ) ? 'rich_modern_editor' : '';
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
	 * @param array<string, mixed> $inner
	 * @return bool
	 */
	private function is_code_editor_type( array $inner ) {
		if ( empty( $inner['id'] ) ) {
			return false;
		}
		$type = isset( $inner['type'] ) ? sanitize_key( (string) $inner['type'] ) : '';

		return in_array( $type, array( 'code_editor', 'codeeditor', 'code' ), true );
	}

	/**
	 * @param array<string, mixed> $inner
	 * @return bool
	 */
	private function is_rich_modern_editor_type( array $inner ) {
		if ( empty( $inner['id'] ) ) {
			return false;
		}
		$type = isset( $inner['type'] ) ? sanitize_key( (string) $inner['type'] ) : '';

		return in_array( $type, array( 'rich_modern_editor', 'richmoderneditor', 'block_editor', 'gutenberg' ), true );
	}

	/**
	 * @param string $section_slug
	 * @param string $accordion_id
	 * @return array<string, mixed>|null
	 */
	public function get_config( $section_slug, $accordion_id ) {
		$section_slug = sanitize_key( (string) $section_slug );
		$accordion_id = sanitize_key( (string) $accordion_id );
		$key          = $section_slug . '|' . $accordion_id;

		return $this->configs_by_key[ $key ] ?? null;
	}

	/**
	 * @param string $section_slug
	 * @param string $accordion_id
	 */
	public function render_panel( $section_slug, $accordion_id ) {
		$config = $this->get_config( sanitize_key( (string) $section_slug ), sanitize_key( (string) $accordion_id ) );
		if ( is_array( $config ) ) {
			$this->render_field_markup( $config, 'group_inner' );
		}
	}

	/**
	 * @param array<string, mixed>    $config
	 * @param 'default'|'group_inner' $context
	 */
	public function render_field_markup( array $config, $context = 'default' ) {
		$section_slug = isset( $config['section_slug'] ) ? sanitize_key( (string) $config['section_slug'] ) : '';
		$accordion_id = isset( $config['id'] ) ? sanitize_key( (string) $config['id'] ) : '';
		if ( ! $section_slug || ! $accordion_id || empty( $config['panel_defs'] ) || empty( $config['panel_trees'] ) ) {
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
		$from_group   = ! empty( $config['from_group'] );
		$inner_ctx    = $from_group ? 'group_inner' : 'default';

		$row_classes = array( 'sto-field-row', 'sto-field-row-accordion', 'sto-field-row-accordion--v2' );
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

		$panel_defs     = $config['panel_defs'];
		$default_open_raw = isset( $config['default_open_panel_id'] ) ? $config['default_open_panel_id'] : null;
		$default_open_panel_id = null;
		if ( $default_open_raw !== null && $default_open_raw !== '' ) {
			$sk = sanitize_key( (string) $default_open_raw );
			if ( $sk !== '' ) {
				$default_open_panel_id = $sk;
			}
		}
		$master_toolbar = '';
		if ( ! PremiumFieldGate::is_locked() && ! empty( $breakpoints ) ) {
			$master_toolbar = $this->build_master_panel_toolbar_markup( $accordion_id, $panel_defs, $default_open_panel_id );
		}

		$title = isset( $config['title'] ) ? (string) $config['title'] : '';
		?>
		<div
			id="<?php echo esc_attr( 'sto-field-accordion-' . $accordion_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute string from FieldSpacing::row_margin_style_attr().
			echo FieldSpacing::row_margin_style_attr( $config, $context );
			?>
			data-sto-field-id="<?php echo esc_attr( $accordion_id ); ?>"
			<?php if ( empty( $breakpoints ) ) : ?>
				data-sto-accordion="1"
				data-sto-accordion-base="<?php echo esc_attr( $accordion_id ); ?>"
			<?php endif; ?>
			<?php if ( $required_json ) : ?>
				data-sto-required="<?php echo esc_attr( $required_json ); ?>"
			<?php endif; ?>
			<?php if ( ! empty( $breakpoints ) ) : ?>
				data-sto-accordion-master="1"
			<?php endif; ?>
		>
			<?php
			if ( $title || $master_toolbar !== '' ) {
				FieldTitle::render_heading( $title, $context, $tooltip_cfg, $accordion_id, false, '', $master_toolbar );
			}

			if ( PremiumFieldGate::render_controls_or_locked_placeholder( $title, 'accordion' ) ) {
				// Premium body placeholder only.
			} else {
				$dom_base = $this->accordion_dom_base( $accordion_id, '' );
				$this->render_accordion_stack( $section_slug, $accordion_id, $config, $inner_ctx, $dom_base, null );
			}

			if ( $desc !== '' && ! PremiumFieldGate::is_locked() ) {
				echo '<p class="sto-field-description">' . esc_html( $desc ) . '</p>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param string               $section_slug
	 * @param string               $accordion_id
	 * @param array<string, mixed> $config
	 * @param 'default'|'group_inner' $inner_ctx
	 * @param string               $dom_base
	 * @param string|null          $parent_device_bp
	 */
	private function render_accordion_stack( $section_slug, $accordion_id, array $config, $inner_ctx, $dom_base, $parent_device_bp = null ) {
		$panel_defs  = $config['panel_defs'];
		$panel_trees = isset( $config['panel_trees'] ) && is_array( $config['panel_trees'] ) ? $config['panel_trees'] : array();
		$default_open_raw = isset( $config['default_open_panel_id'] ) ? $config['default_open_panel_id'] : null;
		$default_open     = null;
		if ( $default_open_raw !== null && $default_open_raw !== '' ) {
			$sk = sanitize_key( (string) $default_open_raw );
			if ( $sk !== '' ) {
				$default_open = $sk;
			}
		}
		?>
		<div class="sto-accordion sto-accordion--v2" id="<?php echo esc_attr( $dom_base ); ?>">
			<?php
			foreach ( $panel_defs as $pi => $panel ) :
				$pk        = $panel['id'];
				$region_id = $dom_base . '-region-' . $pk;
				$header_id = $dom_base . '-header-' . $pk;
				$expanded  = ( $default_open !== null && $pk === $default_open );
				$tree      = isset( $panel_trees[ $pk ] ) && is_array( $panel_trees[ $pk ] ) ? $panel_trees[ $pk ] : array();
				?>
				<div class="sto-accordion__item sto-accordion__item--v2" data-sto-accordion-item="<?php echo esc_attr( $pk ); ?>">
					<button
						type="button"
						class="sto-accordion__header sto-accordion__header--v2<?php echo $expanded ? ' sto-is-active' : ''; ?>"
						id="<?php echo esc_attr( $header_id ); ?>"
						aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>"
						aria-controls="<?php echo esc_attr( $region_id ); ?>"
						data-sto-accordion-target="<?php echo esc_attr( $region_id ); ?>"
					>
						<span class="sto-accordion__header-index" aria-hidden="true"><?php echo esc_html( (string) ( (int) $pi + 1 ) ); ?></span>
						<span class="sto-accordion__header-text">
							<span class="sto-accordion__header-label"><?php echo esc_html( $panel['label'] ); ?></span>
						</span>
						<span class="sto-accordion__header-chevron" aria-hidden="true"></span>
					</button>
					<div
						class="sto-accordion__region sto-accordion__region--v2"
						id="<?php echo esc_attr( $region_id ); ?>"
						role="region"
						aria-labelledby="<?php echo esc_attr( $header_id ); ?>"
						data-sto-accordion-pane="<?php echo esc_attr( $pk ); ?>"
						<?php echo $expanded ? '' : 'hidden'; ?>
					>
						<div class="sto-accordion__panel sto-accordion__panel--v2">
							<div class="sto-accordion__grid sto-accordion__grid--v2">
								<?php
								foreach ( $tree as $tpl ) {
									$this->render_tree_node( $section_slug, $accordion_id, $pk, $tpl, $inner_ctx, $parent_device_bp );
								}
								?>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $tpl
	 * @param 'default'|'group_inner' $inner_ctx
	 * @param string|null          $parent_device_bp
	 */
	private function render_tree_node( $section_slug, $accordion_id, $panel_pk, array $tpl, $inner_ctx, $parent_device_bp ) {
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
			$cid = $accordion_id . '_' . $panel_pk . '_' . $logical;
			?>
			<div class="sto-accordion__cell sto-accordion__cell--v2 sto-accordion__cell--span-<?php echo esc_attr( (string) $span ); ?>">
				<?php $this->render_inner_by_kind( $section_slug, $cid, $kind, $inner_ctx, $parent_device_bp ); ?>
			</div>
			<?php
			return;
		}

		if ( $node === 'tabs' && ! empty( $tpl['id'] ) ) {
			$tid = sanitize_key( (string) $tpl['id'] );
			$tc  = Tabs::instance()->get_config( $section_slug, $tid );
			?>
			<div class="sto-accordion__cell sto-accordion__cell--v2 sto-accordion__cell--span-<?php echo esc_attr( (string) $span ); ?> sto-accordion__cell--embed">
				<?php
				if ( is_array( $tc ) ) {
					Tabs::instance()->render_field_markup( $tc, $inner_ctx );
				}
				?>
			</div>
			<?php
			return;
		}

		if ( $node === 'accordion' && ! empty( $tpl['id'] ) ) {
			$aid = sanitize_key( (string) $tpl['id'] );
			$ac  = $this->get_config( $section_slug, $aid );
			?>
			<div class="sto-accordion__cell sto-accordion__cell--v2 sto-accordion__cell--span-<?php echo esc_attr( (string) $span ); ?> sto-accordion__cell--embed">
				<?php
				if ( is_array( $ac ) ) {
					$this->render_field_markup( $ac, $inner_ctx );
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
			<div class="sto-accordion__cell sto-accordion__cell--v2 sto-accordion__cell--span-<?php echo esc_attr( (string) $span ); ?> sto-accordion__cell--embed">
				<?php Group::instance()->render_group_branch( $section_slug, $child_group, 1 ); ?>
			</div>
			<?php
		}
	}

	/**
	 * @param string $accordion_id
	 * @param string $suffix_extra
	 */
	private function accordion_dom_base( $accordion_id, $suffix_extra ) {
		$accordion_id = preg_replace( '/[^a-z0-9_-]/i', '', (string) $accordion_id );
		$sfx          = $suffix_extra !== '' ? '-' . sanitize_key( (string) $suffix_extra ) : '';

		return 'sto-accordion-' . $accordion_id . $sfx;
	}

	/**
	 * @param string                                  $accordion_id
	 * @param array<int, array{id:string,label:string}> $panel_defs
	 * @param string|null                               $default_open_panel_id Sanitized panel id to mark active on load, or null when all collapsed.
	 */
	private function build_master_panel_toolbar_markup( $accordion_id, array $panel_defs, $default_open_panel_id = null ) {
		$accordion_id = preg_replace( '/[^a-z0-9_-]/i', '', (string) $accordion_id );
		$pane_base    = $this->accordion_dom_base( $accordion_id, '' );
		$dom_base     = 'sto-accordion-master-' . $accordion_id;
		$open_key     = null;
		if ( $default_open_panel_id !== null && $default_open_panel_id !== '' ) {
			$sk = sanitize_key( (string) $default_open_panel_id );
			$open_key = $sk !== '' ? $sk : null;
		}
		ob_start();
		?>
		<div class="sto-accordion__toolbar sto-accordion__toolbar--sto-master" role="tablist" aria-label="<?php esc_attr_e( 'Accordion sections', 'topten-simple-theme-options' ); ?>">
			<?php foreach ( $panel_defs as $pi => $panel ) : ?>
				<?php
				$pk      = $panel['id'];
				$active  = ( $open_key !== null && $pk === $open_key );
				$first   = 0 === (int) $pi;
				$tab_idx = ( $active || ( $open_key === null && $first ) ) ? '0' : '-1';
				$pane_id = $pane_base . '-region-' . $pk;
				?>
				<button
					type="button"
					class="sto-accordion__master-tab<?php echo $active ? ' sto-is-active' : ''; ?>"
					role="tab"
					id="<?php echo esc_attr( $dom_base . '-tab-' . $pk ); ?>"
					data-sto-sync-accordion-pane="<?php echo esc_attr( $pk ); ?>"
					aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
					aria-controls="<?php echo esc_attr( $pane_id ); ?>"
					tabindex="<?php echo esc_attr( $tab_idx ); ?>"
				><?php echo esc_html( $panel['label'] ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed>|null $f
	 * @param string|null               $parent_device_bp
	 * @return array<string, mixed>|null
	 */
	private function with_accordion_responsive_pane_bp( $f, $parent_device_bp ) {
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
		$out                                                = $f;
		$out[ ResponsiveConfig::PARENT_RESPONSIVE_PANE_BP ] = $bp;

		return $out;
	}

	/**
	 * @param string                    $section_slug
	 * @param string                    $composite_id
	 * @param string                    $kind
	 * @param 'default'|'group_inner'   $inner_ctx
	 * @param string|null               $parent_device_bp
	 */
	private function render_inner_by_kind( $section_slug, $composite_id, $kind, $inner_ctx = 'group_inner', $parent_device_bp = null ) {
		switch ( $kind ) {
			case 'typography':
				$f = Typography::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					Typography::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'color':
				$f = Color::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					Color::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'background_control':
				$f = BackgroundControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					BackgroundControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'border':
				$f = BorderControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					BorderControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'shadow':
				$f = ShadowControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					ShadowControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'gradient':
				$f = GradientControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					GradientControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'switcher':
				$f = Switcher::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					Switcher::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'checkbox':
				$f = CheckboxControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					CheckboxControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'image_select':
				$f = ImageSelect::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					ImageSelect::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'dynamic_object':
				$f = DynamicObject::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					DynamicObject::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'button_group':
				$f = ButtonGroup::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					ButtonGroup::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'range':
				$f = Range::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					Range::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'date':
				$f = DateField::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					DateField::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'datetime':
				$f = DateTimeField::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					DateTimeField::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'dimension':
				$f = Dimension::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					Dimension::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'icon_select':
				$f = IconSelect::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					IconSelect::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'gallery':
				$f = GalleryControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					GalleryControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'multi_text':
				$f = MultiTextControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					MultiTextControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'radio_lists':
				$f = RadioListsControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					RadioListsControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'advanced_repeater':
				$f = AdvancedRepeaterControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					AdvancedRepeaterControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'google_map':
				$f = GoogleMapControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					GoogleMapControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'alignment':
				$f = AlignmentControl::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					AlignmentControl::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'select':
				$f = Select::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					Select::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'code_editor':
				$f = CodeEditor::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					CodeEditor::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'rich_modern_editor':
				$f = RichModernEditor::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					RichModernEditor::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			case 'link_color':
				$f = LinkColor::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					LinkColor::instance()->render_field_markup( $f, $inner_ctx );
				}
				break;
			default:
				$f = Input::get_field( $section_slug, $composite_id );
				if ( $f ) {
					$f = $this->with_accordion_responsive_pane_bp( $f, $parent_device_bp );
					Input::instance()->render_field_markup( $f, $inner_ctx );
				}
		}
	}
}
