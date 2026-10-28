function escapeHtml( value ) {
	return String( value == null ? '' : value ).replace( /[&<>'"]/g, function ( character ) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ character ];
	} );
}

function renderTrustBadges( badges ) {
	badges = Array.isArray( badges ) ? badges : [];
	if ( ! badges.length ) return '';
	return '<div class="dsa-home-trust" aria-label="Site trust">' + badges.map( function ( badge ) {
		return '<span class="dsa-home-trust__badge' + ( badge.active ? ' is-active' : '' ) + '"><i aria-hidden="true"></i>' + escapeHtml( badge.label ) + '</span>';
	} ).join( '' ) + '</div>';
}

function renderAppBadgeIcon( icon, sprite ) {
	sprite = sprite || ( typeof window !== 'undefined' && window.DSA_DATA && window.DSA_DATA.iconSprite ) || '';
	if ( ! sprite ) return '';
	return '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><use href="' + escapeHtml( sprite + '#' + ( icon === 'play' ? 'app-android' : 'app-ios' ) ) + '"></use></svg>';
}

function screenCopy( payload, defaults ) {
	const screen = payload && payload.screenTheme && typeof payload.screenTheme === 'object' ? payload.screenTheme : {};
	return Object.assign( {}, defaults || {}, screen );
}

function renderNotificationChoice( name, item, selected ) {
	selected = Array.isArray( selected ) ? selected : [];
	const checked = selected.indexOf( item.id ) !== -1;
	return '<label class="dsa-notification-choice' + ( checked ? ' is-selected' : '' ) + '"><input type="checkbox" name="' + escapeHtml( name ) + '" value="' + escapeHtml( item.id ) + '"' + ( checked ? ' checked' : '' ) + '><span><strong>' + escapeHtml( item.label || item.id ) + '</strong><small>' + escapeHtml( item.description || '' ) + '</small></span><i aria-hidden="true"></i></label>';
}

function renderNotificationCategories( name, label, categories, selected ) {
	categories = Array.isArray( categories ) ? categories : [];
	selected = Array.isArray( selected ) ? selected : [];
	if ( ! categories.length ) return '';
	return '<details class="dsa-notification-categories"><summary>' + escapeHtml( label ) + '<span>Optional</span></summary><div>' + categories.map( function ( category ) {
		return '<label><input type="checkbox" name="' + escapeHtml( name ) + '" value="' + escapeHtml( category.id ) + '"' + ( selected.indexOf( Number( category.id ) ) !== -1 ? ' checked' : '' ) + '><span>' + escapeHtml( category.label ) + '</span></label>';
	} ).join( '' ) + '</div></details>';
}

function renderNotificationAppButtons() {
	return '<div class="dsa-initial-preloader__actions dsa-notification-platforms"><button class="dsa-app-badge" type="button" data-dsa-notification-platform="ios"><span class="dsa-app-badge__icon dsa-app-badge__icon--appstore" aria-hidden="true">' + renderAppBadgeIcon( 'appstore' ) + '</span><span class="dsa-app-badge__copy"><small>iPhone &amp; iPad</small><strong>App notifications</strong></span></button><button class="dsa-app-badge" type="button" data-dsa-notification-platform="android"><span class="dsa-app-badge__icon dsa-app-badge__icon--play" aria-hidden="true">' + renderAppBadgeIcon( 'play' ) + '</span><span class="dsa-app-badge__copy"><small>Android</small><strong>App notifications</strong></span></button></div>';
}

function notificationData( payload ) {
	payload = payload || {};
	const config = payload.config || {};
	const preferences = payload.preferences || {};
	const copy = screenCopy( payload, {
		label: payload.label || 'Personalize your Appsite',
		eyebrow: 'Notifications',
		title: 'Choose what reaches you.',
		intro: 'Your updates are always available in Kiwe. Choose optional delivery to your devices.',
		topicsLegend: 'Send these updates to me',
		topicsText: 'Optional. Choose which updates you also want delivered to you.',
		channelsLegend: 'Where should alerts reach you?',
		channelsText: 'Email, SMS and WhatsApp appear here when the website has them ready. App notifications need your permission.',
		appText: 'App notifications use the same no-store installation journey from Home.',
		submitLabel: 'Save my choices',
		emailPlaceholder: 'Email for email updates',
		phonePlaceholder: 'Phone for WhatsApp or SMS',
	} );
	return {
		payload: payload,
		copy: copy,
		config: config,
		preferences: preferences,
		topics: Array.isArray( config.topics ) ? config.topics : [],
		channels: ( Array.isArray( config.channels ) ? config.channels : [] ).filter( function ( channel ) { return channel.available; } ),
		productCategories: Array.isArray( config.productCategories ) ? config.productCategories : [],
		postCategories: Array.isArray( config.postCategories ) ? config.postCategories : [],
		user: payload.user || {},
	};
}

