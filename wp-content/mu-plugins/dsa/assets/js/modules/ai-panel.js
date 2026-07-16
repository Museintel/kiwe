function escapeHtml( value ) {
	return String( value == null ? '' : value ).replace( /[&<>'"]/g, function ( character ) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ character ];
	} );
}

function benefits( insight ) {
	const items = insight && Array.isArray( insight.benefits ) ? insight.benefits.filter( Boolean ).slice( 0, 4 ) : [];
	return items.length ? '<ul class="dsa-ai-benefits">' + items.map( function ( item ) {
		return '<li>' + escapeHtml( item ) + '</li>';
	} ).join( '' ) + '</ul>' : '';
}

function notificationTime( timestamp ) {
	try {
		return new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit' } ).format( new Date( Number( timestamp ) ) );
	} catch ( error ) {
		return '';
	}
}

function insightCard( insight ) {
	return [
		'<article class="dsa-ai-insight dsa-ai-insight--' + escapeHtml( insight.type || 'info' ) + ( insight.notification ? ' is-notification' : '' ) + '" data-dsa-ai-insight="' + escapeHtml( insight.id ) + '"' + ( insight.dismissible ? ' data-dsa-ai-dismissible="true"' : '' ) + '>',
		'<span class="dsa-ai-insight__glyph"><span class="dsa-ai-glyph" aria-hidden="true"></span></span>',
		'<div class="dsa-ai-insight__copy"><small>' + escapeHtml( insight.kicker || 'Insight' ) + '</small><strong>' + escapeHtml( insight.title || '' ) + '</strong><p>' + escapeHtml( insight.message || '' ) + '</p>' + benefits( insight ) + ( insight.createdAt ? '<time datetime="' + escapeHtml( new Date( Number( insight.createdAt ) ).toISOString() ) + '">' + escapeHtml( notificationTime( insight.createdAt ) ) + '</time>' : '' ) + '</div>',
		'<span class="dsa-ai-insight__actions">',
		insight.action ? '<button type="button" data-dsa-ai-insight-action="' + escapeHtml( insight.id ) + '">' + escapeHtml( insight.actionLabel || 'Open' ) + '</button>' : '',
		insight.notification ? ( insight.dismissible ? '<button type="button" class="dsa-ai-insight__dismiss" data-dsa-ai-notification-dismiss="' + escapeHtml( insight.id ) + '" aria-label="Dismiss notification">&times;</button>' : '<span class="dsa-ai-insight__saved">Saved</span>' ) : '<button type="button" class="is-quiet" data-dsa-ai-insight-dismiss="' + escapeHtml( insight.id ) + '">Dismiss</button>',
		'</span><span class="dsa-ai-insight__status" data-dsa-ai-insight-status></span></article>',
	].join( '' );
}

function renderLegacyInbox( payload ) {
	const items = Array.isArray( payload.items ) ? payload.items : [];
	if ( ! items.length ) {
		return '<div class="dsa-ai-empty"><span class="dsa-ai-glyph" aria-hidden="true"></span><strong>You are all caught up.</strong><p>New account and cart insights will collect here.</p></div>';
	}
	const unread = Number( payload.unread || 0 );
	const open = Boolean( payload.open );
	return '<div class="dsa-ai-inbox-head"><button type="button" class="dsa-ai-tray-toggle' + ( unread ? ' has-unread' : '' ) + '" data-dsa-ai-tray-toggle aria-expanded="' + ( open ? 'true' : 'false' ) + '" aria-label="' + ( open ? 'Hide notifications' : 'Show notifications' ) + '"><span class="dsa-notification-bell" aria-hidden="true"></span></button><strong>' + escapeHtml( items.length ) + ' notification' + ( items.length === 1 ? '' : 's' ) + '</strong><span>' + ( unread ? escapeHtml( unread ) + ' unread' : 'Recent activity' ) + '</span></div><div class="dsa-ai-inbox-list' + ( open ? '' : ' is-collapsed' ) + '">' + items.map( insightCard ).join( '' ) + '</div>';
}

function renderPrototypeInbox( payload ) {
	const items = Array.isArray( payload.items ) ? payload.items : [];
	if ( ! items.length ) {
		return '<div class="dsa-ai-empty kiwe-ai-v2027__empty"><span class="dsa-ai-glyph" aria-hidden="true"></span><strong>You are all caught up.</strong><p>New account, cart, notification, and safety insights will collect here.</p></div>';
	}
	const unread = Number( payload.unread || 0 );
	const open = Boolean( payload.open );
	return '<div class="dsa-ai-inbox-head kiwe-ai-v2027__inbox-head"><button type="button" class="dsa-ai-tray-toggle' + ( unread ? ' has-unread' : '' ) + '" data-dsa-ai-tray-toggle aria-expanded="' + ( open ? 'true' : 'false' ) + '" aria-label="' + ( open ? 'Hide notifications' : 'Show notifications' ) + '"><span class="dsa-notification-bell" aria-hidden="true"></span></button><span><strong>' + escapeHtml( items.length ) + '</strong><small>signals</small></span><span>' + ( unread ? escapeHtml( unread ) + ' unread' : 'Recent activity' ) + '</span></div><div class="dsa-ai-inbox-list kiwe-ai-v2027__inbox-list' + ( open ? '' : ' is-collapsed' ) + '">' + items.map( insightCard ).join( '' ) + '</div>';
}

const inboxAdapters = {
	legacy: renderLegacyInbox,
	prototype: renderPrototypeInbox,
	kiwe2027: renderPrototypeInbox,
};

