<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Integer priorities for {@see 'sto_render_section_content'} (PHP 8.4+ deprecates fractional hook priorities).
 *
 * Values preserve the legacy float ordering (17 → 21) with room between steps.
 */
final class RenderSectionContentPriority {
	public const IMAGE_SELECT        = 170;
	public const BUTTON_GROUP        = 175;
	public const RADIO_LISTS         = 176;
	public const SELECT_BLOCK        = 180;
	public const COLOR               = 190;
	public const BACKGROUND          = 192;
	public const LINK_COLOR          = 193;
	public const BORDER              = 194;
	public const SHADOW              = 195;
	public const GRADIENT            = 196;
	public const RANGE               = 197;
	public const DATE                = 198;
	public const DATETIME            = 199;
	public const DIMENSION           = 200;
	public const ADVANCED_REPEATER   = 201;
	public const MULTI_TEXT          = 202;
	public const GALLERY             = 203;
	public const ALIGNMENT           = 204;
	public const GOOGLE_MAP          = 205;
	public const ICON_SELECT         = 206;
	public const TABS                = 207;
	public const ACCORDION           = 208;
	public const INPUT               = 209;
	public const RICH_MODERN_EDITOR  = 210;
	public const CODE_EDITOR         = 211;
	public const TYPOGRAPHY          = 212;
	public const GROUP               = 220;
}