function renderNotificationFormParts( data ) {
	return [
		'<form data-dsa-notification-form>',
		'<fieldset class="dsa-notification-options"><legend>' + escapeHtml( data.copy.topicsLegend ) + '</legend>',
		'<p class="dsa-panel__meta">' + escapeHtml( data.copy.topicsText ) + '</p>',
		data.topics.map( function ( topic ) { return renderNotificationChoice( 'topics', topic, data.preferences.topics ); } ).join( '' ),
		'</fieldset>',
		'<fieldset class="dsa-notification-options dsa-notification-options--channels"><legend>' + escapeHtml( data.copy.channelsLegend ) + '</legend>',
		'<p class="dsa-panel__meta">' + escapeHtml( data.copy.channelsText ) + '</p>',
		data.channels.map( function ( channel ) { return renderNotificationChoice( 'channels', channel, data.preferences.channels ); } ).join( '' ),
		'</fieldset>',
		data.user.loggedIn ? '' : '<div class="dsa-notification-contact" data-dsa-notification-contact><input class="dsa-auth-field" type="email" autocomplete="email" placeholder="' + escapeHtml( data.copy.emailPlaceholder ) + '" data-dsa-notification-email><input class="dsa-auth-field" type="tel" autocomplete="tel" placeholder="' + escapeHtml( data.copy.phonePlaceholder ) + '" data-dsa-notification-phone' + ( ( data.preferences.channels || [] ).indexOf( 'sms' ) === -1 && ( data.preferences.channels || [] ).indexOf( 'whatsapp' ) === -1 ? ' hidden' : '' ) + '></div>',
		renderNotificationCategories( 'productCategories', 'Product categories', data.productCategories, data.preferences.productCategories ),
		renderNotificationCategories( 'postCategories', 'Post categories', data.postCategories, data.preferences.postCategories ),
		'<div class="dsa-notification-app-buttons" data-dsa-notification-app-buttons>',
		'<p>' + escapeHtml( data.copy.appText ) + '</p>',
		renderNotificationAppButtons(),
		'</div>',
		renderTrustBadges( data.payload.trustBadges ),
		'<div class="dsa-notification-submit"><button type="submit" class="dsa-panel__button dsa-auth-primary">' + escapeHtml( data.copy.submitLabel ) + '</button><span data-dsa-notification-message aria-live="polite"></span></div>',
		'</form>',
	].join( '' );
}

function renderLegacyNotifications( payload ) {
	const data = notificationData( payload );
	return [
		'<section class="dsa-panel dsa-notification-panel dsa-hero-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( data.copy.label ) + '" data-dsa-notification-panel data-dsa-keep-open' + ( data.payload.setupGate ? ' data-dsa-required-gate' : '' ) + '>',
		'<p class="dsa-hero-kicker">' + escapeHtml( data.copy.eyebrow ) + '</p>',
		'<h2>' + escapeHtml( data.copy.title ) + '</h2>',
		'<p class="dsa-notification-panel__intro">' + escapeHtml( data.copy.intro ) + '</p>',
		renderNotificationFormParts( data ),
		'</section>',
	].join( '' );
}

function renderPrototypeNotifications( payload ) {
	const data = notificationData( payload );
	return [
		'<section class="dsa-panel dsa-notification-panel dsa-hero-panel kiwe-notifications-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( data.copy.label ) + '" data-dsa-notification-panel data-dsa-keep-open data-dsa-notification-adapter="prototype-2027"' + ( data.payload.setupGate ? ' data-dsa-required-gate' : '' ) + '>',
		'<div class="kiwe-notifications-v2027__title"><p class="dsa-hero-kicker">' + escapeHtml( data.copy.eyebrow ) + '</p><h2>' + escapeHtml( data.copy.title ) + '</h2><p class="dsa-notification-panel__intro">' + escapeHtml( data.copy.intro ) + '</p></div>',
		renderNotificationFormParts( data ),
		'</section>',
	].join( '' );
}

