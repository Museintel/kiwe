/* Start the one private startup read while public page assets are loading.
 * The main runtime consumes this response once; nothing is persisted or polled.
 */
( function () {
	'use strict';
	const script = document.currentScript;
	const endpoint = script && script.getAttribute( 'data-dsa-runtime-endpoint' );
	const owner = window.DSA = window.DSA || {};
	if ( ! endpoint || typeof window.fetch !== 'function' || owner.refreshRuntime || owner.startupHydration ) return;
	let url;
	try {
		url = new URL( endpoint, window.location.href );
		if ( url.origin !== window.location.origin || ! [ 'https:', 'http:' ].includes( url.protocol ) ) return;
	} catch ( error ) { return; }
	const canonical = url.toString();
	url.searchParams.set( '_dsa_rt', String( Date.now() ) + '-' + Math.floor( Math.random() * 100000 ) );
	const controller = typeof AbortController === 'function' ? new AbortController() : null;
	const entry = { endpoint: canonical, version: owner.boot && owner.boot.version, startedAt: Date.now(), response: null };
	// Settle network failure into a value before the deferred consumer exists.
	// The main owner retains its existing explicit retry and nonce recovery.
	try {
		entry.response = window.fetch( url.toString(), {
			method: 'GET', credentials: 'same-origin', cache: 'no-store',
			headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache' },
			...( controller ? { signal: controller.signal } : {} ),
		} ).then( function ( response ) { return { response: response }; }, function () { return { failed: true }; } );
	} catch ( error ) { return; }
	owner.startupHydration = entry;
	window.addEventListener( 'pagehide', function () {
		if ( owner.startupHydration === entry ) delete owner.startupHydration;
		if ( controller ) controller.abort();
	}, { once: true } );
} )();
