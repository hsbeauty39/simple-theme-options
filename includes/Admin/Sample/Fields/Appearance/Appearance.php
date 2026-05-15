<?php
namespace SimpleThemeOptions\Admin\Sample\Fields\Appearance;

use SimpleThemeOptions\Admin\Options\Fields\Accordion\Accordion;
use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl;
use SimpleThemeOptions\Admin\Options\Fields\Group\Group;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Sample fields for **Colors & surfaces** subsections (`appearance-color`, `appearance-gradient`, `appearance-surfaces`, `appearance-links`).
 */
final class Appearance {
	use SingletonTrait;

	protected function init() {
		Group::instance();
		Accordion::instance();
		Color::instance();
		GradientControl::instance();
		BackgroundControl::instance();
		LinkColor::instance();
		$this->register_fields();
	}

	public function register_fields() {
		Group::register(
			array(
				'section_slug'  => 'appearance-color',
				'id'            => 'ap_scheme_demo',
				'title'         => __( 'Color scheme layout (demo)', 'simple-theme-options' ),
				'description'   => __( 'Uses the same Theme Settings components as everywhere else: **Group** toolbar row (**Select** + **text**), then an **Accordion** with **one** panel of **Color** pickers on the shared 12-column grid (several colors per row via **`width`**). (*Accordion repeats the same inner `fields` for every panel — use one panel for a token grid, or use **nested groups** without `type` for separate Header / Body / Widget cards.*) Add **Export / Import** with custom buttons + AJAX if you need dynamic scheme lists.', 'simple-theme-options' ),
				'fields'        => array(
					array(
						'type'          => 'select',
						'id'            => 'preset',
						'title'         => __( 'Scheme', 'simple-theme-options' ),
						'description'   => __( 'Illustrative preset label (stored as a string).', 'simple-theme-options' ),
						'default'       => 'default',
						'width'         => '1-3',
						'options'       => array(
							'default' => __( 'Default', 'simple-theme-options' ),
							'dark'    => __( 'Dark', 'simple-theme-options' ),
							'high'    => __( 'High contrast', 'simple-theme-options' ),
						),
					),
					array(
						'type'          => 'text',
						'id'            => 'preset_name',
						'title'         => __( 'Name', 'simple-theme-options' ),
						'description'   => __( 'Editable label for the active scheme (plain text).', 'simple-theme-options' ),
						'default'       => __( 'Default', 'simple-theme-options' ),
						'width'         => '1-3',
					),
					array(
						'type'          => 'accordion',
						'id'            => 'zones',
						'title'         => __( 'Color groups', 'simple-theme-options' ),
						'description'   => __( 'Single accordion section (standard Color field + Iris). Add more **`panels`** rows only when each panel should repeat the **same** inner field list with different storage keys.', 'simple-theme-options' ),
						'width'         => '1-1',
						'panels'        => array(
							array(
								'id'       => 'tokens',
								'label'    => __( 'Header & global tokens', 'simple-theme-options' ),
								'expanded' => true,
							),
						),
						'fields'        => array(
							array(
								'type'        => 'color',
								'id'          => 'h_bg',
								'title'       => __( 'Site header', 'simple-theme-options' ),
								'default'     => '#1d2327',
								'alpha'       => true,
								'width'       => '1-3',
								'palettes'    => array( '#1d2327', '#2271b1', '#ffffff', '#000000' ),
							),
							array(
								'type'     => 'color',
								'id'       => 'h_border',
								'title'    => __( 'Site header border', 'simple-theme-options' ),
								'default'  => '#dcdcde',
								'alpha'    => true,
								'width'    => '1-3',
								'palettes' => array( '#dcdcde', '#c3c4c7', '#8c8f94', '#1d2327' ),
							),
							array(
								'type'     => 'color',
								'id'       => 'h_link',
								'title'    => __( 'Home link', 'simple-theme-options' ),
								'default'  => '#2271b1',
								'alpha'    => true,
								'width'    => '1-3',
								'palettes' => array( '#2271b1', '#135e96', '#72aee6', '#ffffff' ),
							),
							array(
								'type'     => 'color',
								'id'       => 'h_desc',
								'title'    => __( 'Site description', 'simple-theme-options' ),
								'default'  => '#787c82',
								'alpha'    => true,
								'width'    => '1-3',
								'palettes' => array( '#787c82', '#50575e', '#2c3338', '#ffffff' ),
							),
							array(
								'type'     => 'color',
								'id'       => 'h_nav',
								'title'    => __( 'Navbar', 'simple-theme-options' ),
								'default'  => '#1d2327',
								'alpha'    => true,
								'width'    => '1-3',
								'palettes' => array( '#1d2327', '#50575e', '#ffffff', '#2271b1' ),
							),
							array(
								'type'     => 'color',
								'id'       => 'b_bg',
								'title'    => __( 'Body background', 'simple-theme-options' ),
								'default'  => '#ffffff',
								'alpha'    => true,
								'width'    => '1-2',
								'palettes' => array( '#ffffff', '#f6f7f7', '#f0f0f1', '#e0e0e0' ),
							),
							array(
								'type'     => 'color',
								'id'       => 'b_text',
								'title'    => __( 'Body text', 'simple-theme-options' ),
								'default'  => '#2c3338',
								'alpha'    => true,
								'width'    => '1-2',
								'palettes' => array( '#2c3338', '#1d2327', '#50575e', '#ffffff' ),
							),
							array(
								'type'     => 'color',
								'id'       => 'w_title',
								'title'    => __( 'Widget title', 'simple-theme-options' ),
								'default'  => '#1d2327',
								'alpha'    => true,
								'width'    => '1-2',
								'palettes' => array( '#1d2327', '#2271b1', '#50575e', '#ffffff' ),
							),
							array(
								'type'     => 'color',
								'id'       => 'w_text',
								'title'    => __( 'Widget text', 'simple-theme-options' ),
								'default'  => '#50575e',
								'alpha'    => true,
								'width'    => '1-2',
								'palettes' => array( '#50575e', '#787c82', '#2c3338', '#ffffff' ),
							),
						),
					),
				),
			)
		);

		Color::register(
			array(
				'section_slug' => 'appearance-color',
				'id'           => 'appearance_buttons_bg',
				'title'        => __( 'Primary button color', 'simple-theme-options' ),
				'description'  => __( 'Solid color field with swatches, alpha, and preset palette (classic Iris row).', 'simple-theme-options' ),
				'default'      => '#f7f7f7',
				'palettes'     => array(
					'#000000',
					'#ffffff',
					'#d63638',
					'#ff7900',
					'#ffcc00',
					'#00a32a',
					'#2271b1',
					'#7c3aed',
				),
			)
		);

		Color::register(
			array(
				'section_slug' => 'appearance-color',
				'id'           => 'appearance_advanced_palette',
				'title'        => __( 'Advanced palette (grid)', 'simple-theme-options' ),
				'description'  => __( 'Larger rounded swatches in a wrapping grid with a softer popover — uses the built-in extended preset list when `palettes` is omitted (`palette_ui` => `advanced`).', 'simple-theme-options' ),
				'default'      => '#2271b1',
				'palette_ui'   => 'advanced',
				'alpha'        => true,
			)
		);

		Color::register(
			array(
				'section_slug' => 'appearance-color',
				'id'           => 'appearance_advanced_palette_circles',
				'title'        => __( 'Advanced palette (circular swatches)', 'simple-theme-options' ),
				'description'  => __( 'Pastel + grayscale row with circular chips (`palette_ui` => `advanced-circles`).', 'simple-theme-options' ),
				'default'      => '#f8bbd0',
				'palette_ui'   => 'advanced-circles',
				'alpha'        => false,
				'palettes'     => array(
					'#000000',
					'#424242',
					'#757575',
					'#bdbdbd',
					'#eeeeee',
					'#ffffff',
					'#ffcdd2',
					'#f8bbd0',
					'#e1bee7',
					'#c5cae9',
					'#bbdefb',
					'#b2ebf2',
					'#c8e6c9',
					'#fff9c4',
					'#ffe0b2',
				),
			)
		);

		Color::register(
			array(
				'section_slug' => 'appearance-color',
				'id'           => 'appearance_advanced_palette_dense',
				'title'        => __( 'Advanced palette (dense)', 'simple-theme-options' ),
				'description'  => __( 'Compact grid for many presets (`palette_ui` => `advanced-dense`).', 'simple-theme-options' ),
				'default'      => '#00acc1',
				'palette_ui'   => 'advanced-dense',
				'alpha'        => true,
			)
		);

		GradientControl::register(
			array(
				'section_slug' => 'appearance-gradient',
				'id'           => 'appearance_hero_overlay_gradient',
				'title'        => __( 'Hero overlay gradient (popover)', 'simple-theme-options' ),
				'description'  => __( 'Linear or radial gradient: floating color dock at the active pin, click the bar to add stops (up to **max_stops**). **`popup` => true** shows a preview strip and **Edit gradient**.', 'simple-theme-options' ),
				'popup'        => true,
				'max_stops'    => 24,
				'alpha'        => true,
				'default'      => array(
					'type'   => 'linear',
					'angle'  => '180',
					'stops'  => array(
						array( 'color' => 'rgba(0, 0, 0, 0.55)', 'position' => '0' ),
						array( 'color' => 'rgba(0, 0, 0, 0)', 'position' => '100' ),
					),
				),
				'palettes'     => array( '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899', '#fbbf24', '#ffffff', '#0f172a' ),
			)
		);

		GradientControl::register(
			array(
				'section_slug' => 'appearance-gradient',
				'id'           => 'appearance_section_divider_gradient',
				'title'        => __( 'Section divider gradient (inline)', 'simple-theme-options' ),
				'description'  => __( '**`popup` => false** keeps controls inline. Optional **`palettes`** adds suggestion swatches under the color dock.', 'simple-theme-options' ),
				'popup'        => false,
				'max_stops'    => 24,
				'palettes'     => array( '#2271b1', '#72aee6', '#00d084', '#f6b93b', '#eb5a46', '#ffffff' ),
				'default'      => array(
					'type'  => 'linear',
					'angle' => '90',
					'stops' => array(
						array( 'color' => '#2271b1', 'position' => '0' ),
						array( 'color' => '#72aee6', 'position' => '50' ),
						array( 'color' => '#2271b1', 'position' => '100' ),
					),
				),
			)
		);

		BackgroundControl::register(
			array(
				'section_slug' => 'appearance-surfaces',
				'id'           => 'appearance_popup_background',
				'title'        => __( 'Surface & image background', 'simple-theme-options' ),
				'description'  => __( 'Layered background: flat color, image, repeat, position, attachment, and size.', 'simple-theme-options' ),
				'default'      => array(
					'color'    => '#000000',
					'image_id' => '',
				),
				'alpha'        => true,
			)
		);

		LinkColor::register(
			array(
				'section_slug' => 'appearance-links',
				'id'           => 'appearance_links_color',
				'title'        => __( 'Link colors (regular & hover)', 'simple-theme-options' ),
				'description'  => __( 'Paired pickers for default and hover states in content areas.', 'simple-theme-options' ),
				'default'      => array(
					'regular' => '#333333',
					'hover'   => '#222222',
				),
				'labels'       => array(
					'regular' => __( 'Regular', 'simple-theme-options' ),
					'hover'   => __( 'Hover', 'simple-theme-options' ),
				),
				'alpha'        => true,
				'tooltip'      => array(
					'image' => 'https://picsum.photos/seed/sto-links-color/520/360',
				),
				'palettes'     => array(
					'#000000',
					'#333333',
					'#ffffff',
					'#d63638',
					'#2271b1',
					'#7c3aed',
				),
			)
		);
	}
}
