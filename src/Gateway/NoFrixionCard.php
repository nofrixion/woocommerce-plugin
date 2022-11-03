<?php

declare( strict_types=1 );

namespace NoFrixion\WC\Gateway;

use NoFrixion\WC\Helper\ApiHelper;

class NoFrixionCard extends NoFrixionGateway {

	public ApiHelper $apiHelper;

	public function __construct() {
		// General gateway setup.
		$this->id = 'nofrixion_card';

		// Call parent constructor.
		parent::__construct();

		$this->has_fields = true;
		// Define user facing set variables.
		$this->title        = $this->getTitle();
		$this->description  = $this->getDescription();

		// Admin facing title and description.
		$this->method_title       = 'NoFrixion Card';
		$this->method_description = __('NoFrixion gateway supporting all available credit card payments.', 'nofrixion-for-woocommerce');

		// Enable specific features.
		$this->supports = [
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
			'tokenization'
		];
	}

	public function payment_fields() {
		

		echo '
			<div id="nf-error" role="alert" class="alert-danger alert-dismissible nf-error-div nf-border-radius" style="display: none;"></div>

			<form id="nf-cardPaymentForm" onsubmit="event.preventDefault();">

						<div class="form-row form-row-wide">

							<label for="nf-cardNumber">Card Number <span class="required">*</span></label>

								<input style="border: 1px solid lightgray !important;
								height: 33px !important;
								padding: 8px !important;
								margin: 5px 0 5px 0 !important;
								width: 100% !important;
								background: white !important;
								font-size: 16px !important;
								box-shadow: none;" type="text" id="nf-cardNumber" name="nf-cardNumber" placeholder="0000 0000 0000 0000" size="24" maxlength="24" inputmode="numeric" />
						</div>

			<div style="margin: 10px 0 80px 0;">

				<div class="form-row form-row-first" style="vertical-align: top;">

					<label>Expiry Date <span class="required">*</span></label>

					<div style="width: 100% !important;">

					<input style="border: 1px solid lightgray !important;
					height: 33px !important;
					padding: 8px !important;
					margin: 5px 0 5px 0 !important;
					width: 40% !important;
					background: white !important;
					font-size: 16px !important;
					box-shadow: none;" type="text" id="nf-expiryMonth" placeholder="MM" size="2" maxlength="2" inputmode="numeric" />

					<span style="width: 10% !important;" class="input-group-text">/</span>

					<input style="border: 1px solid lightgray !important;
					height: 33px !important;
					padding: 8px !important;
					margin: 5px 0 5px 0 !important;
					width: 50% !important;
					background: white !important;
					font-size: 16px !important;
					box-shadow: none;" type="text" id="nf-expiryYear" placeholder="YYYY" size="4" maxlength="4" inputmode="numeric" />

					</div>

				</div>

				<div class="form-row form-row-last">
					<label for="nf-cardSecurityCode">Card Code (CVC) <span class="required">*</span></label>

					<div id="nf-securityCode-container" class="form-control nf-border-radius">
						<input style="border: 1px solid lightgray !important;
						height: 33px !important;
						padding: 8px !important;
						margin: 5px 0 5px 0 !important;
						width: 100% !important;
						background: white !important;
						font-size: 16px !important;
						box-shadow: none;" type="text" id="nf-cardSecurityCode" name="nf-cardSecurityCode" placeholder="CVC" size="5" maxlength="5" inputmode="numeric" />
					</div>

				</div>
				</div>	
			</form>

			<div style="height: 10px;"></div>';

		// Show save to account and saved payment methods.
		if (is_user_logged_in() && !is_add_payment_method_page() && !$this->isChangingPaymentMethodForSubscription()) {
			$this->save_payment_method_checkbox();
			$this->saved_payment_methods();
		}
	}

	public function add_payment_method() {

	}

	public function getTitle(): string {
		return $this->get_option('title', 'NoFrixion Card');
	}

	public function getDescription(): string {
		return $this->get_option('description', '');
	}

}
