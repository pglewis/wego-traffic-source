<?php
/**
 * Link Click event source type
 */
class WeGo_Event_Source_Link_Click extends WeGo_Event_Source_Abstract {

	/**
	 * Initialize properties
	 */
	protected function init() {
		$this->type  = 'link_click';
		$this->label = __( 'Link Click', 'wego-traffic-source' );
	}

	/**
	 * Render admin config fields
	 *
	 * @param int|string $index        Row index for field names (or '{{INDEX}}' for templates)
	 * @param array      $tracked_event Saved tracked event data
	 */
	public function render_config_fields( $index, $tracked_event ) {
		$selector = $tracked_event['event_source']['selector'] ?? '';
		?>
		<div class="wego-config-fields" data-event-source-type="link_click">
			<textarea
				name="tracked_events[<?= esc_attr( $index ); ?>][event_source_selector]"
				placeholder="<?= esc_attr__( 'e.g., a[href*="calendly.com"]', 'wego-traffic-source' ); ?>"><?= esc_textarea( $selector ); ?></textarea>
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
		$selector = $form_data['event_source_selector'] ?? '';
		$trimmed  = trim( $selector );

		if ( empty( $trimmed ) || $trimmed === 'a' ) {
			return [
				'code'    => 'invalid_css_selector',
				'message' => sprintf(
					__( 'Tracked event "%s": CSS Selector cannot be empty or just "a".', 'wego-traffic-source' ),
					$name
				),
			];
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
		   return [
			   'type'     => $this->type,
			   'selector' => sanitize_textarea_field( wp_unslash( $form_data['event_source_selector'] ) ),
		   ];
	}

}
