<?php
/**
 * Manages Event Type configuration via WordPress options/settings
 */
class WeGo_Event_Type_Settings {

	/**
	 * Option/slug constants
	 */
	const OPTION_EVENT_TYPES = 'wego_traffic_source_event_types';
	const PARENT_MENU_SLUG = 'wego-tracking';
	const PAGE_SLUG = 'wego-event-types';
	const MAX_SLUG_LENGTH = 15;

	/**
	 * Nonce actions
	 */
	const NONCE_ACTION_SAVE_EVENT_TYPES = 'wego_save_event_types';

	/**
	 * Transient keys
	 */
	const TRANSIENT_EVENT_TYPES_ERRORS = 'wego_event_types_errors';

	/**
	 * Error codes
	 */
	const ERROR_INVALID_CSS_SELECTOR = 'invalid_css_selector';
	const ERROR_NO_PODIUM_EVENTS = 'no_podium_events';
	const ERROR_INVALID_PODIUM_EVENT = 'invalid_podium_event';
	const ERROR_UNKNOWN_EVENT_SOURCE_TYPE = 'unknown_event_source_type';
	const ERROR_MULTIPLE_PODIUM_TYPES = 'multiple_podium_types';

	/**
	 * Event source types
	 */
	const EVENT_SOURCE_TYPE_LINK_CLICK = 'link_click';
	const EVENT_SOURCE_TYPE_PODIUM_WIDGET = 'podium_widget';

	/**
	 * Valid Podium event names
	 */
	const PODIUM_EVENTS = [ 'Bubble Clicked', 'Conversation Started', 'Widget Closed' ];

	/**
	 * Get event source type metadata for admin configuration
	 *
	 * @return array Event source type metadata
	 */
	public static function get_event_source_types_metadata() {
		return [
			self::EVENT_SOURCE_TYPE_LINK_CLICK => [
				'label' => __( 'Link Click', 'wego-traffic-source' ),
				'validation_type' => 'css_selector',
			],
			self::EVENT_SOURCE_TYPE_PODIUM_WIDGET => [
				'label' => __( 'Podium Widget', 'wego-traffic-source' ),
				'validation_type' => 'podium_events',
			],
		];
	}

