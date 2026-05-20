<?php
namespace SimpleThemeOptions\Admin\Options\ImportExport;

use SimpleThemeOptions\Admin\CustomFonts\CustomFontsAdmin;
use SimpleThemeOptions\Admin\ThemeSettingsDisplayLocations;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Admin\Sample\SampleFieldModules;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * **Advance** section: export / import **`sto_options`**, optional **Demo mode** toggle, **post editor Theme Settings metabox** preference, and per-file import history.
 * The **Advance** leaf is auto-registered on **`init`** (priority 100) for every **non‑packaged** top-level menu root (hidden from side navigation by default; use **`sto_theme_settings_advance_section_args`** to show it in the menu). The same tools are available under **Tools → Simple Backup** (`tools.php?page=sto-simple-backup`) with sidebar sections **Backup** (`section=backup`) and **Custom fonts** (`section=custom-fonts`) via **`SimpleBackupPage`**, plus **export scope** (client menus only; packaged demo roots omitted) and packaged-demo toggle support.
 * **AJAX export** ensures **`sto_include_option_fields`** runs when needed so the field registry is populated like a Theme Settings screen load.
 * **Import** merges keys from the JSON file into **`sto_options`** by default; optional full replace removes keys not present in the file.
 */
final class ThemeSettingsImportExport {
	use SingletonTrait;

	public const SECTION_SLUG = 'advance';

	/** `tools.php?page=` slug for **Tools → Simple Backup** (export scope, import, demo). */
	public const SETTINGS_ADVANCE_PAGE = 'sto-simple-backup';

	/** Tools → Simple Backup sidebar: import / export / demo. */
	public const TOOLS_SECTION_BACKUP = 'backup';

	/** Tools → Simple Backup sidebar: custom font uploads. */
	public const TOOLS_SECTION_CUSTOM_FONTS = 'custom-fonts';

	/** Prior **`tools.php?page=`** slug; {@see Menu::redirect_legacy_tools_backup_page_slug()} redirects here. */
	public const LEGACY_TOOLS_BACKUP_PAGE_SLUG = 'sto-theme-options-backup';

	/**
	 * Tools submenu and screen title (**Tools → Simple Settings**).
	 */
	public static function get_tools_page_label(): string {
		return __( 'Simple Settings', 'topten-simple-theme-options' );
	}

	/**
	 * Whether a section slug is the Advance import/export leaf (`advance` or `advance-{page}`).
	 */
	public static function is_advance_leaf_slug( $slug ): bool {
		$slug = sanitize_key( (string) $slug );
		if ( $slug === self::SECTION_SLUG ) {
			return true;
		}
		if ( strpos( $slug, 'advance-' ) !== 0 ) {
			return false;
		}
		$rest = substr( $slug, strlen( 'advance-' ) );

		return $rest !== '' && preg_match( '/^[a-z0-9-]+$/', $rest ) === 1;
	}

	/**
	 * Advance leaf slug for a given top-level `admin.php?page=` (after sections are registered).
	 */
	public static function get_advance_section_slug_for_menu_page( OptionsMenu $menu, $menu_page_slug ): string {
		$menu_page_slug = sanitize_key( (string) $menu_page_slug );
		if ( $menu_page_slug === '' ) {
			return self::SECTION_SLUG;
		}
		foreach ( $menu->get_sections() as $sec ) {
			if ( ! isset( $sec['slug'], $sec['sto_menu_page'] ) ) {
				continue;
			}
			if ( sanitize_key( (string) $sec['sto_menu_page'] ) !== $menu_page_slug ) {
				continue;
			}
			$cand = sanitize_key( (string) $sec['slug'] );
			if ( self::is_advance_leaf_slug( $cand ) ) {
				return $cand;
			}
		}
		$primary = $menu->get_parent_menu_slug();
		if ( $menu_page_slug === $primary && ! $menu->is_menu_root_packaged_demo( $primary ) ) {
			return self::SECTION_SLUG;
		}

		return 'advance-' . $menu_page_slug;
	}

