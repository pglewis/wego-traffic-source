<?php
/**
 * Form Submit event source type
 */
class WeGo_Event_Source_Form_Submit extends WeGo_Event_Source_Abstract {

	/**
	 * Initialize properties
	 */
	protected function init() {
		$this->type  = 'form_submit';
		$this->label = __( 'Form Submit', 'wego-traffic-source' );
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
		<div class="wego-config-fields" data-event-source-type="form_submit">
			<textarea
				name="tracked_events[<?= esc_attr( $index ); ?>][event_source_selector]"
				placeholder="<?= esc_attr__( 'e.g., form.contact-form, form#booking', 'wego-traffic-source' ); ?>"><?= esc_textarea( $selector ); ?></textarea>
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

		if ( empty( $trimmed ) ) {
			return [
				'code'    => 'invalid_css_selector',
				'message' => sprintf(
					__( 'Tracked event "%s": CSS Selector cannot be empty. Please provide a form selector.', 'wego-traffic-source' ),
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
