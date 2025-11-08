<?php
/*
Plugin Name: WeGo Traffic Source
Description: Auto-fills any hidden form fields that have a default value of wego-traffic-source
Version: 1.0.1
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
	}

	/**
	 * Front end scripts
	 */
	public static function enqueue_scripts() {
		wp_enqueue_script( 'wego-traffic-source', self::$plugin_url . 'js/wego-traffic-source.js', array(), self::$plugin_version, true );

		// Load the javascript as an ES6 module.  Strict mode will used, variables will have file-level scope
		// execution is deferred (the full DOM is parsed and available at execution time)
		wp_script_add_data( 'wego-traffic-source', 'type', 'module' );
	}

}

/**
 * Call init after all plugins have loaded
 */
add_action( 'plugins_loaded', array( 'WeGo_Traffic_Source', 'init' ) );
