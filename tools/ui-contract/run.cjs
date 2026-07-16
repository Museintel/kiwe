'use strict';

const fs = require( 'fs' );
const http = require( 'http' );
const path = require( 'path' );

function loadPlaywright() {
	try {
		return require( 'playwright' );
	} catch ( error ) {
		const fallback = process.env.DSA_PLAYWRIGHT_PATH || path.join( process.env.USERPROFILE || '', '.cache', 'codex-runtimes', 'codex-primary-runtime', 'dependencies', 'node', 'node_modules', 'playwright' );
		return require( fallback );
	}
}

const { chromium } = loadPlaywright();
const repo = path.resolve( __dirname, '..', '..' );
const artifacts = path.join( __dirname, 'artifacts' );
const matrix = [
	{ name: 'phone-320', width: 320, height: 640 },
	{ name: 'phone-390', width: 390, height: 844 },
	{ name: 'resized-desktop', width: 491, height: 776 },
	{ name: 'tablet', width: 768, height: 720 },
	{ name: 'desktop', width: 1366, height: 900 },
];
const screens = [ 'menu', 'profile', 'cart', 'links', 'search' ];
const themes = [ 'light', 'dark' ];

function installedBrowser() {
	const candidates = [
		process.env.DSA_BROWSER_PATH,
		path.join( process.env.PROGRAMFILES || '', 'Google', 'Chrome', 'Application', 'chrome.exe' ),
		path.join( process.env[ 'PROGRAMFILES(X86)' ] || '', 'Google', 'Chrome', 'Application', 'chrome.exe' ),
		path.join( process.env.LOCALAPPDATA || '', 'Google', 'Chrome', 'Application', 'chrome.exe' ),
		path.join( process.env.PROGRAMFILES || '', 'Microsoft', 'Edge', 'Application', 'msedge.exe' ),
		path.join( process.env[ 'PROGRAMFILES(X86)' ] || '', 'Microsoft', 'Edge', 'Application', 'msedge.exe' ),
	].filter( Boolean );
	return candidates.find( function ( candidate ) { return fs.existsSync( candidate ); } ) || '';
}

function contentType( file ) {
	if ( file.endsWith( '.html' ) ) return 'text/html; charset=utf-8';
	if ( file.endsWith( '.js' ) ) return 'application/javascript; charset=utf-8';
	if ( file.endsWith( '.css' ) ) return 'text/css; charset=utf-8';
	if ( file.endsWith( '.svg' ) ) return 'image/svg+xml';
	return 'application/octet-stream';
}

function server() {
	return http.createServer( function ( request, response ) {
		const url = new URL( request.url, 'http://127.0.0.1' );
		const relative = decodeURIComponent( url.pathname ).replace( /^\/+/, '' );
		const file = path.resolve( repo, relative || 'tools/ui-contract/fixture.html' );
		if ( ! file.startsWith( repo ) || ! fs.existsSync( file ) || fs.statSync( file ).isDirectory() ) {
			response.writeHead( 404 );
			response.end( 'Not found' );
			return;
		}
		response.writeHead( 200, { 'Content-Type': contentType( file ), 'Cache-Control': 'no-store' } );
		fs.createReadStream( file ).pipe( response );
	} );
}

function expectedLayout( width ) {
	return width < 540 ? 'narrow' : ( width < 820 ? 'compact' : 'wide' );
}

