<?php
/*
Plugin Name: WeGo Traffic Source
Description: Auto-fills traffic source form fields and tracks configurable click events (tel links, booking links, etc.)
Version: 2.3.2
Requires at least: 6.5
Requires PHP: 7.4
Author: WeGo Unlimited
Plugin URI: https://github.com/pglewis/wego-traffic-source/releases/latest
License: GPLv2 or later
Text Domain: wego-traffic-source
Domain Path: /languages/
*/

/**
 * Load Composer dependencies
 */
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Load recovery handler
 */
require_once __DIR__ . '/class-wego-autoloader-recovery.php';

/**
 * Load supporting classes
 */
require_once __DIR__ . '/class-wego-tracked-event-settings.php';
require_once __DIR__ . '/class-wego-dynamic-event-post-type.php';
require_once __DIR__ . '/class-wego-migrations.php';

/**
 * Load event source classes
 */
require_once __DIR__ . '/event-sources/class-wego-event-source-abstract.php';
require_once __DIR__ . '/event-sources/class-wego-event-source-registry.php';
require_once __DIR__ . '/event-sources/class-wego-event-source-link-click.php';
require_once __DIR__ . '/event-sources/class-wego-event-source-form-submit.php';
require_once __DIR__ . '/event-sources/class-wego-event-source-podium-widget.php';
require_once __DIR__ . '/event-sources/class-wego-event-source-youtube.php';

/**
 * Register event source types
 */
$wego_event_source_classes = [
	WeGo_Event_Source_Link_Click::class,
	WeGo_Event_Source_Form_Submit::class,
	WeGo_Event_Source_Podium_Widget::class,
	WeGo_Event_Source_YouTube::class,
];
foreach ( $wego_event_source_classes as $class ) {
	WeGo_Event_Source_Registry::register( $class );
}

/**
 * Run database migrations early, before plugin init
 */
add_action( 'plugins_loaded', [ 'WeGo_Migrations', 'run' ], 5 );

class WeGo_Traffic_Source {
	const REST_NAMESPACE = 'wego/v1';
	const REST_TRACK_EVENT_ROUTE = '/track-event';
	const GITHUB_USERNAME = 'pglewis';
	const GITHUB_REPO = 'wego-traffic-source';

	public static $plugin_url;
	public static $plugin_dir;
	public static $plugin_basename;
	public static $plugin_version;

	/**
	 * Plugin bootstrap
	 */
	public static function init() {
		$plugin_data = get_plugin_data( __FILE__ );

		self::$plugin_url = trailingslashit( plugin_dir_url( __FILE__ ) );
		self::$plugin_dir = trailingslashit( plugin_dir_path( __FILE__ ) );
		self::$plugin_basename = trailingslashit( dirname( plugin_basename( __FILE__ ) ) );
		self::$plugin_version = $plugin_data['Version'];

		load_plugin_textdomain( 'wego-traffic-source', false, self::$plugin_basename . 'languages/' );

		// Front end scripts
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_frontend_scripts' ] );

		// Output inline JSON config for dynamic event tracking
		add_action( 'wp_footer', [ __CLASS__, 'output_tracking_config' ] );

		// Register REST API endpoint
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );

		// Register admin menu on init (priority 9) so it exists before CPTs register
		// at priority 10 and try to add themselves as submenus
		add_action( 'init', [ __CLASS__, 'register_admin_menu' ], 9 );

