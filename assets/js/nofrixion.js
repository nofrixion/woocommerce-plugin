/**
 * Trigger ajax request to create order and payment request.
 */
var createOrderPaymentRequest = function () {
	if (window.nfPayFrame === undefined) {

		console.log('Creating payment request');

		let data = {
			'action': 'nofrixion_payment_request',
			'apiNonce': NoFrixionWP.apiNonce,
			'fields': jQuery('form.checkout').serializeArray()
		};

		jQuery.post(NoFrixionWP.url, data, function (response) {
			if (response.data.paymentRequestId) {
				try {
					var paymentRequestID = response.data.paymentRequestId;
					window.nfPayFrame = new NoFrixionPayFrame(paymentRequestID, 'nf-payframe', 'https://api-sandbox.nofrixion.com');
					window.nfPayFrame.load();
					window.nfWCOrderId = response.data.orderId;
					console.log(response);
				} catch (ex) {
					console.log('Error occurred initializing the payframe: ' + ex);
				}
			}
		}).fail(function () {
			alert('Error processing your request. Please contact support or try again.')
		});
	} else {
		noFrixionUpdateOrder();
	}

	return false;
};

/**
 * Update order address data and other changes.
 */
var noFrixionUpdateOrder = function() {
	// Only trigger when we have a NoFrixion order id.
	if (window.nfWCOrderId !== undefined) {

		console.log('Updating existing orderId ' + window.nfWCOrderId);

		let data = {
			'action': 'nofrixion_order_update',
			'apiNonce': NoFrixionWP.apiNonce,
			'fields': jQuery('form.checkout').serializeArray(),
			'orderId': nfWCOrderId
		};

		jQuery.post(NoFrixionWP.url, data, function (response) {
			console.log('Success updating order.');
			return true;
		}).fail(function () {
			console.log('Error updating order.');
			return false;
		});
	}
};

/**
 * Trigger payframe button submit.
 */
var submitPayFrame = function (e) {
	e.preventDefault();

	console.log('Trigger submitting payframe.');
	jQuery('#nf-cardPayButton').click();
	// Seems to not work.
	// nfpayByCard();

	return false;
};

var noFrixionSelected = function () {
	var checkout_form = jQuery('form.woocommerce-checkout');
	if (jQuery('form[name="checkout"] input[name="payment_method"]:checked').val() === 'nofrixion') {
		createOrderPaymentRequest();
		// Bind our custom event handler to checkout button.
		checkout_form.on('checkout_place_order', submitPayFrame);
	} else {
		// Undo bind custom event handler.
		checkout_form.off('checkout_place_order', submitPayFrame);
	}
}

/**
 * Main entry point.
 */
jQuery(function ($) {
	// Listen on Update cart and change of payment methods.
	$('body').on('updated_checkout', function () {
		noFrixionSelected();
	});

	$('input[name="payment_method"]').change(function () {
		noFrixionSelected();
	});
});
