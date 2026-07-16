const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const settings = read('wp-content/mu-plugins/dsa/includes/Settings.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const renderer = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Surface_Renderer.php');
const assets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
const css = read('wp-content/mu-plugins/dsa/assets/css/surface-presets.css');
const loader = read('wp-content/mu-plugins/dsa.php');
const bootstrap = read('wp-content/mu-plugins/dsa/dsa.php');
const checks = [];
const check = (name, pass, detail = '') => checks.push({ name, pass: Boolean(pass), detail });
const presets = ['classic', 'neumorphic', 'glassmorphism', 'bold-vibrant', 'minimal-clean'];

check('Classic remains the default', /'preset'\s*=>\s*'classic'/.test(settings));
check('Style has a dedicated Kiwe admin page', admin.includes("'kiwe-style'") && admin.includes('render_style_page'));
check('Preset input is allowlisted', presets.every((preset) => admin.includes(`'${preset}'`)) && admin.includes('sanitize_style_settings'));
check('Preset travels with Appsite profiles', admin.includes("'style'               => $settings['style']") && admin.includes("$next['style'] = $this->sanitize_style_settings"));
check('Surface receives one preset class', renderer.includes("'dsa-style-' . sanitize_html_class( $preset )"));
check('Optional stylesheet excludes Classic', assets.includes("if ( 'classic' !== $style_preset )") && assets.includes('surface-presets.css'));
check('Home screen receives the same preset before paint', assets.includes('r.dataset.kiweStyle=') && css.includes('.dsa-initial-preloader'));
check('All four optional presets are present', presets.slice(1).every((preset) => css.includes(`dsa-style-${preset}`)));
check('Dark mode is defined for all optional presets', presets.slice(1).every((preset) => css.includes(`[data-kiwe-theme="dark"] .dsa-style-${preset}`)));
check('Reduced motion and forced colors are retained', css.includes('@media (prefers-reduced-motion: reduce)') && css.includes('@media (forced-colors: active)'));

const forbidden = [];
for (const property of ['position', 'inset', 'top', 'right', 'bottom', 'left', 'width', 'height', 'display', 'overflow', 'z-index', 'pointer-events', 'visibility', 'grid-template-columns', 'flex-direction']) {
  const pattern = new RegExp(`(^|[;{]\\s*)${property}\\s*:`, 'gm');
  if (pattern.test(css)) forbidden.push(property);
}
check('Preset stylesheet does not own geometry or lifecycle', forbidden.length === 0, forbidden.join(', '));
check('No designer demo runtime was imported', !css.includes('.dsa-container') && !css.includes('.dock-item') && !assets.includes('greeting'));

const loaderVersion = (loader.match(/KIWE_MU_LOADER_VERSION',\s*'([^']+)'/) || [])[1];
const packageVersion = (bootstrap.match(/define\(\s*'DSA_VERSION',\s*'([^']+)'/) || [])[1];
check('Release versions remain synchronized', Boolean( loaderVersion ) && loaderVersion === packageVersion, `${loaderVersion} / ${packageVersion}`);

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}${item.detail ? ` :: ${item.detail}` : ''}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} Style preset contracts passed.`);
if (failed.length) process.exit(1);
