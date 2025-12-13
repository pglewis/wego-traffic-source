<?php
/**
 * Manages dynamically registered event CPTs based on active event type configurations.
 * Each instance handles one event type (e.g., wego_schedule_clicks).
 */
class WeGo_Dynamic_Event_Post_Type {

	/**
	 * Column slugs
	 */
	const COLUMN_CLICK_DATE_TIME = 'click_date_time';

	/**
	 * Meta keys (also used as column slugs)
	 */
	const COLUMN_TRAFFIC_SOURCE = 'traffic_source';
	const COLUMN_DEVICE_TYPE = 'device_type';
	const COLUMN_PAGE_URL = 'page_url';
	const COLUMN_BROWSER_FAMILY = 'browser_family';
	const COLUMN_OS_FAMILY = 'os_family';

	/**
	 * Filter parameter names
	 */
	const TRAFFIC_SOURCE_FILTER_PARAM = 'traffic_source_filter';
	const DEVICE_TYPE_FILTER_PARAM = 'device_type_filter';
	const BROWSER_FAMILY_FILTER_PARAM = 'browser_family_filter';
	const OS_FAMILY_FILTER_PARAM = 'os_family_filter';

	/**
	 * Date range filter parameter names
	 */
	const DATE_FROM_PARAM = 'date_from';
	const DATE_TO_PARAM = 'date_to';

	/**
	 * Date/time display format
	 */
	const DATETIME_FORMAT = 'm/d/Y g:i a';

	/**
	 * Nonce name for metabox security
	 */
	const METABOX_NONCE = 'wego_dynamic_event_nonce';

	/**
	 * Registered instances keyed by post type slug
	 */
	private static $instances = [];

	/**
	 * Instance properties
	 */
	private $post_type_slug;
	private $event_type_config;
	private $export_action;

	/**
	 * Initialize all dynamic event CPTs from active event type configurations
	 */
	public static function init() {
		// Get active tracked events from settings (options load immediately, no timing issues)
		$active_tracked_events = WeGo_Tracked_Event_Settings::get_active_tracked_events();

		foreach ( $active_tracked_events as $tracked_event ) {
			$instance = new self( $tracked_event );
			self::$instances[ $instance->get_post_type_slug() ] = $instance;
		}
	}

	/**
	 * Get an instance by post type slug
	 */
	public static function get_instance( $post_type_slug ) {
		return isset( self::$instances[ $post_type_slug ] ) ? self::$instances[ $post_type_slug ] : null;
	}

	/**
	 * Get all registered post type slugs
	 */
	public static function get_registered_post_types() {
		return array_keys( self::$instances );
	}

	/**
	 * Constructor - sets up a single dynamic event CPT
	 */
	private function __construct( $event_type_config ) {
		$this->event_type_config = $event_type_config;
		$this->post_type_slug = 'wego_' . $event_type_config['slug'];
		$this->export_action = 'export_' . $this->post_type_slug . '_csv';

		// Register custom post type
		add_action( 'init', [ $this, 'register_post_type' ], 11 );

		// Admin assets
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Customize admin list table
		add_filter( 'manage_' . $this->post_type_slug . '_posts_columns', [ $this, 'manage_posts_columns' ] );
		add_action( 'manage_' . $this->post_type_slug . '_posts_custom_column', [ $this, 'manage_posts_custom_column' ], 10, 2 );
		add_filter( 'manage_edit-' . $this->post_type_slug . '_sortable_columns', [ $this, 'manage_edit_sortable_columns' ] );
		add_action( 'restrict_manage_posts', [ $this, 'add_admin_filter_dropdown' ] );
		add_action( 'pre_get_posts', [ $this, 'modify_admin_list_query' ] );

		// Edit screen metabox
		add_action( 'add_meta_boxes_' . $this->post_type_slug, [ $this, 'add_metaboxes' ] );
		add_action( 'save_post_' . $this->post_type_slug, [ $this, 'save_metabox' ] );

		// CSV Export
		add_action( 'admin_action_' . $this->export_action, [ $this, 'export_csv' ] );
		add_filter( 'views_edit-' . $this->post_type_slug, [ $this, 'add_export_button' ] );
	}

