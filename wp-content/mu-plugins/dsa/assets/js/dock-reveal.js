/* Keep the initial Dock out of sight while the page and Surface load. */
( function () {
	'use strict';
	const root = document.documentElement;
	if ( window.scrollY > 0 || root.hasAttribute( 'data-dsa-dock-reveal' ) ) return;
	root.dataset.dsaDockReveal = 'waiting';

	function reveal( animate ) {
		if ( root.dataset.dsaDockReveal !== 'waiting' ) return;
		window.removeEventListener( 'scroll', onScroll );
		window.removeEventListener( 'pageshow', onScroll );
		window.removeEventListener( 'load', onLoad );
		if ( ! animate || ! window.matchMedia( '(prefers-reduced-motion: no-preference)' ).matches ) {
			delete root.dataset.dsaDockReveal;
			return;
		}
		root.dataset.dsaDockReveal = 'revealing';
		document.addEventListener( 'animationend', finish );
		document.addEventListener( 'animationcancel', finish );
	}

	function finish( event ) {
		if ( event.animationName !== 'dsa-dock-first-scroll' || ! event.target.matches( '[data-dsa-dock-cluster]' ) ) return;
		delete root.dataset.dsaDockReveal;
		document.removeEventListener( 'animationend', finish );
		document.removeEventListener( 'animationcancel', finish );
	}

	function onScroll() {
		if ( window.scrollY > 0 ) reveal( true );
	}

	function onLoad() {
		const scroller = document.scrollingElement;
		if ( scroller && scroller.scrollHeight <= scroller.clientHeight ) reveal( false );
	}

	window.addEventListener( 'scroll', onScroll, { passive: true } );
	window.addEventListener( 'pageshow', onScroll );
	window.addEventListener( 'load', onLoad, { once: true } );
	if ( document.readyState === 'complete' ) onLoad();
} )();
