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
		const cart = window.DSA.cart;
		const payments = Array.from( page.querySelectorAll( '[data-kiwe-checkout-pay]' ) );
		for ( const button of payments ) {
			if ( ! button.hasAttribute( 'data-kiwe-checkout-pay-total' ) || button.querySelector( '[data-kiwe-checkout-value="total"]' ) ) continue;
			const amount = document.createElement( 'span' );
			amount.className = 'kiwe-checkout-payment-total';
			amount.dataset.kiweCheckoutValue = 'total';
			button.append( amount );
		}
		const consents = Array.from( page.querySelectorAll( '[data-kiwe-checkout-consent]' ) );
		const coupon = page.querySelector( '[data-kiwe-checkout-coupon]' );
		const status = page.querySelector( '[data-kiwe-checkout-status]' );
		const offers = Array.from( page.querySelectorAll( '[data-kiwe-checkout-offer]' ) );
		const offerPanel = page.querySelector( '[data-kiwe-checkout-offers]' );
		const deliveryButtons = Array.from( page.querySelectorAll( '[data-kiwe-checkout-delivery-mode]' ) );
		const saveShipping = window.DSA.updateCheckoutShipping;
		const deliveryFragment = () => page.querySelector( '[data-kiwe-checkout-shipping][data-brx-woo-fragment]' );
		const offerLabels = {
			apply: 'Apply', applied: 'Applied', join: 'Add membership',
			'in-cart': 'Membership in cart', choose: 'Choose a plan', unavailable: 'Unavailable for this cart',
		};
		let review = null;
		let busy = false;
		let refreshing = false;
		let pendingCart = null;
		let revision = 0;

		function announce( message ) {
			if ( status ) status.textContent = message || '';
		}

		async function refreshShippingFragment( data, order ) {
			const fragment = deliveryFragment();
			if ( ! fragment || ! data.shippingReview?.required || ! data.shippingFragmentUrl
				|| data.shippingReview.state === 'address' ) return;
			const attrs = fragment.dataset;
			const target = {
				id: attrs.brxWooFragmentId, rootId: attrs.brxWooFragmentRootId,
				source: attrs.brxWooFragmentSource, area: attrs.brxWooFragmentArea, token: attrs.brxWooFragmentToken,
			};
			const fields = new URLSearchParams( {
				postId: window.bricksData?.postId || 0, bricks_woo_current_url: window.location.href.split( '#' )[0],
				fragments: JSON.stringify( [ target ] ),
			} );
			const response = await request( data.shippingFragmentUrl, fields );
			const payload = await response.json();
			if ( order !== revision ) return;
			const html = Object.values( payload.data?.fragments || {} )[0];
			const fresh = typeof html === 'string'
				? new DOMParser().parseFromString( html, 'text/html' ).querySelector( '[data-kiwe-checkout-shipping][data-brx-woo-fragment]' ) : null;
			if ( ! fresh || fresh.dataset.brxWooFragmentId !== target.id ) throw new Error( 'Delivery options could not refresh.' );
			const active = fragment.contains( document.activeElement ) ? document.activeElement : null;
			( deliveryFragment() || fragment ).replaceWith( fresh );
			if ( active ) Array.from( fresh.querySelectorAll( 'input.shipping_method' ) )
				.find( input => input.name === active.name && input.value === active.value )?.focus();
		}

		function updateControls() {
			const ready = review && review.available && ! review.empty;
			const updating = busy || refreshing || Boolean( pendingCart );
			const disabled = updating || ! ready;
			const delivery = review?.shippingReview;
			const fragment = deliveryFragment();
			const shippingPending = Boolean( fragment && delivery?.required && delivery.state !== 'ready' );
			page.querySelectorAll( '[data-kiwe-checkout-show="delivery"]' ).forEach( section => { section.hidden = ! delivery?.required; } );
			if ( fragment ) {
				fragment.hidden = ! delivery?.required || delivery.state === 'address';
				fragment.querySelectorAll( 'input.shipping_method' ).forEach( input => { input.disabled = disabled; } );
			}
			deliveryButtons.forEach( button => {
				const mode = button.dataset.kiweCheckoutDeliveryMode;
				button.hidden = ! fragment || ! delivery?.required || delivery.state === 'address'
					|| ( mode === 'cod' ? ! delivery.codOption : ! ( delivery.codOption || delivery.mode === 'cod' ) );
				button.disabled = disabled;
				button.setAttribute( 'aria-pressed', String( delivery?.mode === mode ) );
			} );
			page.toggleAttribute( 'data-kiwe-checkout-ready', Boolean( review ) );
			payments.forEach( function ( button ) {
				button.hidden = ! ready || ! Object.hasOwn( review.methods, button.dataset.kiweCheckoutPay );
				if ( 'disabled' in button ) button.disabled = disabled || shippingPending;
				button.setAttribute( 'aria-disabled', disabled || shippingPending ? 'true' : 'false' );
			} );
			consents.forEach( function ( control ) {
				control.hidden = ! ready || !( control.dataset.kiweCheckoutConsent === 'terms' ? review.termsRequired : review.subscriptionQuote );
			} );
			if ( coupon ) coupon.hidden = Boolean( review ) && ( ! ready || ! review.coupons );
			let visibleOffers = 0;
			offers.forEach( button => {
				const offer = ready && review.offers?.[button.dataset.kiweCheckoutOffer];
				const card = button.closest( '[data-kiwe-checkout-offer-card]' );
				if ( card ) card.hidden = ! offer;
				else button.hidden = ! offer;
				if ( ! offer ) return;
				visibleOffers++;
				const actionable = [ 'apply', 'join', 'choose' ].includes( offer.state );
				button.dataset.kiweOfferState = offer.state;
				button.setAttribute( 'aria-disabled', String( disabled || ! actionable ) );
				if ( 'disabled' in button ) button.disabled = disabled || ! actionable;
				const label = button.querySelector( '[data-kiwe-offer-label]' ) || button;
				const text = offerLabels[offer.state];
				if ( text && label.textContent !== text ) label.textContent = text;
			} );
			if ( offerPanel ) offerPanel.hidden = ! visibleOffers;
			page.setAttribute( 'aria-busy', updating ? 'true' : 'false' );
		}

		function refresh( nextReview ) {
			const order = ++revision;
			refreshing = true;
			updateControls();
			return ( nextReview || readReview() ).then( async function ( data ) {
				if ( order !== revision ) return;
				await refreshShippingFragment( data, order );
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
				page.querySelectorAll( '[data-kiwe-checkout-show="savings"]' ).forEach( banner => { banner.hidden = ! data.savings; } );
				if ( ! data.available ) announce( 'Checkout details are unavailable. Open the address editor to continue.' );
				else if ( data.empty ) announce( 'Your cart is empty.' );
				else if ( data.purchaseError ) announce( data.purchaseError );
				else if ( deliveryFragment() && data.shippingReview?.state === 'address' ) announce( 'Add your delivery address to see shipping options.' );
				else if ( deliveryFragment() && data.shippingReview?.state === 'unavailable' ) announce( 'No delivery option is available for this address and cart.' );
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
			const delivery = deliveryFragment() && review.shippingReview;
			if ( delivery?.required && delivery.state !== 'ready' ) {
				announce( 'Choose an available delivery option before paying.' );
				return;
			}
			const mode = choice === 'cod' ? 'cod' : 'prepaid';
			if ( delivery?.required && delivery.modeSensitive && delivery.mode !== mode ) {
				await changeShipping( { mode } );
				announce( 'Review your updated delivery rates and total before continuing.' );
				return;
			}
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
			if ( delivery?.token ) fields.set( 'kiwe_checkout_shipping_quote', delivery.token );
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
				if ( delivery?.required ) await refresh();
				throw new Error( noticeText( result.messages ) || 'Review your details and try again.' );
			}
			const destination = new URL( result.redirect, window.location.href );
			if ( destination.origin !== window.location.origin ) throw new Error( 'Open your order to continue payment.' );
			window.location.assign( destination.href );
		}

		async function applyCoupon( selectedCode = '' ) {
			if ( ! review || ! review.coupons ) return;
			const field = coupon?.querySelector( '[name="coupon_code"], input[type="text"]' );
			const code = selectedCode || field?.value.trim();
			if ( ! code ) {
				announce( 'Enter a coupon code.' );
				if ( field ) field.focus();
				return;
			}
			const fields = new URLSearchParams( { coupon_code: code, security: review.couponNonce } );
			if ( review.fields.billing_email ) fields.set( 'billing_email', review.fields.billing_email );
			const response = await request( review.couponUrl, fields );
			if ( ! response.ok ) throw new Error( 'The coupon could not be checked. Try again.' );
			const html = await response.text();
			const message = noticeText( html );
			const output = new DOMParser().parseFromString( html, 'text/html' );
			if ( window.jQuery && output.querySelector( '.woocommerce-message' ) ) {
				window.jQuery( document.body ).trigger( 'applied_coupon_in_checkout', [ code ] );
			}
			await refresh();
			announce( message );
		}

		async function actOnOffer( id ) {
			const offer = review?.offers?.[id];
			if ( ! offer ) return;
			if ( offer.state === 'apply' ) await applyCoupon( offer.code );
			if ( offer.state === 'join' ) {
				if ( ! cart?.addProduct ) throw new Error( 'The cart could not load. Try again.' );
				await cart.addProduct( offer.productId, 'kiwe_checkout_offer', 1 );
				await refresh();
				announce( review?.offers?.[id]?.state === 'applied'
				? 'Membership added. The discount is included in this order.'
				: 'Membership added. Review your updated order total.' );
			}
			if ( offer.state === 'choose' ) {
				const destination = new URL( offer.url, window.location.href );
				if ( destination.origin === window.location.origin ) window.location.assign( destination.href );
			}
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

		async function changeShipping( selection ) {
			try {
				await refresh( Promise.resolve( await saveShipping( selection ) ) );
			} catch ( error ) {
				await refresh();
				throw error;
			}
		}

		if ( saveShipping ) page.addEventListener( 'change', function ( event ) {
			const input = event.target;
			if ( ! input.matches( 'input.shipping_method' ) || ! input.closest( '[data-kiwe-checkout-shipping]' ) ) return;
			event.stopImmediatePropagation();
			run( () => changeShipping( { index: Number( input.dataset.index ), rate: input.value } ), 'Updating delivery and total…' );
		}, true );

		page.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-kiwe-checkout-pay], [data-kiwe-checkout-consent], [data-kiwe-checkout-apply-coupon], [data-kiwe-checkout-offer], [data-kiwe-checkout-delivery-mode]' );
			if ( ! button || ! page.contains( button ) ) return;
			event.preventDefault();
			if ( button.hasAttribute( 'data-kiwe-checkout-consent' ) ) {
				button.setAttribute( 'aria-pressed', button.getAttribute( 'aria-pressed' ) === 'true' ? 'false' : 'true' );
			} else if ( button.hasAttribute( 'data-kiwe-checkout-pay' ) ) {
				run( () => placeOrder( button.dataset.kiweCheckoutPay ), 'Checking your order…' );
			} else if ( button.hasAttribute( 'data-kiwe-checkout-delivery-mode' ) ) {
				if ( review?.shippingReview?.mode !== button.dataset.kiweCheckoutDeliveryMode ) {
					run( () => changeShipping( { mode: button.dataset.kiweCheckoutDeliveryMode } ), 'Updating delivery rates…' );
				}
			} else if ( button.hasAttribute( 'data-kiwe-checkout-offer' ) ) {
				if ( button.getAttribute( 'aria-disabled' ) === 'true' ) return;
				run( () => actOnOffer( button.dataset.kiweCheckoutOffer ), 'Updating your offers…' );
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
		if ( cart?.setQuantity && cart?.changeVariation ) {
			page.addEventListener( 'change', function ( event ) {
				const field = event.target;
				if ( ! field.closest( '[data-kiwe-checkout-cart]' ) ) return;
				const variationKey = field.dataset.kiweCheckoutVariation;
				const quantityKey = field.matches( '.brxe-woocommerce-cart-quantity input.qty' )
					? field.name.match( /^cart\[([a-f0-9]{32})\]\[qty\]$/ )?.[1] : '';
				if ( ! variationKey && ! quantityKey ) return;
				event.stopImmediatePropagation();
				const previous = variationKey
					? Array.from( field.options ).find( option => option.defaultSelected )?.value : field.defaultValue;
				const value = Number( field.value );
				if ( busy || refreshing || pendingCart || field.value === '' || ! Number.isInteger( value ) || value < 0 ) {
					field.value = previous;
					return;
				}
				run( async function () {
					try {
						if ( variationKey ) await cart.changeVariation( variationKey, value );
						else await cart.setQuantity( quantityKey, value );
					} catch ( error ) {
						field.value = previous;
						throw error;
					}
				}, 'Updating your order…' );
			}, true );
		}
		window.addEventListener( 'dsa:checkout:draft-applied', () => refresh() );
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
