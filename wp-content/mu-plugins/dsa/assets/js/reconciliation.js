const RUNTIME_BODY_CLASSES = /^(?:dsa-|kiwe-|logged-in$|admin-bar$|wp-custom-logo$)/;

function normalizeUrl( value ) {
	try {
		const url = new URL( value, window.location.href );
		url.hash = '';
		return url.href;
	} catch ( error ) {
		return '';
	}
}

function textHash( value ) {
	let hash = 2166136261;
	const text = String( value || '' );
	for ( let index = 0; index < text.length; index += 1 ) {
		hash ^= text.charCodeAt( index );
		hash = Math.imul( hash, 16777619 );
	}
	return ( hash >>> 0 ).toString( 16 ).padStart( 8, '0' );
}

function headState( documentNode ) {
	const metas = {};
	documentNode.head.querySelectorAll( 'meta[name], meta[property]' ).forEach( function ( node ) {
		const key = node.getAttribute( 'name' ) || node.getAttribute( 'property' );
		if ( key ) metas[ key.toLowerCase() ] = node.getAttribute( 'content' ) || '';
	} );
	const canonical = documentNode.head.querySelector( 'link[rel="canonical"]' );
	const html = documentNode.documentElement;
	return {
		title: documentNode.title || '',
		canonical: canonical ? normalizeUrl( canonical.href ) : '',
		metas: metas,
		lang: html ? html.getAttribute( 'lang' ) || '' : '',
		dir: html ? html.getAttribute( 'dir' ) || '' : '',
	};
}

function assetState( documentNode ) {
	const external = {};
	const inline = {};
	documentNode.querySelectorAll( 'link[rel="stylesheet"][href], script[src]' ).forEach( function ( node ) {
		const kind = node.tagName === 'LINK' ? 'style' : 'script';
		const url = normalizeUrl( node.href || node.src );
		if ( ! url ) return;
		const key = kind + ':' + url;
		external[ key ] = {
			key: key,
			kind: kind,
			url: url,
			id: node.id || '',
			module: node.getAttribute( 'type' ) === 'module',
		};
	} );
	documentNode.querySelectorAll( 'style, script:not([src])' ).forEach( function ( node, index ) {
		const kind = node.tagName === 'STYLE'
			? 'style'
			: ( node.type === 'application/ld+json' ? 'structured-data' : ( node.type === 'application/json' ? 'data' : ( /^dsa-surface-js-extra/.test( node.id || '' ) ? 'kiwe-runtime-data' : 'script' ) ) );
		const content = node.textContent || '';
		if ( ! content.trim() ) return;
		const key = kind + ':' + ( node.id || index ) + ':' + textHash( content );
		inline[ key ] = { key: key, kind: kind, id: node.id || '', bytes: content.length, hash: textHash( content ) };
	} );
	return { external: external, inline: inline };
}

function diffMap( current, next ) {
	const currentKeys = Object.keys( current );
	const nextKeys = Object.keys( next );
	return {
		retain: nextKeys.filter( function ( key ) { return Object.prototype.hasOwnProperty.call( current, key ); } ).map( function ( key ) { return next[ key ]; } ),
		add: nextKeys.filter( function ( key ) { return ! Object.prototype.hasOwnProperty.call( current, key ); } ).map( function ( key ) { return next[ key ]; } ),
		remove: currentKeys.filter( function ( key ) { return ! Object.prototype.hasOwnProperty.call( next, key ); } ).map( function ( key ) { return current[ key ]; } ),
	};
}

function bodyClassPlan( currentDocument, nextDocument ) {
	const current = Array.from( currentDocument.body.classList );
	const next = Array.from( nextDocument.body.classList );
	const preserve = current.filter( function ( name ) { return RUNTIME_BODY_CLASSES.test( name ); } );
	return {
		current: current,
		next: Array.from( new Set( next.concat( preserve ) ) ),
		preserve: preserve,
		remove: current.filter( function ( name ) { return preserve.indexOf( name ) === -1 && next.indexOf( name ) === -1; } ),
		add: next.filter( function ( name ) { return current.indexOf( name ) === -1; } ),
	};
}

