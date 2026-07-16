function escapeHtml( value ) {
	return String( value == null ? '' : value ).replace( /[&<>'"]/g, function ( character ) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ character ];
	} );
}

function socialGlyph( id, label ) {
	const open = '<svg viewBox="0 0 24 24" role="img" aria-label="' + escapeHtml( label || id || 'Social link' ) + '" focusable="false">';
	const glyphs = {
		facebook: '<path d="M14.2 21v-7h-2.5v-3h2.5V8.8c0-3 1.8-4.8 4.6-4.8 1.1 0 2.1.1 2.6.2v3h-1.8c-1.4 0-1.8.7-1.8 1.7V11h3.4l-.5 3h-2.9v7z"></path>',
		instagram: '<rect x="3" y="3" width="18" height="18" rx="5"></rect><circle cx="12" cy="12" r="4"></circle><circle cx="17.5" cy="6.5" r="1"></circle>',
		x: '<path d="M5 4l14 16M19 4 5 20"></path>',
		youtube: '<path d="m9 8 7 4-7 4z"></path>',
		pinterest: '<path d="M12 2a9.5 9.5 0 0 0-3.4 18.4c-.1-1.6 0-3.4.4-5.1l1.2-5s-.3-.8-.3-2c0-1.9 1.1-3.3 2.5-3.3 1.2 0 1.8.9 1.8 2 0 1.2-.8 3-1.2 4.7-.3 1.4.7 2.5 2.1 2.5 2.5 0 4.2-3.2 4.2-6.9 0-2.9-2.4-5.1-5.7-5.1-4 0-6.5 3-6.5 6.3 0 1.1.3 2.3 1 3 .3.3.3.5.2.9l-.3 1.3c-.1.4-.4.6-.8.4-1.9-.8-2.8-3.1-2.8-5.7C4.4 4.8 8 1 13.9 1 19 1 22 4.7 22 8.8c0 5.3-3 9.2-7.4 9.2-1.5 0-2.9-.8-3.4-1.8l-.9 3.5c-.3 1.2-1 2.5-1.6 3.4A10 10 0 0 0 12 22z"></path>',
		linkedin: '<rect x="4" y="9" width="4" height="11"></rect><circle cx="6" cy="5.5" r="2"></circle><path d="M11 20V9h4v1.7c.8-1.2 2-2.1 3.8-2.1 3 0 4.2 2 4.2 5.3V20h-4v-5.4c0-1.6-.6-2.7-2-2.7-1.4 0-2 1-2 2.7V20z"></path>',
	};
	return open + ( glyphs[ id ] || '<circle cx="12" cy="12" r="8"></circle>' ) + '</svg>';
}

function social( item, preview ) {
	const id = item.id || 'link';
	const label = item.label || id;
	const content = '<span class="dsa-social-icon" aria-hidden="true">' + socialGlyph( id, label ) + '</span><span>' + escapeHtml( label ) + '</span>';
	return item.url ? '<a class="dsa-social-link dsa-social-link--' + escapeHtml( id ) + '" href="' + escapeHtml( item.url ) + '" target="_blank" rel="noopener noreferrer">' + content + '</a>' : ( preview ? '<span class="dsa-social-link dsa-social-link--empty dsa-social-link--' + escapeHtml( id ) + '">' + content + '<small>Not set</small></span>' : '' );
}

function posts( items, section ) {
	const title = section.title || 'Latest Posts';
	return '<section class="dsa-links-posts" aria-label="' + escapeHtml( title ) + '"><h3>' + escapeHtml( title ) + '</h3><div class="dsa-post-strip">' + items.map( function ( post ) {
		return '<a class="dsa-post-card" href="' + escapeHtml( post.url || '#' ) + '" data-dsa-full-navigation>' + ( post.image ? '<img src="' + escapeHtml( post.image ) + '" alt="">' : '<span class="dsa-post-card__blank"></span>' ) + '<span>' + escapeHtml( post.title || 'Latest post' ) + '</span></a>';
	} ).join( '' ) + '</div></section>';
}

function review( item ) {
	const stars = '★★★★★'.slice( 0, Math.max( 0, Math.min( 5, Number( item.rating || 5 ) ) ) );
	return '<figure class="dsa-review-card"><blockquote>' + escapeHtml( item.text || '' ) + '</blockquote><figcaption><span>' + escapeHtml( stars || '★★★★★' ) + '</span><small>' + escapeHtml( item.author || 'Customer' ) + '</small></figcaption></figure>';
}

function health( item ) {
	return '<span class="dsa-health-pill' + ( item.active ? ' is-active' : '' ) + '"><i aria-hidden="true"></i>' + escapeHtml( item.label || 'Check' ) + '</span>';
}

function adminBar( editor ) {
	return '<div class="dsa-links-admin-bar"><button type="button" class="dsa-panel__button dsa-links-edit-button" data-dsa-links-edit>Edit links</button>' + ( editor.adminUrl ? '<a class="dsa-links-admin-link" href="' + escapeHtml( editor.adminUrl ) + '" data-dsa-full-navigation>Kiwe settings</a>' : '' ) + '</div>';
}

