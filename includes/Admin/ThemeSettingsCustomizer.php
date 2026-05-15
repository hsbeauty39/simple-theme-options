<?php
/**
 * Exposes Theme Settings in the WordPress Customizer when enabled.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class ThemeSettingsCustomizer {
	use SingletonTrait;

	protected function init(): void {
		add_action( 'customize_register', array( $this, 'on_customize_register' ), 20 );
	}

	public function on_customize_register( \WP_Customize_Manager $wp_customize ): void {
		if ( ! current_user_can( 'manage_options' ) || ! ThemeSettingsDisplayLocations::instance()->is_customizer_enabled() ) {
			return;
		}

		$menu_slug = OptionsMenu::instance()->get_request_options_menu_slug();
		if ( $menu_slug === '' ) {
			$slugs = OptionsMenu::instance()->get_registered_menu_slugs();
			$menu_slug = ! empty( $slugs[0] ) ? (string) $slugs[0] : 'theme-settings';
		}

		$url = add_query_arg( 'page', sanitize_key( $menu_slug ), admin_url( 'admin.php' ) );

		$wp_customize->add_panel(
			'sto_theme_settings',
			array(
				'title'       => __( 'Theme Settings', 'simple-theme-options' ),
				'description' => __( 'Open the full Theme Settings screen to edit all options. Changes made there apply site-wide unless overridden on a post or term.', 'simple-theme-options' ),
				'priority'    => 160,
			)
		);

		$wp_customize->add_section(
			'sto_theme_settings_link',
			array(
				'title'    => __( 'All options', 'simple-theme-options' ),
				'panel'    => 'sto_theme_settings',
				'priority' => 10,
			)
		);

		$wp_customize->add_setting(
			'sto_theme_settings_admin_link',
			array(
				'type'              => 'option',
				'capability'        => 'manage_options',
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			)
		);

		$wp_customize->add_control(
			new \WP_Customize_Control(
				$wp_customize,
				'sto_theme_settings_admin_link_control',
				array(
					'section'     => 'sto_theme_settings_link',
					'settings'    => 'sto_theme_settings_admin_link',
					'type'        => 'hidden',
					'description' => sprintf(
						/* translators: %s: admin URL to Theme Settings */
						__( '<a href="%s" class="button button-primary" target="_blank" rel="noopener noreferrer">Open Theme Settings</a>', 'simple-theme-options' ),
						esc_url( $url )
					),
				)
			)
		);
	}
}
