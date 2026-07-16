function escapeHtml( value ) {
	return String( value == null ? '' : value ).replace( /[&<>'"]/g, function ( character ) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ character ];
	} );
}

function icon( name, sprite ) {
	return '<svg class="dsa-icon" aria-hidden="true" focusable="false"><use href="' + escapeHtml( sprite ) + '#' + escapeHtml( name ) + '"></use></svg>';
}

function profileForm( user ) {
	return [
		'<form class="dsa-profile-form" data-dsa-profile-form>',
		'<input class="dsa-auth-field" id="dsa-profile-first" name="firstName" value="' + escapeHtml( user.firstName || '' ) + '" autocomplete="given-name" aria-label="First name" placeholder="First name">',
		'<input class="dsa-auth-field" id="dsa-profile-last" name="lastName" value="' + escapeHtml( user.lastName || '' ) + '" autocomplete="family-name" aria-label="Last name" placeholder="Last name">',
		'<input class="dsa-auth-field" id="dsa-profile-display" name="displayName" value="' + escapeHtml( user.displayName || '' ) + '" autocomplete="nickname" aria-label="Display name" placeholder="Display name">',
		'<input class="dsa-auth-field" id="dsa-profile-email" name="email" value="' + escapeHtml( user.email || '' ) + '" autocomplete="email" type="email" aria-label="Email" placeholder="Email">',
		user.isAdmin ? '<input class="dsa-auth-field" name="currentPassword" autocomplete="current-password" type="password" aria-label="Current WordPress password" placeholder="Current password (required to change admin email)">' : '',
		'<div class="dsa-profile-email-verify" data-dsa-profile-email-verify hidden><input class="dsa-auth-field" name="emailCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" aria-label="Email verification code" placeholder="6-digit email code"><button class="dsa-panel__button" type="button" data-dsa-profile-email-confirm>Confirm email</button></div>',
		'<div class="dsa-auth-actions"><button class="dsa-panel__button dsa-auth-primary" type="submit">Update profile</button><span class="dsa-panel__meta" data-dsa-profile-message></span></div>',
		'</form>',
	].join( '' );
}

function accountActions( hasWoo, sprite ) {
	return [
		'<div class="dsa-panel__list dsa-account-actions" data-dsa-context-slot data-dsa-context-name="profile" data-dsa-context-width="dock">',
		hasWoo ? '<button class="dsa-panel__button" type="button" data-dsa-account-view="downloads" aria-label="Downloads">' + icon( 'download', sprite ) + '<span>Downloads</span><b class="dsa-context-action__badge" data-dsa-profile-badge="downloads" hidden>0</b></button>' : '',
		hasWoo ? '<button class="dsa-panel__button" type="button" data-dsa-account-view="addresses" aria-label="Addresses">' + icon( 'map-pin', sprite ) + '<span>Addresses</span><b class="dsa-context-action__badge" data-dsa-profile-badge="addresses" hidden>!</b></button>' : '',
		'<button class="dsa-panel__button" type="button" data-dsa-account-view="password" aria-label="Reset password">' + icon( 'key-round', sprite ) + '<span>Password</span></button>',
		'</div>',
	].join( '' );
}

function userInitials( user ) {
	const source = String( user.displayName || user.userLogin || user.email || 'Kiwe' ).trim();
	const parts = source.split( /\s+/ ).filter( Boolean );
	const initials = parts.slice( 0, 2 ).map( function ( part ) {
		return part.charAt( 0 );
	} ).join( '' ).toUpperCase();

	return initials || 'K';
}

function metricCard( value, label, name ) {
	const attr = name ? ' data-dsa-profile-stat="' + escapeHtml( name ) + '"' : '';
	const display = value === 0 || value ? String( value ) : '—';
	return '<span class="kiwe-profile-v2027__metric"><strong' + attr + '>' + escapeHtml( display ) + '</strong><small>' + escapeHtml( label ) + '</small></span>';
}

function profileRow( title, copy, attrs, badge ) {
	return [
		'<button class="kiwe-profile-v2027__row" type="button" ' + attrs + '>',
		'<span><strong>' + escapeHtml( title ) + '</strong><small>' + escapeHtml( copy ) + '</small></span>',
		badge || '',
		'<b aria-hidden="true">&rsaquo;</b>',
		'</button>',
	].join( '' );
}

