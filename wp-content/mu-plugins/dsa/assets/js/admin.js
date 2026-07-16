( function () {
	'use strict';

	window.DSA_ADMIN_READY = true;

	const data = window.DSA_ADMIN_DATA || {};
	let menuSearchTimer = 0;

	function closestEventTarget( event, selector ) {
		const target = event && event.target;
		return target && typeof target.closest === 'function' ? target.closest( selector ) : null;
	}

	function renumberRows( container, nameRoot ) {
		Array.prototype.forEach.call( container.querySelectorAll( '[data-dsa-menu-row], [data-dsa-message-row]' ), function ( row, index ) {
			Array.prototype.forEach.call( row.querySelectorAll( '[name]' ), function ( field ) {
				field.name = field.name.replace( new RegExp( escapeRegExp( nameRoot ) + '\\[[0-9]+\\]' ), nameRoot + '[' + index + ']' );
			} );
		} );
	}

	function bindRemoveButtons( root, nameRoot ) {
		root.addEventListener( 'click', function ( event ) {
			const button = closestEventTarget( event, '[data-dsa-remove-row]' );

			if ( ! button || ! root.contains( button ) ) {
				return;
			}

			event.preventDefault();
			const row = button.closest( '[data-dsa-menu-row], [data-dsa-message-row]' );

			if ( row ) {
				row.remove();
				renumberRows( root, nameRoot );
			}
		} );
	}

	function nextRowIndex( root, nameRoot ) {
		let next = 0;
		const pattern = new RegExp( escapeRegExp( nameRoot ) + '\\[([0-9]+)\\]' );

		Array.prototype.forEach.call( root.querySelectorAll( '[name]' ), function ( field ) {
			const match = field.name.match( pattern );

			if ( match ) {
				next = Math.max( next, Number( match[1] ) + 1 );
			}
		} );

		return next;
	}

	function menuRowHtml( index, item ) {
		item = item || {};

		return [
			'<div class="dsa-admin-row" data-dsa-menu-row>',
			'<label><span>Title</span><input type="text" name="dock[menu_items][' + index + '][title]" value="' + escapeAttr( item.title || '' ) + '" placeholder="Title"></label>',
			'<label><span>URL</span><input type="url" name="dock[menu_items][' + index + '][url]" value="' + escapeAttr( item.url || '' ) + '" placeholder="/"></label>',
			'<label><span>Type</span><input type="text" name="dock[menu_items][' + index + '][type]" value="' + escapeAttr( item.type || '' ) + '" placeholder="Type"></label>',
			'<label><span>Image URL</span><input type="url" name="dock[menu_items][' + index + '][image]" value="' + escapeAttr( item.image || '' ) + '" placeholder="Optional rounded image"></label>',
			'<input type="hidden" name="dock[menu_items][' + index + '][object_id]" value="' + escapeAttr( item.object_id || 0 ) + '">',
			'<input type="hidden" name="dock[menu_items][' + index + '][object_type]" value="' + escapeAttr( item.object_type || '' ) + '">',
			'<button class="button dsa-admin-remove" type="button" data-dsa-remove-row>Remove</button>',
			'</div>',
		].join( '' );
	}

	function messageRowHtml( index ) {
		return [
			'<div class="dsa-admin-row dsa-admin-row--message" data-dsa-message-row>',
			'<label><span>Title</span><input type="text" name="visual_effects[transition_messages][' + index + '][title]" placeholder="Title, e.g. Did you know"></label>',
			'<label><span>Message</span><textarea name="visual_effects[transition_messages][' + index + '][message]" rows="3" placeholder="Message"></textarea></label>',
			'<button class="button dsa-admin-remove" type="button" data-dsa-remove-row>Remove</button>',
			'</div>',
		].join( '' );
	}

	function initMenuBuilder() {
		const builder = document.querySelector( '[data-dsa-menu-builder]' );

		if ( ! builder ) {
			return;
		}

		const search = builder.querySelector( '[data-dsa-menu-search]' );
		const results = builder.querySelector( '[data-dsa-menu-results]' );
		const rows = builder.querySelector( '[data-dsa-menu-items]' );
		const add = builder.querySelector( '[data-dsa-add-menu-row]' );

		bindRemoveButtons( rows, 'dock[menu_items]' );

		if ( add ) {
			add.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				rows.insertAdjacentHTML( 'beforeend', menuRowHtml( nextRowIndex( rows, 'dock[menu_items]' ) ) );
			} );
		}

		if ( ! search || ! results ) {
			return;
		}

		search.addEventListener( 'input', function () {
			const query = search.value.trim().toLowerCase();

			if ( query.length < 2 ) {
				results.hidden = true;
				results.innerHTML = '';
				return;
			}

			window.clearTimeout( menuSearchTimer );
			results.innerHTML = '<div class="dsa-admin-result dsa-admin-result--empty">Searching...</div>';
			results.hidden = false;
			menuSearchTimer = window.setTimeout( function () {
				searchMenuTargets( query ).then( function ( matches ) {
					if ( search.value.trim().toLowerCase() !== query ) {
						return;
					}

					results.innerHTML = matches.length
						? matches.map( function ( target, index ) {
							return '<button type="button" class="dsa-admin-result" data-index="' + index + '"><strong>' + escapeHtml( target.title ) + '</strong><span>' + escapeHtml( target.type ) + '</span></button>';
						} ).join( '' )
						: '<div class="dsa-admin-result dsa-admin-result--empty">No matching content found.</div>';
					results.hidden = false;
					results.__matches = matches;
				} ).catch( function () {
					results.innerHTML = '<div class="dsa-admin-result dsa-admin-result--empty">No matching content found.</div>';
					results.hidden = false;
					results.__matches = [];
				} );
			}, 180 );
		} );

		results.addEventListener( 'click', function ( event ) {
			const button = closestEventTarget( event, '[data-index]' );
			const matches = results.__matches || [];
			const target = button ? matches[Number( button.dataset.index )] : null;

			if ( ! target ) {
				return;
			}

			rows.insertAdjacentHTML( 'beforeend', menuRowHtml( nextRowIndex( rows, 'dock[menu_items]' ), target ) );
			search.value = '';
			results.hidden = true;
			results.innerHTML = '';
		} );
	}

	function searchMenuTargets( query ) {
		const payload = new window.FormData();
		payload.append( 'action', 'dsa_search_menu_targets' );
		payload.append( 'nonce', data.nonce || '' );
		payload.append( 'query', query );

		return fetch( data.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: payload,
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			if ( ! json || json.success !== true ) {
				throw new Error( 'Search failed' );
			}

			return Array.isArray( json.data ) ? json.data : [];
		} );
	}

	function initMessages() {
		const rows = document.querySelector( '[data-dsa-message-items]' );
		const add = document.querySelector( '[data-dsa-add-message-row]' );

		if ( ! rows ) {
			return;
		}

		bindRemoveButtons( rows, 'visual_effects[transition_messages]' );

		if ( add ) {
			add.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				rows.insertAdjacentHTML( 'beforeend', messageRowHtml( nextRowIndex( rows, 'visual_effects[transition_messages]' ) ) );
			} );
		}
	}

	function initMetricsSummary() {
		const root = document.querySelector( '[data-dsa-metrics-summary]' );

		if ( ! root || ! data.restUrl ) {
			return;
		}

		fetch( data.restUrl + '/metrics/summary', {
			headers: {
				'X-WP-Nonce': data.restNonce || '',
			},
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					if ( ! response.ok ) {
						throw new Error( json.message || 'Metrics unavailable.' );
					}
					return json;
				} );
			} )
			.then( function ( summary ) {
				root.innerHTML = metricsSummaryHtml( summary );
			} )
			.catch( function ( error ) {
				root.innerHTML = '<p>' + escapeHtml( error.message || 'Metrics unavailable.' ) + '</p>';
			} );
	}

	function metricsSummaryHtml( summary ) {
		const totals = summary.totals || {};
		const events = Array.isArray( totals.events ) ? totals.events.slice( 0, 6 ) : [];
		const contexts = Array.isArray( totals.contexts ) ? totals.contexts.slice( 0, 8 ) : [];
		const values = Array.isArray( totals.values ) ? totals.values.slice( 0, 4 ) : [];
		const daily = Array.isArray( summary.daily ) ? summary.daily.slice( 0, 7 ) : [];
		const notes = Array.isArray( summary.notes ) ? summary.notes : [];

		return [
			'<div class="dsa-admin-metrics-grid">',
			metricsCard( 'Total events', String( totals.total || 0 ), summary.enabled ? 'Collecting' : 'Paused' ),
			metricsCard( 'Retention', String( summary.retentionDays || 0 ) + ' days', summary.lastEventAt ? 'Last event ' + summary.lastEventAt : 'No events yet' ),
			metricsList( 'Top events', events, 'key' ),
			metricsList( 'Top surfaces', contexts, 'key' ),
			values.length ? metricsValues( values ) : '',
			daily.length ? metricsDaily( daily ) : '',
			'</div>',
			notes.length ? '<ul class="dsa-admin-metrics-notes">' + notes.map( function ( note ) { return '<li>' + escapeHtml( note ) + '</li>'; } ).join( '' ) + '</ul>' : '',
		].join( '' );
	}

	function metricsCard( title, value, text ) {
		return '<div class="dsa-admin-metric-card"><strong>' + escapeHtml( value ) + '</strong><span>' + escapeHtml( title ) + '</span><small>' + escapeHtml( text || '' ) + '</small></div>';
	}

	function metricsList( title, rows, labelKey ) {
		return [
			'<div class="dsa-admin-metric-card dsa-admin-metric-card--list"><span>' + escapeHtml( title ) + '</span>',
			rows.length ? '<ol>' + rows.map( function ( row ) {
				return '<li><strong>' + escapeHtml( row[labelKey] || '' ) + '</strong><em>' + escapeHtml( row.count || 0 ) + '</em></li>';
			} ).join( '' ) + '</ol>' : '<small>No events yet.</small>',
			'</div>',
		].join( '' );
	}

	function metricsValues( rows ) {
		return [
			'<div class="dsa-admin-metric-card dsa-admin-metric-card--list"><span>Timing and scores</span><ol>',
			rows.map( function ( row ) {
				return '<li><strong>' + escapeHtml( row.event || '' ) + '</strong><em>avg ' + escapeHtml( row.avg || 0 ) + '</em></li>';
			} ).join( '' ),
			'</ol></div>',
		].join( '' );
	}

	function metricsDaily( rows ) {
		return [
			'<div class="dsa-admin-metric-card dsa-admin-metric-card--list"><span>Daily proof</span><ol>',
			rows.map( function ( row ) {
				return '<li><strong>' + escapeHtml( row.day || '' ) + '</strong><em>' + escapeHtml( row.total || 0 ) + '</em></li>';
			} ).join( '' ),
			'</ol></div>',
		].join( '' );
	}

	function initSortableTables() {
		document.querySelectorAll( '.dsa-sortable-table' ).forEach( function ( table ) {
			const tbody = table.tBodies && table.tBodies[0];

			if ( ! tbody ) {
				return;
			}

			Array.prototype.forEach.call( table.querySelectorAll( 'thead th' ), function ( th, index ) {
				th.tabIndex = 0;
				th.style.cursor = 'pointer';
				th.addEventListener( 'click', function () {
					const asc = th.getAttribute( 'data-sort-dir' ) !== 'asc';
					sortTable( tbody, index, asc );
					table.querySelectorAll( 'thead th' ).forEach( function ( header ) {
						header.removeAttribute( 'data-sort-dir' );
					} );
					th.setAttribute( 'data-sort-dir', asc ? 'asc' : 'desc' );
				} );
				th.addEventListener( 'keydown', function ( event ) {
					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						th.click();
					}
				} );
			} );
		} );
	}

	function initDockOrder() {
		document.querySelectorAll( '[data-dsa-dock-order]' ).forEach( function ( list ) {
			let dragging = null;
			list.querySelectorAll( '[data-dsa-dock-item]' ).forEach( function ( item ) {
				item.addEventListener( 'dragstart', function () {
					dragging = item;
					item.classList.add( 'is-dragging' );
				} );
				item.addEventListener( 'dragend', function () {
					item.classList.remove( 'is-dragging' );
					dragging = null;
				} );
			} );
			list.addEventListener( 'dragover', function ( event ) {
				if ( ! dragging ) return;
				event.preventDefault();
				const target = closestEventTarget( event, '[data-dsa-dock-item]' );
				if ( ! target || target === dragging ) return;
				const rect = target.getBoundingClientRect();
				list.insertBefore( dragging, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling );
			} );
		} );
	}

	function initThemeControls() {
		const radios = document.querySelectorAll( 'input[name="style[mode]"]' );
		const controls = document.querySelectorAll( '[data-dsa-theme-controls]' );

		if ( ! radios.length || ! controls.length ) return;

		function sync() {
			const selected = document.querySelector( 'input[name="style[mode]"]:checked' );
			const mode = selected ? selected.value : 'classic';
			controls.forEach( function ( control ) {
				control.hidden = control.dataset.dsaThemeControls !== mode;
			} );
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', sync );
		} );
		sync();
	}

	function sortTable( tbody, index, asc ) {
		const rows = Array.prototype.slice.call( tbody.querySelectorAll( 'tr' ) );

		rows.sort( function ( a, b ) {
			const av = ( a.children[index] ? a.children[index].textContent : '' ).trim();
			const bv = ( b.children[index] ? b.children[index].textContent : '' ).trim();
			const an = Number( av.replace( /[^0-9.-]/g, '' ) );
			const bn = Number( bv.replace( /[^0-9.-]/g, '' ) );
			const result = ! Number.isNaN( an ) && ! Number.isNaN( bn ) && av !== '' && bv !== ''
				? an - bn
				: av.localeCompare( bv );

			return asc ? result : result * -1;
		} );

		rows.forEach( function ( row ) {
			tbody.appendChild( row );
		} );
	}

	function escapeHtml( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function escapeAttr( value ) {
		return escapeHtml( value );
	}

	function escapeRegExp( value ) {
		return String( value ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function initDeveloperTools() {
		const root = document.querySelector( '[data-dsa-developer-tools]' );

		if ( ! root ) {
			return;
		}

		const status = root.querySelector( '[data-dsa-developer-status]' );
		const button = root.querySelector( '[data-dsa-clear-browser]' );
		const resetForm = root.querySelector( '[data-dsa-reset-settings]' );

		function setStatus( message ) {
			if ( status ) {
				status.textContent = message;
			}
		}

		function clearStorage( storage ) {
			if ( ! storage ) return 0;
			let removed = 0;
			const keys = [];
			for ( let index = 0; index < storage.length; index += 1 ) {
				const key = storage.key( index );
				if ( key && /^(?:dsa|kiwe)(?:_|-|:)/i.test( key ) ) keys.push( key );
			}
			keys.forEach( function ( key ) {
				storage.removeItem( key );
				removed += 1;
			} );
			return removed;
		}

		function clearBrowserRuntime() {
			setStatus( 'Clearing this browser’s Kiwe runtime…' );
			const tasks = [];

			if ( navigator.serviceWorker && navigator.serviceWorker.getRegistrations ) {
				tasks.push(
					navigator.serviceWorker.getRegistrations().then( function ( registrations ) {
						return Promise.all( registrations.filter( function ( registration ) {
							const script = registration.active && registration.active.scriptURL
								|| registration.waiting && registration.waiting.scriptURL
								|| registration.installing && registration.installing.scriptURL
								|| '';
							return /(?:dsa_pwa_sw|kiwe)/i.test( script );
						} ).map( function ( registration ) {
							return registration.unregister();
						} ) );
					} )
				);
			}

			if ( window.caches && window.caches.keys ) {
				tasks.push(
					window.caches.keys().then( function ( names ) {
						return Promise.all( names.filter( function ( name ) {
							return /^(?:kiwe|dsa)(?:-|_|:)/i.test( name );
						} ).map( function ( name ) {
							return window.caches.delete( name );
						} ) );
					} )
				);
			}

			try { clearStorage( window.localStorage ); } catch ( error ) {}
			try { clearStorage( window.sessionStorage ); } catch ( error ) {}

			return Promise.all( tasks ).then( function () {
				setStatus( 'This browser’s old Kiwe runtime has been cleared. Reload the frontend once to install the current worker.' );
			} ).catch( function () {
				setStatus( 'Local storage was cleared, but the browser blocked part of service-worker cleanup. Close this tab and reopen the site.' );
			} );
		}

		if ( button ) {
			button.addEventListener( 'click', clearBrowserRuntime );
		}

		if ( resetForm ) {
			resetForm.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( 'Reset Kiwe configuration to defaults? Site content and customer records will remain.' ) ) {
					event.preventDefault();
				}
			} );
		}

		if ( root.dataset.autoClearBrowser === '1' ) {
			clearBrowserRuntime();
		}
	}

	function init() {
		initMenuBuilder();
		initMessages();
		initMetricsSummary();
		initSortableTables();
		initDockOrder();
		initThemeControls();
		initDeveloperTools();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