	/**
	 * Get the post type slug
	 */
	public function get_post_type_slug() {
		return $this->post_type_slug;
	}

	/**
	 * Get the event type configuration
	 */
	public function get_event_type_config() {
		return $this->event_type_config;
	}

	/**
	 * Enqueue admin styles for this post type's list screen
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post_type;

		// Only load on our post type's list screen
		if ( $hook !== 'edit.php' || $post_type !== $this->post_type_slug ) {
			return;
		}

		// Register and enqueue inline styles
		wp_register_style( 'wego-dynamic-event-admin-' . $this->post_type_slug, false );
		wp_enqueue_style( 'wego-dynamic-event-admin-' . $this->post_type_slug );
		wp_add_inline_style( 'wego-dynamic-event-admin-' . $this->post_type_slug, $this->get_admin_css() );
	}

	/**
	 * Get the inline CSS for this post type's admin list screen
	 */
	private function get_admin_css() {
		$selector = '.post-type-' . esc_attr( $this->post_type_slug );
		return "
			/* Flexbox for filters with wrapping and spacing */
			{$selector} .tablenav.top .actions {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				gap: 8px;
				margin-bottom: 8px;
			}

			/* Reset WordPress margins */
			{$selector} .tablenav.top .actions > * {
				margin: 0 !important;
			}

			/* Match date input height to selects */
			{$selector} .tablenav .actions input[type='date'] {
				height: 32px;
			}

			/* Force line break */
			{$selector} .tablenav .actions br {
				flex-basis: 100%;
				height: 0;
			}

			/* Keep date filters grouped */
			{$selector} .wego-date-filter-group {
				display: flex;
				align-items: center;
				gap: 8px;
				flex-wrap: nowrap;
			}
		";
	}

