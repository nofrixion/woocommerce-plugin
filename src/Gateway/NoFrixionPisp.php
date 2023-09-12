<?php

declare( strict_types=1 );

namespace NoFrixion\WC\Gateway;

use NoFrixion\Client\PaymentRequestClient;
use NoFrixion\WC\Helper\ApiHelper;
use NoFrixion\WC\Helper\Logger;

class NoFrixionPisp extends NoFrixionGateway {

	public ApiHelper $apiHelper;

	public static array $providers = [
		'aib' => 'aib',
		'revolut' => 'revolut_eu',
		'boi' => 'bankofireland',
		'ptsb' => 'permanent-tsb',
	];

	public function __construct() {
		// General gateway setup.
		$this->id = 'nofrixion_pisp';

		// Call parent constructor.
		parent::__construct();

		$this->has_fields = true;
		// Define user facing set variables.
		$this->title        = $this->getTitle();
		$this->description  = $this->getDescription();

		// Admin facing title and description.
		$this->method_title       = 'NoFrixion PISP';
		$this->method_description = __('NoFrixion gateway supporting all available PISP banks.', 'nofrixion-for-woocommerce');
	}

	public function payment_fields() {
		// Todo: get the fields from api endpoint.
		echo "
			<div class='row'>
				<input type='radio' value='revolut' name='wc-pisp-provider' id='wc-pisp-revolut' /><label for='wc-pisp-revolut'>Revolut</label>
			</div>
			<div class='row'>
				<input type='radio' value='aib' name='wc-pisp-provider' id='wc-pisp-aib' /><label for='wc-pisp-aib'>Allied Irish Bank</label>
			</div>
			<div class='row'>
				<input type='radio' value='boi' name='wc-pisp-provider' id='wc-pisp-boi' /><label for='wc-pisp-boi'>Bank of Ireland</label>
			</div>
			<div class='row'>
				<input type='radio' value='ptsb' name='wc-pisp-provider' id='wc-pisp-ptsb' /><label for='wc-pisp-ptsb'>Permanent TSB</label>
			</div>
		";
	}

	/**
	 * @inheritDoc
	 */
	public function process_payment( $orderId ) {

		if ( ! $this->apiHelper->isConfigured() ) {
			Logger::debug( 'NoFrixion Server API connection not configured, aborting. Please go to NoFrixion settings and set it up.' );
			throw new \Exception( __( "Can't process order. No merchant token configured, aborting.", 'nofrixion-for-woocommerce' ) );
		}

		// Load the order and check it.
		$order = new \WC_Order( $orderId );
		if ( $order->get_id() === 0 ) {
			$message = 'Could not load order id ' . $orderId . ', aborting.';
			Logger::debug( $message, true );
			throw new \Exception( $message );
		}

		$paymentRequestId = wc_clean( wp_unslash( $_POST['paymentRequestID']));

		Logger::debug('Submitted payment request id ' . $paymentRequestId);

		if (!$paymentRequestId) {
			$msg_no_prid = __('No payment request id found, aborting.', 'nofrixion-for-woocommerce');
			Logger::debug( $msg_no_prid );
			throw new \Exception( $msg_no_prid );
		}

		$pispProvider = sanitize_key($_POST['wc-pisp-provider']);
		Logger::debug('Selected pisp provider: ' . $pispProvider);

		// Check for allowed pisp providers, store provider id to order.
		$allowedPispProviders = array_keys(self::$providers);
		if (!in_array($pispProvider, $allowedPispProviders)) {
			$msg_no_provider = __('No valid pisp provider found, aborting.', 'nofrixion-for-woocommerce');
			Logger::debug( $msg_no_provider);
			throw new \Exception( $msg_no_provider );
		}

		$pispProviderId = self::$providers[$pispProvider];

		$order->add_meta_data('NoFrixion_pispProviderId', $pispProviderId);
		$order->save();

		// Update the payment request with the final order data.
		// We can only update the payment request once, so we need to track it.
		// Todo: add function to check if updated already or keep caching here.
		$paymentRequestUpdated = get_transient($paymentRequestId);
		if ($paymentRequestUpdated !== 'updated') {
			Logger::debug( 'Updating PaymentRequest on NoFrixion.' );
			if ( $paymentRequest = $this->updatePaymentRequest( $paymentRequestId, $order ) ) {
				Logger::debug( 'Updating payment request successful.' );
				Logger::debug( 'PaymentRequest data: ' );
				Logger::debug( $paymentRequest );
				set_transient($paymentRequestId, 'updated', 60*10);
			}
		} else {
			Logger::debug('Skipped updating, payment request already updated once.');
		}

		// Initiate pisp payment.
		try {
			$client = new PaymentRequestClient( $this->apiHelper->url, $this->apiHelper->apiToken );

			$paymentInitiationRequest = $client->submitPaymentInitiationRequest(
				$paymentRequestId,
				$pispProviderId
			);

			Logger::debug('Payment initiation request (PIR): ');
			Logger::debug(print_r($paymentInitiationRequest, true));

			// Check for redirect url.
			if (empty($paymentInitiationRequest['redirectUrl'])) {
				Logger::debug('Got no redirect url for PIR, aborting.');
				return ['result' => 'failure'];
			}

			Logger::debug('PIR received, redirecting user.');

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url(false),
				'orderId' => $order->get_id(),
				'paymentRequestId' => $paymentRequestId,
				'hasSubscription' => false,
				'orderPaidWithToken' => false,
				'orderReceivedPage' => $order->get_checkout_order_received_url(),
				'isPispPayment' => true,
				'pispRedirectUrl' => $paymentInitiationRequest['redirectUrl']
			];

		} catch(\Throwable $e) {
			Logger::debug('Error creating PIR: ' . $e->getMessage());
			return ['result' => 'failure'];
		}
	}

	public function getTitle(): string {
		return $this->get_option('title', 'NoFrixion Pay with your Bank (NO CARD)');
	}

	public function getDescription(): string {
		return $this->get_option('description', '');
	}

}
