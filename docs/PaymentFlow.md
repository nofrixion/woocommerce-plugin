# Plugin Architecture #

The `nofrixion-for-woocommerce` payment plugin makes use of Wordpresses support for AJAX. This is configured by:

- Adding AJAX callbacks for several checkout events in the main plugin file (`nofrixion-for-woocommerce.php`).
- Registering and enqueuing the neccessary frontent javascript in the `NofrixionGateway->addScripts` method (`src\Gateway\NofrixionGateway.php`).

## Checkout flow ##

Checkout page (via `asssets\js\nofrixion.js`):
- jQuery entrypoint listens for cart update/change of payment method to `nofrixion-card` or `nofrixion_pisp`.
- `noFrixionSelected()` function called on selection of NoFrixion payment method:
  - creates payment request: AJAX call is made to backend `NofrixionWCPlugin->processAjaxPaymentRequestInit` method. This creates a 'skeleton' payment request (PR# is needed for embedding payelement fields).
  - binds checkout form `checkout_place_order` action to relevant payment method submit function (payframe/pisp)



## TO DO ##

- Delete PR if checkout page unloads and we haven't processed the order -- too hard due to flakiness of onUnload type events, alternative ideas?
  - Not sure if API preventing multiple payment requests for the same order is valid.

- Handle PISP failure for PISP:
  - Is the following querystring returned on error by the API for all providers: `?error=institution_server_error`?
  
  $_REQUEST["error"] === "institution_server_error" <= NOT EMPTY

- Some check whether cards are enabled should be done before loading the headless pay element(?)


General questions
- Payment events and tokens in payment request are arrays - is most recent one element 0?


https://buckethats.test/wp-json/nofrixion/v1/pisp-notify?id=2E03804B-D145-47D6-A644-339BC42DBDB3