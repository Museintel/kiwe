/**
 * Kiwe 2027 Profile adapter example.
 *
 * This demonstrates how a theme can rearrange the same Profile capabilities
 * without creating new account authority. It is not imported by production
 * runtime yet.
 */

export const profileKiwe2027Example = {
	id: 'kiwe-2027.profile',
	screens: [ 'profile' ],
	render( payload, context ) {
		const escapeHtml = context.escapeHtml;
		const user = payload.user || {};
		const display = user.displayName || user.name || 'Kiwe account';
		const email = user.email || '';
		const initials = String( display || 'K' ).trim().split( /\s+/ ).slice( 0, 2 ).map( function ( part ) { return part.charAt( 0 ); } ).join( '' ).toUpperCase() || 'K';
		const hasWoo = payload.hasWoo !== false;

		return [
			'<section class="dsa-panel dsa-profile-panel kiwe-profile-v2027" role="dialog" aria-modal="false" aria-label="' + escapeHtml( payload.label || 'Profile' ) + '" data-dsa-profile-panel>',
				'<p class="dsa-hero-kicker">Profile &amp; Activity</p>',
				'<h2>Your account</h2>',
				'<div class="kiwe-profile-v2027__identity">',
					'<span class="kiwe-profile-v2027__avatar" aria-hidden="true">' + escapeHtml( initials ) + '</span>',
					'<span><small>Kiwe account</small><strong>' + escapeHtml( display ) + '</strong><em>' + escapeHtml( email ) + '</em></span>',
					'<button class="dsa-panel__button" type="button" data-dsa-profile-edit>Edit</button>',
				'</div>',
				'<div class="kiwe-profile-v2027__stats">',
					'<span><strong>' + escapeHtml( payload.orderCount || '0' ) + '</strong><small>Orders</small></span>',
					'<span><strong>' + escapeHtml( payload.savedCount || '0' ) + '</strong><small>Saved</small></span>',
					'<span><strong>' + escapeHtml( payload.points || '0' ) + '</strong><small>Kiwe points</small></span>',
				'</div>',
				'<div class="kiwe-profile-v2027__rows">',
					hasWoo ? '<button class="kiwe-profile-v2027__row" type="button" data-dsa-account-view="orders"><span><strong>Orders</strong><small>Track, return, buy again</small></span><b aria-hidden="true">›</b></button>' : '',
					'<button class="kiwe-profile-v2027__row" type="button" data-dsa-profile-notifications><span><strong>Notification preferences</strong><small>Choose what reaches you</small></span><b aria-hidden="true">›</b></button>',
					hasWoo ? '<button class="kiwe-profile-v2027__row" type="button" data-dsa-account-view="addresses"><span><strong>Addresses</strong><small>Delivery and billing details</small></span><b aria-hidden="true">›</b></button>' : '',
					'<button class="kiwe-profile-v2027__signout" type="button" data-dsa-account-logout>Sign out</button>',
				'</div>',
				'<form class="dsa-profile-form" data-dsa-profile-form hidden></form>',
				'<section class="dsa-recent-orders" data-dsa-recent-orders hidden></section>',
			'</section>'
		].join( '' );
	}
};
