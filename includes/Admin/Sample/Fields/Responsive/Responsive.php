<?php
/**
 * Packaged demo: all Theme Settings fields with per-breakpoint (responsive) storage.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Sample\Fields\Responsive;

use SimpleThemeOptions\Admin\Options\Fields\Accordion\Accordion;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\CheckboxControl\CheckboxControl;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\Group\Group;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\Tabs\Tabs;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Sample fields for leaf section **`responsive`** (device toolbar on field titles).
 */
final class Responsive {

	use SingletonTrait;

	protected function init() {
		Group::instance();
		Select::instance();
		DynamicObject::instance();
		CheckboxControl::instance();
		Typography::instance();
		BorderControl::instance();
		ShadowControl::instance();
		Range::instance();
		Tabs::instance();
		Accordion::instance();

		add_action( 'sto_render_section_content', array( $this, 'render_section_intro' ), 5, 2 );
		$this->register_fields();
	}

	/**
	 * @param string               $section_slug Leaf slug.
	 * @param array<string, mixed> $section      Section meta from Menu.
	 */
	public function render_section_intro( $section_slug, $section ): void {
		unset( $section );

		if ( sanitize_key( (string) $section_slug ) !== 'responsive' ) {
			return;
		}
		?>
		<div class="sto-responsive-demo-intro">
			<p class="sto-responsive-demo-intro__lead">
				<?php esc_html_e( 'Every field on this page stores separate values per device tab (desktop, tablet, mobile). Use the icon strip on the right of each title to switch breakpoints.', 'topten-simple-theme-options' ); ?>
			</p>
		</div>
		<?php
	}

