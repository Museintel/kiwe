const requestCache = new Map();
const searchMemory = { query: '', scope: '', prefix: '', sort: 'latest' };
const bricksBridgeMemory = new Map();
let bricksReconcileBound = false;

function escapeHtml( value ) {
	return String( value == null ? '' : value ).replace( /[&<>'"]/g, function ( character ) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ character ];
	} );
}

function safeHighlight( value ) {
	const template = document.createElement( 'template' );
	template.innerHTML = String( value || '' );
	template.content.querySelectorAll( '*' ).forEach( function ( node ) {
		if ( node.tagName !== 'MARK' ) {
			node.replaceWith( document.createTextNode( node.textContent || '' ) );
		}
	} );
	return template.innerHTML;
}

function resultCard( item, root ) {
	const meta = [ item.price || '', item.weight || '' ].filter( Boolean ).join( ' / ' );
	const stockBadge = item.stockBadge && item.stockBadge.label ? item.stockBadge : null;
	const config = root._dsaSearchConfig || {};
	const quickAdd = item.type === 'product' && item.addable !== false && config.productAddEnabled !== false;
	return [
		'<article class="dsa-search-result-card dsa-search-result-card--' + escapeHtml( item.type || 'item' ) + '">',
		'<a class="dsa-search-result" href="' + escapeHtml( item.url || '#' ) + '" data-dsa-full-navigation>',
		item.image ? '<img src="' + escapeHtml( item.image ) + '" alt="" loading="lazy" decoding="async">' : '<span class="dsa-search-result__placeholder" aria-hidden="true"></span>',
		'<span class="dsa-search-result__body">',
		'<small>' + escapeHtml( item.typeLabel || item.type || '' ) + '</small>',
		'<strong>' + safeHighlight( item.titleHtml || escapeHtml( item.title || '' ) ) + '</strong>',
		item.excerptHtml ? '<span>' + safeHighlight( item.excerptHtml ) + '</span>' : '',
		meta ? '<em>' + escapeHtml( meta ) + '</em>' : '',
		stockBadge ? '<em class="dsa-search-result__stock is-' + escapeHtml( stockBadge.type || 'alert' ) + '">' + escapeHtml( stockBadge.label ) + '</em>' : '',
		'</span>',
		'<span class="dsa-search-result__arrow" aria-hidden="true">&rarr;</span>',
		'</a>',
		quickAdd ? '<button class="dsa-search-result__add" type="button" data-dsa-search-add="' + escapeHtml( item.id || '' ) + '" aria-label="Add ' + escapeHtml( item.title || 'product' ) + ' to cart">+</button>' : '',
		'</article>',
	].join( '' );
}

function resultGroup( label, items, root ) {
	const family = String( label || 'items' ).toLowerCase().replace( /[^a-z0-9_-]/g, '' );
	const more = Boolean( root._dsaSearchPayload?.pagination?.[ family ] );
	if ( ! Array.isArray( items ) || ( ! items.length && ! more ) ) return '';
	return '<section class="dsa-search-group dsa-search-group--' + escapeHtml( family ) + '"><h3>' + escapeHtml( label ) + '</h3><div class="dsa-search-group__items">' + items.map( function ( item ) { return resultCard( item, root ); } ).join( '' ) + '</div>' + ( more ? '<button type="button" class="dsa-search-more" data-dsa-search-more="' + family + '">Show more ' + family + '</button>' : '' ) + '</section>';
}

function activeFilter( root ) {
	return String( root._dsaSearchScope || root.dataset.dsaSearchFilter || 'all' );
}

function activeSort( root ) {
	return root._dsaSearchSort === 'popular' ? 'popular' : 'latest';
}

