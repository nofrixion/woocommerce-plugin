/**
 * Trigger ajax request to create order and payment request.
 */
var createOrderPaymentRequest = function () {
	// Only create order if nfPayFrame is not initialized yet.
	// Todo: when implementing PISP we need to make sure to update to new PaymentRequestID in case of switching payment
	//  methods. Or to avoid logic here we could create card,pisp payment request IDs for now.

	if (window.nfWCpaymentRequestID === undefined) {

		console.log('Creating payment request');

		let data = {
			'action': 'nofrixion_payment_request',
			'apiNonce': NoFrixionWP.apiNonce,
			'fields': jQuery('form.checkout').serializeArray(),
			'gateway': jQuery('form[name="checkout"] input[name="payment_method"]:checked').val()
		};

		jQuery.post(NoFrixionWP.url, data, function (response) {
			if (response.data.paymentRequestId) {
				try {
					window.nfWCpaymentRequestID = response.data.paymentRequestId;
					//window.nfPayFrame = new NoFrixionPayFrame(nfWCpaymentRequestID, 'nf-payframe', 'https://api-sandbox.nofrixion.com');
					//window.nfPayFrame.load();
					window.nfWCOrderId = response.data.orderId;
					window.nfWCOrderRedirect = response.data.orderRedirect;

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
 * Update order address data and other changes.
 */
var noFrixionUpdateOrder = function() {

	let updated = false;

	// Only trigger when we have a NoFrixion order id and all required fields have values.
	if (window.nfWCOrderId !== undefined && noFrixionValidateFields()) {

		console.log('Updating existing orderId ' + window.nfWCOrderId);

		let data = {
			'action': 'nofrixion_order_update',
			'apiNonce': NoFrixionWP.apiNonce,
			'fields': jQuery('form.checkout').serializeArray(),
			'orderId': nfWCOrderId
		};

		// We need to make sure the update worked before returning from this function, can do same a bit cleaner with .ajax()
		jQuery.ajaxSetup({async: false});

		jQuery.post(NoFrixionWP.url, data, function (response) {
			console.log('Success updating order.');
			updated = true;
		}).fail(function () {
			console.log('Error updating order.');
		});

		// Reenable async.
		jQuery.ajaxSetup({async: true});

		return updated;
	}

	return updated;
};

/**
 * Trigger payframe button submit.
 */
var submitPayFrame = function (e) {
	e.preventDefault();
	console.log('Triggered submitpayframe');
	if (noFrixionUpdateOrder()) {
		console.log('Trigger submitting nofrixion form.');

		let cardPaymentForm = document.getElementById('nf-cardPaymentForm');
		const formData = new FormData(cardPaymentForm);
		formData.append('expiryMonth', formData.get('expiry').split('/')[0]);
		formData.append('expiryYear', formData.get('expiry').split('/')[1]);

		fetch("https://api-sandbox.nofrixion.com/api/v1/paymentrequests/" + nfWCpaymentRequestID + "/cardsensitive", {
			method: 'POST',
			body: formData
		}).then(res => {
			if (res.status >= 200 && res.status < 300) {
				return res.json()
			} else {
				throw new Error();
			}
		}).then(data => window.top.location.href = nfWCOrderRedirect).catch(e => console.error(e.message));
	}

	return false;
};

var noFrixionSelected = function () {
	var checkout_form = jQuery('form.woocommerce-checkout');
	if (jQuery('form[name="checkout"] input[name="payment_method"]:checked').val() === 'nofrixion_card') {
		createOrderPaymentRequest();
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
 * Main entry point.
 */
jQuery(function ($) {
	// Listen on Update cart and change of payment methods.
	$('body').on('updated_checkout payment_method_selected', function (event) {
		console.log('Fired event: ' + event.type);
		noFrixionSelected();
	});
});
