<?php
/**
 * Abstract base class for event source types
 *
 * Uses properties + init() pattern to reduce boilerplate.
 * Each concrete class implements behavior methods for its event source type.
 */

abstract class WeGo_Event_Source_Abstract {

	/** @var string Event source type (e.g., 'link_click') */
	protected $type;

	/** @var string Human-readable label for dropdown */
	protected $label;

	/**
	 * Constructor - calls init() for child classes to set properties
	 */
	public function __construct() {
		$this->init();
	}

	/**
	* Initialize properties ($type, $label)
	 */
	abstract protected function init();

	/**
	 * Render admin config fields for this event source type
	 *
	 * @param int|string $index        Row index for field names (or '{{INDEX}}' for templates)
	 * @param array      $tracked_event Saved tracked event data
	 */
	abstract public function render_config_fields( $index, $tracked_event );

	/**
	 * Validate submitted data
	 *
	 * @param array  $form_data Submitted form data for this event source
	 * @param string $name      Tracked event name (for error messages)
	 * @return array|null Error array ['code' => string, 'message' => string] or null if valid
	 */
	abstract public function validate( $form_data, $name );

	/**
	 * Build event_source array from submitted data
	 *
	 * @param array $form_data Submitted form data
	 * @return array Event source configuration
	 */
	abstract public function build_event_source( $form_data );

	/**
	 * Get event source type
	 *
	 * @return string
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Get human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return $this->label;
	}

}
