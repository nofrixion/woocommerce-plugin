var successCallback = function (data) {
	var checkout_form = $('form.woocommerce-checkout');

	// deactivate the tokenRequest function event
	checkout_form.off('checkout_place_order', createOrderPaymentRequest);

	// submit the form now
	//checkout_form.submit();
};

var errorCallback = function (data) {
	console.log(data);
};

var createOrderPaymentRequest = function (e) {
	//e.preventDefault();

	let data = {
		'action': 'nofrixion_payment_request',
		'apiNonce': NoFrixionWP.apiNonce
	};

	jQuery.post(NoFrixionWP.url, data, function (response) {
		if (response.data.paymentRequestId) {
			try {
				var paymentRequestID = response.data.paymentRequestId;
				var nfPayFrame = new NoFrixionPayFrame(paymentRequestID, 'nf-payframe', 'https://api-sandbox.nofrixion.com');
				nfPayFrame.load();
				//jQuery('.wc-nofrixion-overlay').show();
			} catch (ex) {
				console.log('Error occurred initializing the payframe: ' + ex);
			}
		}
	}).fail(function () {
		alert('Error processing your request. Please contact support or try again.')
	});

	return false;
};

var doNothing = function (e) {
	console.log('doNothing() triggered.')
	return false;
};

var noFrixionSelected = function () {
	if (jQuery('form[name="checkout"] input[name="payment_method"]:checked').val() == 'nofrixion') {
		createOrderPaymentRequest();
	}
}

jQuery(function ($) {
	var checkout_form = $('form.woocommerce-checkout');
	checkout_form.on('checkout_place_order', doNothing);

	$('body')
		.on('updated_checkout', function () {
			noFrixionSelected();
		});

	$('input[name="payment_method"]').change(function () {
		noFrixionSelected();
	});

	// Test form submission.
	$('.nofrixion-test-submit').click(function (e) {
		e.preventDefault();

		$.post('https://nofrixion.free.beeceptor.com/test', {}, function (response) {
			console.log('send success');
			$('form').append('<p>got response: ' + response.status + ' ... redirecting in 2 sec.</p>');
			setTimeout(function () {
				window.location = 'https://nofrixion.com';
			}, 2000);
		}).fail(function () {
			alert('error sending ajax request.');
		});
	});

});
