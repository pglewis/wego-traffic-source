<?php
/**
 * Manages Event Type configuration via WordPress options/settings
 */
class WeGo_Event_Type_Settings {

	/**
	 * Option name for storing event types
	 */
	const OPTION_NAME = 'wego_traffic_source_event_types';

	/**
	 * Maximum slug length (WordPress CPT slugs max 20 chars, minus 5 for 'wego_' prefix)
	 */
	const MAX_SLUG_LENGTH = 15;

	/**
	 * Settings page slug
	 */
	const PAGE_SLUG = 'wego-event-types';

	/**
	 * Nonce action for form security
	 */
	const NONCE_ACTION = 'wego_save_event_types';

	/**
	 * Text domain for translations
	 */
	private static $text_domain;

	/**
	 * Initialize the settings page
	 */
	public static function init( $text_domain ) {
		self::$text_domain = $text_domain;

		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_wego_save_event_types', array( __CLASS__, 'handle_form_submission' ) );
	}

	/**
	 * Enqueue admin scripts and styles for the settings page
	 */
	public static function enqueue_admin_assets( $hook ) {
		// Only load on our settings page
		if ( $hook !== 'wego-tracking_page_' . self::PAGE_SLUG ) {
			return;
		}

		wp_enqueue_style(
			'wego-event-types-admin',
			plugins_url( 'css/wego-event-types-admin.css', __FILE__ ),
			array(),
			'1.0'
		);

		wp_enqueue_script_module(
			'wego-event-types-admin',
			plugins_url( 'js/wego-event-types-admin.js', __FILE__ ),
			array(),
			'1.0'
		);
	}

