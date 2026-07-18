const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const keys = read('wp-content/mu-plugins/dsa/includes/AI/Access_Key_Service.php');
const rest = read('wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php');
const themeService = read('wp-content/mu-plugins/dsa/includes/Theme/Theme_Package_Service.php');
const publicAssets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
const surfaceCss = read('wp-content/mu-plugins/dsa/assets/css/surface.css');
const surfaceJs = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const commercePanels = read('wp-content/mu-plugins/dsa/assets/js/modules/commerce-panels.js');
const screenPayloads = read('wp-content/mu-plugins/dsa/ui-system/screen-payloads.json');
const themePackageSchema = read('wp-content/mu-plugins/dsa/ui-system/theme-package.schema.json');
const stagingExecutor = read('wp-content/mu-plugins/dsa/includes/AI/Staging_Execution_Service.php');
const controlledMutations = read('wp-content/mu-plugins/dsa/includes/AI/Controlled_Mutation_Service.php');
const siteGraph = read('wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php');
const siteInspection = read('wp-content/mu-plugins/dsa/includes/AI/Site_Introspection_Service.php');
const manifest = read('wp-content/mu-plugins/dsa/package-manifest.json');
const docs = read('KIWE-AI.md') + '\n' + read('kiwe-ai-toolkit/contexts/dynamic-lite.md');
const themeDocs = [
	read('wp-content/mu-plugins/dsa/ui-system/HANDOFF-MODES.md'),
	read('wp-content/mu-plugins/dsa/ui-system/prompt.md'),
	read('kiwe-ai-toolkit/contexts/combined-lite.md'),
	read('kiwe-ai-toolkit/contexts/audit-lite.md'),
	read('kiwe-ai-toolkit/contexts/combined.md'),
	read('kiwe-ai-toolkit/contexts/theme.md')
].join('\n');

const checks = [];
function check(label, pass, detail = '') {
	checks.push({ label, pass: Boolean(pass), detail });
}