const notificationAdapters = {
	legacy: renderLegacyNotifications,
	prototype: renderPrototypeNotifications,
	kiwe2027: renderPrototypeNotifications,
};

export function renderNotifications( payload ) {
	payload = payload || {};
	const requestedProfile = String( payload.visualProfile || 'legacy' );
	const adapter = notificationAdapters[ requestedProfile ] || notificationAdapters.legacy;
	return adapter( payload );
}

function renderLegacyIosInstall( payload ) {
	payload = payload || {};
	const siteName = payload.siteName || 'this Appsite';
	const copy = screenCopy( payload, {
		label: payload.label || 'iPhone and iPad App',
		eyebrow: 'For iPhone & iPad',
		title: 'Welcome, iOS users.',
		intro: 'Give ' + siteName + ' its own place on your Home Screen, then open it to sign in.',
		stepOneTitle: 'Open the Share menu',
		stepOneText: 'On your iPhone or iPad, tap the browser’s Share button. If you cannot find Add to Home Screen, open this website in Safari.',
		stepTwoTitle: 'Add to Home Screen',
		stepTwoText: 'Choose Add to Home Screen from the share sheet.',
		stepThreeTitle: 'Tap Add, then open the app',
		stepThreeText: 'Tap Add. If your iPhone offers Open as Web App, keep it on. Open the new icon and sign in; notification delivery is optional.',
		doneLabel: 'Back to website',
	} );
	return [
		'<section class="dsa-panel dsa-ios-install-panel dsa-hero-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( copy.label ) + '" data-dsa-ios-install-panel data-dsa-keep-open>',
		'<p class="dsa-hero-kicker">' + escapeHtml( copy.eyebrow ) + '</p>',
		'<h2>' + escapeHtml( copy.title ) + '</h2>',
		'<p class="dsa-ios-install-panel__lead">' + escapeHtml( copy.intro ) + '</p>',
		'<ol class="dsa-ios-steps"><li><span class="dsa-ios-share" aria-hidden="true">&#8679;</span><div><strong>' + escapeHtml( copy.stepOneTitle ) + '</strong><small>' + escapeHtml( copy.stepOneText ) + '</small></div></li><li><span aria-hidden="true">+</span><div><strong>' + escapeHtml( copy.stepTwoTitle ) + '</strong><small>' + escapeHtml( copy.stepTwoText ) + '</small></div></li><li><span aria-hidden="true">&#10003;</span><div><strong>' + escapeHtml( copy.stepThreeTitle ) + '</strong><small>' + escapeHtml( copy.stepThreeText ) + '</small></div></li></ol>',
		renderTrustBadges( payload.trustBadges ),
		'<button type="button" class="dsa-panel__button" data-dsa-ios-install-done>' + escapeHtml( copy.doneLabel ) + '</button>',
		'</section>',
	].join( '' );
}

function renderPrototypeIosInstall( payload ) {
	payload = payload || {};
	const siteName = payload.siteName || 'this Appsite';
	const copy = screenCopy( payload, {
		label: payload.label || 'iPhone and iPad App',
		eyebrow: 'For iPhone & iPad',
		title: 'Add the Appsite.',
		intro: 'Give ' + siteName + ' its own Home Screen place, then open it to sign in.',
		stepOneTitle: 'Open the Share menu',
		stepOneText: 'On your iPhone or iPad, tap the browser’s Share button. If you cannot find Add to Home Screen, open this website in Safari.',
		stepTwoTitle: 'Add to Home Screen',
		stepTwoText: 'Choose Add to Home Screen from the share sheet.',
		stepThreeTitle: 'Tap Add, then open the app',
		stepThreeText: 'Tap Add. If your iPhone offers Open as Web App, keep it on. Open the new icon and sign in; notification delivery is optional.',
		doneLabel: 'Back to website',
	} );
	return [
		'<section class="dsa-panel dsa-ios-install-panel dsa-hero-panel kiwe-ios-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( copy.label ) + '" data-dsa-ios-install-panel data-dsa-keep-open data-dsa-ios-adapter="prototype-2027">',
		'<div class="kiwe-ios-v2027__title"><p class="dsa-hero-kicker">' + escapeHtml( copy.eyebrow ) + '</p><h2>' + escapeHtml( copy.title ) + '</h2><p class="dsa-ios-install-panel__lead">' + escapeHtml( copy.intro ) + '</p></div>',
		'<ol class="dsa-ios-steps"><li><span class="dsa-ios-share" aria-hidden="true">&#8679;</span><div><strong>' + escapeHtml( copy.stepOneTitle ) + '</strong><small>' + escapeHtml( copy.stepOneText ) + '</small></div></li><li><span aria-hidden="true">+</span><div><strong>' + escapeHtml( copy.stepTwoTitle ) + '</strong><small>' + escapeHtml( copy.stepTwoText ) + '</small></div></li><li><span aria-hidden="true">&#10003;</span><div><strong>' + escapeHtml( copy.stepThreeTitle ) + '</strong><small>' + escapeHtml( copy.stepThreeText ) + '</small></div></li></ol>',
		renderTrustBadges( payload.trustBadges ),
		'<button type="button" class="dsa-panel__button" data-dsa-ios-install-done>' + escapeHtml( copy.doneLabel ) + '</button>',
		'</section>',
	].join( '' );
}

