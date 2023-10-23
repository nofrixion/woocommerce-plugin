<?php

declare(strict_types=1);

namespace Nofrixion\WC\Gateway;

use Nofrixion\Model\PaymentRequests\PaymentRequest;
use Nofrixion\WC\Helper\ApiHelper;
use Nofrixion\WC\Helper\Logger;

class NofrixionPisp extends NofrixionGateway
{
	private const PISP_MINIMUM_AMOUNT = 1.00;
	public array $pispProviders;

	public function __construct()
	{

		// General gateway setup.
		$this->id = 'nofrixion_pisp';

		// Call parent constructor. $apiHelper is initialised there.
		parent::__construct();

		$this->has_fields = true;

		// Define user facing set variables.
		$this->title        = $this->getTitle();
		$this->description  = $this->getDescription();

		// Admin facing title and description.
		$this->method_title       = 'NoFrixion PISP';
		$this->method_description = __('NoFrixion gateway supporting all available PISP banks.', 'nofrixion-for-woocommerce');
	}

	// Handles MoneyMoov API success webhook that confirms payments received.
	public static function pispNotify($request)
	{
		// Note: the wordpress REST API setup calls this statically. Need to instantiate ApiHelper
		$apiHelper = new ApiHelper();
		try {
			$paymentRequest = $apiHelper->paymentRequestClient->getPaymentRequest($request['id']);
		} catch (\Throwable $e) {
			Logger::debug($e->getMessage(), true);
		}
		// Check payment request is fully paid and update the order status.
		if (!is_null($paymentRequest) && $paymentRequest->status == 'FullyPaid') {
			$order = wc_get_order($paymentRequest->orderID);
			$order->update_status('processing', 'Receipt of payment confirmed.');
			$order->save();
		} else {
			// is there any point throwing an error here (it's an invisible callback).
		}

		return rest_ensure_response('Done.');
	}

	public function getPayByBankSettings(): array
	{
		$merchantId = $this->apiHelper->merchantClient->whoAmIMerchant()->id;
		$settings = $this->apiHelper->merchantClient->getMerchantPayByBankSettings($merchantId);
		// quick filter base on currency, may not be needed after API update
		$currency = get_option('woocommerce_currency', null);

		// API was returning banks for multiple currencie at one stage. Filter to providers for store currency.
		$settings = array_values(array_filter($settings, function ($bank) use ($currency) {
			return $bank->currency === $currency;
		}));

		//$names = array_column($settings, 'bankName');
		return $settings;
	}

	public function payment_fields()
	{
		if (empty($this->pispProviders)){
			$this->pispProviders = $this->getPayByBankSettings();
		}

		echo '<div class="nf-pisp-payment-options">';
		foreach ($this->pispProviders as $provider) {
			echo '<input class="nf-bank-button" type="image" src="' . $provider->logo . '" alt="' . $provider->bankName . '" name="wc-pisp-provider" value="' . $provider->personalInstitutionID . '">';
		}
		echo '<input id="pisp_provider_id" type="hidden" name="pisp_provider_id" value="">';
		echo '</div>';
	}

	/**
	 * @inheritDoc
	 */
	public function process_payment($orderId)
	{ 
		// Check cart value is at least 1 EURO before proceeding.
		if (WC()->cart->total < self::PISP_MINIMUM_AMOUNT) {
			wc_add_notice(__('Pay by bank transactions must be at least 1 Euro.'), 'error');
			return;
		}

		if (!$this->apiHelper->isConfigured()) {
			Logger::debug('NoFrixion Server API connection not configured, aborting. Please go to NoFrixion settings and set it up.');
			throw new \Exception(__("Can't process order. No merchant token configured, aborting.", 'nofrixion-for-woocommerce'));
		} else {
			$client = $this->apiHelper->paymentRequestClient;
		}

		// Load the order and check it.
		$order = new \WC_Order($orderId);
		if ($order->get_id() === 0) {
			$message = 'Could not load order id ' . $orderId . ', aborting.';
			Logger::debug($message, true);
			throw new \Exception($message);
		}

		$paymentRequestId = wc_clean(wp_unslash($_POST['payment_request_id']));

		Logger::debug('Payment request id: ' . $paymentRequestId);

		if (!$paymentRequestId) {
			$msg_no_prid = __('No payment request id found, aborting.', 'nofrixion-for-woocommerce');
			Logger::debug($msg_no_prid);
			throw new \Exception($msg_no_prid);
		}

		/* ** DISCUSS THIS WITH AARON **
		// check if there is an existing PR for this order and use that (can happen if there was a previous processing error).
		try {
			$existingPr = $client->getPaymentRequestByOrderId(strval($orderId));
			if ($existingPr->status == "None") {
				$client->deletePaymentRequest($paymentRequestId);
				$paymentRequestId = $existingPr->id;
			}
		} catch (\Nofrixion\Exception\RequestException $e) {
			Logger::debug('No existing PR for this order');
		}
		*/

		$pispProviderId = sanitize_key($_POST['pisp_provider_id']);
		Logger::debug('Selected pisp provider: ' . $pispProviderId);

		// Check for allowed pisp providers, store provider id to order.
		if (empty($this->pispProviders)){
			$this->pispProviders = $this->getPayByBankSettings();
		}
		$allowedPispProviders = array_column($this->pispProviders, 'personalInstitutionID');
		if (!in_array($pispProviderId, $allowedPispProviders)) {
			$msg_no_provider = __('No valid pisp provider found, aborting.', 'nofrixion-for-woocommerce');
			Logger::debug($msg_no_provider);
			throw new \Exception($msg_no_provider);
		}

		$order->add_meta_data('Nofrixion_pispProviderId', $pispProviderId);
		$order->save();

		// Update the payment request with the final order data.
		try {
			$paymentRequest = $this->updatePaymentRequest($paymentRequestId, $order);
		} catch (\Throwable $e) {
			Logger::debug('Error updating payment request: ' . $e->getMessage());
			return ['result' => 'failure'];
		}

		if (!is_null($paymentRequest)) {
			// Initiate pisp payment.
			try {
				$pispInitiationResponse = $client->initiatePayByBank($paymentRequest->id, $pispProviderId, $paymentRequest->callbackUrl, null);

				Logger::debug('Payment initiation request (PIR): ');
				Logger::debug(print_r($pispInitiationResponse, true));

				// Check for redirect url.
				if (empty($pispInitiationResponse->redirectUrl)) {
					Logger::debug('Got no redirect url for PIR, aborting.');
					return ['result' => 'failure'];
				}

				Logger::debug('Payment initiation response received, redirecting user.');

				return [
					'result'   => 'success',
					'redirect' => $order->get_checkout_payment_url(false),
					'orderId' => $order->get_id(),
					'paymentRequestId' => $paymentRequestId,
					'hasSubscription' => false,
					'orderPaidWithToken' => false,
					'orderReceivedPage' => $order->get_checkout_order_received_url(),
					'isPispPayment' => true,
					'pispRedirectUrl' => $pispInitiationResponse->redirectUrl
				];
			} catch (\Throwable $e) {
				Logger::debug('Error with PISP initiation: ' . $e->getMessage());
				return ['result' => 'failure'];
			}
		} else {
			Logger::debug('Error updating paryment request');
			return ['result' => 'failure'];
		}
	}

	public function getTitle(): string
	{
		return $this->get_option('title', 'Pay by Bank');
	}

	public function getDescription(): string
	{
		return $this->get_option('description', '');
	}
}
