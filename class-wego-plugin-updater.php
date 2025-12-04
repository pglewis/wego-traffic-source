<?php
/**
 * GitHub Plugin Updater
 *
 * Handles automatic plugin updates from a GitHub repository.
 * Checks for new releases via the GitHub API and integrates with
 * the WordPress plugin update system.
 *
 * @package WeGo_Traffic_Source
 */

class WeGo_Plugin_Updater {

	/**
	 * Plugin file path
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin slug (directory/file.php)
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * GitHub username/organization
	 *
	 * @var string
	 */
	private $github_username;

	/**
	 * GitHub repository name
	 *
	 * @var string
	 */
	private $github_repo;

	/**
	 * Current plugin version
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Transient key for caching API responses
	 *
	 * @var string
	 */
	private $cache_key;

	/**
	 * Cache expiration in seconds (12 hours)
	 *
	 * @var int
	 */
	private $cache_expiration = 43200;

	/**
	 * Cached GitHub release data
	 *
	 * @var object|null
	 */
	private $github_release = null;

	/**
	 * Constructor
	 *
	 * @param string $plugin_file     Full path to the main plugin file.
	 * @param string $github_username GitHub username or organization.
	 * @param string $github_repo     GitHub repository name.
	 */
	public function __construct( $plugin_file, $github_username, $github_repo ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_slug     = plugin_basename( $plugin_file );
		$this->github_username = $github_username;
		$this->github_repo     = $github_repo;
		$this->cache_key       = 'wego_updater_' . sanitize_key( $github_repo );

		// Get current version from plugin header
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = get_plugin_data( $plugin_file );
		$this->current_version = $plugin_data['Version'];

		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Check for updates
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

		// Plugin details popup
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );

