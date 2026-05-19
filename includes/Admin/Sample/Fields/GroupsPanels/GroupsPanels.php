<?php
namespace SimpleThemeOptions\Admin\Sample\Fields\GroupsPanels;

use SimpleThemeOptions\Admin\Options\Fields\AdvancedRepeaterControl\AdvancedRepeaterControl;

use SimpleThemeOptions\Admin\Options\Fields\Accordion\Accordion;
use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\RichModernEditor\RichModernEditor;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\Group\Group;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\DateField\DateField;
use SimpleThemeOptions\Admin\Options\Fields\DateTimeField\DateTimeField;
use SimpleThemeOptions\Admin\Options\Fields\AlignmentControl\AlignmentControl;
use SimpleThemeOptions\Admin\Options\Fields\Dimension\Dimension;
use SimpleThemeOptions\Admin\Options\Fields\IconSelect\IconSelect;
use SimpleThemeOptions\Admin\Options\Fields\GalleryControl\GalleryControl;
use SimpleThemeOptions\Admin\Options\Fields\GoogleMapControl\GoogleMapControl;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\Tabs\Tabs;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * **Groups & panels** demo (`section_slug` **`groups-panels`**): Group, Advanced repeater, and Accordion field samples.
 */
final class GroupsPanels {
	use SingletonTrait;

	protected function init() {
		AdvancedRepeaterControl::instance();
		Group::instance();
		Tabs::instance();
		Accordion::instance();
		Typography::instance();
		Color::instance();
		BackgroundControl::instance();
		BorderControl::instance();
		LinkColor::instance();
		ImageSelect::instance();
		ButtonGroup::instance();
		CodeEditor::instance();
		RichModernEditor::instance();
		DynamicObject::instance();
		Select::instance();
		Input::instance();
		Range::instance();
		DateField::instance();
		DateTimeField::instance();
		Dimension::instance();
		IconSelect::instance();
		GalleryControl::instance();
		GoogleMapControl::instance();
		AlignmentControl::instance();
		Switcher::instance();
		add_action( 'sto_render_section_content', array( $this, 'render_section_intro' ), 5, 2 );
		$this->register_fields();
	}

