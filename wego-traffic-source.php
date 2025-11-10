<?php
/*
Plugin Name: WeGo Traffic Source
Description: Auto-fills any hidden form fields that have a default value of wego-traffic-source
Version: 2.0.0
Author: WeGo Unlimited
License: GPLv2 or later
Text Domain: wego-traffic-source
Domain Path: /languages/
*/

class WeGo_Traffic_Source {
	static $plugin_url;
 	static $plugin_dir;
 	static $plugin_version;

	/**
	 * Plugin bootstrap
	 */
	public static function init() {
		$plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );

		self::$plugin_url = trailingslashit( plugin_dir_url( __FILE__ ) );
		self::$plugin_dir = trailingslashit( plugin_dir_path( __FILE__ ) );
		self::$plugin_version = $plugin_data['Version'];

		load_plugin_textdomain( 'wego-traffic-source', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		// Scripts
		add_action( 'wp_enqueue_scripts', array( 'WeGo_Traffic_Source', 'enqueue_scripts' ) );

		// Register custom post type
		add_action( 'init', array( 'WeGo_Traffic_Source', 'register_post_type' ) );

		// Register REST API endpoint
		add_action( 'rest_api_init', array( 'WeGo_Traffic_Source', 'register_rest_routes' ) );
	}

	/**
	 * Front end scripts
	 */
	public static function enqueue_scripts() {
		// Enqueue the script as an ES module (adds type="module"). Module scripts
		// run in strict mode and have module-level scope (top-level vars are not
		// globals). Module scripts are deferred by default and execute after the
		// document is parsed, so the DOM is available at execution time.
		wp_enqueue_script_module( 'wego-traffic-source', self::$plugin_url . 'js/wego-traffic-source.js', array(), self::$plugin_version );
	}

	/**
	 * Register custom post type for tel click tracking
	 */
	public static function register_post_type() {
		register_post_type( 'wego_tel_click', array(
			'labels' => array(
				'name' => __( 'Tel Clicks', 'wego-traffic-source' ),
				'singular_name' => __( 'Tel Click', 'wego-traffic-source' ),
			),
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'capability_type' => 'post',
			'supports' => array( 'title' ),
			'menu_icon' => 'dashicons-phone',
			'menu_position' => 58,
		) );
	}

	/**
	 * Register REST API routes
	 */
	public static function register_rest_routes() {
		register_rest_route( 'wego/v1', '/track-tel-click', array(
			'methods' => 'POST',
			'callback' => array( 'WeGo_Traffic_Source', 'handle_tel_click' ),
			'permission_callback' => '__return_true', // Allow unauthenticated requests for now
		) );
	}

	/**
	 * Handle tel click tracking
	 */
	public static function handle_tel_click( $request ) {
		$phone_number = sanitize_text_field( $request->get_param( 'phone_number' ) );
		$traffic_source = sanitize_text_field( $request->get_param( 'traffic_source' ) );
		$timestamp = sanitize_text_field( $request->get_param( 'timestamp' ) );

		// Create new post
		$post_id = wp_insert_post( array(
			'post_type' => 'wego_tel_click',
			'post_title' => $phone_number,
			'post_status' => 'publish',
			'meta_input' => array(
				'traffic_source' => $traffic_source,
				'click_timestamp' => $timestamp,
			),
		) );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'insert_failed', __( 'Failed to save tel click', 'wego-traffic-source' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'post_id' => $post_id,
		) );
	}
}

/**
 * Call init after all plugins have loaded
 */
add_action( 'plugins_loaded', array( 'WeGo_Traffic_Source', 'init' ) );