	/**
	 * @param string $menu_page_slug Sanitized `admin.php?page=` slug.
	 */
	private static function advance_section_exists_for_menu_page( OptionsMenu $menu, $menu_page_slug ): bool {
		$menu_page_slug = sanitize_key( (string) $menu_page_slug );
		foreach ( $menu->get_sections() as $sec ) {
			if ( ! isset( $sec['slug'], $sec['sto_menu_page'] ) ) {
				continue;
			}
			if ( sanitize_key( (string) $sec['sto_menu_page'] ) !== $menu_page_slug ) {
				continue;
			}
			if ( self::is_advance_leaf_slug( sanitize_key( (string) $sec['slug'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	public const EXPORT_FORMAT_VERSION = 1;

	/** Stored boolean; gates packaged sample sections ({@see OptionsMenu::is_demo_mode_enabled()}). */
	public const OPTION_UI_DEMO_ENABLED = 'sto_theme_settings_ui_demo_enabled';

	/**
	 * Stored boolean: show post editor Theme Settings metaboxes when at least one menu root registered them.
	 *
	 * @see OptionsMenu::should_show_theme_settings_metaboxes()
	 */
	/** @deprecated No longer used; metaboxes show when registered. Kept for backwards compatibility. */
	public const OPTION_UI_METABOX_ENABLED = 'sto_theme_settings_ui_metabox_enabled';

	/** List of imports: each row `id`, `filename`, `keys`, `imported_at`. */
	public const OPTION_IMPORT_HISTORY = 'sto_theme_settings_import_history';

	/** @deprecated Legacy single blob — migrated into {@see OPTION_IMPORT_HISTORY} when read. */
	public const OPTION_LAST_IMPORT = 'sto_theme_settings_last_import';

	public const MAX_IMPORT_HISTORY = 40;

	public const MAX_IMPORT_HISTORY_UI = 20;

	/** @var int Raw JSON body max length (bytes). */
	private const MAX_IMPORT_BYTES = 5242880;

	protected function init() {
		add_action( 'init', array( $this, 'maybe_register_advance_section' ), 100 );
		add_action( 'admin_menu', array( $this, 'register_wp_settings_backup_page' ), 25 );
		add_action( 'sto_render_section_content', array( $this, 'render_section_content' ), 5, 3 );
		add_action( 'wp_ajax_sto_theme_settings_export', array( $this, 'ajax_export' ) );
		add_action( 'wp_ajax_sto_theme_settings_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_sto_theme_settings_set_ui_demo', array( $this, 'ajax_set_ui_demo' ) );
		add_action( 'wp_ajax_sto_theme_settings_set_import_display_locations', array( $this, 'ajax_set_import_display_locations' ) );
		add_action( 'wp_ajax_sto_theme_settings_import_entry_remove', array( $this, 'ajax_import_entry_remove' ) );
		add_action( 'wp_ajax_sto_theme_settings_import_entry_export', array( $this, 'ajax_import_entry_export' ) );
		add_action( 'wp_ajax_sto_theme_settings_import_entries_remove', array( $this, 'ajax_import_entries_remove' ) );
	}

	/**
	 * Ensures every **client** top-level menu root has an **Advance** leaf. Packaged demo roots are skipped per-slug (see {@see OptionsMenu::is_menu_root_packaged_demo()}). Idempotent per root.
	 */
	public static function ensure_advance_registered(): void {
		$menu = OptionsMenu::instance();
		if ( $menu->get_parent_menu_slug() === '' ) {
			return;
		}

		foreach ( $menu->get_registered_menu_slugs() as $page_slug ) {
			if ( $menu->is_menu_root_packaged_demo( $page_slug ) || ! $menu->is_menu_root_visible_in_admin( $page_slug ) ) {
				continue;
			}
			if ( self::advance_section_exists_for_menu_page( $menu, $page_slug ) ) {
				continue;
			}

			$primary  = $menu->get_parent_menu_slug();
			$adv_slug = ( $page_slug === $primary && ! $menu->is_menu_root_packaged_demo( $primary ) )
				? self::SECTION_SLUG
				: ( 'advance-' . $page_slug );

			$advance_defaults = array(
				'nav_locked'   => true,
				'show_in_menu' => false,
			);
			$args             = apply_filters( 'sto_theme_settings_advance_section_args', $advance_defaults, $page_slug ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
			if ( ! is_array( $args ) ) {
				$args = array();
			}
			$merged = array_merge(
				array(
					'nav_locked'   => true,
					'show_in_menu' => false,
				),
				$args
			);
			$merged['sto_menu_page'] = $page_slug;
			$menu->add_section(
				__( 'Advance', 'topten-simple-theme-options' ),
				$adv_slug,
				'fa-light fa-file-arrow-up',
				$merged
			);
		}
	}

	public function maybe_register_advance_section(): void {
		if ( ! is_admin() ) {
			return;
		}
		self::ensure_advance_registered();
	}

	/**
	 * **Tools → Simple Backup** — import, export (scoped), demo toggle without opening Theme Settings.
	 */
	public function register_wp_settings_backup_page(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_management_page(
			self::get_tools_page_label(),
			self::get_tools_page_label(),
			'manage_options',
			self::SETTINGS_ADVANCE_PAGE,
			array( $this, 'render_wp_settings_backup_page' )
		);
	}

	public function render_wp_settings_backup_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		SimpleBackupPage::render();
	}

	/**
	 * @param string               $section_slug
	 * @param array<string, mixed> $section
	 * @param OptionsMenu          $menu
	 */
	public function render_section_content( $section_slug, $section, $menu = null ) {
		unset( $menu );
		if ( ! self::is_advance_leaf_slug( sanitize_key( (string) $section_slug ) ) ) {
			return;
		}
		$this->render_advance_panel( 'section' );
	}

	/**
	 * Renders the Advance import/export UI (in-panel **section** or **Settings** screen).
	 *
	 * @param 'section'|'settings' $context
	 */
	public function render_advance_panel( string $context = 'section' ): void {
		if ( 'section' !== $context && 'settings' !== $context ) {
			$context = 'section';
		}

		$idsuf = 'settings' === $context ? '-settings' : '';
		?>
		<div class="sto-advance-import-export" data-sto-advance-import-export="1" data-sto-advance-import-export-from="<?php echo esc_attr( $context ); ?>">
			<?php $this->render_backup_tools_content( $context, $idsuf ); ?>
			<?php if ( 'section' === $context ) : ?>
				<?php CustomFontsAdmin::instance()->render_panel( $idsuf ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Backup tools markup (export, import, demo toggles, history) without the outer wrapper or custom fonts card.
	 *
	 * @param 'section'|'settings' $context
	 */
	public function render_backup_tools_content( string $context = 'section', string $idsuf = '' ): void {
		if ( 'section' !== $context && 'settings' !== $context ) {
			$context = 'section';
		}
		if ( $idsuf === '' ) {
			$idsuf = 'settings' === $context ? '-settings' : '';
		}

		$intro_id     = 'sto-advance-intro' . $idsuf;
		$options_menu = OptionsMenu::instance();
		$demo_on      = wp_validate_boolean( get_option( self::OPTION_UI_DEMO_ENABLED, false ) );
		$history      = $this->get_import_history();
		$history_ui   = array_slice( array_reverse( $history ), 0, self::MAX_IMPORT_HISTORY_UI );
		$total_rows   = count( $history );
		?>
			<input type="hidden" value="" data-sto-advance-source-name autocomplete="off" />
			<?php if ( 'settings' === $context ) : ?>
			<?php
			$scope_slugs = array();
			foreach ( $options_menu->get_registered_menu_slugs() as $mslug ) {
				if ( $options_menu->is_menu_root_packaged_demo( $mslug ) ) {
					continue;
				}
				$scope_slugs[] = $mslug;
			}
			/**
			 * Top-level `admin.php?page=` slugs listed as Export scope checkboxes (Tools → Simple Backup).
			 * Default excludes {@see OptionsMenu::is_menu_root_packaged_demo()} roots.
			 *
			 * @param array<int, string> $scope_slugs
			 * @param OptionsMenu        $options_menu
			 */
			$scope_slugs = apply_filters( 'sto_theme_settings_backup_export_scope_menu_slugs', $scope_slugs, $options_menu ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
			if ( ! is_array( $scope_slugs ) ) {
				$scope_slugs = array();
			}
			?>
			<?php if ( $scope_slugs !== array() ) : ?>
			<div class="sto-advance-card sto-advance-card--export-scope">
				<?php
				FieldTitle::render_heading(
					__( 'Export scope', 'topten-simple-theme-options' ),
					'default',
					null,
					'sto-advance-export-scope',
					false,
					'',
					''
				);
				?>
				<p class="sto-advance-card__desc">
					<?php esc_html_e( 'Choose which Theme Settings screens to include in the backup (you can select several). Packaged demo menus are not listed. Unchecked screens are omitted from the JSON.', 'topten-simple-theme-options' ); ?>
				</p>
				<fieldset class="sto-advance-export-menus">
					<?php foreach ( $scope_slugs as $mslug ) : ?>
						<?php
						$mslug = sanitize_key( (string) $mslug );
						if ( $mslug === '' ) {
							continue;
						}
						?>
						<label class="sto-advance-export-menu-label">
							<input type="checkbox" class="sto-advance-export-menu-cb" value="<?php echo esc_attr( $mslug ); ?>" data-sto-export-menu-slug checked />
							<span class="sto-advance-export-menu-label__text"><?php echo esc_html( $options_menu->get_registered_menu_page_title( $mslug ) ); ?></span>
							<code class="sto-advance-export-menu-label__slug"><?php echo esc_html( $mslug ); ?></code>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</div>
			<?php endif; ?>
			<?php endif; ?>

			<p class="sto-advance-import-export__intro" id="<?php echo esc_attr( $intro_id ); ?>">
				<?php
				if ( 'settings' === $context ) {
					esc_html_e( 'Back up or restore Theme Settings from here without opening each options screen. Import merges into your saved options by default; use “Replace entire option store” on import only when you want the file to be the whole map.', 'topten-simple-theme-options' );
				} else {
					esc_html_e( 'Download or copy a backup of all Theme Settings, or restore a backup from a file or the clipboard. Import merges into your saved options by default; use “Replace entire option store” only when you want the file to be the whole map.', 'topten-simple-theme-options' );
				}
				?>
			</p>

			<?php if ( 'settings' === $context && $options_menu->is_packaged_demo_menu() ) : ?>
			<div class="sto-advance-card sto-advance-card--demo">
				<?php
				FieldTitle::render_heading(
					__( 'Demo mode', 'topten-simple-theme-options' ),
					'default',
					null,
					'sto-advance-demo-settings',
					false,
					'',
					''
				);
				?>
				<p class="sto-advance-card__desc"><?php esc_html_e( 'Show or hide packaged demo field samples. The Theme Settings menu stays visible for licensing; only sample sections and fields are toggled.', 'topten-simple-theme-options' ); ?></p>
				<div class="sto-advance-demo-toggle" data-sto-advance-demo-wrap>
					<input type="hidden" id="sto-advance-demo-input<?php echo esc_attr( $idsuf ); ?>" data-sto-advance-demo-input value="<?php echo $demo_on ? '1' : '0'; ?>" />
					<button
						type="button"
						class="sto-switcher<?php echo $demo_on ? ' sto-switcher--on' : ''; ?>"
						data-sto-advance-demo-switch
						aria-pressed="<?php echo $demo_on ? 'true' : 'false'; ?>"
						aria-label="<?php esc_attr_e( 'Toggle demo mode for sample Theme Settings sections', 'topten-simple-theme-options' ); ?>"
					>
						<span class="sto-switcher__track" aria-hidden="true">
							<span class="sto-switcher__knob"></span>
							<span class="sto-switcher__label sto-switcher__label--on"><?php esc_html_e( 'ON', 'topten-simple-theme-options' ); ?></span>
							<span class="sto-switcher__label sto-switcher__label--off"><?php esc_html_e( 'OFF', 'topten-simple-theme-options' ); ?></span>
						</span>
					</button>
					<span class="sto-advance-demo-toggle__hint" data-sto-advance-demo-status role="status" aria-live="polite"></span>
				</div>
			</div>
				<?php elseif ( ! $options_menu->is_packaged_demo_menu() ) : ?>
			<div class="sto-advance-card sto-advance-card--demo">
				<?php
				FieldTitle::render_heading(
					__( 'Demo mode', 'topten-simple-theme-options' ),
					'default',
					null,
					'sto-advance-demo',
					false,
					'',
					''
				);
				?>
				<div class="sto-advance-demo-toggle" data-sto-advance-demo-wrap>
					<input type="hidden" id="sto-advance-demo-input<?php echo esc_attr( $idsuf ); ?>" data-sto-advance-demo-input value="<?php echo $demo_on ? '1' : '0'; ?>" />
					<button
						type="button"
						class="sto-switcher<?php echo $demo_on ? ' sto-switcher--on' : ''; ?>"
						data-sto-advance-demo-switch
						aria-pressed="<?php echo $demo_on ? 'true' : 'false'; ?>"
						aria-label="<?php esc_attr_e( 'Toggle demo mode for sample Theme Settings sections', 'topten-simple-theme-options' ); ?>"
					>
						<span class="sto-switcher__track" aria-hidden="true">
							<span class="sto-switcher__knob"></span>
							<span class="sto-switcher__label sto-switcher__label--on"><?php esc_html_e( 'ON', 'topten-simple-theme-options' ); ?></span>
							<span class="sto-switcher__label sto-switcher__label--off"><?php esc_html_e( 'OFF', 'topten-simple-theme-options' ); ?></span>
						</span>
					</button>
					<span class="sto-advance-demo-toggle__hint" data-sto-advance-demo-status role="status" aria-live="polite"></span>
				</div>
			</div>
				<?php else : ?>
			<div class="sto-advance-card sto-advance-card--muted">
				<p class="sto-advance-card__desc"><?php esc_html_e( 'Demo samples are packaged for this site. Use Tools → Simple Settings to turn demo mode on or off.', 'topten-simple-theme-options' ); ?></p>
			</div>
				<?php endif; ?>

			<?php if ( $total_rows > 0 ) : ?>
			<div class="sto-advance-card sto-advance-card--import-log" data-sto-advance-import-log="1">
				<?php
				FieldTitle::render_heading(
					__( 'Imports', 'topten-simple-theme-options' ),
					'default',
					null,
					'sto-advance-import-log',
					false,
					'',
					''
				);
				?>
				<?php if ( $total_rows > self::MAX_IMPORT_HISTORY_UI ) : ?>
					<p class="sto-advance-import-log__note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %1$d: shown rows, %2$d: total imports */
								__( 'Showing the %1$d most recent imports (%2$d total).', 'topten-simple-theme-options' ),
								self::MAX_IMPORT_HISTORY_UI,
								$total_rows
							)
						);
						?>
					</p>
				<?php endif; ?>
				<p class="sto-advance-import-log__bulk">
					<button type="button" class="button sto-advance-import-bulk-remove" data-sto-advance-import-bulk-remove disabled>
						<?php esc_html_e( 'Delete selected', 'topten-simple-theme-options' ); ?>
					</button>
				</p>
				<div class="sto-advance-table-wrap">
					<table class="sto-advance-table widefat striped">
						<thead>
							<tr>
								<th scope="col" class="sto-advance-table__check">
									<input type="checkbox" data-sto-advance-import-select-all aria-label="<?php esc_attr_e( 'Select all imports', 'topten-simple-theme-options' ); ?>" />
								</th>
								<th scope="col"><?php esc_html_e( 'File', 'topten-simple-theme-options' ); ?></th>
								<th scope="col" class="sto-advance-table__actions"><?php esc_html_e( 'Actions', 'topten-simple-theme-options' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $history_ui as $row ) : ?>
								<?php
								$rid   = isset( $row['id'] ) ? sanitize_text_field( (string) $row['id'] ) : '';
								$fname = isset( $row['filename'] ) ? (string) $row['filename'] : '';
								if ( $rid === '' ) {
									continue;
								}
								?>
								<?php
								$import_display = ThemeSettingsDisplayLocations::instance()->get_import_settings( $rid );
								?>
								<tr
									class="sto-advance-import-row"
									data-sto-advance-import-row="<?php echo esc_attr( $rid ); ?>"
									data-sto-import-row-toggle
									role="button"
									tabindex="0"
									aria-expanded="false"
								>
									<td class="sto-advance-table__check" data-sto-import-ignore-toggle>
										<input type="checkbox" data-sto-advance-import-cb value="<?php echo esc_attr( $rid ); ?>" aria-label="<?php echo esc_attr( sprintf(
											/* translators: %s: import file name */
											__( 'Select import %s', 'topten-simple-theme-options' ),
											$fname !== '' ? $fname : $rid
										) ); ?>" />
									</td>
									<td>
										<code class="sto-advance-table__filename"><?php echo esc_html( $fname !== '' ? $fname : __( '(untitled import)', 'topten-simple-theme-options' ) ); ?></code>
										<span class="sto-advance-import-row__hint"><?php esc_html_e( 'Click row to set where these keys appear', 'topten-simple-theme-options' ); ?></span>
									</td>
									<td class="sto-advance-table__actions" data-sto-import-ignore-toggle>
										<button type="button" class="button button-small sto-advance-table-btn" data-sto-advance-import-download-entry="<?php echo esc_attr( $rid ); ?>">
											<i class="fa-light fa-download" aria-hidden="true"></i>
											<?php esc_html_e( 'Download', 'topten-simple-theme-options' ); ?>
										</button>
										<button type="button" class="button button-small sto-advance-table-btn" data-sto-advance-import-remove-entry="<?php echo esc_attr( $rid ); ?>">
											<i class="fa-light fa-trash" aria-hidden="true"></i>
											<?php esc_html_e( 'Delete', 'topten-simple-theme-options' ); ?>
										</button>
									</td>
								</tr>
								<tr class="sto-advance-import-row-detail sto-is-hidden" data-sto-import-row-detail="<?php echo esc_attr( $rid ); ?>" hidden>
									<td colspan="3">
										<?php ThemeSettingsDisplayLocations::instance()->render_import_panel( $rid, $import_display, $idsuf ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="sto-advance-status sto-advance-status--error" data-sto-advance-import-log-status role="alert" hidden></p>
			</div>
			<?php endif; ?>

			<div class="sto-advance-card">
				<?php
				FieldTitle::render_heading(
					__( 'Export', 'topten-simple-theme-options' ),
					'default',
					null,
					'sto-advance-export',
					false,
					'',
					''
				);
				?>
				<p class="sto-advance-card__desc">
					<?php
					if ( 'settings' === $context ) {
						esc_html_e( 'Includes every option key registered on the leaves you checked under Export scope (field samples included).', 'topten-simple-theme-options' );
					} else {
						esc_html_e( 'Includes every option key registered on all Theme Settings leaves (field samples and extra menus included).', 'topten-simple-theme-options' );
					}
					?>
				</p>
				<div class="sto-advance-card__actions">
					<button type="button" class="button button-primary sto-advance-btn" data-sto-advance-export-copy>
						<i class="fa-light fa-copy" aria-hidden="true"></i>
						<?php esc_html_e( 'Copy to clipboard', 'topten-simple-theme-options' ); ?>
					</button>
					<button type="button" class="button sto-advance-btn" data-sto-advance-export-file>
						<i class="fa-light fa-download" aria-hidden="true"></i>
						<?php esc_html_e( 'Download JSON file', 'topten-simple-theme-options' ); ?>
					</button>
				</div>
				<p class="sto-advance-status" data-sto-advance-export-status role="status" aria-live="polite" hidden></p>
			</div>

			<div class="sto-advance-card">
				<?php
				FieldTitle::render_heading(
					__( 'Import', 'topten-simple-theme-options' ),
					'default',
					null,
					'sto-advance-import',
					false,
					'',
					''
				);
				?>
				<p class="sto-advance-card__desc"><?php esc_html_e( 'Use a JSON file from a previous export, or paste JSON and apply. By default this merges keys from the file into your existing option store (other keys stay). Turn on “replace entire store” only if this backup should be the only contents of sto_options. Fields appear in Theme Settings only while the plugin or theme that registered them is active — stored values remain for when you activate it again.', 'topten-simple-theme-options' ); ?></p>

				<p class="sto-advance-import-merge">
					<label class="sto-advance-import-merge__label">
						<input type="checkbox" data-sto-advance-import-replace-all value="1" />
						<?php esc_html_e( 'Replace entire option store (remove every key not listed in this file)', 'topten-simple-theme-options' ); ?>
					</label>
				</p>

				<label class="sto-advance-dropzone" data-sto-advance-dropzone>
					<input type="file" class="sto-advance-file-input" data-sto-advance-file accept=".json,application/json" />
					<span class="sto-advance-dropzone__inner">
						<i class="fa-light fa-file-arrow-up sto-advance-dropzone__icon" aria-hidden="true"></i>
						<span class="sto-advance-dropzone__title"><?php esc_html_e( 'Drop a backup file here', 'topten-simple-theme-options' ); ?></span>
						<span class="sto-advance-dropzone__hint"><?php esc_html_e( 'or click to choose a .json file', 'topten-simple-theme-options' ); ?></span>
					</span>
				</label>

				<?php $paste_id = 'sto-advance-paste' . $idsuf; ?>
				<div class="sto-advance-paste-row">
					<label class="sto-advance-label" for="<?php echo esc_attr( $paste_id ); ?>"><?php esc_html_e( 'Or paste exported JSON', 'topten-simple-theme-options' ); ?></label>
					<div class="sto-advance-paste-actions">
						<button type="button" class="button sto-advance-btn" data-sto-advance-paste-clipboard>
							<i class="fa-light fa-paste" aria-hidden="true"></i>
							<?php esc_html_e( 'Read from clipboard', 'topten-simple-theme-options' ); ?>
						</button>
					</div>
					<textarea
						id="<?php echo esc_attr( $paste_id ); ?>"
						class="sto-advance-textarea"
						data-sto-advance-textarea
						rows="8"
						spellcheck="false"
						placeholder="<?php esc_attr_e( 'Paste JSON here…', 'topten-simple-theme-options' ); ?>"
					></textarea>
				</div>

				<div class="sto-advance-card__actions sto-advance-card__actions--import">
					<button type="button" class="button button-primary sto-advance-btn" data-sto-advance-import-apply>
						<i class="fa-light fa-file-import" aria-hidden="true"></i>
						<?php esc_html_e( 'Apply import', 'topten-simple-theme-options' ); ?>
					</button>
					<button type="button" class="button sto-advance-btn" data-sto-advance-import-clear>
						<?php esc_html_e( 'Clear', 'topten-simple-theme-options' ); ?>
					</button>
				</div>
				<p class="sto-advance-status sto-advance-status--error" data-sto-advance-import-status role="alert" hidden></p>
			</div>
		<?php
	}

	public function ajax_export() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export settings.', 'topten-simple-theme-options' ) ), 403 );
		}

		$options = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$menu = OptionsMenu::instance();
		$this->ensure_option_fields_registry_ready( $menu );

		$allowed_pages = $menu->get_registered_menu_slugs();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; JSON decoded and slugs allowlisted.
		if ( isset( $_POST['export_menu_slugs'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string; slugs allowlisted after decode.
			$raw_json = wp_unslash( (string) $_POST['export_menu_slugs'] );
			$decoded  = json_decode( $raw_json, true );
			if ( ! is_array( $decoded ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid export scope. Refresh the page and try again.', 'topten-simple-theme-options' ) ), 400 );
			}
			$slugs = array();
			foreach ( $decoded as $item ) {
				$s = sanitize_key( (string) $item );
				if ( $s !== '' && in_array( $s, $allowed_pages, true ) ) {
					$slugs[] = $s;
				}
			}
			$slugs = array_values( array_unique( $slugs ) );
			if ( $slugs === array() ) {
				wp_send_json_error( array( 'message' => __( 'Select at least one options screen to export.', 'topten-simple-theme-options' ) ), 400 );
			}
			$export_keys = $menu->get_exportable_registered_option_keys_for_menu_pages( $slugs );
		} else {
			$export_keys = $menu->get_exportable_registered_option_keys();
		}

		if ( $export_keys === array() && $options !== array() ) {
			$export_keys = $this->collect_sanitized_option_keys_from_map( $options );
		}

		if ( $export_keys === array() ) {
			wp_send_json_error(
				array(
					'message' => __( 'No option keys were found to export. If a filter removes all keys, adjust it or register fields on your Theme Settings leaves.', 'topten-simple-theme-options' ),
				),
				400
			);
		}

		$subset = $this->build_export_subset_including_missing( $export_keys, $options );

		$payload = $this->build_export_payload_for_options( $subset );

		$json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
			$json_flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}
		$json = wp_json_encode( $payload, $json_flags );
		if ( ! is_string( $json ) || $json === '' ) {
			wp_send_json_error( array( 'message' => __( 'Could not build export data.', 'topten-simple-theme-options' ) ), 500 );
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
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import settings.', 'topten-simple-theme-options' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; JSON validated after read.
		$raw_body = isset( $_POST['import_payload'] ) ? wp_unslash( (string) $_POST['import_payload'] ) : '';
		$raw_body = is_string( $raw_body ) ? trim( $raw_body ) : '';

		if ( $raw_body === '' ) {
			wp_send_json_error( array( 'message' => __( 'Paste or upload JSON before importing.', 'topten-simple-theme-options' ) ), 400 );
		}

		if ( strlen( $raw_body ) > self::MAX_IMPORT_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'That file is too large to import.', 'topten-simple-theme-options' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; sanitized via sanitize_import_filename().
		$source_name = isset( $_POST['import_source_name'] ) ? wp_unslash( (string) $_POST['import_source_name'] ) : '';
		$source_name = $this->sanitize_import_filename( $source_name );

		$decoded = json_decode( $raw_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON. Use an export from this screen or the same plugin version.', 'topten-simple-theme-options' ) ), 400 );
		}

		$options = $this->extract_options_array_from_decoded( $decoded );
		if ( ! is_array( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'The JSON must contain an options object (use a full export file).', 'topten-simple-theme-options' ) ), 400 );
		}

		if ( $options === array() ) {
			wp_send_json_error(
				array(
					'message' => __( 'This backup contains no option keys. Import was cancelled so your current settings were not erased.', 'topten-simple-theme-options' ),
				),
				400
			);
		}

		if ( ! $this->is_safe_options_tree( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'That backup uses unsupported data types or structure.', 'topten-simple-theme-options' ) ), 400 );
		}

		/**
		 * Filter the options map immediately before it is merged into or replaces `sto_options` (see POST `import_replace_all`).
		 *
		 * @param array<string, mixed> $options Keys from the import file only.
		 */
		$options = apply_filters( 'sto_theme_settings_import_options_before_save', $options ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
		if ( ! is_array( $options ) ) {
			wp_send_json_error( array( 'message' => __( 'Import was blocked by a filter.', 'topten-simple-theme-options' ) ), 400 );
		}

		$keys_from_file = $this->sanitize_key_list( array_keys( $options ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; wp_validate_boolean().
		$replace_all = isset( $_POST['import_replace_all'] ) && wp_validate_boolean( wp_unslash( $_POST['import_replace_all'] ) );

		if ( ! $replace_all ) {
			$current = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
			if ( ! is_array( $current ) ) {
				$current = array();
			}
			foreach ( $options as $k => $v ) {
				if ( ! is_string( $k ) ) {
					continue;
				}
				$sk = sanitize_key( $k );
				if ( $sk === '' || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $sk ) ) {
					continue;
				}
				$current[ $sk ] = $v;
			}
			$options = $current;
		}

		update_option( 'sto_options', $options );
		$this->append_import_history_entry(
			array(
				'id'                 => wp_generate_password( 12, false, false ),
				'filename'           => $source_name,
				'keys'               => $keys_from_file,
				'imported_at'        => gmdate( 'c' ),
				'display_locations'  => ThemeSettingsDisplayLocations::instance()->get_default_settings(),
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'Settings imported. Reloading…', 'topten-simple-theme-options' ),
			)
		);
	}

	public function ajax_set_ui_demo() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this setting.', 'topten-simple-theme-options' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; boolean flag via wp_validate_boolean( wp_unslash() ).
		$from_settings = isset( $_POST['from_settings'] ) && wp_validate_boolean( wp_unslash( $_POST['from_settings'] ) );

		if ( OptionsMenu::instance()->is_packaged_demo_menu() && ! $from_settings ) {
			wp_send_json_error( array( 'message' => __( 'Demo mode for packaged sample sites is toggled from Tools → Simple Settings.', 'topten-simple-theme-options' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; allowlisted string compare below.
		$raw = isset( $_POST['ui_demo'] ) ? wp_unslash( (string) $_POST['ui_demo'] ) : '0';
		$on  = in_array( $raw, array( '1', 'true', 'yes', 'on' ), true );

		update_option( self::OPTION_UI_DEMO_ENABLED, $on );

		wp_send_json_success(
			array(
				'message' => __( 'Preference saved. Reloading…', 'topten-simple-theme-options' ),
			)
		);
	}

	public function ajax_set_import_display_locations(): void {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this setting.', 'topten-simple-theme-options' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['import_id'] ) ) : '';
		if ( $import_id === '' || ! preg_match( '/^[a-zA-Z0-9]+$/', $import_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import reference.', 'topten-simple-theme-options' ) ), 400 );
		}

		if ( $this->find_import_entry_by_id( $import_id ) === null ) {
			wp_send_json_error( array( 'message' => __( 'That import was not found.', 'topten-simple-theme-options' ) ), 404 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded; save_import_settings() sanitizes.
		$raw = isset( $_POST['display_locations'] ) ? wp_unslash( $_POST['display_locations'] ) : '';
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			$payload = is_array( $decoded ) ? $decoded : array();
		} elseif ( is_array( $raw ) ) {
			$payload = $raw;
		} else {
			$payload = array();
		}

		if ( ! ThemeSettingsDisplayLocations::instance()->save_import_settings( $import_id, $payload ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not save display settings. Try again.', 'topten-simple-theme-options' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Display settings saved.', 'topten-simple-theme-options' ),
			)
		);
	}

	public function ajax_import_entry_remove() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change settings.', 'topten-simple-theme-options' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['import_id'] ) ) : '';
		if ( $import_id === '' || ! preg_match( '/^[a-zA-Z0-9]+$/', $import_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import reference.', 'topten-simple-theme-options' ) ), 400 );
		}

		$entry = $this->find_import_entry_by_id( $import_id );
		if ( $entry === null ) {
			wp_send_json_error( array( 'message' => __( 'That import was not found.', 'topten-simple-theme-options' ) ), 404 );
		}

		$keys = isset( $entry['keys'] ) && is_array( $entry['keys'] ) ? $this->sanitize_key_list( $entry['keys'] ) : array();
		$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		foreach ( $keys as $k ) {
			unset( $opts[ $k ] );
		}
		update_option( 'sto_options', $opts );
		$this->remove_import_entry_by_id( $import_id );

		wp_send_json_success( array( 'message' => __( 'Import removed from the database.', 'topten-simple-theme-options' ) ) );
	}

	/**
	 * Remove several import log rows and delete every `sto_options` key that belonged to any of them.
	 */
	public function ajax_import_entries_remove(): void {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change settings.', 'topten-simple-theme-options' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each id sanitized in loop below.
		$raw = isset( $_POST['import_ids'] ) ? wp_unslash( $_POST['import_ids'] ) : array();
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No imports were selected.', 'topten-simple-theme-options' ) ), 400 );
		}

		$ids = array();
		foreach ( array_slice( $raw, 0, 40 ) as $item ) {
			$id = sanitize_text_field( (string) $item );
			if ( $id === '' || ! preg_match( '/^[a-zA-Z0-9]+$/', $id ) ) {
				continue;
			}
			$ids[ $id ] = true;
		}
		$ids = array_keys( $ids );
		if ( $ids === array() ) {
			wp_send_json_error( array( 'message' => __( 'No valid import references.', 'topten-simple-theme-options' ) ), 400 );
		}

		$union_keys = array();
		$found_ids  = array();
		foreach ( $ids as $import_id ) {
			$entry = $this->find_import_entry_by_id( $import_id );
			if ( $entry === null ) {
				continue;
			}
			$found_ids[] = $import_id;
			$keys        = isset( $entry['keys'] ) && is_array( $entry['keys'] ) ? $this->sanitize_key_list( $entry['keys'] ) : array();
			foreach ( $keys as $k ) {
				$union_keys[ $k ] = true;
			}
		}

		if ( $found_ids === array() ) {
			wp_send_json_error( array( 'message' => __( 'Those imports were not found.', 'topten-simple-theme-options' ) ), 404 );
		}

		$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		foreach ( array_keys( $union_keys ) as $k ) {
			unset( $opts[ $k ] );
		}
		update_option( 'sto_options', $opts );

		foreach ( $found_ids as $import_id ) {
			$this->remove_import_entry_by_id( $import_id );
		}

		wp_send_json_success( array( 'message' => __( 'Selected imports were removed from the database.', 'topten-simple-theme-options' ) ) );
	}

	public function ajax_import_entry_export() {
		check_ajax_referer( 'sto_theme_settings_import_export', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export settings.', 'topten-simple-theme-options' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['import_id'] ) ) : '';
		if ( $import_id === '' || ! preg_match( '/^[a-zA-Z0-9]+$/', $import_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import reference.', 'topten-simple-theme-options' ) ), 400 );
		}

		$entry = $this->find_import_entry_by_id( $import_id );
		if ( $entry === null ) {
			wp_send_json_error( array( 'message' => __( 'That import was not found.', 'topten-simple-theme-options' ) ), 404 );
		}

		$keys = isset( $entry['keys'] ) && is_array( $entry['keys'] ) ? $this->sanitize_key_list( $entry['keys'] ) : array();
		if ( $keys === array() ) {
			wp_send_json_error( array( 'message' => __( 'No keys to export.', 'topten-simple-theme-options' ) ), 400 );
		}

		$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}

		$subset = array();
		foreach ( $keys as $k ) {
			$subset[ $k ] = array_key_exists( $k, $opts ) ? $opts[ $k ] : null;
		}

		$payload  = $this->build_export_payload_for_options( $subset );
		$json     = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not build export data.', 'topten-simple-theme-options' ) ), 500 );
		}

		$basefile = isset( $entry['filename'] ) ? $this->sanitize_import_filename( (string) $entry['filename'] ) : 'import';
		$basefile = preg_replace( '/\.json$/i', '', $basefile );
		$filename = 'theme-settings-' . $basefile . '-' . gmdate( 'Y-m-d-His' ) . '.json';

		wp_send_json_success(
			array(
				'json'     => $json,
				'filename' => $filename,
			)
		);
	}

	private function sanitize_import_filename( string $raw ): string {
		$s = basename( str_replace( '\\', '/', $raw ) );
		$s = preg_replace( '/[^a-zA-Z0-9._ -]+/', '-', $s );
		$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		if ( $s === '' || $s === '.' || $s === '..' ) {
			return 'theme-settings-import.json';
		}

		return substr( $s, 0, 180 );
	}

	/**
	 * @param array{id: string, filename: string, keys: array<int, string>, imported_at: string} $entry
	 */
	private function append_import_history_entry( array $entry ): void {
		if ( ! isset( $entry['display_locations'] ) || ! is_array( $entry['display_locations'] ) ) {
			$entry['display_locations'] = ThemeSettingsDisplayLocations::instance()->get_default_settings();
		}
		$list = $this->get_import_history_raw();
		array_unshift( $list, $entry );
		if ( count( $list ) > self::MAX_IMPORT_HISTORY ) {
			$list = array_slice( $list, 0, self::MAX_IMPORT_HISTORY );
		}
		update_option( self::OPTION_IMPORT_HISTORY, $list );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_import_history_raw(): array {
		$raw = get_option( self::OPTION_IMPORT_HISTORY, null );
		if ( is_array( $raw ) ) {
			return $raw;
		}

		return array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_import_history(): array {
		$list = $this->get_import_history_raw();
		if ( $list !== array() ) {
			return $list;
		}

		$legacy = get_option( self::OPTION_LAST_IMPORT, null );
		if ( ! is_array( $legacy ) || empty( $legacy['keys'] ) || ! is_array( $legacy['keys'] ) ) {
			return array();
		}

		$migrated = array(
			array(
				'id'                => wp_generate_password( 12, false, false ),
				'filename'          => __( 'Previous import', 'topten-simple-theme-options' ),
				'keys'              => $this->sanitize_key_list( $legacy['keys'] ),
				'imported_at'       => isset( $legacy['imported_at'] ) ? (string) $legacy['imported_at'] : gmdate( 'c' ),
				'display_locations' => ThemeSettingsDisplayLocations::instance()->get_default_settings(),
			),
		);
		update_option( self::OPTION_IMPORT_HISTORY, $migrated );
		delete_option( self::OPTION_LAST_IMPORT );

		return $migrated;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_import_history_for_display(): array {
		return $this->get_import_history();
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	public function update_import_entry( string $id, array $fields ): bool {
		$id = sanitize_text_field( $id );
		if ( $id === '' ) {
			return false;
		}

		$list    = $this->get_import_history_raw();
		$updated = false;
		foreach ( $list as $idx => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || (string) $row['id'] !== $id ) {
				continue;
			}
			$list[ $idx ] = array_merge( $row, $fields );
			$updated      = true;
			break;
		}

		if ( ! $updated ) {
			return false;
		}

		update_option( self::OPTION_IMPORT_HISTORY, $list );

		return true;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_import_entry_by_id( string $id ): ?array {
		foreach ( $this->get_import_history() as $row ) {
			if ( isset( $row['id'] ) && (string) $row['id'] === $id ) {
				return is_array( $row ) ? $row : null;
			}
		}

		return null;
	}

	private function remove_import_entry_by_id( string $id ): void {
		$list = $this->get_import_history_raw();
		$next = array();
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) || ( isset( $row['id'] ) && (string) $row['id'] === $id ) ) {
				continue;
			}
			$next[] = $row;
		}
		if ( $next === array() ) {
			delete_option( self::OPTION_IMPORT_HISTORY );
		} else {
			update_option( self::OPTION_IMPORT_HISTORY, $next );
		}
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
	 * Fires `sto_include_option_fields` when it has not run in this request so deferred `Field::register()`
	 * work is applied before reading the registry (e.g. `admin-ajax.php` export without a Theme Settings screen load).
	 */
	private function ensure_option_fields_registry_ready( OptionsMenu $menu ): void {
		SampleFieldModules::boot_discovered_field_modules_for_registry();

		/**
		 * Themes may register fields on `init` after the first menu `include_fields()` pass.
		 *
		 * @param OptionsMenu $menu
		 */
		do_action( 'sto_prepare_theme_settings_export', $menu ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.

		if ( ! did_action( 'sto_include_option_fields' ) ) {
			/**
			 * Same signature as {@see OptionsMenu::include_fields()} (private); third-party code may listen here.
			 *
			 * @param OptionsMenu $menu
			 */
			do_action( 'sto_include_option_fields', $menu ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
		}

		FieldRegistrationDeferral::flush( $menu );
	}

	/**
	 * Sanitized keys from an `sto_options` map (for export fallback when the registry yields none).
	 *
	 * @param array<string, mixed> $options
	 * @return array<int, string>
	 */
	private function collect_sanitized_option_keys_from_map( array $options ): array {
		$out = array();
		foreach ( array_keys( $options ) as $raw_k ) {
			if ( ! is_string( $raw_k ) ) {
				continue;
			}
			$k = sanitize_key( $raw_k );
			if ( $k !== '' && preg_match( '/^[a-zA-Z0-9_-]+$/', $k ) ) {
				$out[] = $k;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * One entry per export key; missing keys become null so JSON is never an empty object when keys exist.
	 *
	 * @param array<int, string>   $export_keys
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function build_export_subset_including_missing( array $export_keys, array $options ): array {
		$subset = array();
		foreach ( $export_keys as $k ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			$k = sanitize_key( $k );
			if ( $k === '' ) {
				continue;
			}
			$subset[ $k ] = array_key_exists( $k, $options ) ? $options[ $k ] : null;
		}

		return $subset;
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
			'generator'          => STO_PLUGIN_NAME,
			'options'            => $options,
		);

		/**
		 * Filter the export envelope before JSON encoding.
		 *
		 * @param array<string, mixed> $payload
		 */
		return apply_filters( 'sto_theme_settings_export_payload', $payload ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
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

		return $decoded;
	}

	/**
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