	/**
	 * Register the custom post type for this event type
	 */
	public function register_post_type() {
		$name = $this->event_type_config['name'];

		register_post_type(
			$this->post_type_slug,
			[
				'labels' => [
					'name'          => $name,
					'singular_name' => $name,
					'add_new_item'  => sprintf( __( 'Add New %s', 'wego-traffic-source' ), $name ),
					'edit_item'     => sprintf( __( 'Edit %s', 'wego-traffic-source' ), $name ),
					'new_item'      => sprintf( __( 'New %s', 'wego-traffic-source' ), $name ),
					'view_item'     => sprintf( __( 'View %s', 'wego-traffic-source' ), $name ),
					'search_items'  => sprintf( __( 'Search %s', 'wego-traffic-source' ), $name ),
					'not_found'     => sprintf( __( 'No %s found', 'wego-traffic-source' ), strtolower( $name ) ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => 'wego-tracking',
				'capability_type'   => 'post',
				'supports'          => [ 'title' ],
				'show_in_admin_bar' => false,
			]
		);

		// Remove the built-in month dropdown filter
		add_filter( 'disable_months_dropdown', [ $this, 'disable_months_dropdown' ], 10, 2 );
	}

	/**
	 * Disable the built-in month dropdown for this post type
	 */
	public function disable_months_dropdown( $disable, $post_type ) {
		if ( $post_type === $this->post_type_slug ) {
			return true;
		}
		return $disable;
	}

	/**
	 * Manage the admin list columns
	 */
	public function manage_posts_columns( $columns ) {
		$primary_label = $this->event_type_config['primary_value_label'];
		if ( empty( $primary_label ) ) {
			$primary_label = __( 'Primary Value', 'wego-traffic-source' );
		}

		$new_columns = [];
		$new_columns['cb'] = $columns['cb'];
		$new_columns['title'] = $primary_label;
		$new_columns[ self::COLUMN_PAGE_URL ] = __( 'Page URL', 'wego-traffic-source' );
		$new_columns[ self::COLUMN_TRAFFIC_SOURCE ] = __( 'Traffic Source', 'wego-traffic-source' );
		$new_columns[ self::COLUMN_DEVICE_TYPE ] = __( 'Device Type', 'wego-traffic-source' );
		$new_columns[ self::COLUMN_BROWSER_FAMILY ] = __( 'Browser', 'wego-traffic-source' );
		$new_columns[ self::COLUMN_OS_FAMILY ] = __( 'OS', 'wego-traffic-source' );
		$new_columns[ self::COLUMN_CLICK_DATE_TIME ] = __( 'Date/Time', 'wego-traffic-source' );
		return $new_columns;
	}

	/**
	 * Render custom column content
	 */
	public function manage_posts_custom_column( $column, $post_id ) {
		if ( $column === self::COLUMN_CLICK_DATE_TIME ) {
			$post = get_post( $post_id );
			// Use wp_date() with post timestamp for proper timezone handling
			$formatted_date_time = wp_date( self::DATETIME_FORMAT, get_post_datetime( $post )->getTimestamp() );
			echo esc_html( $formatted_date_time );
		} elseif ( $column === self::COLUMN_TRAFFIC_SOURCE ) {
			$traffic_source = get_post_meta( $post_id, self::COLUMN_TRAFFIC_SOURCE, true );
			echo esc_html( $traffic_source ? $traffic_source : '—' );
		} elseif ( $column === self::COLUMN_DEVICE_TYPE ) {
			$device_type = get_post_meta( $post_id, self::COLUMN_DEVICE_TYPE, true );
			echo esc_html( $device_type ? $device_type : '—' );
		} elseif ( $column === self::COLUMN_BROWSER_FAMILY ) {
			$browser_family = get_post_meta( $post_id, self::COLUMN_BROWSER_FAMILY, true );
			echo esc_html( $browser_family ? $browser_family : '—' );
		} elseif ( $column === self::COLUMN_OS_FAMILY ) {
			$os_family = get_post_meta( $post_id, self::COLUMN_OS_FAMILY, true );
			echo esc_html( $os_family ? $os_family : '—' );
		} elseif ( $column === self::COLUMN_PAGE_URL ) {
			$page_url = get_post_meta( $post_id, self::COLUMN_PAGE_URL, true );
			if ( $page_url ) {
				// Truncate long URLs for display
				$display_url = strlen( $page_url ) > 50 ? substr( $page_url, 0, 47 ) . '...' : $page_url;
				echo '<a href="' . esc_url( $page_url ) . '" target="_blank" title="' . esc_attr( $page_url ) . '">' . esc_html( $display_url ) . '</a>';
			} else {
				echo '—';
			}
		}
	}

	/**
	 * Make columns sortable
	 */
	public function manage_edit_sortable_columns( $columns ) {
		$columns[ self::COLUMN_CLICK_DATE_TIME ] = 'post_date';
		$columns[ self::COLUMN_TRAFFIC_SOURCE ] = self::COLUMN_TRAFFIC_SOURCE;
		$columns[ self::COLUMN_DEVICE_TYPE ] = self::COLUMN_DEVICE_TYPE;
		$columns[ self::COLUMN_BROWSER_FAMILY ] = self::COLUMN_BROWSER_FAMILY;
		$columns[ self::COLUMN_OS_FAMILY ] = self::COLUMN_OS_FAMILY;
		return $columns;
	}

	/**
	 * Add filter dropdowns to the admin list
	 */
	public function add_admin_filter_dropdown( $post_type ) {
		if ( $post_type !== $this->post_type_slug ) {
			return;
		}

		global $wpdb;

		// Traffic Source filter
		$traffic_sources = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = %s
				AND pm.meta_value <> ''
				AND p.post_type = %s
				ORDER BY pm.meta_value ASC",
				self::COLUMN_TRAFFIC_SOURCE,
				$this->post_type_slug
			)
		);

		$current_traffic_source = isset( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) )
			: '';

