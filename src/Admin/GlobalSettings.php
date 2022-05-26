<?php

declare( strict_types=1 );

namespace NoFrixion\WC\Admin;

use NoFrixion\Client\ApiKey;
use NoFrixion\Client\StorePaymentMethod;
use NoFrixion\WC\Helper\GreenfieldApiAuthorization;
use NoFrixion\WC\Helper\ApiHelper;
use NoFrixion\WC\Helper\GreenfieldApiWebhook;
use NoFrixion\WC\Helper\Logger;

class GlobalSettings extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = 'nofrixion_settings';
		$this->label = __( 'NoFrixion Settings', 'nofrixion-for-woocommerce' );

		parent::__construct();
	}

	public function output(): void {
		$settings = $this->get_settings_for_default_section();
		\WC_Admin_Settings::output_fields( $settings );
	}

	public function get_settings_for_default_section(): array {
		return $this->getGlobalSettings();
	}

	public function getGlobalSettings(): array {

		Logger::debug( 'Entering Global Settings form.' );

		return [
			'title'      => [
				'title' => esc_html_x(
					'NoFrixion Server Payments Settings',
					'global_settings',
					'nofrixion-for-woocommerce'
				),
				'type'  => 'title',
				'desc'  => sprintf( _x( 'This plugin version is %s and your PHP version is %s. If you need assistance, please contact us at <a href="https://www.nofrixion.com" target="_blank">nofrixion.com</a>. Thank you for using NoFrixion!', 'global_settings', 'nofrixion-for-woocommerce' ), NOFRIXION_VERSION, PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ),
				'id'    => 'nofrixion'
			],
			'mode'       => [
				'title'    => esc_html_x( 'Mode', 'global_settings', 'nofrixion-for-woocommerce' ),
				'type'     => 'select',
				'desc_tip' => _x( 'Choose between live and sandbox mode.', 'global_settings', 'nofrixion-for-woocommerce' ),
				'options'  => [
					'sandbox' => __('Sandbox (for testing)', 'nofrixion-for-woocommerce'),
					'production'    => __('Production', 'nofrixion-for-woocommerce'),
				],
				'default'  => 'sandbox',
				'id'       => 'nofrixion_mode'
			],
			'token'    => [
				'title'   => esc_html_x( 'NoFrixion merchant token', 'global_settings', 'nofrixion-for-woocommerce' ),
				'type'    => 'text',
				'desc'    => _x( 'Your NoFrixion merchant token. Get yours at our merchant portal on <a href="https://www.nofrixion.com" class="nofrixion-link" target="_blank">nofrixion.com</a>', 'global_settings', 'nofrixion-for-woocommerce' ),
				'default' => '',
				'id'      => 'nofrixion_token'
			],
			'debug'      => [
				'title'   => __( 'Debug Log', 'nofrixion-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => sprintf( _x( 'Enable logging <a href="%s" class="button">View Logs</a>', 'global_settings', 'nofrixion-for-woocommerce' ), Logger::getLogFileUrl() ),
				'id'      => 'nofrixion_debug'
			],
			'sectionend' => [
				'type' => 'sectionend',
				'id'   => 'nofrixion',
			],
		];
	}

	/**
	 * On saving the settings form make sure to check if the API key works and register a webhook if needed.
	 */
	public function save() {

		// Flush rewrite rules.
		nofrixion_ensure_endpoints();

		Logger::debug( 'Saving GlobalSettings.' );

		$apiUrl = esc_url_raw( $_POST['nofrixion_url'] );
		$apiKey = sanitize_text_field( $_POST['nofrixion_token'] );

		try {
			// Todo: we could check if the merchant key works.
			// Check if api key works.
		} catch ( \Throwable $e ) {
			$messageException = sprintf(
				__( 'Error fetching data for this token from server. Please check if the merchant token is valid. Error: %s', 'nofrixion-for-woocommerce' ),
				$e->getMessage()
			);
			Notice::addNotice( 'error', $messageException );
			Logger::debug( $messageException, true );
		}

		parent::save();
	}
}
