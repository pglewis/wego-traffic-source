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

	/** Transient cache key prefix */
	const TRANSIENT_PREFIX = 'wego_updater_';

	/** Nonce actions */
	const NONCE_ACTION_CHECK_UPDATES = 'wego_check_updates';
	const NONCE_ACTION_DISMISS_NOTICE = 'wego_dismiss_updater_notice';

	/** Query parameters */
	const QUERY_PARAM_MANUAL_CHECK = 'wego_manual_check';
	const QUERY_PARAM_MANUAL_CHECK_COMPLETE = 'wego_manual_check_complete';

	/** Github URL templates */
	const GITHUB_REPO_URL = 'https://github.com/%s/%s';
	const GITHUB_LATEST_RELEASE_URL = 'https://github.com/%s/%s/releases/latest';
	const GITHUB_ISSUES_URL = 'https://github.com/%s/%s/issues';
	const GITHUB_API_URL = 'https://api.github.com/repos/%s/%s/releases/latest';

	/** Error codes */
	const ERROR_API_REQUEST_FAILED = 'api_request_failed';
	const ERROR_API_RATE_LIMIT = 'api_rate_limit';
	const ERROR_INVALID_RESPONSE = 'invalid_response';
	const ERROR_JSON_PARSE = 'json_parse_failed';
	const ERROR_RENAME_FAILED = 'rename_failed';
	const ERROR_PLUGIN_DATA = 'plugin_data_missing';

	/** Transient cache expirations */
	const CACHE_EXPIRATION_SUCCESS = 43200;  // 12 hours
	const CACHE_EXPIRATION_FAILURE = 3600;   // 1 hour
	const CACHE_EXPIRATION_ERROR_NOTICE = 3600;  // 1 hour

	/** @var string Plugin file path */
	private $plugin_file;

	/** @var string Plugin slug (directory/file.php) */
	private $plugin_slug;

	/** @var string  GitHub username/organization */
	private $github_username;

	/** @var string GitHub repository name */
	private $github_repo;

	/** @var string Current plugin version */
	private $current_version;

	/** Transient keys */
	private $transient_key_release_data;
	private $transient_key_error_data;
	private $transient_key_notice_dismissal;

	/** @var object|null Cached GitHub release data */
	private $cached_release_data = null;

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
		$this->transient_key_release_data = self::TRANSIENT_PREFIX . sanitize_key( $github_repo );
		$this->transient_key_error_data = self::TRANSIENT_PREFIX . 'error_' . $this->plugin_slug;
		$this->transient_key_notice_dismissal = self::TRANSIENT_PREFIX . 'dismissed_' . $this->plugin_slug;

		// Get current version from plugin header
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = get_plugin_data( $plugin_file );

		if ( empty( $plugin_data['Version'] ) ) {
			$this->log_error( self::ERROR_PLUGIN_DATA, 'Unable to retrieve plugin version from header', [ 'file' => $plugin_file ] );
			$this->current_version = '0.0.0';
		} else {
			$this->current_version = $plugin_data['Version'];
		}

		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Handle AJAX dismiss request (fires early on AJAX calls)
		add_action( 'wp_ajax_' . self::NONCE_ACTION_DISMISS_NOTICE, [ $this, 'handle_dismiss_notice' ] );

		// Handle manual update check (fires early in admin)
		add_action( 'admin_init', [ $this, 'handle_manual_update_check' ] );

		// Enqueue dismiss notice script (fires during admin page load)
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_dismiss_script' ] );

		// Display admin notices on plugins.php (fires during admin page render)
		add_action( 'admin_notices', [ $this, 'display_error_notice' ] );

		// Add "Check for Updates" link to plugin actions (fires when plugins list is rendered)
		add_filter( 'plugin_action_links_' . $this->plugin_slug, [ $this, 'add_check_updates_link' ] );

		// Check for updates (fires when WordPress checks for plugin updates)
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );

		// Plugin details popup (fires when user clicks "View details" on plugins page)
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );

		// Modify update source to use GitHub zip (fires during plugin update process)
		add_filter( 'upgrader_source_selection', [ $this, 'fix_directory_name' ], 10, 4 );
	}

	/**
	 * Log an error to error_log and store transient for admin notice
	 *
	 * @param string $error_code Error code constant.
	 * @param string $message Error message (technical, for logging and display).
	 * @param array  $context Additional context data.
	 * @param bool   $should_log Whether to actually log this error.
	 */
	private function log_error( $error_code, $message, $context = [], $should_log = true ) {
		if ( $should_log ) {
			$log_message = sprintf(
				'[%s] WeGo Plugin Updater Error (%s): %s | Repo: %s | Context: %s',
				current_time( 'Y-m-d H:i:s' ),
				$error_code,
				$message,
				$this->github_username . '/' . $this->github_repo,
				wp_json_encode( $context )
			);

			error_log( $log_message );
		}

		// Store error details in transient for admin notice
		$error_data = [
			'code' => $error_code,
			'message' => $message,
			'context' => $context,
			'timestamp' => current_time( 'Y-m-d H:i:s' ),
		];

		set_transient( $this->transient_key_error_data, $error_data, self::CACHE_EXPIRATION_ERROR_NOTICE );
	}

	/**
	 * Display error notice in WordPress admin
	 *
	 * Only shows on plugins.php to users with update_plugins capability.
	 * Displays detailed error information from transient.
	 */
	public function display_error_notice() {
		// Only show on plugins page
		if ( ! function_exists( 'get_current_screen' ) || 'plugins' !== get_current_screen()->base ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$error_data = get_transient( $this->transient_key_error_data );
		if ( ! $error_data || ! is_array( $error_data ) ) {
			return;
		}

		// Check if this error has been dismissed
		$dismissed_timestamp = get_transient( $this->transient_key_notice_dismissal ) ?: 0;
		$error_timestamp = isset( $error_data['timestamp'] ) ? strtotime( $error_data['timestamp'] ) : 0;

		// Don't show if dismissed after this error occurred
		if ( $dismissed_timestamp >= $error_timestamp ) {
			return;
		}

		$error_code = $error_data['code'] ?? null;
		$message = $error_data['message'] ?? __( 'An unknown error occurred with the plugin updater.', 'wego-traffic-source' );
		$context = $error_data['context'] ?? [];
		$timestamp = $error_data['timestamp'] ?? current_time( 'Y-m-d H:i:s' );

		$error_title_map = [
			self::ERROR_API_REQUEST_FAILED => __( 'GitHub API Connection Failed', 'wego-traffic-source' ),
			self::ERROR_API_RATE_LIMIT => __( 'GitHub API Rate Limited', 'wego-traffic-source' ),
			self::ERROR_INVALID_RESPONSE => __( 'Invalid GitHub API Response', 'wego-traffic-source' ),
			self::ERROR_JSON_PARSE => __( 'Failed to Parse API Response', 'wego-traffic-source' ),
			self::ERROR_RENAME_FAILED => __( 'Plugin Update Directory Rename Failed', 'wego-traffic-source' ),
			self::ERROR_PLUGIN_DATA => __( 'Failed to Read Plugin Metadata', 'wego-traffic-source' ),
		];

		$title = $error_title_map[ $error_code ] ?? __( 'Plugin Updater Error', 'wego-traffic-source' );

		?>
		<div class="notice notice-error is-dismissible wego-updater-notice" data-plugin-slug="<?= esc_attr( $this->plugin_slug ); ?>">
			<p>
				<strong><?= esc_html( __( 'WeGo Traffic Source Updater:', 'wego-traffic-source' ) ); ?></strong>
			</p>
			<p>
				<strong><?= esc_html( $title ); ?></strong><br>
				<?= esc_html( $message ); ?>
			</p>
			<?php if ( ! empty( $context ) ) : ?>
				<p>
					<small><?= esc_html( __( 'Details:', 'wego-traffic-source' ) ); ?> <?= esc_html( wp_json_encode( $context ) ); ?></small>
				</p>
			<?php endif; ?>
			<p>
				<small><?= esc_html( sprintf( __( 'Error logged at %s. Check your server error_log for complete details.', 'wego-traffic-source' ), $timestamp ) ); ?></small>
			</p>
		</div>
		<?php
	}

	/**
	 * Add "Check for Updates" link to plugin action links
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified links array.
	 */
	public function add_check_updates_link( $links ) {
		$check_url = wp_nonce_url(
			admin_url( 'plugins.php?' . self::QUERY_PARAM_MANUAL_CHECK . '=' . rawurlencode( $this->plugin_slug ) ),
			self::NONCE_ACTION_CHECK_UPDATES . '_' . $this->plugin_slug
		);

		$links['check_updates'] = '<a href="' . esc_url( $check_url ) . '">' . __( 'Check for Updates', 'wego-traffic-source' ) . '</a>';
		return $links;
	}

	/**
	 * Handle manual update check request
	 */
	public function handle_manual_update_check() {
		if ( ! isset( $_GET[ self::QUERY_PARAM_MANUAL_CHECK ] ) ) {
			return;
		}

		if ( $_GET[ self::QUERY_PARAM_MANUAL_CHECK ] !== $this->plugin_slug ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION_CHECK_UPDATES . '_' . $this->plugin_slug );

		// Clear the GitHub release cache
		delete_transient( $this->transient_key_release_data );

		// Clear WordPress plugin update transient to force fresh check
		delete_site_transient( 'update_plugins' );

		// Clear dismissal so errors show again after manual check
		delete_transient( $this->transient_key_notice_dismissal );

		// Redirect back to plugins page
		wp_safe_redirect( admin_url( 'plugins.php?' . self::QUERY_PARAM_MANUAL_CHECK_COMPLETE . '=1' ) );
		exit;
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

		// Re-read current version from file in case plugin was just updated
		// (avoids some situations where we detect the same update immediately after updating)
		$plugin_data = get_plugin_data( $this->plugin_file );
		$current_version = ! empty( $plugin_data['Version'] ) ? $plugin_data['Version'] : $this->current_version;

		// Build plugin info object for WordPress
		$plugin_info = (object) [
			'slug'         => dirname( $this->plugin_slug ),
			'plugin'       => $this->plugin_slug,
			'new_version'  => $github_version,
			'url'          => $release->html_url,
			'package'      => $release->zipball_url,
			'icons'        => [],
			'banners'      => [],
			'tested'       => '',
			'requires'     => '',
			'requires_php' => '',
		];

		if ( version_compare( $github_version, $current_version, '>' ) ) {
			// Update available
			$transient->response[ $this->plugin_slug ] = $plugin_info;
		} else {
			// No update available - remove any stale entry from response and add to no_update
			unset( $transient->response[ $this->plugin_slug ] );
			$plugin_info->new_version = $current_version;
			$transient->no_update[ $this->plugin_slug ] = $plugin_info;
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

		return (object) [
			'name'              => $plugin_data['Name'],
			'slug'              => dirname( $this->plugin_slug ),
			'version'           => $this->parse_version( $release->tag_name ),
			'author'            => $plugin_data['Author'],
			'homepage'          => $plugin_data['PluginURI'] ?: $release->html_url,
			'requires'          => $plugin_data['RequiresWP'],
			'tested'            => '',
			'downloaded'        => 0,
			'last_updated'      => $release->published_at,
			'sections'          => [
				'description'  => $plugin_data['Description'],
				'changelog'    => $this->format_release_notes( $release->body ),
				'repo_info'  => $this->get_repo_info_section(),
			],
			'download_link'     => $release->zipball_url,
			'banners'           => [],
		];
	}

	/**
	 * GitHub's zipball extracts a folder name in "repo-tag" format
	 * (e.g. wego-traffic-source-2.1.5).  This renames it to match the existing
	 * plugin directory name.
	 *
	 * During the upgrade process, WordPress:
	 *    - Downloads the zip file
	 *    - Extracts it to a temporary location
	 *    - Fires upgrader_source_selection filter ← You are here
	 *    - Moves the source to the final destination (wp-content/plugins/)
	 *    - Cleans up temp files
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

		$existing_plugin_directory = dirname( $this->plugin_slug );
		$new_source   = trailingslashit( $remote_source ) . $existing_plugin_directory . '/';

		// Nothing to do if source already matches the existing folder name
		if ( trailingslashit( $source ) === $new_source ) {
			return $source;
		}

		// Rename the extracted directory and we're done
		if ( $wp_filesystem->move( $source, $new_source ) ) {
			return $new_source;
		}

		$error_msg = 'Failed to move ' . $source . ' to ' . $new_source;
		$this->log_error( self::ERROR_RENAME_FAILED, $error_msg );

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
		if ( null !== $this->cached_release_data ) {
			return $this->cached_release_data;
		}

		// Check transient cache
		$cached = get_transient( $this->transient_key_release_data );
		if ( false !== $cached ) {
			$this->cached_release_data = $cached;
			// Don't log errors for cached failures
			return $this->cached_release_data;
		}

		// Track if we had a cached value before to determine if this is a new failure
		$had_cached_value = ( false !== get_transient( $this->transient_key_release_data ) );

		// Fetch from GitHub API
		$api_url = sprintf(
			self::GITHUB_API_URL,
			$this->github_username,
			$this->github_repo
		);

		$response = wp_remote_get( $api_url, [
			'headers' => [
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			$this->cached_release_data = false;
			$this->log_error(
				self::ERROR_API_REQUEST_FAILED,
				'wp_remote_get failed: ' . $response->get_error_message(),
				[ 'error_code' => $response->get_error_code() ],
				! $had_cached_value // Only log if this is a new failure
			);
			// Cache the failure to prevent hammering the API
			set_transient( $this->transient_key_release_data, false, self::CACHE_EXPIRATION_FAILURE );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$this->cached_release_data = false;
			$body = wp_remote_retrieve_body( $response );
			$response_data = json_decode( $body );
			$error_message = isset( $response_data->message ) ? $response_data->message : 'Unknown error';

			// Detect rate limit (429 or 403 with rate limit info)
			if ( 429 === $response_code || ( 403 === $response_code && false !== strpos( $error_message, 'rate' ) ) ) {
				$this->log_error(
					self::ERROR_API_RATE_LIMIT,
					'GitHub API rate limited. Status: ' . $response_code . ' | Message: ' . $error_message,
					[],
					! $had_cached_value
				);

				// Extend cache to back off during rate limit
				set_transient( $this->transient_key_release_data, false, self::CACHE_EXPIRATION_FAILURE );
			} else {
				$this->log_error(
					self::ERROR_INVALID_RESPONSE,
					'HTTP ' . $response_code . ': ' . $error_message,
					[],
					! $had_cached_value
				);

				// Cache other failures
				set_transient( $this->transient_key_release_data, false, self::CACHE_EXPIRATION_FAILURE );
			}

			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$release = json_decode( $body );

		if ( ! $release ) {
			$this->cached_release_data = false;
			$this->log_error(
				self::ERROR_JSON_PARSE,
				'Failed to parse JSON response',
				[ 'response_snippet' => substr( $body, 0, 200 ) ],
				! $had_cached_value
			);

			// Cache parse failures
			set_transient( $this->transient_key_release_data, false, self::CACHE_EXPIRATION_FAILURE );
			return false;
		}

		if ( ! isset( $release->tag_name ) ) {
			$this->cached_release_data = false;
			$this->log_error(
				self::ERROR_JSON_PARSE,
				'Response missing tag_name field',
				[ 'response_keys' => array_keys( (array) $release ) ],
				! $had_cached_value
			);

			// Cache parse failures
			set_transient( $this->transient_key_release_data, false, self::CACHE_EXPIRATION_FAILURE );
			return false;
		}

		// Cache successful response
		set_transient( $this->transient_key_release_data, $release, self::CACHE_EXPIRATION_SUCCESS );

		// Clear any previous error notice since we succeeded
		delete_transient( $this->transient_key_error_data );

		$this->cached_release_data = $release;
		return $this->cached_release_data;
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
	private function get_repo_info_section() {
		// prepare escaped pieces for clean template output (assign escaped directly)
		$escaped_repo_url           = esc_url( sprintf( self::GITHUB_REPO_URL, $this->github_username, $this->github_repo ) );
		$repo_label                 = esc_html( $this->github_username . '/' . $this->github_repo );
		$escaped_latest_release_url = esc_url( sprintf( self::GITHUB_LATEST_RELEASE_URL, $this->github_username, $this->github_repo ) );
		$escaped_issues_url         = esc_url( sprintf( self::GITHUB_ISSUES_URL, $this->github_username, $this->github_repo ) );

		// build HTML using output buffering and short-echo tags for compactness
		ob_start();
		?>
		<h3>Repository Information</h3>
		<ul>
			<li>
				<strong><a href="<?= $escaped_repo_url ?>" target="_blank" rel="noopener noreferrer">GitHub Repository</a></strong>
			</li>
			<li>
				<strong><a href="<?= $escaped_latest_release_url ?>" target="_blank" rel="noopener noreferrer">Latest Release</a></strong>
			</li>
			<li>
				<strong><a href="<?= $escaped_issues_url ?>" rel="noopener noreferrer">Issue Tracker</a></strong>
			</li>
		</ul>
		<p><em>Use the "Check for Updates" link on the Plugins page to force a fresh update check.</em></p>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueue JavaScript for handling notice dismissal
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_dismiss_script( $hook ) {
		// Only load on plugins page
		if ( 'plugins.php' !== $hook ) {
			return;
		}

		// Only load if user can update plugins
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// ES6 script to handle dismissal
		$nonce_action = self::NONCE_ACTION_DISMISS_NOTICE;
		$nonce = wp_create_nonce( self::NONCE_ACTION_DISMISS_NOTICE );

		$script = "
			document.addEventListener('click', (e) => {
				if (!e.target.classList.contains('notice-dismiss')) {
					return;
				}

				const notice = e.target.closest('.wego-updater-notice');
				if (!notice) {
					return;
				}

				const pluginSlug = notice.dataset.pluginSlug;

				fetch(ajaxurl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams({
						action: '$nonce_action',
						plugin_slug: pluginSlug,
						nonce: '$nonce'
					})
				});
			});
		";

		// We're piggy-backing on jquery's script handle just so we don't have
		// to register and enqueue our own external script.  We have no jquery
		// dependency, we're just keeping this a self-contained single class
		// file
		wp_add_inline_script( 'jquery', $script );
	}

	/**
	 * Handle AJAX request to dismiss error notice
	 */
	public function handle_dismiss_notice() {
		check_ajax_referer( self::NONCE_ACTION_DISMISS_NOTICE, 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
		}

		$plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_text_field( $_POST['plugin_slug'] ) : '';

		if ( empty( $plugin_slug ) || $plugin_slug !== $this->plugin_slug ) {
			wp_send_json_error( [ 'message' => 'Invalid plugin slug' ] );
		}

		// Store dismissal timestamp
		set_transient( $this->transient_key_notice_dismissal, time(), self::CACHE_EXPIRATION_ERROR_NOTICE );

		wp_send_json_success();
	}

}
