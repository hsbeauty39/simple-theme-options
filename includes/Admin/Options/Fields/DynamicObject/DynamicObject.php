<?php
namespace SimpleThemeOptions\Admin\Options\Fields\DynamicObject;

use SimpleThemeOptions\Admin\Options\Fields\Common\FieldRegistrationDeferral;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldSingletonAccessors;
use SimpleThemeOptions\Admin\Options\Fields\Common\FieldTitle;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveConfig;
use SimpleThemeOptions\Admin\Options\Fields\Common\ResponsiveControl;
use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

final class DynamicObject {
	use SingletonTrait;
	use FieldSingletonAccessors;

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $fields_by_section = array();

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private $fields_by_id = array();

	protected function init() {
		add_action( 'sto_render_section_content', array( $this, 'render_section_fields' ), 18, 2 );
		add_action( 'wp_ajax_sto_dynamic_object_search', array( $this, 'ajax_search' ) );
	}

	/**
	 * CPT-backed select (Select2 AJAX). Stored value: single = post ID string or empty;
	 * multiple = array of post ID strings (serialized in `sto_options`).
	 *
	 * Keys: section_slug, id, post_type (required), multiple? (bool), max? (max selections, 0 = unlimited),
	 * title?, description?, default? (string | string[] | comma list for multiple), placeholder?,
	 * limit? (default 10, max posts per AJAX page), search_min_length? (default 3; AJAX + Select2 min chars),
	 * post_status?, class?, wrapper_class?, required?, tooltip?, group?, optional **responsive** (`true` or non-empty array), optional **`device`** => breakpoint slug list.
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
		$post_type    = isset( $field['post_type'] ) ? sanitize_key( (string) $field['post_type'] ) : '';

		if ( ! $section_slug || ! $field_id || $post_type === '' ) {
			return;
		}

		if ( ! $this->is_allowed_post_type( $post_type ) ) {
			return;
		}

		$limit = isset( $field['limit'] ) ? (int) $field['limit'] : 10;
		if ( $limit < 1 ) {
			$limit = 10;
		}
		if ( $limit > 50 ) {
			$limit = 50;
		}

		$status = isset( $field['post_status'] ) ? (string) $field['post_status'] : 'publish';
		if ( $status !== 'any' && $status !== 'publish' && $status !== 'draft' && $status !== 'private' ) {
			$status = 'publish';
		}

		$field['section_slug']  = $section_slug;
		$field['id']            = $field_id;
		$field['post_type']     = $post_type;
		$field['limit']         = $limit;
		$field['post_status']   = $status;
		$field['multiple']      = ! empty( $field['multiple'] );
		$max_sel                = isset( $field['max'] ) ? (int) $field['max'] : 0;
		if ( $max_sel < 0 ) {
			$max_sel = 0;
		}
		if ( $max_sel > 100 ) {
			$max_sel = 100;
		}
		$field['max'] = $max_sel;

		$min_search = isset( $field['search_min_length'] ) ? (int) $field['search_min_length'] : 3;
		if ( $min_search < 1 ) {
			$min_search = 1;
		}
		if ( $min_search > 20 ) {
			$min_search = 20;
		}
		$field['search_min_length'] = $min_search;

		if ( $field['multiple'] ) {
			$def = isset( $field['default'] ) ? $field['default'] : array();
			if ( is_string( $def ) ) {
				$def = trim( $def ) === '' ? array() : array_map( 'trim', explode( ',', $def ) );
			}
			if ( ! is_array( $def ) ) {
				$def = array();
			}
			$field['default'] = array_values(
				array_filter(
					array_map(
						static function ( $v ) {
							return (string) ( is_scalar( $v ) ? $v : '' );
						},
						$def
					)
				)
			);
		} else {
			$field['default'] = isset( $field['default'] ) ? (string) $field['default'] : '';
		}

		$field['title']         = isset( $field['title'] ) ? (string) $field['title'] : '';
		$field['description']   = isset( $field['description'] ) ? (string) $field['description'] : '';
		if ( isset( $field['placeholder'] ) && (string) $field['placeholder'] !== '' ) {
			$field['placeholder'] = (string) $field['placeholder'];
		} else {
			$field['placeholder'] = sprintf(
				/* translators: %d: minimum number of characters before AJAX search runs */
				__( 'Type at least %d characters to search…', 'simple-theme-options' ),
				$min_search
			);
		}
		$field['class']         = isset( $field['class'] ) ? (string) $field['class'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? (string) $field['wrapper_class'] : '';
		$field['required']     = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$field['group']         = isset( $field['group'] ) ? sanitize_key( (string) $field['group'] ) : '';
		$bps                    = ResponsiveConfig::breakpoints_for_field( $field );
		$field['responsive_breakpoints'] = $bps;

		if ( ! isset( $this->fields_by_section[ $section_slug ] ) ) {
			$this->fields_by_section[ $section_slug ] = array();
		}

		$this->fields_by_section[ $section_slug ][] = $field;
		$this->fields_by_id[ $field_id ]             = $field;
	}

	public function registry_is_registered_field_id( $field_id ) {
		$field_id = sanitize_key( (string) $field_id );

		return $field_id !== '' && isset( $this->fields_by_id[ $field_id ] );
	}

	/**
	 * Field ids registered for a leaf section (standalone + grouped). Used by the save handler
	 * to scope `sto_options` keys to the section currently being persisted.
	 *
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
	 * When a `<select multiple>` has no selection, PHP omits the key from POST. Merge empty arrays
	 * so `update_option` does not drop the key (and so "clear all" persists).
	 *
	 * @param array<string, mixed>        $posted
	 * @param array<string, mixed>        $sanitized
	 * @param array<string, true>|null $only_field_ids_map When set (field id => true), only these ids are considered (partial section save).
	 */
	public function merge_missing_multiple_dynamic_fields( $posted, array &$sanitized, ?array $only_field_ids_map = null ) {
		if ( ! is_array( $posted ) ) {
			$posted = array();
		}
		if ( is_array( $only_field_ids_map ) && $only_field_ids_map === array() ) {
			return;
		}
		foreach ( $this->fields_by_id as $fid => $cfg ) {
			if ( $only_field_ids_map !== null && ! isset( $only_field_ids_map[ $fid ] ) ) {
				continue;
			}
			if ( empty( $cfg['multiple'] ) ) {
				continue;
			}
			$bps = isset( $cfg['responsive_breakpoints'] ) && is_array( $cfg['responsive_breakpoints'] ) ? $cfg['responsive_breakpoints'] : null;
			if ( ! empty( $bps ) ) {
				$posted_f = isset( $posted[ $fid ] ) && is_array( $posted[ $fid ] ) ? $posted[ $fid ] : array();
				$san_f    = isset( $sanitized[ $fid ] ) && is_array( $sanitized[ $fid ] ) ? $sanitized[ $fid ] : array();
				foreach ( $bps as $bp ) {
					$bp = sanitize_key( (string) $bp );
					if ( array_key_exists( $bp, $san_f ) ) {
						continue;
					}
					if ( array_key_exists( $bp, $posted_f ) ) {
						continue;
					}
					if ( ! isset( $sanitized[ $fid ] ) || ! is_array( $sanitized[ $fid ] ) ) {
						$sanitized[ $fid ] = array();
					}
					$sanitized[ $fid ][ $bp ] = $this->sanitize_multiple_for_field( $cfg, array() );
				}
				continue;
			}
			if ( array_key_exists( $fid, $sanitized ) ) {
				continue;
			}
			if ( array_key_exists( $fid, $posted ) ) {
				continue;
			}
			$sanitized[ $fid ] = $this->sanitize_for_field( $fid, array() );
		}
	}

	/**
	 * @param string $raw
	 * @return string Post ID as string or empty
	 */
	public function sanitize_stored_value( $raw ) {
		$v = is_string( $raw ) ? trim( $raw ) : '';
		if ( $v === '' ) {
			return '';
		}
		$id = absint( $v );
		if ( $id < 1 ) {
			return '';
		}
		$post = get_post( $id );
		if ( ! $post || $post->post_status === 'trash' ) {
			return '';
		}

		return (string) $id;
	}

	/**
	 * Validate saved post(s) belong to the field's post type.
	 *
	 * @param string                $field_id
	 * @param string|array<mixed>|null $value Posted raw (string, array of ids, per-breakpoint map, or null if omitted)
	 * @return string|array<int, string>|array<string, string|array<int, string>>
	 */
	public function sanitize_for_field( $field_id, $value ) {
		$field_id = sanitize_key( (string) $field_id );
		if ( $field_id === '' || ! isset( $this->fields_by_id[ $field_id ] ) ) {
			return is_array( $value ) ? array() : '';
		}
		$config = $this->fields_by_id[ $field_id ];
		$bps     = isset( $config['responsive_breakpoints'] ) && is_array( $config['responsive_breakpoints'] ) ? $config['responsive_breakpoints'] : null;

		if ( ! empty( $bps ) && ! is_array( $value ) && null !== $value && '' !== $value ) {
			$fill = array();
			foreach ( $bps as $bp ) {
				$fill[ sanitize_key( (string) $bp ) ] = $value;
			}
			$value = $fill;
		}

		if ( ! empty( $bps ) && is_array( $value ) && $value !== array() && ! $this->is_breakpoint_shaped_post( $value, $bps ) && ! empty( $config['multiple'] ) ) {
			$keys = array_keys( $value );
			$n    = count( $value );
			if ( $n > 0 && $keys === range( 0, $n - 1 ) ) {
				$cell = $this->sanitize_multiple_for_field( $config, $value );
				$out  = array();
				foreach ( $bps as $bp ) {
					$out[ sanitize_key( (string) $bp ) ] = $cell;
				}

				return $out;
			}
		}

		if ( ! empty( $bps ) && ( ! is_array( $value ) || $value === array() || $this->is_breakpoint_shaped_post( $value, $bps ) ) ) {
			$posted = is_array( $value ) ? $value : array();
			$out    = array();
			foreach ( $bps as $bp ) {
				$bp        = sanitize_key( (string) $bp );
				$cell      = array_key_exists( $bp, $posted ) ? $posted[ $bp ] : null;
				$out[ $bp ] = ! empty( $config['multiple'] )
					? $this->sanitize_multiple_for_field( $config, $cell )
					: $this->sanitize_single_id_for_config( $config, $cell );
			}

			return $out;
		}

		$multiple = ! empty( $config['multiple'] );

		if ( $multiple ) {
			return $this->sanitize_multiple_for_field( $config, $value );
		}

		return $this->sanitize_single_id_for_config( $config, $value );
	}

	/**
	 * @param array<string, mixed> $config
	 * @param mixed                $value
	 */
	private function sanitize_single_id_for_config( array $config, $value ) {
		$scalar = is_array( $value ) ? (string) reset( $value ) : ( is_string( $value ) ? $value : '' );
		$scalar = $this->sanitize_stored_value( $scalar );
		if ( $scalar === '' ) {
			return '';
		}
		$expected_type = isset( $config['post_type'] )
			? sanitize_key( (string) $config['post_type'] )
			: '';
		$post = get_post( (int) $scalar );
		if ( ! $post || $post->post_type !== $expected_type ) {
			return '';
		}

		return $scalar;
	}

	/**
	 * @param array<string, mixed> $value
	 * @param array<int, string>   $bps
	 */
	private function is_breakpoint_shaped_post( $value, array $bps ) {
		if ( ! is_array( $value ) || $value === array() ) {
			return true;
		}
		$allowed = array_flip( $bps );
		foreach ( array_keys( $value ) as $k ) {
			if ( ! isset( $allowed[ sanitize_key( (string) $k ) ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed>    $config
	 * @param string|array<mixed>|null $value
	 * @return array<int, string>
	 */
	private function sanitize_multiple_for_field( array $config, $value ) {
		$expected_type = isset( $config['post_type'] )
			? sanitize_key( (string) $config['post_type'] )
			: '';
		$max = isset( $config['max'] ) ? (int) $config['max'] : 0;

		$ids = $this->parse_incoming_id_list( $value );
		$out = array();

		foreach ( $ids as $id_str ) {
			$one = $this->sanitize_stored_value( (string) $id_str );
			if ( $one === '' ) {
				continue;
			}
			$post = get_post( (int) $one );
			if ( ! $post || $post->post_type !== $expected_type ) {
				continue;
			}
			$out[] = $one;
			if ( $max > 0 && count( $out ) >= $max ) {
				break;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string|array<mixed>|null $value
	 * @return array<int, string>
	 */
	private function parse_incoming_id_list( $value ) {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_array( $value ) ) {
			$list = array();
			foreach ( $value as $v ) {
				if ( is_scalar( $v ) && (string) $v !== '' ) {
					$list[] = (string) $v;
				}
			}

			return $list;
		}
		if ( ! is_string( $value ) ) {
			return array();
		}
		$value = trim( $value );
		if ( $value === '' ) {
			return array();
		}
		if ( strpos( $value, ',' ) !== false ) {
			return array_map( 'trim', explode( ',', $value ) );
		}

		return array( $value );
	}

	/**
	 * Allow normal CPTs / public types; block core internal types.
	 *
	 * @param string $post_type
	 */
	private function is_allowed_post_type( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( $post_type === '' || ! post_type_exists( $post_type ) ) {
			return false;
		}

		$blocked = array(
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
		);

		if ( in_array( $post_type, $blocked, true ) ) {
			return false;
		}

		$obj = get_post_type_object( $post_type );
		if ( ! $obj ) {
			return false;
		}

		$allowed = (bool) $obj->show_ui || (bool) $obj->public;

		/**
		 * Whether a post type may be used in Dynamic Object fields.
		 *
		 * @param bool   $allowed
		 * @param string $post_type
		 */
		return (bool) apply_filters( 'sto_dynamic_object_is_post_type_allowed', $allowed, $post_type );
	}

	public function ajax_search() {
		check_ajax_referer( 'sto_dynamic_object_search', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$field_id  = isset( $_REQUEST['field_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['field_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post_type = isset( $_REQUEST['post_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$search = isset( $_REQUEST['search'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page = isset( $_REQUEST['page'] ) ? max( 1, (int) $_REQUEST['page'] ) : 1;

		if ( ! $field_id || ! isset( $this->fields_by_id[ $field_id ] ) ) {
			wp_send_json_error( array( 'message' => 'Unknown field' ), 400 );
		}

		$config = $this->fields_by_id[ $field_id ];
		$cfg_pt = isset( $config['post_type'] ) ? sanitize_key( (string) $config['post_type'] ) : '';
		if ( $cfg_pt === '' || $post_type !== $cfg_pt ) {
			wp_send_json_error( array( 'message' => 'Invalid post type' ), 400 );
		}

		$per_page = isset( $config['limit'] ) ? (int) $config['limit'] : 10;
		if ( $per_page < 1 ) {
			$per_page = 10;
		}
		if ( $per_page > 50 ) {
			$per_page = 50;
		}

		$status = isset( $config['post_status'] ) ? (string) $config['post_status'] : 'publish';
		if ( ! in_array( $status, array( 'publish', 'draft', 'private', 'any' ), true ) ) {
			$status = 'publish';
		}

		$post_status = $status === 'any' ? 'any' : $status;

		$min_len = isset( $config['search_min_length'] ) ? (int) $config['search_min_length'] : 3;
		if ( $min_len < 1 ) {
			$min_len = 1;
		}
		if ( $min_len > 20 ) {
			$min_len = 20;
		}

		$search_trim = trim( $search );
		$search_len  = function_exists( 'mb_strlen' )
			? (int) mb_strlen( $search_trim, 'UTF-8' )
			: (int) strlen( $search_trim );

		if ( $search_len < $min_len ) {
			wp_send_json_success(
				array(
					'results' => array(),
					'more'    => false,
				)
			);
		}

		$args = array(
			'post_type'           => $cfg_pt,
			'post_status'         => $post_status,
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
			's'                   => $search_trim,
		);

		/**
		 * @param array<string, mixed> $args
		 * @param string               $field_id
		 */
		$args = apply_filters( 'sto_dynamic_object_query_args', $args, $field_id );

		$query = new \WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $p ) {
			if ( ! $p instanceof \WP_Post ) {
				continue;
			}
			$results[] = array(
				'id'   => (string) $p->ID,
				'text' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			);
		}

		$more = ( $page * $per_page ) < (int) $query->found_posts;

		wp_send_json_success(
			array(
				'results' => $results,
				'more'    => $more,
			)
		);
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
	 * @param array<string, mixed> $field
	 * @param 'default'|'group_inner' $context
	 */
	public function render_field_markup( $field, $context = 'default' ) {
		if ( ! is_array( $field ) ) {
			return;
		}

		$field_id      = $field['id'];
		$post_type     = isset( $field['post_type'] ) ? sanitize_key( (string) $field['post_type'] ) : 'post';
		$limit           = isset( $field['limit'] ) ? (int) $field['limit'] : 10;
		$search_min_len  = isset( $field['search_min_length'] ) ? (int) $field['search_min_length'] : 3;
		$status          = isset( $field['post_status'] ) ? (string) $field['post_status'] : 'publish';
		$title         = $field['title'];
		$description   = $field['description'];
		$placeholder   = $field['placeholder'];
		$default_value = $field['default'];
		$wrapper_class = $field['wrapper_class'];
		$select_class  = $field['class'];
		$required      = isset( $field['required'] ) && is_array( $field['required'] ) ? $field['required'] : array();
		$required_json = ! empty( $required ) ? wp_json_encode( $required ) : '';
		$tooltip       = FieldTitle::get_tooltip_config( $field );
		$multiple      = ! empty( $field['multiple'] );
		$max_sel       = isset( $field['max'] ) ? (int) $field['max'] : 0;
		$bps_storage   = isset( $field['responsive_breakpoints'] ) && is_array( $field['responsive_breakpoints'] ) ? $field['responsive_breakpoints'] : null;
		$tabs_pane_bp  = ResponsiveConfig::parent_responsive_pane_bp( $field );
		$breakpoints   = ( $tabs_pane_bp !== '' && $bps_storage ) ? null : $bps_storage;

		$select_name = $multiple
			? 'sto_options[' . $field_id . '][]'
			: 'sto_options[' . $field_id . ']';
		$is_inner    = ( 'group_inner' === $context );

		$row_classes = array( 'sto-field-row', 'sto-field-row-select', 'sto-field-row-dynamic-object' );
		if ( $multiple ) {
			$row_classes[] = 'sto-field-row-dynamic-object--multiple';
		}
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

		if ( ! $bps_storage ) {
			if ( $multiple ) {
				$current_ids = $this->get_stored_id_list_for_render( $field_id, $post_type, $status, $default_value );
			} else {
				$current_ids   = array();
				$current_value = $this->get_stored_single_for_render( $field_id, $post_type, $status, is_string( $default_value ) ? $default_value : '' );
				$current_int   = absint( $current_value );
				$has_current   = $current_int > 0 && $this->post_matches_field( $current_int, $post_type, $status );
			}
		} elseif ( $tabs_pane_bp !== '' ) {
			if ( $multiple ) {
				$current_ids = $this->get_stored_id_list_for_render( $field_id, $post_type, $status, $default_value, $tabs_pane_bp );
			} else {
				$current_ids   = array();
				$current_value = $this->get_stored_single_for_render( $field_id, $post_type, $status, is_string( $default_value ) ? $default_value : '', $tabs_pane_bp );
				$current_int   = absint( $current_value );
				$has_current   = $current_int > 0 && $this->post_matches_field( $current_int, $post_type, $status );
			}
		}

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

			<?php if ( $tabs_pane_bp !== '' && $bps_storage ) : ?>
				<?php
				$select_name_bp = $multiple
					? 'sto_options[' . $field_id . '][' . $tabs_pane_bp . "][]"
					: 'sto_options[' . $field_id . '][' . $tabs_pane_bp . ']';
				$select_id_bp = $field_id . '-' . $tabs_pane_bp;
				?>
				<div class="sto-select-wrap">
					<select
						id="<?php echo esc_attr( $select_id_bp ); ?>"
						name="<?php echo esc_attr( $select_name_bp ); ?>"
						class="sto-input-select sto-dynamic-object <?php echo esc_attr( $select_class ); ?>"
						<?php echo $multiple ? ' multiple="multiple"' : ''; ?>
						data-sto-dynamic-object="1"
						data-field-id="<?php echo esc_attr( $field_id ); ?>"
						data-post-type="<?php echo esc_attr( $post_type ); ?>"
						data-ajax-page-size="<?php echo (int) $limit; ?>"
						data-search-min="<?php echo (int) $search_min_len; ?>"
						data-multiple="<?php echo $multiple ? '1' : '0'; ?>"
						data-max-selections="<?php echo (int) $max_sel; ?>"
						data-placeholder-text="<?php echo esc_attr( $placeholder ); ?>"
					>
						<?php if ( $multiple ) : ?>
							<option></option>
							<?php foreach ( $current_ids as $cid ) : ?>
								<?php
								$cid = (string) absint( $cid );
								if ( $cid === '0' ) {
									continue;
								}
								if ( ! $this->post_matches_field( (int) $cid, $post_type, $status ) ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $cid ); ?>" selected="selected">
									<?php echo esc_html( get_the_title( (int) $cid ) ); ?>
								</option>
							<?php endforeach; ?>
						<?php else : ?>
							<option value=""><?php echo esc_html( $placeholder ); ?></option>
							<?php if ( $has_current ) : ?>
								<option value="<?php echo esc_attr( (string) $current_int ); ?>" selected="selected">
									<?php echo esc_html( get_the_title( $current_int ) ); ?>
								</option>
							<?php endif; ?>
						<?php endif; ?>
					</select>
				</div>
			<?php elseif ( ! empty( $bps_storage ) ) : ?>
				<div class="sto-responsive">
					<?php ResponsiveControl::render_panes_open(); ?>
					<?php
					foreach ( $bps_storage as $i => $bp ) :
						$bp      = sanitize_key( (string) $bp );
						$visible = ( 0 === (int) $i );
						if ( $multiple ) {
							$current_ids = $this->get_stored_id_list_for_render( $field_id, $post_type, $status, $default_value, $bp );
						} else {
							$current_ids   = array();
							$current_value = $this->get_stored_single_for_render( $field_id, $post_type, $status, is_string( $default_value ) ? $default_value : '', $bp );
							$current_int   = absint( $current_value );
							$has_current   = $current_int > 0 && $this->post_matches_field( $current_int, $post_type, $status );
						}
						$select_name_bp = $multiple
							? 'sto_options[' . $field_id . '][' . $bp . "][]"
							: 'sto_options[' . $field_id . '][' . $bp . ']';
						$select_id_bp = $field_id . '-' . $bp;
						ResponsiveControl::render_pane_start( $bp, $visible );
						?>
						<div class="sto-select-wrap">
							<select
								id="<?php echo esc_attr( $select_id_bp ); ?>"
								name="<?php echo esc_attr( $select_name_bp ); ?>"
								class="sto-input-select sto-dynamic-object <?php echo esc_attr( $select_class ); ?>"
								<?php echo $multiple ? ' multiple="multiple"' : ''; ?>
								data-sto-dynamic-object="1"
								data-field-id="<?php echo esc_attr( $field_id ); ?>"
								data-post-type="<?php echo esc_attr( $post_type ); ?>"
								data-ajax-page-size="<?php echo (int) $limit; ?>"
								data-search-min="<?php echo (int) $search_min_len; ?>"
								data-multiple="<?php echo $multiple ? '1' : '0'; ?>"
								data-max-selections="<?php echo (int) $max_sel; ?>"
								data-placeholder-text="<?php echo esc_attr( $placeholder ); ?>"
							>
								<?php if ( $multiple ) : ?>
									<option></option>
									<?php foreach ( $current_ids as $cid ) : ?>
										<?php
										$cid = (string) absint( $cid );
										if ( $cid === '0' ) {
											continue;
										}
										if ( ! $this->post_matches_field( (int) $cid, $post_type, $status ) ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( $cid ); ?>" selected="selected">
											<?php echo esc_html( get_the_title( (int) $cid ) ); ?>
										</option>
									<?php endforeach; ?>
								<?php else : ?>
									<option value=""><?php echo esc_html( $placeholder ); ?></option>
									<?php if ( $has_current ) : ?>
										<option value="<?php echo esc_attr( (string) $current_int ); ?>" selected="selected">
											<?php echo esc_html( get_the_title( $current_int ) ); ?>
										</option>
									<?php endif; ?>
								<?php endif; ?>
							</select>
						</div>
						<?php
						ResponsiveControl::render_pane_end();
					endforeach;
					ResponsiveControl::render_panes_close();
					?>
				</div>
			<?php else : ?>
			<div class="sto-select-wrap">
				<select
					id="<?php echo esc_attr( $field_id ); ?>"
					name="<?php echo esc_attr( $select_name ); ?>"
					class="sto-input-select sto-dynamic-object <?php echo esc_attr( $select_class ); ?>"
					<?php echo $multiple ? ' multiple="multiple"' : ''; ?>
					data-sto-dynamic-object="1"
					data-field-id="<?php echo esc_attr( $field_id ); ?>"
					data-post-type="<?php echo esc_attr( $post_type ); ?>"
					data-ajax-page-size="<?php echo (int) $limit; ?>"
					data-search-min="<?php echo (int) $search_min_len; ?>"
					data-multiple="<?php echo $multiple ? '1' : '0'; ?>"
					data-max-selections="<?php echo (int) $max_sel; ?>"
					data-placeholder-text="<?php echo esc_attr( $placeholder ); ?>"
				>
					<?php if ( $multiple ) : ?>
						<option></option>
						<?php foreach ( $current_ids as $cid ) : ?>
							<?php
							$cid = (string) absint( $cid );
							if ( $cid === '0' ) {
								continue;
							}
							if ( ! $this->post_matches_field( (int) $cid, $post_type, $status ) ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $cid ); ?>" selected="selected">
								<?php echo esc_html( get_the_title( (int) $cid ) ); ?>
							</option>
						<?php endforeach; ?>
					<?php else : ?>
						<option value=""><?php echo esc_html( $placeholder ); ?></option>
						<?php if ( $has_current ) : ?>
							<option value="<?php echo esc_attr( (string) $current_int ); ?>" selected="selected">
								<?php echo esc_html( get_the_title( $current_int ) ); ?>
							</option>
						<?php endif; ?>
					<?php endif; ?>
				</select>
			</div>
			<?php endif; ?>

			<?php if ( $description ) : ?>
				<p class="sto-field-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function post_matches_field( $post_id, $post_type, $status ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		if ( $post->post_type !== $post_type ) {
			return false;
		}
		if ( $status === 'any' ) {
			return $post->post_status !== 'trash';
		}

		return $post->post_status === $status;
	}

	/**
	 * @param string                $field_id
	 * @param string                $post_type
	 * @param string                $status
	 * @param string                $default_value
	 * @param string|null           $bp_context Breakpoint key when the field is responsive; null = legacy / non-responsive read.
	 * @return string
	 */
	private function get_stored_single_for_render( $field_id, $post_type, $status, $default_value, $bp_context = null ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) ) {
			$root = $default_value;
		} elseif ( array_key_exists( $field_id, $saved ) ) {
			$root = $saved[ $field_id ];
		} else {
			$root = $default_value;
		}

		$bps = isset( $this->fields_by_id[ $field_id ]['responsive_breakpoints'] ) && is_array( $this->fields_by_id[ $field_id ]['responsive_breakpoints'] )
			? $this->fields_by_id[ $field_id ]['responsive_breakpoints']
			: null;

		if ( ! empty( $bps ) && null !== $bp_context && $bp_context !== '' ) {
			$bp_ctx = sanitize_key( (string) $bp_context );
			if ( is_array( $root ) && ResponsiveConfig::is_breakpoint_value_map( $root ) ) {
				$slice = ResponsiveConfig::raw_value_at_breakpoint( $root, $bp_ctx );
				$raw   = null !== $slice ? $slice : $default_value;
			} else {
				$raw = $root;
			}
		} elseif ( is_array( $root ) && ResponsiveConfig::is_breakpoint_value_map( $root ) ) {
			$slice = ResponsiveConfig::raw_value_at_breakpoint( $root, ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT );
			if ( null === $slice ) {
				$first = reset( $root );
				$raw   = is_scalar( $first ) ? (string) $first : '';
			} else {
				$raw = is_scalar( $slice ) ? (string) $slice : '';
			}
		} elseif ( is_array( $root ) ) {
			$raw = reset( $root );
		} else {
			$raw = $root;
		}

		$raw = is_scalar( $raw ) ? (string) $raw : '';
		$id  = absint( $raw );
		if ( $id < 1 ) {
			return '';
		}
		if ( ! $this->post_matches_field( $id, $post_type, $status ) ) {
			return '';
		}

		return (string) $id;
	}

	/**
	 * @param string                $field_id
	 * @param string                $post_type
	 * @param string                $status
	 * @param array<int|string>|string $default_ids
	 * @param string|null           $bp_context
	 * @return array<int, string>
	 */
	private function get_stored_id_list_for_render( $field_id, $post_type, $status, $default_ids, $bp_context = null ) {
		$saved = get_option( 'sto_options', array() );
		if ( ! is_array( $saved ) || ! array_key_exists( $field_id, $saved ) ) {
			$raw = $default_ids;
		} else {
			$root = $saved[ $field_id ];
			$bps  = isset( $this->fields_by_id[ $field_id ]['responsive_breakpoints'] ) && is_array( $this->fields_by_id[ $field_id ]['responsive_breakpoints'] )
				? $this->fields_by_id[ $field_id ]['responsive_breakpoints']
				: null;
			if ( ! empty( $bps ) && null !== $bp_context && $bp_context !== '' ) {
				$bp_ctx = sanitize_key( (string) $bp_context );
				if ( is_array( $root ) && ResponsiveConfig::is_breakpoint_value_map( $root ) ) {
					$slice = ResponsiveConfig::raw_value_at_breakpoint( $root, $bp_ctx );
					$raw   = null !== $slice ? $slice : $default_ids;
				} else {
					$raw = $root;
				}
			} elseif ( is_array( $root ) && ResponsiveConfig::is_breakpoint_value_map( $root ) ) {
				$slice = ResponsiveConfig::raw_value_at_breakpoint( $root, ResponsiveConfig::REQUIRED_EVAL_BREAKPOINT );
				$raw   = null !== $slice ? $slice : $default_ids;
			} else {
				$raw = $root;
			}
		}
		$candidates = $this->parse_incoming_id_list( $raw );
		$out        = array();
		foreach ( $candidates as $c ) {
			$id = absint( $c );
			if ( $id < 1 ) {
				continue;
			}
			if ( ! $this->post_matches_field( $id, $post_type, $status ) ) {
				continue;
			}
			$out[] = (string) $id;
		}

		return array_values( array_unique( $out ) );
	}
}
