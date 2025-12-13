<?php
/**
 * Manages Tracked Event configuration via WordPress options/settings
 */
class WeGo_Tracked_Event_Settings {

	/**
	 * Option/slug constants
	 */
	const OPTION_TRACKED_EVENTS = 'wego_traffic_source_tracked_events';
	const PARENT_MENU_SLUG = 'wego-tracking';
	const PAGE_SLUG = 'wego-tracked-events';
	const MAX_SLUG_LENGTH = 15;

	/**
	 * Nonce actions
	 */
	const NONCE_ACTION_SAVE_TRACKED_EVENTS = 'wego_save_tracked_events';

	/**
	 * Transient keys
	 */
	const TRANSIENT_TRACKED_EVENTS_ERRORS = 'wego_tracked_events_errors';

	/**
	 * Error codes
	 */
	const ERROR_INVALID_CSS_SELECTOR = 'invalid_css_selector';
	const ERROR_NO_PODIUM_EVENTS = 'no_podium_events';
	const ERROR_INVALID_PODIUM_EVENT = 'invalid_podium_event';
	const ERROR_UNKNOWN_EVENT_SOURCE_TYPE = 'unknown_event_source_type';
	const ERROR_MULTIPLE_PODIUM_TYPES = 'multiple_podium_types';

	/**
	 * Get event source type metadata for admin configuration
	 *
	 * @return array Event source type metadata
	 */
	public static function get_event_source_types_metadata() {
		$metadata = [];
		foreach ( WeGo_Event_Source_Registry::get_all() as $event_source ) {
			$metadata[ $event_source->get_type() ] = [
				'label' => $event_source->get_label(),
			];
		}
		return $metadata;
	}

