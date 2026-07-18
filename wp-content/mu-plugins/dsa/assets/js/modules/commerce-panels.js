let phonekey = {};
let commerce = {};
let checkoutState = {};

function escapeHtml( value ) {
	return String( value == null ? '' : value ).replace( /[&<>'"]/g, function ( character ) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ character ];
	} );
}

function renderBasicPanel( label, copy ) {
	return '<section class="dsa-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( label ) + '"><h2>' + escapeHtml( label ) + '</h2><p>' + escapeHtml( copy ) + '</p></section>';
}

function renderCartTrustBadges( badges ) {
	const list = Array.isArray( badges ) ? badges.filter( function ( badge ) {
		return badge && badge.label;
	} ).slice( 0, 3 ) : [];

	if ( ! list.length ) {
		return '';
	}

	return '<div class="kiwe-cart-v2027__trust" aria-label="Cart trust">' + list.map( function ( badge ) {
		return '<span class="' + ( badge.active ? 'is-active' : '' ) + '"><i aria-hidden="true"></i>' + escapeHtml( badge.label ) + '</span>';
	} ).join( '' ) + '</div>';
}

function renderLegacyCartPanel( label ) {
	const cart = phonekey.cart || {};

	if ( ! cart.available ) {
		return renderBasicPanel( label, 'Cart appears here when WooCommerce is active.');
	}

	const items = Array.isArray( cart.items ) ? cart.items : [];
	const checkoutUrl = cart.checkoutUrl || ( commerce.routes && commerce.routes.checkoutUrl ) || '';
	const recommendations = cart.recommendations || commerce.complements || [];

	if ( ! items.length ) {
		return [
			'<section class="dsa-panel dsa-cart-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( label ) + '" data-dsa-cart-panel>',
			'<p class="dsa-cart-panel__eyebrow">' + escapeHtml( label || 'Cart' ) + '</p>',
			'<h2>Your cart is waiting.</h2>',
			'<p class="dsa-panel__meta">Items you add will appear here inside the Surface.</p>',
			checkoutUrl ? '<a class="dsa-cart-panel__checkout is-disabled" href="' + escapeHtml( checkoutUrl ) + '" data-dsa-context-slot data-dsa-context-name="cart" data-dsa-context-width="dock" aria-disabled="true"><span>Checkout</span><span class="dsa-panel__meta">Empty</span></a>' : '',
			'</section>',
		].join( '' );
	}

	return [
		'<section class="dsa-panel dsa-cart-panel dsa-cart-panel--has-checkout" role="dialog" aria-modal="false" aria-label="' + escapeHtml( label ) + '" data-dsa-cart-panel>',
		'<p class="dsa-cart-panel__eyebrow">' + escapeHtml( label || 'Cart' ) + '</p>',
		'<h2>Your cart</h2>',
		'<div class="dsa-cart-panel__summary"><strong>' + escapeHtml( cart.total || '' ) + '</strong><span>' + escapeHtml( cart.count || items.length ) + ' item(s)</span></div>',
		renderDiscountSummary( cart.discountSummary, 'cart' ),
		'<div class="dsa-cart-panel__items">',
		items.map( renderCartPanelItem ).join( '' ),
		'</div>',
		renderCartRecommendations( recommendations ),
		'<p class="dsa-panel__meta" data-dsa-cart-message></p>',
		checkoutUrl ? '<a class="dsa-cart-panel__checkout" href="' + escapeHtml( checkoutUrl ) + '" data-dsa-checkout-open data-dsa-keep-open data-dsa-context-slot data-dsa-context-name="cart" data-dsa-context-width="dock"><span>Checkout</span><span class="dsa-panel__meta">' + escapeHtml( cart.total || '' ) + '</span></a>' : '',
		'</section>',
	].join( '' );
}

