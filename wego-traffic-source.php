<?php
/*
Plugin Name: WeGo Traffic Source
Description: Auto-fills traffic source fields (create a hidden field with the default value of wego-traffic-source) and logs all tel: link clicks
Version: 2.0.1
Requires at least: 6.5
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

		// Add custom columns to admin
		add_filter( 'manage_wego_tel_click_posts_columns', array( 'WeGo_Traffic_Source', 'add_custom_columns' ) );
		add_action( 'manage_wego_tel_click_posts_custom_column', array( 'WeGo_Traffic_Source', 'render_custom_columns' ), 10, 2 );
		add_filter( 'manage_edit-wego_tel_click_sortable_columns', array( 'WeGo_Traffic_Source', 'make_columns_sortable' ) );
		add_action( 'pre_get_posts', array( 'WeGo_Traffic_Source', 'handle_column_sorting' ) );
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
			'permission_callback' => '__return_true', // Allow unauthenticated requests
		) );
	}

	/**
	 * Handle tel click tracking
	 */
	public static function handle_tel_click( $request ) {
		$phone_number = sanitize_text_field( $request->get_param( 'phone_number' ) );
		$traffic_source = sanitize_text_field( $request->get_param( 'traffic_source' ) );

		// Create new post
		$post_id = wp_insert_post( array(
			'post_type' => 'wego_tel_click',
			'post_title' => $phone_number,
			'post_status' => 'publish',
			'meta_input' => array(
				'traffic_source' => $traffic_source,
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

	/**
	 * Add custom columns to Tel Clicks admin list
	 */
	public static function add_custom_columns( $columns ) {
		$new_columns = array();
		$new_columns['cb'] = $columns['cb'];
		$new_columns['title'] = __( 'Phone Number', 'wego-traffic-source' );
		$new_columns['click_date_time'] = __( 'Click Date/Time', 'wego-traffic-source' );
		$new_columns['traffic_source'] = __( 'Traffic Source', 'wego-traffic-source' );
		return $new_columns;
	}

	/**
	 * Render custom column content
	 */
	public static function render_custom_columns( $column, $post_id ) {
		if ( $column === 'click_date_time' ) {
			$post = get_post( $post_id );
			$date_time = wp_date( 'Y-m-d H:i:s', strtotime( $post->post_date ) );
			echo esc_html( $date_time );
		} elseif ( $column === 'traffic_source' ) {
			$traffic_source = get_post_meta( $post_id, 'traffic_source', true );
			echo esc_html( $traffic_source ? $traffic_source : '—' );
		}
	}

	/**
	 * Make columns sortable
	 */
	public static function make_columns_sortable( $columns ) {
		$columns['click_date_time'] = 'post_date';
		$columns['traffic_source'] = 'traffic_source';
		return $columns;
	}

	/**
	 * Handle column sorting
	 */
	public static function handle_column_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( $query->get( 'post_type' ) !== 'wego_tel_click' ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( $orderby === 'traffic_source' ) {
			$query->set( 'meta_key', 'traffic_source' );
			$query->set( 'orderby', 'meta_value' );
		}
	}
}

/**
 * Call init after all plugins have loaded
 */
add_action( 'plugins_loaded', array( 'WeGo_Traffic_Source', 'init' ) );
