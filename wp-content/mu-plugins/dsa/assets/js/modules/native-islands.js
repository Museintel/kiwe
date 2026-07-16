import { store } from '@wordpress/interactivity';

const root = window.DSA = window.DSA || {};
const mirrors = root.nativeStores = root.nativeStores || {};
const aiStore = store( 'kiwe/ai' );
const appStore = store( 'kiwe/app' );
const dataStore = store( 'kiwe/data' );

function publish( key, nativeStore, detail, eventName ) {
	const next = detail && typeof detail === 'object' ? detail : {};
	Object.assign( nativeStore.state, next );
	nativeStore.state.updatedAt = new Date().toISOString();
	mirrors[ key ] = Object.assign( { namespace: 'kiwe/' + key, source: 'wordpress-interactivity' }, nativeStore.state );
	root[ key + 'Island' ] = mirrors[ key ];
	window.dispatchEvent( new CustomEvent( eventName, { detail: mirrors[ key ] } ) );
}

window.addEventListener( 'surface:ai:notifications', function ( event ) {
	publish( 'ai', aiStore, event.detail || {}, 'surface:ai:island' );
} );

window.addEventListener( 'surface:app:adoption', function ( event ) {
	publish( 'app', appStore, event.detail || {}, 'surface:app:island' );
} );

window.addEventListener( 'surface:native:data', function ( event ) {
	publish( 'data', dataStore, event.detail || {}, 'surface:data:island' );
} );