function renderSorts( root, data ) {
	const container = root.querySelector( '[data-dsa-search-sort-options]' );
	if ( ! container ) return;
	const config = root._dsaSearchConfig || {};
	const popularAvailable = typeof data.popularAvailable === 'boolean' ? data.popularAvailable : Boolean( config.popularEnabled );
	const selected = activeSort( root );
	const options = [ { id: 'latest', label: 'Latest' } ];
	if ( popularAvailable ) options.push( { id: 'popular', label: 'Popular' } );
	if ( selected === 'popular' && ! popularAvailable ) {
		root._dsaSearchSort = 'latest';
		root.dataset.dsaSearchSort = 'latest';
	}
	container.innerHTML = options.map( function ( option ) {
		const active = option.id === activeSort( root );
		return '<button type="button" class="dsa-search-filter dsa-search-sort' + ( active ? ' is-active' : '' ) + '" data-dsa-search-sort="' + option.id + '" aria-pressed="' + ( active ? 'true' : 'false' ) + '">' + option.label + ( active ? '<span aria-hidden="true">&times;</span>' : '' ) + '</button>';
	} ).join( '' );
}

function renderFilters( root, data ) {
	const filters = root.querySelector( '[data-dsa-search-filters]' );
	if ( ! filters ) return;
	const config = root._dsaSearchConfig || {};
	const context = config.context || {};
	const options = [];
	const families = context.families || {};
	if ( context.hasCommerce && families.products !== false ) options.push( { id: 'products', label: 'Products' } );
	if ( families.posts !== false ) options.push( { id: 'posts', label: 'Posts' } );
	if ( families.authors !== false ) options.push( { id: 'authors', label: 'Authors' } );
	if ( families.categories === true ) options.push( { id: 'categories', label: 'Categories' } );
	let selected = activeFilter( root );
	if ( selected !== 'all' && ! options.some( function ( option ) { return option.id === selected; } ) ) {
		selected = 'all';
		root._dsaSearchScope = 'all';
		root.dataset.dsaSearchFilter = 'all';
	}
	filters.innerHTML = options.map( function ( option ) {
		const active = option.id === selected;
		return '<button type="button" class="dsa-search-filter' + ( active ? ' is-active' : '' ) + '" data-dsa-search-filter="' + option.id + '" aria-pressed="' + ( active ? 'true' : 'false' ) + '">' + escapeHtml( option.label ) + ( active ? '<span aria-hidden="true">&times;</span>' : '' ) + '</button>';
	} ).join( '' );
}

function renderAlphabet( root, data ) {
	const alphabet = root.querySelector( '[data-dsa-search-alphabet]' );
	if ( ! alphabet ) return;
	const config = root._dsaSearchConfig || {};
	const tokens = config.alphabetEnabled && ! data.query && Array.isArray( data.alphabet ) ? data.alphabet : [];
	const prefix = String( data.prefix || root._dsaSearchPrefix || '' );
	root._dsaSearchPrefix = prefix;
	alphabet.hidden = ! tokens.length && ! prefix;
	alphabet.innerHTML = ( prefix ? '<button type="button" class="dsa-search-prefix is-active" data-dsa-search-prefix-back aria-label="Go back one alphabet level">' + escapeHtml( prefix ) + '<span aria-hidden="true">&times;</span></button>' : '' ) + tokens.map( function ( token ) {
		return '<button type="button" class="dsa-search-prefix" data-dsa-search-prefix="' + escapeHtml( token ) + '">' + escapeHtml( token ) + '</button>';
	} ).join( '' );
	bindAlphabetButtons( root );
}

function bindAlphabetButtons( root ) {
	root.querySelectorAll( '[data-dsa-search-prefix], [data-dsa-search-prefix-back]' ).forEach( function ( button ) {
		if ( button.dataset.dsaSearchPrefixBound === '1' ) return;
		button.dataset.dsaSearchPrefixBound = '1';
		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			const input = root.querySelector( '[data-dsa-search-input]' );
			const clear = root.querySelector( '[data-dsa-search-clear]' );
			root._dsaSearchPrefix = button.hasAttribute( 'data-dsa-search-prefix-back' )
				? String( root._dsaSearchPrefix || '' ).slice( 0, -1 )
				: String( button.dataset.dsaSearchPrefix || '' );
			if ( input ) input.value = '';
			if ( clear ) clear.hidden = true;
			requestResults( root, root._dsaSearchConfig || {}, '' );
		} );
	} );
}

