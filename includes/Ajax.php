<?php
namespace SimpleThemeOptions;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Admin\ThemeSettingsMetabox;
use SimpleThemeOptions\Admin\ThemeSettingsTermBox;
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
            'sto_save_theme_options_metabox',
            'sto_save_theme_options_term',
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
            $menu_slug    = $options_menu->get_request_options_menu_slug();
            $sections     = array();
            foreach ( $options_menu->get_sections() as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $p = isset( $row['sto_menu_page'] ) ? sanitize_key( (string) $row['sto_menu_page'] ) : $options_menu->get_parent_menu_slug();
                if ( $p === $menu_slug ) {
                    $sections[] = $row;
                }
            }

            wp_send_json_success(array(
                'sections'  => $sections,
                'menu_slug' => $menu_slug,
            ));
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Save Theme Settings from the post editor metabox (per-post meta **`_sto_theme_settings_post`**, same sanitization as the main screen).
     */
    public function handle_sto_save_theme_options_metabox() {
        check_ajax_referer( 'sto_save_theme_options_metabox', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to save Theme Settings.', 'simple-theme-options' ) ), 403 );
        }

        if ( ! OptionsMenu::instance()->should_show_theme_settings_metaboxes() ) {
            wp_send_json_error( array( 'message' => __( 'Theme Settings meta box is disabled.', 'simple-theme-options' ) ), 400 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post.', 'simple-theme-options' ) ), 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post.', 'simple-theme-options' ) ), 400 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $page = isset( $_POST['sto_ts_page'] ) ? sanitize_key( wp_unslash( $_POST['sto_ts_page'] ) ) : '';
        if ( $page === '' || ! ThemeSettingsMetabox::instance()->menu_root_allows_post_type( $page, (string) $post->post_type ) ) {
            wp_send_json_error( array( 'message' => __( 'Theme Settings are not available for this screen.', 'simple-theme-options' ) ), 400 );
        }

        check_admin_referer( 'sto_save_options_action', 'sto_save_options_nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $section_slug = isset( $_POST['sto_ts_section'] ) ? sanitize_key( wp_unslash( $_POST['sto_ts_section'] ) ) : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $posted_raw = isset( $_POST['sto_options'] ) && is_array( $_POST['sto_options'] ) ? wp_unslash( $_POST['sto_options'] ) : array();

        $result = OptionsMenu::instance()->persist_theme_settings_leaf( $page, $section_slug, $posted_raw, false, $post_id );
        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data();
            $msgs = ( is_array( $data ) && isset( $data['messages'] ) && is_array( $data['messages'] ) ) ? $data['messages'] : array( $result->get_error_message() );
            wp_send_json_error(
                array(
                    'message' => implode( ' ', array_map( 'strval', $msgs ) ),
                    'messages' => $msgs,
                ),
                400
            );
        }

        wp_send_json_success(
            array(
                'message' => __( 'Settings saved.', 'simple-theme-options' ),
            )
        );
    }

    /**
     * Save Theme Settings from a taxonomy term edit screen (per-term meta {@see ThemeSettingsTermBox::TERM_SETTINGS_META_KEY}).
     */
    public function handle_sto_save_theme_options_term() {
        check_ajax_referer( 'sto_save_theme_options_term', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to save Theme Settings.', 'simple-theme-options' ) ), 403 );
        }

        if ( ! OptionsMenu::instance()->should_show_theme_settings_term_metaboxes() ) {
            wp_send_json_error( array( 'message' => __( 'Theme Settings for terms is disabled.', 'simple-theme-options' ) ), 400 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( (string) $_POST['taxonomy'] ) ) : '';

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof \WP_Term ) {
            wp_send_json_error( array( 'message' => __( 'Invalid term.', 'simple-theme-options' ) ), 400 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $page = isset( $_POST['sto_ts_page'] ) ? sanitize_key( wp_unslash( $_POST['sto_ts_page'] ) ) : '';
        if ( $page === '' || ! ThemeSettingsTermBox::instance()->menu_root_allows_taxonomy( $page, (string) $term->taxonomy ) ) {
            wp_send_json_error( array( 'message' => __( 'Theme Settings are not available for this taxonomy.', 'simple-theme-options' ) ), 400 );
        }

        check_admin_referer( 'sto_save_options_action', 'sto_save_options_nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $section_slug = isset( $_POST['sto_ts_section'] ) ? sanitize_key( wp_unslash( $_POST['sto_ts_section'] ) ) : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $posted_raw = isset( $_POST['sto_options'] ) && is_array( $_POST['sto_options'] ) ? wp_unslash( $_POST['sto_options'] ) : array();

        $result = OptionsMenu::instance()->persist_theme_settings_leaf( $page, $section_slug, $posted_raw, false, 0, $term_id );
        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data();
            $msgs = ( is_array( $data ) && isset( $data['messages'] ) && is_array( $data['messages'] ) ) ? $data['messages'] : array( $result->get_error_message() );
            wp_send_json_error(
                array(
                    'message'  => implode( ' ', array_map( 'strval', $msgs ) ),
                    'messages' => $msgs,
                ),
                400
            );
        }

        wp_send_json_success(
            array(
                'message' => __( 'Settings saved.', 'simple-theme-options' ),
            )
        );
    }

}