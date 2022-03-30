var successCallback = function(data) {
	var checkout_form = $( 'form.woocommerce-checkout' );

	// deactivate the tokenRequest function event
	checkout_form.off( 'checkout_place_order', createOrderPaymentRequest );

	// submit the form now
	//checkout_form.submit();
};

var errorCallback = function(data) {
	console.log(data);
};

var createOrderPaymentRequest = function(e) {
	e.preventDefault();

	let data = {
		'action': 'nofrixion_payment_request',
		'apiNonce': NoFrixionWP.apiNonce
	};

	jQuery.post(NoFrixionWP.url, data, function(response) {
		if (response.data.paymentRequestId) {
			try {
				//jQuery('form[name=checkout]').off();
				var paymentRequestID = response.data.paymentRequestId;
				var nfPayFrame = new NoFrixionPayFrame(paymentRequestID, 'nf-payframe', 'https://api-sandbox.nofrixion.com');
				nfPayFrame.load();
				jQuery('.wc-nofrixion-overlay').show();
			} catch (ex) {
				console.log('Error occurred initializing the payframe: ' + ex);
			}
		}
	}).fail( function() {
		alert('Error processing your request. Please contact support or try again.')
	});

	return false;
};

jQuery(function($){
	var checkout_form = $( 'form.woocommerce-checkout' );
	checkout_form.on( 'checkout_place_order', createOrderPaymentRequest );

	// Test form submission with
	$('.nofrixion-test-submit').click(function(e) {
		e.preventDefault();

		$.post('https://nofrixion.free.beeceptor.com/test', {}, function(response) {
			console.log('send success');
			$('form').append('<p>got response: ' + response.status + ' ... redirecting in 2 sec.</p>');
			setTimeout(function () {
				window.location = 'https://nofrixion.com';
			}, 2000);
		}).fail( function() {
			alert('error sending ajax request.');
		});
	});

});
