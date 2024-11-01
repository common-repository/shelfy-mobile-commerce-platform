<?php
/**
 * The customer REST API controller
 *
 * @package Shelfy
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The controller of customer REST API
 */
class Shelfy_Customer_Rest_Api {
	/**
	 * The base of the controller endpoints
	 *
	 * @var string $rest_base The base
	 */
	private $rest_base = 'customers';

	/**
	 * Constsructs a CustomerRestApi object
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'init_customer_rest_api' ) );
	}

	/**
	 * Defines the customers REST API
	 */
	public function init_customer_rest_api() {
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->rest_base . '/login',
			array(
				'methods'             => 'POST',
				'args'                => array(
					'email'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'password' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => array( $this, 'login' ),
				'permission_callback' => array( $this, 'permission_admin_only' ),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->rest_base . '/login-by-id',
			array(
				'methods'             => 'POST',
				'args'                => array(
					'id' => array(
						'type'     => 'int',
						'required' => true,
					),
				),
				'callback'            => array( $this, 'login_by_id' ),
				'permission_callback' => array( $this, 'permission_admin_only' ),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->rest_base . '/forgot-password',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'args'                => array(
					'email' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => array( $this, 'forgot_password' ),
				'permission_callback' => array( $this, 'permission_admin_only' ),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->rest_base . '/change-password',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'args'                => array(
					'email'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'old_password' => array(
						'type'     => 'string',
						'required' => true,
					),
					'new_password' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => array( $this, 'change_password' ),
				'permission_callback' => array( $this, 'permission_admin_only' ),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->rest_base . '/exists-by-email/(?P<email>.+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'user_exists' ),
				'permission_callback' => array( $this, 'permission_admin_only' ),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->rest_base . '/add-to-blog/(?P<user_id>\\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_to_blog' ),
				'permission_callback' => array( $this, 'permission_admin_only' ),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->rest_base . '/(?P<user_id>\\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_customer_from_all_sites' ),
				'permission_callback' => array( $this, 'permission_admin_only' ),
			)
		);
	}

	/**
	 * Hanles forgot password requests (by sending reset email)
	 *
	 * @param WP_REST_Request $request The HTTP request.
	 * @return array ('success' => boolean, 'message' => string)
	 */
	public function forgot_password( $request ) {
		$email    = $request['email'];
		$userdata = get_user_by( 'email', $email );
		if ( empty( $userdata ) ) {
			return new WP_Error( 'bad_request', __( 'No user associated with this email' ), array( 'status' => 400 ) );
		}
		$user           = new WP_User( $userdata->ID );
		$reset_key      = get_password_reset_key( $user );
		$wc_emails      = WC()->mailer()->get_emails();
		$email_template = $wc_emails['WC_Email_Customer_Reset_Password'];
		assert( $email_template instanceof WC_Email_Customer_Reset_Password );
		$email_template->trigger( $user->user_login, $reset_key );
		return array(
			'success' => true,
			'message' => __( 'Reset link sent to the user\'s email' ),
		);
	}

	/**
	 * Handles password chagne requests
	 *
	 * @param WP_REST_Request $request The HTTP request.
	 * @return void|WP_Error
	 */
	public function change_password( $request ) {
		$user_or_error = wp_signon(
			array(
				'user_login'    => $request['email'],
				'user_password' => $request['old_password'],
			)
		);
		if ( is_wp_error( $user_or_error ) ) {
			return $user_or_error;
		}
		wp_set_password( $request['new_password'], $user_or_error->ID );
	}

	/**
	 * Will allow admin only to the REST endpoint
	 *
	 * @return true|WP_Error
	 */
	public function permission_admin_only() {
		$user = wp_get_current_user();
		if ( ! isset( $user ) || ! isset( $user->caps['administrator'] ) ) {
			return new WP_Error( 'unauthorized', 'You are not authorized to view this resource', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Will verify user credentials by calling wp_signon and initialize a session if the login succeeded
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|array returns {userID: string } on success, error on wrong credentials.
	 */
	public function login( $request ) {
		if ( ! isset( $request['email'] ) ) {
			return new WP_Error( 'bad_request', 'Missing body field "email"', array( 'status' => 400 ) );
		}
		if ( ! isset( $request['password'] ) ) {
			return new WP_Error( 'bad_request', 'Missing body field "password"', array( 'status' => 400 ) );
		}
		$user_or_error = wp_signon(
			array(
				'user_login'    => $request['email'],
				'user_password' => $request['password'],
			),
			false
		);
		if ( is_wp_error( $user_or_error ) ) {
			return new WP_Error( $user_or_error->get_error_code(), $user_or_error->get_error_message(), array( 'status' => 401 ) );
		}
		WC()->initialize_session();
		/**
		 * This will set the necessary cookies so we can use them later for loading the checkout page in a WebView
		 */
		do_action( 'woocommerce_set_cart_cookies', true );
		return array( 'userID' => $user_or_error->ID );
	}

	/**
	 * Checks whether the user exists in all sites
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array ( 'id' => int, 'exists_in_blog' => boolean )
	 */
	public function user_exists( $request ) {
		$email           = $request['email'];
		$user            = get_user_by( 'email', $email );
		$user_id         = false !== $user ? $user->ID : null;
		$current_blog_id = get_current_blog_id();
		$exists_in_blog  = ! is_multisite() || array_key_exists( $current_blog_id, get_blogs_of_user( $user_id ) );

		return array(
			'id'             => $user_id,
			'exists_in_blog' => $exists_in_blog,
		);
	}

	/**
	 * Adds customer to current blog
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|true
	 */
	public function add_to_blog( $request ) {
		$id   = $request['user_id'];
		$user = get_user_by( 'id', $id );
		if ( false === $user ) {
			return new WP_Error( 'bad_request', 'no such user', array( 'status' => 400 ) );
		}
		$current_blog_id = get_current_blog_id();
		$exists_in_blog  = ! is_multisite() || array_key_exists( $current_blog_id, get_blogs_of_user( $id ) );
		if ( ! $exists_in_blog ) {
			$user_details = array(
				'user_id' => $id,
				'role'    => $user->roles,
			);
			add_existing_user_to_blog( $user_details );
		}
		return true;
	}

	/**
	 * Delets a customer from all sites
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return boolean whether the user deleted
	 */
	public function delete_customer_from_all_sites( $request ) {
		$id = $request['user_id'];
		if ( is_multisite() ) {
			if ( ! function_exists( 'wpmu_delete_user' ) ) { 
				require_once ABSPATH . '/wp-admin/includes/ms.php'; 
			}
			return wpmu_delete_user( $id );
		} else {
			return wp_delete_user( $id );
		}
	}

	/**
	 * Will login by calling wp_signon and initialize a session if the login succeeded
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Error|array returns {userID: string } on success, error on wrong credentials.
	 */
	public function login_by_id( $request ) {
		$user_id = $request['id'];

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'no_such_user', 'No Such User', array( 'status' => 400 ) );
		}

		wp_set_current_user( $user_id, $user->user_login );
		wp_set_auth_cookie( $user_id );
		// Trigger hook wp_login for logging in the user.
		do_action( 'wp_login', $user->user_login, $user );

		WC()->initialize_session();
		/**
		 * This will set the necessary cookies so we can use them later for loading the checkout page in a WebView
		 */
		do_action( 'woocommerce_set_cart_cookies', true );
		return array( 'userID' => $user->ID );
	}
}

new Shelfy_Customer_Rest_Api();