		echo '<select name="' . esc_attr( self::TRAFFIC_SOURCE_FILTER_PARAM ) . '" style="max-width:200px;">';
		echo '<option value="">' . esc_html__( 'All Traffic Sources', 'wego-traffic-source' ) . '</option>';

		foreach ( $traffic_sources as $traffic_source ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $traffic_source ),
				selected( $current_traffic_source, $traffic_source, false ),
				esc_html( $traffic_source )
			);
		}

		echo '</select>';

		// Device Type filter
		$device_types = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = %s
				AND pm.meta_value <> ''
				AND p.post_type = %s
				ORDER BY pm.meta_value ASC",
				self::COLUMN_DEVICE_TYPE,
				$this->post_type_slug
			)
		);

		$current_device_type = isset( $_GET[ self::DEVICE_TYPE_FILTER_PARAM ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::DEVICE_TYPE_FILTER_PARAM ] ) )
			: '';

		echo '<select name="' . esc_attr( self::DEVICE_TYPE_FILTER_PARAM ) . '" style="max-width:150px; margin-left: 5px;">';
		echo '<option value="">' . esc_html__( 'All Devices', 'wego-traffic-source' ) . '</option>';

		foreach ( $device_types as $device_type ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $device_type ),
				selected( $current_device_type, $device_type, false ),
				esc_html( $device_type )
			);
		}

		echo '</select>';

		// Browser Family filter
		$browser_families = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = %s
				AND pm.meta_value <> ''
				AND p.post_type = %s
				ORDER BY pm.meta_value ASC",
				self::COLUMN_BROWSER_FAMILY,
				$this->post_type_slug
			)
		);

		$current_browser_family = isset( $_GET[ self::BROWSER_FAMILY_FILTER_PARAM ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::BROWSER_FAMILY_FILTER_PARAM ] ) )
			: '';

		echo '<select name="' . esc_attr( self::BROWSER_FAMILY_FILTER_PARAM ) . '" style="max-width:150px; margin-left: 5px;">';
		echo '<option value="">' . esc_html__( 'All Browsers', 'wego-traffic-source' ) . '</option>';

		foreach ( $browser_families as $browser_family ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $browser_family ),
				selected( $current_browser_family, $browser_family, false ),
				esc_html( $browser_family )
			);
		}

		echo '</select>';

		// OS Family filter
		$os_families = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = %s
				AND pm.meta_value <> ''
				AND p.post_type = %s
				ORDER BY pm.meta_value ASC",
				self::COLUMN_OS_FAMILY,
				$this->post_type_slug
			)
		);

		$current_os_family = isset( $_GET[ self::OS_FAMILY_FILTER_PARAM ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::OS_FAMILY_FILTER_PARAM ] ) )
			: '';

		echo '<select name="' . esc_attr( self::OS_FAMILY_FILTER_PARAM ) . '" style="max-width:150px; margin-left: 5px;">';
		echo '<option value="">' . esc_html__( 'All OS', 'wego-traffic-source' ) . '</option>';

		foreach ( $os_families as $os_family ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $os_family ),
				selected( $current_os_family, $os_family, false ),
				esc_html( $os_family )
			);
		}

		echo '</select>';

		// Add date range filter inputs
		$date_from = isset( $_GET[ self::DATE_FROM_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::DATE_FROM_PARAM ] ) ) : '';
		$date_to = isset( $_GET[ self::DATE_TO_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::DATE_TO_PARAM ] ) ) : '';

		echo '<br>';
		echo '<div class="wego-date-filter-group">';
		echo '<label for="' . esc_attr( self::DATE_FROM_PARAM ) . '">' . esc_html__( 'From:', 'wego-traffic-source' ) . '</label>';
		echo '<input type="date" id="' . esc_attr( self::DATE_FROM_PARAM ) . '" name="' . esc_attr( self::DATE_FROM_PARAM ) . '" value="' . esc_attr( $date_from ) . '">';
		echo '<label for="' . esc_attr( self::DATE_TO_PARAM ) . '">' . esc_html__( 'To:', 'wego-traffic-source' ) . '</label>';
		echo '<input type="date" id="' . esc_attr( self::DATE_TO_PARAM ) . '" name="' . esc_attr( self::DATE_TO_PARAM ) . '" value="' . esc_attr( $date_to ) . '">';
		echo '</div>';

		// Add reset filters button
		$has_filters = ! empty( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) ||
			! empty( $_GET[ self::DEVICE_TYPE_FILTER_PARAM ] ) ||
			! empty( $_GET[ self::BROWSER_FAMILY_FILTER_PARAM ] ) ||
			! empty( $_GET[ self::OS_FAMILY_FILTER_PARAM ] ) ||
			! empty( $date_from ) ||
			! empty( $date_to );

		$reset_url = admin_url( 'edit.php' );
		$reset_url = add_query_arg( [
			'post_type' => $this->post_type_slug,
		], $reset_url );

		if ( $has_filters ) {
			echo '<a href="' . esc_url( $reset_url ) . '" class="button">' . esc_html__( 'Reset Filters', 'wego-traffic-source' ) . '</a>';
		} else {
			echo '<a href="#" class="button disabled" style="pointer-events: none; opacity: 0.5;" disabled>' . esc_html__( 'Reset Filters', 'wego-traffic-source' ) . '</a>';
		}
	}	/**
	 * Modify admin list query:
	 * - Sets default sort to newest first
	 * - Enables traffic_source column sorting
	 * - Filters results by selected traffic source and date range
	 */
	public function modify_admin_list_query( $query ) {
		global $pagenow;

		if ( ! is_admin() || ! $query->is_main_query() || $pagenow !== 'edit.php' ) {
			return;
		}

		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';

		if ( $post_type !== $this->post_type_slug ) {
			return;
		}

		// Handle meta column sorting
		$orderby = $query->get( 'orderby' );
		$meta_columns = [
			self::COLUMN_TRAFFIC_SOURCE,
			self::COLUMN_DEVICE_TYPE,
			self::COLUMN_BROWSER_FAMILY,
			self::COLUMN_OS_FAMILY,
		];

		if ( in_array( $orderby, $meta_columns, true ) ) {
			$query->set( 'meta_key', $orderby );
			$query->set( 'orderby', 'meta_value' );
		}

		// Default sort by date DESC if not manually set
		if ( ! isset( $_GET['orderby'] ) && ! isset( $_GET['order'] ) ) {
			$redirect_url = add_query_arg( [
				'orderby' => 'post_date',
				'order'   => 'desc',
			] );

			wp_redirect( $redirect_url );
			exit;
		}

		// Build meta_query for filters
		$meta_query = [];

		if ( ! empty( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) ) {
			$meta_query[] = [
				'key'   => self::COLUMN_TRAFFIC_SOURCE,
				'value' => sanitize_text_field( wp_unslash( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) ),
			];
		}

		if ( ! empty( $_GET[ self::DEVICE_TYPE_FILTER_PARAM ] ) ) {
			$meta_query[] = [
				'key'   => self::COLUMN_DEVICE_TYPE,
				'value' => sanitize_text_field( wp_unslash( $_GET[ self::DEVICE_TYPE_FILTER_PARAM ] ) ),
			];
		}

		if ( ! empty( $_GET[ self::BROWSER_FAMILY_FILTER_PARAM ] ) ) {
			$meta_query[] = [
				'key'   => self::COLUMN_BROWSER_FAMILY,
				'value' => sanitize_text_field( wp_unslash( $_GET[ self::BROWSER_FAMILY_FILTER_PARAM ] ) ),
			];
		}

		if ( ! empty( $_GET[ self::OS_FAMILY_FILTER_PARAM ] ) ) {
			$meta_query[] = [
				'key'   => self::COLUMN_OS_FAMILY,
				'value' => sanitize_text_field( wp_unslash( $_GET[ self::OS_FAMILY_FILTER_PARAM ] ) ),
			];
		}

		if ( ! empty( $meta_query ) ) {
			// Set relation to AND if there are multiple conditions
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}
			$query->set( 'meta_query', $meta_query );
		}

		// Filter by date range if selected
		$date_from = isset( $_GET[ self::DATE_FROM_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::DATE_FROM_PARAM ] ) ) : '';
		$date_to = isset( $_GET[ self::DATE_TO_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::DATE_TO_PARAM ] ) ) : '';

		if ( ! empty( $date_from ) || ! empty( $date_to ) ) {
			$date_query = [];

			if ( ! empty( $date_from ) ) {
				$date_query['after'] = $date_from;
			}

			if ( ! empty( $date_to ) ) {
				$date_query['before'] = $date_to . ' 23:59:59';
			}

			if ( ! empty( $date_query ) ) {
				$date_query['inclusive'] = true;
				$query->set( 'date_query', [ $date_query ] );
			}
		}
	}

	/**
	 * Add metabox to edit page
	 */
	public function add_metaboxes() {
		$name = $this->event_type_config['name'];

		add_meta_box(
			'wego_dynamic_event_details_metabox',
			sprintf( __( '%s Details', 'wego-traffic-source' ), $name ),
			[ $this, 'render_details_metabox' ],
			$this->post_type_slug,
			'normal',
			'high'
		);
	}

	/**
	 * Render details metabox
	 */
	public function render_details_metabox( $post ) {
		$traffic_source = get_post_meta( $post->ID, self::COLUMN_TRAFFIC_SOURCE, true );
		$device_type = get_post_meta( $post->ID, self::COLUMN_DEVICE_TYPE, true );
		$page_url = get_post_meta( $post->ID, self::COLUMN_PAGE_URL, true );
		$browser_family = get_post_meta( $post->ID, self::COLUMN_BROWSER_FAMILY, true );
		$os_family = get_post_meta( $post->ID, self::COLUMN_OS_FAMILY, true );
		// Use wp_date() for consistent timezone handling with admin list and CSV export
		$formatted_date_time = wp_date( self::DATETIME_FORMAT, get_post_datetime( $post )->getTimestamp() );
		wp_nonce_field( self::METABOX_NONCE, self::METABOX_NONCE );
		?>
		<p>
			<label for="wego_click_date_time"><?= esc_html__( 'Date/Time:', 'wego-traffic-source' ); ?></label>
			<input type="text" id="wego_click_date_time" name="wego_click_date_time" value="<?= esc_attr( $formatted_date_time ); ?>" style="width: 100%;" readonly>
		</p>
		<p>
			<label for="wego_traffic_source"><?= esc_html__( 'Traffic Source:', 'wego-traffic-source' ); ?></label>
			<input type="text" id="wego_traffic_source" name="wego_traffic_source" value="<?= esc_attr( $traffic_source ); ?>" style="width: 100%;" readonly>
		</p>
		<p>
			<label for="wego_device_type"><?= esc_html__( 'Device Type:', 'wego-traffic-source' ); ?></label>
			<input type="text" id="wego_device_type" name="wego_device_type" value="<?= esc_attr( $device_type ); ?>" style="width: 100%;" readonly>
		</p>
		<p>
			<label for="wego_browser_family"><?= esc_html__( 'Browser:', 'wego-traffic-source' ); ?></label>
			<input type="text" id="wego_browser_family" name="wego_browser_family" value="<?= esc_attr( $browser_family ); ?>" style="width: 100%;" readonly>
		</p>
		<p>
			<label for="wego_os_family"><?= esc_html__( 'Operating System:', 'wego-traffic-source' ); ?></label>
			<input type="text" id="wego_os_family" name="wego_os_family" value="<?= esc_attr( $os_family ); ?>" style="width: 100%;" readonly>
		</p>
		<p>
			<label for="wego_page_url"><?= esc_html__( 'Page URL:', 'wego-traffic-source' ); ?></label>
			<input type="text" id="wego_page_url" name="wego_page_url" value="<?= esc_attr( $page_url ); ?>" style="width: 100%;" readonly>
		</p>
		<?php
	}

	/**
	 * Save metabox - intentionally empty, data is set via tracking API
	 */
	public function save_metabox( $post_id ) {
		if ( ! isset( $_POST[ self::METABOX_NONCE ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::METABOX_NONCE ] ) ), self::METABOX_NONCE ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Intentionally empty - data should only be set via the tracking API
	}

	/**
	 * Add Export to CSV button above the posts table
	 */
	public function add_export_button( $views ) {
		$url = admin_url( 'edit.php' );
		$url = add_query_arg( [
			'post_type' => $this->post_type_slug,
			'action'    => $this->export_action,
		], $url );

		// Preserve current filters if set
		if ( ! empty( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) ) {
			$url = add_query_arg(
				self::TRAFFIC_SOURCE_FILTER_PARAM,
				sanitize_text_field( wp_unslash( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) ),
				$url
			);
		}

		if ( ! empty( $_GET[ self::DEVICE_TYPE_FILTER_PARAM ] ) ) {
			$url = add_query_arg(
				self::DEVICE_TYPE_FILTER_PARAM,
				sanitize_text_field( wp_unslash( $_GET[ self::DEVICE_TYPE_FILTER_PARAM ] ) ),
				$url
			);
		}

		if ( ! empty( $_GET[ self::BROWSER_FAMILY_FILTER_PARAM ] ) ) {
			$url = add_query_arg(
				self::BROWSER_FAMILY_FILTER_PARAM,
				sanitize_text_field( wp_unslash( $_GET[ self::BROWSER_FAMILY_FILTER_PARAM ] ) ),
				$url
			);
		}

		if ( ! empty( $_GET[ self::OS_FAMILY_FILTER_PARAM ] ) ) {
			$url = add_query_arg(
				self::OS_FAMILY_FILTER_PARAM,
				sanitize_text_field( wp_unslash( $_GET[ self::OS_FAMILY_FILTER_PARAM ] ) ),
				$url
			);
		}

		if ( ! empty( $_GET[ self::DATE_FROM_PARAM ] ) ) {
			$url = add_query_arg( self::DATE_FROM_PARAM, sanitize_text_field( wp_unslash( $_GET[ self::DATE_FROM_PARAM ] ) ), $url );
		}

		if ( ! empty( $_GET[ self::DATE_TO_PARAM ] ) ) {
			$url = add_query_arg( self::DATE_TO_PARAM, sanitize_text_field( wp_unslash( $_GET[ self::DATE_TO_PARAM ] ) ), $url );
		}

		$url = wp_nonce_url( $url, $this->export_action );

		echo '<div style="margin: 10px 0;">';
		echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="margin-left: 5px;">';
		echo esc_html__( 'Export to CSV', 'wego-traffic-source' );
		echo '</a>';
		echo '</div>';

		return $views;
	}

	/**
	 * Export events to CSV
	 */
	public function export_csv() {
		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $this->export_action ) ) {
			wp_die( __( 'Security check failed', 'wego-traffic-source' ) );
		}

		// Check user capabilities
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( __( 'You do not have permission to export data', 'wego-traffic-source' ) );
		}

		// Build query args
		$args = [
			'post_type'      => $this->post_type_slug,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		// Build meta_query for filters
		$meta_query = [];

		if ( ! empty( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) ) {
			$meta_query[] = [
				'key'   => self::COLUMN_TRAFFIC_SOURCE,
				'value' => sanitize_text_field( wp_unslash( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) ),
			];
		}

		if ( ! empty( $_GET[ self::DEVICE_TYPE_FILTER_PARAM ] ) ) {
			$meta_query[] = [
				'key'   => self::COLUMN_DEVICE_TYPE,
				'value' => sanitize_text_field( wp_unslash( $_GET[ self::DEVICE_TYPE_FILTER_PARAM ] ) ),
			];
		}

		if ( ! empty( $_GET[ self::BROWSER_FAMILY_FILTER_PARAM ] ) ) {
			$meta_query[] = [
				'key'   => self::COLUMN_BROWSER_FAMILY,
				'value' => sanitize_text_field( wp_unslash( $_GET[ self::BROWSER_FAMILY_FILTER_PARAM ] ) ),
			];
		}

		if ( ! empty( $_GET[ self::OS_FAMILY_FILTER_PARAM ] ) ) {
			$meta_query[] = [
				'key'   => self::COLUMN_OS_FAMILY,
				'value' => sanitize_text_field( wp_unslash( $_GET[ self::OS_FAMILY_FILTER_PARAM ] ) ),
			];
		}

		if ( ! empty( $meta_query ) ) {
			// Set relation to AND if there are multiple conditions
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}
			$args['meta_query'] = $meta_query;
		}

		// Apply date range filter if set
		$date_from = isset( $_GET[ self::DATE_FROM_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::DATE_FROM_PARAM ] ) ) : '';
		$date_to = isset( $_GET[ self::DATE_TO_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::DATE_TO_PARAM ] ) ) : '';

		if ( ! empty( $date_from ) || ! empty( $date_to ) ) {
			$date_query = [];

			if ( ! empty( $date_from ) ) {
				$date_query['after'] = $date_from;
			}

			if ( ! empty( $date_to ) ) {
				$date_query['before'] = $date_to . ' 23:59:59';
			}

			if ( ! empty( $date_query ) ) {
				$date_query['inclusive'] = true;
				$args['date_query'] = [ $date_query ];
			}
		}

		$query = new WP_Query( $args );

		// Set headers for CSV download
		$filename = sanitize_file_name( $this->event_type_config['slug'] ) . '-' . wp_date( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Open output stream
		$output = fopen( 'php://output', 'w' );

		// Add UTF-8 BOM for proper Excel compatibility
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		// Write CSV headers
		$primary_label = $this->event_type_config['primary_value_label'];
		if ( empty( $primary_label ) ) {
			$primary_label = __( 'Primary Value', 'wego-traffic-source' );
		}

		fputcsv( $output, [
			$primary_label,
			__( 'Page URL', 'wego-traffic-source' ),
			__( 'Traffic Source', 'wego-traffic-source' ),
			__( 'Device Type', 'wego-traffic-source' ),
			__( 'Browser', 'wego-traffic-source' ),
			__( 'Operating System', 'wego-traffic-source' ),
			__( 'Date/Time', 'wego-traffic-source' ),
		] );

		// Write data rows
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				$post = get_post( $post_id );

				$primary_value = get_the_title();
				// Use wp_date() with post timestamp for consistent timezone handling with admin list display
				$formatted_date_time = wp_date( self::DATETIME_FORMAT, get_post_datetime( $post )->getTimestamp() );
				$traffic_source = get_post_meta( $post_id, self::COLUMN_TRAFFIC_SOURCE, true );
				$device_type = get_post_meta( $post_id, self::COLUMN_DEVICE_TYPE, true );
				$browser_family = get_post_meta( $post_id, self::COLUMN_BROWSER_FAMILY, true );
				$os_family = get_post_meta( $post_id, self::COLUMN_OS_FAMILY, true );
				$page_url = get_post_meta( $post_id, self::COLUMN_PAGE_URL, true );

				fputcsv( $output, [
					$primary_value,
					$page_url ? $page_url : '',
					$traffic_source ? $traffic_source : '',
					$device_type ? $device_type : '',
					$browser_family ? $browser_family : '',
					$os_family ? $os_family : '',
					$formatted_date_time,
				] );
			}
		}

		wp_reset_postdata();
		fclose( $output );
		exit;
	}

}
