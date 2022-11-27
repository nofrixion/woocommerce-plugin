# moneymoov-php

NoFrixion MoneyMoov PHP client library.

## Installation

To use the library in your project, run 
```
composer require nofrixion/moneymoov-php
```

## Usage 

Use of the [create payment request endpoint](https://docs.nofrixion.com/reference/post_api-v1-paymentrequests):

```
try {
    $client = new PaymentRequest( $apiUrl, $apiMerchantToken );
    $result = $client->createPaymentRequest(
        $originUrl,
        $callbackUrl,
        $amount,
        $customerEmailAddress,
        $currency,
        $paymentMethodTypes,
        $orderId,
        $createToken,
        $customerId,
        $cardAuthorizeOnly
    );

    // Process the result.

} catch ( \Throwable $e ) {
    // Catch the exception and log it.
}
```
