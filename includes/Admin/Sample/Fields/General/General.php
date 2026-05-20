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
 * Per-breakpoint demos live on leaf section **`responsive`** ({@see Responsive\Responsive}).
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
					'id'            => 'layout_quick_links',
					'title'         => __( 'Navigation shortcuts', 'topten-simple-theme-options' ),
					'description'  => __( 'Multi-select pages as chips.', 'topten-simple-theme-options' ),
					'multiple'      => true,
					'max'           => 8,
					'placeholder'   => __( 'Select pages…', 'topten-simple-theme-options' ),
					'default'       => array( 'shop_page', 'wishlist', 'cart', 'my_account', 'blog_page' ),
					'options'       => array(
						'shop_page'         => __( 'Shop page', 'topten-simple-theme-options' ),
						'off_canvas_sidebar'=> __( 'Off canvas sidebar', 'topten-simple-theme-options' ),
						'wishlist'          => __( 'Wishlist', 'topten-simple-theme-options' ),
						'cart'              => __( 'Cart', 'topten-simple-theme-options' ),
						'my_account'        => __( 'My account', 'topten-simple-theme-options' ),
						'blog_page'         => __( 'Blog page', 'topten-simple-theme-options' ),
						'contact_page'      => __( 'Contact page', 'topten-simple-theme-options' ),
						'compare'           => __( 'Compare', 'topten-simple-theme-options' ),
					),
				),
			)
		);

		DynamicObject::register(
			array(
				'section_slug'  => 'layout-nav',
				'id'            => 'layout_featured_posts',
				'title'         => __( 'Featured posts (multi)', 'topten-simple-theme-options' ),
				'description'  => __( 'Multi post picker, stores post IDs.', 'topten-simple-theme-options' ),
				'post_type'     => 'post',
				'multiple'      => true,
				'max'           => 8,
				'limit'         => 10,
				'placeholder'   => __( 'Search posts… (min. 3 characters)', 'topten-simple-theme-options' ),
				'default'       => array(),
				'tooltip'       => array(
					'image' => 'https://picsum.photos/seed/sto-posts-tooltip/520/720',
				),
			)
		);

		CheckboxControl::register_many(
			array(
				array(
					'section_slug'  => 'layout-nav',
					'id'            => 'layout_demo_checkbox_multi',
					'title'         => __( 'Multi-check tiles', 'topten-simple-theme-options' ),
					'description'  => __( 'Multi-check tiles with optional max.', 'topten-simple-theme-options' ),
					'multiple'      => true,
					'max'           => 3,
					'columns'       => 2,
					'default'       => array( 'badge_sale', 'badge_new' ),
					'options'       => array(
						'badge_sale'   => __( 'Sale badge', 'topten-simple-theme-options' ),
						'badge_new'    => __( 'New badge', 'topten-simple-theme-options' ),
						'badge_hot'    => array(
							'label'   => __( 'Hot badge', 'topten-simple-theme-options' ),
							'tooltip' => __( 'Optional tooltip on the tile.', 'topten-simple-theme-options' ),
						),
						'badge_limited' => __( 'Limited stock', 'topten-simple-theme-options' ),
					),
					'required'      => array(
						'layout_header' => 'default_header_layout',
					),
				),
			)
		);

		Typography::register(
			array(
				'section_slug' => 'layout-type',
				'id'           => 'layout_demo_typography',
				'title'        => __( 'Sample typography', 'topten-simple-theme-options' ),
				'description'  => __( 'Font family, weight, subset, and text transform with live preview.', 'topten-simple-theme-options' ),
				'default'      => array(
					'family'    => 'Inter',
					'variant'   => 'regular',
					'subset'    => 'latin',
					'transform' => 'none',
				),
			)
		);

		ShadowControl::register(
			array(
				'section_slug' => 'layout-type',
				'id'           => 'layout_demo_shadow_popup',
				'title'        => __( 'Box shadow (popover)', 'topten-simple-theme-options' ),
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

		RadioListsControl::register(
			array(
				'section_slug'     => 'layout-inputs',
				'id'               => 'layout_sample_radio_list_single',
				'title'            => __( 'Radio list (single row, outside group)', 'topten-simple-theme-options' ),
				'description'  => __( 'Stacked radio list outside group.', 'topten-simple-theme-options' ),
				'repeatable'       => false,
				'show_row_titles'  => false,
				'radio_layout'     => 'stack',
				'default'          => array(
					array(
						'title' => '',
						'value' => 'comfortable',
					),
				),
				'options'          => array(
					'compact'     => array(
						'label'   => __( 'Compact', 'topten-simple-theme-options' ),
						'tooltip' => __( 'Tighter spacing.', 'topten-simple-theme-options' ),
					),
					'comfortable' => array(
						'label'   => __( 'Comfortable', 'topten-simple-theme-options' ),
						'tooltip' => __( 'More breathing room.', 'topten-simple-theme-options' ),
					),
					'spacious'    => __( 'Spacious', 'topten-simple-theme-options' ),
				),
			)
		);

		$input_control_fields = self::flattenFieldsWithMarginPadding(
			array(
					array(
						'type'            => 'text',
						'id'              => 'layout_banner_link',
						'title'           => __( 'Banner link', 'topten-simple-theme-options' ),
						'description'     => __( 'The link will be added to the whole banner area.', 'topten-simple-theme-options' ),
						'default'         => '',
						'placeholder'     => 'https://example.com',
						'html_required'   => true,
					),
					array(
						'type'            => 'number',
						'id'              => 'layout_column_count',
						'title'           => __( 'Column count', 'topten-simple-theme-options' ),
						'description'     => __( 'Validated number between 1 and 12.', 'topten-simple-theme-options' ),
						'default'         => '3',
						'min'             => '1',
						'max'             => '12',
						'step'            => '1',
						'placeholder'     => __( '1–12', 'topten-simple-theme-options' ),
					),
					array(
						'type'            => 'email',
						'id'              => 'layout_contact_email',
						'title'           => __( 'Contact email', 'topten-simple-theme-options' ),
						'description'     => __( 'HTML email input; stored with sanitize_email.', 'topten-simple-theme-options' ),
						'default'         => '',
						'placeholder'     => 'name@example.com',
					),
					array(
						'type'            => 'password',
						'id'              => 'layout_sample_api_secret',
						'title'           => __( 'Sample API secret', 'topten-simple-theme-options' ),
						'description'  => __( 'Password field with show-hide toggle.', 'topten-simple-theme-options' ),
						'default'         => '',
						'placeholder'     => __( 'Paste secret…', 'topten-simple-theme-options' ),
					),
					array(
						'type'          => 'date',
						'id'            => 'layout_sample_date',
						'title'         => __( 'Sample date', 'topten-simple-theme-options' ),
						'description'  => __( 'Date picker, stored as Y-m-d.', 'topten-simple-theme-options' ),
						'default'       => '',
						'placeholder'   => __( 'Select a date…', 'topten-simple-theme-options' ),
						'min_date'      => '2000-01-01',
						'max_date'      => '2035-12-31',
					),
					array(
						'type'          => 'datetime',
						'id'            => 'layout_sample_datetime',
						'title'         => __( 'Sample date & time', 'topten-simple-theme-options' ),
						'description'   => __( 'Date and time, stored as Y-m-d H:i.', 'topten-simple-theme-options' ),
						'default'       => '',
						'placeholder'   => __( 'Pick date & time…', 'topten-simple-theme-options' ),
						'min_date'      => '2000-01-01',
						'max_date'      => '2035-12-31',
						'time_step'     => 60,
					),
					array(
						'type'          => 'gallery',
						'id'            => 'layout_sample_gallery',
						'title'         => __( 'Sample gallery', 'topten-simple-theme-options' ),
						'description'  => __( 'Gallery of Media Library image IDs.', 'topten-simple-theme-options' ),
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
						'title'         => __( 'Repeater text lines', 'topten-simple-theme-options' ),
						'default'       => array(
							__( 'First bullet', 'topten-simple-theme-options' ),
							__( 'Second bullet', 'topten-simple-theme-options' ),
							__( 'Third bullet', 'topten-simple-theme-options' ),
						),
						'placeholder'   => __( 'Type a line…', 'topten-simple-theme-options' ),
						'max'           => 40,
					),
					array(
						'type'          => 'radio_lists',
						'id'            => 'layout_sample_radio_lists',
						'title'         => __( 'Repeater radio lists', 'topten-simple-theme-options' ),
						'radio_layout'  => 'stack',
						'default'       => array(
							array(
								'title' => __( 'Primary list', 'topten-simple-theme-options' ),
								'value' => 'compact',
							),
							array(
								'title' => __( 'Secondary list', 'topten-simple-theme-options' ),
								'value' => 'comfortable',
							),
						),
						'max'           => 12,
						'options'       => array(
							'compact'    => array(
								'label'   => __( 'Compact', 'topten-simple-theme-options' ),
								'tooltip' => __( 'Tighter spacing.', 'topten-simple-theme-options' ),
							),
							'comfortable' => array(
								'label'   => __( 'Comfortable', 'topten-simple-theme-options' ),
								'tooltip' => __( 'More breathing room.', 'topten-simple-theme-options' ),
							),
							'spacious'   => __( 'Spacious', 'topten-simple-theme-options' ),
						),
					),
					array(
						'type'        => 'alignment',
						'id'          => 'layout_sample_alignment',
						'title'       => __( 'Sample alignment', 'topten-simple-theme-options' ),
						'description' => __( 'Segmented alignment control with icons.', 'topten-simple-theme-options' ),
						'default'     => 'center',
						'options'     => array(
							'left'   => array(
								'label' => __( 'Left', 'topten-simple-theme-options' ),
								'icon'  => 'fa-light fa-align-left',
							),
							'center' => array(
								'label' => __( 'Center', 'topten-simple-theme-options' ),
								'icon'  => 'fa-light fa-align-center',
							),
							'right'  => array(
								'label' => __( 'Right', 'topten-simple-theme-options' ),
								'icon'  => 'fa-light fa-align-right',
							),
						),
					),
					array(
						'type'          => 'icon_select',
						'id'            => 'layout_sample_icon',
						'title'         => __( 'Sample icon', 'topten-simple-theme-options' ),
						'default'       => 'fa-light fa-star',
						'allow_clear'   => true,
					),
					array(
						'type'          => 'textarea',
						'id'            => 'layout_notes',
						'title'         => __( 'Notes', 'topten-simple-theme-options' ),
						'description'   => __( 'Multi-line plain text (textarea sanitization).', 'topten-simple-theme-options' ),
						'default'       => '',
						'rows'          => 4,
						'placeholder'   => __( 'Short internal notes…', 'topten-simple-theme-options' ),
					),
					array(
						'type'          => 'button_group',
						'id'            => 'layout_page_title_size',
						'title'         => __( 'Page title size', 'topten-simple-theme-options' ),
						'description'  => __( 'Segmented control with option tooltips.', 'topten-simple-theme-options' ),
						'default'       => 'default',
						'options'       => array(
							'default' => array(
								'label'         => __( 'Default', 'topten-simple-theme-options' ),
								'tooltip'       => __( 'Theme default heading scale.', 'topten-simple-theme-options' ),
								'preview_image' => 'https://picsum.photos/seed/sto-title-default/640/360',
							),
							'small'   => array(
								'label'         => __( 'Small', 'topten-simple-theme-options' ),
								'tooltip'       => __( 'Tighter title for dense layouts.', 'topten-simple-theme-options' ),
								'preview_image' => 'https://picsum.photos/seed/sto-title-small/640/360',
							),
							'large'   => array(
								'label'         => __( 'Large', 'topten-simple-theme-options' ),
								'tooltip'       => __( 'Hero-style title.', 'topten-simple-theme-options' ),
								'preview_image' => 'https://picsum.photos/seed/sto-title-large/640/360',
							),
						),
					),
					array(
						'type'          => 'button_group',
						'id'            => 'layout_popup_text_scheme',
						'title'         => __( 'Popup text color', 'topten-simple-theme-options' ),
						'default'       => 'dark',
						'options'       => array(
							'dark'  => array(
								'label'   => __( 'Dark', 'topten-simple-theme-options' ),
								'tooltip' => __( 'Light text on dark popups.', 'topten-simple-theme-options' ),
							),
							'light' => array(
								'label'   => __( 'Light', 'topten-simple-theme-options' ),
								'tooltip' => __( 'Dark text on light popups.', 'topten-simple-theme-options' ),
							),
						),
					),
					array(
						'type'          => 'text',
						'id'            => 'layout_popup_light_extra',
						'title'         => __( 'Light scheme extra note', 'topten-simple-theme-options' ),
						'description'  => __( 'Shown when popup text is light.', 'topten-simple-theme-options' ),
						'default'       => '',
						'required'      => array( 'layout_popup_text_scheme' => 'light' ),
					),
					array(
						'type'            => 'editor',
						'id'              => 'layout_content_block',
						'title'           => __( 'Content block', 'topten-simple-theme-options' ),
						'description'     => __( 'Classic WordPress editor (Visual / Code, Add Media).', 'topten-simple-theme-options' ),
						'default'         => '<p>' . esc_html__( 'Hello world.', 'topten-simple-theme-options' ) . '</p>',
						'editor_height'   => 160,
						'placeholder'     => __( 'Start typing your banner content…', 'topten-simple-theme-options' ),
						'toolbar_end'     => array(
							'label'   => __( 'More', 'topten-simple-theme-options' ),
							'tooltip' => __( 'Insert a Read More tag after the kitchen-sink row.', 'topten-simple-theme-options' ),
							'snippet' => '<!--more-->',
						),
					),
					array(
						'type'            => 'phone',
						'id'              => 'layout_phone',
						'space'           => '20px',
						'title'           => __( 'Phone', 'topten-simple-theme-options' ),
						'description'     => __( 'Digits, spaces, and common phone symbols only.', 'topten-simple-theme-options' ),
						'default'         => '',
						'placeholder'     => '+1 234 567 8900',
					),
					array(
						'type'          => 'search',
						'id'            => 'layout_search_placeholder',
						'title'         => __( 'Search placeholder', 'topten-simple-theme-options' ),
						'description'   => __( 'HTML5 search input for UI copy (stored as plain text).', 'topten-simple-theme-options' ),
						'default'       => '',
						'placeholder'   => __( 'Search…', 'topten-simple-theme-options' ),
					),
			)
		);

		Group::register(
			array(
				'section_slug'  => 'layout-inputs',
				'id'            => 'input_controls',
				'title'         => __( 'Text & number inputs', 'topten-simple-theme-options' ),
				'description'   => __( 'Sample inputs; each control is followed by Padding and Margin dimension fields (TRBL).', 'topten-simple-theme-options' ),
				'fields'        => $input_control_fields,
			)
		);

		Dimension::register_many(
			self::standaloneMarginPaddingPair(
				'layout-inputs',
				'layout_sample_radio_list_single',
				__( 'Radio list (single row, outside group)', 'topten-simple-theme-options' )
			)
		);

		GoogleMapControl::register(
			array(
				'section_slug'  => 'layout-inputs',
				'id'            => 'layout_sample_google_map',
				'title'         => __( 'Sample location map', 'topten-simple-theme-options' ),
				'description'  => __( 'Map search with synced address fields.', 'topten-simple-theme-options' ),
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
				'tooltip'       => array(
					// phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- Sample tooltip URL for Theme Settings demo only.
					'image'     => 'http://woodmart-theme-options.local/wp-content/uploads/2013/09/dsc20040724_152504_532.jpg',
					'preloader' => 'https://example.com/loader.mp4',
				),
			)
		);

		Dimension::register_many(
			self::standaloneMarginPaddingPair(
				'layout-inputs',
				'layout_sample_google_map',
				__( 'Sample location map', 'topten-simple-theme-options' )
			)
		);

		Range::register_many(
			array(
				array(
					'section_slug' => 'layout-code-borders',
					'id'           => 'layout_popup_show_after_pages',
					'title'        => __( 'Show after number of pages visited', 'topten-simple-theme-options' ),
					'description'  => __( 'Pages visited before popup shows.', 'topten-simple-theme-options' ),
					'default'      => '5',
					'min'          => 0,
					'max'          => 50,
					'step'         => 1,
					'units'        => array( 'custom' ),
					'unit_label'   => __( 'PAGE', 'topten-simple-theme-options' ),
				),
			)
		);

		Input::register_many(
			array(
				array(
					'section_slug'  => 'layout-code-borders',
					'type'          => 'text',
					'id'            => 'portfolio_project_slug',
					'title'         => __( 'Portfolio project URL slug', 'topten-simple-theme-options' ),
					'description'  => __( 'Resave permalinks after changing slug.', 'topten-simple-theme-options' ),
					'default'       => '',
					'placeholder'   => 'portfolio',
				),
				array(
					'section_slug'  => 'layout-code-borders',
					'type'          => 'text',
					'id'            => 'portfolio_category_slug',
					'title'         => __( 'Portfolio category URL slug', 'topten-simple-theme-options' ),
					'description'  => __( 'Resave permalinks after changing slug.', 'topten-simple-theme-options' ),
					'default'       => '',
					'placeholder'   => 'portfolio-category',
				),
			)
		);

		BorderControl::register_many(
			array(
				array(
					'section_slug' => 'layout-code-borders',
					'id'           => 'layout_code_border_full',
					'title'        => __( 'Border (full)', 'topten-simple-theme-options' ),
					'description'  => __( 'Radius, style, width, and color with alpha.', 'topten-simple-theme-options' ),
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
					'section_slug' => 'layout-code-borders',
					'id'           => 'layout_code_border_simple',
					'title'        => __( 'Border (radius + style)', 'topten-simple-theme-options' ),
					'description'  => __( 'Border radius and style only.', 'topten-simple-theme-options' ),
					'default'      => array(
						'radius'      => '12',
						'radius_unit' => 'px',
						'style'       => 'dashed',
						'width'       => '1',
						'width_unit'  => 'px',
						'color'       => '#c3c4c7',
					),
					'features'     => array( 'radius', 'style' ),
				),
			)
		);

		CodeEditor::register(
			array(
				'section_slug' => 'layout-code-borders',
				'id'           => 'custom_css',
				'title'        => __( 'Code editor (auto-detect)', 'topten-simple-theme-options' ),
				'description'  => __( 'Auto-detect language; autocomplete enabled.', 'topten-simple-theme-options' ),
				'mode'         => 'auto',
				'height'       => 320,
				'autocomplete' => true,
				'placeholder'  => "/* Paste any CSS, JS, HTML, PHP, JSON or Markdown — the editor will detect the language. */\n.site-header {\n\tbackground-color: #fff;\n}",
				'default'      => '',
			)
		);

		CodeEditor::register(
			array(
				'section_slug' => 'layout-code-borders',
				'id'           => 'custom_header_html',
				'title'        => __( 'Header HTML', 'topten-simple-theme-options' ),
				'description'  => __( 'HTML for the head tag.', 'topten-simple-theme-options' ),
				'mode'         => 'html',
				'height'       => 220,
				'placeholder'  => "<!-- Google Tag Manager, verification meta, etc. -->",
				'default'      => '',
			)
		);

		CodeEditor::register(
			array(
				'section_slug' => 'layout-code-borders',
				'id'           => 'custom_footer_js',
				'title'        => __( 'Footer JavaScript', 'topten-simple-theme-options' ),
				'description'  => __( 'Footer JavaScript, output before body end.', 'topten-simple-theme-options' ),
				'mode'         => 'javascript',
				'height'       => 220,
				'placeholder'  => "// Custom analytics / chat-widget bootstrap",
				'default'      => '',
			)
		);

		Tabs::register(
			array(
				'section_slug'  => 'layout-tabs-side',
				'id'            => 'layout_cust_btn',
				'title'         => __( 'Button bar', 'topten-simple-theme-options' ),
				'description'  => __( 'Five tab slots share one template.', 'topten-simple-theme-options' ),
				'tabs'          => array(
					array( 'id' => 'b1', 'label' => __( 'Slot 1', 'topten-simple-theme-options' ) ),
					array( 'id' => 'b2', 'label' => __( 'Slot 2', 'topten-simple-theme-options' ) ),
					array( 'id' => 'b3', 'label' => __( 'Slot 3', 'topten-simple-theme-options' ) ),
					array( 'id' => 'b4', 'label' => __( 'Slot 4', 'topten-simple-theme-options' ) ),
					array( 'id' => 'b5', 'label' => __( 'Slot 5', 'topten-simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'        => 'text',
						'id'          => 'btn_url',
						'title'       => __( 'Link URL', 'topten-simple-theme-options' ),
						'default'     => '',
						'placeholder' => 'https://',
						'width'       => '1-3',
					),
					array(
						'type'    => 'text',
						'id'      => 'btn_text',
						'title'   => __( 'Label', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-3',
					),
					array(
						'type'        => 'text',
						'id'          => 'btn_icon',
						'title'       => __( 'Icon URL', 'topten-simple-theme-options' ),
						'description'  => __( 'Icon URL or attachment ID text.', 'topten-simple-theme-options' ),
						'default'     => '',
						'width'       => '1-3',
					),
				),
			)
		);
	}

	/**
	 * Append padding + margin dimension rows after each leaf field in a group `fields` list.
	 *
	 * @param list<array<string, mixed>> $fields
	 * @return list<array<string, mixed>>
	 */
	private static function flattenFieldsWithMarginPadding( array $fields ): array {
		$flattened = array();
		foreach ( $fields as $field_config ) {
			foreach ( self::fieldWithMarginPadding( $field_config ) as $field_row ) {
				$flattened[] = $field_row;
			}
		}

		return $flattened;
	}

	/**
	 * @param array<string, mixed> $field_config
	 * @return list<array<string, mixed>>
	 */
	private static function fieldWithMarginPadding( array $field_config ): array {
		if ( empty( $field_config['id'] ) || ( isset( $field_config['type'] ) && $field_config['type'] === 'dimension' ) ) {
			return array( $field_config );
		}

		$field_id    = sanitize_key( (string) $field_config['id'] );
		$field_title = isset( $field_config['title'] ) ? (string) $field_config['title'] : $field_id;

		return array_merge(
			array( $field_config ),
			self::marginPaddingPairForField( $field_id, $field_title )
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function marginPaddingPairForField( string $field_id, string $field_title ): array {
		return array(
			self::dimensionSpacingField( $field_id . '_padding', $field_title, 'padding' ),
			self::dimensionSpacingField( $field_id . '_margin', $field_title, 'margin' ),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function standaloneMarginPaddingPair( string $section_slug, string $field_id, string $field_title ): array {
		$pair = self::marginPaddingPairForField( $field_id, $field_title );
		foreach ( $pair as $index => $field_config ) {
			$pair[ $index ]['section_slug'] = $section_slug;
		}

		return $pair;
	}

	/**
	 * @param 'padding'|'margin' $spacing_kind
	 * @return array<string, mixed>
	 */
	private static function dimensionSpacingField( string $id, string $parent_title, string $spacing_kind ): array {
		$spacing_label = $spacing_kind === 'margin'
			? __( 'Margin', 'topten-simple-theme-options' )
			: __( 'Padding', 'topten-simple-theme-options' );

		return array(
			'type'    => 'dimension',
			'id'      => $id,
			'title'   => sprintf(
				/* translators: 1: parent field title, 2: Margin or Padding */
				__( '%1$s — %2$s', 'topten-simple-theme-options' ),
				$parent_title,
				$spacing_label
			),
			'units'   => array( 'px', 'rem', '%', 'em' ),
			'min'     => 0,
			'max'     => 200,
			'step'    => 1,
			'default' => array(
				'unit'   => 'px',
				'linked' => true,
				'values' => array(
					'top'    => '0',
					'right'  => '0',
					'bottom' => '0',
					'left'   => '0',
				),
			),
		);
	}
}
