<?php
namespace SimpleThemeOptions\Admin\Sample\Fields\Accordion;

use SimpleThemeOptions\Admin\Options\Fields\Accordion\Accordion;
use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\Group\Group;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\DateField\DateField;
use SimpleThemeOptions\Admin\Options\Fields\DateTimeField\DateTimeField;
use SimpleThemeOptions\Admin\Options\Fields\AlignmentControl\AlignmentControl;
use SimpleThemeOptions\Admin\Options\Fields\Dimension\Dimension;
use SimpleThemeOptions\Admin\Options\Fields\GalleryControl\GalleryControl;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\Tabs\Tabs;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * **Accordion** Theme Settings panel (`section_slug` **`accordion`**): every accordion demo in one screen — standard, responsive, nesting, full inner palette, and accordion inside a group.
 */
final class AccordionDemo {
	use SingletonTrait;

	protected function init() {
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
		DynamicObject::instance();
		Select::instance();
		Input::instance();
		Range::instance();
		DateField::instance();
		DateTimeField::instance();
		Dimension::instance();
		GalleryControl::instance();
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
		if ( sanitize_key( (string) $section_slug ) !== 'accordion' ) {
			return;
		}
		?>
		<div class="sto-acc-demo-intro">
			<p class="sto-acc-demo-intro__kicker"><?php esc_html_e( 'Accordion field', 'simple-theme-options' ); ?></p>
			<p class="sto-acc-demo-intro__lead">
				<?php esc_html_e( 'All sample patterns live on this one page: compare behaviors side by side. Click a numbered card to scroll to that demo; the row flashes once so you spot the destination (same cue as quick search).', 'simple-theme-options' ); ?>
			</p>
			<div class="sto-acc-demo-types" role="group" aria-label="<?php esc_attr_e( 'Accordion demo types', 'simple-theme-options' ); ?>">
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-field-accordion-acc_basic"
					aria-label="<?php esc_attr_e( 'Scroll to the standard accordion demo', 'simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">1</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Standard', 'simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Classic vertical panels; optional default-open panel; collapse by clicking the active header again.', 'simple-theme-options' ); ?></p>
					</div>
				</div>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-field-accordion-acc_resp"
					aria-label="<?php esc_attr_e( 'Scroll to the responsive accordion demo', 'simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">2</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Responsive', 'simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Master strip in the title row keeps the same logical panel across breakpoints; inner fields can still store per-device values.', 'simple-theme-options' ); ?></p>
					</div>
				</div>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-field-accordion-acc_adv"
					aria-label="<?php esc_attr_e( 'Scroll to the deep nesting accordion demo', 'simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">3</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Deep nesting', 'simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Tabs, nested group, and a second-level accordion inside one accordion panel.', 'simple-theme-options' ); ?></p>
					</div>
				</div>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-field-accordion-acc_full"
					aria-label="<?php esc_attr_e( 'Scroll to the full inner-type palette demo', 'simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">4</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Full palette', 'simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'One panel with every inner field type the accordion supports (inputs, selects, media, color, border, typography, …).', 'simple-theme-options' ); ?></p>
					</div>
				</div>
				<div
					class="sto-acc-demo-type"
					role="button"
					tabindex="0"
					data-sto-jump-target="#sto-group-acc_host_group"
					aria-label="<?php esc_attr_e( 'Scroll to the accordion inside a group demo', 'simple-theme-options' ); ?>"
				>
					<span class="sto-acc-demo-type__index" aria-hidden="true">5</span>
					<div class="sto-acc-demo-type__body">
						<h3 class="sto-acc-demo-type__title"><?php esc_html_e( 'Inside a group', 'simple-theme-options' ); ?></h3>
						<p class="sto-acc-demo-type__text"><?php esc_html_e( 'Same API beside other group-level controls — use when the accordion is one row among siblings.', 'simple-theme-options' ); ?></p>
					</div>
				</div>
			</div>
			<ul class="sto-acc-demo-intro__list">
				<li><?php esc_html_e( 'Each numbered card jumps to the matching block below (smooth scroll + one-time highlight).', 'simple-theme-options' ); ?></li>
				<li><?php esc_html_e( 'Inner layouts use the shared 12-column grid (stacks on narrow admin widths).', 'simple-theme-options' ); ?></li>
			</ul>
		</div>
		<?php
	}

	public function register_fields() {
		Accordion::register(
			array(
				'section_slug'  => 'accordion',
				'id'            => 'acc_basic',
				'title'         => __( 'Standard accordion', 'simple-theme-options' ),
				'description'   => __( 'Panels start collapsed unless you set expanded, show, or open on a panel. Click the open header again to collapse. Inner widths use the shared 12-column grammar.', 'simple-theme-options' ),
				'wrapper_class' => 'sto-acc-demo-block',
				'panels'        => array(
					array(
						'id'       => 'gen',
						'label'    => __( 'General settings', 'simple-theme-options' ),
						'expanded' => true,
					),
					array( 'id' => 'adv', 'label' => __( 'Advanced options', 'simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'    => 'text',
						'id'      => 'ab_title',
						'title'   => __( 'Block title', 'simple-theme-options' ),
						'default' => '',
						'width'   => '1-2',
					),
					array(
						'type'    => 'switcher',
						'id'      => 'ab_active',
						'title'   => __( 'Enable section', 'simple-theme-options' ),
						'default' => '1',
						'width'   => '1-2',
					),
					array(
						'id'          => 'ab_note',
						'title'       => __( 'Notes', 'simple-theme-options' ),
						'description' => __( 'Optional helper copy under the control.', 'simple-theme-options' ),
						'type'        => 'textarea',
						'default'     => '',
						'rows'        => 3,
						'width'       => '1-1',
					),
					array(
						'id'          => 'ab_align',
						'title'       => __( 'Text align', 'simple-theme-options' ),
						'default'     => 'left',
						'width'       => '1-3',
						'options'     => array(
							'left'   => __( 'Left', 'simple-theme-options' ),
							'center' => __( 'Center', 'simple-theme-options' ),
							'right'  => __( 'Right', 'simple-theme-options' ),
						),
					),
					array(
						'type'    => 'number',
						'id'      => 'ab_z',
						'title'   => __( 'Stacking order', 'simple-theme-options' ),
						'default' => '0',
						'min'     => 0,
						'max'     => 999,
						'width'   => '1-3',
					),
					array(
						'type'    => 'switcher',
						'id'      => 'ab_shadow',
						'title'   => __( 'Drop shadow', 'simple-theme-options' ),
						'default' => '0',
						'width'   => '1-3',
					),
				),
			)
		);

		Accordion::register(
			array(
				'section_slug'  => 'accordion',
				'id'            => 'acc_resp',
				'title'         => __( 'Responsive accordion', 'simple-theme-options' ),
				'description'   => __( 'Master tabs in the title row keep the same logical panel across breakpoints; inner fields can still define their own responsive storage.', 'simple-theme-options' ),
				'wrapper_class' => 'sto-acc-demo-block',
				'responsive'    => true,
				'device'        => array( 'xxl', 'md', 'mobile' ),
				'panels'        => array(
					array( 'id' => 'desk', 'label' => __( 'Desktop', 'simple-theme-options' ) ),
					array( 'id' => 'mob', 'label' => __( 'Mobile', 'simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'    => 'text',
						'id'      => 'ar_label',
						'title'   => __( 'Breakpoint label', 'simple-theme-options' ),
						'default' => '',
						'width'   => '1-1',
					),
					array(
						'type'       => 'range',
						'id'         => 'ar_pad',
						'title'      => __( 'Outer padding', 'simple-theme-options' ),
						'default'    => array( 'v' => 24, 'u' => 'px' ),
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
						'title'       => __( 'Color scheme', 'simple-theme-options' ),
						'default'     => 'light',
						'width'       => '1-2',
						'options'     => array(
							'light' => __( 'Light', 'simple-theme-options' ),
							'dark'  => __( 'Dark', 'simple-theme-options' ),
						),
					),
				),
			)
		);

		Accordion::register(
			array(
				'section_slug'  => 'accordion',
				'id'            => 'acc_adv',
				'title'         => __( 'Deep nesting (tabs · group · accordion)', 'simple-theme-options' ),
				'description'   => __( 'Accordion → horizontal tabs → nested group and a sibling nested accordion.', 'simple-theme-options' ),
				'wrapper_class' => 'sto-acc-demo-block',
				'panels'        => array(
					array( 'id' => 'main', 'label' => __( 'Canvas', 'simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'          => 'tabs',
						'id'            => 'adv_tabs',
						'title'         => __( 'Horizontal tabs', 'simple-theme-options' ),
						'description'   => __( 'The same inner field list is repeated in every tab with per-tab scoped ids. Full-width rows use span-12 so nested groups are not squeezed.', 'simple-theme-options' ),
						'width'         => '1-1',
						'tabs'          => array(
							array( 'id' => 'a', 'label' => __( 'Tab — grouped content', 'simple-theme-options' ) ),
							array( 'id' => 'b', 'label' => __( 'Tab — simple field', 'simple-theme-options' ) ),
						),
						'fields'        => array(
							array(
								'id'          => 'adv_sub',
								'title'       => __( 'Settings panel (nested group)', 'simple-theme-options' ),
								'description' => __( 'Nested group with its own heading, description, and 12-column inner card.', 'simple-theme-options' ),
								'width'       => '1-1',
								'fields'      => array(
									array(
										'type'    => 'switcher',
										'id'      => 'adv_grid',
										'title'   => __( 'Use CSS grid', 'simple-theme-options' ),
										'default' => '1',
										'width'   => '1-2',
									),
									array(
										'type'    => 'range',
										'id'      => 'adv_gap',
										'title'   => __( 'Gap', 'simple-theme-options' ),
										'default' => array( 'v' => 16, 'u' => 'px' ),
										'min'     => 0,
										'max'     => 64,
										'step'    => 1,
										'units'   => array( 'px' ),
										'width'   => '1-2',
									),
									array(
										'type'    => 'text',
										'id'      => 'adv_note',
										'title'   => __( 'Note', 'simple-theme-options' ),
										'default' => '',
										'width'   => '1-1',
									),
								),
							),
							array(
								'type'    => 'text',
								'id'      => 'adv_tab_b',
								'title'   => __( 'Single-line value', 'simple-theme-options' ),
								'default' => '',
								'width'   => '1-1',
							),
						),
					),
					array(
						'type'          => 'accordion',
						'id'            => 'adv_nacc',
						'title'         => __( 'Nested accordion', 'simple-theme-options' ),
						'description'   => __( 'Second-level accordion; composite ids include this block and each panel.', 'simple-theme-options' ),
						'width'         => '1-1',
						'panels'        => array(
							array( 'id' => 'x1', 'label' => __( 'Section A', 'simple-theme-options' ) ),
							array( 'id' => 'x2', 'label' => __( 'Section B', 'simple-theme-options' ) ),
						),
						'fields'        => array(
							array(
								'type'    => 'text',
								'id'      => 'adv_n_label',
								'title'   => __( 'Label', 'simple-theme-options' ),
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
				'section_slug'  => 'accordion',
				'id'            => 'acc_full',
				'title'         => __( 'Complete inner-type palette', 'simple-theme-options' ),
				'description'   => __( 'One panel showcasing every field type supported inside an accordion tree.', 'simple-theme-options' ),
				'wrapper_class' => 'sto-acc-demo-block',
				'panels'        => array(
					array( 'id' => 'all', 'label' => __( 'All controls', 'simple-theme-options' ) ),
				),
				'fields'        => array(
					array(
						'type'    => 'text',
						'id'      => 'cf_text',
						'title'   => __( 'Text', 'simple-theme-options' ),
						'default' => '',
						'width'   => '1-2',
					),
					array(
						'type'    => 'textarea',
						'id'      => 'cf_area',
						'title'   => __( 'Textarea', 'simple-theme-options' ),
						'default' => '',
						'rows'    => 2,
						'width'   => '1-2',
					),
					array(
						'type'    => 'number',
						'id'      => 'cf_num',
						'title'   => __( 'Number', 'simple-theme-options' ),
						'default' => '10',
						'min'     => 0,
						'max'     => 100,
						'width'   => '1-3',
					),
					array(
						'type'    => 'switcher',
						'id'      => 'cf_sw',
						'title'   => __( 'Switcher', 'simple-theme-options' ),
						'default' => '1',
						'width'   => '1-3',
					),
					array(
						'type'        => 'select',
						'id'          => 'cf_sel',
						'title'       => __( 'Select', 'simple-theme-options' ),
						'default'     => 'b',
						'width'       => '1-3',
						'options'     => array(
							'a' => __( 'Option A', 'simple-theme-options' ),
							'b' => __( 'Option B', 'simple-theme-options' ),
							'c' => __( 'Option C', 'simple-theme-options' ),
						),
					),
					array(
						'type'    => 'range',
						'id'      => 'cf_range',
						'title'   => __( 'Range', 'simple-theme-options' ),
						'default' => array( 'v' => 50, 'u' => 'px' ),
						'min'     => 0,
						'max'     => 200,
						'step'    => 1,
						'units'   => array( 'px' ),
						'width'   => '1-2',
					),
					array(
						'type'          => 'button_group',
						'id'            => 'cf_bg',
						'title'         => __( 'Button group', 'simple-theme-options' ),
						'default'       => 'm',
						'width'         => '1-2',
						'options'       => array(
							's' => __( 'S', 'simple-theme-options' ),
							'm' => __( 'M', 'simple-theme-options' ),
							'l' => __( 'L', 'simple-theme-options' ),
						),
					),
					array(
						'type'          => 'image_select',
						'id'            => 'cf_img',
						'title'         => __( 'Image select', 'simple-theme-options' ),
						'default'       => 'boxed',
						'width'         => '1-1',
						'options'       => array(
							'wide'  => array(
								'label' => __( 'Wide', 'simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-acc-wide/400/170',
							),
							'boxed' => array(
								'label' => __( 'Boxed', 'simple-theme-options' ),
								'image' => 'https://picsum.photos/seed/sto-acc-boxed/400/170',
							),
						),
					),
					array(
						'type'        => 'code_editor',
						'id'          => 'cf_code',
						'title'       => __( 'Code editor', 'simple-theme-options' ),
						'description' => __( 'HTML mode sample; stored with field sanitization.', 'simple-theme-options' ),
						'mode'        => 'html',
						'height'      => 160,
						'placeholder' => '<!-- -->',
						'width'       => '1-1',
					),
					array(
						'type'        => 'dynamic_object',
						'id'          => 'cf_page',
						'title'       => __( 'Dynamic object (pages)', 'simple-theme-options' ),
						'description' => __( 'AJAX search after 3+ characters.', 'simple-theme-options' ),
						'post_type'   => 'page',
						'placeholder' => __( 'Search pages…', 'simple-theme-options' ),
						'width'       => '1-1',
					),
					array(
						'type'        => 'typography',
						'id'          => 'cf_typo',
						'title'       => __( 'Typography', 'simple-theme-options' ),
						'description' => __( 'Composite font control.', 'simple-theme-options' ),
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
						'title'       => __( 'Color', 'simple-theme-options' ),
						'default'     => '#2271b1',
						'width'       => '1-2',
					),
					array(
						'type'        => 'background_control',
						'id'          => 'cf_bgctl',
						'title'       => __( 'Background control', 'simple-theme-options' ),
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
						'title'       => __( 'Border', 'simple-theme-options' ),
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
						'title'       => __( 'Gallery', 'simple-theme-options' ),
						'description' => __( 'Same as the Layout sample: optional default array( attachment_id, … ). Theme: sto_get_gallery_attachment_ids().', 'simple-theme-options' ),
						'default'     => array(
							101,
							102,
							103,
						),
						'max'         => 0,
						'width'       => '1-1',
					),
					array(
						'type'        => 'link_color',
						'id'          => 'cf_link',
						'title'       => __( 'Link color', 'simple-theme-options' ),
						'default'     => array(
							'regular' => '#2271b1',
							'hover'   => '#135e96',
						),
						'labels'      => array(
							'regular' => __( 'Regular', 'simple-theme-options' ),
							'hover'   => __( 'Hover', 'simple-theme-options' ),
						),
						'alpha'       => true,
						'width'       => '1-1',
					),
				),
			)
		);

		Group::register(
			array(
				'section_slug'  => 'accordion',
				'id'            => 'acc_host_group',
				'title'         => __( 'Accordion inside a group', 'simple-theme-options' ),
				'description'   => __( 'Use this pattern when the accordion sits beside other group-level controls; blocks above are standalone accordions.', 'simple-theme-options' ),
				'fields'        => array(
					array(
						'type'          => 'accordion',
						'id'            => 'acc_hosted',
						'title'         => __( 'Grouped accordion row', 'simple-theme-options' ),
						'description'   => __( 'Same API as standalone; Group supplies the inner context.', 'simple-theme-options' ),
						'panels'        => array(
							array( 'id' => 'h1', 'label' => __( 'Content', 'simple-theme-options' ) ),
							array( 'id' => 'h2', 'label' => __( 'SEO', 'simple-theme-options' ) ),
						),
						'fields'        => array(
							array(
								'type'    => 'text',
								'id'      => 'hg_title',
								'title'   => __( 'Title', 'simple-theme-options' ),
								'default' => '',
								'width'   => '1-2',
							),
							array(
								'type'    => 'switcher',
								'id'      => 'hg_on',
								'title'   => __( 'Published', 'simple-theme-options' ),
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