function renderPrototypeCartPanel( label, payload ) {
	payload = payload || {};
	const cart = phonekey.cart || {};

	if ( ! cart.available ) {
		return renderBasicPanel( label, 'Cart appears here when WooCommerce is active.');
	}

	const items = Array.isArray( cart.items ) ? cart.items : [];
	const checkoutUrl = cart.checkoutUrl || ( commerce.routes && commerce.routes.checkoutUrl ) || '';
	const recommendations = cart.recommendations || commerce.complements || [];
	const count = cart.count || items.length;
	const trustBadges = renderCartTrustBadges( payload.trustBadges );

	if ( ! items.length ) {
		return [
			'<section class="dsa-panel dsa-cart-panel kiwe-cart-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( label ) + '" data-dsa-cart-panel data-dsa-cart-adapter="prototype-2027">',
			'<div class="kiwe-cart-v2027__title"><p class="dsa-cart-panel__eyebrow">' + escapeHtml( label || 'Cart' ) + '</p><h2>Your cart is waiting.</h2><p class="dsa-panel__meta">Add a product and Kiwe will keep the checkout path close without taking over WooCommerce.</p></div>',
			trustBadges,
			'<div class="kiwe-cart-v2027__empty"><strong>Empty cart</strong><span>Items you add will appear here inside the Surface.</span></div>',
			checkoutUrl ? '<a class="dsa-cart-panel__checkout is-disabled kiwe-cart-v2027__checkout" href="' + escapeHtml( checkoutUrl ) + '" data-dsa-context-slot data-dsa-context-name="cart" data-dsa-context-width="dock" aria-disabled="true"><span>Checkout</span><span class="dsa-panel__meta">Empty</span></a>' : '',
			'</section>',
		].join( '' );
	}

	return [
		'<section class="dsa-panel dsa-cart-panel dsa-cart-panel--has-checkout kiwe-cart-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( label ) + '" data-dsa-cart-panel data-dsa-cart-adapter="prototype-2027">',
		'<div class="kiwe-cart-v2027__title"><p class="dsa-cart-panel__eyebrow">' + escapeHtml( label || 'Cart' ) + '</p><h2>Your cart</h2></div>',
		'<div class="dsa-cart-panel__summary kiwe-cart-v2027__summary"><span><small>Total</small><strong>' + escapeHtml( cart.total || '' ) + '</strong></span><span><small>Items</small><strong>' + escapeHtml( count ) + '</strong></span></div>',
		renderDiscountSummary( cart.discountSummary, 'cart' ),
		'<div class="dsa-cart-panel__items kiwe-cart-v2027__items">',
		items.map( renderCartPanelItem ).join( '' ),
		'</div>',
		renderCartRecommendations( recommendations ),
		trustBadges,
		'<p class="dsa-panel__meta" data-dsa-cart-message></p>',
		checkoutUrl ? '<a class="dsa-cart-panel__checkout kiwe-cart-v2027__checkout" href="' + escapeHtml( checkoutUrl ) + '" data-dsa-checkout-open data-dsa-keep-open data-dsa-context-slot data-dsa-context-name="cart" data-dsa-context-width="dock"><span>Checkout</span><span class="dsa-panel__meta">' + escapeHtml( cart.total || '' ) + '</span></a>' : '',
		'</section>',
	].join( '' );
}

const cartAdapters = {
	legacy: renderLegacyCartPanel,
	prototype: renderPrototypeCartPanel,
	kiwe2027: renderPrototypeCartPanel,
};

function renderCheckoutPanel() {
	const contract = checkoutState.contract || {};

	if ( checkoutState.loading || ! checkoutState.contract ) {
		return [
			'<section class="dsa-panel dsa-checkout-panel" role="dialog" aria-modal="false" aria-label="Checkout" data-dsa-checkout-panel>',
			'<p class="dsa-checkout-panel__loading">Preparing checkout...</p>',
			'</section>',
		].join( '' );
	}

	if ( ! contract.available ) {
		return renderBasicPanel( 'Checkout', 'WooCommerce checkout is not available.' );
	}

	const groups = contract.groups || {};
	const fields = [ 'billing', 'shipping', 'account', 'order' ].reduce( function ( list, group ) {
		return list.concat( Array.isArray( groups[ group ] ) ? groups[ group ] : [] );
	}, [] );

	return [
		'<section class="dsa-panel dsa-checkout-panel" role="dialog" aria-modal="false" aria-label="Checkout" data-dsa-checkout-panel>',
		'<h2 class="dsa-visually-hidden">Checkout details</h2>',
		renderCheckoutNotices(),
		renderDiscountSummary( contract.discountSummary, 'checkout' ),
		'<form id="dsa-checkout-surface-form" class="dsa-checkout-form" data-dsa-checkout-form data-dsa-keep-open>',
		contract.needsShipping ? '<label class="dsa-checkout-checkbox dsa-checkout-shipping-toggle"><input name="ship_to_different_address" type="checkbox" value="1" data-dsa-checkout-field data-dsa-checkout-shipping-toggle' + ( contract.shipToDifferent ? ' checked' : '' ) + '><span>Use a different shipping address</span></label>' : '',
		contract.canCreateAccount ? '<label class="dsa-checkout-checkbox dsa-checkout-account-toggle"><input name="createaccount" type="checkbox" value="1" data-dsa-checkout-field data-dsa-checkout-account-toggle' + ( contract.createAccount ? ' checked' : '' ) + ( contract.accountRequired ? ' disabled' : '' ) + '><span>Create an account</span></label>' : '',
		'<div class="dsa-checkout-fields">',
		fields.map( renderCheckoutField ).join( '' ),
		'</div>',
		'<div class="dsa-checkout-actions" data-dsa-context-slot data-dsa-context-name="checkout" data-dsa-context-width="dock">',
		'<button class="dsa-cart-panel__checkout dsa-checkout-continue" type="submit" form="dsa-checkout-surface-form"><span>' + ( checkoutState.returnToPage ? 'Return to Place order' : 'Continue to Place order' ) + '</span><span class="dsa-panel__meta">' + escapeHtml( contract.cartTotal || '' ) + '</span></button>',
		'<span class="dsa-panel__meta" data-dsa-checkout-message></span>',
		'</div>',
		'</form>',
		'</section>',
	].join( '' );
}

