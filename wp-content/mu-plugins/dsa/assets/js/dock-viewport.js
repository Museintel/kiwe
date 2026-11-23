/* Run before the Dock controls, after the authored page sections. */
( function () {
	'use strict';

	const attribute = 'data-dsa-hide-dock';
	const selector = '[' + attribute + ']';
	const root = document.documentElement;
	let targets = new Set();
	let frame = 0;
	const intersects = new WeakMap();

	function render() {
		frame = 0;
		const viewport = window.visualViewport;
		const top = viewport?.offsetTop ?? 0;
		const left = viewport?.offsetLeft ?? 0;
		const bottom = top + ( viewport?.height ?? root.clientHeight );
		const right = left + ( viewport?.width ?? root.clientWidth );
		const hidden = Array.from( targets ).some( target => {
			const box = target.getBoundingClientRect();
			if ( intersects.get( target ) === false || box.width <= 0 || box.height <= 0 ||
				box.right <= left || box.left >= right || box.bottom <= top || box.top >= bottom ||
				getComputedStyle( target ).visibility !== 'visible' ) return false;

			// A covered hero releases the Dock as soon as the next section enters.
			return target.getAttribute( attribute ) !== 'cover' ||
				( box.top <= top + 1 && box.bottom >= bottom - 1 );
		} );
		root.toggleAttribute( 'data-dsa-dock-viewport-hidden', hidden );
	}

	function schedule() {
		if ( ! frame ) frame = requestAnimationFrame( render );
	}

	const intersection = typeof IntersectionObserver === 'function' ? new IntersectionObserver( entries => {
		entries.forEach( entry => intersects.set( entry.target, entry.isIntersecting ) );
		schedule();
	} ) : null;
	const resize = typeof ResizeObserver === 'function' ? new ResizeObserver( schedule ) : null;

	function refresh() {
		const next = new Set( document.querySelectorAll( selector ) );
		for ( const target of targets ) {
			if ( next.has( target ) ) continue;
			intersection?.unobserve( target );
			resize?.unobserve( target );
			intersects.delete( target );
		}
		for ( const target of next ) {
			if ( targets.has( target ) ) continue;
			intersection?.observe( target );
			resize?.observe( target );
		}
		if ( ! targets.size && next.size ) {
			window.addEventListener( 'scroll', schedule, { capture: true, passive: true } );
			window.addEventListener( 'resize', schedule, { passive: true } );
			window.visualViewport?.addEventListener( 'resize', schedule, { passive: true } );
			window.visualViewport?.addEventListener( 'scroll', schedule, { passive: true } );
		} else if ( targets.size && ! next.size ) {
			window.removeEventListener( 'scroll', schedule, true );
			window.removeEventListener( 'resize', schedule );
			window.visualViewport?.removeEventListener( 'resize', schedule );
			window.visualViewport?.removeEventListener( 'scroll', schedule );
		}
		targets = next;
		if ( frame ) cancelAnimationFrame( frame );
		render();
	}

	refresh();
	const changes = new MutationObserver( records => {
		const containsTarget = node => node.nodeType === 1 && ( node.matches( selector ) || node.querySelector( selector ) );
		if ( records.some( record => record.type === 'attributes' ||
			Array.from( record.addedNodes ).some( containsTarget ) ||
			Array.from( record.removedNodes ).some( containsTarget ) ) ) refresh();
	} );
	changes.observe( root, { subtree: true, childList: true, attributes: true, attributeFilter: [ attribute ] } );
} )();
