<?php
/**
 * Exposes Theme Settings in the WordPress Customizer when enabled.
 *
 * Uses one Customizer section that embeds the full STO panel (sidebar + fields),
 * matching the wp-admin Theme Settings experience.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin;

use SimpleThemeOptions\Admin\Customizer\STO_Embed_Control;
use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Assets;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class ThemeSettingsCustomizer {
	use SingletonTrait;

	protected function init(): void {
		add_action( 'customize_register', array( $this, 'on_customize_register' ), 25 );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_controls_assets' ), 20 );
	}

	public function enqueue_controls_assets(): void {
		if ( ! current_user_can( 'manage_options' ) || ! ThemeSettingsDisplayLocations::instance()->is_customizer_enabled() ) {
			return;
		}

		Assets::instance()->enqueue_customizer_controls_assets();
	}

	public function on_customize_register( \WP_Customize_Manager $wp_customize ): void {
		if ( ! current_user_can( 'manage_options' ) || ! ThemeSettingsDisplayLocations::instance()->is_customizer_enabled() ) {
			return;
		}

		$options_menu = OptionsMenu::instance();
		$menu_slug    = $this->resolve_customizer_menu_slug( $options_menu );
		$menu_label   = $options_menu->get_registered_menu_page_title( $menu_slug );
		if ( $menu_label === '' ) {
			$menu_label = __( 'Theme Settings', 'topten-simple-theme-options' );
		}

		$wp_customize->add_panel(
			'sto_theme_settings',
			array(
				'title'       => __( 'Theme Settings', 'topten-simple-theme-options' ),
				'description' => __( 'Edit theme options with the same section sidebar and fields as wp-admin Theme Settings.', 'topten-simple-theme-options' ),
				'priority'    => 160,
			)
		);

		$wp_customize->add_section(
			'sto_theme_settings_embed',
			array(
				'title'    => $menu_label,
				'panel'    => 'sto_theme_settings',
				'priority' => 10,
			)
		);

		$wp_customize->add_setting(
			'sto_theme_settings_embed_app',
			array(
				'type'              => 'option',
				'capability'        => 'manage_options',
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					return is_string( $value ) ? $value : '';
				},
			)
		);

		$wp_customize->add_control(
			new STO_Embed_Control(
				$wp_customize,
				'sto_theme_settings_embed_control',
				array(
					'section'        => 'sto_theme_settings_embed',
					'settings'       => 'sto_theme_settings_embed_app',
					'menu_page_slug' => $menu_slug,
				)
			)
		);
	}

	/**
	 * Prefer a menu root that has visible sidebar sections.
	 */
	private function resolve_customizer_menu_slug( OptionsMenu $options_menu ): string {
		foreach ( $options_menu->get_registered_menu_slugs() as $slug ) {
			if ( $options_menu->get_sections_for_navigation_for_menu_page( (string) $slug ) !== array() ) {
				return (string) $slug;
			}
		}

		$slugs = $options_menu->get_registered_menu_slugs();

		return ! empty( $slugs[0] ) ? (string) $slugs[0] : OptionsMenu::PACKAGED_DEMO_MENU_SLUG;
	}
}
