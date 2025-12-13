<?php
/**
 * Podium Widget event source type
 */
class WeGo_Event_Source_Podium_Widget extends WeGo_Event_Source_Abstract {

	/**
	 * Valid Podium event names
	 */
	const PODIUM_EVENTS = [ 'Bubble Clicked', 'Conversation Started', 'Widget Closed' ];

	/**
	 * Initialize properties
	 */
	protected function init() {
		$this->type  = 'podium_widget';
		$this->label = __( 'Podium Widget', 'wego-traffic-source' );
	}

	/**
	 * Render admin config fields
	 *
	 * @param int|string $index        Row index for field names (or '{{INDEX}}' for templates)
	 * @param array      $tracked_event Saved tracked event data
	 */
	public function render_config_fields( $index, $tracked_event ) {
		$podium_events = $tracked_event['event_source']['events'] ?? [];
		?>
		<div class="wego-config-fields" data-event-source-type="podium_widget">
			<div class="wego-podium-checkboxes">
				<?php for ( $i = 0; $i < count( self::PODIUM_EVENTS ); $i++ ) : ?>
					<label style="display: block; margin-bottom: 6px;">
						<input type="checkbox"
							name="tracked_events[<?= esc_attr( $index ); ?>][event_source_events][]"
							value="<?= esc_attr( self::PODIUM_EVENTS[ $i ] ); ?>"
							<?php checked( in_array( self::PODIUM_EVENTS[ $i ], $podium_events, true ) ); ?>>
						<?= esc_html( self::PODIUM_EVENTS[ $i ] ); ?>
					</label>
				<?php endfor; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Validate submitted data
	 *
	 * @param array  $form_data Submitted form data for this event source
	 * @param string $name      Tracked event name (for error messages)
	 * @return array|null Error array or null if valid
	 */
	public function validate( $form_data, $name ) {
		$podium_events = $form_data['event_source_events'] ?? [];

		if ( ! is_array( $podium_events ) ) {
			$podium_events = [];
		}

		$podium_events = array_map( 'sanitize_text_field', array_map( 'wp_unslash', $podium_events ) );

		// Validate at least one Podium event is selected
		if ( empty( $podium_events ) ) {
			return [
				'code'    => 'no_podium_events',
				'message' => sprintf(
					__( 'Tracked event "%s": Please select at least one Podium event to track.', 'wego-traffic-source' ),
					$name
				),
			];
		}

		// Validate all selected events are valid
		for ( $i = 0; $i < count( $podium_events ); $i++ ) {
			if ( ! in_array( $podium_events[ $i ], self::PODIUM_EVENTS, true ) ) {
				return [
					'code'    => 'invalid_podium_event',
					'message' => sprintf(
						__( 'Tracked event "%s": Invalid Podium event "%s" selected.', 'wego-traffic-source' ),
						$name,
						$podium_events[ $i ]
					),
				];
			}
		}

		return null;
	}

	/**
	 * Build event_source array from submitted data
	 *
	 * @param array $form_data Submitted form data
	 * @return array Event source configuration
	 */
	public function build_event_source( $form_data ) {
		$podium_events = $form_data['event_source_events'] ?? [];

		if ( ! is_array( $podium_events ) ) {
			$podium_events = [];
		}

		$podium_events = array_map( 'sanitize_text_field', array_map( 'wp_unslash', $podium_events ) );

		   return [
			   'type'   => $this->type,
			   'events' => $podium_events,
		   ];
	}

}
