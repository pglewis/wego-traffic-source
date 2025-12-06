<?php
/*
Plugin Name: WeGo Traffic Source
Description: Auto-fills traffic source form fields and tracks configurable click events (tel links, booking links, etc.)
Version: 2.1.8
Requires at least: 6.5
Author: WeGo Unlimited
License: GPLv2 or later
Text Domain: wego-traffic-source
Domain Path: /languages/
*/

// GitHub updater settings: define constants for username and repo
define( 'WEGO_GITHUB_USERNAME', 'pglewis' );
define( 'WEGO_GITHUB_REPO', 'wego-traffic-source' );

/**
 * Load supporting classes
 */
require_once __DIR__ . '/class-wego-event-type-settings.php';
require_once __DIR__ . '/class-wego-dynamic-event-post-type.php';
require_once __DIR__ . '/class-wego-migrations.php';
require_once __DIR__ . '/class-wego-plugin-updater.php';

/**
 * Run database migrations early, before plugin init
 */
add_action( 'plugins_loaded', array( 'WeGo_Migrations', 'run' ), 5 );

class WeGo_Traffic_Source {
	const REST_NAMESPACE = 'wego/v1';
	const REST_TRACK_EVENT_ROUTE = '/track-event';

	public static $plugin_url;
	public static $plugin_dir;
	public static $plugin_basename;
	public static $plugin_version;
	public static $plugin_text_domain;

	/**
	 * Plugin bootstrap
	 */
	public static function init() {
		$plugin_data = get_plugin_data( __FILE__ );

		self::$plugin_url = trailingslashit( plugin_dir_url( __FILE__ ) );
		self::$plugin_dir = trailingslashit( plugin_dir_path( __FILE__ ) );
		self::$plugin_basename = trailingslashit( dirname( plugin_basename( __FILE__ ) ) );
		self::$plugin_version = $plugin_data['Version'];
		self::$plugin_text_domain = $plugin_data['TextDomain'];

		load_plugin_textdomain( self::$plugin_text_domain, false, self::$plugin_basename . 'languages/' );

		// Front end scripts
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_scripts' ) );

		// Output inline JSON config for dynamic event tracking
		add_action( 'wp_footer', array( __CLASS__, 'output_tracking_config' ) );

		// Register REST API endpoint
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Register admin menu on init (priority 9) so it exists before CPTs register
		// at priority 10 and try to add themselves as submenus
		add_action( 'init', array( __CLASS__, 'register_admin_menu' ), 9 );

		// Initialize admin (only in admin context)
		if ( is_admin() ) {
			WeGo_Event_Type_Settings::init( self::$plugin_text_domain );
			WeGo_Migrations::init_admin_notices();

			// Initialize GitHub auto-updates
			new WeGo_Plugin_Updater( __FILE__, WEGO_GITHUB_USERNAME, WEGO_GITHUB_REPO );
		}

		// Dynamic event CPTs need to register on init (reads from options, no timing issues)
		WeGo_Dynamic_Event_Post_Type::init( self::$plugin_text_domain );
	}

	/**
	 * Enqueue frontend scripts
	 */
	public static function enqueue_frontend_scripts() {
		// Enqueue the script as an ES module (adds type="module"). Module scripts
		// run in strict mode and have module-level scope (top-level vars are not
		// globals). Module scripts are deferred by default and execute after the
		// document is parsed, so the DOM is available at execution time.
		wp_enqueue_script_module( 'wego-traffic-source', self::$plugin_url . 'js/wego-traffic-source.js', array(), self::$plugin_version );
	}

