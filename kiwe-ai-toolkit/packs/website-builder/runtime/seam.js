(function ( root, doc ) {
	'use strict';

	if ( ! root || ! doc ) return;

	var STATE_CLASS = {
		loading: 'seam-is-loading',
		disabled: 'seam-is-disabled',
		selected: 'seam-is-selected',
		current: 'seam-is-current',
		error: 'seam-is-error',
		success: 'seam-is-success',
		warning: 'seam-is-warning',
		featured: 'seam-is-featured',
		collapsed: 'seam-is-collapsed',
		hidden: 'seam-is-hidden',
		'print-hidden': 'seam-print-hidden'
	};

	function toArray( value ) {
		return Array.prototype.slice.call( value || [] );
	}

	function isElement( value ) {
		return value && value.nodeType === 1;
	}

	function safeToken( value ) {
		return String( value || '' ).trim().toLowerCase().replace( /[^a-z0-9_-]+/g, '-' ).replace( /^-+|-+$/g, '' );
	}

	function tokenList( value ) {
		return String( value || '' ).split( /\s+/ ).map( safeToken ).filter( Boolean );
	}

	function writeTokenList( el, attr, values ) {
		var clean = values.map( safeToken ).filter( Boolean );
		if ( clean.length ) {
			el.setAttribute( attr, clean.join( ' ' ) );
		} else {
			el.removeAttribute( attr );
		}
	}

	function vocabulary() {
		return root.DSA_DATA && root.DSA_DATA.seam && root.DSA_DATA.seam.vocabulary ? root.DSA_DATA.seam.vocabulary : null;
	}

	function adoption( role ) {
		var vocab = vocabulary();
		var map = vocab && vocab.appShellAdoption ? vocab.appShellAdoption : {};
		var token = safeToken( role );
		if ( ! token ) return map || {};
		if ( map.publicAdopted && map.publicAdopted[ token ] ) {
			return Object.assign( { level: 'public-adopted', role: token }, map.publicAdopted[ token ] );
		}
		if ( map.shadowOnly && map.shadowOnly[ token ] ) {
			return { level: 'shadow-only', role: token, reason: map.shadowOnly[ token ] };
		}
		if ( map.authorityOnly && map.authorityOnly.indexOf( token ) !== -1 ) {
			return { level: 'authority-only', role: token };
		}
		return { level: 'public-vocabulary', role: token };
	}

	function legalValues( attrName ) {
		var vocab = vocabulary();
		var key = String( attrName || '' ).replace( /^data-/, '' );
		var entry = vocab && vocab.attributes && vocab.attributes[ key ];
		if ( entry && Array.isArray( entry.values ) ) return entry.values;
		return vocab && Array.isArray( vocab[ key ] ) ? vocab[ key ] : [];
	}

	function isLegal( attrName, value ) {
		var legal = legalValues( attrName );
		return ! legal.length || legal.indexOf( value ) !== -1;
	}

	function selectorFor( attrName, value ) {
		var attr = String( attrName || '' ).replace( /^data-/, '' );
		var token = safeToken( value );
		if ( ! attr || ! token ) return '';
		return '[data-' + attr + '~="' + token + '"], [data-seam-' + attr + '~="' + token + '"]';
	}

	function query( attrName, value, scope ) {
		var selector = selectorFor( attrName, value );
		return selector ? toArray( ( scope || doc ).querySelectorAll( selector ) ) : [];
	}

	function closest( element, attrName, value ) {
		var selector = selectorFor( attrName, value );
		return selector && isElement( element ) ? element.closest( selector ) : null;
	}

	function setAttr( element, attrName, value ) {
		if ( ! isElement( element ) ) return false;
		var attr = String( attrName || '' ).replace( /^data-/, '' );
		var token = safeToken( value );
		if ( ! attr || ! token || ! isLegal( attr, token ) ) return false;
		element.setAttribute( 'data-' + attr, token );
		return true;
	}

	function setState( element, state, active ) {
		if ( ! isElement( element ) ) return false;
		var token = safeToken( state );
		if ( ! token || ! isLegal( 'state', token ) ) return false;
		var next = tokenList( element.getAttribute( 'data-state' ) );
		var has = next.indexOf( token ) !== -1;
		var shouldAdd = active !== false;

		if ( shouldAdd && ! has ) next.push( token );
		if ( ! shouldAdd && has ) next = next.filter( function ( item ) { return item !== token; } );
		writeTokenList( element, 'data-state', next );

		if ( STATE_CLASS[ token ] ) {
			element.classList.toggle( STATE_CLASS[ token ], shouldAdd );
		}

		return true;
	}

	function toggleState( element, state, force ) {
		if ( ! isElement( element ) ) return false;
		var token = safeToken( state );
		var states = tokenList( element.getAttribute( 'data-state' ) );
		var active = typeof force === 'boolean' ? force : states.indexOf( token ) === -1;
		return setState( element, token, active );
	}

	function hasState( element, state ) {
		return isElement( element ) && tokenList( element.getAttribute( 'data-state' ) ).indexOf( safeToken( state ) ) !== -1;
	}

	function describe( element ) {
		if ( ! isElement( element ) ) return {};
		return {
			role: element.getAttribute( 'data-role' ) || element.getAttribute( 'data-seam-role' ) || '',
			flow: element.getAttribute( 'data-flow' ) || element.getAttribute( 'data-seam-flow' ) || '',
			tone: element.getAttribute( 'data-tone' ) || element.getAttribute( 'data-seam-tone' ) || '',
			scene: element.getAttribute( 'data-scene' ) || element.getAttribute( 'data-seam-scene' ) || '',
			state: tokenList( element.getAttribute( 'data-state' ) || element.getAttribute( 'data-seam-state' ) ),
			motion: element.getAttribute( 'data-motion' ) || element.getAttribute( 'data-seam-motion' ) || '',
			shape: element.getAttribute( 'data-shape' ) || element.getAttribute( 'data-seam-shape' ) || '',
			slot: element.getAttribute( 'data-seam-slot' ) || '',
			root: element.getAttribute( 'data-seam-root' ) || '',
			surfacePanel: element.getAttribute( 'data-seam-surface-panel' ) || '',
			authority: element.getAttribute( 'data-seam-authority' ) || ''
		};
	}

	function matchesFilters( description, filters ) {
		filters = filters || {};
		return Object.keys( filters ).every( function ( key ) {
			var expected = filters[ key ];
			if ( expected == null || expected === '' ) return true;
			if ( key === 'state' ) {
				return description.state.indexOf( safeToken( expected ) ) !== -1;
			}
			return String( description[ key ] || '' ) === String( expected );
		} );
	}

	function landmarks( scope, filters ) {
		var rootNode = scope && typeof scope.querySelectorAll === 'function' ? scope : doc;
		var selector = [
			'[data-role]',
			'[data-flow]',
			'[data-tone]',
			'[data-scene]',
			'[data-state]',
			'[data-motion]',
			'[data-shape]',
			'[data-seam-role]',
			'[data-seam-flow]',
			'[data-seam-tone]',
			'[data-seam-scene]',
			'[data-seam-state]',
			'[data-seam-motion]',
			'[data-seam-shape]',
			'[data-seam-slot]',
			'[data-seam-surface-panel]',
			'[data-seam-root]'
		].join( ',' );

		return toArray( rootNode.querySelectorAll( selector ) ).map( function ( element ) {
			return Object.assign( { element: element }, describe( element ) );
		} ).filter( function ( item ) {
			return matchesFilters( item, filters );
		} );
	}

	function ready( callback ) {
		if ( typeof callback !== 'function' ) return;
		if ( doc.readyState === 'loading' ) {
			doc.addEventListener( 'DOMContentLoaded', callback, { once: true } );
		} else {
			callback();
		}
	}

	var seam = root.Seam || {};
	seam.version = seam.version || '0.3.1';
	seam.core = true;
	seam.vocabulary = seam.vocabulary || vocabulary;
	seam.adoption = adoption;
	seam.ready = ready;
	seam.query = query;
	seam.closest = closest;
	seam.setAttr = setAttr;
	seam.setState = setState;
	seam.toggleState = toggleState;
	seam.hasState = hasState;
	seam.describe = describe;
	seam.landmarks = landmarks;

	root.Seam = seam;

	ready( function () {
		doc.dispatchEvent( new CustomEvent( 'seam:ready', { detail: { version: seam.version } } ) );
	} );
})( window, document );
