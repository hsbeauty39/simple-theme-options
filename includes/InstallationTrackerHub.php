<?php
/**
 * Built-in STO Analytics hub (remote client sites report here automatically).
 *
 * Update the encoded key when the hub API key is regenerated on hs-beauty.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions;

defined( 'ABSPATH' ) || exit;

final class InstallationTrackerHub {

	/**
	 * Central analytics hub REST URL (client sites POST here on activate/deactivate/usage).
	 */
	public static function get_endpoint(): string {
		$endpoint = 'https://hs-beauty.teamzlab.com/wp-json/topten-track/v1/event';

		/** @var string $endpoint */
		$endpoint = apply_filters( 'sto_installation_tracker_builtin_endpoint', $endpoint );

		return is_string( $endpoint ) ? esc_url_raw( trim( $endpoint ) ) : '';
	}

	/**
	 * Hub API key (matches Topten Track on hs-beauty.teamzlab.com).
	 */
	public static function get_api_key(): string {
		$encoded = 'clU5cldFcENRTW1rTjFpYmNkRFc0d3gycTFsN0Y3T3l1N1VmSXQwQVFRZnZsMFJSdg==';
		$decoded = base64_decode( $encoded, true );
		$api_key = is_string( $decoded ) ? $decoded : '';

		/** @var string $api_key */
		$api_key = apply_filters( 'sto_installation_tracker_builtin_api_key', $api_key );

		return is_string( $api_key ) ? sanitize_text_field( trim( $api_key ) ) : '';
	}
}
