( function () {
	'use strict';

	const form = document.querySelector( 'form.woocommerce-checkout' );
	if ( ! form ) return;

	const address = form.querySelector( '[data-kiwe-checkout-address]' );
	const choices = form.querySelector( '[data-kiwe-payment-choices]' );
	const method = choices && choices.querySelector( '[name="kiwe_checkout_method"]' );
	const buttons = choices ? Array.from( choices.querySelectorAll( '[data-kiwe-payment-choice]' ) ) : [];
	const emptyAddress = address ? address.textContent : '';

	function syncAddress() {
		if ( ! address ) return;
		const shipping = form.querySelector( '#ship-to-different-address-checkbox:checked' );
		const prefix = shipping ? 'shipping_' : 'billing_';
		const fields = [ 'address_1', 'address_2', 'city', 'state', 'postcode' ];
		const values = fields.map( function ( key ) {
			const field = form.elements[ prefix + key ];
			if ( ! field || ! field.value ) return '';
			if ( field.tagName === 'SELECT' && field.selectedOptions[ 0 ] ) return field.selectedOptions[ 0 ].text.trim();
			return field.value.trim();
		} );
		const summary = values[ 0 ] ? values.filter( Boolean ).join( ', ' ) : emptyAddress;
		if ( address.textContent !== summary ) address.textContent = summary;
	}

	function gatewayId( choice ) {
		return 'cod' === choice ? 'cod' : 'razorpay';
	}

	function gatewayInput( choice ) {
		return form.querySelector( 'input[name="payment_method"][value="' + gatewayId( choice ) + '"]:not(:disabled)' );
	}

	function syncPaymentChoices() {
		if ( ! choices ) return;
		const active = form.querySelector( 'input[name="payment_method"]:checked' );
		if ( ! active || 'razorpay' !== active.value ) method.value = '';
		buttons.forEach( function ( button ) {
			const choice = button.dataset.kiwePaymentChoice;
			const input = gatewayInput( choice );
			const selected = 'razorpay' === ( active && active.value ) ? method.value || 'razorpay' : active && active.value;
			button.hidden = ! input;
			button.setAttribute( 'aria-pressed', input && input.checked && choice === selected ? 'true' : 'false' );
		} );
		choices.hidden = buttons.every( function ( button ) { return button.hidden; } );
	}

	buttons.forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			const choice = button.dataset.kiwePaymentChoice;
			const input = gatewayInput( choice );
			if ( ! input ) return;
			method.value = 'upi' === choice || 'card' === choice ? choice : '';
			if ( ! input.checked ) input.click();
			syncPaymentChoices();
		} );
	} );

	function sync() {
		syncAddress();
		syncPaymentChoices();
	}

	form.addEventListener( 'change', sync );
	if ( window.jQuery ) window.jQuery( document.body ).on( 'updated_checkout.kiweExperience', sync );
	sync();
} )();
