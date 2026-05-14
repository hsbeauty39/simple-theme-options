<?php
namespace SimpleThemeOptions\Admin\Options\ImportExport;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * **Advance** section: export / import the full **`sto_options`** map (JSON file or clipboard),
 * optional **Demo mode** UI toggle, and a log of keys from the last import.
 * No option rows are registered for this leaf — updates run via **`admin-ajax.php`** only.
 */
final class ThemeSettingsImportExport {
	use SingletonTrait;

	public const SECTION_SLUG = 'advance';

	public const EXPORT_FORMAT_VERSION = 1;

	/** Stored boolean (WP coerces); when true with {@see OptionsMenu::is_demo_capability_allowed()}, sample sections load. */
	public const OPTION_UI_DEMO_ENABLED = 'sto_theme_settings_ui_demo_enabled';

	/** @var string Last successful import: top-level `sto_options` keys + timestamp. */
	public const OPTION_LAST_IMPORT = 'sto_theme_settings_last_import';

	public const MAX_IMPORT_TABLE_ROWS = 250;

	/** @var int Raw JSON body max length (bytes). */
	private const MAX_IMPORT_BYTES = 5242880;

	protected function init() {
		add_action( 'sto_render_section_content', array( $this, 'render_section_content' ), 5, 3 );
		add_action( 'wp_ajax_sto_theme_settings_export', array( $this, 'ajax_export' ) );
		add_action( 'wp_ajax_sto_theme_settings_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_sto_theme_settings_set_ui_demo', array( $this, 'ajax_set_ui_demo' ) );
		add_action( 'wp_ajax_sto_theme_settings_import_key_remove', array( $this, 'ajax_import_key_remove' ) );
		add_action( 'wp_ajax_sto_theme_settings_import_keys_remove_all', array( $this, 'ajax_import_keys_remove_all' ) );
		add_action( 'wp_ajax_sto_theme_settings_import_subset_export', array( $this, 'ajax_import_subset_export' ) );
		add_action( 'wp_ajax_sto_theme_settings_import_log_dismiss', array( $this, 'ajax_import_log_dismiss' ) );
	}