const iosAdapters = {
	legacy: renderLegacyIosInstall,
	prototype: renderPrototypeIosInstall,
	kiwe2027: renderPrototypeIosInstall,
};

export function renderIosInstall( payload ) {
	payload = payload || {};
	const requestedProfile = String( payload.visualProfile || 'legacy' );
	const adapter = iosAdapters[ requestedProfile ] || iosAdapters.legacy;
	return adapter( payload );
}

function renderSavedGroup( title, items ) {
	return [
		'<section class="dsa-saved-group"><h3>' + escapeHtml( title ) + '</h3><div class="dsa-saved-grid">',
		items.map( function ( item ) {
			const metadata = [ item.category, item.weight, item.stockLabel, item.date ].filter( Boolean ).join( ' &middot; ' );
			return [
				'<article class="dsa-saved-card">',
				'<a href="' + escapeHtml( item.url || '#' ) + '" data-dsa-full-navigation>',
				item.image ? '<img src="' + escapeHtml( item.image ) + '" alt="" loading="lazy">' : '<span class="dsa-saved-card__placeholder" aria-hidden="true"></span>',
				'<span><small>' + escapeHtml( item.kindLabel || ( item.type === 'wishlist' ? 'Product' : 'Bookmark' ) ) + '</small><strong>' + escapeHtml( item.title || 'Saved item' ) + '</strong>',
				item.price ? '<b class="dsa-saved-card__price">' + escapeHtml( item.price ) + '</b>' : '',
				metadata ? '<span class="dsa-saved-card__meta">' + escapeHtml( metadata ) + '</span>' : '',
				item.excerpt ? '<span class="dsa-saved-card__excerpt">' + escapeHtml( item.excerpt ) + '</span>' : '',
				'</span>',
				'</a>',
				'<button type="button" data-dsa-saved-remove="' + escapeHtml( item.key || '' ) + '" data-dsa-keep-open aria-label="Remove ' + escapeHtml( item.title || 'saved item' ) + '"><span class="dsa-close-glyph" aria-hidden="true">&times;</span></button>',
				'</article>',
			].join( '' );
		} ).join( '' ),
		'</div></section>',
	].join( '' );
}

function savedCopy( payload ) {
	return screenCopy( payload, {
		label: payload.label || 'Saved',
		eyebrow: payload.label || 'Saved',
		title: 'Your saved shelf.',
		intro: payload.storeAvailable === false ? 'Your page bookmarks stay close.' : 'Wishlist products and page bookmarks stay close.',
		emptyTitle: 'Nothing saved yet.',
		emptyText: payload.storeAvailable === false ? 'Tap the bookmark on a page to save it here.' : 'Tap the bookmark or heart on products and pages to build this shelf.',
		wishlistLabel: 'Wishlist',
		bookmarksLabel: 'Bookmarks',
		summaryWishlistLabel: 'Wishlist',
		summaryBookmarksLabel: 'Bookmarks',
		summaryTotalLabel: 'Total saved',
	} );
}

