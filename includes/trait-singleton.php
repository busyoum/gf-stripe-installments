<?php
/**
 * Shared singleton trait.
 *
 * @package GFSI
 */

declare( strict_types = 1 );

namespace GFSI;

defined( 'ABSPATH' ) || exit;

/**
 * Provides a single shared instance per class.
 */
trait Singleton {

	/**
	 * Instances, keyed by class name.
	 *
	 * @var array<string, static>
	 */
	private static array $instances = [];

	/**
	 * Get the shared instance.
	 *
	 * @return static
	 */
	public static function instance() {
		$class = static::class;

		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}

		return self::$instances[ $class ];
	}
}
