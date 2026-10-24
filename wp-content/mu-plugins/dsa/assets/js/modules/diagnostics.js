// Requested diagnostics only; no automatic provider work or persistent state.
export function run( context ) {
	const { data, hydrationConfig, phonekey, runtimeHydrated, surface, commerce, searchConfig, presentationModuleUrls, gamesConfig, reconciliationConfig, debugConsoleEnabled, noStoreUrl, noStoreHeaders, printHealthReport } = context;
	function safeHost( url ) {
		try {
			return new URL( url, window.location.href ).host;
		} catch ( error ) {
			return '';
		}
	}

	function compactBodyPreview(text){return String(text||'').replace(/\s+/g,' ').trim().slice(0,220);}

	function probeFetch( label, url, options ) {
		const started = window.performance && window.performance.now ? window.performance.now() : Date.now();
		const requestUrl = noStoreUrl( url );
		const result = {
			label: label,
			url: requestUrl,
			host: safeHost( requestUrl ),
			ok: false,
			status: 0,
			contentType: '',
			durationMs: 0,
			code: '',
			message: '',
		};

		if ( ! requestUrl ) {
			result.message = 'missing_url';
			return Promise.resolve( result );
		}

		return fetch( requestUrl, Object.assign( {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: noStoreHeaders(),
		}, options || {} ) ).then( function ( response ) {
			result.ok = response.ok;
			result.status = response.status;
			result.contentType = response.headers ? String( response.headers.get( 'content-type' ) || '' ) : '';
			return response.text().then( function ( body ) {
				let parsed = null;
				try {
					parsed = body ? JSON.parse( body ) : null;
				} catch ( error ) {
					parsed = null;
				}
				if ( parsed && typeof parsed === 'object' ) {
					result.code = parsed.code || parsed.error || '';
					result.message = parsed.message || parsed.status || '';
					if ( parsed.count !== undefined ) result.count = parsed.count;
					if ( parsed.total !== undefined ) result.total = parsed.total;
					if ( parsed.items && parsed.items.length !== undefined ) result.items = parsed.items.length;
					if ( parsed.cart && parsed.cart.count !== undefined ) result.cartCount = parsed.cart.count;
					if ( parsed.phonekey && parsed.phonekey.user ) result.phonekeyUser = parsed.phonekey.user.id || parsed.phonekey.user.email || true;
					if ( parsed.version !== undefined ) result.responseVersion = parsed.version;
				} else {
					result.message = compactBodyPreview( body );
				}
				return result;
			} );
		} ).catch( function ( error ) {
			result.message = error && error.message ? error.message : String( error || 'fetch_failed' );
			return result;
		} ).then( function ( finalResult ) {
			const ended = window.performance && window.performance.now ? window.performance.now() : Date.now();
			finalResult.durationMs = Math.round( ended - started );
			return finalResult;
		} );
	}

	function probeModuleAsset( label, url ) {
		return probeFetch( label, url, {
			headers: { 'Accept': 'text/javascript, application/javascript, */*' },
		} ).then( function ( result ) {
			result.moduleLike = result.ok && /javascript|ecmascript|text\/plain|octet-stream/i.test( result.contentType || '' );
			return result;
		} );
	}

	function runHealthCheck() {
		const restUrl = String( data.restUrl || '' ).replace( /\/+$/, '' );
		const headers = noStoreHeaders( data.nonce ? { 'X-WP-Nonce': data.nonce } : {} );
		const report = {
			version: data.version || '',
			time: new Date().toISOString(),
			page: window.location.href,
			hosts: {
				page: safeHost( window.location.href ),
				site: data.site && data.site.homeUrl ? safeHost( data.site.homeUrl ) : '',
				rest: safeHost( restUrl ),
				hydration: hydrationConfig.endpoint ? safeHost( hydrationConfig.endpoint ) : '',
			},
			boot: {
				hasDsaData: Boolean( window.DSA_DATA ),
				hasSurface: Boolean( surface ),
				hasOverlayRoot: Boolean( surface && surface.querySelector( '[data-dsa-overlay-root]' ) ),
				hasNonce: Boolean( data.nonce ),
				hasPhoneKeyNonce: Boolean( phonekey && phonekey.nonce ),
				runtimeHydrated: runtimeHydrated,
				runtimeHydrationState: surface ? surface.dataset.dsaRuntimeHydrated || '' : '',
				cartCount: phonekey && phonekey.cart ? phonekey.cart.count : null,
				commerceAvailable: commerce.available === true,
				searchEndpoint: Boolean( searchConfig.endpoint ),
				searchModule: Boolean( searchConfig.moduleUrl ),
				presentationModules: Object.keys( presentationModuleUrls || {} ).length,
				notificationCount: surface ? surface.querySelectorAll( '[data-dsa-ai-count], .dsa-ai-count' ).length : 0,
			},
			endpoints: [],
			modules: [],
		};
		const endpointProbes = [];
		const moduleProbes = [];

		if ( hydrationConfig.endpoint ) {
			endpointProbes.push( probeFetch( 'runtime.hydrate', hydrationConfig.endpoint, { headers: headers } ) );
		}
		if ( restUrl ) {
			endpointProbes.push( probeFetch( 'rest.cart_nonce', restUrl + '/cart/nonce' ) );
			endpointProbes.push( probeFetch( 'rest.cart', restUrl + '/cart', { headers: headers } ) );
		}
		if ( searchConfig.endpoint ) {
			endpointProbes.push( probeFetch( 'rest.search', String( searchConfig.endpoint ) + '?q=a&scope=all&limit=1', { headers: headers } ) );
		}

		if ( searchConfig.moduleUrl ) {
			moduleProbes.push( probeModuleAsset( 'module.search', searchConfig.moduleUrl ) );
		}
		Object.keys( presentationModuleUrls || {} ).forEach( function ( key ) {
			moduleProbes.push( probeModuleAsset( 'module.' + key, presentationModuleUrls[ key ] ) );
		} );
		if ( gamesConfig.moduleUrl ) {
			moduleProbes.push( probeModuleAsset( 'module.games', gamesConfig.moduleUrl ) );
		}
		if ( reconciliationConfig.moduleUrl ) {
			moduleProbes.push( probeModuleAsset( 'module.reconciliation', reconciliationConfig.moduleUrl ) );
		}

		return Promise.all( [ Promise.all( endpointProbes ), Promise.all( moduleProbes ) ] ).then( function ( groups ) {
			report.endpoints = groups[0] || [];
			report.modules = groups[1] || [];
			report.ok = report.boot.hasDsaData
				&& report.boot.hasSurface
				&& report.boot.hasNonce
				&& report.endpoints.every( function ( item ) { return item.ok; } )
				&& report.modules.every( function ( item ) { return item.ok; } );
			window.DSA.lastHealthReport = report;
			if ( debugConsoleEnabled ) printHealthReport( report );
			return report;
		} );
	}


	return runHealthCheck();
}
