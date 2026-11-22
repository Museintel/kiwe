function escapeHtml( value ) {
	return String( value == null ? '' : value ).replace( /[&<>'"]/g, function ( character ) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ character ];
	} );
}

function socialGlyph( id, label, iconSprite ) {
 const open = '<svg viewBox="0 0 24 24" role="img" aria-label="' + escapeHtml( label || id || 'Social link' ) + '" focusable="false">';
 const known = [ 'facebook', 'instagram', 'x', 'youtube', 'pinterest', 'linkedin', 'whatsapp', 'message-circle' ];
 const symbol = id === 'email' ? 'mail' : ( known.indexOf( id ) !== -1 ? id : 'share-2' );
 return open + ( iconSprite ? '<use href="' + escapeHtml( String( iconSprite ) + '#' + symbol ) + '"></use>' : '<circle cx="12" cy="12" r="8"></circle>' ) + '</svg>';
}

function linksCopy( payload ) {
	const screen = payload && payload.screenTheme && typeof payload.screenTheme === 'object' ? payload.screenTheme : {};
	return {
		label: screen.label || payload.label || 'Links',
		eyebrow: screen.eyebrow || screen.label || payload.label || 'Links',
		title: screen.title || '',
		intro: screen.intro || 'Store links, social proof, trust, and recent content in one Appsite surface.',
		shopLabel: screen.shopLabel || 'Shop',
		shopMeta: screen.shopMeta || 'Open store',
		cartLabel: screen.cartLabel || 'Cart',
		cartMeta: screen.cartMeta || 'Open cart',
	};
}

function social( item, preview, iconSprite, enquiryAvailable, shareAvailable, shareDocument ) {
 const id = item.id || 'link', label = item.label || id, empty = ! item.url;
 if ( empty && ( ! preview || ! item.editUrl ) ) return '';
 const content = '<span class="dsa-social-icon" aria-hidden="true">' + socialGlyph( id, label, iconSprite ) + '</span><span class="dsa-social-link__body"><strong>' + escapeHtml( label ) + '</strong>' + ( empty ? '<small>Add link</small>' : '' ) + '</span>';
 const canChat = enquiryAvailable && ! empty && ( item.kind === 'messaging' || item.chatAvailable || ( item.kind === 'contact' && [ 'whatsapp','email' ].includes( id ) ) );
 const chatLabel = id === 'email' ? 'Email us' : 'Chat on ' + label;
 const iconAction = ( action, text, glyph, href ) => '<' + ( href ? 'a href="' + escapeHtml( href ) + '" rel="noreferrer"' : 'button type="button"' ) + ' class="dsa-links-icon-action" data-kiwe-' + action + '="' + escapeHtml( id ) + '" aria-label="' + escapeHtml( text ) + '" title="' + escapeHtml( text ) + '">' + socialGlyph( glyph, text, iconSprite ) + '</' + ( href ? 'a' : 'button' ) + '>';
 const chat = canChat ? iconAction( 'chat', chatLabel, 'message-circle' ) : '';
 // Direct URLs are projected by the native public-subject owner. Other channels use the
 // current gesture's device share/copy fallback, never another generic confirmation step.
 const directUrl = shareDocument?.links?.[id];
 const direct = typeof directUrl === 'string' && ( directUrl.startsWith( 'https://' ) || directUrl.startsWith( 'mailto:?' ) ) ? directUrl : '';
 const share = shareAvailable && ! empty ? iconAction( 'share', 'Share on ' + label, 'share-2', direct ) : '';
 let main;
 if ( canChat && ( item.kind === 'messaging' || item.kind === 'contact' ) ) main = '<span class="dsa-social-link dsa-social-link--' + escapeHtml( id ) + '">' + content + '</span>';
 else {
  const navigation = item.kind === 'contact' && id === 'email' ? '' : ' target="_blank" rel="noopener noreferrer"';
  main = '<a class="dsa-social-link' + ( empty ? ' dsa-social-link--empty' : '' ) + ' dsa-social-link--' + escapeHtml( id ) + '" href="' + escapeHtml( empty ? item.editUrl : item.url ) + '"' + ( empty ? ' data-dsa-full-navigation aria-label="Add ' + escapeHtml( label ) + ' link"' : navigation ) + '>' + content + '</a>';
 }
 return '<div class="dsa-links-channel">' + main + chat + share + '</div>';
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
	return editor.adminUrl ? '<div class="dsa-links-admin-bar"><a class="dsa-panel__button dsa-links-admin-link" href="' + escapeHtml( editor.adminUrl ) + '" data-dsa-full-navigation>Manage links</a></div>' : '';
}

