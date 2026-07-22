( function () {
	'use strict';

	const cfg = window.kiweBricksStudio || {};
	if ( ! cfg.restRoot || ! cfg.nonce ) return;
	if ( document.getElementById( 'kiwe-bricks-studio' ) ) return;

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'className' ) node.className = attrs[ key ];
			else if ( key === 'text' ) node.textContent = attrs[ key ];
			else node.setAttribute( key, attrs[ key ] );
		} );
		( children || [] ).forEach( function ( child ) {
			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function api( route, body, method ) {
		return fetch( String( cfg.restRoot ).replace( /\/$/, '' ) + route, {
			method: method || 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: method === 'GET' ? undefined : JSON.stringify( body || {} )
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					throw new Error( ( json && json.message ) || response.statusText || 'Kiwe request failed' );
				}
				return json;
			} );
		} );
	}

	function builderSnapshot() {
		const bricksData = window.bricksData || {};
		return {
			postId: cfg.postId || bricksData.postId || 0,
			bricksVersion: bricksData.version || bricksData.bricksVersion || '',
			hasElements: Boolean( bricksData.elements ),
			elementCount: Array.isArray( bricksData.elements ) ? bricksData.elements.length : 0,
			hasQueryLoopInstances: Boolean( bricksData.queryLoopInstances ),
			queryLoopIds: Object.keys( bricksData.queryLoopInstances || {} ).slice( 0, 20 ),
			hasFilterInstances: Boolean( bricksData.filterInstances ),
			filterIds: Object.keys( bricksData.filterInstances || {} ).slice( 0, 20 )
		};
	}

	function setOutput( value ) {
		output.value = typeof value === 'string' ? value : JSON.stringify( value, null, 2 );
	}

	function requestPayload( callNative ) {
		return {
			mode: lane.value,
			lane: lane.value,
			brief: prompt.value,
			postId: cfg.postId || 0,
			callNative: Boolean( callNative ),
			builder: builderSnapshot()
		};
	}

	const root = el( 'aside', { id: 'kiwe-bricks-studio', className: 'kiwe-bricks-studio', 'aria-live': 'polite' } );
	const toggle = el( 'button', { type: 'button', className: 'kiwe-bricks-studio__toggle', text: 'Kiwe AI' } );
	const panel = el( 'div', { className: 'kiwe-bricks-studio__panel', hidden: 'hidden' } );
	const prompt = el( 'textarea', { className: 'kiwe-bricks-studio__prompt', rows: '5', placeholder: ( cfg.labels && cfg.labels.placeholder ) || 'Describe the page or section you want to build...' } );
	const lane = el( 'select', { className: 'kiwe-bricks-studio__lane' }, [
		el( 'option', { value: 'website', text: 'Website/page' } ),
		el( 'option', { value: 'combined', text: 'Website + Kiwe AppShell' } ),
		el( 'option', { value: 'dynamic', text: 'Dynamic Bricks binding' } ),
		el( 'option', { value: 'audit', text: 'Audit/review' } )
	] );
	const output = el( 'textarea', { className: 'kiwe-bricks-studio__output', rows: '12', readonly: 'readonly' } );
	const status = el( 'p', { className: 'kiwe-bricks-studio__status', text: 'Ready. Context stays local until you ask.' } );
	const contextBtn = el( 'button', { type: 'button', className: 'kiwe-bricks-studio__button', text: 'Get Bricks context' } );
	const planBtn = el( 'button', { type: 'button', className: 'kiwe-bricks-studio__button', text: 'Plan with Studio' } );
	const nativeBtn = el( 'button', { type: 'button', className: 'kiwe-bricks-studio__button kiwe-bricks-studio__button--hot', text: 'Ask native AI' } );
	const copyBtn = el( 'button', { type: 'button', className: 'kiwe-bricks-studio__button', text: 'Copy output' } );

	if ( ! cfg.nativeMode ) nativeBtn.disabled = true;

	panel.append(
		el( 'div', { className: 'kiwe-bricks-studio__head' }, [
			el( 'div', {}, [
				el( 'strong', { text: ( cfg.labels && cfg.labels.title ) || 'Kiwe Studio' } ),
				el( 'span', { text: ( cfg.labels && cfg.labels.subtitle ) || 'Bricks + Seam AI companion' } )
			] ),
			el( 'button', { type: 'button', className: 'kiwe-bricks-studio__close', text: '×', 'aria-label': 'Close Kiwe Studio' } )
		] ),
		prompt,
		el( 'div', { className: 'kiwe-bricks-studio__row' }, [ lane, contextBtn, planBtn, nativeBtn, copyBtn ] ),
		status,
		output
	);
	root.append( toggle, panel );
	document.body.appendChild( root );

	toggle.addEventListener( 'click', function () {
		panel.hidden = ! panel.hidden;
	} );
	panel.querySelector( '.kiwe-bricks-studio__close' ).addEventListener( 'click', function () {
		panel.hidden = true;
	} );

	contextBtn.addEventListener( 'click', function () {
		status.textContent = 'Reading live Bricks + Seam context...';
		api( cfg.contextRoute || '/bricks/studio/context', requestPayload( false ) ).then( function (json) {
			status.textContent = 'Context ready. This is the packet browser AI should use before building.';
			setOutput( json );
		} ).catch( function (error) {
			status.textContent = error.message;
		} );
	} );

	planBtn.addEventListener( 'click', function () {
		status.textContent = 'Creating Studio plan packet...';
		api( cfg.startRoute || '/bricks/studio/start', requestPayload( false ) ).then( function (json) {
			status.textContent = 'Studio packet ready. No model call was made.';
			setOutput( json );
		} ).catch( function (error) {
			status.textContent = error.message;
		} );
	} );

	nativeBtn.addEventListener( 'click', function () {
		if ( nativeBtn.disabled ) return;
		status.textContent = 'Calling native Kiwe AI with bounded Bricks context...';
		api( cfg.draftRoute || '/bricks/studio/draft', requestPayload( true ) ).then( function (json) {
			status.textContent = json && json.native && json.native.called ? 'Native draft returned. Review before staging.' : 'Native route returned without model call.';
			setOutput( json );
		} ).catch( function (error) {
			status.textContent = error.message;
		} );
	} );

	copyBtn.addEventListener( 'click', function () {
		output.select();
		try {
			document.execCommand( 'copy' );
			status.textContent = 'Copied.';
		} catch (error) {
			status.textContent = 'Copy failed; select the output manually.';
		}
	} );
}() );
