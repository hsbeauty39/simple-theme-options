<?php
/** One-off generator: php tools/build-icon-manifest.php */
$names = array(
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
$out = array();
foreach ( $names as $n ) {
	foreach ( array( array( 'fa-solid', 'solid' ), array( 'fa-regular', 'regular' ), array( 'fa-light', 'light' ) ) as $st ) {
		$out[] = array(
			'c' => $st[0] . ' fa-' . $n,
			'n' => $n,
			'g' => $st[1],
		);
	}
}
foreach ( array( 'wordpress', 'woocommerce', 'html5', 'css3-alt', 'js', 'php', 'facebook', 'instagram', 'youtube', 'github', 'apple', 'google' ) as $n ) {
	$out[] = array(
		'c' => 'fa-brands fa-' . $n,
		'n' => $n,
		'g' => 'brands',
	);
}
$path = dirname( __DIR__ ) . '/assets/admin/data/sto-icon-select-manifest.json';
$dir  = dirname( $path );
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
file_put_contents( $path, json_encode( array( 'version' => 1, 'icons' => $out ), JSON_UNESCAPED_SLASHES ) );
echo "Wrote " . count( $out ) . " icons to $path\n";
