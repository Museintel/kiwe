const drafts = new Map();
const maximumDraftAge = 15 * 60 * 1000;
const escapeHtml = value => String( value == null ? '' : value ).replace( /[&<>"']/g, c => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c] ) );

export function newSendId() {
	if ( globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function' ) return globalThis.crypto.randomUUID();
	const bytes = globalThis.crypto.getRandomValues( new Uint8Array( 16 ) ); bytes[6] = ( bytes[6] & 15 ) | 64; bytes[8] = ( bytes[8] & 63 ) | 128;
	return Array.from( bytes, ( n, i ) => ( [ 4, 6, 8, 10 ].includes( i ) ? '-' : '' ) + n.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

export function verificationContinuation( response ) {
	if ( response.requiresTotp || response.newDevice || response.deviceRecoveryVerified || /^(?:privileged|device_)/.test( response.mode || '' ) || response.next === 'login_passkey' ) return 'secure';
	if ( response.next === 'verify_phone' ) return 'next-code';
	if ( response.redirect || response.alreadyVerified ) return 'complete';
	return response.token && ( response.canLater || response.counterpartRequired ) ? 'partial' : 'secure';
}

function style( stylesheet ) {
	if ( document.querySelector( 'link[data-kiwe-discussion-style]' ) ) return;
	const link = document.createElement( 'link' ); link.rel = 'stylesheet'; link.href = stylesheet || new URL( '../../css/comments.css', import.meta.url ).href; link.dataset.kiweDiscussionStyle = '1'; document.head.appendChild( link );
}

export function renderComments( payload = {} ) {
	const copy = payload.copy || {};
	style( payload.styleUrl );
	return '<section class="dsa-panel dsa-comments dsa-comments__stack" role="dialog" aria-modal="false" aria-label="' + escapeHtml( payload.label || 'Comments' ) + '" data-dsa-comments-panel data-seam-role="section" data-dsa-part="discussion" data-dsa-discussion-id="' + escapeHtml( payload.targetId || 0 ) + '">' +
		'<header class="dsa-comments__header"><span class="dsa-panel__meta">' + escapeHtml( copy.eyebrow || 'Conversation' ) + '</span><h2 data-discussion-heading>' + escapeHtml( copy.title || 'Comments' ) + '</h2><a data-discussion-target></a></header>' +
		'<p class="dsa-comments__status" role="status" aria-live="polite" data-discussion-status>Loading the conversation…</p>' +
		'<div class="dsa-comments__order dsa-comments__row" role="group" aria-label="Comment order"><button type="button" class="dsa-comments__button" data-discussion-sort="top" aria-pressed="true">Top</button><button type="button" class="dsa-comments__button" data-discussion-sort="latest" aria-pressed="false">Latest</button><button type="button" class="dsa-comments__button" data-discussion-refresh hidden>Refresh conversation</button></div>' +
		'<section class="dsa-comments__focus dsa-comments__stack" data-discussion-focus hidden aria-label="From your notification"></section><div class="dsa-comments__list" data-discussion-list></div><button class="dsa-comments__more dsa-comments__button" type="button" data-discussion-more hidden>Show more comments</button>' +
		'<form class="dsa-comments__composer dsa-comments__stack" data-discussion-form hidden><h3>' + escapeHtml( copy.composerTitle || 'Join the conversation' ) + '</h3><p class="dsa-comments__replying" data-discussion-replying hidden></p>' +
		'<fieldset class="dsa-comments__stars" data-discussion-stars hidden><legend>Your rating</legend>' + [ 1, 2, 3, 4, 5 ].map( n => '<label><input type="radio" name="rating" value="' + n + '"><span aria-hidden="true">★</span><span class="dsa-comments__sr">' + n + ( n === 1 ? ' star' : ' stars' ) + '</span></label>' ).join( '' ) + '</fieldset>' +
		'<label class="dsa-auth-field"><span data-discussion-text-label>Your comment</span><textarea name="text" rows="3" maxlength="5000" required placeholder="Share your thoughts…" data-dsa-part="input"></textarea></label>' +
		'<label class="dsa-auth-field" data-discussion-name>Your public name<input name="name" type="text" autocomplete="name" maxlength="60" placeholder="The name others will see"></label>' +
		'<label class="dsa-auth-field" data-discussion-contact>Email or phone number<input name="contact" type="text" autocomplete="username" maxlength="254" placeholder="Kept private"></label>' +
		'<label class="dsa-auth-field" data-discussion-extra-phone hidden>Phone number<input name="phone" type="tel" autocomplete="tel" maxlength="30" placeholder="Include country code"></label>' +
		'<label class="dsa-comments__trap" aria-hidden="true" inert>Website<input name="website" type="text" autocomplete="off" tabindex="-1"></label>' +
		'<p class="dsa-comments__privacy">Your contact stays private. Comments follow this website’s moderation rules.</p>' +
		'<div class="dsa-comments__verification dsa-comments__stack" data-discussion-verification hidden><h4>Confirm it’s you</h4><p data-discussion-code-copy>Enter the code to send your comment.</p><label class="dsa-auth-field">Verification code<input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"></label><div class="dsa-comments__row"><button type="button" class="dsa-comments__button dsa-comments__primary" data-discussion-verify>Verify and send</button><button type="button" class="dsa-comments__button" data-discussion-resend>Send a code</button></div></div>' +
		'<p class="dsa-comments__error" role="alert" data-discussion-error hidden></p><div class="dsa-comments__actions dsa-comments__row"><button type="submit" class="dsa-comments__button dsa-comments__primary" data-discussion-send>Send</button><button type="button" class="dsa-comments__button" data-discussion-skip hidden>Skip verification · send for approval</button><button type="button" class="dsa-comments__button" data-discussion-edit hidden>Edit draft</button><button type="button" class="dsa-comments__button" data-discussion-secure hidden>Continue secure sign-in</button></div></form></section>';
}

function cleanMessage( message ) { return String( message || 'Please try again.' ).replace( /<[^>]*>/g, '' ).replace( /&#(?:0*39|x0*27);/gi, "'" ).replace( /&quot;/g, '"' ).replace( /&amp;/g, '&' ).slice( 0, 500 ); }
function rememberedDraft( id, actor ) {
	for ( const [ key, draft ] of drafts ) if ( Date.now() - draft.updated > maximumDraftAge ) drafts.delete( key );
	let draft = drafts.get( id );
	if ( ! draft || draft.actor !== actor ) draft = { actor, text: '', name: '', contact: '', phone: '', parent: 0, parentName: '', rating: 0, sendId: newSendId(), updated: Date.now() };
	drafts.delete( id ); drafts.set( id, draft ); if ( drafts.size > 4 ) drafts.delete( drafts.keys().next().value );
	return draft;
}

export function bindComments( options ) {
	const panel = options.panel; if ( ! panel || panel.dataset.discussionBound ) return;
	panel.dataset.discussionBound = '1';
	const id = Number( panel.dataset.dsaDiscussionId );
	const q = selector => panel.querySelector( selector );
	const form = q( '[data-discussion-form]' ), status = q( '[data-discussion-status]' ), list = q( '[data-discussion-list]' );
	const alive = () => panel.isConnected && options.isActive();
	const fields = form.elements;
	let composer, draft, flow, busy = false, uncertain = false, next = 0, nextCursor = '', sort = 'top', viewer = {}, readSequence = 0, cooldownUntil = 0, cooldownTimer = 0, frozen = null;
	const reacting = new Set();
	const abort = new AbortController();
	const endpoint = '/discussion/' + id;
	const focusId = Number.isSafeInteger( options.focusId ) && options.focusId > 0 ? options.focusId : 0;
	function error( message ) { const node = q( '[data-discussion-error]' ); node.hidden = ! message; node.textContent = cleanMessage( message ); }
	function setBusy( value ) { busy = value; form.setAttribute( 'aria-busy', String( value ) ); form.querySelectorAll( 'button' ).forEach( button => { button.disabled = value; } ); q( '[data-discussion-resend]' ).disabled = value || Date.now() < cooldownUntil; }
	function save() {
		if ( ! draft || frozen ) return;
		[ 'text', 'name', 'contact', 'phone' ].forEach( key => { draft[key] = fields.namedItem( key ).value; } );
		draft.rating = Number( fields.namedItem( 'rating' ).value ) || 0; draft.updated = Date.now();
	}
	function fill() { [ 'text', 'name', 'contact', 'phone' ].forEach( key => { fields.namedItem( key ).value = draft[key] || ''; } ); for ( const radio of form.querySelectorAll( '[name=rating]' ) ) radio.checked = Number( radio.value ) === draft.rating; }
	function revealComposer() {
		form.hidden = ! composer.target.open; if ( form.hidden ) { status.textContent = 'New comments are closed. You can still read the conversation.'; return; }
		q( '[data-discussion-text-label]' ).textContent = draft.parent ? 'Your reply' : composer.target.kind === 'review' ? 'Your review' : 'Your comment';
		q( '[data-discussion-name]' ).hidden = ! composer.nameRequired; fields.namedItem( 'name' ).required = composer.nameRequired;
		q( '[data-discussion-contact]' ).hidden = ! composer.contactRequired; fields.namedItem( 'contact' ).required = composer.contactRequired;
		const dual = composer.contactRequired && composer.identifierMode === 'email_and_phone';
		q( '[data-discussion-extra-phone]' ).hidden = ! dual; fields.namedItem( 'phone' ).required = dual;
		fields.namedItem( 'contact' ).type = dual || composer.identifierMode === 'email' ? 'email' : 'text';
		const stars = composer.target.review && composer.target.review.stars && ! draft.parent;
		q( '[data-discussion-stars]' ).hidden = ! stars; for ( const radio of form.querySelectorAll( '[name=rating]' ) ) radio.required = Boolean( stars && composer.target.review.starsRequired );
		if ( ! stars ) { draft.rating = 0; for ( const radio of form.querySelectorAll( '[name=rating]' ) ) radio.checked = false; }
		const replying = q( '[data-discussion-replying]' ); replying.hidden = ! draft.parent; replying.replaceChildren();
		if ( draft.parent ) { replying.append( 'Replying to ' + draft.parentName + ' ' ); const cancel = document.createElement( 'button' ); cancel.type = 'button'; cancel.className = 'dsa-comments__button'; cancel.textContent = 'Cancel reply'; cancel.onclick = () => { if ( busy || frozen ) return; draft.parent = 0; draft.parentName = ''; revealComposer(); }; replying.append( cancel ); }
	}
	function setFrozen( active ) { for ( const node of form.querySelectorAll( 'textarea, input:not([name=code]):not([name=website])' ) ) { if ( node.type === 'radio' ) node.disabled = active; else node.readOnly = active; } q( '[data-discussion-edit]' ).hidden = ! active || uncertain; }
	async function request( path, body, retry = false ) {
		const response = await fetch( options.restUrl.replace( /\/$/, '' ) + path, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', signal: body === undefined ? abort.signal : undefined, headers: options.headers( body === undefined ? {} : { 'Content-Type': 'application/json', 'X-Kiwe-Mutation': '1' } ), body: body === undefined ? undefined : JSON.stringify( body ) } );
		const json = await response.json();
		if ( ! response.ok || json.ok === false || ( json.code && json.message ) ) {
			if ( ! retry && json.code === 'rest_cookie_invalid_nonce' ) { await options.refreshNonce(); return request( path, body, true ); }
			const failure = new Error( json.message || 'This request could not be completed.' ); failure.code = json.code; failure.status = response.status >= 400 ? response.status : 400; failure.data = json; throw failure;
		}
		return json;
	}
	function date( value ) { const d = new Date( String( value ).replace( ' ', 'T' ) + 'Z' ); return Number.isNaN( d.getTime() ) ? '' : new Intl.DateTimeFormat( undefined, { dateStyle: 'medium' } ).format( d ); }
	function icon( name ) { return options.iconSprite ? '<svg class="dsa-lucide" aria-hidden="true" viewBox="0 0 24 24"><use href="' + escapeHtml( options.iconSprite + '#' + name ) + '"></use></svg>' : ''; }
	async function react( record, kind, enabled, button ) {
		if ( reacting.has( record.id ) || ! alive() ) return;
		if ( ! viewer.canLike ) { error( 'Sign in with a verified contact to react. Your comment draft stays here.' ); q( '[data-discussion-secure]' ).hidden = false; return; }
		reacting.add( record.id ); button.disabled = true; error( '' );
		const previousMarkup = button.innerHTML; button.setAttribute( 'aria-busy', 'true' ); const copy = button.querySelector( 'span' ); if ( copy ) copy.textContent = 'Saving…';
		const owner = options.scrollOwner ? options.scrollOwner() : panel, scroll = owner.scrollTop;
		try {
			const body = { kind, enabled, actorKey: composer.actorKey }; if ( record.moderation ) body.expected = record.moderation.expected;
			const response = await request( endpoint + '/comments/' + record.id + '/reaction', body ); if ( ! alive() ) return;
			Object.assign( record, response.reaction );
			if ( kind === 'like' || kind === 'report' ) {
				button.innerHTML = icon( kind === 'like' ? 'thumbs-up' : 'flag' ) + '<span>' + escapeHtml( kind === 'like' ? 'Like' + ( record.likes ? ' (' + record.likes + ')' : '' ) : record.reported ? 'Withdraw report' : 'Report' ) + '</span>';
				button.setAttribute( 'aria-pressed', String( kind === 'like' ? record.liked : record.reported ) );
				if ( sort === 'top' && nextCursor ) { nextCursor = ''; q( '[data-discussion-more]' ).hidden = true; q( '[data-discussion-refresh]' ).hidden = false; }
			} else {
				const restoreFocus = document.activeElement === button; await read(); if ( ! alive() ) return;
				owner.scrollTop = scroll;
				if ( restoreFocus ) { const replacement = Array.from( list.querySelectorAll( '[data-comment-id]' ) ).find( node => Number( node.dataset.commentId ) === record.id ); const action = replacement && replacement.querySelector( '[data-discussion-action="' + kind + '"]' ); if ( action ) action.focus( { preventScroll: true } ); }
			}
			if ( kind === 'report' ) status.textContent = enabled ? 'Thank you. The website team can review your report.' : 'Your report was withdrawn.';
		} catch ( failure ) { button.innerHTML = previousMarkup; if ( alive() ) { error( failure.message ); q( '[data-discussion-refresh]' ).hidden = false; } }
		finally { reacting.delete( record.id ); button.disabled = false; button.removeAttribute( 'aria-busy' ); }
	}
	function reactionButton( actions, record, kind, label, pressed, artwork ) {
		const button = document.createElement( 'button' ); button.type = 'button'; button.className = 'dsa-comments__button dsa-comment__reaction'; button.dataset.discussionAction = kind; button.setAttribute( 'aria-pressed', String( Boolean( pressed ) ) );
		button.innerHTML = icon( artwork ) + '<span>' + escapeHtml( label ) + '</span>'; button.onclick = () => react( record, kind, ! record[ { like: 'liked', report: 'reported', heart: 'hearted', pin: 'pinned' }[kind] ], button ); actions.append( button );
	}
	function item( record ) {
		const article = document.createElement( 'article' ); article.className = 'dsa-comment dsa-comments__card'; article.dataset.commentId = String( record.id ); article.dataset.dsaPart = 'card'; article.dataset.seamRole = 'card';
		article.innerHTML = '<header><span class="dsa-comment__avatar" aria-hidden="true">' + escapeHtml( Array.from( record.name || 'R' )[0] ) + '</span><div><strong>' + escapeHtml( record.name ) + '</strong>' + ( record.team ? '<span class="dsa-comment__team">Team</span>' : '' ) + ( record.buyer ? '<span class="dsa-comment__buyer">Verified purchase</span>' : '' ) + '<time>' + escapeHtml( date( record.date ) ) + '</time></div></header>' + ( record.rating ? '<p class="dsa-comment__rating" role="img" aria-label="' + record.rating + ' out of 5 stars">' + '★'.repeat( record.rating ) + '☆'.repeat( 5 - record.rating ) + '</p>' : '' ) + '<p class="dsa-comment__text">' + escapeHtml( record.text ) + '</p><div class="dsa-comment__actions dsa-comments__row"></div><div class="dsa-comment__replies"></div>';
		const actions = article.querySelector( '.dsa-comment__actions' );
		if ( record.pinned ) { const mark = document.createElement( 'p' ); mark.className = 'dsa-comment__pin'; mark.textContent = 'Pinned by the website team'; article.prepend( mark ); }
		if ( record.hearted ) { const mark = document.createElement( 'p' ); mark.className = 'dsa-comment__heart'; mark.innerHTML = icon( 'heart' ) + '<span>Liked by ' + escapeHtml( options.brandLabel || 'the website team' ) + '</span>'; article.append( mark ); }
		if ( viewer.reactionsAvailable ) {
			if ( viewer.canModerate ) reactionButton( actions, record, 'heart', record.hearted ? 'Team heart added' : 'Heart as the team', record.hearted, 'heart' );
			else reactionButton( actions, record, 'like', 'Like' + ( record.likes ? ' (' + record.likes + ')' : '' ), record.liked, 'thumbs-up' );
			if ( viewer.canModerate && ! record.parent ) reactionButton( actions, record, 'pin', record.pinned ? 'Unpin' : 'Pin', record.pinned, 'pin' );
			if ( ! viewer.canModerate ) reactionButton( actions, record, 'report', record.reported ? 'Withdraw report' : 'Report', record.reported, 'flag' );
		}
		if ( composer && composer.target.open && composer.target.threaded && record.depth < composer.target.depth ) { const reply = document.createElement( 'button' ); reply.type = 'button'; reply.className = 'dsa-comments__button'; reply.textContent = 'Reply'; reply.onclick = () => { if ( busy || frozen ) return; save(); draft.parent = record.id; draft.parentName = record.name; draft.sendId = newSendId(); revealComposer(); fields.namedItem( 'text' ).focus( { preventScroll: true } ); form.scrollIntoView( { block: 'nearest', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } ); }; actions.append( reply ); }
		if ( record.replies ) {
			const button = document.createElement( 'button' ); button.type = 'button'; button.className = 'dsa-comments__button'; button.textContent = 'View replies (' + record.replies + ')'; button.setAttribute( 'aria-expanded', 'false' ); let before = 0;
			button.onclick = async () => { button.disabled = true; try { const thread = await request( endpoint + '?parent=' + record.id + '&sort=latest' + ( before ? '&before=' + before : '' ) ); if ( ! alive() ) return; const children = article.querySelector( '.dsa-comment__replies' ); thread.items.forEach( child => children.append( item( child ) ) ); before = thread.nextBefore; button.setAttribute( 'aria-expanded', 'true' ); button.textContent = before ? 'More replies' : 'Replies shown'; button.hidden = ! before; } catch ( e ) { if ( alive() ) error( e.message ); } finally { button.disabled = false; } }; actions.append( button );
		}
		return article;
	}
	async function read( append = false ) {
		const sequence = ++readSequence;
		const continuation = append ? ( sort === 'top' && nextCursor ? '&cursor=' + encodeURIComponent( nextCursor ) : next ? '&before=' + next : '' ) : '';
		let result;
		try { result = await request( endpoint + '?sort=' + sort + continuation + ( focusId && ! append ? '&focus=' + focusId : '' ) ); }
		catch ( failure ) { if ( alive() && sequence === readSequence ) q( '[data-discussion-refresh]' ).hidden = false; throw failure; }
		if ( ! alive() || sequence !== readSequence ) return;
		viewer = result.viewer || {}; sort = result.sort || sort;
		if ( ! append ) {
			list.replaceChildren(); const focus = q( '[data-discussion-focus]' ); focus.replaceChildren(); focus.hidden = ! result.focus;
			if ( result.focus ) { const label = document.createElement( 'p' ); label.textContent = 'From your notification'; focus.append( label, item( result.focus ) ); }
			if ( result.focusUnavailable ) error( 'That contribution is no longer available. The current conversation is shown below.' );
		}
		result.items.filter( record => record.id !== ( result.focus?.id || focusId ) ).forEach( record => list.append( item( record ) ) ); next = result.nextBefore; nextCursor = result.nextCursor || '';
		q( '[data-discussion-heading]' ).textContent = ( ( options.copy || {} ).title || result.target.label ) + ' (' + result.count + ')';
		panel.setAttribute( 'aria-label', result.target.label );
		q( '[data-discussion-target]' ).textContent = result.target.title; q( '[data-discussion-target]' ).href = result.target.url;
		q( '[data-discussion-more]' ).hidden = ! next && ! nextCursor; q( '[data-discussion-refresh]' ).hidden = true;
		panel.querySelectorAll( '[data-discussion-sort]' ).forEach( button => button.setAttribute( 'aria-pressed', String( button.dataset.discussionSort === sort ) ) );
		status.textContent = result.count ? '' : ( options.copy || {} ).emptyText || 'Be the first to join the conversation.'; options.onCount( id, result.count );
	}
	function showVerification() {
		const codeAvailable = Boolean( flow && flow.token && ! flow.secureSigninRequired && ( flow.identifierType !== 'phone' || composer.phoneReady ) );
		q( '[data-discussion-verification]' ).hidden = ! codeAvailable; q( '[data-discussion-send]' ).hidden = Boolean( flow ) || ! composer.phoneKeyAvailable;
		const mayModerate = composer.actor.signedIn || ( flow && flow.subscribeOnSkip ) || ( ! composer.accountRequired && ( ! composer.guestEmailRequired || ( frozen && frozen.email ) ) );
		q( '[data-discussion-skip]' ).hidden = ! mayModerate;
		q( '[data-discussion-code-copy]' ).textContent = flow && flow.identifierType === 'phone' ? 'Enter the code sent to your phone. Your number stays private.' : 'Enter the code sent to your email. Your address stays private.';
		if ( codeAvailable ) fields.namedItem( 'code' ).focus( { preventScroll: true } );
	}
	function cooldown( seconds = 60 ) {
		cooldownUntil = Date.now() + Math.max( 0, Number( seconds ) || 0 ) * 1000; clearTimeout( cooldownTimer );
		const tick = () => { if ( ! alive() ) return; const remaining = Math.max( 0, Math.ceil( ( cooldownUntil - Date.now() ) / 1000 ) ); q( '[data-discussion-resend]' ).textContent = remaining ? 'Resend in ' + remaining + 's' : 'Resend code'; q( '[data-discussion-resend]' ).disabled = busy || remaining > 0; if ( remaining ) cooldownTimer = setTimeout( tick, 1000 ); }; tick();
	}
	async function deliver() { const sent = await options.phoneKeyPost( 'resend-otp', { token: flow.token } ); if ( alive() ) cooldown( sent.resendAfter || 60 ); }
	async function refreshedComposer( allowTransfer = false ) {
		await options.refreshIdentity(); const current = await request( endpoint + '/composer' );
		if ( ! allowTransfer && draft.actor !== current.actorKey ) throw new Error( 'Your signed-in account changed. Reopen the conversation before sending.' );
		if ( allowTransfer && ! composer.actor.signedIn ) {
			const own = options.ownProfile() || {}, claim = String( draft.contact || '' ).trim();
			const matched = claim.includes( '@' ) ? String( own.email || '' ).toLowerCase() === claim.toLowerCase() : String( own.phone || '' ).replace( /\D/g, '' ) === claim.replace( /\D/g, '' );
			if ( ! matched || ! current.actor.signedIn ) throw new Error( 'The account changed during verification. Reopen the conversation to review your draft.' );
		}
		composer = current; draft.actor = current.actorKey; options.nonce( current.nonce ); return current;
	}
	async function publish() {
		if ( ! alive() ) return;
		draft.pendingBody = Object.assign( {}, frozen ); draft.updated = Date.now();
		const body = Object.assign( {}, frozen, { actorKey: composer.actorKey } );
		let receipt;
		try {
			receipt = await request( endpoint + '/comments', body );
			if ( receipt.ok !== true || ! Number.isInteger( receipt.id ) || receipt.id < 1 || ! [ 'published', 'pending' ].includes( receipt.status ) ) throw new Error( 'The Send receipt could not be confirmed.' );
		} catch ( failure ) {
			uncertain = ! failure.status || failure.status >= 500;
			if ( ! uncertain ) { frozen = null; flow = null; delete draft.pendingBody; draft.sendId = newSendId(); setFrozen( false ); q( '[data-discussion-verification]' ).hidden = true; q( '[data-discussion-send]' ).hidden = false; q( '[data-discussion-skip]' ).hidden = true; }
			if ( alive() ) { if ( uncertain ) q( '[data-discussion-edit]' ).hidden = true; error( uncertain ? 'We could not confirm your Send. Retry the same comment; it will be added at most once.' : failure.message ); q( '[data-discussion-send]' ).textContent = uncertain ? 'Retry the same Send' : 'Send'; q( '[data-discussion-send]' ).hidden = false; }
			return;
		}
		drafts.delete( id ); if ( ! alive() ) return;
		const scrollOwner = options.scrollOwner ? options.scrollOwner() : panel;
		const scroll = scrollOwner.scrollTop; flow = null; frozen = null; uncertain = false;
		draft = rememberedDraft( id, composer.actorKey ); draft.name = composer.actor.name || ''; fill(); revealComposer(); setFrozen( false );
		q( '[data-discussion-verification]' ).hidden = true; q( '[data-discussion-send]' ).hidden = false; q( '[data-discussion-send]' ).textContent = 'Send'; q( '[data-discussion-skip]' ).hidden = true; q( '[data-discussion-secure]' ).hidden = true;
		try { composer = await request( endpoint + '/composer' ); if ( ! alive() ) return; draft.actor = composer.actorKey; draft.name = composer.actor.name || ''; fill(); revealComposer(); if ( receipt.status === 'published' ) await read(); }
		catch ( ignored ) { if ( alive() ) error( 'Your Send is confirmed. Reopen the conversation to refresh it.' ); }
		if ( alive() ) { status.textContent = receipt.message; scrollOwner.scrollTop = scroll; }
	}
	async function start() {
		if ( busy || ! composer || ! alive() ) return; error( '' );
		if ( uncertain ) { setBusy( true ); try { await publish(); } catch ( ignored ) {} finally { if ( alive() ) setBusy( false ); } return; }
		if ( ! form.reportValidity() ) return; save();
		frozen = { sendId: draft.sendId, parent: draft.parent, name: draft.name || composer.actor.name, text: draft.text, email: ! composer.actor.signedIn && draft.contact.includes( '@' ) ? draft.contact.trim() : '', rating: draft.rating, website: fields.namedItem( 'website' ).value };
		setFrozen( true ); setBusy( true );
		try {
			if ( composer.actor.verified ) { await publish(); return; }
			if ( ! composer.phoneKeyAvailable ) { showVerification(); error( 'Verification is unavailable. You can send for moderation if this website allows it.' ); return; }
			const input = composer.actor.signedIn ? {} : composer.identifierMode === 'email_and_phone' ? { email: draft.contact.trim(), phone: draft.phone.trim() } : { identifier: draft.contact.trim() };
			flow = await request( endpoint + '/identity', input ); if ( ! alive() ) return;
			if ( flow.alreadyVerified ) { await refreshedComposer(); await publish(); return; }
			showVerification();
			if ( flow.secureSigninRequired ) { q( '[data-discussion-verification]' ).hidden = true; q( '[data-discussion-send]' ).hidden = true; q( '[data-discussion-secure]' ).hidden = false; error( 'This account uses secure sign-in. Continue there to post as its owner, or send for moderation.' ); return; }
			if ( flow.identifierType === 'phone' && ! composer.phoneReady ) { error( q( '[data-discussion-skip]' ).hidden ? 'Phone verification is unavailable. Use this account’s secure sign-in, or edit your draft to use another allowed contact.' : 'Phone verification is unavailable. You can send for moderation without a code.' ); q( '[data-discussion-secure]' ).hidden = false; return; }
			await deliver();
		} catch ( failure ) { if ( alive() ) { showVerification(); error( failure.message ); } }
		finally { if ( alive() ) setBusy( false ); }
	}
	async function verify() {
		if ( busy || ! flow || ! alive() ) return; const code = fields.namedItem( 'code' ).value.trim(); if ( ! /^\d{6}$/.test( code ) ) { error( 'Enter the six-digit code.' ); return; }
		setBusy( true ); error( '' );
		try {
			const proof = await options.phoneKeyPost( 'verify-code', { token: flow.token, code, deferOtpDelivery: true } );
			if ( ! alive() ) return; const nextStep = verificationContinuation( proof );
			if ( nextStep === 'secure' ) { q( '[data-discussion-verification]' ).hidden = true; q( '[data-discussion-secure]' ).hidden = false; error( 'Finish this account’s secure sign-in to publish as its owner. Your draft is retained.' ); return; }
			if ( nextStep === 'next-code' ) { flow = Object.assign( {}, flow, proof, { identifierType: 'phone' } ); fields.namedItem( 'code' ).value = ''; await deliver(); showVerification(); return; }
			if ( nextStep === 'partial' && ! composer.actor.signedIn ) await options.phoneKeyPost( 'continue-later', { token: proof.token } );
			await refreshedComposer( true ); if ( ! composer.actor.verified ) throw new Error( 'Please complete verification before posting immediately.' );
			await publish();
		} catch ( failure ) { if ( alive() ) error( failure.message ); }
		finally { if ( alive() ) setBusy( false ); }
	}
	async function skip() {
		if ( busy || ! frozen || ! alive() ) return; setBusy( true ); error( '' );
		try { if ( flow && flow.subscribeOnSkip && ! composer.actor.signedIn ) { await options.phoneKeyPost( 'continue-later', { token: flow.token } ); await refreshedComposer( true ); } await publish(); }
		catch ( failure ) { if ( alive() ) error( failure.message ); }
		finally { if ( alive() ) setBusy( false ); }
	}
	form.addEventListener( 'input', save ); form.addEventListener( 'submit', event => { event.preventDefault(); if ( flow && ! q( '[data-discussion-verification]' ).hidden ) verify(); else start(); } );
	q( '[data-discussion-verify]' ).onclick = verify; q( '[data-discussion-skip]' ).onclick = skip;
	q( '[data-discussion-edit]' ).onclick = () => { if ( busy || uncertain ) return; frozen = null; flow = null; delete draft.pendingBody; draft.sendId = newSendId(); setFrozen( false ); q( '[data-discussion-verification]' ).hidden = true; q( '[data-discussion-skip]' ).hidden = true; q( '[data-discussion-secure]' ).hidden = true; q( '[data-discussion-send]' ).hidden = false; q( '[data-discussion-send]' ).textContent = 'Send'; error( '' ); fields.namedItem( 'text' ).focus( { preventScroll: true } ); };
	q( '[data-discussion-resend]' ).onclick = async () => { if ( busy || ! flow || Date.now() < cooldownUntil ) return; setBusy( true ); try { await deliver(); error( '' ); } catch ( e ) { if ( alive() ) error( e.message ); } finally { if ( alive() ) setBusy( false ); } };
	q( '[data-discussion-secure]' ).onclick = () => {
		if ( busy || uncertain ) return;
		// Only an explicit handoff persists a short-lived draft across native sign-in/reload. Never save OTPs or tokens.
		try { const own = options.ownProfile() || {}; sessionStorage.setItem( 'kiwe_discussion_signin_draft_v1', JSON.stringify( { id, draft, owner: own.id || 0, at: Date.now() } ) ); } catch ( ignored ) {}
		options.openSignIn();
	};
	q( '[data-discussion-more]' ).onclick = async () => { const button = q( '[data-discussion-more]' ); button.disabled = true; try { await read( true ); } catch ( e ) { if ( alive() ) error( e.message ); } finally { button.disabled = false; } };
	panel.querySelectorAll( '[data-discussion-sort]' ).forEach( button => { button.onclick = async () => { if ( reacting.size ) return; sort = button.dataset.discussionSort; error( '' ); try { await read(); } catch ( failure ) { if ( alive() ) error( failure.message ); } }; } );
	q( '[data-discussion-refresh]' ).onclick = async () => { error( '' ); try { await read(); } catch ( failure ) { if ( alive() ) error( failure.message ); } };
	const close = () => { save(); abort.abort(); clearTimeout( cooldownTimer ); window.removeEventListener( 'surface:overlay:close', close ); window.removeEventListener( 'surface:module:unmount', unmount ); };
	const unmount = event => { if ( ( event.detail || {} ).panel === 'comments' || ! panel.isConnected ) close(); };
	window.addEventListener( 'surface:overlay:close', close, { once: true } );
	window.addEventListener( 'surface:module:unmount', unmount );
	Promise.resolve( options.ready ).then( async () => {
		composer = await request( endpoint + '/composer' ); if ( ! alive() ) return;
		options.nonce( composer.nonce ); draft = rememberedDraft( id, composer.actorKey );
		try {
			const pending = JSON.parse( sessionStorage.getItem( 'kiwe_discussion_signin_draft_v1' ) || 'null' ), own = options.ownProfile() || {};
			if ( pending && pending.id === id ) {
				sessionStorage.removeItem( 'kiwe_discussion_signin_draft_v1' );
				const claim = String( pending.draft.contact || '' ).trim();
				const matchingOwner = pending.owner ? Number( own.id ) === pending.owner : claim.includes( '@' ) ? String( own.email || '' ).toLowerCase() === claim.toLowerCase() : claim && String( own.phone || '' ).replace( /\D/g, '' ) === claim.replace( /\D/g, '' );
				if ( composer.actor.signedIn && matchingOwner && Date.now() >= pending.at && Date.now() - pending.at <= maximumDraftAge ) { draft = Object.assign( {}, pending.draft, { actor: composer.actorKey, sendId: newSendId(), updated: Date.now() } ); drafts.set( id, draft ); }
			}
		} catch ( ignored ) {}
		if ( ! draft.name ) draft.name = composer.actor.name || ''; fill(); revealComposer();
		if ( draft.pendingBody ) { frozen = Object.assign( {}, draft.pendingBody ); uncertain = true; setFrozen( true ); q( '[data-discussion-send]' ).textContent = 'Retry the same Send'; }
		await read(); if ( ! composer.target.open ) revealComposer();
	} ).catch( e => { if ( alive() ) { status.textContent = cleanMessage( e.message ); } } );
}
