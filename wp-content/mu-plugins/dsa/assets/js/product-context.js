( function () {
	'use strict';
	const root = document.querySelector( '[data-kiwe-product-context]' );
	if ( ! root || ! window.wp || ! wp.media ) return;
	const input = root.querySelector( '[data-kiwe-nutrition-image-id]' );
	const preview = root.querySelector( '[data-kiwe-nutrition-preview]' );
	const remove = root.querySelector( '[data-kiwe-nutrition-remove]' );
	root.querySelector( '[data-kiwe-nutrition-select]' )?.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		const frame = wp.media( { title: 'Choose nutrition information image', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			const image = frame.state().get( 'selection' ).first().toJSON();
			const url = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
			input.value = image.id || 0;
			preview.innerHTML = '<img src="' + String( url ).replace( /"/g, '&quot;' ) + '" alt="" style="display:block;max-width:180px;height:auto;margin:0 0 8px">';
			remove.hidden = false;
		} );
		frame.open();
	} );
	remove?.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		input.value = '0';
		preview.replaceChildren();
		remove.hidden = true;
	} );
}() );