	/**
	 * @param string               $section_slug
	 * @param array<string, mixed> $section
	 * @param OptionsMenu          $menu
	 */
	public function render_section_content( $section_slug, $section, $menu = null ) {
		unset( $menu );
		if ( sanitize_key( (string) $section_slug ) !== self::SECTION_SLUG ) {
			return;
		}

		$intro_id     = 'sto-advance-intro';
		$options_menu = OptionsMenu::instance();
		$demo_cap     = $options_menu->is_demo_capability_allowed();
		$demo_on = wp_validate_boolean( get_option( self::OPTION_UI_DEMO_ENABLED, false ) );
		$import_log   = $this->get_last_import_log();
		$log_keys     = isset( $import_log['keys'] ) && is_array( $import_log['keys'] ) ? $import_log['keys'] : array();
		$log_keys     = array_values(
			array_filter(
				array_map(
					static function ( $k ) {
						return is_string( $k ) ? sanitize_key( $k ) : '';
					},
					$log_keys
				)
			)
		);
		$imported_at = isset( $import_log['imported_at'] ) ? (string) $import_log['imported_at'] : '';
		$total_keys  = count( $log_keys );
		$table_keys  = $log_keys;
		$truncated   = false;
		if ( $total_keys > self::MAX_IMPORT_TABLE_ROWS ) {
			$table_keys = array_slice( $table_keys, 0, self::MAX_IMPORT_TABLE_ROWS );
			$truncated  = true;
		}
		?>
		<div class="sto-advance-import-export" data-sto-advance-import-export="1">
			<p class="sto-advance-import-export__intro" id="<?php echo esc_attr( $intro_id ); ?>">
				<?php esc_html_e( 'Download or copy a backup of all Theme Settings, or restore a backup from a file or the clipboard. Import replaces the entire saved options map for this site.', 'simple-theme-options' ); ?>
			</p>

			<?php if ( $demo_cap ) : ?>
			<div class="sto-advance-card sto-advance-card--demo">
				<?php
				FieldTitle::render_heading(
					__( 'Demo mode', 'simple-theme-options' ),
					'default',
					null,
					'sto-advance-demo',
					false,
					'',
					''
				);
				?>
				<p class="sto-advance-card__desc"><?php esc_html_e( 'Show or hide the packaged sample sections (Field samples, Colors & surfaces, Accordion). Off by default; the page reloads when you change this.', 'simple-theme-options' ); ?></p>
				<div class="sto-advance-demo-toggle" data-sto-advance-demo-wrap>
					<input type="hidden" id="sto-advance-demo-input" data-sto-advance-demo-input value="<?php echo $demo_on ? '1' : '0'; ?>" />
					<button
						type="button"
						class="sto-switcher<?php echo $demo_on ? ' sto-switcher--on' : ''; ?>"
						data-sto-advance-demo-switch
						aria-pressed="<?php echo $demo_on ? 'true' : 'false'; ?>"
						aria-label="<?php esc_attr_e( 'Toggle demo mode for sample Theme Settings sections', 'simple-theme-options' ); ?>"
					>
						<span class="sto-switcher__track" aria-hidden="true">
							<span class="sto-switcher__knob"></span>
							<span class="sto-switcher__label sto-switcher__label--on"><?php esc_html_e( 'ON', 'simple-theme-options' ); ?></span>
							<span class="sto-switcher__label sto-switcher__label--off"><?php esc_html_e( 'OFF', 'simple-theme-options' ); ?></span>
						</span>
					</button>
					<span class="sto-advance-demo-toggle__hint" data-sto-advance-demo-status role="status" aria-live="polite"></span>
				</div>
			</div>
			<?php else : ?>
			<div class="sto-advance-card sto-advance-card--muted">
				<p class="sto-advance-card__desc"><?php esc_html_e( 'Demo samples are turned off for this menu registration (code passed demo => false).', 'simple-theme-options' ); ?></p>
			</div>
			<?php endif; ?>

			<?php if ( $total_keys > 0 ) : ?>
			<div class="sto-advance-card sto-advance-card--import-log" data-sto-advance-import-log="1">
				<?php
				FieldTitle::render_heading(
					__( 'Last import', 'simple-theme-options' ),
					'default',
					null,
					'sto-advance-import-log',
					false,
					'',
					''
				);
				?>
				<p class="sto-advance-card__desc">
					<?php
					if ( $imported_at !== '' ) {
						/* translators: %1$d: number of option keys, %2$s: ISO datetime */
						echo esc_html( sprintf( __( '%1$d option keys were applied (%2$s). You can remove keys from the database or download a subset as JSON.', 'simple-theme-options' ), $total_keys, $imported_at ) );
					} else {
						echo esc_html( sprintf( /* translators: %d: number of keys */ __( '%d option keys were applied. You can remove keys from the database or download a subset as JSON.', 'simple-theme-options' ), $total_keys ) );
					}
					?>
				</p>
				<?php if ( $truncated ) : ?>
					<p class="sto-advance-import-log__note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %1$d: shown rows, %2$d: total keys */
								__( 'Showing the first %1$d of %2$d keys.', 'simple-theme-options' ),
								self::MAX_IMPORT_TABLE_ROWS,
								$total_keys
							)
						);
						?>
					</p>
				<?php endif; ?>
				<div class="sto-advance-table-wrap">
					<table class="sto-advance-table widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Option key', 'simple-theme-options' ); ?></th>
								<th scope="col" class="sto-advance-table__actions"><?php esc_html_e( 'Actions', 'simple-theme-options' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $table_keys as $row_key ) : ?>
								<tr data-sto-advance-import-row="<?php echo esc_attr( $row_key ); ?>">
									<td><code><?php echo esc_html( $row_key ); ?></code></td>
									<td class="sto-advance-table__actions">
										<button type="button" class="button button-small sto-advance-table-btn" data-sto-advance-import-download-key="<?php echo esc_attr( $row_key ); ?>">
											<i class="fa-light fa-download" aria-hidden="true"></i>
											<?php esc_html_e( 'Download', 'simple-theme-options' ); ?>
										</button>
										<button type="button" class="button button-small sto-advance-table-btn" data-sto-advance-import-remove-key="<?php echo esc_attr( $row_key ); ?>">
											<i class="fa-light fa-trash" aria-hidden="true"></i>
											<?php esc_html_e( 'Remove', 'simple-theme-options' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="sto-advance-card__actions sto-advance-import-log__bulk">
					<button type="button" class="button sto-advance-btn" data-sto-advance-import-download-all>
						<i class="fa-light fa-download" aria-hidden="true"></i>
						<?php esc_html_e( 'Download all listed keys', 'simple-theme-options' ); ?>
					</button>
					<button type="button" class="button sto-advance-btn" data-sto-advance-import-remove-all>
						<i class="fa-light fa-trash" aria-hidden="true"></i>
						<?php esc_html_e( 'Remove all listed keys', 'simple-theme-options' ); ?>
					</button>
					<button type="button" class="button sto-advance-btn" data-sto-advance-import-dismiss-log>
						<?php esc_html_e( 'Dismiss log', 'simple-theme-options' ); ?>
					</button>
				</div>
				<p class="sto-advance-status sto-advance-status--error" data-sto-advance-import-log-status role="alert" hidden></p>
			</div>
			<?php endif; ?>

			<div class="sto-advance-card">
				<?php
				FieldTitle::render_heading(
					__( 'Export', 'simple-theme-options' ),
					'default',
					null,
					'sto-advance-export',
					false,
					'',
					''
				);
				?>
				<p class="sto-advance-card__desc"><?php esc_html_e( 'Includes every option key stored under Theme Settings.', 'simple-theme-options' ); ?></p>
				<div class="sto-advance-card__actions">
					<button type="button" class="button button-primary sto-advance-btn" data-sto-advance-export-copy>
						<i class="fa-light fa-copy" aria-hidden="true"></i>
						<?php esc_html_e( 'Copy to clipboard', 'simple-theme-options' ); ?>
					</button>
					<button type="button" class="button sto-advance-btn" data-sto-advance-export-file>
						<i class="fa-light fa-download" aria-hidden="true"></i>
						<?php esc_html_e( 'Download JSON file', 'simple-theme-options' ); ?>
					</button>
				</div>
				<p class="sto-advance-status" data-sto-advance-export-status role="status" aria-live="polite" hidden></p>
			</div>

			<div class="sto-advance-card">
				<?php
				FieldTitle::render_heading(
					__( 'Import', 'simple-theme-options' ),
					'default',
					null,
					'sto-advance-import',
					false,
					'',
					''
				);
				?>
				<p class="sto-advance-card__desc"><?php esc_html_e( 'Use a JSON file from a previous export, or paste JSON and apply. This cannot be undone.', 'simple-theme-options' ); ?></p>

				<label class="sto-advance-dropzone" data-sto-advance-dropzone>
					<input type="file" class="sto-advance-file-input" data-sto-advance-file accept=".json,application/json" />
					<span class="sto-advance-dropzone__inner">
						<i class="fa-light fa-file-arrow-up sto-advance-dropzone__icon" aria-hidden="true"></i>
						<span class="sto-advance-dropzone__title"><?php esc_html_e( 'Drop a backup file here', 'simple-theme-options' ); ?></span>
						<span class="sto-advance-dropzone__hint"><?php esc_html_e( 'or click to choose a .json file', 'simple-theme-options' ); ?></span>
					</span>
				</label>

				<div class="sto-advance-paste-row">
					<label class="sto-advance-label" for="sto-advance-paste"><?php esc_html_e( 'Or paste exported JSON', 'simple-theme-options' ); ?></label>
					<div class="sto-advance-paste-actions">
						<button type="button" class="button sto-advance-btn" data-sto-advance-paste-clipboard>
							<i class="fa-light fa-paste" aria-hidden="true"></i>
							<?php esc_html_e( 'Read from clipboard', 'simple-theme-options' ); ?>
						</button>
					</div>
					<textarea
						id="sto-advance-paste"
						class="sto-advance-textarea"
						data-sto-advance-textarea
						rows="8"
						spellcheck="false"
						placeholder="<?php esc_attr_e( 'Paste JSON here…', 'simple-theme-options' ); ?>"
					></textarea>
				</div>

				<div class="sto-advance-card__actions sto-advance-card__actions--import">
					<button type="button" class="button button-primary sto-advance-btn" data-sto-advance-import-apply>
						<i class="fa-light fa-file-import" aria-hidden="true"></i>
						<?php esc_html_e( 'Apply import', 'simple-theme-options' ); ?>
					</button>
					<button type="button" class="button sto-advance-btn" data-sto-advance-import-clear>
						<?php esc_html_e( 'Clear', 'simple-theme-options' ); ?>
					</button>
				</div>
				<p class="sto-advance-status sto-advance-status--error" data-sto-advance-import-status role="alert" hidden></p>
			</div>
		</div>
		<?php
	}

	public function ajax_export() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export settings.', 'simple-theme-options' ) ), 403 );
		}

		$options = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$payload = $this->build_export_payload_for_options( $options );

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not build export data.', 'simple-theme-options' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'json'     => $json,
				'filename' => 'theme-settings-export-' . gmdate( 'Y-m-d-His' ) . '.json',
			)
		);
	}

	public function ajax_import() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import settings.', 'simple-theme-options' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$raw_body = isset( $_POST['import_payload'] ) ? wp_unslash( (string) $_POST['import_payload'] ) : '';
		$raw_body = is_string( $raw_body ) ? trim( $raw_body ) : '';

		if ( $raw_body === '' ) {
			wp_send_json_error( array( 'message' => __( 'Paste or upload JSON before importing.', 'simple-theme-options' ) ), 400 );
		}

		if ( strlen( $raw_body ) > self::MAX_IMPORT_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'That file is too large to import.', 'simple-theme-options' ) ), 400 );
		}

		$decoded = json_decode( $raw_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON. Use an export from this screen or the same plugin version.', 'simple-theme-options' ) ), 400 );
		}

		$options = $this->extract_options_array_from_decoded( $decoded );
		if ( ! is_array( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'The JSON must contain an options object (use a full export file).', 'simple-theme-options' ) ), 400 );
		}

		if ( ! $this->is_safe_options_tree( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'That backup uses unsupported data types or structure.', 'simple-theme-options' ) ), 400 );
		}

		/**
		 * Filter the options map immediately before it replaces `sto_options`.
		 *
		 * @param array<string, mixed> $options
		 */
		$options = apply_filters( 'sto_theme_settings_import_options_before_save', $options );
		if ( ! is_array( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'Import was blocked by a filter.', 'simple-theme-options' ) ), 400 );
		}

		$import_keys = array_keys( $options );
		update_option( 'sto_options', $options );
		$this->persist_last_import_log( $import_keys );

		wp_send_json_success(
			array(
				'message' => __( 'Settings imported. Reloading…', 'simple-theme-options' ),
			)
		);
	}

	public function ajax_set_ui_demo() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this setting.', 'simple-theme-options' ) ), 403 );
		}

		if ( ! OptionsMenu::instance()->is_demo_capability_allowed() ) {
			wp_send_json_error( array( 'message' => __( 'Demo mode is not available for this site.', 'simple-theme-options' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['ui_demo'] ) ? wp_unslash( (string) $_POST['ui_demo'] ) : '0';
		$on  = in_array( $raw, array( '1', 'true', 'yes', 'on' ), true );

		update_option( self::OPTION_UI_DEMO_ENABLED, $on );

		wp_send_json_success(
			array(
				'message' => __( 'Preference saved. Reloading…', 'simple-theme-options' ),
			)
		);
	}

	public function ajax_import_key_remove() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change settings.', 'simple-theme-options' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$key = isset( $_POST['option_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['option_key'] ) ) : '';
		if ( $key === '' ) {
			wp_send_json_error( array( 'message' => __( 'Missing option key.', 'simple-theme-options' ) ), 400 );
		}

		$log = $this->get_last_import_log();
		if ( ! $this->last_import_contains_key( $log, $key ) ) {
			wp_send_json_error( array( 'message' => __( 'That key is not part of the current import log.', 'simple-theme-options' ) ), 400 );
		}

		$this->remove_option_key_from_sto_options( $key );
		$this->remove_key_from_last_import_log( $key );

		wp_send_json_success( array( 'message' => __( 'Key removed.', 'simple-theme-options' ) ) );
	}

	public function ajax_import_keys_remove_all() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change settings.', 'simple-theme-options' ) ), 403 );
		}

		$log = $this->get_last_import_log();
		$keys = isset( $log['keys'] ) && is_array( $log['keys'] ) ? $log['keys'] : array();
		if ( $keys === array() ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to remove.', 'simple-theme-options' ) ), 400 );
		}

		$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}

		foreach ( $keys as $k ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			$k = sanitize_key( $k );
			if ( $k === '' ) {
				continue;
			}
			unset( $opts[ $k ] );
		}

		update_option( 'sto_options', $opts );
		delete_option( self::OPTION_LAST_IMPORT );

		wp_send_json_success( array( 'message' => __( 'Keys removed.', 'simple-theme-options' ) ) );
	}

	public function ajax_import_subset_export() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export settings.', 'simple-theme-options' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$scope = isset( $_POST['export_scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['export_scope'] ) ) : 'single';
		$log   = $this->get_last_import_log();
		$keys  = array();

		if ( 'all' === $scope ) {
			$keys = isset( $log['keys'] ) && is_array( $log['keys'] ) ? $log['keys'] : array();
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$one = isset( $_POST['option_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['option_key'] ) ) : '';
			if ( $one !== '' && $this->last_import_contains_key( $log, $one ) ) {
				$keys = array( $one );
			}
		}

		$keys = $this->sanitize_key_list( $keys );
		if ( $keys === array() ) {
			wp_send_json_error( array( 'message' => __( 'No keys to export.', 'simple-theme-options' ) ), 400 );
		}

		$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}

		$subset = array();
		foreach ( $keys as $k ) {
			if ( array_key_exists( $k, $opts ) ) {
				$subset[ $k ] = $opts[ $k ];
			}
		}

		$payload = $this->build_export_payload_for_options( $subset );
		$json    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not build export data.', 'simple-theme-options' ) ), 500 );
		}

		$suffix  = 'all' === $scope ? 'subset-' . count( $keys ) . '-keys' : $keys[0];
		$filename = 'theme-settings-export-' . $suffix . '-' . gmdate( 'Y-m-d-His' ) . '.json';

		wp_send_json_success(
			array(
				'json'     => $json,
				'filename' => $filename,
			)
		);
	}

	public function ajax_import_log_dismiss() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change settings.', 'simple-theme-options' ) ), 403 );
		}

		delete_option( self::OPTION_LAST_IMPORT );

		wp_send_json_success( array( 'message' => __( 'Log dismissed.', 'simple-theme-options' ) ) );
	}

	/**
	 * @param array<int|string, string> $keys
	 * @return array<int, string>
	 */
	private function sanitize_key_list( $keys ) {
		$out = array();
		if ( ! is_array( $keys ) ) {
			return $out;
		}
		foreach ( $keys as $k ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			$k = sanitize_key( $k );
			if ( $k === '' ) {
				continue;
			}
			$out[] = $k;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string, mixed> $log
	 */
	private function last_import_contains_key( array $log, string $key ): bool {
		$keys = isset( $log['keys'] ) && is_array( $log['keys'] ) ? $log['keys'] : array();
		foreach ( $keys as $k ) {
			if ( is_string( $k ) && sanitize_key( $k ) === $key ) {
				return true;
			}
		}

		return false;
	}

	private function remove_option_key_from_sto_options( string $key ): void {
		$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		unset( $opts[ $key ] );
		update_option( 'sto_options', $opts );
	}

	private function remove_key_from_last_import_log( string $key ): void {
		$log = $this->get_last_import_log();
		if ( $log === array() ) {
			return;
		}
		$keys = isset( $log['keys'] ) && is_array( $log['keys'] ) ? $log['keys'] : array();
		$next = array();
		foreach ( $keys as $k ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			if ( sanitize_key( $k ) === $key ) {
				continue;
			}
			$next[] = sanitize_key( $k );
		}
		if ( $next === array() ) {
			delete_option( self::OPTION_LAST_IMPORT );

			return;
		}
		$log['keys'] = array_values( array_unique( $next ) );
		update_option( self::OPTION_LAST_IMPORT, $log );
	}

	/**
	 * @param array<int, string> $import_keys
	 */
	private function persist_last_import_log( array $import_keys ): void {
		$keys = $this->sanitize_key_list( $import_keys );
		if ( $keys === array() ) {
			delete_option( self::OPTION_LAST_IMPORT );

			return;
		}
		update_option(
			self::OPTION_LAST_IMPORT,
			array(
				'keys'         => $keys,
				'imported_at'  => gmdate( 'c' ),
			)
		);
	}

	/**
	 * @return array{keys?: array<int, string>, imported_at?: string}
	 */
	private function get_last_import_log(): array {
		$raw = get_option( self::OPTION_LAST_IMPORT, null );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return $raw;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function build_export_payload_for_options( array $options ) {
		$payload = array(
			'sto_export_version' => self::EXPORT_FORMAT_VERSION,
			'exported_at'        => gmdate( 'c' ),
			'site_url'           => home_url( '/' ),
			'generator'          => 'Simple Theme Options',
			'options'            => $options,
		);

		/**
		 * Filter the export envelope before JSON encoding.
		 *
		 * @param array<string, mixed> $payload
		 */
		return apply_filters( 'sto_theme_settings_export_payload', $payload );
	}

	/**
	 * @param array<string, mixed> $decoded
	 * @return array<string, mixed>|null
	 */
	private function extract_options_array_from_decoded( array $decoded ) {
		if ( isset( $decoded['options'] ) && is_array( $decoded['options'] ) ) {
			return $decoded['options'];
		}

		if ( isset( $decoded['sto_export_version'] ) ) {
			return null;
		}

		// Plain map of option keys (e.g. raw `sto_options` JSON from code or another tool).
		return $decoded;
	}

	/**
	 * Reject resource, object, and absurdly deep structures.
	 *
	 * @param mixed $node
	 * @param int   $depth
	 */
	private function is_safe_options_tree( $node, $depth = 0 ) {
		if ( $depth > 60 ) {
			return false;
		}

		if ( is_null( $node ) || is_bool( $node ) || is_int( $node ) || is_float( $node ) ) {
			return true;
		}

		if ( is_string( $node ) ) {
			return strlen( $node ) <= 1000000;
		}

		if ( ! is_array( $node ) ) {
			return false;
		}

		$key_count = 0;
		foreach ( $node as $key => $child ) {
			++$key_count;
			if ( $key_count > 6000 ) {
				return false;
			}

			if ( 0 === $depth && ! is_string( $key ) ) {
				return false;
			}

			if ( is_string( $key ) ) {
				if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $key ) ) {
					return false;
				}
			} elseif ( ! is_int( $key ) ) {
				return false;
			}

			if ( ! $this->is_safe_options_tree( $child, $depth + 1 ) ) {
				return false;
			}
		}

		return true;
	}
}
