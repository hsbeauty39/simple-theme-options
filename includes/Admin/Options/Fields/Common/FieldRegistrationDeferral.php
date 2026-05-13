<?php
namespace SimpleThemeOptions\Admin\Options\Fields\Common;

defined( 'ABSPATH' ) || exit;

/**
 * Runs field `register()` / `register_many()` work on `sto_include_option_fields` when callers
 * run earlier (e.g. theme `functions.php`, or a section class `init()` before `Options\Menu::register()`).
 *
 * When already inside (or after) that action, work runs immediately so nested registrations stay correct.
 */
final class FieldRegistrationDeferral {

	/**
	 * @var list<callable():void>
	 */
	private static $queue = array();

	private static $listener_added = false;

	/**
	 * @param callable():void $work
	 */
	public static function defer_or_run( callable $work ): void {
		if ( doing_action( 'sto_include_option_fields' ) || did_action( 'sto_include_option_fields' ) ) {
			$work();
			return;
		}

		self::$queue[] = $work;

		if ( ! self::$listener_added ) {
			self::$listener_added = true;
			add_action( 'sto_include_option_fields', array( __CLASS__, 'flush' ), 0, 1 );
		}
	}

	/**
	 * @param mixed $_menu Options Menu instance (unused).
	 */
	public static function flush( $_menu = null ): void {
		$batch       = self::$queue;
		self::$queue = array();

		foreach ( $batch as $work ) {
			$work();
		}
	}
}
