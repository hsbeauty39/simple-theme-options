<?php
/**
 * Per-import display locations (which surfaces show each backup file's option keys).
 *
 * @package SimpleThemeOptions
 */

namespace SimpleThemeOptions\Admin;

use SimpleThemeOptions\Admin\Options\ImportExport\ThemeSettingsImportExport;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class ThemeSettingsDisplayLocations {
	use SingletonTrait;

	public const SURFACE_ADMIN      = 'admin';
	public const SURFACE_CUSTOMIZER = 'customizer';
	public const SURFACE_TAXONOMY   = 'taxonomy';
	public const SURFACE_METABOX    = 'metabox';

	/** @var string */
	private $current_surface = self::SURFACE_ADMIN;

	/** @var array<string, string> */
	private $surface_context = array();

	/** @var array<string, array<string, bool>>|null */
	private $key_index_cache = null;

	/**
	 * @return array{admin: array{enabled: bool}, customizer: array{enabled: bool}, taxonomy: array{enabled: bool, taxonomies: array<int, string>}, metabox: array{enabled: bool, post_types: array<int, string>}}
	 */
	public function get_default_settings(): array {
		$registered_pts = ThemeSettingsMetabox::instance()->get_union_metabox_post_types();
		$registered_tax = ThemeSettingsTermBox::instance()->get_union_term_metabox_taxonomies();

		return array(
			'admin'      => array(
				'enabled' => true,
			),
			'customizer' => array(
				'enabled' => false,
			),
			'taxonomy'   => array(
				'enabled'    => false,
				'taxonomies' => $registered_tax !== array() ? $registered_tax : array( 'category', 'post_tag' ),
			),
			'metabox'    => array(
				'enabled'     => false,
				'post_types'  => $registered_pts !== array() ? $registered_pts : array( 'post', 'page' ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array{admin: array{enabled: bool}, customizer: array{enabled: bool}, taxonomy: array{enabled: bool, taxonomies: array<int, string>}, metabox: array{enabled: bool, post_types: array<int, string>}}
	 */
	public function normalize_settings( array $raw ): array {
		$defaults = $this->get_default_settings();

		return array(
			'admin'      => array(
				'enabled' => ! empty( $raw['admin']['enabled'] ),
			),
			'customizer' => array(
				'enabled' => ! empty( $raw['customizer']['enabled'] ),
			),
			'taxonomy'   => array(
				'enabled'    => ! empty( $raw['taxonomy']['enabled'] ),
				'taxonomies' => $this->sanitize_slug_list(
					isset( $raw['taxonomy']['taxonomies'] ) && is_array( $raw['taxonomy']['taxonomies'] )
						? $raw['taxonomy']['taxonomies']
						: array(),
					$this->get_selectable_taxonomy_slugs()
				),
			),
			'metabox'    => array(
				'enabled'     => ! empty( $raw['metabox']['enabled'] ),
				'post_types'  => $this->sanitize_slug_list(
					isset( $raw['metabox']['post_types'] ) && is_array( $raw['metabox']['post_types'] )
						? $raw['metabox']['post_types']
						: array(),
					$this->get_selectable_post_type_slugs()
				),
			),
		);
	}

	/**
	 * @return array{admin: array{enabled: bool}, customizer: array{enabled: bool}, taxonomy: array{enabled: bool, taxonomies: array<int, string>}, metabox: array{enabled: bool, post_types: array<int, string>}}
	 */
	public function get_import_settings( string $import_id ): array {
		$import_id = sanitize_text_field( $import_id );
		$entry     = ThemeSettingsImportExport::instance()->find_import_entry_by_id( $import_id );
		if ( $entry === null ) {
			return $this->get_default_settings();
		}

		$raw = isset( $entry['display_locations'] ) && is_array( $entry['display_locations'] )
			? $entry['display_locations']
			: array();

		if ( $raw === array() ) {
			return $this->get_default_settings();
		}

		$normalized = $this->normalize_settings( $raw );
		$defaults   = $this->get_default_settings();

		if ( $normalized['taxonomy']['enabled'] && $normalized['taxonomy']['taxonomies'] === array() ) {
			$normalized['taxonomy']['taxonomies'] = $defaults['taxonomy']['taxonomies'];
		}
		if ( $normalized['metabox']['enabled'] && $normalized['metabox']['post_types'] === array() ) {
			$normalized['metabox']['post_types'] = $defaults['metabox']['post_types'];
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function save_import_settings( string $import_id, array $payload ): bool {
		$import_id = sanitize_text_field( $import_id );
		if ( $import_id === '' ) {
			return false;
		}

		$next = $this->normalize_settings( $payload );
		$this->key_index_cache = null;

		return ThemeSettingsImportExport::instance()->update_import_entry(
			$import_id,
			array(
				'display_locations' => $next,
			)
		);
	}

	public function set_render_surface( string $surface, array $context = array() ): void {
		$surface = sanitize_key( $surface );
		if ( ! in_array( $surface, array( self::SURFACE_ADMIN, self::SURFACE_CUSTOMIZER, self::SURFACE_TAXONOMY, self::SURFACE_METABOX ), true ) ) {
			$surface = self::SURFACE_ADMIN;
		}
		$this->current_surface = $surface;
		$this->surface_context = array();
		foreach ( $context as $key => $value ) {
			$this->surface_context[ sanitize_key( (string) $key ) ] = sanitize_key( (string) $value );
		}
	}

	public function is_customizer_enabled(): bool {
		return $this->surface_has_keys( self::SURFACE_CUSTOMIZER );
	}

	public function is_metabox_enabled(): bool {
		if ( ThemeSettingsMetabox::instance()->get_roots() === array() ) {
			return false;
		}

		return $this->surface_has_keys( self::SURFACE_METABOX );
	}

	public function is_taxonomy_enabled(): bool {
		if ( ThemeSettingsTermBox::instance()->get_roots() === array() ) {
			return false;
		}

		return $this->surface_has_keys( self::SURFACE_TAXONOMY );
	}

	public function is_post_type_enabled( string $post_type ): bool {
		$post_type = sanitize_key( $post_type );
		if ( $post_type === '' || ! $this->is_metabox_enabled() ) {
			return false;
		}
		foreach ( $this->get_key_index() as $row ) {
			if ( empty( $row[ self::SURFACE_METABOX ] ) ) {
				continue;
			}
			$allowed = isset( $row['metabox_post_types'] ) && is_array( $row['metabox_post_types'] ) ? $row['metabox_post_types'] : array();
			if ( in_array( $post_type, $allowed, true ) ) {
				return true;
			}
		}

		return $this->get_key_index() === array();
	}

	public function is_taxonomy_slug_enabled( string $taxonomy ): bool {
		$taxonomy = sanitize_key( $taxonomy );
		if ( $taxonomy === '' || ! $this->is_taxonomy_enabled() ) {
			return false;
		}
		foreach ( $this->get_key_index() as $row ) {
			if ( empty( $row[ self::SURFACE_TAXONOMY ] ) ) {
				continue;
			}
			$allowed = isset( $row['taxonomy_slugs'] ) && is_array( $row['taxonomy_slugs'] ) ? $row['taxonomy_slugs'] : array();
			if ( in_array( $taxonomy, $allowed, true ) ) {
				return true;
			}
		}

		return $this->get_key_index() === array();
	}

	public function is_field_visible_on_current_surface( string $field_id ): bool {
		return $this->is_field_visible_on_surface( $field_id, $this->current_surface, $this->surface_context );
	}

	public function is_field_visible_on_surface( string $field_id, string $surface, array $context = array() ): bool {
		$field_id = sanitize_key( $field_id );
		$surface  = sanitize_key( $surface );
		if ( $field_id === '' || $surface === '' ) {
			return true;
		}

		$index = $this->get_key_index();
		if ( ! isset( $index[ $field_id ] ) ) {
			return true;
		}

		$row = $index[ $field_id ];
		if ( empty( $row[ $surface ] ) ) {
			return false;
		}

		if ( self::SURFACE_METABOX === $surface ) {
			$post_type = isset( $context['post_type'] ) ? sanitize_key( (string) $context['post_type'] ) : '';
			if ( $post_type !== '' ) {
				$allowed = isset( $row['metabox_post_types'] ) && is_array( $row['metabox_post_types'] ) ? $row['metabox_post_types'] : array();
				return in_array( $post_type, $allowed, true );
			}
		}

		if ( self::SURFACE_TAXONOMY === $surface ) {
			$taxonomy = isset( $context['taxonomy'] ) ? sanitize_key( (string) $context['taxonomy'] ) : '';
			if ( $taxonomy !== '' ) {
				$allowed = isset( $row['taxonomy_slugs'] ) && is_array( $row['taxonomy_slugs'] ) ? $row['taxonomy_slugs'] : array();
				return in_array( $taxonomy, $allowed, true );
			}
		}

		return true;
	}

	private function surface_has_keys( string $surface ): bool {
		$surface = sanitize_key( $surface );
		foreach ( $this->get_key_index() as $row ) {
			if ( ! empty( $row[ $surface ] ) ) {
				if ( self::SURFACE_METABOX === $surface ) {
					return true;
				}
				if ( self::SURFACE_TAXONOMY === $surface ) {
					return true;
				}
				if ( self::SURFACE_CUSTOMIZER === $surface || self::SURFACE_ADMIN === $surface ) {
					return true;
				}
			}
		}

		if ( $this->get_key_index() === array() ) {
			return true;
		}

		return false;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function get_key_index(): array {
		if ( is_array( $this->key_index_cache ) ) {
			return $this->key_index_cache;
		}

		$index = array();
		foreach ( ThemeSettingsImportExport::instance()->get_import_history_for_display() as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['keys'] ) || ! is_array( $entry['keys'] ) ) {
				continue;
			}
			$dl_raw   = isset( $entry['display_locations'] ) && is_array( $entry['display_locations'] ) ? $entry['display_locations'] : array();
			$settings = $dl_raw === array() ? $this->get_default_settings() : $this->normalize_settings( $dl_raw );
			foreach ( $entry['keys'] as $key ) {
				$key = sanitize_key( (string) $key );
				if ( $key === '' ) {
					continue;
				}
				$index[ $key ] = array(
					self::SURFACE_ADMIN      => $settings['admin']['enabled'],
					self::SURFACE_CUSTOMIZER => $settings['customizer']['enabled'],
					self::SURFACE_TAXONOMY   => $settings['taxonomy']['enabled'],
					self::SURFACE_METABOX    => $settings['metabox']['enabled'],
					'taxonomy_slugs'         => $settings['taxonomy']['taxonomies'],
					'metabox_post_types'     => $settings['metabox']['post_types'],
				);
			}
		}

		$this->key_index_cache = $index;

		return $index;
	}

	/**
	 * @return array<int, string>
	 */
	public function get_selectable_post_type_slugs(): array {
		$slugs = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $obj ) {
			if ( $obj instanceof \WP_Post_Type && $obj->name ) {
				$slugs[] = sanitize_key( (string) $obj->name );
			}
		}
		sort( $slugs );

		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	/**
	 * @return array<int, array{slug: string, label: string}>
	 */
	public function get_selectable_post_types(): array {
		$out = array();
		foreach ( $this->get_selectable_post_type_slugs() as $slug ) {
			$obj   = get_post_type_object( $slug );
			$out[] = array(
				'slug'  => $slug,
				'label' => $obj && isset( $obj->labels->name ) ? (string) $obj->labels->name : $slug,
			);
		}

		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	public function get_selectable_taxonomy_slugs(): array {
		$slugs = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $obj ) {
			if ( $obj instanceof \WP_Taxonomy && $obj->name ) {
				$slugs[] = sanitize_key( (string) $obj->name );
			}
		}
		sort( $slugs );

		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	/**
	 * @return array<int, array{slug: string, label: string}>
	 */
	public function get_selectable_taxonomies(): array {
		$out = array();
		foreach ( $this->get_selectable_taxonomy_slugs() as $slug ) {
			$obj   = get_taxonomy( $slug );
			$out[] = array(
				'slug'  => $slug,
				'label' => $obj && isset( $obj->labels->name ) ? (string) $obj->labels->name : $slug,
			);
		}

		return $out;
	}

	/**
	 * @param array{admin: array{enabled: bool}, customizer: array{enabled: bool}, taxonomy: array{enabled: bool, taxonomies: array<int, string>}, metabox: array{enabled: bool, post_types: array<int, string>}} $settings
	 */
	public function render_import_panel( string $import_id, array $settings, string $idsuf = '' ): void {
		$import_id = sanitize_text_field( $import_id );
		$idsuf     = preg_replace( '/[^a-zA-Z0-9_-]/', '', $idsuf );
		$has_term  = ThemeSettingsTermBox::instance()->get_roots() !== array();
		$has_meta  = ThemeSettingsMetabox::instance()->get_roots() !== array();
		?>
		<div
			class="sto-display-locations sto-display-locations--import"
			data-sto-import-display-locations="<?php echo esc_attr( $import_id ); ?>"
		>
			<p class="sto-display-location__intro">
				<?php esc_html_e( 'Choose where the option keys from this import file appear. Turn a switch on to configure that location.', 'simple-theme-options' ); ?>
			</p>
			<?php
			$this->render_location_row( 'admin', __( 'Theme Settings screen', 'simple-theme-options' ), $settings['admin']['enabled'], $import_id, $idsuf );
			$this->render_location_body(
				'admin',
				$settings['admin']['enabled'],
				'<p class="sto-display-location__hint">' . esc_html__( 'Shows fields on the main Theme Settings admin menu (admin.php).', 'simple-theme-options' ) . '</p>'
			);

			$this->render_location_row( 'customizer', __( 'Customizer', 'simple-theme-options' ), $settings['customizer']['enabled'], $import_id, $idsuf );
			$this->render_location_body(
				'customizer',
				$settings['customizer']['enabled'],
				'<p class="sto-display-location__hint">' . esc_html__( 'Adds options under Appearance → Customize for keys in this file.', 'simple-theme-options' ) . '</p>'
			);

			if ( $has_term ) {
				$this->render_location_row( 'taxonomy', __( 'Taxonomy terms', 'simple-theme-options' ), $settings['taxonomy']['enabled'], $import_id, $idsuf );
				ob_start();
				?>
				<p class="sto-display-location__hint"><?php esc_html_e( 'Select taxonomies for keys in this file.', 'simple-theme-options' ); ?></p>
				<ul class="sto-display-location__checks">
					<?php foreach ( $this->get_selectable_taxonomies() as $row ) : ?>
						<?php
						$slug    = (string) $row['slug'];
						$checked = in_array( $slug, $settings['taxonomy']['taxonomies'], true );
						$id      = 'sto-display-tax-' . $import_id . '-' . $slug . $idsuf;
						?>
						<li>
							<label class="sto-display-location__check-label" for="<?php echo esc_attr( $id ); ?>">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $id ); ?>"
									class="sto-display-location__check"
									data-sto-display-taxonomy="<?php echo esc_attr( $slug ); ?>"
									value="1"
									<?php checked( $checked ); ?>
								/>
								<?php echo esc_html( (string) $row['label'] ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php
				$this->render_location_body( 'taxonomy', $settings['taxonomy']['enabled'], (string) ob_get_clean() );
			}

			if ( $has_meta ) {
				$this->render_location_row( 'metabox', __( 'Post editor metabox', 'simple-theme-options' ), $settings['metabox']['enabled'], $import_id, $idsuf );
				ob_start();
				?>
				<p class="sto-display-location__hint"><?php esc_html_e( 'Select post types for keys in this file.', 'simple-theme-options' ); ?></p>
				<ul class="sto-display-location__checks">
					<?php foreach ( $this->get_selectable_post_types() as $row ) : ?>
						<?php
						$slug    = (string) $row['slug'];
						$checked = in_array( $slug, $settings['metabox']['post_types'], true );
						$id      = 'sto-display-pt-' . $import_id . '-' . $slug . $idsuf;
						?>
						<li>
							<label class="sto-display-location__check-label" for="<?php echo esc_attr( $id ); ?>">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $id ); ?>"
									class="sto-display-location__check"
									data-sto-display-post-type="<?php echo esc_attr( $slug ); ?>"
									value="1"
									<?php checked( $checked ); ?>
								/>
								<?php echo esc_html( (string) $row['label'] ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php
				$this->render_location_body( 'metabox', $settings['metabox']['enabled'], (string) ob_get_clean() );
			}
			?>
			<p class="sto-advance-status" data-sto-import-display-status="<?php echo esc_attr( $import_id ); ?>" role="status" aria-live="polite" hidden></p>
		</div>
		<?php
	}

	private function render_location_row( string $key, string $label, bool $on, string $import_id, string $idsuf ): void {
		$key = sanitize_key( $key );
		?>
		<div class="sto-display-location" data-sto-display-location="<?php echo esc_attr( $key ); ?>">
			<div class="sto-display-location__head">
				<span class="sto-display-location__title"><?php echo esc_html( $label ); ?></span>
				<input type="hidden" data-sto-display-location-input="<?php echo esc_attr( $key ); ?>" value="<?php echo $on ? '1' : '0'; ?>" />
				<button
					type="button"
					class="sto-switcher<?php echo $on ? ' sto-switcher--on' : ''; ?>"
					data-sto-display-location-switch="<?php echo esc_attr( $key ); ?>"
					aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"
					aria-label="<?php echo esc_attr( $label ); ?>"
				>
					<span class="sto-switcher__track" aria-hidden="true">
						<span class="sto-switcher__knob"></span>
						<span class="sto-switcher__label sto-switcher__label--on"><?php esc_html_e( 'ON', 'simple-theme-options' ); ?></span>
						<span class="sto-switcher__label sto-switcher__label--off"><?php esc_html_e( 'OFF', 'simple-theme-options' ); ?></span>
					</span>
				</button>
			</div>
		</div>
		<?php
	}

	private function render_location_body( string $key, bool $on, string $html ): void {
		$key = sanitize_key( $key );
		?>
		<div class="sto-display-location__body<?php echo $on ? '' : ' sto-is-hidden'; ?>" data-sto-display-location-body="<?php echo esc_attr( $key ); ?>"<?php echo $on ? '' : ' hidden'; ?>>
			<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, mixed>  $raw
	 * @param array<int, string> $allowed
	 * @return array<int, string>
	 */
	private function sanitize_slug_list( array $raw, array $allowed ): array {
		$allowed_map = array_fill_keys( $allowed, true );
		$out         = array();
		foreach ( $raw as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug !== '' && isset( $allowed_map[ $slug ] ) ) {
				$out[ $slug ] = true;
			}
		}

		return array_keys( $out );
	}
}
