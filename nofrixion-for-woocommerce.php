<?php

/**
 * Plugin Name:     NoFrixion for WooCommerce
 * Plugin URI:      https://wordpress.org/plugins/nofrixion-for-woocommerce/
 * Description:     Adds a new payment method to WooCommerce that connects to the NoFrixion MoneyMoov API.
 * Author:          NoFrixion
 * Author URI:      https://nofrixion.com
 * Text Domain:     nofrixion-for-woocommerce
 * Domain Path:     /languages
 * Version:         1.2.4
 * Requires PHP:    7.4
 * Tested up to:    6.3 * Requires at least: 5.2
 */

use Nofrixion\Client\PaymentRequestClient;
use Nofrixion\Model\PaymentRequests\PaymentRequestCreate;
use Nofrixion\Model\PaymentRequests\PaymentRequestUpdate;
use Nofrixion\Util\PreciseNumber;
use Nofrixion\WC\Helper\ApiHelper;
use Nofrixion\WC\Helper\Logger;
use Nofrixion\WC\Helper\TokenManager;

defined('ABSPATH') || exit();

define('NOFRIXION_VERSION', '1.2.4');
// bump these independently of NOFRIXION_VERSION for cachebusting during development
define('NOFRIXION_JS_VERSION', '1.2.4');
define('NOFRIXION_CSS_VERSION', '1.2.4');

define('NOFRIXION_PLUGIN_FILE_PATH', plugin_dir_path(__FILE__));
define('NOFRIXION_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NOFRIXION_PLUGIN_ID', 'nofrixion-for-woocommerce');
define('NORFIXION_REST_URL_NAMESPACE', 'nofrixion/v1');
define('NORFIXION_SUCCESS_WEBHOOK_ROUTE', '/pisp-notify');
define('NOFRIXION_SESSION_PAYMENTREQUEST_ID', 'nofrixion_payment_request_id');

class NofrixionWCPlugin
{
	const SESSION_PAYMENTREQUEST_ID = 'nofrixion_payment_request_id';
	const CALLBACK_PM_CHANGE = 'order-payment-method-change';
	const CALLBACK_AUTH_CARD = 'authorize-card';

	private static $instance;
	public ApiHelper $apiHelper;

	public function __construct()
	{
		$this->includes();
		$this->apiHelper = new ApiHelper();

		add_action('woocommerce_thankyou_nofrixion_card', [$this, 'orderStatusThankYouPage'], 10, 1);
		add_action('woocommerce_thankyou_nofrixion_pisp', [$this, 'orderStatusThankYouPage'], 10, 1);
		add_action('wp_ajax_nofrixion_payment_request_init', [$this, 'processAjaxPaymentRequestInit']);
		add_action('wp_ajax_nopriv_nofrixion_payment_request_init', [$this, 'processAjaxPaymentRequestInit']);
		add_action('wp_ajax_nofrixion_payment_request_authorize_card', [$this, 'processAjaxPaymentRequestAuthorizeCard']);
		add_action('wp_ajax_nopriv_nofrixion_payment_request_authorize_card', [$this, 'processAjaxPaymentRequestAuthorizeCard']);
		add_action('wp_ajax_nofrixion_payment_request', [$this, 'processAjaxPaymentRequestOrder']);
		add_action('wp_ajax_nopriv_nofrixion_payment_request', [$this, 'processAjaxPaymentRequestOrder']);

		// create endpoint for PISP success webhook URL
		add_action('rest_api_init', function () {
			register_rest_route(NORFIXION_REST_URL_NAMESPACE, NORFIXION_SUCCESS_WEBHOOK_ROUTE, array(
				'methods' => 'GET',
				'callback' => array('Nofrixion\WC\Gateway\NofrixionPisp', 'pispNotify'),
				'permission_callback' => '__return_true',
			));
		});

		// Modify thank you page text if we have `?error` query string in callBackUrl.
		add_filter('woocommerce_endpoint_order-received_title', [$this, 'modifyThankYouPageTitle']);
		add_filter('woocommerce_thankyou_order_received_text', [$this, 'modifyCustomerThankyouText']);

		if (is_admin()) {
			// Register our custom global settings page.
			add_filter(
				'woocommerce_get_settings_pages',
				function ($settings) {
					$settings[] = new \Nofrixion\WC\Admin\GlobalSettings();

					return $settings;
				}
			);

			$this->dependenciesNotification();
			$this->notConfiguredNotification();
		}
	}


