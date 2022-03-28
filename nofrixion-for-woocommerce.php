<?php
/**
 * Plugin Name:     NoFrixion for WooCommerce
 * Plugin URI:      https://wordpress.org/plugins/nofrixion-for-woocommerce/
 * Description:
 * Author:          NoFrixion
 * Author URI:      https://nofrixion.com
 * Text Domain:     nofrixion-for-woocommerce
 * Domain Path:     /languages
 * Version:         0.1.0
 * Requires PHP:    7.4
 * Tested up to:    5.9
 * Requires at least: 5.2
 */

defined( 'ABSPATH' ) || exit();

define( 'NOFRIXION_VERSION', '0.1.0' );
define( 'NOFRIXION_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOFRIXION_PLUGIN_URL', plugin_dir_url(__FILE__ ) );
define( 'NOFRIXION_PLUGIN_ID', 'nofrixion-for-woocommerce' );

class NoFrixionWCPlugin {

	private static $instance;

	public function __construct() {
		$this->includes();

		add_action('woocommerce_thankyou_nofrixion', [$this, 'orderStatusThankYouPage'], 10, 1);

		if (is_admin()) {
			// Register our custom global settings page.
			add_filter(
				'woocommerce_get_settings_pages',
				function ($settings) {
					$settings[] = new \NoFrixion\WC\Admin\GlobalSettings();

					return $settings;
				}
			);
			add_action( 'wp_ajax_handle_ajax_api_url', [$this, 'processAjaxApiUrl'] );

			$this->dependenciesNotification();
			$this->notConfiguredNotification();
		}
	}

	public function includes(): void {
		$autoloader = NOFRIXION_PLUGIN_FILE_PATH . 'vendor/autoload.php';
		if (file_exists($autoloader)) {
			/** @noinspection PhpIncludeInspection */
			require_once $autoloader;
		}

		// Make sure WP internal functions are available.
		if ( ! function_exists('is_plugin_active') ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	public static function initPaymentGateway($gateways): array {
		// Add NoFrixion gateway to WooCommerce.
		$gateways[] = \NoFrixion\WC\Gateway\NoFrixionGateway::class;

		return $gateways;
	}

	/**
	 * Displays notice (and link to config page) on admin dashboard if the plugin is not configured yet.
	 */
	public function notConfiguredNotification(): void {
		/*
		if (!\NoFrixion\WC\Helper\GreenfieldApiHelper::getConfig()) {
			$message = sprintf(
				esc_html__(
					'Plugin not configured yet, please %1$sconfigure the plugin here%2$s',
					'nofrixion-for-woocommerce'
				),
				'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=btcpay_settings')) . '">',
				'</a>'
			);

			\NoFrixion\WC\Admin\Notice::addNotice('error', $message);
		}
		*/
	}

	/**
	 * Checks and displays notice on admin dashboard if PHP version is too low or WooCommerce not installed.
	 */
	public function dependenciesNotification() {
		// Check PHP version.
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$versionMessage = sprintf( __( 'Your PHP version is %s but NoFrixion Greenfield Payment plugin requires version 7.4+.', 'nofrixion-for-woocommerce' ), PHP_VERSION );
			\NoFrixion\WC\Admin\Notice::addNotice('error', $versionMessage);
		}

		// Check if WooCommerce is installed.
		if ( ! is_plugin_active('woocommerce/woocommerce.php') ) {
			$wcMessage = __('WooCommerce seems to be not installed. Make sure you do before you activate NoFrixion Payment Gateway.', 'nofrixion-for-woocommerce');
			\NoFrixion\WC\Admin\Notice::addNotice('error', $wcMessage);
		}

	}

	/**
	 * Handles the AJAX callback from the GlobalSettings form. Unfortunately with namespaces it seems to not work
	 * to have this method on the GlobalSettings class. So keeping it here for the time being.
	 */
	public function processAjaxApiUrl() {
		$nonce = $_POST['apiNonce'];
		if ( ! wp_verify_nonce( $nonce, 'nofrixion-api-url-nonce' ) ) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		if ( current_user_can( 'manage_options' ) ) {
			$host = filter_var($_POST['host'], FILTER_VALIDATE_URL);

			if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
				wp_send_json_error("Error validating NoFrixion URL.");
			}

			try {
				// Create the redirect url to NoFrixion instance.
				$url = \NoFrixion\Client\ApiKey::getAuthorizeUrl(
					$host,
					\NoFrixion\WC\Helper\GreenfieldApiAuthorization::REQUIRED_PERMISSIONS,
					'WooCommerce',
					true,
					true,
					home_url('?btcpay-settings-callback'),
					null
				);

				// Store the host to options before we leave the site.
				update_option('nofrixion_url', $host);

				// Return the redirect url.
				wp_send_json_success(['url' => $url]);
			} catch (\Throwable $e) {
				\NoFrixion\WC\Helper\Logger::debug('Error fetching redirect url from NoFrixion Server.');
			}
		}

		wp_send_json_error("Error processing Ajax request.");
	}

	public function orderStatusThankYouPage($order_id)
	{
		if (!$order = wc_get_order($order_id)) {
			return;
		}

		$title = _x('Payment Status', 'nofrixion-for-woocommerce');

		$orderData = $order->get_data();
		$status = $orderData['status'];

		switch ($status)
		{
			case 'on-hold':
				$statusDesc = _x('Waiting for payment settlement', 'nofrixion-for-woocommerce');
				break;
			case 'processing':
				$statusDesc = _x('Payment processing', 'nofrixion-for-woocommerce');
				break;
			case 'completed':
				$statusDesc = _x('Payment settled', 'nofrixion-for-woocommerce');
				break;
			case 'failed':
				$statusDesc = _x('Payment failed', 'nofrixion-for-woocommerce');
				break;
			default:
				$statusDesc = _x(ucfirst($status), 'nofrixion-for-woocommerce');
				break;
		}

		echo "
		<section class='woocommerce-order-payment-status'>
		    <h2 class='woocommerce-order-payment-status-title'>{$title}</h2>
		    <p><strong>{$statusDesc}</strong></p>
		</section>
		";
	}


	/**
	 * Gets the main plugin loader instance.
	 *
	 * Ensures only one instance can be loaded.
	 *
	 * @return \NoFrixionWCPlugin
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

// Start everything up.
function init_nofrixion() {
	\NoFrixionWCPlugin::instance();
}

/**
 * Bootstrap stuff on init.
 */
add_action('init', function() {
	// Adding textdomain and translation support.
	load_plugin_textdomain('nofrixion-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');
});

// Initialize payment gateways and plugin.
add_filter( 'woocommerce_payment_gateways', [ 'NoFrixionWCPlugin', 'initPaymentGateway' ] );
add_action( 'plugins_loaded', 'init_nofrixion', 0 );