function linksViewData( payload ) {
	payload = payload || {};
	const hub = payload.hub || {};
	const editor = hub.editor || {};
	const socials = hub.canEdit && Array.isArray( editor.socials ) && editor.socials.length ? editor.socials : ( Array.isArray( hub.socials ) ? hub.socials : [] );
	const shop = hub.shop || {};
	const actions = [];
	if ( hub.commerceAvailable && ( shop.url || hub.canEdit ) ) actions.push( shop.url ? '<a class="dsa-shop-link" href="' + escapeHtml( shop.url ) + '" data-dsa-full-navigation><span>' + escapeHtml( shop.label || 'Shop' ) + '</span><small>Open store</small></a>' : '<span class="dsa-shop-link dsa-shop-link--empty"><span>' + escapeHtml( shop.label || 'Shop' ) + '</span><small>Not set</small></span>' );
	if ( hub.cartAvailable ) actions.push( '<button class="dsa-shop-link dsa-links-cart-button" type="button" data-dsa-links-cart data-dsa-keep-open><span>Cart</span><small>Open cart</small></button>' );
	const logo = payload.dark && payload.logoDark ? payload.logoDark : ( hub.logo || '' );
	const name = hub.siteName || payload.documentTitle || payload.label || 'Links';
	const items = Array.isArray( hub.posts ) ? hub.posts : [];
	const checks = Array.isArray( hub.health ) ? hub.health : [];
	const rawScore = hub.score == null ? '' : String( hub.score ).trim();
	const hasScore = rawScore !== '' && Number.isFinite( Number( rawScore ) );
	const score = hasScore ? Math.max( 0, Math.min( 100, Number( rawScore ) ) ) : null;

	return { payload: payload, hub: hub, editor: editor, socials: socials, actions: actions, logo: logo, name: name, items: items, checks: checks, hasScore: hasScore, score: score };
}

function linksHero( data ) {
	return '<div class="dsa-links-hero' + ( data.hasScore ? '' : ' dsa-links-hero--no-score' ) + '">' + ( data.logo ? '<img class="dsa-links-logo" src="' + escapeHtml( data.logo ) + '" data-dsa-theme-logo data-light-src="' + escapeHtml( data.hub.logo || '' ) + '" data-dark-src="' + escapeHtml( data.payload.logoDark || data.hub.logo || '' ) + '" alt="' + escapeHtml( data.name ) + '">' : '<div class="dsa-links-logo dsa-links-logo--text">' + escapeHtml( data.name.charAt( 0 ) || 'K' ) + '</div>' ) + ( data.hasScore ? '<div class="dsa-links-score" aria-label="Site score ' + escapeHtml( data.score ) + ' out of 100"><span>' + escapeHtml( data.score ) + '</span><small>site score</small></div>' : '' ) + '</div>';
}

function renderLegacyLinks( payload ) {
	const data = linksViewData( payload );
	return '<section class="dsa-panel dsa-links-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( data.payload.label || 'Links' ) + '">' + linksHero( data ) + ( data.hub.canEdit ? adminBar( data.editor ) : '' ) + ( data.socials.length ? '<div class="dsa-social-grid">' + data.socials.map( function ( item ) { return social( item, data.hub.canEdit ); } ).join( '' ) + '</div>' : '' ) + ( data.actions.length ? '<div class="dsa-links-commerce-actions">' + data.actions.join( '' ) + '</div>' : '' ) + ( data.items.length ? posts( data.items, data.hub.postsSection || {} ) : '' ) + ( data.hub.review && data.hub.review.text ? review( data.hub.review ) : '' ) + ( data.checks.length ? '<div class="dsa-health-row" data-dsa-context-slot data-dsa-context-name="links" data-dsa-context-width="dock">' + data.checks.map( health ).join( '' ) + '</div>' : '' ) + '</section>';
}

function renderPrototypeLinks( payload ) {
	const data = linksViewData( payload );
	return [
		'<section class="dsa-panel dsa-links-panel kiwe-links-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( data.payload.label || 'Links' ) + '" data-dsa-links-adapter="prototype-2027">',
		'<div class="kiwe-links-v2027__title"><p class="dsa-hero-kicker">' + escapeHtml( data.payload.label || 'Links' ) + '</p><h2>' + escapeHtml( data.name ) + '</h2><p class="dsa-panel__meta">Store links, social proof, trust, and recent content in one Appsite surface.</p></div>',
		'<div class="kiwe-links-v2027__identity">' + linksHero( data ) + ( data.hub.canEdit ? adminBar( data.editor ) : '' ) + '</div>',
		data.socials.length ? '<div class="dsa-social-grid kiwe-links-v2027__socials">' + data.socials.map( function ( item ) { return social( item, data.hub.canEdit ); } ).join( '' ) + '</div>' : '',
		data.actions.length ? '<div class="dsa-links-commerce-actions kiwe-links-v2027__actions">' + data.actions.join( '' ) + '</div>' : '',
		data.items.length ? posts( data.items, data.hub.postsSection || {} ) : '',
		data.hub.review && data.hub.review.text ? review( data.hub.review ) : '',
		data.checks.length ? '<div class="dsa-health-row kiwe-links-v2027__health" data-dsa-context-slot data-dsa-context-name="links" data-dsa-context-width="dock">' + data.checks.map( health ).join( '' ) + '</div>' : '',
		'</section>',
	].join( '' );
}

