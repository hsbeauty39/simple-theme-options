<?php
namespace SimpleThemeOptions\Admin\Sample\Fields\General;

use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\Group\Group;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\DateField\DateField;
use SimpleThemeOptions\Admin\Options\Fields\DateTimeField\DateTimeField;
use SimpleThemeOptions\Admin\Options\Fields\AlignmentControl\AlignmentControl;
use SimpleThemeOptions\Admin\Options\Fields\Dimension\Dimension;
use SimpleThemeOptions\Admin\Options\Fields\IconSelect\IconSelect;
use SimpleThemeOptions\Admin\Options\Fields\GalleryControl\GalleryControl;
use SimpleThemeOptions\Admin\Options\Fields\MultiTextControl\MultiTextControl;
use SimpleThemeOptions\Admin\Options\Fields\RadioListsControl\RadioListsControl;
use SimpleThemeOptions\Admin\Options\Fields\GoogleMapControl\GoogleMapControl;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\Tabs\Tabs;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\CheckboxControl\CheckboxControl;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Sample fields for **Field samples** subsections (`layout-nav`, `layout-type`, … under parent `field-samples`).
 */
final class General {
	use SingletonTrait;

	protected function init() {
		Group::instance();
		ImageSelect::instance();
		ButtonGroup::instance();
		Input::instance();
		Range::instance();
		DateField::instance();
		DateTimeField::instance();
		Dimension::instance();
		IconSelect::instance();
		GalleryControl::instance();
		MultiTextControl::instance();
		RadioListsControl::instance();
		GoogleMapControl::instance();
		AlignmentControl::instance();
		Tabs::instance();
		Switcher::instance();
		// Priority 18: boot Select + DynamicObject before CheckboxControl so **layout-nav** rows render in nav order (selects → search pickers → tiles), not tiles first.
		Select::instance();
		DynamicObject::instance();
		CheckboxControl::instance();
		Typography::instance();
		BorderControl::instance();
		ShadowControl::instance();
		CodeEditor::instance();
		$this->register_select_fields();
	}

