<?php
namespace SimpleThemeOptions\Admin\Options\Fields\GoogleMapControl;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRenderGate;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\RenderSectionContentPriority;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSanitizePostedProxy;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\PremiumFieldGate;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Admin\Options\RequiredVisibility;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * **Location map** control (`google_map` type): Leaflet + OpenStreetMap tiles + Nominatim search/reverse geocode — **no API key**.
 * Map, search (Enter), click / drag marker, and structured address fields in **`sto_options[id]`** as a JSON object (or per-breakpoint map
 * when **`responsive`** is set). **Sync:** search fills all parts; editing address fields rebuilds the search line; editing coordinates (debounced) runs reverse geocode.
 *
 * Register with **`'type' => 'google_map'`** (or **`GoogleMapControl::register()`**). Keys: **`section_slug`**, **`id`**, **`title`**,
 * optional **`default`** (partial associative array — merged with the canonical keys below), **`description`**, conditional **`required`**,
 * **`html_required`**, **`tooltip`**, **`wrapper_class`**, optional **`responsive`** + **`device`**. Stored JSON keys: **`formatted_address`**, **`address`**
 * (street number), **`street`**, **`city`**, **`state`**, **`zip`**, **`country`**, **`lat`**, **`lng`**. Boot **`GoogleMapControl::instance()`**
 * before **`Group::register()`** when used inside groups / tabs / accordion.
 */
final class GoogleMapControl {
	use SingletonTrait;
	use FieldSingletonAccessors;
	use FieldSanitizePostedProxy;

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
	 * @return array<string, string>
	 */
	private function blank_payload() {
		return array(
			'formatted_address' => '',
			'address'           => '',
			'street'            => '',
			'city'              => '',
			'state'             => '',
			'zip'               => '',
			'country'           => '',
			'lat'               => '',
			'lng'               => '',
		);
	}

