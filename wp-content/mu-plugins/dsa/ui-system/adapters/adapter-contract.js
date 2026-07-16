/**
 * Kiwe theme adapter contract.
 *
 * This file documents the portable adapter shape. Current production runtime
 * has screen-local visual-profile registries plus a tiny browser bridge at
 * window.DSA.ui; the full marketplace import/export registry is still future
 * work.
 */

/**
 * @typedef {Object} KiweAdapterContext
 * @property {Function} escapeHtml Escape untrusted strings before HTML insertion.
 * @property {Function} icon Render an approved bundled Kiwe/Lucide icon.
 * @property {Object} tokens Runtime token metadata when available.
 * @property {Object} geometry Current Surface geometry state when available.
 * @property {string} visualProfile legacy | prototype | marketplace profile id.
 * @property {Object} ui Runtime bridge exposed as window.DSA.ui when available.
 * @property {Object} ui.seam Optional Seam landmark bridge for active AppShell inspection.
 * @property {Function} ui.seam.landmarks Return semantic landmarks from public Seam attributes and protected Kiwe data-seam-* metadata.
 * @property {Function} ui.seam.describe Describe one element using Seam vocabulary.
 * @property {Function} ui.seam.activePanel Describe the currently mounted DSA panel root.
 */

/**
 * @typedef {Object} KiweScreenAdapter
 * @property {string} id Stable adapter id.
 * @property {string[]} screens Screen ids rendered by this adapter.
 * @property {(payload:Object, context:KiweAdapterContext) => string} render Returns HTML for one DSA panel.
 */

export const requiredPanelAttributes = {
	profile: 'class="dsa-panel dsa-profile-panel" role="dialog" aria-modal="false" data-dsa-profile-panel',
	cart: 'class="dsa-panel dsa-cart-panel" role="dialog" aria-modal="false" data-dsa-cart-panel',
	checkout: 'class="dsa-panel dsa-checkout-panel" role="dialog" aria-modal="false" data-dsa-checkout-panel',
	search: 'class="dsa-panel dsa-search-panel" role="dialog" aria-modal="false" data-dsa-search-panel',
	menu: 'class="dsa-panel dsa-menu-panel" role="dialog" aria-modal="false"',
	saved: 'class="dsa-panel dsa-saved-panel" role="dialog" aria-modal="false" data-dsa-saved-panel'
};

export const forbiddenAdapterActions = [
	'Do not fetch payment/auth/cart endpoints directly from theme code.',
	'Do not persist account, cart, checkout, or PhoneKey state in localStorage.',
	'Do not remove data-dsa-* attributes used by core event handlers.',
	'Do not clone transactional controls; move/rearrange the original action markup or render the same data attributes.'
];