	public function register_select_fields() {
		Select::register_many(
			array(
				array(
					'section_slug'  => 'layout-nav',
					'id'            => 'layout_header',
					'title'         => __( 'Site header', 'simple-theme-options' ),
					'placeholder'   => __( 'Select', 'simple-theme-options' ),
					'description'   => __( 'Choose which header layout applies by default globally; per-post overrides are possible from the Theme Settings meta box on posts/pages.', 'simple-theme-options' ),
					'default'       => 'default_header_layout',
					'responsive'    => true,
					'options'       => array(
						'none'                  => __( 'None', 'simple-theme-options' ),
						'default_header_layout' => __( 'Default layout', 'simple-theme-options' ),
					),
				),
				array(
					'section_slug'  => 'layout-nav',
					'id'            => 'layout_quick_links',
					'title'         => __( 'Navigation shortcuts', 'simple-theme-options' ),
					'description'   => __( 'Multi-select: chosen pages appear as blue chips. Demonstrates Select2 multiple mode.', 'simple-theme-options' ),
					'multiple'      => true,
					'max'           => 8,
					'placeholder'   => __( 'Select pages…', 'simple-theme-options' ),
					'default'       => array( 'shop_page', 'wishlist', 'cart', 'my_account', 'blog_page' ),
					'options'       => array(
						'shop_page'         => __( 'Shop page', 'simple-theme-options' ),
						'off_canvas_sidebar'=> __( 'Off canvas sidebar', 'simple-theme-options' ),
						'wishlist'          => __( 'Wishlist', 'simple-theme-options' ),
						'cart'              => __( 'Cart', 'simple-theme-options' ),
						'my_account'        => __( 'My account', 'simple-theme-options' ),
						'blog_page'         => __( 'Blog page', 'simple-theme-options' ),
						'contact_page'      => __( 'Contact page', 'simple-theme-options' ),
						'compare'           => __( 'Compare', 'simple-theme-options' ),
					),
				),
			)
		);

		DynamicObject::register(
			array(
				'section_slug'  => 'layout-nav',
				'id'            => 'layout_featured_page',
				'title'         => __( 'Featured page (search)', 'simple-theme-options' ),
				'description'   => __( 'Single page picker: type at least three characters to search (AJAX). Tooltip preview on the help icon.', 'simple-theme-options' ),
				'post_type'     => 'page',
				'limit'         => 10,
				'placeholder'   => __( 'Search pages… (min. 3 characters)', 'simple-theme-options' ),
				'required'      => array(
					'layout_header' => 'default_header_layout',
				),
				'tooltip'       => array(
					'image' => 'https://picsum.photos/seed/sto-page-tooltip/520/720',
				),
				'responsive'    => true,
			)
		);

		DynamicObject::register(
			array(
				'section_slug'  => 'layout-nav',
				'id'            => 'layout_featured_posts',
				'title'         => __( 'Featured posts (multi)', 'simple-theme-options' ),
				'description'   => __( 'Multi post picker with the same search rules; values stored as post IDs.', 'simple-theme-options' ),
				'post_type'     => 'post',
				'multiple'      => true,
				'max'           => 8,
				'limit'         => 10,
				'placeholder'   => __( 'Search posts… (min. 3 characters)', 'simple-theme-options' ),
				'default'       => array(),
				'tooltip'       => array(
					'image' => 'https://picsum.photos/seed/sto-posts-tooltip/520/720',
				),
			)
		);

		Typography::register(
			array(
				'section_slug' => 'layout-type',
				'id'           => 'layout_body_typography',
				'title'        => __( 'Body typography', 'simple-theme-options' ),
				'description'  => __( 'Font family, weight, subset, and transform — with live preview. Responsive device tabs when the header above is set to the default layout.', 'simple-theme-options' ),
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

		CheckboxControl::register_many(
			array(
				array(
					'section_slug'  => 'layout-nav',
					'id'            => 'layout_demo_checkbox_single',
					'title'         => __( 'Custom checkbox (single)', 'simple-theme-options' ),
					'description'   => __( 'Boolean stored as 1/0 with a tile control (hidden field + button), not a default browser checkbox row.', 'simple-theme-options' ),
					'default'       => '0',
					'labels'        => array(
						'on'  => __( 'Feature enabled', 'simple-theme-options' ),
						'off' => __( 'Feature disabled', 'simple-theme-options' ),
					),
					'responsive'    => true,
					'device'        => array( 'lg', 'md', 'mobile' ),
					'required'      => array(
						'layout_header' => 'default_header_layout',
					),
				),
				array(
					'section_slug'  => 'layout-nav',
					'id'            => 'layout_demo_checkbox_multi',
					'title'         => __( 'Multi-check tiles', 'simple-theme-options' ),
					'description'   => __( 'Several option keys; native inputs stay for POST but are screen-reader only. Optional max selections and fixed column count.', 'simple-theme-options' ),
					'multiple'      => true,
					'max'           => 3,
					'columns'       => 2,
					'default'       => array( 'badge_sale', 'badge_new' ),
					'options'       => array(
						'badge_sale'   => __( 'Sale badge', 'simple-theme-options' ),
						'badge_new'    => __( 'New badge', 'simple-theme-options' ),
						'badge_hot'    => array(
							'label'   => __( 'Hot badge', 'simple-theme-options' ),
							'tooltip' => __( 'Optional tooltip on the tile.', 'simple-theme-options' ),
						),
						'badge_limited' => __( 'Limited stock', 'simple-theme-options' ),
					),
					'required'      => array(
						'layout_header' => 'default_header_layout',
					),
				),
			)
		);

		BorderControl::register(
			array(
				'section_slug' => 'layout-type',
				'id'           => 'layout_banner_border',
				'title'        => __( 'Border composite', 'simple-theme-options' ),
				'description'  => __( 'Radius, style, width, and color in one JSON value. Open “Edit settings” for the popover. Multi-unit chips when more than one unit is allowed.', 'simple-theme-options' ),
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
				'section_slug' => 'layout-type',
				'id'           => 'layout_demo_shadow_popup',
				'title'        => __( 'Box shadow (popover)', 'simple-theme-options' ),
				'description'  => __( 'Shadow values open in a popover (like Border). Put **`selector`** on the register array next to `default` (not inside `default`). Theme: `sto_get_shadow_css_rule( \'layout_demo_shadow_popup\' )` or `sto_get_shadow_box_shadow()`.', 'simple-theme-options' ),
				'selector'     => '.sto-demo-shadow-target',
				'popup'        => true,
				'default'      => array(
					'color'      => 'rgba(0, 0, 0, 0.14)',
					'horizontal' => '0',
					'vertical'   => '4',
					'blur'       => '12',
					'spread'     => '0',
					'position'   => 'outline',
				),
				'palettes'     => array( '#000000', '#2271b1', '#ffffff' ),
			)
		);

		ShadowControl::register(
			array(
				'section_slug' => 'layout-type',
				'id'           => 'layout_demo_shadow_inline',
				'title'        => __( 'Box shadow (inline)', 'simple-theme-options' ),
				'description'  => __( 'Same field with `popup` disabled: color and sliders stay in the row. **`selector`** sits on the register array beside `default` (see popup demo above).', 'simple-theme-options' ),
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
				'section_slug'  => 'layout-inputs',
				'id'            => 'input_controls',
				'title'         => __( 'Text & number inputs', 'simple-theme-options' ),
				'description'   => __( 'This leaf is Theme Settings → Field samples → Inputs & buttons (section slug layout-inputs). Representative controls: text, number, email, password, textarea, classic editor, phone, search, plus date, datetime, dimension, gallery, multi_text, radio_lists, map, alignment, icon, and button groups.', 'simple-theme-options' ),
				'fields'        => array(
					array(
						'type'            => 'text',
						'id'              => 'layout_banner_link',
						'title'           => __( 'Banner link', 'simple-theme-options' ),
						'description'     => __( 'The link will be added to the whole banner area.', 'simple-theme-options' ),
						'default'         => '',
						'placeholder'     => 'https://example.com',
						'html_required'   => true,
					),
					array(
						'type'            => 'number',
						'id'              => 'layout_column_count',
						'title'           => __( 'Column count', 'simple-theme-options' ),
						'description'     => __( 'Validated number between 1 and 12.', 'simple-theme-options' ),
						'default'         => '3',
						'min'             => '1',
						'max'             => '12',
						'step'            => '1',
						'placeholder'     => __( '1–12', 'simple-theme-options' ),
					),
					array(
						'type'            => 'email',
						'id'              => 'layout_contact_email',
						'title'           => __( 'Contact email', 'simple-theme-options' ),
						'description'     => __( 'HTML email input; stored with sanitize_email.', 'simple-theme-options' ),
						'default'         => '',
						'placeholder'     => 'name@example.com',
					),
					array(
						'type'            => 'password',
						'id'              => 'layout_sample_api_secret',
						'title'           => __( 'Sample API secret', 'simple-theme-options' ),
						'description'     => __( 'Masked input with a show / hide control (eye). Stored with sanitize_text_field like plain text—not encrypted in the database unless you add that in the theme.', 'simple-theme-options' ),
						'default'         => '',
						'placeholder'     => __( 'Paste secret…', 'simple-theme-options' ),
					),
					array(
						'type'          => 'date',
						'id'            => 'layout_sample_date',
						'title'         => __( 'Sample date', 'simple-theme-options' ),
						'description'   => __( 'jQuery UI calendar; value stored as Y-m-d (empty allowed). Uses the site date format in the picker.', 'simple-theme-options' ),
						'default'       => '',
						'placeholder'   => __( 'Select a date…', 'simple-theme-options' ),
						'min_date'      => '2000-01-01',
						'max_date'      => '2035-12-31',
					),
					array(
						'type'          => 'date',
						'id'            => 'layout_responsive_date',
						'title'         => __( 'Responsive date', 'simple-theme-options' ),
						'description'   => __( 'Per-breakpoint date map; shown only when Site header is Default layout (conditional visibility demo).', 'simple-theme-options' ),
						'default'       => '',
						'placeholder'   => __( 'Select…', 'simple-theme-options' ),
						'responsive'    => true,
						'required'      => array( 'layout_header' => 'default_header_layout' ),
					),
					array(
						'type'          => 'datetime',
						'id'            => 'layout_sample_datetime',
						'title'         => __( 'Sample date & time', 'simple-theme-options' ),
						'description'   => __( 'Calendar plus native time; stored as Y-m-d H:i (empty allowed).', 'simple-theme-options' ),
						'default'       => '',
						'placeholder'   => __( 'Pick date & time…', 'simple-theme-options' ),
						'min_date'      => '2000-01-01',
						'max_date'      => '2035-12-31',
						'time_step'     => 60,
					),
					array(
						'type'          => 'dimension',
						'id'            => 'layout_sample_dimension',
						'title'         => __( 'Sample dimensions (TRBL)', 'simple-theme-options' ),
						'description'   => __( 'Top / right / bottom / left with shared units, optional link, and JSON storage. Use sto_get_dimension_shorthand() in the theme for margin/padding-style CSS.', 'simple-theme-options' ),
						'units'         => array( 'px', 'rem', '%', 'custom' ),
						'min'           => 0,
						'max'           => 200,
						'step'          => 1,
						'default'       => array(
							'u'      => 'px',
							'linked' => true,
							'values' => array(
								'top'    => '0',
								'right'  => '0',
								'bottom' => '0',
								'left'   => '0',
							),
						),
					),
					array(
						'type'          => 'dimension',
						'id'            => 'layout_responsive_dimension',
						'title'         => __( 'Responsive dimensions', 'simple-theme-options' ),
						'description'   => __( 'Same control per breakpoint; units limited to px and %.', 'simple-theme-options' ),
						'responsive'    => true,
						'units'         => array( 'px', '%' ),
						'min'           => 0,
						'max'           => 120,
						'step'          => 1,
						'show_link'     => true,
						'default'       => array(
							'u'      => 'px',
							'linked' => false,
							'values' => array(
								'top'    => '8',
								'right'  => '16',
								'bottom' => '8',
								'left'   => '16',
							),
						),
					),
					array(
						'type'          => 'gallery',
						'id'            => 'layout_sample_gallery',
						'title'         => __( 'Sample gallery', 'simple-theme-options' ),
						'description'   => __( 'Optional default is a plain array of Media Library image attachment IDs, e.g. array( 101, 102, 103 ). Non-images are ignored. Theme helper: sto_get_gallery_attachment_ids().', 'simple-theme-options' ),
						'default'       => array(
							101,
							102,
							103,
						),
						'max'           => 24,
					),
					array(
						'type'          => 'multi_text',
						'id'            => 'layout_sample_multi_text',
						'title'         => __( 'Repeater text lines', 'simple-theme-options' ),
						'description'   => __( 'Add lines with the Add more button, drag the grip to reorder, use the trash control to remove. Optional default lines in PHP: default => array( \'Line 1\', \'Line 2\' ); optional placeholder for empty inputs. Stored as JSON in sto_options. Theme helper: sto_get_multi_text_lines( \'layout_sample_multi_text\' ).', 'simple-theme-options' ),
						'default'       => array(
							__( 'First bullet', 'simple-theme-options' ),
							__( 'Second bullet', 'simple-theme-options' ),
							__( 'Third bullet', 'simple-theme-options' ),
						),
						'placeholder'   => __( 'Type a line…', 'simple-theme-options' ),
						'max'           => 40,
					),
					array(
						'type'          => 'radio_lists',
						'id'            => 'layout_sample_radio_lists',
						'title'         => __( 'Repeater radio lists', 'simple-theme-options' ),
						'description'   => __( 'Each row is one list: optional label plus one choice from the shared options map (same shape as a button group). Add list / drag / remove. Stored as JSON in sto_options. Theme helper: sto_get_radio_lists_rows( \'layout_sample_radio_lists\' ).', 'simple-theme-options' ),
						'radio_layout'  => 'inline',
						'default'       => array(
							array(
								'title' => __( 'Primary list', 'simple-theme-options' ),
								'value' => 'compact',
							),
							array(
								'title' => __( 'Secondary list', 'simple-theme-options' ),
								'value' => 'comfortable',
							),
						),
						'max'           => 12,
						'options'       => array(
							'compact'    => array(
								'label'   => __( 'Compact', 'simple-theme-options' ),
								'tooltip' => __( 'Tighter spacing.', 'simple-theme-options' ),
							),
							'comfortable' => array(
								'label'   => __( 'Comfortable', 'simple-theme-options' ),
								'tooltip' => __( 'More breathing room.', 'simple-theme-options' ),
							),
							'spacious'   => __( 'Spacious', 'simple-theme-options' ),
						),
					),
					array(
						'type'          => 'google_map',
						'id'            => 'layout_sample_google_map',
						'title'         => __( 'Sample location map', 'simple-theme-options' ),
						'description'   => __( 'Map (OpenStreetMap), search (Enter) fills fields; fields and search line stay in sync; coordinates trigger reverse lookup. One JSON in sto_options. Theme helper: sto_get_google_map_field().', 'simple-theme-options' ),
						'default'       => array(
							'formatted_address' => '1600 Pennsylvania Avenue NW, Washington, DC 20500, USA',
							'address'           => '1600',
							'street'            => 'Pennsylvania Avenue NW',
							'city'              => 'Washington',
							'state'             => 'DC',
							'zip'               => '20500',
							'country'           => 'United States',
							'lat'               => '38.8976763',
							'lng'               => '-77.0365298',
						),
						// image tooltip
						'tooltip' => array(
							'image' => 'http://woodmart-theme-options.local/wp-content/uploads/2013/09/dsc20040724_152504_532.jpg',
							'preloader' => 'https://example.com/loader.mp4',
						),
					),
					array(
						'type'        => 'alignment',
						'id'          => 'layout_sample_alignment',
						'title'       => __( 'Sample alignment', 'simple-theme-options' ),
						'description' => __( 'Segmented control (icons + labels). Optional css_map for themes: sto_get_alignment_css_fragment().', 'simple-theme-options' ),
						'default'     => 'center',
						'options'     => array(
							'left'   => array(
								'label' => __( 'Left', 'simple-theme-options' ),
								'icon'  => 'fa-light fa-align-left',
							),
							'center' => array(
								'label' => __( 'Center', 'simple-theme-options' ),
								'icon'  => 'fa-light fa-align-center',
							),
							'right'  => array(
								'label' => __( 'Right', 'simple-theme-options' ),
								'icon'  => 'fa-light fa-align-right',
							),
						),
						'css_map'     => array(
							'left'   => 'flex-start',
							'center' => 'center',
							'right'  => 'flex-end',
						),
					),
					array(
						'type'          => 'icon_select',
						'id'            => 'layout_sample_icon',
						'title'         => __( 'Sample icon', 'simple-theme-options' ),
						'description'   => __( 'Font Awesome picker from the plugin manifest (solid / regular / light / brands). Theme: output the class string with sto_get_icon_select_field( \'layout_sample_icon\' ).', 'simple-theme-options' ),
						'default'       => 'fa-light fa-star',
						'allow_clear'   => true,
					),
					array(
						'type'          => 'textarea',
						'id'            => 'layout_notes',
						'title'         => __( 'Notes', 'simple-theme-options' ),
						'description'   => __( 'Multi-line plain text (textarea sanitization).', 'simple-theme-options' ),
						'default'       => '',
						'rows'          => 4,
						'placeholder'   => __( 'Short internal notes…', 'simple-theme-options' ),
					),
					array(
						'type'          => 'button_group',
						'id'            => 'layout_page_title_size',
						'title'         => __( 'Page title size', 'simple-theme-options' ),
						'description'   => __( 'Segmented control: optional per-option help (?). Plain text uses a small hover tooltip; preview_image opens the same floating image preview as row-level FieldTitle tooltips.', 'simple-theme-options' ),
						'default'       => 'default',
						'options'       => array(
							'default' => array(
								'label'         => __( 'Default', 'simple-theme-options' ),
								'tooltip'       => __( 'Theme default heading scale.', 'simple-theme-options' ),
								'preview_image' => 'https://picsum.photos/seed/sto-title-default/640/360',
							),
							'small'   => array(
								'label'         => __( 'Small', 'simple-theme-options' ),
								'tooltip'       => __( 'Tighter title for dense layouts.', 'simple-theme-options' ),
								'preview_image' => 'https://picsum.photos/seed/sto-title-small/640/360',
							),
							'large'   => array(
								'label'         => __( 'Large', 'simple-theme-options' ),
								'tooltip'       => __( 'Hero-style title.', 'simple-theme-options' ),
								'preview_image' => 'https://picsum.photos/seed/sto-title-large/640/360',
							),
						),
					),
					array(
						'type'          => 'button_group',
						'id'            => 'layout_popup_text_scheme',
						'title'         => __( 'Popup text color', 'simple-theme-options' ),
						'description'   => __( 'Set light or dark text depending on the promo popup background.', 'simple-theme-options' ),
						'default'       => 'dark',
						'options'       => array(
							'dark'  => array(
								'label'   => __( 'Dark', 'simple-theme-options' ),
								'tooltip' => __( 'Light text on dark popups.', 'simple-theme-options' ),
							),
							'light' => array(
								'label'   => __( 'Light', 'simple-theme-options' ),
								'tooltip' => __( 'Dark text on light popups.', 'simple-theme-options' ),
							),
						),
					),
					array(
						'type'          => 'text',
						'id'            => 'layout_popup_light_extra',
						'title'         => __( 'Light scheme extra note', 'simple-theme-options' ),
						'description'   => __( 'Visible only when Popup text color is Light — uses the same required JSON as other fields.', 'simple-theme-options' ),
						'default'       => '',
						'required'      => array( 'layout_popup_text_scheme' => 'light' ),
					),
					array(
						'type'            => 'editor',
						'id'              => 'layout_content_block',
						'title'           => __( 'Content block', 'simple-theme-options' ),
						'description'     => __( 'Classic WordPress editor (Visual / Code, Add Media).', 'simple-theme-options' ),
						'default'         => '<p>' . esc_html__( 'Hello world.', 'simple-theme-options' ) . '</p>',
						'editor_height'   => 160,
						'placeholder'     => __( 'Start typing your banner content…', 'simple-theme-options' ),
						'toolbar_end'     => array(
							'label'   => __( 'More', 'simple-theme-options' ),
							'tooltip' => __( 'Insert a Read More tag after the kitchen-sink row.', 'simple-theme-options' ),
							'snippet' => '<!--more-->',
						),
					),
					array(
						'type'            => 'phone',
						'id'              => 'layout_phone',
						'title'           => __( 'Phone', 'simple-theme-options' ),
						'description'     => __( 'Digits, spaces, and common phone symbols only.', 'simple-theme-options' ),
						'default'         => '',
						'placeholder'     => '+1 234 567 8900',
					),
					array(
						'type'          => 'search',
						'id'            => 'layout_search_placeholder',
						'title'         => __( 'Search placeholder', 'simple-theme-options' ),
						'description'   => __( 'HTML5 search input for UI copy (stored as plain text).', 'simple-theme-options' ),
						'default'       => '',
						'placeholder'   => __( 'Search…', 'simple-theme-options' ),
					),
				),
			)
		);

		Group::register(
			array(
				'section_slug'  => 'layout-code-borders',
				'id'            => 'range_controls',
				'title'         => __( 'Sliders & spacing', 'simple-theme-options' ),
				'description'   => __( 'Slider + number + units: locked badge, custom suffix, and mixed rem / em / custom.', 'simple-theme-options' ),
				'fields'        => array(
					array(
						'type'          => 'range',
						'id'            => 'layout_popup_width',
						'title'         => __( 'Popup width', 'simple-theme-options' ),
						'description'   => __( 'Width of the promo popup. One value for all viewports. Units: pixels only.', 'simple-theme-options' ),
						'default'       => '760px',
						'min'           => 200,
						'max'           => 1400,
						'responsive'    => true,
						'device'        => array( 'lg', 'md', 'mobile' ),
						'step'          => 1,
						'units'         => array( 'px' ),
					),
					array(
						'type'          => 'range',
						'id'            => 'layout_popup_show_after_pages',
						'title'         => __( 'Show after number of pages visited', 'simple-theme-options' ),
						'description'   => __( 'How many pages the user should visit before the popup is shown. Uses a custom display label (not a CSS unit).', 'simple-theme-options' ),
						'default'       => '5',
						'min'           => 0,
						'max'           => 50,
						'step'          => 1,
						'units'         => array( 'custom' ),
						'unit_label'    => __( 'PAGE', 'simple-theme-options' ),
					),
					array(
						'type'          => 'range',
						'id'            => 'layout_popup_padding',
						'title'         => __( 'Popup padding', 'simple-theme-options' ),
						'description'   => __( 'Inner padding; can differ per device tab when responsive is enabled. Units: rem, em, or custom suffix.', 'simple-theme-options' ),
						'default'       => '30px',
						'min'           => 0,
						'max'           => 120,
						'step'          => 1,
						'responsive'    => true,
						'units'         => array( 'rem', 'em', 'custom' ),
					),
				),
			)
		);

		Group::register(
			array(
				'section_slug' => 'layout-code-borders',
				'id'           => 'portfolio_url',
				'title'        => __( 'URL slugs (two columns)', 'simple-theme-options' ),
				'description'  => __( 'Side-by-side text fields on the 12-column grid; they stack on narrow screens.', 'simple-theme-options' ),
				'fields'       => array(
					array(
						'type'        => 'text',
						'id'          => 'portfolio_project_slug',
						'title'       => __( 'Portfolio project URL slug', 'simple-theme-options' ),
						'description' => __( 'IMPORTANT: You need to go to WordPress Settings -> Permalinks and resave them to apply these settings.', 'simple-theme-options' ),
						'default'     => '',
						'placeholder' => 'portfolio',
						'width'       => '1-2',
					),
					array(
						'type'        => 'text',
						'id'          => 'portfolio_category_slug',
						'title'       => __( 'Portfolio category URL slug', 'simple-theme-options' ),
						'description' => __( 'IMPORTANT: You need to go to WordPress Settings -> Permalinks and resave them to apply these settings.', 'simple-theme-options' ),
						'default'     => '',
						'placeholder' => 'portfolio-category',
						'width'       => '1-2',
					),
				),
			)
		);

		CodeEditor::register(
			array(
				'section_slug' => 'layout-code-borders',
				'id'           => 'custom_css',
				'title'        => __( 'Code editor (auto-detect)', 'simple-theme-options' ),
				'description'  => __( 'Paste CSS, JS, HTML, PHP, JSON, Markdown, XML, or YAML — mode follows content; override from the language chip.', 'simple-theme-options' ),
				'mode'         => 'auto',
				'height'       => 320,
				'placeholder'  => "/* Paste any CSS, JS, HTML, PHP, JSON or Markdown — the editor will detect the language. */\n.site-header {\n\tbackground-color: #fff;\n}",
				'default'      => '',
			)
		);

		Group::register(
			array(
				'section_slug' => 'layout-code-borders',
				'id'           => 'custom_code_snippets',
				'title'        => __( 'Header & footer code', 'simple-theme-options' ),
				'description'  => __( 'Two editors in one row (`width` 1-2): HTML for the head, JavaScript before closing body.', 'simple-theme-options' ),
				'fields'       => array(
					array(
						'type'        => 'code_editor',
						'id'          => 'custom_header_html',
						'title'       => __( 'Header HTML', 'simple-theme-options' ),
						'description' => __( 'Sanitized with `wp_kses_post()` — safe for editor-cap users. Output inside the `<head>` tag.', 'simple-theme-options' ),
						'mode'        => 'html',
						'height'      => 220,
						'placeholder' => "<!-- Google Tag Manager, verification meta, etc. -->",
						'width'       => '1-2',
					),
					array(
						'type'        => 'code_editor',
						'id'          => 'custom_footer_js',
						'title'       => __( 'Footer JavaScript', 'simple-theme-options' ),
						'description' => __( 'Stored verbatim — `manage_options` capability is required to save. Output just before the closing `</body>` tag, unescaped.', 'simple-theme-options' ),
						'mode'        => 'javascript',
						'height'      => 220,
						'placeholder' => "// Custom analytics / chat-widget bootstrap",
						'width'       => '1-2',
					),
				),
			)
		);

		Group::register(
			array(
				'section_slug' => 'layout-code-borders',
				'id'           => 'border_controls',
				'title'        => __( 'Border presets', 'simple-theme-options' ),
				'description'  => __( 'Full border control vs. a reduced `features` set (radius + style only).', 'simple-theme-options' ),
				'fields'       => array(
					array(
						'type'         => 'border',
						'id'           => 'card_border',
						'title'        => __( 'Card outline (full)', 'simple-theme-options' ),
						'description'  => __( 'Radius (px / %), style, width, and color with alpha.', 'simple-theme-options' ),
						'default'      => array(
							'radius'      => '8',
							'radius_unit' => 'px',
							'style'       => 'solid',
							'width'       => '1',
							'width_unit'  => 'px',
							'color'       => '#e6e8eb',
						),
						'radius_units' => array( 'px', '%' ),
						'min_radius'   => 0,
						'max_radius'   => 60,
						'min_width'    => 0,
						'max_width'    => 12,
					),
					array(
						'type'        => 'border',
						'id'          => 'card_border_simple',
						'title'       => __( 'Card outline (radius + style)', 'simple-theme-options' ),
						'description' => __( 'Same field with only radius and style in the popover.', 'simple-theme-options' ),
						'default'     => array(
							'radius' => '12',
							'style'  => 'dashed',
						),
						'features'    => array( 'radius', 'style' ),
					),
				),
			)
		);

		Group::register(
			array(
				'section_slug'  => 'layout-tabs-side',
				'id'            => 'layout_custom_buttons_tabs',
				'title'         => __( 'Tabbed button slots', 'simple-theme-options' ),
				'description'   => __( 'Tabs inside a group: each tab repeats the same inner fields; storage keys include the tab id.', 'simple-theme-options' ),
				'fields'        => array(
					array(
						'type'        => 'tabs',
						'id'          => 'layout_cust_btn',
						'title'       => __( 'Button bar', 'simple-theme-options' ),
						'description' => __( 'Five slots share one field template; values are stored per tab.', 'simple-theme-options' ),
						'tabs'        => array(
							array( 'id' => 'b1', 'label' => __( 'Slot 1', 'simple-theme-options' ) ),
							array( 'id' => 'b2', 'label' => __( 'Slot 2', 'simple-theme-options' ) ),
							array( 'id' => 'b3', 'label' => __( 'Slot 3', 'simple-theme-options' ) ),
							array( 'id' => 'b4', 'label' => __( 'Slot 4', 'simple-theme-options' ) ),
							array( 'id' => 'b5', 'label' => __( 'Slot 5', 'simple-theme-options' ) ),
						),
						'fields'      => array(
							array(
								'type'          => 'text',
								'id'            => 'btn_url',
								'title'         => __( 'Link URL', 'simple-theme-options' ),
								'default'       => '',
								'placeholder'   => 'https://',
								'width'         => '1-3',
							),
							array(
								'type'          => 'text',
								'id'            => 'btn_text',
								'title'         => __( 'Label', 'simple-theme-options' ),
								'default'       => '',
								'width'         => '1-3',
							),
							array(
								'type'          => 'text',
								'id'            => 'btn_icon',
								'title'         => __( 'Icon URL', 'simple-theme-options' ),
								'description'   => __( 'Icon URL or attachment ID as text (media picker can be wired in the theme).', 'simple-theme-options' ),
								'default'       => '',
								'width'         => '1-3',
							),
						),
					),
				),
			)
		);

		Tabs::register(
			array(
				'section_slug'  => 'layout-tabs-side',
				'id'            => 'layout_standalone_tabs',
				'title'         => __( 'Responsive tabs & grid', 'simple-theme-options' ),
				'description'   => __( 'Standalone tabs row: master strip + per-field device toolbars. Inner fields use the 12-column grid inside each tab.', 'simple-theme-options' ),
				'responsive'    => true,
				'tooltip'       => array(
					'image' => 'https://picsum.photos/seed/sto-tabs-tooltip/520/720',
				),
				'device'        => array( 'xxl', 'md', 'mobile' ),
				'tabs'          => array(
					array( 'id' => 'slot_a', 'label' => __( 'Primary', 'simple-theme-options' ) ),
					array( 'id' => 'slot_b', 'label' => __( 'Secondary', 'simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'    => 'text',
						'id'      => 'demo_col_1',
						'title'   => __( 'Row 1 · column 1', 'simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_2',
						'title'   => __( 'Row 1 · column 2', 'simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_3',
						'title'   => __( 'Row 1 · column 3', 'simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_4',
						'title'   => __( 'Row 2 · column 1', 'simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
						'tooltip' => array(
							'image' => 'https://picsum.photos/seed/sto-banner-tooltip/520/720',
						),
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_5',
						'title'   => __( 'Row 2 · column 2', 'simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'demo_col_6',
						'title'   => __( 'Row 2 · column 3', 'simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
				),
			)
		);

		Group::register(
			array(
				'section_slug' => 'layout-tabs-side',
				'id'           => 'sidebar',
				'title'        => __( 'Sidebar layout', 'simple-theme-options' ),
				'required'     => array(
					'layout_header' => 'default_header_layout',
				),
				'fields'       => array(
					array(
						'type'          => 'image_select',
						'id'            => 'sidebar_position',
						'title'         => __( 'Position', 'simple-theme-options' ),
						'description'   => __( 'Image tiles with responsive column counts; hover shows the full label.', 'simple-theme-options' ),
						'default'       => 'right',
						// `columns` accepts an int (same on all viewports) or a per-tier map.
						// xxl = default / desktop, md = ≤991px, mobile = ≤600px. Missing tiers cascade up.
						'columns'       => array(
							'xxl'    => 3,
							'md'     => 2,
							'mobile' => 1,
						),
						// Each option may use `image` (URL) for a custom thumbnail; add as many choices as needed.
						// `label` doubles as a hover/focus tooltip on every tile so admins can identify thumbs even when columns are narrow.
						'options'       => array(
							'none'  => array(
								'label' => __( 'No sidebar', 'simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-sidebar-none/400/170',
							),
							'left'  => array(
								'label' => __( 'Left sidebar', 'simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-sidebar-left/400/170',
							),
							'right' => array(
								'label' => __( 'Right sidebar', 'simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-sidebar-right/400/170',
							),
						),
					),
					array(
						'id'          => 'sidebar_size',
						'title'       => __( 'Width preset', 'simple-theme-options' ),
						'description' => __( 'Visible when the sidebar sits on the right.', 'simple-theme-options' ),
						'default'     => 'medium',
						'options'     => array(
							'small'  => __( 'Small', 'simple-theme-options' ),
							'medium' => __( 'Medium', 'simple-theme-options' ),
							'large'  => __( 'Large', 'simple-theme-options' ),
						),
						'required'     => array(
							'sidebar_position' => 'right',
						),
					),
					array(
						'type'          => 'switcher',
						'id'            => 'sidebar_off_canvas_mobile',
						'title'         => __( 'Off canvas sidebar for mobile', 'simple-theme-options' ),
						'description'   => __( 'You can hide the sidebar on mobile devices and show it nicely with a button click.', 'simple-theme-options' ),
						'default'       => '0',
						'required'      => array(
							'sidebar_position' => 'right',
						),
						'tooltip'       => array(
							'image' => 'https://picsum.photos/seed/sto-sidebar-tooltip/520/720',
						),
					),
					array(
						'type'        => 'typography',
						'id'          => 'sidebar_typography',
						'title'       => __( 'Sidebar typography', 'simple-theme-options' ),
						'description' => __( 'Example typography control inside a nested group.', 'simple-theme-options' ),
						'default'     => array(
							'family'    => 'Open Sans',
							'variant'   => 'regular',
							'subset'    => 'latin',
							'transform' => 'none',
						),
						'required'    => array(
							array(
								'sidebar_position' => 'right',
								'sidebar_off_canvas_mobile' => '1',
							),
						),
					),
					array(
						'id'            => 'sidebar_layout_extras',
						'title'         => __( 'Nested options', 'simple-theme-options' ),
						'description'   => __( 'Subgroup inside Sidebar — demonstrates nested group + conditional visibility.', 'simple-theme-options' ),
						'required'      => array(
							array(
								'sidebar_position' => 'right',
							),
						),
						'fields'        => array(
							array(
								'id'          => 'sidebar_sticky',
								'title'       => __( 'Sticky sidebar', 'simple-theme-options' ),
								'description' => __( 'Shown only when position is right and width is large.', 'simple-theme-options' ),
								'default'     => 'no',
								'options'     => array(
									'no'  => __( 'No', 'simple-theme-options' ),
									'yes' => __( 'Yes', 'simple-theme-options' ),
								),
								'required'     => array(
									array(
										'sidebar_position' => 'right',
										'sidebar_size' => 'large',
									),
								),
							),
						),
					),
				),
			)
		);
	}
}
