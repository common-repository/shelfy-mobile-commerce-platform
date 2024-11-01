<?php
/**
 * Cart item schema with parent_id field
 *
 * @package Shelfy
 */


if ( ! defined( 'WPINC' ) ) {
	die;
}
if (version_compare( WC_VERSION, '6.0.0' ) >= 0 ) {
	/**
	 * Our extended version of CartItemSchema, with additional parent_id field
	 */
	class Shelfy_Cart_Item_Schema_Ex extends Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema {
		/**
		 * Returns cart item with parent_id
		 *
		 * @param CartItem $cart_item The cart item.
		 */
		public function get_item_response( $cart_item ) {
			$response = parent::get_item_response( $cart_item );
	
			// Get the item meta, but without stripping the values (so we would still have our arrays).
			$response['item_data'] = apply_filters( 'woocommerce_get_item_data', array(), $cart_item );
			if ( !isset( $cart_item['created_at'] ) ) {
				$cart_item['created_at'] = time();
			}
			if ( !isset( $cart_item['updated_at'] ) ) {
				$cart_item['updated_at'] = $cart_item['created_at'];
			}
			$response['created_at'] = $cart_item['created_at'];
			$response['updated_at'] = $cart_item['updated_at'];
			$response['parent_id'] = $cart_item['data']->get_parent_id();
			return $response;
		}
	}
} else {
	/**
	 * Our extended version of CartItemSchema, with additional parent_id field
	 */
	class Shelfy_Cart_Item_Schema_Ex extends Automattic\WooCommerce\Blocks\StoreApi\Schemas\CartItemSchema {
		/**
		 * Returns cart item with parent_id
		 *
		 * @param CartItem $cart_item The cart item.
		 */
		public function get_item_response( $cart_item ) {
			$response = parent::get_item_response( $cart_item );
	
			// Get the item meta, but without stripping the values (so we would still have our arrays).
			$response['item_data'] = apply_filters( 'woocommerce_get_item_data', array(), $cart_item );
			if ( !isset( $cart_item['created_at'] ) ) {
				$cart_item['created_at'] = time();
			}
			if ( !isset( $cart_item['updated_at'] ) ) {
				$cart_item['updated_at'] = $cart_item['created_at'];
			}
			$response['created_at'] = $cart_item['created_at'];
			$response['updated_at'] = $cart_item['updated_at'];
			$response['parent_id'] = $cart_item['data']->get_parent_id();
			return $response;
		}
	}
}