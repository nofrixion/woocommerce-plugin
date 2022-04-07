# NoFrixion Plugin for WooCommerce

## WARNING: Plugin work in progress, and only operating in sandbox mode for now


## How WooCommerce Payment plugins work

https://woocommerce.com/document/payment-gateway-api/

As you can see from the types the one we have here is not directly supported, we have some mix of Direct and iFrame based payment flow.


### Todo
- minify JS
- Card form validations?.
- PISP integration
- LN integration

## Development
```
git clone git@github.com:nofrixion/woocommerce-plugin.git
```

### Local development with Docker
```
docker-compose up -d
```
go to [http://localhost:8821]() and install WordPress, WooCommerce and NoFrixion for WooCommerce Plugin
