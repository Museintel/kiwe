#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const cache = read('wp-content/mu-plugins/dsa/includes/Diagnostics/Cache_Maintenance_Service.php');
const database = read('wp-content/mu-plugins/dsa/includes/Diagnostics/Database_Inventory_Service.php');
const assets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
const pwa = read('wp-content/mu-plugins/dsa/includes/PWA/PWA_Service.php');
const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

check('Database & Cache is reachable from the unified System destination', admin.includes("[ 'System', 'kiwe-system', 'render_system_page' ]") && admin.includes("[ 'kiwe-database', 'render_database_page'") && admin.includes("'Database & cache'"));
check('admin toolbar opens the evidence-first cache page', admin.includes("'id'    => 'kiwe-database-cache'") && admin.includes("admin.php?page=kiwe-database"));
check('cache mutation requires capability nonce and confirmation', admin.includes("check_admin_referer( 'dsa_database_purge_cache' )") && admin.includes("$_POST['confirm_cache_purge']"));
check('whole object-cache flush requires an exact phrase', admin.includes("'FLUSH OBJECT CACHE' !== $phrase"));
check('native WordPress cache layers advertise all-device scope only', cache.includes("'scopes'        => [ 'all' ]") && cache.includes("if ( 'all' !== $scope )"));
check('device purge requires an evidence-backed callable adapter', cache.includes("apply_filters( 'dsa_database_cache_adapters', $adapters )") && cache.includes("is_callable( $adapter['purge'] ?? null )") && cache.includes("in_array( $scope, $adapter['scopes'], true )"));
check('official LiteSpeed and WP Rocket all-cache APIs are adapted when present', cache.includes("do_action( 'litespeed_purge_all' )") && cache.includes("function_exists( 'rocket_clean_domain' )") && cache.includes('rocket_clean_domain();'));
check('unsupported page-cache scope is reported instead of claimed', cache.includes('No evidence-backed page-cache adapter supports this scope.'));
check('database ownership separates proven strong heuristic and unknown evidence', database.includes("'confidence' => 'proven'") && database.includes("'confidence' => 'strong'") && database.includes("'confidence' => 'heuristic'") && database.includes("'confidence' => 'unknown'"));
check('database inventory is read-only and contains no broad table deletion', !database.includes('DROP TABLE') && !database.includes('DELETE FROM'));
check('cleanup candidates are reported without destructive implementation', database.includes("'cleanupCandidates'") && admin.includes('Cleanup candidates — read-only in this release'));
check('runtime epoch invalidates Kiwe asset and PWA cache generations', assets.includes("get_option( 'dsa_runtime_cache_epoch', 0 )") && pwa.includes('$runtime_version') && pwa.includes("get_option( 'dsa_runtime_cache_epoch', 0 )"));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} database/cache contracts passed.`);
if (failed.length) process.exit(1);