const linksAdapters = {
	legacy: renderLegacyLinks,
	prototype: renderPrototypeLinks,
	kiwe2027: renderPrototypeLinks,
};

export function render( payload ) {
	payload = payload || {};
	const requestedProfile = String( payload.visualProfile || 'legacy' );
	const adapter = linksAdapters[ requestedProfile ] || linksAdapters.legacy;
	return adapter( payload );
}

function options( categories, selected ) {
	if ( ! categories.length ) return '<option value="0">Latest Posts</option>';
	return categories.map( function ( category ) { const id = String( category.id || 0 ); return '<option value="' + escapeHtml( id ) + '"' + ( Number( selected ) === Number( id ) ? ' selected' : '' ) + '>' + escapeHtml( category.label || 'Category' ) + '</option>'; } ).join( '' );
}

export function renderEditor( payload ) {
	const hub = payload.hub || {};
	const editor = hub.editor || {};
	const socials = Array.isArray( editor.socials ) ? editor.socials : [];
	const fields = socials.map( function ( item ) { return '<label>' + escapeHtml( item.label || item.id || 'Link' ) + '<input class="dsa-auth-field" type="url" data-dsa-social-id="' + escapeHtml( item.id || '' ) + '" value="' + escapeHtml( item.url || '' ) + '" placeholder="https://"></label>'; } ).join( '' );
	return '<section class="dsa-panel dsa-links-panel dsa-links-editor" role="dialog" aria-modal="false" aria-label="Edit links" data-dsa-links-panel data-dsa-keep-open><div class="dsa-links-editor__top"><button type="button" class="dsa-panel__button dsa-links-back" data-dsa-links-view>Back</button><span class="dsa-panel__meta">Admin editor</span></div><form class="dsa-links-form" data-dsa-links-form><label class="dsa-links-logo-field">' + ( hub.logo ? '<img class="dsa-links-logo" src="' + escapeHtml( hub.logo ) + '" alt="Site logo">' : '<span class="dsa-links-logo dsa-links-logo--text">' + escapeHtml( ( hub.siteName || 'K' ).charAt( 0 ) ) + '</span>' ) + '<span>Site logo</span><input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-dsa-links-logo></label><label>Site score<input class="dsa-auth-field" type="number" min="0" max="100" name="siteScore" value="' + escapeHtml( editor.siteScore == null ? '' : editor.siteScore ) + '" placeholder="Optional"></label><div class="dsa-links-field-grid">' + fields + '</div><div class="dsa-links-field-grid"><label>Shop label<input class="dsa-auth-field" name="shopLabel" value="' + escapeHtml( editor.shopLabel || ( hub.shop || {} ).label || 'Shop' ) + '"></label><label>Shop URL<input class="dsa-auth-field" type="url" name="shopUrl" value="' + escapeHtml( editor.shopUrl || '' ) + '" placeholder="Leave blank for WooCommerce shop"></label></div><div class="dsa-links-field-grid"><label>Posts title<input class="dsa-auth-field" name="postsTitle" value="' + escapeHtml( editor.postsTitle || '' ) + '" placeholder="Blank uses category name"></label><label>Posts category<select class="dsa-auth-field" name="postsCategory">' + options( Array.isArray( editor.categories ) ? editor.categories : [], editor.postsCategory || 0 ) + '</select></label></div><div class="dsa-links-field-grid"><label>SSL provider<input class="dsa-auth-field" name="sslProvider" value="' + escapeHtml( editor.sslProvider || '' ) + '" placeholder="Hostinger"></label><label>Payment provider<input class="dsa-auth-field" name="paymentProvider" value="' + escapeHtml( editor.paymentProvider || '' ) + '" placeholder="Fallback if WooCommerce cannot detect"></label></div><div class="dsa-links-field-grid"><label>Review source<select class="dsa-auth-field" name="reviewSource"><option value="manual"' + ( editor.reviewSource !== 'google' ? ' selected' : '' ) + '>Manual testimonials</option><option value="google"' + ( editor.reviewSource === 'google' ? ' selected' : '' ) + '>Google Places reviews</option></select></label><label>Google Place ID<input class="dsa-auth-field" name="googlePlaceId" value="' + escapeHtml( editor.googlePlaceId || '' ) + '"></label></div><label>Google API key<input class="dsa-auth-field" type="password" name="googleApiKey" value="" placeholder="' + escapeHtml( editor.hasGoogleApiKey ? 'Saved. Enter a new key to replace it.' : 'Places API key' ) + '" autocomplete="new-password"></label><label>Testimonials<textarea class="dsa-auth-field dsa-links-textarea" name="testimonials" rows="5" placeholder="One testimonial per line">' + escapeHtml( editor.testimonials || '' ) + '</textarea></label><div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" type="submit">Save links</button><span class="dsa-panel__meta" data-dsa-links-message></span></div></form></section>';
}
