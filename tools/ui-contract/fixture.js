( function () {
	'use strict';

	const params = new URLSearchParams( window.location.search );
	const screen = params.get( 'screen' ) || 'cart';
	const layout = params.get( 'layout' ) || ( window.innerWidth < 540 ? 'narrow' : ( window.innerWidth < 820 ? 'compact' : 'wide' ) );
	const density = params.get( 'density' ) || ( window.innerHeight < 700 ? 'dense' : 'comfortable' );
	const theme = params.get( 'theme' ) === 'dark' ? 'dark' : 'light';
	const orientation = params.get( 'orientation' ) === 'vertical' ? 'vertical' : 'horizontal';
	const surface = document.querySelector( '[data-dsa-surface]' );
	const viewport = document.querySelector( '[data-dsa-overlay-root]' );
	const context = document.querySelector( '[data-dsa-dock-context]' );
	const contextContent = document.querySelector( '[data-dsa-dock-context-content]' );
	const dock = document.querySelector( '.dsa-phonekey-dock' );

	document.documentElement.dataset.kiweTheme = theme;
	surface.dataset.dsaLayout = layout;
	surface.dataset.dsaDensity = density;
	surface.dataset.dsaDockOrientation = orientation;
	if ( orientation === 'vertical' ) {
		surface.classList.add( 'dsa-dock-mobile-vertical-position-bottom', 'dsa-dock-desktop-vertical-position-center' );
		surface.style.setProperty( '--dsa-dock-cluster-axis-size', 'min(430px, calc(100dvh - 24px))' );
		surface.style.setProperty( '--dsa-screen-inline-reserve', '82px' );
		surface.style.setProperty( '--dsa-screen-block-reserve', '12px' );
	}

	const icons = [ 'Menu', 'Search', 'Profile', 'Links', 'AI', 'Saved', 'Cart', 'Theme' ];
	dock.innerHTML = icons.map( function ( label ) {
		return '<button class="dsa-dock__button' + ( label === 'AI' ? ' dsa-ai-launcher' : '' ) + '" type="button" aria-label="' + label + '"><span class="fixture-icon" aria-hidden="true">' + label.slice( 0, 1 ) + '</span></button>';
	} ).join( '' );

	const panels = {
		menu: '<section class="dsa-panel dsa-menu-panel dsa-hero-panel" role="dialog" aria-label="Menu"><p class="dsa-cart-panel__eyebrow">Menu</p><section class="dsa-menu-group"><h2 class="dsa-menu-group__title">Primary navigation</h2><ul class="dsa-menu-list"><li><a class="dsa-menu-link is-active" href="#"><span class="dsa-menu-link__body"><span>Home</span></span></a></li><li><a class="dsa-menu-link" href="#"><span class="dsa-menu-link__body"><span>Shop</span></span></a></li><li><a class="dsa-menu-link" href="#"><span class="dsa-menu-link__body"><span>Contact</span></span></a></li></ul></section><section class="dsa-menu-context"><h2 class="dsa-menu-context__title">Table of contents</h2><ol class="dsa-menu-context__list"><li><button type="button">Article title</button></li><li><button type="button">Ingredients</button></li><li style="--dsa-menu-depth:1"><button type="button">How to use</button></li><li><button type="button">Reviews</button></li></ol></section></section>',
		profile: '<section class="dsa-panel dsa-profile-panel" role="dialog" aria-label="Profile"><div class="dsa-panel__header"><div><strong>munaf@example.com</strong><p class="dsa-panel__meta">Verified</p></div></div><form class="dsa-profile-form"><input class="dsa-auth-field" aria-label="First name" value="Munaf"><input class="dsa-auth-field" aria-label="Last name" value="Patni"><input class="dsa-auth-field" aria-label="Email" value="munaf@example.com"><input class="dsa-auth-field" aria-label="Display name" value="Munaf"><div class="dsa-auth-actions"><button class="dsa-panel__button" type="button">Update profile</button></div></form><section class="dsa-recent-orders"><div class="dsa-recent-orders__head"><strong>Recent orders</strong><span>2</span></div><div class="dsa-recent-orders__rail"><article class="dsa-recent-order"><div><strong>#1032</strong><span class="dsa-order-status">Processing</span></div><small>Today</small><b>$130.25</b></article></div><button class="dsa-recent-orders__all" type="button">View all</button></section></section>',
		cart: '<section class="dsa-panel dsa-cart-panel" role="dialog" aria-label="Cart"><p class="dsa-cart-panel__eyebrow">Cart</p><h2>Your cart</h2><div class="dsa-cart-panel__summary"><strong>$130.25</strong><span>2 items</span></div><div class="dsa-cart-panel__items"><article class="dsa-cart-panel__item"><span class="dsa-cart-panel__image-placeholder"></span><div class="dsa-cart-panel__item-body"><strong>Lactase Enzyme 3000 FCC</strong><span>$11.00</span></div><div class="dsa-cart-panel__quantity"><button aria-label="Decrease quantity">−</button><strong>1</strong><button aria-label="Increase quantity">+</button></div></article><article class="dsa-cart-panel__item"><span class="dsa-cart-panel__image-placeholder"></span><div class="dsa-cart-panel__item-body"><strong>Vitamin D3 1000 IU</strong><span>$119.25</span></div><div class="dsa-cart-panel__quantity"><button aria-label="Decrease quantity">−</button><strong>1</strong><button aria-label="Increase quantity">+</button></div></article></div><section class="dsa-cart-fbt"><strong>Frequently Bought Together</strong><div class="dsa-cart-fbt__rail"><article class="dsa-cart-fbt__card"><span>Senna 8.6 mg</span><button type="button">Add</button></article></div></section></section>',
		links: '<section class="dsa-panel dsa-links-panel" role="dialog" aria-label="Links"><div class="dsa-links-hero"><div class="dsa-links-logo--text">K</div><div class="dsa-links-score"><span>96</span><small>Site score</small></div></div><div class="dsa-social-grid"><a class="dsa-social-link" href="#"><span>Facebook</span></a><a class="dsa-social-link" href="#"><span>Instagram</span></a><a class="dsa-social-link" href="#"><span>YouTube</span></a></div><div class="dsa-links-commerce-actions"><a class="dsa-shop-link" href="#"><span>Shop</span><small>Open store</small></a><button class="dsa-shop-link dsa-links-cart-button" type="button"><span>Cart</span><small>Open cart</small></button></div></section>',
		search: '<section class="dsa-panel dsa-search-panel" role="dialog" aria-label="Search" data-dsa-search-panel><p class="dsa-search-panel__eyebrow">Search</p><h2>Find what you need.</h2><form class="dsa-search-panel__form" data-dsa-search-form><span class="dsa-search-panel__field"><input aria-label="Search products" placeholder="Search products" data-dsa-search-input><button type="button" aria-label="Clear search" data-dsa-search-clear hidden>&times;</button></span></form><div class="dsa-search-panel__filters" data-dsa-search-filters><button class="dsa-search-filter is-active" aria-pressed="true">Products <span aria-hidden="true">&times;</span></button><button class="dsa-search-filter" aria-pressed="false">Posts</button><button class="dsa-search-filter" aria-pressed="false">Authors</button></div><div class="dsa-search-panel__alphabet" data-dsa-search-alphabet><button class="dsa-search-prefix">L</button><button class="dsa-search-prefix">M</button><button class="dsa-search-prefix">V</button></div><div class="dsa-search-panel__results" data-dsa-search-results><section class="dsa-search-group dsa-search-group--products"><h3>Products</h3><div class="dsa-search-group__items"><article class="dsa-search-result-card dsa-search-result-card--product"><a class="dsa-search-result" href="#"><span class="dsa-search-result__placeholder" aria-hidden="true"></span><span class="dsa-search-result__body"><small>Product</small><strong>Lactase Enzyme</strong><span>Useful product information remains readable.</span><em>$11.00</em></span></a><button class="dsa-search-result__add" type="button" aria-label="Add Lactase Enzyme to cart">+</button></article><article class="dsa-search-result-card dsa-search-result-card--product"><a class="dsa-search-result" href="#"><span class="dsa-search-result__placeholder" aria-hidden="true"></span><span class="dsa-search-result__body"><small>Product</small><strong>Melatonin 3mg</strong><span>Compact cards remain usable at narrow widths.</span><em>$12.00</em></span></a><button class="dsa-search-result__add" type="button" aria-label="Add Melatonin 3mg to cart">+</button></article></div></section></div><span data-dsa-search-status></span></section>',
	};

	viewport.innerHTML = panels[ screen ] || panels.cart;
	context.dataset.dsaContext = screen;
	context.dataset.dsaContextWidth = screen === 'menu' ? 'content' : 'dock';
	contextContent.innerHTML = screen === 'menu'
		? '<a class="dsa-menu-dashboard" href="#">Dashboard</a>'
		: ( screen === 'profile'
			? '<div class="dsa-account-actions"><button class="dsa-panel__button">Downloads</button><button class="dsa-panel__button">Addresses</button><button class="dsa-panel__button">Password</button></div><button class="dsa-panel__button dsa-logout-button" aria-label="Log out">↪</button>'
			: '<div class="fixture-context"><strong>' + ( screen === 'cart' ? 'Checkout' : 'Primary action' ) + '</strong><span>' + ( screen === 'cart' ? '$130.25' : 'Ready' ) + '</span></div>' );

	if ( orientation === 'vertical' ) {
		window.requestAnimationFrame( function () {
			const panel = viewport.querySelector( ':scope > .dsa-panel' ).getBoundingClientRect();
			surface.style.setProperty( '--dsa-context-left', panel.left.toFixed( 2 ) + 'px' );
			surface.style.setProperty( '--dsa-context-width', panel.width.toFixed( 2 ) + 'px' );
			surface.style.setProperty( '--dsa-context-top', Math.min( window.innerHeight - context.offsetHeight - 12, panel.bottom + 4 ).toFixed( 2 ) + 'px' );
		} );
	}
}() );