function metaPlan( current, next ) {
	const keys = Array.from( new Set( Object.keys( current.metas ).concat( Object.keys( next.metas ) ) ) );
	return keys.reduce( function ( plan, key ) {
		if ( current.metas[ key ] === next.metas[ key ] ) return plan;
		plan.push( { key: key, from: current.metas[ key ] || '', to: next.metas[ key ] || '', operation: Object.prototype.hasOwnProperty.call( next.metas, key ) ? 'upsert' : 'remove' } );
		return plan;
	}, [] );
}

function parseJsonResponse( response ) {
	return response.json().catch( function () { return {}; } ).then( function ( payload ) {
		return { ok: response.ok, status: response.status, payload: payload };
	} );
}

function responseEvidence( response ) {
	const names = [ 'cache-control', 'age', 'etag', 'last-modified', 'vary', 'x-cache', 'cf-cache-status', 'content-security-policy' ];
	return names.reduce( function ( evidence, name ) {
		const value = response.headers.get( name );
		if ( value ) evidence[ name ] = value.slice( 0, 500 );
		return evidence;
	}, { status: response.status, redirected: response.redirected, url: response.url } );
}

function routeScenario( requested, envelope, nextContent ) {
	const path = requested.pathname.toLowerCase();
	if ( requested.searchParams.has( 's' ) || path.indexOf( '/search/' ) !== -1 ) return 'search';
	if ( /\/(?:category|tag|author|date)\//.test( path ) ) return 'archive';
	if ( envelope.content && envelope.content.renderer === 'bricks-2.3-contract' ) return 'bricks';
	if ( nextContent && nextContent.querySelector( 'form, input, textarea, select, button' ) ) return 'forms_or_comments';
	if ( nextContent && nextContent.querySelector( 'iframe, video, audio, embed, object' ) ) return 'embeds_or_media';
	return 'static_editorial';
}

export async function inspect( targetUrl, config ) {
	config = config || {};
	const requested = new URL( targetUrl, window.location.href );
	const target = normalizeUrl( targetUrl );
	if ( ! target || new URL( target ).origin !== window.location.origin ) {
		throw new Error( 'Reconciliation accepts same-origin destinations only.' );
	}
	if ( requested.username || requested.password ) {
		throw new Error( 'Credential-bearing destination URLs are not allowed.' );
	}
	if ( [ 'add-to-cart', 'wc-ajax', 'bricks', 'preview', 'preview_id', '_wpnonce', 'nonce' ].some( function ( key ) { return requested.searchParams.has( key ); } ) ) {
		throw new Error( 'Mutation and preview URLs cannot be inspected.' );
	}
	if ( /\/(?:cart|checkout|my-account|order-pay|order-received|wp-admin|wp-json)(?:\/|$)/i.test( requested.pathname ) || /\/wp-login\.php$/i.test( requested.pathname ) ) {
		throw new Error( 'Protected and administrative routes cannot be inspected.' );
	}

	const envelopeUrl = new URL( config.endpoint || '/wp-json/dsa/v1/editorial-envelope', window.location.origin );
	envelopeUrl.searchParams.set( 'url', target );
	const headers = { 'X-Kiwe-Reconciliation': 'observe-only' };
	if ( config.nonce ) headers[ 'X-WP-Nonce' ] = config.nonce;

	const responses = await Promise.all( [
		fetch( target, { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: headers } ),
		fetch( envelopeUrl.href, { method: 'GET', credentials: 'same-origin', headers: headers } ).then( parseJsonResponse ),
	] );
	const documentResponse = responses[0];
	const envelopeResponse = responses[1];
	if ( ! documentResponse.ok ) throw new Error( 'Destination document returned HTTP ' + documentResponse.status + '.' );

	const html = await documentResponse.text();
	const nextDocument = new DOMParser().parseFromString( html, 'text/html' );
	const envelope = envelopeResponse.payload || {};
	const currentHead = headState( document );
	const nextHead = headState( nextDocument );
	const currentAssets = assetState( document );
	const nextAssets = assetState( nextDocument );
	const externalAssets = diffMap( currentAssets.external, nextAssets.external );
	const inlineAssets = diffMap( currentAssets.inline, nextAssets.inline );
	const currentContent = document.querySelector( '#brx-content, main' );
	const nextContent = nextDocument.querySelector( '#brx-content, main' );
	const blockers = [];

	if ( ! envelopeResponse.ok || ! envelope.ok ) blockers.push( envelope.fallback && envelope.fallback.reason ? envelope.fallback.reason : 'envelope_rejected' );
	if ( ! currentContent || ! nextContent ) blockers.push( 'content_root_missing' );
	if ( envelope.content && Array.isArray( envelope.content.blockers ) ) blockers.push.apply( blockers, envelope.content.blockers );
	if ( envelope.content && envelope.content.renderer === 'bricks-2.3-contract' ) blockers.push( 'bricks_lifecycle_requires_s16' );
	if ( nextContent && nextContent.querySelector( 'form, button, input, select, textarea, details, dialog, iframe, video, audio, [contenteditable="true"]' ) ) blockers.push( 'interactive_content_requires_full_document' );
	if ( nextContent && /\sdata-[a-z0-9_-]+\s*=/i.test( nextContent.innerHTML ) ) blockers.push( 'runtime_binding_markers_require_full_document' );
	externalAssets.add.concat( externalAssets.remove ).forEach( function ( asset ) {
		blockers.push( 'external_' + asset.kind + '_change:' + ( asset.id || asset.url ) );
	} );
	inlineAssets.add.forEach( function ( asset ) {
		if ( asset.kind === 'script' || asset.kind === 'style' ) blockers.push( 'new_inline_' + asset.kind + ':' + ( asset.id || asset.hash ) );
		if ( asset.kind === 'data' && asset.id !== 'dsa-element-registry' ) blockers.push( 'new_inline_data:' + ( asset.id || asset.hash ) );
	} );
	inlineAssets.remove.forEach( function ( asset ) {
		if ( asset.kind === 'script' || asset.kind === 'style' ) blockers.push( 'removed_inline_' + asset.kind + ':' + ( asset.id || asset.hash ) );
		if ( asset.kind === 'data' && asset.id !== 'dsa-element-registry' ) blockers.push( 'removed_inline_data:' + ( asset.id || asset.hash ) );
	} );

	const plan = {
		version: 1,
		observeOnly: ! config.applyEnabled,
		applyEnabled: Boolean( config.applyEnabled ),
		target: requested.href,
		generatedAt: new Date().toISOString(),
		envelope: envelope,
		reconciliationReady: blockers.length === 0,
		morphReady: Boolean( config.applyEnabled ) && blockers.length === 0,
		blockers: Array.from( new Set( blockers ) ),
		head: {
			title: currentHead.title === nextHead.title ? null : { from: currentHead.title, to: nextHead.title },
			canonical: currentHead.canonical === nextHead.canonical ? null : { from: currentHead.canonical, to: nextHead.canonical },
			meta: metaPlan( currentHead, nextHead ),
			htmlAttributes: {
				lang: currentHead.lang === nextHead.lang ? null : { from: currentHead.lang, to: nextHead.lang },
				dir: currentHead.dir === nextHead.dir ? null : { from: currentHead.dir, to: nextHead.dir },
			},
		},
		body: { classes: bodyClassPlan( document, nextDocument ) },
		assets: { external: externalAssets, inline: inlineAssets },
		content: {
			selector: nextDocument.querySelector( '#brx-content' ) ? '#brx-content' : ( nextContent ? 'main' : '' ),
			currentHash: currentContent ? textHash( currentContent.innerHTML ) : '',
			nextHash: nextContent ? textHash( nextContent.innerHTML ) : '',
		},
		history: { operation: 'push', url: requested.href, title: nextHead.title },
		focus: { selector: '#brx-content, main', preventScroll: true },
		scroll: { mode: requested.hash ? 'anchor' : 'top', hash: requested.hash || '' },
		liveRegion: { message: nextHead.title ? nextHead.title + ' loaded.' : 'Page loaded.' },
		fallback: { mode: 'full_document', reason: blockers.length ? 'reconciliation_blocked' : 's15_apply_not_enabled' },
		safety: {
			version: 1,
			scenario: routeScenario( requested, envelope, nextContent ),
			documentResponse: responseEvidence( documentResponse ),
			envelopeStatus: envelopeResponse.status,
			expectedMode: blockers.length ? 'full_document' : ( config.applyEnabled ? 'controlled_morph' : 'observe_only' ),
		},
	};
	Object.defineProperties( plan, {
		_nextDocument: { value: nextDocument, enumerable: false },
		_nextContent: { value: nextContent, enumerable: false },
	} );

	window.dispatchEvent( new CustomEvent( 'surface:reconciliation:planned', { detail: plan } ) );
	return plan;
}

function verifyCommittedPlan( plan ) {
	const content = document.querySelector( plan.content.selector );
	const canonical = document.head.querySelector( 'link[rel="canonical"]' );
	const failures = [];
	if ( ! content ) failures.push( 'content_root_missing_after_commit' );
	if ( ! document.getElementById( 'dsa-surface' ) ) failures.push( 'surface_shell_missing_after_commit' );
	if ( plan.head.title && document.title !== plan.head.title.to ) failures.push( 'title_mismatch_after_commit' );
	if ( plan.head.canonical ) {
		const actualCanonical = canonical ? normalizeUrl( canonical.href ) : '';
		const expectedCanonical = plan.head.canonical.to ? normalizeUrl( plan.head.canonical.to ) : '';
		if ( actualCanonical !== expectedCanonical ) failures.push( 'canonical_mismatch_after_commit' );
	}
	if ( content && textHash( content.innerHTML ) !== plan.content.nextHash ) failures.push( 'content_hash_mismatch_after_commit' );
	if ( normalizeUrl( window.location.href ) !== normalizeUrl( plan.history.url ) ) failures.push( 'history_url_mismatch_after_commit' );
	return failures;
}

function reconcileHead( plan ) {
	const nextDocument = plan._nextDocument;
	if ( plan.head.title ) document.title = plan.head.title.to;

	if ( plan.head.canonical ) {
		let canonical = document.head.querySelector( 'link[rel="canonical"]' );
		if ( plan.head.canonical.to ) {
			if ( ! canonical ) {
				canonical = document.createElement( 'link' );
				canonical.rel = 'canonical';
				document.head.appendChild( canonical );
			}
			canonical.href = plan.head.canonical.to;
		} else if ( canonical ) {
			canonical.remove();
		}
	}

	plan.head.meta.forEach( function ( change ) {
		const selector = 'meta[name="' + CSS.escape( change.key ) + '"], meta[property="' + CSS.escape( change.key ) + '"]';
		const current = document.head.querySelector( selector );
		const next = nextDocument.head.querySelector( selector );
		if ( ! next ) {
			if ( current ) current.remove();
			return;
		}
		if ( current ) {
			current.setAttribute( 'content', next.getAttribute( 'content' ) || '' );
		} else {
			document.head.appendChild( document.importNode( next, true ) );
		}
	} );

	[ 'lang', 'dir' ].forEach( function ( attribute ) {
		const change = plan.head.htmlAttributes[ attribute ];
		if ( ! change ) return;
		if ( change.to ) document.documentElement.setAttribute( attribute, change.to );
		else document.documentElement.removeAttribute( attribute );
	} );

	document.head.querySelectorAll( 'script[type="application/ld+json"]' ).forEach( function ( node ) { node.remove(); } );
	nextDocument.head.querySelectorAll( 'script[type="application/ld+json"]' ).forEach( function ( node ) {
		document.head.appendChild( document.importNode( node, true ) );
	} );
}

function reconcileRegistry( nextDocument ) {
	const current = document.getElementById( 'dsa-element-registry' );
	const next = nextDocument.getElementById( 'dsa-element-registry' );
	if ( current && next ) current.textContent = next.textContent || '';
}

function commitPlan( plan ) {
	const currentContent = document.querySelector( plan.content.selector );
	if ( ! currentContent || ! plan._nextContent ) throw new Error( 'Content root disappeared before commit.' );
	reconcileHead( plan );
	document.body.className = plan.body.classes.next.join( ' ' );
	currentContent.innerHTML = plan._nextContent.innerHTML;
	reconcileRegistry( plan._nextDocument );
	if ( window.DSA_DATA && window.DSA_DATA.site && window.DSA_DATA.site.current && plan.envelope.route ) {
		window.DSA_DATA.site.current.postId = Number( plan.envelope.route.postId ) || 0;
		window.DSA_DATA.site.current.isFrontPage = false;
	}
	window.history.pushState( Object.assign( {}, window.history.state || {}, { kiweMorph: { version: 1, url: plan.target } } ), plan.history.title || '', plan.history.url );
}

export async function apply( plan ) {
	if ( ! plan || ! plan.applyEnabled || ! plan.morphReady || plan.blockers.length || ! plan._nextDocument || ! plan._nextContent ) {
		throw new Error( 'This reconciliation plan is not eligible for controlled morphing.' );
	}

	window.dispatchEvent( new CustomEvent( 'surface:morph:before', { detail: plan } ) );
	if ( typeof document.startViewTransition === 'function' && !( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) ) {
		const transition = document.startViewTransition( function () { commitPlan( plan ); } );
		await transition.finished;
	} else {
		commitPlan( plan );
	}
	const invariantFailures = verifyCommittedPlan( plan );
	plan.safety.invariantFailures = invariantFailures;
	if ( invariantFailures.length ) {
		window.dispatchEvent( new CustomEvent( 'surface:morph:invariant-failed', { detail: { plan: plan, failures: invariantFailures } } ) );
		throw new Error( invariantFailures.join( ', ' ) );
	}

	const focusTarget = document.querySelector( plan.focus.selector );
	if ( focusTarget ) {
		if ( ! focusTarget.hasAttribute( 'tabindex' ) ) focusTarget.setAttribute( 'tabindex', '-1' );
		focusTarget.focus( { preventScroll: Boolean( plan.focus.preventScroll ) } );
	}
	if ( plan.scroll.mode === 'anchor' && plan.scroll.hash ) {
		const anchor = document.getElementById( decodeURIComponent( plan.scroll.hash.slice( 1 ) ) );
		if ( anchor ) anchor.scrollIntoView();
		else window.scrollTo( 0, 0 );
	} else {
		window.scrollTo( 0, 0 );
	}
	const liveRegion = document.querySelector( '.dsa-live-region' );
	if ( liveRegion ) liveRegion.textContent = plan.liveRegion.message;
	window.dispatchEvent( new CustomEvent( 'surface:morph:complete', { detail: plan } ) );
	return plan;
}

export async function runSafetyMatrix( targetUrls, config ) {
	const urls = Array.from( new Set( ( Array.isArray( targetUrls ) ? targetUrls : [] ).filter( Boolean ).slice( 0, 12 ) ) );
	const current = new URL( window.location.href );
	const intentional = [
		{ scenario: 'intentional_mutation_failure', url: current.origin + current.pathname + '?_wpnonce=kiwe-s16-intentional-failure', expect: 'rejected' },
		{ scenario: 'intentional_admin_failure', url: current.origin + '/wp-admin/', expect: 'rejected' },
		{ scenario: 'intentional_protected_failure', url: current.origin + '/checkout/', expect: 'rejected' },
	];
	const cases = urls.map( function ( url ) { return { scenario: 'discovered_editorial', url: url, expect: 'inspect' }; } ).concat( intentional );
	const results = [];

	for ( const testCase of cases ) {
		try {
			const plan = await inspect( testCase.url, Object.assign( {}, config || {}, { applyEnabled: false } ) );
			results.push( {
				scenario: plan.safety.scenario || testCase.scenario,
				path: new URL( testCase.url, window.location.href ).pathname,
				outcome: plan.reconciliationReady ? 'ready' : 'fallback',
				blockers: plan.blockers,
				evidence: plan.safety,
			} );
		} catch ( error ) {
			results.push( {
				scenario: testCase.scenario,
				path: new URL( testCase.url, window.location.href ).pathname,
				outcome: testCase.expect === 'rejected' ? 'expected_rejection' : 'inspection_error',
				blockers: [ error && error.message ? error.message : 'unknown_error' ],
			} );
		}
	}

	return {
		version: 1,
		generatedAt: new Date().toISOString(),
		browser: {
			viewTransitions: typeof document.startViewTransition === 'function',
			crossDocumentViewTransitions: 'onpageswap' in window && 'onpagereveal' in window,
			reducedMotion: Boolean( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ),
			bfcacheEvents: 'onpageshow' in window && 'onpagehide' in window,
		},
		results: results,
	};
}
