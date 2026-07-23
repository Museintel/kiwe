const fs = require( 'fs' );
const path = require( 'path' );

( async function () {
	const root = path.resolve( __dirname, '..', '..' );
	const read = ( file ) => fs.readFileSync( path.join( root, file ), 'utf8' );
	const core = read( 'wp-content/mu-plugins/dsa/assets/js/surface.js' );
	const linksSource = read( 'wp-content/mu-plugins/dsa/assets/js/modules/links-panel.js' );
	const aiSource = read( 'wp-content/mu-plugins/dsa/assets/js/modules/ai-panel.js' );
	const css = read( 'wp-content/mu-plugins/dsa/assets/css/surface.css' );
	const adminCss = read( 'wp-content/mu-plugins/dsa/assets/css/admin.css' );
	const assets = read( 'wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php' );
	const manifest = read( 'wp-content/mu-plugins/dsa/includes/Runtime/Package_Manifest.php' );
	const checks = [];
	const check = ( name, pass, detail = '' ) => checks.push( { name, pass: Boolean( pass ), detail } );

	check( 'Links and AI advertise first-use presentation modules', assets.includes( "'links' =>" ) && assets.includes( 'links-panel.js' ) && assets.includes( "'ai' =>" ) && assets.includes( 'ai-panel.js' ) );
	check( 'Runtime manifest protects both extracted modules', manifest.includes( 'assets/js/modules/links-panel.js' ) && manifest.includes( 'assets/js/modules/ai-panel.js' ) );
	check( 'Links view and editor delegate to one imported adapter', core.includes( "presentationModules.get( 'links' )" ) && core.includes( 'adapter.renderEditor' ) && core.includes( 'adapter.render( linksPresentationPayload' ) );
	check( 'AI panel, inbox, and report delegate after import', core.includes( "presentationModules.get( 'ai' )" ) && core.includes( 'adapter.renderPanel' ) && core.includes( 'adapter.renderInbox' ) && core.includes( 'adapter.renderReport' ) );
	check( 'Heavy Links helpers left the persistent shell', !/function render(?:LinksAdminBar|SocialLink|SocialPreview|ShopLink|LinksCartButton|SocialField|CategoryOptions|PostCard|PostsSection|Review|HealthItem)\s*\(/.test( core ) );
	check( 'Heavy AI helpers left the persistent shell', !/function renderAi(?:Skeleton|Section|Item|InsightCard)\s*\(/.test( core ) );
	check( 'Persistent shell is below the Version 6 raw budget', Buffer.byteLength( core ) < 400000, `${ Buffer.byteLength( core ) } bytes` );
	check( 'Extracted modules are browser-global-free and mutation-free', [ linksSource, aiSource ].every( ( source ) => !/\b(?:fetch|dsaPost|XMLHttpRequest|localStorage|sessionStorage)\s*\(/.test( source ) && !source.includes( 'window.' ) && !source.includes( 'document.' ) ) );
	check( 'AI queue, action authority, and popout arbitration remain in core', core.includes( 'aiNotificationQueue' ) && core.includes( 'bindAiInsightActions' ) && core.includes( 'showAiPopout' ) );
	check( 'Links persistence and upload authority remain in core', core.includes( "dsaPost( '/links'" ) && core.includes( "dsaUpload( '/links/logo'" ) );
	check( 'Relocated Profile context actions retain click ownership', core.includes( "dockContext.dataset.dsaContext === 'profile'" ) && /dockContext\.addEventListener\(\s*'click'[\s\S]*?handleAccountContextClick\( event \)/.test( core ) );
	check( 'Bottom Sheet grabber centers within the actual panel', /\.dsa-theme-sheet\.dsa-sheet-position-bottom \.dsa-sheet-grabber\s*\{[\s\S]*?width:\s*100%;[\s\S]*?margin-inline:\s*0;[\s\S]*?\}/.test( css ) && !/\.dsa-theme-sheet\.dsa-sheet-position-bottom \.dsa-sheet-grabber\s*\{[\s\S]*?width:\s*100dvw/.test( css ) );
	check( 'Sheet sizing consumes measured visual viewport variables', core.includes( "'--dsa-visual-viewport-height'" ) && css.includes( 'var(--dsa-visual-viewport-height, 100dvh)' ) );
	check( 'Inactive vertical Navigation bar permits page scroll gestures', /html:not\(\.dsa-overlay-active\) \.dsa-surface\[data-dsa-ui-contract="2"\]\[data-dsa-dock-presentation="navbar"\]\[data-dsa-dock-orientation="vertical"\] \.dsa-phonekey-dock \[data-dsa-module\]\s*\{[\s\S]*?touch-action:\s*pan-y;[\s\S]*?\}/.test( css ) );
	check( 'Reduced motion suppresses unread AI launcher ring and badge pulse', /@media\s*\(\s*prefers-reduced-motion:\s*reduce\s*\)\s*\{[\s\S]*?\.dsa-ai-launcher\.has-unread,\s*[\s\S]*?\.dsa-ai-launcher\.has-unread \.dsa-dock__badge\s*\{[\s\S]*?animation:\s*none;[\s\S]*?\}/.test( css ) );
	check( 'Panel headings use readable UI text instead of active state color', [ '.dsa-search-panel > h2', '.dsa-saved-panel > h2' ].every( ( selector ) => new RegExp( selector.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\s*\\{[\\s\\S]*?color:\\s*var\\(--dsa-ui-text\\);' ).test( css ) ) && /\.dsa-notification-panel > h2,\s*\.dsa-ios-install-panel > h2\s*\{[\s\S]*?color:\s*var\(--dsa-ui-text\);/.test( css ) );
	check( 'Classic radial glows are tokenized', css.includes( 'radial-gradient(circle at 18% 12%, color-mix(in srgb, var(--dsa-hover-color) 24%, transparent)' ) && css.includes( 'radial-gradient(circle at 82% 72%, color-mix(in srgb, var(--dsa-active-color) 16%, transparent)' ) && !css.includes( 'radial-gradient(circle at 18% 12%, rgba(36,198,161' ) );
	check( 'Surface semantic colors are token backed', css.includes( '--dsa-success-color: var(--kiwe-color-success, #12a66a);' ) && css.includes( '--dsa-danger-color: var(--kiwe-color-danger, #d83a52);' ) && /\.dsa-cart-item__price,\s*[\s\S]*?\.dsa-mini-item strong\s*\{[\s\S]*?color:\s*var\(--dsa-success-color\);/.test( css ) && /\.dsa-logout-button\s*\{[\s\S]*?var\(--dsa-danger-color\)/.test( css ) && /\.dsa-auth-primary\s*\{[\s\S]*?color:\s*color-mix\(in srgb, var\(--dsa-hover-color\) 88%, var\(--dsa-ui-text\)\);/.test( css ) );
	check( 'Loader title consumes muted UI token', /\.dsa-loader__title\s*\{[\s\S]*?color:\s*var\(--dsa-ui-muted\);[\s\S]*?\}/.test( css ) );
	check( 'Surface font weights use standard browser weights', !/font-weight:\s*(?:750|850|950);/.test( css ) && !/font:\s*950\b/.test( css ) );
	check( 'Surface control font stacks use Kiwe font token', !/font:\s*[0-9]+[^;]*Inter,ui-sans-serif/.test( css ) && /--dsa-runtime-token-\d+:\s*800 18px\/1\.2 var\(--kiwe-font-ui\);/.test( css ) && /--dsa-runtime-token-\d+:\s*900 13px\/1 var\(--kiwe-font-ui\);/.test( css ) && /font:\s*var\(--dsa-runtime-token-\d+\);/.test( css ) );
	check( 'Admin accent stays WordPress-native and tokenized', adminCss.includes( '--dsa-admin-accent: var(--wp-admin-theme-color, #2271b1);' ) && ( adminCss.match( /#2271b1/g ) || [] ).length === 1 && adminCss.includes( 'border-color: var(--dsa-admin-accent);' ) );
	check( 'AI notification dismissibility is centralized', core.includes( 'function isAiNotificationDismissible' ) && core.includes( 'function normalizeAiNotificationDismissible' ) && core.includes( 'normalizeAiNotificationDismissible( saved )' ) && !core.includes( 'if ( saved.notification ) {\n\t\t\tsaved.dismissible = true;' ) );
	check( 'Dismissible AI popouts share swipe behavior', core.includes( 'const popoutDismissible = isAiNotificationDismissible( insight );' ) && core.includes( 'bindAiNotificationSwipe( card )' ) && css.includes( '.dsa-ai-popout[data-dsa-ai-dismissible]' ) && /\.dsa-ai-insight\.is-swiping,\s*[\s\S]*?\.dsa-ai-popout\.is-swiping\s*\{[\s\S]*?transition:\s*none;/.test( css ) );
	check( 'Cart first-add confetti has commerce-safe target', core.includes( 'function cartConfettiTarget()' ) && core.includes( "overlayRoot.querySelector( '[data-dsa-cart-panel], [data-dsa-checkout-panel]' )" ) && core.includes( "blastConfetti( cartConfettiTarget(), 'cart' )" ) && core.includes( "variant === 'cart' ? cartConfettiTarget()" ) );

	const links = await import( 'data:text/javascript;base64,' + Buffer.from( linksSource ).toString( 'base64' ) );
	const linksHtml = links.render( { label: 'Links', hub: { siteName: '<Store>', score: 96, socials: [ { id: 'youtube', label: 'YouTube', url: 'https://example.com' } ], health: [ { label: 'Secure', active: true } ] } } );
	check( 'Links fixture preserves canonical semantics and escaping', linksHtml.includes( 'dsa-links-panel' ) && linksHtml.includes( 'dsa-social-link--youtube' ) && linksHtml.includes( '&lt;' ) && !linksHtml.includes( '<Store>' ) );
	const editorHtml = links.renderEditor( { hub: { siteName: 'Store', canEdit: true, editor: { socials: [ { id: 'x', label: 'X', url: '' } ], categories: [] } } } );
	check( 'Links editor fixture preserves form hooks', editorHtml.includes( 'data-dsa-links-form' ) && editorHtml.includes( 'data-dsa-social-id="x"' ) && editorHtml.includes( 'data-dsa-links-logo' ) );

	const ai = await import( 'data:text/javascript;base64,' + Buffer.from( aiSource ).toString( 'base64' ) );
	const aiHtml = ai.renderPanel( { label: 'AI', unread: 1, open: false, items: [ { id: 'safe', title: '<Ready>', message: 'Test', action: 'open', actionLabel: 'Open' } ] } );
	check( 'AI fixture preserves inbox hooks, collapsed state, and escaping', aiHtml.includes( 'data-dsa-ai-panel' ) && aiHtml.includes( 'data-dsa-ai-insight-action="safe"' ) && aiHtml.includes( 'is-collapsed' ) && aiHtml.includes( '&lt;Ready&gt;' ) );

	for ( const item of checks ) console.log( `${ item.pass ? 'PASS' : 'FAIL' } ${ item.name }${ item.detail ? ` :: ${ item.detail }` : '' }` );
	const failed = checks.filter( ( item ) => !item.pass );
	console.log( `\n${ checks.length - failed.length }/${ checks.length } RC9B presentation lazy contracts passed.` );
	if ( failed.length ) process.exit( 1 );
} )().catch( function ( error ) { console.error( error ); process.exit( 1 ); } );
