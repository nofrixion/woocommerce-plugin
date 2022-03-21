# NoFrixion Plugin for WooCommerce

## WARNING: Plugin work in progress, and only operating in sandbox mode for now


## How WooCommerce Payment plugins work

https://woocommerce.com/document/payment-gateway-api/

As you can see from the types the one we have here is not directly supported, we have some mix of Direct and iFrame based payment flow.


### What is already there
- Complete structure incl deployment scripts (currently deactivated)
- Curl Client class and API class
- Payment Gateway class `Gateway\NoFrixionGateway`
- Creation of a PaymentRequest works class `Client\PaymentRequest`

### Todo
- still cleanups (Global config form etc. from BTCPay)
- Make Payform work / JS via ajax work (load NoFrixion JS, figure out flow)
- Build out PaymentRequest endpoints (get)
- Make webhooks work, how the payload looks etc.
- ...

## Development
```
git clone git@github.com:btcpayserver/woocommerce-greenfield-plugin.git
```

### Local development with Docker
```
docker-compose up -d
```
go to [http://localhost:8821]() and install WordPress, WooCommerce and NoFrixion Greenfield for WooCommerce Plugin
