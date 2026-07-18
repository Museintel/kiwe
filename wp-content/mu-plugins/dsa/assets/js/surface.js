( function () {
	'use strict';

	const data = window.DSA_DATA || {};
	const iconSprite = String( data.iconSprite || '' );
	const initialPreloader = document.querySelector( '[data-dsa-initial-preloader]' );
	let initialPreloaderClockTimer = 0;
	let deferredInstallPrompt = null;
	let pendingPwaInstall = null;
	let pwaInstallPromptWaiter = null;
	const visual = Object.assign( {
		blur_type: 'gaussian',
		loader_type: 'orb-chase',
		show_on_overlay_open: true,
		show_on_navigation: true,
		show_on_page_in: false,
		show_on_page_out: true,
		min_loader_ms: 700,
		artificial_delay_ms: 0,
	}, data.visual || {} );
	const hapticConfig = Object.assign( {
		enabled: true,
		vibration_enabled: true,
		sound_enabled: true,
		sound_profile: 'soft',
		context: 'both',
		events: { buttons: true, quantity: true, swipe_back: true, notifications: true },
	}, data.haptic || {} );
	hapticConfig.events = Object.assign( { buttons: true, quantity: true, swipe_back: true, notifications: true }, hapticConfig.events || {} );
	const theme = Object.assign( {
		active_color: '#8f8f98',
		hover_color: '#24c6a1',
		hero_text_color: 'rgba(20,24,34,0.18)',
		confetti_color_source: 'hero',
	}, data.theme || {} );
	const styleConfig = Object.assign( {
		screen_heading_tag: 'h2',
	}, data.style || {} );
	const appConfig = data.app || {};
	const hydrationConfig = data.hydration || {};
	const searchConfig = data.search || {};
	const presentationModuleUrls = data.presentationModules || {};
	const presentationModules = new Map();
	const presentationModulePromises = new Map();
	const surfaceReturnKey = 'dsa_surface_return';
	const gamesConfig = data.games || {};
	const aiConfig = data.ai || {};
	const dockSettings = data.dock || {};
	const secure = data.secure || {};
	const trust = data.trust || {};
	const protectedFlow = data.protectedFlow || {};
	const commerce = data.commerce || {};
	const metricsConfig = data.metrics || {};
	const permissionsConfig = data.permissions || {};
	const notificationConfig = data.notificationPreferences || {};
	const pwaConfig = data.pwa || {};
	const surfaceTriggers = data.surfaceTriggers || {};
	const moduleContract = data.modules || {};
	const native = data.native || {};
	const navigationContract = data.navigation || {};
	const reconciliationConfig = navigationContract.reconciliation || {};
	const routePolicy = navigationContract.policy || {};
	const viewTransitionIntentKey = 'kiwe_editorial_view_transition';
	const designTokens = data.designTokens || {};
	const kiweTokens = data.kiweTokens || data.seamTokens || {};
	let linksHub = data.links || {};
	const savedStorageKey = 'dsa_saved_items_v2';
	let savedItems = [];
	const phonekey = data.phonekey || {};
	let cartStateInitialized = Boolean( phonekey.cart && typeof phonekey.cart.count !== 'undefined' );
	let firstCartConfettiPlayedForCart = false;
	let firstCartConfettiQueued = false;
	const phonekeyConfig = phonekey.config || {};
	let runtimeHydrated = false;
	let runtimeHydrationPromise = Promise.resolve( false );
	const debugConfig = data.debug || {};
	const debugEnabled = Boolean( debugConfig.enabled );
	const debugConsoleEnabled = Boolean( debugConfig.console );
	const surface = document.querySelector( '[data-dsa-surface]' );
	if ( surface ) {
		surface.classList.toggle( 'dsa-has-commerce', commerce.available === true );
	}
	const registryNode = document.getElementById( 'dsa-element-registry' );
	const scrim = document.querySelector( '[data-dsa-scrim]' );
	let currentActiveMode = '';
	let activeOverlayMode = '';
	let activeOverlayModuleId = '';
	let activeOverlayPanel = '';
	let activeOverlayLifecycle = null;
	let overlayContentSequence = 0;
	let surfaceLifecycleSequence = 0;
	let surfaceScrollY = 0;
	let overlayVisibilityToken = 0;
	let surfaceGeometryFrame = 0;
	let surfaceHistoryActive = false;
	let surfaceHistoryClosing = false;
	let surfaceHistorySuppressPop = false;
	let morphDocumentActive = Boolean( window.history.state && window.history.state.kiweMorph );
	let searchModulePromise = null;
	let reconciliationModulePromise = null;
	const fragmentSafetyLedgerKey = 'kiwe:s16:fragment-safety';
	const modePriority = {
		protected: 1,
		game: 2,
		transition: 3,
		dock: 4,
		appsiteHome: 5,
	};

	window.DSA = window.DSA || {};
	window.DSA.debug = debugEnabled;
	window.DSA.diagnostics = {
		enabled: debugEnabled,
		console: debugConsoleEnabled,
		wpDebugActive: Boolean( debugConfig.wpDebugActive ),
	};
	const uiAdapterScreens = Object.freeze( [
		'profile',
		'menu',
		'saved',
		'cart',
		'search',
		'links',
		'notifications',
		'ios-install',
		'ai',
	] );
	function currentVisualProfile() {
		const profile = surface && surface.dataset.dsaVisualProfile ? String( surface.dataset.dsaVisualProfile ) : 'legacy';
		return profile === 'prototype' || profile === 'kiwe2027' || profile === 'kiwe-2027' ? 'kiwe2027' : 'legacy';
	}
	function withUiProfile( payload ) {
		return Object.assign( {}, payload || {}, { visualProfile: currentVisualProfile() } );
	}
	function seamUiBridge() {
		return {
			landmarks: function ( scope, filters ) {
				return window.Seam && typeof window.Seam.landmarks === 'function'
					? window.Seam.landmarks( scope || overlayRoot || document, filters || {} )
					: [];
			},
			describe: function ( element ) {
				return window.Seam && typeof window.Seam.describe === 'function' ? window.Seam.describe( element ) : {};
			},
			adoption: function ( role ) {
				return window.Seam && typeof window.Seam.adoption === 'function' ? window.Seam.adoption( role ) : {};
			},
			activePanel: function () {
				const panel = overlayRoot ? overlayRoot.querySelector( ':scope > [role="dialog"], :scope > .dsa-panel' ) : null;
				return panel && window.Seam && typeof window.Seam.describe === 'function' ? window.Seam.describe( panel ) : {};
			},
		};
	}
	function syncUiRegistry() {
		window.DSA.ui = Object.assign( {}, window.DSA.ui || {}, {
			contract: 'kiwe.surface-ui.v2',
			adapterVersion: 1,
			visualProfile: currentVisualProfile(),
			adapterScreens: uiAdapterScreens.slice(),
			colorModel: {
				active: theme.active_color || '',
				hover: theme.hover_color || '',
			},
			getVisualProfile: currentVisualProfile,
			withProfile: withUiProfile,
			seam: seamUiBridge(),
		} );
		return window.DSA.ui;
	}
	function debugLog( label, details ) {
		if ( ! debugEnabled || ! debugConsoleEnabled || ! window.console || ! window.console.log ) {
			return;
		}

		window.console.log( '[Kiwe DSA] ' + label, redactDebugDetails( details || {} ) );
	}

	function redactDebugDetails( value, depth ) {
		depth = Number( depth || 0 );
		if ( depth > 5 ) return '[depth-limit]';
		if ( value === null || value === undefined ) return value;
		if ( Array.isArray( value ) ) {
			return value.slice( 0, 24 ).map( function ( item ) {
				return redactDebugDetails( item, depth + 1 );
			} );
		}
		if ( typeof value !== 'object' ) return value;
		const redacted = {};
		Object.keys( value ).slice( 0, 60 ).forEach( function ( key ) {
			const normalized = String( key || '' ).toLowerCase();
			if ( /(?:authorization|code|credential|currentpassword|email|identifier|nonce|otp|passcode|password|phone|secret|token)/.test( normalized ) ) {
				redacted[ key ] = '[redacted]';
				return;
			}
			redacted[ key ] = redactDebugDetails( value[ key ], depth + 1 );
		} );
		return redacted;
	}

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

	function printHealthReport( report ) {
		if ( ! window.console ) return;
		if ( window.console.groupCollapsed ) window.console.groupCollapsed( '[Kiwe DSA] health report ' + report.version );
		if ( window.console.log ) window.console.log( report );
		if ( window.console.table ) {
			window.console.table( report.endpoints || [] );
			window.console.table( report.modules || [] );
		}
		if ( window.console.groupEnd ) window.console.groupEnd();
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

	window.DSA.healthCheck = runHealthCheck;
	window.DSA.report = runHealthCheck;
	window.DSA.printHealthReport = printHealthReport;

	function noStoreUrl( url ) {
		let target;
		try {
			target = new URL( String( url || '' ), window.location.href );
		} catch ( error ) {
			return String( url || '' );
		}

		target.searchParams.set( '_dsa_rt', String( Date.now() ) + '-' + Math.floor( Math.random() * 100000 ) );
		return target.toString();
	}

	function noStoreHeaders( headers ) {
		return Object.assign( {
			'Accept': 'application/json',
			'Cache-Control': 'no-cache, no-store, must-revalidate',
			'Pragma': 'no-cache',
		}, headers || {} );
	}

	function runtimeHeaders( headers ) {
		const result = noStoreHeaders( headers );
		if ( data.nonce ) result['X-WP-Nonce'] = data.nonce;
		return result;
	}

	function hydrateRuntime( retried ) {
		if ( ! hydrationConfig.endpoint ) {
			runtimeHydrated = true;
			return Promise.resolve( false );
		}
		return fetch( noStoreUrl( hydrationConfig.endpoint ), {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: runtimeHeaders(),
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				if ( ! retried && ( response.status === 401 || response.status === 403 ) ) {
					return refreshRestNonce().then( function () { return hydrateRuntime( true ); } );
				}
				throw new Error( 'Runtime hydration was not accepted.' );
			}
			return response.json();
		} ).then( function ( payload ) {
			data.nonce = payload.nonce || '';
			if ( data.nonce ) {
				searchConfig.nonce = data.nonce;
			}
			if ( payload.phonekey ) {
				if ( payload.phonekey.config ) Object.assign( phonekeyConfig, payload.phonekey.config );
				Object.assign( phonekey, payload.phonekey );
				phonekey.config = phonekeyConfig;
			}
			if ( payload.protectedFlow ) Object.assign( protectedFlow, payload.protectedFlow );
			if ( payload.commerce ) Object.assign( commerce, payload.commerce );
			if ( payload.ai ) Object.assign( aiConfig, payload.ai );
			if ( payload.secure ) Object.assign( secure, payload.secure );
			if ( payload.dock ) Object.assign( dockSettings, payload.dock );
			if ( payload.style ) Object.assign( styleConfig, payload.style );
			if ( payload.links ) Object.assign( linksHub, payload.links );
			if ( payload.nativeData ) {
				data.nativeData = Object.assign( data.nativeData || {}, payload.nativeData );
				window.dispatchEvent( new CustomEvent( 'surface:native:data', { detail: data.nativeData } ) );
			}
			cartStateInitialized = true;
			runtimeHydrated = true;
			updateDockBadge( 'profile', Number( phonekey.user && phonekey.user.badgeCount ) || 0 );
			updateDockBadge( 'cart', Number( phonekey.cart && phonekey.cart.count ) || 0 );
			const profileButton = surface ? surface.querySelector( '[data-dsa-module="profile"]' ) : null;
			if ( profileButton ) profileButton.hidden = payload.dock && payload.dock.phonekey_visible === false;
			if ( surface ) {
				const visibleMain = Array.from( surface.querySelectorAll( '.dsa-dock [data-dsa-module]' ) ).filter( function ( button ) {
					return ! button.hidden && button.getAttribute( 'data-dsa-module' ) !== 'ai';
				} ).length;
				surface.dataset.dsaDockItemCount = String( visibleMain );
				surface.style.setProperty( '--dsa-dock-item-count', String( visibleMain ) );
			}
			if ( surface ) surface.dataset.dsaRuntimeHydrated = 'true';
			window.dispatchEvent( new CustomEvent( 'surface:runtime:hydrated', { detail: { version: payload.version || 1 } } ) );
			return true;
		} ).catch( function ( error ) {
			runtimeHydrated = true;
			debugLog( 'runtime hydration failed', { message: error.message || String( error ) } );
			if ( surface ) surface.dataset.dsaRuntimeHydrated = 'failed';
			return false;
		} );
	}

	window.DSA.refreshRuntime = function () {
		runtimeHydrated = false;
		runtimeHydrationPromise = hydrateRuntime();
		return runtimeHydrationPromise;
	};

	function lucideIcon( name, className ) {
		if ( ! iconSprite ) return '';
		return '<svg class="' + escapeHtml( className || 'dsa-context-icon' ) + ' dsa-lucide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="' + escapeHtml( iconSprite + '#' + String( name || '' ) ) + '"></use></svg>';
	}

	debugLog( 'boot', {
		version: data.version || '',
		cartCount: phonekey && phonekey.cart ? phonekey.cart.count : null,
		idleHomeEnabled: Boolean( appConfig && appConfig.idle && appConfig.idle.enabled ),
		triggers: surfaceTriggers && surfaceTriggers.rules ? surfaceTriggers.rules : [],
	} );
	if ( debugConsoleEnabled ) {
		window.setTimeout( function () {
			runHealthCheck().catch( function ( error ) {
				debugLog( 'health check failed', { message: error && error.message ? error.message : String( error || '' ) } );
			} );
		}, 900 );
	}
	runtimeHydrationPromise = hydrateRuntime();
	window.addEventListener( 'beforeinstallprompt', function ( event ) {
		event.preventDefault();
		deferredInstallPrompt = event;
		window.DSA.deferredInstallPrompt = event;
		recordMetric( 'pwa_prompt_available' );
		if ( typeof pwaInstallPromptWaiter === 'function' ) {
			pwaInstallPromptWaiter( event );
		}
		publishAppAdoptionState();
	} );
	window.addEventListener( 'appinstalled', function () {
		recordMetric( 'pwa_installed', platformContext() );
		recordPermissionOutcome( 'accepted', 'pwa_install' );
		deferredInstallPrompt = null;
		window.DSA.deferredInstallPrompt = null;
		publishAppAdoptionState();
		showPwaInstalledNotification( true );
		announce( 'App installed.' );
	} );
	applyThemeVariables();
	initializeColorMode();
	bindInitialPreloader();

	if ( ! surface ) {
		return;
	}

	const overlayRoot = surface.querySelector( '[data-dsa-overlay-root]' );
	const dockContext = surface.querySelector( '[data-dsa-dock-context]' );
	const dockContextContent = surface.querySelector( '[data-dsa-dock-context-content]' );
	const aiPopout = surface.querySelector( '[data-dsa-ai-popout]' );
	if ( aiPopout && document.body && aiPopout.parentNode !== document.body ) {
		document.body.appendChild( aiPopout );
	}
	const loader = surface.querySelector( '[data-dsa-loader]' );
	const loaderMessage = surface.querySelector( '[data-dsa-loader-message]' );
	const loaderTitle = surface.querySelector( '[data-dsa-loader-title]' );
	const loaderCopy = surface.querySelector( '[data-dsa-loader-copy]' );
	const loaderLabel = surface.querySelector( '[data-dsa-loader-label]' );
	const liveRegion = surface.querySelector( '.dsa-live-region' );
	let lastFocusedElement = null;
	let overlayCloseTimer = 0;
	let dockContextPlacements = [];
	let registry = { elements: [], count: 0 };
	let navigationInFlight = false;
	let loaderStartedAt = 0;
	let pendingFullNavigationUrl = '';
	let pendingFullNavigationTimer = 0;
	let pendingFullNavigationReady = false;
	let navigationSafetyTimer = 0;
	let fragmentAbortController = null;
	let activeGame = null;
	let gameEnginePromise = null;
	let gameFrame = 0;
	let gameAttempts = 0;
	let gameBestScore = 0;
	let gameStarting = false;
	let gameCurrentId = '';
	let gameStartedAt = 0;
	let gameRewardSession = null;
	let scheduledGameTimer = 0;
	let loaderHoverHold = false;
	let loaderHidePending = false;
	let loaderHideTimer = 0;
	let loaderHoverGraceTimer = 0;
	let preloaderClockTimer = 0;
	let cartRefreshTimers = [];
	let cartRefreshMutationPending = false;
	let cartMutationQueue = Promise.resolve();
	let cartRequestSequence = 0;
	let cartAppliedSequence = 0;
	let aiInsights = [];
	let aiInsightMemory = loadAiInsightMemory();
	let aiNotificationHistory = loadAiNotificationHistory();
	let aiNotificationQueue = [];
	let aiPopoutTimer = 0;
	let aiPopoutAnimationTimer = 0;
	let aiPopoutInsightId = '';
	let aiPopoutContext = '';
	let aiPopoutClosing = false;
	let aiPopoutLocked = false;
	let aiTrayOpen = false;
	let socialDockTimer = 0;
	let checkoutDraftTimer = 0;
	let checkoutErrorOpenTimer = 0;
	let checkoutPageDraftTimer = 0;
	let checkoutRequestSequence = 0;
	let checkoutAppliedSequence = 0;
	let checkoutPageDraftValues = {};
	let checkoutPageHydrating = false;
	let checkoutState = {
		contract: null,
		errors: {},
		notices: [],
		loading: false,
		returnToPage: false,
	};
	let phonekeyState = {
		token: '',
		mode: '',
		name: '',
		identifier: '',
		identifierType: '',
		loginToken: '',
		error: '',
		canEmailRecovery: false,
		hasTotp: false,
		hasBackup: false,
		emailDelivery: 'magic_link',
		emailAccepted: null,
		otpResendLockedUntil: 0,
		adminPhoneBinding: false,
	};
	let permissionAskSessionCount = permissionSessionAskCount();
	let notificationPreferences = loadNotificationPreferenceDraft();
	let notificationIdentityIntent = '';
	let notificationJourneyContext = notificationPreferences.context || '';
	let appPhoneKeyGate = false;
	let notificationSetupGate = false;
	let feedbackAudioContext = null;
	let lastFeedbackAt = 0;

	document.documentElement.classList.add( 'dsa-effect-blur-' + String( visual.blur_type || 'gaussian' ) );
	document.documentElement.classList.add( 'dsa-effect-loader-' + String( visual.loader_type || 'orb-chase' ) );
	document.documentElement.classList.toggle( 'dsa-protected-flow-active', isProtectedFlowActive() );

	if ( registryNode && registryNode.textContent ) {
		try {
			registry = JSON.parse( registryNode.textContent );
		} catch ( error ) {
			registry = { elements: [], count: 0, error: 'parse_failed' };
		}
	}

	window.DSA.registry = registry;
	window.DSA.feedback = surfaceFeedback;
	window.DSA.getElementsByType = function ( type ) {
		return ( window.DSA.registry.elements || [] ).filter( function ( element ) {
			return element.type === type;
		} );
	};
	window.DSA.getAiVisibleElements = function () {
		return ( window.DSA.registry.elements || [] ).filter( function ( element ) {
			return element.aiVisible !== false;
		} );
	};
	window.DSA.getEditableElements = function () {
		return ( window.DSA.registry.elements || [] ).filter( function ( element ) {
			return element.editable === true;
		} );
	};
	window.DSA.getReplacementImageTargets = function () {
		return ( window.DSA.registry.elements || [] ).filter( function ( element ) {
			return element.type === 'image' && element.editable === true && element.bricksType !== 'icon';
		} );
	};
	window.DSA.boot = Object.assign( {}, window.DSA.boot || {}, {
		assetLoaded: true,
		version: data.version || ( window.DSA.boot && window.DSA.boot.version ) || null,
	} );
	window.DSA.trust = trust;
	window.DSA.protectedFlow = protectedFlow;
	window.DSA.commerce = commerce;
	window.DSA.cart = Object.assign( {}, window.DSA.cart || {}, { addProduct: addProductToCart } );
	window.DSA.surfaceTriggers = surfaceTriggers;
	window.DSA.modules = moduleContract;
	window.DSA.native = native;
	window.DSA.routePolicy = routePolicy;
	window.DSA.designTokens = designTokens;
	window.DSA.kiweTokens = kiweTokens;
	window.DSA.seamTokens = kiweTokens;
	window.DSA.routeState = null;
	syncUiRegistry();
	window.DSA.surfaceLifecycle = {
		version: 1,
		current: null,
	};
	window.DSA.classifyRoute = function ( target ) {
		return classifyNavigationTarget( target );
	};
	mountProtectedFlowRail();
	if ( isProtectedFlowActive() ) {
		recordMetric( 'protected_flow_view', protectedFlow.context || 'protected' );
	}

	function announce( message ) {
		if ( liveRegion ) {
			liveRegion.textContent = message;
		}
	}

	function mountProtectedFlowRail() {
		if ( ! protectedFlow.railEnabled || ! isProtectedFlowActive() || ! surface || surface.querySelector( '[data-dsa-protected-rail]' ) ) {
			return;
		}

		const rail = document.createElement( 'aside' );
		rail.className = 'dsa-protected-rail';
		rail.dataset.dsaProtectedRail = '1';
		rail.dataset.dsaKeepOpen = '1';
		rail.setAttribute( 'aria-label', 'Protected checkout and account trust' );
		rail.innerHTML = renderProtectedFlowRail();
		surface.appendChild( rail );
	}

	function renderProtectedFlowRail() {
		const badges = protectedTrustBadges();
		const context = protectedFlow.context ? protectedFlow.context.replace( /_/g, ' ' ) : 'protected';
		const message = protectedFlow.message || 'This flow is protected by Kiwe trust rules.';

		return [
			'<div class="dsa-protected-rail__copy">',
			'<span class="dsa-protected-rail__kicker">' + escapeHtml( context ) + '</span>',
			'<strong>' + escapeHtml( message ) + '</strong>',
			'</div>',
			badges.length ? '<div class="dsa-protected-rail__badges">' + badges.map( function ( badge ) {
				return '<span class="dsa-protected-badge' + ( badge.active ? ' is-active' : '' ) + '"><i aria-hidden="true"></i>' + escapeHtml( badge.label ) + '</span>';
			} ).join( '' ) + '</div>' : '',
		].join( '' );
	}

	function protectedTrustBadges() {
		const badges = [];

		if ( trust.ssl ) {
			const sslProvider = trust.ssl.provider || 'active SSL';
			badges.push( {
				active: Boolean( trust.ssl.active ),
				label: sslProvider === 'active SSL' ? 'SSL active' : 'SSL by ' + sslProvider,
			} );
		}

		if ( trust.phonekey ) {
			badges.push( {
				active: Boolean( trust.phonekey.active ),
				label: 'Secure login by ' + ( trust.phonekey.label || 'Kiwe Key' ),
			} );
		}

		if ( trust.payment ) {
			badges.push( {
				active: Boolean( trust.payment.active ),
				label: 'Payment protected by ' + ( trust.payment.label || 'your payment provider' ),
			} );
		}

		return badges;
	}

	function requiredStandaloneGateActive() {
		if ( ! isStandaloneApp() || ! overlayRoot || overlayRoot.hidden ) return false;
		return Boolean(
			( appPhoneKeyGate && overlayRoot.querySelector( '[data-dsa-phonekey-auth]' ) )
			|| ( notificationSetupGate && overlayRoot.querySelector( '[data-dsa-notification-panel]' ) )
		);
	}

	function closeOverlay( force, options ) {
		options = options || {};
		if ( ! overlayRoot || overlayRoot.hidden ) {
			reconcileInactiveOverlayState();
			return;
		}
		if ( ! force && requiredStandaloneGateActive() ) {
			announce( appPhoneKeyGate ? 'Sign in and verify to continue in the Appsite.' : 'Save your notification choice to continue.' );
			return;
		}

		const closingMode = activeOverlayMode;
		const closingModuleId = activeOverlayModuleId;
		const finalizeClose = function () {
			window.clearTimeout( overlayCloseTimer );
			cleanupSheetInteractionState();
			overlayRoot.classList.remove( 'is-closing' );
			overlayRoot.hidden = true;
			overlayRoot.innerHTML = '';
			overlayContentSequence += 1;
			clearDockContextRail();
			activeOverlayMode = '';
			activeOverlayModuleId = '';
			activeOverlayPanel = '';
			activeOverlayLifecycle = null;
			window.DSA.surfaceLifecycle.current = null;
			overlayRoot.classList.remove( 'has-ai-panel' );
			setDockModuleActive( '' );
			setOverlayActive( false );
			clearSurfaceMode( closingMode );
			document.removeEventListener( 'keydown', onOverlayKeydown );
			announce( 'Surface panel closed.' );
			recordMetric( 'dock_close', closingModuleId || closingMode || 'overlay' );
			if ( ! options.fromHistory && ! options.retainHistory ) releaseSurfaceHistoryEntry();
			syncDockContextRail();
			if ( lastFocusedElement && typeof lastFocusedElement.focus === 'function' ) lastFocusedElement.focus( { preventScroll: true } );
		};
		dispatchSurfaceLifecycle( 'suspend', { reason: force ? 'force_close' : 'close' } );
		dispatchSurfaceLifecycle( 'destroy', { reason: force ? 'force_close' : 'close' } );
		stopGame();
		if ( options.immediate || window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			finalizeClose();
			return;
		}
		overlayRoot.classList.add( 'is-closing' );
		const closeMs = surface && surface.classList.contains( 'dsa-theme-sheet' )
			? Math.max( 120, Math.min( 900, parseFloat( window.getComputedStyle( surface ).getPropertyValue( '--dsa-sheet-duration' ) ) || 320 ) )
			: 280;
		overlayCloseTimer = window.setTimeout( finalizeClose, closeMs );
	}

	function usesSheetPresentation() {
		return Boolean( surface && ( surface.dataset.dsaTheme === 'sheet' || surface.classList.contains( 'dsa-theme-sheet' ) ) );
	}

	function reconcileInactiveOverlayState() {
		if ( overlayRoot && ! overlayRoot.hidden ) {
			const livePanel = overlayRoot.querySelector( ':scope > [role="dialog"], :scope > .dsa-panel' );
			if ( livePanel ) {
				return;
			}

			cleanupSheetInteractionState();
			overlayRoot.classList.remove( 'is-closing', 'has-ai-panel' );
			overlayRoot.hidden = true;
			overlayRoot.innerHTML = '';
			overlayContentSequence += 1;
			activeOverlayMode = '';
			activeOverlayModuleId = '';
			activeOverlayPanel = '';
			activeOverlayLifecycle = null;
			window.DSA.surfaceLifecycle.current = null;
			setDockModuleActive( '' );
			document.removeEventListener( 'keydown', onOverlayKeydown );
		}

		if ( loader && ! loader.hidden ) {
			return;
		}

		if ( document.documentElement.classList.contains( 'dsa-overlay-active' ) ) {
			setOverlayActive( false );
		} else if ( scrim && scrim.classList.contains( 'is-visible' ) ) {
			scrim.classList.remove( 'is-visible' );
			scrim.hidden = true;
		}

		if ( currentActiveMode === 'dock' || currentActiveMode === 'game' || currentActiveMode === 'transition' ) {
			clearSurfaceMode( currentActiveMode );
		}
	}

	function prepareSheetPanel() {
		if ( ! usesSheetPresentation() || ! overlayRoot || overlayRoot.hidden ) return;
		const panel = overlayRoot.querySelector( ':scope > [role="dialog"]' );
		if ( ! panel || panel.dataset.dsaSheetReady === '1' ) return;
		panel.dataset.dsaSheetReady = '1';
		const grabber = document.createElement( 'button' );
		grabber.type = 'button';
		grabber.className = 'dsa-sheet-grabber';
		grabber.dataset.dsaSheetGrabber = '1';
		grabber.dataset.dsaKeepOpen = '1';
		grabber.setAttribute( 'aria-label', 'Close sheet' );
		grabber.innerHTML = '<span aria-hidden="true"></span>';
		grabber.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			if ( grabber.dataset.dsaSuppressClick === '1' ) return;
			closeOverlay();
		} );
		panel.insertBefore( grabber, panel.firstChild );
		bindSheetDrag( panel, grabber );
	}

	function cleanupSheetInteractionState() {
		if ( ! overlayRoot ) return;
		overlayRoot.querySelectorAll( '.is-sheet-dragging' ).forEach( function ( panel ) {
			panel.classList.remove( 'is-sheet-dragging' );
			panel.style.transform = '';
		} );
		overlayRoot.querySelectorAll( '[data-dsa-sheet-grabber]' ).forEach( function ( handle ) {
			delete handle.dataset.dsaSuppressClick;
		} );
	}

	function bindSheetDrag( panel, handle ) {
		let active = false;
		let pointerId = null;
		let startX = 0;
		let startY = 0;
		let distance = 0;
		const position = surface && surface.dataset.dsaSheetPosition ? surface.dataset.dsaSheetPosition : 'bottom';
		const threshold = function () {
			const axis = position === 'bottom' ? ( window.innerHeight || 720 ) : ( window.innerWidth || 1024 );
			return Math.max( 56, Math.min( 150, Math.round( axis * 0.14 ) ) );
		};
		const transformFor = function ( value ) {
			if ( position === 'right' ) return 'translate3d(' + value + 'px,0,0)';
			if ( position === 'left' ) return 'translate3d(' + ( -value ) + 'px,0,0)';
			return 'translate3d(0,' + value + 'px,0)';
		};
		const start = function ( event ) {
			if ( event.button !== undefined && event.button !== 0 ) return;
			active = true;
			pointerId = event.pointerId;
			startX = event.clientX || 0;
			startY = event.clientY || 0;
			distance = 0;
			panel.classList.add( 'is-sheet-dragging' );
			delete handle.dataset.dsaSuppressClick;
			if ( handle.setPointerCapture && pointerId !== undefined ) handle.setPointerCapture( pointerId );
		};
		const move = function ( event ) {
			if ( ! active ) return;
			const dx = ( event.clientX || 0 ) - startX;
			const dy = ( event.clientY || 0 ) - startY;
			distance = position === 'right' ? Math.max( 0, dx ) : ( position === 'left' ? Math.max( 0, -dx ) : Math.max( 0, dy ) );
			if ( distance > 4 ) event.preventDefault();
			panel.style.transform = transformFor( Math.round( distance ) );
		};
		const finish = function () {
			if ( ! active ) return;
			if ( handle.releasePointerCapture && pointerId !== null && pointerId !== undefined ) {
				try {
					if ( ! handle.hasPointerCapture || handle.hasPointerCapture( pointerId ) ) {
						handle.releasePointerCapture( pointerId );
					}
				} catch ( error ) {}
			}
			active = false;
			pointerId = null;
			panel.classList.remove( 'is-sheet-dragging' );
			panel.style.transform = '';
			const dismissed = distance >= threshold();
			if ( distance > 4 ) {
				handle.dataset.dsaSuppressClick = '1';
				window.setTimeout( function () { delete handle.dataset.dsaSuppressClick; }, 0 );
			}
			if ( dismissed ) closeOverlay();
			distance = 0;
		};
		handle.addEventListener( 'pointerdown', start );
		handle.addEventListener( 'pointermove', move );
		handle.addEventListener( 'pointerup', finish );
		handle.addEventListener( 'pointercancel', finish );
	}

	function surfaceHistoryState( moduleId ) {
		const state = window.history.state && typeof window.history.state === 'object'
			? Object.assign( {}, window.history.state )
			: {};
		state.kiweSurface = {
			version: 1,
			module: String( moduleId || '' ),
		};
		return state;
	}

	function claimSurfaceHistoryEntry( moduleId ) {
		if ( requiredStandaloneGateActive() || ! window.history || typeof window.history.pushState !== 'function' ) {
			return;
		}

		if ( surfaceHistoryActive ) {
			try {
				window.history.replaceState( surfaceHistoryState( moduleId ), '', window.location.href );
			} catch ( error ) {
				debugLog( 'surface history replace unavailable', { message: error && error.message ? error.message : '' } );
			}
			return;
		}

		try {
			window.history.pushState( surfaceHistoryState( moduleId ), '', window.location.href );
			surfaceHistoryActive = true;
		} catch ( error ) {
			debugLog( 'surface history unavailable', { message: error && error.message ? error.message : '' } );
		}
	}

	function releaseSurfaceHistoryEntry() {
		if ( ! surfaceHistoryActive || surfaceHistoryClosing || ! window.history || typeof window.history.back !== 'function' ) {
			return;
		}

		surfaceHistoryActive = false;
		surfaceHistorySuppressPop = true;
		try {
			window.history.back();
		} catch ( error ) {
			surfaceHistorySuppressPop = false;
			debugLog( 'surface history release unavailable', { message: error && error.message ? error.message : '' } );
		}
	}

	function normalizeStaleSurfaceHistoryEntry() {
		const state = window.history.state;
		if ( ! state || typeof state !== 'object' || ! state.kiweSurface || typeof window.history.replaceState !== 'function' ) {
			return;
		}

		const clean = Object.assign( {}, state );
		delete clean.kiweSurface;
		try {
			window.history.replaceState( clean, '', window.location.href );
		} catch ( error ) {
			debugLog( 'stale surface history cleanup unavailable', { message: error && error.message ? error.message : '' } );
		}
	}

	function onOverlayKeydown( event ) {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			resetNavigationState( true );
			closeOverlay();
		}
	}

	function moduleItems() {
		return Array.isArray( moduleContract.items ) ? moduleContract.items : [];
	}

	function getSurfaceModule( moduleId ) {
		const id = String( moduleId || '' );
		const found = moduleItems().find( function ( item ) {
			return item && item.id === id;
		} );

		if ( found ) {
			return found;
		}

		const fallbackPanels = {
			profile: 'profile',
			cart: 'cart',
			checkout: 'checkout',
			menu: 'menu',
			search: 'search',
			secure: 'secure',
			links: 'links',
			saved: 'saved',
			games: 'games',
			ai: 'ai',
			notifications: 'notifications',
			'ios-install': 'ios-install',
		};

		return {
			id: id,
			label: id || 'Surface module',
			mode: id === 'games' ? 'game' : 'dock',
			panel: fallbackPanels[id] || id,
			binder: id,
			dismiss: id === 'games' ? 'game_rules' : 'outside_safe',
		};
	}

	function openOverlay( moduleId, label ) {
		if ( ! overlayRoot ) {
			return;
		}

		const module = getSurfaceModule( moduleId );
		const requestedMode = module.mode === 'game' || module.panel === 'games' ? 'game' : 'dock';
		label = label || module.label || 'Surface module';
		if ( requiredStandaloneGateActive() && activeOverlayModuleId !== moduleId ) {
			announce( appPhoneKeyGate ? 'Finish secure sign in first.' : 'Finish notification setup first.' );
			return;
		}

		if ( ! enterSurfaceMode( requestedMode ) ) {
			return;
		}

		if ( loader && ! loader.hidden ) {
			hideLoader( true );
		}

		if ( ! overlayRoot.hidden ) {
			closeOverlay( false, { retainHistory: true, immediate: true } );
			if ( ! overlayRoot.hidden ) {
				return;
			}
			if ( ! enterSurfaceMode( requestedMode ) ) {
				return;
			}
		}

		activeOverlayMode = requestedMode;
		activeOverlayModuleId = module.id || moduleId;
		activeOverlayPanel = module.panel || module.id || moduleId;
		activeOverlayLifecycle = createSurfaceLifecycle( module, requestedMode, label );
		window.DSA.surfaceLifecycle.current = Object.assign( {}, activeOverlayLifecycle );
		setDockModuleActive( activeOverlayModuleId );
		lastFocusedElement = document.activeElement;

		try {
			dispatchSurfaceLifecycle( 'before-render', { reason: 'open' } );
			if ( moduleId === 'games' ) {
				gameAttempts = 0;
				gameBestScore = 0;
			}
			if ( ( module.panel || module.id ) === 'ai' ) aiTrayOpen = false;
			overlayRoot.innerHTML = renderModulePanel( module, label );
			applyOverlayHeadingTag();
			applySeamOverlayContract( module.panel || module.id || moduleId );
			overlayContentSequence += 1;
			dispatchSurfaceLifecycle( 'render', { reason: 'open' } );
		} catch ( error ) {
			replaceOverlayContent( renderBasicPanel( 'Surface error', error.message || 'This Surface panel could not render.' ), { reason: 'render_error', module: module } );
		}
		overlayRoot.classList.toggle( 'has-ai-panel', ( module.panel || module.id ) === 'ai' );

		document.addEventListener( 'keydown', onOverlayKeydown );
		overlayRoot.hidden = false;
		overlayRoot.classList.remove( 'is-closing' );
		prepareSheetPanel();
		setOverlayActive( true );
		announce( label + ' opened.' );

		const panel = overlayRoot.querySelector( '[role="dialog"]' );
		if ( panel ) {
			panel.tabIndex = -1;
			panel.focus( { preventScroll: true } );
		}

		bindModulePanel( module );
		syncDockContextRail();
		dispatchSurfaceLifecycle( 'mount', { reason: 'open' } );
		claimSurfaceHistoryEntry( activeOverlayModuleId );

		if ( ( module.panel || module.id ) === 'cart' ) {
			refreshCartState( { rerender: true } ).then( function () {
				syncAiInsights( 'cart', true );
			} );
		}

		if ( ( module.panel || module.id ) === 'ai' ) {
			aiNotificationQueue = [];
			hideAiPopout( false );
			syncAiInsights( 'ai', false );
			rerenderAiInsightInbox();
		}

		recordMetric( 'dock_open', module.id || moduleId || 'module' );
	}

	function createSurfaceLifecycle( module, mode, label ) {
		return {
			version: 1,
			sequence: ++surfaceLifecycleSequence,
			id: module.id || '',
			panel: module.panel || module.id || '',
			binder: module.binder || module.id || '',
			mode: mode || 'dock',
			label: label || module.label || module.id || 'Surface module',
			dismiss: module.dismiss || '',
			mountedAt: Date.now(),
		};
	}

	function surfaceLifecycleDetail( eventName, extra ) {
		const current = activeOverlayLifecycle || {
			version: 1,
			sequence: ++surfaceLifecycleSequence,
			id: activeOverlayModuleId || '',
			panel: activeOverlayPanel || activeOverlayModuleId || '',
			binder: activeOverlayModuleId || '',
			mode: activeOverlayMode || '',
			label: activeOverlayModuleId || 'Surface module',
			dismiss: '',
			mountedAt: Date.now(),
		};

		return Object.assign( {}, current, {
			event: eventName,
			visible: Boolean( overlayRoot && ! overlayRoot.hidden ),
			timestamp: Date.now(),
		}, extra || {} );
	}

	function dispatchSurfaceLifecycle( eventName, extra ) {
		const detail = surfaceLifecycleDetail( eventName, extra );
		window.DSA.surfaceLifecycle.current = detail;
		window.dispatchEvent( new CustomEvent( 'surface:module:' + eventName, { detail: detail } ) );

		if ( eventName === 'mount' ) {
			window.dispatchEvent( new CustomEvent( 'surface:module:open', { detail: detail } ) );
		}
	}

	function replaceOverlayContent( html, options ) {
		if ( ! overlayRoot ) {
			return false;
		}

		const opts = options || {};
		if ( opts.lifecycleSequence && ( ! activeOverlayLifecycle || opts.lifecycleSequence !== activeOverlayLifecycle.sequence ) ) {
			return false;
		}
		if ( opts.contentSequence && opts.contentSequence !== overlayContentSequence ) {
			return false;
		}
		const module = opts.module || getSurfaceModule( activeOverlayModuleId || opts.moduleId || '' );
		const panel = module.panel || module.id || activeOverlayPanel || activeOverlayModuleId || '';
		const previousPanel = activeOverlayPanel;

		if ( panel && panel !== activeOverlayPanel ) {
			dispatchSurfaceLifecycle( 'suspend', { reason: opts.reason || 'replace', nextPanel: panel } );
			activeOverlayModuleId = module.id || activeOverlayModuleId;
			activeOverlayMode = activeOverlayMode || ( module.mode === 'game' ? 'game' : 'dock' );
			activeOverlayPanel = panel;
			activeOverlayLifecycle = createSurfaceLifecycle(
				Object.assign( {}, module, { panel: panel } ),
				activeOverlayMode || ( module.mode === 'game' ? 'game' : 'dock' ),
				opts.label || module.label || panel
			);
			window.DSA.surfaceLifecycle.current = Object.assign( {}, activeOverlayLifecycle );
			setDockModuleActive( activeOverlayModuleId );
			dispatchSurfaceLifecycle( 'resume', { reason: opts.reason || 'replace', previousPanel: previousPanel } );
		}

		dispatchSurfaceLifecycle( 'before-update', { reason: opts.reason || 'replace' } );
		clearDockContextRail();
		overlayRoot.innerHTML = html;
		applyOverlayHeadingTag();
		applySeamOverlayContract( panel );
		overlayContentSequence += 1;
		prepareSheetPanel();
		dispatchSurfaceLifecycle( 'update', { reason: opts.reason || 'replace' } );
		window.requestAnimationFrame( syncDockContextRail );
		return true;
	}

	function screenHeadingTag() {
		const tag = String( styleConfig.screen_heading_tag || 'h2' ).toLowerCase();
		return /^(h1|h2|h3|h4|p|span)$/.test( tag ) ? tag : 'h2';
	}

	function replaceHeadingElement( node, tag ) {
		if ( !node || !tag || node.localName === tag ) {
			return node;
		}
		const replacement = document.createElement( tag );
		Array.from( node.attributes ).forEach( function ( attribute ) {
			replacement.setAttribute( attribute.name, attribute.value );
		} );
		replacement.innerHTML = node.innerHTML;
		node.parentNode.replaceChild( replacement, node );
		return replacement;
	}

	function applyOverlayHeadingTag() {
		if ( !overlayRoot ) {
			return;
		}
		const tag = screenHeadingTag();
		const selectors = [
			':scope > .dsa-panel > h1:first-child',
			':scope > .dsa-panel > h2:first-child',
			':scope > .dsa-panel > .kiwe-search-v2027__title > h2',
			':scope > .dsa-panel > .kiwe-profile-v2027__title > h2',
			':scope > .dsa-panel > .kiwe-cart-v2027__title > h2',
			':scope > .dsa-panel > .kiwe-menu-v2027__title > h2',
			':scope > .dsa-panel > .kiwe-saved-v2027__title > h2',
			':scope > .dsa-panel > .kiwe-links-v2027__title > h2',
			':scope > .dsa-panel > .kiwe-ai-v2027__title > h2',
			':scope > .dsa-panel > .kiwe-notifications-v2027__title > h2',
			':scope > .dsa-panel > .kiwe-ios-v2027__title > h2',
		];
		const seen = new Set();
		selectors.forEach( function ( selector ) {
			overlayRoot.querySelectorAll( selector ).forEach( function ( heading ) {
				if ( seen.has( heading ) ) {
					return;
				}
				seen.add( heading );
				replaceHeadingElement( heading, tag );
			} );
		} );
	}

	function applySeamOverlayContract( panelName ) {
		if ( !overlayRoot ) {
			return;
		}
		const panel = overlayRoot.querySelector( ':scope > [role="dialog"], :scope > .dsa-panel' );
		if ( !panel ) {
			return;
		}
		const resolvedPanel = String( panelName || panel.getAttribute( 'data-dsa-lazy-panel' ) || 'surface' ).toLowerCase().replace( /[^a-z0-9_-]+/g, '-' );
		panel.setAttribute( 'data-dsa-screen', '' );
		panel.setAttribute( 'data-dsa-screen-module', resolvedPanel || 'surface' );
		panel.setAttribute( 'data-seam-root', 'kiwe-dsa' );
		panel.setAttribute( 'data-seam-role', 'modal' );
		panel.setAttribute( 'data-seam-flow', 'stack' );
		panel.setAttribute( 'data-seam-tone', 'surface' );
		panel.setAttribute( 'data-seam-scene', styleConfig.mode === 'sheet' ? 'compact' : 'elevated' );
		panel.setAttribute( 'data-seam-surface-panel', resolvedPanel || 'surface' );
		panel.setAttribute( 'data-seam-authority', 'kiwe-dsa' );
		applySeamShadowLandmarks( panel );
	}

	function setSeamShadow( node, meta ) {
		if ( !node || !meta ) {
			return;
		}
		if ( Array.isArray( meta.classes ) ) {
			meta.classes.forEach( function ( className ) {
				if ( /^seam-[a-z0-9_-]+$/.test( String( className || '' ) ) ) {
					node.classList.add( className );
				}
			} );
		}
		Object.keys( meta ).forEach( function ( key ) {
			if ( key === 'classes' ) {
				return;
			}
			const value = meta[ key ];
			if ( value == null || value === '' ) {
				return;
			}
			node.setAttribute( 'data-seam-' + key, String( value ) );
		} );
	}

	function applySeamShadowLandmarks( panel ) {
		if ( !panel ) {
			return;
		}
		const rules = [
			{ selector: '.kiwe-profile-v2027__title, .kiwe-cart-v2027__title, .kiwe-menu-v2027__title, .kiwe-saved-v2027__title, .kiwe-links-v2027__title, .kiwe-search-v2027__title, .kiwe-ai-v2027__title, .kiwe-notifications-v2027__title, .kiwe-ios-v2027__title', meta: { role: 'hero', flow: 'stack', tone: 'surface', slot: 'title' } },
			{ selector: '.dsa-hero-kicker, .dsa-cart-panel__eyebrow, .dsa-saved-panel__eyebrow, .dsa-search-panel__eyebrow, .dsa-menu-panel__eyebrow', meta: { role: 'eyebrow', tone: 'brand', slot: 'eyebrow', classes: [ 'seam-eyebrow', 'seam-tone-brand' ] } },
			{ selector: '.dsa-panel__meta, .dsa-notification-panel__intro, .dsa-ios-install-panel__lead', meta: { role: 'caption', tone: 'muted', classes: [ 'seam-caption', 'seam-tone-muted' ] } },
			{ selector: '.dsa-panel__header, .dsa-auth-actions, .dsa-checkout-actions, .dsa-links-admin-bar, .dsa-notification-submit, .dsa-initial-preloader__actions, .dsa-game-hud', meta: { role: 'actions', flow: 'cluster', slot: 'actions' } },
			{ selector: '.dsa-panel__list, .dsa-account-actions, .dsa-menu-links, .dsa-secure-list', meta: { role: 'nav', flow: 'stack', slot: 'navigation' } },
			{ selector: '.dsa-social-grid, .dsa-saved-grid, .dsa-links-field-grid, .dsa-checkout-fields', meta: { role: 'container', flow: 'grid' } },
			{ selector: '.dsa-cart-panel__items, .kiwe-cart-v2027__items, .dsa-discount-summary__lines, .dsa-notification-options, .dsa-ios-steps', meta: { role: 'container', flow: 'stack' } },
			{ selector: '.dsa-cart-fbt', meta: { role: 'section', flow: 'stack', slot: 'fbt' } },
			{ selector: '.dsa-cart-fbt__rail', meta: { role: 'nav', flow: 'reel', slot: 'fbt-rail' } },
			{ selector: '.dsa-cart-panel__item, .dsa-cart-fbt__card, .dsa-saved-card, .dsa-discount-summary, .dsa-saved-empty, .kiwe-saved-v2027__summary, .kiwe-cart-v2027__summary, .kiwe-cart-v2027__empty, .dsa-notification-choice, .dsa-game-start', meta: { role: 'card', flow: 'stack' } },
			{ selector: '.dsa-links-hero, .dsa-links-commerce-actions, .dsa-health-row, .dsa-home-trust, .kiwe-cart-v2027__trust', meta: { role: 'container', flow: 'cluster' } },
			{ selector: '.dsa-links-logo, .dsa-panel__avatar, .dsa-cart-panel__item img, .dsa-cart-fbt__card img, .dsa-saved-card img', meta: { role: 'media' } },
			{ selector: '.dsa-panel__button, .dsa-cart-panel__checkout, .dsa-cart-fbt__action, .dsa-cart-fbt__view, .dsa-links-edit-button, .dsa-links-admin-link, .dsa-app-badge, [data-dsa-ios-install-done]', meta: { role: 'button', tone: 'brand' } },
			{ selector: '.dsa-auth-field, [data-dsa-checkout-field], [data-dsa-notification-email], [data-dsa-notification-phone]', meta: { role: 'input' } },
			{ selector: '.dsa-checkout-field, .dsa-links-logo-field, .dsa-notification-contact, .dsa-notification-categories', meta: { role: 'field', flow: 'stack' } },
			{ selector: 'form[data-dsa-checkout-form], form[data-dsa-notification-form], form[data-dsa-profile-form], form[data-dsa-links-form]', meta: { role: 'form', flow: 'stack', authority: 'kiwe-dsa' } },
			{ selector: '.dsa-context-action__badge, .dsa-cart-panel__stock-badge, .dsa-home-trust__badge', meta: { role: 'badge', tone: 'accent' } },
			{ selector: '.dsa-cart-panel__quantity, .dsa-notification-platforms', meta: { role: 'actions', flow: 'row' } },
			{ selector: '.dsa-cart-fbt__price, .dsa-saved-card__price', meta: { role: 'price', tone: 'brand', classes: [ 'seam-price', 'seam-tone-brand' ] } },
			{ selector: '.dsa-game-stage', meta: { role: 'container', flow: 'stack', scene: 'dramatic', slot: 'game-stage' } },
		];
		rules.forEach( function ( rule ) {
			panel.querySelectorAll( rule.selector ).forEach( function ( node ) {
				setSeamShadow( node, rule.meta );
			} );
		} );
	}

	function overlayLifecycleCurrent( sequence ) {
		return Boolean( overlayRoot && ! overlayRoot.hidden && activeOverlayLifecycle && sequence && activeOverlayLifecycle.sequence === sequence );
	}

	function restoreDockContextControls() {
		dockContextPlacements.forEach( function ( placement ) {
			if ( placement.node && placement.marker && placement.marker.parentNode ) {
				placement.marker.parentNode.insertBefore( placement.node, placement.marker );
				placement.marker.remove();
			}
		} );
		dockContextPlacements = [];
	}

	function clearDockContextRail() {
		if ( ! dockContext || ! dockContextContent ) return;
		restoreDockContextControls();
		dockContextContent.innerHTML = '';
		dockContext.hidden = true;
		delete dockContext.dataset.dsaContext;
		delete dockContext.dataset.dsaContextWidth;
		surface.classList.remove( 'has-dock-context' );
		scheduleSurfaceGeometry();
	}

	function buildProductContextControl( form ) {
		if ( !form ) return null;
		const originalButton = form.querySelector( '.single_add_to_cart_button, button[type="submit"], input[type="submit"]' );
		if ( !originalButton ) return null;
		const originalQuantity = form.querySelector( 'input.qty' );
		const control = document.createElement( 'div' );
		control.className = 'dsa-product-context';
		control.dataset.dsaContextSynthetic = '1';
		control.dataset.dsaKeepOpen = '1';
		const label = String( originalButton.textContent || originalButton.value || 'Add to cart' ).trim() || 'Add to cart';
		const minimum = originalQuantity ? Math.max( 0, Number( originalQuantity.min ) || 1 ) : 1;
		const maximum = originalQuantity && originalQuantity.max ? Number( originalQuantity.max ) : 0;
		const step = originalQuantity ? Math.max( 1, Number( originalQuantity.step ) || 1 ) : 1;
		const value = originalQuantity ? Math.max( minimum, Number( originalQuantity.value ) || minimum ) : 1;

		control.innerHTML = [
			originalQuantity ? '<div class="dsa-product-context__quantity" aria-label="Product quantity"><button type="button" data-dsa-product-quantity="decrease" aria-label="Decrease quantity">&minus;</button><output data-dsa-product-quantity-value>' + escapeHtml( value ) + '</output><button type="button" data-dsa-product-quantity="increase" aria-label="Increase quantity">+</button></div>' : '',
			'<button type="button" class="dsa-product-context__add" data-dsa-product-add>' + escapeHtml( label ) + '</button>',
		].join( '' );

		const output = control.querySelector( '[data-dsa-product-quantity-value]' );
		control.querySelectorAll( '[data-dsa-product-quantity]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				if ( !originalQuantity ) return;
				const direction = button.dataset.dsaProductQuantity === 'increase' ? 1 : -1;
				const current = Number( originalQuantity.value ) || minimum;
				const next = Math.max( minimum, maximum > 0 ? Math.min( maximum, current + direction * step ) : current + direction * step );
				originalQuantity.value = String( next );
				originalQuantity.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				originalQuantity.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				if ( output ) output.textContent = String( next );
				surfaceFeedback( 'quantity' );
			} );
		} );

		const add = control.querySelector( '[data-dsa-product-add]' );
		add.disabled = Boolean( originalButton.disabled || originalButton.getAttribute( 'aria-disabled' ) === 'true' );
		add.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			if ( add.disabled ) return;
			surfaceFeedback( 'cart' );
			originalButton.click();
		} );

		return control;
	}

	function syncDockContextRail() {
		if ( ! dockContext || ! dockContextContent ) return;
		if ( dockSettings.context_rail_enabled !== true || ! surface.classList.contains( 'dsa-context-rail-enabled' ) ) {
			clearDockContextRail();
			return;
		}
		clearDockContextRail();
		let controls = [];
		let context = '';
		if ( overlayRoot && ! overlayRoot.hidden ) {
			const declared = Array.from( overlayRoot.querySelectorAll( '[data-dsa-context-slot]' ) );
			if ( declared.length ) {
				controls = declared;
				context = String( declared[ 0 ].dataset.dsaContextName || activeOverlayPanel || 'module' );
			} else if ( overlayRoot.querySelector( '[data-dsa-cart-panel]' ) ) {
				controls = Array.from( overlayRoot.querySelectorAll( '.dsa-cart-panel__checkout' ) );
				context = 'cart';
			} else if ( overlayRoot.querySelector( '[data-dsa-checkout-panel]' ) ) {
				controls = Array.from( overlayRoot.querySelectorAll( '.dsa-checkout-actions' ) );
				context = 'checkout';
			} else if ( overlayRoot.querySelector( '[data-dsa-ai-panel]' ) ) {
				controls = Array.from( overlayRoot.querySelectorAll( '.dsa-ai-chat-placeholder' ) );
				context = 'ai';
			} else if ( overlayRoot.querySelector( '[data-dsa-profile-panel]' ) ) {
				controls = Array.from( overlayRoot.querySelectorAll( '.dsa-account-actions, .dsa-logout-button' ) );
				context = 'profile';
			} else if ( overlayRoot.querySelector( '.dsa-links-panel:not(.dsa-links-editor)' ) ) {
				controls = Array.from( overlayRoot.querySelectorAll( '.dsa-health-row' ) );
				context = 'links';
			} else if ( overlayRoot.querySelector( '.dsa-menu-panel' ) ) {
				controls = Array.from( overlayRoot.querySelectorAll( '.dsa-menu-dashboard' ) );
				context = 'menu';
			}
		} else if ( document.body.classList.contains( 'single-product' ) ) {
			const form = document.querySelector( '.single-product form.cart:not(.variations_form)' );
			const control = buildProductContextControl( form );
			if ( control ) {
				controls = [ control ];
				context = 'product';
			}
		}
		controls = controls.filter( Boolean );
		if ( ! controls.length ) return;
		controls.forEach( function ( control ) {
			if ( control.dataset.dsaContextSynthetic === '1' ) {
				dockContextContent.appendChild( control );
				return;
			}
			const marker = document.createComment( 'Kiwe dock context: ' + context );
			control.parentNode.insertBefore( marker, control );
			dockContextPlacements.push( { node: control, marker: marker } );
			dockContextContent.appendChild( control );
		} );
		dockContext.dataset.dsaContext = context;
		dockContext.dataset.dsaContextWidth = String( controls[ 0 ].dataset.dsaContextWidth || ( context === 'menu' ? 'content' : 'dock' ) );
		dockContext.hidden = false;
		surface.classList.add( 'has-dock-context' );
		window.requestAnimationFrame( scheduleSurfaceGeometry );
	}

	function setDockModuleActive( moduleId ) {
		if ( ! surface ) {
			return;
		}

		surface.querySelectorAll( '[data-dsa-module]' ).forEach( function ( button ) {
			if ( button.dataset.dsaModule === 'theme' ) {
				setThemeToggleState( button );
				return;
			}
			const active = Boolean( moduleId ) && button.dataset.dsaModule === moduleId;
			button.classList.toggle( 'is-active', active );
			button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
	}

	function renderModulePanel( module, label ) {
		const panel = module.panel || module.id;

		if ( panel === 'profile' ) {
			return renderProfilePanel( label );
		}

		if ( panel === 'cart' ) {
			return renderCartPanel( label );
		}

		if ( panel === 'checkout' ) {
			return renderCheckoutPanel();
		}

		if ( panel === 'menu' ) {
			return renderMenuPanel( label );
		}

		if ( panel === 'search' ) {
			return renderSearchPanel( label );
		}

		if ( panel === 'secure' ) {
			return renderSecurePanel( label );
		}

		if ( panel === 'links' ) {
			return renderLinksPanel( label );
		}

		if ( panel === 'saved' ) {
			return renderSavedPanel( label );
		}

		if ( panel === 'games' ) {
			return renderGamesPanel( label );
		}

		if ( panel === 'ai' ) {
			return renderAiPanel( label );
		}

		if ( panel === 'notifications' ) {
			return renderNotificationPreferencePanel();
		}

		if ( panel === 'ios-install' ) {
			return renderIosInstallPanel();
		}

		return renderBasicPanel( label, 'DSA detected ' + escapeHtml( registry.count || 0 ) + ' page elements on this route.' );
	}

	function renderLazyPresentation( panel, label ) {
		return '<section class="dsa-panel dsa-lazy-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( label || panel ) + '" data-dsa-lazy-panel="' + escapeHtml( panel ) + '"><span class="dsa-panel__meta">Preparing ' + escapeHtml( label || panel ) + '...</span></section>';
	}

	function loadPresentationModule( panel ) {
		if ( presentationModules.has( panel ) ) {
			return Promise.resolve( presentationModules.get( panel ) );
		}
		if ( presentationModulePromises.has( panel ) ) {
			return presentationModulePromises.get( panel );
		}
		const url = String( presentationModuleUrls[ panel ] || '' );
		const pending = url ? import( url ).then( function ( adapter ) {
			presentationModules.set( panel, adapter );
			return adapter;
		} ).catch( function ( error ) {
			presentationModulePromises.delete( panel );
			throw error;
		} ) : Promise.reject( new Error( panel + ' presentation module is unavailable.' ) );
		presentationModulePromises.set( panel, pending );
		return pending;
	}

	function hydrateLazyPresentation( module ) {
		const panel = module.panel || module.id;
		const placeholder = overlayRoot ? overlayRoot.querySelector( '[data-dsa-lazy-panel="' + panel + '"]' ) : null;
		if ( ! placeholder ) return false;
		const contentSequence = overlayContentSequence;
		const lifecycleSequence = activeOverlayLifecycle ? activeOverlayLifecycle.sequence : 0;
		loadPresentationModule( panel ).then( function () {
			if ( ! placeholder.isConnected || activeOverlayPanel !== panel ) return;
			if ( ! replaceOverlayContent( renderModulePanel( module, module.label || panel ), { reason: 'presentation_hydrated', module: module, lifecycleSequence: lifecycleSequence, contentSequence: contentSequence } ) ) return;
			bindModulePanel( module );
			syncDockContextRail();
			const hydratedPanel = overlayRoot.querySelector( '[role="dialog"]' );
			if ( hydratedPanel ) {
				hydratedPanel.tabIndex = -1;
				hydratedPanel.focus( { preventScroll: true } );
			}
			dispatchSurfaceLifecycle( 'hydrate', { reason: 'presentation_module', panel: panel } );
		} ).catch( function ( error ) {
			if ( ! placeholder.isConnected ) return;
			replaceOverlayContent( renderBasicPanel( module.label || panel, error.message || 'This Surface module could not load.' ), { reason: 'presentation_error', module: module, lifecycleSequence: lifecycleSequence, contentSequence: contentSequence } );
		} );
		return true;
	}

	function renderBasicPanel( label, copy ) {
		return '<section class="dsa-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( label ) + '"><h2>' + escapeHtml( label ) + '</h2><p>' + escapeHtml( copy ) + '</p></section>';
	}

	function searchPanelLabel( label ) {
		return label || 'Search';
	}

	function renderLegacySearchPanel( label ) {
		return [
			'<section class="dsa-panel dsa-search-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( searchPanelLabel( label ) ) + '" data-dsa-search-panel>',
			'<p class="dsa-search-panel__eyebrow">' + escapeHtml( searchPanelLabel( label ) ) + '</p>',
			'<h2>Find what you need.</h2>',
			'<form class="dsa-search-panel__form" role="search" data-dsa-search-form data-dsa-keep-open>',
			'<label class="dsa-visually-hidden" for="dsa-live-search">Search products and posts</label>',
			'<span class="dsa-search-panel__field">',
			'<span class="dsa-search-glyph" aria-hidden="true"></span>',
			'<input id="dsa-live-search" type="search" name="q" inputmode="search" autocomplete="off" placeholder="Search products and posts" data-dsa-search-input>',
			'<button type="button" aria-label="Clear search" data-dsa-search-clear hidden>&times;</button>',
			'</span>',
			'</form>',
			'<div class="dsa-search-panel__filters" data-dsa-search-filters aria-label="Filter search results"></div>',
			'<div class="dsa-search-panel__alphabet" data-dsa-search-alphabet aria-label="Browse by title" hidden></div>',
			'<div class="dsa-search-panel__results" data-dsa-search-results></div>',
			'</section>',
		].join( '' );
	}

	function renderPrototypeSearchPanel( label ) {
		const resolvedLabel = searchPanelLabel( label );
		return [
			'<section class="dsa-panel dsa-search-panel kiwe-search-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( resolvedLabel ) + '" data-dsa-search-panel data-dsa-search-adapter="prototype-2027">',
			'<div class="kiwe-search-v2027__title">',
			'<p class="dsa-search-panel__eyebrow">' + escapeHtml( resolvedLabel ) + '</p>',
			'<h2>Find what you need.</h2>',
			'<p class="dsa-panel__meta dsa-search-panel__status" data-dsa-search-status></p>',
			'</div>',
			'<form class="dsa-search-panel__form kiwe-search-v2027__form" role="search" data-dsa-search-form data-dsa-keep-open>',
			'<label class="dsa-visually-hidden" for="dsa-live-search">Search products and posts</label>',
			'<span class="dsa-search-panel__field">',
			'<span class="dsa-search-glyph" aria-hidden="true"></span>',
			'<input id="dsa-live-search" type="search" name="q" inputmode="search" autocomplete="off" placeholder="Search products and posts" data-dsa-search-input>',
			'<button type="button" aria-label="Clear search" data-dsa-search-clear hidden>&times;</button>',
			'</span>',
			'</form>',
			'<div class="kiwe-search-v2027__controls">',
			'<div class="dsa-search-panel__filters" data-dsa-search-filters aria-label="Filter search results"></div>',
			'<div class="dsa-search-panel__alphabet" data-dsa-search-alphabet aria-label="Browse by title" hidden></div>',
			'</div>',
			'<div class="dsa-search-panel__results kiwe-search-v2027__results" data-dsa-search-results></div>',
			'</section>',
		].join( '' );
	}

	function renderSearchPanel( label ) {
		const visualProfile = currentVisualProfile();
		return visualProfile === 'kiwe2027' ? renderPrototypeSearchPanel( label ) : renderLegacySearchPanel( label );
	}

	function renderMenuPanel( label ) {
		const adapter = presentationModules.get( 'menu' );
		if ( ! adapter || typeof adapter.renderMenu !== 'function' ) return renderLazyPresentation( 'menu', label || 'Menu' );
		const tag = /^(h1|h2|h3|h4|p|span)$/.test( dockSettings.menu_heading_tag || '' ) ? dockSettings.menu_heading_tag : 'span';
		const menuLabel = dockSettings.menu_label || label;
		const items = Array.isArray( dockSettings.menu_items ) ? dockSettings.menu_items : [];
		const configuredGroups = Array.isArray( dockSettings.menu_groups ) ? dockSettings.menu_groups : [];
		const menuGroups = configuredGroups.length ? configuredGroups : ( items.length ? [ { label: '', items: items } ] : [] );
		const fallbackUrl = window.location.origin + '/';
		const contextHeadings = collectContextHeadings();
		const links = items.length ? items : [
			{
				title: menuLabel,
				url: dockSettings.menu_url || fallbackUrl,
				type: 'Open',
			},
		];

		return adapter.renderMenu( {
			label: menuLabel,
			tag: tag,
			groups: menuGroups.map( function ( group ) {
				return Object.assign( {}, group, { items: ( Array.isArray( group.items ) ? group.items : [] ).map( menuPayloadItem ) } );
			} ),
			links: links.map( menuPayloadItem ),
			fallbackUrl: fallbackUrl,
			contextHeadings: contextHeadings,
			contextTitle: ( dockSettings.menu_context || {} ).title || 'On this page',
			adminDashboard: dockSettings.admin_dashboard || {},
			visualProfile: currentVisualProfile(),
		} );
	}

	function menuPayloadItem( item ) {
		item = item && typeof item === 'object' ? item : {};
		return Object.assign( {}, item, { isActive: isCurrentMenuItem( item ), url: item.url || window.location.origin + '/' } );
	}

	function collectContextHeadings() {
		const context = dockSettings.menu_context || {};
		if ( !context.active ) return [];
		const levels = Array.isArray( context.headingLevels ) && context.headingLevels.length ? context.headingLevels : [ 'h1', 'h2', 'h3' ];
		const selector = levels.map( function ( level ) { return String( level ).toLowerCase(); } ).filter( function ( level ) { return /^h[1-6]$/.test( level ); } ).join( ',' );
		const scope = document.querySelector( 'main, [role="main"], #brx-content, .brx-content' ) || document.body;
		const used = {};
		const blockedClosest = '.dsa-surface, [data-dsa-overlay], header, footer, nav, aside, form, [role="banner"], [role="contentinfo"], [role="complementary"], [data-query-element-id], [data-brx-filter], .brxe-filter-search, .brxe-filter-checkbox, .brxe-filter-radio, .brxe-filter-range, .brxe-filter-select, .brxe-woocommerce-products, .products';
		const normalizeTitle = function ( value ) {
			return String( value || '' ).trim().replace( /\s+/g, ' ' );
		};
		const assignId = function ( node, fallback, index ) {
			let id = node.id || 'kiwe-section-' + String( fallback || 'section' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ).slice( 0, 54 );
			if ( !id || used[id] || ( document.getElementById( id ) && document.getElementById( id ) !== node ) ) id = 'kiwe-section-' + ( index + 1 );
			used[id] = true;
			node.id = id;
			return id;
		};
		const labelledByText = function ( node ) {
			const labelledBy = String( node.getAttribute( 'aria-labelledby' ) || '' ).trim();
			if ( ! labelledBy ) return '';
			return labelledBy.split( /\s+/ ).map( function ( id ) {
				const labelNode = document.getElementById( id );
				return normalizeTitle( labelNode && labelNode.textContent );
			} ).filter( Boolean ).join( ' ' );
		};
		const headings = selector ? Array.from( scope.querySelectorAll( selector ) ).filter( function ( heading ) {
			if ( heading.closest( blockedClosest ) ) return false;
			if ( heading.hidden || heading.getAttribute( 'aria-hidden' ) === 'true' ) return false;
			return Boolean( normalizeTitle( heading.textContent ) );
		} ).slice( 0, 60 ).map( function ( heading, index ) {
			const title = normalizeTitle( heading.textContent );
			return { id: assignId( heading, title, index ), title: title, level: Number( heading.tagName.slice( 1 ) ) || 2, source: 'heading' };
		} ) : [];

		if ( headings.length ) return headings;

		const sectionSelector = '[data-role~="section"], .seam-section';
		const sections = Array.from( scope.querySelectorAll( sectionSelector ) ).filter( function ( section ) {
			if ( section.closest( blockedClosest ) ) return false;
			if ( section.hidden || section.getAttribute( 'aria-hidden' ) === 'true' ) return false;
			const titleNode = section.querySelector( 'h1,h2,h3,h4,h5,h6' );
			return Boolean(
				normalizeTitle( section.getAttribute( 'aria-label' ) )
				|| labelledByText( section )
				|| normalizeTitle( titleNode && titleNode.textContent )
			);
		} ).slice( 0, 60 ).map( function ( section, index ) {
			const titleNode = section.querySelector( 'h1,h2,h3,h4,h5,h6' );
			const title = normalizeTitle( section.getAttribute( 'aria-label' ) )
				|| labelledByText( section )
				|| normalizeTitle( titleNode && titleNode.textContent );
			const level = titleNode && /^H[1-6]$/.test( titleNode.tagName ) ? Number( titleNode.tagName.slice( 1 ) ) : 2;
			return { id: assignId( section, title, index ), title: title, level: level, source: 'section' };
		} );

		return sections;
	}

	function isCurrentUrl( url ) {
		try {
			const target = new URL( url, window.location.href );
			const current = new URL( window.location.href );
			const targetPath = normalizePath( target.pathname );
			const currentPath = normalizePath( current.pathname );

			if ( target.origin !== current.origin ) {
				return false;
			}

			if ( targetPath === currentPath ) {
				return true;
			}

			const body = document.body;
			if ( body && body.classList.contains( 'home' ) && targetPath === normalizePath( '/' ) ) {
				return true;
			}

			if ( data.site && data.site.homeUrl ) {
				const home = new URL( data.site.homeUrl, window.location.href );
				return targetPath === normalizePath( home.pathname ) && currentPath === normalizePath( home.pathname );
			}

			return false;
		} catch ( error ) {
			return false;
		}
	}

	function isCurrentMenuItem( item ) {
		const current = ( data.site && data.site.current ) || {};
		const objectId = Number( item.object_id || 0 );
		const objectType = String( item.object_type || '' );

		if ( objectId && Number( current.postId || 0 ) === objectId ) {
			return true;
		}

		if ( objectId && current.isFrontPage && Number( current.frontPageId || 0 ) === objectId ) {
			return true;
		}

		if ( current.isFrontPage && objectType === 'front_page' ) {
			return true;
		}

		if ( current.isFrontPage && current.frontPageUrl && isSameUrlPath( item.url || '', current.frontPageUrl ) ) {
			return true;
		}

		return isCurrentUrl( item.url || '' );
	}

	function isSameUrlPath( first, second ) {
		try {
			const firstUrl = new URL( first, window.location.href );
			const secondUrl = new URL( second, window.location.href );

			return firstUrl.origin === secondUrl.origin && normalizePath( firstUrl.pathname ) === normalizePath( secondUrl.pathname );
		} catch ( error ) {
			return false;
		}
	}

	function normalizePath( path ) {
		let value = String( path || '/' ).replace( /\/+$/, '' );
		value = value || '/';
		return value.toLowerCase();
	}

	function renderSecurePanel( label ) {
		const links = secure.available && Array.isArray( secure.links ) ? secure.links : [];

		if ( ! links.length ) return renderBasicPanel( label, 'SecureTrack is available to site administrators only.' );

		return '<section class="dsa-panel dsa-secure-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( label ) + '"><p class="dsa-panel__name">Secure</p><ul class="dsa-panel__list dsa-secure-list">' + links.map( function ( link ) { return '<li><a class="dsa-panel__link dsa-secure-link" href="' + escapeHtml( link.url || '#' ) + '" data-dsa-full-navigation><span>' + escapeHtml( link.label || 'Secure page' ) + '</span><span class="dsa-panel__meta">Admin</span></a></li>'; } ).join( '' ) + '</ul></section>';
	}

	function renderAiPanel( label ) {
		const adapter = presentationModules.get( 'ai' );
		return adapter && typeof adapter.renderPanel === 'function' ? adapter.renderPanel( aiPresentationPayload( label ) ) : renderLazyPresentation( 'ai', label );
	}

	function renderAiReport(report){
		const adapter=presentationModules.get('ai');
		const payload=withUiProfile(report||{});
		return adapter&&typeof adapter.renderReport==='function'?adapter.renderReport(payload):'';
	}

	function loadAiInsightMemory() {
		try {
			const parsed = JSON.parse( window.sessionStorage.getItem( 'dsa_ai_insight_memory_v1' ) || '{}' );
			return {
				interacted: parsed && parsed.interacted && typeof parsed.interacted === 'object' ? parsed.interacted : {},
				shown: parsed && parsed.shown && typeof parsed.shown === 'object' ? parsed.shown : {},
			};
		} catch ( error ) {
			return { interacted: {}, shown: {} };
		}
	}

	function saveAiInsightMemory() {
		try {
			window.sessionStorage.setItem( 'dsa_ai_insight_memory_v1', JSON.stringify( aiInsightMemory ) );
		} catch ( error ) {}
	}

	function loadAiNotificationHistory() {
		try {
			const parsed = JSON.parse( window.sessionStorage.getItem( 'dsa_ai_notification_history_v1' ) || '[]' );
			return Array.isArray( parsed ) ? parsed.filter( function ( item ) {
				return item && item.id && item.title;
			} ).map( normalizeAiNotificationDismissible ).slice( 0, 30 ) : [];
		} catch ( error ) {
			return [];
		}
	}

	function isAiNotificationDismissible( item ) {
		if ( ! item ) {
			return false;
		}
		if ( item.requiredAction || item.dismissible === false ) {
			return false;
		}
		if ( item.dismissible === true ) {
			return true;
		}

		const type = String( item.type || '' );
		const id = String( item.id || '' );
		return Boolean( item.notification || id.indexOf( 'push-' ) === 0 || type.indexOf( 'admin_' ) === 0 || [ 'permission', 'cart_pair', 'discount_applied', 'saved_item', 'pwa_install', 'pwa_install_success' ].indexOf( type ) !== -1 );
	}

	function normalizeAiNotificationDismissible( item ) {
		if ( item ) item.dismissible = isAiNotificationDismissible( item );
		return item;
	}

	function saveAiNotificationHistory() {
		try {
			window.sessionStorage.setItem( 'dsa_ai_notification_history_v1', JSON.stringify( aiNotificationHistory.slice( 0, 30 ) ) );
		} catch ( error ) {}
	}

	function recordAiNotification( notification ) {
		if ( ! notification || ! notification.id || aiNotificationHistory.some( function ( item ) { return item.id === notification.id; } ) ) {
			return null;
		}

		if ( notification.type === 'admin_live_visitor' ) {
			aiNotificationHistory = aiNotificationHistory.filter( function ( item ) { return item.type !== 'admin_live_visitor'; } );
		}

		const saved = Object.assign( {
			type: 'notification',
			kicker: 'Cart update',
			message: '',
			createdAt: Date.now(),
			read: false,
			persistent: true,
			notification: true,
			dismissible: null,
		}, notification );

		normalizeAiNotificationDismissible( saved );

		aiNotificationHistory.unshift( saved );
		aiNotificationHistory = aiNotificationHistory.slice( 0, 30 );
		saveAiNotificationHistory();
		return saved;
	}

	function surfaceFeedback( kind ) {
		const eventKey = kind === 'quantity' ? 'quantity' : ( kind === 'notification' ? 'notifications' : ( kind === 'swipe_back' ? 'swipe_back' : 'buttons' ) );
		const context = String( hapticConfig.context || 'both' );
		const standalone = isStandaloneApp();
		if ( ! hapticConfig.enabled || ! hapticConfig.events[ eventKey ] ) return;
		if ( context === 'website' && standalone ) return;
		if ( context === 'appsite' && ! standalone ) return;
		const now = Date.now();
		if ( now - lastFeedbackAt < 90 ) return;
		lastFeedbackAt = now;
		const patterns = {
			quantity: [ 18 ],
			cart: [ 16, 35, 22 ],
			notification: [ 28, 45, 28 ],
			button: [ 10 ],
			swipe_back: [ 12, 26, 18 ],
		};
		if ( hapticConfig.vibration_enabled && window.navigator && typeof window.navigator.vibrate === 'function' ) {
			try { window.navigator.vibrate( patterns[ kind ] || [ 8 ] ); } catch ( error ) {}
		}
		if ( hapticConfig.sound_enabled ) playFeedbackChime( kind );
	}

	function playFeedbackChime( kind ) {
		const AudioContext = window.AudioContext || window.webkitAudioContext;
		if ( ! AudioContext ) return;
		try {
			feedbackAudioContext = feedbackAudioContext || new AudioContext();
			if ( feedbackAudioContext.state === 'suspended' ) feedbackAudioContext.resume().catch( function () {} );
			const profiles = {
				soft: { type: 'sine', gain: .025, base: 1 },
				bright: { type: 'triangle', gain: .032, base: 1.18 },
				pop: { type: 'square', gain: .018, base: .82 },
				bell: { type: 'sine', gain: .03, base: 1.42 },
			};
			const profile = profiles[ String( hapticConfig.sound_profile || 'soft' ) ] || profiles.soft;
			const notes = kind === 'notification' ? [ 659, 880, 1047 ] : ( kind === 'cart' ? [ 740, 988 ] : ( kind === 'swipe_back' ? [ 620, 465 ] : ( kind === 'quantity' ? [ 880 ] : [ 784 ] ) ) );
			const start = feedbackAudioContext.currentTime + .01;
			notes.forEach( function ( frequency, index ) {
				const oscillator = feedbackAudioContext.createOscillator();
				const gain = feedbackAudioContext.createGain();
				const at = start + index * .075;
				oscillator.type = profile.type;
				oscillator.frequency.setValueAtTime( frequency * profile.base, at );
				gain.gain.setValueAtTime( .0001, at );
				gain.gain.exponentialRampToValueAtTime( kind === 'notification' ? Math.max( .035, profile.gain ) : profile.gain, at + .012 );
				gain.gain.exponentialRampToValueAtTime( .0001, at + .12 );
				oscillator.connect( gain );
				gain.connect( feedbackAudioContext.destination );
				oscillator.start( at );
				oscillator.stop( at + .13 );
			} );
		} catch ( error ) {}
	}

	function removeAiNotification( notificationId ) {
		if ( ! notificationId ) {
			return;
		}
		aiNotificationHistory = aiNotificationHistory.filter( function ( item ) { return item.id !== notificationId; } );
		acknowledgeServerAdminNotification( notificationId );
		saveAiNotificationHistory();
		publishAiNotificationState();
		rerenderAiInsightInbox();
	}

	function acknowledgeServerAdminNotification( notificationId ) {
		if ( String( notificationId || '' ).indexOf( 'push-' ) !== 0 || !( phonekey.user || {} ).loggedIn ) return;
		dsaPost( '/admin-notifications', { id: String( notificationId ).slice( 5 ) } ).catch( function () {} );
	}

	function acknowledgeAiNotification( notificationId ) {
		const notification = aiNotificationHistory.find( function ( item ) { return item.id === notificationId; } );
		if ( notification ) {
			notification.read = true;
			saveAiNotificationHistory();
		}
		hideAiPopout( false, true );
		updateDockBadge( 'ai', activeAiInsights().length + unreadAiNotificationCount() );
		publishAiNotificationState();
		rerenderAiInsightInbox();
	}

	function unreadAiNotificationCount() {
		return aiNotificationHistory.filter( function ( item ) { return ! item.read; } ).length;
	}

	function markAiNotificationsRead() {
		let changed = false;
		aiNotificationHistory.forEach( function ( item ) {
			if ( ! item.read ) {
				item.read = true;
				changed = true;
			}
		} );
		if ( changed ) {
			saveAiNotificationHistory();
		}
		publishAiNotificationState();
	}

	function publishAiNotificationState() {
		const detail = {
			version: 1,
			actionable: activeAiInsights().length,
			unread: unreadAiNotificationCount(),
			total: activeAiInsights().length + unreadAiNotificationCount(),
			latest: aiNotificationHistory.length ? aiNotificationHistory[ 0 ] : null,
		};
		window.DSA.aiNotifications = detail;
		window.dispatchEvent( new CustomEvent( 'surface:ai:notifications', { detail: detail } ) );
	}

	function buildAiInsights() {
		const insights = [];
		const greeting = buildReturningUserInsight();

		if ( greeting ) {
			insights.push( greeting );
		}

		const cart = phonekey.cart || {};
		const upsells = Array.isArray( cart.upsells ) ? cart.upsells : [];

		upsells.forEach( function ( offer ) {
			const state = String( offer.state || 'pending' );
			const productId = String( offer.id || '' );
			const triggerId = String( offer.triggerId || offer.trigger_id || '' );

			if ( ! productId || ! triggerId || state === 'applied' ) {
				return;
			}

			const title = offer.title || 'this cart pick';
			const triggerTitle = offer.triggerTitle || 'an item in your cart';
			const saving = [ offer.offerLabel || '', offer.discountScopeLabel || '' ].filter( Boolean ).join( ' ' );
			const eligible = state === 'eligible';
			const id = 'cart-offer:' + triggerId + ':' + productId + ':' + state;

			insights.push( {
				id: id,
				type: 'cart_offer',
				priority: eligible ? 110 : 100,
				kicker: eligible ? 'Bonus ready' : 'Cart match',
				title: eligible ? ( saving ? 'Your ' + saving + ' is ready.' : 'Your cart bonus is ready.' ) : 'Complete this pair and save.',
				message: eligible
					? 'Apply it now for ' + triggerTitle + ' and ' + title + '.'
					: 'Add ' + title + ' with ' + triggerTitle + ( saving ? ' to unlock ' + saving + '.' : '.' ),
				actionLabel: eligible ? 'Apply' : ( offer.actionLabel || 'Add & Save' ),
				action: eligible ? 'claim_cart_offer' : 'add_and_claim_cart_offer',
				productId: productId,
				triggerId: triggerId,
			} );
		} );

		if ( notificationConfig.enabled && notificationConfig.passiveOrderEnabled && protectedFlow.context === 'order_received' && notificationPreferences.topics.indexOf( 'order_status' ) === -1 ) {
			insights.push( {
				id: 'notification-order-status:' + String( data.site && data.site.current ? data.site.current.postId || 'complete' : 'complete' ),
				type: 'permission',
				priority: 88,
				kicker: 'Keep track',
				title: 'Get order notifications.',
				message: 'Choose how this Appsite should keep you updated after checkout.',
				action: 'open_notification_preferences',
				actionLabel: 'Notify me',
				prefillTopic: 'order_status',
			} );
		}

		return insights.sort( function ( a, b ) {
			return Number( b.priority || 0 ) - Number( a.priority || 0 );
		} );
	}

	function buildReturningUserInsight() {
		const user = phonekey.user || {};

		if ( ! user.loggedIn || ! user.id ) {
			return null;
		}

		const knownKey = 'dsa_ai_known_user_' + String( user.id );
		const sessionKey = 'dsa_ai_user_session_' + String( user.id );
		let known = false;

		try {
			known = Boolean( window.localStorage.getItem( knownKey ) );
			const sessionState = window.sessionStorage.getItem( sessionKey );
			window.localStorage.setItem( knownKey, String( Date.now() ) );
			if ( sessionState ) {
				return sessionState === 'returning' ? createReturningUserInsight( user ) : null;
			}
			window.sessionStorage.setItem( sessionKey, known ? 'returning' : 'first' );
		} catch ( error ) {
			return null;
		}

		return known ? createReturningUserInsight( user ) : null;
	}

	function createReturningUserInsight( user ) {
		const name = user.firstName || user.displayName || '';
		return {
			id: 'welcome-back:' + String( user.id ),
			type: 'greeting',
			priority: 20,
			kicker: 'Welcome back',
			title: name ? 'Good to see you, ' + name + '.' : 'Good to see you again.',
			message: 'Your profile, cart, orders, and downloads are ready in the Kiwe dock.',
			action: 'acknowledge',
			actionLabel: 'Thanks',
		};
	}

	function activeAiInsights() {
		return aiInsights.filter( function ( insight ) {
			return ! aiInsightMemory.interacted[ insight.id ];
		} );
	}

	function renderAiInsightInbox() {
		const active = activeAiInsights();
		const history = aiNotificationHistory.slice().sort( function ( a, b ) {
			return Number( b.createdAt || 0 ) - Number( a.createdAt || 0 );
		} );
		const items = active.concat( history );

		const adapter = presentationModules.get( 'ai' );
		return adapter && typeof adapter.renderInbox === 'function' ? adapter.renderInbox( withUiProfile( { items: items, unread: active.length + unreadAiNotificationCount(), open: aiTrayOpen } ) ) : '';
	}

	function renderAiBenefits( insight ) {
		const benefits = insight && Array.isArray( insight.benefits ) ? insight.benefits.filter( Boolean ).slice( 0, 4 ) : [];
		return benefits.length ? '<ul class="dsa-ai-benefits">' + benefits.map( function ( benefit ) {
			return '<li>' + escapeHtml( benefit ) + '</li>';
		} ).join( '' ) + '</ul>' : '';
	}

	function aiPresentationPayload( label ) {
		const active = activeAiInsights();
		const history = aiNotificationHistory.slice().sort( function ( a, b ) { return Number( b.createdAt || 0 ) - Number( a.createdAt || 0 ); } );
		return {
			label: label || 'AI Assistant',
			items: active.concat( history ),
			unread: active.length + unreadAiNotificationCount(),
			open: aiTrayOpen,
			visualProfile: currentVisualProfile(),
		};
	}

	function rerenderAiInsightInbox() {
		const inbox = overlayRoot ? overlayRoot.querySelector( '[data-dsa-ai-insights]' ) : null;

		if ( inbox ) {
			inbox.innerHTML = renderAiInsightInbox();
			bindAiInsightActions( inbox );
		}
	}

	function bindAiInsightActions( root ) {
		if ( ! root ) {
			return;
		}

		root.querySelectorAll( '[data-dsa-ai-insight-action]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				executeAiInsightAction( button.dataset.dsaAiInsightAction, button );
			} );
		} );

		root.querySelectorAll( '[data-dsa-ai-insight-dismiss]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				markAiInsightInteracted( button.dataset.dsaAiInsightDismiss );
				syncAiInsights( 'ai', false );
				rerenderAiInsightInbox();
			} );
		} );

		root.querySelectorAll( '[data-dsa-ai-notification-dismiss]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				dismissAiNotificationCard( button.closest( '[data-dsa-ai-insight]' ), button.dataset.dsaAiNotificationDismiss );
			} );
		} );

		root.querySelectorAll( '[data-dsa-ai-dismissible="true"]' ).forEach( bindAiNotificationSwipe );

		const trayToggle = root.querySelector( '[data-dsa-ai-tray-toggle]' );
		if ( trayToggle ) {
			trayToggle.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				aiTrayOpen = ! aiTrayOpen;
				if ( aiTrayOpen ) {
					markAiNotificationsRead();
					updateDockBadge( 'ai', activeAiInsights().length + unreadAiNotificationCount() );
				}
				rerenderAiInsightInbox();
			} );
		}
	}

	function dismissAiNotificationCard( card, notificationId ) {
		if ( ! notificationId ) {
			return;
		}
		if ( card === aiPopout ) {
			removeAiNotification( notificationId );
			hideAiPopout( false );
			return;
		}
		if ( card ) {
			card.classList.add( 'is-dismissing' );
			window.setTimeout( function () {
				removeAiNotification( notificationId );
			}, 220 );
			return;
		}
		removeAiNotification( notificationId );
	}

	function bindAiNotificationSwipe( card ) {
		if ( ! card || card.dataset.dsaSwipeBound === '1' ) {
			return;
		}
		card.dataset.dsaSwipeBound = '1';
		let startX = null;
		let deltaX = 0;

		card.addEventListener( 'pointerdown', function ( event ) {
			if ( ! card.hasAttribute( 'data-dsa-ai-dismissible' ) ) {
				return;
			}
			if ( event.button !== undefined && event.button !== 0 ) {
				return;
			}
			if ( closestEventTarget( event, 'button, a, input' ) ) {
				return;
			}
			startX = event.clientX;
			deltaX = 0;
			card.classList.add( 'is-swiping' );
			if ( card.setPointerCapture && event.pointerId !== undefined ) {
				card.setPointerCapture( event.pointerId );
			}
		} );

		card.addEventListener( 'pointermove', function ( event ) {
			if ( startX === null ) {
				return;
			}
			deltaX = event.clientX - startX;
			card.style.transform = 'translateX(' + deltaX + 'px)';
			card.style.opacity = String( Math.max( .25, 1 - Math.abs( deltaX ) / 180 ) );
		} );

		const finish = function () {
			if ( startX === null ) {
				return;
			}
			startX = null;
			card.classList.remove( 'is-swiping' );
			if ( Math.abs( deltaX ) >= 72 ) {
				dismissAiNotificationCard( card, card.dataset.dsaAiInsight );
				return;
			}
			card.style.transform = '';
			card.style.opacity = '';
		};
		card.addEventListener( 'pointerup', finish );
		card.addEventListener( 'pointercancel', finish );
	}

	function syncAiInsights( context, allowPopout ) {
		if ( aiConfig.enabled === false ) {
			return;
		}

		aiInsights = buildAiInsights();
		const active = activeAiInsights();
		updateDockBadge( 'ai', active.length + unreadAiNotificationCount() );
		publishAiNotificationState();

		if ( overlayRoot && ! overlayRoot.hidden && overlayRoot.querySelector( '[data-dsa-ai-panel]' ) ) {
			rerenderAiInsightInbox();
		}

		if ( allowPopout ) {
			const relevant = active.filter( function ( insight ) {
				return context === 'welcome' ? insight.type === 'greeting' : ( context === 'cart' || context === 'checkout' ? insight.type === 'cart_offer' : true );
			} );
			const next = context === 'cart'
				? ( aiPopout && aiPopout.hidden ? relevant[0] : null )
				: relevant.find( function ( insight ) {
					return ! aiInsightMemory.shown[ context + '|' + insight.id ];
				} );

			if ( next ) {
				queueAiPopout( next, context );
			}
		}
	}

	function queueAiPopout( insight, context ) {
		if ( ! insight || ! insight.id ) {
			return;
		}
		if ( overlayRoot && ! overlayRoot.hidden && overlayRoot.querySelector( '[data-dsa-ai-panel]' ) ) {
			return;
		}
		if ( aiPopoutInsightId === insight.id || aiNotificationQueue.some( function ( queued ) { return queued.insight.id === insight.id; } ) ) {
			return;
		}
		if ( aiPopout && aiPopout.hidden && ! aiPopoutClosing ) {
			showAiPopout( insight, context );
			return;
		}
		aiNotificationQueue.push( { insight: insight, context: context || 'page' } );
	}

	function showAiPopout( insight, context ) {
		if ( ! aiPopout || ! insight || ! surface.querySelector( '[data-dsa-module="ai"]' ) ) {
			return;
		}

		window.clearTimeout( aiPopoutTimer );
		window.clearTimeout( aiPopoutAnimationTimer );
		aiPopoutClosing = false;
		aiPopoutInsightId = insight.id;
		aiPopoutContext = context;
		const popoutDismissible = isAiNotificationDismissible( insight );
		aiPopoutLocked = Boolean( insight.requiredAction && ! popoutDismissible );
		aiInsightMemory.shown[ context + '|' + insight.id ] = Date.now();
		saveAiInsightMemory();
		aiPopout.innerHTML = [
			popoutDismissible ? '<button type="button" class="dsa-ai-popout__close" data-dsa-ai-popout-dismiss="' + escapeHtml( insight.id ) + '" aria-label="Dismiss notification">&times;</button>' : ( aiPopoutLocked ? '' : '<button type="button" class="dsa-ai-popout__close" data-dsa-ai-popout-close aria-label="Close insight">&times;</button>' ),
			'<div class="dsa-ai-popout__head"><span class="dsa-ai-glyph" aria-hidden="true"></span><strong>AI Assistant</strong></div>',
			'<small>' + escapeHtml( insight.kicker || 'New insight' ) + '</small>',
			'<h3>' + escapeHtml( insight.title || '' ) + '</h3>',
			'<p>' + escapeHtml( insight.message || '' ) + '</p>',
			renderAiBenefits( insight ),
			'<div class="dsa-ai-popout__actions">',
			insight.action ? '<button type="button" data-dsa-ai-popout-action="' + escapeHtml( insight.id ) + '">' + escapeHtml( insight.actionLabel || 'Open' ) + '</button>' : '',
			aiPopoutLocked ? '' : '<button type="button" class="is-quiet" data-dsa-ai-popout-view>View all</button>',
			'</div>',
			'<span class="dsa-ai-popout__status" data-dsa-ai-popout-status></span>',
		].join( '' );
		aiPopout.dataset.dsaAiInsight = insight.id;
		aiPopout.toggleAttribute( 'data-dsa-ai-dismissible', popoutDismissible );
		if ( popoutDismissible ) {
			bindAiNotificationSwipe( aiPopout );
		}
		aiPopout.classList.remove( 'is-leaving' );
		aiPopout.classList.add( 'is-entering' );
		aiPopout.hidden = false;
		surfaceFeedback( 'notification' );
		window.requestAnimationFrame( function () {
			positionAiPopout();
			window.requestAnimationFrame( function () { aiPopout.classList.remove( 'is-entering' ); } );
		} );
		window.setTimeout( positionAiPopout, 80 );
		if ( ! aiPopoutLocked && ( insight.notification || context !== 'cart' ) ) {
			aiPopoutTimer = window.setTimeout( hideAiPopout, Math.max( 2000, Math.min( 15000, Number( aiConfig.popupDurationMs ) || 3200 ) ) );
		}
	}

	function hideAiPopout( showNext, immediate ) {
		if ( aiPopoutLocked && ! immediate ) {
			return;
		}
		showNext = showNext !== false;
		window.clearTimeout( aiPopoutTimer );
		aiPopoutTimer = 0;
		aiPopoutInsightId = '';
		aiPopoutContext = '';
		if ( ! aiPopout || aiPopout.hidden ) {
			if ( showNext ) {
				showNextAiPopout();
			}
			return;
		}

		const finish = function () {
			window.clearTimeout( aiPopoutAnimationTimer );
			aiPopout.hidden = true;
			aiPopout.innerHTML = '';
			aiPopout.classList.remove( 'is-entering', 'is-leaving' );
			aiPopout.style.transform = '';
			aiPopout.style.opacity = '';
			aiPopoutClosing = false;
			aiPopoutLocked = false;
			if ( showNext ) {
				showNextAiPopout();
			}
		};

		if ( immediate || ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) ) {
			finish();
			return;
		}

		aiPopoutClosing = true;
		aiPopout.classList.add( 'is-leaving' );
		aiPopoutAnimationTimer = window.setTimeout( finish, 280 );
	}

	function showNextAiPopout() {
		if ( ! aiNotificationQueue.length || ! aiPopout || ! aiPopout.hidden ) {
			return;
		}
		const next = aiNotificationQueue.shift();
		window.setTimeout( function () {
			showAiPopout( next.insight, next.context );
		}, 90 );
	}

	function positionAiPopout() {
		if ( ! aiPopout || aiPopout.hidden ) {
			return;
		}

		const button = surface.querySelector( '[data-dsa-module="ai"]' );
		const dock = surface.querySelector( '.dsa-dock' );

		if ( ! button || ! dock ) {
			return;
		}

		const buttonRect = button.getBoundingClientRect();
		const popRect = aiPopout.getBoundingClientRect();
		const direction = window.getComputedStyle( dock ).flexDirection;
		const horizontal = direction === 'row' || direction === 'row-reverse';
		const margin = 14;
		let left = 12;
		let top = 12;
		let placement = '';

		if ( horizontal ) {
			left = buttonRect.left + ( buttonRect.width - popRect.width ) / 2;
			if ( buttonRect.top > window.innerHeight / 2 ) {
				top = buttonRect.top - popRect.height - margin;
				placement = 'top';
			} else {
				top = buttonRect.bottom + margin;
				placement = 'bottom';
			}
		} else {
			top = buttonRect.top + ( buttonRect.height - popRect.height ) / 2;
			if ( buttonRect.left > window.innerWidth / 2 ) {
				left = buttonRect.left - popRect.width - margin;
				placement = 'left';
			} else {
				left = buttonRect.right + margin;
				placement = 'right';
			}
		}

		aiPopout.style.left = Math.max( 12, Math.min( window.innerWidth - popRect.width - 12, left ) ) + 'px';
		aiPopout.style.top = Math.max( 12, Math.min( window.innerHeight - popRect.height - 12, top ) ) + 'px';
		aiPopout.dataset.placement = placement;
	}

	function markAiInsightInteracted( insightId ) {
		if ( ! insightId ) {
			return;
		}

		aiInsightMemory.interacted[ insightId ] = Date.now();
		saveAiInsightMemory();
		if ( aiPopoutInsightId === insightId ) {
			hideAiPopout();
		}
	}

	function executeAiInsightAction( insightId, button ) {
		const insight = aiInsights.concat( aiNotificationHistory ).find( function ( item ) { return item.id === insightId; } );

		if ( ! insight ) {
			return;
		}

		if ( insight.action === 'acknowledge' ) {
			markAiInsightInteracted( insight.id );
			syncAiInsights( 'ai', false );
			rerenderAiInsightInbox();
			return;
		}

		if ( insight.action === 'acknowledge_notification' ) {
			acknowledgeAiNotification( insight.id );
			return;
		}

		if ( insight.action === 'pwa_install_confirm' ) {
			confirmPwaInstallFromAi( insight );
			return;
		}

		if ( insight.action === 'open_notification_preferences' ) {
			hideAiPopout( false, true );
			openNotificationPreferences( {
				topic: insight.prefillTopic || '',
				context: isStandaloneApp() ? 'standalone_setup' : 'passive_order',
				required: isStandaloneApp(),
			} );
			return;
		}

		if ( insight.action === 'open_url' && insight.actionUrl ) {
			markAiInsightInteracted( insight.id );
			acknowledgeServerAdminNotification( insight.id );
			window.location.href = insight.actionUrl;
			return;
		}

		if ( insight.action === 'ios_notification_permission' ) {
			if ( button ) {
				button.dataset.dsaIosNotificationResume = '1';
			}
			requestBrowserNotifications( button );
			return;
		}

		if ( insight.action !== 'claim_cart_offer' && insight.action !== 'add_and_claim_cart_offer' ) {
			return;
		}

		setAiInsightActionBusy( button, true, 'Working...' );
		const followContext = aiPopoutContext || ( overlayRoot && ! overlayRoot.hidden && overlayRoot.querySelector( '[data-dsa-cart-panel]' ) ? 'cart' : ( isCurrentCheckoutPage() ? 'checkout' : 'ai' ) );
		performAiCartOfferAction( insight )
			.then( function ( response ) {
				const offers = response && response.cart && Array.isArray( response.cart.upsells ) ? response.cart.upsells : [];
				const current = offers.find( function ( offer ) {
					return Number( offer.id || 0 ) === Number( insight.productId || 0 ) && Number( offer.triggerId || offer.trigger_id || 0 ) === Number( insight.triggerId || 0 );
				} );

				hideAiPopout();
				if ( ! current || String( current.state || '' ) === 'applied' ) {
					markAiInsightInteracted( insight.id );
				}
				syncAiInsights( followContext, followContext === 'cart' || followContext === 'checkout' );
				rerenderAiInsightInbox();
			} )
			.catch( function ( error ) {
				setAiInsightActionStatus( button, error.message || 'Could not update the cart.' );
			} )
			.finally( function () {
				setAiInsightActionBusy( button, false, insight.actionLabel || 'Try again' );
			} );
	}

	function performAiCartOfferAction( insight ) {
		const productId = Number( insight.productId ) || 0;
		const triggerId = Number( insight.triggerId ) || 0;
		let cartSequence = 0;

		if ( ! productId || ! triggerId ) {
			return Promise.reject( new Error( 'This cart offer is no longer available.' ) );
		}

		return enqueueCartMutation( function () {
			cartSequence = nextCartSequence();
			if ( insight.action === 'claim_cart_offer' ) {
				return dsaPost( '/cart/upsell/claim', { productId: productId, triggerId: triggerId } );
			}

			return dsaPost( '/cart/add', { productId: productId, quantity: 1, triggerId: triggerId } )
				.then( function ( response ) {
					const offers = response && response.cart && Array.isArray( response.cart.upsells ) ? response.cart.upsells : [];
					const ready = offers.find( function ( offer ) {
						return Number( offer.id || 0 ) === productId && Number( offer.triggerId || offer.trigger_id || 0 ) === triggerId && String( offer.state || '' ) === 'eligible';
					} );

					if ( ready ) {
						return dsaPost( '/cart/upsell/claim', { productId: productId, triggerId: triggerId } );
					}

					return response;
				} );
		} ).then( function ( response ) {
			applyAiCartResponse( response, insight.action === 'add_and_claim_cart_offer' ? 'added_to_cart' : 'wc_fragments_refreshed', cartSequence );
			return response;
		} );
	}

	function applyAiCartResponse( response, eventName, sequence ) {
		if ( response && response.cart ) {
			applyCartPayload( response, { rerender: true, cartMutation: true, sequence: sequence } );
			applyWooCartFragments( response, eventName );
		}
	}

	function setAiInsightActionBusy( button, busy, label ) {
		if ( button ) {
			button.disabled = Boolean( busy );
			button.textContent = label || button.textContent;
		}
	}

	function setAiInsightActionStatus( button, message ) {
		const container = button ? button.closest( '.dsa-ai-insight, .dsa-ai-popout' ) : null;
		const status = container ? container.querySelector( '[data-dsa-ai-insight-status], [data-dsa-ai-popout-status]' ) : null;

		if ( status ) {
			status.textContent = message || '';
		}
	}

	function initializeAiInsights() {
		if ( aiConfig.enabled === false || ! surface.querySelector( '[data-dsa-module="ai"]' ) ) {
			return;
		}

		syncAiInsights( 'page', false );
		window.setTimeout( function () {
			const context = protectedFlow.context === 'order_received' ? 'permission' : ( isCurrentCheckoutPage() ? 'checkout' : 'welcome' );
			syncAiInsights( context, true );
		}, 900 );
		window.addEventListener( 'resize', function () {
			window.requestAnimationFrame( positionAiPopout );
		} );
		window.addEventListener( 'scroll', function () {
			window.requestAnimationFrame( positionAiPopout );
		}, true );
	}

	function renderGamesPanel( label, scheduledGame ) {
		const adapter = presentationModules.get( 'games' );
		if ( ! adapter || typeof adapter.renderGames !== 'function' ) return renderLazyPresentation( 'games', label || 'Game' );
		return adapter.renderGames( {
			label: label || 'Game',
			config: gamesConfig,
			scheduledGame: scheduledGame || '',
			bonusLabel: nextBonusLabel( 0 ),
			coarsePointer: isCoarsePointer(),
		} );
	}

	function renderLinksPanel( label ) {
		const adapter = presentationModules.get( 'links' );
		return adapter && typeof adapter.render === 'function' ? adapter.render( linksPresentationPayload( label ) ) : renderLazyPresentation( 'links', label );
	}

	function renderLinksEditor() {
		const adapter = presentationModules.get( 'links' );
		return adapter && typeof adapter.renderEditor === 'function' ? adapter.renderEditor( linksPresentationPayload( 'Edit links' ) ) : renderLazyPresentation( 'links', 'Edit links' );
	}

	function linksPresentationPayload( label ) {
		return {
			label: label || 'Links',
			hub: linksHub,
			dark: currentColorMode() === 'dark',
			logoDark: data.site && data.site.logoInverse ? data.site.logoInverse : '',
			documentTitle: document.title || '',
			visualProfile: currentVisualProfile(),
		};
	}

	function socialGlyph( id, label ) {
		const title = escapeHtml( label || id || 'Social link' );
		const open = '<svg viewBox="0 0 24 24" role="img" aria-label="' + title + '" focusable="false">';
		const close = '</svg>';
		const glyphs = {
			share: '<circle cx="18" cy="5" r="2.5"></circle><circle cx="6" cy="12" r="2.5"></circle><circle cx="18" cy="19" r="2.5"></circle><path d="M8.2 10.9 15.7 6.2M8.2 13.1l7.5 4.7"></path>',
			facebook: '<path d="M14.2 21v-7h-2.5v-3h2.5V8.8c0-3 1.8-4.8 4.6-4.8 1.1 0 2.1.1 2.6.2v3h-1.8c-1.4 0-1.8.7-1.8 1.7V11h3.4l-.5 3h-2.9v7z"></path>',
			instagram: '<rect x="3" y="3" width="18" height="18" rx="5"></rect><circle cx="12" cy="12" r="4"></circle><circle cx="17.5" cy="6.5" r="1"></circle>',
			x: '<path d="M5 4l14 16M19 4 5 20"></path>',
			youtube: '<path d="m9 8 7 4-7 4z"></path>',
			pinterest: '<path d="M12 2a9.5 9.5 0 0 0-3.4 18.4c-.1-1.6 0-3.4.4-5.1l1.2-5s-.3-.8-.3-2c0-1.9 1.1-3.3 2.5-3.3 1.2 0 1.8.9 1.8 2 0 1.2-.8 3-1.2 4.7-.3 1.4.7 2.5 2.1 2.5 2.5 0 4.2-3.2 4.2-6.9 0-2.9-2.4-5.1-5.7-5.1-4 0-6.5 3-6.5 6.3 0 1.1.3 2.3 1 3 .3.3.3.5.2.9l-.3 1.3c-.1.4-.4.6-.8.4-1.9-.8-2.8-3.1-2.8-5.7C4.4 4.8 8 1 13.9 1 19 1 22 4.7 22 8.8c0 5.3-3 9.2-7.4 9.2-1.5 0-2.9-.8-3.4-1.8l-.9 3.5c-.3 1.2-1 2.5-1.6 3.4A10 10 0 0 0 12 22z"></path>',
			linkedin: '<rect x="4" y="9" width="4" height="11"></rect><circle cx="6" cy="5.5" r="2"></circle><path d="M11 20V9h4v1.7c.8-1.2 2-2.1 3.8-2.1 3 0 4.2 2 4.2 5.3V20h-4v-5.4c0-1.6-.6-2.7-2-2.7-1.4 0-2 1-2 2.7V20z"></path>',
		};
		const body = glyphs[ id ] || '<circle cx="12" cy="12" r="8"></circle>';
		return open + body + close;
	}

	function initializeLinksDockIcon() {
		window.clearInterval( socialDockTimer );
		socialDockTimer = 0;
		const button = surface.querySelector( '[data-dsa-module="links"]' );
		const icon = button ? button.querySelector( '.dsa-dock__icon' ) : null;
		const socials = Array.isArray( linksHub.socials ) ? linksHub.socials.filter( function ( item ) { return item && item.id; } ).slice( 0, 6 ) : [];

		if ( ! icon ) {
			return;
		}

		const frames = [ { id: 'share', label: 'Share' } ].concat( socials );
		icon.classList.add( 'dsa-social-cycle' );
		icon.innerHTML = frames.map( function ( item, index ) {
			return '<span class="dsa-social-cycle__frame dsa-social-cycle__frame--' + escapeHtml( item.id ) + ( index === 0 ? ' is-active' : '' ) + '" data-dsa-social-cycle-frame>' + socialGlyph( item.id, item.label ) + '</span>';
		} ).join( '' );

		if ( frames.length < 2 || ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) ) {
			return;
		}

		let active = 0;
		socialDockTimer = window.setInterval( function () {
			const nodes = icon.querySelectorAll( '[data-dsa-social-cycle-frame]' );
			if ( ! nodes.length ) {
				window.clearInterval( socialDockTimer );
				return;
			}
			nodes[ active ].classList.remove( 'is-active' );
			active = ( active + 1 ) % nodes.length;
			nodes[ active ].classList.add( 'is-active' );
		}, 1500 );
	}

	function renderSavedPanel( label ) {
		const adapter = presentationModules.get( 'saved' );
		if ( ! adapter || typeof adapter.renderSaved !== 'function' ) return renderLazyPresentation( 'saved', label || 'Saved' );
		return adapter.renderSaved( {
			label: label || 'Saved',
			items: savedItems,
			visualProfile: currentVisualProfile(),
		} );
	}

	function bindSavedPanel() {
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-saved-panel]' ) : null;
		if ( ! panel ) return;
		panel.querySelectorAll( '[data-dsa-saved-remove]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				removeSavedItem( button.dataset.dsaSavedRemove );
			} );
		} );
	}

	function normalizeSavedItem( item ) {
		item = item && typeof item === 'object' ? item : {};
		const type = item.type === 'wishlist' ? 'wishlist' : 'bookmark';
		const id = Math.max( 0, Number( item.id ) || 0 );
		const url = String( item.url || '' );
		const title = String( item.title || '' ).trim();
		if ( ! url || ! title ) return null;
		return {
			key: String( item.key || ( id ? type + ':' + id : type + ':' + aiStringHash( url ) ) ),
			id: id,
			type: type,
			title: title,
			url: url,
			image: String( item.image || '' ),
			kindLabel: String( item.kindLabel || '' ),
			price: String( item.price || '' ),
			weight: String( item.weight || '' ),
			stockLabel: String( item.stockLabel || '' ),
			category: String( item.category || '' ),
			excerpt: String( item.excerpt || '' ),
			date: String( item.date || '' ),
			savedAt: Number( item.savedAt ) || Date.now(),
		};
	}

	function mergeSavedItems( incoming ) {
		const map = new Map();
		[ savedItems, Array.isArray( incoming ) ? incoming : [] ].forEach( function ( list ) {
			list.forEach( function ( raw ) {
				const item = normalizeSavedItem( raw );
				if ( item ) map.set( item.key, item );
			} );
		} );
		savedItems = Array.from( map.values() ).sort( function ( a, b ) { return b.savedAt - a.savedAt; } ).slice( 0, 100 );
		persistSavedItems();
		updateSavedUi();
	}

	function persistSavedItems() {
		try {
			if ( window.localStorage ) window.localStorage.setItem( savedStorageKey, JSON.stringify( savedItems ) );
		} catch ( error ) {}
	}

	function updateSavedUi() {
		updateDockBadge( 'saved', savedItems.length );
		const savedUse = surface ? surface.querySelector( '[data-dsa-module="saved"] .dsa-lucide use' ) : null;
		if ( savedUse && iconSprite ) {
			savedUse.setAttribute( 'href', iconSprite + '#' + ( savedItems.some( function ( item ) { return item.type === 'wishlist'; } ) ? 'heart' : 'bookmark' ) );
		}
		document.querySelectorAll( '[data-kiwe-save]' ).forEach( function ( trigger ) {
			const item = inferSavedItem( trigger );
			const saved = item && savedItems.some( function ( existing ) { return existing.key === item.key; } );
			trigger.classList.toggle( 'is-kiwe-saved', Boolean( saved ) );
			trigger.setAttribute( 'aria-pressed', saved ? 'true' : 'false' );
		} );
		if ( overlayRoot && ! overlayRoot.hidden && overlayRoot.querySelector( '[data-dsa-saved-panel]' ) ) {
			replaceOverlayContent( renderSavedPanel( 'Saved' ), { reason: 'saved_refresh', module: getSurfaceModule( 'saved' ), label: 'Saved' } );
			bindSavedPanel();
		}
	}

	function initializeSavedItems() {
		try {
			const local = window.localStorage ? JSON.parse( window.localStorage.getItem( savedStorageKey ) || '[]' ) : [];
			mergeSavedItems( local );
		} catch ( error ) {
			savedItems = [];
		}

		dsaGet( '/saved-items' ).then( function ( response ) {
			if ( response && Array.isArray( response.items ) ) mergeSavedItems( response.items );
		} ).catch( function () {} );
	}

	function inferSavedItem( trigger ) {
		if ( ! trigger ) return null;
		const card = trigger.closest( '[data-product_id], [data-product-id], .product, article, li' );
		const productNode = trigger.closest( '[data-product_id], [data-product-id]' ) || ( card && card.matches( '[data-product_id], [data-product-id]' ) ? card : null );
		const current = commerce.current && typeof commerce.current === 'object' ? commerce.current : {};
		const currentId = Number( current.id ) || ( data.site && data.site.current ? Number( data.site.current.postId ) || 0 : 0 );
		const id = Number( trigger.dataset.kiweSaveId || ( productNode && ( productNode.dataset.product_id || productNode.dataset.productId ) ) || currentId ) || 0;
		const requested = String( trigger.dataset.kiweSave || 'auto' ).toLowerCase();
		const productContext = requested === 'wishlist' || Boolean( productNode ) || current.type === 'product' || document.body.classList.contains( 'single-product' );
		const type = requested === 'bookmark' ? 'bookmark' : ( productContext && commerce.available === true ? 'wishlist' : 'bookmark' );
		const nearestLink = trigger.closest( 'a[href]' ) || ( card && card.querySelector( 'a[href]' ) );
		const heading = card && card.querySelector( '.woocommerce-loop-product__title, .product_title, h1, h2, h3, h4' );
		const image = card && card.querySelector( 'img' );
		return normalizeSavedItem( {
			id: id,
			type: type,
			title: trigger.dataset.kiweSaveTitle || ( heading && heading.textContent ) || current.title || document.querySelector( 'h1' ) && document.querySelector( 'h1' ).textContent || document.title,
			url: trigger.dataset.kiweSaveUrl || ( nearestLink && nearestLink.href ) || current.url || window.location.href,
			image: trigger.dataset.kiweSaveImage || ( image && ( image.currentSrc || image.src ) ) || current.image || '',
			savedAt: Date.now(),
		} );
	}

	function mutateSavedItem( action, item ) {
		return dsaPost( '/saved-items', { action: action, item: item } ).then( function ( response ) {
			if ( response && Array.isArray( response.items ) ) mergeSavedItems( response.items );
			return response;
		} ).catch( function () {} );
	}

	function toggleSavedItem( trigger ) {
		const item = inferSavedItem( trigger );
		if ( ! item ) return;
		const existing = savedItems.some( function ( candidate ) { return candidate.key === item.key; } );
		if ( existing ) {
			removeSavedItem( item.key );
			return;
		}
		savedItems.unshift( item );
		savedItems = savedItems.slice( 0, 100 );
		persistSavedItems();
		updateSavedUi();
		mutateSavedItem( 'add', item );
		removeAiNotification( 'saved:' + item.key );
		const notice = recordAiNotification( {
			id: 'saved:' + item.key,
			type: 'saved_item',
			kicker: item.type === 'wishlist' ? 'Wishlisted' : 'Bookmarked',
			title: item.title + ' is saved.',
			message: item.type === 'wishlist' ? 'Find it in your Wishlist inside Saved.' : 'Find it in your Bookmarks inside Saved.',
			dismissible: true,
			notification: true,
		} );
		if ( notice ) queueAiPopout( notice, 'saved' );
	}

	function removeSavedItem( key ) {
		const item = savedItems.find( function ( candidate ) { return candidate.key === key; } );
		if ( ! item ) return;
		savedItems = savedItems.filter( function ( candidate ) { return candidate.key !== key; } );
		persistSavedItems();
		updateSavedUi();
		mutateSavedItem( 'remove', item );
	}

	function bindSavedTriggers() {
		document.addEventListener( 'click', function ( event ) {
			const trigger = closestEventTarget( event, '[data-kiwe-save]' );
			if ( ! trigger ) return;
			event.preventDefault();
			event.stopPropagation();
			toggleSavedItem( trigger );
		}, true );
	}

	function renderProfilePanel( label ) {
		const user = phonekey.user || {};

		if ( ! user.loggedIn || appPhoneKeyGate ) {
			resetPhoneKeyState();
			return [
				'<section class="dsa-panel dsa-auth-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( label ) + '" data-dsa-phonekey-auth' + ( appPhoneKeyGate ? ' data-dsa-keep-open data-dsa-required-gate' : '' ) + '>',
				phoneKeyCloseButton(),
				renderPhoneKeyStart(),
				'</section>',
			].join( '' );
		}

		const adapter = presentationModules.get( 'profile' );
		if ( ! adapter || typeof adapter.render !== 'function' ) {
			return renderLazyPresentation( 'profile', label );
		}

		return adapter.render( {
			label: label,
			user: user,
			hasWoo: Boolean( phonekey.cart && phonekey.cart.available ),
			iconSprite: iconSprite,
			visualProfile: currentVisualProfile(),
			savedCount: savedItems.length,
		} );
	}

	function resetPhoneKeyState() {
		phonekeyState = {
			token: '',
			mode: '',
			name: '',
			identifier: '',
			identifierType: '',
			loginToken: '',
			error: '',
			canEmailRecovery: false,
			hasTotp: false,
			hasBackup: false,
			emailDelivery: 'magic_link',
			otpResendLockedUntil: 0,
		};
	}

	function bindMenuPanel() {
		if ( !overlayRoot ) return;
		overlayRoot.querySelectorAll( '[data-dsa-menu-anchor]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				const target = document.getElementById( button.getAttribute( 'data-dsa-menu-anchor' ) || '' );
				if ( !target ) return;
				const reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
				let scrolled = false;
				const scrollToTarget = function () {
					if ( scrolled ) return;
					if ( document.documentElement.classList.contains( 'dsa-scroll-locked' ) ) {
						window.setTimeout( scrollToTarget, 40 );
						return;
					}
					const adminBarHeight = geometryCssNumber( '--dsa-admin-bar-height', 0 );
					const reserve = geometryCssNumber( '--dsa-screen-block-reserve', 0 );
					target.style.scrollMarginTop = Math.max( 12, adminBarHeight + Math.min( reserve, 96 ) + 12 ) + 'px';
					target.scrollIntoView( { behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' } );
					scrolled = true;
					if ( window.history && window.history.replaceState ) {
						try {
							window.history.replaceState( window.history.state, document.title, '#' + encodeURIComponent( target.id ) );
						} catch ( error ) {}
					}
				};
				const scheduleScrollToTarget = function () {
					window.requestAnimationFrame( function () {
						window.requestAnimationFrame( scrollToTarget );
					} );
				};
				window.addEventListener( 'surface:overlay:close', scheduleScrollToTarget, { once: true } );
				closeOverlay( false, { retainHistory: false, immediate: reducedMotion } );
				window.setTimeout( function () {
					scrollToTarget();
				}, reducedMotion ? 0 : 700 );
			} );
		} );
	}

	function bindModulePanel( module ) {
		const panel = module.panel || module.id;
		const binder = module.binder || panel;
		if ( hydrateLazyPresentation( module ) ) {
			return;
		}

		if ( panel === 'profile' && !( phonekey.user || {} ).loggedIn ) {
			bindPhoneKeyAuth();
		}

		if ( panel === 'profile' && ( phonekey.user || {} ).loggedIn ) {
			bindProfilePanel();
		}

		if ( panel === 'cart' ) {
			bindCartPanel();
		}

		if ( panel === 'search' ) {
			bindSearchPanel();
		}

		if ( panel === 'menu' ) {
			bindMenuPanel();
		}

		if ( panel === 'checkout' ) {
			bindCheckoutPanel();
		}

		if ( binder === 'links' ) {
			bindLinksPanel();
		}

		if ( panel === 'saved' ) {
			bindSavedPanel();
		}

		if ( panel === 'ai' ) {
			bindAiPanel();
		}

		if ( panel === 'notifications' ) {
			bindNotificationPreferencePanel();
		}

		if ( panel === 'ios-install' ) {
			bindIosInstallPanel();
		}

		if ( panel === 'games' ) {
			bindGamesPanel();
		}
	}

	function bindSearchPanel() {
		const root = overlayRoot ? overlayRoot.querySelector( '[data-dsa-search-panel]' ) : null;

		if ( ! root || ! searchConfig.moduleUrl || typeof window.Promise !== 'function' ) {
			return;
		}

		root.classList.add( 'is-loading' );
		if ( ! searchModulePromise ) {
			searchModulePromise = import( searchConfig.moduleUrl );
		}

		searchModulePromise.then( function ( searchModule ) {
			if ( root.isConnected && searchModule && typeof searchModule.mount === 'function' ) {
				searchModule.mount( root, {
					endpoint: searchConfig.endpoint || ( String( data.restUrl || '' ).replace( /\/$/, '' ) + '/search' ),
					nonce: data.nonce || '',
					limit: Number( searchConfig.limit ) || 6,
					context: searchConfig.context || {},
					alphabetEnabled: searchConfig.alphabetEnabled !== false,
					productAddEnabled: searchConfig.productAddEnabled !== false,
					bricksBridgeEnabled: searchConfig.bricksBridgeEnabled !== false,
				} );
			}
		} ).catch( function () {
			searchModulePromise = null;
			const status = root.querySelector( '[data-dsa-search-status]' );
			root.classList.remove( 'is-loading' );
			if ( status ) {
				status.textContent = 'Search could not start. Please try again.';
			}
		} );
	}

	function bindAiPanel() {
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-ai-panel]' ) : null;

		if ( ! panel ) {
			return;
		}

		bindAiInsightActions( panel );
		const chatInput = panel.querySelector( '.dsa-ai-chat-placeholder input' );
		if ( chatInput ) {
			const closeTray = function () {
				if ( aiTrayOpen ) {
					aiTrayOpen = false;
					rerenderAiInsightInbox();
				}
			};
			chatInput.addEventListener( 'focus', closeTray );
			chatInput.addEventListener( 'pointerdown', closeTray );
		}
	}

	function bindGamesPanel() {
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-game-panel]' ) : null;

		if ( ! panel ) {
			return;
		}

		panel.querySelectorAll( '[data-dsa-start-game]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				startGame( button.dataset.dsaStartGame || 'dino' );
			} );
		} );

		const canvas = panel.querySelector( '[data-dsa-game-canvas]' );
		if ( canvas ) {
			canvas.addEventListener( 'pointerdown', function () {
				if ( panel.dataset.dsaScheduledGame && ! activeGame ) {
					startGame( panel.dataset.dsaScheduledGame );
					return;
				}

				if ( activeGame && typeof activeGame.input === 'function' ) {
					activeGame.input();
				}
			} );
		}

		const start = panel.querySelector( '[data-dsa-game-start]' );
		if ( start ) {
			start.addEventListener( 'pointerdown', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				startGame( panel.dataset.dsaScheduledGame || 'dino' );
			} );
		}

		window.addEventListener( 'keydown', onGameKeydown );
	}

	function onGameKeydown( event ) {
		const scheduled = overlayRoot ? overlayRoot.querySelector( '[data-dsa-scheduled-game]' ) : null;
		if ( scheduled && ! activeGame ) {
			event.preventDefault();
			startGame( scheduled.dataset.dsaScheduledGame || 'dino' );
			return;
		}

		if ( ! activeGame ) {
			return;
		}

		if ( event.code === 'Space' || event.code === 'ArrowUp' ) {
			event.preventDefault();
			activeGame.input();
		}

		if ( event.code === 'ArrowLeft' || event.code === 'ArrowRight' ) {
			event.preventDefault();
			if ( typeof activeGame.move === 'function' ) {
				activeGame.move( event.code === 'ArrowLeft' ? -1 : 1 );
			}
		}
	}

	function startGame( id ) {
		if ( gameStarting ) {
			return;
		}

		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-game-panel]' ) : null;
		const canvas = panel ? panel.querySelector( '[data-dsa-game-canvas]' ) : null;

		if ( ! canvas ) {
			return;
		}

		if ( gameRewardsEnabled() ) {
			gameStarting = true;
			setGameMessage( 'Checking reward attempts...' );
			dsaPost( '/rewards/session', {
				game: id,
				visitorId: rewardVisitorId(),
			} ).then( function ( session ) {
				gameRewardSession = session;
				gameStarting = false;
				beginGame( id, canvas, panel );
			} ).catch( function ( error ) {
				gameStarting = false;
				gameRewardSession = null;
				setGameMessage( error.message || 'Reward check failed. Try again shortly.' );
			} );
			return;
		}

		beginGame( id, canvas, panel );
	}

	function loadGameEngine() {
		if ( ! gameEnginePromise ) {
			const moduleUrl = String( gamesConfig.moduleUrl || '' );
			gameEnginePromise = moduleUrl ? import( moduleUrl ) : Promise.reject( new Error( 'Game engine module is unavailable.' ) );
		}
		return gameEnginePromise;
	}

	function beginGame( id, canvas, panel ) {
		id = id === 'star' ? 'star' : 'dino';

		stopGame( false );
		gameStarting = true;
		setGameMessage( 'Loading game...' );
		loadGameEngine().then( function ( engine ) {
			if ( ! panel.isConnected || ! canvas.isConnected ) return;
			window.addEventListener( 'keydown', onGameKeydown );
			window.clearTimeout( scheduledGameTimer );
			const start = panel.querySelector( '[data-dsa-game-start]' );
			if ( start ) start.hidden = true;
			gameAttempts += 1;
			gameCurrentId = id;
			gameStartedAt = Date.now();
			activeGame = engine.createGame( id, canvas, {
				hero: theme.hero_text_color || 'rgba(20,24,34,0.18)',
				active: getComputedStyle( document.documentElement ).getPropertyValue( '--dsa-active-color' ).trim() || '#8f8f98',
				hover: getComputedStyle( document.documentElement ).getPropertyValue( '--dsa-hover-color' ).trim() || '#24c6a1',
			} );
			gameStarting = false;
			setGameMessage( activeGame.title + ': tap, click, or use keyboard.' );
			recordMetric( 'game_start', id );
			gameLoop();
		} ).catch( function ( error ) {
			gameStarting = false;
			setGameMessage( error && error.message ? error.message : 'The game could not start.' );
		} );
	}

	function stopGame( resetReward ) {
		resetReward = resetReward !== false;
		if ( gameFrame ) {
			window.cancelAnimationFrame( gameFrame );
			gameFrame = 0;
		}
		activeGame = null;
		if ( resetReward ) {
			gameCurrentId = '';
			gameStartedAt = 0;
			gameRewardSession = null;
		}
		window.removeEventListener( 'keydown', onGameKeydown );
	}

	function openScheduledGameSurface() {
		if ( ! overlayRoot || ! shouldShowScheduledGame() ) {
			return;
		}

		if ( ! presentationModules.has( 'games' ) ) {
			loadPresentationModule( 'games' ).then( openScheduledGameSurface ).catch( function ( error ) {
				debugLog( 'games panel module failed', { message: error && error.message ? error.message : String( error || '' ) } );
			} );
			return;
		}

		if ( ! enterSurfaceMode( 'game' ) ) {
			return;
		}

		if ( loader && ! loader.hidden ) {
			hideLoader( true );
		}

		if ( ! overlayRoot.hidden ) {
			closeOverlay();
			if ( ! enterSurfaceMode( 'game' ) ) {
				return;
			}
		}

		activeOverlayMode = 'game';
		activeOverlayModuleId = 'games';
		activeOverlayPanel = 'games';
		activeOverlayLifecycle = createSurfaceLifecycle( getSurfaceModule( 'games' ), 'game', gamesConfig.startTitle || 'Game' );
		window.DSA.surfaceLifecycle.current = Object.assign( {}, activeOverlayLifecycle );
		gameAttempts = 0;
		gameBestScore = 0;
		dispatchSurfaceLifecycle( 'before-render', { reason: 'scheduled_game' } );
		overlayRoot.innerHTML = renderGamesPanel( gamesConfig.startTitle || 'Game', scheduledGameId() );
		applyOverlayHeadingTag();
		dispatchSurfaceLifecycle( 'render', { reason: 'scheduled_game' } );
		recordMetric( 'game_surface_show', scheduledGameId() );
		overlayRoot.hidden = false;
		setOverlayActive( true );
		document.addEventListener( 'keydown', onOverlayKeydown );
		bindGamesPanel();
		dispatchSurfaceLifecycle( 'mount', { reason: 'scheduled_game' } );
		claimSurfaceHistoryEntry( 'games' );

		const panel = overlayRoot.querySelector( '[role="dialog"]' );
		if ( panel ) {
			panel.tabIndex = -1;
			panel.focus( { preventScroll: true } );
		}

		const duration = scheduledGameDuration();
		if ( duration > 0 ) {
			window.clearTimeout( scheduledGameTimer );
			scheduledGameTimer = window.setTimeout( function () {
				if ( ! activeGame ) {
					closeOverlay();
				}
			}, duration );
		}
	}

	function shouldShowScheduledGame() {
		if ( isProtectedFlowActive() || ! surfaceTriggerEnabled( 'scheduled_game', gamesConfig.surfaceEnabled && gamesConfig.showOnPageLoad ) ) {
			return false;
		}

		const rule = getSurfaceTrigger( 'scheduled_game' );
		const trigger = String( ( rule && rule.path ) || gamesConfig.triggerPath || '' ).trim();
		if ( ! trigger ) {
			return false;
		}

		return window.location.pathname.indexOf( trigger ) !== -1 || window.location.href.indexOf( trigger ) !== -1;
	}

	function scheduledGameId() {
		const payload = surfaceTriggerPayload( 'scheduled_game' );
		const rule = getSurfaceTrigger( 'scheduled_game' );
		return payload.game || ( rule && rule.module ) || gamesConfig.triggerGame || 'dino';
	}

	function scheduledGameDuration() {
		const rule = getSurfaceTrigger( 'scheduled_game' );
		return Number( rule && rule.durationMs ) || Number( gamesConfig.durationMs ) || 0;
	}

	function isCoarsePointer() {
		return window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches;
	}

	function isProtectedFlowActive() {
		return Boolean( protectedFlow && protectedFlow.active );
	}

	function triggerRules() {
		return Array.isArray( surfaceTriggers.rules ) ? surfaceTriggers.rules : [];
	}

	function getSurfaceTrigger( id ) {
		return triggerRules().find( function ( rule ) {
			return rule && rule.id === id;
		} ) || null;
	}

	function surfaceTriggerEnabled( id, fallback ) {
		const rule = getSurfaceTrigger( id );

		if ( ! rule ) {
			return Boolean( fallback );
		}

		return Boolean( rule.enabled );
	}

	function surfaceTriggerPayload( id ) {
		const rule = getSurfaceTrigger( id );
		return rule && rule.payload && typeof rule.payload === 'object' ? rule.payload : {};
	}

	function gameLoop() {
		if ( ! activeGame ) {
			return;
		}

		activeGame.update();
		activeGame.draw();
		updateGameHud( activeGame.score );

		if ( activeGame.over ) {
			finishGame( activeGame.score );
			return;
		}

		gameFrame = window.requestAnimationFrame( gameLoop );
	}

	function finishGame( score ) {
		gameBestScore = Math.max( gameBestScore, score );
		updateGameHud( score );
		const attempt = Math.min( 3, Math.max( 1, gameAttempts ) );
		const bonus = currentBonus( attempt );
		const retry = ( gamesConfig.retryTexts || [] )[attempt - 1] || '';
		const rewardText = gameRewardsEnabled() ? bonus.discount + '% discount' : 'reward preview';
		const localMessage = 'Score ' + score + '. ' + bonus.label + ': ' + rewardText + '. ' + retry;
		setGameMessage( localMessage );

		if ( attempt >= 3 && gamesConfig.confettiEnabled ) {
			blastConfetti();
		}

		recordMetric( 'game_complete', gameCurrentId || 'game', score );
		submitRewardAttempt( score, localMessage );
		activeGame = null;
		gameFrame = 0;
	}

	function submitRewardAttempt( score, fallbackMessage ) {
		if ( ! gameRewardsEnabled() || ! gameRewardSession || ! gameRewardSession.token ) {
			return;
		}

		const payload = {
			token: gameRewardSession.token,
			game: gameCurrentId || scheduledGameId() || 'dino',
			score: score,
			durationMs: gameStartedAt ? Date.now() - gameStartedAt : 0,
			visitorId: rewardVisitorId(),
		};

		setGameMessage( fallbackMessage + ' Verifying reward...' );
		dsaPost( '/rewards/attempt', payload )
			.then( function ( result ) {
				const bonus = result.bonus || {};
				let message = result.message || fallbackMessage;
				if ( result.coupon && result.coupon.code ) {
					message += ' Code: ' + result.coupon.code + '.';
				} else if ( bonus.discount ) {
					message += ' Reward: ' + bonus.discount + '%.';
				}
				if ( result.remaining > 0 ) {
					message += ' Attempts left today: ' + result.remaining + '.';
				}
				setGameMessage( message );
				recordMetric( 'game_reward_verified', payload.game, bonus.discount || 0 );
				gameRewardSession = null;
			} )
			.catch( function ( error ) {
				setGameMessage( ( error && error.message ) || 'Reward verification failed. Your score was not used for a coupon.' );
				gameRewardSession = null;
			} );
	}

	function gameRewardsEnabled() {
		return Boolean( gamesConfig.reward && gamesConfig.reward.enabled );
	}

	function rewardVisitorId() {
		const key = 'dsa_reward_visitor';
		try {
			let id = window.localStorage ? window.localStorage.getItem( key ) : '';
			if ( ! id ) {
				id = 'v' + Math.random().toString( 36 ).slice( 2 ) + Date.now().toString( 36 );
				if ( window.localStorage ) {
					window.localStorage.setItem( key, id );
				}
			}
			return id;
		} catch ( error ) {
			return 'session-' + Date.now().toString( 36 );
		}
	}

	function updateGameHud( score ) {
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-game-panel]' ) : null;
		if ( ! panel ) {
			return;
		}
		const scoreNode = panel.querySelector( '[data-dsa-game-score]' );
		const bestNode = panel.querySelector( '[data-dsa-game-best]' );
		const bonusNode = panel.querySelector( '[data-dsa-game-bonus]' );
		if ( scoreNode ) scoreNode.textContent = 'Score ' + score;
		if ( bestNode ) bestNode.textContent = 'Best ' + Math.max( gameBestScore, score );
		if ( bonusNode ) bonusNode.textContent = nextBonusLabel( gameAttempts );
	}

	function setGameMessage( message ) {
		const node = overlayRoot ? overlayRoot.querySelector( '[data-dsa-game-message]' ) : null;
		if ( node ) {
			node.textContent = message;
		}
	}

	function currentBonus( attempt ) {
		const bonuses = Array.isArray( gamesConfig.bonuses ) ? gamesConfig.bonuses : [];
		return bonuses[Math.max( 0, Math.min( bonuses.length - 1, attempt - 1 ) )] || { label: 'Bonus', discount: 0 };
	}

	function nextBonusLabel( attempt ) {
		const bonus = currentBonus( Math.min( 3, Math.max( 1, attempt + 1 ) ) );
		return bonus.discount ? 'Next bonus ' + bonus.discount + '%' : 'Bonus ready';
	}

	function blastConfetti( target, variant ) {
		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}

		const panel = target || ( variant === 'cart' ? cartConfettiTarget() : ( overlayRoot ? overlayRoot.querySelector( '[data-dsa-game-panel]' ) : null ) );
		if ( ! panel || typeof panel.appendChild !== 'function' ) {
			return;
		}
		const layer = document.createElement( 'div' );
		layer.className = 'dsa-confetti-layer' + ( variant ? ' dsa-confetti-layer--' + variant : '' );
		const color = getConfettiColor();
		for ( let i = 0; i < 42; i++ ) {
			const piece = document.createElement( 'i' );
			piece.style.left = Math.round( Math.random() * 100 ) + '%';
			piece.style.setProperty( '--dsa-confetti-delay', ( Math.random() * 0.35 ).toFixed( 2 ) + 's' );
			piece.style.setProperty( '--dsa-confetti-x', Math.round( -80 + Math.random() * 160 ) + 'px' );
			piece.style.background = color;
			layer.appendChild( piece );
		}
		panel.appendChild( layer );
		window.setTimeout( function () { layer.remove(); }, 1700 );
	}

	function cartConfettiTarget() {
		return ( overlayRoot && ! overlayRoot.hidden && overlayRoot.querySelector( '[data-dsa-cart-panel], [data-dsa-checkout-panel]' ) ) || document.body;
	}

	function firstCartConfettiEnabled() {
		return ! ( commerce.settings && commerce.settings.firstCartConfettiEnabled === false );
	}

	function playFirstCartConfetti( reason ) {
		if ( ! firstCartConfettiEnabled() || firstCartConfettiPlayedForCart ) {
			return false;
		}

		firstCartConfettiPlayedForCart = true;
		firstCartConfettiQueued = false;
		window.requestAnimationFrame( function () {
			blastConfetti( cartConfettiTarget(), 'cart' );
			debugLog( 'first cart confetti played', { reason: reason || 'cart' } );
		} );
		return true;
	}

	function getConfettiColor() {
		const root = getComputedStyle( document.documentElement );
		if ( theme.confetti_color_source === 'hover' ) {
			return root.getPropertyValue( '--dsa-hover-color' ).trim() || '#24c6a1';
		}
		if ( theme.confetti_color_source === 'active' ) {
			return root.getPropertyValue( '--dsa-active-color' ).trim() || '#8f8f98';
		}
		return root.getPropertyValue( '--dsa-hero-text-color' ).trim() || 'rgba(20,24,34,0.18)';
	}

	function bindLinksPanel() {
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-links-panel], .dsa-links-panel' ) : null;

		if ( ! panel ) {
			return;
		}

		const cartButton = panel.querySelector( '[data-dsa-links-cart]' );
		if ( cartButton ) {
			cartButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				openOverlay( 'cart', 'Cart' );
			} );
		}

		if ( ! linksHub.canEdit ) {
			return;
		}

		const edit = panel.querySelector( '[data-dsa-links-edit]' );
		const view = panel.querySelector( '[data-dsa-links-view]' );
		const form = panel.querySelector( '[data-dsa-links-form]' );
		const logoInput = panel.querySelector( '[data-dsa-links-logo]' );

		if ( edit ) {
			edit.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				openLinksEditor();
			} );
		}

		if ( view ) {
			view.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				openLinksView();
			} );
		}

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				updateLinks( form );
			} );
		}

		if ( logoInput ) {
			logoInput.addEventListener( 'change', function () {
				updateLinksLogo( logoInput );
			} );
		}
	}

	function openLinksEditor() {
		if ( ! overlayRoot ) {
			return;
		}

		replaceOverlayContent( renderLinksEditor(), { reason: 'links_editor', module: getSurfaceModule( 'links' ), label: 'Links' } );
		bindLinksPanel();
		const panel = overlayRoot.querySelector( '[role="dialog"]' );
		if ( panel ) {
			panel.tabIndex = -1;
			panel.focus( { preventScroll: true } );
		}
	}

	function openLinksView() {
		if ( ! overlayRoot ) {
			return;
		}

		replaceOverlayContent( renderLinksPanel( 'Links' ), { reason: 'links_view', module: getSurfaceModule( 'links' ), label: 'Links' } );
		bindLinksPanel();
		const panel = overlayRoot.querySelector( '[role="dialog"]' );
		if ( panel ) {
			panel.tabIndex = -1;
			panel.focus( { preventScroll: true } );
		}
	}

	function updateLinks( form ) {
		const message = form.querySelector( '[data-dsa-links-message]' );
		const socialLinks = {};

		form.querySelectorAll( '[data-dsa-social-id]' ).forEach( function ( input ) {
			socialLinks[input.dataset.dsaSocialId] = input.value;
		} );

		if ( message ) {
			message.textContent = 'Saving...';
		}

		dsaPost( '/links', {
			siteScore: form.querySelector( '[name="siteScore"]' ).value,
			shopLabel: form.querySelector( '[name="shopLabel"]' ).value,
			shopUrl: form.querySelector( '[name="shopUrl"]' ).value,
			postsTitle: form.querySelector( '[name="postsTitle"]' ).value,
			postsCategory: form.querySelector( '[name="postsCategory"]' ).value,
			sslProvider: form.querySelector( '[name="sslProvider"]' ).value,
			paymentProvider: form.querySelector( '[name="paymentProvider"]' ).value,
			reviewSource: form.querySelector( '[name="reviewSource"]' ).value,
			googlePlaceId: form.querySelector( '[name="googlePlaceId"]' ).value,
			googleApiKey: form.querySelector( '[name="googleApiKey"]' ).value,
			testimonials: form.querySelector( '[name="testimonials"]' ).value,
			socialLinks: socialLinks,
		} )
			.then( function ( response ) {
				linksHub = Object.assign( {}, linksHub, response.links || {} );
				initializeLinksDockIcon();
				openLinksView();
			} )
			.catch( function ( error ) {
				if ( message ) {
					message.textContent = error.message || 'Could not save links.';
				}
			} );
	}

	function updateLinksLogo( input ) {
		const file = input.files && input.files[0];
		const panel = input.closest( '[data-dsa-links-panel]' );
		const message = panel ? panel.querySelector( '[data-dsa-links-message]' ) : null;

		if ( ! file ) {
			return;
		}

		if ( message ) {
			message.textContent = 'Uploading logo...';
		}

		const formData = new FormData();
		formData.append( 'logo', file );

		dsaUpload( '/links/logo', formData )
			.then( function ( response ) {
				if ( response.logo ) {
					linksHub.logo = response.logo;
				}
				openLinksEditor();
			} )
			.catch( function ( error ) {
				if ( message ) {
					message.textContent = error.message || 'Could not upload logo.';
				}
			} );
	}

	function bindProfilePanel() {
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-profile-panel]' ) : null;

		if ( ! panel ) {
			return;
		}

		const form = panel.querySelector( '[data-dsa-profile-form]' );
		const logout = panel.querySelector( '[data-dsa-account-logout]' );
		const avatarInput = panel.querySelector( '[data-dsa-avatar-input]' );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				updateProfile( form );
			} );
		}

		if ( avatarInput ) {
			avatarInput.addEventListener( 'change', function () {
				updateAvatar( avatarInput );
			} );
		}

		panel.addEventListener( 'click', function ( event ) {
			const profileEdit = closestEventTarget( event, '[data-dsa-profile-edit]' );
			if ( profileEdit && panel.contains( profileEdit ) ) {
				event.preventDefault();
				event.stopPropagation();
				const editRegion = panel.querySelector( '[data-dsa-profile-edit-region]' );
				if ( editRegion ) {
					const expanded = editRegion.hidden;
					editRegion.hidden = ! expanded;
					profileEdit.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
					if ( expanded ) {
						const firstField = editRegion.querySelector( 'input, button, select, textarea' );
						if ( firstField && typeof firstField.focus === 'function' ) {
							firstField.focus( { preventScroll: false } );
						}
					}
				}
				return;
			}
			const notificationPreferences = closestEventTarget( event, '[data-dsa-profile-notifications]' );
			if ( notificationPreferences && panel.contains( notificationPreferences ) ) {
				event.preventDefault();
				event.stopPropagation();
				openOverlay( 'notifications', 'Personalize your Appsite' );
				return;
			}
			const emailConfirm = closestEventTarget( event, '[data-dsa-profile-email-confirm]' );
			if ( emailConfirm && panel.contains( emailConfirm ) ) {
				event.preventDefault();
				event.stopPropagation();
				confirmProfileEmail( emailConfirm );
				return;
			}
			handleAccountContextClick( event, panel );
		} );

		if ( logout ) {
			logout.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				window.location.href = phonekey.logoutUrl || '/wp-login.php?action=logout';
			} );
		}

		loadRecentOrders( panel );
		refreshProfileContextBadges();
	}

	function handleAccountContextClick( event, scope ) {
		const accountButton = closestEventTarget( event, '[data-dsa-account-view]' );
		const logoutButton = closestEventTarget( event, '[data-dsa-account-logout]' );
		const target = accountButton || logoutButton;

		if ( ! target ) {
			return false;
		}

		const allowedScope = scope || surface;
		if ( ! allowedScope.contains( target ) ) {
			return false;
		}

		const profileActive = overlayRoot
			&& ! overlayRoot.hidden
			&& ( overlayRoot.querySelector( '[data-dsa-profile-panel]' ) || ( dockContext && dockContext.dataset.dsaContext === 'profile' ) );
		if ( ! profileActive ) {
			return false;
		}

		event.preventDefault();
		event.stopPropagation();

		if ( logoutButton ) {
			window.location.href = phonekey.logoutUrl || '/wp-login.php?action=logout';
			return true;
		}

		openAccountView( accountButton.dataset.dsaAccountView );
		return true;
	}

	function loadRecentOrders( panel ) {
		const target = panel ? panel.querySelector( '[data-dsa-recent-orders]' ) : null;
		if ( ! target || ! ( phonekey.cart && phonekey.cart.available ) ) return;
		dsaGet( '/account/orders' ).then( function ( response ) {
			setProfileMetric( 'orders', ( response.orders || [] ).length );
			if ( target.isConnected ) target.innerHTML = renderRecentOrdersRail( response.orders || [] );
		} ).catch( function () {
			if ( target.isConnected ) target.innerHTML = '<div class="dsa-recent-orders__head"><strong>Recent orders</strong><span>Unavailable</span></div>';
		} );
	}

	function renderRecentOrdersRail( orders ) {
		if ( ! orders.length ) return '<div class="dsa-recent-orders__head"><strong>Recent orders</strong><span>No orders yet</span></div>';
		return [
			'<div class="dsa-recent-orders__head"><strong>Recent orders</strong><span>' + escapeHtml( orders.length ) + '</span></div>',
			'<div class="dsa-recent-orders__rail">',
			orders.slice( 0, 6 ).map( function ( order ) {
				const statusClass = String( order.status || '' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' );
				return '<article class="dsa-recent-order"><div><strong>#' + escapeHtml( order.number || '' ) + '</strong><span class="dsa-order-status is-' + escapeHtml( statusClass ) + '">' + escapeHtml( order.status || '' ) + '</span></div><small>' + escapeHtml( order.date || '' ) + '</small><b>' + escapeHtml( order.total || '' ) + '</b><div class="dsa-recent-order__items">' + ( order.items || [] ).slice( 0, 3 ).map( function ( item ) { return item.image ? '<img src="' + escapeHtml( item.image ) + '" alt="' + escapeHtml( item.name || '' ) + '">' : ''; } ).join( '' ) + '</div></article>';
			} ).join( '' ),
			'</div>',
			'<button class="dsa-recent-orders__all" type="button" data-dsa-account-view="orders">View all ' + lucideIcon( 'arrow-right', 'dsa-recent-orders__arrow' ) + '</button>',
		].join( '' );
	}

	function profileDownloadsSeenKey() {
		const user = phonekey.user || {};
		return 'kiwe:downloads:seen:' + aiStringHash( String( user.email || user.userLogin || 'account' ) );
	}

	function downloadSignature( downloads ) {
		return aiStringHash( JSON.stringify( ( downloads || [] ).map( function ( item ) {
			return [ item.productName || '', item.downloadName || '', item.url || '', item.remaining || '' ];
		} ) ) );
	}

	function setProfileContextBadge( name, value ) {
		document.querySelectorAll( '[data-dsa-profile-badge="' + name + '"]' ).forEach( function ( badge ) {
			badge.hidden = ! value;
			if ( value ) badge.textContent = String( value );
		} );
	}

	function setProfileMetric( name, value ) {
		document.querySelectorAll( '[data-dsa-profile-stat="' + name + '"]' ).forEach( function ( metric ) {
			metric.textContent = value === 0 || value ? String( value ) : '—';
		} );
	}

	function refreshProfileContextBadges() {
		if ( ! ( phonekey.cart && phonekey.cart.available ) ) return;
		dsaGet( '/account/addresses' ).then( function ( response ) {
			const fields = [ 'address_1', 'city', 'postcode', 'country' ];
			const configured = [ response.billing || {}, response.shipping || {} ].some( function ( address ) {
				return fields.every( function ( field ) { return String( address[ field ] || '' ).trim() !== ''; } );
			} );
			setProfileContextBadge( 'addresses', configured ? 0 : '!' );
		} ).catch( function () {} );

		dsaGet( '/account/downloads' ).then( function ( response ) {
			const downloads = response.downloads || [];
			let seen = '';
			try { seen = window.localStorage ? window.localStorage.getItem( profileDownloadsSeenKey() ) || '' : ''; } catch ( error ) {}
			setProfileMetric( 'downloads', downloads.length );
			setProfileContextBadge( 'downloads', downloads.length && downloadSignature( downloads ) !== seen ? downloads.length : 0 );
		} ).catch( function () {} );
	}

	function bindCartPanel() {
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-cart-panel]' ) : null;

		if ( ! panel ) {
			return;
		}

		panel.querySelectorAll( '[data-dsa-cart-quantity]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();

				if ( panel.classList.contains( 'is-updating' ) ) {
					return;
				}

				surfaceFeedback( 'quantity' );
				updateCartQuantity( button.dataset.dsaCartQuantity, button.dataset.dsaCartNext, panel, button );
			} );
		} );

		panel.querySelectorAll( '[data-dsa-cart-add]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				if ( panel.classList.contains( 'is-updating' ) ) {
					return;
				}
				surfaceFeedback( 'cart' );
				addCartRecommendation( button.dataset.dsaCartAdd, panel, button.dataset.dsaCartTrigger || '' );
			} );
		} );

		panel.querySelectorAll( '[data-dsa-cart-claim]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				if ( panel.classList.contains( 'is-updating' ) ) {
					return;
				}
				claimCartUpsell( button.dataset.dsaCartClaim, panel, button.dataset.dsaCartTrigger || '' );
			} );
		} );

		const checkoutButton = panel.querySelector( '[data-dsa-checkout-open]' );
		if ( checkoutButton ) {
			checkoutButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();

				if ( commerce.settings && commerce.settings.checkoutSurfaceEnabled ) {
					openCheckoutSurface();
					return;
				}

				closeOverlay();
				clearSurfaceReturnState();
				navigateWithFullPageLoader( checkoutButton.href );
			} );
		}
	}

	function addCartRecommendation( productId, panel, triggerId ) {
		if ( ! productId ) {
			return;
		}

		let cartSequence = 0;
		enqueueCartMutation( function () {
			cartSequence = nextCartSequence();
			setCartPanelBusy( panel, true );
			return dsaPost( '/cart/add', {
				productId: Number( productId ) || 0,
				quantity: 1,
				triggerId: Number( triggerId ) || 0,
			} );
		} )
			.then( function ( response ) {
				if ( response.cart ) {
					applyCartPayload( response, { rerender: true, cartMutation: true, sequence: cartSequence } );
					applyWooCartFragments( response, 'added_to_cart' );
				}
			} )
			.catch( function ( error ) {
				const message = panel ? panel.querySelector( '[data-dsa-cart-message]' ) : null;

				if ( message ) {
					message.textContent = error.message;
				}
				showCartActionError( panel, '[data-dsa-cart-add="' + cssEscape( productId ) + '"]', error.message );
				refreshCartState( { rerender: true } );
			} )
			.finally( function () {
				setCartPanelBusy( panel, false );
			} );
	}

	function addProductToCart( productId, source ) {
		if ( ! productId ) return Promise.reject( new Error( 'Product is unavailable.' ) );
		surfaceFeedback( 'cart' );
		let cartSequence = 0;
		return enqueueCartMutation( function () {
			cartSequence = nextCartSequence();
			return dsaPost( '/cart/add', {
				productId: Number( productId ) || 0,
				quantity: 1,
				source: String( source || 'dsa_cart' ),
			} );
		} ).then( function ( response ) {
			if ( response.cart ) {
				applyCartPayload( response, { rerender: activeOverlayPanel === 'cart', cartMutation: true, sequence: cartSequence } );
				applyWooCartFragments( response, 'added_to_cart' );
			}
			return response;
		} );
	}

	function openCheckoutSurface( options ) {
		options = options || {};
		checkoutState = {
			contract: null,
			errors: options.errors || {},
			notices: options.notices || [],
			loading: true,
			returnToPage: options.returnToPage !== undefined ? Boolean( options.returnToPage ) : isCurrentCheckoutPage(),
		};

		clearSurfaceReturnState();
		openOverlay( 'checkout', 'Checkout' );
		refreshCartState( { rerender: false } ).then( function () {
			syncAiInsights( 'checkout', true );
		} );
	}

	function bindCheckoutPanel() {
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-checkout-panel]' ) : null;

		if ( ! panel ) {
			return;
		}

		if ( ! checkoutState.contract ) {
			loadCheckoutContract();
			return;
		}

		const form = panel.querySelector( '[data-dsa-checkout-form]' );
		if ( ! form ) {
			return;
		}

		form.addEventListener( 'input', function () {
			queueCheckoutDraftSave( form );
		} );

		form.addEventListener( 'change', function ( event ) {
			const field = closestEventTarget( event, '[data-dsa-checkout-field]' );
			if ( field && field.hasAttribute( 'data-dsa-checkout-shipping-toggle' ) ) {
				saveCheckoutDraft( form, false, true );
				return;
			}
			if ( field && field.hasAttribute( 'data-dsa-checkout-account-toggle' ) ) {
				saveCheckoutDraft( form, false, true );
				return;
			}
			if ( field && ( field.dataset.dsaCheckoutType === 'country' || /_country$/.test( field.name || '' ) ) ) {
				saveCheckoutDraft( form, false, true );
				return;
			}

			queueCheckoutDraftSave( form );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			saveCheckoutDraft( form, true, true );
		} );
	}

	function loadCheckoutContract() {
		const lifecycleSequence = activeOverlayLifecycle ? activeOverlayLifecycle.sequence : 0;
		const checkoutSequence = ++checkoutRequestSequence;
		if ( ! data.restUrl ) {
			if ( ! overlayLifecycleCurrent( lifecycleSequence ) ) return;
			checkoutState.loading = false;
			checkoutState.contract = { available: false };
			renderCheckoutSurface();
			return;
		}

		dsaGet( '/checkout?consumeErrors=1' )
			.then( function ( response ) {
				if ( ! overlayLifecycleCurrent( lifecycleSequence ) || checkoutSequence < checkoutAppliedSequence ) {
					return;
				}
				checkoutAppliedSequence = checkoutSequence;
				const explicitErrors = checkoutState.errors || {};
				const explicitNotices = checkoutState.notices || [];
				checkoutState.contract = response.checkout || { available: false };
				checkoutState.loading = false;
				checkoutState.errors = Object.assign( {}, checkoutState.contract.errors || {}, explicitErrors );
				checkoutState.notices = uniqueStrings( ( checkoutState.contract.notices || [] ).concat( explicitNotices ) );
				renderCheckoutSurface();
			} )
			.catch( function ( error ) {
				if ( ! overlayLifecycleCurrent( lifecycleSequence ) || checkoutSequence < checkoutAppliedSequence ) {
					return;
				}
				checkoutAppliedSequence = checkoutSequence;
				checkoutState.loading = false;
				checkoutState.contract = { available: false };
				checkoutState.notices = [ error.message || 'Checkout could not load.' ];
				renderCheckoutSurface();
			} );
	}

	function queueCheckoutDraftSave( form ) {
		window.clearTimeout( checkoutDraftTimer );
		checkoutDraftTimer = window.setTimeout( function () {
			saveCheckoutDraft( form, false, false );
		}, 360 );
	}

	function saveCheckoutDraft( form, validate, rerender ) {
		if ( ! form ) {
			return Promise.resolve( null );
		}

		window.clearTimeout( checkoutDraftTimer );
		checkoutDraftTimer = 0;
		const button = form.querySelector( 'button[type="submit"]' );
		const message = form.querySelector( '[data-dsa-checkout-message]' );

		if ( button && validate ) {
			button.disabled = true;
		}
		if ( message && validate ) {
			message.textContent = 'Checking details...';
		}

		const lifecycleSequence = activeOverlayLifecycle ? activeOverlayLifecycle.sequence : 0;
		const checkoutSequence = ++checkoutRequestSequence;
		return dsaPost( '/checkout', {
			fields: collectCheckoutValues( form ),
			validate: Boolean( validate ),
		} ).then( function ( response ) {
			if ( ! overlayLifecycleCurrent( lifecycleSequence ) || checkoutSequence < checkoutAppliedSequence ) {
				return response;
			}
			checkoutAppliedSequence = checkoutSequence;
			if ( response && response.checkout ) {
				checkoutState.contract = response.checkout;
				if ( validate ) {
					checkoutState.errors = response.errors || response.checkout.errors || {};
					checkoutState.notices = response.notices || response.checkout.notices || [];
				}
			}

			if ( validate && ( response.valid === false || Object.keys( checkoutState.errors ).length || checkoutState.notices.length ) ) {
				renderCheckoutSurface();
				focusFirstCheckoutError();
				return response;
			}

			if ( validate ) {
				completeCheckoutSurface();
			} else if ( rerender ) {
				renderCheckoutSurface();
			}

			return response;
		} ).catch( function ( error ) {
			if ( ! overlayLifecycleCurrent( lifecycleSequence ) || checkoutSequence < checkoutAppliedSequence ) {
				return null;
			}
			checkoutAppliedSequence = checkoutSequence;
			if ( message ) {
				message.textContent = error.message || 'Could not save checkout details.';
			}
			return null;
		} ).finally( function () {
			if ( button && button.isConnected ) {
				button.disabled = false;
			}
		} );
	}

	function collectCheckoutValues( form ) {
		const values = {};

		form.querySelectorAll( '[data-dsa-checkout-field]' ).forEach( function ( field ) {
			values[ field.name ] = field.type === 'checkbox' ? ( field.checked ? '1' : '0' ) : field.value;
		} );

		return values;
	}

	function completeCheckoutSurface() {
		const contract = checkoutState.contract || {};
		const checkoutUrl = contract.checkoutUrl || ( commerce.routes && commerce.routes.checkoutUrl ) || '';

		if ( checkoutState.returnToPage && isCurrentCheckoutPage() ) {
			checkoutPageDraftValues = Object.assign( {}, contract.values || {} );
			applyCheckoutValuesToPage( checkoutPageDraftValues, true );
			closeOverlay();
			window.setTimeout( triggerWooCheckoutRefresh, 30 );
			const placeOrder = document.querySelector( '#place_order, .wc-block-components-checkout-place-order-button' );
			if ( placeOrder && typeof placeOrder.focus === 'function' ) {
				placeOrder.focus( { preventScroll: false } );
			}
			return;
		}

		if ( checkoutUrl ) {
			closeOverlay();
			clearSurfaceReturnState();
			navigateWithFullPageLoader( checkoutUrl );
		}
	}

	function renderCheckoutSurface() {
		if ( ! overlayRoot || overlayRoot.hidden || activeOverlayMode !== 'dock' ) {
			return;
		}

		const module = getSurfaceModule( 'checkout' );
		replaceOverlayContent( renderCheckoutPanel(), { reason: 'checkout_render', module: module, label: 'Checkout' } );
		if ( module && hydrateLazyPresentation( module ) ) return;
		bindCheckoutPanel();
	}

	function focusFirstCheckoutError() {
		window.setTimeout( function () {
			const field = overlayRoot ? overlayRoot.querySelector( '.dsa-checkout-field.has-error [data-dsa-checkout-field]' ) : null;
			if ( field ) {
				field.focus();
			}
		}, 20 );
	}

	function updateCartQuantity( key, quantity, panel, button ) {
		if ( ! key ) {
			return;
		}

		let cartSequence = 0;
		enqueueCartMutation( function () {
			cartSequence = nextCartSequence();
			setCartPanelBusy( panel, true );
			return dsaPost( '/cart/item', {
				key: key,
				quantity: Number( quantity ) || 0,
				productId: button ? button.dataset.dsaCartProduct || '' : '',
				variationId: button ? button.dataset.dsaCartVariation || '' : '',
			} );
		} )
			.then( function ( response ) {
				if ( response.cart ) {
					applyCartPayload( response, { rerender: true, cartMutation: true, sequence: cartSequence } );
					applyWooCartFragments( response, Number( quantity ) <= 0 ? 'removed_from_cart' : 'updated_cart_totals' );
				}
			} )
			.catch( function ( error ) {
				const message = panel ? panel.querySelector( '[data-dsa-cart-message]' ) : null;

				if ( message ) {
					message.textContent = error.message;
				}
				showCartActionError( panel, '[data-dsa-cart-quantity="' + cssEscape( key ) + '"]', error.message );
				refreshCartState( { rerender: true } );
			} )
			.finally( function () {
				setCartPanelBusy( panel, false );
			} );
	}

	function claimCartUpsell( productId, panel, triggerId ) {
		if ( ! productId || ! triggerId ) {
			return;
		}

		let cartSequence = 0;
		enqueueCartMutation( function () {
			cartSequence = nextCartSequence();
			setCartPanelBusy( panel, true );
			return dsaPost( '/cart/upsell/claim', {
				productId: Number( productId ) || 0,
				triggerId: Number( triggerId ) || 0,
			} );
		} )
			.then( function ( response ) {
				if ( response.cart ) {
					applyCartPayload( response, { rerender: true, cartMutation: true, sequence: cartSequence } );
					applyWooCartFragments( response, 'wc_fragments_refreshed' );
				}
			} )
			.catch( function ( error ) {
				const message = panel ? panel.querySelector( '[data-dsa-cart-message]' ) : null;

				if ( message ) {
					message.textContent = error.message;
				}
				refreshCartState( { rerender: true } );
			} )
			.finally( function () {
				setCartPanelBusy( panel, false );
			} );
	}

	function setCartPanelBusy( panel, busy ) {
		if ( ! panel ) {
			return;
		}

		panel.classList.toggle( 'is-updating', Boolean( busy ) );
		panel.querySelectorAll( '[data-dsa-cart-quantity], [data-dsa-cart-add], [data-dsa-cart-claim]' ).forEach( function ( button ) {
			button.disabled = Boolean( busy );
		} );
	}

	function showCartActionError( panel, selector, message ) {
		if ( ! panel || ! selector ) {
			return;
		}

		const button = panel.querySelector( selector );

		if ( ! button ) {
			return;
		}

		const label = button.dataset.dsaOriginalLabel || button.textContent || '';
		button.dataset.dsaOriginalLabel = label;
		button.textContent = message || 'Failed';
		window.setTimeout( function () {
			if ( button.isConnected ) {
				button.textContent = label;
			}
		}, 2600 );
	}

	function cssEscape( value ) {
		const raw = String( value || '' );

		if ( window.CSS && typeof window.CSS.escape === 'function' ) {
			return window.CSS.escape( raw );
		}

		return raw.replace( /["\\]/g, '\\$&' );
	}

	function enqueueCartMutation( task ) {
		const run = cartMutationQueue.catch( function () {
			return null;
		} ).then( task );
		cartMutationQueue = run.catch( function () {
			return null;
		} );

		return run;
	}

	function nextCartSequence() {
		cartRequestSequence += 1;
		return cartRequestSequence;
	}

	function cartOfferKey( offer ) {
		return String( offer && ( offer.triggerId || offer.trigger_id ) || '' ) + ':' + String( offer && offer.id || '' );
	}

	function cartOfferMap( cart ) {
		const map = {};
		( cart && Array.isArray( cart.upsells ) ? cart.upsells : [] ).forEach( function ( offer ) {
			const key = cartOfferKey( offer );
			if ( key !== ':' ) {
				map[ key ] = offer;
			}
		} );
		return map;
	}

	function aiStringHash( value ) {
		let hash = 0;
		String( value || '' ).split( '' ).forEach( function ( character ) {
			hash = ( ( hash << 5 ) - hash + character.charCodeAt( 0 ) ) | 0;
		} );
		return Math.abs( hash ).toString( 36 );
	}

	function discountSignature( summary ) {
		if ( ! summary || ! summary.hasDiscount ) {
			return '';
		}
		return JSON.stringify( {
			total: summary.totalDiscount || '',
			lines: Array.isArray( summary.lines ) ? summary.lines.map( function ( line ) {
				return [ line.type || '', line.label || '', line.amount || '' ];
			} ) : [],
		} );
	}

	function reconcileAiCartState( previousCart, nextCart ) {
		const previousOffers = cartOfferMap( previousCart );
		const nextOffers = cartOfferMap( nextCart );
		let historyChanged = false;
		let memoryChanged = false;
		const nextDiscount = discountSignature( nextCart && nextCart.discountSummary );
		const nextDiscountId = nextDiscount ? 'cart-discount:' + aiStringHash( nextDiscount ) : '';

		aiNotificationHistory = aiNotificationHistory.filter( function ( item ) {
			if ( item.type === 'cart_pair' || String( item.id || '' ).indexOf( 'cart-paired:' ) === 0 ) {
				const key = item.cartOfferKey || String( item.id || '' ).replace( /^cart-paired:/, '' );
				const offer = nextOffers[ key ];
				const state = offer ? String( offer.state || 'pending' ) : '';
				const keep = Boolean( offer ) && ( state === 'eligible' || state === 'applied' );
				historyChanged = historyChanged || ! keep;
				return keep;
			}

			if ( item.type === 'discount_applied' || String( item.id || '' ).indexOf( 'cart-discount:' ) === 0 ) {
				const keep = Boolean( nextDiscountId ) && ( item.discountSignature === nextDiscount || item.id === nextDiscountId );
				historyChanged = historyChanged || ! keep;
				return keep;
			}

			return true;
		} );

		Object.keys( aiInsightMemory.interacted || {} ).forEach( function ( insightId ) {
			if ( insightId.indexOf( 'cart-offer:' ) !== 0 ) {
				return;
			}
			const parts = insightId.split( ':' );
			const key = ( parts[1] || '' ) + ':' + ( parts[2] || '' );
			const previous = previousOffers[ key ];
			const next = nextOffers[ key ];
			const restarted = ! previous && next;
			const removed = previous && ! next;
			const resetToPending = previous && next && String( previous.state || '' ) !== 'pending' && String( next.state || '' ) === 'pending';
			if ( restarted || removed || resetToPending ) {
				delete aiInsightMemory.interacted[ insightId ];
				memoryChanged = true;
			}
		} );

		if ( historyChanged ) {
			saveAiNotificationHistory();
		}
		if ( memoryChanged ) {
			saveAiInsightMemory();
		}

		if ( aiPopoutInsightId.indexOf( 'cart-paired:' ) === 0 || aiPopoutInsightId.indexOf( 'cart-discount:' ) === 0 ) {
			const stillValid = aiNotificationHistory.some( function ( item ) { return item.id === aiPopoutInsightId; } );
			if ( ! stillValid ) {
				hideAiPopout( false, true );
			}
		} else if ( aiPopoutInsightId.indexOf( 'cart-offer:' ) === 0 ) {
			const parts = aiPopoutInsightId.split( ':' );
			const key = ( parts[1] || '' ) + ':' + ( parts[2] || '' );
			const offer = nextOffers[ key ];
			const expectedId = offer ? 'cart-offer:' + key + ':' + String( offer.state || 'pending' ) : '';
			if ( expectedId !== aiPopoutInsightId ) {
				hideAiPopout( false, true );
			}
		}
	}

	function positiveDiscountLabel( value ) {
		return String( value || '' ).replace( /^\s*[-\u2212]\s*/, '' ) || 'Your cart';
	}

	function emitCartAiNotifications( previousCart, nextCart ) {
		const previousOffers = cartOfferMap( previousCart );
		const nextOffers = cartOfferMap( nextCart );

		Object.keys( nextOffers ).forEach( function ( key ) {
			const offer = nextOffers[ key ];
			const previous = previousOffers[ key ] || null;
			const state = String( offer.state || 'pending' );
			const previousState = previous ? String( previous.state || 'pending' ) : '';
			const productTitle = offer.title || 'your matched item';
			const triggerTitle = offer.triggerTitle || 'your cart item';

			if ( state === 'pending' && ! previous ) {
				const actionable = aiInsights.find( function ( insight ) {
					return insight.type === 'cart_offer' && String( insight.productId ) === String( offer.id || '' ) && String( insight.triggerId ) === String( offer.triggerId || offer.trigger_id || '' );
				} );
				if ( actionable ) {
					queueAiPopout( actionable, 'cart' );
				}
			}

			if ( ( state === 'eligible' || state === 'applied' ) && previousState !== 'eligible' && previousState !== 'applied' ) {
				const paired = recordAiNotification( {
					id: 'cart-paired:' + key,
					type: 'cart_pair',
					kicker: 'Pair completed',
					title: 'Paired ' + triggerTitle + ' + ' + productTitle + '.',
					message: state === 'applied' ? 'Both products are in your cart and their offer is active.' : 'Both products are in your cart. Your saving is ready to apply.',
					cartOfferKey: key,
					cartScoped: true,
					dismissible: true,
				} );
				if ( paired ) {
					queueAiPopout( paired, 'cart' );
				}
			}
		} );

		const previousDiscount = discountSignature( previousCart && previousCart.discountSummary );
		const nextDiscount = discountSignature( nextCart && nextCart.discountSummary );
		if ( nextDiscount && nextDiscount !== previousDiscount ) {
			const summary = nextCart.discountSummary || {};
			const discount = positiveDiscountLabel( summary.totalDiscount );
			const applied = recordAiNotification( {
				id: 'cart-discount:' + aiStringHash( nextDiscount ),
				type: 'discount_applied',
				kicker: 'Saving applied',
				title: 'Congratulations. ' + discount + ' discount applied.',
				message: 'Your cart now shows the discount and the total after discount.',
				discountSignature: nextDiscount,
				cartScoped: true,
				dismissible: true,
			} );
			if ( applied ) {
				queueAiPopout( applied, 'cart' );
			}
		}

		updateDockBadge( 'ai', activeAiInsights().length + unreadAiNotificationCount() );
		publishAiNotificationState();
		if ( overlayRoot && ! overlayRoot.hidden && overlayRoot.querySelector( '[data-dsa-ai-panel]' ) ) {
			rerenderAiInsightInbox();
		}
	}

	function applyCartPayload( response, options ) {
		options = options || {};
		const sequence = Number( options.sequence || 0 );
		if ( sequence && sequence < cartAppliedSequence ) {
			debugLog( 'stale cart payload ignored', { sequence: sequence, applied: cartAppliedSequence } );
			return;
		}

		if ( ! response || ! response.cart ) {
			debugLog( 'cart payload missing', { response: response || null } );
			return;
		}

		if ( sequence ) {
			cartAppliedSequence = Math.max( cartAppliedSequence, sequence );
		}

		debugLog( 'cart payload applied', {
			count: response.cart.count || 0,
			total: response.cart.total || '',
			items: Array.isArray( response.cart.items ) ? response.cart.items.map( function ( item ) {
				return {
					productId: item.productId,
					variationId: item.variationId,
					quantity: item.quantity,
					key: item.key,
					title: item.title,
				};
			} ) : [],
			rerender: Boolean( options.rerender ),
		} );

		const previousCart = phonekey.cart || {};
		phonekey.cart = response.cart;
		const cartMutation = options.cartMutation === true;
		const previousCartCount = cartStateInitialized ? Number( previousCart.count || 0 ) : 0;
		const nextCartCount = Number( response.cart.count || 0 );
		if ( nextCartCount <= 0 ) {
			firstCartConfettiPlayedForCart = false;
			firstCartConfettiQueued = false;
		}
		if ( cartMutation && nextCartCount > 0 && ( previousCartCount === 0 || firstCartConfettiQueued ) ) {
			playFirstCartConfetti( firstCartConfettiQueued ? 'queued_cart_add' : 'cart_payload' );
		}
		cartStateInitialized = true;
		reconcileAiCartState( previousCart, response.cart );
		updateDockBadge( 'cart', response.cart.count || 0 );
		syncAiInsights( isCurrentCheckoutPage() ? 'checkout' : 'page', false );
		if ( options.emitNotifications === true || ( cartMutation && options.emitNotifications !== false ) ) {
			emitCartAiNotifications( previousCart, response.cart );
		}

		if ( options.rerender && overlayRoot && ! overlayRoot.hidden && overlayRoot.querySelector( '[data-dsa-cart-panel]' ) ) {
			replaceOverlayContent( renderCartPanel( 'Cart' ), { reason: 'cart_refresh', module: getSurfaceModule( 'cart' ), label: 'Cart' } );
			bindCartPanel();
		}

		if ( checkoutState.contract && response.cart.discountSummary ) {
			checkoutState.contract.discountSummary = response.cart.discountSummary;
			checkoutState.contract.cartTotal = response.cart.total || checkoutState.contract.cartTotal || '';
			if ( options.rerender && overlayRoot && ! overlayRoot.hidden && overlayRoot.querySelector( '[data-dsa-checkout-panel]' ) ) {
				replaceOverlayContent( renderCheckoutPanel(), { reason: 'checkout_refresh', module: getSurfaceModule( 'checkout' ), label: 'Checkout' } );
				bindCheckoutPanel();
			}
		}

		refreshUniversalAddToCartEnhancers();
	}

	function applyWooCartFragments( response, eventName ) {
		const fragments = response && response.fragments ? response.fragments : {};
		const fragmentKeys = Object.keys( fragments );
		let replaced = 0;

		Object.keys( fragments ).forEach( function ( selector ) {
			document.querySelectorAll( selector ).forEach( function ( node ) {
				const wrapper = document.createElement( 'div' );
				wrapper.innerHTML = fragments[ selector ];
				const fresh = wrapper.firstElementChild;

				if ( fresh ) {
					node.replaceWith( fresh );
					replaced += 1;
				}
			} );
		} );

		debugLog( 'woo fragments applied', {
			keys: fragmentKeys,
			replaced: replaced,
			cartHash: response && response.cart_hash ? response.cart_hash : '',
		} );

		if ( response && response.cart_hash ) {
			try {
				window.sessionStorage.setItem( 'wc_cart_hash', response.cart_hash );
				window.localStorage.setItem( 'wc_cart_hash', response.cart_hash );
			} catch ( error ) {}
		}

		if ( window.jQuery ) {
			if ( eventName && eventName !== 'wc_fragments_refreshed' ) {
				triggerWooCartEventSafely( eventName, [ fragments, response && response.cart_hash ? response.cart_hash : '', wooCartEventButton() ] );
			}
			triggerWooCartEventSafely( 'wc_fragments_refreshed', [ fragments ] );
		}
	}

	let wooCartEventProxy = null;

	function wooCartEventButton() {
		if ( ! wooCartEventProxy ) {
			wooCartEventProxy = document.createElement( 'button' );
			wooCartEventProxy.type = 'button';
			wooCartEventProxy.className = 'dsa-woo-event-proxy';
			wooCartEventProxy.setAttribute( 'data-original-text', '' );
		}

		return window.jQuery( wooCartEventProxy );
	}

	function triggerWooCartEventSafely( eventName, args ) {
		try {
			window.jQuery( document.body ).trigger( eventName, args );
		} catch ( error ) {
			debugLog( 'external Woo cart listener failed', {
				event: eventName,
				message: error && error.message ? error.message : String( error || '' ),
				stack: error && error.stack ? error.stack : '',
			} );
		}
	}

	function scheduleCartRefreshSequence( delays, options ) {
		options = options || {};
		cartRefreshMutationPending = cartRefreshMutationPending || options.cartMutation === true;
		cartRefreshTimers.forEach( function ( timer ) {
			window.clearTimeout( timer );
		} );
		cartRefreshTimers = [];

		const refreshDelays = Array.isArray( delays ) && delays.length ? delays : [ 80, 360, 1100 ];
		refreshDelays.forEach( function ( delay, index ) {
			cartRefreshTimers.push(
				window.setTimeout( function () {
					const cartMutation = cartRefreshMutationPending;
					refreshCartState( { rerender: true, cartMutation: cartMutation } );
					if ( index === refreshDelays.length - 1 ) {
						cartRefreshMutationPending = false;
					}
				}, Number( delay ) || 120 )
			);
		} );
	}

	function refreshCartState( options ) {
		options = options || {};

		if ( ! data.restUrl || ! commerce.settings ) {
			return Promise.resolve( null );
		}

		const cartSequence = nextCartSequence();
		return dsaGet( '/cart' )
			.then( function ( response ) {
				if ( ! response || ! response.cart ) {
					return response;
				}

				applyCartPayload( response, { rerender: options.rerender, cartMutation: options.cartMutation === true, sequence: cartSequence } );

				return response;
			} )
			.catch( function () {
				return null;
			} );
	}

	function updateDockBadge( moduleId, value ) {
		const badge = surface ? surface.querySelector( '[data-dsa-badge="' + String( moduleId || '' ) + '"]' ) : null;
		const button = surface ? surface.querySelector( '[data-dsa-module="' + String( moduleId || '' ) + '"]' ) : null;

		if ( badge ) {
			badge.textContent = String( value || '' );
			badge.hidden = ! Number( value );
		}

		if ( button && moduleId === 'ai' ) {
			button.classList.toggle( 'has-unread', Number( value ) > 0 );
		}
	}

	function dsaPost( path, payload, retried ) {
		debugLog( 'REST POST start', { path: path, payload: payload || {}, retried: Boolean( retried ) } );

		return fetch( noStoreUrl( data.restUrl + path ), {
			method: 'POST',
			headers: runtimeHeaders( {
				'Content-Type': 'application/json',
				'X-Kiwe-Mutation': '1',
			} ),
			credentials: 'same-origin',
			cache: 'no-store',
			body: JSON.stringify( payload || {} ),
		} ).then( function ( response ) {
			debugLog( 'REST POST response', { path: path, status: response.status, ok: response.ok } );

			if ( response.ok ) {
				return restJson( response ).then( function ( json ) {
					debugLog( 'REST POST json', {
						path: path,
						ok: json && json.ok,
						cartCount: json && json.cart ? json.cart.count : null,
						item: json && json.item ? json.item : null,
						fragmentKeys: json && json.fragments ? Object.keys( json.fragments ) : [],
						cartHash: json && json.cart_hash ? json.cart_hash : '',
					} );

					return json;
				} );
			}

			return response.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				const code = json && json.code ? String( json.code ) : '';

				if ( ! retried && ( response.status === 401 || response.status === 403 || code === 'rest_cookie_invalid_nonce' ) ) {
					debugLog( 'REST nonce retry', { path: path, status: response.status, code: code } );
					return refreshRestNonce().then( function () {
						return dsaPost( path, payload, true );
					} );
				}

				debugLog( 'REST POST error', { path: path, status: response.status, code: code, message: json.message || 'Request failed' } );
				const error = new Error( json.message || 'Request failed' );
				error.status = response.status;
				error.code = code;
				throw error;
			} );
		} );
	}

	function dsaGet( path, retried ) {
		return fetch( noStoreUrl( data.restUrl + path ), {
			headers: runtimeHeaders(),
			credentials: 'same-origin',
			cache: 'no-store',
		} ).then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( json ) {
				const code = json && json.code ? String( json.code ) : '';
				if ( ! response.ok || json.ok === false ) {
					if ( ! retried && path !== '/cart/nonce' && ( response.status === 401 || response.status === 403 || code === 'rest_cookie_invalid_nonce' ) ) {
						return refreshRestNonce().then( function () { return dsaGet( path, true ); } );
					}
					const error = new Error( json.message || 'Request failed' );
					error.status = response.status;
					error.code = code;
					throw error;
				}
				return json;
			} );
		} );
	}

	function dsaDelete( path, payload, retried ) {
		return fetch( noStoreUrl( data.restUrl + path ), {
			method: 'DELETE',
			headers: runtimeHeaders( {
				'Content-Type': 'application/json',
				'X-Kiwe-Mutation': '1',
			} ),
			credentials: 'same-origin',
			cache: 'no-store',
			body: JSON.stringify( payload || {} ),
		} ).then( function ( response ) {
			if ( response.ok ) return restJson( response );
			return response.json().catch( function () { return {}; } ).then( function ( json ) {
				const code = json && json.code ? String( json.code ) : '';
				if ( ! retried && ( response.status === 401 || response.status === 403 || code === 'rest_cookie_invalid_nonce' ) ) {
					return refreshRestNonce().then( function () { return dsaDelete( path, payload, true ); } );
				}
				const error = new Error( json.message || 'Request failed' );
				error.status = response.status;
				error.code = code;
				throw error;
			} );
		} );
	}

	function dsaUpload( path, formData, retried ) {
		return fetch( noStoreUrl( data.restUrl + path ), {
			method: 'POST',
			headers: runtimeHeaders( {
				'X-Kiwe-Mutation': '1',
			} ),
			credentials: 'same-origin',
			cache: 'no-store',
			body: formData,
		} ).then( function ( response ) {
			if ( response.ok ) return restJson( response );
			return response.json().catch( function () { return {}; } ).then( function ( json ) {
				const code = json && json.code ? String( json.code ) : '';
				if ( ! retried && ( response.status === 401 || response.status === 403 || code === 'rest_cookie_invalid_nonce' ) ) {
					return refreshRestNonce().then( function () { return dsaUpload( path, formData, true ); } );
				}
				const error = new Error( json.message || 'Request failed' );
				error.status = response.status;
				error.code = code;
				throw error;
			} );
		} );
	}

	function refreshRestNonce() {
		return fetch( noStoreUrl( data.restUrl + '/cart/nonce' ), {
			credentials: 'same-origin',
			cache: 'no-store',
			headers: noStoreHeaders(),
		} ).then( restJson ).then( function ( response ) {
			if ( response && response.nonce ) {
				data.nonce = response.nonce;
				return response.nonce;
			}

			throw new Error( 'Request failed' );
		} );
	}

	function restJson( response ) {
		return response.json().then( function ( json ) {
			if ( ! response.ok || json.ok === false ) {
				throw new Error( json.message || 'Request failed' );
			}

			return json;
		} );
	}

	function recordMetric( eventName, context, value ) {
		recordPermissionJourneyEvent( eventName );

		if ( ! metricsConfig.enabled || ! data.restUrl || ! eventName ) {
			return;
		}

		const payload = {
			event: String( eventName ),
			context: context ? String( context ) : '',
			value: Number.isFinite( Number( value ) ) ? Math.max( 0, Math.round( Number( value ) ) ) : 0,
		};

		try {
			dsaPost( '/metrics/event', payload ).catch( function () {} );
		} catch ( error ) {}
	}

	function recordPermissionJourneyEvent( eventName ) {
		if ( ! permissionsConfig.enabled || ! eventName ) {
			return;
		}

		const map = {
			appsite_home_view: 'homeViews',
			dock_open: 'dockOpens',
			transition_complete: 'transitionCompletes',
			game_complete: 'gameCompletes',
		};
		const key = map[eventName];

		if ( ! key ) {
			return;
		}

		const events = permissionJourneyEvents();
		events[key] = Math.min( 1000, Number( events[key] || 0 ) + 1 );
		savePermissionJourneyEvents( events );
	}

	function permissionJourneyEvents() {
		const empty = {
			homeViews: 0,
			dockOpens: 0,
			transitionCompletes: 0,
			gameCompletes: 0,
		};

		try {
			const raw = window.localStorage ? window.localStorage.getItem( 'dsa_permission_events' ) : '';
			const parsed = raw ? JSON.parse( raw ) : {};
			return Object.keys( empty ).reduce( function ( out, key ) {
				out[key] = Math.max( 0, Math.min( 1000, Number( parsed[key] || 0 ) ) );
				return out;
			}, empty );
		} catch ( error ) {
			return empty;
		}
	}

	function savePermissionJourneyEvents( events ) {
		try {
			if ( window.localStorage ) {
				window.localStorage.setItem( 'dsa_permission_events', JSON.stringify( events || {} ) );
			}
		} catch ( error ) {}
	}

	function permissionVisitorId() {
		const key = 'dsa_permission_visitor';
		try {
			let id = window.localStorage ? window.localStorage.getItem( key ) : '';
			if ( ! id ) {
				id = 'p' + Math.random().toString( 36 ).slice( 2 ) + Date.now().toString( 36 );
				if ( window.localStorage ) {
					window.localStorage.setItem( key, id );
				}
			}
			return id;
		} catch ( error ) {
			return 'session-' + Date.now().toString( 36 );
		}
	}

	function permissionSessionAskCount() {
		try {
			return window.sessionStorage ? Math.max( 0, Number( window.sessionStorage.getItem( 'dsa_permission_asks' ) || 0 ) ) : 0;
		} catch ( error ) {
			return 0;
		}
	}

	function savePermissionSessionAskCount( count ) {
		try {
			if ( window.sessionStorage ) {
				window.sessionStorage.setItem( 'dsa_permission_asks', String( Math.max( 0, Number( count ) || 0 ) ) );
			}
		} catch ( error ) {}
	}

	function updateProfile( form ) {
		const message = form.querySelector( '[data-dsa-profile-message]' );
		const payload = {
			firstName: form.querySelector( '[name="firstName"]' ).value,
			lastName: form.querySelector( '[name="lastName"]' ).value,
			 displayName: form.querySelector( '[name="displayName"]' ).value,
			email: form.querySelector( '[name="email"]' ).value,
			currentPassword: form.querySelector( '[name="currentPassword"]' ) ? form.querySelector( '[name="currentPassword"]' ).value : '',
		};

		if ( message ) {
			message.textContent = 'Saving...';
		}

		dsaPost( '/account/profile', payload )
			.then( function ( response ) {
				phonekey.user.firstName = payload.firstName;
				phonekey.user.lastName = payload.lastName;
				phonekey.user.displayName = payload.displayName;
				if ( ! response.emailChange ) {
					phonekey.user.email = response.email || payload.email;
				}

				if ( message ) {
					message.textContent = response.emailChange
						? ( response.emailChange.accepted ? 'Enter the code sent to the new email.' : 'The profile was saved, but email delivery was not accepted. Check site mail configuration.' )
						: 'Saved';
				}
				if ( response.emailChange ) {
					form.dataset.emailChangeToken = response.emailChange.token || '';
					const verify = form.querySelector( '[data-dsa-profile-email-verify]' );
					if ( verify ) verify.hidden = false;
				}
			} )
			.catch( function ( error ) {
				if ( message ) {
					message.textContent = error.message;
				}
			} );
	}

	function confirmProfileEmail( button ) {
		const form = button.closest( '[data-dsa-profile-form]' );
		const message = form ? form.querySelector( '[data-dsa-profile-message]' ) : null;
		const code = form ? form.querySelector( '[name="emailCode"]' ) : null;
		if ( ! form || ! code || ! form.dataset.emailChangeToken ) return;
		if ( message ) message.textContent = 'Verifying...';
		dsaPost( '/account/email-change/verify', { token: form.dataset.emailChangeToken, code: code.value } )
			.then( function ( response ) {
				phonekey.user.email = response.email;
				form.dataset.emailChangeToken = '';
				const verify = form.querySelector( '[data-dsa-profile-email-verify]' );
				if ( verify ) verify.hidden = true;
				if ( message ) message.textContent = 'Email verified. Remembered devices were signed out for your protection.';
			} )
			.catch( function ( error ) { if ( message ) message.textContent = error.message; } );
	}

	function updateAvatar( input ) {
		const file = input.files && input.files[0] ? input.files[0] : null;
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-profile-panel]' ) : null;
		const image = panel ? panel.querySelector( '[data-dsa-profile-avatar]' ) : null;

		if ( ! file ) {
			return;
		}

		const payload = new window.FormData();
		payload.append( 'avatar', file );

		dsaUpload( '/account/avatar', payload )
			.then( function ( response ) {
				if ( response.avatar ) {
					phonekey.user.avatar = response.avatar;

					if ( image ) {
						image.src = response.avatar;
					}
				}
			} )
			.catch( function ( error ) {
				window.alert( error.message );
			} );
	}

	function openAccountView( view ) {
		const title = {
			orders: 'Orders',
			downloads: 'Downloads',
			addresses: 'Addresses',
			password: 'Reset password',
		}[view] || 'Account';

		replaceOverlayContent( renderAccountShell( title, '<p class="dsa-panel__meta">Loading...</p>', view ), { reason: 'account_loading', module: getSurfaceModule( 'profile' ), label: title } );
		const lifecycleSequence = activeOverlayLifecycle ? activeOverlayLifecycle.sequence : 0;
		const contentSequence = overlayContentSequence;
		bindAccountBack();

		if ( view === 'orders' ) {
			dsaGet( '/account/orders' ).then( function ( response ) {
				if ( replaceOverlayContent( renderAccountShell( title, renderOrders( response.orders || [] ), view ), { reason: 'account_orders', module: getSurfaceModule( 'profile' ), label: title, lifecycleSequence: lifecycleSequence, contentSequence: contentSequence } ) ) {
					bindAccountBack();
				}
			} ).catch( function ( error ) { renderAccountError( error, lifecycleSequence, contentSequence ); } );
		} else if ( view === 'downloads' ) {
			dsaGet( '/account/downloads' ).then( function ( response ) {
				const downloads = response.downloads || [];
				try { if ( window.localStorage ) window.localStorage.setItem( profileDownloadsSeenKey(), downloadSignature( downloads ) ); } catch ( error ) {}
				setProfileContextBadge( 'downloads', 0 );
				if ( replaceOverlayContent( renderAccountShell( title, renderDownloads( downloads ), view ), { reason: 'account_downloads', module: getSurfaceModule( 'profile' ), label: title, lifecycleSequence: lifecycleSequence, contentSequence: contentSequence } ) ) {
					bindAccountBack();
				}
			} ).catch( function ( error ) { renderAccountError( error, lifecycleSequence, contentSequence ); } );
		} else if ( view === 'addresses' ) {
			dsaGet( '/account/addresses' ).then( function ( response ) {
				if ( replaceOverlayContent( renderAccountShell( title, renderAddresses( response ), view ), { reason: 'account_addresses', module: getSurfaceModule( 'profile' ), label: title, lifecycleSequence: lifecycleSequence, contentSequence: contentSequence } ) ) {
					bindAccountBack();
					bindAddressForms();
				}
			} ).catch( function ( error ) { renderAccountError( error, lifecycleSequence, contentSequence ); } );
		} else if ( view === 'password' ) {
			replaceOverlayContent( renderAccountShell( title, renderPasswordReset(), view ), { reason: 'account_password', module: getSurfaceModule( 'profile' ), label: title } );
			bindAccountBack();
			bindPasswordReset();
		}
	}

	function renderAccountShell( title, body, view ) {
		return [
			'<section class="dsa-panel dsa-account-view" role="dialog" aria-modal="false" aria-label="' + escapeHtml( title ) + '" data-dsa-account-view="' + escapeHtml( view || '' ) + '">',
			'<button class="dsa-panel__button dsa-account-back" type="button" data-dsa-account-back><span>Profile</span></button>',
			'<h2>' + escapeHtml( title ) + '</h2>',
			body,
			'</section>',
		].join( '' );
	}

	function bindAccountBack() {
		const back = overlayRoot.querySelector( '[data-dsa-account-back]' );

		if ( back ) {
			back.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				replaceOverlayContent( renderProfilePanel( 'Profile' ), { reason: 'profile_return', module: getSurfaceModule( 'profile' ), label: 'Profile' } );
				bindProfilePanel();
			} );
		}
	}

	function renderAccountError( error, lifecycleSequence, contentSequence ) {
		if ( replaceOverlayContent( renderAccountShell( 'Account', '<div class="dsa-auth-error">' + escapeHtml( error.message ) + '</div>', 'account' ), { reason: 'account_error', module: getSurfaceModule( 'profile' ), label: 'Account', lifecycleSequence: lifecycleSequence || 0, contentSequence: contentSequence || 0 } ) ) {
			bindAccountBack();
		}
	}

	function renderOrders( orders ) {
		if ( ! orders.length ) {
			return '<p class="dsa-panel__meta">No orders yet.</p>';
		}

		return '<div class="dsa-order-list">' + orders.map( function ( order ) {
			return [
				'<article class="dsa-order">',
				'<div class="dsa-order__head"><strong>#' + escapeHtml( order.number ) + '</strong><span>' + escapeHtml( order.status ) + '</span></div>',
				'<p class="dsa-panel__meta">' + escapeHtml( order.date ) + ' · ' + escapeHtml( order.total ) + '</p>',
				'<div class="dsa-mini-items">' + ( order.items || [] ).map( renderMiniItem ).join( '' ) + '</div>',
				'</article>',
			].join( '' );
		} ).join( '' ) + '</div>';
	}

	function renderDownloads( downloads ) {
		if ( ! downloads.length ) {
			return '<p class="dsa-panel__meta">No downloads available.</p>';
		}

		return '<div class="dsa-panel__list">' + downloads.map( function ( item ) {
			return '<a class="dsa-panel__link" href="' + escapeHtml( item.url ) + '" data-dsa-full-navigation><span>' + escapeHtml( item.downloadName || item.productName ) + '</span><span class="dsa-panel__meta">' + escapeHtml( item.remaining || 'Download' ) + '</span></a>';
		} ).join( '' ) + '</div>';
	}

	function renderAddresses( data ) {
		return [
			renderAddressForm( 'billing', data.billing || {} ),
			renderAddressForm( 'shipping', data.shipping || {} ),
		].join( '' );
	}

	function renderAddressForm( type, address ) {
		const title = type.charAt( 0 ).toUpperCase() + type.slice( 1 );
		const fields = [
			[ 'first_name', 'First name' ],
			[ 'last_name', 'Last name' ],
			[ 'company', 'Company' ],
			[ 'address_1', 'Address line 1' ],
			[ 'address_2', 'Address line 2' ],
			[ 'city', 'City' ],
			[ 'state', 'State' ],
			[ 'postcode', 'Postcode' ],
			[ 'country', 'Country' ],
			[ 'phone', 'Phone' ],
			[ 'email', 'Email' ],
		];

		return [
			'<form class="dsa-address-form" data-dsa-address-form data-address-type="' + escapeHtml( type ) + '">',
			'<h3>' + escapeHtml( title ) + '</h3>',
			fields.map( function ( field ) {
				return '<input class="dsa-auth-field" name="' + escapeHtml( field[0] ) + '" value="' + escapeHtml( address[field[0]] || '' ) + '" aria-label="' + escapeHtml( field[1] ) + '" placeholder="' + escapeHtml( field[1] ) + '">';
			} ).join( '' ),
			'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" type="submit">Update ' + escapeHtml( title ) + '</button><span class="dsa-panel__meta" data-dsa-address-message></span></div>',
			'</form>',
		].join( '' );
	}

	function bindAddressForms() {
		overlayRoot.querySelectorAll( '[data-dsa-address-form]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				const payload = { type: form.dataset.addressType };
				const message = form.querySelector( '[data-dsa-address-message]' );

				form.querySelectorAll( 'input[name]' ).forEach( function ( input ) {
					payload[input.name] = input.value;
				} );

				if ( message ) {
					message.textContent = 'Saving...';
				}

				dsaPost( '/account/address', payload )
					.then( function () {
						if ( message ) {
							message.textContent = 'Saved';
						}
					} )
					.catch( function ( error ) {
						if ( message ) {
							message.textContent = error.message;
						}
					} );
			} );
		} );
	}

	function renderPasswordReset() {
		return [
			'<p class="dsa-panel__meta">Send a secure password reset link to ' + escapeHtml( ( phonekey.user || {} ).email || 'your email' ) + '.</p>',
			'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" type="button" data-dsa-password-reset>Send reset email</button><span class="dsa-panel__meta" data-dsa-password-message></span></div>',
		].join( '' );
	}

	function bindPasswordReset() {
		const button = overlayRoot.querySelector( '[data-dsa-password-reset]' );
		const message = overlayRoot.querySelector( '[data-dsa-password-message]' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;

			if ( message ) {
				message.textContent = 'Sending...';
			}

			dsaPost( '/account/password-reset', {} )
				.then( function ( response ) {
					if ( message ) {
						message.textContent = response.message || 'Reset email sent.';
					}
				} )
				.catch( function ( error ) {
					button.disabled = false;

					if ( message ) {
						message.textContent = error.message;
					}
				} );
		} );
	}

	function renderMiniItem( item ) {
		return [
			'<div class="dsa-mini-item">',
			item.image ? '<img src="' + escapeHtml( item.image ) + '" alt="">' : '',
			'<span>' + escapeHtml( item.name || item.title || 'Item' ) + '</span>',
			'<strong>' + escapeHtml( item.total || item.price || '' ) + '</strong>',
			'</div>',
		].join( '' );
	}

	function phoneKeyRoot() {
		return overlayRoot ? overlayRoot.querySelector( '[data-dsa-phonekey-auth]' ) : null;
	}

	function bindPhoneKeyAuth() {
		const root = phoneKeyRoot();

		if ( ! root ) {
			return;
		}

		bindPhoneKeyClose( root );

		const start = root.querySelector( '[data-dsa-pk-start]' );

		if ( start ) {
			start.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				identifyPhoneKey();
			} );
		}
	}

	function phoneKeyInputLabel() {
		const identifierMode = isStandaloneApp() ? ( phonekeyConfig.appIdentifierMode || phonekeyConfig.identifierMode ) : phonekeyConfig.identifierMode;
		if ( identifierMode === 'email' ) {
			return 'Email address';
		}

		if ( identifierMode === 'phone' ) {
			return 'Phone number';
		}

		return 'Email or phone';
	}

	function renderPhoneKeyStart() {
		if ( ! phonekey.available || ! phonekey.restUrl ) {
			return [
				'<h2>Sign in</h2>',
				'<p>Kiwe Auth is not available yet. Re-upload the Auth integration files and refresh this page.</p>',
			].join( '' );
		}

		const label = phoneKeyInputLabel();
		const appPrefill = appPhoneKeyGate && label !== 'Phone number' ? ( ( phonekey.user || {} ).email || '' ) : '';

		const appWelcome = isStandaloneApp() && ( !( phonekey.user || {} ).loggedIn || appPhoneKeyGate ) && ! notificationIdentityIntent;
		return [
			notificationIdentityIntent ? '<h2>Keep your notifications with you.</h2>' : ( appWelcome ? '<h2>Welcome to your Appsite.</h2>' : '<h2>Continue securely</h2>' ),
			notificationIdentityIntent ? '<p>Confirm ' + escapeHtml( label.toLowerCase() ) + ' once so your Appsite choices can follow you securely.</p>' : ( appWelcome ? '<p>Use ' + escapeHtml( label.toLowerCase() ) + ' once to keep this app, your preferences, and future notifications together.</p>' : '<p>Use ' + escapeHtml( label.toLowerCase() ) + ' to continue. If this device supports passkeys, Kiwe Auth will use the secure login built into your device.</p>' ),
			renderPhoneKeyError(),
			'<form class="dsa-auth-form" data-dsa-pk-start>',
			'<label class="dsa-auth-label" for="dsa-pk-ident">' + escapeHtml( label ) + '</label>',
			'<input class="dsa-auth-field" id="dsa-pk-ident" autocomplete="username" placeholder="' + escapeHtml( label ) + '" value="' + escapeHtml( notificationIdentityIntent || phonekeyState.identifier || appPrefill ) + '">',
			'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" type="submit" data-dsa-pk-identify>Continue</button></div>',
			'</form>',
			renderHomeTrustBadges(),
		].join( '' );
	}

	function renderPhoneKeyError() {
		return phonekeyState.error ? '<div class="dsa-auth-error">' + escapeHtml( phonekeyState.error ) + '</div>' : '';
	}

	function phoneKeyCloseButton() {
		return appPhoneKeyGate ? '' : '<button class="dsa-auth-close" type="button" aria-label="Close" data-dsa-pk-close>&times;</button>';
	}

	function bindPhoneKeyClose( root ) {
		const close = root ? root.querySelector( '[data-dsa-pk-close]' ) : null;

		if ( close ) {
			close.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				closeOverlay();
			} );
		}
	}

	function renderPhoneKeyStep( html, binder ) {
		const root = phoneKeyRoot();

		if ( ! root ) {
			return;
		}

		root.innerHTML = phoneKeyCloseButton() + html + ( String( html ).indexOf( 'dsa-home-trust' ) === -1 ? renderHomeTrustBadges() : '' );
		bindPhoneKeyClose( root );

		if ( typeof binder === 'function' ) {
			binder( root );
		}
	}

	function setPhoneKeyBusy( busy ) {
		const root = phoneKeyRoot();

		if ( ! root ) {
			return;
		}

		root.querySelectorAll( 'button, input' ).forEach( function ( control ) {
			control.disabled = busy;
		} );
	}

	function phoneKeyPost( path, payload, retried ) {
		const headers = noStoreHeaders( {
			'Content-Type': 'application/json',
			'X-Kiwe-Mutation': '1',
		} );

		const nonce = phonekey.nonce || data.nonce || '';
		if ( nonce ) {
			headers['X-WP-Nonce'] = nonce;
		}

		return fetch( noStoreUrl( phonekey.restUrl + path ), {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			cache: 'no-store',
			body: JSON.stringify( payload || {} ),
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok || json.ok === false ) {
					const code = json && json.code ? String( json.code ) : '';
					if ( ! retried && ( response.status === 401 || response.status === 403 || code === 'rest_cookie_invalid_nonce' ) ) {
						return hydrateRuntime().then( function () {
							return refreshRestNonce();
						} ).then( function ( nonceValue ) {
							phonekey.nonce = nonceValue || data.nonce || phonekey.nonce || '';
							return phoneKeyPost( path, payload, true );
						} );
					}
					const error = new Error( ( json && json.message ) || 'Request failed' );
					error.data = json || {};
					error.status = response.status;
					error.code = code;
					throw error;
				}

				return json;
			} );
		} );
	}

	function identifyPhoneKey() {
		const root = phoneKeyRoot();
		const input = root ? root.querySelector( '#dsa-pk-ident' ) : null;
		const value = input ? input.value.trim() : '';

		if ( ! value ) {
			phonekeyState.error = 'Enter your ' + phoneKeyInputLabel().toLowerCase() + '.';
			renderPhoneKeyStep( renderPhoneKeyStart(), bindPhoneKeyAuth );
			return;
		}

		setPhoneKeyBusy( true );
		phoneKeyPost( 'identify', { identifier: value, appContext: isStandaloneApp() } )
			.then( function ( response ) {
				phonekeyState = Object.assign( phonekeyState, {
					token: response.token,
					mode: response.mode,
					name: response.displayName,
					identifier: response.identifier,
					identifierType: response.identifierType,
					canEmailRecovery: Boolean( response.canEmailRecovery ),
					knownDevice: Boolean( response.knownDevice ),
					hasTotp: Boolean( response.hasTotp ),
					hasBackup: Boolean( response.hasBackup ),
					emailDelivery: response.emailDelivery || 'magic_link',
					emailAccepted: typeof response.emailAccepted === 'boolean' ? response.emailAccepted : null,
					otpResendLockedUntil: 0,
					error: '',
				} );

				if ( response.mode === 'login_passkey' ) {
					renderPasskeyLogin();
				} else if ( response.mode === 'new_device_verify' ) {
					renderVerify( Object.assign( {}, response, { newDevice: true, emailDelivery: 'otp' } ) );
				} else if ( response.mode === 'verify_required' ) {
					renderVerify( response );
				} else if ( response.mode === 'unverified_return' ) {
					renderUnverified( response );
				} else if ( response.mode === 'privileged_setup' ) {
					renderPrivileged();
				} else {
					renderEnroll( response );
				}
			} )
			.catch( function ( error ) {
				phonekeyState.error = error.message;
				renderPhoneKeyStep( renderPhoneKeyStart(), bindPhoneKeyAuth );
			} )
			.finally( function () {
				setPhoneKeyBusy( false );
			} );
	}

	function renderPasskeyLogin() {
		const newDevice = phonekeyState.knownDevice === false;
		renderPhoneKeyStep(
			[
				'<h2>Welcome back' + ( phonekeyState.name ? ', ' + escapeHtml( phonekeyState.name ) : '' ) + '</h2>',
				'<p>' + ( newDevice ? 'This appears to be a new or untrusted device. Use a synced passkey, or verify through your recovery email.' : 'Confirm it is you with your device passkey.' ) + '</p>',
				renderPhoneKeyError(),
				'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-pass>Continue with passkey</button><button class="dsa-panel__button" data-dsa-pk-other>' + ( newDevice && phonekeyState.canEmailRecovery ? 'Use email code' : 'Try another way' ) + '</button></div>',
			].join( '' ),
			function ( root ) {
				root.querySelector( '[data-dsa-pk-pass]' ).addEventListener( 'click', passkeyLogin );
				root.querySelector( '[data-dsa-pk-other]' ).addEventListener( 'click', tryAnother );
			}
		);
	}

	function renderEnroll( response ) {
		const newDevice = Boolean( response && response.newDevice );
		renderPhoneKeyStep(
			[
				'<h2>' + ( newDevice ? 'Secure this device' : 'Secure your account' ) + '</h2>',
				'<p>' + ( newDevice ? 'Create a passkey for this device using Face ID, fingerprint, device PIN, or its password manager.' : 'Create a passkey using Face ID, fingerprint, device PIN, or your browser password manager.' ) + '</p>',
				! response.verified ? '<p class="dsa-panel__meta">Verification is pending. You can finish it from the link or code sent to you.</p>' : '',
				renderPhoneKeyError(),
				'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-enroll>Set up passkey</button>' + ( newDevice ? '' : '<button class="dsa-panel__button" data-dsa-pk-later>Later</button>' ) + '</div>',
			].join( '' ),
			function ( root ) {
				root.querySelector( '[data-dsa-pk-enroll]' ).addEventListener( 'click', passkeyRegister );
				const later = root.querySelector( '[data-dsa-pk-later]' );
				if ( later ) later.addEventListener( 'click', continueLater );
			}
		);
	}

	function renderVerify( response ) {
		const isPhone = ( response.identifierType || phonekeyState.identifierType ) === 'phone';
		const useOtp = isPhone || ( response.emailDelivery || phonekeyState.emailDelivery ) === 'otp';
		const canResend = useOtp && Date.now() >= Number( phonekeyState.otpResendLockedUntil || 0 );
		const newDevice = Boolean( response.newDevice || phonekeyState.mode === 'new_device_verify' );

		renderPhoneKeyStep(
			[
				'<h2>' + ( newDevice ? 'A new device' : 'Verify first' ) + '</h2>',
				'<p>' + ( newDevice ? 'It looks like you are using a new device. Enter the six digit code, then set up a passkey for this device.' : ( useOtp ? 'Enter the six digit code sent to your ' + ( isPhone ? 'phone.' : 'email.' ) : 'We sent a verification link to your email. Open it to continue, or request a recovery code.' ) ) + '</p>',
				! isPhone && phonekeyState.emailAccepted === false ? '<p class="dsa-panel__meta dsa-auth-error">WordPress could not hand this message to its mail transport. The site administrator needs to check Kiwe Email and SMTP.</p>' : '',
				renderPhoneKeyError(),
				useOtp ? '<input class="dsa-auth-field" id="dsa-pk-code" inputmode="numeric" placeholder="123456"><div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-verify>Verify</button><button class="dsa-panel__button" data-dsa-pk-resend' + ( canResend ? '' : ' disabled' ) + '>' + ( canResend ? 'Resend code' : 'Wait before retry' ) + '</button></div>' : '<div class="dsa-auth-actions"><button class="dsa-panel__button" data-dsa-pk-recovery>Send recovery code</button></div>',
			].join( '' ),
			function ( root ) {
				const verifyButton = root.querySelector( '[data-dsa-pk-verify]' );
				const resendButton = root.querySelector( '[data-dsa-pk-resend]' );
				const recoveryButton = root.querySelector( '[data-dsa-pk-recovery]' );

				if ( verifyButton ) {
					verifyButton.addEventListener( 'click', function () {
						verifyCode( isPhone );
					} );
				}

				if ( recoveryButton ) {
					recoveryButton.addEventListener( 'click', sendRecovery );
				}

				if ( resendButton ) {
					resendButton.addEventListener( 'click', function () {
						resendOtp( isPhone );
					} );
				}
			}
		);
	}

	function renderUnverified( response ) {
		renderPhoneKeyStep(
			[
				'<h2>Welcome back</h2>',
				'<p>' + escapeHtml( response.returnMessage || 'Your account is not verified yet.' ) + '</p>',
				renderPhoneKeyError(),
				'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-verify-now>Verify now</button>' + ( response.canLater ? '<button class="dsa-panel__button" data-dsa-pk-later>Later</button>' : '' ) + '</div>',
			].join( '' ),
			function ( root ) {
				root.querySelector( '[data-dsa-pk-verify-now]' ).addEventListener( 'click', function () {
					renderVerify( response );
				} );

				const later = root.querySelector( '[data-dsa-pk-later]' );

				if ( later ) {
					later.addEventListener( 'click', continueLater );
				}
			}
		);
	}

	function renderPrivileged() {
		renderPhoneKeyStep(
			[
				'<h2>Secure admin access</h2>',
				'<p>Enter your WordPress admin password first. After that, this device can set up a passkey.</p>',
				renderPhoneKeyError(),
				'<input class="dsa-auth-field" id="dsa-pk-admin-password" type="password" autocomplete="current-password" placeholder="Admin password">',
				'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-admin-password>Continue</button></div>',
			].join( '' ),
			function ( root ) {
				const submit = function () {
					const password = root.querySelector( '#dsa-pk-admin-password' ).value;

					setPhoneKeyBusy( true );
					phoneKeyPost( 'admin-password-verify', { token: phonekeyState.token, password: password } )
						.then( function ( response ) {
							phonekeyState.token = response.token || phonekeyState.token;
							phonekeyState.mode = response.mode || 'enroll_passkey';
							phonekeyState.error = '';
							renderEnroll( { verified: Boolean( response.verified ) } );
						} )
						.catch( function ( error ) {
							phonekeyState.error = error.message;
							renderPrivileged();
						} )
						.finally( function () {
							setPhoneKeyBusy( false );
						} );
				};

				root.querySelector( '[data-dsa-pk-admin-password]' ).addEventListener( 'click', submit );
				root.querySelector( '#dsa-pk-admin-password' ).addEventListener( 'keydown', function ( event ) {
					if ( event.key === 'Enter' ) {
						event.preventDefault();
						submit();
					}
				} );
			}
		);
	}

	function verifyCode( isPhone ) {
		const root = phoneKeyRoot();
		const code = root && root.querySelector( '#dsa-pk-code' ) ? root.querySelector( '#dsa-pk-code' ).value.trim() : '';

		phoneKeyPost( isPhone ? 'verify-phone' : 'verify-email', { token: phonekeyState.token, code: code } )
			.then( function ( response ) {
				if ( response && ( response.redirect || response.requiresTotp ) ) {
					phonekeyState.adminPhoneBinding = false;
					afterPhoneKeyAuth( response );
					return;
				}

				phonekeyState.token = response.token || phonekeyState.token;
				phonekeyState.mode = response.next || 'enroll_passkey';
				renderEnroll( { verified: true, newDevice: Boolean( response.newDevice ) } );
			} )
			.catch( function ( error ) {
				phonekeyState.error = error.message;
				renderVerify( { identifierType: isPhone ? 'phone' : 'email', emailDelivery: 'otp' } );
			} );
	}

	function sendRecovery() {
		phoneKeyPost( 'send-recovery', { token: phonekeyState.token } )
			.then( function () {
				renderPhoneKeyStep(
					'<h2>Check your email</h2><p>If recovery is available, a code has been sent.</p><input class="dsa-auth-field" id="dsa-pk-code" inputmode="numeric" placeholder="123456"><div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-verify>Verify</button></div>',
					function ( root ) {
						root.querySelector( '[data-dsa-pk-verify]' ).addEventListener( 'click', function () {
							verifyCode( false );
						} );
					}
				);
			} )
			.catch( function ( error ) {
				phonekeyState.error = error.message;
				tryAnother();
			} );
	}

	function resendOtp( isPhone ) {
		setPhoneKeyBusy( true );
		phoneKeyPost( 'resend-otp', { token: phonekeyState.token, type: isPhone ? 'phone' : 'email' } )
			.then( function () {
				phonekeyState.error = '';
				phonekeyState.otpResendLockedUntil = Date.now() + 60000;
				renderVerify( { identifierType: isPhone ? 'phone' : 'email', emailDelivery: 'otp' } );
				window.setTimeout( function () {
					if ( phoneKeyRoot() && Date.now() >= Number( phonekeyState.otpResendLockedUntil || 0 ) ) {
						renderVerify( { identifierType: isPhone ? 'phone' : 'email', emailDelivery: 'otp' } );
					}
				}, 61000 );
			} )
			.catch( function ( error ) {
				phonekeyState.error = error.message;
				renderVerify( { identifierType: isPhone ? 'phone' : 'email', emailDelivery: 'otp' } );
			} );
	}

	function continueLater() {
		phoneKeyPost( 'continue-later', { token: phonekeyState.token } )
			.then( phoneKeyDone )
			.catch( function ( error ) {
				const payload = error && error.data ? error.data : {};

				if ( payload.factor_required ) {
					phonekeyState.error = '';

					if ( payload.next === 'login_passkey' ) {
						renderPasskeyLogin();
						return;
					}

					renderVerify( { identifierType: phonekeyState.identifierType, emailDelivery: phonekeyState.emailDelivery } );
					return;
				}

				phonekeyState.error = error.message;
				renderPhoneKeyStep( renderPhoneKeyStart(), bindPhoneKeyAuth );
			} );
	}

	function b64ToBuffer( value ) {
		let base64 = String( value || '' ).replace( /-/g, '+' ).replace( /_/g, '/' );

		while ( base64.length % 4 ) {
			base64 += '=';
		}

		const binary = window.atob( base64 );
		const bytes = new Uint8Array( binary.length );

		for ( let index = 0; index < binary.length; index++ ) {
			bytes[index] = binary.charCodeAt( index );
		}

		return bytes.buffer;
	}

	function bufferToB64( buffer ) {
		const bytes = new Uint8Array( buffer );
		let binary = '';

		for ( let index = 0; index < bytes.length; index++ ) {
			binary += String.fromCharCode( bytes[index] );
		}

		return window.btoa( binary ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=/g, '' );
	}

	function prepareCreateOptions( options ) {
		options.challenge = b64ToBuffer( options.challenge );
		options.user.id = b64ToBuffer( options.user.id );
		( options.excludeCredentials || [] ).forEach( function ( credential ) {
			credential.id = b64ToBuffer( credential.id );
		} );
		return options;
	}

	function prepareGetOptions( options ) {
		options.challenge = b64ToBuffer( options.challenge );
		( options.allowCredentials || [] ).forEach( function ( credential ) {
			credential.id = b64ToBuffer( credential.id );
		} );
		return options;
	}

	function publicCredential( credential ) {
		const response = credential.response;
		const output = {
			id: credential.id,
			rawId: bufferToB64( credential.rawId ),
			type: credential.type,
			response: {
				clientDataJSON: bufferToB64( response.clientDataJSON ),
			},
		};

		if ( response.attestationObject ) {
			output.response.attestationObject = bufferToB64( response.attestationObject );
		}

		if ( response.authenticatorData ) {
			output.response.authenticatorData = bufferToB64( response.authenticatorData );
		}

		if ( response.signature ) {
			output.response.signature = bufferToB64( response.signature );
		}

		if ( response.userHandle ) {
			output.response.userHandle = bufferToB64( response.userHandle );
		}

		if ( response.getTransports ) {
			output.response.transports = response.getTransports();
		}

		return output;
	}

	function passkeyRegister() {
		if ( ! window.navigator.credentials ) {
			phonekeyState.error = 'This browser does not support passkeys.';
			renderEnroll( {} );
			return;
		}

		setPhoneKeyBusy( true );
		phoneKeyPost( 'webauthn/register/options', { token: phonekeyState.token } )
			.then( function ( response ) {
				return window.navigator.credentials.create( { publicKey: prepareCreateOptions( response.publicKey ) } )
					.then( function ( credential ) {
						return phoneKeyPost( 'webauthn/register/verify', { token: response.token, credential: publicCredential( credential ) } );
					} );
			} )
			.then( afterPhoneKeyAuth )
			.catch( function ( error ) {
				phonekeyState.error = error.message || 'Passkey setup failed.';
				renderEnroll( {} );
			} )
			.finally( function () {
				setPhoneKeyBusy( false );
			} );
	}

	function passkeyLogin() {
		if ( ! window.navigator.credentials ) {
			phonekeyState.error = 'This browser does not support passkeys.';
			renderPasskeyLogin();
			return;
		}

		setPhoneKeyBusy( true );
		phoneKeyPost( 'webauthn/login/options', { token: phonekeyState.token } )
			.then( function ( response ) {
				return window.navigator.credentials.get( { publicKey: prepareGetOptions( response.publicKey ) } )
					.then( function ( credential ) {
						return phoneKeyPost( 'webauthn/login/verify', { token: response.token, credential: publicCredential( credential ) } );
					} );
			} )
			.then( afterPhoneKeyAuth )
			.catch( function ( error ) {
				phonekeyState.error = error.message || 'Passkey login failed.';
				renderPasskeyLogin();
			} )
			.finally( function () {
				setPhoneKeyBusy( false );
			} );
	}

	function afterPhoneKeyAuth( response ) {
		if ( response.requiresTotp ) {
			phonekeyState.loginToken = response.loginToken;
			renderTotp( response );
			return;
		}

		if ( response.bindPhoneRequired ) {
			if ( response.token ) {
				phonekeyState.token = response.token;
			}
			phonekeyState.adminPhoneBinding = true;
			renderBindPhone( response );
			return;
		}

		phoneKeyDone( response );
	}

	function renderBindPhone( response ) {
		const phoneReady = Boolean( response && response.phoneReady );

		renderPhoneKeyStep(
			[
				'<h2>Add a phone number</h2>',
				'<p>' + ( phoneReady ? 'Admin accounts must verify a phone number. Enter it to receive a one-time code.' : 'Admin accounts must record a phone number. Phone delivery is not configured yet, so this will be saved as pending.' ) + '</p>',
				renderPhoneKeyError(),
				'<input class="dsa-auth-field" id="dsa-pk-phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="Phone number">',
				'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-bind-phone>' + ( phoneReady ? 'Send code' : 'Save phone' ) + '</button></div>',
			].join( '' ),
			function ( root ) {
				root.querySelector( '[data-dsa-pk-bind-phone]' ).addEventListener( 'click', function () {
					const phone = root.querySelector( '#dsa-pk-phone' ).value.trim();

					if ( ! phone ) {
						phonekeyState.error = 'Enter a phone number.';
						renderBindPhone( response );
						return;
					}

					setPhoneKeyBusy( true );
					phoneKeyPost( 'bind-phone', { token: phonekeyState.token, phone: phone } )
						.then( function ( bindResponse ) {
							phonekeyState.error = '';

							if ( bindResponse && bindResponse.otpDispatched ) {
								phonekeyState.identifierType = 'phone';
								phonekeyState.emailDelivery = 'otp';
								phonekeyState.adminPhoneBinding = true;
								renderVerify( { identifierType: 'phone', emailDelivery: 'otp' } );
								return;
							}

							if ( bindResponse && ( bindResponse.redirect || bindResponse.requiresTotp ) ) {
								phonekeyState.adminPhoneBinding = false;
								afterPhoneKeyAuth( bindResponse );
								return;
							}

							renderPhoneKeyStep( '<h2>Phone saved</h2><p>Your phone number is recorded as pending. You can verify it later from the Kiwe sign-in.</p>' );
						} )
						.catch( function ( error ) {
							phonekeyState.error = error.message || 'Could not save phone.';
							renderBindPhone( response );
						} )
						.finally( function () {
							setPhoneKeyBusy( false );
						} );
				} );
			}
		);
	}

	function renderTotp( response ) {
		renderPhoneKeyStep(
			[
				'<h2>Authenticator code</h2>',
				'<p>' + ( response.totpEnrolled ? 'Enter the code from your authenticator app.' : 'This role requires an authenticator app. Use a backup code if you have one.' ) + '</p>',
				renderPhoneKeyError(),
				'<input class="dsa-auth-field" id="dsa-pk-totp" inputmode="numeric" placeholder="123456">',
				'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-totp>Verify</button><button class="dsa-panel__button" data-dsa-pk-backup>Backup code</button></div>',
			].join( '' ),
			function ( root ) {
				root.querySelector( '[data-dsa-pk-totp]' ).addEventListener( 'click', function () {
					phoneKeyPost( 'totp/login-verify', { token: phonekeyState.loginToken, code: root.querySelector( '#dsa-pk-totp' ).value } )
						.then( phoneKeyDone )
						.catch( function ( error ) {
							phonekeyState.error = error.message;
							renderTotp( response );
						} );
				} );
				root.querySelector( '[data-dsa-pk-backup]' ).addEventListener( 'click', backupScreen );
			}
		);
	}

	function backupScreen() {
		renderPhoneKeyStep(
			'<h2>Backup code</h2><p>Enter one of your saved one-time backup codes.</p>' + renderPhoneKeyError() + '<input class="dsa-auth-field" id="dsa-pk-backup" placeholder="ABCD-EFGH"><div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-backup-verify>Verify</button></div>',
			function ( root ) {
				root.querySelector( '[data-dsa-pk-backup-verify]' ).addEventListener( 'click', function () {
					phoneKeyPost( 'backup/login-verify', { token: phonekeyState.loginToken, code: root.querySelector( '#dsa-pk-backup' ).value } )
						.then( phoneKeyDone )
						.catch( function ( error ) {
							phonekeyState.error = error.message;
							backupScreen();
						} );
				} );
			}
		);
	}

	function tryAnother() {
		const buttons = [
			phonekeyState.canEmailRecovery ? '<button class="dsa-panel__button" data-dsa-pk-recovery>Email recovery code</button>' : '',
			phonekeyState.hasTotp ? '<button class="dsa-panel__button" data-dsa-pk-totp-recovery>Authenticator app code</button>' : '',
			phonekeyState.hasBackup ? '<button class="dsa-panel__button" data-dsa-pk-backup-recovery>Backup code</button>' : '',
			'<button class="dsa-panel__button" data-dsa-pk-back>Back to passkey</button>',
		].join( '' );
		const empty = ! phonekeyState.canEmailRecovery && ! phonekeyState.hasTotp && ! phonekeyState.hasBackup;

		renderPhoneKeyStep(
			'<h2>Try another way</h2><p>' + ( empty ? 'No self-service recovery method is available. Contact the site admin.' : 'Only available recovery methods are shown.' ) + '</p>' + renderPhoneKeyError() + '<div class="dsa-auth-actions">' + buttons + '</div>',
			function ( root ) {
				const recovery = root.querySelector( '[data-dsa-pk-recovery]' );
				const totp = root.querySelector( '[data-dsa-pk-totp-recovery]' );
				const backup = root.querySelector( '[data-dsa-pk-backup-recovery]' );

				if ( recovery ) {
					recovery.addEventListener( 'click', sendRecovery );
				}

				if ( totp ) {
					totp.addEventListener( 'click', totpRecovery );
				}

				if ( backup ) {
					backup.addEventListener( 'click', backupRecovery );
				}

				root.querySelector( '[data-dsa-pk-back]' ).addEventListener( 'click', renderPasskeyLogin );
			}
		);
	}

	function totpRecovery() {
		renderPhoneKeyStep(
			'<h2>Authenticator recovery</h2><p>Enter your authenticator app code.</p>' + renderPhoneKeyError() + '<input class="dsa-auth-field" id="dsa-pk-totp-r" inputmode="numeric" placeholder="123456"><div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-totp-r>Verify</button></div>',
			function ( root ) {
				root.querySelector( '[data-dsa-pk-totp-r]' ).addEventListener( 'click', function () {
					phoneKeyPost( 'totp/recovery-verify', { token: phonekeyState.token, code: root.querySelector( '#dsa-pk-totp-r' ).value } )
						.then( phoneKeyDone )
						.catch( function ( error ) {
							phonekeyState.error = error.message;
							totpRecovery();
						} );
				} );
			}
		);
	}

	function backupRecovery() {
		renderPhoneKeyStep(
			'<h2>Backup code</h2><p>Enter one of your saved one-time backup codes.</p>' + renderPhoneKeyError() + '<input class="dsa-auth-field" id="dsa-pk-backup-r" placeholder="ABCD-EFGH"><div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" data-dsa-pk-backup-r>Verify</button></div>',
			function ( root ) {
				root.querySelector( '[data-dsa-pk-backup-r]' ).addEventListener( 'click', function () {
					phoneKeyPost( 'backup/recovery-verify', { token: phonekeyState.token, code: root.querySelector( '#dsa-pk-backup-r' ).value } )
						.then( phoneKeyDone )
						.catch( function ( error ) {
							phonekeyState.error = error.message;
							backupRecovery();
						} );
				} );
			}
		);
	}

	function phoneKeyDone( response ) {
		renderPhoneKeyStep( '<h2>You are in</h2><p>Secure login complete.</p>' );

		if ( response && response.redirect ) {
			window.location.href = response.redirect;
			return;
		}

		window.setTimeout( function () {
			window.location.reload();
		}, 650 );
	}

	function renderCartPanel( label ) {
		const adapter = presentationModules.get( 'cart' );
		if ( ! adapter || typeof adapter.renderCart !== 'function' ) return renderLazyPresentation( 'cart', label );
		return adapter.renderCart( {
			label: label,
			cart: phonekey.cart || {},
			settings: commerce.settings || {},
			routes: commerce.routes || {},
			complements: commerce.complements || [],
			visualProfile: currentVisualProfile(),
			trustBadges: protectedTrustBadges(),
		} );
	}

	function renderCheckoutPanel() {
		const adapter = presentationModules.get( 'checkout' );
		if ( ! adapter || typeof adapter.renderCheckout !== 'function' ) return renderLazyPresentation( 'checkout', 'Checkout' );
		return adapter.renderCheckout( { checkoutState: checkoutState, settings: commerce.settings || {}, routes: commerce.routes || {} } );
	}







	function preserveDockAnchor() {
		if ( ! surface || surface.classList.contains( 'has-preserved-dock-anchor' ) ) return;
		// Mobile placement is a live viewport contract. Capturing a pixel top here
		// drifts when browser chrome or the Surface scroll viewport changes height.
		if ( surface.dataset.dsaDockMobile === '1' ) return;
		const cluster = surface.querySelector( ':scope > .dsa-dock-cluster' );
		if ( ! cluster ) return;
		const rect = cluster.getBoundingClientRect();
		if ( rect.width <= 0 || rect.height <= 0 ) return;
		surface.style.setProperty( '--dsa-active-dock-left', rect.left.toFixed( 2 ) + 'px' );
		surface.style.setProperty( '--dsa-active-dock-top', rect.top.toFixed( 2 ) + 'px' );
		surface.classList.add( 'has-preserved-dock-anchor' );
	}

	function releaseDockAnchor() {
		if ( ! surface ) return;
		surface.classList.remove( 'has-preserved-dock-anchor' );
		surface.style.removeProperty( '--dsa-active-dock-left' );
		surface.style.removeProperty( '--dsa-active-dock-top' );
	}

	function setOverlayActive( active ) {
		if ( active && ! document.documentElement.classList.contains( 'dsa-overlay-active' ) ) {
			preserveDockAnchor();
		}
		document.documentElement.classList.toggle( 'dsa-overlay-active', active );
		if ( ! active ) releaseDockAnchor();

		if ( scrim ) {
			const token = ++overlayVisibilityToken;

			if ( active ) {
				scrim.hidden = false;
				window.requestAnimationFrame( function () {
					if ( token === overlayVisibilityToken ) {
						scrim.classList.add( 'is-visible' );
					}
				} );
			} else {
				scrim.classList.remove( 'is-visible' );
				window.setTimeout( function () {
					if ( token === overlayVisibilityToken && ! scrim.classList.contains( 'is-visible' ) ) {
						scrim.hidden = true;
					}
				}, 220 );
			}
		}

		window.dispatchEvent(
			new CustomEvent( active ? 'surface:overlay:open' : 'surface:overlay:close' )
		);
	}

	function setSurfaceScrollLocked( locked ) {
		const root = document.documentElement;
		if ( ! root || ! document.body ) {
			return;
		}

		if ( locked ) {
			if ( root.classList.contains( 'dsa-scroll-locked' ) ) {
				return;
			}

			surfaceScrollY = Math.max( 0, window.scrollY || window.pageYOffset || 0 );
			root.style.setProperty( '--dsa-scroll-lock-top', '-' + surfaceScrollY + 'px' );
			root.classList.add( 'dsa-scroll-locked' );
			return;
		}

		if ( ! root.classList.contains( 'dsa-scroll-locked' ) ) {
			return;
		}

		root.classList.remove( 'dsa-scroll-locked' );
		root.style.removeProperty( '--dsa-scroll-lock-top' );
		window.scrollTo( 0, surfaceScrollY );
	}

	function enterSurfaceMode( mode, force ) {
		if ( ! mode ) {
			return false;
		}

		if ( currentActiveMode === mode ) {
			return true;
		}

		if ( currentActiveMode && ! force ) {
			const currentPriority = modePriority[currentActiveMode] || 99;
			const nextPriority = modePriority[mode] || 99;

			if ( currentPriority < nextPriority ) {
				return false;
			}
		}

		if ( currentActiveMode === 'appsiteHome' && mode !== 'appsiteHome' ) {
			dismissAppsiteHomeScreens();
		}

		currentActiveMode = mode;
		document.documentElement.dataset.dsaSurfaceMode = mode;
		window.DSA.currentActiveMode = mode;
		setSurfaceScrollLocked( true );
		window.dispatchEvent( new CustomEvent( 'surface:mode:enter', { detail: { mode: mode } } ) );
		return true;
	}

	function clearSurfaceMode( mode ) {
		if ( mode && currentActiveMode !== mode ) {
			return;
		}

		const previous = currentActiveMode;
		currentActiveMode = '';
		delete document.documentElement.dataset.dsaSurfaceMode;
		window.DSA.currentActiveMode = '';
		setSurfaceScrollLocked( false );

		if ( previous ) {
			window.dispatchEvent( new CustomEvent( 'surface:mode:exit', { detail: { mode: previous } } ) );
		}
	}

	function hasActiveSurfaceMode() {
		return Boolean( currentActiveMode );
	}

	function hasInteractiveFocus() {
		const active = document.activeElement;

		if ( ! active || active === document.body || typeof active.closest !== 'function' ) {
			return false;
		}

		return Boolean( active.closest( 'input, textarea, select, [contenteditable="true"], [data-dsa-keep-open]' ) );
	}

	function closestEventTarget( event, selector ) {
		const target = event && event.target;
		return target && typeof target.closest === 'function' ? target.closest( selector ) : null;
	}

	function dismissAppsiteHomeScreens() {
		window.clearInterval( initialPreloaderClockTimer );
		initialPreloaderClockTimer = 0;
		const screens = document.querySelectorAll( '[data-dsa-appsite-home-active], [data-dsa-initial-preloader]' );

		if ( ! screens.length ) {
			clearSurfaceMode( 'appsiteHome' );
			return;
		}

		screens.forEach( function ( screen ) {
			screen.classList.add( 'is-dismissing' );
			window.setTimeout( function () {
				if ( screen.parentNode ) {
					screen.remove();
				}
			}, 190 );
		} );
		window.setTimeout( function () {
			clearSurfaceMode( 'appsiteHome' );
		}, 190 );
	}

	function showLoader( targetUrl ) {
		if ( ! loader ) {
			return;
		}

		if ( overlayRoot && ! overlayRoot.hidden ) {
			closeOverlay( true, { immediate: true, retainHistory: true } );
		}

		if ( ! enterSurfaceMode( 'transition', true ) ) {
			return;
		}

		window.clearInterval( preloaderClockTimer );
		preloaderClockTimer = 0;
		const hasMessage = renderLoaderMessage( targetUrl );

		if ( visual.loader_type === 'none' && ! hasMessage ) {
			clearSurfaceMode( 'transition' );
			return;
		}

		loaderStartedAt = Date.now();
		loader.hidden = false;
		setOverlayActive( true );
		announce( 'Surface loading experience started.' );
		recordMetric( 'transition_start', transitionMetricContext( targetUrl ) );
		window.dispatchEvent( new CustomEvent( 'surface:loading:start' ) );
	}

	function renderLoaderMessage( targetUrl ) {
		const messages = Array.isArray( visual.transition_messages ) ? visual.transition_messages.filter( function ( item ) {
			return item && ( item.title || item.message );
		} ) : [];
		const commerceMessage = commerceTransitionMessage( targetUrl );

		if ( commerceMessage && renderLoaderMessageItem( commerceMessage ) ) {
			return true;
		}

		if ( ! loaderMessage || ! loaderTitle || ! loaderCopy || ! messages.length ) {
			if ( loader ) {
				loader.classList.remove( 'has-message' );
			}
			if ( loaderMessage ) {
				loaderMessage.hidden = true;
			}
			if ( loaderLabel ) {
				loaderLabel.hidden = false;
			}
			return false;
		}

		const fixedIndex = Math.max( 0, Math.min( messages.length - 1, Number( visual.transition_message_index ) || 0 ) );
		const index = visual.transition_message_mode === 'fixed'
			? fixedIndex
			: Math.floor( Math.random() * messages.length );
		const item = messages[index] || messages[0];
		const titlePosition = visual.transition_title_position === 'below' ? 'below' : 'above';
		const meta = loaderMessage.querySelector( '[data-dsa-preloader-meta]' );

		loader.classList.remove( 'is-initial-preloader' );
		if ( meta ) {
			meta.remove();
		}

		loaderMessage.classList.toggle( 'is-title-below', titlePosition === 'below' );
		loaderTitle.textContent = item.title || '';
		loaderCopy.textContent = item.message || '';
		loaderTitle.hidden = ! item.title;
		loaderCopy.hidden = ! item.message;
		loaderMessage.hidden = false;
		loader.classList.add( 'has-message' );
		syncLoaderTrustBadges();

		if ( loaderLabel ) {
			loaderLabel.hidden = true;
		}

		return true;
	}

	function renderLoaderMessageItem( item ) {
		if ( ! loaderMessage || ! loaderTitle || ! loaderCopy || ! item || ( ! item.title && ! item.message ) ) {
			return false;
		}

		const titlePosition = visual.transition_title_position === 'below' ? 'below' : 'above';
		const meta = loaderMessage.querySelector( '[data-dsa-preloader-meta]' );

		loader.classList.remove( 'is-initial-preloader' );
		if ( meta ) {
			meta.remove();
		}

		loaderMessage.classList.toggle( 'is-title-below', titlePosition === 'below' );
		loaderTitle.textContent = item.title || '';
		loaderCopy.textContent = item.message || '';
		loaderTitle.hidden = ! item.title;
		loaderCopy.hidden = ! item.message;
		loaderMessage.hidden = false;
		loader.classList.add( 'has-message' );
		syncLoaderTrustBadges();

		if ( loaderLabel ) {
			loaderLabel.hidden = true;
		}

		return true;
	}

	function syncLoaderTrustBadges() {
		if ( ! loaderMessage ) return;
		const existing = loaderMessage.querySelector( '[data-dsa-loader-trust]' );
		if ( existing ) existing.remove();
		const badges = protectedTrustBadges();
		if ( ! badges.length ) return;
		const rail = document.createElement( 'div' );
		rail.className = 'dsa-loader-trust';
		rail.dataset.dsaLoaderTrust = '1';
		rail.innerHTML = badges.map( function ( badge ) {
			return '<span class="dsa-protected-badge' + ( badge.active ? ' is-active' : '' ) + '"><i aria-hidden="true"></i>' + escapeHtml( badge.label ) + '</span>';
		} ).join( '' );
		loaderMessage.appendChild( rail );
	}

	function commerceTransitionMessage( targetUrl ) {
		if ( ! commerce || ! commerce.available || ! targetUrl ) {
			return null;
		}

		const url = new URL( targetUrl, window.location.href );
		const messages = commerce.transitionMessages || {};

		if ( isCheckoutNavigationUrl( url ) ) {
			return messages.checkoutReadiness || commerce.decision || null;
		}

		if ( isCartNavigationUrl( url ) ) {
			return messages.cartReminder || commerce.decision || null;
		}

		if ( isShopNavigationUrl( url ) ) {
			return messages.shopContext || null;
		}

		return null;
	}

	function transitionMetricContext( targetUrl ) {
		try {
			const url = new URL( targetUrl || window.location.href, window.location.href );

			if ( isCheckoutNavigationUrl( url ) ) {
				return 'checkout';
			}
			if ( isCartNavigationUrl( url ) ) {
				return 'cart';
			}
			if ( isAccountNavigationUrl( url ) ) {
				return 'account';
			}
			if ( isShopNavigationUrl( url ) ) {
				return 'shop';
			}

			return 'other';
		} catch ( error ) {
			return 'unknown';
		}
	}

	function showInitialPreloader() {
		if ( initialPreloader || appsiteHomeSeen() || ! surfaceTriggerEnabled( 'first_session_home', visual.initial_preloader_enabled ) || isProtectedFlowActive() ) {
			return;
		}

		if ( ! document.body || document.querySelector( '[data-dsa-initial-preloader]' ) ) {
			return;
		}

		markAppsiteHomeSeen();
		showAppsiteHomeScreen( { markSeen: false } );
	}

	function bindInitialPreloader() {
		if ( ! initialPreloader ) {
			return;
		}

		if ( isProtectedFlowActive() ) {
			initialPreloader.remove();
			document.documentElement.classList.add( 'dsa-appsite-home-seen' );
			return;
		}

		if ( appsiteHomeSeen() ) {
			initialPreloader.remove();
			document.documentElement.classList.add( 'dsa-appsite-home-seen' );
			return;
		}

		markAppsiteHomeSeen();
		bindAppsiteHomeScreen( initialPreloader );
	}

	function bindAppsiteHomeScreen( screen ) {
		if ( ! enterSurfaceMode( 'appsiteHome' ) ) {
			screen.remove();
			return;
		}

		const clock = screen.querySelector( '[data-dsa-initial-clock]' );
		let shift = 0;
		let touchY = 0;
		let closed = false;
		let wheelDismissDelta = 0;
		let wheelDismissAt = 0;
		let handleHomeKeydown = null;
		const maxShift = Math.max( 180, Math.round( window.innerHeight * 0.56 ) );
		const closeThreshold = Math.max( 72, Math.min( 140, Math.round( window.innerHeight * 0.16 ) ) );
		const tick = function () {
			if ( clock ) {
				const now = new Date();
				const date = now.toLocaleDateString( [], {
					weekday: 'short',
					month: 'short',
					day: 'numeric',
				} );
				const time = now.toLocaleTimeString( [], {
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
				} );
				clock.textContent = date + ' · ' + time;
			}
		};
		const applyShift = function () {
			const progress = Math.min( 1, shift / maxShift );
			screen.style.setProperty( '--dsa-appsite-shift', '-' + Math.round( shift ) + 'px' );
			screen.style.setProperty( '--dsa-appsite-opacity', String( Math.max( 0.18, 1 - progress * 0.72 ) ) );
		};
		const close = function ( reason ) {
			if ( closed ) {
				return;
			}

			closed = true;
			window.removeEventListener( 'wheel', handleHomeWheel, true );
			if ( handleHomeKeydown ) {
				document.removeEventListener( 'keydown', handleHomeKeydown );
			}
			window.clearInterval( initialPreloaderClockTimer );
			initialPreloaderClockTimer = 0;
			recordMetric( 'appsite_home_dismiss', reason || 'scroll_or_escape' );
			screen.classList.add( 'is-dismissing' );
			window.setTimeout( function () {
				screen.hidden = true;
				screen.remove();
				clearSurfaceMode( 'appsiteHome' );
			}, 190 );
		};
		const moveBy = function ( delta ) {
			if ( closed ) {
				return;
			}

			shift = Math.max( 0, Math.min( maxShift, shift + delta ) );
			applyShift();

			if ( shift >= closeThreshold ) {
				close( 'scroll_or_swipe' );
			}
		};
		const handleHomeWheel = function ( event ) {
			if ( closed || ! document.body || ! document.body.contains( screen ) ) {
				return;
			}

			if ( event.target && event.target.closest && event.target.closest( '[data-dsa-initial-action]' ) ) {
				return;
			}

			const deltaY = Number( event.deltaY || 0 );
			const maxScroll = Math.max( 0, screen.scrollHeight - screen.clientHeight );
			if ( maxScroll > 1 && deltaY < 0 && screen.scrollTop > 1 ) {
				return;
			}

			event.preventDefault();
			if ( deltaY <= 0 ) {
				moveBy( deltaY );
				return;
			}

			const now = Date.now();
			if ( now - wheelDismissAt > 420 ) {
				wheelDismissDelta = 0;
			}
			wheelDismissAt = now;
			const amplifiedDelta = Math.max( deltaY, Math.min( 28, closeThreshold * 0.35 ) );
			wheelDismissDelta += amplifiedDelta;

			if ( wheelDismissDelta >= Math.min( 56, closeThreshold * 0.72 ) ) {
				close( 'trackpad_or_wheel' );
				return;
			}

			moveBy( amplifiedDelta );
		};

		screen.dataset.dsaAppsiteHomeActive = '1';
		recordMetric( 'appsite_home_view', initialPreloader && screen === initialPreloader ? 'first_session' : 'idle' );
		tick();
		initialPreloaderClockTimer = window.setInterval( tick, 1000 );
		screen.querySelectorAll( '[data-dsa-install-pwa]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				handlePwaInstall( button, button.dataset.dsaPwaPlatform || '' );
			} );
		} );
		window.addEventListener( 'wheel', handleHomeWheel, { passive: false, capture: true } );
		screen.addEventListener( 'dsa:appsite-home:dismiss', function ( event ) {
			close( event && event.detail && event.detail.reason ? event.detail.reason : 'programmatic' );
		} );
		screen.addEventListener( 'touchstart', function ( event ) {
			touchY = event.touches && event.touches.length ? event.touches[0].clientY : 0;
			screen.classList.add( 'is-dragging' );
		}, { passive: true } );
		screen.addEventListener( 'touchmove', function ( event ) {
			if ( ( event.target && event.target.closest && event.target.closest( '[data-dsa-initial-action]' ) ) || ! event.touches || ! event.touches.length ) {
				return;
			}

			const nextY = event.touches[0].clientY;
			const delta = touchY - nextY;
			event.preventDefault();
			moveBy( delta );
			touchY = nextY;
		}, { passive: false } );
		const finishHomeTouch = function () {
			screen.classList.remove( 'is-dragging' );
			if ( closed ) {
				return;
			}
			if ( shift >= Math.min( 56, closeThreshold * 0.7 ) ) {
				close( 'scroll_or_swipe' );
				return;
			}
			shift = 0;
			applyShift();
		};
		screen.addEventListener( 'touchend', finishHomeTouch, { passive: true } );
		screen.addEventListener( 'touchcancel', finishHomeTouch, { passive: true } );
		handleHomeKeydown = function ( event ) {
			if ( ! document.body || ! document.body.contains( screen ) ) {
				document.removeEventListener( 'keydown', handleHomeKeydown );
				return;
			}

			if ( event.key === 'Escape' ) {
				event.preventDefault();
				close( 'escape' );
			}
			if ( [ 'ArrowDown', 'PageDown', ' ', 'Spacebar' ].indexOf( event.key ) !== -1 && ! hasInteractiveFocus() ) {
				event.preventDefault();
				close( 'keyboard' );
			}
		};
		document.addEventListener( 'keydown', handleHomeKeydown );
	}

	function appsiteHomeSeen() {
		try {
			return window.sessionStorage && window.sessionStorage.getItem( 'dsa_appsite_home_seen' ) === '1';
		} catch ( error ) {
			return document.documentElement.classList.contains( 'dsa-appsite-home-seen' );
		}
	}

	function markAppsiteHomeSeen() {
		try {
			if ( window.sessionStorage ) {
				window.sessionStorage.setItem( 'dsa_appsite_home_seen', '1' );
			}
		} catch ( error ) {}
	}

	function applyThemeVariables() {
		const root = document.documentElement;
		root.style.setProperty( '--dsa-active-color', theme.active_color || '#8f8f98' );
		root.style.setProperty( '--dsa-hover-color', theme.hover_color || '#24c6a1' );
		root.style.setProperty( '--dsa-hero-text-color', theme.hero_text_color || 'rgba(20,24,34,0.18)' );
	}

	function geometryCssNumber( name, fallback ) {
		const value = parseFloat( window.getComputedStyle( document.documentElement ).getPropertyValue( name ) );
		return Number.isFinite( value ) ? value : fallback;
	}

	function geometrySafeAreaInsets() {
		if ( ! document.body ) return { top: 0, right: 0, bottom: 0, left: 0 };
		const probe = document.createElement( 'div' );
		probe.setAttribute( 'aria-hidden', 'true' );
		probe.style.cssText = 'position:fixed;visibility:hidden;pointer-events:none;padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);';
		document.body.appendChild( probe );
		const computed = window.getComputedStyle( probe );
		const insets = {
			top: parseFloat( computed.paddingTop ) || 0,
			right: parseFloat( computed.paddingRight ) || 0,
			bottom: parseFloat( computed.paddingBottom ) || 0,
			left: parseFloat( computed.paddingLeft ) || 0,
		};
		probe.remove();
		return insets;
	}

	function interpolateGeometry( minimum, ideal, ratio ) {
		return minimum + ( ideal - minimum ) * ratio;
	}

	function resolvedDockGeometry( available, itemCount, hasAi ) {
		const values = {
			controlMin: geometryCssNumber( '--dsa-geometry-control-min', 30 ),
			controlIdeal: geometryCssNumber( '--dsa-geometry-control-ideal', 48 ),
			aiMin: geometryCssNumber( '--dsa-geometry-control-min', 30 ),
			aiIdeal: geometryCssNumber( '--dsa-geometry-control-ideal', 48 ),
			iconMin: geometryCssNumber( '--dsa-geometry-icon-min', 18 ),
			iconIdeal: geometryCssNumber( '--dsa-geometry-icon-ideal', 23 ),
			badgeMin: geometryCssNumber( '--dsa-geometry-badge-min', 16 ),
			badgeIdeal: geometryCssNumber( '--dsa-geometry-badge-ideal', 18 ),
			gapMin: geometryCssNumber( '--dsa-geometry-dock-gap-min', 1 ),
			gapIdeal: geometryCssNumber( '--dsa-geometry-dock-gap-ideal', 2 ),
			paddingMin: geometryCssNumber( '--dsa-geometry-dock-padding-min', 6 ),
			paddingIdeal: geometryCssNumber( '--dsa-geometry-dock-padding-ideal', 8 ),
			border: geometryCssNumber( '--dsa-geometry-dock-border', 1 ),
			clusterMin: geometryCssNumber( '--dsa-geometry-cluster-gap-min', 6 ),
			clusterIdeal: geometryCssNumber( '--dsa-geometry-cluster-gap-ideal', 14 ),
		};
		itemCount = Math.max( 0, Number( itemCount ) || 0 );
		const itemGaps = Math.max( 0, itemCount - 1 );
		const minimumTotal = itemCount * values.controlMin
			+ itemGaps * values.gapMin
			+ ( itemCount ? ( values.paddingMin + values.border ) * 2 : 0 )
			+ ( hasAi ? values.aiMin - values.controlMin : 0 );
		const idealTotal = itemCount * values.controlIdeal
			+ itemGaps * values.gapIdeal
			+ ( itemCount ? ( values.paddingIdeal + values.border ) * 2 : 0 )
			+ ( hasAi ? values.aiIdeal - values.controlIdeal : 0 );
		let ratio = idealTotal > minimumTotal ? ( available - minimumTotal ) / ( idealTotal - minimumTotal ) : 1;
		ratio = Math.max( 0, Math.min( 1, ratio ) );
		let control = interpolateGeometry( values.controlMin, values.controlIdeal, ratio );
		let ai = hasAi ? interpolateGeometry( values.aiMin, values.aiIdeal, ratio ) : 0;
		let icon = interpolateGeometry( values.iconMin, values.iconIdeal, ratio );
		let badge = interpolateGeometry( values.badgeMin, values.badgeIdeal, ratio );
		let gap = interpolateGeometry( values.gapMin, values.gapIdeal, ratio );
		let padding = interpolateGeometry( values.paddingMin, values.paddingIdeal, ratio );
		let clusterGap = 0;

		if ( available < minimumTotal && minimumTotal > 0 ) {
			const fixedBorder = itemCount ? values.border * 2 : 0;
			const compression = Math.max( 0.1, Math.min( 1, ( available - fixedBorder ) / Math.max( 1, minimumTotal - fixedBorder ) ) );
			control = values.controlMin * compression;
			ai = hasAi ? values.aiMin * compression : 0;
			icon = values.iconMin * compression;
			badge = values.badgeMin * compression;
			gap = values.gapMin * compression;
			padding = values.paddingMin * compression;
			clusterGap = 0;
		}

		const mainAxis = itemCount * control + itemGaps * gap + ( itemCount ? ( padding + values.border ) * 2 : 0 ) + ( hasAi ? ai - control : 0 );
		return {
			control: control,
			ai: ai,
			icon: icon,
			badge: badge,
			gap: gap,
			padding: padding,
			clusterGap: clusterGap,
			mainAxis: mainAxis,
			clusterAxis: mainAxis,
			crossAxis: Math.max( itemCount ? control : 0, hasAi ? ai : 0 ) + ( itemCount ? ( padding + values.border ) * 2 : 0 ),
		};
	}

	function applySurfaceGeometry() {
		if ( ! surface ) return;
		reconcileInactiveOverlayState();
		const viewport = window.visualViewport || { width: window.innerWidth, height: window.innerHeight };
		const widthCandidates = [ Number( viewport.width ), Number( window.innerWidth ), Number( document.documentElement && document.documentElement.clientWidth ) ].filter( function ( value ) { return Number.isFinite( value ) && value > 0; } );
		const heightCandidates = [ Number( viewport.height ), Number( window.innerHeight ), Number( document.documentElement && document.documentElement.clientHeight ) ].filter( function ( value ) { return Number.isFinite( value ) && value > 0; } );
		const width = Math.max( 1, widthCandidates.length ? Math.min.apply( Math, widthCandidates ) : 1 );
		const height = Math.max( 1, heightCandidates.length ? Math.min.apply( Math, heightCandidates ) : 1 );
		const mobileBreakpoint = Math.max( 320, Number( dockSettings.mobile_breakpoint ) || 640 );
		const tabletBreakpoint = Math.max( mobileBreakpoint + 1, Number( dockSettings.tablet_breakpoint ) || 1024 );
		const profile = width <= mobileBreakpoint ? 'mobile' : ( width <= tabletBreakpoint ? 'tablet' : 'desktop' );
		const mobile = profile === 'mobile';
		const requestedOrientation = String( dockSettings[ profile + '_orientation' ] || dockSettings.desktop_orientation || 'auto' );
		const orientation = requestedOrientation === 'horizontal' || requestedOrientation === 'vertical'
			? requestedOrientation
			: ( width <= 820 || width <= height ? 'horizontal' : 'vertical' );
		const presentation = String( dockSettings.presentation || surface.dataset.dsaDockPresentation || 'dock' );
		const navbar = presentation === 'navbar';
		const verticalPosition = String( dockSettings[ profile + '_vertical_position' ] || ( profile === 'mobile' ? 'bottom' : 'center' ) );
		const horizontalAlignment = String( dockSettings[ profile + '_horizontal_position' ] || ( profile === 'desktop' ? 'right' : 'center' ) );
		const horizontalPosition = String( dockSettings[ profile + '_horizontal_vertical_position' ] || 'bottom' );
		const verticalEdge = String( dockSettings[ profile + '_vertical_edge' ] || 'right' );
		const horizontalEdge = String( dockSettings[ profile + '_horizontal_edge' ] || 'bottom' );
		const mainDock = surface.querySelector( '.dsa-phonekey-dock' );
		const itemCount = mainDock ? mainDock.querySelectorAll( ':scope > [data-dsa-module]' ).length : Number( surface.dataset.dsaDockItemCount ) || 0;
		const hasAi = Boolean( surface.querySelector( '.dsa-ai-launcher' ) );
		const safe = geometrySafeAreaInsets();
		const gutter = geometryCssNumber( '--dsa-geometry-viewport-gutter', 12 );
		const adminBar = document.getElementById( 'wpadminbar' );
		const adminBarHeight = adminBar && window.getComputedStyle( adminBar ).display !== 'none' ? adminBar.getBoundingClientRect().height : 0;
		const before = orientation === 'horizontal' ? Math.max( gutter, safe.left ) : Math.max( gutter, safe.top ) + adminBarHeight;
		const after = orientation === 'horizontal' ? Math.max( gutter, safe.right ) : Math.max( gutter, safe.bottom );
		const available = Math.max( 1, ( orientation === 'horizontal' ? width : height ) - before - after );
		const geometry = resolvedDockGeometry( available, itemCount, hasAi );
		const contextRect = dockContext && ! dockContext.hidden ? dockContext.getBoundingClientRect() : null;
		const contextSize = contextRect && contextRect.height > 0 ? contextRect.height + ( navbar ? 0 : geometry.clusterGap ) : 0;
		const dockReserve = geometry.crossAxis + ( navbar ? 0 : gutter );
		const blockReserve = ( orientation === 'horizontal' ? dockReserve : gutter ) + contextSize;
		const inlineReserve = orientation === 'vertical' ? dockReserve : gutter;
		const reserve = Math.max( blockReserve, inlineReserve );
		const panelInline = Math.max( 1, width - inlineReserve - gutter * 2 );
		const panelBlock = Math.max( 1, height - blockReserve - safe.top - safe.bottom - adminBarHeight - gutter );
		const layout = panelInline < 540 ? 'narrow' : ( panelInline < 820 ? 'compact' : 'wide' );
		const density = panelBlock < 560 ? 'dense' : 'comfortable';
		const dockCluster = surface.querySelector( '[data-dsa-dock-cluster]' );
		const activePanel = overlayRoot && ! overlayRoot.hidden ? overlayRoot.querySelector( ':scope > .dsa-panel' ) : null;
		const dockAnchorRect = mainDock ? mainDock.getBoundingClientRect() : ( dockCluster ? dockCluster.getBoundingClientRect() : null );
		const anchorRect = orientation === 'horizontal' ? dockAnchorRect : ( activePanel ? activePanel.getBoundingClientRect() : dockAnchorRect );
		const fullAxisContext = navbar && orientation === 'horizontal';
		const dockAxisContext = navbar && orientation === 'vertical' && ! activePanel;
		const contextWidth = fullAxisContext
			? width
			: ( dockAxisContext ? geometry.crossAxis : ( anchorRect && anchorRect.width > 0 ? anchorRect.width : Math.max( 1, width - inlineReserve - gutter ) ) );
		const contextLeft = fullAxisContext
			? 0
			: ( dockAxisContext && anchorRect && verticalEdge === 'right'
				? Math.max( gutter, anchorRect.left - contextWidth )
				: ( dockAxisContext && anchorRect && verticalEdge === 'left' ? Math.max( gutter, Math.min( width - contextWidth - gutter, anchorRect.right ) ) : ( anchorRect && anchorRect.width > 0 ? anchorRect.left : gutter ) ) );
		const contextTop = orientation === 'vertical' && anchorRect
			? Math.max( safe.top + gutter + adminBarHeight, Math.min( height - safe.bottom - ( contextRect ? contextRect.height : 0 ) - gutter, anchorRect.bottom + Math.max( 3, geometry.gap ) ) )
			: 0;
		const properties = {
			'--dsa-dock-item-count': itemCount,
			'--dsa-dock-ai-count': hasAi ? 1 : 0,
			'--dsa-dock-control-size': geometry.control.toFixed( 2 ) + 'px',
			'--dsa-dock-ai-size': geometry.ai.toFixed( 2 ) + 'px',
			'--dsa-dock-icon-size': geometry.icon.toFixed( 2 ) + 'px',
			'--dsa-dock-icon-scale': Math.max( 0.1, geometry.icon / geometryCssNumber( '--dsa-geometry-icon-ideal', 23 ) ).toFixed( 4 ),
			'--dsa-dock-badge-size': geometry.badge.toFixed( 2 ) + 'px',
			'--dsa-dock-gap': geometry.gap.toFixed( 2 ) + 'px',
			'--dsa-dock-padding': geometry.padding.toFixed( 2 ) + 'px',
			'--dsa-dock-cluster-gap': geometry.clusterGap.toFixed( 2 ) + 'px',
			'--dsa-dock-main-axis-size': geometry.mainAxis.toFixed( 2 ) + 'px',
			'--dsa-dock-cluster-axis-size': geometry.clusterAxis.toFixed( 2 ) + 'px',
			'--dsa-screen-dock-reserve': reserve.toFixed( 2 ) + 'px',
			'--dsa-dock-only-reserve': dockReserve.toFixed( 2 ) + 'px',
			'--dsa-screen-block-reserve': blockReserve.toFixed( 2 ) + 'px',
			'--dsa-screen-inline-reserve': inlineReserve.toFixed( 2 ) + 'px',
			'--dsa-screen-available-inline': panelInline.toFixed( 2 ) + 'px',
			'--dsa-screen-available-block': panelBlock.toFixed( 2 ) + 'px',
			'--dsa-visual-viewport-width': width.toFixed( 2 ) + 'px',
			'--dsa-visual-viewport-height': height.toFixed( 2 ) + 'px',
			'--dsa-context-bottom-offset': ( orientation === 'horizontal' ? geometry.crossAxis + ( navbar ? 0 : gutter ) : gutter ).toFixed( 2 ) + 'px',
			'--dsa-context-left': contextLeft.toFixed( 2 ) + 'px',
			'--dsa-context-width': contextWidth.toFixed( 2 ) + 'px',
			'--dsa-context-top': contextTop.toFixed( 2 ) + 'px',
			'--dsa-dock-context-size': contextSize.toFixed( 2 ) + 'px',
			'--dsa-sticky-action-offset': contextSize.toFixed( 2 ) + 'px',
			'--dsa-admin-bar-height': adminBarHeight.toFixed( 2 ) + 'px',
			'--dsa-admin-bar-half': ( adminBarHeight / 2 ).toFixed( 2 ) + 'px',
		};
		Object.keys( properties ).forEach( function ( name ) {
			document.documentElement.style.setProperty( name, String( properties[ name ] ) );
			surface.style.setProperty( name, String( properties[ name ] ) );
		} );
		surface.dataset.dsaDockOrientation = orientation;
		surface.dataset.dsaDockRequestedOrientation = requestedOrientation === 'horizontal' || requestedOrientation === 'vertical' ? requestedOrientation : 'auto';
		surface.dataset.dsaDockMobile = mobile ? '1' : '0';
		surface.dataset.dsaDockProfile = profile;
		surface.dataset.dsaDockPosition = orientation === 'horizontal' ? horizontalPosition : verticalPosition;
		surface.dataset.dsaDockAlignment = orientation === 'horizontal' ? horizontalAlignment : verticalPosition;
		surface.dataset.dsaDockEdge = orientation === 'horizontal' ? horizontalEdge : verticalEdge;
		surface.dataset.dsaLayout = layout;
		surface.dataset.dsaDensity = density;
		window.DSA.geometry = {
			version: 1,
			orientation: orientation,
			mobile: mobile,
			profile: profile,
			presentation: presentation,
			position: orientation === 'horizontal' ? horizontalPosition : verticalPosition,
			alignment: orientation === 'horizontal' ? horizontalAlignment : verticalPosition,
			edge: orientation === 'horizontal' ? horizontalEdge : verticalEdge,
			layout: layout,
			density: density,
			viewport: { width: width, height: height },
			availablePanel: { inline: panelInline, block: panelBlock },
			safeArea: safe,
			itemCount: itemCount,
			hasAi: hasAi,
			availableAxis: available,
			controlSize: geometry.control,
			reserve: reserve,
			blockReserve: blockReserve,
			inlineReserve: inlineReserve,
			contextSize: contextSize,
		};
		window.dispatchEvent( new CustomEvent( 'surface:geometry:change', { detail: window.DSA.geometry } ) );
	}

	function scheduleSurfaceGeometry() {
		window.cancelAnimationFrame( surfaceGeometryFrame );
		surfaceGeometryFrame = window.requestAnimationFrame( applySurfaceGeometry );
	}

	function initializeSurfaceGeometry() {
		applySurfaceGeometry();
		window.requestAnimationFrame( syncDockContextRail );
		window.addEventListener( 'resize', scheduleSurfaceGeometry, { passive: true } );
		window.addEventListener( 'orientationchange', scheduleSurfaceGeometry, { passive: true } );
		if ( window.visualViewport ) window.visualViewport.addEventListener( 'resize', scheduleSurfaceGeometry, { passive: true } );
		const dock = surface.querySelector( '.dsa-phonekey-dock' );
		if ( dock && typeof MutationObserver === 'function' ) {
			new MutationObserver( scheduleSurfaceGeometry ).observe( dock, { childList: true } );
		}
		if ( overlayRoot && typeof MutationObserver === 'function' ) {
			new MutationObserver( scheduleSurfaceGeometry ).observe( overlayRoot, { childList: true, subtree: true } );
		}
		if ( typeof ResizeObserver === 'function' ) {
			const observer = new ResizeObserver( scheduleSurfaceGeometry );
			if ( dockContext ) observer.observe( dockContext );
			if ( overlayRoot ) observer.observe( overlayRoot );
		}
	}

	function currentColorMode() {
		const root = document.documentElement;
		const bricksMode = String( root.dataset.brxTheme || '' ).toLowerCase();
		if ( bricksMode === 'dark' || bricksMode === 'light' ) {
			return bricksMode;
		}

		try {
			const saved = String( window.localStorage.getItem( 'brx_mode' ) || window.localStorage.getItem( 'kiwe_color_mode' ) || '' ).toLowerCase();
			if ( saved === 'dark' || saved === 'light' ) return saved;
			if ( saved === 'auto' && window.matchMedia ) return window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light';
		} catch ( error ) {}

		return String( root.dataset.kiweTheme || '' ).toLowerCase() === 'dark' ? 'dark' : 'light';
	}

	function setThemeToggleState( button ) {
		button = button || ( surface ? surface.querySelector( '[data-dsa-module="theme"]' ) : null );
		if ( ! button ) return;
		const dark = document.documentElement.dataset.kiweTheme === 'dark';
		button.classList.toggle( 'is-dark', dark );
		button.setAttribute( 'aria-pressed', dark ? 'true' : 'false' );
		button.setAttribute( 'aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode' );
		button.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
	}

	function applyColorMode( mode, persist, source ) {
		mode = mode === 'dark' ? 'dark' : 'light';
		const root = document.documentElement;
		root.dataset.kiweTheme = mode;
		root.dataset.brxTheme = mode;
		root.style.colorScheme = mode;
		if ( persist ) {
			try {
				window.localStorage.setItem( 'brx_mode', mode );
				window.localStorage.setItem( 'kiwe_color_mode', mode );
			} catch ( error ) {}
		}
		document.querySelectorAll( '[data-dsa-theme-logo]' ).forEach( function ( image ) {
			const nextSource = mode === 'dark' ? image.dataset.darkSrc : image.dataset.lightSrc;
			if ( nextSource && image.getAttribute( 'src' ) !== nextSource ) image.setAttribute( 'src', nextSource );
		} );
		setThemeToggleState();
		window.dispatchEvent( new CustomEvent( 'surface:theme:change', { detail: { mode: mode, source: source || 'kiwe' } } ) );
	}

	function toggleColorMode() {
		const next = currentColorMode() === 'dark' ? 'light' : 'dark';
		applyColorMode( next, true, 'dock' );
		announce( next === 'dark' ? 'Dark mode on.' : 'Light mode on.' );
		recordMetric( 'theme_change', next );
	}

	function initializeColorMode() {
		applyColorMode( currentColorMode(), false, 'initial' );
		const root = document.documentElement;
		if ( typeof MutationObserver === 'function' ) {
			new MutationObserver( function ( mutations ) {
				if ( ! mutations.some( function ( mutation ) { return mutation.attributeName === 'data-brx-theme'; } ) ) return;
				const bricksMode = String( root.dataset.brxTheme || '' ).toLowerCase();
				if ( ( bricksMode === 'dark' || bricksMode === 'light' ) && root.dataset.kiweTheme !== bricksMode ) {
					applyColorMode( bricksMode, false, 'bricks' );
				}
			} ).observe( root, { attributes: true, attributeFilter: [ 'data-brx-theme' ] } );
		}
		window.addEventListener( 'storage', function ( event ) {
			if ( event.key === 'brx_mode' || event.key === 'kiwe_color_mode' ) applyColorMode( currentColorMode(), false, 'storage' );
		} );
	}

	function emptyNotificationPreferences() {
		return {
			topics: [],
			channels: [],
			productCategories: [],
			postCategories: [],
			productIds: [],
			context: '',
			standalone: false,
			browserPermission: 'default',
		};
	}

	function loadNotificationPreferenceDraft() {
		try {
			const parsed = JSON.parse( window.localStorage.getItem( 'dsa_notification_preferences_v1' ) || '{}' );
			return Object.assign( emptyNotificationPreferences(), parsed && typeof parsed === 'object' ? parsed : {} );
		} catch ( error ) {
			return emptyNotificationPreferences();
		}
	}

	function saveNotificationPreferenceDraft( preferences ) {
		notificationPreferences = Object.assign( emptyNotificationPreferences(), preferences || {} );
		try {
			window.localStorage.setItem( 'dsa_notification_preferences_v1', JSON.stringify( notificationPreferences ) );
		} catch ( error ) {}
	}

	function openNotificationPreferences( options ) {
		options = options || {};
		if ( ! notificationConfig.enabled ) {
			announce( 'Notification preferences are not enabled on this Appsite.' );
			return;
		}

		const next = Object.assign( emptyNotificationPreferences(), notificationPreferences );
		if ( options.required ) {
			notificationSetupGate = true;
		}
		if ( options.adminDefaults && ! next.topics.length ) {
			next.topics = ( Array.isArray( notificationConfig.topics ) ? notificationConfig.topics : [] ).filter( function ( topic ) {
				return topic && topic.audience === 'admin';
			} ).map( function ( topic ) { return topic.id; } );
			if ( next.channels.indexOf( 'app' ) === -1 ) next.channels.push( 'app' );
		}
		if ( options.topic && next.topics.indexOf( options.topic ) === -1 ) {
			next.topics = next.topics.concat( [ options.topic ] );
		}
		if ( options.productId && next.productIds.indexOf( Number( options.productId ) ) === -1 ) {
			next.productIds = next.productIds.concat( [ Number( options.productId ) ] );
		}
		if ( options.context ) {
			next.context = options.context;
			notificationJourneyContext = options.context;
		}
		saveNotificationPreferenceDraft( next );
		openOverlay( 'notifications', 'Personalize your Appsite' );
	}

	function renderNotificationPreferencePanel() {
		const adapter = presentationModules.get( 'notifications' );
		if ( ! adapter || typeof adapter.renderNotifications !== 'function' ) return renderLazyPresentation( 'notifications', 'Personalize your Appsite' );
		return adapter.renderNotifications( {
			config: notificationConfig,
			preferences: notificationPreferences,
			user: phonekey.user || {},
			setupGate: notificationSetupGate,
			trustBadges: protectedTrustBadges(),
			visualProfile: currentVisualProfile(),
		} );
	}

	function bindNotificationPreferencePanel() {
		const panel = overlayRoot ? overlayRoot.querySelector( '[data-dsa-notification-panel]' ) : null;
		if ( ! panel ) {
			return;
		}

		panel.querySelectorAll( '.dsa-notification-choice input' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				input.closest( '.dsa-notification-choice' ).classList.toggle( 'is-selected', input.checked );
				if ( input.name === 'channels' ) updateNotificationContactFields( panel );
			} );
		} );

		panel.querySelectorAll( '[data-dsa-notification-platform]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				panel.dataset.preferredPlatform = button.dataset.dsaNotificationPlatform || '';
				panel.querySelectorAll( '[data-dsa-notification-platform]' ).forEach( function ( item ) { item.classList.toggle( 'is-selected', item === button ); } );
				const appChoice = panel.querySelector( 'input[name="channels"][value="app"]' );
				if ( appChoice ) {
					appChoice.checked = true;
					appChoice.closest( '.dsa-notification-choice' ).classList.add( 'is-selected' );
				}
				const next = Object.assign( emptyNotificationPreferences(), notificationPreferences, {
					topics: checkedNotificationValues( panel, 'topics' ),
					channels: checkedNotificationValues( panel, 'channels' ),
					productCategories: checkedNotificationValues( panel, 'productCategories' ).map( Number ),
					postCategories: checkedNotificationValues( panel, 'postCategories' ).map( Number ),
					standalone: isStandaloneApp(),
				} );
				saveNotificationPreferenceDraft( next );
				if ( button.dataset.dsaNotificationPlatform === 'ios' && ! isStandaloneApp() ) {
					openIosNotificationGuide( { context: notificationJourneyContext || 'notification_preferences', platform: 'ios' } );
					return;
				}
				button.dataset.dsaNativeNotificationRequest = '1';
				requestBrowserNotifications( button );
			} );
		} );

		const form = panel.querySelector( '[data-dsa-notification-form]' );
		if ( form ) {
			form.addEventListener( 'submit', submitNotificationPreferences );
		}
	}

	function updateNotificationContactFields( panel ) {
		const selected = checkedNotificationValues( panel, 'channels' );
		const email = panel.querySelector( '[data-dsa-notification-email]' );
		const phone = panel.querySelector( '[data-dsa-notification-phone]' );
		if ( email ) email.hidden = false;
		if ( phone ) phone.hidden = selected.indexOf( 'sms' ) === -1 && selected.indexOf( 'whatsapp' ) === -1;
	}

	function submitNotificationPreferences( event ) {
		event.preventDefault();
		const form = event.currentTarget;
		const panel = form.closest( '[data-dsa-notification-panel]' );
		const message = form.querySelector( '[data-dsa-notification-message]' );
		const payload = {
			visitorId: permissionVisitorId(),
			topics: checkedNotificationValues( form, 'topics' ),
			channels: checkedNotificationValues( form, 'channels' ),
			productCategories: checkedNotificationValues( form, 'productCategories' ).map( Number ),
			postCategories: checkedNotificationValues( form, 'postCategories' ).map( Number ),
			productIds: notificationPreferences.productIds || [],
			context: notificationJourneyContext || notificationPreferences.context || 'visitor_choice',
			standalone: isStandaloneApp(),
			browserPermission: 'Notification' in window ? window.Notification.permission : 'unsupported',
		};

		if ( ! payload.topics.length || ! payload.channels.length ) {
			message.textContent = 'Choose at least one update and one way to receive it.';
			return;
		}

		const emailInput = form.querySelector( '[data-dsa-notification-email]' );
		const phoneInput = form.querySelector( '[data-dsa-notification-phone]' );
		if ( !( phonekey.user || {} ).loggedIn && payload.channels.indexOf( 'email' ) !== -1 && ( ! emailInput || ! emailInput.value.trim() ) ) {
			message.textContent = 'Enter the email that should receive these updates.';
			return;
		}
		if ( !( phonekey.user || {} ).loggedIn && ( payload.channels.indexOf( 'sms' ) !== -1 || payload.channels.indexOf( 'whatsapp' ) !== -1 ) && ( ! phoneInput || ! phoneInput.value.trim() ) ) {
			message.textContent = 'Enter the phone number that should receive these updates.';
			return;
		}

		const appSelected = payload.channels.indexOf( 'app' ) !== -1;
		const preferredPlatform = panel ? panel.dataset.preferredPlatform || platformContext() : platformContext();
		let permissionRequest = Promise.resolve( payload.browserPermission );
		if ( appSelected && ( ! isIosDevice() || isStandaloneApp() ) ) {
			const nativeTrigger = form.querySelector( 'button[type="submit"]' );
			if ( nativeTrigger ) {
				nativeTrigger.dataset.dsaNativeNotificationRequest = '1';
				nativeTrigger.setAttribute( 'data-kiwe-notification-status-target', '[data-dsa-notification-message]' );
			}
			permissionRequest = requestBrowserNotifications( nativeTrigger );
		}

		message.textContent = 'Saving...';
		permissionRequest.then( function () {
			payload.browserPermission = 'Notification' in window ? window.Notification.permission : 'unsupported';
			saveNotificationPreferenceDraft( payload );
			recordMetric( 'notification_preferences_saved', payload.context, payload.topics.length );
			return dsaPost( '/notification-preferences', payload );
		} ).then( function ( response ) {
			message.textContent = 'Saved.';
			if ( ! appSelected ) removePushSubscription().catch( function () {} );
			const email = form.querySelector( '[data-dsa-notification-email]' );
			const phone = form.querySelector( '[data-dsa-notification-phone]' );
			const needsPhone = payload.channels.indexOf( 'sms' ) !== -1 || payload.channels.indexOf( 'whatsapp' ) !== -1;
			notificationIdentityIntent = needsPhone && phone && phone.value.trim() ? phone.value.trim() : ( email ? email.value.trim() : '' );

			if ( response.needsIdentity ) {
				try { window.localStorage.setItem( 'dsa_notification_identity_resume', '1' ); } catch ( error ) {}
				window.setTimeout( function () { openOverlay( 'profile', 'Keep your notifications' ); }, 220 );
				return;
			}

			if ( appSelected && isIosDevice() && ! isStandaloneApp() ) {
				window.setTimeout( function () { openIosNotificationGuide( { context: 'notification_preferences', platform: preferredPlatform } ); }, 220 );
				return;
			}

			notificationSetupGate = false;
			removeAiNotification( 'app-notification-setup' );
			window.setTimeout( function () { closeOverlay( true ); }, 420 );
		} ).catch( function ( error ) {
			message.textContent = error.message || 'Your choices could not be saved.';
		} );
	}

	function checkedNotificationValues( form, name ) {
		return Array.prototype.slice.call( form.querySelectorAll( 'input[name="' + name + '"]:checked' ) ).map( function ( input ) { return input.value; } );
	}

	function openIosNotificationGuide( options ) {
		options = options || {};
		notificationJourneyContext = options.context || notificationJourneyContext || 'ios_install';
		if ( notificationJourneyContext === 'home_install' ) {
			const next = Object.assign( emptyNotificationPreferences(), notificationPreferences );
			if ( next.channels.indexOf( 'app' ) === -1 ) next.channels = next.channels.concat( [ 'app' ] );
			if ( ! next.topics.length ) {
				next.topics = ( Array.isArray( notificationConfig.topics ) ? notificationConfig.topics : [] ).slice( 0, notificationConfig.commerce ? 4 : 2 ).map( function ( topic ) { return topic.id; } );
			}
			next.context = 'home_install';
			saveNotificationPreferenceDraft( next );
			dsaPost( '/notification-preferences', Object.assign( { visitorId: permissionVisitorId() }, next ) ).catch( function () {} );
		}
		openOverlay( 'ios-install', 'iPhone and iPad App' );
	}

	function renderIosInstallPanel() {
		const adapter = presentationModules.get( 'ios-install' ) || presentationModules.get( 'notifications' );
		const siteName = data.site && ( data.site.title || data.site.name ) ? data.site.title || data.site.name : 'this Appsite';
		if ( ! adapter || typeof adapter.renderIosInstall !== 'function' ) return renderLazyPresentation( 'ios-install', 'iPhone and iPad App' );
		return adapter.renderIosInstall( {
			siteName: siteName,
			trustBadges: protectedTrustBadges(),
			visualProfile: currentVisualProfile(),
		} );
	}

	function bindIosInstallPanel() {
		const button = overlayRoot ? overlayRoot.querySelector( '[data-dsa-ios-install-done]' ) : null;
		if ( ! button ) return;
		button.addEventListener( 'click', function () {
			try { window.localStorage.setItem( 'dsa_ios_notification_resume', '1' ); } catch ( error ) {}
			const notice = recordAiNotification( {
				id: 'ios-notifications-open-app',
				type: 'permission',
				kicker: 'Almost there',
				title: 'Good job. Now open your Home Screen app.',
				message: 'Your choices are saved. Open the Appsite from its new icon to finish notification permission.',
				notification: true,
				dismissible: false,
			} );
			closeOverlay();
			if ( notice ) queueAiPopout( notice, 'permission' );
		} );
	}

	function resumeIosNotificationJourney() {
		if ( ! isIosDevice() || ! isStandaloneApp() ) return false;
		let pending = false;
		try { pending = window.localStorage.getItem( 'dsa_ios_notification_resume' ) === '1'; } catch ( error ) {}
		if ( ! pending || notificationPreferences.channels.indexOf( 'app' ) === -1 ) return false;
		removeAiNotification( 'ios-notifications-open-app' );
		const user = phonekey.user || {};
		const name = user.firstName || user.displayName || '';
		const notification = recordAiNotification( {
			id: 'ios-notifications-permission',
			type: 'permission',
			kicker: 'Welcome to the app',
			title: name ? 'Welcome, ' + name + '. Let us finish notifications.' : 'Welcome. Let us finish notifications.',
			message: 'Tap OK and iOS will show its notification permission choice.',
			action: 'ios_notification_permission',
			actionLabel: 'OK',
			requiredAction: true,
			dismissible: false,
		} );
		if ( notification ) queueAiPopout( notification, 'permission' );
		return true;
	}

	function initializeNotificationPreferences() {
		if ( ! notificationConfig.enabled ) {
			if ( isStandaloneApp() ) window.setTimeout( initializeStandaloneAppJourney, 120 );
			return Promise.resolve();
		}
		enhanceCurrentUnavailableProduct();
		const preferencesRequest = dsaGet( '/notification-preferences?visitorId=' + encodeURIComponent( permissionVisitorId() ) + '&standalone=' + ( isStandaloneApp() ? '1' : '0' ) ).then( function ( response ) {
			if ( response && response.preferences ) {
				const local = notificationPreferences;
				const server = response.preferences;
				const hasServerChoice = Array.isArray( server.topics ) && server.topics.length;
				saveNotificationPreferenceDraft( hasServerChoice ? server : local );
			}
		} ).catch( function () {} );

		document.addEventListener( 'click', function ( event ) {
			const target = event.target && event.target.closest ? event.target.closest( '[data-dsa-notify-product]' ) : null;
			if ( ! target ) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			openNotificationPreferences( {
				topic: target.dataset.dsaNotificationTopic || 'stock_update',
				productId: Number( target.dataset.dsaNotifyProduct || 0 ),
				context: 'product_notify',
			} );
		}, true );

		if ( ( phonekey.user || {} ).loggedIn ) {
			let resumeIdentity = false;
			try {
				resumeIdentity = window.localStorage.getItem( 'dsa_notification_identity_resume' ) === '1';
				if ( resumeIdentity ) window.localStorage.removeItem( 'dsa_notification_identity_resume' );
			} catch ( error ) {}
			if ( resumeIdentity ) window.setTimeout( function () { openNotificationPreferences( { context: notificationPreferences.context || 'identity_resume' } ); }, 700 );
		}

		return preferencesRequest.finally( function () {
			if ( isStandaloneApp() ) window.setTimeout( initializeStandaloneAppJourney, 120 );
		} );
	}

	function enhanceCurrentUnavailableProduct() {
		const current = notificationConfig.currentProduct || {};
		if ( ! current.productId ) return;
		const roots = document.querySelectorAll( '.brxe-product-add-to-cart, .summary.entry-summary, form.cart' );
		roots.forEach( function ( root ) {
			let button = root.querySelector( '[data-dsa-notify-product], .single_add_to_cart_button, a.button' );
			if ( ! button && root.matches( '.brxe-product-add-to-cart' ) ) {
				button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'button';
				root.appendChild( button );
			}
			if ( ! button ) return;
			button.textContent = notificationConfig.ctaLabel || 'Notify me';
			button.classList.add( 'dsa-notify-me-button', 'dsa-notify-me-button--' + ( notificationConfig.ctaColor || 'active' ) );
			button.dataset.dsaNotifyProduct = String( current.productId );
			button.dataset.dsaNotificationTopic = current.topic || 'stock_update';
			button.dataset.dsaNotificationReason = current.reason || '';
			if ( button.tagName === 'A' ) button.setAttribute( 'href', '#' );
		} );
	}

	function appsiteHomePayload() { return { site: data.site || {}, config: appConfig || {}, documentTitle: document.title || '', trustBadges: protectedTrustBadges() }; }

	function renderHomeTrustBadges() {
		const badges = protectedTrustBadges();
		if ( ! badges.length ) return '';
		return '<div class="dsa-home-trust" aria-label="Site trust">' + badges.map( function ( badge ) { return '<span class="dsa-home-trust__badge' + ( badge.active ? ' is-active' : '' ) + '"><i aria-hidden="true"></i>' + escapeHtml( badge.label ) + '</span>'; } ).join( '' ) + '</div>';
	}

	function showAppsiteHomeScreen( options ) {
		options = options || {};
		if ( document.querySelector( '[data-dsa-appsite-home-active]' ) ) {
			return;
		}

		if ( options.markSeen ) {
			markAppsiteHomeSeen();
		}

		loadPresentationModule( 'appsite-home' ).then( function ( adapter ) {
			if ( document.querySelector( '[data-dsa-appsite-home-active], [data-dsa-initial-preloader]' ) || ! adapter || typeof adapter.renderAppsiteHome !== 'function' ) {
				return;
			}
			const holder = document.createElement( 'div' );
			holder.innerHTML = adapter.renderAppsiteHome( appsiteHomePayload() );
			const screen = holder.firstElementChild;
			if ( ! screen ) return;
			document.body.appendChild( screen );
			bindAppsiteHomeScreen( screen );
		} ).catch( function ( error ) {
			debugLog( 'appsite home module failed', { message: error && error.message ? error.message : String( error || '' ) } );
		} );
	}

	function startAppsiteIdleEvent() {
		const idle = appConfig.idle || {};
		const rule = getSurfaceTrigger( 'idle_home' );
		if ( ! surfaceTriggerEnabled( 'idle_home', idle.enabled ) || isProtectedFlowActive() ) {
			debugLog( 'idle home disabled', {
				idleEnabled: Boolean( idle.enabled ),
				ruleEnabled: Boolean( rule && rule.enabled ),
				protectedFlow: isProtectedFlowActive(),
			} );
			return;
		}

		const delay = Math.max( 10000, Number( rule && rule.delayMs ) || Number( idle.delayMs ) || 60000 );
		debugLog( 'idle home armed', { delay: delay, rule: rule || null, idle: idle } );
		let timer = 0;
		const reset = function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				if ( hasActiveSurfaceMode() || hasInteractiveFocus() ) {
					debugLog( 'idle home postponed', {
						activeSurfaceMode: hasActiveSurfaceMode(),
						interactiveFocus: hasInteractiveFocus(),
					} );
					reset();
					return;
				}
				debugLog( 'idle home showing', { delay: delay } );
				showAppsiteHomeScreen();
			}, delay );
		};

		[ 'mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'wheel' ].forEach( function ( eventName ) {
			document.addEventListener( eventName, reset, { passive: true } );
		} );
		reset();
	}

	function handlePwaInstall( button, preferredPlatform ) {
		const prompt = deferredInstallPrompt || window.DSA.deferredInstallPrompt;
		const platform = preferredPlatform || platformContext();
		recordMetric( 'pwa_install_click', prompt ? platform + '_prompt' : platform + '_help' );
		recordMetric( 'pwa_install_intent', platform );
		const homeDismissal = dismissAppsiteHomeForInstall( button );

		if ( isStandaloneApp() ) {
			homeDismissal.then( function () {
				showPwaInstalledNotification( false );
				announce( 'This appsite is already installed on this device.' );
			} );
			return;
		}

		if ( ! pwaConfig.enabled ) {
			homeDismissal.then( function () {
				showPwaInstallGuidanceNotification( 'App installation is not enabled for this site yet.', {
					blocker: 'site_disabled',
					steps: [ 'The site owner needs to enable Kiwe App installation.' ],
				}, platform );
			} );
			return;
		}

		if ( permissionJourneyEnabled( 'pwa_install' ) ) {
			if ( isProtectedFlowActive() ) {
				setPwaStatus( button, 'Kiwe will offer installation after this protected flow.' );
				return;
			}

			permissionAskSessionCount += 1;
			savePermissionSessionAskCount( permissionAskSessionCount );
			recordPermissionOutcome( 'shown', 'pwa_install' );
			dsaPost( '/permissions/decision', {
				type: 'pwa_install',
				visitorId: permissionVisitorId(),
				available: Boolean( prompt && typeof prompt.prompt === 'function' ),
				protectedFlow: isProtectedFlowActive(),
				explicit: true,
				events: permissionJourneyEvents(),
			} ).catch( function () {} );
		}

		if ( platform === 'android' || ( ! isIosDevice() && /android/i.test( window.navigator.userAgent || '' ) ) ) {
			const promptReady = prompt ? Promise.resolve( prompt ) : waitForPwaInstallPrompt( 1200 );
			Promise.all( [ homeDismissal, promptReady ] ).then( function ( results ) {
				const availablePrompt = results[1];
				if ( availablePrompt && typeof availablePrompt.prompt === 'function' ) {
					showAndroidPwaPrimer( button, availablePrompt, platform );
					return;
				}

				inspectPwaInstallReadiness().then( function ( readiness ) {
					const guidance = pwaInstallGuidance( readiness, platform );
					showPwaInstallGuidanceNotification( guidance.message, guidance, platform );
				} );
			} );
			return;
		}

		homeDismissal.then( function () {
			if ( platform === 'ios' || isIosDevice() ) {
				openIosNotificationGuide( { context: 'home_install' } );
				return;
			}
			performPwaInstall( button, prompt, platform );
		} );
	}

	function dismissAppsiteHomeForInstall( button ) {
		const screen = button && button.closest ? button.closest( '[data-dsa-initial-preloader]' ) : null;
		if ( ! screen ) {
			return Promise.resolve();
		}

		screen.dispatchEvent( new CustomEvent( 'dsa:appsite-home:dismiss', {
			detail: { reason: 'install_intent' },
		} ) );
		return wait( 210 );
	}

	function waitForPwaInstallPrompt( timeout ) {
		const available = deferredInstallPrompt || window.DSA.deferredInstallPrompt;
		if ( available ) {
			return Promise.resolve( available );
		}

		return new Promise( function ( resolve ) {
			let settled = false;
			const finish = function ( prompt ) {
				if ( settled ) {
					return;
				}
				settled = true;
				window.clearTimeout( timer );
				if ( pwaInstallPromptWaiter === finish ) {
					pwaInstallPromptWaiter = null;
				}
				resolve( prompt || null );
			};
			const timer = window.setTimeout( function () { finish( null ); }, Math.max( 250, Number( timeout ) || 1200 ) );
			pwaInstallPromptWaiter = finish;
		} );
	}

	function showAndroidPwaPrimer( button, prompt, platform ) {
		if ( ! prompt || typeof prompt.prompt !== 'function' ) {
			inspectPwaInstallReadiness().then( function ( readiness ) {
				const guidance = pwaInstallGuidance( readiness, platform || 'android' );
				showPwaInstallGuidanceNotification( guidance.message, guidance, platform || 'android' );
			} );
			return;
		}

		if ( ! aiPopout || ! surface.querySelector( '[data-dsa-module="ai"]' ) ) {
			performPwaInstall( button, prompt, platform );
			return;
		}

		pendingPwaInstall = { button: button, prompt: prompt, platform: platform || 'android' };
		removeAiNotification( 'pwa-install-primer:android' );
		removeAiNotification( 'pwa-install-retry:android' );
		removeAiNotification( 'pwa-install-guidance:android' );
		const siteName = data.site && ( data.site.title || data.site.name ) ? ( data.site.title || data.site.name ) : 'this appsite';
		const primer = recordAiNotification( {
			id: 'pwa-install-primer:android',
			type: 'pwa_install',
			kicker: 'Home Screen app',
			title: 'Add ' + siteName + ' to your phone.',
			message: 'After you tap OK, the browser will show its install prompt.',
			benefits: [ 'Works offline', 'Opens faster', 'One tap from your Home screen' ],
			action: 'pwa_install_confirm',
			actionLabel: 'OK',
			requiredAction: true,
		} );

		if ( primer ) {
			hideAiPopout( false, true );
			queueAiPopout( primer, 'pwa_install' );
			updateDockBadge( 'ai', activeAiInsights().length + unreadAiNotificationCount() );
			publishAiNotificationState();
		}
	}

	function confirmPwaInstallFromAi( insight ) {
		const context = pendingPwaInstall || {
			button: document.querySelector( '[data-dsa-install-pwa][data-dsa-pwa-platform="android"]' ),
			prompt: deferredInstallPrompt || window.DSA.deferredInstallPrompt,
			platform: insight.platform || 'android',
		};
		recordMetric( 'pwa_primer_ok', context.platform || 'android' );
		removeAiNotification( insight.id );
		hideAiPopout( false, true );
		pendingPwaInstall = null;
		performPwaInstall( context.button, deferredInstallPrompt || window.DSA.deferredInstallPrompt || context.prompt, context.platform || 'android' );
	}

	function performPwaInstall( button, prompt, platform ) {
		if ( prompt && typeof prompt.prompt === 'function' ) {
			try {
				prompt.prompt();
			} catch ( error ) {
				inspectPwaInstallReadiness().then( function ( readiness ) {
					const guidance = pwaInstallGuidance( readiness, platform );
					showPwaInstallGuidanceNotification( 'The browser could not open this install prompt. ' + guidance.message, guidance, platform );
				} );
				setPwaStatus( button, 'The browser could not open its install prompt. Kiwe Assistant has the available next step.' );
				return;
			}
			if ( prompt.userChoice && typeof prompt.userChoice.then === 'function' ) {
				prompt.userChoice.then( function ( choice ) {
					const outcome = choice && choice.outcome ? choice.outcome : 'unknown';
					recordMetric( 'pwa_install_choice', outcome );
					recordPermissionOutcome( outcome === 'accepted' ? 'accepted' : 'dismissed', 'pwa_install' );
					if ( outcome === 'accepted' ) {
						recordMetric( 'pwa_prompt_accepted', platform || platformContext() );
						removeAiNotification( 'pwa-install-retry:android' );
						removeAiNotification( 'pwa-install-guidance:android' );
					} else {
						recordMetric( 'pwa_install_dismissed', platform || platformContext() );
						showPwaInstallGuidanceNotification(
							'The browser install prompt was closed. You can use the browser menu and choose Install app or Add to Home screen when you are ready.',
							{ blocker: 'prompt_dismissed', steps: [ 'Open the browser menu.', 'Choose Install app or Add to Home screen.' ] },
							platform
						);
					}
					setPwaStatus( button, outcome === 'accepted' ? 'Installation accepted. Your browser will finish adding the app.' : 'Installation paused. Your install option is saved in AI Assistant.' );
				} ).finally( function () {
					deferredInstallPrompt = null;
					window.DSA.deferredInstallPrompt = null;
					publishAppAdoptionState();
				} );
			}
			return;
		}

		recordPermissionOutcome( 'fallback', 'pwa_install' );
		const help = platform === 'ios' || isIosDevice()
			? ( pwaConfig.iosHelp || 'Tap Share, then Add to Home Screen, then Add.' )
			: ( pwaConfig.androidHelp || 'Open the browser menu and choose Install app or Add to Home screen.' );
		setPwaStatus( button, help );
		inspectPwaInstallReadiness().then( function ( readiness ) {
			const guidance = pwaInstallGuidance( readiness, platform );
			showPwaInstallGuidanceNotification( platform === 'ios' || isIosDevice() ? help : guidance.message, guidance, platform );
		} );
	}

	function showPwaInstallGuidanceNotification( reason, guidance, platform ) {
		removeAiNotification( 'pwa-install-retry:android' );
		removeAiNotification( 'pwa-install-guidance:android' );
		removeAiNotification( 'pwa-install-primer:android' );
		const siteName = data.site && ( data.site.title || data.site.name ) ? ( data.site.title || data.site.name ) : 'this appsite';
		const isIos = platform === 'ios' || isIosDevice();
		const steps = guidance && Array.isArray( guidance.steps ) && guidance.steps.length
			? guidance.steps
			: ( isIos ? [ 'Open Safari Share.', 'Choose Add to Home Screen.', 'Tap Add.' ] : [ 'Open the browser menu.', 'Choose Install app or Add to Home screen.' ] );
		const notification = recordAiNotification( {
			id: 'pwa-install-guidance:android',
			type: 'pwa_install',
			kicker: guidance && guidance.blocker === 'site_configuration' ? 'App setup needed' : 'Install help',
			title: isIos ? 'Add ' + siteName + ' from Safari.' : 'Add ' + siteName + ' from your browser.',
			message: reason || 'The browser did not make its native install prompt available.',
			benefits: steps,
			action: 'acknowledge_notification',
			actionLabel: 'OK',
			requiredAction: false,
		} );
		if ( notification ) {
			hideAiPopout( false, true );
			queueAiPopout( notification, 'pwa_install_guidance' );
			updateDockBadge( 'ai', activeAiInsights().length + unreadAiNotificationCount() );
			publishAiNotificationState();
			rerenderAiInsightInbox();
		}
	}

	function inspectPwaInstallReadiness() {
		const manifestLink = document.querySelector( 'link[rel~="manifest"]' );
		const readiness = {
			version: 1,
			platform: platformContext(),
			browser: pwaBrowserFamily(),
			enabled: Boolean( pwaConfig.enabled ),
			standalone: isStandaloneApp(),
			secureContext: Boolean( window.isSecureContext ),
			manifestPresent: Boolean( manifestLink || pwaConfig.manifestUrl ),
			manifestReadable: false,
			manifestValid: false,
			manifestIssues: [],
			siteIconReady: pwaConfig.siteIconReady !== false,
			serviceWorkerSupported: pwaConfig.serviceWorkerEnabled !== false && 'serviceWorker' in window.navigator,
			serviceWorkerReady: Boolean( pwaConfig.serviceWorkerEnabled !== false && window.DSA.serviceWorkerRegistration ),
			promptAvailable: Boolean( deferredInstallPrompt || window.DSA.deferredInstallPrompt ),
		};
		const manifestUrl = pwaConfig.manifestUrl || ( manifestLink ? manifestLink.href : '' );
		const registrationCheck = readiness.serviceWorkerSupported && typeof window.navigator.serviceWorker.getRegistration === 'function'
			? window.navigator.serviceWorker.getRegistration( pwaConfig.scope || '/' ).then( function ( registration ) {
				readiness.serviceWorkerReady = Boolean( pwaConfig.serviceWorkerEnabled !== false && ( registration || window.DSA.serviceWorkerRegistration ) );
			} ).catch( function () {} )
			: Promise.resolve();
		const manifestCheck = manifestUrl
			? window.fetch( manifestUrl, { credentials: 'same-origin', cache: 'no-store' } ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Manifest returned HTTP ' + response.status );
				}
				return response.json();
			} ).then( function ( manifest ) {
				readiness.manifestReadable = true;
				if ( ! manifest || ! ( manifest.name || manifest.short_name ) ) {
					readiness.manifestIssues.push( 'name' );
				}
				if ( ! manifest || ! manifest.start_url ) {
					readiness.manifestIssues.push( 'start_url' );
				}
				if ( ! manifest || [ 'standalone', 'minimal-ui', 'fullscreen' ].indexOf( manifest.display ) === -1 ) {
					readiness.manifestIssues.push( 'display' );
				}
				const iconSizes = manifest && Array.isArray( manifest.icons ) ? manifest.icons.reduce( function ( sizes, icon ) {
					String( icon && icon.sizes ? icon.sizes : '' ).split( /\s+/ ).forEach( function ( size ) { sizes.push( size ); } );
					return sizes;
				}, [] ) : [];
				if ( iconSizes.indexOf( '192x192' ) === -1 ) {
					readiness.manifestIssues.push( 'icon_192' );
				}
				if ( iconSizes.indexOf( '512x512' ) === -1 ) {
					readiness.manifestIssues.push( 'icon_512' );
				}
				readiness.manifestValid = readiness.manifestIssues.length === 0;
			} ).catch( function ( error ) {
				readiness.manifestError = error && error.message ? error.message : String( error || 'Manifest could not be read.' );
			} )
			: Promise.resolve();

		return Promise.all( [ registrationCheck, manifestCheck ] ).then( function () {
			readiness.promptAvailable = Boolean( deferredInstallPrompt || window.DSA.deferredInstallPrompt );
			debugLog( 'PWA install readiness', readiness );
			return readiness;
		} );
	}

	function pwaInstallGuidance( readiness, platform ) {
		readiness = readiness || {};
		if ( ! readiness.enabled ) {
			return { blocker: 'site_configuration', message: 'Installation is not enabled on this appsite yet.', steps: [ 'The site owner needs to enable Kiwe App installation.' ] };
		}
		if ( readiness.standalone ) {
			return { blocker: 'already_installed', message: 'This appsite is already installed on this device.', steps: [ 'Open it from your Home screen.' ] };
		}
		if ( ! readiness.secureContext ) {
			return { blocker: 'site_configuration', message: 'Installation needs a secure HTTPS connection, and this page is not secure.', steps: [ 'Open the HTTPS version of this site.', 'Try the install button again.' ] };
		}
		if ( platform === 'ios' || readiness.platform === 'ios' ) {
			return { blocker: 'manual_ios', message: pwaConfig.iosHelp || 'Tap Share, then Add to Home Screen, then Add.', steps: [ 'Open Safari Share.', 'Choose Add to Home Screen.', 'Tap Add.' ] };
		}
		if ( ! readiness.manifestPresent || readiness.manifestError ) {
			return { blocker: 'site_configuration', message: 'This appsite could not provide its installation manifest. The site owner needs to finish the app setup.', steps: [ 'Continue using the website for now.', 'Try again after the site app setup is repaired.' ] };
		}
		if ( readiness.siteIconReady === false ) {
			return { blocker: 'site_configuration', message: 'This appsite does not have a reliable square Home Screen icon yet. The site owner needs to set a 512px WordPress Site Icon.', steps: [ 'Continue using the website for now.', 'Try again after the Site Icon is configured.' ] };
		}
		if ( readiness.manifestReadable && ! readiness.manifestValid ) {
			return { blocker: 'site_configuration', message: 'This appsite is missing required install details or a valid Home Screen icon. The site owner needs to finish the app setup.', steps: [ 'Continue using the website for now.', 'Try again after the site icon is configured.' ] };
		}
		if ( ! readiness.serviceWorkerSupported ) {
			return { blocker: 'browser_support', message: 'This browser does not support the offline app service Kiwe needs.', steps: [ 'Open this site in an up-to-date Android browser.', 'Use its menu and choose Install app or Add to Home screen.' ] };
		}
		if ( [ 'firefox', 'other' ].indexOf( readiness.browser ) !== -1 ) {
			return { blocker: 'browser_support', message: 'This browser does not expose an in-page install prompt to Kiwe.', steps: [ 'Open the browser menu.', 'Choose Install app or Add to Home screen.' ] };
		}
		return {
			blocker: 'browser_prompt_unavailable',
			message: 'Your browser has not offered its native install prompt. It may already be installed, have been dismissed recently, be in private browsing, or be restricted by browser settings. Websites cannot read which private browser rule suppressed it.',
			steps: [ 'Open the browser menu.', 'Choose Install app or Add to Home screen.', 'If that option is missing, leave private browsing and check that the app is not already installed.' ],
		};
	}

	function pwaBrowserFamily() {
		const userAgent = window.navigator.userAgent || '';
		if ( isIosDevice() ) return 'ios-safari';
		if ( /SamsungBrowser/i.test( userAgent ) ) return 'samsung';
		if ( /EdgA|EdgiOS|Edg\//i.test( userAgent ) ) return 'edge';
		if ( /Chrome|CriOS/i.test( userAgent ) ) return 'chrome';
		if ( /Firefox|FxiOS/i.test( userAgent ) ) return 'firefox';
		return 'other';
	}

	function showPwaInstalledNotification( force ) {
		if ( ! surface || ! aiPopout ) {
			return;
		}
		try {
			if ( ! force && window.localStorage && window.localStorage.getItem( 'dsa_pwa_install_confirmation_seen' ) === '1' ) {
				return;
			}
			if ( window.localStorage ) {
				window.localStorage.setItem( 'dsa_pwa_install_confirmation_seen', '1' );
			}
		} catch ( error ) {}
		removeAiNotification( 'pwa-install-primer:android' );
		removeAiNotification( 'pwa-install-retry:android' );
		removeAiNotification( 'pwa-install-guidance:android' );
		const siteName = data.site && ( data.site.title || data.site.name ) ? ( data.site.title || data.site.name ) : 'this appsite';
		const installed = recordAiNotification( {
			id: 'pwa-install-complete',
			type: 'pwa_install_success',
			kicker: 'You are in',
			title: 'Find ' + siteName + ' on your Home screen.',
			message: 'Want site notifications too? That is a separate choice you stay in control of.',
			benefits: [ 'Sale and price changes', 'Stock updates', 'New products and stories' ],
			action: 'acknowledge_notification',
			actionLabel: 'OK',
			requiredAction: true,
		} );
		if ( installed ) {
			hideAiPopout( false, true );
			queueAiPopout( installed, 'pwa_installed' );
			updateDockBadge( 'ai', activeAiInsights().length + unreadAiNotificationCount() );
			publishAiNotificationState();
		}
	}

	function permissionJourneyEnabled( type ) {
		return Boolean(
			permissionsConfig.enabled
			&& permissionsConfig.permissions
			&& permissionsConfig.permissions[type]
			&& permissionsConfig.permissions[type].enabled
		);
	}

	function recordPermissionOutcome( outcome, type ) {
		if ( ! permissionsConfig.enabled || ! data.restUrl || ! outcome ) {
			return;
		}

		dsaPost( '/permissions/outcome', {
			type: type || 'pwa_install',
			outcome: outcome,
			visitorId: permissionVisitorId(),
		} ).catch( function () {} );
	}

	function setPwaStatus( button, message ) {
		const screen = button && button.closest ? button.closest( '[data-dsa-initial-preloader]' ) : null;
		const status = screen ? screen.querySelector( '[data-dsa-pwa-status]' ) : null;
		if ( status ) {
			status.textContent = message || '';
			status.hidden = ! message;
		}
		announce( message || '' );
	}

	function isStandaloneApp() {
		return Boolean(
			( window.matchMedia && window.matchMedia( '(display-mode: standalone)' ).matches )
			|| window.navigator.standalone === true
		);
	}

	function isIosDevice() {
		return /iphone|ipad|ipod/i.test( window.navigator.userAgent || '' )
			|| ( window.navigator.platform === 'MacIntel' && Number( window.navigator.maxTouchPoints || 0 ) > 1 );
	}

	function platformContext() {
		if ( isIosDevice() ) {
			return 'ios';
		}
		if ( /android/i.test( window.navigator.userAgent || '' ) ) {
			return 'android';
		}
		return 'desktop';
	}

	function registerKiweServiceWorker() {
		if ( ! pwaConfig.enabled || pwaConfig.serviceWorkerEnabled === false || ! pwaConfig.serviceWorkerUrl || ! ( 'serviceWorker' in window.navigator ) || ! window.isSecureContext ) {
			return Promise.resolve( null );
		}

		if ( window.DSA.serviceWorkerRegistration ) {
			return Promise.resolve( window.DSA.serviceWorkerRegistration );
		}

		return window.navigator.serviceWorker.register( pwaConfig.serviceWorkerUrl, {
			scope: pwaConfig.scope || '/',
		} ).then( function ( registration ) {
			window.DSA.serviceWorkerRegistration = registration;
			publishAppAdoptionState();
			return registration;
		} ).catch( function ( error ) {
			debugLog( 'PWA service worker registration failed', { message: error && error.message ? error.message : String( error || '' ) } );
			return null;
		} );
	}

	function initializePwaRuntime() {
		if ( ! pwaConfig.enabled ) {
			retireKiweServiceWorker();
			return;
		}

		if ( pwaConfig.serviceWorkerEnabled === false ) {
			retireKiweServiceWorker();
		} else {
			registerKiweServiceWorker();
		}
		if ( isStandaloneApp() ) {
			let recorded = false;
			try {
				recorded = window.sessionStorage && window.sessionStorage.getItem( 'dsa_pwa_standalone_recorded' ) === '1';
				if ( window.sessionStorage ) {
					window.sessionStorage.setItem( 'dsa_pwa_standalone_recorded', '1' );
				}
			} catch ( error ) {}
			if ( ! recorded ) {
				recordMetric( 'pwa_standalone_launch', platformContext() );
			}
		}

		if ( 'Notification' in window && window.Notification.permission === 'granted' ) {
			recordMetric( 'notification_granted', platformContext() );
			if ( notificationPreferences.channels.indexOf( 'app' ) !== -1 ) {
				ensurePushSubscription().catch( function ( error ) { debugLog( 'Existing push subscription refresh failed', { message: error.message || String( error ) } ); } );
			}
		}
		if ( 'serviceWorker' in window.navigator ) {
			window.navigator.serviceWorker.addEventListener( 'message', function ( event ) {
				if ( event.data && event.data.type === 'KIWE_PUSH_RECEIVED' ) handleLivePushNotification( event.data.payload || {} );
			} );
		}
		publishAppAdoptionState();
	}

	function handleLivePushNotification( payload ) {
		payload = payload && typeof payload === 'object' ? payload : {};
		surfaceFeedback( 'notification' );
		const tag = String( payload.eventId || payload.tag || ( payload.eventType || 'push' ) + '-' + Date.now() );
		const notice = recordAiNotification( {
			id: 'push-' + tag,
			type: payload.eventType || 'push',
			kicker: payload.kicker || 'New notification',
			title: payload.aiTitle || payload.title || 'New update',
			message: payload.aiMessage || payload.body || '',
			action: payload.url ? 'open_url' : '',
			actionLabel: payload.eventType === 'admin_new_order' || payload.eventType === 'admin_new_comment' ? 'Review' : 'Open',
			actionUrl: payload.url || '',
			notification: true,
			dismissible: true,
		} );
		if ( ! notice ) return;
		syncAiInsights( 'push', false );
		rerenderAiInsightInbox();
		queueAiPopout( notice, 'push' );
	}

	function initializeAdminNotificationInbox() {
		const user = phonekey.user || {};
		if ( ! user.isAdmin && ! user.canManageOrders && ! user.canModerate ) return;
		const pull = function () {
			dsaGet( '/admin-notifications' ).then( function ( response ) {
			const items = response && Array.isArray( response.notifications ) ? response.notifications : [];
			let first = null;
			items.forEach( function ( item ) {
				const notice = recordAiNotification( {
					id: 'push-' + String( item.id || Date.now() ),
					type: item.type || 'admin_notification',
					kicker: item.kicker || 'Admin update',
					title: item.title || 'New update',
					message: item.message || '',
					action: item.actionUrl ? 'open_url' : '',
					actionLabel: item.actionLabel || ( item.type === 'admin_visitor_summary' || item.type === 'admin_live_visitor' ? 'View' : 'Review' ),
					actionUrl: item.actionUrl || '',
					createdAt: Number( item.createdAt || Date.now() ) * ( Number( item.createdAt || 0 ) < 100000000000 ? 1000 : 1 ),
					notification: true,
					dismissible: true,
				} );
				if ( notice && ! first ) first = notice;
			} );
			if ( ! first ) return;
			syncAiInsights( 'admin', false );
			rerenderAiInsightInbox();
			queueAiPopout( first, 'admin' );
			} ).catch( function () {} );
		};

		pull();
		window.setInterval( function () {
			if ( document.visibilityState && document.visibilityState !== 'visible' ) return;
			pull();
		}, 30000 );
	}

	function bindCommerceFeedback() {
		const feedbackTarget = function ( event ) {
			const target = event.target && event.target.closest ? event.target.closest( 'button, input[type="button"], input[type="submit"], [role="button"], .bricks-button, .brxe-button, .add_to_cart_button, .single_add_to_cart_button, [data-dsa-bricks-cart-add], .quantity .plus, .quantity .minus, [data-dsa-bricks-cart-quantity], [data-dsa-store-qty]' ) : null;
			if ( ! target || target.disabled || target.getAttribute( 'aria-disabled' ) === 'true' ) return;
			const quantity = target.matches( '.quantity .plus, .quantity .minus, [data-dsa-cart-quantity], [data-dsa-bricks-cart-quantity], [data-dsa-store-qty]' );
			const cart = target.matches( '.add_to_cart_button, .single_add_to_cart_button, [data-dsa-bricks-cart-add]' );
			const now = Date.now();
			const previous = Number( target.dataset.dsaFeedbackAt || 0 );
			if ( event.type === 'click' && now - previous < 500 ) return;
			target.dataset.dsaFeedbackAt = String( now );
			surfaceFeedback( quantity ? 'quantity' : ( cart ? 'cart' : 'button' ) );
		};
		if ( window.PointerEvent ) document.addEventListener( 'pointerdown', feedbackTarget, true );
		else document.addEventListener( 'touchstart', feedbackTarget, { capture: true, passive: true } );
		document.addEventListener( 'click', feedbackTarget, true );
	}

	function initializeStandaloneAppJourney() {
		if ( ! isStandaloneApp() ) return;
		const currentUser = phonekey.user || {};
		if ( ! currentUser.loggedIn || ! currentUser.verified ) {
			appPhoneKeyGate = true;
			notificationIdentityIntent = '';
			openOverlay( 'profile', 'Welcome to your Appsite' );
			return;
		}
		appPhoneKeyGate = false;
		if ( ! notificationConfig.enabled ) return;
		if ( resumeIosNotificationJourney() ) return;
		const hasPreferences = notificationPreferences.topics.length > 0 && notificationPreferences.channels.length > 0;
		const appEnabled = notificationPreferences.channels.indexOf( 'app' ) !== -1;
		const browserPermission = 'Notification' in window ? window.Notification.permission : 'unsupported';
		const permissionDecided = ! notificationConfig.browserPermissionEnabled || [ 'granted', 'denied', 'unsupported' ].indexOf( browserPermission ) !== -1;
		if ( hasPreferences && ( ! appEnabled || permissionDecided ) ) return;
		notificationSetupGate = true;
		const ownerUser = currentUser.isAdmin || currentUser.canManageOrders || currentUser.canModerate;
		if ( ! ownerUser ) {
			openNotificationPreferences( { context: 'standalone_setup', required: true } );
			return;
		}
		if ( ! hasPreferences ) {
			const next = Object.assign( emptyNotificationPreferences(), notificationPreferences );
			next.topics = ( Array.isArray( notificationConfig.topics ) ? notificationConfig.topics : [] ).filter( function ( topic ) { return topic && topic.audience === 'admin'; } ).map( function ( topic ) { return topic.id; } );
			if ( next.channels.indexOf( 'app' ) === -1 ) next.channels.push( 'app' );
			next.context = 'standalone_admin_setup';
			next.standalone = true;
			saveNotificationPreferenceDraft( next );
		}
		const insight = recordAiNotification( {
			id: 'app-notification-setup',
			type: 'permission',
			kicker: 'Finish your app',
			title: 'Choose the updates that matter to you.',
			message: 'Allow or deny owner alerts for new orders and comments. Your choice stays editable.',
			action: 'open_notification_preferences',
			actionLabel: 'Set up notifications',
			requiredAction: true,
			dismissible: false,
		} );
		if ( insight ) queueAiPopout( insight, 'permission' );
	}

	function retireKiweServiceWorker() {
		if ( 'serviceWorker' in window.navigator && typeof window.navigator.serviceWorker.getRegistrations === 'function' ) {
			window.navigator.serviceWorker.getRegistrations().then( function ( registrations ) {
				registrations.forEach( function ( registration ) {
					const scriptUrl = registration.active && registration.active.scriptURL ? registration.active.scriptURL : '';
					const waitingUrl = registration.waiting && registration.waiting.scriptURL ? registration.waiting.scriptURL : '';
					const installingUrl = registration.installing && registration.installing.scriptURL ? registration.installing.scriptURL : '';
					if ( scriptUrl.indexOf( 'dsa_service_worker=1' ) !== -1 || waitingUrl.indexOf( 'dsa_service_worker=1' ) !== -1 || installingUrl.indexOf( 'dsa_service_worker=1' ) !== -1 ) {
						registration.unregister();
					}
				} );
			} ).catch( function () {} );
		}
		if ( 'caches' in window ) {
			window.caches.keys().then( function ( keys ) {
				keys.filter( function ( key ) {
					return key.indexOf( 'kiwe-appsite-' ) === 0 || key.indexOf( 'kiwe-editorial-v1-' ) === 0 || key.indexOf( 'kiwe-editorial-media-v1-' ) === 0;
				} ).forEach( function ( key ) {
					window.caches.delete( key );
				} );
			} ).catch( function () {} );
		}
	}

	function publishAppAdoptionState() {
		const manifestLink = document.querySelector( 'link[rel~="manifest"]' );
		const detail = {
			version: 2,
			platform: platformContext(),
			browser: pwaBrowserFamily(),
			standalone: isStandaloneApp(),
			installAvailable: Boolean( deferredInstallPrompt || window.DSA.deferredInstallPrompt ),
			secureContext: Boolean( window.isSecureContext ),
			manifestPresent: Boolean( manifestLink || pwaConfig.manifestUrl ),
			notificationPermission: 'Notification' in window ? window.Notification.permission : 'unsupported',
			serviceWorkerReady: Boolean( pwaConfig.serviceWorkerEnabled !== false && window.DSA.serviceWorkerRegistration ),
		};
		window.DSA.appAdoption = detail;
		window.DSA.inspectAppInstall = inspectPwaInstallReadiness;
		window.dispatchEvent( new CustomEvent( 'surface:app:adoption', { detail: detail } ) );
	}

	function bindBrowserNotificationTriggers() {
		document.addEventListener( 'click', function ( event ) {
			const target = event.target && event.target.closest
				? event.target.closest( '[data-kiwe-notifications], [data-dsa-notifications], [data-dsa-permission="notifications"]' )
				: null;
			if ( ! target ) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			if ( target.hasAttribute( 'data-dsa-native-notification-request' ) ) {
				requestBrowserNotifications( target );
				return;
			}
			openNotificationPreferences( { topic: target.getAttribute( 'data-kiwe-notification-topic' ) || '', context: 'explicit_trigger' } );
		}, true );
	}

	function requestBrowserNotifications( trigger ) {
		if ( ! permissionJourneyEnabled( 'browser_notifications' ) ) {
			updateNotificationTrigger( trigger, 'disabled', 'Browser notifications are not enabled by this site.' );
			return Promise.resolve( 'disabled' );
		}
		if ( isProtectedFlowActive() ) {
			updateNotificationTrigger( trigger, 'deferred', 'Kiwe will ask after this protected flow.' );
			return Promise.resolve( 'deferred' );
		}
		if ( ! window.isSecureContext || ! ( 'Notification' in window ) ) {
			const message = isIosDevice() && ! isStandaloneApp()
				? 'Add this appsite to your Home Screen first, then open it there to enable notifications.'
				: 'This browser does not support notification permission here.';
			updateNotificationTrigger( trigger, 'unsupported', message );
			return Promise.resolve( 'unsupported' );
		}

		if ( window.Notification.permission === 'granted' ) {
			syncNotificationPermissionPreference( 'granted' );
			recordMetric( 'notification_granted', platformContext() );
			recordPermissionOutcome( 'granted', 'browser_notifications' );
			updateNotificationTrigger( trigger, 'granted', 'Browser notifications are already on.' );
			completeIosNotificationResume( trigger );
			return ensurePushSubscription().then( function () { return 'granted'; } ).catch( function ( error ) {
				debugLog( 'Push subscription failed', { message: error.message || String( error ) } );
				updateNotificationTrigger( trigger, 'error', error.message || 'Notification permission is on, but this device could not subscribe.' );
				return 'granted';
			} );
		}
		if ( window.Notification.permission === 'denied' ) {
			syncNotificationPermissionPreference( 'denied' );
			recordMetric( 'notification_denied', platformContext() );
			recordPermissionOutcome( 'denied', 'browser_notifications' );
			updateNotificationTrigger( trigger, 'denied', 'Notifications are blocked. Change this site permission in your browser settings.' );
			return Promise.resolve( 'denied' );
		}

		recordMetric( 'notification_prompt', platformContext() );
		recordPermissionOutcome( 'shown', 'browser_notifications' );
		return requestBrowserNotificationPermission().then( function ( permission ) {
			if ( permission === 'granted' ) {
				syncNotificationPermissionPreference( 'granted' );
				return ensurePushSubscription().catch( function ( error ) {
					updateNotificationTrigger( trigger, 'error', error.message || 'Notification permission is on, but this device could not subscribe.' );
				} ).then( function () {
				recordMetric( 'notification_granted', platformContext() );
				recordPermissionOutcome( 'granted', 'browser_notifications' );
				updateNotificationTrigger( trigger, 'granted', 'Notifications are on. You are in control and can switch them off in your browser.' );
				showNotificationConfirmation();
				completeIosNotificationResume( trigger );
				publishAppAdoptionState();
				return 'granted';
				} );
			} else {
				const outcome = permission === 'denied' ? 'denied' : 'default';
				syncNotificationPermissionPreference( outcome );
				recordMetric( permission === 'denied' ? 'notification_denied' : 'notification_prompt', platformContext() );
				recordPermissionOutcome( outcome, 'browser_notifications' );
				updateNotificationTrigger( trigger, outcome, permission === 'denied' ? 'Notifications were not enabled.' : 'Notification request closed. You can choose again later.' );
				publishAppAdoptionState();
				return outcome;
			}
		} ).catch( function () {
			updateNotificationTrigger( trigger, 'error', 'The browser could not open notification settings.' );
			return 'error';
		} );
	}

	function ensurePushSubscription() {
		if ( ! pwaConfig.pushEnabled || ! pwaConfig.vapidPublicKey ) return Promise.reject( new Error( 'Push delivery is not ready on this Appsite.' ) );
		return registerKiweServiceWorker().then( function ( registration ) {
			if ( ! registration ) throw new Error( 'The service worker is not ready.' );
			return window.navigator.serviceWorker.ready;
		} ).then( function ( registration ) {
			if ( ! registration.pushManager ) throw new Error( 'This browser does not expose PushManager.' );
			return registration.pushManager.getSubscription().then( function ( subscription ) {
				const subscribedKey = subscription && subscription.options ? subscription.options.applicationServerKey : null;
				const expectedKey = urlBase64ToUint8Array( pwaConfig.vapidPublicKey );
				let rememberedKeyId = '';
				try { rememberedKeyId = window.localStorage ? window.localStorage.getItem( 'dsa_vapid_key_id' ) || '' : ''; } catch ( error ) {}
				const keyMatches = ! subscription
					|| ( subscribedKey ? pushKeyMatches( subscribedKey, expectedKey ) : ! rememberedKeyId || rememberedKeyId === ( pwaConfig.vapidKeyId || '' ) );
				const ready = subscription && ! keyMatches
					? subscription.unsubscribe().catch( function () { return false; } ).then( function () { return null; } )
					: Promise.resolve( subscription );
				return ready.then( function ( current ) { return current || registration.pushManager.subscribe( {
					userVisibleOnly: true,
					applicationServerKey: expectedKey,
				} ); } );
			} );
		} ).then( function ( subscription ) {
			let renewalToken = '';
			let renewalEndpoint = '';
			try {
				renewalToken = window.localStorage ? window.localStorage.getItem( 'dsa_push_renewal_token' ) || '' : '';
				renewalEndpoint = window.localStorage ? window.localStorage.getItem( 'dsa_push_renewal_endpoint' ) || '' : '';
			} catch ( error ) {}
			const payload = {
				visitorId: permissionVisitorId(),
				standalone: isStandaloneApp(),
				oldEndpoint: renewalToken && renewalEndpoint ? renewalEndpoint : '',
				renewalToken: renewalToken && renewalEndpoint ? renewalToken : '',
				subscription: subscription.toJSON ? subscription.toJSON() : subscription,
			};
			const save = dsaPost( '/push/subscription', payload ).catch( function ( error ) {
				if ( ! payload.renewalToken || Number( error && error.status ) !== 403 ) throw error;
				try {
					if ( window.localStorage ) {
						window.localStorage.removeItem( 'dsa_push_renewal_token' );
						window.localStorage.removeItem( 'dsa_push_renewal_endpoint' );
					}
				} catch ( storageError ) {}
				payload.oldEndpoint = '';
				payload.renewalToken = '';
				return dsaPost( '/push/subscription', payload );
			} );
			return save.then( function ( payload ) {
				window.DSA.pushSubscription = subscription;
				try { if ( window.localStorage && pwaConfig.vapidKeyId ) window.localStorage.setItem( 'dsa_vapid_key_id', pwaConfig.vapidKeyId ); } catch ( error ) {}
				if ( payload && payload.renewalToken ) persistPushRenewal( subscription.endpoint, payload.renewalToken );
				return subscription;
			} );
		} );
	}

	function persistPushRenewal( endpoint, token ) {
		try {
			if ( window.localStorage ) {
				window.localStorage.setItem( 'dsa_push_renewal_token', token );
				window.localStorage.setItem( 'dsa_push_renewal_endpoint', endpoint );
			}
		} catch ( error ) {}
		const message = { type: 'KIWE_PUSH_RENEWAL', endpoint: endpoint, renewalToken: token };
		if ( window.navigator.serviceWorker.controller ) window.navigator.serviceWorker.controller.postMessage( message );
		window.navigator.serviceWorker.ready.then( function ( registration ) {
			const worker = registration.active || registration.waiting || registration.installing;
			if ( worker ) worker.postMessage( message );
		} ).catch( function () {} );
	}

	function pushKeyMatches( current, expected ) {
		const currentBytes = current instanceof Uint8Array ? current : new Uint8Array( current );
		if ( currentBytes.length !== expected.length ) return false;
		for ( let index = 0; index < expected.length; index++ ) {
			if ( currentBytes[ index ] !== expected[ index ] ) return false;
		}
		return true;
	}

	function urlBase64ToUint8Array( value ) {
		const padding = '='.repeat( ( 4 - value.length % 4 ) % 4 );
		const base64 = ( value + padding ).replace( /-/g, '+' ).replace( /_/g, '/' );
		const raw = window.atob( base64 );
		return Uint8Array.from( raw, function ( character ) { return character.charCodeAt( 0 ); } );
	}

	function syncNotificationPermissionPreference( permission ) {
		if ( ! notificationConfig.enabled || notificationPreferences.channels.indexOf( 'app' ) === -1 ) return;
		const next = Object.assign( emptyNotificationPreferences(), notificationPreferences, {
			standalone: isStandaloneApp(),
			browserPermission: permission || 'default',
		} );
		saveNotificationPreferenceDraft( next );
		dsaPost( '/notification-preferences', Object.assign( { visitorId: permissionVisitorId() }, next ) ).catch( function () {} );
		if ( permission === 'denied' ) removePushSubscription().catch( function () {} );
	}

	function removePushSubscription() {
		if ( ! ( 'serviceWorker' in window.navigator ) ) return Promise.resolve( false );
		return window.navigator.serviceWorker.ready.then( function ( registration ) {
			return registration.pushManager ? registration.pushManager.getSubscription() : null;
		} ).then( function ( subscription ) {
			if ( ! subscription ) return false;
			const endpoint = subscription.endpoint || '';
			return subscription.unsubscribe().catch( function () { return false; } ).then( function () {
				if ( ! endpoint ) return true;
				return dsaDelete( '/push/subscription', { endpoint: endpoint, visitorId: permissionVisitorId() } ).then( function () {
					try {
						if ( window.localStorage ) {
							window.localStorage.removeItem( 'dsa_push_renewal_token' );
							window.localStorage.removeItem( 'dsa_push_renewal_endpoint' );
						}
					} catch ( error ) {}
					if ( window.navigator.serviceWorker.controller ) window.navigator.serviceWorker.controller.postMessage( { type: 'KIWE_PUSH_RENEWAL_CLEAR' } );
					return true;
				} );
			} );
		} );
	}

	function completeIosNotificationResume( trigger ) {
		if ( ! trigger || ! trigger.dataset || trigger.dataset.dsaIosNotificationResume !== '1' ) return;
		try { window.localStorage.removeItem( 'dsa_ios_notification_resume' ); } catch ( error ) {}
		removeAiNotification( 'ios-notifications-permission' );
		hideAiPopout( false, true );
		rerenderAiInsightInbox();
	}

	function requestBrowserNotificationPermission() {
		return new Promise( function ( resolve, reject ) {
			let complete = false;
			const finish = function ( permission ) {
				if ( complete ) {
					return;
				}
				complete = true;
				resolve( permission || window.Notification.permission || 'default' );
			};

			try {
				const request = window.Notification.requestPermission( finish );
				if ( request && typeof request.then === 'function' ) {
					request.then( finish ).catch( reject );
				} else if ( typeof request === 'string' ) {
					finish( request );
				}
			} catch ( error ) {
				reject( error );
			}
		} );
	}

	function updateNotificationTrigger( trigger, state, message ) {
		if ( trigger ) {
			trigger.dataset.kiweNotificationState = state || '';
			trigger.setAttribute( 'aria-label', message || trigger.getAttribute( 'aria-label' ) || 'Browser notifications' );
			trigger.dispatchEvent( new CustomEvent( 'kiwe:notifications', { bubbles: true, detail: { state: state, message: message } } ) );
			const selector = trigger.getAttribute( 'data-kiwe-notification-status-target' );
			let status = null;
			if ( selector ) {
				try {
					status = document.querySelector( selector );
				} catch ( error ) {
					debugLog( 'Notification status target is not a valid selector', { selector: selector } );
				}
			}
			if ( status ) {
				status.textContent = message || '';
			}
		}
		announce( message || '' );
		if ( message && [ 'granted', 'denied', 'unsupported', 'error' ].indexOf( state ) !== -1 ) {
			const notification = recordAiNotification( {
				id: 'browser-notifications:' + state,
				type: 'permission',
				kicker: state === 'granted' ? 'Notifications ready' : 'Notification setting',
				title: state === 'granted' ? 'Browser notifications are on.' : 'Browser notifications need attention.',
				message: message,
			} );
			if ( notification ) {
				queueAiPopout( notification, 'permission' );
				syncAiInsights( 'permission', false );
			}
		}
	}

	function showNotificationConfirmation() {
		const permission = permissionsConfig.permissions && permissionsConfig.permissions.browser_notifications
			? permissionsConfig.permissions.browser_notifications
			: {};
		registerKiweServiceWorker().then( function ( registration ) {
			if ( registration && typeof registration.showNotification === 'function' ) {
				return registration.showNotification( data.site && data.site.title ? data.site.title : 'Notifications are on', {
					body: permission.message || 'Useful updates can now appear here.',
					icon: data.site && data.site.icon ? data.site.icon : undefined,
					tag: 'kiwe-notifications-ready',
				} );
			}
			return null;
		} ).catch( function () {} );
	}

	function hideLoader( force ) {
		if ( ! loader ) {
			return;
		}

		if ( force ) {
			window.clearTimeout( loaderHideTimer );
			window.clearTimeout( loaderHoverGraceTimer );
			loaderHideTimer = 0;
			loaderHoverGraceTimer = 0;
		}

		if ( loaderHoverHold && ! force ) {
			loaderHidePending = true;
			return;
		}

		const elapsed = Date.now() - loaderStartedAt;
		const remaining = force ? 0 : Math.max( 0, Number( visual.min_loader_ms ) - elapsed );

		window.clearTimeout( loaderHideTimer );
		loaderHideTimer = window.setTimeout( function () {
			loaderHideTimer = 0;
			if ( loaderHoverHold && ! force ) {
				loaderHidePending = true;
				return;
			}
			loader.hidden = true;
			loaderHidePending = false;
			window.clearInterval( preloaderClockTimer );
			preloaderClockTimer = 0;
			if ( ! overlayRoot || overlayRoot.hidden ) {
				setOverlayActive( false );
			}
			clearSurfaceMode( 'transition' );
			announce( 'Surface loading experience complete.' );
			recordMetric( 'transition_complete', 'complete', Date.now() - loaderStartedAt );
			window.dispatchEvent( new CustomEvent( 'surface:loading:complete' ) );
		}, remaining );
	}

	function scheduleLoaderRelease( delay ) {
		window.clearTimeout( loaderHoverGraceTimer );
		loaderHoverGraceTimer = window.setTimeout( function () {
			loaderHoverGraceTimer = 0;

			if ( loaderHoverHold ) {
				return;
			}

			if ( loaderHidePending ) {
				hideLoader( true );
			}

			if ( pendingFullNavigationReady ) {
				commitPendingFullNavigation();
			}
		}, Number( delay ) || 900 );
	}

	function commitPendingFullNavigation() {
		if ( ! pendingFullNavigationUrl ) {
			return;
		}

		if ( loaderHoverHold ) {
			pendingFullNavigationReady = true;
			return;
		}

		const url = pendingFullNavigationUrl;
		pendingFullNavigationUrl = '';
		pendingFullNavigationReady = false;
		window.location.href = url;
	}

	function startNavigationWatchdog( url, mode ) {
		window.clearTimeout( navigationSafetyTimer );
		navigationSafetyTimer = window.setTimeout( function () {
			navigationSafetyTimer = 0;

			if ( ! navigationInFlight ) {
				return;
			}

			if ( fragmentAbortController && typeof fragmentAbortController.abort === 'function' ) {
				fragmentAbortController.abort();
				fragmentAbortController = null;
			}

			resetNavigationState( true );
			recordMetric( 'transition_timeout', mode || 'unknown' );
			window.dispatchEvent(
				new CustomEvent( 'surface:navigation:timeout', {
					detail: {
						url: url,
						mode: mode || 'unknown',
					},
				} )
			);
		}, navigationTimeoutMs() );
	}

	function resetNavigationState( releaseSurface ) {
		navigationInFlight = false;
		pendingFullNavigationUrl = '';
		pendingFullNavigationReady = false;

		if ( pendingFullNavigationTimer ) {
			window.clearTimeout( pendingFullNavigationTimer );
			pendingFullNavigationTimer = 0;
		}

		if ( navigationSafetyTimer ) {
			window.clearTimeout( navigationSafetyTimer );
			navigationSafetyTimer = 0;
		}

		if ( fragmentAbortController && typeof fragmentAbortController.abort === 'function' ) {
			fragmentAbortController.abort();
			fragmentAbortController = null;
		}

		if ( releaseSurface ) {
			loaderHoverHold = false;
			loaderHidePending = false;
			window.clearTimeout( loaderHideTimer );
			window.clearTimeout( loaderHoverGraceTimer );
			loaderHideTimer = 0;
			loaderHoverGraceTimer = 0;
			if ( loaderCopy ) {
				loaderCopy.classList.remove( 'is-hovered' );
			}
			hideLoader( true );
		}
	}

	function rememberSurfaceReturn( link ) {
		if ( ! overlayRoot || overlayRoot.hidden || ! link ) {
			return;
		}

		let state = null;
		const accountView = overlayRoot.querySelector( '[data-dsa-account-view]' );

		if ( accountView ) {
			state = {
				panel: 'profile',
				view: accountView.dataset.dsaAccountView || '',
			};
		} else if ( overlayRoot.querySelector( '[data-dsa-profile-panel]' ) ) {
			state = { panel: 'profile' };
		} else if ( overlayRoot.querySelector( '[data-dsa-cart-panel]' ) ) {
			state = { panel: 'cart' };
		}

		if ( ! state ) {
			return;
		}

		state.expires = Date.now() + ( 10 * 60 * 1000 );

		try {
			if ( window.sessionStorage ) {
				window.sessionStorage.setItem( surfaceReturnKey, JSON.stringify( state ) );
			}
		} catch ( error ) {}
	}

	function clearSurfaceReturnState() {
		try {
			if ( window.sessionStorage ) {
				window.sessionStorage.removeItem( surfaceReturnKey );
			}
		} catch ( error ) {}
	}

	function restoreSurfaceReturn() {
		let state = null;

		try {
			if ( window.sessionStorage ) {
				state = JSON.parse( window.sessionStorage.getItem( surfaceReturnKey ) || 'null' );
				window.sessionStorage.removeItem( surfaceReturnKey );
			}
		} catch ( error ) {
			state = null;
		}

		if ( ! state || ! state.panel || Number( state.expires ) < Date.now() ) {
			return;
		}

		if ( state.panel === 'cart' && isCurrentCheckoutPage() ) {
			return;
		}

		openOverlay( state.panel, state.panel === 'cart' ? 'Cart' : 'Profile' );

		if ( state.panel === 'profile' && state.view ) {
			window.setTimeout( function () {
				if ( overlayRoot && ! overlayRoot.hidden ) {
					openAccountView( state.view );
				}
			}, 80 );
		}
	}

	function bindCheckoutPageBridge() {
		if ( ! ( commerce.settings && commerce.settings.checkoutSurfaceEnabled ) || ! isCurrentCheckoutPage() ) {
			return;
		}

		clearSurfaceReturnState();

		dsaGet( '/checkout?consumeErrors=1' ).then( function ( response ) {
			const contract = response && response.checkout ? response.checkout : null;
			if ( ! contract || ! contract.available ) {
				return;
			}

			checkoutPageDraftValues = contract.hasDraft ? Object.assign( {}, contract.values || {} ) : {};
			const hydrated = applyCheckoutValuesToPage( contract.values || {}, Boolean( contract.hasDraft ) );
			if ( contract.hasDraft && hydrated ) {
				window.setTimeout( triggerWooCheckoutRefresh, 60 );
			}
			const errors = contract.errors || {};
			const notices = contract.notices || [];
			if ( Object.keys( errors ).length || notices.length ) {
				openCheckoutSurface( { errors: errors, notices: notices, returnToPage: true } );
			}
		} ).catch( function () {} );

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'checkout_error.dsaCheckout', function () {
				window.setTimeout( openCheckoutCorrectionsFromPage, 20 );
			} );
			window.jQuery( document.body ).on( 'updated_checkout.dsaCheckout', function () {
				window.setTimeout( reconcileCheckoutPageValues, 20 );
			} );
		}

		const checkoutRoot = document.querySelector( 'form.checkout, .wp-block-woocommerce-checkout, .wc-block-checkout' );
		if ( checkoutRoot && window.MutationObserver ) {
			const observer = new MutationObserver( function ( mutations ) {
				const hasErrorNotice = mutations.some( function ( mutation ) {
					return Array.prototype.some.call( mutation.addedNodes || [], function ( node ) {
						return node.nodeType === 1 && ( node.matches( '.woocommerce-error, .wc-block-components-validation-error, .wc-block-store-notice--error' ) || node.querySelector( '.woocommerce-error, .wc-block-components-validation-error, .wc-block-store-notice--error' ) );
					} );
				} );

				if ( hasErrorNotice ) {
					window.clearTimeout( checkoutErrorOpenTimer );
					checkoutErrorOpenTimer = window.setTimeout( openCheckoutCorrectionsFromPage, 80 );
				}

				const fieldsReplaced = mutations.some( function ( mutation ) {
					return Array.prototype.some.call( mutation.addedNodes || [], function ( node ) {
						return node.nodeType === 1 && ( node.matches( 'input[name], select[name], textarea[name]' ) || node.querySelector( 'input[name], select[name], textarea[name]' ) );
					} );
				} );
				if ( fieldsReplaced ) {
					window.setTimeout( reconcileCheckoutPageValues, 20 );
				}
			} );

			observer.observe( checkoutRoot, { childList: true, subtree: true } );
		}

		document.addEventListener( 'input', function ( event ) {
			if ( checkoutPageHydrating || ! event.target || ! event.target.matches( 'form.checkout [name], .wp-block-woocommerce-checkout [name], .wc-block-checkout [name]' ) ) {
				return;
			}

			const key = normalizeCheckoutPageFieldName( event.target.name );
			if ( ! key ) {
				return;
			}

			checkoutPageDraftValues[ key ] = event.target.type === 'checkbox' ? ( event.target.checked ? '1' : '0' ) : event.target.value;
			window.clearTimeout( checkoutPageDraftTimer );
			checkoutPageDraftTimer = window.setTimeout( function () {
				dsaPost( '/checkout', { fields: collectCheckoutPageValues(), validate: false } ).catch( function () {} );
			}, 420 );
		}, true );
	}

	function openCheckoutCorrectionsFromPage() {
		if ( overlayRoot && ! overlayRoot.hidden && overlayRoot.querySelector( '[data-dsa-checkout-panel]' ) ) {
			return;
		}

		const pageValues = collectCheckoutPageValues();
		const pageErrors = collectCheckoutPageErrors();

		dsaPost( '/checkout', { fields: pageValues, validate: false } )
			.catch( function () {
				return null;
			} )
			.finally( function () {
				openCheckoutSurface( {
					errors: pageErrors.fields,
					notices: pageErrors.notices,
					returnToPage: true,
				} );
			} );
	}

	function collectCheckoutPageValues() {
		const values = {};
		const roots = document.querySelectorAll( 'form.checkout, .wp-block-woocommerce-checkout, .wc-block-checkout' );

		roots.forEach( function ( root ) {
			root.querySelectorAll( 'input[name], select[name], textarea[name]' ).forEach( function ( field ) {
				const name = normalizeCheckoutPageFieldName( field.name );
				if ( [ 'ship_to_different_address', 'createaccount' ].indexOf( name ) === -1 && ! /^(billing|shipping|account|order)_/.test( name ) ) {
					return;
				}
				values[ name ] = field.type === 'checkbox' ? ( field.checked ? '1' : '0' ) : field.value;
			} );
		} );

		return values;
	}

	function collectCheckoutPageErrors() {
		const fields = {};
		const notices = [];

		document.querySelectorAll( '.woocommerce-invalid, .woocommerce-invalid-required-field, .wc-block-components-validation-error' ).forEach( function ( row ) {
			const input = row.matches( '[name]' ) ? row : row.querySelector( '[name]' );
			const messageNode = row.querySelector( '.woocommerce-error, .wc-block-components-validation-error__message, [role="alert"]' );
			const key = input ? normalizeCheckoutPageFieldName( input.name ) : '';
			const message = messageNode ? messageNode.textContent.trim() : 'Please check this field.';
			if ( key ) {
				fields[ key ] = message;
			}
		} );

		document.querySelectorAll( '.woocommerce-error li, .woocommerce-error, .wc-block-store-notice--error, .wc-block-components-notice-banner.is-error' ).forEach( function ( node ) {
			const field = normalizeCheckoutPageFieldName( node.dataset && node.dataset.id ? node.dataset.id : '' );
			const message = node.textContent.trim();
			if ( ! message ) {
				return;
			}
			if ( field ) {
				fields[ field ] = message;
			} else {
				notices.push( message );
			}
		} );

		return { fields: fields, notices: uniqueStrings( notices ) };
	}

	function applyCheckoutValuesToPage( values, force ) {
		let changed = 0;
		const selectFields = [];
		checkoutPageHydrating = true;

		Object.keys( values || {} ).forEach( function ( key ) {
			const selectors = [
				'[name="' + cssEscape( key ) + '"]',
				'[name="' + cssEscape( key.replace( /^(billing|shipping|account|order)_/, '$1-' ) ) + '"]',
				'[name="' + cssEscape( key.replace( /_/g, '-' ) ) + '"]',
			];
			let field = null;
			selectors.some( function ( selector ) {
				field = document.querySelector( 'form.checkout ' + selector + ', .wp-block-woocommerce-checkout ' + selector + ', .wc-block-checkout ' + selector );
				return Boolean( field );
			} );

			if ( ! field || ( ! force && ( field.type === 'checkbox' ? field.checked : field.value ) ) ) {
				return;
			}

			if ( field.type === 'checkbox' ) {
				setNativeCheckoutFieldProperty( field, 'checked', String( values[ key ] ) === '1' );
			} else {
				setNativeCheckoutFieldProperty( field, 'value', values[ key ] == null ? '' : String( values[ key ] ) );
			}
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			if ( field.tagName === 'SELECT' ) {
				selectFields.push( field );
			}
			changed += 1;
		} );

		if ( window.jQuery ) {
			selectFields.forEach( function ( field ) {
				window.jQuery( field ).trigger( 'change.select2' );
			} );
		}

		checkoutPageHydrating = false;
		return changed;
	}

	function setNativeCheckoutFieldProperty( field, property, value ) {
		let prototype = null;
		if ( field.tagName === 'SELECT' ) {
			prototype = window.HTMLSelectElement && window.HTMLSelectElement.prototype;
		} else if ( field.tagName === 'TEXTAREA' ) {
			prototype = window.HTMLTextAreaElement && window.HTMLTextAreaElement.prototype;
		} else {
			prototype = window.HTMLInputElement && window.HTMLInputElement.prototype;
		}

		const descriptor = prototype ? Object.getOwnPropertyDescriptor( prototype, property ) : null;
		if ( descriptor && typeof descriptor.set === 'function' ) {
			descriptor.set.call( field, value );
		} else {
			field[ property ] = value;
		}
	}

	function reconcileCheckoutPageValues() {
		if ( ! Object.keys( checkoutPageDraftValues ).length ) {
			return;
		}

		applyCheckoutValuesToPage( checkoutPageDraftValues, true );
	}

	function triggerWooCheckoutRefresh() {
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
		window.dispatchEvent( new CustomEvent( 'dsa:checkout:draft-applied' ) );
	}

	function normalizeCheckoutPageFieldName( name ) {
		name = String( name || '' ).replace( /\[.*$/, '' ).replace( /-/g, '_' );
		return [ 'ship_to_different_address', 'createaccount' ].indexOf( name ) !== -1 || /^(billing|shipping|account|order)_/.test( name ) ? name : '';
	}

	function isCurrentCheckoutPage() {
		try {
			return isCheckoutNavigationUrl( new URL( window.location.href ) ) && ! /order-(?:pay|received)/i.test( window.location.pathname );
		} catch ( error ) {
			return false;
		}
	}

	function uniqueStrings( values ) {
		return values.filter( function ( value, index, list ) {
			value = String( value || '' ).trim();
			return value && list.map( String ).indexOf( value ) === index;
		} );
	}

	function bindExternalUiEscape() {
		const cartEvents = [
			'dsa:cart:changed',
			'wc_fragments_loaded',
			'wc_fragments_refreshed',
			'updated_wc_div',
			'updated_cart_totals',
			'added_to_cart',
			'removed_from_cart',
			'wc-blocks_added_to_cart',
			'wc-blocks_removed_from_cart',
			'wc-blocks_cart_update',
		];
		const cartMutationEvents = [
			'dsa:cart:changed',
			'added_to_cart',
			'wc-blocks_added_to_cart',
		];
		const release = function ( event ) {
			resetNavigationState( true );

			if ( event && cartEvents.indexOf( event.type ) !== -1 ) {
				if ( cartMutationEvents.indexOf( event.type ) !== -1 ) {
					const knownCartCount = cartStateInitialized ? Number( phonekey.cart && phonekey.cart.count || 0 ) : 0;
					firstCartConfettiQueued = knownCartCount <= 0;
					if ( firstCartConfettiQueued ) {
						playFirstCartConfetti( event.type );
					}
				}

				if ( event.type === 'dsa:cart:changed' && event.detail && event.detail.items ) {
					applyCartPayload( { cart: event.detail }, { rerender: true, cartMutation: true } );
					return;
				}

				scheduleCartRefreshSequence( [ 80, 360, 1100 ], { cartMutation: cartMutationEvents.indexOf( event.type ) !== -1 } );
			}
		};
		const nativeEvents = [
			'dsa:external-ui-open',
			'dsa:cart:changed',
			'wc_fragments_loaded',
			'wc_fragments_refreshed',
			'added_to_cart',
			'removed_from_cart',
			'wc-blocks_added_to_cart',
			'wc-blocks_removed_from_cart',
			'wc-blocks_cart_update',
			'shown.bs.modal',
			'show.bs.offcanvas',
			'elementor/popup/show',
		];

		nativeEvents.forEach( function ( eventName ) {
			document.addEventListener( eventName, release );
		} );

		if ( window.jQuery && window.jQuery.fn && ! window.DSA.externalUiEscapeBound ) {
			window.DSA.externalUiEscapeBound = true;
			window.jQuery( document.body ).on( 'added_to_cart removed_from_cart wc_fragments_loaded wc_fragments_refreshed updated_wc_div updated_cart_totals', function ( event ) {
				release( { type: event && event.type ? event.type : 'cart' } );
			} );
			window.jQuery( document ).on( 'elementor/popup/show shown.bs.modal show.bs.offcanvas', release );
		}

		bindNativeCartDomSync();
	}

	function bindNativeCartDomSync() {
		if ( ! window.MutationObserver || ! document.body || window.DSA.cartDomSyncBound ) {
			return;
		}

		window.DSA.cartDomSyncBound = true;
		const cartSelector = [
			'.widget_shopping_cart_content',
			'.woocommerce-mini-cart',
			'.wc-block-mini-cart',
			'.brxe-woocommerce-mini-cart',
			'[data-dsa-bricks-fbt]',
			'[data-dsa-bricks-cart-offers]',
		].join( ',' );
		const observer = new MutationObserver( function ( mutations ) {
			const changed = mutations.some( function ( mutation ) {
				const target = mutation.target && mutation.target.nodeType === 1 ? mutation.target : mutation.target.parentElement;

				if ( target && ( target.matches( cartSelector ) || target.closest( cartSelector ) ) ) {
					return true;
				}

				return Array.prototype.some.call( mutation.addedNodes || [], function ( node ) {
					return node.nodeType === 1 && ( node.matches( cartSelector ) || node.querySelector( cartSelector ) );
				} );
			} );

			if ( changed ) {
				scheduleCartRefreshSequence( [ 100, 420, 1200 ] );
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	function universalAddToCartMode() {
		const mode = commerce.settings && commerce.settings.addToCartMode ? String( commerce.settings.addToCartMode ) : 'default';
		return [ 'plus_only', 'quantity', 'replace' ].indexOf( mode ) !== -1 ? mode : 'default';
	}

	function cartItemForStoreProduct( productId ) {
		const items = phonekey.cart && Array.isArray( phonekey.cart.items ) ? phonekey.cart.items : [];
		return items.find( function ( item ) {
			return Number( item.productId || item.product_id || 0 ) === Number( productId ) && Number( item.variationId || item.variation_id || 0 ) === 0;
		} ) || null;
	}

	function isBricksEnhancedAddToCart( button ) {
		return Boolean( button && button.closest( '[data-brx-t1], [data-brx-t2], [data-brx-t3], [data-brx-t4], [data-brx-t5]' ) );
	}

	function refreshUniversalAddToCartEnhancers( root ) {
		const mode = universalAddToCartMode();
		const scope = root && root.querySelectorAll ? root : document;
		const buttons = scope.querySelectorAll( 'a.add_to_cart_button.ajax_add_to_cart[data-product_id]' );

		buttons.forEach( function ( button ) {
			if ( button.closest( '[data-dsa-surface]' ) || isBricksEnhancedAddToCart( button ) ) {
				return;
			}

			if ( ! button.dataset.dsaStoreOriginalHtml ) {
				button.dataset.dsaStoreOriginalHtml = button.innerHTML;
			}

			const productId = Number( button.dataset.product_id || button.getAttribute( 'data-product_id' ) || 0 );
			let wrapper = button.parentElement ? button.parentElement.querySelector( '[data-dsa-store-quantity="' + productId + '"]' ) : null;

			if ( mode === 'default' ) {
				button.innerHTML = button.dataset.dsaStoreOriginalHtml;
				button.hidden = false;
				if ( wrapper ) wrapper.remove();
				return;
			}

			if ( mode === 'plus_only' ) {
				button.innerHTML = '<span aria-hidden="true">+</span>';
				button.classList.add( 'dsa-store-atc--plus' );
				button.setAttribute( 'aria-label', button.getAttribute( 'aria-label' ) || 'Add product to cart' );
				button.hidden = false;
				if ( wrapper ) wrapper.remove();
				return;
			}

			if ( ! productId ) {
				return;
			}

			if ( ! wrapper ) {
				wrapper = document.createElement( 'span' );
				wrapper.className = 'dsa-store-quantity';
				wrapper.dataset.dsaStoreQuantity = String( productId );
				wrapper.innerHTML = '<button type="button" data-dsa-store-qty="-1" aria-label="Decrease quantity">&minus;</button><output aria-live="polite">0</output><button type="button" data-dsa-store-qty="1" aria-label="Increase quantity">+</button>';
				button.insertAdjacentElement( 'afterend', wrapper );
			}

			const item = cartItemForStoreProduct( productId );
			const quantity = item ? Number( item.quantity || 0 ) : 0;
			const output = wrapper.querySelector( 'output' );
			const minus = wrapper.querySelector( '[data-dsa-store-qty="-1"]' );
			if ( output ) output.value = String( quantity );
			if ( minus ) minus.disabled = quantity < 1;
			wrapper.hidden = mode === 'replace' && quantity < 1;
			button.hidden = mode === 'quantity' || ( mode === 'replace' && quantity > 0 );
		} );
	}

	function bindUniversalAddToCartEnhancer() {
		if ( universalAddToCartMode() === 'default' ) {
			return;
		}

		refreshUniversalAddToCartEnhancers();
		document.addEventListener( 'click', function ( event ) {
			const control = event.target && event.target.closest ? event.target.closest( '[data-dsa-store-qty]' ) : null;
			if ( ! control ) return;

			const wrapper = control.closest( '[data-dsa-store-quantity]' );
			const productId = wrapper ? Number( wrapper.dataset.dsaStoreQuantity || 0 ) : 0;
			const delta = Number( control.dataset.dsaStoreQty || 0 );
			if ( ! productId || ! delta || control.disabled ) return;

			event.preventDefault();
			event.stopPropagation();
			control.disabled = true;
			wrapper.classList.add( 'is-busy' );

			let cartSequence = 0;
			enqueueCartMutation( function () {
				cartSequence = nextCartSequence();
				const item = cartItemForStoreProduct( productId );
				const current = item ? Number( item.quantity || 0 ) : 0;
				const request = delta > 0
					? dsaPost( '/cart/add', { productId: productId, quantity: 1, source: 'kiwe_store_control' } )
					: dsaPost( '/cart/item', { key: item ? item.key : '', productId: productId, variationId: 0, quantity: Math.max( 0, current - 1 ), source: 'kiwe_store_control' } );

				return request.then( function ( response ) {
					applyCartPayload( response, { rerender: true, cartMutation: true, sequence: cartSequence } );
					applyWooCartFragments( response, delta > 0 ? 'added_to_cart' : ( current <= 1 ? 'removed_from_cart' : 'updated_cart_totals' ) );
					announce( delta > 0 ? 'Added to cart.' : 'Cart quantity updated.' );
				} );
			} ).catch( function ( error ) {
				announce( error && error.message ? error.message : 'Cart could not be updated.' );
			} ).finally( function () {
				wrapper.classList.remove( 'is-busy' );
				refreshUniversalAddToCartEnhancers();
			} );
		} );

		if ( window.MutationObserver && document.body ) {
			const observer = new MutationObserver( function ( mutations ) {
				const addedProductControls = mutations.some( function ( mutation ) {
					return Array.prototype.some.call( mutation.addedNodes || [], function ( node ) {
						return node.nodeType === 1 && ( node.matches( 'a.add_to_cart_button[data-product_id]' ) || node.querySelector( 'a.add_to_cart_button[data-product_id]' ) );
					} );
				} );
				if ( addedProductControls ) refreshUniversalAddToCartEnhancers();
			} );
			observer.observe( document.body, { childList: true, subtree: true } );
		}
	}

	function shouldHandleSurfaceFullNavigation( link ) {
		if ( ! link || navigationInFlight || link.hasAttribute( 'download' ) ) {
			return false;
		}

		if ( link.getAttribute( 'aria-disabled' ) === 'true' || link.closest( '[aria-disabled="true"]' ) ) {
			return false;
		}

		if ( link.target && link.target !== '_self' ) {
			return false;
		}

		if ( /^(mailto:|tel:|sms:)/i.test( link.getAttribute( 'href' ) || '' ) ) {
			return false;
		}

		try {
			const url = new URL( link.href, window.location.href );
			return url.origin === window.location.origin && ! isUnsafeCommerceMutationUrl( url );
		} catch ( error ) {
			return false;
		}
	}

	function navigationTimeoutMs() {
		const min = Number( visual.min_loader_ms ) || 0;
		const artificial = Number( visual.artificial_delay_ms ) || 0;
		return Math.max( 9000, Math.min( 30000, min + artificial + 12000 ) );
	}

	function escapeHtml( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	if ( aiPopout ) {
		aiPopout.addEventListener( 'click', function ( event ) {
			const dismiss = closestEventTarget( event, '[data-dsa-ai-popout-dismiss]' );
			if ( dismiss ) {
				event.preventDefault();
				event.stopPropagation();
				dismissAiNotificationCard( aiPopout, dismiss.dataset.dsaAiPopoutDismiss );
				return;
			}

			const close = closestEventTarget( event, '[data-dsa-ai-popout-close]' );
			if ( close ) {
				event.preventDefault();
				event.stopPropagation();
				hideAiPopout();
				return;
			}

			const view = closestEventTarget( event, '[data-dsa-ai-popout-view]' );
			if ( view ) {
				event.preventDefault();
				event.stopPropagation();
				hideAiPopout( false, true );
				openOverlay( 'ai', 'AI Assistant' );
				return;
			}

			const action = closestEventTarget( event, '[data-dsa-ai-popout-action]' );
			if ( action ) {
				event.preventDefault();
				event.stopPropagation();
				executeAiInsightAction( action.dataset.dsaAiPopoutAction, action );
			}
		} );
	}

	if ( dockContext ) {
		dockContext.addEventListener( 'click', function ( event ) {
			handleAccountContextClick( event );
		} );
	}

	surface.addEventListener( 'click', function ( event ) {
		if ( handleAccountContextClick( event ) ) {
			return;
		}

		const popoutClose = closestEventTarget( event, '[data-dsa-ai-popout-close]' );
		if ( popoutClose ) {
			event.preventDefault();
			event.stopPropagation();
			hideAiPopout();
			return;
		}

		const popoutView = closestEventTarget( event, '[data-dsa-ai-popout-view]' );
		if ( popoutView ) {
			event.preventDefault();
			event.stopPropagation();
			hideAiPopout( false, true );
			openOverlay( 'ai', 'AI Assistant' );
			return;
		}

		const popoutAction = closestEventTarget( event, '[data-dsa-ai-popout-action]' );
		if ( popoutAction ) {
			event.preventDefault();
			event.stopPropagation();
			executeAiInsightAction( popoutAction.dataset.dsaAiPopoutAction, popoutAction );
			return;
		}

		const menuLink = closestEventTarget( event, '.dsa-menu-link[href]' );

		if ( menuLink ) {
			event.preventDefault();
			event.stopPropagation();
			navigateWithFullPageLoader( menuLink.href );
			return;
		}

		const dockLink = closestEventTarget( event, '[data-dsa-dock-link][href]' );

		if ( dockLink ) {
			event.preventDefault();
			event.stopPropagation();
			navigateWithFullPageLoader( dockLink.href );
			return;
		}

		const button = closestEventTarget( event, '[data-dsa-module]' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		if ( button.dataset.dsaModule === 'theme' ) {
			toggleColorMode();
			return;
		}
		if ( overlayRoot && ! overlayRoot.hidden && activeOverlayModuleId === button.dataset.dsaModule ) {
			closeOverlay();
			return;
		}
		if ( button.dataset.dsaModule === 'ai' ) {
			hideAiPopout();
		}
		runtimeHydrationPromise.then( function () {
			openOverlay( button.dataset.dsaModule, button.getAttribute( 'aria-label' ) || 'Surface module' );
		} );
	} );

	if ( overlayRoot ) {
		overlayRoot.addEventListener( 'click', function ( event ) {
			const link = closestEventTarget( event, 'a[href][data-dsa-full-navigation]' );

			if ( link ) {
				rememberSurfaceReturn( link );

				if ( shouldHandleSurfaceFullNavigation( link ) ) {
					event.preventDefault();
					event.stopPropagation();
					navigateWithFullPageLoader( link.href );
				}
			}
		}, true );

		overlayRoot.addEventListener( 'click', function ( event ) {
			if ( overlayRoot.hidden ) {
				return;
			}

			if ( usesSheetPresentation() ) {
				const panel = overlayRoot.querySelector( ':scope > [role="dialog"]' );
				const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
				const insidePanel = Boolean(
					panel
					&& ( panel.contains( event.target ) || path.indexOf( panel ) !== -1 )
				);

				if ( insidePanel ) {
					return;
				}

				event.preventDefault();
				event.stopPropagation();
				closeOverlay();
				return;
			}

			if ( overlayRoot.querySelector( '[data-dsa-phonekey-auth]' ) ) {
				return;
			}

			if ( shouldKeepOverlayOpen( event.target ) ) {
				return;
			}

			event.preventDefault();
			closeOverlay();
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		const launcher = closestEventTarget( event, '[data-dsa-open-module]' );
		if ( ! launcher || launcher.closest( '[data-dsa-surface]' ) ) return;
		const moduleId = String( launcher.dataset.dsaOpenModule || '' );
		if ( ! moduleId ) return;
		event.preventDefault();
		event.stopPropagation();
		if ( moduleId === 'theme' ) {
			toggleColorMode();
			return;
		}
		openOverlay( moduleId, launcher.getAttribute( 'aria-label' ) || '' );
	}, true );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Enter' && event.key !== ' ' ) return;
		const launcher = closestEventTarget( event, '[data-dsa-open-module]' );
		if ( ! launcher || launcher.closest( '[data-dsa-surface]' ) ) return;
		event.preventDefault();
		launcher.click();
	} );

	if ( loader ) {
		loader.addEventListener( 'click', function ( event ) {
			if ( event.target === loader || event.target === loaderMessage ) {
				event.preventDefault();
				resetNavigationState( true );
			}
		} );
	}

	if ( loaderCopy ) {
		loaderCopy.addEventListener( 'pointerenter', function () {
			loaderHoverHold = true;
			if ( loaderHideTimer ) {
				loaderHidePending = true;
			}
			window.clearTimeout( loaderHideTimer );
			window.clearTimeout( loaderHoverGraceTimer );
			loaderHideTimer = 0;
			loaderHoverGraceTimer = 0;
			loaderCopy.classList.add( 'is-hovered' );
		} );
		loaderCopy.addEventListener( 'pointerleave', function () {
			loaderHoverHold = false;
			loaderCopy.classList.remove( 'is-hovered' );
			scheduleLoaderRelease( 1200 );
		} );
	}

	function shouldKeepOverlayOpen( target ) {
		if ( ! target || typeof target.closest !== 'function' ) {
			return false;
		}

		return Boolean(
			target.closest(
				[
					'a[href]',
					'button',
					'input',
					'select',
					'textarea',
					'label',
					'summary',
					'[contenteditable="true"]',
					'[role="button"]',
					'[role="link"]',
					'[tabindex]:not([tabindex="-1"])',
					'[data-dsa-keep-open]',
				].join( ',' )
			)
		);
	}

	document.addEventListener( 'click', function ( event ) {
		if ( ! overlayRoot || overlayRoot.hidden ) {
			return;
		}

		if ( usesSheetPresentation() ) {
			return;
		}

		if ( overlayRoot.querySelector( '[data-dsa-phonekey-auth]' ) ) {
			return;
		}

		if ( surface.contains( event.target ) ) {
			return;
		}

		closeOverlay();
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' && loader && ! loader.hidden ) {
			event.preventDefault();
			hideLoader( true );
		}
	} );

	window.addEventListener( 'pageshow', function () {
		resetNavigationState( true );
		reconcileInactiveOverlayState();
		scheduleCartRefreshSequence( [ 80, 420 ] );
	} );

	window.addEventListener( 'pagehide', function () {
		resetNavigationState( true );
		reconcileInactiveOverlayState();
	} );

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' && navigationInFlight && ! pendingFullNavigationUrl ) {
			resetNavigationState( true );
		}
		if ( document.visibilityState === 'visible' ) {
			reconcileInactiveOverlayState();
		}
	} );

	window.DSA.showLoader = showLoader;
	window.DSA.hideLoader = hideLoader;
	window.DSA.inspectAppInstall = inspectPwaInstallReadiness;
	window.DSA.inspectEditorialReconciliation = inspectEditorialReconciliation;
	window.DSA.previewLoader = function ( duration ) {
		showLoader();
		window.setTimeout( hideLoader, Number( duration ) || 1800 );
	};

	initializeSurfaceGeometry();
	normalizeStaleSurfaceHistoryEntry();
	setupCrossDocumentViewTransitions();
	showInitialPreloader();
	startAppsiteIdleEvent();
	bindExternalUiEscape();
	runtimeHydrationPromise.then( function () {
		reconcileAiCartState( phonekey.cart || {}, phonekey.cart || {} );
		initializeNotificationPreferences();
		initializeAiInsights();
		initializeAdminNotificationInbox();
		initializeLinksDockIcon();
		initializeSavedItems();
		initializePwaRuntime();
		bindCommerceFeedback();
		bindUniversalAddToCartEnhancer();
		bindBrowserNotificationTriggers();
		bindSavedTriggers();
		bindCheckoutPageBridge();
		window.setTimeout( restoreSurfaceReturn, 120 );
	} );

	if ( surfaceTriggerEnabled( 'safe_link_transition', visual.show_on_navigation ) || ( data.navigation && data.navigation.enabled ) || reconciliationConfig.applyEnabled || ( commerce.settings && commerce.settings.checkoutSurfaceEnabled ) ) {
		document.addEventListener( 'click', onNavigationClick, true );
	}

	window.addEventListener( 'popstate', function ( event ) {
		recordFragmentSafetyOutcome( { type: 'history', outcome: 'popstate', morphDocumentActive: morphDocumentActive, stateMarked: Boolean( event.state && event.state.kiweMorph ) } );
		if ( surfaceHistorySuppressPop ) {
			surfaceHistorySuppressPop = false;
			// This pop only consumes Kiwe's synthetic overlay entry. It is not page
			// navigation and must not reach Bricks filters or another page router.
			if ( event && typeof event.stopImmediatePropagation === 'function' ) {
				event.stopImmediatePropagation();
			}
			window.setTimeout( function () {
				window.dispatchEvent( new CustomEvent( 'surface:history:released' ) );
			}, 0 );
			return;
		}

		if ( surfaceHistoryActive && overlayRoot && ! overlayRoot.hidden ) {
			surfaceHistoryActive = false;
			surfaceHistoryClosing = true;
			surfaceFeedback( 'swipe_back' );
			closeOverlay( false, { fromHistory: true } );
			surfaceHistoryClosing = false;
			if ( event && typeof event.stopImmediatePropagation === 'function' ) {
				event.stopImmediatePropagation();
			}
			return;
		}

		if ( morphDocumentActive || ( event.state && event.state.kiweMorph ) ) {
			window.location.reload();
			return;
		}

		if ( data.navigation && data.navigation.enabled ) {
			window.location.reload();
		}
	} );

	window.addEventListener( 'pageshow', function ( event ) {
		recordFragmentSafetyOutcome( { type: 'history', outcome: 'pageshow', persisted: Boolean( event.persisted ) } );
	} );
	window.addEventListener( 'pagehide', function ( event ) {
		recordFragmentSafetyOutcome( { type: 'history', outcome: 'pagehide', persisted: Boolean( event.persisted ) } );
	} );

	function publishRouteCapability( link, source ) {
		const capability = classifyNavigationTarget( link );
		capability.source = source || 'unknown';
		window.DSA.routeState = capability;
		window.dispatchEvent( new CustomEvent( 'surface:route:capability', { detail: capability } ) );
		debugLog( 'route capability', capability );
		return capability;
	}

	function classifyNavigationTarget( target ) {
		const link = target && target.tagName ? target : null;
		const href = link ? link.href : String( target || '' );
		const rawHref = link ? String( link.getAttribute( 'href' ) || '' ).trim() : href;
		const result = {
			version: routePolicy.version || 1,
			href: href || '',
			capability: 'unknown',
			reason: '',
			mode: 'browser',
			sameOrigin: false,
			protected: false,
			transitionCapable: false,
			fragmentCandidate: false,
			viewTransitionCandidate: false,
		};

		if ( ! rawHref || rawHref === '#' ) {
			result.capability = 'local_ui';
			result.reason = 'empty_or_hash_href';
			return result;
		}

		if ( link && ( link.getAttribute( 'aria-disabled' ) === 'true' || link.closest( '[aria-disabled="true"], [data-no-dsa-navigation]' ) ) ) {
			result.capability = 'disabled';
			result.reason = 'aria_or_dsa_disabled';
			return result;
		}

		if ( link && link.hasAttribute( 'download' ) ) {
			result.capability = 'asset_download';
			result.reason = 'download_attribute';
			return result;
		}

		if ( /^(mailto:|tel:|sms:)/i.test( rawHref ) ) {
			result.capability = 'external_protocol';
			result.reason = 'contact_protocol';
			return result;
		}

		if ( link && link.target && link.target !== '_self' ) {
			result.capability = 'external_window';
			result.reason = 'target_window';
			return result;
		}

		if ( link && isPopupOrDrawerTrigger( link ) ) {
			result.capability = 'local_ui';
			result.reason = 'popup_or_drawer_trigger';
			return result;
		}

		let url;
		try {
			url = new URL( href, window.location.href );
		} catch ( error ) {
			result.capability = 'invalid';
			result.reason = 'url_parse_failed';
			return result;
		}

		result.href = url.href;
		result.sameOrigin = url.origin === window.location.origin;

		if ( ! result.sameOrigin ) {
			result.capability = 'external';
			result.reason = 'cross_origin';
			return result;
		}

		if ( routeHasUnsafeQueryParam( url ) ) {
			result.capability = 'unsafe_mutation';
			result.reason = 'unsafe_query_param';
			return result;
		}

		if ( routeHasAssetExtension( url ) ) {
			result.capability = 'asset';
			result.reason = 'asset_extension';
			return result;
		}

		if ( url.pathname === window.location.pathname && url.search === window.location.search && url.hash ) {
			result.capability = 'same_page_anchor';
			result.reason = 'same_document_hash';
			return result;
		}

		if ( routeMatchesPolicy( 'protectedPatterns', url ) || isProtectedCommerceNavigationUrl( url ) ) {
			result.capability = 'protected_full_document';
			result.reason = 'protected_route';
			result.mode = 'full';
			result.protected = true;
			return result;
		}

		if ( routeMatchesPolicy( 'excludedPatterns', url ) || isExcludedNavigationUrl( url ) ) {
			result.capability = 'excluded_full_document';
			result.reason = 'excluded_route';
			result.mode = 'full';
			return result;
		}

		result.capability = 'safe_full_document';
		result.reason = 'same_origin_safe_route';
		result.mode = 'full';
		result.transitionCapable = shouldShowNavigationSurface();
		result.viewTransitionCandidate = Boolean( routePolicy.viewTransitions && routePolicy.viewTransitions.enabled );
		result.fragmentCandidate = routeMatchesCandidatePolicy( 'fragment', url );
		return result;
	}

	function crossDocumentViewTransitionsAvailable() {
		return Boolean(
			routePolicy.viewTransitions
			&& routePolicy.viewTransitions.enabled
			&& 'onpageswap' in window
			&& 'onpagereveal' in window
			&& !( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches )
		);
	}

	function isApprovedEditorialTransitionLink( link, capability ) {
		return crossDocumentViewTransitionsAvailable() && isApprovedEditorialNavigationLink( link, capability );
	}

	function isApprovedEditorialNavigationLink( link, capability ) {
		if ( ! routePolicy.viewTransitions || ! routePolicy.viewTransitions.currentDocumentEditorial ) return false;
		if ( ! link || ! capability || capability.capability !== 'safe_full_document' || capability.protected || link.closest( '[data-dsa-surface]' ) ) return false;
		if ( link.closest( '[data-no-dsa-view-transition], .product, .type-product, .woocommerce, form' ) ) return false;
		return link.dataset.dsaViewTransition === 'editorial'
			|| Boolean( link.closest( '.menu-item-object-post, .menu-item-object-page, .menu-item-object-category, .menu-item-object-post_tag, article.type-post, .wp-block-post' ) );
	}

	function prepareCrossDocumentViewTransition( link, capability ) {
		try {
			if ( ! isApprovedEditorialTransitionLink( link, capability ) ) {
				window.sessionStorage.removeItem( viewTransitionIntentKey );
				return false;
			}
			window.sessionStorage.setItem( viewTransitionIntentKey, JSON.stringify( {
				version: 1,
				from: window.location.href,
				to: capability.href,
				createdAt: Date.now(),
			} ) );
			return true;
		} catch ( error ) {
			return false;
		}
	}

	function readCrossDocumentViewTransitionIntent() {
		try {
			const intent = JSON.parse( window.sessionStorage.getItem( viewTransitionIntentKey ) || 'null' );
			if ( ! intent || intent.version !== 1 || Date.now() - Number( intent.createdAt || 0 ) > 15000 ) return null;
			return intent;
		} catch ( error ) {
			return null;
		}
	}

	function setupCrossDocumentViewTransitions() {
		if ( ! routePolicy.viewTransitions || ! routePolicy.viewTransitions.enabled ) return;

		window.addEventListener( 'pageswap', function ( event ) {
			if ( ! event.viewTransition ) return;
			const intent = readCrossDocumentViewTransitionIntent();
			const target = event.activation && event.activation.entry ? event.activation.entry.url : '';
			if ( ! intent || ! target || trimTrailingSlash( intent.to ) !== trimTrailingSlash( target ) ) {
				event.viewTransition.skipTransition();
			}
		} );

		window.addEventListener( 'pagereveal', function ( event ) {
			if ( ! event.viewTransition ) return;
			const intent = readCrossDocumentViewTransitionIntent();
			const approved = intent
				&& trimTrailingSlash( intent.to ) === trimTrailingSlash( window.location.href )
				&& routePolicy.viewTransitions.currentDocumentEditorial
				&& !( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
			if ( ! approved ) event.viewTransition.skipTransition();
			try { window.sessionStorage.removeItem( viewTransitionIntentKey ); } catch ( error ) {}
		} );
	}

	function loadReconciliationModule() {
		if ( ! reconciliationConfig.moduleUrl || ! reconciliationConfig.endpoint ) {
			return Promise.reject( new Error( 'Editorial reconciliation is unavailable.' ) );
		}

		if ( ! reconciliationModulePromise ) {
			reconciliationModulePromise = import( reconciliationConfig.moduleUrl );
		}

		return reconciliationModulePromise;
	}

	function inspectEditorialReconciliation( targetUrl ) {
		return loadReconciliationModule().then( function ( module ) {
			if ( ! module || typeof module.inspect !== 'function' ) throw new Error( 'Editorial reconciliation module is invalid.' );
			return module.inspect( targetUrl, {
				endpoint: reconciliationConfig.endpoint,
				nonce: data.nonce || '',
				applyEnabled: Boolean( reconciliationConfig.applyEnabled ),
			} );
		} ).catch( function ( error ) {
			reconciliationModulePromise = null;
			throw error;
		} );
	}

	function recordFragmentSafetyOutcome( outcome ) {
		try {
			const ledger = JSON.parse( window.sessionStorage.getItem( fragmentSafetyLedgerKey ) || '[]' );
			ledger.push( Object.assign( { at: new Date().toISOString() }, outcome || {} ) );
			window.sessionStorage.setItem( fragmentSafetyLedgerKey, JSON.stringify( ledger.slice( -40 ) ) );
		} catch ( error ) {}
	}

	function readFragmentSafetyOutcomes() {
		try {
			return JSON.parse( window.sessionStorage.getItem( fragmentSafetyLedgerKey ) || '[]' );
		} catch ( error ) {
			return [];
		}
	}

	function runEditorialSafetyMatrix() {
		const targets = Array.from( document.querySelectorAll( 'a[href]' ) ).map( function ( link ) {
			const capability = classifyNavigationTarget( link );
			return isApprovedEditorialNavigationLink( link, capability ) ? capability.href : '';
		} ).filter( Boolean );

		return loadReconciliationModule().then( function ( module ) {
			if ( ! module || typeof module.runSafetyMatrix !== 'function' ) throw new Error( 'S16 safety matrix is unavailable.' );
			return module.runSafetyMatrix( targets, {
				endpoint: reconciliationConfig.endpoint,
				nonce: data.nonce || '',
			} );
		} ).then( function ( report ) {
			recordFragmentSafetyOutcome( { type: 'matrix', report: report } );
			return report;
		} );
	}

	function navigateWithEditorialMorph( targetUrl ) {
		if ( navigationInFlight || ! reconciliationConfig.applyEnabled ) return;
		navigationInFlight = true;
		try { window.sessionStorage.removeItem( viewTransitionIntentKey ); } catch ( error ) {}
		if ( shouldShowNavigationSurface() ) showLoader( targetUrl );
		window.dispatchEvent( new CustomEvent( 'surface:navigation:start', { detail: { url: targetUrl, mode: 'morph' } } ) );

		loadReconciliationModule().then( function ( module ) {
			if ( ! module || typeof module.inspect !== 'function' || typeof module.apply !== 'function' ) throw new Error( 'Controlled morph module is invalid.' );
			return module.inspect( targetUrl, {
				endpoint: reconciliationConfig.endpoint,
				nonce: data.nonce || '',
				applyEnabled: true,
			} ).then( function ( plan ) {
				if ( ! plan.morphReady ) throw new Error( plan.blockers && plan.blockers.length ? plan.blockers.join( ', ' ) : 'Morph plan was not approved.' );
				return module.apply( plan );
			} );
		} ).then( function ( plan ) {
			morphDocumentActive = true;
			recordFragmentSafetyOutcome( { type: 'navigation', path: new URL( plan.target ).pathname, scenario: plan.safety ? plan.safety.scenario : 'unknown', outcome: 'morphed', invariantFailures: plan.safety ? plan.safety.invariantFailures || [] : [] } );
			navigationInFlight = false;
			hideLoader( true );
			scheduleCartRefreshSequence( [ 80, 420 ] );
			window.dispatchEvent( new CustomEvent( 'surface:navigation:complete', { detail: { url: plan.target, mode: 'morph' } } ) );
		} ).catch( function ( error ) {
			recordFragmentSafetyOutcome( { type: 'navigation', path: new URL( targetUrl, window.location.href ).pathname, outcome: 'full_document_fallback', reason: error && error.message ? error.message : 'unknown_error' } );
			debugLog( 'controlled morph fallback', { url: targetUrl, message: error && error.message ? error.message : '' } );
			navigationInFlight = false;
			hideLoader( true );
			navigateWithFullPageLoader( targetUrl );
		} );
	}

	window.DSA.runEditorialSafetyMatrix = runEditorialSafetyMatrix;
	window.DSA.getEditorialSafetyOutcomes = readFragmentSafetyOutcomes;
	window.DSA.clearEditorialSafetyOutcomes = function () {
		try { window.sessionStorage.removeItem( fragmentSafetyLedgerKey ); } catch ( error ) {}
	};

	function routePolicyList( key ) {
		return Array.isArray( routePolicy[ key ] ) ? routePolicy[ key ] : [];
	}

	function routeMatchesPolicy( key, url ) {
		return routePolicyList( key ).some( function ( pattern ) {
			return routePatternMatches( String( pattern || '' ), url );
		} );
	}

	function routeMatchesCandidatePolicy( key, url ) {
		const candidates = routePolicy.candidatePatterns && Array.isArray( routePolicy.candidatePatterns[ key ] )
			? routePolicy.candidatePatterns[ key ]
			: [];
		return candidates.some( function ( pattern ) {
			return routePatternMatches( String( pattern || '' ), url );
		} );
	}

	function routeHasUnsafeQueryParam( url ) {
		const params = routePolicyList( 'unsafeQueryParams' );
		return params.length
			? params.some( function ( param ) { return url.searchParams.has( param ); } )
			: isUnsafeCommerceMutationUrl( url );
	}

	function routeHasAssetExtension( url ) {
		const extensions = routePolicyList( 'assetExtensions' );
		const match = url.pathname.match( /\.([a-z0-9]+)$/i );
		return Boolean( match && extensions.indexOf( match[1].toLowerCase() ) !== -1 );
	}

	function onNavigationClick( event ) {
		if ( event.target && event.target.closest && event.target.closest( '[data-kiwe-notifications], [data-dsa-notifications], [data-dsa-permission="notifications"], [data-dsa-notify-product]' ) ) {
			return;
		}

		const link = closestEventTarget( event, 'a[href]' );

		if ( ! link ) {
			return;
		}

		if ( event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		const capability = publishRouteCapability( link, 'click' );
		const morphCandidate = isApprovedEditorialNavigationLink( link, capability );
		const nativeEditorialTransition = prepareCrossDocumentViewTransition( link, capability );

		if ( shouldOpenCheckoutSurfaceLink( link ) ) {
			event.preventDefault();
			event.stopPropagation();
			openCheckoutSurface();
			return;
		}

		if ( shouldUseProtectedFullPageSurface( link ) ) {
			event.preventDefault();
			navigateWithFullPageLoader( link.href );
			return;
		}

		if ( ! isEligibleLink( link ) ) {
			return;
		}

		if ( morphCandidate && reconciliationConfig.applyEnabled ) {
			event.preventDefault();
			navigateWithEditorialMorph( link.href );
			return;
		}

		if ( nativeEditorialTransition ) {
			return;
		}

		event.preventDefault();

		if ( data.navigation && data.navigation.enabled ) {
			navigateWithFullPageLoader( link.href );
			return;
		}

		navigateWithFullPageLoader( link.href );
	}

	function shouldShowNavigationSurface() {
		return surfaceTriggerEnabled( 'safe_link_transition', visual.show_on_navigation ) && visual.show_on_page_out;
	}

	function shouldUseProtectedFullPageSurface( link ) {
		if ( ! shouldShowNavigationSurface() || isProtectedFlowActive() || navigationInFlight ) {
			return false;
		}

		if ( link.target && link.target !== '_self' ) {
			return false;
		}

		if ( link.closest( '[data-dsa-surface]' ) || link.closest( 'form' ) || link.hasAttribute( 'download' ) || link.dataset.dsaFullNavigation !== undefined ) {
			return false;
		}

		if ( isPopupOrDrawerTrigger( link ) ) {
			return false;
		}

		if ( link.getAttribute( 'aria-disabled' ) === 'true' || link.closest( '[aria-disabled="true"], [data-no-dsa-navigation]' ) ) {
			return false;
		}

		if ( /^(mailto:|tel:|sms:)/i.test( link.getAttribute( 'href' ) || '' ) ) {
			return false;
		}

		const url = new URL( link.href, window.location.href );

		if ( url.origin !== window.location.origin ) {
			return false;
		}

		if ( isUnsafeCommerceMutationUrl( url ) ) {
			return false;
		}

		return isProtectedCommerceNavigationUrl( url );
	}

	function isEligibleLink( link ) {
		const url = new URL( link.href, window.location.href );

		if ( isProtectedFlowActive() ) {
			return false;
		}

		if ( navigationInFlight || url.origin !== window.location.origin ) {
			return false;
		}

		if ( link.target && link.target !== '_self' ) {
			return false;
		}

		if ( link.closest( '[data-dsa-surface]' ) || link.closest( 'form' ) || link.hasAttribute( 'download' ) || link.dataset.dsaFullNavigation !== undefined ) {
			return false;
		}

		if ( isPopupOrDrawerTrigger( link ) ) {
			return false;
		}

		if ( link.getAttribute( 'aria-disabled' ) === 'true' || link.closest( '[aria-disabled="true"], [data-no-dsa-navigation]' ) ) {
			return false;
		}

		if ( /^(mailto:|tel:|sms:)/i.test( link.getAttribute( 'href' ) || '' ) ) {
			return false;
		}

		if ( /\.(pdf|zip|rar|7z|jpg|jpeg|png|gif|webp|mp4|mov|doc|docx|xls|xlsx)$/i.test( url.pathname ) ) {
			return false;
		}

		if ( url.pathname === window.location.pathname && url.search === window.location.search && url.hash ) {
			return false;
		}

		return ! isExcludedNavigationUrl( url );
	}

	function isPopupOrDrawerTrigger( link ) {
		if ( ! link || typeof link.closest !== 'function' ) {
			return false;
		}

		const href = String( link.getAttribute( 'href' ) || '' ).trim();

		if ( ! href || href === '#' ) {
			return true;
		}

		if ( link.getAttribute( 'aria-haspopup' ) && link.getAttribute( 'aria-haspopup' ) !== 'false' ) {
			return true;
		}

		if ( link.matches( '[data-toggle], [data-bs-toggle], [data-elementor-open-lightbox], [data-elementor-lightbox-slideshow], [data-popup], [data-modal], [data-offcanvas], [data-cart], [data-open-cart], [data-fancybox]' ) ) {
			return true;
		}

		return Boolean(
			link.closest(
				[
					'.wc-block-mini-cart',
					'.wc-block-mini-cart__button',
					'.widget_shopping_cart',
					'.elementor-menu-cart__toggle',
					'.elementor-menu-cart__container',
					'.xoo-wsc-cart-trigger',
					'.xoo-wsc-basket',
					'.xt_woofc-trigger',
					'.woofc-cart-trigger',
					'.cart-contents',
					'.site-header-cart .cart-contents',
					'.mini-cart .cart-contents',
					'.side-cart .cart-contents',
					'.offcanvas',
					'.modal',
					'[data-toggle]',
					'[data-bs-toggle]',
					'[data-elementor-open-lightbox]',
					'[data-popup]',
					'[data-modal]',
					'[data-offcanvas]',
					'[aria-haspopup="dialog"]',
					'[aria-haspopup="menu"]',
				].join( ',' )
			)
		);
	}

	function isProtectedCommerceNavigationUrl( url ) {
		return isCheckoutNavigationUrl( url )
			|| isCartNavigationUrl( url )
			|| urlPathMatchesKnownRoute( url, commerce.routes && commerce.routes.accountUrl )
			|| /\/(?:my-account|order-pay|order-received)(?:\/|$)/i.test( url.pathname );
	}

	function isCheckoutNavigationUrl( url ) {
		return urlPathMatchesKnownRoute( url, commerce.routes && commerce.routes.checkoutUrl )
			|| /\/(?:checkout|order-pay|order-received)(?:\/|$)/i.test( url.pathname );
	}

	function shouldOpenCheckoutSurfaceLink( link ) {
		if ( ! ( commerce.settings && commerce.settings.checkoutSurfaceEnabled ) || isCurrentCheckoutPage() || ! link || link.closest( 'form, [data-dsa-surface]' ) ) {
			return false;
		}

		if ( link.target && link.target !== '_self' ) {
			return false;
		}

		try {
			const url = new URL( link.href, window.location.href );
			return url.origin === window.location.origin
				&& isCheckoutNavigationUrl( url )
				&& ! /order-(?:pay|received)/i.test( url.pathname )
				&& ! isUnsafeCommerceMutationUrl( url )
				&& Number( phonekey.cart && phonekey.cart.count ? phonekey.cart.count : 0 ) > 0;
		} catch ( error ) {
			return false;
		}
	}

	function isCartNavigationUrl( url ) {
		return urlPathMatchesKnownRoute( url, commerce.routes && commerce.routes.cartUrl )
			|| /\/cart(?:\/|$)/i.test( url.pathname );
	}

	function isAccountNavigationUrl( url ) {
		return urlPathMatchesKnownRoute( url, commerce.routes && commerce.routes.accountUrl )
			|| /\/(?:my-account|order-pay|order-received)(?:\/|$)/i.test( url.pathname );
	}

	function isShopNavigationUrl( url ) {
		return urlPathMatchesKnownRoute( url, commerce.routes && commerce.routes.shopUrl )
			|| /\/shop(?:\/|$)/i.test( url.pathname );
	}

	function urlPathMatchesKnownRoute( url, knownUrl ) {
		if ( ! knownUrl ) {
			return false;
		}

		try {
			const known = new URL( knownUrl, window.location.href );
			return known.origin === url.origin && trimTrailingSlash( known.pathname ) === trimTrailingSlash( url.pathname );
		} catch ( error ) {
			return false;
		}
	}

	function trimTrailingSlash( value ) {
		return String( value || '/' ).replace( /\/+$/, '' ) || '/';
	}

	function isUnsafeCommerceMutationUrl( url ) {
		return url.searchParams.has( 'add-to-cart' )
			|| url.searchParams.has( 'wc-ajax' )
			|| url.searchParams.has( 'bricks' );
	}

	function isExcludedNavigationUrl( url ) {
		if ( ! url ) {
			return true;
		}

		if ( /\/(?:cart|checkout|my-account)(?:\/|$)/i.test( url.pathname ) ) {
			return true;
		}

		if ( /\/(?:order-pay|order-received)(?:\/|$)/i.test( url.pathname ) ) {
			return true;
		}

		if ( isUnsafeCommerceMutationUrl( url ) ) {
			return true;
		}

		const triggerExcluded = Array.isArray( surfaceTriggers.routeExclusions )
			? surfaceTriggers.routeExclusions
			: [];
		const manifestExcluded = data.manifest && data.manifest.routes && Array.isArray( data.manifest.routes.excluded )
			? data.manifest.routes.excluded
			: [];
		const excluded = triggerExcluded.length ? triggerExcluded : manifestExcluded;

		return excluded.some( function ( pattern ) {
			return routePatternMatches( String( pattern || '' ), url );
		} );
	}

	function routePatternMatches( pattern, url ) {
		if ( ! pattern ) {
			return false;
		}

		const target = url.pathname + url.search;
		const pathname = url.pathname;

		if ( pattern.indexOf( '?' ) === -1 && pattern.charAt( 0 ) === '/' ) {
			return wildcardMatches( pattern, pathname );
		}

		return wildcardMatches( pattern, target );
	}

	function wildcardMatches( pattern, value ) {
		const escaped = pattern.replace( /[.+?^${}()|[\]\\]/g, '\\$&' ).replace( /\*/g, '.*' );
		return new RegExp( '^' + escaped + '$', 'i' ).test( value );
	}

	function navigateWithFullPageLoader( url ) {
		if ( navigationInFlight ) {
			return;
		}

		navigationInFlight = true;
		pendingFullNavigationUrl = url;
		startNavigationWatchdog( url, 'full' );

		if ( shouldShowNavigationSurface() ) {
			showLoader( url );
		}

		window.dispatchEvent( new CustomEvent( 'surface:navigation:start', { detail: { url: url, mode: 'full' } } ) );

		const delay = visual.loader_type === 'none'
			? Number( visual.artificial_delay_ms ) || 0
			: Math.max(
				Number( visual.artificial_delay_ms ) || 0,
				Number( visual.min_loader_ms ) || 0
			);

		pendingFullNavigationTimer = window.setTimeout( function () {
			pendingFullNavigationTimer = 0;
			commitPendingFullNavigation();
		}, delay );
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, Math.max( 0, ms ) );
		} );
	}

	window.setTimeout( openScheduledGameSurface, 180 );
} )();
