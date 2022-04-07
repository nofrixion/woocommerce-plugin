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
	}

	public function payment_fields() {
		echo '<form id="nf-cardPaymentForm" onsubmit="event.preventDefault();">
		        <div class="form-row form-row-wide"><label>Card Number <span class="required">*</span></label>
				<input name="cardNumber" type="text" autocomplete="off">
				</div>
				<div class="form-row form-row-first">
					<label>Expiry Date <span class="required">*</span></label>
					<input name="expiry" type="text" autocomplete="off" placeholder="MM / YYYY">
				</div>
				<div class="form-row form-row-last">
					<label>Card Code (CVC) <span class="required">*</span></label>
					<input name="cardVerificationNumber" type="password" autocomplete="off" placeholder="CVC">
				</div>
				<div class="clear"></div>
		</form>';
	}

	public function getTitle(): string {
		return $this->get_option('title', 'NoFrixion Card (Mastercard, VISA)');
	}

	public function getDescription(): string {
		return $this->get_option('description', '');
	}

}