export function renderInbox( payload ) {
	payload = payload || {};
	const requestedProfile = String( payload.visualProfile || 'legacy' );
	const adapter = inboxAdapters[ requestedProfile ] || inboxAdapters.legacy;
	return adapter( payload );
}

function renderLegacyPanel( payload ) {
	return [
		'<section class="dsa-panel dsa-ai-panel dsa-hero-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( payload.label || 'AI Assistant' ) + '" data-dsa-ai-panel data-dsa-keep-open>',
		'<p class="dsa-hero-kicker">AI Assistant</p><h2>Useful things, at the right moment.</h2>',
		'<div class="dsa-ai-insights" data-dsa-ai-insights>' + renderInbox( payload ) + '</div>',
		'<div class="dsa-ai-chat-placeholder" data-dsa-keep-open data-dsa-context-slot data-dsa-context-name="ai" data-dsa-context-width="dock"><input type="text" placeholder="Chat with AI" aria-label="Chat with AI" readonly><button type="button" aria-label="Send message" disabled>&uarr;</button></div>',
		'</section>',
	].join( '' );
}

function renderPrototypePanel( payload ) {
	return [
		'<section class="dsa-panel dsa-ai-panel dsa-hero-panel kiwe-ai-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( payload.label || 'AI Assistant' ) + '" data-dsa-ai-panel data-dsa-keep-open data-dsa-ai-adapter="prototype-2027">',
		'<div class="kiwe-ai-v2027__title"><p class="dsa-hero-kicker">AI Assistant</p><h2>Useful things, at the right moment.</h2><p class="dsa-panel__meta">DSA keeps the decisions deterministic; this surface only arranges the useful signals.</p></div>',
		'<div class="dsa-ai-insights kiwe-ai-v2027__insights" data-dsa-ai-insights>' + renderInbox( payload ) + '</div>',
		'<div class="dsa-ai-chat-placeholder kiwe-ai-v2027__chat" data-dsa-keep-open data-dsa-context-slot data-dsa-context-name="ai" data-dsa-context-width="dock"><input type="text" placeholder="Chat with AI" aria-label="Chat with AI" readonly><button type="button" aria-label="Send message" disabled>&uarr;</button></div>',
		'</section>',
	].join( '' );
}

const panelAdapters = {
	legacy: renderLegacyPanel,
	prototype: renderPrototypePanel,
	kiwe2027: renderPrototypePanel,
};

export function renderPanel( payload ) {
	payload = payload || {};
	const requestedProfile = String( payload.visualProfile || 'legacy' );
	const adapter = panelAdapters[ requestedProfile ] || panelAdapters.legacy;
	return adapter( payload );
}

function reportItem( item ) {
	return '<li class="dsa-ai-item dsa-ai-item--' + escapeHtml( item.status || 'info' ) + '"><strong>' + escapeHtml( item.label || 'Signal' ) + '</strong><span>' + escapeHtml( item.text || '' ) + '</span></li>';
}

function renderLegacyReport( report ) {
	const sections = report && Array.isArray( report.sections ) ? report.sections : [];
	if ( ! sections.length ) {
		return '<div class="dsa-ai-card"><h3>Copilot workflows</h3><p>Audit trust, transition copy, SecureTrack, and GEO readiness from live DSA data.</p></div>';
	}
	return '<div class="dsa-ai-mode">Mode: ' + escapeHtml( report.mode || 'deterministic' ) + '</div>' + sections.map( function ( section ) {
		const items = Array.isArray( section.items ) ? section.items : [];
		return '<section class="dsa-ai-card dsa-ai-card--' + escapeHtml( section.id || 'section' ) + '"><h3>' + escapeHtml( section.title || 'Copilot section' ) + '</h3>' + ( section.lead ? '<p>' + escapeHtml( section.lead ) + '</p>' : '' ) + ( items.length ? '<ul class="dsa-ai-list">' + items.map( reportItem ).join( '' ) + '</ul>' : '' ) + '</section>';
	} ).join( '' );
}

function renderPrototypeReport( report ) {
	const sections = report && Array.isArray( report.sections ) ? report.sections : [];
	if ( ! sections.length ) {
		return '<div class="dsa-ai-card kiwe-ai-v2027__report-card"><h3>Copilot workflows</h3><p>Audit trust, transition copy, SecureTrack, and GEO readiness from live DSA data.</p></div>';
	}
	return '<div class="dsa-ai-mode kiwe-ai-v2027__mode">Mode: ' + escapeHtml( report.mode || 'deterministic' ) + '</div><div class="kiwe-ai-v2027__report-grid">' + sections.map( function ( section ) {
		const items = Array.isArray( section.items ) ? section.items : [];
		return '<section class="dsa-ai-card kiwe-ai-v2027__report-card dsa-ai-card--' + escapeHtml( section.id || 'section' ) + '"><h3>' + escapeHtml( section.title || 'Copilot section' ) + '</h3>' + ( section.lead ? '<p>' + escapeHtml( section.lead ) + '</p>' : '' ) + ( items.length ? '<ul class="dsa-ai-list">' + items.map( reportItem ).join( '' ) + '</ul>' : '' ) + '</section>';
	} ).join( '' ) + '</div>';
}

const reportAdapters = {
	legacy: renderLegacyReport,
	prototype: renderPrototypeReport,
	kiwe2027: renderPrototypeReport,
};

export function renderReport( report ) {
	report = report || {};
	const requestedProfile = String( report.visualProfile || 'legacy' );
	const adapter = reportAdapters[ requestedProfile ] || reportAdapters.legacy;
	return adapter( report );
}
