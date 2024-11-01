<?php
/**
 * This is the plugin main class
 *
 * @package Shelfy
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The main plugin class
 */
class Shelfy {
	/**
	 * The name of the plugin
	 *
	 * @var string $plugin_name
	 */
	private $plugin_name;

	/**
	 * The constructor of the class
	 */
	public function __construct() {
		$this->plugin_name = 'Shelfy.io';
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->define_scripts();
		add_action(
			'plugins_loaded',
			function () {
				if ( $this->woocommerce_installed() && $this->woocommerce_version_supported() ) {
					$this->define_rest_api();
					$this->define_custom_webhooks();
				}
				$this->define_admin_pages();
			}
		);
		add_action( 'admin_notices', array( $this, 'define_admin_notices' ) );
		add_action( 'activated_plugin', array( $this, 'activated' ) );

		// Display custom cart item meta data (in cart and checkout).
		add_filter(
			'woocommerce_get_item_data',
			function ( $item_data, $cart_item ) {
				if ( isset( $cart_item['shelfy_dynamic_attributes'] ) ) {
					foreach ( $cart_item['shelfy_dynamic_attributes'] as $key => $value ) {
						$data = array(
							'key'   => $key,
							'value' => $value,
						);
						if ( is_array( $value ) ) {
							$data['display'] = join( ', ', $value );
						}
						$item_data[] = $data;
					}
				}
				return $item_data;
			},
			10,
			2
		);

		// Save cart item custom meta as order item meta data and display it everywhere on orders and email notifications.
		add_action(
			'woocommerce_checkout_create_order_line_item',
			function ( $item, $cart_item_key, $values, $order ) {
				if ( isset( $values['shelfy_dynamic_attributes'] ) ) {
					foreach ( $values['shelfy_dynamic_attributes'] as $key => $value ) {
						$item->update_meta_data( $key, $value );
					}
				}
			},
			10,
			4
		);
	}

