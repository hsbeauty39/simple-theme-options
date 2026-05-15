<?php
namespace SimpleThemeOptions;

use SimpleThemeOptions\Admin\Compatibility\ShammiStoreFallback;
use SimpleThemeOptions\Admin\CustomFonts\CustomFontsAdmin;
use SimpleThemeOptions\Admin\ThemeSettingsCustomizer;
use SimpleThemeOptions\Admin\ThemeSettingsDisplayLocations;
use SimpleThemeOptions\Admin\ThemeSettingsTermBox;
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
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			add_action( 'init', array( $this, 'boot_admin_modules' ), 5 );
			add_action( 'init', array( ShammiStoreFallback::class, 'maybe_register' ), 25 );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'simple-theme-options',
			false,
			dirname( plugin_basename( STO_FILE ) ) . '/languages'
		);
	}

	/**
	 * Admin-only sample menu, field modules, import/export, and admin bar (after textdomain on `init`).
	 */
	public function boot_admin_modules(): void {
		SampleMenu::instance();
		SampleFieldModules::boot();
		ThemeSettingsImportExport::instance();
		CustomFontsAdmin::instance();
		ThemeSettingsTermBox::instance();
		ThemeSettingsDisplayLocations::instance();
		ThemeSettingsCustomizer::instance();
		ThemeSettingsAdminBar::instance();
	}
}
