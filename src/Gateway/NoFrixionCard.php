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
		echo '
			<div id="nf-error" role="alert" class="alert-danger alert-dismissible nf-error-div nf-border-radius" style="display: none;"></div>
			<form id="nf-cardPaymentForm" onsubmit="event.preventDefault();">
				<table style="padding: 0;">
					<tr style="padding: 0;">
						<td colspan="2" style="padding: 0;">Card Number <span class="required">*</span></td>
					</tr>
					<tr style="padding: 0;">
						<td colspan="2" style="background-color: #f2f2f2; padding:0 0 0 0.62em;">
							<div id="nf-number-container" style="height:45px;"></div>
						</td>
					</tr>
					<tr style="padding: 0;">
						<td style="padding: 0;">Expiry <span class="required">*</span></td>
						<td style="padding: 0;">CVN <span class="required">*</span></td>
					</tr>
					<tr style="padding: 0;">
						<td style="padding: 0;">
							<input type="text" id="nf-expiryMonth" name="expiryMonth" placeholder="MM" size="2" maxlength="2" inputmode="numeric" />
							<span class="input-group-text">/</span>
							<input type="text" id="nf-expiryYear" name="expiryYear" placeholder="YYYY" size="4" maxlength="4" inputmode="numeric" />
						</td>
						<td style="padding: 0; background-color: #f2f2f2; padding-left: 0.62em;">
							<div id="nf-securityCode-container" class="form-control nf-border-radius" style="height:45px; width: 4em;"></div>
						</td>
					</tr>
				</table>
		</form>';
	}

	public function getTitle(): string {
		return $this->get_option('title', 'NoFrixion Card');
	}

	public function getDescription(): string {
		return $this->get_option('description', '');
	}

}
