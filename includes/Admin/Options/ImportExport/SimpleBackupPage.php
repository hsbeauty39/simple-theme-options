<?php
/**
 * Tools → Simple Settings layout (sidebar: Settings | Custom fonts).
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin\Options\ImportExport;

use SimpleThemeOptions\Admin\CustomFonts\CustomFontsAdmin;

defined( 'ABSPATH' ) || exit;

final class SimpleBackupPage {

	/**
	 * @return array<int, array{slug: string, name: string, icon: string}>
	 */
	public static function get_sections(): array {
		return array(
			array(
				'slug' => ThemeSettingsImportExport::TOOLS_SECTION_BACKUP,
				'name' => __( 'Settings', 'topten-simple-theme-options' ),
				'icon' => 'fa-light fa-sliders',
			),
			array(
				'slug' => ThemeSettingsImportExport::TOOLS_SECTION_CUSTOM_FONTS,
				'name' => __( 'Custom fonts', 'topten-simple-theme-options' ),
				'icon' => 'fa-light fa-font',
			),
		);
	}

	public static function get_current_section_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : '';
		foreach ( self::get_sections() as $row ) {
			if ( isset( $row['slug'] ) && $row['slug'] === $section ) {
				return $section;
			}
		}

		return ThemeSettingsImportExport::TOOLS_SECTION_BACKUP;
	}

	/**
	 * @return array{slug: string, name: string, icon: string}|null
	 */
	public static function get_section( string $slug ): ?array {
		$slug = sanitize_key( $slug );
		foreach ( self::get_sections() as $row ) {
			if ( isset( $row['slug'] ) && $row['slug'] === $slug ) {
				return $row;
			}
		}

		return null;
	}

	public static function get_section_url( string $section_slug ): string {
		return add_query_arg(
			array(
				'page'    => ThemeSettingsImportExport::SETTINGS_ADVANCE_PAGE,
				'section' => sanitize_key( $section_slug ),
			),
			admin_url( 'tools.php' )
		);
	}

	public static function render(): void {
		$current_section = self::get_current_section_slug();
		$current_meta    = self::get_section( $current_section );
		$page_label      = ThemeSettingsImportExport::get_tools_page_label();
		$content_title   = $current_meta['name'] ?? $page_label;
		$content_icon    = $current_meta['icon'] ?? 'fa-light fa-sliders';
		$idsuf           = '-settings';
		$import_export   = ThemeSettingsImportExport::instance();
		?>
		<div class="wrap sto-simple-backup-wrap sto-section-content">
			<div
				class="sto-option-panel-wrapper sto-option-panel-wrapper--simple-backup"
				data-sto-simple-backup-panel="1"
				data-sto-default-leaf="<?php echo esc_attr( ThemeSettingsImportExport::TOOLS_SECTION_BACKUP ); ?>"
			>
				<div class="sto-option-panel-head">
					<h1 class="sto-option-panel-title"><?php echo esc_html( $page_label ); ?></h1>
				</div>
				<div class="sto-option-panel-body">
					<div class="sto-option-panel-nav-layout">
						<div class="sto-option-panel-sidebar-wrap">
							<ul class="sto-option-panel-sidebar" role="navigation" aria-label="<?php echo esc_attr( sprintf(
								/* translators: %s: Tools screen title, e.g. Simple Settings */
								__( '%s sections', 'topten-simple-theme-options' ),
								$page_label
							) ); ?>">
								<?php foreach ( self::get_sections() as $section ) : ?>
									<?php self::render_sidebar_item( $section, $current_section ); ?>
								<?php endforeach; ?>
							</ul>
						</div>
						<div class="sto-option-panel-main">
							<div class="sto-option-panel-content-head">
								<span class="sto-option-panel-content-icon-wrap">
									<i class="<?php echo esc_attr( $content_icon ); ?> sto-option-panel-content-icon"></i>
								</span>
								<h2 class="sto-option-panel-content-title"><?php echo esc_html( $content_title ); ?></h2>
							</div>
							<?php self::render_section_panel( ThemeSettingsImportExport::TOOLS_SECTION_BACKUP, $current_section, $idsuf, $import_export ); ?>
							<?php self::render_section_panel( ThemeSettingsImportExport::TOOLS_SECTION_CUSTOM_FONTS, $current_section, $idsuf, $import_export ); ?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array{slug: string, name: string, icon: string} $section
	 */
	private static function render_sidebar_item( array $section, string $current_section_slug ): void {
		$slug      = sanitize_key( (string) ( $section['slug'] ?? '' ) );
		$name      = (string) ( $section['name'] ?? '' );
		$icon      = (string) ( $section['icon'] ?? 'fa-light fa-circle' );
		$is_active = $current_section_slug === $slug;
		$url       = self::get_section_url( $slug );
		?>
		<li class="sto-option-panel-sidebar-item<?php echo $is_active ? ' sto-is-active' : ''; ?>" data-sto-section="<?php echo esc_attr( $slug ); ?>">
			<a class="sto-option-panel-sidebar-item-link<?php echo $is_active ? ' sto-is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<i class="<?php echo esc_attr( $icon ); ?> sto-option-panel-icon" aria-hidden="true"></i>
				<span class="sto-option-panel-sidebar-item-title"><?php echo esc_html( $name ); ?></span>
			</a>
		</li>
		<?php
	}

	private static function render_section_panel( string $section_slug, string $current_section_slug, string $idsuf, ThemeSettingsImportExport $import_export ): void {
		$is_active = $current_section_slug === $section_slug;
		?>
		<div class="sto-option-panel-section<?php echo $is_active ? ' sto-is-active' : ' sto-is-hidden'; ?>" data-section="<?php echo esc_attr( $section_slug ); ?>">
			<fieldset class="sto-panel-section-fields"<?php echo $is_active ? '' : ' disabled'; ?>>
				<?php
				if ( ThemeSettingsImportExport::TOOLS_SECTION_CUSTOM_FONTS === $section_slug ) {
					CustomFontsAdmin::instance()->render_panel( $idsuf );
				} else {
					?>
					<div class="sto-advance-import-export" data-sto-advance-import-export="1" data-sto-advance-import-export-from="settings">
						<?php $import_export->render_backup_tools_content( 'settings', $idsuf ); ?>
					</div>
					<?php
				}
				?>
			</fieldset>
		</div>
		<?php
	}
}
