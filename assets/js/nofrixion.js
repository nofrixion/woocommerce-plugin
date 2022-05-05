/**
 * Trigger ajax request to create only payment request.
 */
var createPaymentRequest = function () {
	// Todo: when implementing PISP we need to make sure to update to new PaymentRequestID in case of switching payment
	//  methods. Or to avoid logic here we could create card,pisp payment request IDs for now.

	if (window.nfWCpaymentRequestID === undefined) {

		console.log('Creating payment request.');

		let data = {
			'action': 'nofrixion_payment_request_init',
			'apiNonce': NoFrixionWP.apiNonce,
			'gateway': jQuery('form[name="checkout"] input[name="payment_method"]:checked').val()
		};

		jQuery.post(NoFrixionWP.url, data, function (response) {
			if (response.data.paymentRequestId) {
				try {
					window.nfWCpaymentRequestID = response.data.paymentRequestId;
					//NoFrixionStorage.setItem('paymentRequestID', response.data.paymentRequestId, 90);
					console.log("payment request ID=" + window.nfWCpaymentRequestID + ".");
					//window.nfPayElement = new NoFrixionPayElementHeadless(window.nfWCpaymentRequestID, 'nf-cardNumber',
					//	'nf-cardSecurityNumber', 'nf-error', 'https://api-sandbox.nofrixion.com');
					window.nfPayElement = new NoFrixionPayElementHeadlessFlex(window.nfWCpaymentRequestID, 'nf-number-container',
						'nf-securityCode-container', 'nf-error', 'https://api-sandbox.nofrixion.com');
					window.nfPayElement.load();
					jQuery('form[name="checkout"]').append('<input type="hidden" name="payment_request_id" value="' + window.nfWCpaymentRequestID + '" />');
					console.log(response);
				} catch (ex) {
					console.log('Error occurred initializing the payframe: ' + ex);
				}
			}
		}).fail(function () {
			alert('Error processing your request. Please contact support or try again.')
		});
	}

	return false;
};

/**
 * Trigger ajax request to create order and process checkout.
 */
var processPaymentRequestOrder = function () {
	// Todo: when implementing PISP we need to make sure to update to new PaymentRequestID in case of switching payment
	//  methods. Or to avoid logic here we could create card,pisp payment request IDs for now.

	let processedOrder = false;

	if (window.nfWCpaymentRequestID) {

		console.log('Creating order and processing checkout.');

		// Prepare form data and additional required data.
		let formData = jQuery('form.checkout').serialize();
		let additionalData = {
			'action': 'nofrixion_payment_request',
			'apiNonce': NoFrixionWP.apiNonce,
			'paymentRequestID': window.nfWCpaymentRequestID,
		};

		let data = jQuery.param(additionalData) + '&' + formData;

		// We need to make sure the order processing worked before returning from this function.
		jQuery.ajaxSetup({async: false});

		jQuery.post(NoFrixionWP.url, data, function (response) {
			console.log('Received response when processing PaymentRequestOrder: ');
			console.log(response);
			if (response.paymentRequestId) {
				processedOrder = true;
			}
		}).fail(function () {
			alert('Error processing your request. Please contact support or try again.')
		});

		// Reenable async.
		jQuery.ajaxSetup({async: true});
	}

	return processedOrder;
};

/**
 * Trigger payframe button submit.
 */
var submitPayFrame = function (e) {
	e.preventDefault();
	console.log('Triggered submitpayframe');
	if (processPaymentRequestOrder()) {
		// Remove the local storage item for the next order.
		console.log('Trigger submitting nofrixion form.');
		nfpayByCard();
	}

	return false;
};

/**
 * Makes sure to trigger on payment method changes and overriding the default button submit handler.
 */
var noFrixionSelected = function () {
	var checkout_form = jQuery('form.woocommerce-checkout');
	if (jQuery('form[name="checkout"] input[name="payment_method"]:checked').val() === 'nofrixion_card') {
		createPaymentRequest();
		// Bind our custom event handler to checkout button.
		checkout_form.on('checkout_place_order', submitPayFrame);
	} else {
		// Undo bind custom event handler.
		checkout_form.off('checkout_place_order', submitPayFrame);
	}
}

/**
 * Validate form fields.
 */
var noFrixionValidateFields = function () {

	console.log('Validating form fields.');

	let hasErrors = false;

	// Prepare div container structure.
	let $checkoutForm = jQuery('form.checkout');

	let alert = `<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">
					<ul class="woocommerce-error" role="alert">`;

	jQuery.each($checkoutForm.serializeArray(), function(item, field) {
		let $fieldRow = jQuery('#' + field.name + '_field');
		if ($fieldRow.hasClass('validate-required')) {

			if (field.value === '') {
				console.log(field.name + ' is required');

				hasErrors = true;
				alert += `<li><strong>${$fieldRow.find('label').text()}</strong> ${NoFrixionWP.isRequiredField}</li>`;
			}
		}
	});

	// Close ul and div.
	alert += '</ul></div>';

	// Add or remove errors from page.
	if (hasErrors) {
		$checkoutForm.prepend(alert);
	} else {
		jQuery('.woocommerce-NoticeGroup').remove();
	}

	return !hasErrors;
};

/**
 * Stores data in localStorage with expiry times.
 */
var NoFrixionStorage = {
	getItem: function (key) {
		const itemStr = localStorage.getItem(key)

		if (!itemStr) {
			return null
		}

		const item = JSON.parse(itemStr)
		const now = new Date()

		// Check if item expired.
		if (now.getTime() > item.expiry) {
			localStorage.removeItem(key)
			return null
		}
		return item.value
	},
	setItem: function (key, value, expirySeconds) {
		const now = new Date()

		// Set the item with expiry.
		const item = {
			value: value,
			expiry: now.getTime() + (expirySeconds * 1000),
		}
		localStorage.setItem(key, JSON.stringify(item))
	},
	removeItem: function (key) {
		localStorage.removeItem(key);
	}
}

/**
 * Main entry point.
 */
jQuery(function ($) {
	// Listen on Update cart and change of payment methods.
	$('body').on('updated_checkout payment_method_selected', function (event) {
		console.log('Fired event: ' + event.type);
		noFrixionSelected();
	});
});
