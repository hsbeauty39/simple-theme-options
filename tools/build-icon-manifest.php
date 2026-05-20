<?php
/**
 * CLI utility: regenerate assets/admin/data/sto-icon-select-manifest.json
 *
 * Usage (from plugin root): php tools/build-icon-manifest.php
 *
 * Excluded from WordPress.org zip via `.distignore`.
 *
 * @package SimpleThemeOptions
 */

if ( ! defined( 'ABSPATH' ) && 'cli' === PHP_SAPI ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return void
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- CLI-only; excluded from distribution zip via `.distignore`.
function sto_build_icon_manifest_run() {
	$icon_names = array(
		'house', 'user', 'gear', 'magnifying-glass', 'check', 'xmark', 'heart', 'star', 'cart-shopping', 'image',
		'pen', 'trash', 'link', 'download', 'upload', 'envelope', 'phone', 'calendar-days', 'clock', 'map',
		'location-dot', 'bars', 'arrow-right', 'chevron-down', 'circle', 'play', 'pause', 'home', 'file', 'folder',
		'bookmark', 'tag', 'fire', 'bolt', 'cloud', 'wifi', 'lock', 'unlock-keyhole', 'eye', 'eye-slash',
		'palette', 'brush', 'camera', 'video', 'music', 'code', 'terminal', 'bug', 'shield-halved', 'trophy',
		'gem', 'leaf', 'sun', 'moon', 'droplet', 'snowflake', 'umbrella', 'mug-hot', 'pizza-slice', 'utensils',
		'car', 'plane', 'ship', 'train', 'bicycle', 'tree', 'mountain', 'city', 'globe', 'flag', 'language',
		'quote-left', 'hashtag', 'at', 'ribbon', 'gift', 'hands', 'thumbs-up', 'thumbs-down', 'face-smile',
		'face-frown', 'store', 'bag-shopping', 'credit-card', 'money-bill', 'percent', 'filter', 'sliders',
		'layer-group', 'table-cells', 'chart-line', 'chart-pie', 'spinner', 'rotate-right', 'arrows-rotate',
		'compress', 'expand', 'up-right-from-square', 'align-left', 'align-center', 'align-right', 'list',
		'table', 'keyboard', 'print', 'paper-plane', 'bell', 'inbox', 'paperclip', 'floppy-disk', 'pencil',
		'wrench', 'hammer', 'screwdriver', 'key', 'copy', 'paste', 'clone', 'plus', 'minus', 'circle-plus',
		'circle-minus', 'question', 'circle-question', 'info', 'triangle-exclamation',
	);
	$manifest_icons = array();
	foreach ( $icon_names as $icon_name ) {
		foreach ( array( array( 'fa-solid', 'solid' ), array( 'fa-regular', 'regular' ), array( 'fa-light', 'light' ) ) as $style_pair ) {
			$manifest_icons[] = array(
				'c' => $style_pair[0] . ' fa-' . $icon_name,
				'n' => $icon_name,
				'g' => $style_pair[1],
			);
		}
	}
	foreach ( array( 'wordpress', 'woocommerce', 'html5', 'css3-alt', 'js', 'php', 'facebook', 'instagram', 'youtube', 'github', 'apple', 'google' ) as $brand_icon_name ) {
		$manifest_icons[] = array(
			'c' => 'fa-brands fa-' . $brand_icon_name,
			'n' => $brand_icon_name,
			'g' => 'brands',
		);
	}
	$manifest_path = dirname( __DIR__ ) . '/assets/admin/data/sto-icon-select-manifest.json';
	$manifest_dir  = dirname( $manifest_path );
	if ( ! is_dir( $manifest_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- CLI build script only.
		mkdir( $manifest_dir, 0755, true );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI build script only.
	file_put_contents( $manifest_path, json_encode( array( 'version' => 1, 'icons' => $manifest_icons ), JSON_UNESCAPED_SLASHES ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI stdout.
	echo 'Wrote ' . count( $manifest_icons ) . ' icons to ' . $manifest_path . "\n";
}

if ( 'cli' === PHP_SAPI ) {
	sto_build_icon_manifest_run();
}
