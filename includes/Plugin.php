<?php
namespace SimpleThemeOptions;

use SimpleThemeOptions\Admin\Options\ImportExport\ThemeSettingsImportExport;
use SimpleThemeOptions\Admin\Sample\Menu as SampleMenu;
use SimpleThemeOptions\Admin\Sample\SampleFieldModules;
use SimpleThemeOptions\Admin\ThemeSettingsAdminBar;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	use SingletonTrait;

	protected function init() {
		$this->register_hooks();
	}

	private function register_hooks() {
		Assets::instance()->init();
		Ajax::instance()->init();
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			SampleFieldModules::boot();
			ThemeSettingsImportExport::instance();
			ThemeSettingsAdminBar::instance();
			SampleMenu::instance();
		}
	}
}
