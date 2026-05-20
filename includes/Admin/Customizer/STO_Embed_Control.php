<?php
/**
 * Renders the full Theme Settings panel inside the Customizer.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Customizer;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;

defined( 'ABSPATH' ) || exit;

/**
 * @extends \WP_Customize_Control<\WP_Customize_Setting>
 */
final class STO_Embed_Control extends \WP_Customize_Control {

	/**
	 * @var string
	 */
	public $type = 'sto_embed';

	/**
	 * STO menu root (`admin.php?page=`).
	 *
	 * @var string
	 */
	public $menu_page_slug = '';

	/**
	 * @return void
	 */
	protected function render_content() {
		$menu_page_slug = sanitize_key( (string) $this->menu_page_slug );
		if ( $menu_page_slug === '' ) {
			return;
		}

		echo '<div class="sto-customizer-embed-root">';
		OptionsMenu::instance()->render_customizer_embed( $menu_page_slug );
		echo '</div>';
	}
}