function renderLegacyProfile( payload ) {
	const user = payload.user || {};
	const status = user.verified
		? '<span class="dsa-panel__status">Verified</span>'
		: '<span class="dsa-panel__status is-unverified">Unverified &middot; verify now</span>';

	return [
		'<section class="dsa-panel dsa-profile-panel" role="dialog" aria-modal="false" aria-label="' + escapeHtml( payload.label || 'Profile' ) + '" data-dsa-profile-panel>',
		'<div class="dsa-panel__header">',
		'<label class="dsa-avatar-editor"><img class="dsa-panel__avatar" src="' + escapeHtml( user.avatar || '' ) + '" alt="" data-dsa-profile-avatar><span>Change photo</span><input type="file" accept="image/*" data-dsa-avatar-input></label>',
		'<div><p class="dsa-panel__name">' + escapeHtml( user.displayName || user.userLogin || 'Account' ) + '</p>' + status + '</div>',
		'</div>',
		profileForm( user ),
		accountActions( Boolean( payload.hasWoo ), payload.iconSprite || '' ),
		'<section class="dsa-recent-orders" data-dsa-recent-orders><div class="dsa-recent-orders__head"><strong>Recent orders</strong><span>Loading...</span></div></section>',
		'<button class="dsa-panel__button dsa-logout-button" type="button" data-dsa-account-logout data-dsa-context-slot data-dsa-context-name="profile" data-dsa-context-width="dock" aria-label="Log out" title="Log out">' + icon( 'log-out', payload.iconSprite || '' ) + '</button>',
		'</section>',
	].join( '' );
}

function renderPrototypeProfile( payload ) {
	const user = payload.user || {};
	const hasWoo = Boolean( payload.hasWoo );
	const displayName = user.displayName || user.userLogin || 'Kiwe account';
	const email = user.email || '';
	const points = user.kiwePoints ?? user.points ?? payload.points ?? null;
	const savedCount = payload.savedCount ?? null;
	const orderCount = payload.orderCount ?? null;
	const downloadBadge = '<b class="dsa-context-action__badge" data-dsa-profile-badge="downloads" hidden>0</b>';
	const addressBadge = '<b class="dsa-context-action__badge" data-dsa-profile-badge="addresses" hidden>!</b>';

	return [
		'<section class="dsa-panel dsa-profile-panel kiwe-profile-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( payload.label || 'Profile' ) + '" data-dsa-profile-panel data-dsa-profile-adapter="prototype-2027">',
		'<div class="kiwe-profile-v2027__title">',
		'<p class="dsa-hero-kicker">Profile &amp; Activity</p>',
		'<h2>Your account</h2>',
		'</div>',
		'<div class="kiwe-profile-v2027__identity">',
		'<label class="kiwe-profile-v2027__avatar"><span aria-hidden="true">' + escapeHtml( userInitials( user ) ) + '</span><img src="' + escapeHtml( user.avatar || '' ) + '" alt="" data-dsa-profile-avatar><input type="file" accept="image/*" data-dsa-avatar-input><em>Change photo</em></label>',
		'<span class="kiwe-profile-v2027__person"><small>Kiwe account</small><strong>' + escapeHtml( displayName ) + '</strong><em>' + escapeHtml( email ) + '</em></span>',
		'<button class="dsa-panel__button kiwe-profile-v2027__edit" type="button" data-dsa-profile-edit aria-expanded="false">Edit</button>',
		'</div>',
		'<div class="kiwe-profile-v2027__edit-region" data-dsa-profile-edit-region hidden>',
		profileForm( user ),
		'</div>',
		'<div class="kiwe-profile-v2027__stats" aria-label="Account activity">',
		metricCard( orderCount, 'Orders', 'orders' ),
		metricCard( savedCount, 'Saved', 'saved' ),
		metricCard( points, 'Kiwe points', 'points' ),
		'</div>',
		'<div class="kiwe-profile-v2027__rows">',
		hasWoo ? profileRow( 'Orders', 'Track, return, buy again', 'data-dsa-account-view="orders" aria-label="Orders"', '' ) : '',
		hasWoo ? profileRow( 'Downloads', 'Access your digital purchases', 'data-dsa-account-view="downloads" aria-label="Downloads"', downloadBadge ) : '',
		profileRow( 'Notification preferences', 'Choose what reaches you', 'data-dsa-profile-notifications aria-label="Notification preferences"', '' ),
		hasWoo ? profileRow( 'Addresses', 'Delivery and billing details', 'data-dsa-account-view="addresses" aria-label="Addresses"', addressBadge ) : '',
		profileRow( 'Password', 'Send a secure reset email', 'data-dsa-account-view="password" aria-label="Reset password"', '' ),
		'<button class="kiwe-profile-v2027__signout" type="button" data-dsa-account-logout>Sign out</button>',
		'</div>',
		'<section class="dsa-recent-orders kiwe-profile-v2027__recent" data-dsa-recent-orders hidden><div class="dsa-recent-orders__head"><strong>Recent orders</strong><span>Loading...</span></div></section>',
		'</section>',
	].join( '' );
}

const profileAdapters = {
	legacy: renderLegacyProfile,
	prototype: renderPrototypeProfile,
	kiwe2027: renderPrototypeProfile,
};

export function render( payload ) {
	const profilePayload = payload || {};
	const requestedProfile = String( profilePayload.visualProfile || 'legacy' );
	const adapter = profileAdapters[ requestedProfile ] || profileAdapters.legacy;
	return adapter( profilePayload );
}
