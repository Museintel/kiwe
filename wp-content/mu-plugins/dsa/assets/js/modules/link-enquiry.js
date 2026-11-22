const channels = { whatsapp:'WhatsApp', email:'email', x:'X', instagram:'Instagram', facebook:'Messenger' };
const escape = value => String( value == null ? '' : value ).replace( /[&<>"']/g, char => ( { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' } )[char] );

/** Only the qualified server-owned handoff shapes are navigation, never arbitrary response URLs. */
export function safeHandoffUrl( value, channel, gmail = false ) {
	try {
		if ( typeof value !== 'string' || value.length > 4096 || /[\x00-\x20\x7f\\]/.test( value ) ) return '';
		const url = new URL( value );
		if ( url.username || url.password || url.hash ) return '';
		if ( gmail ) return channel === 'email' && url.protocol === 'https:' && url.host === 'mail.google.com' && url.pathname === '/mail/' && Array.from( url.searchParams.keys() ).every( key => [ 'view','fs','to','su','body' ].includes( key ) ) ? value : '';
		if ( channel === 'x' ) return url.protocol === 'https:' && url.host === 'x.com' && url.pathname === '/messages/compose' && /^[1-9][0-9]{0,19}$/.test( url.searchParams.get( 'recipient_id' ) || '' ) && Array.from( url.searchParams.keys() ).every( key => [ 'recipient_id','text' ].includes( key ) ) ? value : '';
		if ( channel === 'facebook' || channel === 'instagram' ) return url.protocol === 'https:' && ! url.search && ( channel === 'facebook' ? url.host === 'm.me' && /^\/[A-Za-z0-9._-]{1,100}\/?$/.test( url.pathname ) : url.host === 'ig.me' && /^\/m\/[A-Za-z0-9._]{1,30}\/?$/.test( url.pathname ) ) ? value : '';
		if ( channel === 'whatsapp' ) return url.protocol === 'https:' && url.host === 'wa.me' && /^\/[1-9][0-9]{5,14}$/.test( url.pathname ) && Array.from( url.searchParams.keys() ).every( key => key === 'text' ) ? value : '';
		return channel === 'email' && url.protocol === 'mailto:' && /^[^\s?&#,%]+@[^\s?&#,%]+$/.test( url.pathname ) && Array.from( url.searchParams.keys() ).every( key => [ 'subject','body' ].includes( key ) ) ? value : '';
	} catch { return ''; }
}

export function render( state ) {
	const label = state.channel === 'email' ? 'Email us' : 'Chat on ' + channels[state.channel];
	const start = '<section class="dsa-panel dsa-links-panel" role="dialog" aria-modal="false" aria-label="' + label + '" data-dsa-enquiry-panel data-dsa-keep-open><button type="button" class="dsa-panel__button" data-dsa-enquiry-back>Back to Links</button><h2>' + label + '</h2>';
	const status = '<p class="dsa-panel__meta" role="' + ( state.error ? 'alert' : 'status' ) + '" data-dsa-enquiry-status>' + escape( state.error || state.status || '' ) + '</p>';
	if ( state.loading ) return start + '<p role="status">Opening your enquiry…</p></section>';
	if ( ! state.draft ) return start + status + '<button type="button" class="dsa-panel__button" data-dsa-enquiry-retry>Try again</button></section>';
	if ( state.draft.saved ) {
		const plan = state.draft.saved;
		const url = safeHandoffUrl( plan.url, state.channel );
		const gmail = safeHandoffUrl( plan.gmailUrl, state.channel, true );
		return start + '<p>Your enquiry is saved for the team. Review and send it in ' + ( state.channel === 'email' ? 'your email app' : channels[state.channel] ) + '.</p>' + ( plan.mode === 'copy' ? '<p>This message needs to be copied and pasted into your app.</p><button type="button" class="dsa-panel__button" data-dsa-enquiry-copy>Copy message</button><label>Message<textarea class="dsa-auth-field dsa-links-textarea" readonly rows="5">' + escape( plan.text ) + '</textarea></label>' : '' ) + '<div class="dsa-auth-actions">' + ( url ? '<a class="dsa-panel__button" href="' + escape( url ) + '" rel="noreferrer">Open ' + ( state.channel === 'email' ? 'email app' : channels[state.channel] ) + '</a>' : '' ) + ( gmail ? '<a class="dsa-panel__button" href="' + escape( gmail ) + '" rel="noreferrer">Open Gmail</a>' : '' ) + '<button type="button" class="dsa-panel__button" data-dsa-enquiry-new>New enquiry</button></div>' + status + '</section>';
	}
	const draft = state.draft;
	const frozen = state.busy || !! draft.attempt;
	const field = ( name, label, type, max, complete ) => '<label>' + label + '<input class="dsa-auth-field" name="' + name + '" type="' + type + '" maxlength="' + max + '" autocomplete="' + complete + '" value="' + escape( draft[name] ) + '" required' + ( frozen ? ' readonly' : '' ) + '></label>';
	return start + '<p class="dsa-panel__meta">Your enquiry is saved before your app opens. New contacts become unverified subscribers, and these details can fill your next form on this browser. Marketing notifications remain your choice.</p><form class="dsa-links-form" data-dsa-enquiry-form>' + field( 'name', 'Your name', 'text', 150, 'name' ) + field( 'contact', state.channel === 'email' ? 'Your email' : state.channel === 'whatsapp' ? 'Your WhatsApp number, including country code' : 'Your email or phone number, including country code', state.channel === 'email' ? 'email' : state.channel === 'whatsapp' ? 'tel' : 'text', state.channel === 'whatsapp' ? 40 : 254, state.channel === 'email' ? 'email' : state.channel === 'whatsapp' ? 'tel' : 'off' ) + '<label>Your message<textarea class="dsa-auth-field dsa-links-textarea" name="message" rows="5" maxlength="4000" required' + ( frozen ? ' readonly' : '' ) + '>' + escape( draft.message ) + '</textarea></label><div hidden aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off" value=""></label></div>' + ( draft.attempt && ! state.busy ? '<p>Keep this message unchanged while its save is being confirmed. Retry to avoid sending a duplicate.</p>' : '' ) + '<div class="dsa-auth-actions"><button type="submit" class="dsa-panel__button"' + ( state.busy ? ' disabled' : '' ) + '>' + ( state.busy ? 'Saving…' : draft.attempt ? 'Retry saving' : 'Save and continue' ) + '</button>' + ( state.channel === 'email' && ! draft.attempt ? '<button type="submit" class="dsa-panel__button" data-dsa-enquiry-client="gmail"' + ( state.busy ? ' disabled' : '' ) + '>Continue in Gmail</button>' : '' ) + '</div>' + status + '</form></section>';
}

/** The bridge owns the existing sheet lifecycle/transport; drafts exist only in this page's memory. */
export function createController( bridge ) {
	const drafts = new Map();
	let state = { active:false }, generation = 0, owner = '';
	const visible = () => state.active && bridge.active() && state.lifecycle === bridge.lifecycle();
	const current = number => number === generation && visible();
	const repaint = () => { if ( visible() && bridge.paint( render( state ) ) !== false ) bind( bridge.panel() ); };
	const remember = ( key, draft ) => { drafts.set( key, draft ); if ( drafts.size > 8 ) drafts.delete( drafts.keys().next().value ); };

	async function open( channel, subjectId, fresh = false ) {
		if ( ! Object.hasOwn( channels, channel ) ) return;
		const number = ++generation;
		state = { active:true, lifecycle:bridge.lifecycle(), channel, subjectId:Number.isSafeInteger( subjectId ) && subjectId > 0 ? subjectId : 0, loading:true, error:'', status:'' };
		repaint();
		try {
			const config = await bridge.get( '/links/enquiry?channel=' + channel + '&subjectId=' + state.subjectId );
			if ( ! current( number ) ) return;
			if ( config.ok !== true || config.channel !== channel || typeof config.ticket !== 'string' || config.ticket.length > 1024 || ! /^[a-f0-9]{64}$/.test( config.draftOwner || '' ) || typeof config.nonce !== 'string' || typeof config.prefill?.name !== 'string' || typeof config.prefill?.contact !== 'string' ) throw Error( 'This enquiry could not be opened. Please try again.' );
			if ( owner && owner !== config.draftOwner ) drafts.clear();
			owner = config.draftOwner;
			bridge.nonce( config.nonce );
			const key = owner + ':' + channel + ':' + state.subjectId;
			let draft = drafts.get( key );
			if ( ! draft || fresh ) draft = { name:config.prefill.name, contact:config.prefill.contact, message:typeof config.openingMessage === 'string' && config.openingMessage.length <= 1000 ? config.openingMessage : '', ticket:config.ticket, attempt:null, saved:null };
			if ( draft.expired ) { draft.ticket = config.ticket; draft.attempt = null; draft.expired = false; }
			remember( key, draft );
			state = { ...state, key, draft, loading:false };
			repaint();
		} catch ( error ) { if ( current( number ) ) { state.loading = false; state.error = error.message || 'The enquiry could not open. Please try again.'; repaint(); } }
	}

	async function submit( client, website ) {
		if ( ! state.draft || state.busy || state.draft.saved ) return;
		const number = generation, draft = state.draft;
		const body = draft.attempt || { channel:state.channel, ticket:draft.ticket, name:draft.name, contact:draft.contact, message:draft.message, subjectId:state.subjectId, website };
		draft.attempt = body; draft.client = draft.client || client;
		state.busy = true; state.error = ''; state.status = 'Saving your enquiry…'; repaint();
		try {
			const result = await bridge.post( '/links/enquiry', body );
			const plan = result.handoff;
			if ( result.ok !== true || result.saved !== true || ! plan || plan.channel !== body.channel || ! [ 'prefill','copy' ].includes( plan.mode ) || plan.sent !== false || typeof plan.text !== 'string' || plan.text.length > 20000 || ! safeHandoffUrl( plan.url, body.channel ) || ( plan.gmailUrl && ! safeHandoffUrl( plan.gmailUrl, body.channel, true ) ) ) throw Error( 'The save could not be confirmed. Keep your message and retry.' );
			draft.saved = plan; draft.attempt = null;
			if ( ! current( number ) ) return;
			state.busy = false; state.status = 'Saved in Kiwe. Sending is completed in your app.'; repaint();
			if ( plan.mode === 'prefill' ) bridge.navigate( draft.client === 'gmail' && plan.gmailUrl ? plan.gmailUrl : plan.url );
		} catch ( error ) {
			if ( error.code === 'kiwe_link_enquiry_expired' ) { draft.expired = true; draft.attempt = null; }
			else if ( error.status >= 400 && error.status < 500 ) { draft.attempt = null; draft.client = ''; }
			if ( ! current( number ) ) return;
			state.busy = false; state.status = ''; state.error = error.message || 'The save could not be confirmed. Keep your message and retry.'; repaint();
		}
	}

	function bind( panel ) {
		if ( ! panel || panel.dsaEnquiryBound ) return;
		panel.dsaEnquiryBound = true;
		panel.addEventListener( 'click', event => {
			const button = event.target.closest?.( '[data-dsa-enquiry-back], [data-dsa-enquiry-retry], [data-dsa-enquiry-new], [data-dsa-enquiry-copy]' );
			if ( ! button || ! panel.contains( button ) ) return;
			event.preventDefault(); event.stopPropagation();
			if ( button.hasAttribute( 'data-dsa-enquiry-back' ) ) { generation++; state.active = false; bridge.back(); return; }
			if ( button.hasAttribute( 'data-dsa-enquiry-copy' ) ) { const number = generation; bridge.copy( state.draft.saved.text ).then( () => { if ( current( number ) ) { state.status = 'Copied. Open your app and paste the message.'; state.error = ''; repaint(); } } ).catch( () => { if ( current( number ) ) { state.error = 'Select and copy the message below, then paste it into your app.'; repaint(); } } ); return; }
			open( state.channel, state.subjectId, button.hasAttribute( 'data-dsa-enquiry-new' ) );
		} );
		panel.addEventListener( 'input', event => { const name = event.target.name; if ( event.target.closest?.( '[data-dsa-enquiry-form]' ) && state.draft && ! state.busy && ! state.draft.attempt && [ 'name','contact','message' ].includes( name ) ) state.draft[name] = event.target.value; } );
		panel.addEventListener( 'submit', event => {
			const form = event.target;
			if ( ! form.matches?.( '[data-dsa-enquiry-form]' ) ) return;
			event.preventDefault(); event.stopPropagation();
			if ( state.draft?.expired ) { open( state.channel, state.subjectId ); return; }
			if ( ! form.reportValidity() ) return;
			if ( ! state.draft.attempt && ! state.busy ) for ( const key of [ 'name','contact','message' ] ) state.draft[key] = form.elements.namedItem( key ).value;
			submit( event.submitter?.dataset.dsaEnquiryClient === 'gmail' ? 'gmail' : 'default', form.elements.website?.value || '' );
		} );
	}
	return { open, render:()=>render( state ), bind, active:visible, pause:()=>{ generation++; state.active = false; }, invalidate:()=>{ generation++; drafts.clear(); owner = ''; state = { active:false }; } };
}
