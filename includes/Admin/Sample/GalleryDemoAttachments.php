<?php
/**
 * Creates a few **Media Library** attachments used only as **gallery field samples**
 * so `'default' => array( id, id, … )` is visible in the Theme Settings UI.
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Sample;

defined( 'ABSPATH' ) || exit;

/**
 * On-demand demo JPEGs (GD) + `wp_insert_attachment`; IDs cached in **`sto_gallery_demo_attachment_ids`**.
 */
final class GalleryDemoAttachments {

	private const OPTION_KEY = 'sto_gallery_demo_attachment_ids';

	private const DEMO_COUNT = 3;

	/**
	 * Attachment IDs for gallery **`default`** samples (empty when GD is missing or uploads fail).
	 *
	 * Demo files are created only on the **Theme Settings** admin screen (`page=theme-settings`) so other
	 * admin pages do not write to the Media Library.
	 *
	 * @return array<int, int>
	 */
	public static function get_attachment_ids() {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
			return array();
		}

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) {
			return array();
		}

		$cached = get_option( self::OPTION_KEY, null );
		if ( is_array( $cached ) && $cached !== array() ) {
			$valid = array();
			foreach ( $cached as $id ) {
				$id = absint( $id );
				if ( $id && wp_attachment_is_image( $id ) ) {
					$valid[] = $id;
				}
			}
			if ( count( $valid ) >= self::DEMO_COUNT ) {
				return array_slice( $valid, 0, self::DEMO_COUNT );
			}
			delete_option( self::OPTION_KEY );
		}

		if ( ! self::is_theme_settings_admin_request() ) {
			return array();
		}

		return self::create_demo_attachments();
	}

	/**
	 * True when the current request is rendering **Theme Settings** (field registration runs here).
	 */
	private static function is_theme_settings_admin_request() {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing context.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return $page === 'theme-settings';
	}

	/**
	 * @return array<int, int>
	 */
	private static function create_demo_attachments() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || ! is_string( $upload['path'] ) || $upload['path'] === '' ) {
			return array();
		}

		$specs = array(
			array(
				'slug'  => 'forest',
				'title' => __( 'STO gallery demo — forest', 'simple-theme-options' ),
				'rgb'   => array( 34, 120, 68 ),
			),
			array(
				'slug'  => 'wood',
				'title' => __( 'STO gallery demo — wood', 'simple-theme-options' ),
				'rgb'   => array( 139, 90, 43 ),
			),
			array(
				'slug'  => 'sky',
				'title' => __( 'STO gallery demo — sky', 'simple-theme-options' ),
				'rgb'   => array( 70, 130, 200 ),
			),
		);

		$ids = array();

		foreach ( $specs as $spec ) {
			$basename = 'sto-gallery-demo-' . $spec['slug'] . '.jpg';
			$basename = wp_unique_filename( $upload['path'], $basename );
			$path     = trailingslashit( $upload['path'] ) . $basename;

			if ( ! self::write_demo_jpeg( $path, $spec['rgb'] ) ) {
				continue;
			}

			$filetype = wp_check_filetype( $basename, null );
			$mime     = ( is_array( $filetype ) && ! empty( $filetype['type'] ) ) ? $filetype['type'] : 'image/jpeg';

			$attachment = array(
				'post_mime_type' => $mime,
				'post_title'     => $spec['title'],
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attach_id = wp_insert_attachment( $attachment, $path );
			if ( ! $attach_id || is_wp_error( $attach_id ) ) {
				wp_delete_file( $path );
				continue;
			}

			$meta = wp_generate_attachment_metadata( $attach_id, $path );
			if ( ! is_wp_error( $meta ) && is_array( $meta ) ) {
				wp_update_attachment_metadata( $attach_id, $meta );
			}

			$ids[] = (int) $attach_id;
		}

		if ( count( $ids ) >= self::DEMO_COUNT ) {
			update_option( self::OPTION_KEY, $ids, false );
		}

		return array_slice( $ids, 0, self::DEMO_COUNT );
	}

	/**
	 * @param string          $path Absolute filesystem path (JPEG).
	 * @param array<int, int> $rgb  0–255.
	 */
	private static function write_demo_jpeg( $path, array $rgb ) {
		$im = @imagecreatetruecolor( 320, 200 );
		if ( ! $im ) {
			return false;
		}

		$r = isset( $rgb[0] ) ? max( 0, min( 255, (int) $rgb[0] ) ) : 120;
		$g = isset( $rgb[1] ) ? max( 0, min( 255, (int) $rgb[1] ) ) : 120;
		$b = isset( $rgb[2] ) ? max( 0, min( 255, (int) $rgb[2] ) ) : 120;

		$bg = imagecolorallocate( $im, $r, $g, $b );
		imagefill( $im, 0, 0, $bg );

		$stripe = imagecolorallocate(
			$im,
			min( 255, $r + 50 ),
			min( 255, $g + 50 ),
			min( 255, $b + 50 )
		);
		imagefilledrectangle( $im, 0, 76, 320, 124, $stripe );

		$ok = imagejpeg( $im, $path, 88 );
		imagedestroy( $im );

		return (bool) $ok;
	}
}
