const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const css = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/css/surface.css'), 'utf8');
const surface = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/js/surface.js'), 'utf8');
const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

const finalHiddenRule = css.lastIndexOf('/* Closed means absent.');
const finalSheetDisplay = css.lastIndexOf('.dsa-theme-sheet .dsa-overlay-root');

check('closed Sheet overlay has final authoritative display-none state',
	finalHiddenRule > finalSheetDisplay
	&& css.slice(finalHiddenRule).includes('display: none !important')
	&& css.slice(finalHiddenRule).includes('pointer-events: none !important')
	&& css.slice(finalHiddenRule).includes('content: none !important')
);
check('publisher document is never transformed or filtered for a Kiwe overlay',
	css.includes('html.dsa-overlay-active #brx-content {\n\t/* The scrim/sheet owns the visual treatment.')
	&& css.includes('\tfilter: none;\n\ttransform: none;\n\ttransition: none;')
);
check('AdSense and Google ad boundaries are explicit runtime exclusions',
	surface.includes('const advertisingBoundarySelector')
	&& surface.includes("'ins.adsbygoogle'")
	&& surface.includes("'[data-ad-client]'")
	&& surface.includes("'iframe[src*=\"googlesyndication.com\"]'")
	&& surface.includes('isAdvertisingNode( event.target )')
);
check('ad refresh mutations do not drive Kiwe modal or form observers',
	surface.includes('if ( isAdvertisingNode( mutation.target ) ) return false;')
	&& surface.includes('node.nodeType === 1 && ! isAdvertisingNode( node )')
);
check('AdSense retains native navigation and vignette history authority',
	surface.includes('if ( advertisingRuntimePresent() )')
	&& surface.includes('rememberAdvertisingNavigation( link )')
	&& surface.includes('function completeAdvertisingNavigation()')
	&& surface.includes("document.addEventListener( 'click', onAdvertisingNavigationClick, true )")
	&& surface.includes('window.location.assign( pending.href )')
	&& surface.includes('without removing the clicked result from the DOM')
	&& surface.includes('const advertisingHistoryTransition = advertisingHistoryActive || isAdvertisingHistoryHash( window.location.hash )')
	&& surface.includes("window.addEventListener( 'hashchange'")
	&& surface.includes("/^#google_(?:vignette|interstitial|ads?)(?:[=&_-]|$)/i")
);
check('trust rail suppresses inactive badges and commerce badges on editorial sites',
	surface.includes('trust.ssl && trust.ssl.active')
	&& surface.includes('trust.phonekey && trust.phonekey.active')
	&& surface.includes('trust.payment && trust.payment.active')
	&& surface.includes('commerce.available === true')
	&& surface.includes('phonekey.cart && phonekey.cart.available')
);

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} mobile overlay and AdSense contracts passed.`);
if (failed.length) process.exit(1);