function renderResults( root, data ) {
	const results = root.querySelector( '[data-dsa-search-results]' );
	const status = root.querySelector( '[data-dsa-search-status]' );
	const query = String( data.query || '' );
	const total = Number( data.total ) || 0;

	if ( ! results ) {
		return;
	}

	root._dsaSearchPayload = data;
	renderFilters( root, data );
	renderSorts( root, data );
	renderAlphabet( root, data );
	const filter = activeFilter( root );
	const visibleTotal = filter === 'products' ? ( data.products || [] ).length : ( filter === 'posts' ? ( data.posts || [] ).length : ( filter === 'authors' ? ( data.authors || [] ).length : ( filter === 'categories' ? ( data.categories || [] ).length : total ) ) );
	const canContinue = Object.entries( data.pagination || {} ).some( function ( [ family, cursor ] ) { return cursor && ( filter === 'all' || filter === family ); } );
	results.innerHTML = visibleTotal || canContinue
		? ( filter === 'all' || filter === 'products' ? resultGroup( 'Products', data.products, root ) : '' ) + ( filter === 'all' || filter === 'posts' ? resultGroup( 'Posts', data.posts, root ) : '' ) + ( filter === 'all' || filter === 'authors' ? resultGroup( 'Authors', data.authors, root ) : '' ) + ( filter === 'all' || filter === 'categories' ? resultGroup( 'Categories', data.categories, root ) : '' )
		: '<div class="dsa-search-empty"><strong>No matches yet.</strong><span>Try another word.</span></div>';
	if ( status ) status.textContent = ( query ? visibleTotal + ( visibleTotal === 1 ? ' result shown' : ' results shown' ) + ' for “' + query + '” · ' : '' ) + ( activeSort( root ) === 'popular' ? 'Popular' : 'Latest' );
	results.classList.remove( 'is-ghost' );
	root.classList.remove( 'is-loading' );
	bindQuickAddButtons( root );
	closeAnchoredResults( root );
}

function bindQuickAddButtons( root ) {
	root.querySelectorAll( '[data-dsa-search-add]' ).forEach( function ( addButton ) {
		if ( addButton.dataset.dsaSearchAddBound === '1' ) return;
		addButton.dataset.dsaSearchAddBound = '1';
		addButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			const cart = window.DSA && window.DSA.cart;
			if ( ! cart || typeof cart.addProduct !== 'function' || addButton.disabled ) return;
			addButton.disabled = true;
			addButton.classList.add( 'is-loading' );
			cart.addProduct( Number( addButton.dataset.dsaSearchAdd ) || 0, 'dsa_search' ).then( function () {
				addButton.textContent = '✓';
				addButton.classList.add( 'is-added' );
			} ).catch( function () {
				addButton.disabled = false;
				addButton.textContent = '+';
			} ).finally( function () {
				addButton.classList.remove( 'is-loading' );
			} );
		} );
	} );
}

function openAnchoredResults( root, requested ) {
	// Search has one canonical in-Surface layout. A browser top-layer popover
	// obscures contextual filters and produces a second, list-like geometry.
	return Boolean( root && requested );
}

function closeAnchoredResults( root ) {
	const results = root.querySelector( '[data-dsa-search-results]' );
	if ( results && typeof results.hidePopover === 'function' ) {
		try {
			if ( results.matches( ':popover-open' ) ) {
				results.hidePopover();
			}
		} catch ( error ) {}
	}
	if ( results ) {
		results.removeAttribute( 'popover' );
	}
}

function adaptiveDelay( query ) {
	const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
	if ( connection && ( connection.saveData || /2g/.test( connection.effectiveType || '' ) ) ) {
		return 360;
	}
	return query.length > 5 ? 120 : ( query.length > 2 ? 180 : 360 );
}

