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
		$options_menu->register( __( 'Theme Settings', 'simple-theme-options' ), 'theme-settings', 'dashicons-admin-customizer' );
	}
}