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
const ideate = read('kiwe-ai-toolkit/contexts/ideate.md');
const manifest = JSON.parse(read('kiwe-ai-toolkit/command-manifest.json'));
const enhancement = read('wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Enhancement_Service.php');
const ideateDiscoverySchema = JSON.parse(read('kiwe-ai-toolkit/schemas/ideate-discovery.schema.json'));

const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

check('SiteGraph service has one stable public-only schema', service.includes('kiwe.site-graph.v1') && service.includes("'publicDataOnly'      => true") && service.includes("'readOnly'            => true"));
check('design-context service denies mutation and private commerce/visitor data', service.includes("'mayMutateWordPress'  => false") && service.includes("'mayPublish'          => false") && service.includes("'ordersIncluded'      => false") && service.includes("'visitorDataIncluded' => false"));
check('design-context service has bounded public and administrator export budgets', service.includes('PUBLIC_MAX_PRODUCTS = 100') && service.includes('PUBLIC_MAX_MEDIA    = 100') && service.includes('ADMIN_MAX_MEDIA     = 500'));
check('design evidence covers identity products media public content and target capabilities', ['site', 'menus', 'products', 'media', 'pages', 'posts', 'bindingTargets', 'calibration'].every((needle) => service.includes(`'${needle}'`)));
check('media evidence exposes searchable visual metadata without filesystem paths', data.includes("'search', 'include'") && data.includes("'aspectRatio'") && data.includes("'orientation'") && data.includes('wp_basename') && !service.includes('get_attached_file'));
check('product evidence includes galleries and attributes', data.includes("'gallery'") && data.includes("'attributes'") && data.includes('get_gallery_image_ids'));
check('product evidence includes Woo relationships bundles Kiwe offers discounts and bestsellers', ['crossSells', 'upsells', 'bundleItems', 'kiweMerchandising', 'discountScope', 'bestsellerTerms'].every((needle) => data.includes(needle)) && service.includes("'bestsellers'") && service.includes("'kiweOffers'"));
check('design context includes public business identity and explicit Kiwe contact sources', service.includes("'business'") && service.includes("'publicContact'") && service.includes('Site_Identity_Service::store_phone') && service.includes('wordpressAdminEmailExcluded'));
check('design context includes the owner-authored SEAM design brief without making Framework mandatory', service.includes("'seamDesignContext'") && service.includes("'/seamdesigncontext'") && service.includes("'/brand'") && service.includes("'/audience'"));
check('all public custom content types taxonomies and safe field contracts are bounded', service.includes('custom_content_catalog') && service.includes('taxonomy_catalog') && service.includes('custom_field_contract') && service.includes('MAX_CUSTOM_TYPES') && service.includes('MAX_FIELDS_PER_TYPE'));
check('custom field values are safety filtered and private meta is not dumped anonymously', data.includes('public_registered_meta') && data.includes('is_secretish_meta_key') && data.includes("str_starts_with( $key, '_bricks' )") && service.includes("'valueExposed'"));
check('SiteGraph core advertises public contact and merchandising authority', graph.includes("'publicContact'") && graph.includes("'merchandising'") && graph.includes('woocommerceOwnsPricing'));
check('task capsules can request the expanded context without gaining write authority', capsules.includes("'customcontent'") && capsules.includes("'taxonomies'") && capsules.includes("'business'") && capsules.includes("'commerce'"));
check('one authenticated SiteGraph route replaces separate design-context routes', aiController.includes("'/ai/site-graph'") && !publicController.includes("'/site-graph/design-context'") && !aiController.includes("'/ai/design-context'"));
check('task capsule SiteGraph is resource and row bounded', aiController.includes("$args['resources']") && aiController.includes("$args['productLimit']") && aiController.includes("$args['mediaLimit']"));
check('admin exposes one secret-free SiteGraph download', admin.includes('dsa_export_site_graph') && admin.includes('Download SiteGraph JSON') && !admin.includes('dsa_export_sitegraph_design_context'));
check('MCP and WordPress Abilities expose one read-only SiteGraph contract', mcp.includes('kiwe_sitegraph_get_graph') && !mcp.includes('kiwe_sitegraph_design_context') && abilities.includes('dsa/get-site-graph') && !abilities.includes('dsa/get-sitegraph-design-context'));
check('command grammar has no separate design-context or SiteGraph command', !manifest.commands.some((item) => item.command.includes('designcontext') || item.command.includes('sitegraph')) && !core.includes('/usesitegraph'));
check('command documentation teaches one embedded SiteGraph handoff', ideate.includes('SiteGraph or its embedded Design Context') && manifest.authority.siteContext.includes('embedded Design Context'));
check('raw-source contract preserves stable media and dynamic intent', service.includes('data-kiwe-media-id') && service.includes('data-kiwe-query-template') && service.includes('bricks-bindings/kiwe-bindings.json'));
check('owner context exposes social and complete market coverage without secrets', service.includes("'socials'") && service.includes("'marketCoverage'") && graph.includes("'socialProfileCount'") && !service.includes('consumer_secret'));
check('design context embeds a hash-bound AI enhancement contract and resolved layer', service.includes("'designContextEnhancementContract'") && service.includes("'resolvedDesignContext'") && service.includes("'ownerContextHash'") && enhancement.includes('lockedPaths'));
check('AI enhancement stays SiteGraph data rather than a command lane', !manifest.commands.some((item) => item.command.includes('enhancement')) && service.includes("'designContextEnhancementContract'") && enhancement.includes('mayOverwriteOwnerEvidence'));
check('design context is self-describing for bare ideate without granting write authority', service.includes("'ideationContract'") && service.includes("'autoComposeCommands' => [ '/ideate' ]") && service.includes("'ownerFacts' => 'locked'") && service.includes("'ownerPreferences' => 'preserve-unless-human-explicitly-revises'") && service.includes("'mayMutateDesignContext' => false") && service.includes("'ideateCommand' => '/ideate'"));
check('SiteGraph exposes verified native Bricks product tags plus Kiwe nutrition media', ['{post_title}','{post_content}','{woo_product_excerpt}','{woo_product_price}','{woo_product_images}','{kiwe_product_nutrition_image}'].every((tag) => service.includes(tag)) && data.includes("'nutritionImage'"));
check('ideate consumes a single SiteGraph context without mutating it', ideate.includes('read-only SiteGraph') && ideate.includes('Never invent IDs') && service.includes("'siteDataRequirement'"));
check('ideate delivery includes portable assets and a provenance manifest', ideate.includes('assets/asset-manifest.json') && ideate.includes('intended WordPress Media use') && manifest.commands.find((item) => item.command === '/ideate').outputs.includes('asset manifest'));
check('ideate gates composition on an explicit SiteGraph requirement', ideate.includes('`required`, `beneficial`, or `not-needed`') && ideate.includes('stop before recreation') && ideateDiscoverySchema.properties.siteGraph.properties.requirement.enum.includes('required'));
check('ideate reports deterministic Design Context coverage before mutation', ideate.includes('Design Context coverage before changing any file') && ideate.includes('designContextStrength') && ideate.includes('coverage—not subjective design quality') && manifest.commands.find((item) => item.command === '/ideate').outputs.includes('Design Context coverage report before modification'));
check('ideate discovery artifact has a strict v4 schema, source authority and creative boundary', ideateDiscoverySchema.properties.schema.const === 'kiwe.ideate-discovery.v4' && ideateDiscoverySchema.required.includes('designContextCoverage') && ideateDiscoverySchema.required.includes('approval') && ideateDiscoverySchema.required.includes('sourceAuthority') && ideateDiscoverySchema.properties.sourceAuthority.properties.status.enum.includes('approved-final') && ideateDiscoverySchema.properties.nextAction.enum.includes('ask-source-authority') && ideateDiscoverySchema.properties.creativeBoundary.properties.frameworkNeutral.const === true);
check('accepted source recreation preserves art direction and behavior', ideate.includes('For `approved-final`, visual composition, palette, typography, spacing system, responsive behavior, content hierarchy, page boundaries and JavaScript are immutable') && ideate.includes('Never collapse multiple supplied pages into one JavaScript-switched document'));
check('ideate cannot infer redesign permission and asks one source-authority question first', ideate.includes('/ideate`, the presence of source files, visual defects, stale navigation, missing content, or the AI\'s preference never grants that authority') && ideate.includes('Is this design final/approved, should I enhance it without changing its art direction, or are you asking for a redesign?') && ideate.includes('Do not select a direction, propose an art style, request SiteGraph, emit a usage brief, or create files until the user answers'));
check('ideate shortlists relevant Design Content instead of dumping SiteGraph media', ideate.includes('usage brief') && ideate.includes('do not enumerate, download or force every SiteGraph media item') && ideateDiscoverySchema.required.includes('designContentUsage'));
check('ideate requires an explicit proceed gate before file mutation', ideate.includes('Then stop and ask the user to say `proceed`') && ideate.includes('Do not create or modify output files before that explicit response') && ideateDiscoverySchema.properties.approval.properties.requiredPhrase.const === 'proceed');
check('requested SiteGraph attachments never imply proceed', ideate.includes('only satisfies an input request') && ideate.includes('never means `proceed`') && ideate.includes('The explicit response must occur after the usage brief'));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} SiteGraph design-context contracts passed.`);
if (failed.length) process.exit(1);
