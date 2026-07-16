( function () {
	'use strict';

	const root = window.DSA = window.DSA || {};
	const store = root.nativeStores = root.nativeStores || {};
	const initial = root.appAdoption || {};

	store.app = {
		namespace: 'kiwe/app',
		version: 1,
		source: 'fallback',
		platform: initial.platform || '',
		browser: initial.browser || '',
		standalone: Boolean( initial.standalone ),
		installAvailable: Boolean( initial.installAvailable ),
		secureContext: Boolean( initial.secureContext ),
		manifestPresent: Boolean( initial.manifestPresent ),
		notificationPermission: initial.notificationPermission || '',
		serviceWorkerReady: Boolean( initial.serviceWorkerReady ),
		updatedAt: '',
	};

	function update( detail ) {
		const next = detail && typeof detail === 'object' ? detail : {};
		store.app.platform = next.platform || store.app.platform || '';
		store.app.browser = next.browser || store.app.browser || '';
		store.app.standalone = Boolean( next.standalone );
		store.app.installAvailable = Boolean( next.installAvailable );
		store.app.secureContext = Boolean( next.secureContext );
		store.app.manifestPresent = Boolean( next.manifestPresent );
		store.app.notificationPermission = next.notificationPermission || store.app.notificationPermission || '';
		store.app.serviceWorkerReady = Boolean( next.serviceWorkerReady );
		store.app.updatedAt = new Date().toISOString();
		root.appIsland = store.app;
		window.dispatchEvent( new CustomEvent( 'surface:app:island', { detail: store.app } ) );
	}

	window.addEventListener( 'surface:app:adoption', function ( event ) {
		update( event.detail || {} );
	} );

	if ( initial && ( initial.platform || initial.browser || initial.standalone || initial.manifestPresent ) ) {
		update( initial );
	}
}() );
