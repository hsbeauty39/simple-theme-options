<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Shared headings + optional image tooltip (**`?`**) for option fields and group panels.
 * The help trigger sits **immediately after the title**; **scalar** responsive fields keep device tabs in **`trailing_html`** on the right. **Responsive Tabs** use **`between_html`** for the horizontal tab strip only; each **inner** responsive field uses **`trailing_html`** for its own device toolbar.
 */
final class FieldTitle {

	/**
	 * Read tooltip config from a field definition.
	 *
	 * Supported shapes:
	 * - `tooltip` => array( 'image' => URL, optional `'preloader'` => URL ) — omit **preloader** (or leave empty) to use the **built-in CSS spinner** while **image** loads; **preloader** is only for an optional custom image or short video (`.mp4` / `.webm` / `.ogg`) instead of that spinner.
	 * - `tooltip_image` => URL, optional `tooltip_preloader` => URL (same semantics).
	 *
	 * @param array<string, mixed> $field
	 * @return array{image: string, preloader: string}|null
	 */
	public static function get_tooltip_config( $field ) {
		if ( ! is_array( $field ) ) {
			return null;
		}

		$image     = '';
		$preloader = '';

		if ( ! empty( $field['tooltip'] ) && is_array( $field['tooltip'] ) ) {
			$t = $field['tooltip'];
			if ( ! empty( $t['image'] ) ) {
				$image = esc_url_raw( (string) $t['image'] );
			}
			if ( ! empty( $t['preloader'] ) ) {
				$preloader = esc_url_raw( (string) $t['preloader'] );
			}
		} elseif ( ! empty( $field['tooltip_image'] ) ) {
			$image = esc_url_raw( (string) $field['tooltip_image'] );
			if ( ! empty( $field['tooltip_preloader'] ) ) {
				$preloader = esc_url_raw( (string) $field['tooltip_preloader'] );
			}
		}

		if ( $image === '' ) {
			return null;
		}

		return array(
			'image'     => $image,
			'preloader' => $preloader,
		);
	}

	/**
	 * @param string                                         $title
	 * @param 'default'|'group_inner'|'group_panel'        $context `group_panel` = boxed **Group** / nested subgroup `<h3>` heading.
	 * @param array{image: string, preloader: string}|null $tooltip
	 * @param string                                         $field_id Sanitized id for stable aria / data attributes (field id or group id).
	 * @param bool                                           $inner_include_sep When `$context` is `group_inner`, print `.sto-field-title-sep` under the title (e.g. image group select).
	 * @param string                                         $trailing_html Safe HTML on the **right** of the title row (e.g. responsive device tabs from {@see ResponsiveControl::toolbar_markup()}). Image **`?`** help is rendered **immediately after the title** (not inside this string). Only pass trusted markup from this plugin.
	 * @param string                                         $between_html Optional center column (e.g. responsive **Tabs** / **Accordion** master strip). When non-empty, the row uses **`.sto-field-title-row--tabs-master`** (grid: title | center [+ optional trailing for scalar fields]).
	 */
	public static function render_heading( $title, $context, $tooltip, $field_id = '', $inner_include_sep = false, $trailing_html = '', $between_html = '' ) {
		$title           = (string) $title;
		$trailing_html   = is_string( $trailing_html ) ? $trailing_html : '';
		$between_html    = is_string( $between_html ) ? $between_html : '';
		$fid             = $field_id !== '' ? sanitize_key( $field_id ) : '';
		if ( $title === '' && $trailing_html === '' && $between_html === '' ) {
			return;
		}

		if ( 'group_inner' === $context ) {
			self::render_inner_heading( $title, $tooltip, $fid, (bool) $inner_include_sep, $trailing_html, $between_html );
			return;
		}

		if ( 'group_panel' === $context ) {
			self::render_group_panel_heading( $title, $tooltip, $fid, $trailing_html, $between_html );
			return;
		}

		self::render_default_heading( $title, $tooltip, $fid, $trailing_html, $between_html );
	}

	/**
	 * @param array{image: string, preloader: string}|null $tooltip
	 */
	private static function render_default_heading( $title, $tooltip, $field_id, $trailing_html = '', $between_html = '' ) {
		$row_class = 'sto-field-title-row';
		if ( $between_html !== '' ) {
			$row_class .= ' sto-field-title-row--tabs-master';
		}
		?>
		<div class="<?php echo esc_attr( $row_class ); ?>">
			<div class="sto-field-title-row__leading">
				<?php if ( $title !== '' ) : ?>
					<h4 class="sto-field-title"><?php echo esc_html( $title ); ?></h4>
				<?php else : ?>
					<span class="sto-field-title sto-field-title--visually-hidden" aria-hidden="true">&nbsp;</span>
				<?php endif; ?>
				<?php self::render_help_trigger( $tooltip, $field_id ); ?>
			</div>
			<?php if ( $between_html !== '' ) : ?>
				<div class="sto-field-title-row__center">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted plugin-generated markup (Tabs toolbar).
					echo $between_html;
					?>
				</div>
			<?php endif; ?>
			<?php if ( $trailing_html !== '' ) : ?>
				<div class="sto-field-title-row__trailing">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted plugin-generated markup (responsive toolbar only).
					echo $trailing_html;
					?>
				</div>
			<?php endif; ?>
		</div>
		<div class="sto-field-title-sep" aria-hidden="true"></div>
		<?php
	}