function renderSavedSummary( wishes, bookmarks, copy, storeAvailable ) {
	copy = copy || {};
	return [
		'<div class="kiwe-saved-v2027__summary dsa-summary-stats" aria-label="Saved summary">',
		storeAvailable === false ? '' : '<span><strong>' + escapeHtml( wishes.length ) + '</strong><small>' + escapeHtml( copy.summaryWishlistLabel || 'Wishlist' ) + '</small></span>',
		'<span><strong>' + escapeHtml( bookmarks.length ) + '</strong><small>' + escapeHtml( copy.summaryBookmarksLabel || 'Bookmarks' ) + '</small></span>',
		'<span><strong>' + escapeHtml( wishes.length + bookmarks.length ) + '</strong><small>' + escapeHtml( copy.summaryTotalLabel || 'Total saved' ) + '</small></span>',
		'</div>',
	].join( '' );
}

function renderLegacySaved( payload ) {
	payload = payload || {};
	const items = Array.isArray( payload.items ) ? payload.items : [];
	const wishes = items.filter( function ( item ) { return item.type === 'wishlist'; } );
	const bookmarks = items.filter( function ( item ) { return item.type !== 'wishlist'; } );
	const copy = savedCopy( payload );
	return [
		'<section class="dsa-panel dsa-saved-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( copy.label ) + '" data-dsa-saved-panel>',
		'<p class="dsa-saved-panel__eyebrow">' + escapeHtml( copy.eyebrow ) + '</p>',
		'<h2>' + escapeHtml( copy.title ) + '</h2>',
		copy.intro ? '<p class="dsa-panel__meta">' + escapeHtml( copy.intro ) + '</p>' : '',
		wishes.length ? renderSavedGroup( copy.wishlistLabel, wishes ) : '',
		bookmarks.length ? renderSavedGroup( copy.bookmarksLabel, bookmarks ) : '',
		! items.length ? '<div class="dsa-saved-empty"><strong>' + escapeHtml( copy.emptyTitle ) + '</strong><span>' + escapeHtml( copy.emptyText ) + '</span></div>' : '',
		'</section>',
	].join( '' );
}

function renderPrototypeSaved( payload ) {
	payload = payload || {};
	const items = Array.isArray( payload.items ) ? payload.items : [];
	const wishes = items.filter( function ( item ) { return item.type === 'wishlist'; } );
	const bookmarks = items.filter( function ( item ) { return item.type !== 'wishlist'; } );
	const copy = savedCopy( payload );
	const hasItems = items.length > 0;

	return [
		'<section class="dsa-panel dsa-saved-panel kiwe-saved-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( copy.label ) + '" data-dsa-saved-panel data-dsa-saved-adapter="prototype-2027">',
		'<div class="kiwe-saved-v2027__title"><p class="dsa-hero-kicker">' + escapeHtml( copy.eyebrow ) + '</p><h2>' + escapeHtml( copy.title ) + '</h2><p class="dsa-panel__meta">' + escapeHtml( copy.intro ) + '</p></div>',
		renderSavedSummary( wishes, bookmarks, copy, payload.storeAvailable ),
		wishes.length ? renderSavedGroup( copy.wishlistLabel, wishes ) : '',
		bookmarks.length ? renderSavedGroup( copy.bookmarksLabel, bookmarks ) : '',
		! hasItems ? '<div class="dsa-saved-empty kiwe-saved-v2027__empty"><strong>' + escapeHtml( copy.emptyTitle ) + '</strong><span>' + escapeHtml( copy.emptyText ) + '</span></div>' : '',
		'</section>',
	].join( '' );
}

const savedAdapters = {
	legacy: renderLegacySaved,
	prototype: renderPrototypeSaved,
	kiwe2027: renderPrototypeSaved,
};

export function renderSaved( payload ) {
	payload = payload || {};
	if ( payload.storeAvailable === false && Array.isArray( payload.items ) ) payload = Object.assign( {}, payload, { items: payload.items.filter( function ( item ) { return item.type !== 'wishlist'; } ) } );
	const requestedProfile = String( payload.visualProfile || 'legacy' );
	const adapter = savedAdapters[ requestedProfile ] || savedAdapters.legacy;
	return adapter( payload );
}

