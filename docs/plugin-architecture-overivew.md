# Plugin Architecture #

The `nofrixion-for-woocommerce` payment plugin makes use of Wordpresses support for AJAX. This is configured by:

- Adding AJAX callbacks for several checkout events in the main plugin file (`nofrixion-for-woocommerce.php`).
- Registering and enqueuing the neccessary frontent javascript in the `NofrixionGateway->addScripts` method (`src\Gateway\NofrixionGateway.php`).

## Checkout flow ##

Checkout page (via `asssets\js\nofrixion.js`):
- jQuery entrypoint listens for cart update/change of payment method to `nofrixion-card` or `nofrixion_pisp`.
- `noFrixionSelected()` function called on selection of NoFrixion payment method:
  - creates payment request: AJAX call is made to backend `NofrixionWCPlugin->processAjaxPaymentRequestInit` method. This creates a 'skeleton' payment request (PR# is needed for embedding payelement fields).
  - binds checkout form `checkout_place_order` action to relevant payment method submit function (payframe/pisp).
  - the `process_payment()` method of the payment gateway processes the payment. Generally speaking:
    - updates the payment request 'stub' with the order details.

### PISP ###

- the customer is redirected to the PISP provider URL and completes the transaction.
- the payment initiation callback is handled by the `NofrixionWCPlugin->orderStatusThankYouPage()` method in `nofrixion-for-woocommerce.php`

## TO DO ##

- `NofrixionGateway -> updateOrderMetadata()`: logic for setting `Nofrixion_isSubscription` metadata is incorrect, create token flag will be set if saving payment method.
- When authorising a card (for adding a saved payment method) we create a payment request to get the id to add to the callback_url and then update it to set the callback URL. See if there is something more efficient we can do here.