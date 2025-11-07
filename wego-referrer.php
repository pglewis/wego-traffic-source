<?php
/*
Plugin Name: WeGo Referrer Auto-fill
Description: Auto-fill hidden referrer form fields
Version: 1.0.0
Author: WeGo Unlimited
License: GPLv2 or later
Text Domain: wego-referrer
Domain Path: /languages/
*/

class WeGo_Referrer {
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

		load_plugin_textdomain( 'wego-referrer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		// Scripts
		add_action( 'wp_enqueue_scripts', array( 'WeGo_Referrer', 'enqueue_scripts' ) );
	}

	/**
	 * Front end scripts
	 */
	public static function enqueue_scripts() {
		wp_enqueue_script( 'wego-referrer', self::$plugin_url . 'js/wego-referrer.js', array(), self::$plugin_version, true );
		wp_script_add_data( 'wego-referrer', 'type', 'module' );
	}

}

/**
 * Call init after all plugins have loaded
 */
add_action( 'plugins_loaded', array( 'WeGo_Referrer', 'init' ) );