	private function register_fields(): void {
		$section_slug = 'responsive';

		Select::register(
			array(
				'section_slug'  => $section_slug,
				'id'            => 'layout_header',
				'title'         => __( 'Site header', 'topten-simple-theme-options' ),
				'placeholder'   => __( 'Select', 'topten-simple-theme-options' ),
				'description'   => __( 'Default site header layout per breakpoint.', 'topten-simple-theme-options' ),
				'default'       => 'default_header_layout',
				'responsive'    => true,
				'options'       => array(
					'none'                  => __( 'None', 'topten-simple-theme-options' ),
					'default_header_layout' => __( 'Default layout', 'topten-simple-theme-options' ),
				),
			)
		);

		DynamicObject::register(
			array(
				'section_slug'  => $section_slug,
				'id'            => 'layout_featured_page',
				'title'         => __( 'Featured page (search)', 'topten-simple-theme-options' ),
				'description'   => __( 'AJAX page search, three characters minimum.', 'topten-simple-theme-options' ),
				'post_type'     => 'page',
				'limit'         => 10,
				'placeholder'   => __( 'Search pages… (min. 3 characters)', 'topten-simple-theme-options' ),
				'required'      => array(
					'layout_header' => 'default_header_layout',
				),
				'tooltip'       => array(
					'image' => 'https://picsum.photos/seed/sto-page-tooltip/520/720',
				),
				'responsive'    => true,
			)
		);

		CheckboxControl::register(
			array(
				'section_slug'  => $section_slug,
				'id'            => 'layout_demo_checkbox_single',
				'title'         => __( 'Custom checkbox (single)', 'topten-simple-theme-options' ),
				'description'   => __( 'Custom checkbox tile, stores one or zero per device.', 'topten-simple-theme-options' ),
				'default'       => '0',
				'labels'        => array(
					'on'  => __( 'Feature enabled', 'topten-simple-theme-options' ),
					'off' => __( 'Feature disabled', 'topten-simple-theme-options' ),
				),
				'responsive'    => true,
				'device'        => array( 'lg', 'md', 'mobile' ),
				'required'      => array(
					'layout_header' => 'default_header_layout',
				),
			)
		);

		Typography::register(
			array(
				'section_slug' => $section_slug,
				'id'           => 'layout_body_typography',
				'title'        => __( 'Body typography', 'topten-simple-theme-options' ),
				'description'  => __( 'Typography with live preview per breakpoint.', 'topten-simple-theme-options' ),
				'default'      => array(
					'family'    => 'Inter',
					'variant'   => 'regular',
					'subset'    => 'latin',
					'transform' => 'none',
				),
				'responsive'   => true,
				'device'       => array( 'lg', 'md', 'mobile' ),
				'required'     => array(
					'layout_header' => 'default_header_layout',
				),
			)
		);

		BorderControl::register(
			array(
				'section_slug' => $section_slug,
				'id'           => 'layout_banner_border',
				'title'        => __( 'Border composite', 'topten-simple-theme-options' ),
				'description'  => __( 'Border radius, style, width, color per device.', 'topten-simple-theme-options' ),
				'default'      => array(
					'radius'      => '0',
					'radius_unit' => 'px',
					'style'       => 'none',
					'width'       => '1',
					'width_unit'  => 'px',
					'color'       => '#1d2327',
				),
				'features'     => array( 'radius', 'style', 'width', 'color' ),
				'radius_units' => array( 'px', '%' ),
				'min_radius'   => 0,
				'max_radius'   => 80,
				'min_width'    => 0,
				'max_width'    => 16,
				'alpha'        => true,
				'palettes'     => array( '#1d2327', '#2271b1', '#e6e8eb', '#ffffff' ),
				'responsive'   => true,
				'device'       => array( 'lg', 'md', 'mobile' ),
			)
		);

		ShadowControl::register(
			array(
				'section_slug' => $section_slug,
				'id'           => 'layout_demo_shadow_inline',
				'title'        => __( 'Box shadow (inline)', 'topten-simple-theme-options' ),
				'description'  => __( 'Inline shadow controls per breakpoint.', 'topten-simple-theme-options' ),
				'selector'     => '#sto-site-header',
				'responsive'   => true,
				'device'       => array( 'lg', 'md', 'mobile' ),
				'popup'        => false,
				'default'      => array(
					'color'      => 'rgba(34, 113, 177, 0.15)',
					'horizontal' => '0',
					'vertical'   => '2',
					'blur'       => '8',
					'spread'     => '0',
					'position'   => 'outline',
				),
			)
		);

		Group::register(
			array(
				'section_slug' => $section_slug,
				'id'           => 'responsive_measure_group',
				'title'        => __( 'Date & dimensions', 'topten-simple-theme-options' ),
				'description'  => __( 'Responsive date and TRBL dimension fields.', 'topten-simple-theme-options' ),
				'fields'       => array(
					array(
						'type'          => 'date',
						'id'            => 'layout_responsive_date',
						'title'         => __( 'Responsive date', 'topten-simple-theme-options' ),
						'description'   => __( 'Responsive date with conditional visibility.', 'topten-simple-theme-options' ),
						'default'       => '',
						'placeholder'   => __( 'Select…', 'topten-simple-theme-options' ),
						'responsive'    => true,
						'required'      => array( 'layout_header' => 'default_header_layout' ),
					),
					array(
						'type'          => 'dimension',
						'id'            => 'layout_responsive_dimension',
						'title'         => __( 'Responsive dimensions', 'topten-simple-theme-options' ),
						'description'   => __( 'Same control per breakpoint; units limited to px and %.', 'topten-simple-theme-options' ),
						'responsive'    => true,
						'units'         => array( 'px', '%' ),
						'min'           => 0,
						'max'           => 120,
						'step'          => 1,
						'show_link'     => true,
						'default'       => array(
							'unit'   => 'px',
							'linked' => false,
							'values' => array(
								'top'    => '8',
								'right'  => '16',
								'bottom' => '8',
								'left'   => '16',
							),
						),
					),
				),
			)
		);

		Range::register_many(
			array(
				array(
					'section_slug' => $section_slug,
					'id'           => 'layout_popup_width',
					'title'        => __( 'Popup width', 'topten-simple-theme-options' ),
					'description'  => __( 'Popup width in pixels per device.', 'topten-simple-theme-options' ),
					'default'      => '760px',
					'min'          => 200,
					'max'          => 1400,
					'responsive'   => true,
					'device'       => array( 'lg', 'md', 'mobile' ),
					'step'         => 1,
					'units'        => array( 'px' ),
				),
				array(
					'section_slug' => $section_slug,
					'id'           => 'layout_popup_padding',
					'title'        => __( 'Popup padding', 'topten-simple-theme-options' ),
					'description'  => __( 'Popup padding per device tab.', 'topten-simple-theme-options' ),
					'default'      => '30px',
					'min'          => 0,
					'max'          => 120,
					'step'         => 1,
					'responsive'   => true,
					'units'        => array( 'rem', 'em', 'custom' ),
				),
			)
		);

		Tabs::register(
			array(
				'section_slug'  => $section_slug,
				'id'            => 'layout_standalone_tabs',
				'title'         => __( 'Responsive tabs & grid', 'topten-simple-theme-options' ),
				'description'   => __( 'Responsive tabs with twelve-column grid.', 'topten-simple-theme-options' ),
				'responsive'    => true,
				'tooltip'       => array(
					'image' => 'https://picsum.photos/seed/sto-tabs-tooltip/520/720',
				),
				'device'        => array( 'xxl', 'md', 'mobile' ),
				'tabs'          => array(
					array( 'id' => 'slot_a', 'label' => __( 'Primary', 'topten-simple-theme-options' ) ),
					array( 'id' => 'slot_b', 'label' => __( 'Secondary', 'topten-simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'    => 'text',
						'id'      => 'demo_col_1',
						'title'   => __( 'Row 1 · column 1', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_2',
						'title'   => __( 'Row 1 · column 2', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_3',
						'title'   => __( 'Row 1 · column 3', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_4',
						'title'   => __( 'Row 2 · column 1', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_5',
						'title'   => __( 'Row 2 · column 2', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_6',
						'title'   => __( 'Row 2 · column 3', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
				),
			)
		);

		Accordion::register(
			array(
				'section_slug'  => $section_slug,
				'id'            => 'acc_resp',
				'title'         => __( 'Responsive accordion', 'topten-simple-theme-options' ),
				'description'   => __( 'Responsive accordion with device tabs on the title row.', 'topten-simple-theme-options' ),
				'wrapper_class' => 'sto-acc-demo-block',
				'responsive'    => true,
				'device'        => array( 'xxl', 'md', 'mobile' ),
				'panels'        => array(
					array( 'id' => 'desk', 'label' => __( 'Desktop', 'topten-simple-theme-options' ) ),
					array( 'id' => 'mob', 'label' => __( 'Mobile', 'topten-simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'    => 'text',
						'id'      => 'ar_label',
						'title'   => __( 'Breakpoint label', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-1',
					),
					array(
						'type'       => 'range',
						'id'         => 'ar_pad',
						'title'      => __( 'Outer padding', 'topten-simple-theme-options' ),
						'default'    => array( 'value' => 24, 'unit' => 'px' ),
						'min'        => 0,
						'max'        => 120,
						'step'       => 1,
						'units'      => array( 'px' ),
						'width'      => '1-2',
						'responsive' => true,
						'device'     => array( 'xxl', 'mobile' ),
					),
					array(
						'id'          => 'ar_scheme',
						'title'       => __( 'Color scheme', 'topten-simple-theme-options' ),
						'default'     => 'light',
						'width'       => '1-2',
						'options'     => array(
							'light' => __( 'Light', 'topten-simple-theme-options' ),
							'dark'  => __( 'Dark', 'topten-simple-theme-options' ),
						),
					),
				),
			)
		);
	}
}
