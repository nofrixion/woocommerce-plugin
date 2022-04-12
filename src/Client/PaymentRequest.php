<?php

declare(strict_types=1);

namespace NoFrixion\WC\Client;

use NoFrixion\WC\Helper\PreciseNumber;

class PaymentRequest extends AbstractClient
{

    public function createPaymentRequest(
		string $originUrl,
		string $callbackUrl,
        PreciseNumber $amount,
	    ?string $currency = null,
	    ?array $paymentMethodTypes = null,
        ?string $orderId = null
    ): array {
        $url = $this->getApiUrl() . 'paymentrequests';
        $headers = $this->getRequestHeaders();
        $method = 'POST';

        $body = http_build_query([
                'Amount' => $amount->__toString(),
                'Currency' => $currency,
                'OriginUrl' => $originUrl,
                'CallbackUrl' => $callbackUrl,
                //'CardTransmitRawDetails' => 'true',
                'PaymentMethodTypes' => implode(',', $paymentMethodTypes),
                'OrderID' => $orderId
            ]);

        $response = $this->getHttpClient()->request($method, $url, $headers, $body);

        if (in_array($response->getStatus() ,[200, 201])) {
            return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    public function getPaymentRequestStatus(
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
}
