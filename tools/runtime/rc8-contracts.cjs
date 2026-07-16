const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const pkg = path.join(root, 'wp-content', 'mu-plugins', 'dsa');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const checks = [];
const check = (name, pass, detail = '') => checks.push({ name, pass: Boolean(pass), detail });

const loader = read('wp-content/mu-plugins/dsa.php');
const bootstrap = read('wp-content/mu-plugins/dsa/dsa.php');
const settings = read('wp-content/mu-plugins/dsa/includes/Settings.php');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const autoloader = read('wp-content/mu-plugins/dsa/includes/Autoloader.php');
const assets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
const manifest = read('wp-content/mu-plugins/dsa/includes/Runtime/Package_Manifest.php');

const loaderVersion = (loader.match(/KIWE_MU_LOADER_VERSION',\s*'([^']+)'/) || [])[1];
const packageVersion = (bootstrap.match(/define\(\s*'DSA_VERSION',\s*'([^']+)'/) || [])[1];
check('loader and package versions are synchronized', loaderVersion && loaderVersion === packageVersion, `${loaderVersion} / ${packageVersion}`);
check('bootstrap delegates to package manifest', bootstrap.includes('Package_Manifest::verify()'));
check('bootstrap no longer scans a hardcoded file list', !bootstrap.includes('$dsa_required_files') && !bootstrap.includes('foreach ( $dsa_required_files'));
check('incomplete package fails open', bootstrap.includes("empty( $dsa_package_proof['valid'] )") && !bootstrap.includes('throw new RuntimeException'));
check('manifest proof is cached and expires', manifest.includes('CACHE_OPTION') && manifest.includes('CACHE_TTL') && manifest.includes('checked_at'));
check('settings constructor performs no migration I/O', !/function __construct\s*\([^)]*\)\s*\{[\s\S]*?run_(?:safety_)?migrations/.test(settings));
check('settings migration is explicit', settings.includes('public function run_migrations(): void') && plugin.indexOf('$this->settings->run_migrations();') < plugin.indexOf('$settings    = $this->settings->all();'));
check('autoloading is exact and scan-free', !autoloader.includes('glob(') && !autoloader.includes('resolve_case_insensitive'));
check('admin bar policy is shell-gated', assets.includes("[ $this, 'filter_admin_bar' ]") && assets.includes('Environment::should_render_frontend()') && assets.includes("hide_frontend_admin_bar"));

const classMismatches = [];
function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full);
    else if (entry.name.endsWith('.php')) {
      const source = fs.readFileSync(full, 'utf8');
      const namespace = (source.match(/namespace\s+([^;]+);/) || [])[1];
      const className = (source.match(/(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/) || [])[1];
      if (!namespace || !className || !namespace.startsWith('DSA')) continue;
      const actual = path.relative(path.join(pkg, 'includes'), full).replace(/\\/g, '/');
      const expected = `${namespace === 'DSA' ? '' : namespace.slice(4).replace(/\\/g, '/') + '/'}${className}.php`;
      if (actual !== expected) classMismatches.push(`${actual} != ${expected}`);
    }
  }
}
walk(path.join(pkg, 'includes'));
check('autoloaded class filenames match namespaces exactly', classMismatches.length === 0, classMismatches.join('; '));

// Existing legacy adapters are frozen: new direct pk_/stp_ consumers fail this contract.
const directBridgeAllowlist = new Set([
  'AI/Copilot_Service.php', 'Admin/Admin.php', 'Commerce/Abandoned_Cart_Service.php',
  'Commerce/COD_Gate_Service.php', 'Commerce/Store_Analytics_Service.php',
  'Diagnostics/Production_Readiness_Service.php', 'Notifications/Notification_Preference_Service.php',
  'Notifications/Push_Service.php', 'PhoneKey/PhoneKey_Bridge.php', 'PhoneKey/phonekey-core.php',
  'Rest/Account_Controller.php', 'Rest/Cart_Controller.php', 'Utilities/Origin_Checker.php'
]);
const unexpectedConsumers = [];
function scanBridgeConsumers(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) scanBridgeConsumers(full);
    else if (entry.name.endsWith('.php')) {
      const rel = path.relative(path.join(pkg, 'includes'), full).replace(/\\/g, '/');
      if (rel.startsWith('Secure/')) continue;
      const source = fs.readFileSync(full, 'utf8');
      if (/\b(?:pk_|stp_)[A-Za-z0-9_]+\s*\(/.test(source) && !directBridgeAllowlist.has(rel)) unexpectedConsumers.push(rel);
    }
  }
}
scanBridgeConsumers(path.join(pkg, 'includes'));
check('PhoneKey/SecureTrack direct-global boundary is frozen', unexpectedConsumers.length === 0, unexpectedConsumers.join(', '));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}${item.detail ? ` :: ${item.detail}` : ''}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} RC8 contracts passed.`);
if (failed.length) process.exit(1);
