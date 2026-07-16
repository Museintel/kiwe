( function () {
	'use strict';

	const root = window.DSA = window.DSA || {};
	const store = root.nativeStores = root.nativeStores || {};
	const initial = root.nativeData || {};

	store.data = {
		namespace: 'kiwe/data',
		version: 1,
		source: 'fallback',
		site: initial.site || {},
		trust: initial.trust || {},
		profile: initial.profile || {},
		cart: initial.cart || {},
		updatedAt: '',
	};

	function update( detail ) {
		const next = detail && typeof detail === 'object' ? detail : {};
		store.data.site = next.site || store.data.site || {};
		store.data.trust = next.trust || store.data.trust || {};
		store.data.profile = next.profile || store.data.profile || {};
		store.data.cart = next.cart || store.data.cart || {};
		store.data.updatedAt = new Date().toISOString();
		root.dataIsland = store.data;
		window.dispatchEvent( new CustomEvent( 'surface:data:island', { detail: store.data } ) );
	}

	window.addEventListener( 'surface:native:data', function ( event ) {
		update( event.detail || {} );
	} );

	if ( initial && ( initial.site || initial.trust || initial.profile || initial.cart ) ) {
		update( initial );
	}
}() );