( async function run() {
	fs.mkdirSync( artifacts, { recursive: true } );
	const host = server();
	await new Promise( function ( resolve ) { host.listen( 0, '127.0.0.1', resolve ); } );
	const port = host.address().port;
	const executablePath = installedBrowser();
	const browser = await chromium.launch( Object.assign( { headless: true }, executablePath ? { executablePath: executablePath } : {} ) );
	const failures = [];
	const results = [];

	for ( const viewport of matrix ) {
		for ( const screen of screens ) {
			for ( const theme of themes ) {
				for ( const orientation of ( viewport.width >= 768 ? [ 'horizontal', 'vertical' ] : [ 'horizontal' ] ) ) {
				const page = await browser.newPage( { viewport: { width: viewport.width, height: viewport.height }, deviceScaleFactor: 1 } );
				const layout = expectedLayout( viewport.width );
				const url = `http://127.0.0.1:${ port }/tools/ui-contract/fixture.html?screen=${ screen }&theme=${ theme }&layout=${ layout }&orientation=${ orientation }`;
				await page.goto( url, { waitUntil: 'networkidle' } );
				const checks = await page.evaluate( function () {
					const dock = document.querySelector( '.dsa-phonekey-dock' ).getBoundingClientRect();
					const context = document.querySelector( '[data-dsa-dock-context]' ).getBoundingClientRect();
					const panel = document.querySelector( '.dsa-overlay-root > .dsa-panel' ).getBoundingClientRect();
					const surface = document.querySelector( '[data-dsa-surface]' );
					const buttons = Array.from( document.querySelectorAll( 'button, a[href], input, select, textarea' ) );
					const unnamed = buttons.filter( function ( node ) {
						return ! String( node.getAttribute( 'aria-label' ) || node.getAttribute( 'title' ) || node.textContent || node.getAttribute( 'placeholder' ) || '' ).trim();
					} ).length;
					const ids = Array.from( document.querySelectorAll( '[id]' ) ).map( function ( node ) { return node.id; } );
					const duplicateIds = ids.filter( function ( id, index ) { return ids.indexOf( id ) !== index; } );
					const imagesWithoutAlt = document.querySelectorAll( 'img:not([alt])' ).length;
					const fieldsWithoutNames = Array.from( document.querySelectorAll( 'input, select, textarea' ) ).filter( function ( node ) {
						return ! String( node.getAttribute( 'aria-label' ) || node.getAttribute( 'aria-labelledby' ) || node.getAttribute( 'placeholder' ) || '' ).trim() && ! ( node.id && document.querySelector( 'label[for="' + CSS.escape( node.id ) + '"]' ) );
					} ).length;
					return {
						dock: { left: dock.left, right: dock.right, top: dock.top, bottom: dock.bottom, width: dock.width },
						context: { left: context.left, right: context.right, top: context.top, bottom: context.bottom, width: context.width, policy: document.querySelector( '[data-dsa-dock-context]' ).dataset.dsaContextWidth },
						panel: { left: panel.left, right: panel.right, top: panel.top, bottom: panel.bottom },
						viewport: { width: window.innerWidth, height: window.innerHeight },
						layout: document.querySelector( '[data-dsa-surface]' ).dataset.dsaLayout,
						orientation: surface.dataset.dsaDockOrientation,
						unnamed: unnamed,
						imagesWithoutAlt: imagesWithoutAlt,
						fieldsWithoutNames: fieldsWithoutNames,
						duplicateIds: duplicateIds,
						dialogs: document.querySelectorAll( '[role="dialog"]' ).length,
						dockTargets: Array.from( document.querySelectorAll( '.dsa-dock__button' ) ).map( function ( node ) { const rect = node.getBoundingClientRect(); return Math.min( rect.width, rect.height ); } ),
						searchContract: document.querySelector( '.dsa-search-panel' ) ? {
							activeFilters: document.querySelectorAll( '.dsa-search-filter.is-active' ).length,
							alphabetVisible: Boolean( document.querySelector( '.dsa-search-panel__alphabet:not([hidden])' ) ),
							cards: document.querySelectorAll( '.dsa-search-result-card' ).length,
							quickAdds: document.querySelectorAll( '.dsa-search-result__add' ).length,
						} : null,
					};
				} );

				const id = `${ viewport.name }-${ orientation }-${ screen }-${ theme }`;
				const assertions = {
					layout: checks.layout === layout,
					viewportOverflow: checks.panel.left >= -1 && checks.panel.right <= checks.viewport.width + 1 && checks.dock.left >= -1 && checks.dock.right <= checks.viewport.width + 1,
					dockContextAlignment: checks.context.policy !== 'dock' || ( orientation === 'horizontal'
						? Math.abs( checks.context.left - checks.dock.left ) <= 1.5 && Math.abs( checks.context.width - checks.dock.width ) <= 1.5
						: Math.abs( checks.context.left - checks.panel.left ) <= 1.5 && Math.abs( checks.context.width - ( checks.panel.right - checks.panel.left ) ) <= 1.5 ),
					contextPlacement: orientation === 'horizontal'
						? checks.context.bottom <= checks.dock.top + 1 && checks.dock.top - checks.context.bottom <= 24
						: checks.context.top >= checks.panel.bottom - 1 || checks.context.bottom <= checks.viewport.height + 1,
					accessibleNames: checks.unnamed === 0,
					accessibleMediaAndFields: checks.imagesWithoutAlt === 0 && checks.fieldsWithoutNames === 0,
					uniqueIds: checks.duplicateIds.length === 0,
					oneDialog: checks.dialogs === 1,
					touchTargets: checks.dockTargets.every( function ( size ) { return size >= 30; } ),
					searchContract: ! checks.searchContract || ( checks.searchContract.activeFilters === 1 && checks.searchContract.alphabetVisible && checks.searchContract.cards >= 2 && checks.searchContract.quickAdds >= 2 ),
				};
				const failed = Object.keys( assertions ).filter( function ( key ) { return ! assertions[ key ]; } );
				if ( failed.length ) failures.push( { id: id, failed: failed, geometry: checks } );
				results.push( { id: id, assertions: assertions, geometry: checks } );
				await page.screenshot( { path: path.join( artifacts, id + '.png' ), animations: 'disabled' } );
				await page.close();
				}
			}
		}
	}

	{
		const page = await browser.newPage( { viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 } );
		await page.goto( `http://127.0.0.1:${ port }/tools/ui-contract/fixture.html?screen=search&theme=light&layout=narrow&orientation=horizontal`, { waitUntil: 'networkidle' } );
		const bridge = await page.evaluate( async function () {
			const nativeInput = document.createElement( 'input' );
			nativeInput.type = 'search';
			document.body.appendChild( nativeInput );
			const trail = document.createElement( 'div' );
			trail.dataset.queryElementId = 'tgbzzq';
			trail.dataset.queryVars = JSON.stringify( { post_type: 'product' } );
			document.body.appendChild( trail );
			const instance = { filterId: 'dungms', targetQueryId: 'tgbzzq', filterType: 'search', filterElement: nativeInput, currentValue: '', originalValue: '' };
			const calls = { selected: 0, fetched: 0, aborted: 0, added: 0 };
			window.bricksData = { filterInstances: { dungms: instance }, queryLoopInstances: { tgbzzq: { isLiveSearch: false, disableUrlParams: false } }, selectedFilters: {} };
			window.bricksUtils = {
				updateLiveSearchTerm: function () {},
				updateSearchFilterIconVisibility: function () {},
				currentPageTargetQueryIds: function () { return [ 'tgbzzq' ]; },
				maybeAbortXhr: function () { calls.aborted += 1; },
				updateSelectedFilters: function ( targetId, filter ) {
					calls.selected += 1;
					window.bricksData.selectedFilters[ targetId ] = { 0: filter.filterId };
				},
				fetchFilterResults: function () { calls.fetched += 1; },
			};
			window.DSA = { cart: { addProduct: function () { calls.added += 1; return Promise.resolve( {} ); } } };
			window.fetch = function ( requestUrl ) {
				const request = new URL( String( requestUrl ), window.location.href );
				const prefix = request.searchParams.get( 'prefix' ) || '';
				const query = request.searchParams.get( 'q' ) || '';
				return Promise.resolve( { ok: true, json: function () { return Promise.resolve( {
					query: query,
					scope: 'products',
					prefix: prefix,
					alphabet: query ? [] : ( prefix ? [ prefix + 'a' ] : [ 'L', 'M', 'V' ] ),
					products: [ { id: 517, type: 'product', typeLabel: 'Product', title: 'Lactase Enzyme', titleHtml: 'Lactase Enzyme', excerpt: 'Product information', excerptHtml: 'Product information', url: '#product', price: '$11.00', addable: true } ],
					posts: [], authors: [], total: 1,
				} ); } } );
			};
			const module = await import( '/wp-content/mu-plugins/dsa/assets/js/search.js?contract=1' );
			const root = document.querySelector( '[data-dsa-search-panel]' );
			module.mount( root, { endpoint: '/search', context: { scope: 'products', hasCommerce: true, families: { products: true, posts: true, authors: true } }, alphabetEnabled: true, productAddEnabled: true, bricksBridgeEnabled: true, limit: 6 } );
			await new Promise( function ( resolve ) { setTimeout( resolve, 30 ); } );
			const alphabetVisible = ! root.querySelector( '[data-dsa-search-alphabet]' ).hidden;
			root.querySelector( '[data-dsa-search-prefix="L"]' )?.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
			await new Promise( function ( resolve ) { setTimeout( resolve, 30 ); } );
			const selectedPrefix = root._dsaSearchPrefix || '';
			const nextPrefix = root.querySelector( '[data-dsa-search-prefix="La"]' )?.textContent || '';
			const input = root.querySelector( '[data-dsa-search-input]' );
			input.value = 'lactase';
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			await new Promise( function ( resolve ) { setTimeout( resolve, 320 ); } );
			const selectedBefore = root.querySelector( '.dsa-search-filter.is-active' )?.dataset.dsaSearchFilter || '';
			window.bricksData.selectedFilters.tgbzzq = {};
			document.dispatchEvent( new CustomEvent( 'bricks/ajax/query_result/displayed', { detail: { queryId: 'tgbzzq' } } ) );
			await new Promise( function ( resolve ) { setTimeout( resolve, 30 ); } );
			const repairedSelection = Object.values( window.bricksData.selectedFilters.tgbzzq || {} ).includes( 'dungms' );
			window.bricksData.selectedFilters.tgbzzq = {};
			window.dispatchEvent( new CustomEvent( 'surface:history:released' ) );
			await new Promise( function ( resolve ) { setTimeout( resolve, 30 ); } );
			const closeReconciled = Object.values( window.bricksData.selectedFilters.tgbzzq || {} ).includes( 'dungms' );
			const closeHistoryState = window.history.state || {};
			root.querySelector( '[data-dsa-search-add]' )?.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
			await new Promise( function ( resolve ) { setTimeout( resolve, 30 ); } );
			return {
				alphabetVisible: alphabetVisible,
				selectedPrefix: selectedPrefix,
				nextPrefix: nextPrefix,
				selectedBefore: selectedBefore,
				nativeValue: nativeInput.value,
				instanceValue: instance.currentValue,
				selectedCalls: calls.selected,
				fetchCalls: calls.fetched,
				abortCalls: calls.aborted,
				repairedSelection: repairedSelection,
				closeReconciled: closeReconciled,
				closeHistoryPersisted: closeHistoryState.isBricksFilter === true
					&& closeHistoryState.targetQueryId === 'tgbzzq'
					&& closeHistoryState.instancesValue
					&& closeHistoryState.instancesValue.tgbzzq
					&& closeHistoryState.instancesValue.tgbzzq.dungms === 'lactase',
				addCalls: calls.added,
				cards: root.querySelectorAll( '.dsa-search-result-card' ).length,
				quickAdds: root.querySelectorAll( '[data-dsa-search-add]' ).length,
			};
		} );
		const assertions = {
			contextPreserved: bridge.selectedBefore === 'products',
			alphabetAvailable: bridge.alphabetVisible,
			alphabetDrills: bridge.selectedPrefix === 'L' && bridge.nextPrefix === 'La',
			nonLiveBricksBridge: bridge.selectedCalls >= 1 && bridge.fetchCalls >= 1 && bridge.abortCalls >= 1,
			historyReconciled: bridge.nativeValue === 'lactase' && bridge.instanceValue === 'lactase',
			selectedFilterReconciled: bridge.repairedSelection && bridge.closeReconciled && bridge.closeHistoryPersisted,
			cardsAndQuickAdd: bridge.cards >= 1 && bridge.quickAdds >= 1 && bridge.addCalls === 1,
		};
		const failed = Object.keys( assertions ).filter( function ( key ) { return ! assertions[ key ]; } );
		if ( failed.length ) failures.push( { id: 'search-bricks-tgbzzq-contract', failed: failed, evidence: bridge } );
		results.push( { id: 'search-bricks-tgbzzq-contract', assertions: assertions, evidence: bridge } );
		await page.close();
	}

	await browser.close();
	host.close();
	fs.writeFileSync( path.join( artifacts, 'report.json' ), JSON.stringify( { generatedAt: new Date().toISOString(), results: results, failures: failures }, null, 2 ) );
	console.log( `Kiwe UI contract: ${ results.length } variants, ${ failures.length } failures.` );
	if ( failures.length ) {
		failures.forEach( function ( failure ) { console.error( failure.id + ': ' + failure.failed.join( ', ' ) ); } );
		process.exitCode = 1;
	}
}() ).catch( function ( error ) {
	console.error( error );
	process.exitCode = 1;
} );