export function renderGames( payload ) {
	payload = payload || {};
	const config = payload.config || {};
	const copy = screenCopy( payload, {
		label: payload.label || 'Game',
		eyebrow: payload.label || 'Game',
		startTitle: config.startTitle || 'Are You Game! for discount??',
		startText: config.startText || 'Press any key to start',
		mobileStartText: config.mobileStartText || 'Touch to start',
		chooseText: 'Choose a game',
		scoreLabel: 'Score',
		bestLabel: 'Best',
	} );
	const games = Array.isArray( config.games ) ? config.games : [
		{ id: 'dino', label: 'Dinosaur Jump' },
		{ id: 'star', label: 'Star Shooter' },
	];
	const startGameId = payload.scheduledGame || '';
	const startText = payload.coarsePointer ? copy.mobileStartText : copy.startText;

	return [
		'<section class="dsa-panel dsa-game-panel dsa-hero-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( copy.label ) + '" data-dsa-game-panel data-dsa-keep-open' + ( startGameId ? ' data-dsa-scheduled-game="' + escapeHtml( startGameId ) + '"' : '' ) + '>',
		'<p class="dsa-hero-kicker">' + escapeHtml( copy.eyebrow ) + '</p>',
		startGameId ? '<div class="dsa-game-start" data-dsa-game-start><strong>' + escapeHtml( copy.startTitle ) + '</strong><span>' + escapeHtml( startText ) + '</span></div>' : '',
		'<div class="dsa-game-stage">',
		'<canvas class="dsa-game-canvas" width="960" height="420" data-dsa-game-canvas></canvas>',
		'<div class="dsa-game-hud">',
		'<span data-dsa-game-score>' + escapeHtml( copy.scoreLabel ) + ' 0</span>',
		'<span data-dsa-game-best>' + escapeHtml( copy.bestLabel ) + ' 0</span>',
		'<span data-dsa-game-bonus>' + escapeHtml( payload.bonusLabel || '' ) + '</span>',
		'</div>',
		'<div class="dsa-game-message" data-dsa-game-message>' + escapeHtml( startGameId ? startText : copy.chooseText ) + '</div>',
		'</div>',
		'<div class="dsa-game-actions"' + ( startGameId ? ' hidden' : '' ) + '>',
		games.map( function ( game ) {
			return '<button type="button" class="dsa-game-choice" data-dsa-start-game="' + escapeHtml( game.id ) + '">' + escapeHtml( game.label ) + '</button>';
		} ).join( '' ),
		'</div>',
		'</section>',
	].join( '' );
}

function renderMenuItem( item, fallbackUrl ) {
	const title = item.title || item.label || 'Menu item';
	const url = item.url || fallbackUrl || '/';
	return '<li><a class="dsa-menu-link' + ( item.isActive ? ' is-active' : '' ) + '" href="' + escapeHtml( url ) + '" data-dsa-full-navigation' + ( item.isActive ? ' aria-current="page"' : '' ) + ( item.object_id ? ' data-dsa-object-id="' + escapeHtml( item.object_id ) + '"' : '' ) + '>' + ( item.image ? '<img class="dsa-menu-link__image" src="' + escapeHtml( item.image ) + '" alt="">' : '' ) + '<span class="dsa-menu-link__body"><span>' + escapeHtml( title ) + '</span></span></a></li>';
}

function renderMenuGroup( group, fallbackUrl, headings ) {
	const items = Array.isArray( group && group.items ) ? group.items : [];
	const context = group.pageContents && group.pageContents.enabled ? renderMenuContext( headings, group.pageContents.title ) : '';
	if ( ! items.length && ! context ) return '';
	return '<section class="dsa-menu-group">' + ( group.label ? '<h2 class="dsa-menu-group__title">' + escapeHtml( group.label ) + '</h2>' : '' ) + '<ul class="dsa-menu-list">' + items.map( function ( item ) { return renderMenuItem( item, fallbackUrl ); } ).join( '' ) + '</ul>' + context + '</section>';
}

function menuGroupsWithSectionTitle( groups, title ) {
	const normalized = Array.isArray( groups ) ? groups.map( function ( group ) {
		return Object.assign( {}, group || {} );
	} ) : [];
	if ( normalized.length && title && ! normalized[ 0 ].label ) normalized[ 0 ].label = title;
	return normalized;
}

