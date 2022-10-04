<?php
/**
 * Plugin Name:     NoFrixion for WooCommerce
 * Plugin URI:      https://wordpress.org/plugins/nofrixion-for-woocommerce/
 * Description:     Adds a new payment method to WooCommerce that connects to the NoFrixion MoneyMoov API.
 * Author:          NoFrixion
 * Author URI:      https://nofrixion.com
 * Text Domain:     nofrixion-for-woocommerce
 * Domain Path:     /languages
 * Version:         1.1.20
 * Requires PHP:    7.4
 * Tested up to:    6.0
 * Requires at least: 5.2
 */

use NoFrixion\WC\Client\PaymentRequest;
use NoFrixion\WC\Helper\ApiHelper;
use NoFrixion\WC\Helper\Logger;
use NoFrixion\WC\Helper\TokenManager;

defined( 'ABSPATH' ) || exit();

define( 'NOFRIXION_VERSION', '1.1.20' );
define( 'NOFRIXION_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOFRIXION_PLUGIN_URL', plugin_dir_url(__FILE__ ) );
define( 'NOFRIXION_PLUGIN_ID', 'nofrixion-for-woocommerce' );

class NoFrixionWCPlugin {

	const SESSION_PAYMENTREQUEST_ID = 'nofrixion_payment_request_id';
	const CALLBACK_PM_CHANGE = 'order-payment-method-change';
	const CALLBACK_AUTH_CARD = 'authorize-card';

	private static $instance;

	public function __construct() {
		$this->includes();

		add_action('woocommerce_thankyou_nofrixion_card', [$this, 'orderStatusThankYouPage'], 10, 1);
		add_action('woocommerce_thankyou_nofrixion_pisp', [$this, 'orderStatusThankYouPage'], 10, 1);
		add_action( 'wp_ajax_nofrixion_payment_request_init', [$this, 'processAjaxPaymentRequestInit'] );
		add_action( 'wp_ajax_nopriv_nofrixion_payment_request_init', [$this, 'processAjaxPaymentRequestInit'] );
		add_action( 'wp_ajax_nofrixion_payment_request_update_pm', [$this, 'processAjaxPaymentRequestUpdatePm'] );
		add_action( 'wp_ajax_nopriv_nofrixion_payment_request_update_pm', [$this, 'processAjaxPaymentRequestUpdatePm'] );
		add_action( 'wp_ajax_nofrixion_payment_request_authorize_card', [$this, 'processAjaxPaymentRequestAuthorizeCard'] );
		add_action( 'wp_ajax_nopriv_nofrixion_payment_request_authorize_card', [$this, 'processAjaxPaymentRequestAuthorizeCard'] );
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
			$versionMessage = sprintf( __( 'Your PHP version is %s but the NoFrixion Payment plugin requires version 7.4+.', 'nofrixion-for-woocommerce' ), PHP_VERSION );
			\NoFrixion\WC\Admin\Notice::addNotice('error', $versionMessage);
		}

