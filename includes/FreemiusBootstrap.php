<?php
/**
 * Freemius SDK bootstrap (premium / self-hosted builds only).
 *
 * Omit this file from WordPress.org distribution zips (see `.distignore`).
 *
 * @package SimpleThemeOptions
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'topten_sto_resolve_admin_menu_slug' ) ) {
	/**
	 * Admin page slug Freemius should attach Account / Pricing under.
	 *
	 * Default `theme-settings` is the packaged sample menu (always registered; demo sections hide when demo mode is off).
	 * Themes may filter {@see 'sto_freemius_menu_slug'} to attach Account / Pricing under a different client root.
	 *
	 * @return string Sanitized menu slug.
	 */
	function topten_sto_resolve_admin_menu_slug(): string {
		/**
		 * @param string $menu_slug Default packaged demo slug.
		 */
		$menu_slug = (string) apply_filters( 'sto_freemius_menu_slug', 'theme-settings' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.

		$menu_slug = sanitize_key( $menu_slug );

		return $menu_slug !== '' ? $menu_slug : 'theme-settings';
	}
}

if ( ! function_exists( 'topten_sto' ) ) {
	/**
	 * Freemius SDK accessor.
	 *
	 * @return object|null
	 */
	function topten_sto() {
		global $topten_sto;

		if ( ! isset( $topten_sto ) ) {
			if ( ! defined( 'WP_FS__PRODUCT_29793_MULTISITE' ) ) {
				define( 'WP_FS__PRODUCT_29793_MULTISITE', true );
			}

			if ( ! function_exists( 'fs_dynamic_init' ) ) {
				return null;
			}

			$admin_menu_slug = topten_sto_resolve_admin_menu_slug();
			$first_path      = 'admin.php?page=' . rawurlencode( $admin_menu_slug );

			$topten_sto = fs_dynamic_init(
				array(
					'id'                  => '29793',
					'slug'                => 'topten-simple-theme-options',
					'premium_slug'        => STO_PLUGIN_SLUG,
					'type'                => 'plugin',
					'public_key'          => 'pk_15f8fadad4d1dd98a828c539eaf31',
					'is_premium'          => true,
					'premium_suffix'      => 'Premium',
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'is_org_compliant'    => true,
					'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
					'trial'               => array(
						'days'               => 3,
						'is_require_payment' => true,
					),
					'menu'                => array(
						'slug'       => $admin_menu_slug,
						'parent'     => array(
							'slug' => $admin_menu_slug,
						),
						'first-path' => $first_path,
						'support'    => false,
					),
				)
			);
		}

		return $topten_sto;
	}

	/**
	 * Init Freemius after the active theme can filter {@see 'sto_freemius_menu_slug'}.
	 */
	function topten_sto_plugins_loaded_bootstrap(): void {
		if ( topten_sto() ) {
			do_action( 'topten_sto_loaded' );
		}
	}

	add_action(
		'topten_sto_loaded',
		static function (): void {
			if ( class_exists( \SimpleThemeOptions\Admin\Freemius\StoFreemiusContact::class ) ) {
				\SimpleThemeOptions\Admin\Freemius\StoFreemiusContact::boot();
			}
			if ( class_exists( \SimpleThemeOptions\Admin\Freemius\StoFreemiusPricing::class ) ) {
				\SimpleThemeOptions\Admin\Freemius\StoFreemiusPricing::boot();
			}
		}
	);

	add_action( 'plugins_loaded', 'topten_sto_plugins_loaded_bootstrap', 5 );
}
