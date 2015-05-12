<?php

class WP_Stream_Network {

	/**
	 * Fire hooks on load
	 *
	 * @return void
	 */
	public static function load() {
		add_action( 'init', array( __CLASS__, 'ajax_network_admin' ) );
		add_action( 'network_admin_menu', array( 'WP_Stream_Admin', 'register_menu' ) );
		add_action( 'network_admin_menu', array( 'WP_Stream_Reports', 'register_menu' ) );
		add_action( 'network_admin_menu', array( __CLASS__, 'admin_menu_screens' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu_screens' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'network_admin_bar_menu' ), 99 );

		add_filter( 'wp_stream_query_properties', array( __CLASS__, 'query_properties' ) );
		add_filter( 'wp_stream_list_table_screen_id', array( __CLASS__, 'list_table_screen_id' ) );
		add_filter( 'wp_stream_query_args', array( __CLASS__, 'list_table_query_args' ) );
		add_filter( 'wp_stream_list_table_filters', array( __CLASS__, 'list_table_filters' ) );
		add_filter( 'wp_stream_list_table_columns', array( __CLASS__, 'network_admin_columns' ) );
		add_filter( 'wp_stream_register_column_defaults', array( __CLASS__, 'network_admin_column_blog_id' ) );
		add_filter( 'wp_stream_insert_column_default-blog_id', array( __CLASS__, 'network_admin_column_default_blog_id' ), 10, 2 );

		if ( ! is_network_admin() ) {
			remove_action( 'load-index.php', array( 'WP_Stream_Admin', 'prepare_connect_notice' ) );
			remove_action( 'load-plugins.php', array( 'WP_Stream_Admin', 'prepare_connect_notice' ) );
		}
	}

	/**
	 * Workaround to get admin-ajax.php to know when the request is from the network admin
	 *
	 * @see https://core.trac.wordpress.org/ticket/22589
	 */
	public static function ajax_network_admin() {
		if (
			defined( 'DOING_AJAX' )
			&&
			DOING_AJAX
			&&
			preg_match( '#^' . network_admin_url() . '#i', $_SERVER['HTTP_REFERER'] )
			&&
			! defined( 'WP_NETWORK_ADMIN' )
		) {
			define( 'WP_NETWORK_ADMIN', true );
		}
	}

	/**
	 * Builds a stdClass object used when displaying actions done in network administration
	 *
	 * @return object
	 */
	public static function get_network_blog() {
		$blog           = new stdClass;
		$blog->blog_id  = 0;
		$blog->blogname = esc_html__( 'Network Admin', 'stream' );

		return $blog;
	}

	/**
	 * Setup admin menus for network
	 *
	 * @param $screen_id
	 *
	 * @return array
	 */
	public static function admin_menu_screens() {
		if ( is_network_admin() ) {
			remove_submenu_page( WP_Stream_Admin::RECORDS_PAGE_SLUG, 'wp_stream_settings' );
		} else {
			remove_submenu_page( WP_Stream_Admin::RECORDS_PAGE_SLUG, 'wp_stream_account' );
		}
	}

	/**
	 * Adds Stream to the admin bar under the "My Sites > Network Admin" menu
	 * if Stream has been network-activated.
	 *
	 * @param object $admin_bar
	 *
	 * @return void
	 */
	public static function network_admin_bar_menu( $admin_bar ) {
		$href = add_query_arg(
			array(
				'page' => WP_Stream_Admin::RECORDS_PAGE_SLUG,
			),
			network_admin_url( WP_Stream_Admin::ADMIN_PARENT_PAGE )
		);

		$admin_bar->add_menu(
			array(
				'id'     => 'network-admin-stream',
				'parent' => 'network-admin',
				'title'  => esc_html__( 'Stream', 'stream' ),
				'href'   => esc_url( $href ),
			)
		);
	}

	/**
	 * Filter records by multisite properties
	 *
	 * @param array $properties
	 *
	 * @return array
	 */
	public static function query_properties( $properties ) {
		$properties['site_id'] = get_current_site()->id;

		if ( ! is_network_admin() ) {
			$properties['blog_id'] = get_current_blog_id();
		}

		return $properties;
	}

	/**
	 * Add the network suffix to the $screen_id when in the network admin
	 *
	 * @filter wp_stream_list_table_screen_id
	 *
	 * @param $screen_id
	 *
	 * @return string
	 */
	public static function list_table_screen_id( $screen_id ) {
		if ( $screen_id && is_network_admin() ) {
			if ( '-network' !== substr( $screen_id, -8 ) ) {
				$screen_id .= '-network';
			}
		}

		return $screen_id;
	}

	/**
	 * Add the Site filter to the stream activity in Network Admin
	 *
	 * @filter wp_stream_query_args
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public static function list_table_query_args( $args ) {
		if ( is_network_admin() ) {
			$args['aggregations'][] = 'blog_id';
		}

		return $args;
	}

	/**
	 * Add the Site filter dropdown in the Network Admin
	 *
	 * @filter wp_stream_list_table_filters
	 *
	 * @param array $filters
	 *
	 * @return array
	 */
	public static function list_table_filters( $filters ) {
		if ( is_network_admin() && ! wp_is_large_network() ) {
			$blogs        = array();
			$query_meta   = WP_Stream::$db->get_query_meta();
			$have_records = isset( $query_meta->aggregations->blog_id->buckets ) ? wp_list_pluck( $query_meta->aggregations->blog_id->buckets, 'key' ) : array();

			// Add all sites
			foreach ( wp_get_sites() as $blog ) {
				$blog_data = get_blog_details( $blog['blog_id'] );

				$blogs[] = array(
					'blog_id'  => $blog['blog_id'],
					'label'    => $blog_data->blogname,
					'disabled' => in_array( $blog['blog_id'], $have_records ) ? '' : 'disabled="disabled"',
				);
			}

			$disabled = wp_list_pluck( $blogs, 'disabled' );
			$label    = wp_list_pluck( $blogs, 'label' );

			// Sort first by disabled status, then again by label
			array_multisort( $disabled, SORT_ASC, $label, SORT_ASC, $blogs );

			$items = array();

			// Always display Network Admin as the first item
			$network_blog = self::get_network_blog();

			$items[0] = array(
				'blog_id'  => 0,
				'label'    => $network_blog->blogname,
				'disabled' => in_array( 0, $have_records ) ? '' : 'disabled="disabled"',
			);

			// Reindexing is required since array_multisort() does not preserve numeric keys
			foreach ( $blogs as $blog ) {
				$items[ $blog['blog_id'] ] = $blog;
			}

			$filters['blog_id'] = array(
				'title' => esc_html__( 'sites', 'stream' ),
				'items' => $items,
			);
		}

		return $filters;
	}

	/**
	 * Add the Site column to the network stream records
	 *
	 * @filter wp_stream_list_table_columns
	 *
	 * @param $args
	 *
	 * @return mixed
	 */
	public static function network_admin_columns( $columns ) {
		if ( is_network_admin() ) {
			$columns = array_merge(
				array_slice( $columns, 0, -1 ),
				array(
					'blog_id' => esc_html__( 'Site', 'stream' ),
				),
				array_slice( $columns, -1 )
			);
		}

		return $columns;
	}

	/**
	 * Register column defaults for blog_id
	 *
	 * @filter wp_stream_register_column_defaults
	 *
	 * @param array $new_columns
	 *
	 * @return array
	 */
	public static function network_admin_column_blog_id( $new_columns ) {
		if ( is_network_admin() ) {
			$new_columns[] = 'blog_id';
		}

		return $new_columns;
	}

	/**
	 * Populate the blog_id column with content
	 *
	 * @filter wp_stream_insert_column_default-blog_id
	 *
	 * @param string $column_name
	 * @param object $item
	 *
	 * @return string
	 */
	public static function network_admin_column_default_blog_id( $column_name, $item ) {
		if ( ! is_network_admin() ) {
			return;
		}

		$blog = ( 0 === $item->blog_id ) ? self::get_network_blog() : get_blog_details( $item->blog_id );
		$out  = sprintf(
			'<a href="%s"><span>%s</span></a>',
			add_query_arg( array( 'blog_id' => $blog->blog_id ), network_admin_url( 'admin.php?page=wp_stream' ) ),
			esc_html( $blog->blogname )
		);

		return $out;
	}

}