	/**
	 * Register REST API routes
	 */
	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_TRACK_EVENT_ROUTE,
			array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'log_event' ),
				'permission_callback' => '__return_true', // Allow unauthenticated requests
			)
		);
	}

	/**
	 * Output inline JSON config for frontend event tracking
	 */
	public static function output_tracking_config() {
		$active_event_types = WeGo_Event_Type_Settings::get_active_event_types();

		// Build event types array for JSON output
		$event_types = array();
		foreach ( $active_event_types as $event_type ) {
			if ( ! empty( $event_type['css_selectors'] ) ) {
				$event_types[] = array(
					'slug'     => $event_type['slug'],
					'selector' => $event_type['css_selectors'],
				);
			}
		}

		$config = array(
			'endpoint'   => rest_url( self::REST_NAMESPACE . self::REST_TRACK_EVENT_ROUTE ),
			'eventTypes' => $event_types,
		);

		wp_print_inline_script_tag(
			wp_json_encode( $config ),
			array(
				'type'  => 'application/json',
				'class' => 'wego-tracking-config',
			)
		);
	}

	/**
	 * Log dynamic event with traffic source tracking
	 */
	public static function log_event( $request ) {
		$event_type = sanitize_key( $request->get_param( 'event_type' ) );
		$primary_value = sanitize_text_field( $request->get_param( 'primary_value' ) );
		$traffic_source = sanitize_text_field( $request->get_param( 'traffic_source' ) );
		$device_type = sanitize_text_field( $request->get_param( 'device_type' ) );
		$page_url = esc_url_raw( $request->get_param( 'page_url' ) );
		$browser_family = sanitize_text_field( $request->get_param( 'browser_family' ) );
		$os_family = sanitize_text_field( $request->get_param( 'os_family' ) );

		// Validate required fields
		if ( empty( $event_type ) || empty( $primary_value ) ) {
			return new WP_Error(
				'missing_required_fields',
				__( 'Missing required fields: event_type and primary_value', 'wego-traffic-source' ),
				array( 'status' => 400 )
			);
		}

		// Validate event_type exists and is active
		$active_event_types = WeGo_Event_Type_Settings::get_active_event_types();
		$is_valid_event_type = false;

		foreach ( $active_event_types as $et ) {
			if ( $et['slug'] === $event_type ) {
				$is_valid_event_type = true;
				break;
			}
		}

		if ( ! $is_valid_event_type ) {
			return new WP_Error(
				'invalid_event_type',
				__( 'Invalid or inactive event type', 'wego-traffic-source' ),
				array( 'status' => 400 )
			);
		}

		// Build the CPT slug (wego_ prefix + event slug)
		$post_type = 'wego_' . $event_type;

		// Create new post
		$post_id = wp_insert_post( array(
			'post_type'   => $post_type,
			'post_title'  => $primary_value,
			'post_status' => 'publish',
			'meta_input'  => array(
				WeGo_Dynamic_Event_Post_Type::COLUMN_TRAFFIC_SOURCE => $traffic_source,
				WeGo_Dynamic_Event_Post_Type::COLUMN_DEVICE_TYPE    => $device_type,
				WeGo_Dynamic_Event_Post_Type::COLUMN_PAGE_URL       => $page_url,
				WeGo_Dynamic_Event_Post_Type::COLUMN_BROWSER_FAMILY => $browser_family,
				WeGo_Dynamic_Event_Post_Type::COLUMN_OS_FAMILY      => $os_family,
			),
		) );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'insert_failed',
				__( 'Failed to save event', 'wego-traffic-source' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			'post_id' => $post_id,
		) );
	}

	/**
	 * Register the top-level admin menu for WeGo Tracking
	 */
	public static function register_admin_menu() {
		// Register parent menu. The first submenu item added (Event Types CPT)
		// will become the default landing page when clicking the parent.
		add_menu_page(
			__( 'WeGo Tracking', self::$plugin_text_domain ),
			__( 'WeGo Tracking', self::$plugin_text_domain ),
			'edit_posts',
			'wego-tracking',
			'', // No callback - first submenu becomes the landing page
			'dashicons-analytics',
			58.8
		);

		// Remove the auto-generated duplicate submenu that matches the parent
		add_action( 'admin_menu', array( __CLASS__, 'remove_duplicate_submenu' ), 99 );
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
add_action( 'plugins_loaded', array( 'WeGo_Traffic_Source', 'init' ) );
