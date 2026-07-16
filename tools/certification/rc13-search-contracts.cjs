const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const checks = [];
const check = (label, condition) => checks.push([label, Boolean(condition)]);

const settings = read('wp-content/mu-plugins/dsa/includes/Settings.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const search = read('wp-content/mu-plugins/dsa/includes/Search/Search_Service.php');
const searchRuntime = read('wp-content/mu-plugins/dsa/assets/js/search.js');
const surfaceRuntime = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const live = read('tools/certification/rc13-public-live.cjs');

check('recommended Search defaults enable Products, Posts, and Authors',
  /'products'\s*=>\s*true/.test(settings)
  && /'posts'\s*=>\s*true/.test(settings)
  && /'authors'\s*=>\s*true/.test(settings));
check('recommended Search defaults enable alphabet and quick add',
  /'alphabet_enabled'\s*=>\s*true/.test(settings)
  && /'product_add_enabled'\s*=>\s*true/.test(settings));
check('unrelated settings forms preserve Search state',
  /if \( ! is_array\( \$input \) \) \{\s*return \$next;/s.test(admin));
check('Search recovery is versioned and signature-specific',
  /SAFETY_MIGRATION_VERSION = 2/.test(settings)
  && /\$collapsed_by_absent_form/.test(settings)
  && /configuration_version'\] = 2/.test(settings));
check('Search REST response declares capability state',
  /'families'\s*=>\s*\$families/.test(search)
  && /'alphabetEnabled'/.test(search));
check('live preflight reports Search deployment state',
  live.includes('Search publishes capability state for deployment diagnosis')
  && live.includes('families=')
  && live.includes('alphabet='));
check('synthetic Surface history cleanup cannot reset Bricks filters',
  /surfaceHistorySuppressPop[\s\S]*?stopImmediatePropagation[\s\S]*?surface:history:released/.test(surfaceRuntime));
check('Search persists only after Surface history release',
  searchRuntime.includes("surface:history:released")
  && searchRuntime.includes('persistBricksHistorySnapshot'));

const failed = checks.filter(([, passed]) => !passed);
for (const [label, passed] of checks) process.stdout.write(`${passed ? 'PASS' : 'FAIL'} ${label}\n`);
process.stdout.write(`${checks.length - failed.length}/${checks.length} RC13 Search contracts passed.\n`);
if (failed.length) process.exit(1);
