<?php
/**
 * Configure remote STO Analytics hub (Tools → Simple Settings).
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions;

use SimpleThemeOptions\Admin\Options\ImportExport\ThemeSettingsImportExport;

defined( 'ABSPATH' ) || exit;

final class InstallationTrackerSettings {

	public const OPTION_ENDPOINT = 'sto_installation_tracker_endpoint';

	public const OPTION_API_KEY = 'sto_installation_tracker_api_key';

	public static function register_hooks(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_save_settings' ) );
	}

	/**
	 * Render hub settings card (Tools → Simple Settings only).
	 */
	public static function render_settings_card(): void {
		/**
		 * Hidden from clients by default — STO reports to the built-in hub automatically.
		 *
		 * @param bool $show Show Tools → Simple Settings hub form.
		 */
		if ( ! apply_filters( 'sto_show_installation_tracker_hub_settings', false ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$endpoint       = InstallationTracker::get_saved_endpoint();
		$api_key        = InstallationTracker::get_saved_api_key();
		$pending        = get_option( InstallationTracker::PENDING_OPTION, '' );
		$hub_on_site    = self::site_has_local_tracker_bridge();
		$can_report     = InstallationTracker::is_configured();
		$last_error     = get_transient( 'sto_installation_tracker_last_error' );
		?>
		<div class="sto-advance-card sto-advance-card--telemetry">
			<h3 class="sto-advance-card__title"><?php esc_html_e( 'Analytics hub', 'topten-simple-theme-options' ); ?></h3>
			<p class="sto-advance-card__desc">
				<?php esc_html_e( 'Send install, usage, and administrator telemetry to your central STO Analytics dashboard (any WordPress site — local, staging, or live).', 'topten-simple-theme-options' ); ?>
			</p>

			<?php if ( $hub_on_site && '' === $endpoint && '' === $api_key ) : ?>
				<p class="notice notice-info inline">
					<?php esc_html_e( 'Topten Track Plugin Installation is active on this site — reporting uses this site’s REST API automatically.', 'topten-simple-theme-options' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( is_string( $pending ) && '' !== $pending ) : ?>
				<p class="notice notice-warning inline">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: pending event type, e.g. install */
							__( 'A %s event is queued until the hub URL and API key are saved.', 'topten-simple-theme-options' ),
							$pending
						)
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( is_string( $last_error ) && '' !== $last_error ) : ?>
				<p class="notice notice-error inline"><code><?php echo esc_html( $last_error ); ?></code></p>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'sto_tracker_hub_settings', 'sto_tracker_hub_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sto_tracker_endpoint"><?php esc_html_e( 'Hub endpoint URL', 'topten-simple-theme-options' ); ?></label></th>
						<td>
							<input
								type="url"
								class="large-text code"
								name="sto_tracker_endpoint"
								id="sto_tracker_endpoint"
								value="<?php echo esc_attr( $endpoint ); ?>"
								placeholder="<?php echo esc_attr( self::default_hub_endpoint() ); ?>"
								autocomplete="off"
							/>
							<p class="description"><?php esc_html_e( 'POST URL from STO Analytics → API & privacy (ends with /wp-json/topten-track/v1/event).', 'topten-simple-theme-options' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sto_tracker_api_key"><?php esc_html_e( 'API key', 'topten-simple-theme-options' ); ?></label></th>
						<td>
							<input
								type="password"
								class="large-text code"
								name="sto_tracker_api_key"
								id="sto_tracker_api_key"
								value="<?php echo esc_attr( $api_key ); ?>"
								autocomplete="new-password"
							/>
							<p class="description"><?php esc_html_e( 'Header: X-Topten-Track-Key — copy from the hub site’s API & privacy screen.', 'topten-simple-theme-options' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="sto_tracker_hub_save" class="button button-primary" value="1"><?php esc_html_e( 'Save hub settings', 'topten-simple-theme-options' ); ?></button>
					<?php if ( $can_report ) : ?>
						<button type="submit" name="sto_tracker_hub_test" class="button button-secondary" value="1"><?php esc_html_e( 'Send test report now', 'topten-simple-theme-options' ); ?></button>
					<?php endif; ?>
				</p>
			</form>

			<?php if ( $can_report ) : ?>
				<p class="description">
					<?php esc_html_e( 'Status: configured — installs and daily heartbeats will reach your hub.', 'topten-simple-theme-options' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function default_hub_endpoint(): string {
		$default = 'https://hs-beauty.teamzlab.com/wp-json/topten-track/v1/event';

		/** @var string $default */
		$default = apply_filters( 'sto_installation_tracker_default_hub_endpoint', $default );

		return is_string( $default ) ? esc_url_raw( $default ) : '';
	}

	public static function site_has_local_tracker_bridge(): bool {
		return class_exists( '\ToptenTrackPluginInstallation\StoEndpointBridge' );
	}

	public static function maybe_save_settings(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['sto_tracker_hub_nonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['sto_tracker_hub_nonce'] ) ), 'sto_tracker_hub_settings' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['sto_tracker_hub_test'] ) ) {
			self::handle_test_report();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['sto_tracker_hub_save'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$endpoint = isset( $_POST['sto_tracker_endpoint'] ) ? esc_url_raw( wp_unslash( (string) $_POST['sto_tracker_endpoint'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$api_key = isset( $_POST['sto_tracker_api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['sto_tracker_api_key'] ) ) : '';

		update_option( self::OPTION_ENDPOINT, $endpoint, false );
		update_option( self::OPTION_API_KEY, $api_key, false );

		delete_transient( 'sto_installation_tracker_last_error' );
		InstallationTracker::flush_pending_event();

		$redirect = add_query_arg(
			array(
				'page'              => ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE,
				'section'           => ThemeSettingsImportExport::TOOLS_SECTION_BACKUP,
				'sto_tracker_saved' => '1',
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	private static function handle_test_report(): void {
		$ok = InstallationTracker::report( 'heartbeat', array( 'key' => 'manual_test' ), true );

		if ( $ok ) {
			delete_transient( 'sto_installation_tracker_last_error' );
			InstallationTracker::flush_pending_event();
		} else {
			set_transient( 'sto_installation_tracker_last_error', __( 'Test report failed — check hub URL, API key, and that the hub site allows HTTPS POST from this server.', 'topten-simple-theme-options' ), 300 );
		}

		$redirect = add_query_arg(
			array(
				'page'             => ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE,
				'section'          => ThemeSettingsImportExport::TOOLS_SECTION_BACKUP,
				'sto_tracker_test' => $ok ? 'ok' : 'fail',
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}
