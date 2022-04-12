<?php

declare( strict_types=1 );

namespace NoFrixion\WC\Gateway;

use NoFrixion\WC\Client\PaymentRequest;
use NoFrixion\WC\Helper\ApiHelper;
use NoFrixion\WC\Helper\Logger;
use NoFrixion\WC\Helper\OrderStates;
use NoFrixion\WC\Helper\PreciseNumber;

abstract class NoFrixionGateway extends \WC_Payment_Gateway {

	public ApiHelper $apiHelper;

	public function __construct() {
		// General gateway setup.
		// Do not set id here.


		//$this->icon              = $this->getIcon();

		$this->order_button_text = __( 'Place order', 'nofrixion-for-woocommerce' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user facing set variables.
		$this->title        = $this->getTitle();
		$this->description  = $this->getDescription();

		// Admin facing title and description.
		$this->method_title       = 'NoFrixion';
		$this->method_description = __('NoFrixion gateway supporting all available credit card and SEPA payments.', 'nofrixion-for-woocommerce');

		// Debugging & informational settings.
		$this->debug_php_version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$this->debug_plugin_version = NOFRIXION_VERSION;

		$this->apiHelper = new ApiHelper();

		// Actions.
		add_action('woocommerce_api_nofrixion', [$this, 'processWebhook']);
		add_action('wp_enqueue_scripts', [$this, 'addScripts']);
		add_action('woocommerce_update_options_payment_gateways_' . $this->getId(), [$this, 'process_admin_options']);
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled' => [
				'title'       => __( 'Enabled/Disabled', 'nofrixion-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable this payment gateway.', 'nofrixion-for-woocommerce' ),
				'default'     => 'no',
				'value'       => 'yes',
				'desc_tip'    => false,
			],
			'title'       => [
				'title'       => __( 'Customer Text', 'nofrixion-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Controls the name of this payment method as displayed to the customer during checkout.', 'nofrixion-for-woocommerce' ),
				'default'     => $this->title,
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Customer Message', 'nofrixion-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Message to explain how the customer will be paying for the purchase.', 'nofrixion-for-woocommerce' ),
				'default'     => $this->description,
				'desc_tip'    => true,
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function process_payment( $orderId ) {

		if ( ! $this->apiHelper->isConfigured() ) {
			Logger::debug( 'NoFrixion Server API connection not configured, aborting. Please go to NoFrixion settings and set it up.' );
			// todo: show error notice/make sure it fails
			throw new \Exception( __( "Can't process order. No merchant token configured, aborting.", 'nofrixion-for-woocommerce' ) );
		}

		// Load the order and check it.
		$order = new \WC_Order( $orderId );
		if ( $order->get_id() === 0 ) {
			$message = 'Could not load order id ' . $orderId . ', aborting.';
			Logger::debug( $message, true );
			throw new \Exception( $message );
		}

		// Check for existing invoice and redirect instead.
		/*
		if ( $this->validInvoiceExists( $orderId ) ) {
			$existingInvoiceId = get_post_meta( $orderId, 'NoFrixion_id', true );
			Logger::debug( 'Found existing NoFrixion Server invoice and redirecting to it. Invoice id: ' . $existingInvoiceId );

			return [
				'result'   => 'success',
				'redirect' => $this->apiHelper->getInvoiceRedirectUrl( $existingInvoiceId ),
			];
		}
		*/

		// Create an invoice.
		Logger::debug( 'Creating PaymentRequest on NoFrixion.' );
		if ( $paymentRequest = $this->createPaymentRequest( $order ) ) {

			// Todo: update order status and NoFrixion meta data.

			Logger::debug( 'PaymentRequest creation successful, redirecting user.' );

			Logger::debug($paymentRequest, true);

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url(false),
				'orderId' => $order->get_id(),
				'paymentRequestId' => $paymentRequest['id'],
			];
		}
	}


	public function getId(): string {
		return $this->id;
	}

	public function getTitle(): string {
		return $this->get_option('title', 'NoFrixion');
	}

	public function getDescription(): string {
		return $this->get_option('description', '');
	}

	/**
	 * Get custom gateway icon, if any.
	 */
	public function getIcon(): string {
		return NOFRIXION_PLUGIN_URL . 'assets/images/btcpay-logo.png';
	}

	/**
	 * Add scripts.
	 */
	public function addScripts($hook_suffix) {
		// We only need this on checkout and pay-for-order page.
		if ( ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
			return;
		}

		// if our payment gateway is disabled, we do not have to enqueue JS too
		if ( 'no' === $this->enabled ) {
			return;
		}

		// let's suppose it is our payment processor JavaScript that allows to obtain a token
		wp_enqueue_script( 'nofrixion_js', 'http://host.docker.internal/payelement.js' );

		// and this is our custom JS in your plugin directory that works with token.js
		wp_register_script( 'woocommerce_nofrixion', NOFRIXION_PLUGIN_URL . 'assets/js/nofrixion.js', [ 'jquery', 'nofrixion_js' ], false, true );

		// in most payment processors you have to use PUBLIC KEY to obtain a token
		wp_localize_script( 'woocommerce_nofrixion', 'NoFrixionWP', [
			'url' => admin_url( 'admin-ajax.php' ),
			'apiNonce' => wp_create_nonce( 'nofrixion-nonce' ),
			'isRequiredField' => __('is a required field.', 'nofrixion-for-woocommerce'),
			'nfApiUrl' => $this->get_option('url', null),
		] );

		wp_enqueue_script( 'woocommerce_nofrixion' );
	}

	/**
	 * Process webhooks from NoFrixion.
	 */
	public function processWebhook() {
		// todo nofrixion: this is currently not needed
		if ($rawPostData = file_get_contents("php://input")) {
			// Validate webhook request.
			// Note: getallheaders() CamelCases all headers for PHP-FPM/Nginx but for others maybe not, so "NoFrixion-Sig" may becomes "Btcpay-Sig".
			$headers = getallheaders();
			foreach ($headers as $key => $value) {
				if (strtolower($key) === 'btcpay-sig') {
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

				// Load the order by metadata field NoFrixion_id
				$orders = wc_get_orders([
					'meta_key' => 'NoFrixion_id',
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

	protected function processOrderStatus(\WC_Order $order, \stdClass $webhookData) {
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
	protected function validInvoiceExists( int $orderId ): bool {
		// Check order metadata for NoFrixion_id.
		if ( $invoiceId = get_post_meta( $orderId, 'NoFrixion_id', true ) ) {
			// Validate the order status on NoFrixion server.
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			try {
				Logger::debug( 'Trying to fetch existing invoice from NoFrixion Server.' );
				$invoice       = $client->getInvoice( $this->apiHelper->storeId, $invoiceId );
				$invalidStates = [ 'Expired', 'Invalid' ];
				if ( in_array( $invoice->getData()['status'], $invalidStates ) ) {
					return false;
				} else {
					return true;
				}
			} catch ( \Throwable $e ) {
				Logger::debug( $e->getMessage() );
			}
		}

		return false;
	}

	/**
	 * Update WC order status (if a valid mapping is set).
	 */
	public function updateWCOrderStatus(\WC_Order $order, string $status): void {
		if ($status !== OrderStates::IGNORE) {
			$order->update_status($status);
		}
	}

	public function updateWCOrderPayments(\WC_Order $order): void {
		// Load payment data from API.
		try {
			$client = new Invoice( $this->apiHelper->url, $this->apiHelper->apiKey );
			$allPaymentData = $client->getPaymentMethods($this->apiHelper->storeId, $order->get_meta('NoFrixion_id'));

			foreach ($allPaymentData as $payment) {
				// Only continue if the payment method has payments made.
				if ((float) $payment->getTotalPaid() > 0.0) {
					$paymentMethod = $payment->getPaymentMethod();
					// Update order meta data.
					update_post_meta( $order->get_id(), "NoFrixion_{$paymentMethod}_destination", $payment->getDestination() ?? '' );
					update_post_meta( $order->get_id(), "NoFrixion_{$paymentMethod}_amount", $payment->getAmount() ?? '' );
					update_post_meta( $order->get_id(), "NoFrixion_{$paymentMethod}_paid", $payment->getTotalPaid() ?? '' );
					update_post_meta( $order->get_id(), "NoFrixion_{$paymentMethod}_networkFee", $payment->getNetworkFee() ?? '' );
					update_post_meta( $order->get_id(), "NoFrixion_{$paymentMethod}_rate", $payment->getRate() ?? '' );
					if ((float) $payment->getRate() > 0.0) {
						$formattedRate = number_format((float) $payment->getRate(), wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
						update_post_meta( $order->get_id(), "NoFrixion_{$paymentMethod}_rateFormatted", $formattedRate );
					}
				}
			}
		} catch (\Throwable $e) {
			Logger::debug( 'Error processing payment data for invoice: ' . $order->get_meta('NoFrixion_id') . ' and order ID: ' . $order->get_id() );
			Logger::debug($e->getMessage());
		}
	}

	/**
	 * Create an invoice on NoFrixion Server.
	 */
	public function createPaymentRequest( \WC_Order $order ): ?array {
		// In case some plugins customizing the order number we need to pass that along, defaults to internal ID.
		$orderNumber = $order->get_order_number();
		Logger::debug( 'Got order number: ' . $orderNumber . ' and order ID: ' . $order->get_id() );

		$originUrl     = get_site_url();
		Logger::debug( 'Setting origin url to: ' . $originUrl );

		$currency = $order->get_currency();
		$amount = PreciseNumber::parseString( $order->get_total() ); // unlike method signature suggests, it returns string.

		try {
			$client = new PaymentRequest( $this->apiHelper->url, $this->apiHelper->apiToken );

			$paymentRequest = $client->createPaymentRequest(
				$originUrl,
				$this->get_return_url($order),
				$amount,
				$currency,
				[str_replace('nofrixion_', '', $this->getId())], // pass card, pisp, .. here
				$orderNumber
			);

			$this->updateOrderMetadata( $order->get_id(), $paymentRequest );

			return $paymentRequest;

		} catch ( \Throwable $e ) {
			Logger::debug( $e->getMessage(), true );
		}

		return null;
	}

	/**
	 * Maps customer billing metadata.
	 */
	protected function prepareCustomerMetadata( \WC_Order $order ): array {
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
	protected function updateOrderMetadata( int $orderId, array $paymentRequest ) {
		update_post_meta( $orderId, 'NoFrixion_id', $paymentRequest['id'] );
	}
}
