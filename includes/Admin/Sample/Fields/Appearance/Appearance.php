<?php
namespace SimpleThemeOptions\Admin\Sample\Fields\Appearance;

use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Sample fields for **Colors & surfaces** subsections (`appearance-color`, `appearance-gradient`, `appearance-surfaces`, `appearance-links`).
 */
final class Appearance {
	use SingletonTrait;

	protected function init() {
		Color::instance();
		GradientControl::instance();
		BackgroundControl::instance();
		LinkColor::instance();
		$this->register_fields();
	}

	public function register_fields() {
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
				'description'  => __( 'Linear or radial gradient with multiple color stops. **`popup` => true** shows a live preview strip and opens controls in a panel (like Border / Shadow).', 'simple-theme-options' ),
				'popup'        => true,
				'max_stops'    => 5,
				'alpha'        => true,
				'default'      => array(
					'type'   => 'linear',
					'angle'  => '180',
					'stops'  => array(
						array( 'color' => 'rgba(0, 0, 0, 0.55)', 'position' => '0' ),
						array( 'color' => 'rgba(0, 0, 0, 0)', 'position' => '100' ),
					),
				),
				'palettes'     => array( '#000000', '#2271b1', '#ffffff', '#7c3aed' ),
			)
		);

		GradientControl::register(
			array(
				'section_slug' => 'appearance-gradient',
				'id'           => 'appearance_section_divider_gradient',
				'title'        => __( 'Section divider gradient (inline)', 'simple-theme-options' ),
				'description'  => __( '**`popup` => false** keeps type, angle, and stops visible in the row (still one JSON value in **`sto_options`**).', 'simple-theme-options' ),
				'popup'        => false,
				'max_stops'    => 4,
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
