/**
 * Trigger ajax request to create only payment request.
 */
var createPaymentRequest = function (gateway) {
	// Todo: when implementing PISP we need to make sure to update to new PaymentRequestID in case of switching payment
	//  methods. Or to avoid logic here we could create card,pisp payment request IDs for now.

	if (window.nfWCpaymentRequestID === undefined || window.nfWCType !== gateway) {

		console.log('Creating payment request.');

		let data = {
			'action': 'nofrixion_payment_request_init',
			'apiNonce': NoFrixionWP.apiNonce,
			'gateway': gateway
		};

		jQuery.post(NoFrixionWP.url, data, function (response) {
			if (response.data.paymentRequestId) {
				try {
					window.nfWCpaymentRequestID = response.data.paymentRequestId;
					window.nfWCType = gateway;
					//NoFrixionStorage.setItem('paymentRequestID', response.data.paymentRequestId, 90);
					console.log("payment request ID=" + window.nfWCpaymentRequestID + ".");
					window.nfPayElement = new NoFrixionPayElementHeadless(window.nfWCpaymentRequestID, 'nf-number-container',
						'nf-securityCode-container', 'nf-error', NoFrixionWP.apiUrl);
					window.nfPayElement.load();
					jQuery('form[name="checkout"]').append('<input type="hidden" name="payment_request_id" value="' + window.nfWCpaymentRequestID + '" />');
					console.log(response);
				} catch (ex) {
					console.log('Error occurred initializing the payframe: ' + ex);
				}
			} else {
				// Show errors.
				if ( response.messages ) {
					submitError( response.messages );
				} else {
					submitError( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' );
				}
			}
		}).fail(function () {
			submitError( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' );
		});
	}

	return false;
};

/**
 * Trigger ajax request to create only payment request for changing payment method.
 */
var createPaymentRequestChangePM = function () {

	if (window.nfWCpaymentRequestID === undefined) {

		console.log('Creating payment request (pm change).');

		let data = {
			'action': 'nofrixion_payment_request_update_pm',
			'apiNonce': NoFrixionWP.apiNonce,
			'gateway': jQuery('input[name="payment_method"]:checked').val(),
			'orderId': jQuery('input[name="woocommerce_change_payment"]').val()
		};

		jQuery.post(NoFrixionWP.url, data, function (response) {
			if (response.data.paymentRequestId) {
				try {
					window.nfWCpaymentRequestID = response.data.paymentRequestId;
					console.log("payment request ID=" + window.nfWCpaymentRequestID + ".");
					window.nfPayElement = new NoFrixionPayElementHeadless(window.nfWCpaymentRequestID, 'nf-number-container',
						'nf-securityCode-container', 'nf-error', NoFrixionWP.apiUrl);
					window.nfPayElement.load();
					jQuery('form#order_review').append('<input type="hidden" name="paymentRequestID" value="' + window.nfWCpaymentRequestID + '" />');
					console.log(response);
				} catch (ex) {
					console.log('Error occurred initializing the payframe: ' + ex);
				}
			} else {
				// Show errors.
				if ( response.messages ) {
					submitError( response.messages );
				} else {
					submitError( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' ); // eslint-disable-line max-len
				}
			}
		}).fail(function () {
			submitError( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' );
		});
	}

	return false;
};

/**
 * Trigger ajax request to create only payment request for authorize card.
 */
var createPaymentRequestAuthorizeCard = function () {

	if (window.nfWCpaymentRequestID === undefined) {

		console.log('Creating payment request (authorize card).');

		let data = {
			'action': 'nofrixion_payment_request_authorize_card',
			'apiNonce': NoFrixionWP.apiNonce,
			'gateway': jQuery('input[name="payment_method"]:checked').val()
		};

		jQuery.post(NoFrixionWP.url, data, function (response) {
			if (response.data.paymentRequestId) {
				try {
					window.nfWCpaymentRequestID = response.data.paymentRequestId;
					console.log("payment request ID=" + window.nfWCpaymentRequestID + ".");
					window.nfPayElement = new NoFrixionPayElementHeadless(
						window.nfWCpaymentRequestID,
						'nf-number-container',
						'nf-securityCode-container',
						'nf-error',
						NoFrixionWP.apiUrl
					);
					window.nfPayElement.load();
					console.log(response);
				} catch (ex) {
					console.log('Error occurred initializing the payframe: ' + ex);
				}
			} else {
				// Show errors.
				if ( response.messages ) {
					submitError( response.messages );
				} else {
					submitError( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' ); // eslint-disable-line max-len
				}
			}
		}).fail(function () {
			submitError( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' );
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

		// Block the UI.
		blockElement('.woocommerce-checkout-payment');

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

		jQuery.post(wc_checkout_params.checkout_url, data, function (response) {
			console.log('Received response when processing PaymentRequestOrder: ');
			console.log(response);

			// Payment done by token, redirect directly to order received page.
			if (response.orderPaidWithToken === true) {
				window.location = response.orderReceivedPage;
			}

			// On pisp payments, redirect to provider.
			if (response.isPispPayment === true) {
				window.location = response.pispRedirectUrl;
			}

			if (response.paymentRequestId) {
				processedOrder = true;
			} else {
				unblockElement('.woocommerce-checkout-payment');
				// Show errors.
				if ( response.messages ) {
					submitError( response.messages );
				} else {
					submitError( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' ); // eslint-disable-line max-len
				}
			}
		}).fail(function () {
			unblockElement('.woocommerce-checkout-payment');
			submitError( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' );
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
		console.log('Trigger submitting nofrixion form to api.');
		blockElement('.woocommerce-checkout-payment');
		nfpayByCard();
	}

	return false;
};

/**
 * Trigger payframe button submit.
 */
var submitPisp = function (e) {
	e.preventDefault();
	console.log('Triggered submitPisp()');
	// Make sure at least one pisp provider selected.
	let $checkoutForm = jQuery('form.checkout');

	if (jQuery('input:radio[name="wc-pisp-provider"]:checked').length > 0) {
		blockElement('.woocommerce-checkout-payment');
		processPaymentRequestOrder()
	} else {
		submitError( '<div class="woocommerce-error">' + NoFrixionWP.pispNoProviderSelected + '</div>' );
	}

	return false;
};

/**
 * Block UI of a given element.
 */
var blockElement = function (cssClass) {
	console.log('Triggered blockElement.');

	jQuery( cssClass ).block({
		message: null,
		overlayCSS: {
			background: '#fff',
			opacity: 0.6
		}
	});
};

/**
 * Unblock UI of a given element.
 */
var unblockElement = function (cssClass) {
	console.log('Triggered unblockElement.');
	jQuery( cssClass ).unblock();
};

/**
 * Trigger payframe (change payment method) button submit.
 */
var submitPayFrameChangePM = function (e) {
	e.preventDefault();
	console.log('Triggered submitpayframe (change pm)');
	nfpayByCard();
	return false;
};

/**
 * Trigger payframe (authorize card) button submit.
 */
var submitPayFrameAuthorizeCard = function (e) {
	e.preventDefault();
	console.log('Triggered submit payframe (authorize card)');
	nfpayByCard();
	return false;
};

/**
 * Makes sure to trigger on payment method changes and overriding the default button submit handler.
 */
var noFrixionSelected = function () {
	var checkout_form = jQuery('form.woocommerce-checkout');
	var selected_gateway = jQuery('form[name="checkout"] input[name="payment_method"]:checked').val();
	var supported_methods = ['nofrixion_card', 'nofrixion_pisp'];
	unblockElement('.woocommerce-checkout-payment');

	if (supported_methods.includes(selected_gateway)) {
		createPaymentRequest(selected_gateway);
		// Bind our custom event handler to checkout button.
		if (selected_gateway === 'nofrixion_card') {
			checkout_form.off('checkout_place_order', submitPisp);
			checkout_form.on('checkout_place_order', submitPayFrame);
			// Unblock UI on error.
			jQuery('#nf-error').on('DOMNodeInserted', function(){
				unblockElement('.woocommerce-checkout-payment');
			});
		} else {
			checkout_form.off('checkout_place_order', submitPayFrame);
			checkout_form.on('checkout_place_order', submitPisp);
		}
	} else {
		// Unbind custom event handlers.
		checkout_form.off('checkout_place_order', submitPisp);
		checkout_form.off('checkout_place_order', submitPayFrame);
	}
}

/**
 * Makes sure to trigger on payment method changes and overriding the default button submit handler.
 */
var noFrixionChangePaymentMethod = function () {
	var order_review_form = jQuery('form#order_review');
	if (jQuery('input[name="payment_method"]:checked', order_review_form).val() === 'nofrixion_card') {
		createPaymentRequestChangePM();
		// Bind our custom event handler to checkout button.
		order_review_form.on('submit', submitPayFrameChangePM);
	} else {
		// Undo bind custom event handler.
		order_review_form.off('submit', submitPayFrameChangePM);
	}
}

/**
 * Makes sure to trigger add payment method page and overriding the default button submit handler.
 */
var noFrixionAuthorizeCard = function () {
	console.log('Authorize Card.');
	var add_pm_form = jQuery('form#add_payment_method');
	if (jQuery('input[name="payment_method"]:checked', add_pm_form).val() === 'nofrixion_card') {
		createPaymentRequestAuthorizeCard();
		// Bind our custom event handler to checkout button.
		add_pm_form.on('submit', submitPayFrameAuthorizeCard);
	} else {
		// Undo bind custom event handler.
		add_pm_form.off('submit', submitPayFrameAuthorizeCard);
	}
}

/**
 * Show errors, mostly copied from WC checkout.js
 *
 * @param error_message
 */
var submitError = function( error_message ) {
	let $checkoutForm = jQuery('form.checkout');
	jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
	$checkoutForm.prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + error_message + '</div>' ); // eslint-disable-line max-len
	$checkoutForm.removeClass( 'processing' ).unblock();
	$checkoutForm.find( '.input-text, select, input:checkbox' ).trigger( 'validate' ).trigger( 'blur' );
	scrollToNotices();
	jQuery(document.body).trigger('checkout_error' , [ error_message ]);
};

/**
 * Scroll to errors on top of form, copied from WC checkout.js.
 */
var scrollToNotices = function() {
	var scrollElement = jQuery( '.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout' );

	if ( ! scrollElement.length ) {
		scrollElement = $( 'form.checkout' );
	}

	jQuery.scroll_to_notices( scrollElement );
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
 * Handle visibility of payment form depending on stored tokens.
 */
var handleStoredTokens = function () {
	// Check if there are any stored tokens, handle initial page load.
	let $savedPaymentMethods = jQuery('ul.woocommerce-SavedPaymentMethods input[type="radio"]');
	if ($savedPaymentMethods.length > 0) {
		// Initial check.
		if (jQuery('li.woocommerce-SavedPaymentMethods-token').length > 0) {
			// At least one payment token available, if selected hide the CC form.
			// If one is selected by default, hide the CC form.
			if (jQuery('li.woocommerce-SavedPaymentMethods-token input[type="radio"]:checked').length > 0) {
				//togglePaymentForm(true);
			}
		} else {
			// Saved payment methods present but no tokens yet. CC form shown.
		}

		// Capture radio changes.
		$savedPaymentMethods.change(function () {
			if (jQuery(this).val() === 'new') {
				togglePaymentForm(false);
			} else {
				togglePaymentForm(true);
			}
		});
	}
}

/**
 * Hide or show the CC payment form.
 *
 * @param hide
 */
var togglePaymentForm = function (hide = true) {
	let $paymentForm = jQuery('#nf-cardPaymentForm');
	let $saveTokenCheckbox = jQuery('.woocommerce-SavedPaymentMethods-saveNew');
	if (hide) {
		$paymentForm.hide();
		$saveTokenCheckbox.hide();
	} else {
		$paymentForm.show();
		$saveTokenCheckbox.show();
	}
}

/**
 * Main entry point.
 */
jQuery(function ($) {
	// Listen on Update cart and change of payment methods.
	$('body').on('init_checkout updated_checkout payment_method_selected', function (event) {
		console.log('Fired event: ' + event.type);
		noFrixionSelected();
		handleStoredTokens();
	});
	// On payment method change page, initialize NoFrixion.
	if ( 'yes' === NoFrixionWP.is_change_payment_page || 'yes' === NoFrixionWP.is_pay_for_order_page ) {
		noFrixionChangePaymentMethod();
		handleStoredTokens();
	}
	if ( 'yes' === NoFrixionWP.is_add_payment_method_page) {
		noFrixionAuthorizeCard();
	}

	/**
	 * Detect closing of NoFrixion modal and stop blocking UI.
	 */
	$(document).on('DOMNodeRemoved', function(e) {
		if ($(e.target).hasClass('nf-modal')) {
			$('.woocommerce-checkout-payment').unblock();
		}
	});
});
