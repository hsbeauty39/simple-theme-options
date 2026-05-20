<?php
namespace SimpleThemeOptions\Admin\Sample\Fields\Accordion;

use SimpleThemeOptions\Traits\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy module path — accordion demos moved to {@see GroupsPanels\GroupsPanels} (`groups-panels`).
 */
final class AccordionDemo {
	use SingletonTrait;

	protected function init() {
		// Intentionally empty: registrations live in GroupsPanels.
	}
}