	/**
	 * @param array{image: string, preloader: string}|null $tooltip
	 */
	private static function render_inner_heading( $title, $tooltip, $field_id, $include_sep = false, $trailing_html = '', $between_html = '' ) {
		$row_class = 'sto-field-group-item-title-row';
		if ( $between_html !== '' ) {
			$row_class .= ' sto-field-title-row--tabs-master';
		}
		?>
		<div class="<?php echo esc_attr( $row_class ); ?>">
			<div class="sto-field-title-row__leading">
				<?php if ( $title !== '' ) : ?>
					<h5 class="sto-field-group-item-title"><?php echo esc_html( $title ); ?></h5>
				<?php else : ?>
					<span class="sto-field-group-item-title sto-field-title--visually-hidden" aria-hidden="true">&nbsp;</span>
				<?php endif; ?>
				<?php self::render_help_trigger( $tooltip, $field_id ); ?>
			</div>
			<?php if ( $between_html !== '' ) : ?>
				<div class="sto-field-title-row__center">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted plugin-generated markup (Tabs toolbar).
					echo $between_html;
					?>
				</div>
			<?php endif; ?>
			<?php if ( $trailing_html !== '' ) : ?>
				<div class="sto-field-title-row__trailing">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted plugin-generated markup (responsive toolbar only).
					echo $trailing_html;
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( $include_sep ) : ?>
			<div class="sto-field-title-sep sto-field-title-sep--inner" aria-hidden="true"></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array{image: string, preloader: string}|null $tooltip
	 */
	private static function render_group_panel_heading( $title, $tooltip, $field_id, $trailing_html = '', $between_html = '' ) {
		$title          = (string) $title;
		$trailing_html  = is_string( $trailing_html ) ? $trailing_html : '';
		$between_html   = is_string( $between_html ) ? $between_html : '';
		if ( $title === '' && $trailing_html === '' && $between_html === '' ) {
			return;
		}
		$row_class = 'sto-field-group-heading-row';
		if ( $between_html !== '' ) {
			$row_class .= ' sto-field-title-row--tabs-master';
		}
		?>
		<div class="<?php echo esc_attr( $row_class ); ?>">
			<div class="sto-field-title-row__leading">
				<?php if ( $title !== '' ) : ?>
					<h3 class="sto-field-group-heading"><?php echo esc_html( $title ); ?></h3>
				<?php else : ?>
					<span class="sto-field-group-heading sto-field-title--visually-hidden" aria-hidden="true">&nbsp;</span>
				<?php endif; ?>
				<?php self::render_help_trigger( $tooltip, $field_id ); ?>
			</div>
			<?php if ( $between_html !== '' ) : ?>
				<div class="sto-field-title-row__center">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted plugin-generated markup (Tabs toolbar).
					echo $between_html;
					?>
				</div>
			<?php endif; ?>
			<?php if ( $trailing_html !== '' ) : ?>
				<div class="sto-field-title-row__trailing">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted plugin-generated markup (responsive toolbar only).
					echo $trailing_html;
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array{image: string, preloader: string}|null $tooltip
	 */
	public static function render_help_trigger( $tooltip, $field_id ) {
		if ( ! is_array( $tooltip ) || $tooltip['image'] === '' ) {
			return;
		}

		$data_id = $field_id !== '' ? $field_id : wp_generate_password( 8, false, false );
		?>
		<button
			type="button"
			class="sto-field-help-trigger"
			data-sto-field-help="<?php echo esc_attr( $data_id ); ?>"
			data-sto-tooltip-image="<?php echo esc_attr( $tooltip['image'] ); ?>"
			<?php if ( $tooltip['preloader'] !== '' ) : ?>
				data-sto-tooltip-preloader="<?php echo esc_attr( $tooltip['preloader'] ); ?>"
			<?php endif; ?>
			aria-label="<?php esc_attr_e( 'Show help preview', 'simple-theme-options' ); ?>"
		>
			<span class="sto-field-help-trigger__glyph" aria-hidden="true">?</span>
		</button>
		<?php
	}
}