		// Modify update source to use GitHub zip
		add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );

		// Clear cache when checking for updates manually
		add_action( 'admin_init', array( $this, 'maybe_clear_cache' ) );
	}

	/**
	 * Check GitHub for a newer release
	 *
	 * @param object $transient WordPress update transient.
	 * @return object Modified transient with update info if available.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_github_release();

		if ( ! $release ) {
			return $transient;
		}

		$github_version = $this->parse_version( $release->tag_name );

		if ( version_compare( $github_version, $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_slug ] = (object) array(
				'slug'        => dirname( $this->plugin_slug ),
				'plugin'      => $this->plugin_slug,
				'new_version' => $github_version,
				'url'         => $release->html_url,
				'package'     => $release->zipball_url,
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => '',
				'requires'    => '',
			);
		}

		return $transient;
	}

	/**
	 * Display plugin information in the details popup
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object Plugin info or false.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( dirname( $this->plugin_slug ) !== $args->slug ) {
			return $result;
		}

		$release = $this->get_github_release();

		if ( ! $release ) {
			return $result;
		}

		$plugin_data = get_plugin_data( $this->plugin_file );

		return (object) array(
			'name'              => $plugin_data['Name'],
			'slug'              => dirname( $this->plugin_slug ),
			'version'           => $this->parse_version( $release->tag_name ),
			'author'            => $plugin_data['Author'],
			'homepage'          => $plugin_data['PluginURI'] ?: $release->html_url,
			'requires'          => $plugin_data['RequiresWP'],
			'tested'            => '',
			'downloaded'        => 0,
			'last_updated'      => $release->published_at,
			'sections'          => array(
				'description'  => $plugin_data['Description'],
				'changelog'    => $this->format_release_notes( $release->body ),
				'system_info'  => $this->get_system_info_section(),
			),
			'download_link'     => $release->zipball_url,
			'banners'           => array(),
		);
	}

	/**
	 * Fix the directory name after extraction
	 *
	 * GitHub's zipball extracts to "username-repo-hash" format.
	 * This renames it to match the expected plugin directory name.
	 *
	 * @param string      $source        Path to the extracted source.
	 * @param string      $remote_source Remote source path.
	 * @param WP_Upgrader $upgrader      WP_Upgrader instance.
	 * @param array       $hook_extra    Extra arguments.
	 * @return string|WP_Error Corrected source path or error.
	 */
	public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		// Only process our plugin
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
			return $source;
		}

		$expected_dir = dirname( $this->plugin_slug );
		$new_source   = trailingslashit( $remote_source ) . $expected_dir . '/';

		// If source already matches expected, nothing to do
		if ( trailingslashit( $source ) === $new_source ) {
			return $source;
		}

		// Rename the extracted directory
		if ( $wp_filesystem->move( $source, $new_source ) ) {
			return $new_source;
		}

		return new WP_Error(
			'rename_failed',
			__( 'Unable to rename the update directory.', 'wego-traffic-source' )
		);
	}

	/**
	 * Fetch the latest release from GitHub API
	 *
	 * Results are cached to avoid hitting API rate limits.
	 *
	 * @return object|false Release data or false on failure.
	 */
	private function get_github_release() {
		// Return cached instance data if already fetched this request
		if ( null !== $this->github_release ) {
			return $this->github_release;
		}

		// Check transient cache
		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			$this->github_release = $cached;
			return $this->github_release;
		}

		// Fetch from GitHub API
		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			$this->github_username,
			$this->github_repo
		);

		$response = wp_remote_get( $api_url, array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			$this->github_release = false;
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$this->github_release = false;
			// Don't cache failures - let them retry on next check
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$release = json_decode( $body );

		if ( ! $release || ! isset( $release->tag_name ) ) {
			$this->github_release = false;
			// Don't cache parse failures either
			return false;
		}

		// Cache successful response
		set_transient( $this->cache_key, $release, $this->cache_expiration );
		$this->github_release = $release;

		return $this->github_release;
	}

	/**
	 * Parse version from tag name
	 *
	 * Handles both "v1.0.0" and "1.0.0" formats.
	 *
	 * @param string $tag_name Git tag name.
	 * @return string Cleaned version string.
	 */
	private function parse_version( $tag_name ) {
		// Remove 'v' prefix if present
		return ltrim( $tag_name, 'v' );
	}

	/**
	 * Format GitHub release notes for WordPress display
	 *
	 * Converts Markdown to HTML for the plugin info popup.
	 *
	 * @param string $body Release notes body (Markdown).
	 * @return string Formatted HTML.
	 */
	private function format_release_notes( $body ) {
		if ( empty( $body ) ) {
			return '<p>' . __( 'No changelog available.', 'wego-traffic-source' ) . '</p>';
		}

		// Basic Markdown to HTML conversion
		$html = esc_html( $body );

		// Convert headers
		$html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
		$html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^# (.+)$/m', '<h2>$1</h2>', $html );

		// Convert bold and italic
		$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $html );

		// Convert bullet points to list items
		$html = preg_replace( '/^[\-\*] (.+)$/m', '<li>$1</li>', $html );

		// Wrap consecutive list items in ul tags
		$html = preg_replace( '/(<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', $html );

		// Convert line breaks to paragraphs for remaining text
		$html = '<p>' . preg_replace( '/\n\n+/', '</p><p>', $html ) . '</p>';

		// Clean up empty paragraphs
		$html = preg_replace( '/<p>\s*<\/p>/', '', $html );

		return $html;
	}

	/**
	 * Get system information section for plugin details popup
	 *
	 * @return string HTML content for system info section.
	 */
	private function get_system_info_section() {
		$cache_data = get_transient( $this->cache_key );
		$cache_status = $cache_data ? 'Cached' : 'Not cached';
		$repo_url = sprintf(
			'https://github.com/%s/%s',
			$this->github_username,
			$this->github_repo
		);

		$html = '<h3>System Information</h3>';
		$html .= '<ul>';
		$html .= '<li><strong>GitHub Repository:</strong> <a href="' . esc_url( $repo_url ) . '" target="_blank">' . esc_html( $this->github_username . '/' . $this->github_repo ) . '</a></li>';
		$html .= '<li><strong>Current Version:</strong> ' . esc_html( $this->current_version ) . '</li>';
		$html .= '<li><strong>Update Cache:</strong> ' . esc_html( $cache_status ) . '</li>';
		$html .= '<li><strong>Cache Duration:</strong> ' . esc_html( human_time_diff( 0, $this->cache_expiration ) ) . '</li>';
		$html .= '</ul>';
		$html .= '<p><em>To force a fresh update check, go to Dashboard &rarr; Updates and click "Check Again".</em></p>';

		return $html;
	}

	/**
	 * Clear the update cache when force-checking for updates
	 */
	public function maybe_clear_cache() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// WordPress adds force-check=1 when clicking "Check Again"
		if ( isset( $_GET['force-check'] ) && '1' === $_GET['force-check'] ) {
			delete_transient( $this->cache_key );
		}
	}

}
