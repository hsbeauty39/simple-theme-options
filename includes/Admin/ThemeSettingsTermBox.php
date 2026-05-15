<?php
namespace SimpleThemeOptions\Admin;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Theme Settings UI on taxonomy term add / edit screens (per-term storage).
 *
 * Enable from {@see OptionsMenu::register()} with either:
 * - `'term_metabox' => array( 'taxonomies' => array( 'category', 'product_cat' ), … )`, or
 * - `'enable_term_metabox' => true` plus optional `'term_metabox_taxonomies' => array( … )`.
 *
 * Values save to term meta {@see TERM_SETTINGS_META_KEY} for that term only (merged over global `sto_options` when editing).
 */
final class ThemeSettingsTermBox {
	use SingletonTrait;

	/**
	 * Serialized map of `sto_options` keys for one taxonomy term.
	 *
	 * @see OptionsMenu::get_effective_sto_options_for_term()
	 */
	public const TERM_SETTINGS_META_KEY = '_sto_theme_settings_term';

	/** @var bool */
	private $hooks_attached = false;

	/**
	 * @var array<string, array<string, mixed>> menu slug => config (taxonomies, title)
	 */
	private $roots = array();

	/** @var array<string, bool> */
	private $taxonomies_hooked = array();

	/**
	 * @param string               $menu_slug Sanitized `admin.php?page=` slug.
	 * @param array<string, mixed> $config    Keys: taxonomies (string[]), title?
	 */
	public function register_root( string $menu_slug, array $config ): void {
		$menu_slug = sanitize_key( $menu_slug );
		if ( $menu_slug === '' ) {
			return;
		}

		$this->roots[ $menu_slug ] = $config;

		$taxonomies = isset( $config['taxonomies'] ) && is_array( $config['taxonomies'] ) ? $config['taxonomies'] : array();
		foreach ( $taxonomies as $tax ) {
			$tax = sanitize_key( (string) $tax );
			if ( $tax !== '' && taxonomy_exists( $tax ) ) {
				$this->ensure_taxonomy_hooks( $tax );
			}
		}

		$this->attach_hooks();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_roots(): array {
		return $this->roots;
	}

	/**
	 * @return array<int, string>
	 */
	public function get_union_term_metabox_taxonomies(): array {
		$out = array();
		foreach ( $this->roots as $row ) {
			$taxes = isset( $row['taxonomies'] ) && is_array( $row['taxonomies'] ) ? $row['taxonomies'] : array();
			foreach ( $taxes as $tax ) {
				$tax = sanitize_key( (string) $tax );
				if ( $tax !== '' ) {
					$out[ $tax ] = true;
				}
			}
		}

		return array_keys( $out );
	}

	public function menu_root_allows_taxonomy( string $menu_slug, string $taxonomy ): bool {
		$menu_slug = sanitize_key( $menu_slug );
		$taxonomy  = sanitize_key( (string) $taxonomy );
		if ( $menu_slug === '' || $taxonomy === '' || ! isset( $this->roots[ $menu_slug ] ) ) {
			return false;
		}

		$taxes = isset( $this->roots[ $menu_slug ]['taxonomies'] ) && is_array( $this->roots[ $menu_slug ]['taxonomies'] )
			? $this->roots[ $menu_slug ]['taxonomies']
			: array();

		if ( ! in_array( $taxonomy, array_map( 'sanitize_key', $taxes ), true ) ) {
			return false;
		}

		return ThemeSettingsDisplayLocations::instance()->is_taxonomy_slug_enabled( $taxonomy );
	}

	/**
	 * First registered menu root that supports this taxonomy (one panel per term screen).
	 */
	public function resolve_menu_slug_for_taxonomy( string $taxonomy ): string {
		$taxonomy = sanitize_key( (string) $taxonomy );
		foreach ( $this->roots as $menu_slug => $cfg ) {
			if ( $this->menu_root_allows_taxonomy( (string) $menu_slug, $taxonomy ) ) {
				return sanitize_key( (string) $menu_slug );
			}
		}

		return '';
	}

	private function attach_hooks(): void {
		if ( $this->hooks_attached ) {
			return;
		}
		$this->hooks_attached = true;
	}

	private function ensure_taxonomy_hooks( string $taxonomy ): void {
		if ( isset( $this->taxonomies_hooked[ $taxonomy ] ) ) {
			return;
		}
		$this->taxonomies_hooked[ $taxonomy ] = true;

		add_action( "{$taxonomy}_edit_form_fields", array( $this, 'on_edit_form_fields' ), 10, 2 );
		add_action( "{$taxonomy}_add_form_fields", array( $this, 'on_add_form_fields' ), 10, 1 );
		add_action( "created_{$taxonomy}", array( $this, 'on_term_created' ), 10, 2 );
	}

	/**
	 * @param \WP_Term $term     Term being edited.
	 * @param string   $taxonomy Taxonomy slug.
	 */
	public function on_edit_form_fields( $term, $taxonomy = '' ): void {
		if ( ! $term instanceof \WP_Term || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! OptionsMenu::instance()->should_show_theme_settings_term_metaboxes() ) {
			return;
		}

		$taxonomy = sanitize_key( (string) $taxonomy );
		if ( $taxonomy === '' ) {
			$taxonomy = sanitize_key( (string) $term->taxonomy );
		}

		$menu_slug = $this->resolve_menu_slug_for_taxonomy( $taxonomy );
		if ( $menu_slug === '' ) {
			return;
		}

		$markup = OptionsMenu::instance()->get_term_panel_markup( $menu_slug, (int) $term->term_id, $taxonomy );
		if ( $markup === '' ) {
			return;
		}

		echo '<tr class="form-field sto-term-settings-row"><td colspan="2">';
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</td></tr>';
	}

	/**
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function on_add_form_fields( $taxonomy = '' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! OptionsMenu::instance()->should_show_theme_settings_term_metaboxes() ) {
			return;
		}

		$taxonomy = sanitize_key( (string) $taxonomy );
		$menu_slug = $this->resolve_menu_slug_for_taxonomy( $taxonomy );
		if ( $taxonomy === '' || $menu_slug === '' ) {
			return;
		}

		$markup = OptionsMenu::instance()->get_term_panel_markup( $menu_slug, 0, $taxonomy );
		if ( $markup === '' ) {
			return;
		}

		echo '<div class="form-field sto-term-settings-row sto-term-settings-row--add">';
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Persist Theme Settings from the add-term form after WordPress creates the term.
	 *
	 * @param int $term_id  Term ID.
	 * @param int $tt_id    Term taxonomy row ID.
	 */
	public function on_term_created( $term_id, $tt_id = 0 ): void {
		unset( $tt_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['sto_ts_term_add'] ) || ! wp_validate_boolean( wp_unslash( (string) $_POST['sto_ts_term_add'] ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['sto_options'] ) || ! is_array( $_POST['sto_options'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page = isset( $_POST['sto_ts_page'] ) ? sanitize_key( wp_unslash( (string) $_POST['sto_ts_page'] ) ) : '';
		if ( $page === '' ) {
			return;
		}

		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return;
		}

		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || ! $this->menu_root_allows_taxonomy( $page, (string) $term->taxonomy ) ) {
			return;
		}

		if ( isset( $_POST['sto_save_options_nonce'] ) ) {
			check_admin_referer( 'sto_save_options_action', 'sto_save_options_nonce' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posted_raw = wp_unslash( $_POST['sto_options'] );

		OptionsMenu::instance()->persist_term_settings_from_add_request( $page, $posted_raw, $term_id );
	}
}
