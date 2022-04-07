<?php

declare( strict_types=1 );

namespace NoFrixion\WC\Gateway;

use NoFrixion\WC\Helper\ApiHelper;

class NoFrixionPisp extends NoFrixionGateway {

	public ApiHelper $apiHelper;

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
		echo "todo: PISP integration";
	}

	public function getTitle(): string {
		return $this->get_option('title', 'NoFrixion Pay with your Bank (NO CARD)');
	}

	public function getDescription(): string {
		return $this->get_option('description', '');
	}

}
