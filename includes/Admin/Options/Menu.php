<?php
namespace SimpleThemeOptions\Admin\Options;

use SimpleThemeOptions\Admin\Options\Fields\Group\Group;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\CheckboxControl\CheckboxControl;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\DateField\DateField;
use SimpleThemeOptions\Admin\Options\Fields\DateTimeField\DateTimeField;
use SimpleThemeOptions\Admin\Options\Fields\Dimension\Dimension;
use SimpleThemeOptions\Admin\Options\Fields\IconSelect\IconSelect;
use SimpleThemeOptions\Admin\Options\ImportExport\ThemeSettingsImportExport;
use SimpleThemeOptions\Admin\Options\Fields\GalleryControl\GalleryControl;
use SimpleThemeOptions\Admin\Options\Fields\MultiTextControl\MultiTextControl;
use SimpleThemeOptions\Admin\Options\Fields\GoogleMapControl\GoogleMapControl;
use SimpleThemeOptions\Admin\Options\Fields\AlignmentControl\AlignmentControl;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Admin\ThemeSettingsCleanScreen;
use SimpleThemeOptions\Admin\ThemeSettingsMetabox;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Menu {
	use SingletonTrait;

	/** Legacy `options-general.php?page=` slug; redirects to {@see ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE} under Tools. */
	public const PACKAGED_DEMO_SETTINGS_PAGE = 'sto-packaged-theme-settings-demo';

	private $sections = array();
	private $sub_sections = array();
	private $parent_menu_slug = '';

	/**
	 * Every top-level `admin.php?page=` slug passed to {@see register()} (order = registration order).
	 * {@see $parent_menu_slug} stays the **first** slug for backwards compatibility (primary Theme Settings URL, export gating, etc.).
	 *
	 * @var array<int, string>
	 */
	private $registered_menu_slugs = array();

	/**
	 * While `register()` runs, sections/subsections bind to this slug unless overridden via `sto_menu_page` in args.
	 *
	 * @var string
	 */
	private $registration_context_slug = '';

	/** @var bool */
	private $core_admin_hooks_attached = false;

	/** @var bool */
	private $option_fields_included = false;

	/**
	 * Human-readable admin menu title per `admin.php?page=` slug (from {@see register()}).
	 *
	 * @var array<string, string>
	 */
	private $registered_menu_labels = array();

	/**
	 * Whether each registered `admin.php?page=` root is the packaged STO demo (that root gets no Advance leaf).
	 *
	 * @var array<string, bool>
	 */
	private $menu_root_packaged_demo = array();

	/**
	 * When **true**, this registration is the plugin’s packaged **Theme Settings** demo only: no **Advance** UI,
	 * and the top-level admin menu is omitted while {@see is_demo_mode_enabled()} is false (Redux-style off).
	 *
	 * @var bool
	 */
	private $packaged_demo_menu = false;

	/**
	 * When false, sample sections cannot be shown (no Field samples / Colors & surfaces / Accordion).
	 * User-facing demo visibility also requires {@see is_demo_mode_enabled()} (stored preference, default off).
	 *
	 * @var bool
	 */
	private $demo_capability_allowed = true;

	/**
	 * Stack of post IDs for {@see filter_option_sto_options_metabox_overlay()} while rendering the Theme Settings metabox.
	 *
	 * @var array<int, int>
	 */
	private $sto_options_metabox_overlay_post_ids = array();

	/**
	 * Whether the packaged **Field samples** / **Colors & surfaces** / **Accordion** demo UI is active.
	 * Requires {@see is_demo_capability_allowed()} and option **`sto_theme_settings_ui_demo_enabled`** (default off).
	 * For **client** menus, that option is toggled from **Advance**; for {@see is_packaged_demo_menu()}, use **Tools → Simple Backup** (Demo mode).
	 * Filter {@see 'sto_theme_settings_demo_mode'} receives this combined boolean.
	 */
	public function is_demo_mode_enabled() {
		$ui_on = wp_validate_boolean( get_option( ThemeSettingsImportExport::OPTION_UI_DEMO_ENABLED, false ) );

		$base = $this->demo_capability_allowed && $ui_on;

		return (bool) apply_filters( 'sto_theme_settings_demo_mode', $base );
	}

	/**
	 * Whether post editor Theme Settings metaboxes should appear (any root registered a metabox, preference on, packaged demo gate).
	 *
	 * When no metabox roots are registered, returns **false** (filter not applied). Otherwise the stored option **`sto_theme_settings_ui_metabox_enabled`** (default **true**) is evaluated, then filter **`sto_theme_settings_metabox_ui_enabled`**.
	 */
	public function should_show_theme_settings_metaboxes(): bool {
		if ( ThemeSettingsMetabox::instance()->get_roots() === array() ) {
			return false;
		}
		if ( $this->is_packaged_demo_menu() && ! $this->is_demo_mode_enabled() ) {
			return false;
		}
		$on = wp_validate_boolean( get_option( ThemeSettingsImportExport::OPTION_UI_METABOX_ENABLED, true ) );

		return (bool) apply_filters( 'sto_theme_settings_metabox_ui_enabled', $on );
	}

	/**
	 * Global `sto_options` merged with per-post metabox overrides for this post (post wins on key collisions).
	 *
	 * @return array<string, mixed>
	 */
	public function get_effective_sto_options_for_post( int $post_id ): array {
		$post_id = (int) $post_id;
		$base    = get_option( 'sto_options', array() );
		if ( ! is_array( $base ) ) {
			$base = array();
		}
		if ( $post_id <= 0 ) {
			return $base;
		}
		$ov = get_post_meta( $post_id, ThemeSettingsMetabox::POST_SETTINGS_META_KEY, true );
		if ( ! is_array( $ov ) || $ov === array() ) {
			return $base;
		}

		return array_merge( $base, $ov );
	}

	/**
	 * While rendering metabox HTML, merge {@see ThemeSettingsMetabox::POST_SETTINGS_META_KEY} over `sto_options` for all `get_option( 'sto_options' )` reads.
	 */
	public function push_sto_options_metabox_overlay( int $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$this->sto_options_metabox_overlay_post_ids[] = $post_id;
		if ( count( $this->sto_options_metabox_overlay_post_ids ) === 1 ) {
			add_filter( 'option_sto_options', array( $this, 'filter_option_sto_options_metabox_overlay' ), 10, 2 );
		}
	}

	public function pop_sto_options_metabox_overlay(): void {
		array_pop( $this->sto_options_metabox_overlay_post_ids );
		if ( $this->sto_options_metabox_overlay_post_ids === array() ) {
			remove_filter( 'option_sto_options', array( $this, 'filter_option_sto_options_metabox_overlay' ), 10 );
		}
	}

	/**
	 * @param mixed  $value  Option value from the database.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public function filter_option_sto_options_metabox_overlay( $value, $option ) {
		if ( $option !== 'sto_options' || $this->sto_options_metabox_overlay_post_ids === array() ) {
			return $value;
		}
		$post_id = (int) end( $this->sto_options_metabox_overlay_post_ids );
		if ( $post_id <= 0 ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		$ov = get_post_meta( $post_id, ThemeSettingsMetabox::POST_SETTINGS_META_KEY, true );
		if ( ! is_array( $ov ) || $ov === array() ) {
			return $value;
		}

		return array_merge( $value, $ov );
	}

	/**
	 * Whether `register()` allows the demo UI at all (`'demo' => false` forbids it; no Advance switcher).
	 */
	public function is_demo_capability_allowed() {
		return $this->demo_capability_allowed;
	}

	/**
	 * Whether this `admin.php?page=` root was registered with **`packaged_demo` => true** (no Advance on that root).
	 */
	public function is_menu_root_packaged_demo( $slug ): bool {
		$slug = sanitize_key( (string) $slug );

		return ! empty( $this->menu_root_packaged_demo[ $slug ] );
	}

	/**
	 * Whether the packaged **Theme Settings** demo is active for the **first** registered root (legacy helper).
	 */
	public function is_packaged_demo_menu(): bool {
		return $this->packaged_demo_menu;
	}

	/**
	 * Leaf section slugs that belong to the packaged field samples / colors / accordion demo (excluded from client exports).
	 *
	 * @return array<int, string>
	 */
	public static function get_packaged_demo_sample_leaf_slugs(): array {
		$slugs = array(
			'layout-nav',
			'layout-type',
			'layout-inputs',
			'layout-code-borders',
			'layout-tabs-side',
			'appearance-color',
			'appearance-gradient',
			'appearance-surfaces',
			'appearance-links',
			'accordion',
		);

		/**
		 * Leaf slugs treated as packaged demo sample panels for export filtering.
		 *
		 * @param array<int, string> $slugs
		 */
		$filtered = apply_filters( 'sto_packaged_demo_sample_leaf_slugs', $slugs );

		return is_array( $filtered ) ? array_values( array_unique( array_map( 'sanitize_key', $filtered ) ) ) : $slugs;
	}

	/**
	 * Option keys registered for every navigable leaf (Theme Settings field samples, client menus, Advance, etc.).
	 *
	 * Packaged demo sample leaves are **included** so full-site backups match what the admin shows. To omit keys,
	 * use filter {@see 'sto_theme_settings_export_option_keys'} or the legacy list from {@see get_packaged_demo_sample_leaf_slugs()}
	 * inside your callback.
	 *
	 * @return array<int, string>
	 */
	public function get_exportable_registered_option_keys(): array {
		$by_key = array();
		foreach ( $this->get_leaf_sections_for_navigation() as $leaf ) {
			$slug = isset( $leaf['slug'] ) ? sanitize_key( (string) $leaf['slug'] ) : '';
			if ( $slug === '' ) {
				continue;
			}
			foreach ( $this->get_registered_option_keys_for_leaf_section( $slug ) as $k ) {
				$by_key[ $k ] = true;
			}
		}

		$keys = array_keys( $by_key );

		/**
		 * Option keys included in **Advance → Export** (subset of `sto_options`).
		 *
		 * @param array<int, string> $keys Sanitized option ids.
		 * @param Menu               $menu
		 */
		$filtered = apply_filters( 'sto_theme_settings_export_option_keys', $keys, $this, null );

		if ( ! is_array( $filtered ) ) {
			return $keys;
		}

		$out = array();
		foreach ( $filtered as $k ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			$k = sanitize_key( $k );
			if ( $k !== '' ) {
				$out[ $k ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * Option keys registered on leaves tied to the given top-level menu slugs (union). Same rules and **`sto_theme_settings_export_option_keys`** filter as {@see get_exportable_registered_option_keys()}, with a non-null third filter argument listing the requested menu slugs.
	 *
	 * @param array<int, string> $menu_page_slugs Registered `admin.php?page=` slugs.
	 * @return array<int, string>
	 */
	public function get_exportable_registered_option_keys_for_menu_pages( array $menu_page_slugs ): array {
		$allowed_roots = array_flip( $this->registered_menu_slugs );
		$want          = array();
		foreach ( $menu_page_slugs as $p ) {
			$p = sanitize_key( (string) $p );
			if ( $p !== '' && isset( $allowed_roots[ $p ] ) ) {
				$want[ $p ] = true;
			}
		}
		if ( empty( $want ) ) {
			return array();
		}

		$by_key = array();
		foreach ( $this->get_leaf_sections_for_navigation() as $leaf ) {
			$slug = isset( $leaf['slug'] ) ? sanitize_key( (string) $leaf['slug'] ) : '';
			if ( $slug === '' ) {
				continue;
			}
			$meta = $this->get_section_by_slug( $slug );
			if ( ! $meta ) {
				continue;
			}
			$page = $this->get_section_row_menu_page( $meta );
			if ( ! isset( $want[ $page ] ) ) {
				continue;
			}
			foreach ( $this->get_registered_option_keys_for_leaf_section( $slug ) as $k ) {
				$by_key[ $k ] = true;
			}
		}

		$keys           = array_keys( $by_key );
		$menu_slugs_arg = array_keys( $want );

		$filtered = apply_filters( 'sto_theme_settings_export_option_keys', $keys, $this, $menu_slugs_arg );

		if ( ! is_array( $filtered ) ) {
			return $keys;
		}

		$out = array();
		foreach ( $filtered as $k ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			$k = sanitize_key( $k );
			if ( $k !== '' ) {
				$out[ $k ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * Human-readable title for a registered top-level options `page` slug.
	 */
	public function get_registered_menu_page_title( $slug ): string {
		$slug = sanitize_key( (string) $slug );
		if ( $slug !== '' && isset( $this->registered_menu_labels[ $slug ] ) && $this->registered_menu_labels[ $slug ] !== '' ) {
			return (string) $this->registered_menu_labels[ $slug ];
		}

		return $slug;
	}

	/**
	 * Register a top-level Theme Settings–style admin page (or an additional one). Call once for the primary menu, then again for extra roots (e.g. **Theme Settings** + **Store**). Only the **first** call applies **`demo`** and **`packaged_demo`**; later calls add another **`add_menu_page`** + scoped navigation. Sections added while this method runs bind to this slug unless **`sto_menu_page`** is set on the section args.
	 *
	 * @param string               $name Admin menu page title.
	 * @param string               $slug Top-level `page` slug (e.g. `theme-settings`).
	 * @param string               $icon Dashicon class or URL.
	 * @param array<string, mixed> $args Optional. **`demo`** (bool) — default **true** (samples may show when {@see is_demo_mode_enabled()}). **`packaged_demo`** (bool) — when **true** (plugin sample menu only): no **Advance**; top-level menu hidden while demo is off; use **Tools → Simple Backup** (Demo mode) to turn the panel on or off. Omit or **false** for a theme‑registered client menu (**Advance** + export of registered keys on all leaves). **Read on first call only** for **`demo`** / **`packaged_demo`**. **`metabox`** => array( **post_types** => array( 'post', 'page' ), optional **title**, **context**, **priority** ) registers the same Theme Settings UI as a post editor metabox on those types (writes that post’s overrides to meta **`_sto_theme_settings_post`** via AJAX when the post is saved — no separate **Save options** button; merged over global `sto_options` while editing; do not stack duplicate `#sto-theme-settings-options-form` ids on one screen). Shorthand: **`enable_metabox`** => true with optional **`metabox_post_types`** (defaults to post + page when enabled). Visibility is also gated by option **`sto_theme_settings_ui_metabox_enabled`** (default **on**), toggled from **Advance** or **Tools → Simple Backup** when any metabox root exists; see {@see should_show_theme_settings_metaboxes()}.
	 */
	public function register( $name, $slug, $icon, $args = array() ) {
		$args   = is_array( $args ) ? $args : array();
		$slug_s = sanitize_key( (string) $slug );
		if ( $slug_s === '' ) {
			return;
		}

		$is_first_root = empty( $this->registered_menu_slugs );

		$packaged_this = array_key_exists( 'packaged_demo', $args ) ? (bool) $args['packaged_demo'] : false;

		if ( $is_first_root ) {
			$this->parent_menu_slug        = $slug_s;
			$this->demo_capability_allowed = array_key_exists( 'demo', $args ) ? (bool) $args['demo'] : true;
			$this->packaged_demo_menu      = $packaged_this;
		}

		$this->menu_root_packaged_demo[ $slug_s ] = $packaged_this;

		if ( ! in_array( $slug_s, $this->registered_menu_slugs, true ) ) {
			$this->registered_menu_slugs[] = $slug_s;
		}

		$this->registration_context_slug     = $slug_s;
		$this->registered_menu_labels[ $slug_s ] = is_string( $name ) ? $name : '';

		if ( ! $this->core_admin_hooks_attached ) {
			$this->core_admin_hooks_attached = true;
			add_action( 'admin_init', array( $this, 'redirect_legacy_packaged_demo_gate_url' ), 0 );
			add_action( 'admin_init', array( $this, 'redirect_legacy_tools_backup_page_slug' ), 0 );
			add_action( 'admin_init', array( $this, 'redirect_packaged_demo_blocked_screen' ), 0 );
			add_action( 'admin_init', array( $this, 'maybe_handle_save_request' ) );
			add_action( 'admin_init', array( $this, 'redirect_theme_settings_to_canonical_leaf' ), 1 );
		}

		add_action(
			'admin_menu',
			function () use ( $name, $slug_s, $icon ) {
				if ( $this->is_packaged_demo_menu() && ! $this->is_demo_mode_enabled() && $slug_s === $this->parent_menu_slug ) {
					return;
				}

				add_menu_page( $name, $name, 'manage_options', $slug_s, array( $this, 'render_menu_page' ), $icon, 10 );

				foreach ( $this->get_sections_for_navigation_for_menu_page( $slug_s ) as $section ) {
					add_submenu_page(
						$slug_s,
						$section['name'],
						$section['name'],
						'manage_options',
						$slug_s . '&section=' . $section['slug'],
						array( $this, 'render_menu_page' )
					);
				}
			},
			10
		);

		// Remove WP auto-added duplicate parent submenu after all submenu items are registered.
		add_action(
			'admin_menu',
			function () use ( $slug_s ) {
				remove_submenu_page( $slug_s, $slug_s );

				global $submenu;
				if ( isset( $submenu[ $slug_s ] ) && is_array( $submenu[ $slug_s ] ) ) {
					foreach ( $submenu[ $slug_s ] as $index => $submenu_item ) {
						if ( isset( $submenu_item[2] ) && $submenu_item[2] === $slug_s ) {
							unset( $submenu[ $slug_s ][ $index ] );
						}
					}
					$submenu[ $slug_s ] = array_values( $submenu[ $slug_s ] );
				}
			},
			999
		);

		add_filter(
			'parent_file',
			function ( $parent_file ) use ( $slug_s ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === $slug_s && isset( $_GET['section'] ) ) {
					return $slug_s;
				}

				return $parent_file;
			}
		);

		add_filter(
			'submenu_file',
			function ( $submenu_file ) use ( $slug_s ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== $slug_s ) {
					return $submenu_file;
				}

				$leaf = $this->get_current_section_slug();
				if ( $leaf === '' ) {
					return $submenu_file;
				}

				$highlight = $this->get_wp_submenu_highlight_slug_for_leaf( $leaf );

				return $highlight !== '' ? $slug_s . '&section=' . $highlight : $submenu_file;
			}
		);

		ThemeSettingsCleanScreen::instance()->hook_clean_screen( $slug_s );

		if ( ! $this->option_fields_included ) {
			$this->option_fields_included = true;
			$this->include_fields();
		}

		$metabox_cfg = $this->parse_metabox_register_args( $args );
		if ( ! empty( $metabox_cfg['post_types'] ) ) {
			ThemeSettingsMetabox::instance()->register_root(
				$slug_s,
				array(
					'post_types' => $metabox_cfg['post_types'],
					'title'      => isset( $metabox_cfg['title'] ) ? (string) $metabox_cfg['title'] : '',
					'context'    => isset( $metabox_cfg['context'] ) ? (string) $metabox_cfg['context'] : 'normal',
					'priority'   => isset( $metabox_cfg['priority'] ) ? (string) $metabox_cfg['priority'] : 'low',
				)
			);
		}
	}

	/**
	 * Parse metabox-related keys from {@see register()} $args.
	 *
	 * Supported shapes:
	 * - `'metabox' => array( 'post_types' => array( 'page', 'post' ), 'title' => '…', 'context' => 'normal', 'priority' => 'low' )`
	 * - `'enable_metabox' => true` with optional `'metabox_post_types' => array( … )` (defaults to post + page).
	 *
	 * @param array<string, mixed> $args
	 * @return array{post_types: array<int, string>, title?: string, context?: string, priority?: string}
	 */
	private function parse_metabox_register_args( array $args ): array {
		$out = array(
			'post_types' => array(),
			'title'      => '',
			'context'    => 'normal',
			'priority'   => 'low',
		);

		$from_nested = isset( $args['metabox'] ) && is_array( $args['metabox'] ) ? $args['metabox'] : array();

		if ( ! empty( $from_nested['post_types'] ) && is_array( $from_nested['post_types'] ) ) {
			foreach ( $from_nested['post_types'] as $pt ) {
				$pt = sanitize_key( (string) $pt );
				if ( $pt !== '' ) {
					$out['post_types'][] = $pt;
				}
			}
		} elseif ( ! empty( $args['enable_metabox'] ) ) {
			$pts = isset( $args['metabox_post_types'] ) && is_array( $args['metabox_post_types'] ) ? $args['metabox_post_types'] : array( 'post', 'page' );
			foreach ( $pts as $pt ) {
				$pt = sanitize_key( (string) $pt );
				if ( $pt !== '' ) {
					$out['post_types'][] = $pt;
				}
			}
		}

		$out['post_types'] = array_values( array_unique( $out['post_types'] ) );

		if ( isset( $from_nested['title'] ) && is_string( $from_nested['title'] ) ) {
			$out['title'] = $from_nested['title'];
		}
		if ( isset( $from_nested['context'] ) && is_string( $from_nested['context'] ) && $from_nested['context'] !== '' ) {
			$out['context'] = $from_nested['context'];
		}
		if ( isset( $from_nested['priority'] ) && is_string( $from_nested['priority'] ) && $from_nested['priority'] !== '' ) {
			$out['priority'] = $from_nested['priority'];
		}

		return $out;
	}

	/**
	 * Old bookmarks to **Settings → Theme Settings (demo)** redirect to **Tools → Simple Backup**.
	 */
	public function redirect_legacy_packaged_demo_gate_url(): void {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $pagenow;
		if ( $pagenow !== 'options-general.php' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( (string) $_GET['page'] ) ) !== self::PACKAGED_DEMO_SETTINGS_PAGE ) {
			return;
		}

		wp_safe_redirect( admin_url( 'tools.php?page=' . rawurlencode( ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE ) ) );
		exit;
	}

	/**
	 * Legacy **`tools.php?page=sto-theme-options-backup`** redirects to **Simple Backup**, preserving other query args (e.g. **`section`**).
	 */
	public function redirect_legacy_tools_backup_page_slug(): void {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $pagenow;
		if ( $pagenow !== 'tools.php' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( (string) $_GET['page'] ) ) !== ThemeSettingsImportExport::LEGACY_TOOLS_BACKUP_PAGE_SLUG ) {
			return;
		}

		$args = array();
		foreach ( $_GET as $key => $value ) {
			$key = sanitize_key( wp_unslash( (string) $key ) );
			if ( $key === '' || is_array( $value ) ) {
				continue;
			}
			$value = wp_unslash( $value );
			if ( ! is_string( $value ) ) {
				continue;
			}
			$args[ $key ] = sanitize_text_field( $value );
		}
		$args['page'] = ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE;

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Block direct `admin.php?page=…` access when the packaged demo menu is turned off.
	 */
	public function redirect_packaged_demo_blocked_screen(): void {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->is_packaged_demo_menu() || $this->is_demo_mode_enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page === '' || $page !== $this->parent_menu_slug ) {
			return;
		}

		wp_safe_redirect( admin_url( 'tools.php?page=' . rawurlencode( ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE ) ) );
		exit;
	}

    /**
     * Navigable leaf slugs (section => true) for save validation.
     *
     * @return array<string, true>
     */
    private function get_leaf_section_slug_map() {
        $map = array();
        foreach ( $this->get_leaf_sections_for_navigation() as $leaf ) {
            if ( ! empty( $leaf['slug'] ) ) {
                $map[ sanitize_key( (string) $leaf['slug'] ) ] = true;
            }
        }

        return $map;
    }

    /**
     * @param string $menu_page_slug
     * @return array<string, true>
     */
    private function get_leaf_section_slug_map_for_menu_page( $menu_page_slug ) {
        $menu_page_slug = sanitize_key( (string) $menu_page_slug );
        $map            = array();
        foreach ( $this->get_leaf_sections_for_navigation_for_menu_page( $menu_page_slug ) as $leaf ) {
            if ( ! empty( $leaf['slug'] ) ) {
                $map[ sanitize_key( (string) $leaf['slug'] ) ] = true;
            }
        }

        return $map;
    }

    /**
     * Canonical leaf for a menu root (parent slug → first child; unknown → default leaf).
     *
     * @param string $menu_ctx      Registered `admin.php?page=` slug.
     * @param string $section_slug  Requested section or parent slug.
     */
    public function resolve_canonical_leaf_for_menu_and_section( string $menu_ctx, string $section_slug ): string {
        $menu_ctx = sanitize_key( $menu_ctx );
        if ( $menu_ctx === '' || ! in_array( $menu_ctx, $this->registered_menu_slugs, true ) ) {
            $menu_ctx = $this->get_parent_menu_slug();
        }

        $raw = sanitize_key( (string) $section_slug );
        if ( $raw === '' ) {
            return $this->get_default_leaf_section_slug_for_menu_page( $menu_ctx );
        }

        $resolved = $this->resolve_to_first_leaf_slug( $raw );
        $leaf_map = $this->get_leaf_section_slug_map_for_menu_page( $menu_ctx );

        if ( $resolved !== '' && isset( $leaf_map[ $resolved ] ) ) {
            return $resolved;
        }

        return $this->get_default_leaf_section_slug_for_menu_page( $menu_ctx );
    }

    /**
     * Canonical leaf `section` for a save request (parent slug → first child; unknown → default leaf).
     */
    private function resolve_canonical_leaf_for_save( $section_slug ) {
        $menu_ctx = $this->get_parent_menu_slug();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['sto_ts_page'] ) ) {
            $p = sanitize_key( wp_unslash( $_POST['sto_ts_page'] ) );
            if ( $p !== '' && in_array( $p, $this->registered_menu_slugs, true ) ) {
                $menu_ctx = $p;
            }
        }

        return $this->resolve_canonical_leaf_for_menu_and_section( $menu_ctx, (string) $section_slug );
    }

    /**
     * All `sto_options` keys registered for one leaf panel (standalone + group-inner fields).
     *
     * @return array<int, string>
     */
    public function get_registered_option_keys_for_leaf_section( $section_slug ) {
        $section_slug = sanitize_key( (string) $section_slug );
        $keys         = array();

        $chunks = array(
            ImageSelect::get_field_ids_for_section( $section_slug ),
            ButtonGroup::get_field_ids_for_section( $section_slug ),
            Select::get_field_ids_for_section( $section_slug ),
            CheckboxControl::get_field_ids_for_section( $section_slug ),
            Switcher::get_field_ids_for_section( $section_slug ),
            Color::get_field_ids_for_section( $section_slug ),
            BackgroundControl::get_field_ids_for_section( $section_slug ),
            BorderControl::get_field_ids_for_section( $section_slug ),
            ShadowControl::get_field_ids_for_section( $section_slug ),
            GradientControl::get_field_ids_for_section( $section_slug ),
            LinkColor::get_field_ids_for_section( $section_slug ),
            Input::get_field_ids_for_section( $section_slug ),
            DateField::get_field_ids_for_section( $section_slug ),
            DateTimeField::get_field_ids_for_section( $section_slug ),
            Dimension::get_field_ids_for_section( $section_slug ),
            IconSelect::get_field_ids_for_section( $section_slug ),
            GalleryControl::get_field_ids_for_section( $section_slug ),
            MultiTextControl::get_field_ids_for_section( $section_slug ),
            GoogleMapControl::get_field_ids_for_section( $section_slug ),
            AlignmentControl::get_field_ids_for_section( $section_slug ),
            Range::get_field_ids_for_section( $section_slug ),
            CodeEditor::get_field_ids_for_section( $section_slug ),
            Typography::get_field_ids_for_section( $section_slug ),
            DynamicObject::get_field_ids_for_section( $section_slug ),
        );

        foreach ( $chunks as $ids ) {
            foreach ( $ids as $id ) {
                $id = sanitize_key( (string) $id );
                if ( $id !== '' ) {
                    $keys[ $id ] = true;
                }
            }
        }

        return array_keys( $keys );
    }

    /**
     * Transient key for validation messages after a failed save (per user).
     */
    private function get_validation_notice_transient_name() {
        $uid = get_current_user_id();

        return $uid > 0 ? 'sto_ts_validate_' . $uid : 'sto_ts_validate_0';
    }

    /**
     * Merge and persist `sto_options` keys for one leaf (same rules as Theme Settings POST save).
     *
     * @param array<string, mixed> $posted_options_raw Unslashed `sto_options` subset from the request.
     * @param bool                 $store_validation_transient When validation fails, store {@see get_validation_notice_transient_name()} payload for admin redirect screens.
     * @param int                  $metabox_post_id When **> 0**, persist this leaf’s keys to that post’s {@see ThemeSettingsMetabox::POST_SETTINGS_META_KEY} only (no global `update_option`).
     * @return true|\WP_Error `WP_Error` with code `sto_theme_settings_validation` and `messages` list in error data.
     */
    public function persist_theme_settings_leaf( string $menu_page_slug, string $requested_section_slug, array $posted_options_raw, bool $store_validation_transient = true, int $metabox_post_id = 0 ) {
        $menu_page_slug = sanitize_key( $menu_page_slug );
        if ( $menu_page_slug === '' || ! in_array( $menu_page_slug, $this->registered_menu_slugs, true ) ) {
            return new \WP_Error( 'sto_theme_settings_bad_menu', __( 'Invalid Theme Settings menu.', 'simple-theme-options' ) );
        }

        $metabox_post_id = (int) $metabox_post_id;
        if ( $metabox_post_id > 0 && ! current_user_can( 'edit_post', $metabox_post_id ) ) {
            return new \WP_Error( 'sto_theme_settings_bad_post', __( 'You cannot edit this post’s Theme Settings.', 'simple-theme-options' ) );
        }

        $section_slug = $this->resolve_canonical_leaf_for_menu_and_section( $menu_page_slug, $requested_section_slug );
        $section_key_map = array_flip( $this->get_registered_option_keys_for_leaf_section( $section_slug ) );

        $posted_options = $posted_options_raw;
        if ( ! empty( $section_key_map ) ) {
            $posted_options = array_intersect_key( $posted_options, $section_key_map );
        } else {
            $posted_options = array();
        }

        $sanitized_options = array();

        foreach ( $posted_options as $key => $value ) {
            $option_key = sanitize_key( (string) $key );
            if ( ! $option_key ) {
                continue;
            }

            if ( Typography::is_registered_field_id( $option_key ) ) {
                $raw_typ = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Typography::sanitize_posted_value( $option_key, $raw_typ );
                continue;
            }

            if ( BackgroundControl::is_registered_field_id( $option_key ) ) {
                $raw_bg = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = BackgroundControl::sanitize_posted_value( $option_key, $raw_bg );
                continue;
            }

            if ( BorderControl::is_registered_field_id( $option_key ) ) {
                $raw_border = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = BorderControl::sanitize_posted_value( $option_key, $raw_border );
                continue;
            }

            if ( ShadowControl::is_registered_field_id( $option_key ) ) {
                $raw_shadow = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = ShadowControl::sanitize_posted_value( $option_key, $raw_shadow );
                continue;
            }

            if ( GradientControl::is_registered_field_id( $option_key ) ) {
                $raw_grad = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = GradientControl::sanitize_posted_value( $option_key, $raw_grad );
                continue;
            }

            if ( LinkColor::is_registered_field_id( $option_key ) ) {
                $raw_lc = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = LinkColor::sanitize_posted_value( $option_key, $raw_lc );
                continue;
            }

            if ( Color::is_registered_field_id( $option_key ) ) {
                $raw_color = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Color::sanitize_posted_value( $option_key, $raw_color );
                continue;
            }

            if ( Switcher::is_registered_field_id( $option_key ) ) {
                $raw_sw = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Switcher::sanitize_posted_value( $option_key, $raw_sw );
                continue;
            }

            if ( CheckboxControl::is_registered_field_id( $option_key ) ) {
                $raw_cb = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = CheckboxControl::sanitize_posted_value( $option_key, $raw_cb );
                continue;
            }

            if ( Select::is_registered_field_id( $option_key ) ) {
                $raw_sel = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Select::sanitize_posted_value( $option_key, $raw_sel );
                continue;
            }

            if ( ImageSelect::is_registered_field_id( $option_key ) ) {
                $raw_img = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = ImageSelect::sanitize_posted_value( $option_key, $raw_img );
                continue;
            }

            if ( DynamicObject::is_registered_field_id( $option_key ) ) {
                $raw_dyn = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : null;
                $sanitized_options[ $option_key ] = DynamicObject::instance()->sanitize_for_field( $option_key, $raw_dyn );
                continue;
            }

            if ( Input::is_registered_field_id( $option_key ) ) {
                $raw_in = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Input::sanitize_posted_value( $option_key, $raw_in );
                continue;
            }

            if ( DateField::is_registered_field_id( $option_key ) ) {
                $raw_date = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = DateField::sanitize_posted_value( $option_key, $raw_date );
                continue;
            }

            if ( DateTimeField::is_registered_field_id( $option_key ) ) {
                $raw_dt = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = DateTimeField::sanitize_posted_value( $option_key, $raw_dt );
                continue;
            }

            if ( Dimension::is_registered_field_id( $option_key ) ) {
                $raw_dim = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Dimension::sanitize_posted_value( $option_key, $raw_dim );
                continue;
            }

            if ( IconSelect::is_registered_field_id( $option_key ) ) {
                $raw_icon = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = IconSelect::sanitize_posted_value( $option_key, $raw_icon );
                continue;
            }

            if ( GalleryControl::is_registered_field_id( $option_key ) ) {
                $raw_gal = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = GalleryControl::sanitize_posted_value( $option_key, $raw_gal );
                continue;
            }

            if ( MultiTextControl::is_registered_field_id( $option_key ) ) {
                $raw_mt = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = MultiTextControl::sanitize_posted_value( $option_key, $raw_mt );
                continue;
            }

            if ( GoogleMapControl::is_registered_field_id( $option_key ) ) {
                $raw_map = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = GoogleMapControl::sanitize_posted_value( $option_key, $raw_map );
                continue;
            }

            if ( AlignmentControl::is_registered_field_id( $option_key ) ) {
                $raw_aln = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = AlignmentControl::sanitize_posted_value( $option_key, $raw_aln );
                continue;
            }

            if ( Range::is_registered_field_id( $option_key ) ) {
                $raw_range = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Range::sanitize_posted_value( $option_key, $raw_range );
                continue;
            }

            if ( CodeEditor::is_registered_field_id( $option_key ) ) {
                $raw_code = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = CodeEditor::sanitize_posted_value( $option_key, $raw_code );
                continue;
            }

            if ( ButtonGroup::is_registered_field_id( $option_key ) ) {
                $raw_bg = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = ButtonGroup::sanitize_posted_value( $option_key, $raw_bg );
                continue;
            }

            if ( is_array( $value ) ) {
                $sanitized_options[ $option_key ] = array_map( 'sanitize_text_field', $value );
            } else {
                $sanitized_options[ $option_key ] = sanitize_text_field( (string) $value );
            }
        }

        Select::instance()->merge_missing_multiple_select_fields( $posted_options, $sanitized_options, $section_key_map );
        CheckboxControl::instance()->merge_missing_multiple_checkbox_fields( $posted_options, $sanitized_options, $section_key_map );
        DynamicObject::instance()->merge_missing_multiple_dynamic_fields( $posted_options, $sanitized_options, $section_key_map );

        $existing = $metabox_post_id > 0
            ? $this->get_effective_sto_options_for_post( $metabox_post_id )
            : (array) get_option( 'sto_options', array() );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        foreach ( array_keys( $section_key_map ) as $k ) {
            if ( ! array_key_exists( $k, $sanitized_options ) && array_key_exists( $k, $existing ) ) {
                $sanitized_options[ $k ] = $existing[ $k ];
            }
        }

        foreach ( $existing as $k => $v ) {
            $k = sanitize_key( (string) $k );
            if ( ! $k || isset( $section_key_map[ $k ] ) ) {
                continue;
            }
            $sanitized_options[ $k ] = $v;
        }

        /**
         * Full merged option array immediately before validation and persistence.
         *
         * @param array<string, mixed> $sanitized_options
         * @param string                 $section_slug    Active leaf section slug.
         */
        $sanitized_options = apply_filters( 'sto_options_before_save', $sanitized_options, $section_slug );

        /**
         * Extra validation errors when saving one leaf section (HTML required, etc.).
         *
         * @param array<int, string>   $errors
         * @param string               $section_slug
         * @param array<string, mixed> $sanitized_options Full merged preview.
         * @param array<string, mixed> $posted_options    Posted `sto_options` slice for this request.
         */
        $validation_errors = apply_filters(
            'sto_theme_settings_validation_errors',
            array_merge(
                Input::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                DateField::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                DateTimeField::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                Dimension::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                IconSelect::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                GalleryControl::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                MultiTextControl::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                GoogleMapControl::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                AlignmentControl::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options )
            ),
            $section_slug,
            $sanitized_options,
            $posted_options
        );

        if ( ! empty( $validation_errors ) ) {
            if ( $store_validation_transient ) {
                set_transient(
                    $this->get_validation_notice_transient_name(),
                    array(
                        'section'  => $section_slug,
                        'messages' => array_values( array_filter( array_map( 'strval', $validation_errors ) ) ),
                    ),
                    120
                );
            }

            return new \WP_Error(
                'sto_theme_settings_validation',
                __( 'Validation failed.', 'simple-theme-options' ),
                array(
                    'messages' => array_values( array_filter( array_map( 'strval', $validation_errors ) ) ),
                )
            );
        }

        if ( $metabox_post_id > 0 ) {
            $prev = get_post_meta( $metabox_post_id, ThemeSettingsMetabox::POST_SETTINGS_META_KEY, true );
            if ( ! is_array( $prev ) ) {
                $prev = array();
            }
            foreach ( array_keys( $section_key_map ) as $k ) {
                if ( array_key_exists( $k, $sanitized_options ) ) {
                    $prev[ $k ] = $sanitized_options[ $k ];
                }
            }
            update_post_meta( $metabox_post_id, ThemeSettingsMetabox::POST_SETTINGS_META_KEY, $prev );

            return true;
        }

        update_option( 'sto_options', $sanitized_options );

        return true;
    }

    public function maybe_handle_save_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! isset( $_POST['sto_save_options'] ) ) {
            return;
        }

        global $pagenow;
        if ( $pagenow === 'post.php' || $pagenow === 'post-new.php' ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $page === '' && isset( $_POST['sto_ts_page'] ) ) {
            $page = sanitize_key( wp_unslash( $_POST['sto_ts_page'] ) );
        }
        if ( $page === '' || ! in_array( $page, $this->registered_menu_slugs, true ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        check_admin_referer( 'sto_save_options_action', 'sto_save_options_nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $section_slug = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $section_slug === '' && isset( $_POST['sto_ts_section'] ) ) {
            $section_slug = sanitize_key( wp_unslash( $_POST['sto_ts_section'] ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $posted_raw = isset( $_POST['sto_options'] ) && is_array( $_POST['sto_options'] ) ? wp_unslash( $_POST['sto_options'] ) : array();

        $result = $this->persist_theme_settings_leaf( $page, $section_slug, $posted_raw, true );
        if ( is_wp_error( $result ) ) {
            $redirect_url = admin_url( 'admin.php?page=' . rawurlencode( $page ) );
            $leaf         = $this->resolve_canonical_leaf_for_menu_and_section( $page, $section_slug );
            if ( $leaf ) {
                $redirect_url = add_query_arg( 'section', $leaf, $redirect_url );
            }
            $redirect_url = add_query_arg( 'sto_validation_error', '1', $redirect_url );
            wp_safe_redirect( $redirect_url );
            exit;
        }

        $redirect_url = admin_url( 'admin.php?page=' . rawurlencode( $page ) );
        $leaf         = $this->resolve_canonical_leaf_for_menu_and_section( $page, $section_slug );
        if ( $leaf ) {
            $redirect_url = add_query_arg( 'section', $leaf, $redirect_url );
        }
        $redirect_url = add_query_arg( 'sto_saved', '1', $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Fires after menu filters are attached. Register option fields on this hook.
     */
    private function include_fields() {
        do_action( 'sto_include_option_fields', $this );
    }

    /**
     * Breadcrumb label for sidebar leaf (e.g. "General -> Layout" or "Social").
     */
    public function get_leaf_breadcrumb_label( $leaf_slug ) {
        return $this->get_breadcrumb_label_for_leaf( $leaf_slug );
    }

    private function get_breadcrumb_label_for_leaf( $leaf_slug ) {
        $leaf_slug = sanitize_key( (string) $leaf_slug );

        foreach ( $this->sub_sections as $sub ) {
            if ( isset( $sub['slug'] ) && $sub['slug'] === $leaf_slug ) {
                $parent = $this->get_section_by_slug( isset( $sub['parent_slug'] ) ? (string) $sub['parent_slug'] : '' );
                if ( $parent && isset( $parent['name'], $sub['name'] ) ) {
                    return $parent['name'] . ' -> ' . $sub['name'];
                }

                return isset( $sub['name'] ) ? (string) $sub['name'] : '';
            }
        }

        $top = $this->get_section_by_slug( $leaf_slug );

        return ( $top && isset( $top['name'] ) ) ? (string) $top['name'] : '';
    }

    /**
     * Search / quick-nav entries for Theme Settings header (sections, groups, fields).
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_search_items() {
        return $this->get_search_items_for_menu_page( $this->get_request_options_menu_slug() );
    }

    /**
     * Quick-search index scoped to one registered Theme Settings root.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_search_items_for_menu_page( string $menu_page_slug ) {
        $items = array();
        $ctx   = sanitize_key( $menu_page_slug );
        if ( $ctx === '' ) {
            $ctx = $this->get_parent_menu_slug();
        }

        foreach ( $this->get_leaf_sections_for_navigation() as $leaf ) {
            if ( empty( $leaf['slug'] ) ) {
                continue;
            }

            $slug = (string) $leaf['slug'];
            $meta = $this->get_section_by_slug( $slug );
            if ( ! $meta || $this->get_section_row_menu_page( $meta ) !== $ctx || ! $this->is_section_show_in_menu( $meta ) ) {
                continue;
            }

            $path = $this->get_breadcrumb_label_for_leaf( $slug );

            $items[] = array(
                'type'    => 'section',
                'id'      => 'section-' . $slug,
                'title'   => isset( $leaf['name'] ) ? (string) $leaf['name'] : $slug,
                'path'    => $path,
                'section' => $slug,
                'icon'    => isset( $leaf['icon'] ) ? (string) $leaf['icon'] : 'fa-light fa-folder',
                'focus'   => '',
                'page'    => $ctx,
            );
        }

        foreach ( Group::instance()->get_groups_for_search() as $group_row ) {
            $section_slug = isset( $group_row['section_slug'] ) ? sanitize_key( (string) $group_row['section_slug'] ) : '';
            $group_id     = isset( $group_row['id'] ) ? sanitize_key( (string) $group_row['id'] ) : '';
            $gtitle       = isset( $group_row['title'] ) ? (string) $group_row['title'] : '';

            if ( ! $section_slug || ! $group_id || $gtitle === '' ) {
                continue;
            }

            $leaf_sec = $this->get_section_by_slug( $section_slug );
            if ( ! $leaf_sec || $this->get_section_row_menu_page( $leaf_sec ) !== $ctx || ! $this->is_section_show_in_menu( $leaf_sec ) ) {
                continue;
            }

            $path  = $this->get_breadcrumb_label_for_leaf( $section_slug );
            $icon  = ( $leaf_sec && isset( $leaf_sec['icon'] ) ) ? (string) $leaf_sec['icon'] : 'fa-light fa-layer-group';

            $g_chain = Group::instance()->get_group_breadcrumb_titles( $section_slug, $group_id );
            if ( count( $g_chain ) > 1 ) {
                $path = $path . ' -> ' . implode( ' -> ', array_slice( $g_chain, 0, -1 ) );
            }

            $items[] = array(
                'type'    => 'group',
                'id'      => 'group-' . $group_id,
                'title'   => $gtitle,
                'path'    => $path,
                'section' => $section_slug,
                'icon'    => $icon,
                'focus'   => 'group:' . $group_id,
                'page'    => $this->get_section_row_menu_page( $leaf_sec ),
            );
        }

        $search_field_rows = array_merge(
            Select::get_all_fields_for_search(),
            DynamicObject::get_all_fields_for_search(),
            ImageSelect::get_all_fields_for_search(),
            Switcher::get_all_fields_for_search(),
            CheckboxControl::get_all_fields_for_search(),
            Color::get_all_fields_for_search(),
            Input::get_all_fields_for_search(),
            DateField::get_all_fields_for_search(),
            DateTimeField::get_all_fields_for_search(),
            Dimension::get_all_fields_for_search(),
            IconSelect::get_all_fields_for_search(),
            GalleryControl::get_all_fields_for_search(),
            MultiTextControl::get_all_fields_for_search(),
            GoogleMapControl::get_all_fields_for_search(),
            AlignmentControl::get_all_fields_for_search(),
            Range::get_all_fields_for_search(),
            ButtonGroup::get_all_fields_for_search(),
            Typography::get_all_fields_for_search(),
            BackgroundControl::get_all_fields_for_search(),
            BorderControl::get_all_fields_for_search(),
            ShadowControl::get_all_fields_for_search(),
            GradientControl::get_all_fields_for_search(),
            CodeEditor::get_all_fields_for_search(),
            LinkColor::get_all_fields_for_search()
        );

        foreach ( $search_field_rows as $field ) {
            $section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
            $fid          = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
            $ftitle       = isset( $field['title'] ) ? (string) $field['title'] : '';

            if ( ! $section_slug || ! $fid ) {
                continue;
            }

            $leaf_sec = $this->get_section_by_slug( $section_slug );
            if ( ! $leaf_sec || $this->get_section_row_menu_page( $leaf_sec ) !== $ctx || ! $this->is_section_show_in_menu( $leaf_sec ) ) {
                continue;
            }

            $base = $this->get_breadcrumb_label_for_leaf( $section_slug );
            $path = $base;

            if ( ! empty( $field['group'] ) ) {
                $chain = Group::instance()->get_group_breadcrumb_titles( $section_slug, (string) $field['group'] );
                if ( ! empty( $chain ) ) {
                    $path = $base . ' -> ' . implode( ' -> ', $chain );
                }
            }

            $leaf = $this->get_section_by_slug( $section_slug );
            $icon = ( $leaf && isset( $leaf['icon'] ) ) ? (string) $leaf['icon'] : 'fa-light fa-sliders';

            $items[] = array(
                'type'    => 'field',
                'id'      => 'field-' . $fid,
                'title'   => $ftitle,
                'path'    => $path,
                'section' => $section_slug,
                'icon'    => $icon,
                'focus'   => 'field:' . $fid,
                'page'    => $this->get_section_row_menu_page( $leaf_sec ),
            );
        }

        /**
         * Filter search index for Theme Settings quick search.
         *
         * @param array<int, array<string, mixed>> $items
         * @param Menu                             $menu
         */
        return apply_filters( 'sto_search_items', $items, $this );
    }

    /**
     * @param string               $name
     * @param string               $slug
     * @param string               $icon
     * @param array<string, mixed> $args Optional. **`nav_locked`** => true keeps the row last in the sidebar. **`show_in_menu`** => false hides the row from the in-page sidebar, WP submenu, quick search, and admin bar (deep links with `?section=` still work). **`sto_menu_page`** => top-level `admin.php?page=` slug this section belongs to (defaults to the menu being registered when the section is added).
     */
    public function add_section( $name, $slug, $icon, $args = array() ) {
        $args       = is_array( $args ) ? $args : array();
        $nav_locked = ! empty( $args['nav_locked'] );
        $show       = array_key_exists( 'show_in_menu', $args ) ? (bool) $args['show_in_menu'] : true;
        $sto_page   = '';
        if ( isset( $args['sto_menu_page'] ) && is_string( $args['sto_menu_page'] ) ) {
            $sto_page = sanitize_key( $args['sto_menu_page'] );
        }
        if ( $sto_page === '' || ! in_array( $sto_page, $this->registered_menu_slugs, true ) ) {
            $ctx      = $this->registration_context_slug !== '' ? $this->registration_context_slug : $this->get_parent_menu_slug();
            $sto_page = $ctx;
        }
        $row = array(
            'name'         => $name,
            'slug'         => $slug,
            'icon'         => $icon,
            'nav_locked'   => $nav_locked,
            'show_in_menu' => $show,
        );
        if ( $sto_page !== '' ) {
            $row['sto_menu_page'] = $sto_page;
        }
        $this->sections[] = $row;
    }

    /**
     * Whether a section row appears in sidebar / submenu / search / admin bar.
     *
     * @param array<string, mixed> $section
     */
    public function is_section_show_in_menu( array $section ): bool {
        return ! isset( $section['show_in_menu'] ) || false !== $section['show_in_menu'];
    }

    /**
     * Top-level sections for WP submenu + in-page sidebar: unlocked first, `nav_locked` last.
     * Omits rows with **`show_in_menu` => false** (Customizer-only panels may still use `?section=` URLs).
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_sections_for_navigation() {
        $unlocked = array();
        $locked   = array();
        foreach ( $this->sections as $section ) {
            if ( ! $this->is_section_show_in_menu( $section ) ) {
                continue;
            }
            if ( ! empty( $section['nav_locked'] ) ) {
                $locked[] = $section;
            } else {
                $unlocked[] = $section;
            }
        }

        return array_merge( $unlocked, $locked );
    }

    /**
     * @param string               $name
     * @param string               $slug
     * @param string               $icon
     * @param string               $parent_slug
     * @param array<string, mixed> $args Optional. **`show_in_menu`** => false hides from sidebar / submenu / search / admin bar. **`sto_menu_page`** => top-level `admin.php?page=` slug (defaults to the menu being registered when the subsection is added).
     */
    public function add_sub_section( $name, $slug, $icon, $parent_slug, $args = array() ) {
        $args     = is_array( $args ) ? $args : array();
        $show     = array_key_exists( 'show_in_menu', $args ) ? (bool) $args['show_in_menu'] : true;
        $sto_page = '';
        if ( isset( $args['sto_menu_page'] ) && is_string( $args['sto_menu_page'] ) ) {
            $sto_page = sanitize_key( $args['sto_menu_page'] );
        }
        if ( $sto_page === '' || ! in_array( $sto_page, $this->registered_menu_slugs, true ) ) {
            $ctx      = $this->registration_context_slug !== '' ? $this->registration_context_slug : $this->get_parent_menu_slug();
            $sto_page = $ctx;
        }
        $row = array(
            'name'         => $name,
            'slug'         => $slug,
            'icon'         => $icon,
            'parent_slug'  => $parent_slug,
            'show_in_menu' => $show,
        );
        if ( $sto_page !== '' ) {
            $row['sto_menu_page'] = $sto_page;
        }
        $this->sub_sections[] = $row;
    }

    public function get_sub_sections() {
        return $this->sub_sections;
    }

    public function get_sections() {
        return $this->sections;
    }

    public function get_parent_menu_slug() {
        return $this->parent_menu_slug;
    }

    /**
     * Every registered top-level options `page` slug (registration order).
     *
     * @return array<int, string>
     */
    public function get_registered_menu_slugs() {
        return $this->registered_menu_slugs;
    }

    /**
     * Active `admin.php?page=` slug for the current request when on an STO options screen; otherwise the first registered slug.
     */
    public function get_request_options_menu_slug() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $page !== '' && in_array( $page, $this->registered_menu_slugs, true ) ) {
            return $page;
        }

        return $this->get_parent_menu_slug();
    }

    /**
     * @param array<string, mixed> $section
     */
    public function get_section_row_menu_page( array $section ): string {
        if ( isset( $section['sto_menu_page'] ) && is_string( $section['sto_menu_page'] ) ) {
            $p = sanitize_key( (string) $section['sto_menu_page'] );
            if ( $p !== '' && in_array( $p, $this->registered_menu_slugs, true ) ) {
                return $p;
            }
        }

        return $this->get_parent_menu_slug();
    }

    /**
     * @param string $menu_page_slug
     * @return array<int, array<string, mixed>>
     */
    public function get_sections_for_navigation_for_menu_page( $menu_page_slug ) {
        $menu_page_slug = sanitize_key( (string) $menu_page_slug );
        $out            = array();
        foreach ( $this->get_sections_for_navigation() as $section ) {
            if ( $this->get_section_row_menu_page( $section ) === $menu_page_slug ) {
                $out[] = $section;
            }
        }

        return $out;
    }

    /**
     * @param string $menu_page_slug
     * @return array<int, array<string, mixed>>
     */
    public function get_leaf_sections_for_navigation_for_menu_page( $menu_page_slug ) {
        $menu_page_slug = sanitize_key( (string) $menu_page_slug );
        $out            = array();
        foreach ( $this->get_leaf_sections_for_navigation() as $leaf ) {
            if ( empty( $leaf['slug'] ) ) {
                continue;
            }
            $meta = $this->get_section_by_slug( (string) $leaf['slug'] );
            if ( ! $meta ) {
                continue;
            }
            if ( $this->get_section_row_menu_page( $meta ) === $menu_page_slug ) {
                $out[] = $leaf;
            }
        }

        return $out;
    }

    /**
     * Default leaf for one top-level admin page (empty string if none).
     *
     * @param string $menu_page_slug
     * @return string
     */
    public function get_default_leaf_section_slug_for_menu_page( $menu_page_slug ) {
        $menu_page_slug = sanitize_key( (string) $menu_page_slug );
        $nav            = $this->get_sections_for_navigation_for_menu_page( $menu_page_slug );
        if ( ! empty( $nav ) ) {
            return $this->resolve_to_first_leaf_slug( (string) $nav[0]['slug'] );
        }

        return '';
    }

    private function get_current_section_slug() {
        $req_page = $this->get_request_options_menu_slug();

        $selected_slug = '';

        if ( isset( $_GET['section'] ) ) {
            $selected_slug = sanitize_key( wp_unslash( $_GET['section'] ) );
        }

        if ( ! $selected_slug && isset( $_GET['page'] ) ) {
            $page = wp_unslash( $_GET['page'] );
            if ( strpos( $page, '&section=' ) !== false ) {
                $parts = explode( '&section=', $page );
                if ( isset( $parts[1] ) ) {
                    $selected_slug = sanitize_key( $parts[1] );
                }
            }
        }

        if ( ! $selected_slug ) {
            $nav = $this->get_sections_for_navigation_for_menu_page( $req_page );
            $selected_slug = ! empty( $nav ) ? (string) $nav[0]['slug'] : '';
        }

        $resolved = $this->resolve_to_first_leaf_slug( $selected_slug );
        $leaf_map = $this->get_leaf_section_slug_map_for_menu_page( $req_page );

        if ( $resolved !== '' && isset( $leaf_map[ $resolved ] ) ) {
            return $resolved;
        }

        return $this->get_default_leaf_section_slug_for_menu_page( $req_page );
    }

    private function get_current_section() {
        $current_slug = $this->get_current_section_slug();

        return $this->get_section_by_slug( $current_slug );
    }

    private function get_section_by_slug( $slug ) {
        foreach ( $this->sections as $section ) {
            if ( $section['slug'] === $slug ) {
                return $section;
            }
        }

        foreach ( $this->sub_sections as $sub_section ) {
            if ( $sub_section['slug'] === $slug ) {
                return $sub_section;
            }
        }

        return null;
    }

    private function get_sub_sections_by_parent_slug( $parent_slug, $visible_in_menu_only = false ) {
        return array_values(
            array_filter(
                $this->sub_sections,
                function ( $sub_section ) use ( $parent_slug, $visible_in_menu_only ) {
                    if ( ! isset( $sub_section['parent_slug'] ) || $sub_section['parent_slug'] !== $parent_slug ) {
                        return false;
                    }
                    if ( $visible_in_menu_only && ! $this->is_section_show_in_menu( $sub_section ) ) {
                        return false;
                    }

                    return true;
                }
            )
        );
    }

    private function resolve_to_first_leaf_slug( $slug ) {
        $current_slug = $slug;

        while ( $current_slug ) {
            $children = $this->get_sub_sections_by_parent_slug( $current_slug );
            if ( empty( $children ) ) {
                break;
            }

            $current_slug = $children[0]['slug'];
        }

        return $current_slug;
    }

    private function section_has_children( $slug ) {
        return ! empty( $this->get_sub_sections_by_parent_slug( $slug, true ) );
    }

    /**
     * Navigable panels (top-level sections without children, or leaf subsections).
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_leaf_sections() {
        $items = array_merge( $this->sections, $this->sub_sections );

        return array_values(
            array_filter(
                $items,
                function ( $item ) {
                    return isset( $item['slug'] ) && ! $this->section_has_children( $item['slug'] );
                }
            )
        );
    }

    /**
     * Leaf panels for the options form: same as {@see get_leaf_sections()} but `nav_locked` sections last.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_leaf_sections_for_navigation() {
        $leaves   = $this->get_leaf_sections();
        $unlocked = array();
        $locked   = array();
        foreach ( $leaves as $leaf ) {
            $slug = isset( $leaf['slug'] ) ? sanitize_key( (string) $leaf['slug'] ) : '';
            if ( $slug === '' ) {
                continue;
            }
            $meta = $this->get_section_by_slug( $slug );
            if ( $meta && ! empty( $meta['nav_locked'] ) ) {
                $locked[] = $leaf;
            } else {
                $unlocked[] = $leaf;
            }
        }

        return array_merge( $unlocked, $locked );
    }

    /**
     * Admin URL for Theme Settings. Always includes `section` for a navigable leaf (resolves parents to first leaf).
     *
     * @param string $section_slug     Section or parent slug, or empty for default (first top-level branch → first leaf).
     * @param string $menu_page_slug   Optional. `admin.php?page=` slug; defaults to {@see get_parent_menu_slug()} (first registered menu).
     */
    public function get_theme_settings_url( $section_slug = '', $menu_page_slug = '' ) {
        $page = $menu_page_slug !== '' ? sanitize_key( (string) $menu_page_slug ) : $this->get_parent_menu_slug();
        if ( $page === '' || ! in_array( $page, $this->registered_menu_slugs, true ) ) {
            $page = $this->get_parent_menu_slug();
        }
        if ( $page === '' ) {
            return admin_url();
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( $page ) );
        $section_slug = sanitize_key( (string) $section_slug );
        $base_slug    = $section_slug;
        if ( $base_slug === '' ) {
            $base_slug = $this->get_default_leaf_section_slug_for_menu_page( $page );
        }
        $leaf = $base_slug !== '' ? $this->resolve_to_first_leaf_slug( $base_slug ) : '';
        if ( $leaf !== '' ) {
            $url = add_query_arg( 'section', $leaf, $url );
        }

        return $url;
    }

    /**
     * If `section` is missing or is a parent slug, redirect to the canonical leaf URL (matches panel content + WP submenu highlight).
     */
    public function redirect_theme_settings_to_canonical_leaf() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $req = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $req === '' || ! in_array( $req, $this->registered_menu_slugs, true ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['sto_save_options'] ) ) {
            return;
        }

        $requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        $leaf      = $this->get_current_section_slug();

        if ( $leaf === '' || $requested === $leaf ) {
            return;
        }

        wp_safe_redirect( $this->get_theme_settings_url( $leaf, $req ) );
        exit;
    }

    /**
     * First leaf when `section` is omitted (first registered top-level branch, fully resolved).
     *
     * @return string
     */
    public function get_default_leaf_section_slug() {
        return $this->get_default_leaf_section_slug_for_menu_page( $this->get_request_options_menu_slug() );
    }

    /**
     * Registered top-level submenu slug for WP admin menu current-item styling (walks up to a registered submenu row).
     */
    public function get_wp_submenu_highlight_slug_for_leaf( $leaf_slug ) {
        $leaf_slug = sanitize_key( (string) $leaf_slug );
        if ( $leaf_slug === '' ) {
            return '';
        }

        $current = $leaf_slug;
        for ( $i = 0; $i < 25 && $current !== ''; $i++ ) {
            foreach ( $this->sections as $sec ) {
                if ( isset( $sec['slug'] ) && $sec['slug'] === $current ) {
                    return $current;
                }
            }

            $parent = '';
            foreach ( $this->sub_sections as $sub ) {
                if ( isset( $sub['slug'], $sub['parent_slug'] ) && $sub['slug'] === $current ) {
                    $parent = sanitize_key( (string) $sub['parent_slug'] );
                    break;
                }
            }

            if ( $parent === '' ) {
                return $leaf_slug;
            }

            $current = $parent;
        }

        return $leaf_slug;
    }

    /**
     * Subsections registered under a top-level section slug.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_sub_sections_for_parent( $parent_slug ) {
        return $this->get_sub_sections_by_parent_slug( sanitize_key( (string) $parent_slug ), true );
    }

    private function render_section_panel( $section, $current_section_slug ) {
        $is_active = $current_section_slug === $section['slug'];
        ?>
        <div
            class="sto-option-panel-section <?php echo esc_attr( $is_active ? 'sto-is-active' : 'sto-is-hidden' ); ?>"
            data-section="<?php echo esc_attr( $section['slug'] ); ?>"
        >
            <fieldset class="sto-panel-section-fields"<?php echo $is_active ? '' : ' disabled'; ?>>
            <?php
            /**
             * Render content for a section slug.
             *
             * Developers can hook here and output section-specific fields/UI.
             */
            do_action( 'sto_render_section_content', $section['slug'], $section, $this );
            if ( ! has_action( 'sto_render_section_content' ) ) :
                ?>
                <p class="sto-option-panel-section-placeholder">
                    <?php
                    printf(
                        /* translators: %s is section name. */
                        esc_html__( 'Add fields for "%s" by hooking into sto_render_section_content.', 'simple-theme-options' ),
                        esc_html( $section['name'] )
                    );
                    ?>
                </p>
                <?php
            endif;
            ?>
            </fieldset>
        </div>
        <?php
    }


    private function item_contains_active_descendant( $item_slug, $current_slug ) {
        $children = $this->get_sub_sections_by_parent_slug( $item_slug );
        if ( empty( $children ) ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( $child['slug'] === $current_slug || $this->item_contains_active_descendant( $child['slug'], $current_slug ) ) {
                return true;
            }
        }

        return false;
    }

    private function render_sidebar_item( $item, $current_section_slug, $is_child = false, $menu_page_slug = '', $sidebar_link_base = '' ) {
        $menu_page_slug = $menu_page_slug !== '' ? sanitize_key( (string) $menu_page_slug ) : $this->get_request_options_menu_slug();
        $slug              = $item['slug'];
        $has_children      = $this->section_has_children( $slug );
        // Parents with children use href → first leaf but must never show leaf-active (JS + CSS rely on slug match).
        $is_active         = ( $current_section_slug === $slug ) && ! $has_children;
        $is_open           = $has_children && ( $current_section_slug === $slug || $this->item_contains_active_descendant( $slug, $current_section_slug ) );
        $li_classes        = array( 'sto-option-panel-sidebar-item' );
        $link_classes      = array( 'sto-option-panel-sidebar-item-link' );
        $children_classes  = array( 'sto-option-panel-sidebar-children' );

        if ( $is_active ) {
            $li_classes[]   = 'sto-is-active';
            $link_classes[] = 'sto-is-active';
        } elseif ( ! $is_child && $has_children && $this->item_contains_active_descendant( $slug, $current_section_slug ) ) {
            $li_classes[]   = 'sto-is-parent-active';
            $link_classes[] = 'sto-is-parent-active';
        }

        if ( $has_children ) {
            $li_classes[]       = 'sto-has-children';
            $children_classes[] = $is_open ? 'sto-is-open' : 'sto-is-collapsed';
        }

        if ( $is_child ) {
            $li_classes[] = 'sto-is-child';
        }

        if ( ! empty( $item['nav_locked'] ) ) {
            $li_classes[] = 'sto-option-panel-sidebar-item--nav-locked';
        }

        $toggle_icon = 'fa-light fa-angle-down';
        if ( $has_children ) {
            $toggle_icon = $is_open ? 'fa-light fa-angle-up' : 'fa-light fa-angle-down';
        }

        $href_section = $has_children ? $this->resolve_to_first_leaf_slug( $slug ) : $slug;
        $sidebar_link_base = is_string( $sidebar_link_base ) ? $sidebar_link_base : '';
        if ( $sidebar_link_base !== '' ) {
            $item_href = add_query_arg( 'section', (string) $href_section, $sidebar_link_base );
        } else {
            $item_href = admin_url( 'admin.php?page=' . rawurlencode( $menu_page_slug ) . '&section=' . rawurlencode( (string) $href_section ) );
        }
        ?>
        <li class="<?php echo esc_attr( implode( ' ', $li_classes ) ); ?>" data-sto-section="<?php echo esc_attr( $slug ); ?>">
            <a href="<?php echo esc_url( $item_href ); ?>" class="<?php echo esc_attr( implode( ' ', $link_classes ) ); ?>">
                <i class="sto-option-panel-icon <?php echo esc_attr( $item['icon'] ); ?>"></i>
                <span class="sto-option-panel-sidebar-item-title"><?php echo esc_html( $item['name'] ); ?></span>
                <?php if ( ! empty( $item['nav_locked'] ) ) { ?>
                    <span class="screen-reader-text"><?php esc_html_e( '(Plugin section)', 'simple-theme-options' ); ?></span>
                <?php } ?>
                <?php if ( $has_children ) { ?>
                    <span class="sto-option-panel-sidebar-toggle <?php echo esc_attr( $toggle_icon ); ?>" aria-hidden="true"></span>
                <?php } ?>
            </a>
            <?php if ( $has_children ) { ?>
                <ul class="<?php echo esc_attr( implode( ' ', $children_classes ) ); ?>">
                    <?php foreach ( $this->get_sub_sections_by_parent_slug( $slug, true ) as $child ) { ?>
                        <?php $this->render_sidebar_item( $child, $current_section_slug, true, $menu_page_slug, $sidebar_link_base ); ?>
                    <?php } ?>
                </ul>
            <?php } ?>
        </li>
        <?php
    }

    public function get_section_markup( $section_slug = '' ) {
        $req                  = $this->get_request_options_menu_slug();
        $panel_heading        = isset( $this->registered_menu_labels[ $req ] ) && $this->registered_menu_labels[ $req ] !== ''
            ? (string) $this->registered_menu_labels[ $req ]
            : __( 'Theme Settings', 'simple-theme-options' );
        $current_section_slug = $this->get_current_section_slug();
        $current_section      = $this->get_current_section();
        $content_title        = '';
        if ( $current_section && isset( $current_section['name'] ) && (string) $current_section['name'] !== '' ) {
            $content_title = (string) $current_section['name'];
        }
        if ( $content_title === '' ) {
            $content_title = $this->get_leaf_breadcrumb_label( $current_section_slug );
        }
        if ( $content_title === '' ) {
            $content_title = $panel_heading;
        }
        $content_icon    = $current_section && isset( $current_section['icon'] ) ? $current_section['icon'] : 'fa-light fa-circle-question';
        $leaf_sections   = $this->get_leaf_sections_for_navigation_for_menu_page( $req );
        $default_leaf    = $this->get_default_leaf_section_slug_for_menu_page( $req );

        ob_start();
        ?>
        <div class="wrap sto-section-content">
            <div class="sto-option-panel-wrapper" data-sto-default-leaf="<?php echo esc_attr( $default_leaf ); ?>">
                <div class="sto-option-panel-head sto-panel-head-with-search">
                    <h1 class="sto-option-panel-title"><?php echo esc_html( $panel_heading ); ?></h1>
                    <div class="sto-quick-search" data-sto-quick-search>
                        <div class="sto-quick-search-field">
                            <span class="sto-quick-search-icon-wrap" aria-hidden="true">
                                <i class="sto-quick-search-icon fa-light fa-magnifying-glass"></i>
                            </span>
                            <input
                                type="search"
                                class="sto-quick-search-input"
                                placeholder="<?php esc_attr_e( 'Start typing to find options…', 'simple-theme-options' ); ?>"
                                autocomplete="off"
                                aria-autocomplete="list"
                                aria-controls="sto-quick-search-results"
                                aria-expanded="false"
                                id="sto-quick-search-input"
                            />
                        </div>
                        <div
                            class="sto-quick-search-results"
                            id="sto-quick-search-results"
                            role="listbox"
                            aria-labelledby="sto-quick-search-input"
                            hidden
                        ></div>
                    </div>
                </div>
                <div class="sto-option-panel-body">
                    <div class="sto-option-panel-nav-layout">
                        <div class="sto-option-panel-sidebar-wrap">
                            <ul class="sto-option-panel-sidebar" role="navigation" aria-label="<?php esc_attr_e( 'Theme Settings sections', 'simple-theme-options' ); ?>">
                                <?php foreach ( $this->get_sections_for_navigation_for_menu_page( $req ) as $section ) { ?>
                                    <?php $this->render_sidebar_item( $section, $current_section_slug, false, $req ); ?>
                                <?php } ?>
                            </ul>
                        </div>
                        <div class="sto-option-panel-main">
                            <div class="sto-option-panel-content-head">
                                <span class="sto-option-panel-content-icon-wrap">
                                    <i class="<?php echo esc_attr( $content_icon ); ?> sto-option-panel-content-icon"></i>
                                </span>
                                <h2 class="sto-option-panel-content-title"><?php echo esc_html( $content_title ); ?></h2>
                            </div>
                            <?php if ( isset( $_GET['sto_saved'] ) && sanitize_text_field( wp_unslash( $_GET['sto_saved'] ) ) === '1' ) { ?>
                                <div class="sto-save-notice"><?php esc_html_e( 'Settings are successfully saved.', 'simple-theme-options' ); ?></div>
                            <?php } ?>
                            <?php if ( isset( $_GET['sto_imported'] ) && sanitize_text_field( wp_unslash( $_GET['sto_imported'] ) ) === '1' ) { ?>
                                <div class="sto-save-notice"><?php esc_html_e( 'Settings were imported from your backup.', 'simple-theme-options' ); ?></div>
                            <?php } ?>
                            <?php
                            $sto_val_err = isset( $_GET['sto_validation_error'] ) ? sanitize_text_field( wp_unslash( $_GET['sto_validation_error'] ) ) : '';
                            if ( $sto_val_err === '1' ) {
                                $verr = get_transient( $this->get_validation_notice_transient_name() );
                                delete_transient( $this->get_validation_notice_transient_name() );
                                $vslug = is_array( $verr ) && isset( $verr['section'] ) ? sanitize_key( (string) $verr['section'] ) : '';
                                $vmsgs = is_array( $verr ) && isset( $verr['messages'] ) && is_array( $verr['messages'] ) ? $verr['messages'] : array();
                                if ( $vslug === $current_section_slug && ! empty( $vmsgs ) ) {
                                    ?>
                                    <div class="sto-validation-notice" role="alert">
                                        <p class="sto-validation-notice-title"><?php esc_html_e( 'This section could not be saved yet', 'simple-theme-options' ); ?></p>
                                        <p class="sto-validation-notice-lead"><?php esc_html_e( 'Fix the following, then publish or update the post again:', 'simple-theme-options' ); ?></p>
                                        <ul class="sto-validation-notice-list">
                                            <?php foreach ( $vmsgs as $one ) { ?>
                                                <li><?php echo esc_html( (string) $one ); ?></li>
                                            <?php } ?>
                                        </ul>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                            <?php
                            $sto_form_action = $this->get_theme_settings_url( $current_section_slug, $req );
                            $sto_form_action = remove_query_arg( array( 'sto_saved', 'sto_imported', 'sto_validation_error' ), $sto_form_action );
                            ?>
                            <form id="sto-theme-settings-options-form" method="post" class="sto-options-form" action="<?php echo esc_url( $sto_form_action ); ?>">
                                <?php wp_nonce_field( 'sto_save_options_action', 'sto_save_options_nonce' ); ?>
                                <input type="hidden" name="sto_ts_page" value="<?php echo esc_attr( $req ); ?>" />
                                <input type="hidden" name="sto_ts_section" value="<?php echo esc_attr( $current_section_slug ); ?>" />
                                <div class="sto-option-panel-content-body">
                                    <?php foreach ( $leaf_sections as $section ) { ?>
                                        <?php $this->render_section_panel( $section, $current_section_slug ); ?>
                                    <?php } ?>
                                </div>
                                <?php if ( ! ThemeSettingsImportExport::is_advance_leaf_slug( $current_section_slug ) ) : ?>
                                <div class="sto-options-form-footer sto-section-actions">
                                    <button type="submit" form="sto-theme-settings-options-form" name="sto_save_options" value="1" class="button button-primary">
                                        <i class="fa-light fa-floppy-disk" aria-hidden="true"></i>
                                        <?php esc_html_e( 'Save options', 'simple-theme-options' ); ?>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Base post editor URL for metabox deep links (strip known query noise).
     *
     * @param int                 $post_id     Post ID (0 on a blank **post-new** screen before first save).
     * @param \WP_Post|mixed|null $editor_post Optional post object for resolving **post_type** when ID is 0.
     */
    private function get_metabox_post_editor_base_url( int $post_id, $editor_post = null ): string {
        $noise = array( 'sto_saved', 'sto_imported', 'sto_validation_error', 'sto-metabox-saved', 'message' );

        if ( $post_id > 0 ) {
            $url = get_edit_post_link( $post_id, 'raw' );
            if ( ! is_string( $url ) || $url === '' ) {
                $url = add_query_arg(
                    array(
                        'post'   => $post_id,
                        'action' => 'edit',
                    ),
                    admin_url( 'post.php' )
                );
            }

            return remove_query_arg( $noise, $url );
        }

        $pt = '';
        if ( $editor_post instanceof \WP_Post && $editor_post->post_type ) {
            $pt = sanitize_key( (string) $editor_post->post_type );
        }
        if ( $pt === '' && function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && ! empty( $screen->post_type ) ) {
                $pt = sanitize_key( (string) $screen->post_type );
            }
        }
        if ( $pt === '' ) {
            return '';
        }

        return remove_query_arg( $noise, add_query_arg( array( 'post_type' => $pt ), admin_url( 'post-new.php' ) ) );
    }

    /**
     * Active leaf slug when Theme Settings is embedded on `post.php` / `post-new.php` (uses `?section=` on the editor URL).
     */
    public function get_metabox_active_leaf_slug( string $menu_page_slug ): string {
        $menu_page_slug = sanitize_key( $menu_page_slug );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selected = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

        return $this->resolve_canonical_leaf_for_menu_and_section( $menu_page_slug, $selected );
    }

    /**
     * Full Theme Settings panel for a post metabox (same fields + SPA as the admin page; persists with the post via AJAX).
     *
     * Only one metabox should use `#sto-theme-settings-options-form` on the same screen (duplicate ids are invalid HTML).
     *
     * @param int                 $post_id     Post being edited (may be **0** on **post-new** before first save).
     * @param \WP_Post|mixed|null $editor_post Optional; used to resolve **post_type** when building URLs for ID **0**.
     */
    public function get_metabox_panel_markup( string $menu_page_slug, int $post_id, $editor_post = null ): string {
        $req = sanitize_key( $menu_page_slug );
        if ( $req === '' || ! in_array( $req, $this->registered_menu_slugs, true ) ) {
            return '';
        }

        if ( ! $this->should_show_theme_settings_metaboxes() ) {
            return '';
        }

        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return '';
        }

        $panel_heading = isset( $this->registered_menu_labels[ $req ] ) && $this->registered_menu_labels[ $req ] !== ''
            ? (string) $this->registered_menu_labels[ $req ]
            : __( 'Theme Settings', 'simple-theme-options' );

        $current_section_slug = $this->get_metabox_active_leaf_slug( $req );
        $current_section      = $this->get_section_by_slug( $current_section_slug );

        $content_title = '';
        if ( $current_section && isset( $current_section['name'] ) && (string) $current_section['name'] !== '' ) {
            $content_title = (string) $current_section['name'];
        }
        if ( $content_title === '' ) {
            $content_title = $this->get_leaf_breadcrumb_label( $current_section_slug );
        }
        if ( $content_title === '' ) {
            $content_title = $panel_heading;
        }

        $content_icon  = $current_section && isset( $current_section['icon'] ) ? $current_section['icon'] : 'fa-light fa-circle-question';
        $leaf_sections = $this->get_leaf_sections_for_navigation_for_menu_page( $req );
        $default_leaf  = $this->get_default_leaf_section_slug_for_menu_page( $req );

        $sidebar_base = $this->get_metabox_post_editor_base_url( $post_id, $editor_post );
        if ( $sidebar_base === '' ) {
            return '';
        }

        $sto_form_action = add_query_arg( 'section', $current_section_slug, $sidebar_base );
        $sto_form_action = remove_query_arg( array( 'sto_saved', 'sto_imported', 'sto_validation_error', 'sto-metabox-saved' ), $sto_form_action );

        $this->push_sto_options_metabox_overlay( $post_id );
        ob_start();
        try {
        ?>
        <div class="sto-section-content sto-theme-settings-metabox-inner">
            <div
                class="sto-option-panel-wrapper sto-option-panel-wrapper--metabox"
                data-sto-default-leaf="<?php echo esc_attr( $default_leaf ); ?>"
                data-sto-menu-page="<?php echo esc_attr( $req ); ?>"
                data-sto-post-edit-base="<?php echo esc_attr( $sidebar_base ); ?>"
                data-sto-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
            >
                <p class="sto-metabox-hint description">
                    <?php esc_html_e( 'These fields apply to this post only (they override the same keys from global Theme Settings on the front). They are stored when you publish or update the post.', 'simple-theme-options' ); ?>
                </p>
                <div class="sto-metabox-inline-notice sto-metabox-inline-notice--success" role="status" hidden></div>
                <div class="sto-metabox-inline-notice sto-metabox-inline-notice--error" role="alert" hidden></div>
                <div class="sto-option-panel-head sto-panel-head-with-search">
                    <h2 class="sto-option-panel-title sto-option-panel-title--metabox"><?php echo esc_html( $panel_heading ); ?></h2>
                    <div class="sto-quick-search" data-sto-quick-search>
                        <div class="sto-quick-search-field">
                            <span class="sto-quick-search-icon-wrap" aria-hidden="true">
                                <i class="sto-quick-search-icon fa-light fa-magnifying-glass"></i>
                            </span>
                            <input
                                type="search"
                                class="sto-quick-search-input"
                                placeholder="<?php esc_attr_e( 'Start typing to find options…', 'simple-theme-options' ); ?>"
                                autocomplete="off"
                                aria-autocomplete="list"
                                aria-controls="sto-quick-search-results"
                                aria-expanded="false"
                                id="sto-quick-search-input"
                            />
                        </div>
                        <div
                            class="sto-quick-search-results"
                            id="sto-quick-search-results"
                            role="listbox"
                            aria-labelledby="sto-quick-search-input"
                            hidden
                        ></div>
                    </div>
                </div>
                <div class="sto-option-panel-body">
                    <div class="sto-option-panel-nav-layout">
                        <div class="sto-option-panel-sidebar-wrap">
                            <ul class="sto-option-panel-sidebar" role="navigation" aria-label="<?php esc_attr_e( 'Theme Settings sections', 'simple-theme-options' ); ?>">
                                <?php foreach ( $this->get_sections_for_navigation_for_menu_page( $req ) as $section ) { ?>
                                    <?php $this->render_sidebar_item( $section, $current_section_slug, false, $req, $sidebar_base ); ?>
                                <?php } ?>
                            </ul>
                        </div>
                        <div class="sto-option-panel-main">
                            <div class="sto-option-panel-content-head">
                                <span class="sto-option-panel-content-icon-wrap">
                                    <i class="<?php echo esc_attr( $content_icon ); ?> sto-option-panel-content-icon"></i>
                                </span>
                                <h3 class="sto-option-panel-content-title"><?php echo esc_html( $content_title ); ?></h3>
                            </div>
                            <?php if ( isset( $_GET['sto-metabox-saved'] ) && sanitize_text_field( wp_unslash( $_GET['sto-metabox-saved'] ) ) === '1' ) { ?>
                                <div class="sto-save-notice"><?php esc_html_e( 'Settings are successfully saved.', 'simple-theme-options' ); ?></div>
                            <?php } ?>
                            <?php
                            $sto_val_err = isset( $_GET['sto-metabox-validation'] ) ? sanitize_text_field( wp_unslash( $_GET['sto-metabox-validation'] ) ) : '';
                            if ( $sto_val_err === '1' ) {
                                $verr = get_transient( $this->get_validation_notice_transient_name() );
                                delete_transient( $this->get_validation_notice_transient_name() );
                                $vslug = is_array( $verr ) && isset( $verr['section'] ) ? sanitize_key( (string) $verr['section'] ) : '';
                                $vmsgs = is_array( $verr ) && isset( $verr['messages'] ) && is_array( $verr['messages'] ) ? $verr['messages'] : array();
                                if ( $vslug === $current_section_slug && ! empty( $vmsgs ) ) {
                                    ?>
                                    <div class="sto-validation-notice" role="alert">
                                        <p class="sto-validation-notice-title"><?php esc_html_e( 'This section could not be saved yet', 'simple-theme-options' ); ?></p>
                                        <p class="sto-validation-notice-lead"><?php esc_html_e( 'Fix the following, then publish or update the post again:', 'simple-theme-options' ); ?></p>
                                        <ul class="sto-validation-notice-list">
                                            <?php foreach ( $vmsgs as $one ) { ?>
                                                <li><?php echo esc_html( (string) $one ); ?></li>
                                            <?php } ?>
                                        </ul>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                            <form id="sto-theme-settings-options-form" method="post" class="sto-options-form sto-options-form--metabox" action="<?php echo esc_url( $sto_form_action ); ?>" data-sto-metabox-form="1">
                                <?php wp_nonce_field( 'sto_save_options_action', 'sto_save_options_nonce' ); ?>
                                <input type="hidden" name="sto_ts_page" value="<?php echo esc_attr( $req ); ?>" />
                                <input type="hidden" name="sto_ts_section" value="<?php echo esc_attr( $current_section_slug ); ?>" />
                                <div class="sto-option-panel-content-body">
                                    <?php foreach ( $leaf_sections as $section ) { ?>
                                        <?php $this->render_section_panel( $section, $current_section_slug ); ?>
                                    <?php } ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
                $html = (string) ob_get_clean();
        } finally {
            $this->pop_sto_options_metabox_overlay();
        }

        return $html;
    }

    

    public function render_menu_page() {
        echo $this->get_section_markup();
    }
}