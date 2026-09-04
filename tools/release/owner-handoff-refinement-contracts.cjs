#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const onboarding = read('wp-content/mu-plugins/dsa/includes/Onboarding/Onboarding_Service.php');
const profile = read('wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Profile_Service.php');
const refinement = read('wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Refinement_Service.php');
const broker = read('wp-content/mu-plugins/dsa/includes/AI/AI_Broker_Service.php');
const seo = read('wp-content/mu-plugins/dsa/includes/SEO/SEO_Refinement_Service.php');
const seoHead = read('wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php');
const graph = read('wp-content/mu-plugins/dsa/includes/Site_Graph/Design_Context_Service.php');
const js = read('wp-content/mu-plugins/dsa/assets/js/onboarding.js');

const data = read('wp-content/mu-plugins/dsa/includes/Site_Graph/Data_Query_Service.php');
const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });
check('owner handoff uses the native mixed Media Library instead of another picker', onboarding.includes('Open Media Library') && !onboarding.includes('data-kiwe-resource-select') && graph.includes("'mimeType' => ''"));
check('legacy attachment notes are retained and media is never deleted', profile.includes("$raw['resources'] = $stored['resources']") && !onboarding.includes('wp_delete_attachment') && !profile.includes('wp_delete_attachment'));
check('SiteGraph media stays paginated and excludes private parent content', profile.includes("'source' => 'wordpress-media-library'") && data.includes('kiwe_public_media') && data.includes("kiwe_media_parent.post_status = 'publish'") && data.includes("kiwe_media_parent.post_password = ''") && data.includes("'pageInfo'"));
check('one shared broker has isolated design-context and SEO profiles', broker.includes("'design_context' => [") && broker.includes("'seo' => [") && broker.includes("'capabilities'   => [ 'refine' ]") && broker.includes("'capabilities'   => [ 'propose_batch' ]"));
check('copy refinement is proposal-only and field review is explicit', refinement.includes("'proposalOnly'=>true") && ['accept','reject','regenerate','accept_all'].every((action) => refinement.includes(`'${action}'`)) && refinement.includes('originalHash'));
check('Design Context and SEO request provider-enforced JSON schemas with safe diagnostics', refinement.includes("'responseSchema'=>$this->response_schema") && refinement.includes('refinement-detail') && seo.includes("'responseSchema'=>$this->response_schema"));
check('Design Context AI evidence excludes operational and credential-bearing owner data', refinement.includes("'contacts','operationalAddress','legalIdentifiers','mediaResources','adminIdentities'") && refinement.includes("public_context( false )") && !refinement.includes("public_context( true )"));
check('verified facts remain outside the editorial refinement allowlist', refinement.includes('Legal identifiers, verified claims, names, contacts, addresses, prices, products') && !refinement.includes("'regulatory.gstNumber'=>") && !refinement.includes("'contact.phone'=>") && !refinement.includes("'identity.siteName'=>"));
check('eligible missing owner copy can be proposed but never auto-published', refinement.includes("'about.vision'=>[ 'Vision',2000,true ]") && refinement.includes("'about.mission'=>[ 'Mission',2000,true ]") && refinement.includes("'mayPublish'=>false") && refinement.includes('apply_editorial_refinements'));
check('SEO controls cover posts pages products and media', ['all_posts','all_pages','all_products','all_media'].every((scope) => seo.includes(`'${scope}'`)) && seo.includes("add_submenu_page( 'kiwe'"));
check('SEO AI work is shared-host bounded and cron queued', seo.includes('BATCH_SIZE = 5') && seo.includes('wp_schedule_single_event') && seo.includes('get_transient( $lock )') && seo.includes('finally'));
check('SEO output is review-first and does not emit meta keywords', seo.includes("name=\"decision\" value=\"accept\"") && seo.includes("name=\"decision\" value=\"reject\"") && seo.includes('must never be emitted as meta keywords') && !seoHead.includes('name="keywords"'));
check('media refinement preserves URLs and filenames while proposing native metadata', seo.includes('Do not change URLs or filenames') && seo.includes("'_wp_attachment_image_alt'") && seo.includes("'post_excerpt'") && seo.includes("'post_content'"));
check('Kiwe SEO metadata yields frontend authority to dedicated SEO plugins', seo.includes('dedicated_seo_plugin_active') && seoHead.includes('dedicated_seo_plugin_active') && seoHead.includes('SEO_Refinement_Service::singular_description'));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} owner handoff refinement contracts passed.`);
if (failed.length) process.exit(1);
