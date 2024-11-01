<?php

/**
 * The entry point of the plugin.
 *
 * @package Shelfy plugin
 * @wordpress-plugin
 * Plugin Name:       Shelfy - Mobile App Builder
 * Description:       Build fast mobile apps that you control — no coding is required.
 * Version:           1.0.8
 * Stable tag:        1.0.8
 * WC requires at least: 5.0
 * WC tested up to: 6.7
 * Requires at least: 5.8
 * Author:            Shelfy.io
 * Author URI:        https://shelfy.io/
 * License:           GPL-2.0+
 * Requires PHP:      7.3
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'SHELFY_VERSION', '1.0.8' );
define( 'SHELFY_MAIN_PLUGIN_FILE', __FILE__ );
define( 'SHELFY_REQUIRED_WOOCOMMERCE_VERSION', '5.8.0' );
define( 'SHELFY_BACKEND_BASE_URL', 'https://woocommerce-prd.shelfytech.com' );
define( 'SHELFY_FRONTEND_BASE_URL', 'https://shelfyconsole.io' );
define( 'SHELFY_WEBHOOKS_NAME', 'Shelfy webhook subscription - DO NOT DELETE' );
/**
 * NOTE (ItzikSn):
 * SHELFY_REST_API_NAMESPACE has to contain 'wc-' for woocommerce to authenticate incoming requests
 */
define( 'SHELFY_REST_API_NAMESPACE', 'wc-shelfy' );

/**
 * The core plugin class that is used to define
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-shelfy.php';

/**
 * The entry point of the plugin
 */
function shelfy_run() {
	$plugin = new Shelfy();
	$plugin->run();
	register_activation_hook( __FILE__, array( $plugin, 'activate' ) );
	register_deactivation_hook( __FILE__, array( $plugin, 'deactivate' ) );
}

function load_script() {
	wp_enqueue_script( 'edit_js', plugins_url( '/assets/shelfy-smart-app-banner.js', __FILE__ ), false );
}

function load_enqueue_style() {
	wp_enqueue_style( 'edit_style', plugins_url( '/assets/shelfy-smart-app-banner.css', __FILE__ ), false );
}

function asab_page_settings() {
	require( "includes\controllers\class-shelfy-smart-banner-rest-api.php" );
}

$sabarray['appleid']      		= (get_option('appleid') != null) ? get_option('appleid') : "";
$sabarray['playid']       		= (get_option('playid') != null) ? get_option('playid') : "";
$sabarray['dayshidden']   		= 15;
$sabarray['daysreminder'] 		= 20;
$sabarray['title']        		= (get_option('title') != null) ? get_option('title') : 'TRY NOW';
$sabarray['author']       		= (get_option('author') != null) ? get_option('author') : 'Our shelf based app!';
$sabarray['button']       		= (get_option('button') != null) ? get_option('button') : 'Get It';
$sabarray['price']        		= (get_option('price') != null) ? get_option('price') : 'Free';
$sabarray['textColor']    		= (get_option('textColor') != null) ? get_option('textColor') : 'white';
$sabarray['backgroundColor']	= (get_option('backgroundColor') != null) ? get_option('backgroundColor') : 'black';
$sabarray['buttonColor']        = (get_option('buttonColor') != null) ? get_option('buttonColor') : 'blue';
$sabarray['image']				= (get_option('image') != null) ? get_option('image') : 'https://cdn.shopify.com/app-store/listing_images/1368125bdc76573a4702bbb5377ddda8/icon/CJfa8orPqvkCEAE=.png';


function edit_head()
{
	global $sabarray;
	echo '<!-- SHELFY Smart App banners -->' . "\t\n";
	if ($sabarray['appleid'] != "") {
		echo '<meta name="apple-itunes-app" content="app-id=' . $sabarray['appleid'] . '">' . "\t\n";
	}
	if ($sabarray['playid'] != "") {
		echo '<meta name="google-play-app" content="app-id=' . $sabarray['playid'] . '">' . "\t\n";
	}
	if ($sabarray['image'] != null) {
		echo '<link rel="apple-touch-icon" href="' . $sabarray['image'] . '">' . "\t\n";
		echo '<link rel="android-touch-icon" href="' . $sabarray['image'] . '" />' . "\t\n";
	}
	echo '<!-- SHELFY Smart App banners -->' . "\t\n";
}

function edit_footer()
{
	global $sabarray;
	echo "<script type=\"text/javascript\">
      new SmartBanner({
          daysHidden: " . $sabarray['dayshidden'] . ",   
          daysReminder: " . $sabarray['daysreminder'] . ",
          appStoreLanguage: 'us', 
          title: '" . $sabarray['title'] . "',
          author: '" . $sabarray['author'] . "',
          button: '" . $sabarray['button'] . "',
		  textColor: '" . $sabarray['textColor'] . "',
		  backgroundColor: '" . $sabarray['backgroundColor'] . "',
		  buttonColor: '" . $sabarray['buttonColor'] . "',
          // , force: 'ios' // Uncomment for platform emulation
      });
    </script>";
}

function shelfy_modify_prices( $cart_object ) {
	if ( isset( $_COOKIE["shelfy_cart_count"] ) ) {
		$count_value = intval( $_COOKIE["shelfy_cart_count"] );


		$map = array();
		for ( $i = 0; $i < $count_value; $i ++ ) {
			$cookie_index = "shelfy_cart" . $i;
			if ( isset( $_COOKIE[ $cookie_index ] ) ) {
				$str  = base64_decode( $_COOKIE[ $cookie_index ], true );
				$str  = str_replace( '\\', '', $str );
				$data = json_decode( $str, true );
				if ( $str[0] == '[' && $str[ strlen( $str ) - 1 ] == ']' ) {
					$data = json_decode( substr( $str, 1, strlen( $str ) - 2 ), true );
				}


				foreach ( $data["ps"] as $row ) {
					$key = $row["p"] . '_' . $row["v"];
					if ( ! array_key_exists( $key, $map ) ) {
						$map[ $key ] = [];
					}
					array_push( $map[ $key ], $row );
				}
			}
		}

		$cart_items = $cart_object->cart_contents;

		if ( ! empty( $cart_items ) ) {
			foreach ( $cart_items as $key => $value ) {
				$variant_id = $value['variation_id'];
				if ( $variant_id == 0 ) {
					$variant_id = $value['product_id'];
				}
				$itemKey = $value['product_id'] . '_' . $variant_id;
				if ( array_key_exists( $itemKey, $map ) ) {
					foreach ( $map[ $itemKey ] as $k => $cart_product ) {
						$diff = json_decode( json_encode( $value['shelfy_dynamic_attributes'] ) ) == json_decode( json_encode( $cart_product["d"] ) );
						if ( $diff ) {
							$value['data']->set_price( $cart_product["u"] );
						}
					}
				}
			}
		}
	}
}


add_action( 'wp_enqueue_scripts', 'load_enqueue_style' );
add_action( 'wp_enqueue_scripts', 'load_script' );
add_action( 'wp_head', 'edit_head' );
add_action( 'wp_footer', 'edit_footer' );
add_action( 'woocommerce_before_calculate_totals', 'shelfy_modify_prices' );

shelfy_run();
