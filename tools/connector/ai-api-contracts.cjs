#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const manifest = JSON.parse(read('kiwe-ai-toolkit/command-manifest.json'));
const entry = JSON.parse(read('kiwe-ai-toolkit/entry.json'));
const core = read('kiwe-ai-toolkit/lib/kiwe-core.js');
const cli = read('kiwe-ai-toolkit/bin/kiwe.js');
const mcp = read('kiwe-ai-toolkit/mcp/index.js');
const rest = read('wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const settings = read('wp-content/mu-plugins/dsa/includes/Settings.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const keys = read('wp-content/mu-plugins/dsa/includes/AI/Access_Key_Service.php');
const capsules = read('wp-content/mu-plugins/dsa/includes/AI/Task_Capsule_Service.php');
const provider = read('wp-content/mu-plugins/dsa/includes/AI/AI_Provider_Service.php');
const broker = read('wp-content/mu-plugins/dsa/includes/AI/AI_Broker_Service.php');
const bricksValidator = read('kiwe-ai-toolkit/lib/bricks-conversion-validator.js');
const accessibilityValidator = read('kiwe-ai-toolkit/lib/accessibility-validator.js');
const frameworkValidator = read('kiwe-ai-toolkit/lib/framework-profile-validator.js');
const contexts = ['ideate.md', 'convert-bricks.md', 'audit.md', 'accessibility.md'].map((file) => read(`kiwe-ai-toolkit/contexts/${file}`)).join('\n');

const expected = ['/ideate', '/convert /bricks', '/audit', '/accessibility', '/fix', '/redo'];
const checks = [];
const check = (label, pass) => checks.push({ label, pass: Boolean(pass) });

check('SEAM exposes exactly six commands', JSON.stringify(manifest.commands.map((item) => item.command)) === JSON.stringify(expected) && JSON.stringify(entry.commands) === JSON.stringify(expected));
check('SEAM has no aliases or compatibility grammar', manifest.aliases.length === 0 && manifest.unknownCommandPolicy === 'reject' && !JSON.stringify(manifest).includes('compatib'));
check('SiteGraph is input rather than a slash command', manifest.authority.siteContext.includes('never slash commands') && !expected.some((command) => command.includes('sitegraph')));
check('Framework is an explicit conversion option', manifest.authority.framework.includes('explicit conversion option') && core.includes('frameworkMode'));
check('SEAM Compiler is sole Bricks conversion authority', manifest.authority.conversion.includes('only authority') && contexts.includes('owned by SEAM Compiler'));
check('ideation preserves creative authority', contexts.includes('Do not apply Seam Framework, Bricks structures or a SEAM house style'));
check('audit and accessibility are evidence based', contexts.includes('Scores must be reproducible') && contexts.includes('Distinguish source defects from conversion defects'));
check('dark mode preserves brand identity', contexts.includes('must preserve brand identity') && contexts.includes('not turn every surface black'));
check('core rejects unknown commands', core.includes("status: 'rejected'") && core.includes('Unknown command or alias.'));
check('CLI exposes only router and deterministic validators', cli.includes("action === 'route'") && cli.includes("action === 'validate-accessibility'") && !cli.includes('createHandoff'));
check('MCP exposes bounded SEAM tools', mcp.includes("name: 'seam_route'") && mcp.includes("name: 'seam_validate_bricks_conversion'") && !mcp.includes('kiwe_start_project'));
check('native Bricks validator remains executable', bricksValidator.includes('validateBricksConversion') && bricksValidator.includes('kiwe.bricks-conversion-validation.v1'));
check('accessibility validator remains executable', accessibilityValidator.includes('validateAccessibility') && accessibilityValidator.includes('accessibility_low_contrast_literal_pair'));
check('Framework validator remains executable', frameworkValidator.includes('validateFrameworkProfile') && frameworkValidator.includes('unknown_token_name'));

const externalRoutes = [...rest.matchAll(/\[ (?:\[ 'GET', 'POST' \]|'GET'|'POST'), '\/ai\//g)].length;
check('external AI API is reduced to eight guarded operations', externalRoutes === 8);
check('external AI API cannot mutate WordPress', rest.includes("'mutatesContent'           => false") && !rest.includes("'/ai/mutations/") && !rest.includes("'/ai/stage-apply-plan'"));
check('external AI API contains no second compiler or studio', !rest.includes("'/ai/seamflow/") && !rest.includes("'/ai/studio/") && !rest.includes('SeamFlow_Service'));
check('external AI API is credentialed and rate limited', rest.includes('authenticate_request') && rest.includes("'kiwe-ai-client:'") && rest.includes("'Retry-After'"));
check('task capsules remain public-only and mutation forbidden', capsules.includes("'publicOnly'  => true") && capsules.includes("'mutation'    => 'forbidden'"));
check('access keys remain hashed at rest', keys.includes('wp_hash_password') && !keys.includes("'key' => $plain"));
check('shared provider secret remains behind broker transport', provider.includes('Secret_Store::decrypt') && broker.includes("'secretAccess'  => false"));
check('plugin registers one external SiteGraph boundary', plugin.includes('new AI_Access_Controller( $this->site_graph )') && plugin.includes('new Site_Graph_Controller( $this->site_graph )'));
check('clean RC settings schema has no historical migration ladder', settings.includes('SCHEMA_VERSION = 8') && !settings.includes('SAFETY_MIGRATION_VERSION') && !settings.includes('apply_bricks_compatibility_profile'));
check('fresh installs enable only read-only SiteGraph', settings.includes('disable_boolean_tree') && settings.includes("'site_graph'") && settings.includes("'mode'    => 'read_only'"));
check('Kiwe admin exposes seven coherent sections', ['Overview','Context','Build','AppShell','Commerce & Messages','Security','System'].every((label) => admin.includes(`'${label}'`)) && admin.includes('render_hub_page'));

const failures = checks.filter((item) => !item.pass);
for (const item of checks) process.stdout.write(`${item.pass ? 'PASS' : 'FAIL'} ${item.label}\n`);
process.stdout.write(`\n${checks.length - failures.length}/${checks.length} SEAM and Kiwe architecture contracts passed.\n`);
if (failures.length) process.exit(1);
