/* Early, interaction-only reading state for the shared native-page transition.
 * No fetch, navigation interception, startup screen or default reading delay.
 */
( function () {
	'use strict';
	window.DSA = window.DSA || {};
	const key = 'dsa_transition_reader_v1';
	let held = null, destination = '', navigating = false, departing = false, incoming = false, observer = null, safety = 0;
	function forget() { try { window.sessionStorage.removeItem( key ); } catch ( error ) {} }
	function relative( url ) { try { const u = new URL( url, window.location.href ); return u.origin === window.location.origin ? u.pathname + u.search : ''; } catch ( error ) { return ''; } }
	function persist() {
		if ( ! held || ! destination ) return;
		try { window.sessionStorage.setItem( key, JSON.stringify( Object.assign( {}, held, { to: destination, at: Date.now() } ) ) ); } catch ( error ) {}
	}
	function release() {
		const wasHeld = Boolean( held );
		held = null;
		window.clearTimeout( safety ); safety = 0;
		if ( observer ) { observer.disconnect(); observer = null; }
		if ( ! departing ) forget();
		const screen = document.querySelector( '[data-dsa-loader][data-dsa-reading]' );
		if ( screen ) { screen.removeAttribute( 'data-dsa-reading' ); if ( incoming || ! navigating ) screen.hidden = true; }
		if ( wasHeld || screen ) window.dispatchEvent( new CustomEvent( 'surface:transition:release' ) );
	}
	function restore() {
		if ( ! held || ! incoming ) return;
		const screen = document.querySelector( '[data-dsa-loader]' );
		if ( ! screen ) return;
		const message = screen.querySelector( '[data-dsa-loader-message]' );
		const title = screen.querySelector( '[data-dsa-loader-title]' );
		const copy = screen.querySelector( '[data-dsa-loader-copy]' );
		if ( ! message || ! title || ! copy ) return;
		title.textContent = held.title; title.hidden = ! held.title;
		copy.textContent = held.message; copy.hidden = ! held.message;
		message.hidden = false;
		screen.classList.add( 'has-message', 'is-messages' ); screen.classList.remove( 'is-initial-preloader', 'is-animation' );
		screen.setAttribute( 'data-dsa-reading', held.kind ); screen.hidden = false;
		const label = screen.querySelector( '[data-dsa-loader-label]' ); if ( label ) label.hidden = true;
		if ( observer ) { observer.disconnect(); observer = null; }
		if ( held.kind === 'hover' ) {
			const under = document.elementFromPoint( held.x, held.y );
			if ( under && ! message.contains( under ) ) release();
		}
	}
	function receive() {
		departing = false; navigating = false; destination = ''; incoming = false;
		let saved = null;
		try { saved = JSON.parse( window.sessionStorage.getItem( key ) || 'null' ); } catch ( error ) {}
		forget();
		if ( ! saved || saved.to !== relative( window.location.href ) || Date.now() - saved.at < 0 || Date.now() - saved.at > 30000
			|| ! [ 'hover', 'press', 'focus' ].includes( saved.kind ) || typeof saved.title !== 'string' || typeof saved.message !== 'string'
			|| saved.title.length > 300 || saved.message.length > 3000 || ( ! saved.title && ! saved.message ) ) return;
		held = saved; incoming = true;
		// Only a real reading gesture creates this safety timer. It releases a
		// cancelled OS gesture which cannot deliver pointerup to the new document.
		safety = window.setTimeout( release, 120000 );
		observer = new MutationObserver( restore ); observer.observe( document.documentElement, { childList: true, subtree: true } );
		restore();
	}
	function begin( kind, event ) {
		const target = event.target;
		const screen = target && target.closest ? target.closest( '[data-dsa-loader]' ) : null;
		if ( ! screen || screen.hidden || screen.classList.contains( 'is-initial-preloader' ) ) return;
		const title = screen.querySelector( '[data-dsa-loader-title]' ), copy = screen.querySelector( '[data-dsa-loader-copy]' );
		if ( ! title || ! copy || ( ! title.textContent && ! copy.textContent ) ) return;
		held = { kind: kind, title: title.textContent.slice( 0, 300 ), message: copy.textContent.slice( 0, 3000 ), x: event.clientX || 0, y: event.clientY || 0 };
		screen.setAttribute( 'data-dsa-reading', kind ); persist();
	}
	window.DSA.transitionReader = {
		isHeld: function () { return Boolean( held ); },
		item: function () { return held; },
		start: function ( url ) { release(); incoming = false; destination = relative( url ); },
		navigate: function ( url ) { destination = relative( url ); navigating = true; persist(); },
		release: release,
		depart: function () { persist(); departing = true; held = null; },
		restore: restore,
	};
	document.addEventListener( 'pointerover', function ( event ) {
		if ( event.pointerType === 'mouse' && event.target.closest && event.target.closest( '[data-dsa-loader-message]' ) ) begin( 'hover', event );
	}, { passive: true } );
	document.addEventListener( 'pointerout', function ( event ) {
		if ( ! held || held.kind !== 'hover' ) return;
		const next = event.relatedTarget;
		if ( ! next || ! next.closest || ! next.closest( '[data-dsa-loader-message]' ) ) release();
	}, { passive: true } );
	document.addEventListener( 'pointerdown', function ( event ) {
		if ( event.isPrimary === false || event.button > 0 ) return;
		if ( incoming && held && held.kind === 'press' ) { release(); return; }
		begin( 'press', event );
	}, { passive: true } );
	function endPress() { if ( held && held.kind === 'press' ) release(); }
	document.addEventListener( 'pointerup', endPress, { passive: true } );
	document.addEventListener( 'touchend', endPress, { passive: true } );
	document.addEventListener( 'pointercancel', function () { if ( ! navigating || incoming ) endPress(); }, { passive: true } );
	document.addEventListener( 'focusin', function ( event ) { if ( event.target.matches && event.target.matches( '[data-dsa-loader-message]' ) ) begin( 'focus', event ); } );
	document.addEventListener( 'focusout', function () { if ( held && held.kind === 'focus' ) release(); } );
	document.addEventListener( 'keydown', function ( event ) { if ( event.key === 'Escape' ) release(); } );
	window.addEventListener( 'blur', function () { if ( ! navigating ) release(); } );
	window.addEventListener( 'pagehide', function () { window.DSA.transitionReader.depart(); } );
	window.addEventListener( 'pageshow', function ( event ) { if ( event.persisted ) receive(); } );
	receive();
} )();