	public function includes(): void
	{
		$autoloader = NOFRIXION_PLUGIN_FILE_PATH . 'vendor/autoload.php';
		if (file_exists($autoloader)) {
			/** @noinspection PhpIncludeInspection */
			require_once $autoloader;
		}

		// Make sure WP internal functions are available.
		if (!function_exists('is_plugin_active')) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	public static function initPaymentGateways($gateways): array
	{
		// Add Nofrixion gateway to WooCommerce.
		$gateways[] = \Nofrixion\WC\Gateway\NofrixionCard::class;
		$gateways[] = \Nofrixion\WC\Gateway\NofrixionPisp::class;

		return $gateways;
	}

	/**
	 * Displays notice (and link to config page) on admin dashboard if the plugin is not configured yet.
	 */
	public function notConfiguredNotification(): void
	{
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

			\Nofrixion\WC\Admin\Notice::addNotice('error', $message);
		}
	}

	/**
	 * Checks and displays notice on admin dashboard if PHP version is too low or WooCommerce not installed.
	 */
	public function dependenciesNotification()
	{
		// Check PHP version.
		if (version_compare(PHP_VERSION, '7.4', '<')) {
			$versionMessage = sprintf(__('Your PHP version is %s but the NoFrixion Payment plugin requires version 7.4+.', 'nofrixion-for-woocommerce'), PHP_VERSION);
			\Nofrixion\WC\Admin\Notice::addNotice('error', $versionMessage);
		}

		// Check if WooCommerce is installed.
		if (!is_plugin_active('woocommerce/woocommerce.php')) {
			$wcMessage = __('WooCommerce does not seem to be installed. You need to install it before you can activate NoFrixion Payment Gateway.', 'nofrixion-for-woocommerce');
			\Nofrixion\WC\Admin\Notice::addNotice('error', $wcMessage);
		}
	}

	/**
	 * Handles the AJAX callback from the Payment Request on the checkout page.
	 */
	public function processAjaxPaymentRequestInit()
	{
		$client = $this->apiHelper->paymentRequestClient;
		Logger::debug('Entering processAjaxPaymentRequestInit()');

		$nonce = $_POST['apiNonce'];
		if (!wp_verify_nonce($nonce, 'nofrixion-nonce')) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		$gateway = str_replace('nofrixion_', '', sanitize_key($_POST['gateway']));
		if (!in_array($gateway, ['card', 'pisp'])) {
			wp_die('Payment gateway not supported.', '', ['response' => 400]);
		}

		// check if payment request 'stub' is already created in this user session.
		if (class_exists('WC_Session')) {
			$paymentRequestId = WC()->session->get(NOFRIXION_SESSION_PAYMENTREQUEST_ID);
			if ($paymentRequestId) {
				try{
					$tempPaymentRequest = $client->getPaymentRequest($paymentRequestId);
					// check payment request hasn't been used for another order.
					if (!isset($tempPaymentRequest->orderID)) {
						// Empty Payment request is already set up.
						Logger::debug('Payment request already set up. Returning SESSION_PAYMENT_REQUEST_ID.');
						wp_send_json_success(
							[
								'paymentRequestId' => $paymentRequestId,
							]
						);
					}
				} catch (\Throwable $e) {
					Logger::debug('Payment request matching SESSION_PAYMENT_REQUEST_ID does not exist. Creating new Payment Request...');
				}
			}
		}

		try {
			// set to 1.00 here so api doesn't throw exception before user is notified of minumum amount (applies to PISP only)
			$total = 1.00; //(float) WC()->cart->total;
			$newPr = new PaymentRequestCreate(PreciseNumber::parseFloat($total));

			$newPr->baseOriginUrl = site_url();
			$newPr->currency = get_option('woocommerce_currency', null);
			$newPr->paymentMethodTypes = 'card, pisp';
			$newPr->customerID = 'temp' . WC()->cart->get_customer()->get_id();
			$newPr->successWebHookUrl = get_rest_url() . NORFIXION_REST_URL_NAMESPACE . NORFIXION_SUCCESS_WEBHOOK_ROUTE;

			$result = $client->createPaymentRequest($newPr);
			Logger::debug('Result from temporary payment request: ' . print_r($result, true));

			// Store payment request id in the session.
			// Todo: needs more testing if gets cleared after checkout complete to not reuse old data.
			WC()->session->set(NOFRIXION_SESSION_PAYMENTREQUEST_ID, $result->id);

			wp_send_json_success(
				[
					'paymentRequestId' => $result->id ?? null,
				]
			);
		} catch (\Throwable $e) {
			Logger::debug('Error creating payment request: ' . $e->getMessage());
		}

		wp_send_json_error();
	}

