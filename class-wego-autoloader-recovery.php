<?php
/**
 * Recovery handler for missing autoload
 *
 * @package WeGo_Traffic_Source
 */

class WeGo_Autoloader_Recovery {
	/**
	 * Attempt recovery by downloading latest release
	 *
	 * @param string $plugin_file Full path to main plugin file.
	 * @param string $github_username GitHub username or organization.
	 * @param string $github_repo GitHub repository name.
	 */
	public static function attempt( $plugin_file, $github_username, $github_repo ) {
		global $wp_filesystem;

		// Initialize filesystem
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			$github_username,
			$github_repo
		);

		$response = wp_remote_get( $api_url, [
			'headers' => [
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[WeGo Traffic Source] Recovery failed: ' . $response->get_error_message() );
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		$release = json_decode( $body );

		if ( ! $release || empty( $release->assets[0]->browser_download_url ) ) {
			error_log( '[WeGo Traffic Source] Recovery failed: No release asset found' );
			return;
		}

		$download_url = $release->assets[0]->browser_download_url;
		$plugin_dir = dirname( $plugin_file );
		$temp_dir = get_temp_dir() . 'wego-traffic-source-recovery-' . time();
		$zip_file = $temp_dir . '/plugin.zip';

		// Create temp directory
		if ( ! $wp_filesystem->mkdir( $temp_dir ) ) {
			error_log( '[WeGo Traffic Source] Recovery failed: Could not create temp directory' );
			return;
		}

		// Download ZIP
		$zip_response = wp_remote_get( $download_url, [ 'timeout' => 30 ] );
		if ( is_wp_error( $zip_response ) ) {
			error_log( '[WeGo Traffic Source] Recovery failed: Could not download ZIP' );
			$wp_filesystem->delete( $temp_dir, true );
			return;
		}

		// Write ZIP to disk
		if ( ! $wp_filesystem->put_contents( $zip_file, wp_remote_retrieve_body( $zip_response ) ) ) {
			error_log( '[WeGo Traffic Source] Recovery failed: Could not write ZIP file' );
			$wp_filesystem->delete( $temp_dir, true );
			return;
		}

		// Extract ZIP
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$archive = new PclZip( $zip_file );
		$extract_result = $archive->extract( PCLZIP_OPT_PATH, $temp_dir );

		if ( ! $extract_result ) {
			error_log( '[WeGo Traffic Source] Recovery failed: Could not extract ZIP' );
			$wp_filesystem->delete( $temp_dir, true );
			return;
		}

		// Find extracted plugin directory
		$extracted_dir = $temp_dir . '/wego-traffic-source';
		if ( ! $wp_filesystem->is_dir( $extracted_dir ) ) {
			error_log( '[WeGo Traffic Source] Recovery failed: Extracted directory not found' );
			$wp_filesystem->delete( $temp_dir, true );
			return;
		}

		// Back up current installation
		$backup_dir = $plugin_dir . '-backup-' . time();
		if ( ! $wp_filesystem->move( $plugin_dir, $backup_dir ) ) {
			error_log( '[WeGo Traffic Source] Recovery failed: Could not backup current installation' );
			$wp_filesystem->delete( $temp_dir, true );
			return;
		}

		// Move extracted files to plugin directory
		if ( ! $wp_filesystem->move( $extracted_dir, $plugin_dir ) ) {
			error_log( '[WeGo Traffic Source] Recovery failed: Could not move extracted files' );
			$wp_filesystem->move( $backup_dir, $plugin_dir );
			$wp_filesystem->delete( $temp_dir, true );
			return;
		}

		// Cleanup
		$wp_filesystem->delete( $temp_dir, true );
		$wp_filesystem->delete( $backup_dir, true );

		error_log( '[WeGo Traffic Source] Recovery successful: Plugin recovered from latest release' );
	}
}
