(function () {
	'use strict';

	var root = window;
	var doc = document;
	var vocabulary = root.DSA_DATA && root.DSA_DATA.seam && root.DSA_DATA.seam.vocabulary ? root.DSA_DATA.seam.vocabulary : null;

	function valuesFor( key ) {
		if ( ! vocabulary ) return [];
		if ( vocabulary.attributes && vocabulary.attributes[ key ] && Array.isArray( vocabulary.attributes[ key ].values ) ) return vocabulary.attributes[ key ].values;
		return Array.isArray( vocabulary[ key ] ) ? vocabulary[ key ] : [];
	}

	function shadowOnlyRoles() {
		var adoption = vocabulary && vocabulary.appShellAdoption ? vocabulary.appShellAdoption : {};
		return adoption.shadowOnly && typeof adoption.shadowOnly === 'object' ? Object.keys( adoption.shadowOnly ) : [];
	}

	function lint() {
		var attrs = [ 'role', 'flow', 'tone', 'scene', 'state', 'motion', 'shape', 'flow-density', 'gap', 'align', 'justify', 'theme' ];
		var problems = [];
		var allowedShadow = {};
		var protectedAttrs = vocabulary && vocabulary.protectedShadowAttributes && Array.isArray( vocabulary.protectedShadowAttributes.attributes ) ? vocabulary.protectedShadowAttributes.attributes : [];
		var behaviorAttrs = vocabulary && Array.isArray( vocabulary.behaviorAttributes ) ? vocabulary.behaviorAttributes : [];

		protectedAttrs.concat( behaviorAttrs ).forEach( function ( attr ) {
			allowedShadow[ attr ] = true;
		} );

		attrs.forEach( function ( attr ) {
			var legal = valuesFor( attr );
			if ( ! legal.length ) return;
			doc.querySelectorAll( '[data-' + attr + '], [data-seam-' + attr + ']' ).forEach( function ( el ) {
				String( el.getAttribute( 'data-' + attr ) || el.getAttribute( 'data-seam-' + attr ) || '' ).split( /\s+/ ).filter( Boolean ).forEach( function ( value ) {
					if ( legal.indexOf( value ) === -1 ) problems.push( { element: el, attr: attr, value: value } );
				} );
			} );
		} );

		doc.querySelectorAll( '[data-seam-root], [data-seam-role], [data-seam-slot], [data-seam-surface-panel]' ).forEach( function ( el ) {
			Array.prototype.slice.call( el.attributes || [] ).forEach( function ( attr ) {
				if ( attr.name.indexOf( 'data-seam-' ) !== 0 ) return;
				if ( ! allowedShadow[ attr.name ] ) problems.push( { element: el, attr: attr.name, value: attr.value, reason: 'unknown-shadow-attribute' } );
			} );
			if ( el.getAttribute( 'data-seam-root' ) === 'kiwe-dsa' && ! el.getAttribute( 'data-seam-role' ) ) {
				problems.push( { element: el, attr: 'data-seam-role', value: '', reason: 'missing-shadow-role' } );
			}
		} );

		doc.querySelectorAll( '[data-seam-root]' ).forEach( function ( seamRoot ) {
			shadowOnlyRoles().forEach( function ( role ) {
				var className = 'seam-' + role;
				seamRoot.querySelectorAll( '.' + className ).forEach( function ( el ) {
					problems.push( { element: el, attr: 'class', value: className, reason: 'shadow-only-public-class' } );
				} );
			} );
		} );

		if ( ! problems.length ) {
			console.info( '[Seam] vocabulary check passed.' );
			return problems;
		}

		console.groupCollapsed( '[Seam] ' + problems.length + ' vocabulary problem(s)' );
		problems.forEach( function ( problem ) {
			console.warn( ( problem.reason || 'invalid-value' ) + ': ' + problem.attr + '="' + problem.value + '" is not in the published Seam contract.', problem.element );
		} );
		console.groupEnd();
		return problems;
	}

	root.Seam = root.Seam || {};
	root.Seam.version = root.Seam.version || '0.3.1';
	root.Seam.vocabulary = vocabulary;
	root.Seam.lint = lint;
})();
