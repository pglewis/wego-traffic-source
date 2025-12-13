<?php
/**
 * Database migrations for WeGo Traffic Source plugin
 *
 * Handles one-time migrations via a versioned approach. On plugin load,
 * checks current db version and runs any needed migrations sequentially.
 */

class WeGo_Migrations {
	const OPTION_NAME_SCHEMA_VERSION = 'wego_traffic_source_db_version';
	const MIGRATION_NOTICE_OPTION = 'wego_traffic_source_migration_notice_dismissed';

	/**
	 * Run pending migrations
	 */
	public static function run() {
		$current_schema_version = (int) get_option( self::OPTION_NAME_SCHEMA_VERSION, 0 );

		if ( $current_schema_version < 1 ) {
			self::migrate_v1_tel_clicks();
			update_option( self::OPTION_NAME_SCHEMA_VERSION, 1 );
		}

		// Starting with v2.2.0
		if ( $current_schema_version < 2 ) {
			self::migrate_v2_event_source_refactor();
			update_option( self::OPTION_NAME_SCHEMA_VERSION, 2 );
		}

		// Starting with v2.3.0: Rename "event types" to "tracked events"
		if ( $current_schema_version < 3 ) {
			self::migrate_v3_rename_tracked_events();
			update_option( self::OPTION_NAME_SCHEMA_VERSION, 3 );
		}
	}

	/**
	 * v1 Migration: Create default Tel Clicks event type and migrate legacy posts
	 */
	private static function migrate_v1_tel_clicks() {
		// Always create default "Tel Clicks" event type if it doesn't exist
		self::create_default_tel_clicks_event_type();

		// Migrate legacy posts if any exist
		self::migrate_legacy_tel_click_posts();
	}

	/**
	 * Create the default "Tel Clicks" event type in wego_event_types option
	 */
	private static function create_default_tel_clicks_event_type() {
		$event_types = get_option( 'wego_traffic_source_event_types', [] );

		// Check if tel_clicks event type already exists
		foreach ( $event_types as $event_type ) {
			if ( $event_type['slug'] === 'tel_clicks' ) {
				return; // Already exists, nothing to do
			}
		}

		// Add default Tel Clicks event type
		$event_types[] = [
			'name'                => 'Tel Clicks',
			'slug'                => 'tel_clicks',
			'primary_value_label' => 'Phone Number',
			'css_selectors'       => 'a[href^="tel:"]',
			'active'              => true,
		];

		update_option( 'wego_traffic_source_event_types', $event_types );
	}

	/**
	 * Migrate legacy wego_tel_click posts to the new wego_tel_clicks CPT
	 */
	private static function migrate_legacy_tel_click_posts() {
		global $wpdb;

		// Check if any legacy posts exist
		$legacy_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				'wego_tel_click'
			)
		);

		if ( $legacy_count > 0 ) {
			// Update post_type for all legacy tel click posts
			$wpdb->update(
				$wpdb->posts,
				[ 'post_type' => 'wego_tel_clicks' ],
				[ 'post_type' => 'wego_tel_click' ]
			);

			// Set flag to show admin notice
			update_option( self::MIGRATION_NOTICE_OPTION, false );
		}
	}

	/**
	 * Initialize admin notice hooks
	 */
	public static function init_admin_notices() {
		add_action( 'admin_notices', [ __CLASS__, 'show_migration_notice' ] );
		add_action( 'wp_ajax_wego_dismiss_migration_notice', [ __CLASS__, 'dismiss_migration_notice' ] );
	}

	/**
	 * Show one-time admin notice after migration
	 */
	public static function show_migration_notice() {
		// Only show if the option exists and is false (migration happened, not dismissed)
		if ( get_option( self::MIGRATION_NOTICE_OPTION, true ) !== false ) {
			return;
		}

		// Only show to users who can manage options
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="notice notice-success is-dismissible wego-migration-notice">
			<p>
				<strong><?= esc_html__( 'WeGo Traffic Source:', 'wego-traffic-source' ); ?></strong>
				<?= esc_html__( 'Legacy tel click data has been migrated to the new event tracking system.', 'wego-traffic-source' ); ?>
			</p>
		</div>
		<script>
			jQuery(document).on('click', '.wego-migration-notice .notice-dismiss', function() {
				jQuery.post(ajaxurl, {
					action: 'wego_dismiss_migration_notice',
					_wpnonce: '<?= wp_create_nonce( 'wego_dismiss_migration_notice' ); ?>'
				});
			});
		</script>
		<?php
	}

	/**
	 * AJAX handler to dismiss migration notice
	 */
	public static function dismiss_migration_notice() {
		check_ajax_referer( 'wego_dismiss_migration_notice' );

		if ( current_user_can( 'manage_options' ) ) {
			update_option( self::MIGRATION_NOTICE_OPTION, true );
		}

		wp_die();
	}

	/**
	 * v2 Migration: Convert css_selectors string to event_source object structure
	 */
	private static function migrate_v2_event_source_refactor() {
		$event_types = get_option( 'wego_traffic_source_event_types', [] );

		foreach ( $event_types as &$event_type ) {
			// Check if this event type already has event_source structure (already migrated)
			if ( isset( $event_type['event_source'] ) && is_array( $event_type['event_source'] ) ) {
				continue;
			}

			// Check if this is a link-based event type (has css_selectors)
			if ( isset( $event_type['css_selectors'] ) && ! empty( $event_type['css_selectors'] ) ) {
				// Convert to new event_source structure
				$event_type['event_source'] = [
					'type' => 'link_click',
					'selector' => $event_type['css_selectors']
				];
			} else {
				// If no css_selectors exist, this might be a malformed or new event type
				// Set a default link_click structure to maintain consistency
				$event_type['event_source'] = [
					'type' => 'link_click',
					'selector' => ''
				];
			}
		}

		update_option( 'wego_traffic_source_event_types', $event_types );
		error_log( 'WeGo Traffic Source: v2 migration completed. Processed ' . count( $event_types ) . ' event types.' );
	}

	/**
	 * v3 Migration: Rename option from "event_types" to "tracked_events"
	 *
	 * Copies data from wego_traffic_source_event_types to wego_traffic_source_tracked_events
	 * and deletes the old option.
	 */
	private static function migrate_v3_rename_tracked_events() {
		$old_option = 'wego_traffic_source_event_types';
		$new_option = 'wego_traffic_source_tracked_events';

		$data = get_option( $old_option, [] );

		if ( ! empty( $data ) ) {
			update_option( $new_option, $data );
		}

		delete_option( $old_option );
		error_log( 'WeGo Traffic Source: v3 migration completed. Renamed event_types option to tracked_events.' );
	}

}
