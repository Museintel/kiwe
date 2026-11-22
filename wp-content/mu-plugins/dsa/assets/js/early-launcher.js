/* Keep the last Sheet request made before Surface installs its handlers. */
( function () {
	'use strict';
	const owner = window.DSA = window.DSA || {};
	if ( owner.earlyLauncher || owner.refreshRuntime ) return;

	const selector = 'button[data-dsa-dock-item][data-dsa-module-mode="dock"], [data-dsa-open-module]';
	const modules = [ 'menu', 'search', 'profile', 'links', 'saved', 'cart', 'comments', 'ai', 'notifications' ];
	let pending = null;
	let timer = 0;

	function usable( control ) {
		return control.isConnected && ! control.disabled && ! control.closest( '[hidden], [inert], [aria-disabled="true"]' );
	}

	function clear() {
		window.clearTimeout( timer );
		const previous = pending;
		pending = null;
		if ( previous ) previous.removeAttribute( 'data-dsa-launch-pending' );
		return previous;
	}

	function capture( event ) {
		if ( event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey ) return;
		const target = event.target;
		const control = target && typeof target.closest === 'function' ? target.closest( selector ) : null;
		if ( ! control || typeof control.click !== 'function' || ! usable( control ) ) return;
		if ( ! modules.includes( control.getAttribute( 'data-dsa-open-module' ) || control.getAttribute( 'data-dsa-module' ) ) ) return;
		const nativeControl = target.closest( 'a[href], button, input, select, textarea' );
		if ( nativeControl && nativeControl !== control ) return;
		if ( control.matches( 'input, select, textarea' ) || ( control.form && control.type !== 'button' ) ) return;
		const href = control.getAttribute( 'href' );
		if ( href && href !== '#' ) return;
		if ( control.hasAttribute( 'download' ) || control.hasAttribute( 'target' ) ) return;

		event.preventDefault();
		event.stopPropagation();
		clear();
		pending = control;
		control.setAttribute( 'data-dsa-launch-pending', '' );
		timer = window.setTimeout( clear, 20000 );
	}

	document.addEventListener( 'click', capture, true );
	window.addEventListener( 'pagehide', clear );
	owner.earlyLauncher = function ( ready ) {
		document.removeEventListener( 'click', capture, true );
		window.removeEventListener( 'pagehide', clear );
		delete owner.earlyLauncher;
		const control = clear();
		if ( ready && control && usable( control ) ) control.click();
	};
} )();
