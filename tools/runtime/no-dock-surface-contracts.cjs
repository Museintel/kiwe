#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const renderer = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/includes/Public_Endpoint/Surface_Renderer.php'), 'utf8');
const surface = fs.readFileSync(path.join(root, 'wp-content/mu-plugins/dsa/assets/js/surface.js'), 'utf8');

const failures = [];
function check(label, condition) {
	if (condition) console.log(`PASS ${label}`);
	else {
		console.error(`FAIL ${label}`);
		failures.push(label);
	}
}

const conditionalCluster = /<\?php if \( \$modules && \( \$dock\['visible'\] \?\? true \) \) : \?>\s*<div class="dsa-dock-cluster" data-dsa-dock-cluster>[\s\S]*?<\/nav>\s*<\/div>\s*<\?php endif; \?>/;

check('empty or hidden dock does not render a material cluster', conditionalCluster.test(renderer));
check('surface root and overlay remain available without a dock', renderer.includes('data-dsa-surface') && renderer.includes('data-dsa-overlay-root'));
check('external module launchers keep their no-dock path', surface.includes("[data-dsa-open-module]") && surface.includes('openOverlay( moduleId'));
const itemCount=surface.match(/const itemCount = (mainDock \?[^;]+\{ return ! button\.hidden; \}\s*\)\.length : Number\( surface\.dataset\.dsaDockItemCount \) \|\| 0);/);
const countItems=itemCount?new Function('mainDock','surface','return '+itemCount[1]):null;
check('geometry tolerates a missing dock cluster',!!countItems&&countItems(null,{dataset:{dsaDockItemCount:'0'}})===0);
check('context-hidden buttons occupy no Dock geometry',!!countItems&&countItems({querySelectorAll:()=>[{hidden:false},{hidden:true},{hidden:false}]},{dataset:{}})===2);

if (failures.length) process.exit(1);
console.log('No-dock Surface contracts verified.');
