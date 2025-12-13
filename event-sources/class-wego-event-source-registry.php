<?php
/**
 * Event source type registry
 *
 * Maintains a collection of registered event source type handlers.
 */
class WeGo_Event_Source_Registry {

	/** @var WeGo_Event_Source_Abstract[] */
	private static $types = [];

	/**
	 * Register an event source type
	 *
	 * @param string $class_name Fully qualified class name
	 */
	public static function register( $class_name ) {
		$instance = new $class_name();
		self::$types[ $instance->get_type() ] = $instance;
	}

	/**
	 * Get all registered event source types
	 *
	 * @return WeGo_Event_Source_Abstract[]
	 */
	public static function get_all() {
		return self::$types;
	}

	/**
	* Get a specific event source type by type
	*
	* @param string $type Event source type
	* @return WeGo_Event_Source_Abstract|null
	 */
	public static function get( $type ) {
		return self::$types[ $type ] ?? null;
	}

}
