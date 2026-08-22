( function () {
	'use strict';
	const root = document.querySelector( '[data-kiwe-onboarding]' );
	if ( ! root ) return;
	const panels = Array.from( root.querySelectorAll( '[data-kiwe-step]' ) );
	const buttons = Array.from( root.querySelectorAll( '[data-kiwe-step-button]' ) );
	const previous = root.querySelector( '[data-kiwe-prev]' );
	const next = root.querySelector( '[data-kiwe-next]' );
	const save = root.querySelector( '[data-kiwe-save]' );
	const status = root.querySelector( '[data-kiwe-step-status]' );
	let step = 0;

	function show( index ) {
		step = Math.max( 0, Math.min( panels.length - 1, Number( index ) || 0 ) );
		panels.forEach( function ( panel, i ) { panel.hidden = i !== step; } );
		buttons.forEach( function ( button, i ) {
			if ( i === step ) button.setAttribute( 'aria-current', 'step' ); else button.removeAttribute( 'aria-current' );
		} );
		previous.disabled = step === 0;
		next.hidden = step === panels.length - 1;
		save.hidden = step !== panels.length - 1;
		status.textContent = 'Step ' + ( step + 1 ) + ' of ' + panels.length;
		window.scrollTo( { top: Math.max( 0, root.offsetTop - 40 ), behavior: 'smooth' } );
	}
	buttons.forEach( function ( button ) { button.addEventListener( 'click', function () { show( button.dataset.kiweStepButton ); } ); } );
	previous.addEventListener( 'click', function () { show( step - 1 ); } );
	next.addEventListener( 'click', function () {
		const invalid = panels[ step ].querySelector( ':invalid' );
		if ( invalid ) { invalid.reportValidity(); invalid.focus(); return; }
		show( step + 1 );
	} );

	root.addEventListener( 'click', function ( event ) {
		const select = event.target.closest( '[data-kiwe-media-select]' );
		if ( select && window.wp && wp.media ) {
			event.preventDefault();
			const field = select.closest( '[data-kiwe-media-field]' );
			const frame = wp.media( { title: ( window.KIWE_ONBOARDING || {} ).chooseImage || 'Choose image', button: { text: ( window.KIWE_ONBOARDING || {} ).useImage || 'Use this image' }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				const image = frame.state().get( 'selection' ).first().toJSON();
				const url = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
				field.querySelector( '[data-kiwe-media-id]' ).value = image.id || 0;
				field.querySelector( '[data-kiwe-media-preview]' ).innerHTML = '<img src="' + String( url ).replace( /"/g, '&quot;' ) + '" alt="">';
			} );
			frame.open();
		}
		const copy = event.target.closest( '[data-kiwe-copy]' );
		if ( copy ) {
			const input = root.querySelector( '[data-kiwe-copy-source]' );
			if ( input && navigator.clipboard ) navigator.clipboard.writeText( input.value ).then( function () { copy.textContent = 'Copied'; } );
		}
	} );

	const timezone = root.querySelector( '[data-kiwe-timezone]' );
	if ( timezone && ! timezone.value && window.Intl ) {
		try { timezone.value = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch ( error ) {}
	}
	const commerceToggle = root.querySelector( '[data-kiwe-commerce-toggle]' );
	const commerceFields = root.querySelector( '[data-kiwe-commerce-fields]' );
	function syncCommerce() { if ( commerceFields && commerceToggle ) commerceFields.toggleAttribute( 'inert', ! commerceToggle.checked ); }
	if ( commerceToggle ) { commerceToggle.addEventListener( 'change', syncCommerce ); syncCommerce(); }
	show( 0 );
}() );
