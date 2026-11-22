let generation = 0, requests = new WeakMap();
const filled = new Map();
const roles = { name:'name', 'given-name':'first_name', 'family-name':'last_name', email:'email', tel:'phone' };

export function fields( form ) {
	if ( ! form || form.querySelector( 'input[type="password"]' ) ) return [];
	const candidates = Array.from( form.querySelectorAll( 'input' ) ).filter( input => ! input.disabled && ! input.readOnly && ! input.closest( '[hidden],[aria-hidden="true"]' ) && [ 'text','email','tel','' ].includes( input.getAttribute( 'type' ) || '' ) );
	const found = candidates.map( input => {
		const autocomplete = String( input.autocomplete || input.getAttribute( 'autocomplete' ) || '' ).trim().toLowerCase().split( /\s+/ ).pop();
		if ( autocomplete === 'off' ) return null;
		const name = String( input.name || '' ).replace( /^form-field-/, '' ).toLowerCase();
		const role = roles[autocomplete] || ( { name:'name', full_name:'name', fullname:'name', first_name:'first_name', firstname:'first_name', last_name:'last_name', lastname:'last_name', email:'email', billing_email:'email', billing_phone:'phone', phone:'phone', telephone:'phone', billing_first_name:'first_name', billing_last_name:'last_name' } )[name] || ( input.type === 'email' ? 'email' : input.type === 'tel' ? 'phone' : '' );
		return role ? { input, role } : null;
	} ).filter( Boolean );
	return found.filter( row => found.filter( other => other.role === row.role ).length === 1 );
}

export async function fill( form, get ) {
	if ( ! form || ! form.isConnected || ! fields( form ).length ) return;
	if ( requests.has( form ) ) return requests.get( form );
	const current = generation;
	const pending = ( async () => {
		try {
			const result = await get( '/form-contact' );
			if ( current !== generation || ! form.isConnected || result.ok !== true || ! result.contact || typeof result.contact !== 'object' ) return;
			const entries = [];
			for ( const { input, role } of fields( form ) ) {
				const value = result.contact[role];
				if ( input.value || typeof value !== 'string' || ! value || value.length > 600 ) continue;
				// Notify native form frameworks through the standard value setter and ordinary input events.
				const setter = Object.getOwnPropertyDescriptor( Object.getPrototypeOf( input ), 'value' )?.set;
				if ( setter ) setter.call( input, value ); else input.value = value;
				entries.push( { input, value } );
				input.dispatchEvent( new Event( 'input', { bubbles:true } ) ); input.dispatchEvent( new Event( 'change', { bubbles:true } ) );
			}
			filled.set( form, entries );
			for ( const rememberedForm of filled.keys() ) if ( ! rememberedForm.isConnected ) filled.delete( rememberedForm );
			if ( filled.size > 50 ) filled.delete( filled.keys().next().value );
		} catch { requests.delete( form ); /* A failed enhancement never blocks the native form. */ }
	} )();
	requests.set( form, pending ); return pending;
}

export function invalidate() {
	generation++; requests = new WeakMap();
	for ( const entries of filled.values() ) for ( const { input, value } of entries ) if ( input.isConnected && input.value === value ) { input.value = ''; input.dispatchEvent( new Event( 'input', { bubbles:true } ) ); }
	filled.clear();
}
