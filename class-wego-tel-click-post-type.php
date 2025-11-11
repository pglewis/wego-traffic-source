<?php
/**
 * Manages the Tel Click custom post type and its admin interface
 */
class WeGo_Tel_Click_Post_Type {

	/**
	 * Post type slug
	 */
	const POST_TYPE_SLUG = 'wego_tel_click';

	/**
	 * Column slug for click date/time
	 */
	const COLUMN_CLICK_DATE_TIME = 'click_date_time';

	/**
	 * Column slug for traffic source (also used as meta key)
	 */
	const COLUMN_TRAFFIC_SOURCE = 'traffic_source';

	/**
	 * Initialize the post type and admin functionality
	 */
	public static function init() {
		// Register custom post type
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		// Customize admin list table
		add_filter( 'manage_wego_tel_click_posts_columns', array( __CLASS__, 'add_custom_columns' ) );
		add_action( 'manage_wego_tel_click_posts_custom_column', array( __CLASS__, 'render_custom_columns' ), 10, 2 );
		add_filter( 'manage_edit-wego_tel_click_sortable_columns', array( __CLASS__, 'make_columns_sortable' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_admin_filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'handle_admin_list_query' ) );

		// Edit screen metabox
		add_action( 'add_meta_boxes_wego_tel_click', array( __CLASS__, 'add_traffic_source_metabox' ) );
		add_action( 'save_post_wego_tel_click', array( __CLASS__, 'validate_traffic_source_metabox' ) );
	}

	/**
	 * Register custom post type for tel click tracking
	 */
	public static function register_post_type() {
		register_post_type( self::POST_TYPE_SLUG, array(
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
	 * Add custom columns to Tel Clicks admin list
	 */
	public static function add_custom_columns( $columns ) {
		$new_columns = array();
		$new_columns['cb'] = $columns['cb'];
		$new_columns['title'] = __( 'Phone Number', 'wego-traffic-source' );
		$new_columns[ self::COLUMN_CLICK_DATE_TIME ] = __( 'Click Date/Time', 'wego-traffic-source' );
		$new_columns[ self::COLUMN_TRAFFIC_SOURCE ] = __( 'Traffic Source', 'wego-traffic-source' );
		return $new_columns;
	}

	/**
	 * Render custom column content
	 */
	public static function render_custom_columns( $column, $post_id ) {
		if ( $column === self::COLUMN_CLICK_DATE_TIME ) {
			$post = get_post( $post_id );
			$date_time = wp_date( 'Y-m-d H:i:s', strtotime( $post->post_date ) );
			echo esc_html( $date_time );
		} elseif ( $column === self::COLUMN_TRAFFIC_SOURCE ) {
			$traffic_source = get_post_meta( $post_id, self::COLUMN_TRAFFIC_SOURCE, true );
			echo esc_html( $traffic_source ? $traffic_source : '—' );
		}
	}

	/**
	 * Make columns sortable
	 */
	public static function make_columns_sortable( $columns ) {
		$columns[ self::COLUMN_CLICK_DATE_TIME ] = 'post_date';
		$columns[ self::COLUMN_TRAFFIC_SOURCE ] = self::COLUMN_TRAFFIC_SOURCE;
		return $columns;
	}

	/**
	 * Add Traffic Source filter dropdown to the admin list
	 */
	public static function add_admin_filter_dropdown( $post_type ) {
		if ( $post_type !== self::POST_TYPE_SLUG ) {
			return;
		}

		global $wpdb;
		$sources = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND meta_value <> ''
				ORDER BY meta_value ASC",
				self::COLUMN_TRAFFIC_SOURCE
			)
		);

		$current = isset( $_GET['traffic_source_filter'] )
			? sanitize_text_field( $_GET['traffic_source_filter'] )
			: '';

		echo '<select name="traffic_source_filter" style="max-width:200px;">';
		echo '<option value="">All Traffic Sources</option>';

		foreach ( $sources as $source ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $source ),
				selected( $current, $source, false ),
				esc_html( $source )
			);
		}

