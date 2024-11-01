<?php
/**
 * Cart cotroller
 *
 * @package Shelfy
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The cart REST controller class
 */
class Shelfy_Cart_V2_Rest_Api {
	/**
	 * The base REST mapping of the cart controller
	 *
	 * @var string $api_base
	 */
	private $api_base = 'v2/cart';

	/**
	 * Constructs Shelfy_Cart_Rest_Api object
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'shelfy_init_cart_rest_api' ) );
		add_filter( 'woocommerce_is_rest_api_request', array( $this, 'simulate_as_not_rest' ) );
	}

	/**
	 * Makes forntend classes to be loaded for requests for the cart API,
	 * so we can use WooCommerce cart.
	 *
	 * @param bool $is_rest_api_request whether the requests is considered REST API.
	 */
	public function simulate_as_not_rest( $is_rest_api_request ) {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return $is_rest_api_request;
		}
		$uri = esc_url_raw( wp_unslash( $_SERVER )['REQUEST_URI'] );
		if ( false === strpos( $uri, SHELFY_REST_API_NAMESPACE . '/' . $this->api_base ) ) {
			return $is_rest_api_request;
		}
		return false;
	}

	/**
	 * Initializes the cart REST API
	 */
	public function shelfy_init_cart_rest_api() {
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/items',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_item' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'productId'        => array(
						'type'     => 'int',
						'required' => true,
					),
					'quantity'         => array(
						'type'     => 'float',
						'required' => true,
					),
					'variantId'        => array(
						'type'     => 'int',
						'required' => false,
					),
					'custom_attribues' => array(
						'type'     => 'array',
						'required' => false,
					),
				),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/items/(?P<item_key>.+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'quantity' => array(
						'type'     => 'float',
						'required' => false,
					),
				),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/items/(?P<item_key>.+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Add one item to the cart
	 *
	 * @param WP_Rest_Request $request the request.
	 *
	 * @return WC_Cart with added parent id
	 */
	public function add_item( $request ) {
		$body              = $request->get_json_params();
		$cart              = WC()->cart;
		$shelfy_attributes = array();
		if ( isset( $request['dynamicAttributes'] ) ) {
			$shelfy_attributes['shelfy_dynamic_attributes'] = $request['dynamicAttributes'];
		}
		$cart->add_to_cart( $body['productId'], $body['quantity'], $body['variationId'], array(), $shelfy_attributes );
		global $shelfy_cart_api;
		$shelfy_cart_api = true;
		return $this->get_cart_response();
	}

	/**
	 * Updates cart item quantity.
	 *
	 * @param WP_Rest_Request $request the request.
	 *
	 * @return WC_Cart with added parent id
	 */
	public function update_item( $request ) {
		$item_key = $request['item_key'];
		$cart     = WC()->cart;
		if ( ! $cart->get_cart_item( $item_key ) ) {
			return new WP_Error( 'bad_request', 'cart item does not exists', array( 'status' => 400 ) );
		}
		$cart->set_quantity( $item_key, $request['quantity'] );
		return $this->get_cart_response();
	}

	/**
	 * Deletes one item from the cart
	 *
	 * @param WP_Rest_Request $request the request.
	 *
	 * @return WC_Cart with added parent id
	 */
	public function delete_item( $request ) {
		$item_key = $request['item_key'];

		$cart = WC()->cart;

		if ( ! $cart->remove_cart_item( $item_key ) ) {
			return new WP_Error( 'bad_request', 'cart item does not exists', array( 'status' => 400 ) );
		}

		return $this->get_cart_response();
	}

	/**
	 * Returns the current cart in the structure we want
	 *
	 * @return WC_Cart and adding parent_id field to each cart item
	 */
	public function get_cart_response() {
		return Shelfy_Cart_Rest_Api::get_cart_response();
	}
}

new Shelfy_Cart_V2_Rest_Api();
