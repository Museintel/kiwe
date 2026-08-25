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
		const addPlannedPage = event.target.closest( '[data-kiwe-add-planned-page]' );
		if ( addPlannedPage ) {
			const list = root.querySelector( '[data-kiwe-planned-pages]' );
			const template = root.querySelector( '[data-kiwe-planned-page-template]' );
			if ( list && template && list.children.length < 20 ) {
				const index = Array.from( list.querySelectorAll( '[data-kiwe-planned-page-row]' ) ).reduce( function ( highest, row ) {
					const input = row.querySelector( 'input[name]' );
					const match = input ? input.name.match( /plannedPages\]\[(\d+)\]/ ) : null;
					return match ? Math.max( highest, Number( match[1] ) ) : highest;
				}, -1 ) + 1;
				const holder = document.createElement( 'div' );
				holder.innerHTML = template.innerHTML.replaceAll( '__INDEX__', String( index ) );
				const row = holder.firstElementChild;
				if ( row ) { list.appendChild( row ); row.querySelector( 'input' ).focus(); }
			}
		}
		const removePlannedPage = event.target.closest( '[data-kiwe-remove-planned-page]' );
		if ( removePlannedPage ) {
			const list = root.querySelector( '[data-kiwe-planned-pages]' );
			const row = removePlannedPage.closest( '[data-kiwe-planned-page-row]' );
			if ( list && row ) {
				if ( list.querySelectorAll( '[data-kiwe-planned-page-row]' ).length > 1 ) row.remove();
				else { row.querySelector( 'input' ).value = ''; row.querySelector( 'select' ).value = 'primary'; }
			}
		}
		const addTeamMember = event.target.closest( '[data-kiwe-add-team-member]' );
		if ( addTeamMember ) {
			const list = root.querySelector( '[data-kiwe-team-members]' );
			const template = root.querySelector( '[data-kiwe-team-member-template]' );
			if ( list && template && list.children.length < 30 ) {
				const index = Array.from( list.querySelectorAll( '[data-kiwe-team-member]' ) ).reduce( function ( highest, row ) {
					const input = row.querySelector( 'input[name*="[members]"]' );
					const match = input ? input.name.match( /members\]\[(\d+)\]/ ) : null;
					return match ? Math.max( highest, Number( match[1] ) ) : highest;
				}, -1 ) + 1;
				const holder = document.createElement( 'div' );
				holder.innerHTML = template.innerHTML.replaceAll( '__INDEX__', String( index ) );
				const row = holder.firstElementChild;
				if ( row ) { list.appendChild( row ); row.querySelector( '[data-kiwe-person-user]' ).focus(); }
			}
		}
		const removeTeamMember = event.target.closest( '[data-kiwe-remove-team-member]' );
		if ( removeTeamMember ) {
			const row = removeTeamMember.closest( '[data-kiwe-team-member]' );
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
		if ( event.target.matches( '[data-kiwe-team-toggle] input' ) ) syncTeam();
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
		const food = industrySector && industrySector.value === 'food_beverage';
		root.querySelectorAll( '[data-kiwe-food-field]' ).forEach( function ( field ) {
			field.hidden = ! food;
			field.toggleAttribute( 'inert', ! food );
		} );
	}
	if ( industrySector ) industrySector.addEventListener( 'change', syncIndustryFields );
	syncIndustryFields();
	const teamFields = root.querySelector( '[data-kiwe-team-fields]' );
	function syncTeam() {
		const selected = root.querySelector( '[data-kiwe-team-toggle] input:checked' );
		const enabled = selected && selected.value === '1';
		if ( teamFields ) { teamFields.hidden = ! enabled; teamFields.toggleAttribute( 'inert', ! enabled ); }
	}
	syncTeam();

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
