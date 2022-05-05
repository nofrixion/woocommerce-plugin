<?php
/**
 * Plugin Name:     NoFrixion for WooCommerce
 * Plugin URI:      https://wordpress.org/plugins/nofrixion-for-woocommerce/
 * Description:
 * Author:          NoFrixion
 * Author URI:      https://nofrixion.com
 * Text Domain:     nofrixion-for-woocommerce
 * Domain Path:     /languages
 * Version:         0.8.2
 * Requires PHP:    7.4
 * Tested up to:    5.9
 * Requires at least: 5.2
 */

use NoFrixion\WC\Client\PaymentRequest;
use NoFrixion\WC\Helper\ApiHelper;
use NoFrixion\WC\Helper\Logger;

defined( 'ABSPATH' ) || exit();

define( 'NOFRIXION_VERSION', '0.8.2' );
define( 'NOFRIXION_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOFRIXION_PLUGIN_URL', plugin_dir_url(__FILE__ ) );
define( 'NOFRIXION_PLUGIN_ID', 'nofrixion-for-woocommerce' );

class NoFrixionWCPlugin {

	const SESSION_PAYMENTREQUEST_ID = 'nofrixion_payment_request_id';

	private static $instance;

	public function __construct() {
		$this->includes();

		add_action('woocommerce_thankyou_nofrixion_card', [$this, 'orderStatusThankYouPage'], 10, 1);
		add_action('woocommerce_thankyou_nofrixion_pisp', [$this, 'orderStatusThankYouPage'], 10, 1);
		add_action( 'wp_ajax_nofrixion_payment_request_init', [$this, 'processAjaxPaymentRequestInit'] );
		add_action( 'wp_ajax_nopriv_nofrixion_payment_request_init', [$this, 'processAjaxPaymentRequestInit'] );
		add_action( 'wp_ajax_nofrixion_payment_request', [$this, 'processAjaxPaymentRequestOrder'] );
		add_action( 'wp_ajax_nopriv_nofrixion_payment_request', [$this, 'processAjaxPaymentRequestOrder'] );
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

	public static function initPaymentGateways($gateways): array {
		// Add NoFrixion gateway to WooCommerce.
		$gateways[] = \NoFrixion\WC\Gateway\NoFrixionCard::class;
		$gateways[] = \NoFrixion\WC\Gateway\NoFrixionPisp::class;

		return $gateways;
	}

	/**
	 * Displays notice (and link to config page) on admin dashboard if the plugin is not configured yet.
	 */
	public function notConfiguredNotification(): void {
		$apiHelper = new ApiHelper();
		if (!$apiHelper->isConfigured()) {
			$message = sprintf(
				esc_html__(
					'Plugin not configured yet, please %1$sconfigure the plugin here%2$s',
					'nofrixion-for-woocommerce'
				),
				'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=nofrixion_settings')) . '">',
				'</a>'
			);

			\NoFrixion\WC\Admin\Notice::addNotice('error', $message);
		}
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
	public function processAjaxPaymentRequestInit() {

		Logger::debug('Entering processAjaxPaymentRequestInit()');

		$nonce = $_POST['apiNonce'];
		if ( ! wp_verify_nonce( $nonce, 'nofrixion-nonce' ) ) {
			wp_die( 'Unauthorized!', '', [ 'response' => 401 ] );
		}

		$total = (float) WC()->cart->get_total();

		try {
			$apiHelper = new ApiHelper();
			$client = new PaymentRequest( $apiHelper->url, $apiHelper->apiToken);
			$result = $client->createPaymentRequest(
				site_url(),
				site_url() . '/dummyreturnurl',
				\NoFrixion\WC\Helper\PreciseNumber::parseFloat($total),
				null,
				['card'],
				null,
				true,
				'temp' . WC()->cart->get_customer()->get_id()
			);

			Logger::debug('result from dummy: ' . print_r($result, true));

			// Store payment request id in the session.
			// Todo: needs more testing if gets cleared after checkout complete to not reuse old data.
			// WC()->session->set(self::SESSION_PAYMENTREQUEST_ID, $result['id']);

			wp_send_json_success(
				[
					'paymentRequestId' => $result['id'] ?? null,
				]
			);
		} catch (\Throwable $e) {
			Logger::debug('Error creating payment request: ' . $e->getMessage());
		}

		wp_send_json_error();
	}

