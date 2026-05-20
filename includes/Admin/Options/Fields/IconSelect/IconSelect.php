<?php
namespace SimpleThemeOptions\Admin\Options\Fields\IconSelect;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSpacing;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * **Icon select** — Font Awesome + **WordPress Dashicons** picker (preview + modal library, filter groups).
 * Stored as a **single class string** (e.g. **`fa-light fa-house`** or **`dashicons dashicons-admin-home`**) in **`sto_options[id]`** (or per-breakpoint map when **`responsive`** is set).
 * Allowed Font Awesome values come from **`assets/admin/data/sto-icon-select-manifest.json`** (filter **`sto_icon_select_manifest`**). Dashicons are read from **`wp-includes/css/dashicons.css`** and merged in (filter **`sto_icon_select_dashicons`** on the parsed rows).
 *
 * Register with **`'type' => 'icon_select'`** (or **`IconSelect::register()`**). Keys: **`section_slug`**, **`id`**, **`title`**,
 * optional **`default`** (full class string allowed by the merged manifest — FA from JSON or **`dashicons dashicons-*`** from core), **`description`**, conditional **`required`**, **`html_required`**,
 * **`tooltip`**, **`wrapper_class`**, optional **`allow_clear`** (bool — empty value allowed), optional **`responsive`** + **`device`**.
 * **Advanced repeater:** the same **`icon_select`** declarative **`type`** may appear in **`AdvancedRepeaterControl`** **`fields`** (scalar string per JSON row — see **`AdvancedRepeaterControl`** / **`IconSelect::render_embedded_widget_markup()`**).
 * Boot **`IconSelect::instance()`** before **`Group::register()`** when used inside groups / tabs / accordion (**not** required for repeater-only icon leaves, but **`IconSelect`** must be loadable for shared markup + sanitization helpers).
 */
final class IconSelect {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	private const FALLBACK_DEFAULT = 'fa-solid fa-house';

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $fields_by_section = array();

	/**
	 * @var array<string, true>
	 */
	private $registered_ids = array();

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private $fields_by_id = array();

	/**
	 * @var array<int, array{c:string,n:string,g:string}>|null
	 */
	private static $manifest_cache = null;

	/**
	 * Validated Dashicon rows (after **`sto_icon_select_dashicons`**).
	 *
	 * @var array<int, array{c:string,n:string,g:string}>|null
	 */
	private static $dashicons_validated_cache = null;

	protected function init() {
		// After GoogleMapControl (19.441), before Tabs (19.45).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::ICON_SELECT, 2 );
	}

	/**
	 * Whether any icon select fields are registered (conditional script localization).
	 */
	public function registry_has_fields() {
		return ! empty( $this->registered_ids );
	}