check('Kiwe admin has a dedicated AI submenu', admin.includes("'kiwe-ai'") && admin.includes('render_ai_page'));
check('Framework page remains framework-focused', !admin.includes('Kiwe > Framework > AI connector and Site Graph') && admin.includes('Push Kiwe Framework to Bricks'));
check('AI admin creates and revokes hashed API keys', admin.includes('dsa_create_ai_access_key') && admin.includes('dsa_revoke_ai_access_key') && keys.includes('wp_hash_password'));
check('API key secrets are shown once via transient only', admin.includes('dsa_ai_key_once_') && keys.includes("'hash'") && !keys.includes("'plain'"));
check('REST API-key controller is registered', plugin.includes('new AI_Access_Controller') && rest.includes("'/ai/site-graph'"));
check('REST AI routes cover trusted apply chain', rest.includes("'/ai/stage-apply-plan'") && rest.includes("'/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/post-apply-verification'"));
check('REST AI routes require API-key guard', rest.includes('authenticate_request') && rest.includes("'permission_callback' => '__return_true'"));
check('Status endpoint accepts any active key', rest.includes("[ 'GET', '/ai/status', 'status', 'status' ]") && keys.includes("'status' !== $required_scope"));
check('AI API exposes theme package install and activation', rest.includes("'/ai/themes/install'") && rest.includes("'/ai/themes/(?P<themeId>[a-zA-Z0-9._-]+)/activate'") && keys.includes("'themes'"));
check('AI API exposes read-only site inspection and staging executor scopes', rest.includes("'/ai/site-inspection'") && rest.includes("'/ai/staging/execute'") && rest.includes("'/ai/stages/(?P<stageId>[a-zA-Z0-9:_-]+)/execute-staging'") && keys.includes("'site_inspection'") && keys.includes("'staging_execute'") && stagingExecutor.includes("'bricks.settings.patch'"));
check('AI API exposes confirmation-gated mutation/runtime routes', rest.includes("'/ai/mutations/bricks-page-save'") && rest.includes("'/ai/mutations/wordpress-publish'") && rest.includes("'/ai/mutations/woocommerce'") && rest.includes("'/ai/runtime/checkout'") && rest.includes('controlled_route_payload') && keys.includes("'controlled_mutation'"));
check('Controlled staging executor covers Woo, runtime, auth, and raw Bricks writes', controlledMutations.includes("'woocommerce.product.upsert'") && controlledMutations.includes("'woocommerce.order.upsert'") && controlledMutations.includes("'woocommerce.settings.patch'") && controlledMutations.includes("'cart.run'") && controlledMutations.includes("'checkout.run'") && controlledMutations.includes("'auth.run'") && controlledMutations.includes("'bricks.raw-meta-write'") && stagingExecutor.includes('confirmWooCommerceMutation') && stagingExecutor.includes('confirmRuntimeExecution') && stagingExecutor.includes('confirmRawBricksJsonWrite'));
check('AI discovery covers custom post types, taxonomies, and custom fields', siteGraph.includes("'customContent'") && siteGraph.includes('custom_post_types') && siteGraph.includes('custom_taxonomies') && siteGraph.includes('custom_field_summary') && siteInspection.includes("'customContent'") && docs.includes('customContent.postTypes') && docs.includes('customContent.customFields'));
check('Controlled payload sanitizers preserve case-sensitive Bricks keys', stagingExecutor.includes('safe_payload_key') && controlledMutations.includes('safe_payload_key') && !stagingExecutor.includes('sanitize_key( (string) $key )') && !controlledMutations.includes('sanitize_key( (string) $key )'));
check('Theme package activation uses sanitized settings overlay', rest.includes('safe_settings_overlay') && themeService.includes('sanitize_dock_custom_items') && themeService.includes("kiwe.theme-package.v1"));
check('Theme package settings preserve visual profile and sanitized screen-copy presets', themeService.includes("settings['style']['visual_profile']") && themeService.includes('sanitize_screen_settings') && themeService.includes('theme_screens') && themePackageSchema.includes('screens may contain presentation/copy labels') && themePackageSchema.includes('behavior, JavaScript, endpoints, or state authority'));
check('Frontend exposes active installed theme screen settings', publicAssets.includes("'installedTheme'") && publicAssets.includes("'screens'") && publicAssets.includes('active_theme_record') && surfaceJs.includes('const themeScreens') && surfaceJs.includes("screenTheme( 'cart' )"));
check('Installed AppShell theme CSS receives runtime visual authority', themeService.includes('compile_runtime_theme_css') && themeService.includes('#dsa-surface[data-dsa-surface].dsa-installed-theme-') && themeService.includes('preg_replace') && !/dsa-ai-launcher[^{]*\{[^}]*\b(?:background|color)\s*:[^;}]+!important/is.test(surfaceCss));
check('Cart AppShell runtime exposes documented theme hooks', commercePanels.includes('data-dsa-cart-line') && commercePanels.includes('dsa-cart-line') && commercePanels.includes('dsa-line-thumb') && commercePanels.includes('dsa-quantity') && commercePanels.includes('data-dsa-cart-fbt-card') && commercePanels.includes('dsa-fbt-img') && commercePanels.includes('dsa-totals') && commercePanels.includes('dsa-primary-action') && commercePanels.includes('dsa-section-label') && screenPayloads.includes('"runtimeSelectors"') && screenPayloads.includes('[data-dsa-cart-line]') && screenPayloads.includes('.dsa-totals') && screenPayloads.includes('.dsa-primary-action') && screenPayloads.includes('.dsa-section-label'));
check('Cart AppShell runtime consumes installed theme screen copy safely', commercePanels.includes('function cartCopy') && commercePanels.includes('screen.fbtTitle') && commercePanels.includes('checkoutEmptyLabel') && commercePanels.includes('payload.screenTheme') && commercePanels.includes('settings.fbtTitle'));
check('Package manifest includes API key, theme package, inspection, staging executor, and controlled mutation files', manifest.includes('includes/AI/Access_Key_Service.php') && manifest.includes('includes/Rest/AI_Access_Controller.php') && manifest.includes('includes/Theme/Theme_Package_Service.php') && manifest.includes('includes/AI/Site_Introspection_Service.php') && manifest.includes('includes/AI/Staging_Execution_Service.php') && manifest.includes('includes/AI/Controlled_Mutation_Service.php') && manifest.includes('ui-system/theme-package.schema.json'));
check('Docs point external AI clients to Kiwe > AI and /ai API', docs.includes('Kiwe > AI > API access keys') && docs.includes('/wp-json/dsa/v1/ai/site-graph'));
check('Docs describe theme package API and controlled mutation boundaries', docs.includes('/wp-json/dsa/v1/ai/themes/install') && docs.includes('/wp-json/dsa/v1/ai/mutations/bricks-page-save') && docs.includes('kiwe.theme-package.v1') && docs.includes('confirmWooCommerceMutation') && docs.includes('confirmRuntimeExecution') && docs.includes('confirmRawBricksJsonWrite'));
check('Docs do not send AI connector workflow back to Kiwe > Framework', !docs.includes('Kiwe > Framework > AI connector'));
check('Theme handoff docs require live-intended cart preview copy in theme package settings', themeDocs.includes('settings.screens.cart') && themeDocs.includes('Your tea-time bag') && themeDocs.includes('Pairs well with') && themeDocs.includes('preview/live mismatch') && themeDocs.includes('checkoutEmptyLabel'));
check('Theme handoff docs no longer use loose kiwe-settings profile for AppShell theme settings', !themeDocs.includes('kiwe-settings/kiwe-appsite-profile.json') && !themeDocs.includes('Kiwe settings/profile output separate'));

const failures = checks.filter((item) => !item.pass);
for (const item of checks) {
	console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.label}${item.detail ? ` :: ${item.detail}` : ''}`);
}

if (failures.length) {
	console.error(`\n${failures.length}/${checks.length} AI API connector contracts failed.`);
	process.exit(1);
}

console.log(`\n${checks.length}/${checks.length} AI API connector contracts passed.`);