	/**
	 * Initialize the settings page
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ], 11 );
		add_action( 'admin_post_wego_save_tracked_events', [ __CLASS__, 'handle_form_submission' ] );
	}

	/**
	 * Add the settings page under WeGo Tracking menu
	 */
	public static function add_settings_page() {
		add_submenu_page(
			self::PARENT_MENU_SLUG,
			__( 'Tracked Events', 'wego-traffic-source' ),
			__( 'Tracked Events', 'wego-traffic-source' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Render the settings page
	 */
	public static function render_settings_page() {
		$tracked_events = self::get_tracked_events();
		?>
		<div class="wrap">
			<h1><?= esc_html__( 'Tracked Events', 'wego-traffic-source' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) && sanitize_text_field( wp_unslash( $_GET['updated'] ) ) === '1' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?= esc_html__( 'Tracked events saved.', 'wego-traffic-source' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// Display validation errors
			if ( isset( $_GET['error'] ) && sanitize_text_field( wp_unslash( $_GET['error'] ) ) === '1' ) {
				$errors = get_transient( self::TRANSIENT_TRACKED_EVENTS_ERRORS );
				if ( $errors ) {
					for ( $i = 0; $i < count( $errors ); $i++ ) {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $i ]['message'] ) . '</p></div>';
					}
					delete_transient( self::TRANSIENT_TRACKED_EVENTS_ERRORS );
				}
			}
			?>

			<form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wego_save_tracked_events">
				<?php wp_nonce_field( self::NONCE_ACTION_SAVE_TRACKED_EVENTS, 'wego_tracked_events_nonce' ); ?>

				<table class="wp-list-table widefat fixed striped" id="wego-tracked-events-table">
					<thead>
						<tr>
							<th style="width: 18%;">
								<?= esc_html__( 'Name', 'wego-traffic-source' ); ?>
								<span class="dashicons dashicons-editor-help" title="<?= esc_attr__( 'Display name for this tracked event. Becomes the submenu title under WeGo Tracking.', 'wego-traffic-source' ); ?>" aria-label="<?= esc_attr__( 'Display name for this tracked event. Becomes the submenu title under WeGo Tracking.', 'wego-traffic-source' ); ?>" role="img"></span>
							</th>
							<th style="width: 12%;">
								<?= esc_html__( 'Slug', 'wego-traffic-source' ); ?>
								<span class="dashicons dashicons-editor-help" title="<?= esc_attr__( 'Unique identifier for the event (maximum 15 characters). Auto-generated from Name if left blank.', 'wego-traffic-source' ); ?>" aria-label="<?= esc_attr__( 'Unique identifier for the event (maximum 15 characters). Auto-generated from Name if left blank.', 'wego-traffic-source' ); ?>" role="img"></span>
							</th>
							<th style="width: 15%;">
								<?= esc_html__( 'Primary Value Label', 'wego-traffic-source' ); ?>
								<span class="dashicons dashicons-editor-help" title="<?= esc_attr__( 'Column header label for the data list. Example: Phone Number for tel clicks, Video Title for YouTube events.', 'wego-traffic-source' ); ?>" aria-label="<?= esc_attr__( 'Column header label for the data list. Example: Phone Number for tel clicks, Video Title for YouTube events.', 'wego-traffic-source' ); ?>" role="img"></span>
							</th>
							<th style="width: 12%;">
								<?= esc_html__( 'Event Source Type', 'wego-traffic-source' ); ?>
								<span class="dashicons dashicons-editor-help" title="<?= esc_attr__( 'What triggers this event (link clicks, form submissions, video interactions, etc.).', 'wego-traffic-source' ); ?>" aria-label="<?= esc_attr__( 'What triggers this event (link clicks, form submissions, video interactions, etc.).', 'wego-traffic-source' ); ?>" role="img"></span>
							</th>
							<th style="width: 28%;">
								<?= esc_html__( 'Event Source Target(s)', 'wego-traffic-source' ); ?>
								<span class="dashicons dashicons-editor-help" title="<?= esc_attr__( 'Configuration specific to this event source type.', 'wego-traffic-source' ); ?>" aria-label="<?= esc_attr__( 'Configuration specific to this event source type.', 'wego-traffic-source' ); ?>" role="img"></span>
							</th>
							<th style="width: 8%; text-align: center;">Active</th>
							<th style="width: 7%; text-align: center;">Delete</th>
						</tr>
					</thead>
					<tbody id="wego-tracked-events-body" data-row-index="<?= esc_attr( count( $tracked_events ) ); ?>">
						<?php if ( empty( $tracked_events ) ) : ?>
							<tr class="wego-no-items">
								<td colspan="7"><?= esc_html__( 'No tracked events configured. Click "Add Tracked Event" to create one.', 'wego-traffic-source' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $tracked_events as $index => $tracked_event ) : ?>
								<?php self::render_tracked_event_row( $index, $tracked_event ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p style="margin-top: 15px;">
					<button type="button" class="button" id="wego-add-tracked-event">
						<?= esc_html__( 'Add Tracked Event', 'wego-traffic-source' ); ?>
					</button>
				</p>

				<?php submit_button( __( 'Save Tracked Events', 'wego-traffic-source' ) ); ?>
			</form>

			<div class="wego-help-section" style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 16px; margin: 15px 0; max-width: 900px;">
				<p style="margin-top: 0;"><strong>Link Click CSS Selector Examples:</strong></p>
				<ul style="margin: 0 0 12px 20px; list-style: disc;">
					<li><code>a.schedule-button</code> â€” Links with a specific class</li>
					<li><code>a#booking-link</code> â€” Link with a specific ID</li>
					<li><code>a.this, a.or-that</code> â€” Multiple selectors, comma-separated</li>
					<li><code>a[href*="calendly.com"]</code> â€” href contains "calendly.com"</li>
					<li><code>a[href^="https://booking.example.com"]</code> â€” href begins with string</li>
					<li><code>a[href$=".pdf"]</code> â€” href ends with string (e.g., PDF files)</li>
				</ul>
				<p style="margin: 12px 0 0 0;">
					<a href="https://vsdentalcollege.edu.in/static/media/css.1a50a159.pdf" target="_blank" rel="noopener noreferrer">
						CSS Selector Cheat Sheet â†—
					</a>
				</p>
			</div>
		</div>

	<template id="wego-tracked-event-row-template">
		<?php self::render_tracked_event_row( '{{INDEX}}', [] ); ?>
	</template>

	<!-- Event Source Config Templates -->
	<?php foreach ( WeGo_Event_Source_Registry::get_all() as $event_source ) : ?>
		<template id="wego-event-source-<?= esc_attr( $event_source->get_type() ); ?>">
			<?php $event_source->render_config_fields( '{{INDEX}}', [] ); ?>
		</template>
	<?php endforeach; ?>
	<?php
	}

	/**
	 * Render event source configuration fields
	 */
	private static function render_event_source_config( $index, $event_source_type, $tracked_event ) {
		$type = WeGo_Event_Source_Registry::get( $event_source_type );
		if ( $type ) {
			$type->render_config_fields( $index, $tracked_event );
		}
	}

	/**
	 * Render a single tracked event row
	 */
	private static function render_tracked_event_row( $index, $tracked_event ) {
		$name = isset( $tracked_event['name'] ) ? $tracked_event['name'] : '';
		$slug = isset( $tracked_event['slug'] ) ? $tracked_event['slug'] : '';
		$primary_label = isset( $tracked_event['primary_value_label'] ) ? $tracked_event['primary_value_label'] : '';
		$active = isset( $tracked_event['active'] ) ? $tracked_event['active'] : false;

		// Extract event_source data (handle both new and legacy formats)
		$event_source_type = 'link_click'; // Default
		$link_selector = '';
		$podium_events = []; // Array of selected events

		if ( isset( $tracked_event['event_source'] ) && is_array( $tracked_event['event_source'] ) ) {
			// New format
			$event_source_type = $tracked_event['event_source']['type'];
			if ( $event_source_type === 'link_click' ) {
				$link_selector = isset( $tracked_event['event_source']['selector'] ) ? $tracked_event['event_source']['selector'] : '';
			} elseif ( $event_source_type === 'podium_widget' ) {
				$podium_events = isset( $tracked_event['event_source']['events'] ) ? $tracked_event['event_source']['events'] : [];
			}
		} elseif ( isset( $tracked_event['css_selectors'] ) ) {
			// Legacy format (pre-migration)
			$event_source_type = 'link_click';
			$link_selector = $tracked_event['css_selectors'];
		}

		$all_podium_events = [ 'Bubble Clicked', 'Conversation Started', 'Widget Closed' ];
		?>
		<tr data-row-index="<?= esc_attr( $index ); ?>">
			<td>
				<input type="text"
					name="tracked_events[<?= esc_attr( $index ); ?>][name]"
					value="<?= esc_attr( $name ); ?>"
					class="wego-event-name"
					placeholder="<?= esc_attr__( 'e.g., Schedule Clicks', 'wego-traffic-source' ); ?>">
			</td>
			<td>
				<input type="text"
					name="tracked_events[<?= esc_attr( $index ); ?>][slug]"
					value="<?= esc_attr( $slug ); ?>"
					class="wego-event-slug"
					placeholder="<?= esc_attr__( 'auto-generated', 'wego-traffic-source' ); ?>"
					maxlength="<?= esc_attr( self::MAX_SLUG_LENGTH ); ?>"
					<?= $slug ? 'data-manual="true"' : ''; ?>>
			</td>
			<td>
				<input type="text"
					name="tracked_events[<?= esc_attr( $index ); ?>][primary_value_label]"
					value="<?= esc_attr( $primary_label ); ?>"
					placeholder="<?= esc_attr__( 'e.g., Booking URL', 'wego-traffic-source' ); ?>">
			</td>
		<td>
			<select name="tracked_events[<?= esc_attr( $index ); ?>][event_source_type]"
				class="wego-event-source-type">
				<?php foreach ( WeGo_Event_Source_Registry::get_all() as $event_source ) : ?>
					<option value="<?= esc_attr( $event_source->get_type() ); ?>" <?php selected( $event_source_type, $event_source->get_type() ); ?>>
						<?= esc_html( $event_source->get_label() ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
			<td class="wego-config-container">
				<?php self::render_event_source_config( $index, $event_source_type, $tracked_event ); ?>
			</td>
			<td style="text-align: center;">
				<input type="checkbox"
					name="tracked_events[<?= esc_attr( $index ); ?>][active]"
					value="1"
					<?php checked( $active ); ?>>
			</td>
			<td style="text-align: center;">
				<span class="dashicons dashicons-trash wego-delete-row" title="<?= esc_attr__( 'Delete', 'wego-traffic-source' ); ?>"></span>
			</td>
		</tr>
		<?php
	}

	/**
	 * Handle form submission
	 */
	public static function handle_form_submission() {
		// Verify nonce
		if ( ! isset( $_POST['wego_tracked_events_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wego_tracked_events_nonce'] ) ), self::NONCE_ACTION_SAVE_TRACKED_EVENTS ) ) {
			wp_die( __( 'Security check failed', 'wego-traffic-source' ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to manage tracked events', 'wego-traffic-source' ) );
		}

		$tracked_events = [];
		$errors = [];

		if ( isset( $_POST['tracked_events'] ) && is_array( $_POST['tracked_events'] ) ) {
			foreach ( $_POST['tracked_events'] as $tracked_event ) {
				$name = isset( $tracked_event['name'] ) ? sanitize_text_field( wp_unslash( $tracked_event['name'] ) ) : '';
				$slug = isset( $tracked_event['slug'] ) ? sanitize_key( wp_unslash( $tracked_event['slug'] ) ) : '';
				$primary_label = isset( $tracked_event['primary_value_label'] ) ? sanitize_text_field( wp_unslash( $tracked_event['primary_value_label'] ) ) : '';
				$event_source_type = isset( $tracked_event['event_source_type'] ) ? sanitize_text_field( wp_unslash( $tracked_event['event_source_type'] ) ) : 'link_click';
				$active = isset( $tracked_event['active'] ) && $tracked_event['active'] === '1';

				// Skip empty rows
				if ( empty( $name ) && empty( $slug ) ) {
					continue;
				}

			// Build event_source structure based on type
			$event_source = [ 'type' => $event_source_type ];

			// Get event source type handler
			$type = WeGo_Event_Source_Registry::get( $event_source_type );

			if ( ! $type ) {
				// Unknown event source type
				$errors[] = [
					'code'    => 'unknown_event_source_type',
					'message' => sprintf(
						__( 'Tracked event "%s": Unknown event source type "%s".', 'wego-traffic-source' ),
						$name,
						$event_source_type
					),
				];
				continue; // Skip this tracked event
			}

			// Validate using event source type handler
			$validation_error = $type->validate( $tracked_event, $name );
			if ( $validation_error ) {
				$errors[] = $validation_error;
				continue; // Skip this tracked event
			}

			// Build event source using event source type handler
			$event_source = $type->build_event_source( $tracked_event );				// Auto-generate slug if empty
				if ( empty( $slug ) && ! empty( $name ) ) {
					$slug = sanitize_key( str_replace( ' ', '_', strtolower( $name ) ) );
				}

				// Enforce max slug length
				$slug = substr( $slug, 0, self::MAX_SLUG_LENGTH );

				// Ensure unique slugs
				$base_slug = $slug;
				$counter = 1;
				while ( self::slug_exists_in_array( $slug, $tracked_events ) ) {
					$slug = $base_slug . '_' . $counter;
					$counter++;
				}

				$tracked_events[] = [
					'name'                => $name,
					'slug'                => $slug,
					'primary_value_label' => $primary_label,
					'event_source'        => $event_source,
					'active'              => $active,
				];
			}
		}

	// Validate only one Podium tracked event is configured
	$podium_count = 0;
	for ( $i = 0; $i < count( $tracked_events ); $i++ ) {
		if ( isset( $tracked_events[ $i ]['event_source']['type'] ) && $tracked_events[ $i ]['event_source']['type'] === 'podium_widget' ) {
			$podium_count++;
		}
	}
	if ( $podium_count > 1 ) {
		$errors[] = [
			'code'    => self::ERROR_MULTIPLE_PODIUM_TYPES,
			'message' => __( 'Only one Podium Widget tracked event is allowed. Please consolidate multiple Podium configurations into a single tracked event.', 'wego-traffic-source' ),
		];
	}		// If there were validation errors, redirect back with error messages
		if ( ! empty( $errors ) ) {
			set_transient( self::TRANSIENT_TRACKED_EVENTS_ERRORS, $errors, 30 );
			wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&error=1' ) );
			exit;
		}

		update_option( self::OPTION_TRACKED_EVENTS, $tracked_events );

		// Redirect back to settings page
		wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&updated=1' ) );
		exit;
	}

	/**
	 * Check if a slug already exists in the tracked events array
	 */
	private static function slug_exists_in_array( $slug, $tracked_events ) {
		for ( $i = 0; $i < count( $tracked_events ); $i++ ) {
			if ( $tracked_events[ $i ]['slug'] === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get all tracked events
	 *
	 * @return array Array of tracked event configurations
	 */
	public static function get_tracked_events() {
		return get_option( self::OPTION_TRACKED_EVENTS, [] );
	}

	/**
	 * Get all active tracked events
	 *
	 * @return array Array of active tracked event configurations
	 */
	public static function get_active_tracked_events() {
		$tracked_events = self::get_tracked_events();
		return array_filter( $tracked_events, function( $tracked_event ) {
			return ! empty( $tracked_event['active'] );
		} );
	}

}