	/**
	 * Initialize the settings page
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ], 11 );
		add_action( 'admin_post_wego_save_event_types', [ __CLASS__, 'handle_form_submission' ] );
	}

	/**
	 * Add the settings page under WeGo Tracking menu
	 */
	public static function add_settings_page() {
		add_submenu_page(
			self::PARENT_MENU_SLUG,
			__( 'Event Types', 'wego-traffic-source' ),
			__( 'Event Types', 'wego-traffic-source' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Render the settings page
	 */
	public static function render_settings_page() {
		$event_types = self::get_event_types();
		?>
		<div class="wrap">
			<h1><?= esc_html__( 'Event Types', 'wego-traffic-source' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) && sanitize_text_field( wp_unslash( $_GET['updated'] ) ) === '1' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?= esc_html__( 'Event types saved.', 'wego-traffic-source' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// Display validation errors
			if ( isset( $_GET['error'] ) && sanitize_text_field( wp_unslash( $_GET['error'] ) ) === '1' ) {
				$errors = get_transient( 'wego_event_types_errors' );
				if ( $errors ) {
					foreach ( $errors as $error ) {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error['message'] ) . '</p></div>';
					}
					delete_transient( 'wego_event_types_errors' );
				}
			}
			?>

			<form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wego_save_event_types">
				<?php wp_nonce_field( self::NONCE_ACTION_SAVE_EVENT_TYPES, 'wego_event_types_nonce' ); ?>

				<table class="wp-list-table widefat fixed striped" id="wego-event-types-table">
					<thead>
						<tr>
							<th style="width: 18%;">Name</th>
							<th style="width: 12%;">Slug</th>
							<th style="width: 15%;">Primary Value Label</th>
							<th style="width: 12%;">Event Source Type</th>
							<th style="width: 28%;">Event Source</th>
							<th style="width: 8%;">Active</th>
							<th style="width: 7%;">Delete</th>
						</tr>
					</thead>
					<tbody id="wego-event-types-body" data-row-index="<?= esc_attr( count( $event_types ) ); ?>">
						<?php if ( empty( $event_types ) ) : ?>
							<tr class="wego-no-items">
								<td colspan="7"><?= esc_html__( 'No event types configured. Click "Add Event Type" to create one.', 'wego-traffic-source' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $event_types as $index => $event_type ) : ?>
								<?php self::render_event_type_row( $index, $event_type ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p style="margin-top: 15px;">
					<button type="button" class="button" id="wego-add-event-type">
						<?= esc_html__( 'Add Event Type', 'wego-traffic-source' ); ?>
					</button>
				</p>

				<?php submit_button( __( 'Save Event Types', 'wego-traffic-source' ) ); ?>
			</form>

			<div class="wego-help-section" style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 16px; margin: 15px 0; max-width: 900px;">
				<p style="margin-top: 0;"><strong>Link Click CSS Selector Examples:</strong></p>
				<ul style="margin: 0 0 12px 20px; list-style: disc;">
					<li><code>a.schedule-button</code> — Links with a specific class</li>
					<li><code>a#booking-link</code> — Link with a specific ID</li>
					<li><code>a.this, a.or-that</code> — Multiple selectors, comma-separated</li>
					<li><code>a[href*="calendly.com"]</code> — href contains "calendly.com"</li>
					<li><code>a[href^="https://booking.example.com"]</code> — href begins with string</li>
					<li><code>a[href$=".pdf"]</code> — href ends with string (e.g., PDF files)</li>
				</ul>
				<p style="margin: 12px 0 0 0;">
					<a href="https://vsdentalcollege.edu.in/static/media/css.1a50a159.pdf" target="_blank" rel="noopener noreferrer">
						CSS Selector Cheat Sheet ↗
					</a>
				</p>
			</div>
		</div>

		<template id="wego-event-type-row-template">
			<?php self::render_event_type_row( '{{INDEX}}', [] ); ?>
		</template>

		<!-- Event Source Config Templates -->
		<template id="wego-event-source-link_click">
			<?php self::render_event_source_config( '{{INDEX}}', 'link_click', [] ); ?>
		</template>

		<template id="wego-event-source-podium_widget">
			<?php self::render_event_source_config( '{{INDEX}}', 'podium_widget', [] ); ?>
		</template>
		<?php
	}

	/**
	 * Render event source configuration fields
	 */
	private static function render_event_source_config( $index, $event_source_type, $event_type ) {
		switch ( $event_source_type ) {
			case 'link_click':
				$link_selector = '';
				if ( isset( $event_type['event_source']['selector'] ) ) {
					$link_selector = $event_type['event_source']['selector'];
				}
				?>
				<div class="wego-config-fields" data-event-source-type="link_click">
					<textarea
						name="event_types[<?= esc_attr( $index ); ?>][event_source_selector]"
						placeholder="<?= esc_attr__( 'e.g., a[href*="calendly.com"]', 'wego-traffic-source' ); ?>"><?= esc_textarea( $link_selector ); ?></textarea>
				</div>
				<?php
				break;

			case 'podium_widget':
				$podium_events = [];
				if ( isset( $event_type['event_source']['events'] ) ) {
					$podium_events = $event_type['event_source']['events'];
				}
				$all_podium_events = [ 'Bubble Clicked', 'Conversation Started', 'Widget Closed' ];
				?>
				<div class="wego-config-fields" data-event-source-type="podium_widget">
					<div class="wego-podium-checkboxes">
						<?php foreach ( $all_podium_events as $event ) : ?>
							<label style="display: block; margin-bottom: 6px;">
								<input type="checkbox"
									name="event_types[<?= esc_attr( $index ); ?>][event_source_events][]"
									value="<?= esc_attr( $event ); ?>"
									<?php checked( in_array( $event, $podium_events, true ) ); ?>>
								<?= esc_html( $event ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
				<?php
				break;
		}
	}

	/**
	 * Render a single event type row
	 */
	private static function render_event_type_row( $index, $event_type ) {
		$name = isset( $event_type['name'] ) ? $event_type['name'] : '';
		$slug = isset( $event_type['slug'] ) ? $event_type['slug'] : '';
		$primary_label = isset( $event_type['primary_value_label'] ) ? $event_type['primary_value_label'] : '';
		$active = isset( $event_type['active'] ) ? $event_type['active'] : false;

		// Extract event_source data (handle both new and legacy formats)
		$event_source_type = 'link_click'; // Default
		$link_selector = '';
		$podium_events = []; // Array of selected events

		if ( isset( $event_type['event_source'] ) && is_array( $event_type['event_source'] ) ) {
			// New format
			$event_source_type = $event_type['event_source']['type'];
			if ( $event_source_type === 'link_click' ) {
				$link_selector = isset( $event_type['event_source']['selector'] ) ? $event_type['event_source']['selector'] : '';
			} elseif ( $event_source_type === 'podium_widget' ) {
				$podium_events = isset( $event_type['event_source']['events'] ) ? $event_type['event_source']['events'] : [];
			}
		} elseif ( isset( $event_type['css_selectors'] ) ) {
			// Legacy format (pre-migration)
			$event_source_type = 'link_click';
			$link_selector = $event_type['css_selectors'];
		}

		$all_podium_events = [ 'Bubble Clicked', 'Conversation Started', 'Widget Closed' ];
		?>
		<tr data-row-index="<?= esc_attr( $index ); ?>">
			<td>
				<input type="text"
					name="event_types[<?= esc_attr( $index ); ?>][name]"
					value="<?= esc_attr( $name ); ?>"
					class="wego-event-name"
					placeholder="<?= esc_attr__( 'e.g., Schedule Clicks', 'wego-traffic-source' ); ?>">
			</td>
			<td>
				<input type="text"
					name="event_types[<?= esc_attr( $index ); ?>][slug]"
					value="<?= esc_attr( $slug ); ?>"
					class="wego-event-slug"
					placeholder="<?= esc_attr__( 'auto-generated', 'wego-traffic-source' ); ?>"
					maxlength="<?= esc_attr( self::MAX_SLUG_LENGTH ); ?>"
					<?= $slug ? 'data-manual="true"' : ''; ?>>
			</td>
			<td>
				<input type="text"
					name="event_types[<?= esc_attr( $index ); ?>][primary_value_label]"
					value="<?= esc_attr( $primary_label ); ?>"
					placeholder="<?= esc_attr__( 'e.g., Booking URL', 'wego-traffic-source' ); ?>">
			</td>
			<td>
				<select name="event_types[<?= esc_attr( $index ); ?>][event_source_type]"
					class="wego-event-source-type">
					<option value="<?= esc_attr( self::EVENT_SOURCE_TYPE_LINK_CLICK ); ?>" <?php selected( $event_source_type, self::EVENT_SOURCE_TYPE_LINK_CLICK ); ?>>
						<?= esc_html__( 'Link Click', 'wego-traffic-source' ); ?>
					</option>
					<option value="<?= esc_attr( self::EVENT_SOURCE_TYPE_PODIUM_WIDGET ); ?>" <?php selected( $event_source_type, self::EVENT_SOURCE_TYPE_PODIUM_WIDGET ); ?>>
						<?= esc_html__( 'Podium Widget', 'wego-traffic-source' ); ?>
					</option>
				</select>
			</td>
			<td class="wego-config-container">
				<?php self::render_event_source_config( $index, $event_source_type, $event_type ); ?>
			</td>
			<td style="text-align: center;">
				<input type="checkbox"
					name="event_types[<?= esc_attr( $index ); ?>][active]"
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
		   if ( ! isset( $_POST['wego_event_types_nonce'] ) ||
			   ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wego_event_types_nonce'] ) ), self::NONCE_ACTION_SAVE_EVENT_TYPES ) ) {
			wp_die( __( 'Security check failed', 'wego-traffic-source' ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to manage event types', 'wego-traffic-source' ) );
		}

		$event_types = [];
		$errors = [];

		if ( isset( $_POST['event_types'] ) && is_array( $_POST['event_types'] ) ) {
			foreach ( $_POST['event_types'] as $event_type ) {
				$name = isset( $event_type['name'] ) ? sanitize_text_field( wp_unslash( $event_type['name'] ) ) : '';
				$slug = isset( $event_type['slug'] ) ? sanitize_key( wp_unslash( $event_type['slug'] ) ) : '';
				$primary_label = isset( $event_type['primary_value_label'] ) ? sanitize_text_field( wp_unslash( $event_type['primary_value_label'] ) ) : '';
				$event_source_type = isset( $event_type['event_source_type'] ) ? sanitize_text_field( wp_unslash( $event_type['event_source_type'] ) ) : 'link_click';
				$active = isset( $event_type['active'] ) && $event_type['active'] === '1';

				// Skip empty rows
				if ( empty( $name ) && empty( $slug ) ) {
					continue;
				}

				// Build event_source structure based on type
				$event_source = [ 'type' => $event_source_type ];

				if ( $event_source_type === 'link_click' ) {
					$selector = isset( $event_type['event_source_selector'] ) ? sanitize_textarea_field( wp_unslash( $event_type['event_source_selector'] ) ) : '';

					// Validate CSS selector
					$trimmed_selector = trim( $selector );
					if ( empty( $trimmed_selector ) || $trimmed_selector === 'a' ) {
						$errors[] = [
							'code' => 'invalid_css_selector',
							'message' => sprintf(
								__( 'Event type "%s": CSS Selector cannot be empty or just "a". Please provide a more specific selector.', 'wego-traffic-source' ),
								$name
							)
						];
						continue; // Skip this event type
					}

					$event_source['selector'] = $selector;

				} elseif ( $event_source_type === 'podium_widget' ) {
					$podium_events = isset( $event_type['event_source_events'] ) && is_array( $event_type['event_source_events'] )
						? array_map( 'sanitize_text_field', array_map( 'wp_unslash', $event_type['event_source_events'] ) )
						: [];

					// Validate at least one Podium event is selected
					if ( empty( $podium_events ) ) {
						$errors[] = [
							'code' => 'no_podium_events',
							'message' => sprintf(
								__( 'Event type "%s": Please select at least one Podium event to track.', 'wego-traffic-source' ),
								$name
							)
						];
						continue; // Skip this event type
					}

					// Validate all selected events are valid
					$valid_events = [ 'Bubble Clicked', 'Conversation Started', 'Widget Closed' ];
					foreach ( $podium_events as $podium_event ) {
						if ( ! in_array( $podium_event, $valid_events, true ) ) {
							$errors[] = [
								'code' => 'invalid_podium_event',
								'message' => sprintf(
									__( 'Event type "%s": Invalid Podium event "%s" selected.', 'wego-traffic-source' ),
									$name,
									$podium_event
								)
							];
							continue 2; // Skip this event type
						}
					}

					$event_source['events'] = $podium_events;

				} else {
					// Unknown event source type
					$errors[] = [
						'code' => 'unknown_event_source_type',
						'message' => sprintf(
							__( 'Event type "%s": Unknown event source type "%s".', 'wego-traffic-source' ),
							$name,
							$event_source_type
						)
					];
					continue; // Skip this event type
				}

				// Auto-generate slug if empty
				if ( empty( $slug ) && ! empty( $name ) ) {
					$slug = sanitize_key( str_replace( ' ', '_', strtolower( $name ) ) );
				}

				// Enforce max slug length
				$slug = substr( $slug, 0, self::MAX_SLUG_LENGTH );

				// Ensure unique slugs
				$base_slug = $slug;
				$counter = 1;
				while ( self::slug_exists_in_array( $slug, $event_types ) ) {
					$slug = $base_slug . '_' . $counter;
					$counter++;
				}

				$event_types[] = [
					'name'                => $name,
					'slug'                => $slug,
					'primary_value_label' => $primary_label,
					'event_source'        => $event_source,
					'active'              => $active,
				];
			}
		}

		// Validate only one Podium event type is configured
		$podium_count = 0;
		foreach ( $event_types as $event_type ) {
			if ( isset( $event_type['event_source']['type'] ) && $event_type['event_source']['type'] === self::EVENT_SOURCE_TYPE_PODIUM_WIDGET ) {
				$podium_count++;
			}
		}
		if ( $podium_count > 1 ) {
			$errors[] = [
				'code' => self::ERROR_MULTIPLE_PODIUM_TYPES,
				'message' => __( 'Only one Podium Widget event type is allowed. Please consolidate multiple Podium configurations into a single event type.', 'wego-traffic-source' )
			];
		}

		// If there were validation errors, redirect back with error messages
		if ( ! empty( $errors ) ) {
			set_transient( 'wego_event_types_errors', $errors, 30 );
			wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&error=1' ) );
			exit;
		}

		update_option( self::OPTION_EVENT_TYPES, $event_types );

		// Redirect back to settings page
		wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&updated=1' ) );
		exit;
	}

	/**
	 * Check if a slug already exists in the event types array
	 */
	private static function slug_exists_in_array( $slug, $event_types ) {
		foreach ( $event_types as $event_type ) {
			if ( $event_type['slug'] === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get all event types
	 *
	 * @return array Array of event type configurations
	 */
	public static function get_event_types() {
		return get_option( self::OPTION_EVENT_TYPES, [] );
	}

	/**
	 * Get all active event types
	 *
	 * @return array Array of active event type configurations
	 */
	public static function get_active_event_types() {
		$event_types = self::get_event_types();
		return array_filter( $event_types, function( $event_type ) {
			return ! empty( $event_type['active'] );
		} );
	}

}
