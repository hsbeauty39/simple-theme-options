<?php
/**
 * Registers the “Shammi Store” Theme Settings menu when the optional shammi-testing plugin is inactive
 * but {@see sto_get_options()} still contains <code>shm_x_*</code> keys (e.g. after import).
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Compatibility;

use SimpleThemeOptions\Admin\Options\Fields\AlignmentControl\AlignmentControl;
use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Admin\Options\Fields\CheckboxControl\CheckboxControl;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\DateField\DateField;
use SimpleThemeOptions\Admin\Options\Fields\DateTimeField\DateTimeField;
use SimpleThemeOptions\Admin\Options\Fields\Dimension\Dimension;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\GalleryControl\GalleryControl;
use SimpleThemeOptions\Admin\Options\Fields\GoogleMapControl\GoogleMapControl;
use SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl;
use SimpleThemeOptions\Admin\Options\Fields\IconSelect\IconSelect;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;

defined( 'ABSPATH' ) || exit;

final class ShammiStoreFallback {

	private static $attempted = false;

	private static $fallback_active = false;

	/**
	 * Runs after {@see shammi-testing} (priority 20) so the real plugin wins when active.
	 */
	public static function maybe_register(): void {
		if ( self::$attempted ) {
			return;
		}
		self::$attempted = true;

		if ( ! apply_filters( 'sto_shammi_store_fallback_enabled', true ) ) {
			return;
		}

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'sto_get_options' ) ) {
			return;
		}

		if ( self::is_shammi_plugin_active() ) {
			return;
		}

		$menu = OptionsMenu::instance();
		if ( in_array( 'shammi-store', $menu->get_registered_menu_slugs(), true ) ) {
			return;
		}

		if ( ! self::has_shammi_option_keys() ) {
			return;
		}

		self::register_menu_and_fields();
		self::$fallback_active = true;
		add_action( 'admin_notices', array( __CLASS__, 'render_fallback_notice' ) );
	}

	public static function is_fallback_active(): bool {
		return self::$fallback_active;
	}

	public static function render_fallback_notice(): void {
		if ( ! self::$fallback_active || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen hint.
		if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( (string) $_GET['page'] ) ) !== 'shammi-store' ) {
			return;
		}

		echo '<div class="notice notice-info"><p>';
		echo esc_html(
			__( 'The Shammi Testing plugin is inactive. These Theme Settings screens are provided by Simple Theme Options so values you imported (shm_*) stay editable. Activate Shammi Testing again to load its own copy of this menu.', 'simple-theme-options' )
		);
		echo '</p></div>';
	}

	private static function is_shammi_plugin_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'shammi-testing/shammi-testing.php' );
	}

	/**
	 * @return bool True when at least one key looks like the bundled Shammi sample store.
	 */
	private static function has_shammi_option_keys(): bool {
		$opts = sto_get_options();
		foreach ( array_keys( $opts ) as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( strpos( $key, 'shm_x_' ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	private static function register_menu_and_fields(): void {
		$menu = OptionsMenu::instance();

		$menu->register(
			__( 'Shammi Store', 'simple-theme-options' ),
			'shammi-store',
			'dashicons-cart',
			array(
				'demo'          => false,
				'packaged_demo' => false,
			)
		);

		$menu->add_section( __( 'Store', 'simple-theme-options' ), 'shm-x-store', 'fa-light fa-store' );
		$menu->add_sub_section( __( 'General', 'simple-theme-options' ), 'shm-x-store-general', 'fa-light fa-gear', 'shm-x-store' );
		$menu->add_sub_section( __( 'Checkout', 'simple-theme-options' ), 'shm-x-store-checkout', 'fa-light fa-credit-card', 'shm-x-store' );

		$s = 'shm-x-store-general';
		$c = 'shm-x-store-checkout';

		Input::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_site_title',
				'title'        => __( 'Site title (text)', 'simple-theme-options' ),
				'input_type'   => 'text',
				'default'      => '',
			)
		);

		Input::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_units',
				'title'        => __( 'Units in stock (number)', 'simple-theme-options' ),
				'input_type'   => 'number',
				'default'      => '0',
				'min'          => 0,
				'max'          => 99999,
			)
		);

		Input::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_notes',
				'title'        => __( 'Notes (textarea)', 'simple-theme-options' ),
				'input_type'   => 'textarea',
				'rows'         => 4,
			)
		);

		Select::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_currency',
				'title'        => __( 'Currency (select)', 'simple-theme-options' ),
				'default'      => 'usd',
				'options'      => array(
					'usd' => __( 'USD', 'simple-theme-options' ),
					'eur' => __( 'EUR', 'simple-theme-options' ),
					'gbp' => __( 'GBP', 'simple-theme-options' ),
				),
			)
		);

		ButtonGroup::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_catalog_mode',
				'title'        => __( 'Catalog mode (button group)', 'simple-theme-options' ),
				'default'      => 'shop',
				'options'      => array(
					'shop' => __( 'Shop', 'simple-theme-options' ),
					'cat'  => __( 'Catalog', 'simple-theme-options' ),
				),
			)
		);

		Switcher::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_guest_checkout',
				'title'        => __( 'Guest checkout (switcher)', 'simple-theme-options' ),
				'default'      => '1',
			)
		);

		CheckboxControl::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_tax_inclusive',
				'title'        => __( 'Prices include tax (checkbox)', 'simple-theme-options' ),
				'multiple'     => false,
				'default'      => '0',
			)
		);

		CheckboxControl::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_badges',
				'title'        => __( 'Product badges (multi checkbox)', 'simple-theme-options' ),
				'multiple'     => true,
				'options'      => array(
					'sale' => __( 'Sale', 'simple-theme-options' ),
					'new'  => __( 'New', 'simple-theme-options' ),
					'hot'  => __( 'Hot', 'simple-theme-options' ),
				),
				'default'      => array( 'sale' ),
			)
		);

		Color::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_brand_color',
				'title'        => __( 'Brand colour', 'simple-theme-options' ),
				'default'      => '#2271b1',
			)
		);

		LinkColor::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_footer_links',
				'title'        => __( 'Footer link colours', 'simple-theme-options' ),
				'default'      => array(
					'regular' => '#2271b1',
					'hover'   => '#135e96',
					'active'  => '#0a4b78',
				),
			)
		);

		BackgroundControl::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_hero_bg',
				'title'        => __( 'Hero background', 'simple-theme-options' ),
				'default'      => array(
					'color'    => '#f0f0f1',
					'image_id' => '',
				),
			)
		);

		BorderControl::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_card_border',
				'title'        => __( 'Card border', 'simple-theme-options' ),
				'default'      => array(
					'radius'      => '8',
					'radius_unit' => 'px',
					'style'       => 'solid',
					'width'       => '1',
					'width_unit'  => 'px',
					'color'       => '#dcdcde',
				),
				'features'     => array( 'radius', 'style', 'width', 'color' ),
			)
		);

		ShadowControl::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_card_shadow',
				'title'        => __( 'Card shadow', 'simple-theme-options' ),
				'selector'     => '.shm-demo-card',
				'default'      => array(
					'offset_x' => '0',
					'offset_y' => '4',
					'blur'     => '12',
					'spread'   => '0',
					'color'    => 'rgba(0,0,0,0.08)',
					'inset'    => '0',
				),
			)
		);

		GradientControl::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_sale_strip',
				'title'        => __( 'Sale strip gradient', 'simple-theme-options' ),
				'default'      => array(
					'type'   => 'linear',
					'angle'  => '90',
					'stops'  => array(
						array( 'color' => '#d63638', 'position' => '0' ),
						array( 'color' => '#b32d2e', 'position' => '100' ),
					),
				),
			)
		);

		Range::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_sidebar_width',
				'title'        => __( 'Sidebar width', 'simple-theme-options' ),
				'default'      => '280',
				'min'          => 200,
				'max'          => 400,
				'step'         => 1,
				'units'        => array( 'px' ),
			)
		);

		Dimension::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_grid_gap',
				'title'        => __( 'Grid gap (dimension)', 'simple-theme-options' ),
				'default'      => array(
					't' => '16', 'r' => '16', 'b' => '16', 'l' => '16',
					'u' => 'px', 'link' => '1',
				),
			)
		);

		AlignmentControl::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_title_align',
				'title'        => __( 'Title alignment', 'simple-theme-options' ),
				'default'      => 'left',
			)
		);

		DateField::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_launch_date',
				'title'        => __( 'Launch date', 'simple-theme-options' ),
			)
		);

		DateTimeField::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_flash_end',
				'title'        => __( 'Flash sale end', 'simple-theme-options' ),
			)
		);

		CodeEditor::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_custom_css',
				'title'        => __( 'Extra CSS (code editor)', 'simple-theme-options' ),
				'language'     => 'css',
				'height'       => 200,
			)
		);

		Typography::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_body_type',
				'title'        => __( 'Body typography', 'simple-theme-options' ),
				'default'      => array(
					'family'    => 'Inter',
					'variant'   => 'regular',
					'subset'    => 'latin',
					'transform' => 'none',
				),
			)
		);

		DynamicObject::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_shop_page',
				'title'        => __( 'Shop page (dynamic object)', 'simple-theme-options' ),
				'post_type'    => 'page',
				'placeholder'  => __( 'Search pages…', 'simple-theme-options' ),
			)
		);

		ImageSelect::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_grid_style',
				'title'        => __( 'Product grid (image select)', 'simple-theme-options' ),
				'default'      => 'two',
				'options'      => array(
					'one' => array(
						'label' => __( 'One column', 'simple-theme-options' ),
						'image' => 'https://picsum.photos/seed/shm-grid-1/120/80',
					),
					'two' => array(
						'label' => __( 'Two columns', 'simple-theme-options' ),
						'image' => 'https://picsum.photos/seed/shm-grid-2/120/80',
					),
				),
			)
		);

		IconSelect::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_cart_icon',
				'title'        => __( 'Cart icon', 'simple-theme-options' ),
				'default'      => 'fa-light fa-cart-shopping',
			)
		);

		GalleryControl::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_lookbook',
				'title'        => __( 'Lookbook images (gallery)', 'simple-theme-options' ),
				'max'          => 12,
			)
		);

		GoogleMapControl::register(
			array(
				'section_slug' => $s,
				'id'           => 'shm_x_warehouse',
				'title'        => __( 'Warehouse map', 'simple-theme-options' ),
				'default'      => array(
					'address'   => '',
					'lat'       => '',
					'lng'       => '',
					'zoom'      => '14',
					'map_style' => 'streets',
				),
			)
		);

		Input::register(
			array(
				'section_slug' => $c,
				'id'           => 'shm_x_checkout_heading',
				'title'        => __( 'Checkout heading', 'simple-theme-options' ),
				'input_type'   => 'text',
			)
		);

		Switcher::register(
			array(
				'section_slug' => $c,
				'id'           => 'shm_x_terms_required',
				'title'        => __( 'Require terms at checkout', 'simple-theme-options' ),
				'default'      => '1',
			)
		);
	}
}
