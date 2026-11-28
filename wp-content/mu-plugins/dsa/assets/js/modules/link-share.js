const escape = value => String( value ?? '' ).replace( /[&<>"']/g, char => ( { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' } )[char] );
const labels = { whatsapp:'WhatsApp', email:'email', x:'X', instagram:'Instagram', facebook:'Facebook', youtube:'YouTube', pinterest:'Pinterest', linkedin:'LinkedIn', native:'your apps', copy:'a link' };

export function safeShareUrl( value, channel ) {
	try {
		if ( typeof value !== 'string' || value.length > 8192 || /[\x00-\x20\x7f\\]/.test( value ) ) return '';
		const url = new URL( value );
		if ( url.username || url.password || url.hash ) return '';
		const keys = Array.from( url.searchParams.keys() );
		if ( channel === 'whatsapp' ) return url.protocol === 'https:' && url.host === 'wa.me' && url.pathname === '/' && keys.length === 1 && keys[0] === 'text' ? value : '';
		if ( channel === 'email' ) return url.protocol === 'mailto:' && ! url.pathname && keys.length === 2 && keys.every( key => [ 'subject','body' ].includes( key ) ) ? value : '';
		if ( channel === 'x' ) return url.protocol === 'https:' && url.host === 'x.com' && url.pathname === '/intent/tweet' && keys.length === 2 && keys.every( key => [ 'text','url' ].includes( key ) ) ? value : '';
		if ( channel === 'facebook' ) return url.protocol === 'https:' && url.host === 'www.facebook.com' && url.pathname === '/sharer/sharer.php' && keys.length === 1 && keys[0] === 'u' ? value : '';
		if ( channel === 'linkedin' ) return url.protocol === 'https:' && url.host === 'www.linkedin.com' && url.pathname === '/sharing/share-offsite/' && keys.length === 1 && keys[0] === 'url' ? value : '';
		if ( channel === 'pinterest' ) return url.protocol === 'https:' && url.host === 'www.pinterest.com' && url.pathname === '/pin/create/button/' && keys.length === 2 && keys.every( key => [ 'url','description' ].includes( key ) ) ? value : '';
	} catch {}
	return '';
}

function publicSubject( value ) {
	try {
		const url = new URL( value?.url );
		return typeof value.title === 'string' && value.title.length <= 1000 && value.url.length <= 2048 && [ 'http:','https:' ].includes( url.protocol ) && ! url.username && ! url.password && ! url.hash && ! /[\x00-\x20\x7f\\]/.test( value.url );
	} catch { return false; }
}

/** Runs immediately within the original click when the public document is already available. */
export function activate( doc, channel, bridge ) {
	if ( ! Object.hasOwn( labels, channel ) || doc?.schema !== 'kiwe.public-share.v1' || doc.sent !== false || ! publicSubject( doc.subject ) ) return false;
	const destination = safeShareUrl( doc.links?.[channel], channel );
	if ( destination ) { bridge.navigate( destination ); return true; }
	const payload = { title:doc.subject.title, url:doc.subject.url };
	const native = channel !== 'copy' && bridge.canShare( payload );
	let result;
	try { result = native ? bridge.share( payload ) : bridge.copy( doc.subject.url ); }
	catch ( error ) { result = Promise.reject( error ); }
	Promise.resolve( result ).then( () => bridge.announce( native ? 'Your device handles sharing in the app you choose.' : 'Public link copied.' + ( [ 'native','copy' ].includes( channel ) ? '' : ' Paste it in ' + labels[channel] + '.' ) ) ).catch( error => {
		if ( error.name === 'AbortError' ) { bridge.announce( 'Share menu closed.' ); return; }
		bridge.fallback( doc, channel );
	} );
	return true;
}

export function render( state ) {
	const start = '<section class="dsa-panel dsa-links-panel" role="dialog" aria-modal="false" aria-label="Share" data-dsa-share-panel data-dsa-keep-open><button type="button" class="dsa-panel__button" data-dsa-share-back>Back to Links</button><h2>Share ' + escape( state.channel === 'native' || state.channel === 'copy' ? '' : 'on ' + labels[state.channel] ) + '</h2>';
	const status = '<p class="dsa-panel__meta" role="' + ( state.error ? 'alert' : 'status' ) + '">' + escape( state.error || state.status || '' ) + '</p>';
	if ( state.loading ) return start + '<p role="status">Preparing the public link…</p></section>';
	if ( ! state.doc ) return start + status + '<button type="button" class="dsa-panel__button" data-dsa-share-retry>Try again</button></section>';
	const subject = state.doc.subject, destination = safeShareUrl( state.doc.links?.[state.channel], state.channel );
	return start + '<h3>' + escape( subject.title ) + '</h3><p class="dsa-panel__meta">' + ( destination ? 'Review and send in ' + labels[state.channel] + '.' : 'Choose ' + escape( labels[state.channel] ) + ' in your device’s share menu if available, or copy the public link and paste it there.' ) + '</p><div class="dsa-auth-actions">' + ( destination ? '<a class="dsa-panel__button" href="' + escape( destination ) + '" rel="noreferrer">Continue to ' + escape( labels[state.channel] ) + '</a>' : '' ) + ( state.nativeAvailable ? '<button type="button" class="dsa-panel__button" data-dsa-share-native>Choose an app</button>' : '' ) + '<button type="button" class="dsa-panel__button" data-dsa-share-copy>Copy link</button></div><label>Public link<input class="dsa-auth-field" readonly aria-label="Public link" value="' + escape( subject.url ) + '"></label>' + status + '</section>';
}

/** Browser/provider sharing is only invoked by a fresh explicit gesture, after metadata has loaded. */
export function createController( bridge ) {
	let state = { active:false }, generation = 0;
	const visible = () => state.active && bridge.active() && state.lifecycle === bridge.lifecycle();
	const current = number => generation === number && visible();
	const repaint = () => { if ( visible() && bridge.paint( render( state ) ) !== false ) bind( bridge.panel() ); };
	async function open( channel, subjectId, initialDocument, referral = false ) {
		if ( ! Object.hasOwn( labels, channel ) ) return;
		const number = ++generation;
		state = { active:true, lifecycle:bridge.lifecycle(), channel, subjectId:Number.isSafeInteger( subjectId ) && subjectId > 0 ? subjectId : 0, loading:true };
		repaint();
		try {
			const doc = initialDocument || await bridge.get( '/links/share?subjectId=' + state.subjectId + ( referral ? '&referral=1' : '' ) );
			if ( ! current( number ) ) return;
			if ( doc.ok !== true || doc.schema !== 'kiwe.public-share.v1' || doc.sent !== false || ! publicSubject( doc.subject ) || ( referral && doc.referral !== true ) ) throw Error( 'This link could not be prepared. Please try again.' );
			state.doc = doc; state.loading = false; state.nativeAvailable = bridge.canShare( { title:doc.subject.title, url:doc.subject.url } ); repaint();
		} catch ( error ) { if ( current( number ) ) { state.loading = false; state.error = error.message || 'Sharing could not open. Please try again.'; repaint(); } }
	}
	function bind( panel ) {
		if ( ! panel || panel.dsaShareBound ) return;
		panel.dsaShareBound = true;
		panel.addEventListener( 'click', event => {
			const button = event.target.closest?.( '[data-dsa-share-back], [data-dsa-share-retry], [data-dsa-share-native], [data-dsa-share-copy]' );
			if ( ! button || ! panel.contains( button ) ) return;
			event.preventDefault(); event.stopPropagation();
			if ( button.hasAttribute( 'data-dsa-share-back' ) ) { generation++; state.active = false; bridge.back(); return; }
			if ( button.hasAttribute( 'data-dsa-share-retry' ) ) { open( state.channel, state.subjectId ); return; }
			if ( ! state.doc ) return;
			const number = generation, subject = state.doc.subject;
			const native = button.hasAttribute( 'data-dsa-share-native' );
			// No awaiting or transport before share(): the browser's user activation belongs to this click.
			let action;
			try { action = native ? bridge.share( { title:subject.title, url:subject.url } ) : bridge.copy( subject.url ); }
			catch ( error ) { action = Promise.reject( error ); }
			Promise.resolve( action ).then( () => { if ( current( number ) ) { state.error = ''; state.status = native ? 'Your device handles sharing in the app you choose.' : 'Public link copied.'; repaint(); } } ).catch( error => { if ( current( number ) ) { state.error = native && error.name === 'AbortError' ? '' : 'Select and copy the public link below, then paste it into your app.'; state.status = native && error.name === 'AbortError' ? 'Share menu closed. You can try again.' : ''; repaint(); } } );
		} );
	}
	return { open, bind, active:visible, render:()=>render( state ), pause:()=>{ generation++; state.active = false; } };
}
