<?php
/**
 * Custom fonts panel (Tools → Simple Backup / Advance) + AJAX.
 *
 * Auto-detect flow:
 *   1. User picks a font file or ZIP from the media library.
 *   2. JS asks `sto_custom_fonts_preview` for detected family/weight/style/category
 *      (ZIP: every font file inside the archive).
 *   3. User confirms with **Add font**; server imports one or all faces.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\CustomFonts;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Data\CustomFontsRegistry;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class CustomFontsAdmin {
	use SingletonTrait;

	public const AJAX_NONCE_ACTION = CustomFontsRegistry::AJAX_NONCE_ACTION;

	protected function init(): void {
		add_filter( 'upload_mimes', array( CustomFontsRegistry::class, 'allow_font_upload_mimes' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'filter_font_filetype' ), 10, 4 );
		add_action( 'wp_ajax_sto_custom_fonts_upload', array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_sto_custom_fonts_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_sto_custom_fonts_add', array( $this, 'ajax_add' ) );
		add_action( 'wp_ajax_sto_custom_fonts_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_sto_custom_fonts_serve_preview_font', array( $this, 'ajax_serve_preview_font' ) );
		add_action( 'wp_enqueue_scripts', array( CustomFontsRegistry::class, 'enqueue_front_styles' ), 20 );
	}

	/**
	 * @param string $idsuf Suffix for element ids (`''` or `'-settings'`).
	 */
	public function render_panel( string $idsuf = '' ): void {
		$faces = CustomFontsRegistry::get_faces();
		?>
		<div class="sto-advance-card sto-advance-card--custom-fonts" data-sto-custom-fonts-panel="1">
			<?php
			FieldTitle::render_heading(
				__( 'Custom fonts', 'topten-simple-theme-options' ),
				'default',
				null,
				'sto-advance-custom-fonts' . $idsuf,
				false,
				'',
				''
			);
			?>
			<p class="sto-advance-card__desc">
				<?php esc_html_e( 'Drag and drop a font file or ZIP here, or use the buttons below. ZIP archives are scanned for font files in any folder; family name, weight, style, and category are detected automatically.', 'topten-simple-theme-options' ); ?>
			</p>

			<form class="sto-custom-fonts-form" data-sto-custom-fonts-form onsubmit="return false;">
				<input type="hidden" data-sto-custom-font-attachment value="" />

				<label class="sto-advance-dropzone sto-custom-fonts-dropzone" data-sto-custom-font-dropzone>
					<input
						type="file"
						class="sto-advance-file-input"
						data-sto-custom-font-file
						accept=".woff2,.woff,.ttf,.otf,.eot,.zip,font/woff2,font/woff,font/ttf,font/otf,application/zip"
					/>
					<span class="sto-advance-dropzone__inner">
						<i class="fa-light fa-file-arrow-up sto-advance-dropzone__icon" aria-hidden="true"></i>
						<span class="sto-advance-dropzone__title"><?php esc_html_e( 'Drop a font or ZIP file here', 'topten-simple-theme-options' ); ?></span>
						<span class="sto-advance-dropzone__hint"><?php esc_html_e( 'or click to browse — WOFF2, WOFF, TTF, OTF, EOT, or ZIP', 'topten-simple-theme-options' ); ?></span>
					</span>
				</label>

				<div class="sto-advance-card__actions sto-custom-fonts-form__actions">
					<button type="button" class="button sto-advance-btn" data-sto-custom-font-pick>
						<i class="fa-light fa-file-arrow-up" aria-hidden="true"></i>
						<?php esc_html_e( 'Choose font or ZIP', 'topten-simple-theme-options' ); ?>
					</button>
					<button type="button" class="button button-primary sto-advance-btn" data-sto-custom-font-add disabled>
						<i class="fa-light fa-plus" aria-hidden="true"></i>
						<span data-sto-custom-font-add-label><?php esc_html_e( 'Add font', 'topten-simple-theme-options' ); ?></span>
					</button>
				</div>

				<?php $this->render_live_preview_section( $faces ); ?>

				<div class="sto-custom-fonts-preview" data-sto-custom-fonts-preview hidden>
					<div class="sto-custom-fonts-preview__row">
						<span class="sto-custom-fonts-preview__label"><?php esc_html_e( 'Source', 'topten-simple-theme-options' ); ?></span>
						<span class="sto-custom-fonts-preview__value" data-sto-preview-file></span>
					</div>

					<div class="sto-custom-fonts-preview__single" data-sto-preview-single>
						<div class="sto-custom-fonts-preview__row">
							<span class="sto-custom-fonts-preview__label"><?php esc_html_e( 'Detected family', 'topten-simple-theme-options' ); ?></span>
							<span class="sto-custom-fonts-preview__value" data-sto-preview-family></span>
						</div>
						<div class="sto-custom-fonts-preview__row sto-custom-fonts-preview__row--cols">
							<span>
								<span class="sto-custom-fonts-preview__label"><?php esc_html_e( 'Weight', 'topten-simple-theme-options' ); ?></span>
								<span class="sto-custom-fonts-preview__value" data-sto-preview-weight></span>
							</span>
							<span>
								<span class="sto-custom-fonts-preview__label"><?php esc_html_e( 'Style', 'topten-simple-theme-options' ); ?></span>
								<span class="sto-custom-fonts-preview__value" data-sto-preview-style></span>
							</span>
							<span>
								<span class="sto-custom-fonts-preview__label"><?php esc_html_e( 'Category', 'topten-simple-theme-options' ); ?></span>
								<span class="sto-custom-fonts-preview__value" data-sto-preview-category></span>
							</span>
						</div>
					</div>

					<div class="sto-custom-fonts-preview__zip" data-sto-preview-zip hidden>
						<p class="sto-custom-fonts-preview__zip-heading">
							<span data-sto-preview-zip-count></span>
						</p>
						<div class="sto-custom-fonts-preview__zip-table-wrap">
							<table class="sto-custom-fonts-preview__zip-table widefat">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'File', 'topten-simple-theme-options' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Family', 'topten-simple-theme-options' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Weight', 'topten-simple-theme-options' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Style', 'topten-simple-theme-options' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Category', 'topten-simple-theme-options' ); ?></th>
									</tr>
								</thead>
								<tbody data-sto-preview-zip-tbody></tbody>
							</table>
						</div>
					</div>

					<p class="sto-custom-fonts-preview__note" data-sto-preview-note hidden></p>
				</div>
			</form>

			<p class="sto-advance-status" data-sto-custom-fonts-status role="status" aria-live="polite" hidden></p>

			<?php $this->render_table( $faces ); ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $faces
	 */
	public function render_live_preview_section( array $faces ): void {
		$has_imported = $faces !== array();
		?>
		<div
			class="sto-custom-fonts-live"
			data-sto-custom-fonts-live
			<?php echo $has_imported ? '' : ' hidden'; ?>
		>
			<h4 class="sto-custom-fonts-live__title"><?php esc_html_e( 'Live preview', 'topten-simple-theme-options' ); ?></h4>
			<p class="sto-custom-fonts-live__desc">
				<?php esc_html_e( 'Choose a font family and style to preview type before using it in Typography fields.', 'topten-simple-theme-options' ); ?>
			</p>
			<p class="sto-custom-fonts-live__mode" data-sto-live-mode-label hidden></p>
			<div class="sto-custom-fonts-live__controls">
				<div class="sto-custom-fonts-live__field">
					<label class="sto-custom-fonts-live__label" for="sto-custom-font-live-family"><?php esc_html_e( 'Font family', 'topten-simple-theme-options' ); ?></label>
					<select id="sto-custom-font-live-family" class="sto-input-select" data-sto-live-family></select>
				</div>
				<div class="sto-custom-fonts-live__field">
					<label class="sto-custom-fonts-live__label" for="sto-custom-font-live-variant"><?php esc_html_e( 'Font style', 'topten-simple-theme-options' ); ?></label>
					<select id="sto-custom-font-live-variant" class="sto-input-select" data-sto-live-variant></select>
				</div>
			</div>
			<div class="sto-custom-fonts-live__sample" data-sto-custom-fonts-live-sample>
				<p class="sto-custom-fonts-live__glyphs"><?php echo esc_html( '1 2 3 4 5 6 7 8 9 0 A B C D E F G H I J K L M N O P Q R S T U V W X Y Z a b c d e f g h i j k l m n o p q r s t u v w x y z' ); ?></p>
				<p class="sto-custom-fonts-live__heading" data-sto-live-heading><?php esc_html_e( 'The quick brown fox jumps over the lazy dog', 'topten-simple-theme-options' ); ?></p>
				<p class="sto-custom-fonts-live__body" data-sto-live-body><?php esc_html_e( 'Typography is the art and technique of arranging type to make written language legible, readable, and appealing.', 'topten-simple-theme-options' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $faces
	 */
	public function render_table( array $faces ): void {
		?>
		<div class="sto-custom-fonts-table-wrap" data-sto-custom-fonts-table-wrap>
			<?php if ( $faces === array() ) : ?>
				<p class="sto-custom-fonts-empty" data-sto-custom-fonts-empty>
					<?php esc_html_e( 'No custom fonts yet. Upload a font file or ZIP above to add your first font.', 'topten-simple-theme-options' ); ?>
				</p>
			<?php else : ?>
				<div class="sto-custom-fonts-table-toolbar">
					<button
						type="button"
						class="button sto-custom-fonts-bulk-delete"
						data-sto-custom-fonts-bulk-delete
						disabled
					>
						<?php esc_html_e( 'Delete selected', 'topten-simple-theme-options' ); ?>
					</button>
				</div>
				<table class="sto-custom-fonts-table widefat striped">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column">
								<input
									type="checkbox"
									id="sto-custom-font-select-all"
									data-sto-custom-font-select-all
									aria-label="<?php esc_attr_e( 'Select all fonts', 'topten-simple-theme-options' ); ?>"
								/>
							</td>
							<th scope="col"><?php esc_html_e( 'Family', 'topten-simple-theme-options' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Weight', 'topten-simple-theme-options' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Style', 'topten-simple-theme-options' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Variant', 'topten-simple-theme-options' ); ?></th>
							<th scope="col"><?php esc_html_e( 'File', 'topten-simple-theme-options' ); ?></th>
							<th scope="col" class="sto-custom-fonts-table__actions-col"><?php esc_html_e( 'Actions', 'topten-simple-theme-options' ); ?></th>
						</tr>
					</thead>
					<tbody data-sto-custom-fonts-tbody>
						<?php foreach ( $faces as $face ) : ?>
							<?php $this->render_table_row( $face ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $face
	 */
	private function render_table_row( array $face ): void {
		$id            = isset( $face['id'] ) ? CustomFontsRegistry::sanitize_face_id( (string) $face['id'] ) : '';
		$family        = isset( $face['family'] ) ? (string) $face['family'] : '';
		$weight        = isset( $face['weight'] ) ? (int) $face['weight'] : 400;
		$style         = isset( $face['style'] ) ? (string) $face['style'] : 'normal';
		$variant       = isset( $face['variant'] ) ? (string) $face['variant'] : '';
		$attachment_id = isset( $face['attachment_id'] ) ? (int) $face['attachment_id'] : 0;
		$file_label    = '';
		if ( $attachment_id > 0 ) {
			$file_label = basename( (string) get_attached_file( $attachment_id ) );
			if ( $file_label === '' ) {
				$file_label = get_the_title( $attachment_id );
			}
		}
		if ( $id === '' ) {
			return;
		}
		?>
		<tr data-sto-custom-font-row="<?php echo esc_attr( $id ); ?>">
			<th scope="row" class="check-column">
				<input
					type="checkbox"
					name="sto_custom_font_ids[]"
					value="<?php echo esc_attr( $id ); ?>"
					data-sto-custom-font-select
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: font family name */ __( 'Select %s', 'topten-simple-theme-options' ), $family ) ); ?>"
				/>
			</th>
			<td><strong><?php echo esc_html( $family ); ?></strong></td>
			<td><?php echo esc_html( (string) $weight ); ?></td>
			<td><?php echo esc_html( ucfirst( $style ) ); ?></td>
			<td><code><?php echo esc_html( $variant ); ?></code></td>
			<td class="sto-custom-fonts-table__file"><?php echo esc_html( $file_label ); ?></td>
			<td class="sto-custom-fonts-table__actions-col">
				<button type="button" class="button-link-delete" data-sto-custom-font-delete data-face-id="<?php echo esc_attr( $id ); ?>">
					<?php esc_html_e( 'Delete', 'topten-simple-theme-options' ); ?>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array<string, string|false> $data
	 * @param string                      $file
	 * @param string                      $filename
	 * @param array<string, string>       $mimes
	 * @return array<string, string|false>
	 */
	public function filter_font_filetype( $data, $file, $filename, $mimes ) {
		unset( $mimes );
		if ( ! is_array( $data ) ) {
			$data = array(
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			);
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$map = array(
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'eot'   => 'application/vnd.ms-fontobject',
			'zip'   => 'application/zip',
		);
		if ( isset( $map[ $ext ] ) ) {
			$data['ext']  = $ext;
			$data['type'] = $map[ $ext ];

			return $data;
		}
		if ( is_string( $file ) && is_readable( $file ) && self::file_has_zip_signature( $file ) ) {
			$data['ext']  = 'zip';
			$data['type'] = 'application/zip';
		}

		return $data;
	}

	private static function file_has_zip_signature( string $path ): bool {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}
		$bytes = fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return is_string( $bytes ) && strlen( $bytes ) >= 2 && $bytes[0] === 'P' && $bytes[1] === 'K';
	}

	public function ajax_upload(): void {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'topten-simple-theme-options' ) ), 403 );
		}

		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No file was uploaded.', 'topten-simple-theme-options' ) )
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated in CustomFontsRegistry::upload_font_file().
		$result = CustomFontsRegistry::upload_font_file( $_FILES['file'] );
		if ( ! $result['ok'] ) {
			wp_send_json_error(
				array( 'message' => $result['message'] )
			);
		}

		wp_send_json_success(
			array(
				'attachment_id' => $result['attachment_id'],
				'file_name'     => basename( (string) get_attached_file( $result['attachment_id'] ) ),
			)
		);
	}

	public function ajax_preview(): void {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'topten-simple-theme-options' ) ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
		$preview       = CustomFontsRegistry::preview_from_attachment( $attachment_id );

		if ( ! $preview['ok'] ) {
			wp_send_json_error(
				array( 'message' => $preview['message'] ),
				400
			);
		}

		wp_send_json_success(
			array(
				'attachment_id' => $attachment_id,
				'is_zip'        => $preview['is_zip'],
				'file_name'     => $preview['file_name'],
				'fonts'         => $preview['fonts'],
				'font_count'    => count( $preview['fonts'] ),
				'note'          => $preview['note'],
				'duplicates'    => isset( $preview['duplicates'] ) && is_array( $preview['duplicates'] )
					? $preview['duplicates']
					: array(),
				'live_preview'  => isset( $preview['live_preview'] ) && is_array( $preview['live_preview'] )
					? $preview['live_preview']
					: array(
						'families'    => array(),
						'cssByFamily' => array(),
					),
			)
		);
	}

	public function ajax_add(): void {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'topten-simple-theme-options' ) ), 403 );
		}

		$attachment_id       = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
		$replace_duplicates  = ! empty( $_POST['replace_duplicates'] );
		$result              = CustomFontsRegistry::add_faces_from_attachment( $attachment_id, $replace_duplicates );

		if ( ! $result['ok'] ) {
			$payload = array( 'message' => $result['message'] );
			if ( ! empty( $result['requires_replace'] ) ) {
				$payload['requires_replace'] = true;
				$payload['duplicates']       = isset( $result['duplicates'] ) && is_array( $result['duplicates'] )
					? $result['duplicates']
					: array();
			}
			wp_send_json_error( $payload, 400 );
		}

		ob_start();
		$this->render_table( CustomFontsRegistry::get_faces() );
		$table_html = (string) ob_get_clean();

		wp_send_json_success(
			array(
				'faces'        => $result['faces'],
				'added_count'  => $result['added_count'],
				'table_html'   => $table_html,
				'message'      => $result['message'],
				'live_data'    => CustomFontsRegistry::get_live_preview_data(),
			)
		);
	}

	/**
	 * @param array<int, string|int> $raw_ids Unslashed face id(s) from the verified AJAX request.
	 * @return array<int, string>
	 */
	private function normalize_delete_face_ids( array $raw_ids ): array {
		$face_ids = array();
		foreach ( $raw_ids as $raw_id ) {
			$id = CustomFontsRegistry::sanitize_face_id( (string) $raw_id );
			if ( $id !== '' ) {
				$face_ids[] = $id;
			}
		}

		return array_values( array_unique( $face_ids ) );
	}

	public function ajax_delete(): void {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'topten-simple-theme-options' ) ), 403 );
		}

		$raw_ids = array();
		if ( isset( $_POST['face_ids'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array or string; each id passed through sanitize_face_id() below.
			$raw = wp_unslash( $_POST['face_ids'] );
			if ( is_array( $raw ) ) {
				$raw_ids = $raw;
			} elseif ( is_string( $raw ) && $raw !== '' ) {
				$raw_ids = array( $raw );
			}
		}
		if ( $raw_ids === array() && isset( $_POST['face_id'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_face_id() below.
			$raw_ids = array( wp_unslash( (string) $_POST['face_id'] ) );
		}

		$face_ids = $this->normalize_delete_face_ids( $raw_ids );

		$deleted = CustomFontsRegistry::delete_faces( $face_ids );
		if ( $deleted <= 0 ) {
			wp_send_json_error(
				array( 'message' => __( 'Could not delete the selected font(s).', 'topten-simple-theme-options' ) )
			);
		}

		ob_start();
		$this->render_table( CustomFontsRegistry::get_faces() );
		$table_html = (string) ob_get_clean();

		$message = $deleted > 1
			? sprintf(
				/* translators: %d: number of fonts deleted */
				__( '%d custom fonts removed from the typography list.', 'topten-simple-theme-options' ),
				$deleted
			)
			: __( 'Custom font removed from the typography list.', 'topten-simple-theme-options' );

		wp_send_json_success(
			array(
				'table_html' => $table_html,
				'message'    => $message,
				'deleted'    => $deleted,
				'live_data'  => CustomFontsRegistry::get_live_preview_data(),
			)
		);
	}

	public function ajax_serve_preview_font(): void {
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::AJAX_NONCE_ACTION ) ) {
			status_header( 403 );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			exit;
		}

		$attachment_id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
		$file_key      = isset( $_GET['file_key'] ) ? sanitize_key( wp_unslash( (string) $_GET['file_key'] ) ) : '';
		$file          = CustomFontsRegistry::get_preview_serve_file( $attachment_id, $file_key );
		if ( $file === null ) {
			status_header( 404 );
			exit;
		}

		$path = $file['path'];
		$size = filesize( $path );
		if ( $size === false ) {
			status_header( 500 );
			exit;
		}

		$mime = self::font_mime_for_extension( (string) $file['ext'] );
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) $size );
		header( 'Cache-Control: private, max-age=300' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	private static function font_mime_for_extension( string $ext ): string {
		switch ( strtolower( $ext ) ) {
			case 'woff2':
				return 'font/woff2';
			case 'woff':
				return 'font/woff';
			case 'ttf':
				return 'font/ttf';
			case 'otf':
				return 'font/otf';
			case 'eot':
				return 'application/vnd.ms-fontobject';
			default:
				return 'application/octet-stream';
		}
	}
}
