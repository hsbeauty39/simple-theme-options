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
		add_action( 'admin_bar_menu', array( $this, 'register_nodes' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ), 20 );
	}

	public function enqueue_styles() {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$slug = OptionsMenu::instance()->get_parent_menu_slug();
		if ( $slug === '' ) {
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
		$d = (string) apply_filters( 'sto_admin_bar_dashicon', $d, $slug, $fa );

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
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public function register_nodes( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$menu = OptionsMenu::instance();
		$page = $menu->get_parent_menu_slug();
		if ( $page === '' ) {
			return;
		}

		if ( $menu->is_packaged_demo_menu() && ! $menu->is_demo_mode_enabled() ) {
			return;
		}

		$def       = $menu->get_default_leaf_section_slug_for_menu_page( $page );
		$root_href = $def !== '' ? $menu->get_theme_settings_url( $def, $page ) : $menu->get_theme_settings_url( '', $page );

		$wp_admin_bar->add_node(
			array(
				'id'    => 'sto-theme-settings',
				'title' => $this->format_node_title( __( 'Theme Settings', 'simple-theme-options' ), 'fa-light fa-sliders', '_root' ),
				'href'  => $root_href,
				'meta'  => array(
					'class' => 'menupop sto-ab-root',
					'title' => esc_attr__( 'Jump to a Theme Settings section', 'simple-theme-options' ),
				),
			)
		);

		foreach ( $menu->get_sections_for_navigation() as $sec ) {
			if ( empty( $sec['slug'] ) || empty( $sec['name'] ) ) {
				continue;
			}
			if ( $menu->get_section_row_menu_page( $sec ) !== $page ) {
				continue;
			}

			$parent_slug = (string) $sec['slug'];
			$subs        = $menu->get_sub_sections_for_parent( $parent_slug );

			$sec_icon = isset( $sec['icon'] ) ? (string) $sec['icon'] : '';

			if ( empty( $subs ) ) {
				$wp_admin_bar->add_node(
					array(
						'parent' => 'sto-theme-settings',
						'id'     => 'sto-ts-' . sanitize_key( $parent_slug ),
						'title'  => $this->format_node_title( (string) $sec['name'], $sec_icon, $parent_slug ),
						'href'   => $menu->get_theme_settings_url( $parent_slug, $page ),
						'meta'   => array(
							'class' => 'sto-ab-leaf-link',
						),
					)
				);
				continue;
			}

			$group_id   = 'sto-ts-grp-' . sanitize_key( $parent_slug );
			$first_sub  = $subs[0];
			$group_href = ! empty( $first_sub['slug'] )
				? $menu->get_theme_settings_url( (string) $first_sub['slug'], $page )
				: $menu->get_theme_settings_url( $parent_slug, $page );

			$wp_admin_bar->add_node(
				array(
					'parent' => 'sto-theme-settings',
					'id'     => $group_id,
					'title'  => $this->format_node_title( (string) $sec['name'], $sec_icon, $parent_slug ),
					'href'   => $group_href,
					'meta'   => array(
						'class' => 'menupop sto-ab-megapop',
					),
				)
			);

			foreach ( $subs as $sub ) {
				if ( empty( $sub['slug'] ) || empty( $sub['name'] ) ) {
					continue;
				}
				if ( $menu->get_section_row_menu_page( $sub ) !== $page ) {
					continue;
				}

				$sub_slug = (string) $sub['slug'];
				$sub_icon = isset( $sub['icon'] ) ? (string) $sub['icon'] : '';

				$wp_admin_bar->add_node(
					array(
						'parent' => $group_id,
						'id'     => 'sto-ts-' . sanitize_key( $sub_slug ),
						'title'  => $this->format_node_title( (string) $sub['name'], $sub_icon, $sub_slug ),
						'href'   => $menu->get_theme_settings_url( $sub_slug, $page ),
						'meta'   => array(
							'class' => 'sto-ab-sublink',
						),
					)
				);
			}
		}

		/**
		 * After default Theme Settings admin bar nodes are registered.
		 *
		 * @param \WP_Admin_Bar $wp_admin_bar
		 * @param OptionsMenu   $menu
		 */
		do_action( 'sto_admin_bar_theme_settings_registered', $wp_admin_bar, $menu );
	}
}