function bricksQueryTrail( targetQueryId ) {
	return Array.from( document.querySelectorAll( '[data-query-element-id]' ) ).find( function ( node ) {
		return String( node.dataset.queryElementId || '' ) === String( targetQueryId );
	} ) || null;
}

function bricksQueryScope( targetQueryId ) {
	const trail = bricksQueryTrail( targetQueryId );
	if ( ! trail ) return 'all';
	try {
		const vars = JSON.parse( trail.getAttribute( 'data-query-vars' ) || '{}' );
		const postType = Array.isArray( vars.post_type ) ? vars.post_type : [ vars.post_type ];
		if ( postType.includes( 'product' ) ) return 'products';
		if ( postType.includes( 'post' ) ) return 'posts';
	} catch ( error ) {}
	return 'all';
}

function matchingBricksSearchInstances( scope ) {
	const bricksData = window.bricksData || {};
	const instances = Object.values( bricksData.filterInstances || {} );
	const explicitMarkers = Array.from( document.querySelectorAll( '[data-dsa-search-bridge="1"]' ) );
	const legacyMarkers = Array.from( document.querySelectorAll( '[data-dsa-live-search]' ) );
	const explicit = explicitMarkers.length > 0 || legacyMarkers.length > 0;

	return instances.filter( function ( instance ) {
		const targetId = instance && instance.targetQueryId;
		const queryInstance = targetId && bricksData.queryLoopInstances ? bricksData.queryLoopInstances[ targetId ] : null;
		const input = instance && instance.filterElement;
		if ( ! targetId || ! queryInstance || instance.filterType !== 'search' || ! input ) return false;
		const trail = bricksQueryTrail( targetId );
		const explicitlyMatched = explicitMarkers.some( function ( marker ) {
			return marker.contains( input ) || String( marker.dataset.dsaSearchQuery || '' ) === String( targetId );
		} );
		const legacyMatched = legacyMarkers.some( function ( marker ) {
			return marker.contains( input ) || ( trail && marker.contains( trail ) );
		} );
		if ( explicit && ! explicitlyMatched && ! legacyMatched ) return false;
		const queryScope = bricksQueryScope( targetId );
		return scope === 'all' || queryScope === 'all' || queryScope === scope;
	} );
}

