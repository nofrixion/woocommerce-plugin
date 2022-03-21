<?php

declare(strict_types=1);

namespace NoFrixion\WC\Helper;

use NoFrixion\Client\Store;
use NoFrixion\Client\StorePaymentMethod;
use NoFrixion\Client\Webhook;
use NoFrixion\Result\AbstractStorePaymentMethodResult;

class GreenfieldApiHelper {
	const PM_CACHE_KEY = 'btcpay_payment_methods';
	const PM_CLASS_NAME_PREFIX = 'NoFrixion_GF_';
	public $configured = false;
	public $url;
	public $apiKey;
	public $storeId;

	// todo: perf static instance
	public function __construct() {
		if ($config = self::getConfig()) {
			$this->url = $config['url'];
			$this->apiKey = $config['api_key'];
			$this->storeId = $config['store_id'];
			$this->webhook = $config['webhook'];
			$this->configured = true;
		}
	}

	// todo: maybe remove static class and make GFConfig object or similar
	public static function getConfig(): array {
		// todo: perf: maybe add caching
		$url = get_option('nofrixion_url');
		$key = get_option('nofrixion_api_key');
		if ($url && $key) {
			return [
				'url' => $url,
				'api_key' => $key,
				'store_id' => get_option('nofrixion_store_id', null),
				'webhook' => get_option('nofrixion_webhook', null)
			];
		}
		else {
			return [];
		}
	}

	public static function checkApiConnection(): bool {
		if ($config = self::getConfig()) {
			// todo: replace with server info endpoint.
			$client = new Store($config['url'], $config['api_key']);
			if (!empty($stores = $client->getStores())) {
				return true;
			}
		}
		return false;
	}

	/**
	 * List supported payment methods by NoFrixion Server.
	 */
	public static function supportedPaymentMethods(): array {
		$paymentMethods = [];

		// Use transients API to cache pm for a few minutes to avoid too many requests to NoFrixion Server.
		if ($cachedPaymentMethods = get_transient(self::PM_CACHE_KEY)) {
			return $cachedPaymentMethods;
		}

		if ($config = self::getConfig()) {
			$client = new StorePaymentMethod($config['url'], $config['api_key']);
			if ($storeId = get_option('nofrixion_store_id')) {
				try {
					$pmResult = $client->getPaymentMethods($storeId);
					/** @var AbstractStorePaymentMethodResult $pm */
					foreach ($pmResult as $pm) {
						if ($pm->isEnabled() && $pmName = $pm->getData()['paymentMethod'] )  {
							// Convert - to _ and escape value for later use in gateway class generator.
							$symbol = sanitize_html_class(str_replace('-', '_', $pmName));
							$paymentMethods[] = [
								'symbol' => $symbol,
								'className' => self::PM_CLASS_NAME_PREFIX . $symbol
							];
						}
					}
				} catch (\Throwable $e) {
					Logger::debug('Exception loading payment methods: ' . $e->getMessage(), true);
				}
			}
		}

		// Store payment methods into cache.
		set_transient( self::PM_CACHE_KEY, $paymentMethods,5 * MINUTE_IN_SECONDS );

		return $paymentMethods;
	}

	/**
	 * Deletes local cache of supported payment methods.
	 */
	public static function clearSupportedPaymentMethodsCache(): bool {
		return delete_transient( self::PM_CACHE_KEY );
	}

	/**
	 * Returns NoFrixion Server invoice url.
	 */
	public function getInvoiceRedirectUrl($invoiceId): ?string {
		if ($this->configured) {
			return $this->url . '/i/' . urlencode($invoiceId);
		}
		return null;
	}

	/**
	 * Check webhook signature to be a valid request.
	 */
	public function validWebhookRequest(string $signature, string $requestData): bool {
		if ($this->configured) {
			return Webhook::isIncomingWebhookRequestValid($requestData, $signature, $this->webhook['secret']);
		}
		return false;
	}

	/**
	 * Checks if the provided API config already exists in options table.
	 */
	public static function apiCredentialsExist(string $apiUrl, string $apiKey, string $storeId): bool {
		if ($config = self::getConfig()) {
			if (
				$config['url'] === $apiUrl &&
				$config['api_key'] === $apiKey &&
				$config['store_id'] === $storeId
			) {
				return true;
			}
		}

		return false;
	}

}
