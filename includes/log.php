<?php

class WP_Stream_Log {

	/**
	 * Log handler
	 *
	 * @var \WP_Stream_Log
	 */
	public static $instance = null;

	/**
	 * Previous Stream record ID, used for chaining same-session records
	 *
	 * @var int
	 */
	public $prev_record;

	/**
	 * Load log handler class, filterable by extensions
	 *
	 * @return void
	 */
	public static function load() {
		/**
		 * Filter allows developers to change log handler class
		 *
		 * @param  array   Current Class
		 * @return string  New Class for log handling
		 */
		$log_handler = apply_filters( 'wp_stream_log_handler', __CLASS__ );

		self::$instance = new $log_handler;
	}

	/**
	 * Return active instance of this class
	 *
	 * @return WP_Stream_Log
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			$class = __CLASS__;
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * Log handler
	 *
	 * @param         $connector
	 * @param  string $message   sprintf-ready error message string
	 * @param  array  $args      sprintf (and extra) arguments to use
	 * @param  int    $object_id Target object id
	 * @param  string $context   Context of the action
	 * @param  string $action    The action which we are logging
	 * @param  int    $user_id   User responsible for the action
	 *
	 * @internal param string $action Action performed (stream_action)
	 * @return int
	 */
	public function log( $connector, $message, $args, $object_id, $context, $action, $user_id = null ) {
		global $wpdb;

		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}
		require_once WP_STREAM_INC_DIR . 'class-wp-stream-author.php';

		$user  = new WP_User( $user_id );
		$roles = get_option( $wpdb->get_blog_prefix() . 'user_roles' );

		if ( ! isset( $args['author_meta'] ) ) {
			$args['author_meta'] = array(
				'user_email'      => $user->user_email,
				'display_name'    => ( defined( 'WP_CLI' ) && empty( $user->display_name ) ) ? 'WP-CLI' : $user->display_name, // @todo
				'user_login'      => $user->user_login,
				'user_role_label' => ! empty( $user->roles ) ? $roles[ $user->roles[0] ]['name'] : null,
				'agent'           => WP_Stream_Author::get_current_agent(),
			);
		}

		// Remove meta with null values from being logged
		$meta = array_filter(
			$args,
			function ( $var ) {
				return ! is_null( $var );
			}
		);

		$recordarr = array(
			'object_id'   => $object_id,
			'site_id'     => is_multisite() ? get_current_site()->id : 1,
			'blog_id'     => apply_filters( 'blog_id_logged', is_network_admin() ? 0 : get_current_blog_id() ),
			'author'      => $user_id,
			'author_role' => ! empty( $user->roles ) ? $user->roles[0] : null,
			'created'     => current_time( 'mysql', 1 ),
			'summary'     => vsprintf( $message, $args ),
			'parent'      => self::$instance->prev_record,
			'connector'   => $connector,
			'context'     => $context,
			'action'      => $action,
			'meta'        => $meta,
			'ip'          => wp_stream_filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP ),
		);

		$record_id = WP_Stream_DB::get_instance()->insert( $recordarr );

		return $record_id;
	}

}
