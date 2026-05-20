<?php
/**
 * Curated Google Fonts metadata (family → variants). Extend via filter `sto_typography_font_catalog`.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Data;

defined( 'ABSPATH' ) || exit;

final class TypographyFontsCatalog {

	/**
	 * @return array<string, array{variants: array<int, string>, category: string}>
	 */
	public static function get_fonts_map() {
		$fonts = array(
			'Roboto'            => array(
				'category' => 'sans-serif',
				'variants' => array( '100', '100italic', '300', '300italic', 'regular', 'italic', '500', '500italic', '700', '700italic', '900', '900italic' ),
			),
			'Open Sans'         => array(
				'category' => 'sans-serif',
				'variants' => array( '300', '300italic', 'regular', 'italic', '500', '500italic', '600', '600italic', '700', '700italic', '800', '800italic' ),
			),
			'Lato'              => array(
				'category' => 'sans-serif',
				'variants' => array( '100', '100italic', '300', '300italic', 'regular', 'italic', '700', '700italic', '900', '900italic' ),
			),
			'Montserrat'        => array(
				'category' => 'sans-serif',
				'variants' => array( '100', '100italic', '200', '200italic', '300', '300italic', 'regular', 'italic', '500', '500italic', '600', '600italic', '700', '700italic', '800', '800italic', '900', '900italic' ),
			),
			'Poppins'           => array(
				'category' => 'sans-serif',
				'variants' => array( '100', '100italic', '200', '200italic', '300', '300italic', 'regular', 'italic', '500', '500italic', '600', '600italic', '700', '700italic', '800', '800italic', '900', '900italic' ),
			),
			'Inter'             => array(
				'category' => 'sans-serif',
				'variants' => array( '100', '200', '300', 'regular', '500', '600', '700', '800', '900' ),
			),
			'Merriweather'      => array(
				'category' => 'serif',
				'variants' => array( '300', '300italic', 'regular', 'italic', '700', '700italic', '900', '900italic' ),
			),
			'Playfair Display'  => array(
				'category' => 'serif',
				'variants' => array( 'regular', 'italic', '500', '500italic', '600', '600italic', '700', '700italic', '800', '800italic', '900', '900italic' ),
			),
			'Nunito'            => array(
				'category' => 'sans-serif',
				'variants' => array( '200', '200italic', '300', '300italic', 'regular', 'italic', '500', '500italic', '600', '600italic', '700', '700italic', '800', '800italic', '900', '900italic' ),
			),
			'Source Sans 3'     => array(
				'category' => 'sans-serif',
				'variants' => array( '200', '200italic', '300', '300italic', 'regular', 'italic', '500', '500italic', '600', '600italic', '700', '700italic', '800', '800italic', '900', '900italic' ),
			),
			'Rubik'             => array(
				'category' => 'sans-serif',
				'variants' => array( '300', '300italic', 'regular', 'italic', '500', '500italic', '600', '600italic', '700', '700italic', '800', '800italic', '900', '900italic' ),
			),
			'Work Sans'         => array(
				'category' => 'sans-serif',
				'variants' => array( '100', '200', '300', 'regular', '500', '600', '700', '800', '900' ),
			),
			'DM Sans'           => array(
				'category' => 'sans-serif',
				'variants' => array( 'regular', 'italic', '500', '500italic', '700', '700italic' ),
			),
			'Alkatra'           => array(
				'category' => 'display',
				'variants' => array( 'regular', '500', '600', '700' ),
			),
			'Oswald'            => array(
				'category' => 'sans-serif',
				'variants' => array( '200', '300', 'regular', '500', '600', '700' ),
			),
			'Raleway'           => array(
				'category' => 'sans-serif',
				'variants' => array( '100', '100italic', '200', '200italic', '300', '300italic', 'regular', 'italic', '500', '500italic', '600', '600italic', '700', '700italic', '800', '800italic', '900', '900italic' ),
			),
			'Ubuntu'            => array(
				'category' => 'sans-serif',
				'variants' => array( '300', '300italic', 'regular', 'italic', '500', '500italic', '700', '700italic' ),
			),
			'Noto Sans'         => array(
				'category' => 'sans-serif',
				'variants' => array( '100', '200', '300', 'regular', '500', '600', '700', '800', '900' ),
			),
		);

		$custom = CustomFontsRegistry::get_catalog_map();
		if ( $custom !== array() ) {
			$fonts = array_merge( $fonts, $custom );
		}

		/**
		 * @param array<string, array{variants: array<int, string>, category: string, source?: string}> $fonts
		 */
		return apply_filters( 'sto_typography_font_catalog', $fonts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
	}

	/**
	 * @return array<int, array{family: string, variants: array<int, string>, category: string}>
	 */
	public static function get_fonts_list() {
		$list = array();
		foreach ( self::get_fonts_map() as $family => $meta ) {
			$row = array(
				'family'   => $family,
				'variants' => isset( $meta['variants'] ) && is_array( $meta['variants'] ) ? array_values( $meta['variants'] ) : array( 'regular' ),
				'category' => isset( $meta['category'] ) ? (string) $meta['category'] : 'sans-serif',
			);
			if ( isset( $meta['source'] ) && (string) $meta['source'] === 'custom' ) {
				$row['source'] = 'custom';
			}
			$list[] = $row;
		}

		usort(
			$list,
			static function ( $a, $b ) {
				return strcmp( (string) $a['family'], (string) $b['family'] );
			}
		);

		return $list;
	}
}