	/**
	 * Handles the AJAX callback from the Payment Request on the checkout page. 
	 * TODO - Don't think this runs any more, confirm and remove.
	 */

	public function processAjaxPaymentRequestUpdatePm()
	{

		Logger::debug('Entering processAjaxPaymentRequestUpdatePm()');

		$nonce = $_POST['apiNonce'];
		if (!wp_verify_nonce($nonce, 'nofrixion-nonce')) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		$orderId = wc_sanitize_order_id($_POST['orderId']);


		$callbackUrl = site_url() . '/?' . NofrixionWCPlugin::CALLBACK_PM_CHANGE . '&';
		$callbackUrl .= 'orderId=' . $orderId;

		try {
			$newPr = new PaymentRequestCreate(PreciseNumber::parseFloat(0.00));
			$newPr->baseOriginUrl = site_url();
			$newPr->callbackUrl = $callbackUrl;
			$newPr->paymentMethodTypes = "card";
			$newPr->cardCreateToken = true;
			$newPr->customerID = get_current_user_id();
			$newPr->cardAuthorizeOnly = true;

			$result = $this->apiHelper->paymentRequestClient->createPaymentRequest($newPr);

			Logger::debug('Result creating PR for payment method change: ' . print_r($result, true));

			update_post_meta($orderId, 'Nofrixion_pmupdate_PrId', $result->id);
			update_post_meta($orderId, 'Nofrixion_pmupdate_datetime', (new \DateTime())->format('Y-m-d H:i:s'));

			wp_send_json_success(
				[
					'paymentRequestId' => $result->id ?? null,
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
	public function processAjaxPaymentRequestOrder()
	{

		Logger::debug('Entering processAjaxPaymentRequestOrder()');

		$nonce = $_POST['apiNonce'];
		if (!wp_verify_nonce($nonce, 'nofrixion-nonce')) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		// Make sure the submitted payment request id and the one in the session are the same.
		// Todo: needs more testing if gets cleared after checkout complete to not reuse old data.
		/*
		$sessionPRId = WC()->session->get(NOFRIXION_SESSION_PAYMENTREQUEST_ID);
		$submittedPRId =  wc_clean( wp_unslash( $_POST['payment_request_id']));
		if ($sessionPRId !== $submittedPRId) {
			Logger::debug('Submitted and session payment ids differ, aborting.');
			Logger::debug('Submitted: ' . $submittedPRId . ' Session: ' . $sessionPRId);
			wp_send_json_error();
		}
		*/

		wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);

		try {
			WC()->checkout()->process_checkout();
		} catch (\Throwable $e) {
			Logger::debug('Error processing payment request ajax callback: ' . $e->getMessage());
		}
	}

	/**
	 * Handles the AJAX callback to only authorize card (on my-account payment methods page).
	 */
	public function processAjaxPaymentRequestAuthorizeCard()
	{

		Logger::debug('Entering processAjaxPaymentRequestAuthorizeCard()');

		$nonce = $_POST['apiNonce'];
		if (!wp_verify_nonce($nonce, 'nofrixion-nonce')) {
			wp_die('Unauthorized!', '', ['response' => 401]);
		}

		try {
			$client = $this->apiHelper->paymentRequestClient;
			$amount = PreciseNumber::parseFloat(0.00);

			$newPr = new PaymentRequestCreate($amount);
			$newPr->baseOriginUrl = site_url();
			$newPr->customerEmailAddress = WC()->cart->get_customer()->get_billing_email();
			$newPr->cardCreateToken = true;
			$newPr->customerID = get_current_user_id();
			$newPr->cardAuthorizeOnly = true;

			$result = $client->createPaymentRequest($newPr);

			$callbackUrl = site_url() . '/?' . NofrixionWCPlugin::CALLBACK_AUTH_CARD . '&';
			$callbackUrl .= 'authReqId=' . $result->id;

			if ($result) {
				Logger::debug('Result creating PR for authorize only: ' . print_r($result, true));
			} else {
				wp_send_json_error();
			}

			// Store the prId on the user as we do not have any order for this use case.
			update_user_meta(get_current_user_id(), 'Nofrixion_authorizeCard_prId', $result->id);

			// Update PR with callback URL.
			$updatedPr = new PaymentRequestUpdate;
			$updatedPr->callbackUrl = $callbackUrl;
			$updatedPr = $client->updatePaymentRequest($result->id, $updatedPr);

			if ($updatedPr) {
				Logger::debug('Updated PR for authorize only: ' . print_r($updatedPr, true));
			} else {
				wp_send_json_error();
			}

			wp_send_json_success(
				[
					'paymentRequestId' => $result->id ?? null,
				]
			);
		} catch (\Throwable $e) {
			Logger::debug('Error creating payment request: ' . $e->getMessage());
		}

		wp_send_json_error();
	}

	public function orderHasSubscription($order)
	{
		if (!function_exists('wcs_order_contains_subscription')) {
			return false;
		}
		return wcs_order_contains_subscription($order);
	}

	public function updateOrderFields(\WC_Order &$order, array $fields)
	{
		// todo list of specific stuff to update.
		foreach ($fields as $field) {
			$method = 'set_' . $field['name'];
			if (method_exists($order, $method)) {
				$order->$method(wc_clean(wp_unslash($field['value'])));
			}
		}
	}

	function modifyThankYouPageTitle($title)
	{
		// Check if this is the thank you page by inspecting the URL
		if (isset($_REQUEST["error"]) || isset($_REQUEST["cancelled"])) {
			// Modify the title as needed
			return 'Payment failed';
		}

		return $title;
	}

	function modifyCustomerThankyouText($message)
	{
		if (isset($_REQUEST["error"]) || isset($_REQUEST["cancelled"])) {
			return "<p>Payment was not successfully processed by your bank. Please make a new order.</p>
					<p>If problems persist, please contact your financial institution.</p>";
		} else {
			return $message;
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
		$paymentRequestID = $order->get_meta('Nofrixion_id');
		$isSubscription = (bool) $order->get_meta('Nofrixion_isSubscription');
		$saveToken = (bool) $order->get_meta('Nofrixion_saveTokenSelected');

		// Only process payment status if not already done.
		if (!in_array($currentOrderStatus, ['processing', 'completed']) && $paymentRequestID) {
			// Check PaymentRequest.
			try {
				// MoneyMoov API adds "error" query param if Pay by bank fails => Void payment request.
				if (isset($_REQUEST["error"])) {
					$errorMessage = $_REQUEST["error"];
					$paymentStatus = "PispError";
					// updatePaymentRequest model currently doesn't support status changes
					// ... if we leave 'Status' as 'None' can move above code into else block.
				}
				// "cancelled" query param in some cases from NoFrixion provider.
				elseif (isset($_REQUEST["cancelled"]) && $_REQUEST["cancelled"]=='true') {
					$errorMessage = "Transaction was cancelled by banking provider.";
					$paymentStatus = "PispError";
					// updatePaymentRequest model currently doesn't support status changes
					// ... if we leave 'Status' as 'None' can move above code into else block.
				}
				else {
					$client = $this->apiHelper->paymentRequestClient;
					$paymentRequest = $client->getPaymentRequest($paymentRequestID);
					$paymentStatus = $paymentRequest->status ?? null; // comes back 'None' rather than null.
	
					Logger::debug('Payment request data: ' . print_r($paymentRequest, true));
				}

				$payment = $paymentRequest->result->payments[0] ?? null;
				$tokenisedCard = $paymentRequest->tokenisedCards[0] ?? null;
				

				if (isset($paymentStatus)) {
					switch ($paymentStatus) {
						case "FullyPaid":
							$order->payment_complete();
							$order->save();
							break;
						case "OverPaid":
							$order->payment_complete();
							$order->add_order_note(_x('ATTENTION.', 'nofrixion-for-woocommerce'));
							$order->save();
							self::sendAdminMail(
								_x("The order $order_id has been overpaid and needs manual checking.", 'nofrixion-for-woocommerce'),
								$order
							);
							break;
						case "Voided":
							$order->update_status('failed');
							$order->add_order_note(_x('Payment failed, please make a new order or get in contact with us.', 'nofrixion-for-woocommerce'), 1);
							$order->save();
							break;
						case "PartiallyPaid":
							// Put the order on-hold for manual investigation by merchant.
							$order->update_status('on-hold');
							$order->add_order_note(_x('Partial payment received, order needs manual processing.', 'nofrixion-for-woocommerce'));
							$order->save();
							self::sendAdminMail(
								_x("The order $order_id has been partially paid and needs manual checking.", 'nofrixion-for-woocommerce'),
								$order
							);
							break;
						case "Authorized":
							// Do nothing, keeps order in pending state. 
							// MoneyMoov API will call success webhook when funds revieved and update the order status to 'processing'.
							break;
						case "PispError":
							$order->update_status('failed');
							$order->add_order_note(_x('Pay by bank failed with error: ' . $errorMessage . '. Please create a new order.', 'nofrixion-for-woocommerce'), 1);
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
						if ($tokenisedCard && ($isSubscription || $saveToken)) {
							TokenManager::addToken($tokenisedCard, get_current_user_id());
						}

						// For subscriptions, also store the tokenisedCardId on the order for recurring charges.
						if ($isSubscription) {
							//$order->update_meta_data('Nofrixion_cardTokenCustomerID', $payment['cardTokenCustomerID']);
							//$order->update_meta_data('Nofrixion_cardTransactionID', $payment['cardTransactionID']);
							//$order->update_meta_data('Nofrixion_cardAuthorizationID', $payment['cardAuthorizationID']);
							$order->update_meta_data('Nofrixion_tokenisedCard_id', $tokenisedCard['id']);
							$order->add_order_note(_x('Received card token for future charges of a subscription.', 'nofrixion-for-woocommerce'));
							$order->save();
						}
					}
				}
			} catch (\Throwable $e) {
				Logger::debug('Problem fetching PaymentRequest status:', true);
				Logger::debug($e->getMessage(), true);
			}
		}

		$orderData = $order->get_data();
		$status = $orderData['status'];

		switch ($status) {
			case 'on-hold':
				$statusDesc = _x('There was a problem finishing your order, order now on hold, we will get in contact with you.', 'nofrixion-for-woocommerce');
				break;
			case 'pending':
				$statusDesc = _x('Payment authorised. An order confirmation email will be sent upon receipt of funds from your bank.', 'nofrixion-for-woocommerce');
				break;
			case 'processing':
				$statusDesc = _x('Payment completed, processing your order.', 'nofrixion-for-woocommerce');
				break;
			case 'completed':
				$statusDesc = _x('Payment completed', 'nofrixion-for-woocommerce');
				break;
			case 'failed':
				if ($paymentStatus === "PispError") {
					$statusDesc = _x('Error: ' . $errorMessage, 'nofrixion-for-woocommerce');
				} else {
					$statusDesc = _x('Payment failed.', 'nofrixion-for-woocommerce');
				}
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
	 * Helper for sending mails to store staff.
	 */
	public static function sendAdminMail(string $message, WC_Order $order = null)
	{
		if ($mails = get_option('nofrixion_admin_mails', null)) {
			// Remove whitespaces.
			$mails = preg_replace('/\s+/', '', $mails);

			// Add a link to the order.
			$message .= "\n\n";
			$message .= "Order ID: " . $order->get_order_number() . "\n";
			$message .= "Link: " . $order->get_edit_order_url() . "\n";

			return wp_mail($mails, 'NoFrixion for WooCommerce Alert', $message);
		}

		return false;
	}

	/**
	 * Gets the main plugin loader instance.
	 *
	 * Ensures only one instance can be loaded.
	 *
	 * @return \NofrixionWCPlugin
	 */
	public static function instance()
	{

		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

// Start everything up.
function init_nofrixion()
{
	\NofrixionWCPlugin::instance();

	nofrixion_ensure_endpoints();
}

/**
 * Bootstrap stuff on init.
 */
add_action('init', function () {
	// Register custom endpoints.
	add_rewrite_endpoint(NofrixionWCPlugin::CALLBACK_PM_CHANGE, EP_ROOT);
	add_rewrite_endpoint(NofrixionWCPlugin::CALLBACK_AUTH_CARD, EP_ROOT);

	// Adding textdomain and translation support.
	load_plugin_textdomain('nofrixion-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
});

// To be able to use the endpoint without appended url segments we need to do this.
add_filter('request', function ($vars) {
	$callbacks = [
		NofrixionWCPlugin::CALLBACK_PM_CHANGE,
		NofrixionWCPlugin::CALLBACK_AUTH_CARD
	];
	foreach ($callbacks as $cb) {
		if (isset($vars[$cb])) {
			$vars[$cb] = true;
		}
	}

	return $vars;
});

// Adding template redirect handling for payment method change redirect.
add_action('template_redirect', function () {
	global $wp_query;

	// Only continue on correct request.
	if (!isset($wp_query->query_vars[NofrixionWCPlugin::CALLBACK_PM_CHANGE])) {
		return;
	}

	if (!$subscriptionId = sanitize_text_field($_GET['orderId'])) {
		wp_die('Error, no order ID provided, aborting.');
	}

	if (!$subscription = new \WC_Subscription($subscriptionId)) {
		Logger::debug('Update PM callback: Could not load order, orderId: ' . $subscriptionId);
		wp_die('Could not load order, aborting');
	}

	if (!$updatedPrId = $subscription->get_meta('Nofrixion_pmupdate_PrId')) {
		Logger::debug('Order ' . $subscriptionId . ' has no pmupdatePrId set, aborting.');
		wp_die('Could not find update payment method reference, aborting');
	}

	// Load the parent order.
	$parentOrderId = $subscription->get_parent_id();
	if (!$parentOrder = new \WC_Order($parentOrderId)) {
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
		$client = new PaymentRequestClient($apiHelper->url, $apiHelper->apiToken);
		$updatedPr = $client->getPaymentRequest($updatedPrId);

		$payment = $updatedPr->result->payments[0] ?? null;
		$tokenisedCard = $updatedPr->tokenisedCards[0] ?? null;
		$paymentStatus = $updatedPr->result->result;

		Logger::debug('TokenisedCardId: ' . $tokenisedCard['id']);
		Logger::debug('Payment status: ' . $paymentStatus);
		// Update order status?
		if ($tokenisedCard && $paymentStatus === 'FullyPaid') {
			// Save tokenized card id on current and parent order.
			$subscription->update_meta_data('Nofrixion_cardAuthorizationID', $payment['cardAuthorizationID']);
			$subscription->update_meta_data('Nofrixion_tokenisedCard_id', $tokenisedCard['id']);
			$subscription->save();
			$subscription->add_order_note(__('Received card token for future charges of a subscription, updated parent order.', 'nofrixion-for-woocommerce'));
			$parentOrder->update_meta_data('Nofrixion_cardAuthorizationID', $payment['cardAuthorizationID']);
			$parentOrder->update_meta_data('Nofrixion_tokenisedCard_id', $tokenisedCard['id']);
			$parentOrder->update_meta_data('Nofrixion_pmupdate_datetime', (new \DateTime())->format('Y-m-d H:i:s'));
			$parentOrder->save();
			$parentOrder->add_order_note(sprintf(__('Updated card token after payment method change by order/subscription id %u.', 'nofrixion-for-woocommerce'), $subscriptionId));
			Logger::debug('Updated order and parent order with new tokenised card details.');

			// Store CC data as token.
			TokenManager::addToken($tokenisedCard, get_current_user_id());

			WC_Subscriptions_Change_Payment_Gateway::update_payment_method($subscription, $subscription->get_payment_method());

			$messages[] = __('Thank you. Your card details have been updated.', 'nofrixion-for-woocommerce');
		} else {
			$subscription->add_order_note(__('Card authorization failed on payment method change.', 'nofrixion-for-woocommerce'));
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
add_action('template_redirect', function () {
	global $wp_query;

	// Only continue correct request.
	if (!isset($wp_query->query_vars[NofrixionWCPlugin::CALLBACK_AUTH_CARD])) {
		return;
	}

	Logger::debug('Hit template redirect for auth card:');

	if (!$prId = wc_clean(wp_unslash($_GET['authReqId']))) {
		wp_die('Error, no authReqId provided, aborting.');
	}

	$hasErrors = false;
	$messages = [];
	$errors = [];
	$redirectUrl = wc_get_account_endpoint_url('payment-methods');

	// Get the new cc tokenised card id and overwrite it on the parent order for the future charges.
	try {
		$apiHelper = new ApiHelper();
		$pr = $apiHelper->paymentRequestClient->getPaymentRequest($prId);

		$tokenisedCard = $pr->tokenisedCards[0] ?? null;
		$paymentStatus = $pr->result->result;

		Logger::debug('TokenisedCardId: ' . $tokenisedCard['id']);
		Logger::debug('Payment status: ' . $paymentStatus);
		// Update order status?
		if ($tokenisedCard && $paymentStatus === 'FullyPaid') {
			// Store CC data as token.
			TokenManager::addToken($tokenisedCard, get_current_user_id());

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
function nofrixion_ensure_endpoints()
{
	$flushed = (int) get_option('nofrixion_permalinks_flushed');
	if ($flushed < 2) {
		flush_rewrite_rules();
		$flushed++;
		update_option('nofrixion_permalinks_flushed', $flushed);
	}
}


// Installation routine.
register_activation_hook(__FILE__, function () {
	nofrixion_ensure_endpoints();
});

// Initialize payment gateways and plugin.
add_filter('woocommerce_payment_gateways', ['NofrixionWCPlugin', 'initPaymentGateways']);
add_action('plugins_loaded', 'init_nofrixion', 0);
