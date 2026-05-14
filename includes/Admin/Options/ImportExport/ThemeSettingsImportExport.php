<?php
namespace SimpleThemeOptions\Admin\Options\ImportExport;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * **Advance** section: export / import the full **`sto_options`** map (JSON file or clipboard).
 * No option rows are registered for this leaf — updates run via **`admin-ajax.php`** only.
 */
final class ThemeSettingsImportExport {
	use SingletonTrait;

	public const SECTION_SLUG = 'advance';

	public const EXPORT_FORMAT_VERSION = 1;

	/** @var int Raw JSON body max length (bytes). */
	private const MAX_IMPORT_BYTES = 5242880;

	protected function init() {
		add_action( 'sto_render_section_content', array( $this, 'render_section_content' ), 5, 3 );
		add_action( 'wp_ajax_sto_theme_settings_export', array( $this, 'ajax_export' ) );
		add_action( 'wp_ajax_sto_theme_settings_import', array( $this, 'ajax_import' ) );
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

		$intro_id = 'sto-advance-intro';
		?>
		<div class="sto-advance-import-export" data-sto-advance-import-export="1">
			<p class="sto-advance-import-export__intro" id="<?php echo esc_attr( $intro_id ); ?>">
				<?php esc_html_e( 'Download or copy a backup of all Theme Settings, or restore a backup from a file or the clipboard. Import replaces the entire saved options map for this site.', 'simple-theme-options' ); ?>
			</p>

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
		$payload = apply_filters( 'sto_theme_settings_export_payload', $payload );

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

		update_option( 'sto_options', $options );

		wp_send_json_success(
			array(
				'message' => __( 'Settings imported. Reloading…', 'simple-theme-options' ),
			)
		);
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
