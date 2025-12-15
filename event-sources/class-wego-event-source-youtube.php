<?php
/**
 * YouTube Video event source type
 */

class WeGo_Event_Source_YouTube extends WeGo_Event_Source_Abstract {

    /**
     * Valid YouTube player states (display-ready, canonical)
     */
    const VALID_STATES = [ 'Playing', 'Paused', 'Ended', 'Buffering' ];

	/**
	 * Initialize properties
	 */
	protected function init() {
		$this->type  = 'youtube_video';
		$this->label = __( 'YouTube Video', 'wego-traffic-source' );
	}

	/**
	 * Render admin config fields
	 *
	 * @param int|string $index        Row index for field names (or '{{INDEX}}' for templates)
	 * @param array      $tracked_event Saved tracked event data
	 */
	public function render_config_fields( $index, $tracked_event ) {
			$selector = $tracked_event['event_source']['selector'] ?? '';
			$states = $tracked_event['event_source']['states'] ?? [];
			$placeholder = esc_attr__( 'YouTube iframe target e.g.: iframe[data-track-video], iframe.hero-video', 'wego-traffic-source' );
			$textarea_name = 'tracked_events[' . esc_attr( $index ) . '][event_source_selector]';
			?>
			<div class="wego-config-fields" data-event-source-type="youtube_video">
				<textarea name="<?=  $textarea_name; ?>" placeholder="<?= $placeholder; ?>"><?= esc_textarea( $selector ); ?></textarea>
				<div class="wego-field-group">
					<label><?= esc_html__( 'State changes to track:', 'wego-traffic-source' ); ?></label>
					<div class="wego-youtube-checkboxes">
						<?php foreach ( self::VALID_STATES as $state_label ) : ?>
							<label>
								<input type="checkbox"
									name="tracked_events[<?= esc_attr( $index ); ?>][event_source_states][]"
									value="<?= esc_attr( $state_label ); ?>"
									<?php checked( in_array( $state_label, $states, true ) ); ?>>
								<?= esc_html( $state_label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
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
		$selector = $form_data['event_source_selector'] ?? '';
		$states = $form_data['event_source_states'] ?? [];

		// Validate selector
		$selector = sanitize_text_field( wp_unslash( $selector ) );
		if ( empty( $selector ) ) {
			return [
				'code'    => 'empty_selector',
				'message' => sprintf(
					__( 'Tracked event "%s": CSS selector cannot be empty.', 'wego-traffic-source' ),
					$name
				),
			];
		}

		$trimmed = trim( $selector );
		if ( empty( $trimmed ) ) {
			return [
				'code'    => 'invalid_selector',
				'message' => sprintf(
					__( 'Tracked event "%s": CSS selector cannot be empty.', 'wego-traffic-source' ),
					$name
				),
			];
		}

		// Validate states
		if ( ! is_array( $states ) ) {
			$states = [];
		}

		$states = array_map( 'sanitize_text_field', array_map( 'wp_unslash', $states ) );

		// Validate at least one state is selected
		if ( empty( $states ) ) {
			return [
				'code'    => 'no_states',
				'message' => sprintf(
					__( 'Tracked event "%s": Please select at least one video state to track.', 'wego-traffic-source' ),
					$name
				),
			];
		}

			// Validate all selected states are valid
			foreach ( $states as $state ) {
				if ( ! in_array( $state, self::VALID_STATES, true ) ) {
					return [
						'code'    => 'invalid_state',
						'message' => sprintf(
							__( 'Tracked event "%s": Invalid video state "%s" selected.', 'wego-traffic-source' ),
							$name,
							$state
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
		$selector = $form_data['event_source_selector'] ?? '';
		$states = $form_data['event_source_states'] ?? [];

		if ( ! is_array( $states ) ) {
			$states = [];
		}

		$selector = sanitize_text_field( wp_unslash( $selector ) );
		$states = array_map( 'sanitize_text_field', array_map( 'wp_unslash', $states ) );

		return [
			'type'     => $this->type,
			'selector' => $selector,
			'states'   => $states,
		];
	}

}
