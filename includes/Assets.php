<?php
namespace SimpleThemeOptions;

use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\IconSelect\IconSelect;
use SimpleThemeOptions\Admin\Options\Fields\GalleryControl\GalleryControl;
use SimpleThemeOptions\Admin\Options\Fields\GoogleMapControl\GoogleMapControl;
use SimpleThemeOptions\Admin\Options\ImportExport\ThemeSettingsImportExport;
use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Admin\ThemeSettingsMetabox;
use SimpleThemeOptions\ViewportOptions;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class Assets {
	use SingletonTrait;

	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_scripts' ), 20 );
	}

	/**
	 * Theme options screen only (main menu and submenu share this page slug).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	private function is_sto_options_screen( $hook_suffix = '' ) {
		if ( ! is_admin() ) {
			return false;
		}

		if ( is_string( $hook_suffix ) && $hook_suffix !== '' && $hook_suffix === 'tools_page_' . ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE ) {
			return true;
		}

		$slugs = OptionsMenu::instance()->get_registered_menu_slugs();
		foreach ( $slugs as $slug ) {
			if ( is_string( $hook_suffix ) && $hook_suffix !== '' && strpos( $hook_suffix, (string) $slug ) !== false ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return $page !== '' && in_array( $page, $slugs, true );
	}

	/**
	 * Post / page editor when at least one Theme Settings root registered a metabox for this post type.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	private function is_sto_theme_settings_metabox_screen( $hook_suffix = '' ): bool {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! OptionsMenu::instance()->should_show_theme_settings_metaboxes() ) {
			return false;
		}

		if ( ! is_string( $hook_suffix ) || ( $hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php' ) ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = ( $screen && isset( $screen->post_type ) ) ? sanitize_key( (string) $screen->post_type ) : '';
		if ( $post_type === '' ) {
			return false;
		}

		foreach ( ThemeSettingsMetabox::instance()->get_roots() as $menu_slug => $cfg ) {
			if ( ThemeSettingsMetabox::instance()->menu_root_allows_post_type( (string) $menu_slug, $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Theme Settings admin page, backup tools screen, or post editor metabox.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	private function should_enqueue_theme_settings_assets( $hook_suffix = '' ): bool {
		return $this->is_sto_options_screen( $hook_suffix ) || $this->is_sto_theme_settings_metabox_screen( $hook_suffix );
	}

	public function enqueue_styles( $hook_suffix = '' ) {
		if ( ! $this->should_enqueue_theme_settings_assets( $hook_suffix ) ) {
			return;
		}

		$styles = $this->get_styles();
		foreach ( $styles as $handle => $style ) {
			$version = $this->bust_cache_version( $style['src'], $style['version'] );
			wp_enqueue_style( $handle, $style['src'], $style['deps'], $version );
		}
	}

	/**
	 * Append the source file's `filemtime` to the version string for any asset
	 * that lives inside the plugin folder, so editing CSS/JS automatically
	 * busts the browser cache without bumping `STO_VERSION` by hand.
	 *
	 * Vendor / remote URLs (outside `STO_URL`) keep their declared version.
	 *
	 * @param string $src     Full asset URL.
	 * @param string $version Declared version (e.g. `STO_VERSION`).
	 * @return string
	 */
	private function bust_cache_version( $src, $version ) {
		if ( ! is_string( $src ) || $src === '' ) {
			return (string) $version;
		}

		$prefix = (string) STO_URL;
		if ( $prefix === '' || strpos( $src, $prefix ) !== 0 ) {
			return (string) $version;
		}

		$relative = ltrim( substr( $src, strlen( $prefix ) ), '/\\' );
		if ( $relative === '' ) {
			return (string) $version;
		}

		$path = STO_PATH . $relative;
		if ( ! is_readable( $path ) ) {
			return (string) $version;
		}

		$mtime = (int) filemtime( $path );
		if ( $mtime <= 0 ) {
			return (string) $version;
		}

		return $version . '.' . $mtime;
	}

	public function get_styles() {
		return array(
			'sto-fontawesome' => array(
				'src'     => STO_URL . 'assets/admin/css/fontawesome.css',
				'deps'    => array(),
				'version' => STO_VERSION,
			),
			'sto-select2-vendor' => array(
				'src'     => STO_URL . 'assets/admin/vendor/select2/select2.min.css',
				'deps'    => array(),
				'version' => '4.0.13',
			),
			'sto-style' => array(
				'src'     => STO_URL . 'assets/admin/css/style.css',
				'deps'    => array( 'sto-fontawesome' ),
				'version' => STO_VERSION,
			),
			'sto-responsive' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-responsive.css',
				'deps'    => array( 'sto-style' ),
				'version' => STO_VERSION,
			),
			'sto-switcher' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-switcher.css',
				'deps'    => array( 'sto-style', 'sto-responsive' ),
				'version' => STO_VERSION,
			),
			'sto-image-select' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-image-select.css',
				'deps'    => array( 'sto-switcher' ),
				'version' => STO_VERSION,
			),
			'sto-button-group' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-button-group.css',
				'deps'    => array( 'sto-style', 'sto-switcher' ),
				'version' => STO_VERSION,
			),
			'sto-checkbox' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-checkbox.css',
				'deps'    => array( 'sto-style', 'sto-switcher', 'sto-responsive' ),
				'version' => STO_VERSION,
			),
			'sto-select2' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-select2.css',
				'deps'    => array( 'sto-select2-vendor', 'sto-style', 'sto-switcher', 'sto-image-select', 'sto-button-group', 'sto-checkbox', 'sto-date-field', 'sto-datetime-field', 'sto-range', 'sto-dimension-field', 'sto-gallery-field', 'sto-multi-text-field', 'sto-radio-lists-field', 'sto-alignment-field', 'sto-google-map-field', 'sto-icon-select-field', 'sto-tabs', 'sto-accordion', 'sto-import-export' ),
				'version' => STO_VERSION,
			),
			'sto-typography' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-typography.css',
				'deps'    => array( 'sto-style', 'sto-select2', 'sto-switcher', 'sto-image-select' ),
				'version' => STO_VERSION,
			),
			'sto-color' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-color.css',
				'deps'    => array( 'wp-color-picker', 'sto-style', 'sto-switcher', 'sto-image-select' ),
				'version' => STO_VERSION,
			),
			'sto-background-control' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-background-control.css',
				'deps'    => array( 'sto-color' ),
				'version' => STO_VERSION,
			),
			'sto-border-control' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-border-control.css',
				'deps'    => array( 'sto-color' ),
				'version' => STO_VERSION,
			),
			'sto-shadow-control' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-shadow-control.css',
				'deps'    => array( 'sto-color' ),
				'version' => STO_VERSION,
			),
			'sto-gradient-control' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-gradient-control.css',
				'deps'    => array( 'sto-color' ),
				'version' => STO_VERSION,
			),
			'sto-link-color' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-link-color.css',
				'deps'    => array( 'sto-color' ),
				'version' => STO_VERSION,
			),
			'sto-input' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-input.css',
				'deps'    => array( 'sto-style', 'editor-buttons', 'dashicons' ),
				'version' => STO_VERSION,
			),
			'sto-date-field' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-date-field.css',
				'deps'    => array( 'sto-style', 'sto-input' ),
				'version' => STO_VERSION,
			),
			'sto-datetime-field' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-datetime-field.css',
				'deps'    => array( 'sto-date-field' ),
				'version' => STO_VERSION,
			),
			'sto-range' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-range.css',
				'deps'    => array( 'sto-style', 'sto-switcher' ),
				'version' => STO_VERSION,
			),
			'sto-dimension-field' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-dimension-field.css',
				'deps'    => array( 'sto-style', 'sto-input' ),
				'version' => STO_VERSION,
			),
			'sto-gallery-field' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-gallery-field.css',
				'deps'    => array( 'sto-style', 'sto-switcher' ),
				'version' => STO_VERSION,
			),
			'sto-multi-text-field' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-multi-text-field.css',
				'deps'    => array( 'sto-style', 'sto-input' ),
				'version' => STO_VERSION,
			),
			'sto-radio-lists-field' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-radio-lists-field.css',
				'deps'    => array( 'sto-style', 'sto-input' ),
				'version' => STO_VERSION,
			),
			'sto-alignment-field' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-alignment-field.css',
				'deps'    => array( 'sto-style', 'sto-switcher' ),
				'version' => STO_VERSION,
			),
			'sto-leaflet' => array(
				'src'     => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
				'deps'    => array(),
				'version' => '1.9.4',
			),
			'sto-google-map-field' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-google-map-field.css',
				'deps'    => array( 'sto-style', 'sto-input', 'sto-leaflet' ),
				'version' => STO_VERSION,
			),
			'sto-icon-select-field' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-icon-select-field.css',
				'deps'    => array( 'sto-style', 'sto-fontawesome', 'dashicons' ),
				'version' => STO_VERSION,
			),
			'sto-tabs' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-tabs.css',
				'deps'    => array( 'sto-style', 'sto-switcher' ),
				'version' => STO_VERSION,
			),
			'sto-accordion' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-accordion.css',
				'deps'    => array( 'sto-style', 'sto-switcher' ),
				'version' => STO_VERSION,
			),
			'sto-import-export' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-import-export.css',
				'deps'    => array( 'sto-style' ),
				'version' => STO_VERSION,
			),
			'sto-code-editor' => array(
				'src'     => STO_URL . 'assets/admin/css/sto-code-editor.css',
				// `sto-select2` is required because the language switcher upgrades to a
				// Select2 pill — see `sto-code-editor.js` `initLangSelect2()`. Without it
				// the Select2 vendor styles wouldn't be loaded and the pill would render
				// unstyled.
				'deps'    => array( 'sto-style', 'sto-select2' ),
				'version' => STO_VERSION,
			),
		);
	}

	public function enqueue_scripts( $hook_suffix = '' ) {
		if ( ! $this->should_enqueue_theme_settings_assets( $hook_suffix ) ) {
			return;
		}

		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		$scripts = $this->get_scripts();
		foreach ( $scripts as $handle => $script ) {
			$version = $this->bust_cache_version( $script['src'], $script['version'] );
			wp_enqueue_script( $handle, $script['src'], $script['deps'], $version, $script['in_footer'] );
		}

		wp_localize_script(
			'sto-input-password',
			'stoInputPassword',
			array(
				'show' => __( 'Show password', 'simple-theme-options' ),
				'hide' => __( 'Hide password', 'simple-theme-options' ),
			)
		);

		if ( function_exists( 'wp_localize_jquery_ui_datepicker' ) ) {
			wp_localize_jquery_ui_datepicker();
		}

		$page_slug = OptionsMenu::instance()->get_request_options_menu_slug();
		if ( ! $page_slug ) {
			$slugs     = OptionsMenu::instance()->get_registered_menu_slugs();
			$page_slug = ! empty( $slugs[0] ) ? (string) $slugs[0] : 'theme-settings';
		}

		$on_post_metabox  = $this->is_sto_theme_settings_metabox_screen( $hook_suffix );
		$metabox_post_id = 0;
		if ( $on_post_metabox ) {
			global $post;
			$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$post_type = ( $screen && isset( $screen->post_type ) ) ? sanitize_key( (string) $screen->post_type ) : '';
			$picked    = '';
			foreach ( ThemeSettingsMetabox::instance()->get_roots() as $mslug => $cfg ) {
				$mslug = sanitize_key( (string) $mslug );
				if ( $mslug !== '' && ThemeSettingsMetabox::instance()->menu_root_allows_post_type( $mslug, $post_type ) ) {
					$picked = $mslug;
					break;
				}
			}
			if ( $picked !== '' ) {
				$page_slug = $picked;
			}
			if ( $post instanceof \WP_Post ) {
				$metabox_post_id = (int) $post->ID;
			}
		}

		$on_backup_settings = is_string( $hook_suffix ) && $hook_suffix === 'tools_page_' . ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE;

		$options_menu        = OptionsMenu::instance();
		$wp_submenu_for_leaf = array();
		foreach ( $options_menu->get_leaf_sections_for_navigation_for_menu_page( $page_slug ) as $leaf_row ) {
			if ( empty( $leaf_row['slug'] ) ) {
				continue;
			}
			$leaf_key = sanitize_key( (string) $leaf_row['slug'] );
			if ( $leaf_key === '' ) {
				continue;
			}
			$wp_submenu_for_leaf[ $leaf_key ] = $options_menu->get_wp_submenu_highlight_slug_for_leaf( $leaf_key );
		}

		$metabox_js = array(
			'active' => $on_post_metabox ? 1 : 0,
		);
		if ( $on_post_metabox && $metabox_post_id > 0 ) {
			$raw_base = get_edit_post_link( $metabox_post_id, 'raw' );
			$base     = is_string( $raw_base ) && $raw_base !== ''
				? remove_query_arg( array( 'sto_saved', 'sto_imported', 'sto_validation_error', 'sto-metabox-saved', 'message' ), $raw_base )
				: '';
			$metabox_js['post_id']          = $metabox_post_id;
			$metabox_js['menu_page']        = $page_slug;
			$metabox_js['post_edit_base']   = $base;
			$metabox_js['ajax_save_nonce']  = wp_create_nonce( 'sto_save_theme_options_metabox' );
			$metabox_js['ajax_action']      = 'sto_save_theme_options_metabox';
			$metabox_js['i18n']             = array(
				'saved'       => __( 'Settings saved.', 'simple-theme-options' ),
				'save_failed' => __( 'Could not save settings.', 'simple-theme-options' ),
			);
		}

		wp_localize_script(
			'display-section-on-menu',
			'simple_theme_options',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'sto_display_section_on_menu' ),
				'sto_nav'    => array(
					'default_leaf'        => $options_menu->get_default_leaf_section_slug_for_menu_page( $page_slug ),
					'wp_submenu_for_leaf' => $wp_submenu_for_leaf,
				),
				'sto_search' => array(
					'items'       => $options_menu->get_search_items_for_menu_page( $page_slug ),
					'admin_base'  => admin_url( 'admin.php' ),
					'page'        => $page_slug,
					'max_results' => 50,
				),
				'sto_metabox' => $metabox_js,
				'sto_typography' => array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'action'   => 'sto_typography_fonts',
					'nonce'    => wp_create_nonce( 'sto_typography_fonts' ),
					'i18n'     => array(
						'select_font' => __( 'Select font', 'simple-theme-options' ),
						'style'       => __( 'Style', 'simple-theme-options' ),
					),
				),
				'sto_dynamic_object' => array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'action'   => 'sto_dynamic_object_search',
					'nonce'    => wp_create_nonce( 'sto_dynamic_object_search' ),
					'i18n'     => array(
						'input_too_short' => __( 'Type at least %d characters to search for posts.', 'simple-theme-options' ),
					),
				),
				'sto_field_help'     => array(
					'loading'      => __( 'Loading preview…', 'simple-theme-options' ),
					'loading_hint' => __( 'Please wait.', 'simple-theme-options' ),
					'not_found'    => __( 'Preview not found', 'simple-theme-options' ),
					'error_hint'   => __( 'This image could not be loaded. Check the URL or your connection.', 'simple-theme-options' ),
				),
			)
		);

		if ( GoogleMapControl::instance()->registry_has_fields() ) {
			wp_localize_script(
				'sto-google-map-field',
				'stoGoogleMapField',
				array(
					'nominatim' => array(
						'email' => (string) apply_filters( 'sto_nominatim_contact_email', '' ),
					),
				)
			);
		}

		if ( IconSelect::instance()->registry_has_fields() ) {
			wp_localize_script(
				'sto-icon-select-field',
				'stoIconSelectField',
				array(
					'manifestUrl' => STO_URL . 'assets/admin/data/sto-icon-select-manifest.json',
					'dashicons'   => IconSelect::get_dashicons_for_localize(),
					'i18n'        => array(
						'noIcon' => __( 'No icon', 'simple-theme-options' ),
					),
				)
			);
		}

		wp_localize_script(
			'sto-import-export',
			'stoThemeSettingsImportExport',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'sto_theme_settings_import_export' ),
				'actionExport' => 'sto_theme_settings_export',
				'actionImport' => 'sto_theme_settings_import',
				'actionSetUiDemo'            => 'sto_theme_settings_set_ui_demo',
				'actionSetUiMetabox'        => 'sto_theme_settings_set_ui_metabox',
				'actionImportEntryRemove'    => 'sto_theme_settings_import_entry_remove',
				'actionImportEntryExport'    => 'sto_theme_settings_import_entry_export',
				'actionImportEntriesRemove'  => 'sto_theme_settings_import_entries_remove',
				'importReloadUrl'            => $on_backup_settings ? admin_url( 'tools.php?page=' . rawurlencode( ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE ) ) : '',
				'demoCapability'             => ( $options_menu->is_demo_capability_allowed() && ( $on_backup_settings || ! $options_menu->is_packaged_demo_menu() ) ) ? 1 : 0,
				'metaboxUiAvailable'         => ( ThemeSettingsMetabox::instance()->get_roots() !== array()
					&& ( ! $options_menu->is_packaged_demo_menu() || $options_menu->is_demo_mode_enabled() ) ) ? 1 : 0,
				'sectionSlug'              => ThemeSettingsImportExport::get_advance_section_slug_for_menu_page( $options_menu, $options_menu->get_request_options_menu_slug() ),
				'i18n'                     => array(
					'confirmImport'          => __( 'Merge these keys into your existing Theme Settings storage? Values in the file overwrite matching keys. Other keys on the site are left as they are. You cannot undo this.', 'simple-theme-options' ),
					'confirmImportReplace'   => __( 'Replace the entire Theme Settings option store with only the keys in this file? Every other stored key will be removed. You cannot undo this.', 'simple-theme-options' ),
					'confirmDeleteImport'    => __( 'Delete this import from the log and permanently remove every Theme Settings option key that was applied with this file from the database? This cannot be undone.', 'simple-theme-options' ),
					'confirmBulkDeleteImport' => __( 'Delete the selected imports from the log and permanently remove every Theme Settings option key that was applied with those files from the database? This cannot be undone.', 'simple-theme-options' ),
					'pasteImportLabel'       => __( 'pasted-backup.json', 'simple-theme-options' ),
					'savingDemo'             => __( 'Saving…', 'simple-theme-options' ),
					'demoSaveFailed'         => __( 'Could not save demo mode. Try again.', 'simple-theme-options' ),
					'metaboxSaveFailed'      => __( 'Could not save meta box preference. Try again.', 'simple-theme-options' ),
					'fileDownloaded'         => __( 'JSON file download started.', 'simple-theme-options' ),
					'clipboardCopied'        => __( 'Backup JSON copied to the clipboard.', 'simple-theme-options' ),
					'clipboardDenied'        => __( 'Your browser blocked clipboard access. Copy from the downloaded file instead.', 'simple-theme-options' ),
					'clipboardManualHint'    => __( 'Backup JSON is in the import box below — select all (Ctrl+A) and copy (Ctrl+C), or use Download JSON file.', 'simple-theme-options' ),
					'exportFailed'           => __( 'Could not create the export. Try again.', 'simple-theme-options' ),
					'importFailed'           => __( 'Import failed.', 'simple-theme-options' ),
					'emptyPayload'           => __( 'Add a JSON file or paste export text before importing.', 'simple-theme-options' ),
					'invalidFile'            => __( 'Could not read that file as UTF-8 text.', 'simple-theme-options' ),
					'readClipboardFailed'    => __( 'Could not read the clipboard. Paste the JSON into the box instead.', 'simple-theme-options' ),
					'importSuccessReloading' => __( 'Settings imported. Reloading…', 'simple-theme-options' ),
				),
			)
		);
	}

	/**
	 * Optional front script: viewport → breakpoint + read responsive maps by id (see `sto-viewport.js`).
	 * Enable with `add_filter( 'sto_enqueue_viewport_script', '__return_true' );` in the theme.
	 */
	public function enqueue_public_scripts() {
		if ( ! apply_filters( 'sto_enqueue_viewport_script', false ) ) {
			return;
		}

		$src = STO_URL . 'assets/public/js/sto-viewport.js';
		$ver = $this->bust_cache_version( $src, STO_VERSION );
		wp_enqueue_script( 'sto-viewport', $src, array(), $ver, true );

		$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}

		wp_localize_script(
			'sto-viewport',
			'stoViewport',
			array(
				'legacyPhoneKey' => ResponsiveConfig::LEGACY_PHONE_KEY,
				'evalBp'         => ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT,
				'config'         => ViewportOptions::get_js_config( $opts ),
			)
		);
	}

	public function get_scripts() {
		return array(
			'sto-select2-vendor' => array(
				'src'       => STO_URL . 'assets/admin/vendor/select2/select2.full.min.js',
				'deps'      => array( 'jquery' ),
				'version'   => '4.0.13',
				'in_footer' => true,
			),
			'sto-checkbox' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-checkbox.js',
				'deps'      => array( 'jquery' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-input-password' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-input-password.js',
				'deps'      => array( 'jquery' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'display-section-on-menu' => array(
				'src'       => STO_URL . 'assets/admin/js/main.js',
				'deps'      => array( 'jquery', 'sto-select2-vendor', 'sto-checkbox', 'sto-input-password', 'sto-date-field', 'sto-datetime-field', 'sto-dimension-field', 'sto-gallery-field', 'sto-multi-text-field', 'sto-radio-lists-field', 'sto-alignment-field', 'sto-google-map-field', 'sto-icon-select-field', 'sto-import-export' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-typography' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-typography.js',
				'deps'      => array( 'jquery', 'sto-select2-vendor', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-wp-color-picker-alpha' => array(
				'src'        => STO_URL . 'assets/admin/vendor/wp-color-picker-alpha/wp-color-picker-alpha.min.js',
				'deps'       => array( 'jquery', 'wp-color-picker' ),
				'version'    => '3.0.4',
				'in_footer'  => true,
			),
			'sto-color' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-color.js',
				'deps'      => array( 'jquery', 'wp-color-picker', 'sto-wp-color-picker-alpha', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-background-control' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-background-control.js',
				'deps'      => array( 'jquery', 'sto-color', 'media-editor' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-border-control' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-border-control.js',
				'deps'      => array( 'jquery', 'sto-color', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-shadow-control' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-shadow-control.js',
				'deps'      => array( 'jquery', 'sto-color', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-gradient-control' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-gradient-control.js',
				'deps'      => array( 'jquery', 'sto-color', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-link-color' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-link-color.js',
				'deps'      => array( 'jquery', 'sto-color', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-range' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-range.js',
				'deps'      => array( 'jquery', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-dimension-field' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-dimension-field.js',
				'deps'      => array( 'jquery' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-gallery-field' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-gallery-field.js',
				'deps'      => array( 'jquery', 'jquery-ui-sortable', 'media-editor' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-multi-text-field' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-multi-text-field.js',
				'deps'      => array( 'jquery', 'jquery-ui-sortable' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-radio-lists-field' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-radio-lists-field.js',
				'deps'      => array( 'jquery', 'jquery-ui-sortable' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-alignment-field' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-alignment-field.js',
				'deps'      => array( 'jquery' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-leaflet' => array(
				'src'       => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
				'deps'      => array(),
				'version'   => '1.9.4',
				'in_footer' => true,
			),
			'sto-google-map-field' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-google-map-field.js',
				'deps'      => array( 'jquery', 'sto-leaflet' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-icon-select-field' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-icon-select-field.js',
				'deps'      => array( 'jquery' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-import-export' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-import-export.js',
				'deps'      => array( 'jquery' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-date-field' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-date-field.js',
				'deps'      => array( 'jquery', 'jquery-ui-datepicker' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-datetime-field' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-datetime-field.js',
				'deps'      => array( 'jquery', 'jquery-ui-datepicker' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-tabs' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-tabs.js',
				'deps'      => array( 'jquery', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-accordion' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-accordion.js',
				'deps'      => array( 'jquery', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
			'sto-code-editor' => array(
				'src'       => STO_URL . 'assets/admin/js/sto-code-editor.js',
				// `wp.codeEditor` (CodeMirror 5) ships with WP core; the helper script `code-editor`
				// is registered as a dep so the runtime is guaranteed when this file loads.
				// `sto-select2-vendor` provides `jQuery.fn.select2` for the chrome-bar language
				// switcher upgrade — see `initLangSelect2()` in `sto-code-editor.js`.
				'deps'      => array( 'jquery', 'code-editor', 'sto-select2-vendor', 'display-section-on-menu' ),
				'version'   => STO_VERSION,
				'in_footer' => true,
			),
		);
	}
}