function renderDiscountSummary( summary, context ) {
	summary = summary && typeof summary === 'object' ? summary : {};
	const lines = Array.isArray( summary.lines ) ? summary.lines : [];

	if ( ! summary.hasDiscount || ! lines.length ) {
		return '';
	}

	return [
		'<section class="dsa-discount-summary dsa-discount-summary--' + escapeHtml( context || 'cart' ) + '" aria-label="Applied discounts">',
		'<div class="dsa-discount-summary__head"><span class="dsa-discount-summary__mark" aria-hidden="true">&#10003;</span><strong>Discount applied</strong><span>' + escapeHtml( summary.totalDiscount || '' ) + '</span></div>',
		'<div class="dsa-discount-summary__before"><span>Total</span><strong>' + escapeHtml( summary.beforeDiscount || summary.subtotal || '' ) + '</strong></div>',
		'<div class="dsa-discount-summary__lines">',
		lines.map( function ( line ) {
			return '<div><span>' + escapeHtml( line.label || 'Cart discount' ) + '</span><strong>' + escapeHtml( line.amount || '' ) + '</strong></div>';
		} ).join( '' ),
		'</div>',
		'<div class="dsa-discount-summary__total"><span>Total after discount</span><strong>' + escapeHtml( summary.total || '' ) + '</strong></div>',
		'</section>',
	].join( '' );
}

function renderCheckoutNotices() {
	const notices = Array.isArray( checkoutState.notices ) ? checkoutState.notices : [];

	if ( ! notices.length ) {
		return '';
	}

	return '<div class="dsa-checkout-notices" role="alert">' + notices.map( function ( notice ) {
		return '<p>' + escapeHtml( notice ) + '</p>';
	} ).join( '' ) + '</div>';
}

function renderCheckoutField( field ) {
	const key = String( field.key || '' );
	const id = 'dsa-checkout-' + key;
	const error = checkoutState.errors && checkoutState.errors[ key ] ? String( checkoutState.errors[ key ] ) : '';
	const type = String( field.type || 'text' );
	const required = field.required ? ' required aria-required="true"' : '';
	const invalid = error ? ' aria-invalid="true" aria-describedby="' + id + '-error"' : '';
	const autocomplete = field.autocomplete ? ' autocomplete="' + escapeHtml( field.autocomplete ) + '"' : '';
	const wrapperClass = 'dsa-checkout-field dsa-checkout-field--' + escapeHtml( field.group || 'general' ) + ' dsa-checkout-field--type-' + escapeHtml( type ) + ( error ? ' has-error' : '' );
	let control = '';

	if ( type === 'select' || type === 'country' ) {
		const options = field.options || {};
		control = '<select class="dsa-auth-field" id="' + id + '" name="' + escapeHtml( key ) + '" data-dsa-checkout-field data-dsa-checkout-type="' + escapeHtml( type ) + '"' + required + invalid + autocomplete + '>';
		control += '<option value="">' + escapeHtml( field.placeholder || field.label || 'Select' ) + '</option>';
		Object.keys( options ).forEach( function ( value ) {
			control += '<option value="' + escapeHtml( value ) + '"' + ( String( field.value || '' ) === String( value ) ? ' selected' : '' ) + '>' + escapeHtml( options[ value ] ) + '</option>';
		} );
		control += '</select>';
	} else if ( type === 'textarea' ) {
		control = '<textarea class="dsa-auth-field" id="' + id + '" name="' + escapeHtml( key ) + '" data-dsa-checkout-field placeholder="' + escapeHtml( field.placeholder || field.label || '' ) + '"' + required + invalid + autocomplete + '>' + escapeHtml( field.value || '' ) + '</textarea>';
	} else if ( type === 'checkbox' ) {
		control = '<label class="dsa-checkout-checkbox"><input id="' + id + '" name="' + escapeHtml( key ) + '" type="checkbox" value="1" data-dsa-checkout-field' + ( String( field.value || '' ) === '1' ? ' checked' : '' ) + required + invalid + '><span>' + escapeHtml( field.placeholder || field.label || '' ) + '</span></label>';
	} else {
		const inputType = [ 'email', 'tel', 'number', 'password', 'date' ].indexOf( type ) !== -1 ? type : 'text';
		control = '<input class="dsa-auth-field" id="' + id + '" name="' + escapeHtml( key ) + '" type="' + inputType + '" value="' + escapeHtml( field.value || '' ) + '" data-dsa-checkout-field placeholder="' + escapeHtml( field.placeholder || field.label || '' ) + '" aria-label="' + escapeHtml( field.label || field.placeholder || key ) + '"' + required + invalid + autocomplete + '>';
	}

	return [
		'<div class="' + wrapperClass + '">',
		type !== 'checkbox' ? '<label class="dsa-visually-hidden" for="' + id + '">' + escapeHtml( field.label || field.placeholder || key ) + '</label>' : '',
		control,
		error ? '<span class="dsa-checkout-field__error" id="' + id + '-error" role="alert">' + escapeHtml( error ) + '</span>' : '',
		'</div>',
	].join( '' );
}

