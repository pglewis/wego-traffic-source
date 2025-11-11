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
	 * Filter parameter name for traffic source filtering
	 */
	const TRAFFIC_SOURCE_FILTER_PARAM = 'traffic_source_filter';

	/**
	 * Date/time display format
	 */
	const DATETIME_FORMAT = 'Y-m-d g:i a';

	/**
	 * Nonce name for metabox security
	 */
	const METABOX_NONCE = 'wego_traffic_source_nonce';

	/**
	 * Text domain for translations
	 */
	private static $text_domain;

	/**
	 * Initialize the post type and admin functionality
	 */
	public static function init( $text_domain ) {
		self::$text_domain = $text_domain;

		// Register custom post type
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		// Customize admin list table
		add_filter( 'manage_wego_tel_click_posts_columns', array( __CLASS__, 'manage_posts_columns' ) );
		add_action( 'manage_wego_tel_click_posts_custom_column', array( __CLASS__, 'manage_posts_custom_column' ), 10, 2 );
		add_filter( 'manage_edit-wego_tel_click_sortable_columns', array( __CLASS__, 'manage_edit_sortable_columns' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_admin_filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'modify_admin_list_query' ) );

		// Edit screen metabox
		add_action( 'add_meta_boxes_wego_tel_click', array( __CLASS__, 'add_metaboxes' ) );
		add_action( 'save_post_wego_tel_click', array( __CLASS__, 'save_metabox' ) );
	}

	/**
	 * Register the custom post type for tel link click tracking
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE_SLUG,
			array(
				'labels' => array(
					'name' => __( 'Tel Clicks', self::$text_domain ),
					'singular_name' => __( 'Tel Click', self::$text_domain ),
				),
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => true,
				'capability_type' => 'post',
				'supports' => array( 'title' ),
				'menu_icon' => 'dashicons-phone',
				'menu_position' => 58.8,
			)
		);
	}

	/**
	 * Manage the admin list columns
	 */
	public static function manage_posts_columns( $columns ) {
		$new_columns = array();
		$new_columns['cb'] = $columns['cb'];
		$new_columns['title'] = __( 'Phone Number', self::$text_domain );
		$new_columns[ self::COLUMN_CLICK_DATE_TIME ] = __( 'Click Date/Time', self::$text_domain );
		$new_columns[ self::COLUMN_TRAFFIC_SOURCE ] = __( 'Traffic Source', self::$text_domain );
		return $new_columns;
	}

	/**
	 * Render custom column content
	 */
	public static function manage_posts_custom_column( $column, $post_id ) {
		if ( $column === self::COLUMN_CLICK_DATE_TIME ) {
			$post = get_post( $post_id );
			$formatted_date_time = get_post_datetime( $post )->format( self::DATETIME_FORMAT );
			echo esc_html( $formatted_date_time );
		} elseif ( $column === self::COLUMN_TRAFFIC_SOURCE ) {
			$traffic_source = get_post_meta( $post_id, self::COLUMN_TRAFFIC_SOURCE, true );
			echo esc_html( $traffic_source ? $traffic_source : '—' );
		}
	}

	/**
	 * Make columns sortable
	 */
	public static function manage_edit_sortable_columns( $columns ) {
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

		/**
		 * Get an array of available distinct values
		 */
		global $wpdb;
		$traffic_sources = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND meta_value <> ''
				ORDER BY meta_value ASC",
				self::COLUMN_TRAFFIC_SOURCE
			)
		);

		$current = isset( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] )
			? sanitize_text_field( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] )
			: '';

		echo '<select name="' . esc_attr( self::TRAFFIC_SOURCE_FILTER_PARAM ) . '" style="max-width:200px;">';
		echo '<option value="">All Traffic Sources</option>';

		foreach ( $traffic_sources as $traffic_source ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $traffic_source ),
				selected( $current, $traffic_source, false ),
				esc_html( $traffic_source )
			);
		}

		echo '</select>';
	}

	/**
	 * Modify admin list query for Tel Clicks:
	 * - Sets default sort to newest first (with URL redirect to show sort state in UI)
	 * - Enables traffic_source column sorting via meta_key
	 * - Filters results by selected traffic source
	 */
	public static function modify_admin_list_query( $query ) {
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
			if ( ! empty( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) ) {
				$redirect_url = add_query_arg(
					self::TRAFFIC_SOURCE_FILTER_PARAM,
					sanitize_text_field( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ),
					$redirect_url
				);
			}

			wp_redirect( $redirect_url );
			exit;
		}

		// Filter by traffic source if selected
		if ( ! empty( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ) ) {
			$query->set( 'meta_query', array(
				array(
					'key'   => self::COLUMN_TRAFFIC_SOURCE,
					'value' => sanitize_text_field( $_GET[ self::TRAFFIC_SOURCE_FILTER_PARAM ] ),
				),
			) );
		}
	}

	/**
	 * Add traffic source metabox to edit page
	 */
	public static function add_metaboxes() {
		add_meta_box(
			'wego_tel_click_details_metabox',
			__( 'Tel Click Details', self::$text_domain ),
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
		$formatted_date_time = get_post_datetime( $post )->format( self::DATETIME_FORMAT );
		wp_nonce_field( self::METABOX_NONCE, self::METABOX_NONCE );
		?>
		<p>
			<label for="wego_traffic_source"><?php esc_html_e( 'Traffic Source:', self::$text_domain ); ?></label>
			<input type="text" id="wego_traffic_source" name="wego_traffic_source" value="<?php echo esc_attr( $traffic_source ); ?>" style="width: 100%;" readonly>
		</p>
		<p>
			<label for="wego_click_date_time"><?php esc_html_e( 'Click Date/Time:', self::$text_domain ); ?></label>
			<input type="text" id="wego_click_date_time" name="wego_click_date_time" value="<?php echo esc_attr( $formatted_date_time ); ?>" style="width: 100%;" readonly>
		</p>
		<?php
	}

	/**
	 * This method is hooked to save_post but intentionally does not save anything.
	 * The traffic_source field is readonly and should only be set via the tracking API.
	 */
	public static function save_metabox( $post_id ) {
		if ( ! isset( $_POST[ self::METABOX_NONCE ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST[ self::METABOX_NONCE ], self::METABOX_NONCE ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Intentionally empty - traffic_source should only be set via the tracking API
	}

}
