<?php
namespace SimpleThemeOptions\Admin\Options\Fields\CodeEditor;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\PremiumFieldGate;

use SimpleThemeOptions\Admin\Options\Menu as OptionsMenu;
use SimpleThemeOptions\Admin\ThemeSettingsMetabox;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Code editor field — lightweight syntax-highlighted editor backed by **`wp.codeEditor`**
 * (WordPress core CodeMirror 5, shipped since WP 4.9). Zero new vendor files; the relevant
 * mode + addon chunks are pulled in by **`wp_enqueue_code_editor()`** on demand.
 *
 * Modes supported (mapped to CodeMirror via fake filenames inside `mode_to_enqueue_args()`):
 *   - **`css`**         → `text/css`
 *   - **`html`**        → `text/html` (also handles inline JS / CSS inside the tag soup)
 *   - **`javascript`** / **`js`** → `application/javascript`
 *   - **`php`**         → `application/x-httpd-php`
 *   - **`json`**        → `application/json` (sanitize re-encodes invalid JSON to defaults)
 *   - **`markdown`** / **`md`** → `text/x-markdown`
 *   - **`xml`**         → `text/xml`
 *   - **`yaml`** / **`yml`** → `text/x-yaml`
 *   - **`text`** (default) → no syntax highlighting (still uses CodeMirror chrome / line numbers)
 *
 * Storage:
 *   - Non-responsive: raw string in **`sto_options[id]`** (CSS / JS preserved verbatim — no
 *     `wp_kses` because it would eat legitimate syntax). Null bytes stripped, CRLF normalised
 *     to LF. HTML mode applies `wp_kses_post()` so untrusted markup can't slip through. JSON
 *     mode validates via `json_decode()` and re-encodes (drops to default on parse error).
 *   - Responsive: per-breakpoint map **`sto_options[id][xxl|md|mobile]`** of raw strings.
 *
 * UI: standard FieldTitle heading (title, **`?`** tooltip, optional responsive toolbar) →
 * a thin chrome bar (mode pill + optional fullscreen toggle) → the textarea (which the JS
 * helper upgrades to CodeMirror). Fallback when **`wp.codeEditor`** is disabled in the
 * user's WP profile (Users → Profile → "Disable syntax highlighting"): plain `<textarea>`
 * with a monospaced font and the same chrome.
 */
final class CodeEditor {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

	/** Canonical mode keys accepted by `register()`. */
	public const ALLOWED_MODES = array(
		'auto',
		'text',
		'css',
		'html',
		'javascript',
		'php',
		'json',
		'markdown',
		'xml',
		'yaml',
	);

	/**
	 * Modes that the chrome-bar **`<select>`** language switcher exposes. **`auto`** sits at the
	 * top so admins can flip back to detection after manually overriding. **`text`** sits at the
	 * bottom because it's effectively "turn highlighting off".
	 */
	public const SWITCHER_MODES = array( 'auto', 'css', 'html', 'javascript', 'php', 'json', 'markdown', 'xml', 'yaml', 'text' );

	/**
	 * Modes whose CodeMirror parsers must be loaded when **`mode => 'auto'`** is used (or when the
	 * field exposes a language switcher) — otherwise switching to e.g. PHP at runtime would yield
	 * a plain-text editor because the parser is missing.
	 */
	private const AUTO_PRELOAD_MIMES = array(
		'text/css',
		'text/html',
		'application/javascript',
		'application/x-httpd-php',
		'application/json',
		'text/x-markdown',
		'text/xml',
		'text/x-yaml',
	);

	/** Mode aliases — friendly synonyms admins commonly type. */
	private const MODE_ALIASES = array(
		'js'        => 'javascript',
		'jsx'       => 'javascript',
		'ts'        => 'javascript',
		'md'        => 'markdown',
		'yml'       => 'yaml',
		'plain'     => 'text',
		'plaintext' => 'text',
		'txt'       => 'text',
	);

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $fields_by_section = array();

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private $fields_by_id = array();

	/**
	 * @var array<string, true>
	 */
	private $registered_ids = array();

	protected function init() {
		// Between Input (19.5) and Typography (20) so plain text inputs and code blocks sit together.
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::CODE_EDITOR, 2 );