function renderUngroupedMenu( links, title, fallbackUrl ) {
	const list = Array.isArray( links ) ? links : [];
	if ( ! list.length ) return '';
	return '<section class="dsa-menu-group">' + ( title ? '<h2 class="dsa-menu-group__title">' + escapeHtml( title ) + '</h2>' : '' ) + '<ul class="dsa-menu-list">' + list.map( function ( item ) { return renderMenuItem( item, fallbackUrl ); } ).join( '' ) + '</ul></section>';
}

function renderMenuContext( headings, title ) {
	headings = Array.isArray( headings ) ? headings : [];
	if ( ! headings.length ) return '';
	const minimumLevel = headings.reduce( function ( minimum, heading ) { return Math.min( minimum, heading.level ); }, 6 );
	return '<section class="dsa-menu-context" data-dsa-menu-context><h2 class="dsa-menu-context__title">' + escapeHtml( title || 'On this page' ) + '</h2><ol class="dsa-menu-context__list">' + headings.map( function ( heading ) { return '<li style="--dsa-menu-depth:' + Math.max( 0, heading.level - minimumLevel ) + '"><button type="button" data-dsa-menu-anchor="' + escapeHtml( heading.id ) + '">' + escapeHtml( heading.title ) + '</button></li>'; } ).join( '' ) + '</ol></section>';
}

function menuCopy( payload ) {
	return screenCopy( payload, {
		label: payload.label || 'Menu',
		eyebrow: payload.label || 'Menu',
		title: 'Navigation',
		intro: '',
		contextTitle: payload.contextTitle || 'On this page',
		dashboardLabel: 'Dashboard',
	} );
}

function renderMenuStaffActions(payload, copy) {
 const dashboard=payload.adminDashboard||{}, edit=payload.adminEdit||{};
 const links=[{...dashboard,label:dashboard.label||copy.dashboardLabel},edit].filter(item=>typeof item.url==='string'&&item.url);
 return links.length?'<div class="dsa-menu-staff-actions">'+links.map(item=>'<a class="dsa-menu-dashboard" href="'+escapeHtml(item.url)+'" data-dsa-full-navigation>'+escapeHtml(item.label||'Edit')+'</a>').join('')+'</div>':'';
}

function renderLegacyMenu( payload ) {
	payload = payload || {};
	const copy = menuCopy( payload );
	const tag = /^(h1|h2|h3|h4|p|span)$/.test( payload.tag || '' ) ? payload.tag : 'span';
	const groups = menuGroupsWithSectionTitle( payload.groups, copy.title );
	const links = Array.isArray( payload.links ) ? payload.links : [];
	const admin = payload.adminDashboard || {};
	return '<section class="dsa-panel dsa-menu-panel dsa-hero-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( copy.label ) + '"><' + tag + ' class="dsa-hero-kicker">' + escapeHtml( copy.eyebrow ) + '</' + tag + '>' + ( copy.intro ? '<p class="dsa-panel__meta">' + escapeHtml( copy.intro ) + '</p>' : '' ) + ( groups.length ? groups.map( function ( group ) { return renderMenuGroup( group, payload.fallbackUrl, payload.contextHeadings ); } ).join( '' ) : renderUngroupedMenu( links, copy.title, payload.fallbackUrl ) ) + ( payload.groupOwnedContext ? '' : renderMenuContext( payload.contextHeadings, copy.contextTitle ) ) + renderMenuStaffActions( payload, copy ) + '</section>';
}

function renderPrototypeMenu( payload ) {
	payload = payload || {};
	const copy = menuCopy( payload );
	const groups = menuGroupsWithSectionTitle( payload.groups, copy.title );
	const links = Array.isArray( payload.links ) ? payload.links : [];
	const admin = payload.adminDashboard || {};
	const linkCount = groups.length
		? groups.reduce( function ( total, group ) { return total + ( Array.isArray( group.items ) ? group.items.length : 0 ); }, 0 )
		: links.length;
	const menuBody = groups.length
		? groups.map( function ( group ) { return renderMenuGroup( group, payload.fallbackUrl, payload.contextHeadings ); } ).join( '' )
		: renderUngroupedMenu( links, copy.title, payload.fallbackUrl );
	const context = payload.groupOwnedContext ? '' : renderMenuContext( payload.contextHeadings, copy.contextTitle );

	return [
		'<section class="dsa-panel dsa-menu-panel dsa-hero-panel kiwe-menu-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( copy.label ) + '" data-dsa-menu-adapter="prototype-2027">',
		'<div class="kiwe-menu-v2027__title"><p class="dsa-hero-kicker">' + escapeHtml( copy.eyebrow ) + '</p><p class="dsa-panel__meta">' + escapeHtml( copy.intro || ( String( linkCount ) + ' site links' + ( context ? ' plus this page guide' : '' ) + '.' ) ) + '</p></div>',
		'<div class="kiwe-menu-v2027__grid">',
		'<div class="kiwe-menu-v2027__nav">' + menuBody + '</div>',
		context ? '<div class="kiwe-menu-v2027__context">' + context + '</div>' : '',
		'</div>',
		renderMenuStaffActions( payload, copy ),
		'</section>',
	].join( '' );
}

