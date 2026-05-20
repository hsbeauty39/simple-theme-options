<?php
namespace SimpleThemeOptions\Admin;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress admin bar: Theme Settings quick navigation (section / subsection links)
 * from any admin or front-end screen when the toolbar is visible.
 *
 * Toolbar items use Dashicons (core) so icons render without bundling Font Awesome webfonts.
 * Section `icon` strings from Menu are mapped to Dashicons; override via `sto_admin_bar_dashicon`.
 */
final class ThemeSettingsAdminBar {
	use SingletonTrait;

	protected function init() {
		add_action( 'admin_bar_menu', array( $this, 'register_nodes' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ), 20 );
	}

	public function enqueue_styles() {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$menu    = OptionsMenu::instance();
		$has_any = false;
		foreach ( $menu->get_registered_menu_slugs() as $menu_page_slug ) {
			if ( $this->menu_page_has_visible_nav( $menu, $menu_page_slug ) ) {
				$has_any = true;
				break;
			}
		}
		if ( ! $has_any ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		$css_path = STO_PATH . 'assets/admin/css/sto-admin-bar-theme-settings.css';
		$version  = STO_VERSION;
		if ( is_readable( $css_path ) ) {
			$version .= '.' . (string) filemtime( $css_path );
		}

		wp_enqueue_style(
			'sto-admin-bar-theme-settings',
			STO_URL . 'assets/admin/css/sto-admin-bar-theme-settings.css',
			array( 'dashicons', 'admin-bar' ),
			$version
		);
	}

	/**
	 * Map registered FA-style icon classes (and section slug) to a Dashicons suffix, e.g. `dashicons-admin-home`.
	 *
	 * @param string $section_slug Sanitized slug or `_root` for the top-level Theme Settings node.
	 * @param string $fa_icon      Classes from Menu::add_section / add_sub_section.
	 */
	private function dashicon_class_for_item( $section_slug, $fa_icon = '' ) {
		$fa = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-zA-Z0-9 \-]/', '', (string) $fa_icon ) ) );
		$slug = sanitize_key( (string) $section_slug );

		$by_fa = array(
			'fa-light fa-sliders'           => 'dashicons-admin-generic',
			'fa-light fa-house'             => 'dashicons-admin-home',
			'fa-light fa-share-nodes'       => 'dashicons-share',
			'fa-light fa-chart-column'      => 'dashicons-chart-bar',
			'fa-light fa-palette'           => 'dashicons-art',
			'fa-light fa-code'              => 'dashicons-editor-code',
			'fa-light fa-layer-group'       => 'dashicons-layout',
			'fa-light fa-window-maximize'   => 'dashicons-welcome-view-site',
			'fa-light fa-square-caret-down' => 'dashicons-menu-alt',
			'fa-light fa-list'              => 'dashicons-list-view',
			'fa-light fa-font'              => 'dashicons-editor-textcolor',
			'fa-light fa-keyboard'          => 'dashicons-editor-paragraph',
			'fa-light fa-brackets-curly'    => 'dashicons-editor-code',
			'fa-light fa-table-columns'     => 'dashicons-screenoptions',
			'fa-light fa-droplet'           => 'dashicons-art',
			'fa-light fa-image'             => 'dashicons-format-image',
			'fa-light fa-link'              => 'dashicons-admin-links',
			'fa-light fa-object-group'      => 'dashicons-grid-view',
		);

		$by_slug = array(
			'_root'             => 'dashicons-admin-generic',
			'field-samples'     => 'dashicons-layout',
			'layout-nav'        => 'dashicons-list-view',
			'layout-type'       => 'dashicons-editor-textcolor',
			'layout-inputs'     => 'dashicons-editor-paragraph',
			'layout-code-borders' => 'dashicons-editor-code',
			'layout-tabs-side'  => 'dashicons-screenoptions',
			'colors-surfaces'   => 'dashicons-art',
			'appearance-color'  => 'dashicons-art',
			'appearance-surfaces' => 'dashicons-format-image',
			'appearance-links'  => 'dashicons-admin-links',
			'accordion'         => 'dashicons-menu-alt',
			'layout'            => 'dashicons-layout',
			'appearance'        => 'dashicons-art',
			'general'           => 'dashicons-admin-home',
			'social'            => 'dashicons-share',
			'analytics'         => 'dashicons-chart-bar',
			'custom-code'       => 'dashicons-editor-code',
			'header-banner'     => 'dashicons-welcome-view-site',
			'uaebattery-root'   => 'dashicons-admin-home',
			'uaebattery-header' => 'dashicons-welcome-view-site',
			'uaebattery-header-live-search' => 'dashicons-search',
			'uaebattery-header-navigation'  => 'dashicons-menu-alt',
			'uaebattery-footer' => 'dashicons-admin-page',
			'uaebattery-shop'   => 'dashicons-cart',
		);

		$d = '';
		if ( $fa !== '' && isset( $by_fa[ $fa ] ) ) {
			$d = $by_fa[ $fa ];
		} elseif ( $slug !== '' && isset( $by_slug[ $slug ] ) ) {
			$d = $by_slug[ $slug ];
		} else {
			$d = 'dashicons-admin-generic';
		}

		/**
		 * Dashicon class suffix for a Theme Settings admin bar item (e.g. `dashicons-admin-home`).
		 *
		 * @param string $dashicon     Suggested `dashicons-*` class (without leading `dashicons ` base).
		 * @param string $section_slug Section or subsection slug, or `_root`.
		 * @param string $fa_icon      Normalized FA-style class string from Menu.
		 */
		$d = (string) apply_filters( 'sto_admin_bar_dashicon', $d, $slug, $fa ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.

		if ( ! preg_match( '/^dashicons-[a-z0-9-]+$/', $d ) ) {
			$d = 'dashicons-admin-generic';
		}

		return $d;
	}

	/**
	 * Admin bar link inner HTML: Dashicon + label.
	 *
	 * @param string $label          Plain text label.
	 * @param string $fa_icon        FA-style classes from Menu (mapped to Dashicons).
	 * @param string $section_slug   Slug for mapping / filter context.
	 */
	private function format_node_title( $label, $fa_icon = '', $section_slug = '' ) {
		$icon_class = $this->dashicon_class_for_item( $section_slug, $fa_icon );

		// Match core admin-bar markup: .ab-icon + .ab-label (see wp-includes/admin-bar.php).
		return '<span class="ab-icon dashicons ' . esc_attr( $icon_class ) . '" aria-hidden="true"></span>'
			. '<span class="ab-label">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Whether a registered options menu has navigable panels for the admin bar.
	 *
	 * @param OptionsMenu $menu
	 * @param string      $menu_page_slug
	 */
	private function menu_page_has_visible_nav( OptionsMenu $menu, $menu_page_slug ) {
		$menu_page_slug = sanitize_key( (string) $menu_page_slug );
		if ( $menu_page_slug === '' ) {
			return false;
		}

		return $menu->get_leaf_sections_for_navigation_for_menu_page( $menu_page_slug ) !== array();
	}

	/**
	 * First registered menu slug that still has visible sections (theme menus when demo is off).
	 *
	 * @param OptionsMenu        $menu
	 * @param array<int, string> $menu_slugs
	 */
	private function resolve_admin_bar_root_menu_page( OptionsMenu $menu, array $menu_slugs ) {
		foreach ( $menu_slugs as $menu_page_slug ) {
			if ( $this->menu_page_has_visible_nav( $menu, $menu_page_slug ) ) {
				return (string) $menu_page_slug;
			}
		}

		return $menu->get_parent_menu_slug();
	}

	/**
	 * Register every navigable leaf for one STO menu as a single-level admin bar list (no nested flyouts).
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 * @param OptionsMenu   $menu
	 * @param string        $menu_page_slug
	 * @param string        $admin_bar_parent_id
	 * @return int Number of nodes added.
	 */
	private function register_leaf_links_for_menu_page( $wp_admin_bar, OptionsMenu $menu, $menu_page_slug, $admin_bar_parent_id ) {
		$menu_page_slug = sanitize_key( (string) $menu_page_slug );
		if ( $menu_page_slug === '' ) {
			return 0;
		}

		$added = 0;
		foreach ( $menu->get_leaf_sections_for_navigation_for_menu_page( $menu_page_slug ) as $leaf ) {
			if ( empty( $leaf['slug'] ) ) {
				continue;
			}

			$leaf_slug = (string) $leaf['slug'];
			$label     = $menu->get_leaf_breadcrumb_label( $leaf_slug );
			if ( $label === '' && ! empty( $leaf['name'] ) ) {
				$label = (string) $leaf['name'];
			}
			$leaf_icon = isset( $leaf['icon'] ) ? (string) $leaf['icon'] : '';

			$wp_admin_bar->add_node(
				array(
					'parent' => $admin_bar_parent_id,
					'id'     => 'sto-ts-' . sanitize_key( $menu_page_slug . '-' . $leaf_slug ),
					'title'  => $this->format_node_title( $label, $leaf_icon, $leaf_slug ),
					'href'   => $menu->get_theme_settings_url( $leaf_slug, $menu_page_slug ),
					'meta'   => array(
						'class' => 'sto-ab-leaf-link',
					),
				)
			);
			++$added;
		}

		return $added;
	}

	/**
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public function register_nodes( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$menu       = OptionsMenu::instance();
		$menu_slugs = $menu->get_registered_menu_slugs();
		if ( $menu_slugs === array() ) {
			return;
		}

		$visible_menu_slugs = array();
		foreach ( $menu_slugs as $menu_page_slug ) {
			if ( ! $menu->is_menu_root_visible_in_admin( $menu_page_slug ) ) {
				continue;
			}
			if ( $this->menu_page_has_visible_nav( $menu, $menu_page_slug ) ) {
				$visible_menu_slugs[] = $menu_page_slug;
			}
		}

		if ( $visible_menu_slugs === array() ) {
			return;
		}

		$root_page = $this->resolve_admin_bar_root_menu_page( $menu, $visible_menu_slugs );
		$def       = $menu->get_default_leaf_section_slug_for_menu_page( $root_page );
		$root_href = $def !== '' ? $menu->get_theme_settings_url( $def, $root_page ) : $menu->get_theme_settings_url( '', $root_page );

		$wp_admin_bar->add_node(
			array(
				'id'    => 'sto-theme-settings',
				'title' => $this->format_node_title( __( 'Theme Settings', 'topten-simple-theme-options' ), 'fa-light fa-sliders', '_root' ),
				'href'  => $root_href,
				'meta'  => array(
					'class' => 'menupop sto-ab-root',
					'title' => esc_attr__( 'Jump to a Theme Settings section', 'topten-simple-theme-options' ),
				),
			)
		);

		if ( count( $visible_menu_slugs ) === 1 ) {
			$this->register_leaf_links_for_menu_page( $wp_admin_bar, $menu, $visible_menu_slugs[0], 'sto-theme-settings' );
		} else {
			foreach ( $visible_menu_slugs as $menu_page_slug ) {
				$menu_group_id = 'sto-ts-menu-' . sanitize_key( $menu_page_slug );
				$menu_label    = $menu->get_registered_menu_page_title( $menu_page_slug );
				$menu_def      = $menu->get_default_leaf_section_slug_for_menu_page( $menu_page_slug );
				$menu_href     = $menu_def !== ''
					? $menu->get_theme_settings_url( $menu_def, $menu_page_slug )
					: $menu->get_theme_settings_url( '', $menu_page_slug );

				$wp_admin_bar->add_node(
					array(
						'parent' => 'sto-theme-settings',
						'id'     => $menu_group_id,
						'title'  => $this->format_node_title( $menu_label, '', sanitize_key( $menu_page_slug ) ),
						'href'   => $menu_href,
						'meta'   => array(
							'class' => 'menupop sto-ab-menu-group',
						),
					)
				);

				$this->register_leaf_links_for_menu_page( $wp_admin_bar, $menu, $menu_page_slug, $menu_group_id );
			}
		}

		/**
		 * After default Theme Settings admin bar nodes are registered.
		 *
		 * @param \WP_Admin_Bar $wp_admin_bar
		 * @param OptionsMenu   $menu
		 */
		do_action( 'sto_admin_bar_theme_settings_registered', $wp_admin_bar, $menu ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
	}
}
