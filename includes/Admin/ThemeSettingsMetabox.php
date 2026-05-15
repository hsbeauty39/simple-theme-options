<?php
namespace SimpleThemeOptions\Admin;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a post editor metabox that mirrors the Theme Settings UI for one options root.
 *
 * Enable from {@see OptionsMenu::register()} with either:
 * - `'metabox' => array( 'post_types' => array( 'page', 'post' ), … )`, or
 * - `'enable_metabox' => true` plus optional `'metabox_post_types' => array( … )`.
 *
 * When at least one root registers a metabox, visibility is also controlled by option **`sto_theme_settings_ui_metabox_enabled`** (default **on**), toggled from **Advance** / **Tools → Simple Backup**, and {@see OptionsMenu::should_show_theme_settings_metaboxes()}.
 */
final class ThemeSettingsMetabox {
	use SingletonTrait;

	/**
	 * Serialized map of `sto_options` keys saved from the post editor Theme Settings metabox for this post.
	 * Merged over global {@see get_option( 'sto_options' )} when rendering the metabox and when building {@see OptionsMenu::get_effective_sto_options_for_post()}.
	 */
	public const POST_SETTINGS_META_KEY = '_sto_theme_settings_post';

	/** @var bool */
	private $hooks_attached = false;

	/**
	 * @var array<string, array<string, mixed>> menu slug => config (post_types, title, context, priority)
	 */
	private $roots = array();

	/**
	 * @param string               $menu_slug Sanitized `admin.php?page=` slug.
	 * @param array<string, mixed> $config    Keys: post_types (string[]), title?, context?, priority?
	 */
	public function register_root( string $menu_slug, array $config ): void {
		$menu_slug = sanitize_key( $menu_slug );
		if ( $menu_slug === '' ) {
			return;
		}

		$this->roots[ $menu_slug ] = $config;
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
	public function get_union_metabox_post_types(): array {
		$out = array();
		foreach ( $this->roots as $row ) {
			$pts = isset( $row['post_types'] ) && is_array( $row['post_types'] ) ? $row['post_types'] : array();
			foreach ( $pts as $pt ) {
				$pt = sanitize_key( (string) $pt );
				if ( $pt !== '' ) {
					$out[ $pt ] = true;
				}
			}
		}

		return array_keys( $out );
	}

	public function menu_root_allows_post_type( string $menu_slug, string $post_type ): bool {
		$menu_slug = sanitize_key( $menu_slug );
		$post_type  = sanitize_key( (string) $post_type );
		if ( $menu_slug === '' || $post_type === '' || ! isset( $this->roots[ $menu_slug ] ) ) {
			return false;
		}

		$pts = isset( $this->roots[ $menu_slug ]['post_types'] ) && is_array( $this->roots[ $menu_slug ]['post_types'] )
			? $this->roots[ $menu_slug ]['post_types']
			: array();

		return in_array( $post_type, array_map( 'sanitize_key', $pts ), true );
	}

	private function attach_hooks(): void {
		if ( $this->hooks_attached ) {
			return;
		}
		$this->hooks_attached = true;

		add_action( 'add_meta_boxes', array( $this, 'on_add_meta_boxes' ), 10, 2 );
	}

	/**
	 * @param string       $post_type Post type being edited.
	 * @param \WP_Post|null $post     Post object (present on post editor screens).
	 */
	public function on_add_meta_boxes( $post_type, $post = null ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! OptionsMenu::instance()->should_show_theme_settings_metaboxes() ) {
			return;
		}

		$post_type = sanitize_key( (string) $post_type );

		foreach ( $this->roots as $menu_slug => $cfg ) {
			if ( ! $this->menu_root_allows_post_type( $menu_slug, $post_type ) ) {
				continue;
			}

			$title = isset( $cfg['title'] ) && is_string( $cfg['title'] ) && $cfg['title'] !== ''
				? $cfg['title']
				: OptionsMenu::instance()->get_registered_menu_page_title( $menu_slug );

			$context  = isset( $cfg['context'] ) && is_string( $cfg['context'] ) ? $cfg['context'] : 'normal';
			$priority = isset( $cfg['priority'] ) && is_string( $cfg['priority'] ) ? $cfg['priority'] : 'default';

			add_meta_box(
				'sto-theme-settings-' . $menu_slug,
				$title,
				array( $this, 'render_metabox' ),
				$post_type,
				$context,
				$priority,
				array(
					'menu_slug'                          => $menu_slug,
					'__block_editor_compatible_meta_box' => true,
					'__back_compat_meta_box'               => false,
				)
			);
		}
	}

	/**
	 * @param \WP_Post $post Post object.
	 * @param mixed    $box  Meta box instance (array or object depending on WordPress version).
	 */
	public function render_metabox( $post, $box ): void {
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! OptionsMenu::instance()->should_show_theme_settings_metaboxes() ) {
			return;
		}

		$menu_slug = '';
		if ( is_array( $box ) && isset( $box['args']['menu_slug'] ) ) {
			$menu_slug = sanitize_key( (string) $box['args']['menu_slug'] );
		} elseif ( is_object( $box ) && isset( $box->args ) && is_array( $box->args ) && isset( $box->args['menu_slug'] ) ) {
			$menu_slug = sanitize_key( (string) $box->args['menu_slug'] );
		}
		if ( $menu_slug === '' || ! $this->menu_root_allows_post_type( $menu_slug, (string) $post->post_type ) ) {
			return;
		}

		echo OptionsMenu::instance()->get_metabox_panel_markup( $menu_slug, (int) $post->ID, $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
