<?php

declare(strict_types=1);

namespace NoFrixion\WC\Client;

use NoFrixion\WC\Helper\Logger;
use NoFrixion\WC\Helper\PreciseNumber;

class PaymentRequest extends AbstractClient
{
	/**
	 * @seehttps://docs.nofrixion.com/reference/post_api-v1-paymentrequests
	 */
    public function createPaymentRequest(
		string $originUrl,
		string $callbackUrl,
        PreciseNumber $amount,
		string $customerEmailAddress,
	    ?string $currency = null,
	    ?array $paymentMethodTypes = null,
        ?string $orderId = null,
	    ?bool $createToken = false,
	    ?string $customerId = null,
	    ?bool $cardAuthorizeOnly = false
    ): array {
        $url = $this->getApiUrl() . 'paymentrequests';
        $headers = $this->getRequestHeaders();
        $method = 'POST';

        $body = http_build_query([
	        'Amount' => $amount->__toString(),
	        'Currency' => $currency,
	        'OriginUrl' => $originUrl,
	        'CallbackUrl' => $callbackUrl,
	        'PaymentMethodTypes' => implode(',', $paymentMethodTypes),
	        'OrderID' => $orderId,
	        'CardCreateToken' => $createToken && $customerEmailAddress != "" ? 'true' : 'false',
	        'CustomerID' => $customerId ?? '',
	        'CardAuthorizeOnly' => $cardAuthorizeOnly ? 'true' : 'false',
			'CustomerEmailAddress' => $customerEmailAddress
        ]);

        Logger::debug('createPaymentRequest() data sent: ' . $body);
        $response = $this->getHttpClient()->request($method, $url, $headers, $body);

        if (in_array($response->getStatus() ,[200, 201])) {
            return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

	/**
	 * @see https://docs.nofrixion.com/reference/put_api-v1-paymentrequests-id
	 */
	public function updatePaymentRequest(
		string $paymentRequestId,
		string $originUrl,
		string $callbackUrl,
		PreciseNumber $amount,
		?string $currency = null,
		?array $paymentMethodTypes = null,
		?string $orderId = null,
		?bool $createToken = false,
		?string $customerId = null,
		?bool $cardAuthorizeOnly = false,
		?string $customerEmailAddress = null
	): array {
		$url = $this->getApiUrl() . 'paymentrequests/' . urlencode($paymentRequestId);
		$headers = $this->getRequestHeaders();
		$method = 'PUT';

		$body = http_build_query([
			'Amount' => $amount->__toString(),
			'Currency' => $currency,
			'OriginUrl' => $originUrl,
			'CallbackUrl' => $callbackUrl,
			'PaymentMethodTypes' => implode(',', $paymentMethodTypes),
			//'OrderID' => $orderId,
			'CardCreateToken' => $createToken && $customerEmailAddress !== "" && $customerEmailAddress !== null ? 'true' : 'false',
			'CustomerID' => $customerId ?? '',
			'CardAuthorizeOnly' => $cardAuthorizeOnly ? 'true' : 'false',
			'CustomerEmailAddress' => $customerEmailAddress
		]);

		Logger::debug('Data sent on updatePaymentRequest: ' . $body);
		$response = $this->getHttpClient()->request($method, $url, $headers, $body);

		if (in_array($response->getStatus() ,[200, 201])) {
			return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} else {
			throw $this->getExceptionByStatusCode($method, $url, $response);
		}
	}

	/**
	 * @see https://docs.nofrixion.com/reference/get_api-v1-paymentrequests-id
	 */
	public function getPaymentRequest(
		string $paymentRequestId
	): array {
		$url = $this->getApiUrl() . 'paymentrequests/' . urlencode($paymentRequestId);
		$headers = $this->getRequestHeaders();
		$method = 'GET';
		$response = $this->getHttpClient()->request($method, $url, $headers);

		if ($response->getStatus() === 200) {
			return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} else {
			throw $this->getExceptionByStatusCode($method, $url, $response);
		}
	}


	/**
	 * @see https://docs.nofrixion.com/reference/get_api-v1-paymentrequests-id-result
	 */
    public function getPaymentRequestResult(
        string $paymentRequestId
    ): array {
        $url = $this->getApiUrl() . 'paymentrequests/' . urlencode($paymentRequestId) . '/result';
        $headers = $this->getRequestHeaders();
        $method = 'GET';
        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

	/**
	 * @see https://docs.nofrixion.com/reference/post_api-v1-paymentrequests-id-card-paywithtoken
	 */
	public function payWithCardToken(
		string $paymentRequestId,
		string $tokenisedCardId
	): array {
		$url = $this->getApiUrl() . 'paymentrequests/' . urlencode($paymentRequestId) . '/card/paywithtoken';
		$headers = $this->getRequestHeaders();
		$method = 'POST';

		$body = http_build_query([
			'TokenisedCardID' => $tokenisedCardId
		]);

		Logger::debug('payWithCardToken() data sent: ' . $body);
		$response = $this->getHttpClient()->request($method, $url, $headers, $body);

		if (in_array($response->getStatus() ,[200, 201])) {
			return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} else {
			throw $this->getExceptionByStatusCode($method, $url, $response);
		}
	}

	/**
	 * @see https://docs.nofrixion.com/reference/get_api-v1-paymentrequests-card-customertokens-customerid
	 */
	public function getCustomerTokenisedCards(string $customerId): array {
		$url = $this->getApiUrl() . 'paymentrequests/card/customertokens/' . urlencode($customerId);
		$headers = $this->getRequestHeaders();
		$method = 'GET';
		$response = $this->getHttpClient()->request($method, $url, $headers);

		if ($response->getStatus() === 200) {
			return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} else {
			throw $this->getExceptionByStatusCode($method, $url, $response);
		}
	}
}
