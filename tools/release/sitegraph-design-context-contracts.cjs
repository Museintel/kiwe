#!/usr/bin/env node

const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const service = read('wp-content/mu-plugins/dsa/includes/Site_Graph/Design_Context_Service.php');
const data = read('wp-content/mu-plugins/dsa/includes/Site_Graph/Data_Query_Service.php');
const graph = read('wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php');
const capsules = read('wp-content/mu-plugins/dsa/includes/AI/Task_Capsule_Service.php');
const publicController = read('wp-content/mu-plugins/dsa/includes/Rest/Site_Graph_Controller.php');
const aiController = read('wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const abilities = read('wp-content/mu-plugins/dsa/includes/WP7/Abilities_Service.php');
const mcp = read('kiwe-ai-toolkit/mcp/sitegraph-client.js');
const core = read('kiwe-ai-toolkit/lib/kiwe-core.js');
const dynamic = read('kiwe-ai-toolkit/contexts/dynamic-lite.md');
const manifest = JSON.parse(read('kiwe-ai-toolkit/command-manifest.json'));

const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

check('design-context service has a stable public-only schema', service.includes('kiwe.sitegraph-design-context.v1') && service.includes("'publicDataOnly'      => true") && service.includes("'readOnly'            => true"));
check('design-context service denies mutation and private commerce/visitor data', service.includes("'mayMutateWordPress'  => false") && service.includes("'mayPublish'          => false") && service.includes("'ordersIncluded'      => false") && service.includes("'visitorDataIncluded' => false"));
check('design-context service has bounded public and administrator export budgets', service.includes('PUBLIC_MAX_PRODUCTS = 100') && service.includes('PUBLIC_MAX_MEDIA    = 100') && service.includes('ADMIN_MAX_MEDIA     = 500'));
check('design evidence covers identity products media public content and target capabilities', ['site', 'menus', 'products', 'media', 'pages', 'posts', 'bindingTargets', 'calibration'].every((needle) => service.includes(`'${needle}'`)));
check('media evidence exposes searchable visual metadata without filesystem paths', data.includes("'search', 'include'") && data.includes("'aspectRatio'") && data.includes("'orientation'") && data.includes('wp_basename') && !service.includes('get_attached_file'));
check('product evidence includes galleries and attributes', data.includes("'gallery'") && data.includes("'attributes'") && data.includes('get_gallery_image_ids'));
check('product evidence includes Woo relationships bundles Kiwe offers discounts and bestsellers', ['crossSells', 'upsells', 'bundleItems', 'kiweMerchandising', 'discountScope', 'bestsellerTerms'].every((needle) => data.includes(needle)) && service.includes("'bestsellers'") && service.includes("'kiweOffers'"));
check('design context includes public business identity and explicit Kiwe contact sources', service.includes("'business'") && service.includes("'publicContact'") && service.includes('Site_Identity_Service::store_phone') && service.includes('wordpressAdminEmailExcluded'));
check('all public custom content types taxonomies and safe field contracts are bounded', service.includes('custom_content_catalog') && service.includes('taxonomy_catalog') && service.includes('custom_field_contract') && service.includes('MAX_CUSTOM_TYPES') && service.includes('MAX_FIELDS_PER_TYPE'));
check('custom field values are safety filtered and private meta is not dumped anonymously', data.includes('public_registered_meta') && data.includes('is_secretish_meta_key') && data.includes("str_starts_with( $key, '_bricks' )") && service.includes("'valueExposed'"));
check('SiteGraph core advertises public contact and merchandising authority', graph.includes("'publicContact'") && graph.includes("'merchandising'") && graph.includes('woocommerceOwnsPricing'));
check('task capsules can request the expanded context without gaining write authority', capsules.includes("'customcontent'") && capsules.includes("'taxonomies'") && capsules.includes("'business'") && capsules.includes("'commerce'"));
check('public and authenticated design-context routes exist', publicController.includes("'/site-graph/design-context'") && aiController.includes("'/ai/design-context'") && aiController.includes("'site_graph_data'"));
check('anonymous design-context reads are rate limited', publicController.includes("'dsa_sitegraph_design_context', 12") && publicController.includes("'Retry-After', '60'"));
check('task capsule design context is resource and row bounded', aiController.includes("$args['resources']    = $resources") && aiController.includes("$args['productLimit']") && aiController.includes("$args['mediaLimit']"));
check('admin can download a secret-free design-context packet', admin.includes('dsa_export_sitegraph_design_context') && admin.includes('Download AI design context') && admin.includes('contains no API key'));
check('MCP and WordPress Abilities expose the read-only contract', mcp.includes('kiwe_sitegraph_design_context') && mcp.includes("path: '/design-context'") && abilities.includes('dsa/get-sitegraph-design-context'));
check('command grammar registers designcontext and canonical shorthand normalization', manifest.commandGrammar.phaseTargets.includes('/designcontext') && core.includes('/usesitegraph /for /designcontext') && core.includes("/usesitegraph\\s+\\/designcontext"));
check('command documentation keeps design context framework-neutral and file-capable', dynamic.includes('kiwe.sitegraph-design-context.v1') && dynamic.includes('must not add Seam Framework') && dynamic.includes('/usesitegraph /for /designcontext /nonai'));
check('raw-source contract preserves stable media and dynamic intent', service.includes('data-kiwe-media-id') && service.includes('data-kiwe-query-template') && service.includes('bricks-bindings/kiwe-bindings.json'));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} SiteGraph design-context contracts passed.`);
if (failed.length) process.exit(1);