	/**
	 * Asks Shelfy's back-end for the current status integration
	 *
	 * @return array|false application id and whether we should give shelfy permission
	 */
	public function update_backend_and_get_status() {
		global $wp_version;
		$wc_version = null;
		if (defined('WC_VERSION')) {
			$wc_version = WC_VERSION;
		}
		$response = wp_remote_post(
			SHELFY_BACKEND_BASE_URL . '/backend/woocommerce/plugin-activated',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'domain'              => get_home_url(),
					'plugin_version'      => SHELFY_VERSION,
					'wordpress_version'   => $wp_version,
					'woocommerce_version' => $wc_version,
					) ),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ) );
			return array(
				'applicationId'       => $body->data->applicationId,
				'tokensNeeded'        => $body->data->authenticationRequired,
				'integrationFinished' => $body->data->integrationFinished,
			);
		}
	}

	/**
	 * Whether WooCommerce is installed
	 *
	 * @return bool whether woocommerce installed
	 */
	public function woocommerce_installed() {
		return defined( 'WC_VERSION' );
	}

	/**
	 * Whether WooCommerce version is supported
	 *
	 * @return bool whether woocommerce version supported
	 */
	public function woocommerce_version_supported() {
		return version_compare( WC()->version, SHELFY_REQUIRED_WOOCOMMERCE_VERSION, '>=' );
	}

	/**
	 * Activation hook
	 */
	public function activate() {
	}

	/**
	 * Returns the destination URL of the redirect after authentication
	 *
	 * @param string $application_id Shelfy.io application ID retrieved by calling `update_backend_and_get_status()`.
	 * @return string URL to woocommerce authetication page.
	 */
	private function get_authetication_url( $application_id ) {
		return get_site_url( null, '/wc-auth/v1/authorize?app_name=' . $this->plugin_name . '&user_id=' . $application_id . '&return_url=' . SHELFY_BACKEND_BASE_URL . '/backend/woocommerce/after-authenticated&callback_url=' . SHELFY_BACKEND_BASE_URL . '/backend/woocommerce/plugin-authenticated&scope=read_write' );
	}

	/**
	 * After activation hook
	 *
	 * @param string $plugin The plugin that got activated.
	 */
	public function activated( $plugin ) {
		if ( plugin_basename( SHELFY_MAIN_PLUGIN_FILE ) === $plugin ) {
			$status = $this->update_backend_and_get_status();
			if ( ! $status ) {
				deactivate_plugins( $plugin );
				wp_die( esc_html( __( "Failed to register in Shelfy's backend!" ) ) );
			} else {
				add_option( 'shelfy_application_id', $status['applicationId'] );
				if ( ! $status['tokensNeeded'] ) {
					add_option( 'shelfy_authentication_received', true );
				}
				if ( $status['integrationFinished'] ) {
					add_option( 'shelfy_integration_finished', true );
				}
			}
		}
	}

	/**
	 * Deactivation hook
	 */
	public function deactivate() {
		delete_option( 'shelfy_integration_finished' );
		delete_option( 'shelfy_authentication_received' );
		if ( class_exists( 'WC_Webhook_Data_Store' ) ) {
			$store    = new WC_Webhook_Data_Store();
			$webhooks = $store->search_webhooks( array( 'search' => SHELFY_WEBHOOKS_NAME ) );
			foreach ( $webhooks as $webhook ) {
				$store->delete( new WC_Webhook( $webhook ) );
			}
		}

		add_option( 'shelfy_deactivation', true );
		wp_remote_post(
			SHELFY_BACKEND_BASE_URL . '/backend/woocommerce/plugin-deactivated',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'applicationId' => $this->get_shelfy_application_id() ) ),
				'timeout' => 20,
			)
		);
		delete_option( 'shelfy_deactivation' );

		delete_option( 'shelfy_application_id' );
	}

	/**
	 * Defines our REST API
	 */
	private function define_rest_api() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/controllers/class-shelfy-customer-rest-api.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/controllers/class-shelfy-integration-rest-api.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-shelfy-cart-item-schema-ex.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/controllers/class-shelfy-cart-rest-api.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/controllers/class-shelfy-cart-v2-rest-api.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/controllers/class-shelfy-smart-banner-rest-api.php';
	}

	/**
	 * Defines our custom webhooks and payloads
	 */
	private function define_custom_webhooks() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/webhooks/webhooks-category.php';
	}

	/**
	 * Enqueue our script to change the text of the deacticate button
	 */
	private function define_scripts() {
		add_action(
			'admin_enqueue_scripts',
			function ( $page ) {
				if ( 'plugins.php' === $page ) {
					wp_enqueue_script( 'shelfy_plugins_page_script', plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) . 'admin/scripts/plugins.js', array( 'jquery' ), '1.0.0', false );
				}
			}
		);
	}

	/**
	 * Whether shelfy's backend has valid credentials to the site
	 *
	 * @return bool whether Shelfy's backend has API keys
	 */
	private function authentication_granted() {
		return get_option( 'shelfy_authentication_received' );
	}

	/**
	 * Wheter the integration has finished
	 *
	 * @return bool whether the site finished onboarding in shelfy
	 */
	private function integration_finished() {
		return get_option( 'shelfy_integration_finished' );
	}

	/**
	 * Returns shelfy's application ID
	 *
	 * @return string|false Shelfy application ID if exist
	 */
	private function get_shelfy_application_id() {
		return get_option( 'shelfy_application_id', false );
	}

	/**
	 * Renders admin errors notices
	 *
	 * @param string $text notification text.
	 * @param array  $buttons array of associative array of ('href' => string, 'text' => string, 'target' => string?, 'classes' => string?).
	 */
	private function render_admin_error_notice( $text, $buttons ) {
		?>
		<style>
			@font-face {
				font-family: 'Montserrat';
				src: url(<?php echo esc_html( plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) ) . 'assets/Montserrat-Regular.ttf'; ?>);
			}

			@font-face {
				font-family: 'Montserrat semi-bold';
				src: url(<?php echo esc_html( plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) ) . 'assets/Montserrat-SemiBold.ttf'; ?>);
			}
		</style>
		<div class="notice notice-error" style="border-inline-start-width: 7px; border-inline-start-color: #F00; font-family: 'Montserrat';">
			<div style="display: flex; padding-block: 20px; align-items: center; justify-content: space-between">
				<div style="display: flex; align-items: center; column-gap: 10px;">
					<img style="display: block;" width="30" height="30" src="<?php echo esc_html( plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) ) . 'assets/icon.png'; ?>">
					<div style="max-width: 30vw;">
						<h4 style="margin: 0; font-weight: normal; font-family: 'Montserrat semi-bold';">Shelfy.io</h4>
						<p style="margin: 0;"><?php echo esc_html( $text ); ?></p>
					</div>
				</div>
				<div>
					<?php foreach ( $buttons as $button ) : ?>
						<?php
						$classes = isset( $button['classes'] ) ? $button['classes'] : '';
						$target  = isset( $button['target'] ) ? $button['target'] : '';
						?>
						<a class="button button-primary <?php echo esc_html( $classes ); ?>" target="<?php echo esc_html( $target ); ?>" href="<?php echo esc_html( $button['href'] ); ?>"><?php echo esc_html( $button['text'] ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns on-boarding URL
	 */
	private function get_onboarding_url() {
		return SHELFY_BACKEND_BASE_URL . '/backend/woocommerce/after-authenticated?success=1&user_id=' . $this->get_shelfy_application_id();
	}

	/**
	 * Whether WooCommerce is installed but not active
	 */
	private function woocommerce_instsalled_but_not_active() {
		$plugins        = get_plugins();
		$active_plugins = get_option( 'active_plugins' );
		return array_key_exists( 'woocommerce/woocommerce.php', $plugins ) && ! array_key_exists( 'woocommerce/woocommerce.php', $active_plugins );
	}

	/**
	 * Returns link to WooCommerce installation page
	 */
	private function get_woocommerce_installation_url() {
		return get_admin_url( null, 'plugin-install.php?tab=plugin-information&plugin=woocommerce' );
	}

	/**
	 * Returns link for activating WooCommerce
	 */
	private function get_woocommerce_activation_url() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'activate',
					'plugin' => 'woocommerce/woocommerce.php',
				),
				admin_url( 'plugins.php' )
			),
			'activate-plugin_woocommerce/woocommerce.php'
		);
	}

	/**
	 * Renders admin notices in the plugin page
	 */
	public function define_admin_notices() {
		global $pagenow;
		if ( current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ) && 'plugins.php' === $pagenow ) {
			if ( ! $this->woocommerce_installed() ) {
				$woocommerce_installed_but_inactive = $this->woocommerce_instsalled_but_not_active();
				$this->render_admin_error_notice(
					'Whoops, we need WooCommerce to run, it seems like you don’t have it ' . ( $woocommerce_installed_but_inactive ? 'activated' : 'installed' ) . '. Click to ' . ( $woocommerce_installed_but_inactive ? 'activate' : 'install' ) . '.',
					array(
						array(
							'href' => $woocommerce_installed_but_inactive ? $this->get_woocommerce_activation_url() : $this->get_woocommerce_installation_url(),
							'text' => $woocommerce_installed_but_inactive ? 'Activate WooCommerce' : 'Install WooCommerce',
						),
					)
				);
			} elseif ( ! $this->woocommerce_version_supported() ) {
				$this->render_admin_error_notice(
					'Whoops, seems like your running an older version of WooCommerce. Click to upgrade',
					array(
						array(
							'href'    => get_admin_url( null, 'plugin-install.php?tab=plugin-information&plugin=woocommerce&TB_iframe=true&width=772&height=935' ),
							'text'    => 'Update WooCommerce',
							'classes' => 'thickbox open-plugin-details-modal',
						),
					)
				);
			} elseif ( ! $this->authentication_granted() ) {
				$this->render_admin_error_notice(
					'Whoops, you forgot to give Shelfy read, write and edit permissions. Click to continue.',
					array(
						array(
							'href' => $this->get_authetication_url( $this->get_shelfy_application_id() ),
							'text' => 'Give Permissions',
						),
					)
				);
			} elseif ( ! $this->integration_finished() ) {
				$this->render_admin_error_notice(
					'Whoops, you forgot to finish the integration. No big deal, click to continue.',
					array(
						array(
							'href'   => $this->get_onboarding_url(),
							'text'   => 'Continue to Shelfy.io',
							'target' => '_blank',
						),
					)
				);
			}
		}
	}

	/**
	 * Adds shelfy page admin menu item
	 */
	private function define_admin_pages() {
		if ( $this->woocommerce_installed() && $this->woocommerce_version_supported() && $this->authentication_granted() ) {
			add_action(
				'admin_menu',
				function () {
					add_menu_page( 'Shelfy.io', 'Shelfy.io', 'manage_woocommerce', 'shelfyio', array( $this, 'admin_page' ), plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) . 'assets/icon-menu.png' );
				}
			);
		}
	}

	/**
	 * Renders the admin page
	 */
	public function admin_page() {
		?>
		<style>
			.shelfy-card {
				width: 500px;
				height: 250px;
				background: #FEFEFE;
				border: 1px solid #F4F4F4;
				box-sizing: border-box;
				box-shadow: 0px 0px 10px rgba(33, 47, 87, 0.15);
				font-size: 15px;
				text-align: center;
				display: grid;
				justify-content: center;
				align-content: space-between;
				padding-inline: 50px;
				padding-block: 30px;
			}

			.shelfy-card-title,
			.shelfy-button {
				font-size: 20px;
				font-weight: 700;
			}

			.shelfy-button,
			.shelfy-button:focus,
			.shelfy-button:active {
				outline: 0;
				border: none;
				outline-style: none;
				-moz-outline-style: none;
				box-shadow: none;
				position: relative;
				z-index: 1;
				height: 45px;
				min-width: 150px;
				overflow: hidden;
				display: inline-flex;
				margin: auto;
				justify-content: center;
				align-items: center;
				border-radius: 22px;
				color: #fff;
				font-size: 20px;
				font-weight: 700;
				text-decoration: none;
				cursor: pointer;
				padding-inline: 40px;
				transition: all .5s;
				-webkit-appearance: none !important;
				background-color: #ee457a;
			}

			.shelfy-button::before {
				content: "";
				position: absolute;
				width: 100%;
				height: 100%;
				background: #f9c650;
				z-index: -1;
				transform: translateY(-100%);
				transition: 0.5s;
			}

			.shelfy-button:hover {
				color: #2a3240;
				background-color: transparent;
			}

			.shelfy-button:hover::before {
				color: #2a3240;
				transform: translateY(0);
			}

			.shelfy-content>p {
				font-size: 15px;
			}

			@font-face {
				font-family: 'Montserrat';
				src: url(<?php echo esc_html( plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) ) . 'assets/Montserrat-Regular.ttf'; ?>);
			}

			@font-face {
				font-family: 'Montserrat semi-bold';
				src: url(<?php echo esc_html( plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) ) . 'assets/Montserrat-SemiBold.ttf'; ?>);
			}
		</style>
		<div class="wrap" style="font-family: 'Montserrat';">
			<div style="height: 85vh; display: grid; justify-items: center; align-content: center; padding-inline-end: 100px; padding-inline-start: 80px;">
				<div style="display: flex; flex-direction: column; row-gap: 10vh; align-items: center; width: 100%;">
					<div style="display: flex; align-items: center; column-gap: 1vw; -moz-user-select: none; -webkit-user-select: none;">
						<img style="display: block; width: 400px" src="<?php echo esc_html( plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) ) . 'assets/icon-big.png'; ?>">
						<img style="display: block; width: 40px" src="<?php echo esc_html( plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) ) . 'assets/related.svg'; ?>">
						<img style="display: block; width: 260px" src="<?php echo esc_html( plugin_dir_url( SHELFY_MAIN_PLUGIN_FILE ) ) . 'assets/woocommerce-logo.png'; ?>">
					</div>
					<div style="display: flex; column-gap: 2vw; width: 100%; justify-content: center;">
						<?php
						if ( ! $this->integration_finished() ) {
							$this->render_cards_integration_not_finished();
						} else {
							$this->render_cards_shelfy();
						}
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders cards for integration not finished
	 */
	private function render_cards_integration_not_finished() {
		?>
		<div class="shelfy-card" style="width: 600px;">
			<div class="shelfy-content">
				<h3 class="shelfy-card-title">You are almost done</h3>
				<p>Just a few more steps to connect your store to Shelfy.io</p>
			</div>
			<a href="<?php echo esc_html( $this->get_onboarding_url() ); ?>" class="shelfy-button">Connect</a>
		</div>
		<?php
	}

	/**
	 * Renders shelfy cards
	 */
	private function render_cards_shelfy() {
		?>
		<div class="shelfy-card">
			<div class="shelfy-content">
				<h3 class="shelfy-card-title">Build Mobile App</h3>
				<p>Now the fun begins, head on over our drag and drop dashboard to get started. Building an app has never been easier.</p>
			</div>
			<a href="<?php echo esc_html( SHELFY_FRONTEND_BASE_URL ); ?>" class="shelfy-button">Open Dashboard</a>
		</div>
		<div class="shelfy-card">
			<div class="shelfy-content">
				<h3 class="shelfy-card-title">Pricing</h3>
				<p>Our pricing is based on a monthly usage fee. See what plan is right for you.</p>
			</div>
			<a href="https://shelfy.io/contact/" class="shelfy-button">Plans</a>
		</div>
		<?php
	}
}
