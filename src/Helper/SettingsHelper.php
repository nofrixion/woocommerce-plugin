<?php

namespace NoFrixion\WC\Helper;

class SettingsHelper {
	public function gatewayFormFields(
		$defaultTitle,
		$defaultDescription
	) {
		$this->form_fields = [
			'title' => [
				'title'       => __('Title', 'nofrixion-for-woocommerce'),
				'type'        => 'text',
				'description' => __('Controls the name of this payment method as displayed to the customer during checkout.', 'nofrixion-for-woocommerce'),
				'default'     => __('NoFrixion (Bitcoin, Lightning Network, ...)', 'nofrixion-for-woocommerce'),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __('Customer Message', 'nofrixion-for-woocommerce'),
				'type'        => 'textarea',
				'description' => __('Message to explain how the customer will be paying for the purchase.', 'nofrixion-for-woocommerce'),
				'default'     => 'You will be redirected to NoFrixion to complete your purchase.',
				'desc_tip'    => true,
			],
		];

		return $this->form_fields;
	}
}