function renderCartRecommendations( products ) {
	const settings = commerce.settings || {};
	const list = Array.isArray( products ) ? products.slice( 0, Number( settings.fbtMaxProducts ) || 6 ) : [];

	if ( ! settings.fbtEnabled || ! list.length ) {
		return '';
	}

	return [
		'<section class="dsa-cart-fbt" data-dsa-cart-fbt data-dsa-keep-open>',
		'<h3>' + escapeHtml( settings.fbtTitle || 'Frequently Bought Together' ) + '</h3>',
		'<div class="dsa-cart-fbt__rail" data-dsa-cart-fbt-rail>',
		list.map( renderCartRecommendationCard ).join( '' ),
		'</div>',
		'</section>',
	].join( '' );
}

function renderCartRecommendationCard( product ) {
	const image = product.image || '';
	const title = product.title || 'Product';
	const price = product.price || '';
	const weight = product.weight || '';
	const salePrice = product.salePrice || product.sale_price || '';
	const regularPrice = product.regularPrice || product.regular_price || '';
	const id = product.id || '';
	const addable = product.addable !== false && product.actionSafe !== 'view_only';
	const url = product.url || '';
	const triggerId = product.triggerId || product.trigger_id || '';
	const offerLabel = product.offerLabel || '';
	const triggerTitle = product.triggerTitle || '';
	const stateLabel = product.stateLabel || '';
	const actionSafe = product.actionSafe || ( addable ? 'add_to_cart' : 'view_product' );
	const actionLabel = product.actionLabel || ( actionSafe === 'claim_discount' ? 'Apply' : 'Add' );
	const priceHtml = salePrice && regularPrice
		? '<span class="dsa-cart-fbt__price"><strong>' + escapeHtml( salePrice ) + '</strong><del>' + escapeHtml( regularPrice ) + '</del></span>'
		: ( price ? '<span class="dsa-cart-fbt__price">' + escapeHtml( price ) + '</span>' : '' );
	let actionHtml = '';

	if ( actionSafe === 'claim_discount' && id && triggerId ) {
		actionHtml = '<button class="dsa-cart-fbt__action dsa-cart-fbt__action--claim" type="button" data-dsa-cart-claim="' + escapeHtml( id ) + '" data-dsa-cart-trigger="' + escapeHtml( triggerId ) + '"><span>' + escapeHtml( actionLabel ) + '</span></button>';
	} else if ( actionSafe === 'applied' ) {
		actionHtml = '<button class="dsa-cart-fbt__action dsa-cart-fbt__action--applied" type="button" disabled><span>' + escapeHtml( actionLabel || 'Applied' ) + '</span></button>';
	} else if ( addable && id ) {
		actionHtml = '<button class="dsa-cart-fbt__action dsa-cart-fbt__action--add" type="button" data-dsa-cart-add="' + escapeHtml( id ) + '"' + ( triggerId ? ' data-dsa-cart-trigger="' + escapeHtml( triggerId ) + '"' : '' ) + ' aria-label="Add ' + escapeHtml( title ) + ' to cart"><span aria-hidden="true">+</span></button>';
	} else if ( url ) {
		actionHtml = '<a class="dsa-cart-fbt__view" href="' + escapeHtml( url ) + '" data-dsa-full-navigation>View</a>';
	}

	return [
		'<article class="dsa-cart-fbt__card dsa-fbt-card" data-dsa-cart-fbt-card>',
		image ? '<img class="dsa-fbt-img" src="' + escapeHtml( image ) + '" alt="">' : '<span class="dsa-cart-fbt__image-placeholder dsa-fbt-img" aria-hidden="true"></span>',
		offerLabel ? '<em>' + escapeHtml( offerLabel ) + '</em>' : '',
		'<strong>' + escapeHtml( title ) + '</strong>',
		triggerTitle ? '<small>with ' + escapeHtml( triggerTitle ) + '</small>' : '',
		stateLabel ? '<small>' + escapeHtml( stateLabel ) + '</small>' : '',
		weight ? '<small class="dsa-cart-fbt__weight">' + escapeHtml( weight ) + '</small>' : '',
		priceHtml,
		actionHtml,
		'</article>',
	].join( '' );
}

