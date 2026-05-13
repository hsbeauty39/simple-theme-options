<?php
namespace SimpleThemeOptions\Admin\Sample\Fields\Appearance;

use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Sample fields for **Colors & surfaces** subsections (`appearance-color`, `appearance-surfaces`, `appearance-links`).
 */
final class Appearance {
	use SingletonTrait;

	protected function init() {
		Color::instance();
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
				'description'  => __( 'Solid color field with swatches, alpha, and preset palette.', 'simple-theme-options' ),
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