	protected function init() {
		// After AlignmentControl (19.44), before Tabs (19.45).
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), RenderSectionContentPriority::GOOGLE_MAP, 2 );
	}

	/**
	 * Whether any map fields are registered (for conditional script localization).
	 */
	public function registry_has_fields() {
		return ! empty( $this->registered_ids );
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

		$bps = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->registered_ids[ $field_id ]          = true;
		$this->fields_by_id[ $field_id ]            = $field;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, string>
	 */
	public function sanitize_payload_array( $raw ) {
		$out = $this->blank_payload();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $out as $k => $_v ) {
			if ( ! array_key_exists( $k, $raw ) ) {
				continue;
			}
			$val = $raw[ $k ];
			if ( 'lat' === $k || 'lng' === $k ) {
				$s = is_scalar( $val ) ? trim( (string) $val ) : '';
				if ( $s !== '' && is_numeric( $s ) ) {
					$out[ $k ] = (string) $s;
				}
				continue;
			}
			$out[ $k ] = sanitize_text_field( is_scalar( $val ) ? (string) $val : '' );
		}

		return $out;
	}

	/**
	 * @param string $json
	 * @return array<string, string>
	 */
	private function decode_payload_string( $json ) {
		$arr = json_decode( (string) $json, true );

		return $this->sanitize_payload_array( is_array( $arr ) ? $arr : array() );
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array<string, string>
	 */
	private function default_payload_for_field( array $field ) {
		$base = $this->blank_payload();
		if ( isset( $field['default'] ) && is_array( $field['default'] ) ) {
			return $this->sanitize_payload_array( array_merge( $base, $field['default'] ) );
		}

		return $base;
	}

	/**
	 * @param array<string, string> $payload
	 */
	private function coords_are_set( array $payload ) {
		$lat = isset( $payload['lat'] ) ? trim( (string) $payload['lat'] ) : '';
		$lng = isset( $payload['lng'] ) ? trim( (string) $payload['lng'] ) : '';
		if ( $lat === '' || $lng === '' ) {
			return false;
		}
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return false;
		}
		$la = (float) $lat;
		$lo = (float) $lng;

		return $la >= -90.0 && $la <= 90.0 && $lo >= -180.0 && $lo <= 180.0;
	}

	/**
	 * @param string               $field_id
	 * @param string|array<mixed> $raw Posted JSON string or per-breakpoint map.
	 * @return string|array<string, string>
	 */
	public function registry_sanitize_posted_value( $field_id, $raw ) {
		$field_id = sanitize_key( (string) $field_id );
		$field    = $field_id ? ( $this->fields_by_id[ $field_id ] ?? null ) : null;
		if ( ! is_array( $field ) ) {
			return wp_json_encode( $this->blank_payload() );
		}
		$bps = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;

		if ( ! empty( $bps ) && is_array( $raw ) ) {
			$out = array();
			foreach ( $bps as $bp ) {
				$bp         = sanitize_key( (string) $bp );
				$cell       = isset( $raw[ $bp ] ) ? $raw[ $bp ] : '';
				$out[ $bp ] = $this->sanitize_cell( $cell );
			}

			return $out;
		}

		return $this->sanitize_cell( $raw );
	}

	/**
	 * @param mixed $cell
	 * @return string JSON
	 */
	private function sanitize_cell( $cell ) {
		if ( is_string( $cell ) ) {
			$cell = trim( $cell );
			if ( $cell === '' ) {
				return wp_json_encode( $this->blank_payload() );
			}
			$decoded = json_decode( $cell, true );

			return wp_json_encode( $this->sanitize_payload_array( is_array( $decoded ) ? $decoded : array() ) );
		}
		if ( is_array( $cell ) ) {
			return wp_json_encode( $this->sanitize_payload_array( $cell ) );
		}

		return wp_json_encode( $this->blank_payload() );
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
		$defaults      = $this->default_payload_for_field( $field );

		$is_inner    = ( 'group_inner' === $context );
		$group_label = $title !== '' ? $title : $field_id;

		$row_classes = array( 'sto-field-row', 'sto-field-row-google-map' );
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

		$i18n = array(
			'searchPlaceholder' => __( 'Search address…', 'simple-theme-options' ),
			'searchHint'        => __( 'Press Enter to search. The line below and the address fields stay in sync with the map.', 'simple-theme-options' ),
			'geocodeError'      => __( 'Could not look up that place. Try again in a moment.', 'simple-theme-options' ),
			'address'           => __( 'Address', 'simple-theme-options' ),
			'street'            => __( 'Street', 'simple-theme-options' ),
			'city'              => __( 'City', 'simple-theme-options' ),
			'state'             => __( 'State', 'simple-theme-options' ),
			'zip'               => __( 'ZIP', 'simple-theme-options' ),
			'country'           => __( 'Country', 'simple-theme-options' ),
			'lat'               => __( 'Latitude', 'simple-theme-options' ),
			'lng'               => __( 'Longitude', 'simple-theme-options' ),
		);

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

			<?php if ( PremiumFieldGate::render_controls_or_locked_placeholder( $title, 'google_map' ) ) : ?>
			<?php elseif ( $tabs_pane_bp !== '' && $bps_storage ) : ?>
				<?php
				$value_map = $this->get_value_map( $field_id, $defaults, $bps_storage );
				$json      = isset( $value_map[ $tabs_pane_bp ] ) ? (string) $value_map[ $tabs_pane_bp ] : wp_json_encode( $defaults );
				$input_name = 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$id_suffix  = $field_id . '_' . $tabs_pane_bp;
				$this->render_google_map_widget( $id_suffix, $input_name, $json, $group_label . ' — ' . strtoupper( $tabs_pane_bp ), $i18n );
				?>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					$value_map = $this->get_value_map( $field_id, $defaults, $bps_storage );
					foreach ( $bps_storage as $i => $bp ) :
						$bp         = sanitize_key( (string) $bp );
						$visible    = ( 0 === (int) $i );
						$json       = isset( $value_map[ $bp ] ) ? (string) $value_map[ $bp ] : wp_json_encode( $defaults );
						$input_name = 'sto_options[' . $field_id . '][' . $bp . ']';
						$id_suffix  = $field_id . '_' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						$this->render_google_map_widget( $id_suffix, $input_name, $json, $group_label . ' — ' . strtoupper( $bp ), $i18n );
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
				<?php
				$json       = $this->get_merged_json( $field_id, $defaults );
				$input_name = 'sto_options[' . $field_id . ']';
				$this->render_google_map_widget( $field_id, $input_name, $json, $group_label, $i18n );
				?>
			<?php endif; ?>

			<?php if ( $description && ! PremiumFieldGate::is_locked() ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string               $id_suffix
	 * @param string               $input_name
	 * @param string               $json
	 * @param string               $group_label
	 * @param array<string, string> $i18n
	 */
	private function render_google_map_widget( $id_suffix, $input_name, $json, $group_label, array $i18n ) {
		$payload  = $this->decode_payload_string( $json );
		$json_out = wp_json_encode( $payload );

		$search_id = 'sto_gmap_search_' . $id_suffix;
		?>
		<div
			class="sto-gmap"
			data-sto-gmap="1"
			data-sto-gmap-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>"
			aria-label="<?php echo esc_attr( $group_label ); ?>"
		>
			<div class="sto-gmap__chrome">
				<div class="sto-gmap__head">
					<label class="screen-reader-text" for="<?php echo esc_attr( $search_id ); ?>"><?php echo esc_html( $i18n['searchPlaceholder'] ); ?></label>
					<input
						type="search"
						id="<?php echo esc_attr( $search_id ); ?>"
						class="sto-gmap__search sto-gmap__input"
						data-sto-gmap-search
						placeholder="<?php echo esc_attr( $i18n['searchPlaceholder'] ); ?>"
						value="<?php echo esc_attr( $payload['formatted_address'] ); ?>"
						autocomplete="off"
					/>
					<p class="sto-gmap__hint"><?php echo esc_html( $i18n['searchHint'] ); ?></p>
					<p class="sto-gmap__msg" data-sto-gmap-msg hidden role="status" aria-live="polite"></p>
				</div>
				<div class="sto-gmap__map-wrap">
					<div class="sto-gmap__canvas" data-sto-gmap-canvas role="presentation"></div>
				</div>
				<div class="sto-gmap__fields">
					<div class="sto-gmap__grid">
						<?php $this->render_text_cell( $id_suffix, 'address', $i18n['address'], $payload['address'] ); ?>
						<?php $this->render_text_cell( $id_suffix, 'street', $i18n['street'], $payload['street'] ); ?>
						<?php $this->render_text_cell( $id_suffix, 'city', $i18n['city'], $payload['city'] ); ?>
						<?php $this->render_text_cell( $id_suffix, 'state', $i18n['state'], $payload['state'] ); ?>
						<?php $this->render_text_cell( $id_suffix, 'zip', $i18n['zip'], $payload['zip'] ); ?>
						<?php $this->render_text_cell( $id_suffix, 'country', $i18n['country'], $payload['country'] ); ?>
						<?php $this->render_text_cell( $id_suffix, 'lat', $i18n['lat'], $payload['lat'] ); ?>
						<?php $this->render_text_cell( $id_suffix, 'lng', $i18n['lng'], $payload['lng'] ); ?>
					</div>
				</div>
			</div>
			<input type="hidden" class="sto-gmap-value" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $json_out ); ?>" autocomplete="off" />
		</div>
		<?php
	}

	/**
	 * @param string $id_suffix
	 * @param string $key
	 * @param string $label
	 * @param string $value
	 */
	private function render_text_cell( $id_suffix, $key, $label, $value ) {
		$fid = 'sto_gmap_' . $id_suffix . '_' . $key;
		?>
		<div class="sto-gmap__cell">
			<label class="sto-gmap__label" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $label ); ?></label>
			<input
				type="text"
				id="<?php echo esc_attr( $fid ); ?>"
				class="sto-gmap__input"
				data-sto-gmap-field="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				autocomplete="off"
			/>
		</div>
		<?php
	}

	/**
	 * @param string              $field_id
	 * @param array<string, string> $default_payload
	 * @param array<int, string>  $breakpoints
	 * @return array<string, string>
	 */
	private function get_value_map( $field_id, array $default_payload, array $breakpoints ) {
		$default_json = wp_json_encode( $default_payload );
		$saved        = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			$map = ResponsiveConfig::coerce_map( null, $breakpoints, $default_json );
		} else {
			$stored = $saved[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$map = ResponsiveConfig::coerce_map( $stored, $breakpoints, $default_json );
			} else {
				$scalar = is_string( $stored ) ? $this->sanitize_cell( $stored ) : $default_json;
				$map    = ResponsiveConfig::coerce_map( null, $breakpoints, $scalar );
			}
		}
		foreach ( $map as $bp => $json_str ) {
			$decoded = $this->decode_payload_string( (string) $json_str );
			$map[ $bp ] = wp_json_encode( $this->sanitize_payload_array( array_merge( $default_payload, $decoded ) ) );
		}

		return $map;
	}

	/**
	 * @param string              $field_id
	 * @param array<string, string> $default_payload
	 * @return string JSON
	 */
	private function get_merged_json( $field_id, array $default_payload ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! isset( $saved[ $field_id ] ) ) {
			return wp_json_encode( $default_payload );
		}
		$stored = $saved[ $field_id ];
		$slice  = '';
		if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
			$ev = ResponsiveConfig::value_for_required_eval( $stored );
			$slice = is_string( $ev ) ? $ev : '';
		} elseif ( is_string( $stored ) ) {
			$slice = $stored;
		}
		$merged = array_merge( $default_payload, $this->decode_payload_string( $slice ) );

		return wp_json_encode( $this->sanitize_payload_array( $merged ) );
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
				__( '“%s” must be filled in before this section can be saved.', 'simple-theme-options' ),
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

			return ! $this->coords_are_set( $this->decode_payload_string( is_string( $slice ) ? $slice : '' ) );
		}

		return ! $this->coords_are_set( $this->decode_payload_string( is_string( $raw ) ? $raw : ( is_scalar( $raw ) ? (string) $raw : '' ) ) );
	}

	/**
	 * Decoded location for a registered field (theme use).
	 *
	 * @param string      $field_id       Option key.
	 * @param string|null $json_or_scalar When non-null, decode this JSON instead of reading options.
	 * @return array<string, string>
	 */
	public function get_map_payload_for_field( $field_id, $json_or_scalar = null ) {
		$field_id = sanitize_key( (string) $field_id );
		if ( $field_id === '' || ! isset( $this->fields_by_id[ $field_id ] ) ) {
			return $this->blank_payload();
		}
		$field = $this->fields_by_id[ $field_id ];
		$raw   = '';
		if ( null !== $json_or_scalar && is_string( $json_or_scalar ) ) {
			$raw = $json_or_scalar;
		} else {
			$opts = function_exists( 'sto_get_options' ) ? sto_get_options() : array();
			if ( ! is_array( $opts ) || ! array_key_exists( $field_id, $opts ) ) {
				return $this->default_payload_for_field( $field );
			}
			$stored = $opts[ $field_id ];
			if ( is_array( $stored ) && ResponsiveConfig::is_breakpoint_value_map( $stored ) ) {
				$slice = ResponsiveConfig::value_for_required_eval( $stored );
				$raw   = is_string( $slice ) ? $slice : '';
			} else {
				$raw = is_string( $stored ) ? $stored : '';
			}
		}

		$merged = $this->decode_payload_string( $raw );

		return $this->sanitize_payload_array( array_merge( $this->default_payload_for_field( $field ), $merged ) );
	}
}
