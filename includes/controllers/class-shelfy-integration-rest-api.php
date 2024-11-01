<?php
/**
 * Authentication controller for Shelfy's back-end to report integration status to.
 *
 * @package Shelfy
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Authentication controller for Shelfy's back-end to report integration status to.
 */
class Shelfy_Integration_Rest_Api {
	/**
	 * The base URL of the controller
	 *
	 * @var string $base_url
	 */
	private $base_url = 'integration';

	/**
	 * Constructs AuthenticationRestApi object
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'init_authentication_rest_api' ) );
	}

	/**
	 * Initializes the authentication REST API
	 */
	public function init_authentication_rest_api() {
		if ( ! get_option( 'shelfy_authentication_received', false ) || ! get_option( 'shelfy_integration_finished', false ) ) {
			register_rest_route(
				SHELFY_REST_API_NAMESPACE,
				$this->base_url . '/state',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'integration_state_updated' ),
					'permission_callback' => array( $this, 'permission_admin_only' ),
					'args'                => array(
						'state' => array(
							'type'     => 'string',
							'required' => true,
						),
						'plan'  => array(
							'type'     => 'string',
							'required' => false,
						),
					),
				)
			);
		}
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->base_url . '/deactivation-confirmation',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'deactivation_confirmation' ),
				'permission_callback' => '__return_true',
			),
		);
	}

	/**
	 * Updates the integration state
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error error in case of invalid state, true otherwise.
	 */
	public function integration_state_updated( $request ) {
		switch ( $request['state'] ) {
			case 'registered':
				add_option( 'shelfy_integration_finished', true );
				add_option( 'shelfy_authentication_received', true );
				break;
			case 'authenticated':
				add_option( 'shelfy_authentication_received', true );
				break;
			default:
				return new WP_Error( 'bad_request', 'Uknown integration state "' . $request['state'] . '"', array( 'status' => 400 ) );
		}
	}

	/**
	 * Will only allow site admin to the REST endpoint
	 *
	 * @return true|WP_Error
	 */
	public function permission_admin_only() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->caps['administrator'] ) {
			return new WP_Error( 'unauthorized', 'You are not authorized to view this resource', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Will return whether the plugin currently deactivating
	 *
	 * @return array ( 'confirmed' => boolean )
	 */
	public function deactivation_confirmation() {
		return array( 'confirmed' => (bool) get_option( 'shelfy_deactivation', false ) );
	}
}

new Shelfy_Integration_Rest_Api();
