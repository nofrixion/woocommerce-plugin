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
				<div id="nf-error" role="alert" class="alert-danger alert-dismissible nf-error-div nf-border-radius" style="display: none;"></div>
		        <div class="form-row form-row-wide"><label>Card Number <span class="required">*</span></label>
				<div id="nf-number-container" style="height:38px" />
				</div>
				<div style="display: row;">
					<div style="display: table-cell;">
						<input name="expiryMonth" type="text" autocomplete="off" placeholder="MM">
					</div>
					<div style="display: table-cell;">
						<input name="expiryYear" type="text" autocomplete="off" placeholder="YYYY">
					</div>
					<div style="display: table-cell;">
						<div id="nf-securityCode-container" style="height:38px" />
					</div>
				</div>
		</form>';
	}

	public function getTitle(): string {
		return $this->get_option('title', 'NoFrixion Card');
	}

	public function getDescription(): string {
		return $this->get_option('description', '');
	}

}
