<?php

declare( strict_types=1 );

namespace NoFrixion\WC\Helper;

class ApiHelper {
	public const API_URL = [
		'sandbox' => 'https://api-sandbox.nofrixion.com',
		'live'    => 'https://api.nofrixion.com'
	];

	public string $mode;
	public string $url;
	public string $apiToken;

	// todo: perf static instance
	public function __construct() {
		$this->mode     = get_option( 'nofrixion_mode', null );
		$this->url      = $this->mode ? self::API_URL[ $this->mode ] : null;
		$this->apiToken = get_option( 'nofrixion_token', null );
	}

	public function isConfigured(): bool {
		return $this->mode && $this->url && $this->apiToken;
	}

	public function checkApiConnection(): bool {
		// Todo: could check if token and mode
		return true;
	}

}
