<?php
/**
 * Definitions of custom webhooks and payloads
 *
 * @package Shelfy
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Add a new webhook topic hook.
 *
 * @param array $topic_hooks Esxisting topic hooks.
 */
function shelfy_add_new_topic_hooks( $topic_hooks ) {
	$new_hooks = array(
		'product_cat.created' => array(
			'created_product_cat', // Action documentation: https://developer.wordpress.org/reference/hooks/created_taxonomy/.
		),
		'product_cat.updated' => array(
			'edited_product_cat', // Action documentation: https://developer.wordpress.org/reference/hooks/edited_taxonomy/.
		),
		'product_cat.deleted' => array(
			'delete_product_cat', // Action documentation: https://developer.wordpress.org/reference/hooks/delete_taxonomy/.
		),
	);
	// Fix woocommerce product deleted hook (It does not work for variation because it listens only to wp_trash_post and not delete_post).
	$topic_hooks['product.deleted'][] = 'delete_post';
	return array_merge( $topic_hooks, $new_hooks );
}
add_filter( 'woocommerce_webhook_topic_hooks', 'shelfy_add_new_topic_hooks' );

/**
 * Adds product_cat as a resource to the webhooks resources list
 *
 * @param string[] $resources List of existing resources.
 */
function shelfy_add_category_resource( $resources ) {
	return array_merge( $resources, array( 'product_cat' ) );
}
add_filter( 'woocommerce_valid_webhook_resources', 'shelfy_add_category_resource' );

/**
 * Adds the new webhook to the dropdown list on the Webhook page.
 *
 * @param array $topics Array of topics with the i18n proper name.
 */
function shelfy_add_new_webhook_topics( $topics ) {

	// New topic array to add to the list, must match hooks being created.
	$new_topics = array(
		'product_cat.created' => __( 'Product Category Created', 'woocommerce' ),
		'product_cat.updated' => __( 'Product Category Updated', 'woocommerce' ),
		'product_cat.deleted' => __( 'Product Category Deleted', 'woocommerce' ),
	);

	return array_merge( $topics, $new_topics );
}
add_filter( 'woocommerce_webhook_topics', 'shelfy_add_new_webhook_topics' );

/**
 * Adds our required payloads to webhooks
 *
 * @param mixed  $payload The webhook payload.
 * @param string $resource The name of the resource.
 * @param int    $resource_id The ID of the resource.
 * @param int    $webhook_id The ID of the webhook.
 */
function shelfy_add_product_category_webhook_payload( $payload, $resource, $resource_id, $webhook_id ) {
	$webhook = wc_get_webhook( $webhook_id );
	if ( 'product_cat' === $resource ) {
		if ( 'deleted' !== $webhook->get_event() ) {
			// Build the payload with the same user context as the user who created
			// the webhook -- this avoids permission errors as background processing
			// runs with no user context.
			$current_user = get_current_user_id();
			wp_set_current_user( $webhook->get_user_id() );

			$version = str_replace( 'wp_api_', '', $webhook->get_api_version() );
			$payload = WC()->api->get_endpoint_data( "/wc/{$version}/products/categories/{$resource_id}" );
			// Get a list of all products in the category.
			$products = wc_get_products(
				array(
					'status'   => 'publish',
					'category' => array( $payload['slug'] ),
				)
			);
			// Find one with image, and set the image as the secondary image of the category.
			foreach ( $products as $product ) {
				if ( $product->get_image_id() ) {
					$image_post_id                       = $product->get_image_id();
					$payload['x_shelfy_secondary_image'] = array(
						'src'  => current( wp_get_attachment_image_src( $image_post_id, 'full' ) ),
						'name' => get_the_title( $image_post_id ),
						'alt'  => get_post_meta( $image_post_id, '_wp_attachment_image_alt', true ),
					);
					break;
				}
			}
			// Restore the current user.
			wp_set_current_user( $current_user );
		}
	} elseif ( 'product' === $resource && 'deleted' !== $webhook->get_event() ) {
		if ( ! isset( $payload['weight_unit'] ) ) {
			$payload['x_shelfy_weight_unit'] = get_option( 'woocommerce_weight_unit' );
		} else {
			$payload['x_shelfy_weight_unit'] = $payload['weight_unit'];
		}
	}
	// Adding time signature for every event, so we can compare this value to our catalog update time.
	$payload['x_shelfy_event_time'] = gmdate( \DateTime::W3C );
	return $payload;
}
add_filter( 'woocommerce_webhook_payload', 'shelfy_add_product_category_webhook_payload', 10, 4 );
