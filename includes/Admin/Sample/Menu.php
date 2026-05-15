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
		// Set 'demo' => false to forbid sample sections. Use 'packaged_demo' => true only for this plugin sample menu
		// (no Advance; top-level menu hidden when demo is off — use Tools → Simple Backup to toggle demo.)
		// Theme code should call Options\Menu::register() without packaged_demo to get Advance + client export keys.
		$options_menu->register(
			__( 'Theme Settings', 'simple-theme-options' ),
			'theme-settings',
			'dashicons-admin-customizer',
			array(
				'demo'           => true,
				'packaged_demo'  => true,
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