function renderCartPanelItem( item ) {
	const image = item.image || '';
	const title = item.title || 'Cart item';
	const quantity = Number( item.quantity ) || 0;
	const maxQuantity = Math.max( 1, Number( item.maxQuantity ) || 99 );
	const price = item.subtotal || item.price || '';
	const weight = item.weight || '';
	const quantityControls = commerce.settings && commerce.settings.cartQuantityControls;
	const stockBadge = item.stockBadge && item.stockBadge.label ? item.stockBadge : null;
	const key = item.key || '';
	const productId = item.productId || item.product_id || '';
	const variationId = item.variationId || item.variation_id || '';
	const itemMeta = ' data-dsa-cart-product="' + escapeHtml( productId ) + '" data-dsa-cart-variation="' + escapeHtml( variationId ) + '"';
	const productLink = item.permalink ? '<a class="dsa-cart-panel__product-link" href="' + escapeHtml( item.permalink ) + '" data-dsa-full-navigation>' + escapeHtml( title ) + '</a>' : '<strong>' + escapeHtml( title ) + '</strong>';
	const plusDisabled = quantity >= maxQuantity ? ' disabled' : '';
	const content = [
		image ? '<img class="dsa-line-thumb" src="' + escapeHtml( image ) + '" alt="">' : '<span class="dsa-cart-panel__image-placeholder dsa-line-thumb" aria-hidden="true"></span>',
		'<span class="dsa-cart-panel__item-body dsa-cart-line__body">',
		productLink,
		'<span>' + ( price ? escapeHtml( price ) : '' ) + '</span>',
		weight ? '<small class="dsa-cart-panel__weight">' + escapeHtml( weight ) + '</small>' : '',
		stockBadge ? '<em class="dsa-cart-panel__stock-badge is-' + escapeHtml( stockBadge.type || 'alert' ) + '">' + escapeHtml( stockBadge.label ) + '</em>' : '',
		'</span>',
		quantityControls && key ? '<span class="dsa-cart-panel__quantity dsa-quantity" data-dsa-keep-open><button type="button" data-dsa-cart-quantity="' + escapeHtml( key ) + '"' + itemMeta + ' data-dsa-cart-next="' + escapeHtml( Math.max( 0, quantity - 1 ) ) + '" aria-label="Decrease quantity">&minus;</button><strong>' + escapeHtml( quantity || 1 ) + '</strong><button type="button" data-dsa-cart-quantity="' + escapeHtml( key ) + '"' + itemMeta + ' data-dsa-cart-next="' + escapeHtml( Math.min( maxQuantity, quantity + 1 ) ) + '" aria-label="Increase quantity"' + plusDisabled + '>+</button></span>' : '<span class="dsa-cart-panel__quantity-label dsa-quantity">Qty ' + escapeHtml( quantity || 1 ) + '</span>',
	].join( '' );

	return '<article class="dsa-cart-panel__item dsa-cart-line" data-dsa-cart-line>' + content + '</article>';
}

export function renderCart( payload ) {
	payload = payload || {};
	phonekey = { cart: payload.cart || {} };
	commerce = { settings: payload.settings || {}, routes: payload.routes || {}, complements: payload.complements || [] };
	const requestedProfile = String( payload.visualProfile || 'legacy' );
	const adapter = cartAdapters[ requestedProfile ] || cartAdapters.legacy;
	return adapter( payload.label || 'Cart', payload );
}

export function renderCheckout( payload ) {
	payload = payload || {};
	commerce = { settings: payload.settings || {}, routes: payload.routes || {}, complements: [] };
	checkoutState = payload.checkoutState || {};
	return renderCheckoutPanel();
}
