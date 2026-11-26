( function () {
	'use strict';

	const reviewPage = document.querySelector( '[data-kiwe-checkout]' );
	if ( reviewPage ) {
		bindAuthoredCheckout( reviewPage );
		return;
	}

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

	function bindAuthoredCheckout( page ) {
		const readReview = window.DSA && window.DSA.readCheckoutReview;
		if ( ! readReview ) return;
		const payments = Array.from( page.querySelectorAll( '[data-kiwe-checkout-pay]' ) );
		const consents = Array.from( page.querySelectorAll( '[data-kiwe-checkout-consent]' ) );
		const coupon = page.querySelector( '[data-kiwe-checkout-coupon]' );
		const status = page.querySelector( '[data-kiwe-checkout-status]' );
		let review = null;
		let busy = false;
		let refreshing = false;
		let pendingCart = null;
		let revision = 0;

		function announce( message ) {
			if ( status ) status.textContent = message || '';
		}

		function updateControls() {
			const ready = review && review.available && ! review.empty;
			const updating = busy || refreshing || Boolean( pendingCart );
			const disabled = updating || ! ready;
			page.toggleAttribute( 'data-kiwe-checkout-ready', Boolean( review ) );
			payments.forEach( function ( button ) {
				button.hidden = ! ready || ! Object.hasOwn( review.methods, button.dataset.kiweCheckoutPay );
				if ( 'disabled' in button ) button.disabled = disabled;
				button.setAttribute( 'aria-disabled', disabled ? 'true' : 'false' );
			} );
			consents.forEach( function ( control ) {
				control.hidden = ! ready || !( control.dataset.kiweCheckoutConsent === 'terms' ? review.termsRequired : review.subscriptionQuote );
			} );
			if ( coupon ) coupon.hidden = Boolean( review ) && ( ! ready || ! review.coupons );
			page.setAttribute( 'aria-busy', updating ? 'true' : 'false' );
		}

		function refresh() {
			const order = ++revision;
			refreshing = true;
			updateControls();
			return readReview().then( function ( data ) {
				if ( order !== revision ) return;
				if ( ! review || review.subscriptionQuote !== data.subscriptionQuote ) {
					consents.filter( control => control.dataset.kiweCheckoutConsent === 'renewal' )
						.forEach( control => control.setAttribute( 'aria-pressed', 'false' ) );
				}
				review = data;
				page.querySelectorAll( '[data-kiwe-checkout-value]' ).forEach( function ( output ) {
					const key = output.dataset.kiweCheckoutValue;
					output.textContent = data[key] || ( key === 'address' ? 'Add your address' : key === 'total' ? '–' : '' );
				} );
				if ( ! data.available ) announce( 'Checkout details are unavailable. Open the address editor to continue.' );
				else if ( data.empty ) announce( 'Your cart is empty.' );
				else if ( data.purchaseError ) announce( data.purchaseError );
				else if ( data.sharedAgreement ) announce( 'These plans share one billing agreement. Buy them separately to manage renewals independently.' );
				else if ( ! Object.keys( data.methods ).length && ! data.renewal ) announce( 'No payment method is available for this cart.' );
				else announce( '' );
			} ).catch( function () {
				if ( order !== revision ) return;
				review = null;
				announce( 'Checkout could not load. Refresh the page to try again.' );
			} ).finally( function () {
				if ( order !== revision ) return;
				refreshing = false;
				updateControls();
			} );
		}

		function accepted( kind ) {
			return consents.some( control => control.dataset.kiweCheckoutConsent === kind && control.getAttribute( 'aria-pressed' ) === 'true' );
		}

		function request( endpoint, fields ) {
			const url = new URL( endpoint, window.location.href );
			if ( url.origin !== window.location.origin ) throw new Error( 'Invalid checkout destination.' );
			return fetch( url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: fields } );
		}

		function noticeText( html ) {
			return new DOMParser().parseFromString( String( html || '' ), 'text/html' ).body.textContent.trim();
		}

		async function placeOrder( choice ) {
			if ( ! review || ! review.available || review.empty || ! Object.hasOwn( review.methods, choice ) ) return;
			if ( review.termsRequired && ! accepted( 'terms' ) ) {
				announce( 'Accept the terms before continuing.' );
				return;
			}
			if ( choice === 'subscription' && ! accepted( 'renewal' ) ) {
				announce( 'Review and agree to automatic renewals before continuing.' );
				return;
			}
			const fields = new URLSearchParams();
			Object.entries( review.fields ).forEach( function ( [ key, value ] ) {
				if ( Array.isArray( value ) ) value.forEach( item => fields.append( key + '[]', item ) );
				else fields.set( key, value );
			} );
			Object.entries( review.shipping ).forEach( ( [ index, rate ] ) => fields.set( 'shipping_method[' + index + ']', rate ) );
			fields.set( 'woocommerce-process-checkout-nonce', review.checkoutNonce );
			fields.set( 'payment_method', review.methods[choice] );
			fields.set( 'kiwe_checkout_method', choice );
			if ( review.termsRequired ) {
				fields.set( 'terms-field', '1' );
				fields.set( 'terms', 'on' );
			}
			if ( choice === 'subscription' ) {
				fields.set( 'kiwe_subscription_quote', review.subscriptionQuote );
				fields.set( 'kiwe_subscription_consent', 'yes' );
			}
			const response = await request( review.checkoutUrl, fields );
			const result = await response.json();
			if ( result.result !== 'success' || ! result.redirect ) {
				throw new Error( noticeText( result.messages ) || 'Review your details and try again.' );
			}
			const destination = new URL( result.redirect, window.location.href );
			if ( destination.origin !== window.location.origin ) throw new Error( 'Open your order to continue payment.' );
			window.location.assign( destination.href );
		}

		async function applyCoupon() {
			if ( ! review || ! review.coupons || ! coupon ) return;
			const field = coupon.querySelector( '[name="coupon_code"], input[type="text"]' );
			if ( ! field || ! field.value.trim() ) {
				announce( 'Enter a coupon code.' );
				if ( field ) field.focus();
				return;
			}
			const fields = new URLSearchParams( { coupon_code: field.value.trim(), security: review.couponNonce } );
			if ( review.fields.billing_email ) fields.set( 'billing_email', review.fields.billing_email );
			const response = await request( review.couponUrl, fields );
			if ( ! response.ok ) throw new Error( 'The coupon could not be checked. Try again.' );
			const message = noticeText( await response.text() );
			await refresh();
			announce( message );
		}

		function run( action, message ) {
			if ( busy || refreshing || pendingCart || ! review || ! review.available ) return;
			busy = true;
			updateControls();
			announce( message );
			Promise.resolve().then( action ).catch( function ( error ) {
				announce( error.message || 'Checkout could not continue.' );
			} ).finally( function () {
				busy = false;
				updateControls();
			} );
		}

		page.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-kiwe-checkout-pay], [data-kiwe-checkout-consent], [data-kiwe-checkout-apply-coupon]' );
			if ( ! button || ! page.contains( button ) ) return;
			event.preventDefault();
			if ( button.hasAttribute( 'data-kiwe-checkout-consent' ) ) {
				button.setAttribute( 'aria-pressed', button.getAttribute( 'aria-pressed' ) === 'true' ? 'false' : 'true' );
			} else if ( button.hasAttribute( 'data-kiwe-checkout-pay' ) ) {
				run( () => placeOrder( button.dataset.kiweCheckoutPay ), 'Checking your order…' );
			} else {
				run( applyCoupon, 'Checking coupon…' );
			}
		} );
		if ( coupon ) coupon.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			run( applyCoupon, 'Checking coupon…' );
		}, true );
		if ( coupon && coupon.matches( '.brxe-woocommerce-checkout-coupon' ) ) {
			// Bricks styles this control; its classic handler expects an on-page billing form.
			function useCheckoutSession( event ) {
				event.preventDefault();
				event.stopImmediatePropagation();
				run( applyCoupon, 'Checking coupon…' );
			}
			coupon.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( 'button[name="apply_coupon"]' ) ) useCheckoutSession( event );
			}, true );
			coupon.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' && event.target.matches( 'input[name="coupon_code"]' ) ) useCheckoutSession( event );
			}, true );
		}
		window.addEventListener( 'dsa:checkout:draft-applied', refresh );
		window.addEventListener( 'dsa:checkout:cart-updating', function ( event ) {
			const pending = pendingCart = event.detail;
			updateControls();
			pending.then( function () {
				if ( pendingCart !== pending ) return;
				pendingCart = null;
				refresh();
			} );
		} );
		window.addEventListener( 'dsa:checkout:cart-updated', function () {
			if ( ! pendingCart ) refresh();
		} );
		refresh();
	}
} )();
