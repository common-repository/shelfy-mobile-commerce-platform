<?php
/**
 * Cart cotroller
 *
 * @package Shelfy
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

use Automattic\WooCommerce\Blocks\Domain\Services\ExtendRestApi;
use Automattic\WooCommerce\Blocks\Package as BlocksPackage;
use Automattic\WooCommerce\Blocks\StoreApi\SchemaController;
use Automattic\WooCommerce\Blocks\StoreApi\Schemas\CartSchema;
use Automattic\WooCommerce\Blocks\StoreApi\Schemas\ImageAttachmentSchema;

/**
 * The cart REST controller class
 */
class Shelfy_Cart_Rest_Api {
	/**
	 * The base REST mapping of the cart controller
	 *
	 * @var string $api_base
	 */
	private $api_base = 'cart';
	private static $user_id = 0;

	/**
	 * Constructs Shelfy_Cart_Rest_Api object
	 */
	public function __construct() {
		Shelfy_Cart_Rest_Api::$user_id=get_current_user_id();
		add_action( 'rest_api_init', array( $this, 'shelfy_init_cart_rest_api' ) );
		add_filter( 'woocommerce_is_rest_api_request', array( $this, 'simulate_as_not_rest' ) );
		/**
		 * add timestamp for when cart item created (unix time)
		 * @param mixed $data the added item's data.
		 */
		add_filter( 'woocommerce_add_cart_item', function( $data ) {
			$data['created_at'] = time();
			return $data;
		}, 20, 1);
		/**
		 * add timestamp for when cart item created (unix time)
		 * @param string $item_key the updated item's key.
		 * @param float $_quantity the new quantity.
		 * @param WC_Cart $cart the cart.
		 */
		add_action('woocommerce_cart_item_set_quantity', function( $item_key, $_quantity, $cart ) {
			$cart->cart_contents[$item_key]['updated_at'] = time();
		}, 20, 3 );
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
			$this->api_base . '/',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cart_response' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'empty_cart' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/items',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item_quantity' ),
				'permission_callback' => '__return_true',
				'args'                => $this->get_update_item_quantity_schema(),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/items/batch',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'batch_update_item_quantity' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'products' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => $this->get_update_item_quantity_schema(),
					),
				),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/items/batch-prices',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'batch_update_item_quantity_with_prices' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'products' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => $this->get_update_item_quantity_schema(),
					),
				),
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/calculate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'args'                => array(
					'products' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => $this->get_update_item_quantity_schema(),
					),
				),
				'callback'            => array( $this, 'calculate_cart' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Returns the current cart in the structure we want
	 *
	 * @return WC_Cart and adding parent_id field to each cart item
	 */
	public static function get_cart_response() {
		wp_set_current_user(Shelfy_Cart_Rest_Api::$user_id);
		/**
		 * The SchemaController
		 *
		 * @var SchemaController $controller
		 */
		$controller = BlocksPackage::container()->get( SchemaController::class );
		/**
		 * The schema for HTTP response body generation
		 *
		 * @var CartSchema $schema
		 */
		$schema = $controller->get( CartSchema::IDENTIFIER );
		try {
			$schema->item_schema = new Shelfy_Cart_Item_Schema_Ex( BlocksPackage::container()->get( ExtendRestApi::class ), $controller->get( ImageAttachmentSchema::IDENTIFIER ) );
		} catch ( \TypeError $ignore ) {
			$schema->item_schema = new Shelfy_Cart_Item_Schema_Ex( BlocksPackage::container()->get( ExtendRestApi::class ), $controller );
		}
		$cart                     = WC()->cart;
		$response                 = $schema->get_item_response( $cart );
		$response['checkout_url'] = wc_get_checkout_url();
		/**
		 * Gets the return URL after chekcout so we can use that to know when the checkout is finished in the WebView
		 */
		$response['return_url'] = apply_filters( 'woocommerce_get_return_url', wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() ), null );
		return $response;
	}

	/**
	 * Empties the cart
	 */
	public function empty_cart() {
		wp_set_current_user(Shelfy_Cart_Rest_Api::$user_id);
		WC()->cart->empty_cart();
		WC()->cart->calculate_totals();
		return $this->get_cart_response();
	}

	/**
	 * Updates cart items quantities in batches
	 *
	 * @param WP_REST_Request $request The REST request.
	 */
	public function batch_update_item_quantity( $request ) {
		wp_set_current_user(Shelfy_Cart_Rest_Api::$user_id);
		$requests       = $request['products'];
		$batch_response = array();
		foreach ( $requests as $index => $request ) {
			$response = $this->update_item_quantity( $request, true );
			if ( is_wp_error( $response ) ) {
				$batch_response[] = array(
					'requestIndex' => $index,
					'message'      => $response->get_error_message(),
					'data'         => $response->get_error_data(),
				);
			}
		}
		$response = $this->get_cart_response();
		if ( ! empty( $batch_response ) ) {
			$response['batch_errors'] = $batch_response;
		}
		return $response;
	}

	public function batch_update_item_quantity_with_prices( $request ) {
		wp_set_current_user(Shelfy_Cart_Rest_Api::$user_id);
		WC()->cart->empty_cart();

		$requests       = $request['products'];
		$batch_response = array();

		foreach ( $requests as $index => $request ) {
			$response = $this->update_item_quantity( $request, true );
			if ( is_wp_error( $response ) ) {
				$batch_response[] = array(
					'requestIndex' => $index,
					'message'      => $response->get_error_message(),
					'data'         => $response->get_error_data(),
				);
			}
		}
		$response = $this->get_cart_response();
		if ( ! empty( $batch_response ) ) {
			$response['batch_errors'] = $batch_response;
		}

		return $response;
	}
	/**
	 * Calculate cart totals and prices by replacing the current cart and restoring it afterwards
	 *
	 * @param WP_Rest_Request $request containing list of products to calculate price for.
	 * @return array The calculated cart response.
	 */
	public function calculate_cart( $request ) {
		wp_set_current_user(Shelfy_Cart_Rest_Api::$user_id);
		$old_cart = clone WC()->cart;
		$cart     = WC()->cart;
		$cart->empty_cart( false );
		$products     = $request['products'];
		$batch_errors = array();
		foreach ( $products as $index => $product ) {
			$response = $this->update_item_quantity( $product, true );
			if ( is_wp_error( $response ) ) {
				$batch_errors[] = array(
					'requestIndex' => $index,
					'message'      => $response->get_error_message(),
					'data'         => $response->get_error_data(),
				);
			}
		}
		$response = $this->get_cart_response();
		$cart->set_cart_contents( $old_cart->get_cart_contents() );
		$cart->calculate_totals();
		if ( ! empty( $batch_errors ) ) {
			$response['batch_errors'] = $batch_errors;
		}
		return $response;
	}

	/**
	 * Updates one cart item quantity. Adds to the cart if product is not in cart and quantity > 0
	 *
	 * @param WP_REST_Request $request The HTTP request object.
	 * @param bool $add_always whether to always add the quantity requsted, regardless of whether the exact item present in the cart already.
	 */
	public function update_item_quantity( $request, $add_always = false ) {
		wp_set_current_user(Shelfy_Cart_Rest_Api::$user_id);
		$product_id = $request['productId'];
		$variant_id = $request['variantId'];
		$quantity   = $request['quantity'];
		$attribues = array();
		if ( isset( $request['dynamicAttributes'] ) ) {
			$attribues['shelfy_dynamic_attributes'] = $request['dynamicAttributes'];
		}
		$cart       = WC()->cart;
		$product    = wc_get_product( $product_id );
		if ( ! $product || $product->get_type() === 'variation' ) {
			return new WP_Error( 'bad_request', __( 'Product does not exist' ), array( 'status' => 400 ) );
		}
		if ( 'variable' === $product->get_type() ) {
			$variant = wc_get_product( $variant_id );
			if ( ! $variant || $variant->get_type() !== 'variation' ) {
				if ( ! isset( $variant_id ) ) {
					return new WP_Error( 'bad_request', __( 'Product is variable, variantId is required' ) );
				}
				return new WP_Error( 'bad_request', __( 'No product variant with for the ID requested' ), array( 'status' => 400 ) );
			}
		} else {
			$variant_id = null;
		}
		$variations = wc_get_product_variation_attributes( $variant_id );
		if ( !$add_always ) {
			$cart_id    = $cart->generate_cart_id( $product_id, $variant_id, $variations, $attribues );
			$cart_id    = $cart->find_product_in_cart( $cart_id );
		}
		if ( ! empty( $cart_id ) && ! $add_always ) {
			if ( 0.0 === (float)$quantity ) {
				$cart->remove_cart_item( $cart_id );
			} else {
				$cart->set_quantity( $cart_id, $quantity );
			}
		} elseif ( $quantity > 0 ) {
			try {
				if ( ! $cart->add_to_cart( $product_id, $quantity, $variant_id, $variations, $attribues ) ) {
					$notices = WC()->session->get( 'wc_notices' );
					if ( $notices && isset( $notices['error'] ) ) {
						return new WP_Error( 'bad_request', $notices['error'][0]['notice'], array( 'status' => 400 ) );
					}
					return new WP_Error( 'bad_request', __( 'Bad product or quantity' ), array( 'status' => 400 ) );
				};
			} catch ( Exception $e ) {
				return new WP_Error( 'bad_request', $e->getMessage(), array( 'status' => 404 ) );
			}
		}
		return $this->get_cart_response();
	}

	/**
	 * Returns the REST schema of update item quantity request
	 *
	 * @return array
	 */
	private function get_update_item_quantity_schema() {
		return array(
			'productId' => array(
				'type'     => 'int',
				'required' => true,
			),
			'quantity'  => array(
				'type'     => 'float',
				'required' => true,
			),
			'variantId' => array(
				'type'     => 'int',
				'required' => false,
			),
			'dynamicAttributes' => array(
				'type'     => 'object',
				'required' => false,
			)
		);
	}
}

new Shelfy_Cart_Rest_Api();