	/**
	 * @param string               $section_slug
	 * @param array<string, mixed> $section
	 */
	public function render_section_intro( $section_slug, $section ) {
		if ( sanitize_key( (string) $section_slug ) !== 'groups-panels' ) {
			return;
		}
		?>
		<div class="sto-acc-demo-intro sto-groups-panels-intro">
			<p class="sto-acc-demo-intro__kicker"><?php esc_html_e( 'Group · Advanced repeater · Accordion', 'topten-simple-theme-options' ); ?></p>
			<p class="sto-acc-demo-intro__lead">
				<?php esc_html_e( 'Premium structure fields on one screen: boxed groups, drag-and-drop repeaters, and collapsible accordions. Click a numbered card to jump to each demo.', 'topten-simple-theme-options' ); ?>
			</p>
			<div class="sto-acc-demo-types" role="group" aria-label="<?php esc_attr_e( 'Groups and panels demo types', 'topten-simple-theme-options' ); ?>">
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-field-advanced-repeater-layout_sample_advanced_repeater"
					aria-label="<?php esc_attr_e( 'Scroll to the advanced repeater demo', 'topten-simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">1</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Advanced repeater', 'topten-simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Drag-and-drop rows with nested repeaters and fieldsets.', 'topten-simple-theme-options' ); ?></p>
					</div>
				</div>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-group-border_controls"
					aria-label="<?php esc_attr_e( 'Scroll to the border presets group demo', 'topten-simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">2</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Group (border)', 'topten-simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Boxed panel wrapping sibling border controls.', 'topten-simple-theme-options' ); ?></p>
					</div>
				</div>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-group-sidebar"
					aria-label="<?php esc_attr_e( 'Scroll to the sidebar layout group demo', 'topten-simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">3</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Group (conditional)', 'topten-simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Image select, selects, switcher, and typography with required maps.', 'topten-simple-theme-options' ); ?></p>
					</div>
				</div>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-field-accordion-acc_basic"
					aria-label="<?php esc_attr_e( 'Scroll to the standard accordion demo', 'topten-simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">4</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Accordion — standard', 'topten-simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Classic vertical panels; optional default-open panel; collapse by clicking the active header again.', 'topten-simple-theme-options' ); ?></p>
					</div>
				</div>
				<a
					class="sto-acc-demo-type sto-acc-demo-type--link"
					href="<?php echo esc_url( admin_url( 'admin.php?page=theme-settings&section=responsive#sto-field-accordion-acc_resp' ) ); ?>"
					aria-label="<?php esc_attr_e( 'Open the Responsive section (responsive accordion demo)', 'topten-simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">5</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Accordion — responsive', 'topten-simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Device tabs on the title row; demo lives on the Responsive section.', 'topten-simple-theme-options' ); ?></p>
					</div>
				</a>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-field-accordion-acc_adv"
					aria-label="<?php esc_attr_e( 'Scroll to the deep nesting accordion demo', 'topten-simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">6</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Accordion — deep nesting', 'topten-simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Tabs, nested group, and a second-level accordion inside one accordion panel.', 'topten-simple-theme-options' ); ?></p>
					</div>
				</div>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-field-accordion-acc_full"
					aria-label="<?php esc_attr_e( 'Scroll to the full inner-type palette demo', 'topten-simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">7</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Accordion — full palette', 'topten-simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'One panel with every inner field type the accordion supports (inputs, selects, media, color, border, typography, …).', 'topten-simple-theme-options' ); ?></p>
					</div>
				</div>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-group-acc_host_group"
					aria-label="<?php esc_attr_e( 'Scroll to the accordion inside a group demo', 'topten-simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">8</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Accordion inside a group', 'topten-simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Same API beside other group-level controls — use when the accordion is one row among siblings.', 'topten-simple-theme-options' ); ?></p>
					</div>
				</div>
			</div>
			<ul class="sto-acc-demo-intro__list">
				<li><?php esc_html_e( 'Each numbered card jumps to the matching block below (smooth scroll + one-time highlight).', 'topten-simple-theme-options' ); ?></li>
				<li><?php esc_html_e( 'Inner layouts use the shared 12-column grid (stacks on narrow admin widths).', 'topten-simple-theme-options' ); ?></li>
			</ul>
		</div>
		<?php
	}

	public function register_fields() {
		AdvancedRepeaterControl::register(
			array(
				'section_slug'  => 'groups-panels',
				'id'            => 'layout_sample_advanced_repeater',
				'title'         => __( 'Advanced repeater', 'topten-simple-theme-options' ),
				'max'           => 12,
				'default'       => array(
					array(
						'adv_block'    => array(
							'adv_title'   => __( 'First block', 'topten-simple-theme-options' ),
							'adv_qty'     => '2',
							'adv_summary' => __( 'Demo summary for this top-level item.', 'topten-simple-theme-options' ),
							'adv_tier'    => 'standard',
							'adv_feature' => '0',
						),
						'adv_subitems' => array(
							array(
								'adv_note'  => __( 'Nested line A (level 1 note).', 'topten-simple-theme-options' ),
								'adv_cells' => array(
									array(
										'adv_cell_label' => __( 'Level-2 row', 'topten-simple-theme-options' ),
										'adv_cell_mode'  => 'mode_standard',
										'adv_cell_on'    => '1',
									),
								),
							),
						),
					),
				),
				'fields'        => array(
					array(
						'type'   => 'fieldset',
						'id'     => 'adv_block',
						'title'  => __( 'Primary fields (fieldset)', 'topten-simple-theme-options' ),
						'fields' => array(
							array(
								'type'          => 'text',
								'id'            => 'adv_title',
								'title'         => __( 'Block title', 'topten-simple-theme-options' ),
								'default'       => '',
								'html_required' => true,
							),
							array(
								'type'    => 'number',
								'id'      => 'adv_qty',
								'title'   => __( 'Quantity', 'topten-simple-theme-options' ),
								'default' => '1',
								'min'     => '1',
								'max'     => '99',
								'step'    => '1',
							),
							array(
								'type'        => 'textarea',
								'id'          => 'adv_summary',
								'title'       => __( 'Summary', 'topten-simple-theme-options' ),
								'description' => __( 'Longer copy inside the fieldset.', 'topten-simple-theme-options' ),
								'default'     => '',
								'placeholder' => __( 'Optional summary…', 'topten-simple-theme-options' ),
							),
							array(
								'type'        => 'select',
								'id'          => 'adv_tier',
								'title'       => __( 'Tier (select)', 'topten-simple-theme-options' ),
								'placeholder' => __( 'Choose…', 'topten-simple-theme-options' ),
								'default'     => 'standard',
								'options'     => array(
									'standard' => __( 'Standard', 'topten-simple-theme-options' ),
									'premium'  => __( 'Premium', 'topten-simple-theme-options' ),
								),
							),
							array(
								'type'        => 'switcher',
								'id'          => 'adv_feature',
								'title'       => __( 'Feature toggle (switcher)', 'topten-simple-theme-options' ),
								'description' => __( 'Example switcher inside the fieldset.', 'topten-simple-theme-options' ),
								'default'     => '0',
							),
						),
					),
					array(
						'type'    => 'advanced_repeater',
						'id'      => 'adv_subitems',
						'title'   => __( 'Nested repeater (level 1)', 'topten-simple-theme-options' ),
						'max'     => 8,
						'default' => array(
							array(
								'adv_note'  => '',
								'adv_cells' => array(
									array(
										'adv_cell_label' => '',
										'adv_cell_mode'  => 'mode_standard',
										'adv_cell_on'    => '0',
									),
								),
							),
						),
						'fields'  => array(
							array(
								'type'    => 'textarea',
								'id'      => 'adv_note',
								'title'   => __( 'Note (level 1)', 'topten-simple-theme-options' ),
								'default' => '',
							),
							array(
								'type'    => 'advanced_repeater',
								'id'      => 'adv_cells',
								'title'   => __( 'Nested repeater (level 2)', 'topten-simple-theme-options' ),
								'max'     => 6,
								'default' => array(
									array(
										'adv_cell_label' => '',
										'adv_cell_mode'  => 'mode_standard',
										'adv_cell_on'    => '0',
									),
								),
								'fields'  => array(
									array(
										'type'    => 'text',
										'id'      => 'adv_cell_label',
										'title'   => __( 'Label (text)', 'topten-simple-theme-options' ),
										'default' => '',
									),
									array(
										'type'    => 'select',
										'id'      => 'adv_cell_mode',
										'title'   => __( 'Mode (select)', 'topten-simple-theme-options' ),
										'default' => 'mode_standard',
										'options' => array(
											'mode_standard' => __( 'Standard', 'topten-simple-theme-options' ),
											'mode_alt'      => __( 'Alternate', 'topten-simple-theme-options' ),
										),
									),
									array(
										'type'    => 'switcher',
										'id'      => 'adv_cell_on',
										'title'   => __( 'Inner toggle (level 2)', 'topten-simple-theme-options' ),
										'default' => '0',
									),
								),
							),
						),
					),
				),
			)
		);

		Group::register(
			array(
				'section_slug' => 'groups-panels',
				'id'           => 'border_controls',
				'title'        => __( 'Border presets', 'topten-simple-theme-options' ),
				'description'  => __( 'Full border versus radius and style.', 'topten-simple-theme-options' ),
				'fields'       => array(
					array(
						'type'         => 'border',
						'id'           => 'card_border',
						'title'        => __( 'Card outline (full)', 'topten-simple-theme-options' ),
						'description'  => __( 'Radius (px / %), style, width, and color with alpha.', 'topten-simple-theme-options' ),
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
						'title'       => __( 'Card outline (radius + style)', 'topten-simple-theme-options' ),
						'description'  => __( 'Border radius and style only.', 'topten-simple-theme-options' ),
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
				'section_slug' => 'groups-panels',
				'id'           => 'sidebar',
				'title'        => __( 'Sidebar layout', 'topten-simple-theme-options' ),
				'required'     => array(
					'layout_header' => 'default_header_layout',
				),
				'fields'       => array(
					array(
						'type'          => 'image_select',
						'id'            => 'sidebar_position',
						'title'         => __( 'Position', 'topten-simple-theme-options' ),
						'description'  => __( 'Image tiles with responsive columns.', 'topten-simple-theme-options' ),
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
								'label' => __( 'No sidebar', 'topten-simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-sidebar-none/400/170',
							),
							'left'  => array(
								'label' => __( 'Left sidebar', 'topten-simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-sidebar-left/400/170',
							),
							'right' => array(
								'label' => __( 'Right sidebar', 'topten-simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-sidebar-right/400/170',
							),
						),
					),
					array(
						'id'          => 'sidebar_size',
						'title'       => __( 'Width preset', 'topten-simple-theme-options' ),
						'description' => __( 'Visible when the sidebar sits on the right.', 'topten-simple-theme-options' ),
						'default'     => 'medium',
						'options'     => array(
							'small'  => __( 'Small', 'topten-simple-theme-options' ),
							'medium' => __( 'Medium', 'topten-simple-theme-options' ),
							'large'  => __( 'Large', 'topten-simple-theme-options' ),
						),
						'required'     => array(
							'sidebar_position' => 'right',
						),
					),
					array(
						'type'          => 'switcher',
						'id'            => 'sidebar_off_canvas_mobile',
						'title'         => __( 'Off canvas sidebar for mobile', 'topten-simple-theme-options' ),
						'description'  => __( 'Off-canvas sidebar on mobile.', 'topten-simple-theme-options' ),
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
						'title'       => __( 'Sidebar typography', 'topten-simple-theme-options' ),
						'description' => __( 'Example typography control inside a nested group.', 'topten-simple-theme-options' ),
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
						'id'          => 'sidebar_sticky',
						'title'       => __( 'Sticky sidebar', 'topten-simple-theme-options' ),
						'description'  => __( 'When sidebar is right and large.', 'topten-simple-theme-options' ),
						'default'     => 'no',
						'options'     => array(
							'no'  => __( 'No', 'topten-simple-theme-options' ),
							'yes' => __( 'Yes', 'topten-simple-theme-options' ),
						),
						'required'    => array(
							array(
								'sidebar_position' => 'right',
								'sidebar_size'     => 'large',
							),
						),
					),
				),
			)
		);

		Accordion::register(
			array(
				'section_slug'  => 'groups-panels',
				'id'            => 'acc_basic',
				'title'         => __( 'Standard accordion', 'topten-simple-theme-options' ),
				'description'  => __( 'Collapsible panels with twelve-column grid.', 'topten-simple-theme-options' ),
				'wrapper_class' => 'sto-acc-demo-block',
				'panels'        => array(
					array(
						'id'       => 'gen',
						'label'    => __( 'General settings', 'topten-simple-theme-options' ),
						'expanded' => true,
					),
					array( 'id' => 'adv', 'label' => __( 'Advanced options', 'topten-simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'    => 'text',
						'id'      => 'ab_title',
						'title'   => __( 'Block title', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-2',
					),
					array(
						'type'    => 'switcher',
						'id'      => 'ab_active',
						'title'   => __( 'Enable section', 'topten-simple-theme-options' ),
						'default' => '1',
						'width'   => '1-2',
					),
					array(
						'id'          => 'ab_note',
						'title'       => __( 'Notes', 'topten-simple-theme-options' ),
						'description' => __( 'Optional helper copy under the control.', 'topten-simple-theme-options' ),
						'type'        => 'textarea',
						'default'     => '',
						'rows'        => 3,
						'width'       => '1-1',
					),
					array(
						'id'          => 'ab_align',
						'title'       => __( 'Text align', 'topten-simple-theme-options' ),
						'default'     => 'left',
						'width'       => '1-3',
						'options'     => array(
							'left'   => __( 'Left', 'topten-simple-theme-options' ),
							'center' => __( 'Center', 'topten-simple-theme-options' ),
							'right'  => __( 'Right', 'topten-simple-theme-options' ),
						),
					),
					array(
						'type'    => 'number',
						'id'      => 'ab_z',
						'title'   => __( 'Stacking order', 'topten-simple-theme-options' ),
						'default' => '0',
						'min'     => 0,
						'max'     => 999,
						'width'   => '1-3',
					),
					array(
						'type'    => 'switcher',
						'id'      => 'ab_shadow',
						'title'   => __( 'Drop shadow', 'topten-simple-theme-options' ),
						'default' => '0',
						'width'   => '1-3',
					),
				),
			)
		);

		Accordion::register(
			array(
				'section_slug'  => 'groups-panels',
				'id'            => 'acc_adv',
				'title'         => __( 'Deep nesting (tabs · group · accordion)', 'topten-simple-theme-options' ),
				'wrapper_class' => 'sto-acc-demo-block',
				'panels'        => array(
					array( 'id' => 'main', 'label' => __( 'Canvas', 'topten-simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'          => 'tabs',
						'id'            => 'adv_tabs',
						'title'         => __( 'Horizontal tabs', 'topten-simple-theme-options' ),
						'description'  => __( 'Per-tab fields with full-width rows.', 'topten-simple-theme-options' ),
						'width'         => '1-1',
						'tabs'          => array(
							array( 'id' => 'a', 'label' => __( 'Tab — grouped content', 'topten-simple-theme-options' ) ),
							array( 'id' => 'b', 'label' => __( 'Tab — simple field', 'topten-simple-theme-options' ) ),
						),
						'fields'        => array(
							array(
								'id'          => 'adv_sub',
								'title'       => __( 'Settings panel (nested group)', 'topten-simple-theme-options' ),
								'description'  => __( 'Nested group with inner card.', 'topten-simple-theme-options' ),
								'width'       => '1-1',
								'fields'      => array(
									array(
										'type'    => 'switcher',
										'id'      => 'adv_grid',
										'title'   => __( 'Use CSS grid', 'topten-simple-theme-options' ),
										'default' => '1',
										'width'   => '1-2',
									),
									array(
										'type'    => 'range',
										'id'      => 'adv_gap',
										'title'   => __( 'Gap', 'topten-simple-theme-options' ),
										'default' => array( 'value' => 16, 'unit' => 'px' ),
										'min'     => 0,
										'max'     => 64,
										'step'    => 1,
										'units'   => array( 'px' ),
										'width'   => '1-2',
									),
									array(
										'type'    => 'text',
										'id'      => 'adv_note',
										'title'   => __( 'Note', 'topten-simple-theme-options' ),
										'default' => '',
										'width'   => '1-1',
									),
								),
							),
							array(
								'type'    => 'text',
								'id'      => 'adv_tab_b',
								'title'   => __( 'Single-line value', 'topten-simple-theme-options' ),
								'default' => '',
								'width'   => '1-1',
							),
						),
					),
					array(
						'type'          => 'accordion',
						'id'            => 'adv_nacc',
						'title'         => __( 'Nested accordion', 'topten-simple-theme-options' ),
						'description'  => __( 'Nested accordion inside tabs.', 'topten-simple-theme-options' ),
						'width'         => '1-1',
						'panels'        => array(
							array( 'id' => 'x1', 'label' => __( 'Section A', 'topten-simple-theme-options' ) ),
							array( 'id' => 'x2', 'label' => __( 'Section B', 'topten-simple-theme-options' ) ),
						),
						'fields'        => array(
							array(
								'type'    => 'text',
								'id'      => 'adv_n_label',
								'title'   => __( 'Label', 'topten-simple-theme-options' ),
								'default' => '',
								'width'   => '1-1',
							),
						),
					),
				),
			)
		);

		Accordion::register(
			array(
				'section_slug'  => 'groups-panels',
				'id'            => 'acc_full',
				'title'         => __( 'Complete inner-type palette', 'topten-simple-theme-options' ),
				'description'  => __( 'Every inner field type in one panel.', 'topten-simple-theme-options' ),
				'wrapper_class' => 'sto-acc-demo-block',
				'panels'        => array(
					array( 'id' => 'all', 'label' => __( 'All controls', 'topten-simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'    => 'text',
						'id'      => 'cf_text',
						'title'   => __( 'Text', 'topten-simple-theme-options' ),
						'default' => '',
						'width'   => '1-2',
					),
					array(
						'type'    => 'textarea',
						'id'      => 'cf_area',
						'title'   => __( 'Textarea', 'topten-simple-theme-options' ),
						'default' => '',
						'rows'    => 2,
						'width'   => '1-2',
					),
					array(
						'type'    => 'number',
						'id'      => 'cf_num',
						'title'   => __( 'Number', 'topten-simple-theme-options' ),
						'default' => '10',
						'min'     => 0,
						'max'     => 100,
						'width'   => '1-3',
					),
					array(
						'type'    => 'switcher',
						'id'      => 'cf_sw',
						'title'   => __( 'Switcher', 'topten-simple-theme-options' ),
						'default' => '1',
						'width'   => '1-3',
					),
					array(
						'type'        => 'select',
						'id'          => 'cf_sel',
						'title'       => __( 'Select', 'topten-simple-theme-options' ),
						'default'     => 'b',
						'width'       => '1-3',
						'options'     => array(
							'a' => __( 'Option A', 'topten-simple-theme-options' ),
							'b' => __( 'Option B', 'topten-simple-theme-options' ),
							'c' => __( 'Option C', 'topten-simple-theme-options' ),
						),
					),
					array(
						'type'    => 'range',
						'id'      => 'cf_range',
						'title'   => __( 'Range', 'topten-simple-theme-options' ),
						'default' => array( 'value' => 50, 'unit' => 'px' ),
						'min'     => 0,
						'max'     => 200,
						'step'    => 1,
						'units'   => array( 'px' ),
						'width'   => '1-2',
					),
					array(
						'type'          => 'button_group',
						'id'            => 'cf_bg',
						'title'         => __( 'Button group', 'topten-simple-theme-options' ),
						'default'       => 'm',
						'width'         => '1-2',
						'options'       => array(
							's' => __( 'S', 'topten-simple-theme-options' ),
							'm' => __( 'M', 'topten-simple-theme-options' ),
							'l' => __( 'L', 'topten-simple-theme-options' ),
						),
					),
					array(
						'type'          => 'image_select',
						'id'            => 'cf_img',
						'title'         => __( 'Image select', 'topten-simple-theme-options' ),
						'default'       => 'boxed',
						'width'         => '1-1',
						'options'       => array(
							'wide'  => array(
								'label' => __( 'Wide', 'topten-simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-acc-wide/400/170',
							),
							'boxed' => array(
								'label' => __( 'Boxed', 'topten-simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-acc-boxed/400/170',
							),
						),
					),
					array(
						'type'        => 'code_editor',
						'id'          => 'cf_code',
						'title'       => __( 'Code editor', 'topten-simple-theme-options' ),
						'description' => __( 'HTML mode sample; stored with field sanitization.', 'topten-simple-theme-options' ),
						'mode'        => 'html',
						'height'      => 160,
						'placeholder' => '<!-- -->',
						'width'       => '1-1',
					),
					array(
						'type'          => 'rich_modern_editor',
						'id'            => 'cf_rich_modern',
						'title'         => __( 'Rich modern editor', 'topten-simple-theme-options' ),
						'description'   => __( 'Gutenberg block editor inside a group panel.', 'topten-simple-theme-options' ),
						'editor_height' => 280,
						'width'         => '1-1',
					),
					array(
						'type'        => 'dynamic_object',
						'id'          => 'cf_page',
						'title'       => __( 'Dynamic object (pages)', 'topten-simple-theme-options' ),
						'description' => __( 'AJAX search after 3+ characters.', 'topten-simple-theme-options' ),
						'post_type'   => 'page',
						'placeholder' => __( 'Search pages…', 'topten-simple-theme-options' ),
						'width'       => '1-1',
					),
					array(
						'type'        => 'typography',
						'id'          => 'cf_typo',
						'title'       => __( 'Typography', 'topten-simple-theme-options' ),
						'description' => __( 'Composite font control.', 'topten-simple-theme-options' ),
						'default'     => array(
							'family'    => 'Open Sans',
							'variant'   => 'regular',
							'subset'    => 'latin',
							'transform' => 'none',
						),
						'width'       => '1-1',
					),
					array(
						'type'        => 'color',
						'id'          => 'cf_color',
						'title'       => __( 'Color', 'topten-simple-theme-options' ),
						'default'     => '#2271b1',
						'width'       => '1-2',
					),
					array(
						'type'        => 'background_control',
						'id'          => 'cf_bgctl',
						'title'       => __( 'Background control', 'topten-simple-theme-options' ),
						'default'     => array(
							'color'    => '#f0f0f1',
							'image_id' => '',
						),
						'alpha'       => true,
						'width'       => '1-2',
					),
					array(
						'type'        => 'border',
						'id'          => 'cf_border',
						'title'       => __( 'Border', 'topten-simple-theme-options' ),
						'default'     => array(
							'radius'      => '8',
							'radius_unit' => 'px',
							'style'       => 'solid',
							'width'       => '1',
							'width_unit'  => 'px',
							'color'       => '#dcdcde',
						),
						'radius_units' => array( 'px' ),
						'width'       => '1-1',
					),
					array(
						'type'        => 'gallery',
						'id'          => 'cf_gallery',
						'title'       => __( 'Gallery', 'topten-simple-theme-options' ),
						'description'  => __( 'Gallery attachment IDs array.', 'topten-simple-theme-options' ),
						'default'     => array(
							101,
							102,
							103,
						),
						'max'         => 0,
						'width'       => '1-1',
					),
					array(
						'type'        => 'google_map',
						'id'          => 'cf_google_map',
						'title'       => __( 'Google map', 'topten-simple-theme-options' ),
						'description'  => __( 'Map field JSON in sto_options.', 'topten-simple-theme-options' ),
						'default'     => array(
							'city'    => 'Washington',
							'country' => 'United States',
							'lat'     => '38.9072',
							'lng'     => '-77.0369',
						),
						'width'       => '1-1',
					),
					array(
						'type'        => 'link_color',
						'id'          => 'cf_link',
						'title'       => __( 'Link color', 'topten-simple-theme-options' ),
						'default'     => array(
							'regular' => '#2271b1',
							'hover'   => '#135e96',
						),
						'labels'      => array(
							'regular' => __( 'Regular', 'topten-simple-theme-options' ),
							'hover'   => __( 'Hover', 'topten-simple-theme-options' ),
						),
						'alpha'       => true,
						'width'       => '1-1',
					),
				),
			)
		);

		Group::register(
			array(
				'section_slug'  => 'groups-panels',
				'id'            => 'acc_host_group',
				'title'         => __( 'Accordion inside a group', 'topten-simple-theme-options' ),
				'description'  => __( 'Accordion nested inside a group.', 'topten-simple-theme-options' ),
				'fields'        => array(
					array(
						'type'          => 'accordion',
						'id'            => 'acc_hosted',
						'title'         => __( 'Grouped accordion row', 'topten-simple-theme-options' ),
						'description'   => __( 'Same API as standalone; Group supplies the inner context.', 'topten-simple-theme-options' ),
						'panels'        => array(
							array( 'id' => 'h1', 'label' => __( 'Content', 'topten-simple-theme-options' ) ),
							array( 'id' => 'h2', 'label' => __( 'SEO', 'topten-simple-theme-options' ) ),
						),
						'fields'        => array(
							array(
								'type'    => 'text',
								'id'      => 'hg_title',
								'title'   => __( 'Title', 'topten-simple-theme-options' ),
								'default' => '',
								'width'   => '1-2',
							),
							array(
								'type'    => 'switcher',
								'id'      => 'hg_on',
								'title'   => __( 'Published', 'topten-simple-theme-options' ),
								'default' => '1',
								'width'   => '1-2',
							),
						),
					),
				),
			)
		);
	}
}
