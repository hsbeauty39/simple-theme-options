<?php
namespace SimpleThemeOptions\Admin\Sample;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Menu {
	use SingletonTrait;

	protected function init() {
		$this->register_menu();
	}

	private function register_menu() {
		$options_menu = OptionsMenu::instance();
		// Packaged sample root slug: {@see OptionsMenu::PACKAGED_DEMO_MENU_SLUG}. Demo on/off: Tools → Simple Settings.
		// Theme/client menus use a different slug; Advance + export attach to those roots automatically.
		$options_menu->register(
			__( 'Theme Settings', 'topten-simple-theme-options' ),
			'theme-settings',
			'dashicons-admin-customizer',
			array(
				'metabox'        => array(
					'post_types' => array( 'post', 'page' ),
					'context'    => 'normal',
					'priority'   => 'high',
				),
				'term_metabox'   => array(
					'taxonomies' => array_filter(
						array(
							'category',
							'post_tag',
							taxonomy_exists( 'product_cat' ) ? 'product_cat' : '',
							taxonomy_exists( 'product_tag' ) ? 'product_tag' : '',
						)
					),
				),
			)
		);
	}
}