const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

const profile = read('wp-content/mu-plugins/dsa/assets/js/modules/profile-panel.js');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const panels = read('wp-content/mu-plugins/dsa/assets/js/modules/surface-panels.js');
const assets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const css = read('wp-content/mu-plugins/dsa/assets/css/surface.css');

check('legacy and prototype profiles omit recent orders without commerce capability',
	(profile.match(/hasWoo \? '<section class="dsa-recent-orders/g) || []).length === 2
	&& profile.includes('const hasWoo = Boolean( payload.hasWoo );')
	&& (profile.match(/data-dsa-commerce="' \+ \( hasWoo \? '1' : '0' \) \+ '"/g) || []).length === 2
	&& profile.includes("hasWoo ? metricCard( orderCount, 'Orders', 'orders' ) : ''")
	&& surface.includes("if ( ! target || ! ( phonekey.cart && phonekey.cart.available ) ) return;")
);
check('wide editorial profiles release the commerce column and rebalance by measured layout',
	css.includes('.dsa-profile-panel[data-dsa-commerce="0"]')
	&& css.includes('grid-template-columns: repeat(2, minmax(0, 1fr));')
	&& css.includes('[name="currentPassword"],')
	&& css.includes('grid-column: 1 / -1;')
	&& css.includes(':is([data-dsa-layout="compact"], [data-dsa-layout="narrow"])')
	&& css.includes('@container dsa-surface-viewport (max-width: 520px)')
	&& css.includes('.dsa-profile-panel[data-dsa-commerce] .dsa-profile-form > *')
);
check('Surface sprite icons cannot inherit SVG intrinsic dimensions',
	css.includes('.dsa-surface[data-dsa-ui-contract="2"] .dsa-icon')
	&& css.includes('width: var(--dsa-runtime-token-0167) !important;')
	&& css.includes('height: var(--dsa-runtime-token-0167) !important;')
	&& css.includes('max-width: var(--dsa-runtime-token-0167);')
	&& css.includes('max-height: var(--dsa-runtime-token-0167);')
);
check('circular dismiss controls share optically centred glyph geometry',
	(surface.match(/class="dsa-close-glyph"/g) || []).length >= 5
	&& panels.includes('class="dsa-close-glyph"')
	&& css.includes('.dsa-surface[data-dsa-ui-contract="2"] .dsa-close-glyph')
	&& css.includes('place-content: center;')
	&& css.includes('transform: translateY(-6%);')
);
check('Menu reuses selected WordPress menus and the contextual heading engine',
	admin.includes('name="dock[menu_nav_ids][]"')
	&& admin.includes('name="dock[menu_context_heading_levels][]"')
	&& admin.includes('name="dock[menu_context_locations]')
	&& assets.includes('private function dock_with_nav_menu_items')
	&& assets.includes("sanitize_text_field( wp_specialchars_decode( (string) $item->title, ENT_QUOTES ) )")
	&& assets.includes('private function menu_context_contract')
	&& surface.includes('function collectContextHeadings()')
);
check('profile verification badge launches the canonical PhoneKey journey',
	profile.includes('data-dsa-profile-verify')
	&& profile.includes('function verificationStatus( user )')
	&& surface.includes("closestEventTarget( event, '[data-dsa-profile-verify]' )")
	&& surface.includes('function openProfileVerification()')
	&& surface.includes("reason: 'profile_verification'")
	&& surface.includes('identifyPhoneKey();')
);
check('profile contact controls expose inline email and phone verification states',
	profile.includes("contactField( 'email', user )")
	&& profile.includes("contactField( 'phone', user )")
	&& profile.includes('data-dsa-profile-factor-verify=')
	&& profile.includes('data-dsa-profile-factor-input=')
	&& profile.includes('Add &amp; verify')
	&& profile.includes('>Verified</span>')
	&& css.includes('.dsa-profile-contact__control')
	&& css.includes('.dsa-profile-contact__action')
	&& css.includes('.dsa-profile-contact__state.is-verified')
);
check('profile account actions keep text beside icons until the genuinely small layout',
	profile.includes('dsa-account-action__label')
	&& /dsa-logout-button[\s\S]*?dsa-account-action__label/.test(profile)
	&& css.includes('.dsa-profile-panel .dsa-account-actions .dsa-panel__button,')
	&& css.includes('justify-content: flex-start;')
	&& css.includes('@container dsa-surface-viewport (max-width: 360px)')
	&& css.includes('.dsa-profile-panel .dsa-account-action__label')
);
check('article table of contents prefers the real article body over one giant builder wrapper',
	surface.includes('const articleBodySelector =')
	&& surface.includes("article .entry-content, article .post-content")
	&& surface.includes('if ( sections.length > 1 ) return sections;')
	&& surface.includes('articleHeadings.unshift( primaryHeading );')
);
check('Menu context tracks the in-view heading and preserves sticky-header clearance',
	surface.includes('function pageScrollHeaderOffset()')
	&& surface.includes('function updateMenuContextActiveState( buttons, reveal )')
	&& surface.includes('function bindMenuContextTracking( buttons )')
	&& surface.includes("button.classList.toggle( 'is-active', active )")
	&& surface.includes("button.setAttribute( 'aria-current', 'location' )")
	&& surface.includes('window.scrollY + target.getBoundingClientRect().top - offset')
	&& surface.includes("buttons.some( function ( button ) { return button.isConnected; } )")
	&& surface.includes("activeButton.scrollIntoView( { block: 'center'")
	&& css.includes('.dsa-menu-context__list button.is-active')
	&& css.includes('color: var(--dsa-active-color);')
);
check('Menu opens at its own top without forcing the active TOC item into view',
	surface.includes('function resetOverlayScrollPosition()')
	&& surface.includes('overlayRoot.scrollTop = 0;')
	&& surface.includes("const panel = overlayRoot.querySelector( '[role=\"dialog\"]' );")
	&& surface.includes('if ( panel ) panel.scrollTop = 0;')
	&& surface.includes('updateMenuContextActiveState( buttons );')
	&& ! surface.includes('updateMenuContextActiveState( buttons, true );')
);
check('post category context highlights the matching navigation item',
	assets.includes("'categoryIds' => $this->current_post_category_ids(),")
	&& assets.includes("if ( ! is_singular( 'post' ) )")
	&& assets.includes("wp_get_post_categories(")
	&& surface.includes("objectType === 'category' && categoryIds.includes( objectId )")
	&& panels.includes("item.isActive ? ' aria-current=\"page\"' : ''")
);
check('Menu navigation scale is administrator-controlled and defaults compact',
	admin.includes('name="dock[menu_scale]"')
	&& admin.includes("[ 'compact', 'balanced', 'expressive' ]")
	&& surface.includes('surface.dataset.dsaMenuScale = menuScale')
	&& css.includes('.dsa-surface[data-dsa-menu-scale="compact"]')
	&& css.includes('--dsa-runtime-token-0720: clamp(24px, 3.6vw, 46px);')
	&& css.includes('--dsa-runtime-token-0319: var(--dsa-runtime-token-0720);')
);
check('closing a Surface restores reading position after synthetic history release',
	surface.includes('function restoreSurfaceScrollPosition( position )')
	&& surface.includes('restoreSurfaceScrollPosition( surfaceScrollY );')
	&& surface.includes('if ( ! menuAnchorNavigationPending ) restoreSurfaceScrollPosition( surfaceScrollY );')
);
const menuBinding = surface.slice(surface.indexOf('function bindMenuPanel()'), surface.indexOf('function pageScrollHeaderOffset()'));
check('TOC selection converts the synthetic Sheet entry into the article anchor without a late history rollback',
	menuBinding.includes('surfaceHistoryActive = false;')
	&& menuBinding.includes('closeOverlay( false, { retainHistory: true, immediate: true } );')
	&& menuBinding.includes('delete state.kiweSurface;')
	&& menuBinding.includes('window.history.replaceState')
	&& !menuBinding.includes('window.history.back(')
);

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} profile, menu, and geometry contracts passed.`);
if (failed.length) process.exit(1);
