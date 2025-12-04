<?php
/**
 * Database migrations for WeGo Traffic Source plugin
 *
 * Handles one-time migrations via a versioned approach. On plugin load,
 * checks current db version and runs any needed migrations sequentially.
 */

class WeGo_Migrations {
	const OPTION_NAME = 'wego_traffic_source_db_version';
	const MIGRATION_NOTICE_OPTION = 'wego_traffic_source_migration_notice_dismissed';

	/**
	 * Run pending migrations
	 */
	public static function run() {
		$current_version = (int) get_option( self::OPTION_NAME, 0 );

		if ( $current_version < 1 ) {
			self::migrate_v1_tel_clicks();
			update_option( self::OPTION_NAME, 1 );
		}

		// Future migrations:
		// if ( $current_version < 2 ) {
		//     self::migrate_v2_something();
		//     update_option( self::OPTION_NAME, 2 );
		// }
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
		$event_types = get_option( WeGo_Event_Type_Settings::OPTION_NAME, array() );

		// Check if tel_clicks event type already exists
		foreach ( $event_types as $event_type ) {
			if ( $event_type['slug'] === 'tel_clicks' ) {
				return; // Already exists, nothing to do
			}
		}

		// Add default Tel Clicks event type
		$event_types[] = array(
			'name'                => 'Tel Clicks',
			'slug'                => 'tel_clicks',
			'primary_value_label' => 'Phone Number',
			'css_selectors'       => 'a[href^="tel:"]',
			'active'              => true,
		);

		update_option( WeGo_Event_Type_Settings::OPTION_NAME, $event_types );
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
				array( 'post_type' => 'wego_tel_clicks' ),
				array( 'post_type' => 'wego_tel_click' )
			);

			// Set flag to show admin notice
			update_option( self::MIGRATION_NOTICE_OPTION, false );
		}
	}

	/**
	 * Initialize admin notice hooks
	 */
	public static function init_admin_notices() {
		add_action( 'admin_notices', array( __CLASS__, 'show_migration_notice' ) );
		add_action( 'wp_ajax_wego_dismiss_migration_notice', array( __CLASS__, 'dismiss_migration_notice' ) );
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
				<strong><?php esc_html_e( 'WeGo Traffic Source:', 'wego-traffic-source' ); ?></strong>
				<?php esc_html_e( 'Legacy tel click data has been migrated to the new event tracking system.', 'wego-traffic-source' ); ?>
			</p>
		</div>
		<script>
			jQuery(document).on('click', '.wego-migration-notice .notice-dismiss', function() {
				jQuery.post(ajaxurl, {
					action: 'wego_dismiss_migration_notice',
					_wpnonce: '<?php echo wp_create_nonce( 'wego_dismiss_migration_notice' ); ?>'
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
}