	/**
	 * Add the settings page under WeGo Tracking menu
	 */
	public static function add_settings_page() {
		add_submenu_page(
			'wego-tracking',
			__( 'Event Types', self::$text_domain ),
			__( 'Event Types', self::$text_domain ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page
	 */
	public static function render_settings_page() {
		$event_types = self::get_event_types();
		?>
		<div class="wrap">
			<h1><?= esc_html( 'Event Types', self::$text_domain ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) && sanitize_text_field( wp_unslash( $_GET['updated'] ) ) === '1' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?= esc_html( 'Event types saved.', self::$text_domain ); ?></p>
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
				<?php wp_nonce_field( self::NONCE_ACTION, 'wego_event_types_nonce' ); ?>

				<table class="wp-list-table widefat fixed striped" id="wego-event-types-table">
					<thead>
						<tr>
							<th style="width: 20%;">Name</th>
							<th style="width: 15%;">Slug</th>
							<th style="width: 20%;">Primary Value Label</th>
							<th style="width: 30%;">CSS Selector(s)</th>
							<th style="width: 8%;">Active</th>
							<th style="width: 7%;">Delete</th>
						</tr>
					</thead>
					<tbody id="wego-event-types-body" data-row-index="<?= esc_attr( count( $event_types ) ); ?>">
						<?php if ( empty( $event_types ) ) : ?>
							<tr class="wego-no-items">
								<td colspan="6"><?= esc_html( 'No event types configured. Click "Add Event Type" to create one.', self::$text_domain ); ?></td>
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
						<?= esc_html( 'Add Event Type', self::$text_domain ); ?>
					</button>
				</p>

				<?php submit_button( __( 'Save Event Types', self::$text_domain ) ); ?>
			</form>

			<div class="wego-selector-help" style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 16px; margin: 15px 0; max-width: 800px;">
				<p style="margin-top: 0;"><strong>CSS Selector Examples:</strong></p>
				<ul style="margin: 0 0 12px 20px; list-style: disc;">
					<li><code>a.schedule-button</code> — Links with a specific class</li>
					<li><code>a#booking-link</code> — Link with a specific ID</li>
					<li><code>a.this, a.or-that</code> — Multiple selectors, comma-separated, matches if any are matched</li>
					<li><code>a[href*="calendly.com"]</code> — href contains "calendly.com" anywhere</li>
					<li><code>a[href^="https://booking.example.com"]</code> — href begins with a specific string</li>
					<li><code>a[href$=".pdf"]</code> — href ends with a specific string (example: all PDF files)</li>
				</ul>
				<p>Note that only link clicks can be targeted (<code>a</code> tags only)</p>
				<p style="margin: 0;">
					<a href="https://vsdentalcollege.edu.in/static/media/css.1a50a159.pdf" target="_blank" rel="noopener noreferrer">
						CSS Selector Cheat Sheet ↗
					</a>
				</p>
			</div>
		</div>

		<script type="text/template" id="wego-event-type-row-template">
			<?php self::render_event_type_row( '{{INDEX}}', array() ); ?>
		</script>
		<?php
	}

	/**
	 * Render a single event type row
	 */
	private static function render_event_type_row( $index, $event_type ) {
		$name = isset( $event_type['name'] ) ? $event_type['name'] : '';
		$slug = isset( $event_type['slug'] ) ? $event_type['slug'] : '';
		$primary_label = isset( $event_type['primary_value_label'] ) ? $event_type['primary_value_label'] : '';
		$css_selectors = isset( $event_type['css_selectors'] ) ? $event_type['css_selectors'] : '';
		$active = isset( $event_type['active'] ) ? $event_type['active'] : false;
		?>
		<tr>
			<td>
				<input type="text"
					name="event_types[<?= esc_attr( $index ); ?>][name]"
					value="<?= esc_attr( $name ); ?>"
					class="wego-event-name"
					placeholder="<?= esc_attr( 'e.g., Schedule Clicks', self::$text_domain ); ?>">
			</td>
			<td>
				<input type="text"
					name="event_types[<?= esc_attr( $index ); ?>][slug]"
					value="<?= esc_attr( $slug ); ?>"
					class="wego-event-slug"
					placeholder="<?= esc_attr( 'auto-generated', self::$text_domain ); ?>"
					maxlength="<?= esc_attr( self::MAX_SLUG_LENGTH ); ?>"
					<?= $slug ? 'data-manual="true"' : ''; ?>>
			</td>
			<td>
				<input type="text"
					name="event_types[<?= esc_attr( $index ); ?>][primary_value_label]"
					value="<?= esc_attr( $primary_label ); ?>"
					placeholder="<?= esc_attr( 'e.g., Booking URL', self::$text_domain ); ?>">
			</td>
			<td>
				<textarea
					name="event_types[<?= esc_attr( $index ); ?>][css_selectors]"
					placeholder="<?= esc_attr( 'e.g., a[href*=&quot;calendly.com&quot;]', self::$text_domain ); ?>"><?= esc_textarea( $css_selectors ); ?></textarea>
			</td>
			<td style="text-align: center;">
				<input type="checkbox"
					name="event_types[<?= esc_attr( $index ); ?>][active]"
					value="1"
					<?php checked( $active ); ?>>
			</td>
			<td style="text-align: center;">
				<span class="dashicons dashicons-trash wego-delete-row" title="<?= esc_attr( 'Delete', self::$text_domain ); ?>"></span>
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
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wego_event_types_nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( __( 'Security check failed', self::$text_domain ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to manage event types', self::$text_domain ) );
		}

		$event_types = array();

		if ( isset( $_POST['event_types'] ) && is_array( $_POST['event_types'] ) ) {
			foreach ( $_POST['event_types'] as $event_type ) {
				$name = isset( $event_type['name'] ) ? sanitize_text_field( wp_unslash( $event_type['name'] ) ) : '';
				$slug = isset( $event_type['slug'] ) ? sanitize_key( wp_unslash( $event_type['slug'] ) ) : '';
				$primary_label = isset( $event_type['primary_value_label'] ) ? sanitize_text_field( wp_unslash( $event_type['primary_value_label'] ) ) : '';
				$css_selectors = isset( $event_type['css_selectors'] ) ? sanitize_textarea_field( wp_unslash( $event_type['css_selectors'] ) ) : '';
				$active = isset( $event_type['active'] ) && $event_type['active'] === '1';

				// Skip empty rows
				if ( empty( $name ) && empty( $slug ) ) {
					continue;
				}

				// Validate CSS selector: cannot be empty or just 'a'
				$trimmed_selector = trim( $css_selectors );
				if ( empty( $trimmed_selector ) || $trimmed_selector === 'a' ) {
					add_settings_error(
						'wego_event_types',
						'invalid_css_selector',
						sprintf(
							__( 'Event type "%s": CSS Selector cannot be empty or just "a". Please provide a more specific selector.', self::$text_domain ),
							$name
						),
						'error'
					);
					set_transient( 'wego_event_types_errors', get_settings_errors( 'wego_event_types' ), 30 );
					wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&error=1' ) );
					exit;
				}

				// Auto-generate slug if empty
				if ( empty( $slug ) && ! empty( $name ) ) {
					$slug = sanitize_key( str_replace( ' ', '_', strtolower( $name ) ) );
				}

				// Enforce max slug length (WordPress CPT slugs max 20 chars, minus 5 for 'wego_' prefix)
				$slug = substr( $slug, 0, self::MAX_SLUG_LENGTH );

				// Ensure unique slugs
				$base_slug = $slug;
				$counter = 1;
				while ( self::slug_exists_in_array( $slug, $event_types ) ) {
					$slug = $base_slug . '_' . $counter;
					$counter++;
				}

				$event_types[] = array(
					'name'                => $name,
					'slug'                => $slug,
					'primary_value_label' => $primary_label,
					'css_selectors'       => $css_selectors,
					'active'              => $active,
				);
			}
		}

		update_option( self::OPTION_NAME, $event_types );

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
		return get_option( self::OPTION_NAME, array() );
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
