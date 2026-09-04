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
		if ( previous ) previous.disabled = step === 0;
		if ( next ) next.hidden = step === panels.length - 1;
		save.hidden = step !== panels.length - 1;
		status.textContent = config.singleSection ? ( config.saved ? 'Saved' : '' ) : ( config.saved && step === panels.length - 1 ? 'Saved · ' : '' ) + 'Step ' + ( step + 1 ) + ' of ' + panels.length;
		window.scrollTo( { top: Math.max( 0, root.offsetTop - 40 ), behavior: 'smooth' } );
	}
	buttons.forEach( function ( button, index ) { button.addEventListener( 'click', function () { show( index ); } ); } );
	panels.forEach( function ( panel, index ) { const number = panel.querySelector( '.kiwe-onboarding__intro > span' ); if ( number ) { number.hidden = !! config.singleSection; number.textContent = String( index + 1 ).padStart( 2, '0' ); } } );
	if ( previous ) previous.addEventListener( 'click', function () { show( step - 1 ); } );
	if ( next ) next.addEventListener( 'click', function () {
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
		const addService = event.target.closest( '[data-kiwe-add-service]' );
		if ( addService ) {
			const list = root.querySelector( '[data-kiwe-services]' );
			const template = root.querySelector( '[data-kiwe-service-template]' );
			if ( list && template && list.children.length < 100 ) {
				const index = Array.from( list.querySelectorAll( '[data-kiwe-service-row]' ) ).reduce( function ( highest, row ) {
					const input = row.querySelector( 'input[name*="[items]"]' );
					const match = input ? input.name.match( /items\]\[(\d+)\]/ ) : null;
					return match ? Math.max( highest, Number( match[1] ) ) : highest;
				}, -1 ) + 1;
				const holder = document.createElement( 'div' );
				holder.innerHTML = template.innerHTML.replaceAll( '__INDEX__', String( index ) );
				const row = holder.firstElementChild;
				if ( row ) { list.appendChild( row ); row.querySelector( 'input[name$="[title]"]' ).focus(); }
			}
		}
		const removeService = event.target.closest( '[data-kiwe-remove-service]' );
		if ( removeService ) {
			const row = removeService.closest( '[data-kiwe-service-row]' );
			if ( row ) row.remove();
		}

	} );

	root.addEventListener( 'change', function ( event ) {
		const userSelect = event.target.closest( '[data-kiwe-person-user]' );
		if ( userSelect && userSelect.value !== '0' ) {
			const person = userSelect.closest( '[data-kiwe-person]' );
			const option = userSelect.options[ userSelect.selectedIndex ];
			let profile = {};
			try { profile = JSON.parse( option.dataset.kiweUserProfile || '{}' ); } catch ( error ) {}
			[ 'name', 'title', 'bio', 'linkedin' ].forEach( function ( field ) {
				const input = person && person.querySelector( '[data-kiwe-person-' + field + ']' );
				if ( input ) input.value = profile[ field ] || '';
			} );
			const imageInput = person && person.querySelector( '[data-kiwe-media-id]' );
			const imagePreview = person && person.querySelector( '[data-kiwe-media-preview]' );
			if ( imageInput ) imageInput.value = profile.imageId || 0;
			if ( imagePreview ) imagePreview.innerHTML = profile.imageUrl ? '<img src="' + String( profile.imageUrl ).replace( /"/g, '&quot;' ) + '" alt="">' : '<em>No image selected</em>';
		}

		if ( event.target.matches( '[data-kiwe-service-source]' ) ) {
			const note = root.querySelector( '[data-kiwe-service-source-note]' );
			if ( note ) note.textContent = event.target.value ? 'Save once to bind this source and load its existing services and taxonomies.' : 'Entries will remain an owner-approved plan until a developer binds a custom post type.';
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
	const industrySector = root.querySelector( '[data-kiwe-industry-sector]' );
	function syncIndustryFields() {
		const food = ( industrySector ? industrySector.value : config.industrySector ) === 'food_beverage';
		root.querySelectorAll( '[data-kiwe-food-field]' ).forEach( function ( field ) {
			field.hidden = ! food;
			field.toggleAttribute( 'inert', ! food );
		} );
	}
	if ( industrySector ) industrySector.addEventListener( 'change', syncIndustryFields );
	syncIndustryFields();

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
	const serviceToggle = root.querySelector( '[data-kiwe-services-toggle]' );
	const serviceFields = root.querySelector( '[data-kiwe-services-fields]' );
	function syncServices() {
		if ( ! serviceToggle || ! serviceFields ) return;
		serviceFields.hidden = ! serviceToggle.checked;
		serviceFields.disabled = ! serviceToggle.checked;
	}
	if ( serviceToggle ) serviceToggle.addEventListener( 'change', syncServices );
	syncServices();
	const initial = panels.findIndex( function ( panel ) { return Number( panel.dataset.kiweStep ) === Number( config.startStep ); } );
	show( config.saved ? panels.length - 1 : Math.max( 0, initial ) );
}() );