		// Prime `wp.codeEditor` (loads CodeMirror + the required modes) on Theme Settings **and**
		// post editor metabox screens. Priority **9** runs before `Assets::enqueue_scripts` (10).
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_prime_code_editor' ), 9 );
		// Block editor loads the edit screen in a context where we still need CodeMirror registered
		// for meta box markup (mirrors the admin `post.php` hook path).
		add_action( 'enqueue_block_editor_assets', array( $this, 'maybe_prime_code_editor_block' ), 9 );
	}

	/**
	 * Register a code-editor field.
	 *
	 * Keys: section_slug, id, title?, description?, default? (string), placeholder?, **mode** (see ALLOWED_MODES),
	 *       height? (px integer; default 240), min_height? (px integer; default 160),
	 *       line_numbers? (bool; default true), line_wrapping? (bool; default false — code stays scrollable),
	 *       indent_size? (int; default 4), tab_size? (int; mirrors indent_size when omitted),
	 *       fullscreen? (bool; default true — show the expand button),
	 *       **autocomplete?** (bool; default **false** — set **`true`** for `Ctrl/Cmd + Space` + smart auto-trigger),
	 *       **language_switcher?** (bool; default **true** — show the in-chrome `<select>` so admins
	 *       can override auto-detection or change the language without leaving the screen),
	 *       responsive? (bool / array), device? (see ResponsiveConfig),
	 *       wrapper_class?, required?, html_required?, group?, tooltip?, tooltip_image?, tooltip_preloader?
	 *
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

		$section_slug = isset( $field['section_slug'] ) ? sanitize_key( (string) $field['section_slug'] ) : '';
		$field_id     = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';

		if ( ! $section_slug || ! $field_id ) {
			return;
		}

		$mode = $this->normalize_mode( isset( $field['mode'] ) ? $field['mode'] : 'text' );

		$height     = isset( $field['height'] ) && is_numeric( $field['height'] ) ? (int) $field['height'] : 240;
		$min_height = isset( $field['min_height'] ) && is_numeric( $field['min_height'] ) ? (int) $field['min_height'] : 160;
		if ( $height < $min_height ) {
			$height = $min_height;
		}
		if ( $min_height < 80 ) {
			$min_height = 80;
		}
		if ( $height > 2000 ) {
			$height = 2000;
		}

		$indent = isset( $field['indent_size'] ) && is_numeric( $field['indent_size'] ) ? (int) $field['indent_size'] : 4;
		if ( $indent < 1 ) {
			$indent = 1;
		}
		if ( $indent > 8 ) {
			$indent = 8;
		}

		$tab_size = isset( $field['tab_size'] ) && is_numeric( $field['tab_size'] ) ? (int) $field['tab_size'] : $indent;
		if ( $tab_size < 1 ) {
			$tab_size = 1;
		}
		if ( $tab_size > 8 ) {
			$tab_size = 8;
		}

		$field['section_slug']         = $section_slug;
		$field['id']                   = $field_id;
		$field['title']                = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']          = isset( $field['description'] ) ? (string) $field['description'] : '';
		$field['placeholder']          = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$field['default']              = isset( $field['default'] ) && is_scalar( $field['default'] ) ? (string) $field['default'] : '';
		$field['wrapper_class']        = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']             = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['html_required']       = ! empty( $field['html_required'] );
		$field['group']                = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$field['mode']                 = $mode;
		$field['height']               = $height;
		$field['min_height']           = $min_height;
		$field['line_numbers']         = ! array_key_exists( 'line_numbers', $field ) || (bool) $field['line_numbers'];
		$field['line_wrapping']        = isset( $field['line_wrapping'] ) && (bool) $field['line_wrapping'];
		$field['indent_size']          = $indent;
		$field['tab_size']             = $tab_size;
		$field['fullscreen']           = ! array_key_exists( 'fullscreen', $field ) || (bool) $field['fullscreen'];
		// Chrome-bar language switcher defaults **on** (override `mode => 'auto'` or flip language).
		// Autocomplete defaults **off** — most snippet fields don't need Ctrl/Cmd+Space hints.
		$field['autocomplete']         = isset( $field['autocomplete'] ) && (bool) $field['autocomplete'];
		$field['language_switcher']    = ! array_key_exists( 'language_switcher', $field ) || (bool) $field['language_switcher'];
		$field['responsive_breakpoints'] = ResponsiveConfig::breakpoints_for_field( $field );

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

	/**
	 * @param string $field_id
	 * @return bool
	 */
	public function registry_is_registered_field_id( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );

		return $field_id !== '' && isset( $this->registered_ids[ $field_id ] );
	}

	/**
	 * @param string $field_id
	 * @return array<int, string>|null
	 */
	public function get_responsive_breakpoints( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;

		return is_array( $field ) ? ( $field['responsive_breakpoints'] ?? null ) : null;
	}

	/**
	 * Server-side checks when **`html_required`** is set — only if conditional **`required`**
	 * visibility rules mean the row is shown (parity with Theme Settings JS).
	 *
	 * @param string               $section_slug  Leaf section being saved.
	 * @param array<string, mixed> $option_values Merged preview of `sto_options`.
	 * @return array<int, string> User-visible error strings.
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

			$field_option_key = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
			if ( $field_option_key === '' ) {
				continue;
			}

			$row_value_raw = array_key_exists( $field_option_key, $option_values ) ? $option_values[ $field_option_key ] : null;
			if ( $this->field_value_is_nonempty_for_required( $field, $row_value_raw ) ) {
				continue;
			}

			$heading_title      = isset( $field['title'] ) ? trim( (string) $field['title'] ) : '';
			$error_field_label = $heading_title !== '' ? $heading_title : $field_option_key;

			$messages[] = sprintf(
				/* translators: %s: field label */
				__( '“%s” must be filled in before this section can be saved.', 'simple-theme-options' ),
				$error_field_label
			);
		}

		return $messages;
	}

	/**
	 * @param array<string, mixed> $field Registered editor field.
	 * @param mixed                $raw_value Stored scalar or breakpoint map for this option id.
	 */
	private function field_value_is_nonempty_for_required( array $field, $raw_value ): bool {
		if ( is_array( $raw_value ) && ResponsiveConfig::is_breakpoint_value_map( $raw_value ) ) {
			$breakpoints_for_field = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : array();
			foreach ( $breakpoints_for_field as $breakpoint_key ) {
				$breakpoint_key = sanitize_key( (string) $breakpoint_key );
				$breakpoint_cell_value = isset( $raw_value[ $breakpoint_key ] ) ? $raw_value[ $breakpoint_key ] : '';
				if ( trim( wp_strip_all_tags( (string) $breakpoint_cell_value ) ) !== '' ) {
					return true;
				}
			}

			return false;
		}

		return trim( wp_strip_all_tags( is_scalar( $raw_value ) ? (string) $raw_value : '' ) ) !== '';
	}

	/**
	 * Sanitize the value coming from `$_POST['sto_options'][ $id ]`. For responsive fields the raw
	 * payload is an associative breakpoint map; otherwise it's a single scalar.
	 *
	 * @param string $field_id
	 * @param mixed  $raw
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
				$out[ $bp ] = $this->sanitize_stored_value( is_scalar( $cell ) ? (string) $cell : '', $field );
			}

			return $out;
		}

		return $this->sanitize_stored_value( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ), $field );
	}

	/**
	 * Mode-aware string sanitizer. Common rules:
	 *   - Strip null bytes (no payload smuggling).
	 *   - Normalise CRLF → LF so diffs stay clean across editor reloads.
	 *   - Cap at **`64KB`** to protect the database (more than enough for theme custom code;
	 *     larger snippets should live in a child theme file).
	 *
	 * Mode-specific rules:
	 *   - **`html`** → `wp_kses_post()` so editor cap users can drop arbitrary script /
	 *     style tags but contributor-level cannot inject XSS.
	 *   - **`json`** → `json_decode( … , false )` validity check; invalid input falls back
	 *     to the registered default and the original is dropped (admins can fix and re-save).
	 *   - **`text`** / **`css`** / **`javascript`** / **`php`** / **`markdown`** / **`xml`** /
	 *     **`yaml`** → raw string (admins are expected to have the right capability).
	 *
	 * @param string               $raw
	 * @param array<string, mixed> $field
	 * @return string
	 */
	public function sanitize_stored_value( $raw, array $field ) {
		$raw = is_string( $raw ) ? $raw : '';
		$raw = str_replace( "\0", '', $raw );
		$raw = str_replace( "\r\n", "\n", $raw );
		$raw = str_replace( "\r", "\n", $raw );

		// 64KB cap — DB safe and well above any reasonable inline snippet length.
		if ( strlen( $raw ) > 65536 ) {
			$raw = substr( $raw, 0, 65536 );
		}

		$mode = isset( $field['mode'] ) ? (string) $field['mode'] : 'text';

		if ( $mode === 'html' ) {
			// Strip pre-magic-quotes slashes added by WP before `wp_kses_post` reformats.
			return (string) wp_kses_post( wp_unslash( $raw ) );
		}

		if ( $mode === 'json' ) {
			$candidate = trim( $raw );
			if ( $candidate === '' ) {
				return '';
			}
			$decoded = json_decode( $candidate, true );
			if ( $decoded === null && strtolower( $candidate ) !== 'null' ) {
				// Invalid JSON — fall back to registered default (or empty).
				return isset( $field['default'] ) ? (string) $field['default'] : '';
			}

			// Re-encode to canonicalise whitespace; preserve unicode + slashes for paths / URLs.
			$re = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			return is_string( $re ) ? $re : '';
		}

		// CSS / JS / PHP / Markdown / XML / YAML / Text — store verbatim. Admin-level capability
		// is enforced by `Menu::maybe_handle_save_request()` upstream.
		return $raw;
	}

	/**
	 * Render every standalone code-editor field for a section (group-inner + tabs-inner are skipped).
	 *
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
	 * @param string $section_slug
	 * @param string $field_id
	 * @return array<string, mixed>|null
	 */
	public function registry_get_field( $section_slug, $field_id ) {
		$section_slug = sanitize_key( (string) $section_slug );
		$field_id     = sanitize_key( (string) $field_id );
		if ( $section_slug === '' || $field_id === '' || empty( $this->fields_by_section[ $section_slug ] ) ) {
			return null;
		}
		foreach ( $this->fields_by_section[ $section_slug ] as $f ) {
			$fid = isset( $f['id'] ) ? sanitize_key( (string) $f['id'] ) : '';
			if ( $fid === $field_id ) {
				return $f;
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

		$is_inner = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-code-editor' );
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
			class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
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

			<?php if ( PremiumFieldGate::render_controls_or_locked_placeholder( $title, 'code_editor' ) ) : ?>
			<?php elseif ( $tabs_pane_bp !== '' && $bps_storage ) : ?>
				<?php
				$value_map = $this->get_value_map( $field_id, $bps_storage, $field );
				$cur       = isset( $value_map[ $tabs_pane_bp ] ) ? (string) $value_map[ $tabs_pane_bp ] : (string) $field['default'];
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$suffix     = $field_id . '_' . $tabs_pane_bp;
				$this->render_editor_control( $suffix, $input_name, $cur, $field );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $bps_storage, $field );
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						$cur     = isset( $value_map[ $bp ] ) ? (string) $value_map[ $bp ] : (string) $field['default'];
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$suffix     = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_editor_control( $suffix, $input_name, $cur, $field );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$cur = $this->get_option_value( $field_id, (string) $field['default'] );
				$this->render_editor_control( $field_id, 'sto_options[' . $field_id . ']', $cur, $field );
				?>
			<?php endif; ?>

			<?php if ( $description && ! PremiumFieldGate::is_locked() ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the editor chrome (mode pill + fullscreen toggle) and the textarea CodeMirror upgrades.
	 *
	 * @param string               $suffix     DOM-unique suffix (field id or `{id}_{bp}`).
	 * @param string               $input_name Full posted name (responsive maps already include the bp key).
	 * @param string               $value      Current raw value.
	 * @param array<string, mixed> $field
	 */
	private function render_editor_control( $suffix, $input_name, $value, array $field ) {
		$ta_id   = 'sto-code-editor-' . $suffix;
		$mode    = (string) $field['mode'];
		$height  = (int) $field['height'];
		$min_h   = (int) $field['min_height'];
		$indent  = (int) $field['indent_size'];
		$tab_sz  = (int) $field['tab_size'];
		$ln      = (bool) $field['line_numbers'];
		$lw      = (bool) $field['line_wrapping'];
		$fs      = (bool) $field['fullscreen'];
		$ac      = (bool) $field['autocomplete'];
		$switch  = (bool) $field['language_switcher'];
		$placeh  = (string) $field['placeholder'];

		// For `mode => 'auto'` the CodeMirror parser stays at `text/plain` on first paint; the JS
		// helper runs `detectModeFromContent()` once the editor is mounted and switches to the
		// detected mime via `cm.setOption('mode', mime)`. Pre-loading every common mode (see
		// `maybe_prime_code_editor()`) makes that switch zero-latency.
		$is_auto         = ( $mode === 'auto' );
		$initial_cm_mime = $is_auto ? 'text/plain' : $this->mode_to_cm_mime( $mode );

		// Settings handed to `wp.codeEditor.initialize(textarea, settings)` on the JS side.
		// Keys match the CodeMirror config object so the JS helper can do `_.extend()`-style merges.
		$settings = array(
			'mode'           => $initial_cm_mime,
			'indentUnit'     => $indent,
			'tabSize'        => $tab_sz,
			'lineNumbers'    => $ln,
			'lineWrapping'   => $lw,
			'placeholder'    => $placeh,
			'theme'          => 'default',
			// Bracket / tag matching + automatic close — small UX wins that don't cost any
			// extra payload because the addons are already in WP's `wp-codemirror` bundle.
			'matchBrackets'  => true,
			'autoCloseBrackets' => true,
			'autoCloseTags'  => true,
		);
		if ( $ac ) {
			// Hint scaffolding — `Ctrl+Space` binding + auto-trigger live in `sto-code-editor.js`.
			$settings['hintOptions'] = array(
				'completeSingle' => false,
				'closeOnUnfocus' => true,
			);
		}
		$settings_json = (string) wp_json_encode( $settings );

		$mode_label = $this->mode_to_label( $mode );

		$wrapper_classes = array( 'sto-code-editor' );
		if ( $fs ) {
			$wrapper_classes[] = 'sto-code-editor--fullscreen-enabled';
		}
		if ( $is_auto ) {
			$wrapper_classes[] = 'sto-code-editor--mode-auto';
		}
		if ( $ac ) {
			$wrapper_classes[] = 'sto-code-editor--autocomplete';
		}
		?>
		<div
			class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>"
			data-sto-code-editor="1"
			data-sto-code-mode="<?php echo esc_attr( $mode ); ?>"
			data-sto-code-autocomplete="<?php echo $ac ? '1' : '0'; ?>"
			data-sto-code-height="<?php echo esc_attr( (string) $height ); ?>"
			data-sto-code-min-height="<?php echo esc_attr( (string) $min_h ); ?>"
			data-sto-code-settings="<?php echo esc_attr( $settings_json ); ?>"
			data-sto-code-textarea="<?php echo esc_attr( $ta_id ); ?>"
		>
			<div class="sto-code-editor__bar">
				<?php if ( $switch ) : ?>
					<label class="screen-reader-text" for="<?php echo esc_attr( $ta_id . '-lang' ); ?>">
						<?php esc_html_e( 'Editor language', 'simple-theme-options' ); ?>
					</label>
					<?php
					/*
					 * Native <select> upgrades to a compact **Select2** pill inside
					 * `sto-code-editor.js` (`attachLanguageSwitcher()`). We deliberately do **NOT**
					 * stamp the standard `sto-input-select` class because the global `refreshStoSelect2`
					 * loop in `main.js` applies the full-row Select2 chrome (40px height, 100% width)
					 * that's too heavy for a chrome-bar pill. The init in `sto-code-editor.js` uses a
					 * custom `selectionCssClass` + `dropdownCssClass` so the override CSS in
					 * `sto-code-editor.css` can target only this widget.
					 */
					?>
					<select
						id="<?php echo esc_attr( $ta_id . '-lang' ); ?>"
						class="sto-code-editor__lang-select"
						data-sto-code-lang-select
						aria-label="<?php esc_attr_e( 'Editor language', 'simple-theme-options' ); ?>"
					>
						<?php foreach ( self::SWITCHER_MODES as $opt ) : ?>
							<option
								value="<?php echo esc_attr( $opt ); ?>"
								data-sto-code-mime="<?php echo esc_attr( $this->mode_to_cm_mime( $opt ) ); ?>"
								data-sto-code-icon="<?php echo esc_attr( $this->mode_to_icon( $opt ) ); ?>"
								<?php selected( $mode, $opt ); ?>
							>
								<?php echo esc_html( $this->mode_to_label( $opt ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<span class="sto-code-editor__mode-pill" aria-label="<?php esc_attr_e( 'Editor mode', 'simple-theme-options' ); ?>">
						<i class="fa-light fa-code" aria-hidden="true"></i>
						<span class="sto-code-editor__mode-label"><?php echo esc_html( $mode_label ); ?></span>
					</span>
				<?php endif; ?>

				<?php /*
					Always rendered — visibility is controlled by the wrapper class
					`.sto-code-editor--mode-auto` (CSS shows the badge only while the field is
					in auto-detect mode). This way the chrome-bar switcher can flip the field
					into auto mode at runtime without re-rendering the badge in JS.
				*/ ?>
				<span
					class="sto-code-editor__auto-badge"
					data-sto-code-auto-badge
					aria-live="polite"
					title="<?php esc_attr_e( 'Language auto-detected from content', 'simple-theme-options' ); ?>"
				>
					<i class="fa-light fa-wand-magic-sparkles" aria-hidden="true"></i>
					<span><?php esc_html_e( 'Auto', 'simple-theme-options' ); ?></span>
				</span>

				<div class="sto-code-editor__bar-spacer" aria-hidden="true"></div>

				<span
					class="sto-code-editor__shortcut sto-code-editor__shortcut--wrap"
					data-sto-code-wrap-hint
					data-sto-wrap-active="<?php echo $lw ? '1' : '0'; ?>"
					title="<?php esc_attr_e( 'Toggle word wrap — Alt + Z (Option + Z on macOS)', 'simple-theme-options' ); ?>"
				>
					<kbd><?php esc_html_e( 'Alt', 'simple-theme-options' ); ?></kbd>
					<span aria-hidden="true">+</span>
					<kbd>Z</kbd>
				</span>

				<?php if ( $ac ) : ?>
					<span
						class="sto-code-editor__shortcut"
						title="<?php esc_attr_e( 'Press Ctrl + Space (or Cmd + Space) to open the autocomplete menu', 'simple-theme-options' ); ?>"
					>
						<kbd>Ctrl</kbd>
						<span aria-hidden="true">+</span>
						<kbd>Space</kbd>
					</span>
				<?php endif; ?>

				<?php if ( $fs ) : ?>
					<button
						type="button"
						class="sto-code-editor__fullscreen"
						data-sto-code-fullscreen
						aria-label="<?php esc_attr_e( 'Toggle fullscreen', 'simple-theme-options' ); ?>"
						title="<?php esc_attr_e( 'Toggle fullscreen', 'simple-theme-options' ); ?>"
					>
						<i class="fa-light fa-expand sto-code-editor__icon-expand" aria-hidden="true"></i>
						<i class="fa-light fa-compress sto-code-editor__icon-compress" aria-hidden="true"></i>
					</button>
				<?php endif; ?>
			</div>

			<?php
			$code_editor_requires_input = ! empty( $field['html_required'] );
			?>
			<textarea
				id="<?php echo esc_attr( $ta_id ); ?>"
				class="sto-code-editor__textarea"
				name="<?php echo esc_attr( $input_name ); ?>"
				rows="10"
				spellcheck="false"
				autocomplete="off"
				autocorrect="off"
				autocapitalize="off"
				wrap="off"
				style="height: <?php echo esc_attr( (string) $height ); ?>px; min-height: <?php echo esc_attr( (string) $min_h ); ?>px;"
				<?php if ( $placeh !== '' ) : ?>
					placeholder="<?php echo esc_attr( $placeh ); ?>"
				<?php endif; ?>
				<?php if ( $code_editor_requires_input ) : ?>
					required="required"
					aria-required="true"
				<?php endif; ?>
			><?php echo esc_textarea( (string) $value ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Whether this admin load should prime CodeMirror for registered fields (Theme Settings page or post metabox).
	 *
	 * @param string $hook_suffix From {@see admin_enqueue_scripts}, or empty when called from {@see enqueue_block_editor_assets}.
	 */
	private function should_prime_code_editor_for_screen( string $hook_suffix = '' ): bool {
		if ( empty( $this->fields_by_id ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== '' ) {
			if ( strpos( $page, 'theme-settings' ) !== false ) {
				return true;
			}
			$slugs = OptionsMenu::instance()->get_registered_menu_slugs();
			foreach ( $slugs as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( $slug !== '' && $page === $slug ) {
					return true;
				}
			}
		}

		$hook = is_string( $hook_suffix ) ? $hook_suffix : '';
		if ( $hook === '' && function_exists( 'get_current_screen' ) ) {
			$sc = get_current_screen();
			if ( $sc && isset( $sc->base ) && 'post' === $sc->base ) {
				$hook = isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : 'post.php';
			}
		}

		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) || ! OptionsMenu::instance()->should_show_theme_settings_metaboxes() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$pt     = ( $screen && isset( $screen->post_type ) ) ? sanitize_key( (string) $screen->post_type ) : '';
		if ( $pt === '' ) {
			return false;
		}

		foreach ( ThemeSettingsMetabox::instance()->get_roots() as $mslug => $_cfg ) {
			if ( ThemeSettingsMetabox::instance()->menu_root_allows_post_type( (string) $mslug, $pt ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calls **`wp_enqueue_code_editor()`** once per unique mode so CodeMirror loads only the addons it needs.
	 */
	private function prime_registered_code_editor_mimes(): void {
		if ( ! function_exists( 'wp_enqueue_code_editor' ) ) {
			return;
		}

		$mimes_seen       = array();
		$preload_for_auto = false;

		foreach ( $this->fields_by_id as $field ) {
			$mode     = isset( $field['mode'] ) ? (string) $field['mode'] : 'text';
			$switcher = ! array_key_exists( 'language_switcher', $field ) || (bool) $field['language_switcher'];

			if ( $mode === 'auto' || $switcher ) {
				$preload_for_auto = true;
			}

			$mime = $this->mode_to_cm_mime( $mode === 'auto' ? 'text' : $mode );
			if ( isset( $mimes_seen[ $mime ] ) ) {
				continue;
			}
			$mimes_seen[ $mime ] = true;
			wp_enqueue_code_editor( array( 'type' => $mime ) );
		}

		if ( $preload_for_auto ) {
			foreach ( self::AUTO_PRELOAD_MIMES as $mime ) {
				if ( isset( $mimes_seen[ $mime ] ) ) {
					continue;
				}
				$mimes_seen[ $mime ] = true;
				wp_enqueue_code_editor( array( 'type' => $mime ) );
			}
		}
	}

	/**
	 * Whole-screen-life enqueue helper (Theme Settings admin + post editor Theme Settings metabox).
	 *
	 * @param string $hook_suffix Current admin page file, e.g. `post.php`.
	 */
	public function maybe_prime_code_editor( $hook_suffix = '' ) {
		if ( ! $this->should_prime_code_editor_for_screen( is_string( $hook_suffix ) ? $hook_suffix : '' ) ) {
			return;
		}

		$this->prime_registered_code_editor_mimes();
	}

	/**
	 * Block editor: same priming when the edit screen hosts our meta box (no `$_GET['page']` slug).
	 */
	public function maybe_prime_code_editor_block(): void {
		if ( ! $this->should_prime_code_editor_for_screen( '' ) ) {
			return;
		}

		$this->prime_registered_code_editor_mimes();
	}

	/**
	 * @param string $field_id
	 * @param string $default
	 * @return string
	 */
	private function get_option_value( $field_id, $default ) {
		$opts  = (array) get_option( 'sto_options', array() );
		$value = array_key_exists( $field_id, $opts ) ? $opts[ $field_id ] : null;
		if ( is_string( $value ) ) {
			return $value;
		}

		return $default;
	}

	/**
	 * @param string             $field_id
	 * @param array<int, string> $bps_storage
	 * @param array<string, mixed> $field
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, array $bps_storage, array $field ) {
		$opts    = (array) get_option( 'sto_options', array() );
		$stored  = array_key_exists( $field_id, $opts ) ? $opts[ $field_id ] : null;
		$default = isset( $field['default'] ) ? (string) $field['default'] : '';
		$out     = array();

		if ( is_array( $stored ) ) {
			foreach ( $bps_storage as $bp ) {
				$bp_key      = sanitize_key( (string) $bp );
				$cell        = array_key_exists( $bp_key, $stored ) ? $stored[ $bp_key ] : '';
				$out[ $bp_key ] = is_string( $cell ) ? $cell : $default;
			}

			return $out;
		}

		// Legacy / scalar value — first breakpoint takes it, others fall back to default so the
		// per-device editor never shows blank panes after a non-responsive → responsive migration.
		$scalar = is_scalar( $stored ) ? (string) $stored : $default;
		foreach ( $bps_storage as $i => $bp ) {
			$bp_key         = sanitize_key( (string) $bp );
			$out[ $bp_key ] = ( (int) $i === 0 ) ? $scalar : $default;
		}

		return $out;
	}

	/**
	 * Normalize `register()` `mode` input → one of `ALLOWED_MODES`.
	 *
	 * @param mixed $raw
	 * @return string
	 */
	private function normalize_mode( $raw ) {
		if ( ! is_string( $raw ) ) {
			return 'text';
		}

		$k = sanitize_key( $raw );
		if ( $k === '' ) {
			return 'text';
		}

		if ( isset( self::MODE_ALIASES[ $k ] ) ) {
			$k = self::MODE_ALIASES[ $k ];
		}

		if ( ! in_array( $k, self::ALLOWED_MODES, true ) ) {
			return 'text';
		}

		return $k;
	}

	/**
	 * Build the `wp_enqueue_code_editor()` settings array. Passing **`type` =>** drives the modes
	 * + addons WordPress loads; an unknown mime degrades to a plain CodeMirror without a parser.
	 *
	 * @param string $mode
	 * @return array<string, mixed>|null
	 */
	private function mode_to_enqueue_args( $mode ) {
		$type = $this->mode_to_cm_mime( $mode );
		if ( $type === '' ) {
			return array( 'type' => 'text/plain' );
		}

		return array( 'type' => $type );
	}

	/**
	 * @param string $mode
	 * @return string CodeMirror mime (used for both enqueue + CM `mode` config).
	 */
	private function mode_to_cm_mime( $mode ) {
		switch ( $mode ) {
			case 'css':
				return 'text/css';
			case 'html':
				return 'text/html';
			case 'javascript':
				return 'application/javascript';
			case 'php':
				return 'application/x-httpd-php';
			case 'json':
				return 'application/json';
			case 'markdown':
				return 'text/x-markdown';
			case 'xml':
				return 'text/xml';
			case 'yaml':
				return 'text/x-yaml';
			case 'auto':
				// Starts as plain text; `sto-code-editor.js` `detectModeFromContent()` flips the
				// CodeMirror parser to the matching mime once the editor mounts.
				return 'text/plain';
			case 'text':
			default:
				return 'text/plain';
		}
	}

	/**
	 * Human-readable label shown in the editor chrome bar. Falls back to uppercased mode key.
	 *
	 * @param string $mode
	 * @return string
	 */
	private function mode_to_label( $mode ) {
		$map = array(
			'auto'       => __( 'Auto-detect', 'simple-theme-options' ),
			'css'        => 'CSS',
			'html'       => 'HTML',
			'javascript' => 'JavaScript',
			'php'        => 'PHP',
			'json'       => 'JSON',
			'markdown'   => 'Markdown',
			'xml'        => 'XML',
			'yaml'       => 'YAML',
			'text'       => __( 'Plain text', 'simple-theme-options' ),
		);

		return isset( $map[ $mode ] ) ? (string) $map[ $mode ] : strtoupper( $mode );
	}

	/**
	 * Map a registered mode to a Font Awesome icon class (used by the Select2 templates in
	 * `sto-code-editor.js` — `templateSelection` / `templateResult` read `data-sto-code-icon`
	 * off each `<option>` to render the lang glyph next to the label). Returns class names
	 * **without** a leading dot so the JS can drop them straight into `class="…"`.
	 */
	private function mode_to_icon( $mode ) {
		$map = array(
			'auto'       => 'fa-light fa-wand-magic-sparkles',
			'css'        => 'fa-brands fa-css3-alt',
			'html'       => 'fa-brands fa-html5',
			'javascript' => 'fa-brands fa-js',
			'php'        => 'fa-brands fa-php',
			'json'       => 'fa-light fa-brackets-curly',
			'markdown'   => 'fa-brands fa-markdown',
			'xml'        => 'fa-light fa-code',
			'yaml'       => 'fa-light fa-file-code',
			'text'       => 'fa-light fa-align-left',
		);

		return isset( $map[ $mode ] ) ? (string) $map[ $mode ] : 'fa-light fa-code';
	}
}