	/**
	 * @return array<int, array{c:string,n:string,g:string}>
	 */
	public static function get_manifest_icons() {
		if ( null !== self::$manifest_cache ) {
			return self::$manifest_cache;
		}

		$path = STO_PATH . 'assets/admin/data/sto-icon-select-manifest.json';
		$raw  = array();
		if ( is_readable( $path ) ) {
			$json = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $json ) && ! empty( $json['icons'] ) && is_array( $json['icons'] ) ) {
				$raw = $json['icons'];
			}
		}

		$clean = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$c = isset( $row['c'] ) ? trim( (string) $row['c'] ) : '';
			$n = isset( $row['n'] ) ? sanitize_key( (string) $row['n'] ) : '';
			$g = isset( $row['g'] ) ? sanitize_key( (string) $row['g'] ) : 'solid';
			if ( $c === '' || $n === '' ) {
				continue;
			}
			if ( ! in_array( $g, array( 'solid', 'regular', 'light', 'brands', 'thin', 'duotone' ), true ) ) {
				$g = 'solid';
			}
			$c = self::sanitize_icon_class_string( $c );
			if ( $c === '' ) {
				continue;
			}
			$clean[] = array( 'c' => $c, 'n' => $n, 'g' => $g );
		}

		$clean = apply_filters( 'sto_icon_select_manifest', $clean ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
		if ( ! is_array( $clean ) ) {
			$clean = array();
		}

		foreach ( self::get_validated_dashicons_rows() as $row ) {
			$clean[] = $row;
		}

		if ( empty( $clean ) ) {
			$clean = array(
				array( 'c' => self::FALLBACK_DEFAULT, 'n' => 'house', 'g' => 'solid' ),
			);
		}

		self::$manifest_cache = $clean;

		return self::$manifest_cache;
	}

	/**
	 * Dashicons for admin script localization (same rows merged into **`get_manifest_icons()`**).
	 *
	 * @return array<int, array{c:string,n:string,g:string}>
	 */
	public static function get_dashicons_for_localize() {
		return self::get_validated_dashicons_rows();
	}

	/**
	 * Raw Dashicon slugs parsed from **`wp-includes/css/dashicons.css`** (before **`sto_icon_select_dashicons`**).
	 *
	 * @return array<int, array{c:string,n:string,g:string}>
	 */
	private static function get_dashicon_rows_from_core() {
		static $rows = null;
		if ( null !== $rows ) {
			return $rows;
		}
		$rows = array();
		$path = ABSPATH . 'wp-includes/css/dashicons.css';
		if ( ! is_readable( $path ) ) {
			return $rows;
		}
		$css = file_get_contents( $path );
		if ( ! is_string( $css ) || $css === '' ) {
			return $rows;
		}
		if ( ! preg_match_all( '/\.dashicons-(?!before\b)([a-z0-9-]+):before/', $css, $m ) || empty( $m[1] ) ) {
			return $rows;
		}
		$seen = array();
		foreach ( $m[1] as $slug ) {
			$n = sanitize_key( (string) $slug );
			if ( $n === '' || isset( $seen[ $n ] ) ) {
				continue;
			}
			$seen[ $n ] = true;
			$rows[]     = array(
				'c' => 'dashicons dashicons-' . $n,
				'n' => $n,
				'g' => 'wordpress',
			);
		}

		return $rows;
	}

	/**
	 * @return array<int, array{c:string,n:string,g:string}>
	 */
	private static function get_validated_dashicons_rows() {
		if ( null !== self::$dashicons_validated_cache ) {
			return self::$dashicons_validated_cache;
		}
		$out       = array();
		$dash_rows = apply_filters( 'sto_icon_select_dashicons', self::get_dashicon_rows_from_core() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public sto_ filter/action API.
		if ( is_array( $dash_rows ) ) {
			foreach ( $dash_rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$c = isset( $row['c'] ) ? self::sanitize_icon_class_string( (string) $row['c'] ) : '';
				$n = isset( $row['n'] ) ? sanitize_key( (string) $row['n'] ) : '';
				if ( $c === '' || $n === '' ) {
					continue;
				}
				if ( strpos( $c, 'dashicons' ) !== 0 ) {
					continue;
				}
				$out[] = array( 'c' => $c, 'n' => $n, 'g' => 'wordpress' );
			}
		}
		self::$dashicons_validated_cache = $out;

		return self::$dashicons_validated_cache;
	}

	/**
	 * Whether a stored value is a Dashicons class pair (**`dashicons dashicons-*`**).
	 *
	 * @param string $class_string
	 */
	public static function is_dashicons_value( $class_string ) {
		$s = trim( (string) $class_string );

		return $s !== '' && preg_match( '/^dashicons(\s+dashicons-[a-z0-9-]+)+$/', $s ) === 1;
	}

	/**
	 * @return array<string, true>
	 */
	public static function get_allowed_class_map() {
		$map = array();
		foreach ( self::get_manifest_icons() as $row ) {
			if ( ! empty( $row['c'] ) ) {
				$map[ $row['c'] ] = true;
			}
		}

		return $map;
	}

	/**
	 * @param string $s
	 * @return string
	 */
	public static function sanitize_icon_class_string( $s ) {
		$s = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-zA-Z0-9\- ]/', '', (string) $s ) ) );

		return $s;
	}

	/**
	 * Normalize a scalar icon stored in an **`advanced_repeater`** **`icon_select`** leaf against the manifest.
	 *
	 * @param string $raw Posted or persisted value.
	 * @param bool   $allow_clear When true, unknown / empty clears to **`''`**.
	 * @param string $schema_default Resolved default already coerced once (typically from schema **`default`**); used when **`! allow_clear`** and **`$raw`** is invalid / empty (but **non-empty invalid** maps to coercion fallback chain, not blindly to **`$schema_default`** — callers should pass **`$schema_default`** as the sane empty-row default).
	 * @return string
	 */
	public static function coerce_advanced_repeater_leaf_value( string $raw, bool $allow_clear, string $schema_default ) {
		$allowed = self::get_allowed_class_map();
		$s       = self::sanitize_icon_class_string( $raw );
		if ( $s !== '' && isset( $allowed[ $s ] ) ) {
			return $s;
		}
		if ( $allow_clear ) {
			return '';
		}
		if ( $s === '' ) {
			$d = self::sanitize_icon_class_string( $schema_default );
			if ( $d !== '' && isset( $allowed[ $d ] ) ) {
				return $d;
			}

			return isset( $allowed[ self::FALLBACK_DEFAULT ] ) ? self::FALLBACK_DEFAULT : self::get_first_allowed_class();
		}
		if ( isset( $allowed[ self::FALLBACK_DEFAULT ] ) ) {
			return self::FALLBACK_DEFAULT;
		}

		return self::get_first_allowed_class();
	}

	/**
	 * Same **IconSelect** picker DOM **`embedded_icon_widget_markup()`** as **`render_icon_widget()`**. Repeater cells carry **`sto-field-row-icon-select`** so **`sto-icon-select-field.css`** keeps the fullscreen library popup (**`position: fixed`**, not flowing inline below the preview).
	 * Does not emit **`responsive`** breakpoint maps here.
	 *
	 * @param string      $id_suffix           Unique suffix for **`id`** attributes (sanitize before call).
	 * @param string|null $input_name          **`name`** for the hidden value; **`null`** when the hosting UI owns persistence (omit attribute).
	 * @param string      $current             Current class string.
	 * @param string      $aria_label          Accessible label for the widget.
	 * @param bool        $allow_clear         Whether clearing to empty is allowed.
	 * @param string|null $repeater_row_default Optional: when **`$input_name`** is **`null`**, **`data-sto-adv-rep-icon`** + **`data-sto-adv-rep-icon-default`** are added for advanced repeater JSON sync / row reset (equals schema default).
	 */
	public static function render_embedded_widget_markup( string $id_suffix, ?string $input_name, string $current, string $aria_label, bool $allow_clear, ?string $repeater_row_default = null ) {
		self::embedded_icon_widget_markup( $id_suffix, $input_name, $current, $aria_label, $allow_clear, $repeater_row_default );
	}

	/**
	 * Shared markup builder for **`render_field_markup`** and **`render_embedded_widget_markup`**.
	 *
	 * @param string|null $repeater_row_default When non-null, emits repeater **`data-*`** helpers on the hidden input.
	 */
	private static function embedded_icon_widget_markup( string $id_suffix, ?string $input_name, string $current, string $aria_label, bool $allow_clear, ?string $repeater_row_default = null ) {
		$wid = 'sto_icon_select_' . $id_suffix;
		?>
		<div
			class="sto-icon-select"
			data-sto-icon-select="1"
			<?php if ( $allow_clear ) : ?>
				data-sto-icon-select-allow-clear="1"
			<?php endif; ?>
			aria-label="<?php echo esc_attr( $aria_label ); ?>"
		>
			<div class="sto-icon-select__chrome">
				<button
					type="button"
					class="sto-icon-select__preview"
					id="<?php echo esc_attr( $wid . '_preview' ); ?>"
					data-sto-icon-select-open
					aria-haspopup="dialog"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $wid . '_dialog' ); ?>"
				>
					<span class="sto-icon-select__preview-inner" data-sto-icon-select-preview>
						<?php if ( $current !== '' ) : ?>
							<?php if ( self::is_dashicons_value( $current ) ) : ?>
								<span class="<?php echo esc_attr( $current ); ?>" aria-hidden="true"></span>
							<?php else : ?>
								<i class="<?php echo esc_attr( $current ); ?>" aria-hidden="true"></i>
							<?php endif; ?>
						<?php else : ?>
							<span class="sto-icon-select__placeholder"><?php esc_html_e( 'No icon', 'topten-simple-theme-options' ); ?></span>
						<?php endif; ?>
					</span>
					<span class="sto-icon-select__preview-hint"><?php esc_html_e( 'Click to choose', 'topten-simple-theme-options' ); ?></span>
				</button>
				<?php if ( $allow_clear ) : ?>
					<button type="button" class="sto-icon-select__clear" data-sto-icon-select-clear aria-label="<?php esc_attr_e( 'Clear icon', 'topten-simple-theme-options' ); ?>">
						<i class="fa-light fa-xmark" aria-hidden="true"></i>
					</button>
				<?php endif; ?>
			</div>
			<input
				type="hidden"
				class="sto-icon-select-value"
				<?php echo null !== $input_name ? 'name="' . esc_attr( $input_name ) . '"' : ''; ?>
				value="<?php echo esc_attr( $current ); ?>"
				autocomplete="off"
				<?php echo null !== $repeater_row_default ? ' data-sto-adv-rep-icon="1" data-sto-adv-rep-icon-default="' . esc_attr( $repeater_row_default ) . '"' : ''; ?>
			/>
			<div
				class="sto-icon-select__modal"
				id="<?php echo esc_attr( $wid . '_dialog' ); ?>"
				data-sto-icon-select-modal
				hidden
				role="dialog"
				aria-modal="true"
				aria-labelledby="<?php echo esc_attr( $wid . '_title' ); ?>"
			>
				<div class="sto-icon-select__backdrop" data-sto-icon-select-close tabindex="-1" aria-hidden="true"></div>
				<div class="sto-icon-select__panel" role="document">
					<header class="sto-icon-select__head">
						<h2 class="sto-icon-select__title" id="<?php echo esc_attr( $wid . '_title' ); ?>"><?php esc_html_e( 'Icon library', 'topten-simple-theme-options' ); ?></h2>
						<button type="button" class="sto-icon-select__close" data-sto-icon-select-close aria-label="<?php esc_attr_e( 'Close', 'topten-simple-theme-options' ); ?>">
							<i class="fa-light fa-xmark" aria-hidden="true"></i>
						</button>
					</header>
					<div class="sto-icon-select__layout">
						<nav class="sto-icon-select__nav" aria-label="<?php esc_attr_e( 'Icon library filters', 'topten-simple-theme-options' ); ?>">
							<button type="button" class="sto-icon-select__nav-btn sto-is-active" data-sto-icon-filter="all"><?php esc_html_e( 'All icons', 'topten-simple-theme-options' ); ?></button>
							<button type="button" class="sto-icon-select__nav-btn" data-sto-icon-filter="solid"><?php esc_html_e( 'Solid', 'topten-simple-theme-options' ); ?></button>
							<button type="button" class="sto-icon-select__nav-btn" data-sto-icon-filter="regular"><?php esc_html_e( 'Regular', 'topten-simple-theme-options' ); ?></button>
							<button type="button" class="sto-icon-select__nav-btn" data-sto-icon-filter="light"><?php esc_html_e( 'Light', 'topten-simple-theme-options' ); ?></button>
							<button type="button" class="sto-icon-select__nav-btn" data-sto-icon-filter="brands"><?php esc_html_e( 'Brands', 'topten-simple-theme-options' ); ?></button>
							<button type="button" class="sto-icon-select__nav-btn" data-sto-icon-filter="wordpress"><?php esc_html_e( 'WordPress', 'topten-simple-theme-options' ); ?></button>
						</nav>
						<div class="sto-icon-select__main">
							<div class="sto-icon-select__search-wrap">
								<label class="screen-reader-text" for="<?php echo esc_attr( $wid . '_q' ); ?>"><?php esc_html_e( 'Filter by name', 'topten-simple-theme-options' ); ?></label>
								<input type="search" class="sto-icon-select__search sto-input-text" id="<?php echo esc_attr( $wid . '_q' ); ?>" data-sto-icon-select-q placeholder="<?php esc_attr_e( 'Filter by name…', 'topten-simple-theme-options' ); ?>" autocomplete="off" />
								<span class="sto-icon-select__search-icon" aria-hidden="true"><i class="fa-light fa-magnifying-glass"></i></span>
							</div>
							<div class="sto-icon-select__grid-wrap">
								<div class="sto-icon-select__grid" data-sto-icon-select-grid></div>
							</div>
						</div>
					</div>
					<footer class="sto-icon-select__foot">
						<button type="button" class="button sto-icon-select__btn-secondary" data-sto-icon-select-close><?php esc_html_e( 'Cancel', 'topten-simple-theme-options' ); ?></button>
						<button type="button" class="button button-primary sto-icon-select__insert" data-sto-icon-select-insert disabled><?php esc_html_e( 'Insert', 'topten-simple-theme-options' ); ?></button>
					</footer>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function register( $field ) {
		$instance = static::instance();
		FieldRegistrationDeferral::defer_or_run(
			function () use ( $instance, $field ) {
				$instance->register_field_config( $field );
			}
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $fields
	 */
	public static function register_many( $fields ) {
		$instance = static::instance();
		FieldRegistrationDeferral::defer_or_run(
			function () use ( $instance, $fields ) {
				if ( ! is_array( $fields ) ) {
					return;
				}
				foreach ( $fields as $f ) {
					$instance->register_field_config( $f );
				}
			}
		);
	}

	/**
	 * @param mixed $field
	 */
	private function register_field_config( $field ): void {
		if ( ! is_array( $field ) ) {
			return;
		}
		FieldSpacing::normalize_config( $field );


		$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
		$field_id     = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';

		if ( ! $section_slug || ! $field_id ) {
			return;
		}

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['html_required'] = ! empty( $field['html_required'] );
		$field['allow_clear']   = ! empty( $field['allow_clear'] );

		$bps = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

		$allowed = self::get_allowed_class_map();
		$def_in  = isset( $field['default'] ) ? self::sanitize_icon_class_string( (string) $field['default'] ) : '';
		if ( $def_in !== '' && isset( $allowed[ $def_in ] ) ) {
			$field['default'] = $def_in;
		} elseif ( ! $field['allow_clear'] ) {
			$first = self::get_first_allowed_class();
			$field['default'] = isset( $allowed[ self::FALLBACK_DEFAULT ] ) ? self::FALLBACK_DEFAULT : $first;
		} else {
			$field['default'] = $def_in !== '' && isset( $allowed[ $def_in ] ) ? $def_in : '';
		}

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]            = $field;
	}

	/**
	 * @param string $section_slug
	 * @return array<int, string>
	 */
	public function registry_get_field_ids_for_section( $section_slug ) {
		$section_slug = sanitize_key( (string) $section_slug );
		if ( $section_slug === '' || empty( $this->fields_by_section[ $section_slug ] ) ) {
			return array();
		}
		$ids = array();
		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			$id = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			if ( $id !== '' ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	public function registry_is_registered_field_id( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );

		return $field_id && ! empty( $this->registered_ids[ $field_id ] );
	}

	/**
	 * @param string $section_slug
	 * @param string $field_id
	 * @return array<string, mixed>|null
	 */
	public function registry_get_field( $section_slug, $field_id ) {
		$section_slug = sanitize_key( (string) $section_slug );
		$field_id     = sanitize_key( (string) $field_id );
		if ( ! $section_slug || ! $field_id || empty( $this->fields_by_section[ $section_slug ] ) ) {
			return null;
		}
		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			if ( isset( $field['id'] ) && $field['id'] === $field_id ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public function registry_get_all_fields_for_search() {
		$out = array();
		foreach ( $this->fields_by_section as $section_slug => $fields ) {
			foreach ( $fields as $field ) {
				$title = isset( $field['title'] ) ? trim( (string) $field['title'] ) : '';
				if ( $title === '' ) {
					continue;
				}
				$out[] = array(
					'section_slug' => (string) $section_slug,
					'id'           => isset( $field['id'] ) ? (string) $field['id'] : '',
					'title'        => $title,
					'group'        => ! empty( $field['group'] ) ? (string) $field['group'] : '',
				);
			}
		}

		return $out;
	}

	/**
	 * @param string               $field_id
	 * @param string|array<mixed> $raw Posted string or per-breakpoint map.
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return '';
		}
		$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;

		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->sanitize_cell( $cell, $field );
			}

			return $out;
		}

		return $this->sanitize_cell( $raw, $field );
	}

	/**
	 * @param mixed                $cell
	 * @param array<string, mixed> $field
	 * @return string
	 */
	private function sanitize_cell( $cell, array $field ) {
		$allowed = self::get_allowed_class_map();
		$s       = is_string( $cell ) ? self::sanitize_icon_class_string( $cell ) : '';
		if ( $s !== '' && isset( $allowed[ $s ] ) ) {
			return $s;
		}
		if ( ! empty( $field['allow_clear'] ) ) {
			return '';
		}
		$def = isset( $field['default'] ) ? self::sanitize_icon_class_string( (string) $field['default'] ) : '';
		if ( $def !== '' && isset( $allowed[ $def ] ) ) {
			return $def;
		}

		return isset( $allowed[ self::FALLBACK_DEFAULT ] ) ? self::FALLBACK_DEFAULT : self::get_first_allowed_class();
	}

	/**
	 * @return string
	 */
	private static function get_first_allowed_class() {
		foreach ( self::get_manifest_icons() as $row ) {
			if ( ! empty( $row['c'] ) ) {
				return (string) $row['c'];
			}
		}

		return self::FALLBACK_DEFAULT;
	}

	/**
	 * @param string               $section_slug
	 * @param array<string, mixed> $section
	 */
	public function render_section_fields( $section_slug, $section ) {
		$section_slug = sanitize_key( (string) $section_slug );
		if ( empty( $this->fields_by_section[ $section_slug ] ) ) {
			return;
		}
		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			if ( ! empty( $field['group'] ) || ResponsiveConfig::is_composite_inner_field( $field ) ) {
				continue;
			}
			if ( ! FieldRenderGate::should_render_field( $field ) ) {
				continue;
			}
			$this->render_field_markup( $field, 'default' );
		}
	}

	/**
	 * @param array<string, mixed>    $field
	 * @param 'default'|'group_inner' $context
	 */
	public function render_field_markup( $field, $context = 'default' ) {
		if ( ! is_array( $field ) ) {
			return;
		}

		$field_id      = $field['id'];
		$title         = $field['title'];
		$description   = $field['description'];
		$wrapper_class = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$required      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$tooltip       = FieldTitle::get_tooltip_config( $field );
		$bps_storage   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$default       = isset( $field['default'] ) ? (string) $field['default'] : '';
		$allow_clear   = ! empty( $field['allow_clear'] );

		$is_inner    = ( 'group_inner' === $context );
		$group_label = $title !== '' ? $title : $field_id;

		$row_classes = array( 'sto-field-row', 'sto-field-row-icon-select' );
		if ( $wrapper_class ) {
			$row_classes[] = $wrapper_class;
		}
		if ( $is_inner ) {
			$row_classes[] = 'sto-field-row--in-group';
		}
		if ( $tabs_pane_bp !== '' ) {
			$row_classes[] = 'sto-field-row--tabs-pane-slice';
		}
		if ( ! empty( $bps_storage ) && $tabs_pane_bp === '' ) {
			$row_classes[] = 'sto-field-row--responsive';
		}

		$eval_bp = ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT;
		if ( ! empty( $bps_storage ) && ! in_array( $eval_bp, $bps_storage, true ) ) {
			$eval_bp = (string) $bps_storage[0];
		}
		$toolbar_markup = ( $tabs_pane_bp === '' && ! empty( $bps_storage ) ) ? ResponsiveControl::toolbar_markup( $bps_storage, $field_id ) : '';

		?>
		<div
			id="<?php echo esc_attr( 'sto-field-' . $field_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute string from FieldSpacing::row_margin_style_attr().
			echo FieldSpacing::row_margin_style_attr( $field, $context );
			?>
			data-sto-field-id="<?php echo esc_attr( $field_id ); ?>"
			<?php if ( $required_json ) : ?>
				data-sto-required="<?php echo esc_attr( $required_json ); ?>"
			<?php endif; ?>
			<?php if ( ! empty( $bps_storage ) && $tabs_pane_bp === '' ) : ?>
				data-sto-responsive="1"
				data-sto-active-bp="<?php echo esc_attr( (string) $bps_storage[0] ); ?>"
				data-sto-require-eval-bp="<?php echo esc_attr( $eval_bp ); ?>"
			<?php endif; ?>
		>
			<?php if ( $title || $toolbar_markup !== '' ) : ?>
				<?php FieldTitle::render_heading( $title, $context, $tooltip, $field_id, $is_inner, $toolbar_markup ); ?>
			<?php endif; ?>

			<?php if ( $tabs_pane_bp !== '' && $bps_storage ) : ?>
				<?php
				$value_map = $this->get_value_map( $field_id, $default, $bps_storage, $field );
				$current   = isset( $value_map[ $tabs_pane_bp ] ) ? (string) $value_map[ $tabs_pane_bp ] : $default;
				$current   = $this->coerce_value( $current, $field );
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$id_suffix  = $field_id . '_' . $tabs_pane_bp;
				$this->render_icon_widget( $id_suffix, $input_name, $current, $group_label . ' — ' . strtoupper( $tabs_pane_bp ), $allow_clear );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $default, $bps_storage, $field );
					foreach ( $bps_storage as $i => $bp ) :
						$bp         = sanitize_key( (string) $bp );
						$visible    = ( 0 === (int) $i );
						$current    = isset( $value_map[ $bp ] ) ? (string) $value_map[ $bp ] : $default;
						$current    = $this->coerce_value( $current, $field );
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$id_suffix  = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_icon_widget( $id_suffix, $input_name, $current, $group_label . ' — ' . strtoupper( $bp ), $allow_clear );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$current    = $this->get_option_value( $field_id, $default, $field );
				$input_name = 'sto_options[' . $field_id . ']';
				$this->render_icon_widget( $field_id, $input_name, $current, $group_label, $allow_clear );
				?>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string               $id_suffix
	 * @param string               $input_name
	 * @param string               $current
	 * @param string               $group_label
	 * @param bool                 $allow_clear
	 */
	private function render_icon_widget( $id_suffix, $input_name, $current, $group_label, $allow_clear ) {
		self::embedded_icon_widget_markup( (string) $id_suffix, (string) $input_name, (string) $current, (string) $group_label, (bool) $allow_clear, null );
	}

	/**
	 * @param string               $field_id
	 * @param string               $default
	 * @param array<int, string>   $breakpoints
	 * @param array<string, mixed> $field
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, $default, array $breakpoints, array $field ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			$scalar = $this->coerce_value( $default, $field );

			return ResponsiveConfig::coerce_map( null, $breakpoints, $scalar );
		}
		$stored = $saved[ $field_id ];
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$map = ResponsiveConfig::coerce_map( $stored, $breakpoints, $this->coerce_value( $default, $field ) );
			foreach ( $map as $bp => $val ) {
				$map[ $bp ] = $this->coerce_value( (string) $val, $field );
			}

			return $map;
		}

		return ResponsiveConfig::coerce_map( null, $breakpoints, $this->coerce_value( (string) $stored, $field ) );
	}

	/**
	 * @param string               $field_id
	 * @param string               $default
	 * @param array<string, mixed> $field
	 * @return string
	 */
	private function get_option_value( $field_id, $default, array $field ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) ) {
			return $this->coerce_value( $default, $field );
		}
		if ( isset( $saved[ $field_id ] ) ) {
			$st = $saved[ $field_id ];
			if ( is_array( $st ) && ResponsiveConfig::is_breakpoint_value_map( $st ) ) {
				return $this->coerce_value( (string) ResponsiveConfig::value_for_required_eval( $st ), $field );
			}

			return $this->coerce_value( is_string( $st ) ? $st : '', $field );
		}

		return $this->coerce_value( $default, $field );
	}

	/**
	 * @param string               $raw
	 * @param array<string, mixed> $field
	 * @return string
	 */
	private function coerce_value( $raw, array $field ) {
		return $this->sanitize_cell( $raw, $field );
	}

	/**
	 * @param string               $section_slug
	 * @param array<string, mixed> $option_values
	 * @return array<int, string>
	 */
	public function collect_html_required_violations_for_section( $section_slug, array $option_values ) {
		$section_slug = sanitize_key( (string) $section_slug );
		if ( $section_slug === '' || empty( $this->fields_by_section[ $section_slug ] ) ) {
			return array();
		}
		$messages = array();
		foreach ( $this->fields_by_section[ $section_slug ] as $field ) {
			if ( ! is_array( $field ) || empty( $field['html_required'] ) ) {
				continue;
			}
			$rules = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
			if ( ! RequiredVisibility::row_is_visible( $rules, $option_values ) ) {
				continue;
			}
			$fid = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			if ( $fid === '' ) {
				continue;
			}
			$raw = array_key_exists( $fid, $option_values ) ? $option_values[ $fid ] : null;
			if ( ! $this->is_html_required_value_empty( $field, $raw ) ) {
				continue;
			}
			$title = isset( $field['title'] ) ? trim( (string) $field['title'] ) : '';
			$label = $title !== '' ? $title : $fid;
			$messages[] = sprintf(
				/* translators: %s: field label */
				__( '“%s” must be filled in before this section can be saved.', 'topten-simple-theme-options' ),
				$label
			);
		}

		return $messages;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param mixed                $raw
	 */
	private function is_html_required_value_empty( array $field, $raw ) {
		$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$slice = ResponsiveConfig::value_for_required_eval( $raw );

			return $this->cell_is_empty( is_string( $slice ) ? $slice : '' );
		}

		return $this->cell_is_empty( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) );
	}

	/**
	 * @param string $cell
	 */
	private function cell_is_empty( $cell ) {
		return trim( (string) $cell ) === '';
	}

	/**
	 * Resolved icon class for a registered field (theme use).
	 *
	 * @param string      $field_id       Option key.
	 * @param string|null $scalar_or_null When non-null, coerce this string instead of reading options.
	 * @return string
	 */
	public function get_icon_class_for_field( $field_id, $scalar_or_null = null ) {
		$field_id = sanitize_key( (string) $field_id );
		if ( $field_id === '' || ! isset( $this->fields_by_id[ $field_id ] ) ) {
			return self::FALLBACK_DEFAULT;
		}
		$field = $this->fields_by_id[ $field_id ];
		$raw   = '';
		if ( null !== $scalar_or_null && is_string( $scalar_or_null ) ) {
			$raw = $scalar_or_null;
		} else {
			$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
			if ( ! is_array( $opts ) || ! array_key_exists( $field_id, $opts ) ) {
				return $this->coerce_value( isset( $field['default'] ) ? (string) $field['default'] : '', $field );
			}
			$stored = $opts[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$slice = ResponsiveConfig::value_for_required_eval( $stored );
				$raw   = is_string( $slice ) ? $slice : '';
			} else {
				$raw = is_string( $stored ) ? $stored : '';
			}
		}

		return $this->coerce_value( $raw, $field );
	}
}
