<?php

declare(strict_types=1);

namespace Nofrixion\WC\Gateway;

use Nofrixion\Model\PaymentRequests\PaymentRequestCreate;
use Nofrixion\Model\PaymentRequests\PaymentRequest;
use Nofrixion\Model\PaymentRequests\PaymentRequestUpdate;
use Nofrixion\WC\Helper\ApiHelper;
use Nofrixion\WC\Helper\Logger;
use Nofrixion\WC\Helper\OrderStates;
use Nofrixion\Util\PreciseNumber;

abstract class NofrixionGateway extends \WC_Payment_Gateway
{
	public ApiHelper $apiHelper;

	public function __construct()
	{
		// General gateway setup.
		// Do not set id here.

		//$this->icon              = $this->getIcon();

		$this->order_button_text = __('Place order', 'nofrixion-for-woocommerce');

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user facing set variables.
		$this->title        = $this->getTitle();
		$this->description  = $this->getDescription();

		// Admin facing title and description.
		$this->method_title       = 'NoFrixion';
		$this->method_description = __('NoFrixion payments supporting cards and open banking.', 'nofrixion-for-woocommerce');

		// Debugging & informational settings.
		$this->debug_php_version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$this->debug_plugin_version = NOFRIXION_VERSION;

		$this->apiHelper = new ApiHelper();

		// Example of available features, needs to be set in specific payment plugins.
		/* $this->supports = array(
               'products',
			   'refunds',
               'subscriptions',
               'subscription_cancellation',
               'subscription_suspension',
               'subscription_reactivation',
               'subscription_amount_changes',
               'subscription_date_changes',
               'subscription_payment_method_change',
               'subscription_payment_method_change_customer',
               'subscription_payment_method_change_admin',
               'multiple_subscriptions',
          ); */

		// Actions.
		// Handler for PISP success webhook.
		//add_action('woocommerce_api_nofrixion', [$this, 'processWebhook']);
		add_action('wp_enqueue_scripts', [$this, 'addScripts']);
		add_action('woocommerce_update_options_payment_gateways_' . $this->getId(), [$this, 'process_admin_options']);
		add_action('woocommerce_scheduled_subscription_payment_' . $this->getId(), array($this, 'scheduledSubscriptionPayment'), 10, 2);
		add_action('woocommerce_subscription_failing_payment_method_updated_' . $this->getId(), array($this, 'updateSubscriptionPaymentMethod'), 10, 2);
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields()
	{
		$this->form_fields = [
			'enabled' => [
				'title'       => __('Enabled/Disabled', 'nofrixion-for-woocommerce'),
				'type'        => 'checkbox',
				'label'       => __('Enable this payment gateway.', 'nofrixion-for-woocommerce'),
				'default'     => 'no',
				'value'       => 'yes',
				'desc_tip'    => false,
			],
			'title'       => [
				'title'       => __('Customer Text', 'nofrixion-for-woocommerce'),
				'type'        => 'text',
				'description' => __('Controls the name of this payment method as displayed to the customer during checkout.', 'nofrixion-for-woocommerce'),
				'default'     => $this->title,
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __('Customer Message', 'nofrixion-for-woocommerce'),
				'type'        => 'textarea',
				'description' => __('Message to explain how the customer will be paying for the purchase.', 'nofrixion-for-woocommerce'),
				'default'     => $this->description,
				'desc_tip'    => true,
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function process_payment($orderId)
	{
		if (!$this->apiHelper->isConfigured()) {
			Logger::debug('NoFrixion Server API connection not configured, aborting. Please go to NoFrixion settings and set it up.');
			throw new \Exception(__("Can't process order. No merchant token configured, aborting.", 'nofrixion-for-woocommerce'));
		}
		$client = $this->apiHelper->paymentRequestClient;

		// Load the order and check it.
		$order = new \WC_Order($orderId);
		if ($order->get_id() === 0) {
			$message = 'Could not load order id ' . $orderId . ', aborting.';
			Logger::debug($message, true);
			throw new \Exception($message);
		}
		// payment request Id submitted with order
		$paymentRequestId = wc_clean(wp_unslash($_POST['payment_request_id']));
		if (!$paymentRequestId) {
			$msg_no_prid = 'No payment request id found, aborting.';
			Logger::debug($msg_no_prid);
			throw new \Exception($msg_no_prid);
		}
		Logger::debug('Submitted payment request id ' . $paymentRequestId);

		// see if existing payment request for this order exists
		try {
			$paymentRequest = $client->getPaymentRequestByOrderId((string) $orderId);
			if ($paymentRequest) {
				if ($paymentRequestId !== $paymentRequest->id) {
					// payment request already created for this order, delete the new payment request 'stub'.
					if ("FullyPaid" === $paymentRequest->status) {
						// if existing payment request is fully paid there is another problem
						wc_add_notice(__('An order with this order ID is showing as fully paid. Please empty your cart and create a new order.'), 'error');
						return;
					} else {
						try {
							$client->deletePaymentRequest($paymentRequestId);
							Logger::debug('Payment request already exists for order ' . $orderId . ' - deleting duplicate.');
						} catch (\Throwable $e) {
							Logger::debug('Error deleting duplicate payment request: ' . $e->getMessage());
							return ['result' => 'failure'];
						}
						WC()->session->__unset(NOFRIXION_SESSION_PAYMENTREQUEST_ID);
						$paymentRequestId = $paymentRequest->id;
					}
				}
			}
		} catch (\Throwable $e) {
			Logger::debug('There is no existing payment request for order id ' . $orderId);
		}

		// Order paid with token.
		$orderPaidWithToken = false;

		// Handle save as token flag (to process it after payment on thank you page)
		$currentGateway = $this->getId();
		$newPaymentMethodFieldName = "wc-{$currentGateway}-new-payment-method";
		$createToken = false;
		Logger::debug('New payment method/save token checkbox : ' . isset($_POST[$newPaymentMethodFieldName]));
		if (isset($_POST[$newPaymentMethodFieldName])) {
			$order->add_meta_data('Nofrixion_saveTokenSelected', 1);
			$order->save();
			$createToken = true;
		}

		// Check if order contains a subscription.
		$hasSubscription = $this->checkWCOrderHasSubscription($order);
		Logger::debug('Order contains subscription: ' . ($hasSubscription ? 'true' : 'false'));

		// For subscriptions, we also create a token.
		if ($hasSubscription) {
			$createToken = true;
		}

		// Update the payment request with the final order data. If we can't update the payment request, abort the payment process.
		Logger::debug('Updating PaymentRequest on NoFrixion.');
		try {
			$paymentRequest = $this->updatePaymentRequest($paymentRequestId, $order, $createToken);
		} catch (\Throwable $e) {
			Logger::debug('Error updating payment request: ' . $e->getMessage());
			return ['result' => 'failure'];
		}
		if (!is_null($paymentRequest)) {
			Logger::debug('Updating payment request successful.');
			Logger::debug('PaymentRequest data: ');
			Logger::debug($paymentRequest);
			// Handle existing token selected for payment.
			// We directly redirect the user from here on success, otherwise continue with the response below.
			Logger::debug('Checking for existing token payment.');
			$paymentTokenFieldName = "wc-{$currentGateway}-payment-token";
			if (isset($_POST[$paymentTokenFieldName]) && 'new' !== $_POST[$paymentTokenFieldName]) {
				Logger::debug('Handle existing token payment.');
				$tokenId = wc_clean($_POST[$paymentTokenFieldName]);
				Logger::debug('Found existing token with id: ' . $tokenId);
				$token = \WC_Payment_Tokens::get($tokenId);
				if ($token->get_user_id() !== get_current_user_id()) {
					// todo: show notice, wc_add_notice()
					Logger::debug('Loaded token user id does not match current user id. Token id: ' . $tokenId);
					throw new \Exception(__('You are not allowed to use this saved card, aborting', 'nofrixion-for-woocommerce'));
				}

				// Try to pay with the saved token.
				$paywithTokenResult = $this->payWithToken($paymentRequestId, $token);
				if ($paywithTokenResult) {
					$order->update_meta_data('Nofrixion_tokenpayment_status', $paywithTokenResult['status']);
					$order->update_meta_data('Nofrixion_tokenisedCard_id', $token->get_token());
					$order->save();

					Logger::debug('Successfully paid with existing token: ' . print_r($paywithTokenResult, true));

					if ($paywithTokenResult['status'] === 'AUTHORIZED') {
						$order->payment_complete();
						$order->add_order_note('Payment with existing token successfully finished. TokenisedCardId: ' . $token->get_token());
						Logger::debug('Successfully completed token payment. Redirecting directly to received-order page.');
						$orderPaidWithToken = true;
					} else {
						$failedMsg = 'Failed to pay with token, returned other status than AUTHORIZED, payment failed. TokenisedCardId: ' . $token->get_token();
						Logger::debug($failedMsg);
						// Todo: maybe keep order in pending state here.
						$order->update_status('failed', $failedMsg);
						throw new \Exception(__('Card was not authorized. Please try another one.', 'nofrixion-for-woocommerce'));
					}
				} else {
					$order->add_order_note('Error processing payment with token, tokenisedCardId: ' . $token->get_token() . ' Check debug log for details.');
					throw new \Exception(__('Error processing the payment with your saved card. Please try another one.', 'nofrixion-for-woocommerce'));
				}
			} else {
				Logger::debug('No existing token payment selected, continuing.');
			}

			Logger::debug('All done, redirecting user.');
			// 'warning' notices generated up to here will be prepended to the json data in HTML format.
			wc_clear_notices();
			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url(false),
				'orderId' => $order->get_id(),
				'paymentRequestId' => $paymentRequestId,
				'hasSubscription' => $hasSubscription,
				'orderPaidWithToken' => $orderPaidWithToken,
				'orderReceivedPage' => $order->get_checkout_order_received_url(),
				'isPispPayment' => false,
			];
		} else {
			Logger::debug('Error updating payment request#: ' . $paymentRequestId);
			return ['result' => 'failure'];
		}
	}

	public function checkWCOrderHasSubscription(\WC_order $order): bool
	{
		// todo: check why wcs_order_contains_subscription() does not work
		if (\function_exists('wcs_order_contains_subscription')) {
			$result = \WC_Subscriptions_Cart::cart_contains_subscription();
			Logger::debug('Order contains subscription: ' . ($result ? 'true' : 'false'));
			return $result;
		} else {
			Logger::debug('Function wcs_order_contains_subscription() does not exist. No Subscriptions plugin installed.');
		}

		return false;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->get_option('title', 'NoFrixion');
	}

	public function getDescription(): string
	{
		return $this->get_option('description', '');
	}

	/**
	 * Get custom gateway icon, if any.
	 */
	public function getIcon(): string
	{
		return NOFRIXION_PLUGIN_URL . 'assets/images/btcpay-logo.png';
	}

	/**
	 * Add scripts.
	 */
	public function addScripts($hook_suffix)
	{
		// We only need this on checkout and pay-for-order page.
		if (!is_checkout() && !isset($_GET['pay_for_order']) && !is_add_payment_method_page()) {
			return;
		}

		// if our payment gateway is disabled, we do not have to enqueue JS too
		if ('no' === $this->enabled) {
			return;
		}

		// Load NoFrixion payelement.
		wp_enqueue_script('nofrixion_js', 'https://cdn.nofrixion.com/nofrixion.js');

		// Register custom css.
		wp_register_style('woocommerce-nofrixion-stylesheet', NOFRIXION_PLUGIN_URL . 'assets/css/nofrixion.css', array(), NOFRIXION_CSS_VERSION);
		wp_enqueue_style('woocommerce-nofrixion-stylesheet');

		// Register custom JS.
		wp_register_script('woocommerce_nofrixion', NOFRIXION_PLUGIN_URL . 'assets/js/nofrixion.js', ['jquery', 'nofrixion_js'], NOFRIXION_JS_VERSION, true);

		// Pass object NoFrixionWP to be available on the frontend in nofrixion.js.
		wp_localize_script('woocommerce_nofrixion', 'NoFrixionWP', [
			'url' => admin_url('admin-ajax.php'),
			'apiUrl' => $this->apiHelper->url,
			'apiNonce' => wp_create_nonce('nofrixion-nonce'),
			'isRequiredField' => __('is a required field.', 'nofrixion-for-woocommerce'),
			'is_change_payment_page' => isset($_GET['change_payment_method']) ? 'yes' : 'no',
			'is_checkout_page' => is_checkout() ? 'yes' : 'no',
			'is_pay_for_order_page' => is_wc_endpoint_url('order-pay') ? 'yes' : 'no',
			'is_add_payment_method_page' => is_add_payment_method_page() ? 'yes' : 'no',
			'pispNoProviderSelected' => __('Please select a bank to continue.', 'nofrixion-for-woocommerce'),
		]);

		// Add the registered nofrixion script to frontend.
		wp_enqueue_script('woocommerce_nofrixion');
	}

	/**
	 * Process webhooks from NoFrixion.
	 */
	public function processWebhook()
	{
		// todo nofrixion: this is currently not needed
		if ($rawPostData = file_get_contents("php://input")) {
			// Validate webhook request.
			// Note: getallheaders() CamelCases all headers for PHP-FPM/Nginx but for others maybe not, so "NoFrixion-Sig" may becomes "Btcpay-Sig".
			$headers = getallheaders();
			foreach ($headers as $key => $value) {
				if (strtolower($key) === 'nofrixion-sig') {
					$signature = $value;
				}
			}

			if (!isset($signature) || !$this->apiHelper->validWebhookRequest($signature, $rawPostData)) {
				Logger::debug('Failed to validate signature of webhook request.');
				wp_die('Webhook request validation failed.');
			}

			try {
				$postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);

				if (!isset($postData->invoiceId)) {
					Logger::debug('No NoFrixion invoiceId provided, aborting.');
					wp_die('No NoFrixion invoiceId provided, aborting.');
				}

				// Load the order by metadata field Nofrixion_id
				$orders = wc_get_orders([
					'meta_key' => 'Nofrixion_id',
					'meta_value' => $postData->invoiceId
				]);

				// Abort if no orders found.
				if (count($orders) === 0) {
					Logger::debug('Could not load order by NoFrixion invoiceId: ' . $postData->invoiceId);
					wp_die('No order found for this invoiceId.', '', ['response' => 404]);
				}

				// TODO: Handle multiple matching orders.
				if (count($orders) > 1) {
					Logger::debug('Found multiple orders for invoiceId: ' . $postData->invoiceId);
					Logger::debug(print_r($orders, true));
					wp_die('Multiple orders found for this invoiceId, aborting.');
				}

				$this->processOrderStatus($orders[0], $postData);
			} catch (\Throwable $e) {
				Logger::debug('Error decoding webook payload: ' . $e->getMessage());
				Logger::debug($rawPostData);
			}
		}
	}

	protected function processOrderStatus(\WC_Order $order, \stdClass $webhookData)
	{
		if (!in_array($webhookData->type, GreenfieldApiWebhook::WEBHOOK_EVENTS)) {
			Logger::debug('Webhook event received but ignored: ' . $webhookData->type);
			return;
		}

		Logger::debug('Updating order status with webhook event received for processing: ' . $webhookData->type);
		// Get configured order states or fall back to defaults.
		if (!$configuredOrderStates = get_option('nofrixion_order_states')) {
			$configuredOrderStates = (new OrderStates())->getDefaultOrderStateMappings();
		}

		switch ($webhookData->type) {
			case 'InvoiceReceivedPayment':
				if ($webhookData->afterExpiration) {
					if ($order->get_status() === $configuredOrderStates[OrderStates::EXPIRED]) {
						$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
						$order->add_order_note(__('Invoice payment received after invoice was already expired.', 'nofrixion-for-woocommerce'));
					}
				} else {
					// No need to change order status here, only leave a note.
					$order->add_order_note(__('Invoice (partial) payment received. Waiting for full payment.', 'nofrixion-for-woocommerce'));
				}

				// Store payment data (exchange rate, address).
				$this->updateWCOrderPayments($order);

				break;
			case 'InvoiceProcessing': // The invoice is paid in full.
				$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::PROCESSING]);
				if ($webhookData->overPaid) {
					$order->add_order_note(__('Invoice payment received fully with overpayment, waiting for settlement.', 'nofrixion-for-woocommerce'));
				} else {
					$order->add_order_note(__('Invoice payment received fully, waiting for settlement.', 'nofrixion-for-woocommerce'));
				}
				break;
			case 'InvoiceInvalid':
				$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::INVALID]);
				if ($webhookData->manuallyMarked) {
					$order->add_order_note(__('Invoice manually marked invalid.', 'nofrixion-for-woocommerce'));
				} else {
					$order->add_order_note(__('Invoice became invalid.', 'nofrixion-for-woocommerce'));
				}
				break;
			case 'InvoiceExpired':
				if ($webhookData->partiallyPaid) {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED_PAID_PARTIAL]);
					$order->add_order_note(__('Invoice expired but was paid partially, please check.', 'nofrixion-for-woocommerce'));
				} else {
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::EXPIRED]);
					$order->add_order_note(__('Invoice expired.', 'nofrixion-for-woocommerce'));
				}
				break;
			case 'InvoiceSettled':
				$order->payment_complete();
				if ($webhookData->overPaid) {
					$order->add_order_note(__('Invoice payment settled but was overpaid.', 'nofrixion-for-woocommerce'));
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::SETTLED_PAID_OVER]);
				} else {
					$order->add_order_note(__('Invoice payment settled.', 'nofrixion-for-woocommerce'));
					$this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::SETTLED]);
				}

				// Store payment data (exchange rate, address).
				$this->updateWCOrderPayments($order);

				break;
		}
	}

	/**
	 * Checks if the order has already a NoFrixion invoice set and checks if it is still
	 * valid to avoid creating multiple invoices for the same order on NoFrixion Server end.
	 *
	 * @param int $orderId
	 *
	 * @return mixed Returns false if no valid invoice found or the invoice id.
	 */
	protected function validInvoiceExists(int $orderId): bool
	{
		// Check order metadata for Nofrixion_id.
		if ($invoiceId = get_post_meta($orderId, 'Nofrixion_id', true)) {
			// Validate the order status on NoFrixion server.
			$client = new Invoice($this->apiHelper->url, $this->apiHelper->apiKey);
			try {
				Logger::debug('Trying to fetch existing invoice from NoFrixion Server.');
				$invoice       = $client->getInvoice($this->apiHelper->storeId, $invoiceId);
				$invalidStates = ['Expired', 'Invalid'];
				if (in_array($invoice->getData()['status'], $invalidStates)) {
					return false;
				} else {
					return true;
				}
			} catch (\Throwable $e) {
				Logger::debug($e->getMessage());
			}
		}

		return false;
	}

	/**
	 * Update WC order status (if a valid mapping is set).
	 */
	public function updateWCOrderStatus(\WC_Order $order, string $status): void
	{
		if ($status !== OrderStates::IGNORE) {
			$order->update_status($status);
		}
	}

	public function updateWCOrderPayments(\WC_Order $order): void
	{
		// Load payment data from API.
		try {
			$client = new Invoice($this->apiHelper->url, $this->apiHelper->apiKey);
			$allPaymentData = $client->getPaymentMethods($this->apiHelper->storeId, $order->get_meta('Nofrixion_id'));

			foreach ($allPaymentData as $payment) {
				// Only continue if the payment method has payments made.
				if ((float) $payment->getTotalPaid() > 0.0) {
					$paymentMethod = $payment->getPaymentMethod();
					// Update order meta data.
					update_post_meta($order->get_id(), "Nofrixion_{$paymentMethod}_destination", $payment->getDestination() ?? '');
					update_post_meta($order->get_id(), "Nofrixion_{$paymentMethod}_amount", $payment->getAmount() ?? '');
					update_post_meta($order->get_id(), "Nofrixion_{$paymentMethod}_paid", $payment->getTotalPaid() ?? '');
					update_post_meta($order->get_id(), "Nofrixion_{$paymentMethod}_networkFee", $payment->getNetworkFee() ?? '');
					update_post_meta($order->get_id(), "Nofrixion_{$paymentMethod}_rate", $payment->getRate() ?? '');
					if ((float) $payment->getRate() > 0.0) {
						$formattedRate = number_format((float) $payment->getRate(), wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
						update_post_meta($order->get_id(), "Nofrixion_{$paymentMethod}_rateFormatted", $formattedRate);
					}
				}
			}
		} catch (\Throwable $e) {
			Logger::debug('Error processing payment data for invoice: ' . $order->get_meta('Nofrixion_id') . ' and order ID: ' . $order->get_id());
			Logger::debug($e->getMessage());
		}
	}

	/*
	 * Create an payment request on NoFrixion Server. ** DOES THIS EVER RUN? **
	
	public function createPaymentRequest(\WC_Order $order, bool $createToken = false): ?PaymentRequest
	{
		Logger::debug('Entering createPaymentRequest()');
		// In case some plugins customizing the order number we need to pass that along, defaults to internal ID.
		$orderNumber = $order->get_order_number();
		Logger::debug('Got order number: ' . $orderNumber . ' and order ID: ' . $order->get_id());

		$originUrl     = get_site_url();
		Logger::debug('Setting origin url to: ' . $originUrl);

		$currency = $order->get_currency();
		$amount = PreciseNumber::parseString($order->get_total()); // unlike method signature suggests, it returns string.

		// We need to set the customer id for card tokens on subscriptions.
		$userId = get_current_user_id();
		if ($userId === 0) {
			$userId = hash('sha256', $order->get_billing_email());
		}

		try {
			$newPr = new PaymentRequestCreate($amount->__toString());

			$newPr->callbackUrl = $this->get_return_url($order);
			$newPr->customerEmailAddress = $order->get_billing_email();
			$newPr->currency = $currency;
			$newPr->paymentMethodTypes = implode(',', [str_replace('nofrixion_', '', $this->getId())]);
			$newPr->orderID = $orderNumber;
			$newPr->cardCreateToken = $createToken;
			$newPr->customerID = $createToken ? (string) $userId : null;

			$paymentRequest = $this->apiHelper->paymentRequestClient->createPaymentRequest($newPr);

			$this->updateOrderMetadata($order, (array) $paymentRequest);

			return $paymentRequest;
		} catch (\Throwable $e) {
			Logger::debug($e->getMessage(), true);
		}

		return null;
	}
 */

	/**
	 * Update payment request on NoFrixion Server with details from Order being processed.
	 */
	public function updatePaymentRequest(string $paymentRequestId, \WC_Order $order, bool $createToken = false): ?PaymentRequest
	{
		Logger::debug('Entering updatePaymentRequest()');
		// In case some plugins customizing the order number we need to pass that along, defaults to internal ID.
		$orderNumber = $order->get_order_number();
		Logger::debug('Got order number: ' . $orderNumber . ' and order ID: ' . $order->get_id());

		$originUrl     = get_site_url();
		Logger::debug('Setting origin url to: ' . $originUrl);

		$currency = $order->get_currency();
		$amount = PreciseNumber::parseString($order->get_total()); // unlike method signature suggests, it returns string.

		// Set the customer id (hash billing email for anonymous users).
		$userId = get_current_user_id();
		if ($userId === 0) {
			$userId = hash('sha256', $order->get_billing_email());
		}

		try {
			$updatePr = new PaymentRequestUpdate();
			$updatePr->callbackUrl = $this->get_return_url($order);
			$updatePr->amount = $amount->__toString();
			$updatePr->currency = $currency;
			$updatePr->paymentMethodTypes = implode(',', [str_replace('nofrixion_', '', $this->getId())]);
			$updatePr->orderID = $orderNumber;
			$updatePr->cardCreateToken = $createToken;
			//$updatePr->customerID = $createToken ? (string) $userId : null;
			$updatePr->customerID = (string) $userId;
			$updatePr->customerEmailAddress = $order->get_billing_email();

			$paymentRequest = $this->apiHelper->paymentRequestClient->updatePaymentRequest($paymentRequestId, $updatePr);

			$this->updateOrderMetadata($order, (array) $paymentRequest);

			// Payment request 'stub' has been updated to match a specific order.
			// => Clear WC session variable so we don't use it again.
			WC()->session->__unset(NOFRIXION_SESSION_PAYMENTREQUEST_ID);

			return $paymentRequest;
		} catch (\Throwable $e) {
			Logger::debug('Error updating payment request: ' . $e->getMessage(), true);
		}

		return null;
	}

	/**
	 * Maps customer billing metadata.
	 */
	protected function prepareCustomerMetadata(\WC_Order $order): array
	{
		return [
			'buyerEmail'    => $order->get_billing_email(),
			'buyerName'     => $order->get_formatted_billing_full_name(),
			'buyerAddress1' => $order->get_billing_address_1(),
			'buyerAddress2' => $order->get_billing_address_2(),
			'buyerCity'     => $order->get_billing_city(),
			'buyerState'    => $order->get_billing_state(),
			'buyerZip'      => $order->get_billing_postcode(),
			'buyerCountry'  => $order->get_billing_country()
		];
	}

	/**
	 * References WC order metadata with NoFrixion payment request data.
	 */
	protected function updateOrderMetadata(\WC_Order $order, array $paymentRequest)
	{
		$orderId = $order->get_id();
		update_post_meta($orderId, 'Nofrixion_id', $paymentRequest['id']);
		update_post_meta($orderId, 'Nofrixion_isSubscription', $this->checkWCOrderHasSubscription($order));
		update_post_meta($orderId, 'Nofrixion_CustomerID', $paymentRequest['customerID'] ?? '');
	}

	public function isChangingPaymentMethodForSubscription()
	{
		if (isset($_GET['change_payment_method'])) { // phpcs:ignore WordPress.Security.NonceVerification
			return wcs_is_subscription(wc_clean(wp_unslash($_GET['change_payment_method']))); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return false;
	}

	public function scheduledSubscriptionPayment(float $amount, \WC_Order $renewalOrder)
	{
		Logger::debug('Subs: Triggered scheduled_subscription_payment() hook.');
		Logger::debug('Subs: amount: ' . $amount . ' renewal order id: ' . $renewalOrder->get_id());

		$subscriptions = wcs_get_subscriptions_for_renewal_order($renewalOrder);
		$subscription  = array_pop($subscriptions);
		$failedMsg = 'Subs: Could not process renewal order: ' . $renewalOrder->get_id();

		if (!($parentOrderId = $subscription->get_parent_id())) {
			Logger::debug('Subs: Failed to load parent order id, aborting.', true);
			$renewalOrder->update_status('failed', $failedMsg);
			return ['result' => 'failure'];
		}

		if (!($parentOrder = new \WC_Order($parentOrderId))) {
			Logger::debug('Subs: Failed to load parent order, aborting.', true);
			$renewalOrder->update_status('failed', $failedMsg);
			return ['result' => 'failure'];
		}

		if (!($tokenisedCardId = $parentOrder->get_meta('Nofrixion_tokenisedCard_id'))) {
			Logger::debug('Subs: Failed to load tokenisedCard_id from order: ' . $parentOrderId, true);
			$renewalOrder->update_status('failed', $failedMsg);
			return ['result' => 'failure'];
		}

		Logger::debug('Subs: renewalOrder object: ' . print_r($renewalOrder));
		Logger::debug('Subs: Subscription object: ' . print_r($subscription));

		// Prepare data.
		$orderNumber = $renewalOrder->get_order_number();
		$originUrl = get_site_url();
		$currency = $renewalOrder->get_currency();
		$amountFormatted = PreciseNumber::parseFloat($amount);

		// PaymentRequest client.
		$client = new PaymentRequestClient($this->apiHelper->url, $this->apiHelper->apiToken);

		// Create payment request with cardtoken payment type.
		try {

			$newPr = new PaymentRequestCreate($amountFormatted->__toString());
			$newPr->baseOriginUrl = $originUrl;
			$newPr->callbackUrl = $this->get_return_url($renewalOrder);
			$newPr->customerEmailAddress = $order->get_billing_email();
			$newPr->currency = $currency;
			$newPr->paymentMethodTypes = 'cardtoken';
			$newPr->orderID = $orderNumber;

			$paymentRequest = $client->createPaymentRequest($newPr);

			$renewalOrder->update_meta_data('Nofrixion_isSubscription', 1);
			$renewalOrder->update_meta_data('Nofrixion_tokenisedCard_id', $tokenisedCardId);

			Logger::debug('Subs: Successfully created new payment request: ' . print_r($paymentRequest, true));
		} catch (\Throwable $e) {
			Logger::debug('Subs: Error creating payment request for subs renewal: ' . $e->getMessage(), true);
			return ['result' => 'failure'];
		}

		try {
			// Charge payment request with tokenised card.
			$paywithTokenResult = $client->payWithCardToken(
				$paymentRequest->id,
				$tokenisedCardId
			);

			$renewalOrder->update_meta_data('Nofrixion_renewal_status', $paywithTokenResult['status']);

			Logger::debug('Subs: Successfully created pay with token request: ' . print_r($paywithTokenResult, true));

			if ($paywithTokenResult['status'] === 'AUTHORIZED') {
				$renewalOrder->payment_complete();
				$renewalOrder->add_order_note('Renewal of subscription successfully processed.');
				Logger::debug('Subs: Successfully completed renewal payment. Exiting.');
				return ['result' => 'success'];
			} else {
				Logger::debug('Subs: Pay with token returned other status than AUTHORIZED, payment failed.');
				$renewalOrder->update_status('failed', $failedMsg);
				return ['result' => 'failure'];
			}
		} catch (\Throwable $e) {
			Logger::debug('Subs: Error on request for paying with card token: ' . $e->getMessage(), true);
			$renewalOrder->update_status('failed', $failedMsg);
			return ['result' => 'failure'];
		}
	}

	public function updateSubscriptionPaymentMethod($originalSubscription, $renewalOrder)
	{
		Logger::debug('UpdateSubscriptionPaymentMethod():');
		Logger::debug('$origSubs: ' . print_r($originalSubscription, true));
		Logger::debug('$renewalOrder: ' . print_r($renewalOrder, true));
		$subscription = wc_get_order($originalSubscription->id);
		Logger::debug('$subscription: ' . print_r($subscription, true));
		/*
		$subscription->update_meta_data('Nofrixion_tokenisedCard_id', $renewalOrder->Nofrixion_tokenisedCard_id);
		$subscription->update_meta_data('Nofrixion_id', $renewalOrder->Nofrixion_id);
		$subscription->save();
		*/
	}

	private function payWithToken(string $paymentRequestId, \WC_Payment_Token $token): array
	{
		Logger::debug('Entering payWithToken().');
		Logger::debug('$paymentRequestId: ' . $paymentRequestId . ' $tokenisedCardId: ' . $token->get_token() . ' TokenId: ' . $token->get_id());

		try {
			// Charge payment request with tokenised card.
			$result = $this->apiHelper->paymentRequestClient->payWithCardToken(
				$paymentRequestId,
				$token->get_token()
			);

			return $result;
		} catch (\Throwable $e) {
			Logger::debug('Error on request to pay with token: ' . $e->getMessage(), true);
		}

		return [];
	}
}
