<?php
namespace SimpleThemeOptions\Traits;

defined( 'ABSPATH' ) || exit;

trait SingletonTrait {
	/**
	 * Keep one instance per class.
	 *
	 * @var array<string, object>
	 */
	private static $instances = array();

	/**
	 * Get singleton instance.
	 *
	 * @return static
	 */
	public static function instance() {
		$class = static::class;

		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();

			if ( method_exists( self::$instances[ $class ], 'init' ) ) {
				self::$instances[ $class ]->init();
			}
		}

		return self::$instances[ $class ];
	}

	protected function __construct() {}

	final public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is not allowed.', 'simple-theme-options' ), STO_VERSION );
	}

	final public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing is not allowed.', 'simple-theme-options' ), STO_VERSION );
	}
}