		echo '</select>';
	}

	/**
	 * Handle admin list query modifications for Tel Clicks:
	 * - Sets default sort to newest first (with URL redirect to show sort state in UI)
	 * - Enables traffic_source column sorting via meta_key
	 * - Filters results by selected traffic source
	 */
	public static function handle_admin_list_query( $query ) {
		global $pagenow;

		if ( ! is_admin() || ! $query->is_main_query() || $pagenow !== 'edit.php' ) {
			return;
		}

		$post_type = isset( $_GET['post_type'] ) ? $_GET['post_type'] : '';

		if ( $post_type !== self::POST_TYPE_SLUG ) {
			return;
		}

		// Handle traffic_source column sorting
		$orderby = $query->get( 'orderby' );
		if ( $orderby === self::COLUMN_TRAFFIC_SOURCE ) {
			$query->set( 'meta_key', self::COLUMN_TRAFFIC_SOURCE );
			$query->set( 'orderby', 'meta_value' );
		}

		// Default sort by date DESC if not manually set
		if ( ! isset( $_GET['orderby'] ) && ! isset( $_GET['order'] ) ) {
			// Redirect to same page with orderby parameters to make UI reflect sort state
			$redirect_url = add_query_arg( array(
				'post_type' => self::POST_TYPE_SLUG,
				'orderby' => 'post_date',
				'order' => 'desc',
			), admin_url( 'edit.php' ) );

			// Preserve traffic_source_filter if present
			if ( ! empty( $_GET['traffic_source_filter'] ) ) {
				$redirect_url = add_query_arg( 'traffic_source_filter', sanitize_text_field( $_GET['traffic_source_filter'] ), $redirect_url );
			}

			wp_redirect( $redirect_url );
			exit;
		}

		// Filter by traffic source if selected
		if ( ! empty( $_GET['traffic_source_filter'] ) ) {
			$query->set( 'meta_query', array(
				array(
					'key'   => self::COLUMN_TRAFFIC_SOURCE,
					'value' => sanitize_text_field( $_GET['traffic_source_filter'] ),
				),
			) );
		}
	}

	/**
	 * Add traffic source metabox to edit page
	 */
	public static function add_traffic_source_metabox() {
		add_meta_box(
			'wego_traffic_source_metabox',
			__( 'Traffic Source', 'wego-traffic-source' ),
			array( __CLASS__, 'render_traffic_source_metabox' ),
			self::POST_TYPE_SLUG,
			'normal',
			'high'
		);
	}

	/**
	 * Render traffic source metabox
	 */
	public static function render_traffic_source_metabox( $post ) {
		$traffic_source = get_post_meta( $post->ID, self::COLUMN_TRAFFIC_SOURCE, true );
		wp_nonce_field( 'wego_traffic_source_nonce', 'wego_traffic_source_nonce' );
		?>
		<p>
			<label for="wego_traffic_source"><?php esc_html_e( 'Traffic Source:', 'wego-traffic-source' ); ?></label>
			<input type="text" id="wego_traffic_source" name="wego_traffic_source" value="<?php echo esc_attr( $traffic_source ); ?>" style="width: 100%;" readonly>
		</p>
		<?php
	}

	/**
	 * Validate traffic source metabox save (prevents manual editing)
	 *
	 * This method is hooked to save_post but intentionally does not save anything.
	 * The traffic_source field is readonly and should only be set via the tracking API.
	 */
	public static function validate_traffic_source_metabox( $post_id ) {
		if ( ! isset( $_POST['wego_traffic_source_nonce'] ) || ! wp_verify_nonce( $_POST['wego_traffic_source_nonce'], 'wego_traffic_source_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Intentionally empty - traffic_source should only be set via the tracking API
	}

}
