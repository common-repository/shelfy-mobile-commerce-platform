<?php

/**
 * The smartbanner REST API controller
 *
 * @package Shelfy
 */

if (!defined('WPINC')) {
	die;
}

/**
 * The controller of smart banners REST API
 */
class Shelfy_Smart_Banner_Rest_Api
{
	/**
	 * The base REST mapping of the cart controller
	 *
	 * @var string $api_base
	 */
	private $api_base = 'smart-banner';

	/**
	 * Constsructs a SmartBannerRestApi object
	 */
	public function __construct()
	{
		add_action('rest_api_init', array($this, 'init_smart_banner_rest_api'));
	}

	/**
	 * Defines the smart banners REST API
	 */
	public function init_smart_banner_rest_api()
	{
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/update',
			array(
				'methods'             => 'POST',
				'args'                => array(
					'appleid'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'playid' => array(
						'type'     => 'string',
						'required' => true,
					),
					'title' => array(
						'type'     => 'string',
						'required' => true,
					),
					'author' => array(
						'type'     => 'string',
						'required' => true,
					),
					'button' => array(
						'type'     => 'string',
						'required' => true,
					),
					'image' => array(
						'type'     => 'string',
						'required' => true,
					),
					'price' => array(
						'type'     => 'string',
						'required' => true,
					)
				),
				'callback'            => array($this, 'update_smart_banner'),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			SHELFY_REST_API_NAMESPACE,
			$this->api_base . '/get-status',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'get_smart_banner_status'),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * update smart banner data
	 *
	 * @param WP_REST_Request $request The HTTP request.
	 */
	public function update_smart_banner($request)
	{
		update_option('appleid', $request['appleid']);
		update_option('playid', $request['playid']);
		update_option('title', $request['title']);
		update_option('author', $request['author']);
		update_option('button', $request['button']);
		update_option('price', $request['price']);
		update_option('image', $request['image']);
	}

	/**
	 * return status of active smart banners.
	 *
	 * @param WP_REST_Request $request The HTTP request.
	 * @return array ('google' => boolean, 'apple' => boolean)
	 */
	public function get_smart_banner_status()
	{
		$apple = false;
		$google = false;
		if (get_option('appleid') != null && get_option('appleid') != "")
			$apple = true;
		if (get_option('playid') != null && get_option('playid') != "")
			$google = true;
		return array(
			'google' => $google,
			'apple' => $apple,
		);
	}
}

new Shelfy_Smart_Banner_Rest_Api();