const menuAdapters = {
	legacy: renderLegacyMenu,
	prototype: renderPrototypeMenu,
	kiwe2027: renderPrototypeMenu,
};

export function renderMenu( payload ) {
	payload = payload || {};
	const requestedProfile = String( payload.visualProfile || 'legacy' );
	const adapter = menuAdapters[ requestedProfile ] || menuAdapters.legacy;
	return adapter( payload );
}

export function renderAppsiteHome( payload ) {
	payload = payload || {};
	const site = payload.site || {};
	const config = payload.config || {};
	const title = site.tagline || 'Welcome';
	const hero = site.title || site.name || payload.documentTitle || 'Our Appsite';
	const message = config.welcomeMessage || 'Welcome to Our Appsite';
	const pwaPitch = config.pwaPitch || 'Try our app. No app store required.';
	const buttons = Array.isArray( config.buttons ) ? config.buttons : [];

	return [
		'<div class="dsa-initial-preloader dsa-material-surface" data-dsa-material="' + ( config.homeMaterial === 'solid' ? 'solid' : 'glass' ) + '" data-dsa-initial-preloader role="dialog" aria-modal="true" tabindex="-1" aria-label="Kiwe Appsite home screen">',
		'<div class="dsa-initial-preloader__inner">',
		'<p class="dsa-initial-preloader__title">' + escapeHtml( title ) + '</p>',
		'<div class="dsa-initial-preloader__hero" role="heading" aria-level="2">' + escapeHtml( hero ) + '</div>',
		'<p class="dsa-initial-preloader__message">' + escapeHtml( message ) + '</p>',
		'<div class="dsa-initial-preloader__clock" data-dsa-initial-clock></div>',
		'<div class="dsa-initial-preloader__unlock"><span aria-hidden="true"></span><strong>Scroll, swipe up, or press ↓ to enter</strong></div>',
		'<p class="dsa-initial-preloader__app-pitch" data-dsa-pwa-pitch>' + escapeHtml( pwaPitch ) + '</p>',
		'<div class="dsa-initial-preloader__actions" data-dsa-install-group data-dsa-keep-open>',
		buttons.map( function ( button ) {
			const label = button.label || 'Open';
			const eyebrow = button.eyebrow || '';
			const platform = button.platform || button.id || '';
			const icon = button.icon || platform;
			const content = '<span class="dsa-app-badge__icon dsa-app-badge__icon--' + escapeHtml( icon ) + '" aria-hidden="true">' + renderAppBadgeIcon( icon, payload.iconSprite ) + '</span><span class="dsa-app-badge__copy"><small>' + escapeHtml( eyebrow ) + '</small><strong>' + escapeHtml( label ) + '</strong></span>';
			if ( button.url ) {
				return '<a class="dsa-app-badge" href="' + escapeHtml( button.url ) + '" data-dsa-initial-action>' + content + '</a>';
			}
			return '<button class="dsa-app-badge" type="button" data-dsa-initial-action data-dsa-install-pwa data-dsa-pwa-platform="' + escapeHtml( platform ) + '">' + content + '</button>';
		} ).join( '' ),
		'<p class="dsa-install-disclosure" data-dsa-install-disclosure>' + escapeHtml( config.installDisclosure || 'Adds this website to your Home Screen. No app store needed. Notifications are optional.' ) + '</p>',
		'</div>',
		renderTrustBadges( payload.trustBadges ),
		'<p class="dsa-initial-preloader__app-status" data-dsa-pwa-status hidden aria-live="polite"></p>',
		'</div>',
		'</div>',
	].join( '' );
}
