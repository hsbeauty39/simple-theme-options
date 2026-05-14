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
		// Set 'demo' => false to forbid sample sections and hide the Advance Demo mode switch.
		// When true (or omitted), samples stay off until the Advance toggle enables sto_theme_settings_ui_demo_enabled.
		$options_menu->register(
			__( 'Theme Settings', 'simple-theme-options' ),
			'theme-settings',
			'dashicons-admin-customizer',
			array(
				'demo' => true,
			)
		);
	}
}