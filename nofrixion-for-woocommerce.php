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

use NoFrixion\WC\Client\PaymentRequest;
use NoFrixion\WC\Helper\Logger;

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
		add_action('wp_footer', [$this, 'addNoFrixionContainer'], 10, 1);
		add_action( 'wp_ajax_nofrixion_payment_request', [$this, 'processAjaxPaymentRequest'] );
		add_action( 'wp_ajax_nopriv_nofrixion_payment_request', [$this, 'processAjaxApiUrl'] );
		add_filter( 'woocommerce_available_payment_gateways', [$this, 'showOnlyNofrixionGateway']);
		add_filter( 'wp_enqueue_scripts', [$this, 'addScripts']);

		if (is_admin()) {
			// Register our custom global settings page.
			add_filter(
				'woocommerce_get_settings_pages',
				function ($settings) {
					$settings[] = new \NoFrixion\WC\Admin\GlobalSettings();

					return $settings;
				}
			);

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
	 * Handles the AJAX callback from the Payment Request on the checkout page.
	 */
	public function processAjaxPaymentRequest() {

		$nonce = $_POST['apiNonce'];
		if ( ! wp_verify_nonce( $nonce, 'nofrixion-nonce' ) ) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}

		try {
			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
			$cart = WC()->cart;
			$checkout = WC()->checkout();
			$orderId = $checkout->create_order([]);
			$order = wc_get_order($orderId);
			$order->set_payment_method('nofrixion');
			$order->set_payment_method_title($available_gateways[ 'nofrixion' ]->title);
			$order->save();

			// Process Payment via NoFrixion to get the PaymentRequestId.
			$result = $available_gateways[ 'nofrixion' ]->process_payment($orderId);

			wp_send_json_success(
				[
					'paymentRequestId' => $result['paymentRequestId'] ?? null,
					'orderId' => $order ? $order->get_id() : 0,
				]
			);
		} catch (\Throwable $e) {
			\NoFrixion\WC\Helper\Logger::debug('Error processing payment request ajax callback: ' . $e->getMessage());
		}


		wp_send_json_error("Error processing request.");
	}

	/**
	 * Show only NoFrixion gateway on order payment page.
	 */
	public function showOnlyNofrixionGateway($available_gateways) {
		global $woocommerce;

		$endpoint = $woocommerce->query->get_current_endpoint();

		if ($endpoint == 'order-pay') {
			foreach ($available_gateways as $key => $data) {
				if ($key !== 'nofrixion') {
					unset($available_gateways[$key]);
				}
			}
		}

		return $available_gateways;
	}

	/**
	 * The payment received page is used as the callbackUrl of NoFrixion to check the PaymentRequest status and update
	 * the order. The PaymentRequest check is done on each visit of that page but stops if the payment failed or succeeded.
	 */
	public function orderStatusThankYouPage($order_id)
	{
		if (!$order = wc_get_order($order_id)) {
			return;
		}

		$currentOrderStatus = $order->get_status();
		$paymentRequestID = $order->get_meta('NoFrixion_id');

		if (
			!in_array($currentOrderStatus, ['processing', 'completed', 'failed']) &&
			$paymentRequestID
		) {
			// Check PaymentRequestStatus.
			try {
				$gatewayConfig = get_option('woocommerce_nofrixion_settings');

				$client = new PaymentRequest( $gatewayConfig['url'], $gatewayConfig['apikey']);
				$paymentRequest = $client->getPaymentRequestStatus($paymentRequestID);

				if (isset($paymentRequest['result'])) {
					switch ( $paymentRequest['result'] ) {
						case "FullyPaid":
							$order->payment_complete();
							$order->save();
							break;
						case "Voided":
							$order->update_status( 'failed' );
							$order->add_order_note( _x( 'Payment failed, please make a new order or get in contact with us.', 'nofrixion-for-woocommerce' ) );
							$order->save();
							break;
						case "None":
							// Do nothing, keeps order in pending state.
						default:
							// Do nothing.
					}
				}

			} catch ( \Throwable $e ) {
				Logger::debug('Problem fetching PaymentRequest status:', true);
				Logger::debug( $e->getMessage(), true );
			}
		}

		$orderData = $order->get_data();
		$status = $orderData['status'];

		switch ($status)
		{
			case 'on-hold':
			case 'pending':
				$statusDesc = _x('Waiting for payment settlement', 'nofrixion-for-woocommerce');
				break;
			case 'processing':
				$statusDesc = _x('Payment completed, processing your order.', 'nofrixion-for-woocommerce');
				break;
			case 'completed':
				$statusDesc = _x('Payment completed', 'nofrixion-for-woocommerce');
				break;
			case 'failed':
				$statusDesc = _x('Payment failed', 'nofrixion-for-woocommerce');
				break;
			default:
				$statusDesc = _x(ucfirst($status), 'nofrixion-for-woocommerce');
				break;
		}

		$title = _x('Payment Status', 'nofrixion-for-woocommerce');

		echo "
		<section class='woocommerce-order-payment-status'>
		    <h2 class='woocommerce-order-payment-status-title'>{$title}</h2>
		    <p><strong>{$statusDesc}</strong></p>
		</section>
		";
	}

	/**
	 * Adds the overlay and NoFrixion payframe div on the checkout page.
	 */
	public function addNoFrixionContainer(){
		echo "
		<div class='wc-nofrixion-overlay'>
			<div id='nf-payframe' style='border: none; width: 350px; height: 800px;'></div>
		</div>
		";
	}

	public function addScripts() {
		wp_register_style( 'nofrixion-style', NOFRIXION_PLUGIN_URL . 'assets/css/nofrixion.css' );
		wp_enqueue_style( 'nofrixion-style' );
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