		// Check if WooCommerce is installed.
		if ( ! is_plugin_active('woocommerce/woocommerce.php') ) {
			$wcMessage = __('WooCommerce does not seem to be installed. You need to install it before you can activate NoFrixion Payment Gateway.', 'nofrixion-for-woocommerce');
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
				WC()->cart->get_customer()->get_billing_email(),
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
	public function processAjaxPaymentRequestUpdatePm() {

		Logger::debug('Entering processAjaxPaymentRequestUpdatePm()');

		$nonce = $_POST['apiNonce'];
		if ( ! wp_verify_nonce( $nonce, 'nofrixion-nonce' ) ) {
			wp_die( 'Unauthorized!', '', [ 'response' => 401 ] );
		}

		$orderId = wc_sanitize_order_id($_POST['orderId']);


		$callbackUrl = site_url() . '/?'. NoFrixionWCPlugin::CALLBACK_PM_CHANGE .'&';
		$callbackUrl .= 'orderId=' . $orderId;

		try {
			$apiHelper = new ApiHelper();
			$client = new PaymentRequest( $apiHelper->url, $apiHelper->apiToken);
			$result = $client->createPaymentRequest(
				site_url(),
				$callbackUrl,
				\NoFrixion\WC\Helper\PreciseNumber::parseFloat(0.00),
				null,
				['card'],
				null,
				true,
				get_current_user_id(),
				true
			);

			Logger::debug('Result creating PR for payment method change: ' . print_r($result, true));

			update_post_meta($orderId, 'NoFrixion_pmupdate_PrId', $result['id']);
			update_post_meta($orderId, 'NoFrixion_pmupdate_datetime', (new \DateTime())->format('Y-m-d H:i:s'));

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

	/**
	 * Handles the AJAX callback to only authorize card (on my-account payment methods page).
	 */
	public function processAjaxPaymentRequestAuthorizeCard() {

		Logger::debug('Entering processAjaxPaymentRequestAuthorizeCard()');

		$nonce = $_POST['apiNonce'];
		if ( ! wp_verify_nonce( $nonce, 'nofrixion-nonce' ) ) {
			wp_die( 'Unauthorized!', '', [ 'response' => 401 ] );
		}

		try {
			$apiHelper = new ApiHelper();
			$client = new PaymentRequest( $apiHelper->url, $apiHelper->apiToken);
			$result = $client->createPaymentRequest(
				site_url(),
				site_url() . '/dummycallback',
				\NoFrixion\WC\Helper\PreciseNumber::parseFloat(0.00),
				WC()->cart->get_customer()->get_billing_email(),
				null,
				['card'],
				null,
				true,
				get_current_user_id(),
				true
			);

			$callbackUrl = site_url() . '/?'. NoFrixionWCPlugin::CALLBACK_AUTH_CARD .'&';
			$callbackUrl .= 'authReqId=' . $result['id'];

			if ($result) {
				Logger::debug('Result creating PR for authorize only: ' . print_r($result, true));
			} else {
				wp_send_json_error();
			}

			// Store the prId on the user as we do not have any order for this use case.
			update_user_meta(get_current_user_id(), 'NoFrixion_authorizeCard_prId', $result['id']);

			// Update PR with callback URL.
			$updatedPr = $client->updatePaymentRequest(
				$result['id'],
				site_url(),
				$callbackUrl,
				\NoFrixion\WC\Helper\PreciseNumber::parseFloat(0.00),
				null,
				['card'],
				null,
				true,
				get_current_user_id(),
				true,
				WC()->cart->get_customer()->get_billing_email()
			);

			if ($updatedPr) {
				Logger::debug('Updated PR for authorize only: ' . print_r($updatedPr, true));
			} else {
				wp_send_json_error();
			}

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

	public function orderHasSubscription($order) {
		if (!function_exists('wcs_order_contains_subscription')) {
			return false;
		}
		return wcs_order_contains_subscription($order);
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

		Logger::debug('Entering orderStatusThankYouPage:');

		$currentOrderStatus = $order->get_status();
		$paymentRequestID = $order->get_meta('NoFrixion_id');
		$isSubscription = (bool) $order->get_meta('NoFrixion_isSubscription');
		$saveToken = (bool) $order->get_meta('NoFrixion_saveTokenSelected');

		// Only process payment status if not already done.
		if (
			!in_array($currentOrderStatus, ['processing', 'completed', 'failed']) &&
			$paymentRequestID
		) {
			// Check PaymentRequest.
			try {
				$apiHelper = new ApiHelper();

				$client = new PaymentRequest( $apiHelper->url, $apiHelper->apiToken);
				$paymentRequest = $client->getPaymentRequest($paymentRequestID);
				$payment = $paymentRequest['result']['payments'][0] ?? null;
				$tokenizedCard = $paymentRequest['tokenisedCards'][0] ?? null;
				$paymentStatus = $paymentRequest['result']['result'] ?? null;

				if (isset($paymentStatus)) {
					switch ( $paymentStatus ) {
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

						// Create a cc token for future charges.
						Logger::debug('Check if we need to store a token.');
						if ($tokenizedCard && ($isSubscription || $saveToken)) {
							TokenManager::addToken($tokenizedCard, get_current_user_id());
						}

						// For subscriptions, also store the tokenisedCardId on the order for recurring charges.
						if ($isSubscription) {
							$order->update_meta_data('NoFrixion_cardTokenCustomerID', $payment['cardTokenCustomerID'] );
							$order->update_meta_data('NoFrixion_cardTransactionID', $payment['cardTransactionID'] );
							$order->update_meta_data( 'NoFrixion_cardAuthorizationID', $payment['cardAuthorizationID'] );
							$order->update_meta_data( 'NoFrixion_tokenisedCard_id', $tokenizedCard['id'] );
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

	nofrixion_ensure_endpoints();
}

/**
 * Bootstrap stuff on init.
 */
add_action('init', function() {
	// Register custom endpoints.
	add_rewrite_endpoint(NoFrixionWCPlugin::CALLBACK_PM_CHANGE, EP_ROOT);
	add_rewrite_endpoint(NoFrixionWCPlugin::CALLBACK_AUTH_CARD, EP_ROOT);

	// Adding textdomain and translation support.
	load_plugin_textdomain('nofrixion-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');
});

// To be able to use the endpoint without appended url segments we need to do this.
add_filter('request', function($vars) {
	$callbacks = [
		NoFrixionWCPlugin::CALLBACK_PM_CHANGE,
		NoFrixionWCPlugin::CALLBACK_AUTH_CARD
	];
	foreach ($callbacks as $cb) {
		if (isset($vars[$cb])) {
			$vars[$cb] = true;
		}
	}

	return $vars;
});

// Adding template redirect handling for payment method change redirect.
add_action( 'template_redirect', function() {
	global $wp_query;

	// Only continue on correct request.
	if ( ! isset( $wp_query->query_vars[ NoFrixionWCPlugin::CALLBACK_PM_CHANGE ] ) ) {
		return;
	}

	if (! $subscriptionId = sanitize_text_field($_GET['orderId'])) {
		wp_die('Error, no order ID provided, aborting.');
	}

	if (! $subscription = new \WC_Subscription($subscriptionId)) {
		Logger::debug('Update PM callback: Could not load order, orderId: ' . $subscriptionId);
		wp_die('Could not load order, aborting');
	}

	if (! $updatedPrId = $subscription->get_meta('NoFrixion_pmupdate_PrId')) {
		Logger::debug('Order ' . $subscriptionId . ' has no pmupdatePrId set, aborting.');
		wp_die('Could not find update payment method reference, aborting');
	}

	// Load the parent order.
	$parentOrderId = $subscription->get_parent_id();
	if (! $parentOrder = new \WC_Order($parentOrderId)) {
		Logger::debug('Update PM callback: Could not load parent order of orderId: ' . $subscriptionId);
		wp_die('Could not find parent order reference, aborting');
	}

	Logger::debug('Found parent order ' . $parentOrderId . '. Fetching PR data and updating meta.');

	$hasErrors = false;
	$messages = [];
	$errors = [];
	$redirectUrl = $subscription->get_checkout_payment_url();

	// Get the new cc tokenised card id and overwrite it on the parent order for the future charges.
	try {
		$apiHelper = new ApiHelper();
		$client = new PaymentRequest( $apiHelper->url, $apiHelper->apiToken);
		$updatedPr = $client->getPaymentRequest($updatedPrId);

		$payment = $updatedPr['result']['payments'][0] ?? null;
		$tokenizedCard = $updatedPr['tokenisedCards'][0] ?? null;
		$paymentStatus = $updatedPr['result']['result'];

		Logger::debug('TokenisedCardId: ' . $tokenizedCard['id']);
		Logger::debug('Payment status: ' . $paymentStatus);
		// Update order status?
		if ($tokenizedCard && $paymentStatus === 'FullyPaid') {
			// Save tokenized card id on current and parent order.
			$subscription->update_meta_data( 'NoFrixion_cardAuthorizationID', $payment['cardAuthorizationID'] );
			$subscription->update_meta_data( 'NoFrixion_tokenisedCard_id', $tokenizedCard['id'] );
			$subscription->save();
			$subscription->add_order_note( __('Received card token for future charges of a subscription, updated parent order.', 'nofrixion-for-woocommerce'));
			$parentOrder->update_meta_data( 'NoFrixion_cardAuthorizationID', $payment['cardAuthorizationID'] );
			$parentOrder->update_meta_data( 'NoFrixion_tokenisedCard_id', $tokenizedCard['id'] );
			$parentOrder->update_meta_data('NoFrixion_pmupdate_datetime', (new \DateTime())->format('Y-m-d H:i:s'));
			$parentOrder->save();
			$parentOrder->add_order_note( sprintf(__('Updated card token after payment method change by order/subscription id %u.', 'nofrixion-for-woocommerce'), $subscriptionId));
			Logger::debug('Updated order and parent order with new tokenised card details.');

			// Store CC data as token.
			TokenManager::addToken($tokenizedCard, get_current_user_id());

			WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $subscription, $subscription->get_payment_method());

			$messages[] = __('Thank you. Your card details have been updated.', 'nofrixion-for-woocommerce');
		} else {
			$subscription->add_order_note( __('Card authorization failed on payment method change.', 'nofrixion-for-woocommerce'));
			$errors[] = __('Something went wrong with your card authorization. Please try again.', 'nofrixion-for-woocommerce');
		}

	} catch (\Throwable $e) {
		// wp_die()
		Logger::debug('Update payment method callback: Error fetching payment request data.');
		Logger::debug('Exception: ' . $e->getMessage());
		$errors[] = __('Something went wrong while contacting payment provider. Please try again.', 'nofrixion-for-woocommerce');
	}
	// Todo: show notice on the redirect page $messages / $errors
	wp_redirect($redirectUrl);
});

// Adding template redirect handling for authorized card.
add_action( 'template_redirect', function() {
	global $wp_query;

	// Only continue correct request.
	if ( ! isset( $wp_query->query_vars[ NoFrixionWCPlugin::CALLBACK_AUTH_CARD ] ) ) {
		return;
	}

	Logger::debug('Hit template redirect for auth card:');

	if (! $prId = wc_clean(wp_unslash($_GET['authReqId']))) {
		wp_die('Error, no authReqId provided, aborting.');
	}

	$hasErrors = false;
	$messages = [];
	$errors = [];
	$redirectUrl = wc_get_account_endpoint_url( 'payment-methods' );

	// Get the new cc tokenised card id and overwrite it on the parent order for the future charges.
	try {
		$apiHelper = new ApiHelper();
		$client = new PaymentRequest( $apiHelper->url, $apiHelper->apiToken);
		$pr = $client->getPaymentRequest($prId);

		$tokenizedCard = $pr['tokenisedCards'][0] ?? null;
		$paymentStatus = $pr['result']['result'];

		Logger::debug('TokenisedCardId: ' . $tokenizedCard['id']);
		Logger::debug('Payment status: ' . $paymentStatus);
		// Update order status?
		if ($tokenizedCard && $paymentStatus === 'FullyPaid') {
			// Store CC data as token.
			TokenManager::addToken($tokenizedCard, get_current_user_id());

			$messages[] = __('Thank you. Your card details have been updated.', 'nofrixion-for-woocommerce');
		} else {
			$errors[] = __('Something went wrong with your card authorization. Please try again.', 'nofrixion-for-woocommerce');
		}

	} catch (\Throwable $e) {
		Logger::debug('Auhtorize card callback: Error fetching payment request data.');
		Logger::debug('Exception: ' . $e->getMessage());
		$errors[] = __('Something went wrong while contacting payment provider. Please try again.', 'nofrixion-for-woocommerce');
		wp_die(print_r($errors, true));
	}
	// Todo: show notice on the redirect page $messages / $errors
	wp_redirect($redirectUrl);
});

/**
 * Flush rewrite rules to make endpoints work.
 *
 * @return void
 */
function nofrixion_ensure_endpoints() {
	$flushed = (int) get_option('nofrixion_permalinks_flushed');
	if ($flushed < 2 ) {
		flush_rewrite_rules();
		$flushed++;
		update_option('nofrixion_permalinks_flushed', $flushed);
	}
}


// Installation routine.
register_activation_hook( __FILE__, function() {
	nofrixion_ensure_endpoints();
});

// Initialize payment gateways and plugin.
add_filter( 'woocommerce_payment_gateways', [ 'NoFrixionWCPlugin', 'initPaymentGateways' ] );
add_action( 'plugins_loaded', 'init_nofrixion', 0 );
