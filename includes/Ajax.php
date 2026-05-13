<?php
namespace SimpleThemeOptions;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Ajax {
    use SingletonTrait;

    public function init() {
        $ajax_lists = $this->get_ajax_lists();
        foreach ($ajax_lists as $ajax_list) {
            add_action('wp_ajax_' . $ajax_list, array($this, 'handle_' . $ajax_list));
            add_action('wp_ajax_nopriv_' . $ajax_list, array($this, 'handle_' . $ajax_list));
        }
    }

    public function get_ajax_lists() {
        return array(
            'sto_display_section_on_menu',
        );
    }

    /**
     * This function will return all sections as an array.
     * 
     * @return array
     */
    public function handle_sto_display_section_on_menu() {
        check_ajax_referer('sto_display_section_on_menu', 'nonce');

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        
        try {
            $options_menu = OptionsMenu::instance();
            $sections     = $options_menu->get_sections();
            $menu_slug    = $options_menu->get_parent_menu_slug();

            wp_send_json_success(array(
                'sections'  => $sections,
                'menu_slug' => $menu_slug,
            ));
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

}