function applyBricksSearchInstance( instance, query, force ) {
	const bricksData = window.bricksData || {};
	const bricksUtils = window.bricksUtils || {};
	const targetId = instance && instance.targetQueryId;
	const queryInstance = targetId && bricksData.queryLoopInstances ? bricksData.queryLoopInstances[ targetId ] : null;
	const input = instance && instance.filterElement;
	if ( ! targetId || ! queryInstance || ! input ) return;
	if ( ! force && input.value === query && instance.currentValue === query ) return;

	input.value = query;
	instance.currentValue = query;
	if ( typeof bricksUtils.updateSearchFilterIconVisibility === 'function' ) bricksUtils.updateSearchFilterIconVisibility( instance, query );
	if ( typeof bricksUtils.updateLiveSearchTerm === 'function' && typeof bricksUtils.updateSelectedFilters === 'function' && typeof bricksUtils.fetchFilterResults === 'function' ) {
		// Match Bricks' native Filter Search sequence. Without aborting an older
		// request, its unfiltered response can arrive after this filtered result.
		if ( typeof bricksUtils.maybeAbortXhr === 'function' ) bricksUtils.maybeAbortXhr( targetId );
		bricksUtils.updateLiveSearchTerm( targetId, query );
		const disableUrlParams = Boolean( queryInstance.disableUrlParams );
		try {
			queryInstance.disableUrlParams = true;
			bricksUtils.updateSelectedFilters( targetId, instance );
		} finally {
			queryInstance.disableUrlParams = disableUrlParams;
		}
		bricksUtils.fetchFilterResults( targetId );
		return;
	}
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

function bricksFilterIsSelected( targetId, instance ) {
	const selected = ( window.bricksData || {} ).selectedFilters || {};
	const target = selected[ targetId ] || {};
	return Boolean( instance && instance.filterId && Object.values( target ).includes( instance.filterId ) );
}

function persistBricksHistorySnapshot( targetId ) {
	const bricksData = window.bricksData || {};
	if ( ! window.history || typeof window.history.replaceState !== 'function' || ( window.history.state && window.history.state.kiweSurface ) ) return;
	const instancesValue = {};
	const queryIds = window.bricksUtils && typeof window.bricksUtils.currentPageTargetQueryIds === 'function'
		? window.bricksUtils.currentPageTargetQueryIds()
		: Object.keys( bricksData.queryLoopInstances || {} );
	queryIds.forEach( function ( queryId ) {
		instancesValue[ queryId ] = {};
		Object.values( bricksData.filterInstances || {} ).forEach( function ( instance ) {
			if ( instance && String( instance.targetQueryId || '' ) === String( queryId ) && instance.filterId ) {
				instancesValue[ queryId ][ instance.filterId ] = instance.currentValue;
			}
		} );
	} );
	const state = window.history.state && typeof window.history.state === 'object'
		? Object.assign( {}, window.history.state )
		: {};
	state.isBricksFilter = true;
	state.targetQueryId = String( targetId || '' );
	state.selectedFilters = bricksData.selectedFilters || {};
	state.instancesValue = instancesValue;
	try {
		window.history.replaceState( state, '', window.location.href );
	} catch ( error ) {}
}

function reconcileBricksTarget( targetId, force ) {
	const desired = bricksBridgeMemory.get( String( targetId || '' ) );
	if ( ! desired || ! desired.query ) return;
	const instances = matchingBricksSearchInstances( desired.scope ).filter( function ( instance ) {
		return String( instance.targetQueryId || '' ) === String( targetId || '' );
	} );
	const stale = instances.find( function ( instance ) {
		return ! instance.filterElement
			|| instance.filterElement.value !== desired.query
			|| instance.currentValue !== desired.query
			|| ! bricksFilterIsSelected( String( targetId || '' ), instance );
	} );
	const instance = stale || instances[ 0 ];
	if ( instance && ( stale || force ) ) {
		applyBricksSearchInstance( instance, desired.query, true );
	}
	if ( instance ) persistBricksHistorySnapshot( targetId );
}

function bindBricksReconciliation() {
	if ( bricksReconcileBound ) return;
	bricksReconcileBound = true;
	document.addEventListener( 'bricks/ajax/query_result/displayed', function ( event ) {
		const targetId = String( ( event.detail || {} ).queryId || '' );
		window.setTimeout( function () { reconcileBricksTarget( targetId ); }, 0 );
	} );
	window.addEventListener( 'surface:overlay:close', function () {
		// When no synthetic history entry exists, the destination is already active.
		if ( ! window.history.state || ! window.history.state.kiweSurface ) {
			bricksBridgeMemory.forEach( function ( value, targetId ) { reconcileBricksTarget( targetId ); } );
		}
	} );
	window.addEventListener( 'surface:history:released', function () {
		// The synthetic entry has now been consumed without leaking a false page
		// navigation to Bricks. Persist the desired filter into the base entry.
		bricksBridgeMemory.forEach( function ( value, targetId ) { reconcileBricksTarget( targetId ); } );
	} );
}

function bridgeBricksLiveSearch( query, scope ) {
	const config = arguments.length > 2 ? arguments[ 2 ] : {};
	if ( config.bricksBridgeEnabled === false ) return;
	bindBricksReconciliation();
	matchingBricksSearchInstances( scope ).forEach( function ( instance ) {
		const targetId = String( instance.targetQueryId || '' );
		bricksBridgeMemory.set( targetId, { query: query, scope: scope } );
		applyBricksSearchInstance( instance, query, false );
	} );
}

function cacheSet( key, value ) {
	if ( requestCache.size >= 24 ) {
		requestCache.delete( requestCache.keys().next().value );
	}
	requestCache.set( key, { value: value, expires: Date.now() + 60000 } );
}

function searchCacheKey( scope, sort, prefix, query, config ) {
	return String( config.endpoint || '' ) + '|' + String( config.limit || 6 ) + '|' + scope + '|' + sort + '|' + prefix + '|' + query.toLocaleLowerCase();
}

function editorialInitialPayload( config, scope ) {
	const context = config.context || {};
	const families = context.families || {};
	const boot = window.DSA_DATA || {};
	const posts = boot.links && Array.isArray( boot.links.posts ) ? boot.links.posts : [];
	if ( context.hasCommerce || families.posts === false || ! [ 'all', 'posts' ].includes( scope ) || ! posts.length ) {
		return null;
	}

	const recent = posts.slice( 0, Number( config.limit ) || 6 ).map( function ( post, index ) {
		return {
			id: Number( post.id ) || index + 1,
			type: 'post',
			typeLabel: 'Post',
			title: String( post.title || '' ),
			titleHtml: escapeHtml( post.title || '' ),
			excerpt: '',
			excerptHtml: '',
			url: String( post.url || '' ),
			image: String( post.image || '' ),
		};
	} ).filter( function ( post ) { return post.title && post.url; } );

	return recent.length ? {
		query: '',
		scope: scope,
		prefix: '',
		sort: 'latest',
		families: Object.assign( { products: false, posts: true, authors: false, categories: false }, families ),
		alphabetEnabled: false,
		alphabet: [],
		hasCommerce: false,
		products: [],
		posts: recent,
		authors: [],
		categories: [],
		total: recent.length,
		cached: true,
		source: 'boot',
		popularAvailable: Boolean( config.popularEnabled ),
	} : null;
}

async function requestResults( root, config, query, continuation = null ) {
	const scope = continuation ? continuation.family : activeFilter( root );
	const prefix = String( root._dsaSearchPrefix || '' );
	const sort = activeSort( root );
	const cursor = continuation ? String( root._dsaSearchPayload?.pagination?.[ scope ] || '' ) : '';
	if ( continuation && ! cursor ) return;
	if ( root._dsaSearchController ) root._dsaSearchController.abort();
	const controller = new AbortController();
	root._dsaSearchController = controller;
	const serial = root._dsaSearchSerial = ( root._dsaSearchSerial || 0 ) + 1;
	searchMemory.query = query;
	searchMemory.scope = activeFilter( root );
	searchMemory.prefix = prefix;
	searchMemory.sort = sort;
	const key = searchCacheKey( scope, sort, prefix, query, config );
	if ( ! continuation ) bridgeBricksLiveSearch( query || prefix, scope, config );
	const remembered = requestCache.get( key );
	if ( ! continuation && remembered && remembered.expires > Date.now() ) {
		renderResults( root, remembered.value );
		return;
	}
	const results = root.querySelector( '[data-dsa-search-results]' );
	const status = root.querySelector( '[data-dsa-search-status]' );
	if ( results && results.childElementCount ) {
		results.classList.add( 'is-ghost' );
	}
	if ( status ) {
		status.textContent = continuation ? 'Loading more results…' : ( query ? 'Searching…' : 'Loading recent results…' );
	}
	root.classList.add( 'is-loading' );

	const url = new URL( config.endpoint, window.location.href );
	url.searchParams.set( 'q', query );
	url.searchParams.set( 'limit', String( config.limit || 6 ) );
	url.searchParams.set( 'scope', scope );
	url.searchParams.set( 'prefix', prefix );
	url.searchParams.set( 'sort', sort );
	if ( cursor ) url.searchParams.set( 'cursor', cursor );
	root.querySelectorAll( '[data-dsa-search-more]' ).forEach( function ( button ) { button.disabled = true; } );
	try {
		const response = await fetch( url.toString(), {
			method: 'GET',
			credentials: 'same-origin',
			cache: root._dsaSearchRefresh ? 'reload' : 'default',
			headers: { 'Accept': 'application/json' },
			signal: controller.signal,
		} );
		if ( ! response.ok ) {
			const error = new Error( response.status === 409 ? 'Results changed. Refresh the results to continue.' : 'Search is unavailable right now.' );
			error.refreshSearch = response.status === 409;
			throw error;
		}
		const payload = await response.json();
		root._dsaSearchRefresh = false;
		if ( controller.signal.aborted || serial !== root._dsaSearchSerial ) return;
		let rendered = payload;
		if ( continuation ) {
			const previous = root._dsaSearchPayload || {};
			const ids = new Set();
			const items = [ ...( previous[ scope ] || [] ), ...( payload[ scope ] || [] ) ].filter( function ( item ) { if ( ids.has( item.id ) ) return false; ids.add( item.id ); return true; } );
			rendered = { ...previous, [ scope ]: items, pagination: { ...previous.pagination, [ scope ]: payload.pagination?.[ scope ] || '' } };
			rendered.total = [ 'posts', 'products', 'authors', 'categories' ].reduce( function ( total, family ) { return total + ( rendered[ family ] || [] ).length; }, 0 );
		} else cacheSet( key, payload );
		if ( window.scheduler && typeof window.scheduler.yield === 'function' ) {
			await window.scheduler.yield();
		}
		if ( root.isConnected && ! controller.signal.aborted && serial === root._dsaSearchSerial ) {
			renderResults( root, rendered );
		}
	} catch ( error ) {
		if ( error.name === 'AbortError' || controller.signal.aborted || serial !== root._dsaSearchSerial ) {
			return;
		}
		root.classList.remove( 'is-loading' );
		if ( status ) {
			status.textContent = error.message || 'Search is unavailable right now.';
		}
		if ( results ) {
			results.classList.remove( 'is-ghost' );
			if ( error.refreshSearch ) results.querySelectorAll( '[data-dsa-search-more]' ).forEach( function ( button ) { delete button.dataset.dsaSearchMore; button.dataset.dsaSearchRefresh = '1'; button.textContent = 'Refresh results'; } );
		}
	} finally {
		if ( serial === root._dsaSearchSerial ) root.querySelectorAll( '[data-dsa-search-more], [data-dsa-search-refresh]' ).forEach( function ( button ) { button.disabled = false; } );
	}
}

export function mount( root, config ) {
	if ( ! root || root.dataset.dsaSearchMounted === '1' ) {
		return;
	}
	root.dataset.dsaSearchMounted = '1';
	root._dsaSearchConfig = config || {};
	const initialScope = String( ( config.context || {} ).scope || 'all' );
	root._dsaSearchScope = searchMemory.scope || ( [ 'products', 'posts', 'authors', 'categories' ].includes( initialScope ) ? initialScope : 'all' );
	root.dataset.dsaSearchFilter = root._dsaSearchScope;
	root._dsaSearchPrefix = searchMemory.prefix || '';
	root._dsaSearchSort = searchMemory.sort === 'popular' ? 'popular' : 'latest';
	root.dataset.dsaSearchSort = root._dsaSearchSort;
	const form = root.querySelector( '[data-dsa-search-form]' );
	const input = root.querySelector( '[data-dsa-search-input]' );
	const clear = root.querySelector( '[data-dsa-search-clear]' );
	let timer = 0;

	if ( ! form || ! input ) {
		return;
	}
	input.value = searchMemory.query || '';
	if ( clear ) clear.hidden = ! input.value;
	if ( initialScope === 'products' ) {
		input.placeholder = 'Search products';
		input.setAttribute( 'aria-label', 'Search products' );
	} else if ( initialScope === 'posts' ) {
		input.placeholder = 'Search posts';
		input.setAttribute( 'aria-label', 'Search posts' );
	} else if ( initialScope === 'authors' ) {
		input.placeholder = 'Search authors';
		input.setAttribute( 'aria-label', 'Search authors' );
	} else if ( initialScope === 'categories' ) {
		input.placeholder = 'Search categories';
		input.setAttribute( 'aria-label', 'Search categories' );
	}

	root.addEventListener( 'click', function ( event ) {
		const moreButton = event.target.closest( '[data-dsa-search-more]' );
		if ( moreButton && ! moreButton.disabled ) {
			event.preventDefault(); event.stopPropagation();
			requestResults( root, config, input.value.trim(), { family: moreButton.dataset.dsaSearchMore } );
			return;
		}
		if ( event.target.closest( '[data-dsa-search-refresh]' ) ) {
			event.preventDefault(); event.stopPropagation();
			requestCache.clear(); root._dsaSearchRefresh = true; requestResults( root, config, input.value.trim() ); return;
		}
		const button = event.target.closest( '[data-dsa-search-filter]' );
		if ( button ) {
			event.preventDefault();
			event.stopPropagation();
			const requested = button.dataset.dsaSearchFilter || 'all';
			root._dsaSearchScope = requested;
			root.dataset.dsaSearchFilter = root._dsaSearchScope;
			root._dsaSearchPrefix = '';
			requestResults( root, config, input.value.trim() );
			return;
		}
		const sortButton = event.target.closest( '[data-dsa-search-sort]' );
		if ( sortButton ) {
			event.preventDefault();
			event.stopPropagation();
			const requestedSort = sortButton.dataset.dsaSearchSort === 'popular' ? 'popular' : 'latest';
			root._dsaSearchSort = requestedSort === activeSort( root ) && requestedSort === 'popular' ? 'latest' : requestedSort;
			root.dataset.dsaSearchSort = root._dsaSearchSort;
			requestResults( root, config, input.value.trim() );
			return;
		}

	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		window.clearTimeout( timer );
		requestResults( root, config, input.value.trim() );
	} );
	input.addEventListener( 'input', function () {
		const query = input.value.trim();
		root._dsaSearchSerial = ( root._dsaSearchSerial || 0 ) + 1;
		if ( root._dsaSearchController ) root._dsaSearchController.abort();
		root._dsaSearchPrefix = '';
		if ( clear ) {
			clear.hidden = ! query;
		}
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			requestResults( root, config, query );
		}, adaptiveDelay( query ) );
	} );
	input.addEventListener( 'focus', function () {
		openAnchoredResults( root, input.value.trim().length > 1 );
	} );
	if ( clear ) {
		clear.addEventListener( 'click', function () {
			input.value = '';
			clear.hidden = true;
			closeAnchoredResults( root );
			input.focus();
			requestResults( root, config, '' );
		} );
	}

	const initialQuery = input.value.trim();
	const initialPayload = ! initialQuery && ! root._dsaSearchPrefix ? editorialInitialPayload( config, root._dsaSearchScope ) : null;
	if ( initialPayload ) {
		cacheSet( searchCacheKey( root._dsaSearchScope, 'latest', '', '', config ), initialPayload );
		renderResults( root, initialPayload );
	} else {
		requestResults( root, config, initialQuery );
	}
	window.requestAnimationFrame( function () {
		if ( root.isConnected && shouldAutofocusSearchInput( root, config ) ) {
			input.focus( { preventScroll: true } );
		}
	} );
}

function shouldAutofocusSearchInput( root, config ) {
	if ( config && config.autofocus === true ) return true;
	if ( config && config.autofocus === false ) return false;
	const coarsePointer = typeof window.matchMedia === 'function' && window.matchMedia( '(hover: none), (pointer: coarse)' ).matches;
	const narrowViewport = Math.min(
		window.innerWidth || 1024,
		document.documentElement ? document.documentElement.clientWidth || 1024 : 1024
	) <= 640;
	if ( coarsePointer || narrowViewport ) return false;
	const sheetSurface = root && root.closest && root.closest( '.dsa-theme-sheet, [data-dsa-surface].dsa-theme-sheet' );
	return ! ( sheetSurface && ( coarsePointer || narrowViewport ) );
}
