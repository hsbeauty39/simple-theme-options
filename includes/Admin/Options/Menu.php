<?php
namespace SimpleThemeOptions\Admin\Options;

use SimpleThemeOptions\Admin\Options\Fields\Group\Group;
use SimpleThemeOptions\Admin\Options\Fields\Select\Select;
use SimpleThemeOptions\Admin\Options\Fields\ImageSelect\ImageSelect;
use SimpleThemeOptions\Admin\Options\Fields\BackgroundControl\BackgroundControl;
use SimpleThemeOptions\Admin\Options\Fields\BorderControl\BorderControl;
use SimpleThemeOptions\Admin\Options\Fields\ShadowControl\ShadowControl;
use SimpleThemeOptions\Admin\Options\Fields\GradientControl\GradientControl;
use SimpleThemeOptions\Admin\Options\Fields\CodeEditor\CodeEditor;
use SimpleThemeOptions\Admin\Options\Fields\Color\Color;
use SimpleThemeOptions\Admin\Options\Fields\LinkColor\LinkColor;
use SimpleThemeOptions\Admin\Options\Fields\Switcher\Switcher;
use SimpleThemeOptions\Admin\Options\Fields\CheckboxControl\CheckboxControl;
use SimpleThemeOptions\Admin\Options\Fields\Typography\Typography;
use SimpleThemeOptions\Admin\Options\Fields\DynamicObject\DynamicObject;
use SimpleThemeOptions\Admin\Options\Fields\Input\Input;
use SimpleThemeOptions\Admin\Options\Fields\DateField\DateField;
use SimpleThemeOptions\Admin\Options\Fields\DateTimeField\DateTimeField;
use SimpleThemeOptions\Admin\Options\Fields\Range\Range;
use SimpleThemeOptions\Admin\Options\Fields\ButtonGroup\ButtonGroup;
use SimpleThemeOptions\Admin\ThemeSettingsCleanScreen;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Menu {
	use SingletonTrait;
    private $sections = array();
    private $sub_sections = array();
    private $parent_menu_slug = '';

    public function register($name, $slug, $icon) {
        $this->parent_menu_slug = $slug;
        add_action( 'admin_init', array( $this, 'maybe_handle_save_request' ) );

        add_action('admin_menu', function() use ($name, $slug, $icon) {
            add_menu_page($name, $name, 'manage_options', $slug, array($this, 'render_menu_page'), $icon, 10);

            foreach ( $this->get_sections_for_navigation() as $section ) {
                add_submenu_page(
                    $slug,
                    $section['name'],
                    $section['name'],
                    'manage_options',
                    $slug . '&section=' . $section['slug'],
                    array( $this, 'render_menu_page' )
                );
            }
        }, 10);

        // Remove WP auto-added duplicate parent submenu after all submenu items are registered.
        add_action( 'admin_menu', function () use ( $slug ) {
            remove_submenu_page( $slug, $slug );

            global $submenu;
            if ( isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
                foreach ( $submenu[ $slug ] as $index => $submenu_item ) {
                    if ( isset( $submenu_item[2] ) && $submenu_item[2] === $slug ) {
                        unset( $submenu[ $slug ][ $index ] );
                    }
                }
                $submenu[ $slug ] = array_values( $submenu[ $slug ] );
            }
        }, 999 );

        add_filter( 'parent_file', function ( $parent_file ) use ( $slug ) {
            if ( isset( $_GET['page'] ) && $_GET['page'] === $slug && isset( $_GET['section'] ) ) {
                return $slug;
            }

            return $parent_file;
        } );

        add_filter( 'submenu_file', function ( $submenu_file ) use ( $slug ) {
            if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== $slug ) {
                return $submenu_file;
            }

            $leaf = $this->get_current_section_slug();
            if ( $leaf === '' ) {
                return $submenu_file;
            }

            $highlight = $this->get_wp_submenu_highlight_slug_for_leaf( $leaf );

            return $highlight !== '' ? $slug . '&section=' . $highlight : $submenu_file;
        } );

        add_action( 'admin_init', array( $this, 'redirect_theme_settings_to_canonical_leaf' ), 1 );

        ThemeSettingsCleanScreen::instance()->hook_clean_screen( $slug );

        $this->include_fields();
    }

    /**
     * Navigable leaf slugs (section => true) for save validation.
     *
     * @return array<string, true>
     */
    private function get_leaf_section_slug_map() {
        $map = array();
        foreach ( $this->get_leaf_sections_for_navigation() as $leaf ) {
            if ( ! empty( $leaf['slug'] ) ) {
                $map[ sanitize_key( (string) $leaf['slug'] ) ] = true;
            }
        }

        return $map;
    }

    /**
     * Canonical leaf `section` for a save request (parent slug → first child; unknown → default leaf).
     */
    private function resolve_canonical_leaf_for_save( $section_slug ) {
        $raw = sanitize_key( (string) $section_slug );
        if ( $raw === '' ) {
            return $this->get_default_leaf_section_slug();
        }

        $resolved = $this->resolve_to_first_leaf_slug( $raw );
        $leaf_map = $this->get_leaf_section_slug_map();

        if ( $resolved !== '' && isset( $leaf_map[ $resolved ] ) ) {
            return $resolved;
        }

        return $this->get_default_leaf_section_slug();
    }

    /**
     * All `sto_options` keys registered for one leaf panel (standalone + group-inner fields).
     *
     * @return array<int, string>
     */
    private function get_registered_option_keys_for_leaf_section( $section_slug ) {
        $section_slug = sanitize_key( (string) $section_slug );
        $keys         = array();

        $chunks = array(
            ImageSelect::get_field_ids_for_section( $section_slug ),
            ButtonGroup::get_field_ids_for_section( $section_slug ),
            Select::get_field_ids_for_section( $section_slug ),
            CheckboxControl::get_field_ids_for_section( $section_slug ),
            Switcher::get_field_ids_for_section( $section_slug ),
            Color::get_field_ids_for_section( $section_slug ),
            BackgroundControl::get_field_ids_for_section( $section_slug ),
            BorderControl::get_field_ids_for_section( $section_slug ),
            ShadowControl::get_field_ids_for_section( $section_slug ),
            GradientControl::get_field_ids_for_section( $section_slug ),
            LinkColor::get_field_ids_for_section( $section_slug ),
            Input::get_field_ids_for_section( $section_slug ),
            DateField::get_field_ids_for_section( $section_slug ),
            DateTimeField::get_field_ids_for_section( $section_slug ),
            Range::get_field_ids_for_section( $section_slug ),
            CodeEditor::get_field_ids_for_section( $section_slug ),
            Typography::get_field_ids_for_section( $section_slug ),
            DynamicObject::get_field_ids_for_section( $section_slug ),
        );

        foreach ( $chunks as $ids ) {
            foreach ( $ids as $id ) {
                $id = sanitize_key( (string) $id );
                if ( $id !== '' ) {
                    $keys[ $id ] = true;
                }
            }
        }

        return array_keys( $keys );
    }

    /**
     * Transient key for validation messages after a failed save (per user).
     */
    private function get_validation_notice_transient_name() {
        $uid = get_current_user_id();

        return $uid > 0 ? 'sto_ts_validate_' . $uid : 'sto_ts_validate_0';
    }

    public function maybe_handle_save_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! isset( $_POST['sto_save_options'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // POST may hit admin.php without query args (e.g. <base href>, broken relative action); hidden fields mirror the screen.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $page === '' && isset( $_POST['sto_ts_page'] ) ) {
            $page = sanitize_key( wp_unslash( $_POST['sto_ts_page'] ) );
        }
        if ( $page !== $this->get_parent_menu_slug() ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        check_admin_referer( 'sto_save_options_action', 'sto_save_options_nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $section_slug = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $section_slug === '' && isset( $_POST['sto_ts_section'] ) ) {
            $section_slug = sanitize_key( wp_unslash( $_POST['sto_ts_section'] ) );
        }
        $section_slug = $this->resolve_canonical_leaf_for_save( $section_slug );
        // Only the active leaf's registered keys are updated; other sections keep existing values.
        $section_key_map = array_flip( $this->get_registered_option_keys_for_leaf_section( $section_slug ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $posted_options = isset( $_POST['sto_options'] ) && is_array( $_POST['sto_options'] ) ? wp_unslash( $_POST['sto_options'] ) : array();
        if ( ! empty( $section_key_map ) ) {
            $posted_options = array_intersect_key( $posted_options, $section_key_map );
        }

        $sanitized_options = array();

        foreach ( $posted_options as $key => $value ) {
            $option_key = sanitize_key( (string) $key );
            if ( ! $option_key ) {
                continue;
            }

            if ( Typography::is_registered_field_id( $option_key ) ) {
                $raw_typ = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Typography::sanitize_posted_value( $option_key, $raw_typ );
                continue;
            }

            if ( BackgroundControl::is_registered_field_id( $option_key ) ) {
                $raw_bg = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = BackgroundControl::sanitize_posted_value( $option_key, $raw_bg );
                continue;
            }

            if ( BorderControl::is_registered_field_id( $option_key ) ) {
                $raw_border = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = BorderControl::sanitize_posted_value( $option_key, $raw_border );
                continue;
            }

            if ( ShadowControl::is_registered_field_id( $option_key ) ) {
                $raw_shadow = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = ShadowControl::sanitize_posted_value( $option_key, $raw_shadow );
                continue;
            }

            if ( GradientControl::is_registered_field_id( $option_key ) ) {
                $raw_grad = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = GradientControl::sanitize_posted_value( $option_key, $raw_grad );
                continue;
            }

            if ( LinkColor::is_registered_field_id( $option_key ) ) {
                $raw_lc = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = LinkColor::sanitize_posted_value( $option_key, $raw_lc );
                continue;
            }

            if ( Color::is_registered_field_id( $option_key ) ) {
                $raw_color = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Color::sanitize_posted_value( $option_key, $raw_color );
                continue;
            }

			if ( Switcher::is_registered_field_id( $option_key ) ) {
				$raw_sw = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
				$sanitized_options[ $option_key ] = Switcher::sanitize_posted_value( $option_key, $raw_sw );
				continue;
			}

			if ( CheckboxControl::is_registered_field_id( $option_key ) ) {
				$raw_cb = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
				$sanitized_options[ $option_key ] = CheckboxControl::sanitize_posted_value( $option_key, $raw_cb );
				continue;
			}

            if ( Select::is_registered_field_id( $option_key ) ) {
                $raw_sel = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Select::sanitize_posted_value( $option_key, $raw_sel );
                continue;
            }

            if ( ImageSelect::is_registered_field_id( $option_key ) ) {
                $raw_img = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = ImageSelect::sanitize_posted_value( $option_key, $raw_img );
                continue;
            }

            if ( DynamicObject::is_registered_field_id( $option_key ) ) {
                $raw_dyn = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : null;
                $sanitized_options[ $option_key ] = DynamicObject::instance()->sanitize_for_field( $option_key, $raw_dyn );
                continue;
            }

            if ( Input::is_registered_field_id( $option_key ) ) {
                $raw_in = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Input::sanitize_posted_value( $option_key, $raw_in );
                continue;
            }

            if ( DateField::is_registered_field_id( $option_key ) ) {
                $raw_date = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = DateField::sanitize_posted_value( $option_key, $raw_date );
                continue;
            }

            if ( DateTimeField::is_registered_field_id( $option_key ) ) {
                $raw_dt = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = DateTimeField::sanitize_posted_value( $option_key, $raw_dt );
                continue;
            }

            if ( Range::is_registered_field_id( $option_key ) ) {
                $raw_range = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = Range::sanitize_posted_value( $option_key, $raw_range );
                continue;
            }

            if ( CodeEditor::is_registered_field_id( $option_key ) ) {
                $raw_code = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = CodeEditor::sanitize_posted_value( $option_key, $raw_code );
                continue;
            }

            if ( ButtonGroup::is_registered_field_id( $option_key ) ) {
                $raw_bg = array_key_exists( $option_key, $posted_options ) ? $posted_options[ $option_key ] : '';
                $sanitized_options[ $option_key ] = ButtonGroup::sanitize_posted_value( $option_key, $raw_bg );
                continue;
            }

            if ( is_array( $value ) ) {
                $sanitized_options[ $option_key ] = array_map( 'sanitize_text_field', $value );
            } else {
                $sanitized_options[ $option_key ] = sanitize_text_field( (string) $value );
            }
        }

        Select::instance()->merge_missing_multiple_select_fields( $posted_options, $sanitized_options, $section_key_map );
        CheckboxControl::instance()->merge_missing_multiple_checkbox_fields( $posted_options, $sanitized_options, $section_key_map );
        DynamicObject::instance()->merge_missing_multiple_dynamic_fields( $posted_options, $sanitized_options, $section_key_map );

        $existing = get_option( 'sto_options', array() );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        foreach ( array_keys( $section_key_map ) as $k ) {
            if ( ! array_key_exists( $k, $sanitized_options ) && array_key_exists( $k, $existing ) ) {
                $sanitized_options[ $k ] = $existing[ $k ];
            }
        }

        foreach ( $existing as $k => $v ) {
            $k = sanitize_key( (string) $k );
            if ( ! $k || isset( $section_key_map[ $k ] ) ) {
                continue;
            }
            $sanitized_options[ $k ] = $v;
        }

        /**
         * Full merged option array immediately before validation and persistence.
         *
         * @param array<string, mixed> $sanitized_options
         * @param string                 $section_slug    Active leaf section slug.
         */
        $sanitized_options = apply_filters( 'sto_options_before_save', $sanitized_options, $section_slug );

        /**
         * Extra validation errors when saving one leaf section (HTML required, etc.).
         *
         * @param array<int, string>   $errors
         * @param string               $section_slug
         * @param array<string, mixed> $sanitized_options Full merged preview.
         * @param array<string, mixed> $posted_options    Posted `sto_options` slice for this request.
         */
        $validation_errors = apply_filters(
            'sto_theme_settings_validation_errors',
            array_merge(
                Input::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                DateField::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options ),
                DateTimeField::instance()->collect_html_required_violations_for_section( $section_slug, $sanitized_options )
            ),
            $section_slug,
            $sanitized_options,
            $posted_options
        );

        if ( ! empty( $validation_errors ) ) {
            set_transient(
                $this->get_validation_notice_transient_name(),
                array(
                    'section'  => $section_slug,
                    'messages' => array_values( array_filter( array_map( 'strval', $validation_errors ) ) ),
                ),
                120
            );

            $redirect_url = admin_url( 'admin.php?page=' . $this->get_parent_menu_slug() );
            if ( $section_slug ) {
                $redirect_url = add_query_arg( 'section', $section_slug, $redirect_url );
            }
            $redirect_url = add_query_arg( 'sto_validation_error', '1', $redirect_url );

            wp_safe_redirect( $redirect_url );
            exit;
        }

        update_option( 'sto_options', $sanitized_options );

        $redirect_url = admin_url( 'admin.php?page=' . $this->get_parent_menu_slug() );
        if ( $section_slug ) {
            $redirect_url = add_query_arg( 'section', $section_slug, $redirect_url );
        }
        $redirect_url = add_query_arg( 'sto_saved', '1', $redirect_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Fires after menu filters are attached. Register option fields on this hook.
     */
    private function include_fields() {
        do_action( 'sto_include_option_fields', $this );
    }

    /**
     * Breadcrumb label for sidebar leaf (e.g. "General -> Layout" or "Social").
     */
    public function get_leaf_breadcrumb_label( $leaf_slug ) {
        return $this->get_breadcrumb_label_for_leaf( $leaf_slug );
    }

    private function get_breadcrumb_label_for_leaf( $leaf_slug ) {
        $leaf_slug = sanitize_key( (string) $leaf_slug );

        foreach ( $this->sub_sections as $sub ) {
            if ( isset( $sub['slug'] ) && $sub['slug'] === $leaf_slug ) {
                $parent = $this->get_section_by_slug( isset( $sub['parent_slug'] ) ? (string) $sub['parent_slug'] : '' );
                if ( $parent && isset( $parent['name'], $sub['name'] ) ) {
                    return $parent['name'] . ' -> ' . $sub['name'];
                }

                return isset( $sub['name'] ) ? (string) $sub['name'] : '';
            }
        }

        $top = $this->get_section_by_slug( $leaf_slug );

        return ( $top && isset( $top['name'] ) ) ? (string) $top['name'] : '';
    }

    /**
     * Search / quick-nav entries for Theme Settings header (sections, groups, fields).
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_search_items() {
        $items = array();

        foreach ( $this->get_leaf_sections_for_navigation() as $leaf ) {
            if ( empty( $leaf['slug'] ) ) {
                continue;
            }

            $slug = (string) $leaf['slug'];
            $path = $this->get_breadcrumb_label_for_leaf( $slug );

            $items[] = array(
                'type'    => 'section',
                'id'      => 'section-' . $slug,
                'title'   => isset( $leaf['name'] ) ? (string) $leaf['name'] : $slug,
                'path'    => $path,
                'section' => $slug,
                'icon'    => isset( $leaf['icon'] ) ? (string) $leaf['icon'] : 'fa-light fa-folder',
                'focus'   => '',
            );
        }

        foreach ( Group::instance()->get_groups_for_search() as $group_row ) {
            $section_slug = isset( $group_row['section_slug'] ) ? sanitize_key( (string) $group_row['section_slug'] ) : '';
            $group_id     = isset( $group_row['id'] ) ? sanitize_key( (string) $group_row['id'] ) : '';
            $gtitle       = isset( $group_row['title'] ) ? (string) $group_row['title'] : '';

            if ( ! $section_slug || ! $group_id || $gtitle === '' ) {
                continue;
            }

            $leaf  = $this->get_section_by_slug( $section_slug );
            $path  = $this->get_breadcrumb_label_for_leaf( $section_slug );
            $icon  = ( $leaf && isset( $leaf['icon'] ) ) ? (string) $leaf['icon'] : 'fa-light fa-layer-group';

            $g_chain = Group::instance()->get_group_breadcrumb_titles( $section_slug, $group_id );
            if ( count( $g_chain ) > 1 ) {
                $path = $path . ' -> ' . implode( ' -> ', array_slice( $g_chain, 0, -1 ) );
            }

            $items[] = array(
                'type'    => 'group',
                'id'      => 'group-' . $group_id,
                'title'   => $gtitle,
                'path'    => $path,
                'section' => $section_slug,
                'icon'    => $icon,
                'focus'   => 'group:' . $group_id,
            );
        }

        $search_field_rows = array_merge(
            Select::get_all_fields_for_search(),
            DynamicObject::get_all_fields_for_search(),
            ImageSelect::get_all_fields_for_search(),
            Switcher::get_all_fields_for_search(),
            CheckboxControl::get_all_fields_for_search(),
            Color::get_all_fields_for_search(),
            Input::get_all_fields_for_search(),
            DateField::get_all_fields_for_search(),
            DateTimeField::get_all_fields_for_search(),
            Range::get_all_fields_for_search(),
            ButtonGroup::get_all_fields_for_search(),
            Typography::get_all_fields_for_search(),
            BackgroundControl::get_all_fields_for_search(),
            BorderControl::get_all_fields_for_search(),
            ShadowControl::get_all_fields_for_search(),
            GradientControl::get_all_fields_for_search(),
            CodeEditor::get_all_fields_for_search(),
            LinkColor::get_all_fields_for_search()
        );

        foreach ( $search_field_rows as $field ) {
            $section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
            $fid          = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
            $ftitle       = isset( $field['title'] ) ? (string) $field['title'] : '';

            if ( ! $section_slug || ! $fid ) {
                continue;
            }

            $base = $this->get_breadcrumb_label_for_leaf( $section_slug );
            $path = $base;

            if ( ! empty( $field['group'] ) ) {
                $chain = Group::instance()->get_group_breadcrumb_titles( $section_slug, (string) $field['group'] );
                if ( ! empty( $chain ) ) {
                    $path = $base . ' -> ' . implode( ' -> ', $chain );
                }
            }

            $leaf = $this->get_section_by_slug( $section_slug );
            $icon = ( $leaf && isset( $leaf['icon'] ) ) ? (string) $leaf['icon'] : 'fa-light fa-sliders';

            $items[] = array(
                'type'    => 'field',
                'id'      => 'field-' . $fid,
                'title'   => $ftitle,
                'path'    => $path,
                'section' => $section_slug,
                'icon'    => $icon,
                'focus'   => 'field:' . $fid,
            );
        }

        /**
         * Filter search index for Theme Settings quick search.
         *
         * @param array<int, array<string, mixed>> $items
         * @param Menu                             $menu
         */
        return apply_filters( 'sto_search_items', $items, $this );
    }

    /**
     * @param string               $name
     * @param string               $slug
     * @param string               $icon
     * @param array<string, mixed> $args Optional. `nav_locked` => true keeps the row last in the sidebar (plugin-owned).
     */
    public function add_section( $name, $slug, $icon, $args = array() ) {
        $args       = is_array( $args ) ? $args : array();
        $nav_locked = ! empty( $args['nav_locked'] );
        $this->sections[] = array(
            'name'       => $name,
            'slug'       => $slug,
            'icon'       => $icon,
            'nav_locked' => $nav_locked,
        );
    }

    /**
     * Top-level sections for WP submenu + in-page sidebar: unlocked first, `nav_locked` last.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_sections_for_navigation() {
        $unlocked = array();
        $locked   = array();
        foreach ( $this->sections as $section ) {
            if ( ! empty( $section['nav_locked'] ) ) {
                $locked[] = $section;
            } else {
                $unlocked[] = $section;
            }
        }

        return array_merge( $unlocked, $locked );
    }

    public function add_sub_section($name, $slug, $icon, $parent_slug) {
        $this->sub_sections[] = array(
            'name' => $name,
            'slug' => $slug,
            'icon' => $icon,
            'parent_slug' => $parent_slug,
        );
    }

    public function get_sub_sections() {
        return $this->sub_sections;
    }

    public function get_sections() {
        return $this->sections;
    }

    public function get_parent_menu_slug() {
        return $this->parent_menu_slug;
    }

    private function get_current_section_slug() {
        $selected_slug = '';

        if ( isset( $_GET['section'] ) ) {
            $selected_slug = sanitize_key( wp_unslash( $_GET['section'] ) );
        }

        if ( ! $selected_slug && isset( $_GET['page'] ) ) {
            $page = wp_unslash( $_GET['page'] );
            if ( strpos( $page, '&section=' ) !== false ) {
                $parts = explode( '&section=', $page );
                if ( isset( $parts[1] ) ) {
                    $selected_slug = sanitize_key( $parts[1] );
                }
            }
        }

        if ( ! $selected_slug ) {
            $selected_slug = ! empty( $this->sections ) ? $this->sections[0]['slug'] : '';
        }

        $resolved = $this->resolve_to_first_leaf_slug( $selected_slug );
        $leaf_map = $this->get_leaf_section_slug_map();

        if ( $resolved !== '' && isset( $leaf_map[ $resolved ] ) ) {
            return $resolved;
        }

        // Orphan `section` query (removed parent/placeholder) — fall back so the UI never targets a non-leaf slug.
        return $this->get_default_leaf_section_slug();
    }

    private function get_current_section() {
        $current_slug = $this->get_current_section_slug();

        return $this->get_section_by_slug( $current_slug );
    }

    private function get_section_by_slug( $slug ) {
        foreach ( $this->sections as $section ) {
            if ( $section['slug'] === $slug ) {
                return $section;
            }
        }

        foreach ( $this->sub_sections as $sub_section ) {
            if ( $sub_section['slug'] === $slug ) {
                return $sub_section;
            }
        }

        return null;
    }

    private function get_sub_sections_by_parent_slug( $parent_slug ) {
        return array_values(
            array_filter(
                $this->sub_sections,
                function ( $sub_section ) use ( $parent_slug ) {
                    return isset( $sub_section['parent_slug'] ) && $sub_section['parent_slug'] === $parent_slug;
                }
            )
        );
    }

    private function resolve_to_first_leaf_slug( $slug ) {
        $current_slug = $slug;

        while ( $current_slug ) {
            $children = $this->get_sub_sections_by_parent_slug( $current_slug );
            if ( empty( $children ) ) {
                break;
            }

            $current_slug = $children[0]['slug'];
        }

        return $current_slug;
    }

    private function section_has_children( $slug ) {
        return ! empty( $this->get_sub_sections_by_parent_slug( $slug ) );
    }

    /**
     * Navigable panels (top-level sections without children, or leaf subsections).
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_leaf_sections() {
        $items = array_merge( $this->sections, $this->sub_sections );

        return array_values(
            array_filter(
                $items,
                function ( $item ) {
                    return isset( $item['slug'] ) && ! $this->section_has_children( $item['slug'] );
                }
            )
        );
    }

    /**
     * Leaf panels for the options form: same as {@see get_leaf_sections()} but `nav_locked` sections last.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_leaf_sections_for_navigation() {
        $leaves   = $this->get_leaf_sections();
        $unlocked = array();
        $locked   = array();
        foreach ( $leaves as $leaf ) {
            $slug = isset( $leaf['slug'] ) ? sanitize_key( (string) $leaf['slug'] ) : '';
            if ( $slug === '' ) {
                continue;
            }
            $meta = $this->get_section_by_slug( $slug );
            if ( $meta && ! empty( $meta['nav_locked'] ) ) {
                $locked[] = $leaf;
            } else {
                $unlocked[] = $leaf;
            }
        }

        return array_merge( $unlocked, $locked );
    }

    /**
     * Admin URL for Theme Settings. Always includes `section` for a navigable leaf (resolves parents to first leaf).
     *
     * @param string $section_slug Section or parent slug, or empty for default (first top-level branch → first leaf).
     */
    public function get_theme_settings_url( $section_slug = '' ) {
        $page = $this->get_parent_menu_slug();
        if ( $page === '' ) {
            return admin_url();
        }

        $url = admin_url( 'admin.php?page=' . rawurlencode( $page ) );
        $section_slug = sanitize_key( (string) $section_slug );
        $base_slug    = $section_slug;
        if ( $base_slug === '' && ! empty( $this->sections ) ) {
            $base_slug = (string) $this->sections[0]['slug'];
        }
        $leaf = $base_slug !== '' ? $this->resolve_to_first_leaf_slug( $base_slug ) : '';
        if ( $leaf !== '' ) {
            $url = add_query_arg( 'section', $leaf, $url );
        }

        return $url;
    }

    /**
     * If `section` is missing or is a parent slug, redirect to the canonical leaf URL (matches panel content + WP submenu highlight).
     */
    public function redirect_theme_settings_to_canonical_leaf() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $slug = $this->get_parent_menu_slug();
        if ( $slug === '' || ! isset( $_GET['page'] ) ) {
            return;
        }

        if ( sanitize_key( wp_unslash( $_GET['page'] ) ) !== $slug ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['sto_save_options'] ) ) {
            return;
        }

        $requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        $leaf      = $this->get_current_section_slug();

        if ( $leaf === '' || $requested === $leaf ) {
            return;
        }

        wp_safe_redirect( $this->get_theme_settings_url( $leaf ) );
        exit;
    }

    /**
     * First leaf when `section` is omitted (first registered top-level branch, fully resolved).
     *
     * @return string
     */
    public function get_default_leaf_section_slug() {
        if ( empty( $this->sections ) ) {
            return '';
        }

        return $this->resolve_to_first_leaf_slug( (string) $this->sections[0]['slug'] );
    }

    /**
     * Registered top-level submenu slug for WP admin menu current-item styling (walks up to a registered submenu row).
     */
    public function get_wp_submenu_highlight_slug_for_leaf( $leaf_slug ) {
        $leaf_slug = sanitize_key( (string) $leaf_slug );
        if ( $leaf_slug === '' ) {
            return '';
        }

        $current = $leaf_slug;
        for ( $i = 0; $i < 25 && $current !== ''; $i++ ) {
            foreach ( $this->sections as $sec ) {
                if ( isset( $sec['slug'] ) && $sec['slug'] === $current ) {
                    return $current;
                }
            }

            $parent = '';
            foreach ( $this->sub_sections as $sub ) {
                if ( isset( $sub['slug'], $sub['parent_slug'] ) && $sub['slug'] === $current ) {
                    $parent = sanitize_key( (string) $sub['parent_slug'] );
                    break;
                }
            }

            if ( $parent === '' ) {
                return $leaf_slug;
            }

            $current = $parent;
        }

        return $leaf_slug;
    }

    /**
     * Subsections registered under a top-level section slug.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_sub_sections_for_parent( $parent_slug ) {
        return $this->get_sub_sections_by_parent_slug( sanitize_key( (string) $parent_slug ) );
    }

    private function render_section_panel( $section, $current_section_slug ) {
        $is_active = $current_section_slug === $section['slug'];
        ?>
        <div
            class="sto-option-panel-section <?php echo esc_attr( $is_active ? 'sto-is-active' : 'sto-is-hidden' ); ?>"
            data-section="<?php echo esc_attr( $section['slug'] ); ?>"
        >
            <fieldset class="sto-panel-section-fields"<?php echo $is_active ? '' : ' disabled'; ?>>
            <?php
            /**
             * Render content for a section slug.
             *
             * Developers can hook here and output section-specific fields/UI.
             */
            do_action( 'sto_render_section_content', $section['slug'], $section, $this );
            if ( ! has_action( 'sto_render_section_content' ) ) :
                ?>
                <p class="sto-option-panel-section-placeholder">
                    <?php
                    printf(
                        /* translators: %s is section name. */
                        esc_html__( 'Add fields for "%s" by hooking into sto_render_section_content.', 'simple-theme-options' ),
                        esc_html( $section['name'] )
                    );
                    ?>
                </p>
                <?php
            endif;
            ?>
            </fieldset>
        </div>
        <?php
    }


    private function item_contains_active_descendant( $item_slug, $current_slug ) {
        $children = $this->get_sub_sections_by_parent_slug( $item_slug );
        if ( empty( $children ) ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( $child['slug'] === $current_slug || $this->item_contains_active_descendant( $child['slug'], $current_slug ) ) {
                return true;
            }
        }

        return false;
    }

    private function render_sidebar_item( $item, $current_section_slug, $is_child = false ) {
        $slug              = $item['slug'];
        $has_children      = $this->section_has_children( $slug );
        // Parents with children use href → first leaf but must never show leaf-active (JS + CSS rely on slug match).
        $is_active         = ( $current_section_slug === $slug ) && ! $has_children;
        $is_open           = $has_children && ( $current_section_slug === $slug || $this->item_contains_active_descendant( $slug, $current_section_slug ) );
        $li_classes        = array( 'sto-option-panel-sidebar-item' );
        $link_classes      = array( 'sto-option-panel-sidebar-item-link' );
        $children_classes  = array( 'sto-option-panel-sidebar-children' );

        if ( $is_active ) {
            $li_classes[]   = 'sto-is-active';
            $link_classes[] = 'sto-is-active';
        } elseif ( ! $is_child && $has_children && $this->item_contains_active_descendant( $slug, $current_section_slug ) ) {
            $li_classes[]   = 'sto-is-parent-active';
            $link_classes[] = 'sto-is-parent-active';
        }

        if ( $has_children ) {
            $li_classes[]       = 'sto-has-children';
            $children_classes[] = $is_open ? 'sto-is-open' : 'sto-is-collapsed';
        }

        if ( $is_child ) {
            $li_classes[] = 'sto-is-child';
        }

        if ( ! empty( $item['nav_locked'] ) ) {
            $li_classes[] = 'sto-option-panel-sidebar-item--nav-locked';
        }

        $toggle_icon = 'fa-light fa-angle-down';
        if ( $has_children ) {
            $toggle_icon = $is_open ? 'fa-light fa-angle-up' : 'fa-light fa-angle-down';
        }

        $href_section = $has_children ? $this->resolve_to_first_leaf_slug( $slug ) : $slug;
        ?>
        <li class="<?php echo esc_attr( implode( ' ', $li_classes ) ); ?>" data-sto-section="<?php echo esc_attr( $slug ); ?>">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->get_parent_menu_slug() . '&section=' . $href_section ) ); ?>" class="<?php echo esc_attr( implode( ' ', $link_classes ) ); ?>">
                <i class="sto-option-panel-icon <?php echo esc_attr( $item['icon'] ); ?>"></i>
                <span class="sto-option-panel-sidebar-item-title"><?php echo esc_html( $item['name'] ); ?></span>
                <?php if ( ! empty( $item['nav_locked'] ) ) { ?>
                    <span class="screen-reader-text"><?php esc_html_e( '(Plugin section)', 'simple-theme-options' ); ?></span>
                <?php } ?>
                <?php if ( $has_children ) { ?>
                    <span class="sto-option-panel-sidebar-toggle <?php echo esc_attr( $toggle_icon ); ?>" aria-hidden="true"></span>
                <?php } ?>
            </a>
            <?php if ( $has_children ) { ?>
                <ul class="<?php echo esc_attr( implode( ' ', $children_classes ) ); ?>">
                    <?php foreach ( $this->get_sub_sections_by_parent_slug( $slug ) as $child ) { ?>
                        <?php $this->render_sidebar_item( $child, $current_section_slug, true ); ?>
                    <?php } ?>
                </ul>
            <?php } ?>
        </li>
        <?php
    }

    public function get_section_markup( $section_slug = '' ) {
        $current_section_slug = $this->get_current_section_slug();
        $current_section      = $this->get_current_section();
        $content_title          = $this->get_leaf_breadcrumb_label( $current_section_slug );
        if ( $content_title === '' ) {
            $content_title = $current_section && isset( $current_section['name'] ) ? $current_section['name'] : __( 'Theme Settings', 'simple-theme-options' );
        }
        $content_icon    = $current_section && isset( $current_section['icon'] ) ? $current_section['icon'] : 'fa-light fa-circle-question';
        $leaf_sections   = $this->get_leaf_sections_for_navigation();
        $default_leaf    = $this->get_default_leaf_section_slug();

        ob_start();
        ?>
        <div class="wrap sto-section-content">
            <div class="sto-option-panel-wrapper" data-sto-default-leaf="<?php echo esc_attr( $default_leaf ); ?>">
                <div class="spo-option-panel-head sto-panel-head-with-search">
                    <h1 class="sto-option-panel-title"><?php esc_html_e( 'Theme Settings', 'simple-theme-options' ); ?></h1>
                    <div class="sto-quick-search" data-sto-quick-search>
                        <div class="sto-quick-search-field">
                            <span class="sto-quick-search-icon-wrap" aria-hidden="true">
                                <i class="sto-quick-search-icon fa-light fa-magnifying-glass"></i>
                            </span>
                            <input
                                type="search"
                                class="sto-quick-search-input"
                                placeholder="<?php esc_attr_e( 'Start typing to find options…', 'simple-theme-options' ); ?>"
                                autocomplete="off"
                                aria-autocomplete="list"
                                aria-controls="sto-quick-search-results"
                                aria-expanded="false"
                                id="sto-quick-search-input"
                            />
                        </div>
                        <div
                            class="sto-quick-search-results"
                            id="sto-quick-search-results"
                            role="listbox"
                            aria-labelledby="sto-quick-search-input"
                            hidden
                        ></div>
                    </div>
                </div>
                <div class="sto-option-panel-body">
                    <div class="sto-option-panel-nav-layout">
                        <div class="sto-option-panel-sidebar-wrap">
                            <ul class="sto-option-panel-sidebar" role="navigation" aria-label="<?php esc_attr_e( 'Theme Settings sections', 'simple-theme-options' ); ?>">
                                <?php foreach ( $this->get_sections_for_navigation() as $section ) { ?>
                                    <?php $this->render_sidebar_item( $section, $current_section_slug ); ?>
                                <?php } ?>
                            </ul>
                        </div>
                        <div class="sto-option-panel-main">
                            <div class="sto-option-panel-content-head">
                                <span class="sto-option-panel-content-icon-wrap">
                                    <i class="<?php echo esc_attr( $content_icon ); ?> sto-option-panel-content-icon"></i>
                                </span>
                                <h2 class="sto-option-panel-content-title"><?php echo esc_html( $content_title ); ?></h2>
                            </div>
                            <?php if ( isset( $_GET['sto_saved'] ) && sanitize_text_field( wp_unslash( $_GET['sto_saved'] ) ) === '1' ) { ?>
                                <div class="sto-save-notice"><?php esc_html_e( 'Settings are successfully saved.', 'simple-theme-options' ); ?></div>
                            <?php } ?>
                            <?php
                            $sto_val_err = isset( $_GET['sto_validation_error'] ) ? sanitize_text_field( wp_unslash( $_GET['sto_validation_error'] ) ) : '';
                            if ( $sto_val_err === '1' ) {
                                $verr = get_transient( $this->get_validation_notice_transient_name() );
                                delete_transient( $this->get_validation_notice_transient_name() );
                                $vslug = is_array( $verr ) && isset( $verr['section'] ) ? sanitize_key( (string) $verr['section'] ) : '';
                                $vmsgs = is_array( $verr ) && isset( $verr['messages'] ) && is_array( $verr['messages'] ) ? $verr['messages'] : array();
                                if ( $vslug === $current_section_slug && ! empty( $vmsgs ) ) {
                                    ?>
                                    <div class="sto-validation-notice" role="alert">
                                        <p class="sto-validation-notice-title"><?php esc_html_e( 'This section could not be saved yet', 'simple-theme-options' ); ?></p>
                                        <p class="sto-validation-notice-lead"><?php esc_html_e( 'Fix the following, then try Save again:', 'simple-theme-options' ); ?></p>
                                        <ul class="sto-validation-notice-list">
                                            <?php foreach ( $vmsgs as $one ) { ?>
                                                <li><?php echo esc_html( (string) $one ); ?></li>
                                            <?php } ?>
                                        </ul>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                            <?php
                            $sto_form_action = $this->get_theme_settings_url( $current_section_slug );
                            $sto_form_action = remove_query_arg( array( 'sto_saved', 'sto_validation_error' ), $sto_form_action );
                            ?>
                            <form id="sto-theme-settings-options-form" method="post" class="sto-options-form" action="<?php echo esc_url( $sto_form_action ); ?>">
                                <?php wp_nonce_field( 'sto_save_options_action', 'sto_save_options_nonce' ); ?>
                                <input type="hidden" name="sto_ts_page" value="<?php echo esc_attr( $this->get_parent_menu_slug() ); ?>" />
                                <input type="hidden" name="sto_ts_section" value="<?php echo esc_attr( $current_section_slug ); ?>" />
                                <div class="sto-option-panel-content-body">
                                    <?php foreach ( $leaf_sections as $section ) { ?>
                                        <?php $this->render_section_panel( $section, $current_section_slug ); ?>
                                    <?php } ?>
                                </div>
                                <div class="sto-options-form-footer sto-section-actions">
                                    <button type="submit" form="sto-theme-settings-options-form" name="sto_save_options" value="1" class="button button-primary">
                                        <i class="fa-light fa-floppy-disk" aria-hidden="true"></i>
                                        <?php esc_html_e( 'Save options', 'simple-theme-options' ); ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    

    public function render_menu_page() {
        echo $this->get_section_markup();
    }
}