function linksViewData( payload ) {
	payload = payload || {};
	const hub = payload.hub || {};
	const copy = linksCopy( payload );
	const editor = hub.editor || {};
	const profiles = ( hub.canEdit && Array.isArray( editor.socials ) && editor.socials.length ? editor.socials : ( Array.isArray( hub.socials ) ? hub.socials : [] ) ).map( item => ( { ...item, chatAvailable:!! hub.messaging?.[item.id] } ) ).filter( item => item.url || ! hub.messaging?.[item.id] );
	Object.values( hub.messaging || {} ).forEach( item => { if ( ! profiles.some( profile => profile.id === item.id && profile.url ) ) profiles.push( item ); } );
	const contacts = Array.isArray( hub.contacts ) ? hub.contacts.filter( function ( item ) { return item && item.kind === 'contact' && [ 'whatsapp', 'email' ].indexOf( item.id ) !== -1; } ) : [];
	const socials = contacts.concat( profiles );
	const shop = hub.shop || {};
	const actions = [];
	if ( hub.commerceAvailable && ( shop.url || hub.canEdit ) ) actions.push( shop.url ? '<a class="dsa-shop-link" href="' + escapeHtml( shop.url ) + '" data-dsa-full-navigation><span>' + escapeHtml( shop.label || copy.shopLabel ) + '</span><small>' + escapeHtml( copy.shopMeta ) + '</small></a>' : '<span class="dsa-shop-link dsa-shop-link--empty"><span>' + escapeHtml( shop.label || copy.shopLabel ) + '</span><small>Not set</small></span>' );
	if ( hub.cartAvailable ) actions.push( '<button class="dsa-shop-link dsa-links-cart-button" type="button" data-dsa-links-cart data-dsa-keep-open><span>' + escapeHtml( copy.cartLabel ) + '</span><small>' + escapeHtml( copy.cartMeta ) + '</small></button>' );
	const logo = payload.dark && payload.logoDark ? payload.logoDark : ( hub.logo || '' );
	const name = hub.siteName || payload.documentTitle || copy.title || copy.label;
	const items = Array.isArray( hub.posts ) ? hub.posts : [];
	const checks = Array.isArray( hub.health ) ? hub.health : [];
	const rawScore = hub.score == null ? '' : String( hub.score ).trim();
	const hasScore = rawScore !== '' && Number.isFinite( Number( rawScore ) );
	const score = hasScore ? Math.max( 0, Math.min( 100, Number( rawScore ) ) ) : null;

	return { payload: payload, copy: copy, hub: hub, editor: editor, socials: socials, actions: actions, logo: logo, name: name, items: items, checks: checks, hasScore: hasScore, score: score, scoreLabel: hub.scoreLabel || 'site score' };
}

function linksHero( data ) {
	return '<div class="dsa-links-hero' + ( data.hasScore ? '' : ' dsa-links-hero--no-score' ) + '">' + ( data.logo ? '<img class="dsa-links-logo" src="' + escapeHtml( data.logo ) + '" data-dsa-theme-logo data-light-src="' + escapeHtml( data.hub.logo || '' ) + '" data-dark-src="' + escapeHtml( data.payload.logoDark || data.hub.logo || '' ) + '" alt="' + escapeHtml( data.name ) + '">' : '<div class="dsa-links-logo dsa-links-logo--text">' + escapeHtml( data.name.charAt( 0 ) || 'K' ) + '</div>' ) + ( data.hasScore ? '<div class="dsa-links-score" aria-label="' + escapeHtml( data.scoreLabel || 'Site score' ) + ' ' + escapeHtml( data.score ) + ' out of 100"><span>' + escapeHtml( data.score ) + '</span><small>' + escapeHtml( data.scoreLabel || 'site score' ) + '</small></div>' : '' ) + '</div>';
}

function renderLegacyLinks( payload ) {
	const data = linksViewData( payload );
	return '<section class="dsa-panel dsa-links-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( data.copy.label ) + '"><div class="dsa-links-panel__title"><p class="dsa-hero-kicker">' + escapeHtml( data.copy.eyebrow ) + '</p><h2>' + escapeHtml( data.copy.title || data.name ) + '</h2>' + ( data.copy.intro ? '<p class="dsa-panel__meta">' + escapeHtml( data.copy.intro ) + '</p>' : '' ) + '</div>' + linksHero( data ) + ( data.hub.canEdit ? adminBar( data.editor ) : '' ) + ( data.socials.length ? '<div class="dsa-social-grid">' + data.socials.map( function ( item ) { return social( item, data.hub.canEdit, data.payload.iconSprite, data.hub.enquiryAvailable === true, data.hub.shareAvailable === true, data.hub.shareDocument ); } ).join( '' ) + '</div>' : '' ) + ( data.actions.length ? '<div class="dsa-links-commerce-actions">' + data.actions.join( '' ) + '</div>' : '' ) + ( data.items.length ? posts( data.items, data.hub.postsSection || {} ) : '' ) + ( data.hub.review && data.hub.review.text ? review( data.hub.review ) : '' ) + ( data.checks.length ? '<div class="dsa-health-row" data-dsa-context-slot data-dsa-context-name="links" data-dsa-context-width="dock">' + data.checks.map( health ).join( '' ) + '</div>' : '' ) + '</section>';
}

function renderPrototypeLinks( payload ) {
	const data = linksViewData( payload );
	return [
		'<section class="dsa-panel dsa-links-panel kiwe-links-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( data.copy.label ) + '" data-dsa-links-adapter="prototype-2027">',
		'<div class="kiwe-links-v2027__title"><p class="dsa-hero-kicker">' + escapeHtml( data.copy.eyebrow ) + '</p><h2>' + escapeHtml( data.copy.title || data.name ) + '</h2><p class="dsa-panel__meta">' + escapeHtml( data.copy.intro ) + '</p></div>',
		'<div class="kiwe-links-v2027__identity">' + linksHero( data ) + ( data.hub.canEdit ? adminBar( data.editor ) : '' ) + '</div>',
		data.socials.length ? '<div class="dsa-social-grid kiwe-links-v2027__socials">' + data.socials.map( function ( item ) { return social( item, data.hub.canEdit, data.payload.iconSprite, data.hub.enquiryAvailable === true, data.hub.shareAvailable === true, data.hub.shareDocument ); } ).join( '' ) + '</div>' : '',
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
