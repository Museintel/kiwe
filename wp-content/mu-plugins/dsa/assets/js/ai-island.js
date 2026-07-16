( function () {
	'use strict';

	const root = window.DSA = window.DSA || {};
	const store = root.nativeStores = root.nativeStores || {};
	const initial = root.aiNotifications || {};

	store.ai = {
		namespace: 'kiwe/ai',
		version: 1,
		source: 'fallback',
		actionable: Number( initial.actionable || 0 ),
		unread: Number( initial.unread || 0 ),
		total: Number( initial.total || 0 ),
		latest: initial.latest || null,
		updatedAt: '',
	};

	function update( detail ) {
		const next = detail && typeof detail === 'object' ? detail : {};
		store.ai.actionable = Number( next.actionable || 0 );
		store.ai.unread = Number( next.unread || 0 );
		store.ai.total = Number( next.total || store.ai.actionable + store.ai.unread || 0 );
		store.ai.latest = next.latest || null;
		store.ai.updatedAt = new Date().toISOString();
		root.aiIsland = store.ai;
		window.dispatchEvent( new CustomEvent( 'surface:ai:island', { detail: store.ai } ) );
	}

	window.addEventListener( 'surface:ai:notifications', function ( event ) {
		update( event.detail || {} );
	} );

	if ( initial && ( initial.total || initial.actionable || initial.unread || initial.latest ) ) {
		update( initial );
	}
}() );