		// Enqueue admin assets for Event Type Settings page
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );

		// Initialize admin (only in admin context)
		if ( is_admin() ) {
			WeGo_Tracked_Event_Settings::init();
			WeGo_Migrations::init_admin_notices();

			// Check for missing autoload and trigger recovery if needed
			if ( ! class_exists( 'WeGo_Plugin_Updater' ) ) {
				WeGo_Autoloader_Recovery::attempt( __FILE__, self::GITHUB_USERNAME, self::GITHUB_REPO );
			} else {
				// Initialize GitHub auto-updates
				new WeGo_Plugin_Updater( __FILE__, self::GITHUB_USERNAME, self::GITHUB_REPO );
			}
		}

		// Dynamic event CPTs need to register on init (reads from options, no timing issues)
		WeGo_Dynamic_Event_Post_Type::init();
	}

	/**
	 * Enqueue admin assets for Tracked Event Settings page
	 */
	public static function enqueue_admin_assets( $hook ) {
		// Only load on our settings page
		if ( $hook !== 'wego-tracking_page_' . WeGo_Tracked_Event_Settings::PAGE_SLUG ) {
			return;
		}

		wp_enqueue_style(
			'wego-tracked-events-admin',
			plugins_url( 'css/wego-tracked-events-admin.css', __FILE__ ),
			[],
			self::$plugin_version
		);

		wp_enqueue_script_module(
			'wego-tracked-events-admin',
			plugins_url( 'js/wego-tracked-events-admin.js', __FILE__ ),
			[],
			self::$plugin_version
		);
	}

	/**
	 * Enqueue frontend scripts
	 */
	public static function enqueue_frontend_scripts() {
		// Enqueue the script as an ES module (adds type="module"). Module scripts
		// run in strict mode and have module-level scope (top-level vars are not
		// globals). Module scripts are deferred by default and execute after the
		// document is parsed, so the DOM is available at execution time.
		wp_enqueue_script_module( 'wego-traffic-source', self::$plugin_url . 'js/wego-traffic-source.js', [], self::$plugin_version );
	}

	/**
	 * Register REST API routes
	 */
	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_TRACK_EVENT_ROUTE,
			[
				'methods' => 'POST',
				'callback' => [ __CLASS__, 'log_event' ],
				'permission_callback' => '__return_true', // Allow unauthenticated requests
			]
		);
	}

	/**
	 * Output inline JSON config for frontend event tracking
	 */
	public static function output_tracking_config() {
		$active_tracked_events = WeGo_Tracked_Event_Settings::get_active_tracked_events();

		// Build tracked events array for JSON output
		$tracked_events = [];
		foreach ( $active_tracked_events as $tracked_event ) {
			if ( ! empty( $tracked_event['event_source'] ) ) {
				$tracked_events[] = [
					'slug'        => $tracked_event['slug'],
					'eventSource' => $tracked_event['event_source'],
				];
			}
		}

		$config = [
			'endpoint'       => rest_url( self::REST_NAMESPACE . self::REST_TRACK_EVENT_ROUTE ),
			'trackedEvents'  => $tracked_events,
		];

		wp_print_inline_script_tag(
			wp_json_encode( $config ),
			[
				'type'  => 'application/json',
				'class' => 'wego-tracking-config',
			]
		);
	}

	/**
	 * Log dynamic event with traffic source tracking
	 *
	 * Note: REST API parameters use snake_case (not camelCase). This is standard REST API
	 * best practice, matching WordPress conventions. The client (JavaScript) uses camelCase
	 * internally and converts to snake_case when building the payload.
	 */
	public static function log_event( $request ) {
		$event_type = sanitize_key( $request->get_param( 'event_type' ) );
		$primary_value = sanitize_text_field( $request->get_param( 'primary_value' ) );
		$traffic_source = sanitize_text_field( $request->get_param( 'traffic_source' ) );
		$device_type = sanitize_text_field( $request->get_param( 'device_type' ) );
		$page_url = esc_url_raw( $request->get_param( 'page_url' ) );
		$browser_family = sanitize_text_field( $request->get_param( 'browser_family' ) );
		$os_family = sanitize_text_field( $request->get_param( 'os_family' ) );
		$event_source_data = $request->get_param( 'event_source_data' );

		// Validate required fields
		if ( empty( $event_type ) || empty( $primary_value ) ) {
			return new WP_Error(
				'missing_required_fields',
				__( 'Missing required fields: event_type and primary_value', 'wego-traffic-source' ),
				[ 'status' => 400 ]
			);
		}

		// Validate event_type slug exists and is active
		$active_tracked_events = WeGo_Tracked_Event_Settings::get_active_tracked_events();
		$is_valid_event_type = false;

		foreach ( $active_tracked_events as $tracked_event ) {
			if ( $tracked_event['slug'] === $event_type ) {
				$is_valid_event_type = true;
				break;
			}
		}

		if ( ! $is_valid_event_type ) {
			return new WP_Error(
				'invalid_event_type',
				__( 'Invalid or inactive event type', 'wego-traffic-source' ),
				[ 'status' => 400 ]
			);
		}

		// Build the CPT slug (wego_ prefix + event slug)
		$post_type = 'wego_' . $event_type;

		// Build meta_input array
		$meta_input = [
			WeGo_Dynamic_Event_Post_Type::COLUMN_TRAFFIC_SOURCE => $traffic_source,
			WeGo_Dynamic_Event_Post_Type::COLUMN_DEVICE_TYPE    => $device_type,
			WeGo_Dynamic_Event_Post_Type::COLUMN_PAGE_URL       => $page_url,
			WeGo_Dynamic_Event_Post_Type::COLUMN_BROWSER_FAMILY => $browser_family,
			WeGo_Dynamic_Event_Post_Type::COLUMN_OS_FAMILY      => $os_family,
		];

		// Add event_source_data if provided
		if ( ! empty( $event_source_data ) ) {
			$meta_input[ WeGo_Dynamic_Event_Post_Type::COLUMN_EVENT_SOURCE_DATA ] = $event_source_data;
		}

		// Create new post
		$post_id = wp_insert_post( [
			'post_type'   => $post_type,
			'post_title'  => $primary_value,
			'post_status' => 'publish',
			'meta_input'  => $meta_input,
		] );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'insert_failed',
				__( 'Failed to save event', 'wego-traffic-source' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( [
			'success' => true,
			'post_id' => $post_id,
		] );
	}

	/**
	 * Register the top-level admin menu for WeGo Tracking
	 */
	public static function register_admin_menu() {
		add_menu_page(
			__( 'WeGo Tracking', 'wego-traffic-source' ),
			__( 'WeGo Tracking', 'wego-traffic-source' ),
			'edit_posts',
			'wego-tracking',
			'', // No callback - submenus are registered separately
			'dashicons-analytics',
			58.8
		);

		// Remove the auto-generated duplicate submenu that matches the parent
		add_action( 'admin_menu', [ __CLASS__, 'remove_duplicate_submenu' ], 99 );
	}

	/**
	 * Remove the auto-generated "WeGo Tracking" submenu item
	 */
	public static function remove_duplicate_submenu() {
		remove_submenu_page( 'wego-tracking', 'wego-tracking' );
	}

}

/**
 * Call init after all plugins have loaded
 */
add_action( 'plugins_loaded', [ 'WeGo_Traffic_Source', 'init' ] );