		/**
	 * Handles the AJAX callback from the Payment Request on the checkout page.
	 */
	public function processAjaxPaymentRequestOrder() {

		Logger::debug('Entering processAjaxPaymentRequestOrder()');

		$nonce = $_POST['apiNonce'];
		if ( ! wp_verify_nonce( $nonce, 'nofrixion-nonce' ) ) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		// Make sure the submitted payment request id and the one in the session are the same.
		// Todo: needs more testing if gets cleared after checkout complete to not reuse old data.
		/*
		$sessionPRId = WC()->session->get(self::SESSION_PAYMENTREQUEST_ID);
		$submittedPRId =  wc_clean( wp_unslash( $_POST['payment_request_id']));
		if ($sessionPRId !== $submittedPRId) {
			Logger::debug('Submitted and session payment ids differ, aborting.');
			Logger::debug('Submitted: ' . $submittedPRId . ' Session: ' . $sessionPRId);
			wp_send_json_error();
		}
		*/

		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			WC()->checkout()->process_checkout();
		} catch (\Throwable $e) {
			Logger::debug('Error processing payment request ajax callback: ' . $e->getMessage());
		}
	}

	public function orderHasSubscription($order) {
		if (!function_exists('wcs_order_contains_subscription')) {
			return false;
		}
		return wcs_order_contains_subscription($order);
	}

	public function processAjaxUpdateOrder() {
		$nonce = $_POST['apiNonce'];
		if ( ! wp_verify_nonce( $nonce, 'nofrixion-nonce' ) ) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}

		// todo: for submission to wp directory we probably need to iterate and sanitize here instead of the called function below
		$fields = $_POST['fields'];

		$orderId = wc_sanitize_order_id($_POST['orderId']);

		$order = new \WC_Order($orderId);
		$this->updateOrderFields($order, $fields);
		$order->save();

		wp_send_json_success(
			[
				'orderId' => $orderId
			]
		);
	}

	public function updateOrderFields(\WC_Order &$order, array $fields) {
		// todo list of specific stuff to update.
		foreach ($fields as $field) {
			$method = 'set_' . $field['name'];
			if (method_exists($order, $method)) {
				$order->$method(wc_clean(wp_unslash($field['value'])));
			}
		}
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
		$isSubscription = (bool) $order->get_meta('NoFrixion_isSubscription');

		if (
			!in_array($currentOrderStatus, ['processing', 'completed', 'failed']) &&
			$paymentRequestID
		) {
			// Check PaymentRequestStatus.
			try {
				$apiHelper = new ApiHelper();

				$client = new PaymentRequest( $apiHelper->url, $apiHelper->apiToken);
				$paymentRequest = $client->getPaymentRequest($paymentRequestID);
				$payment = $paymentRequest['result']['payments'][0] ?? null;
				$tokenizedCard = $paymentRequest['tokenisedCards'][0] ?? null;

				if (isset($paymentRequest['result']['result'])) {
					switch ( $paymentRequest['result']['result'] ) {
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


					if (is_null($payment)) {
						Logger::debug('Order received page: paymentRequest does not have any payments. ID: ' . $paymentRequestID);
					} else {
						// Store the card token and authorization for future charges.
						if ($isSubscription) {
							$order->add_meta_data('NoFrixion_cardTokenCustomerID', $payment['cardTokenCustomerID'] );
							$order->add_meta_data('NoFrixion_cardTransactionID', $payment['cardTransactionID'] );
							$order->add_meta_data( 'NoFrixion_cardAuthorizationID', $payment['cardAuthorizationID'] );
							$order->add_meta_data( 'NoFrixion_tokenisedCard_id', $tokenizedCard['id'] );
							$order->add_order_note( _x('Received card token for future charges of a subscription.', 'nofrixion-for-woocommerce'));
							$order->save();
						}
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

	public function addScripts() {
		// Not needed for now:
		// todo: remove incl files if of no use.
		// wp_register_style( 'nofrixion-style', NOFRIXION_PLUGIN_URL . 'assets/css/nofrixion.css' );
		// wp_enqueue_style( 'nofrixion-style' );
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
add_filter( 'woocommerce_payment_gateways', [ 'NoFrixionWCPlugin', 'initPaymentGateways' ] );
add_action( 'plugins_loaded', 'init_nofrixion', 0 );
