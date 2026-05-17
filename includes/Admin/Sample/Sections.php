<?php
namespace SimpleThemeOptions\Admin\Sample;

use SimpleThemeOptions\Admin\Options\Fields\Common\PremiumFieldGate;
use SimpleThemeOptions\Admin\Options\ImportExport\ThemeSettingsImportExport;
use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Theme Settings nav: **parent sections** expand to **subsection leaves** so fields group visually in the sidebar + WP submenu flyouts.
 *
 * Field `section_slug` values match these leaf slugs. Parent slugs (`field-samples`, …) resolve to their first child in URLs.
 */
final class Sections {
	use SingletonTrait;

	/**
	 * Registers the Theme Settings **sidebar / submenu** tree only (used when {@see OptionsMenu::is_demo_mode_enabled()} is false).
	 * Field modules are still booted from {@see SampleFieldModules::boot()} so export/save metadata matches registered keys.
	 */
	public function register_without_demo() {
		$this->register_nav_structure();
		add_action( 'sto_render_section_content', array( $this, 'render_section_content' ), 30, 2 );
	}

	public function register() {
		$this->register_nav_structure();
		add_action( 'sto_render_section_content', array( $this, 'render_section_content' ), 30, 2 );
	}

	/**
	 * Parent sections + subsection leaves for the packaged Theme Settings menu.
	 */
	private function register_nav_structure(): void {
		$options_menu = OptionsMenu::instance();

		$options_menu->add_section( __( 'Field samples', 'simple-theme-options' ), 'field-samples', 'fa-light fa-layer-group' );
		$options_menu->add_sub_section( __( 'Navigation & lists', 'simple-theme-options' ), 'layout-nav', 'fa-light fa-list', 'field-samples' );
		$options_menu->add_sub_section( __( 'Typography & frames', 'simple-theme-options' ), 'layout-type', 'fa-light fa-font', 'field-samples' );
		$options_menu->add_sub_section( __( 'Inputs & buttons', 'simple-theme-options' ), 'layout-inputs', 'fa-light fa-keyboard', 'field-samples' );
		$options_menu->add_sub_section( __( 'Measure, code & borders', 'simple-theme-options' ), 'layout-code-borders', 'fa-light fa-brackets-curly', 'field-samples' );
		$options_menu->add_sub_section( __( 'Tabs & sidebar', 'simple-theme-options' ), 'layout-tabs-side', 'fa-light fa-table-columns', 'field-samples' );

		$options_menu->add_section( __( 'Colors & surfaces', 'simple-theme-options' ), 'colors-surfaces', 'fa-light fa-palette' );
		$options_menu->add_sub_section( __( 'Solid colors', 'simple-theme-options' ), 'appearance-color', 'fa-light fa-droplet', 'colors-surfaces' );
		$options_menu->add_sub_section( __( 'Gradient colors', 'simple-theme-options' ), 'appearance-gradient', 'fa-light fa-fill-drip', 'colors-surfaces' );
		$options_menu->add_sub_section( __( 'Surfaces & media', 'simple-theme-options' ), 'appearance-surfaces', 'fa-light fa-image', 'colors-surfaces' );
		$options_menu->add_sub_section( __( 'Link colors', 'simple-theme-options' ), 'appearance-links', 'fa-light fa-link', 'colors-surfaces' );

		$options_menu->add_section( __( 'Accordion', 'simple-theme-options' ), 'accordion', 'fa-light fa-square-caret-down' );
	}

	/**
	 * @param string               $section_slug Leaf slug.
	 * @param array<string, mixed> $section      Section meta from Menu.
	 */
	public function render_section_content( $section_slug, $section ) {
		$demo_leaves = array(
			'layout-nav',
			'layout-type',
			'layout-inputs',
			'layout-code-borders',
			'layout-tabs-side',
			'appearance-color',
			'appearance-gradient',
			'appearance-surfaces',
			'appearance-links',
			'accordion',
		);
		$options_menu = OptionsMenu::instance();
		$advance_leaf = ThemeSettingsImportExport::get_advance_section_slug_for_menu_page( $options_menu, $options_menu->get_parent_menu_slug() );
		$leaves         = $options_menu->is_demo_mode_enabled()
			? array_merge( $demo_leaves, array( $advance_leaf ) )
			: array( $advance_leaf );

		if ( in_array( $section_slug, $leaves, true ) ) {
			return;
		}

		if ( PremiumFieldGate::section_has_registered_groups( $section_slug ) ) {
			return;
		}

		/**
		 * Skip the generic placeholder when another module registered section content.
		 *
		 * @param bool   $skip           Default false.
		 * @param string $section_slug   Leaf section slug.
		 * @param array  $section        Section meta from Menu.
		 */
		if ( apply_filters( 'sto_section_skip_generic_placeholder', false, $section_slug, $section ) ) {
			return;
		}

		/* translators: %s: section name */
		printf(
			'<p>%s</p>',
			esc_html( sprintf( __( 'Settings for %s will appear here.', 'simple-theme-options' ), $section['name'] ?? $section_slug ) )
		);
	}
}
