( function () {
	document.addEventListener( 'submit', async function ( event ) {
		const form = event.target.closest( '[data-kiwe-delivery-estimate]' );
		if ( ! form || ! window.DSA?.readDeliveryEstimate ) return;
		event.preventDefault();
		const button = form.querySelector( 'button[type="submit"]' );
		if ( button.disabled ) return;
		const status = form.querySelector( '[role="status"]' );
		const pin = form.elements.pin.value.trim();
		const variation = Number( document.querySelector( 'form.variations_form input[name="variation_id"]' )?.value || 0 );
		button.disabled = true;
		status.textContent = 'Checking delivery…';
		try {
			const result = await window.DSA.readDeliveryEstimate( { product: Number( form.dataset.kiweDeliveryEstimate ), variation, pin } );
			status.textContent = result.dates.length
				? 'Estimated delivery for one item: ' + result.dates.join( ', ' ) + '. Confirm delivery at checkout.'
				: 'No estimated delivery date for this PIN code. Check delivery at checkout.';
		} catch ( error ) {
			status.textContent = error.message || 'Delivery estimates are unavailable. Check delivery at checkout.';
		} finally {
			button.disabled = false;
		}
	} );
}() );
