( function () {
	'use strict';
	const root = document.querySelector( '[data-kiwe-onboarding]' );
	if ( ! root ) return;
	const config = window.KIWE_ONBOARDING || {};
	const form = root.querySelector( '[data-kiwe-onboarding-form]' );
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
		status.textContent = ( config.saved && step === panels.length - 1 ? 'Saved · ' : '' ) + 'Step ' + ( step + 1 ) + ' of ' + panels.length;
		window.scrollTo( { top: Math.max( 0, root.offsetTop - 40 ), behavior: 'smooth' } );
	}
	buttons.forEach( function ( button ) { button.addEventListener( 'click', function () { show( button.dataset.kiweStepButton ); } ); } );
	previous.addEventListener( 'click', function () { show( step - 1 ); } );
	next.addEventListener( 'click', function () {
		const invalid = panels[ step ].querySelector( ':invalid' );
		if ( invalid ) { invalid.reportValidity(); invalid.focus(); return; }
		show( step + 1 );
	} );
	if ( form ) {
		form.addEventListener( 'invalid', function ( event ) {
			const panel = event.target.closest( '[data-kiwe-step]' );
			const index = panel ? panels.indexOf( panel ) : -1;
			if ( index >= 0 && index !== step ) show( index );
		}, true );
		form.addEventListener( 'submit', function () {
			save.disabled = true;
			save.textContent = config.saving || 'Saving owner context…';
			status.textContent = config.saving || 'Saving owner context…';
		} );
	}

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
		const clearTone = event.target.closest( '[data-kiwe-clear-tone]' );
		if ( clearTone ) {
			root.querySelectorAll( 'input[name="context[brand][tone]"]' ).forEach( function ( input ) { input.checked = false; } );
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

	const phone = root.querySelector( '[data-kiwe-public-phone]' );
	const whatsappSame = root.querySelector( '[data-kiwe-whatsapp-same]' );
	const whatsappField = root.querySelector( '[data-kiwe-whatsapp-field]' );
	const whatsapp = root.querySelector( '[data-kiwe-whatsapp]' );
	function syncWhatsapp() {
		if ( ! whatsappSame || ! whatsappField || ! whatsapp ) return;
		whatsappField.hidden = whatsappSame.checked;
		whatsappField.toggleAttribute( 'inert', whatsappSame.checked );
		if ( whatsappSame.checked && phone ) whatsapp.value = phone.value;
	}
	if ( whatsappSame ) whatsappSame.addEventListener( 'change', syncWhatsapp );
	if ( phone ) phone.addEventListener( 'input', function () { if ( whatsappSame && whatsappSame.checked && whatsapp ) whatsapp.value = phone.value; } );
	syncWhatsapp();

	const sellingMode = root.querySelector( '[data-kiwe-country-mode="selling"]' );
	const shippingMode = root.querySelector( '[data-kiwe-country-mode="shipping"]' );
	function syncCountryLists() {
		root.querySelectorAll( '[data-kiwe-country-list]' ).forEach( function ( field ) {
			const role = field.dataset.kiweCountryList;
			const visible = ( role === 'selling-specific' && sellingMode && sellingMode.value === 'specific' )
				|| ( role === 'selling-excluded' && sellingMode && sellingMode.value === 'all_except' )
				|| ( role === 'shipping-specific' && shippingMode && shippingMode.value === 'specific' );
			field.hidden = ! visible;
			field.toggleAttribute( 'inert', ! visible );
		} );
	}
	if ( sellingMode ) sellingMode.addEventListener( 'change', syncCountryLists );
	if ( shippingMode ) shippingMode.addEventListener( 'change', syncCountryLists );
	syncCountryLists();
	show( Number.isInteger( Number( config.startStep ) ) ? Number( config.startStep ) : 0 );
}() );
