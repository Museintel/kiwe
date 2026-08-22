#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const onboarding = read('wp-content/mu-plugins/dsa/includes/Onboarding/Onboarding_Service.php');
const profile = read('wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Profile_Service.php');
const seo = read('wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const graph = read('wp-content/mu-plugins/dsa/includes/Site_Graph/Design_Context_Service.php');
const bricks = read('wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php');
const js = read('wp-content/mu-plugins/dsa/assets/js/onboarding.js');

const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });
check('fresh installs open a resumable owner journey without enabling optional Kiwe runtimes', onboarding.includes("'sitegraph_only_v1'") && onboarding.includes('maybe_open_fresh_install') && plugin.includes('$this->onboarding->register()'));
check('owner journey is segmented and media-picker enabled', ['Identity','Story','Contact','Brand','Website plan','Store','Review'].every((x) => onboarding.includes(x)) && onboarding.includes('wp_enqueue_media') && js.includes('wp.media'));
check('required identity stays server enforced', profile.includes('Site name is required') && profile.includes('A site logo is required') && profile.includes('A square site icon is required') && profile.includes('A public phone number is required') && profile.includes('A valid public email is required'));
check('canonical WordPress and Kiwe identity options receive owner values', profile.includes("update_option( 'blogname'") && profile.includes("update_option( 'blogdescription'") && profile.includes('set_theme_mod( \'custom_logo\'') && profile.includes('OPTION_STORE_PHONE') && profile.includes("update_option( 'site_icon'"));
check('WooCommerce remains price shipping-zone and product authority', profile.includes("update_option( 'woocommerce_currency'") && onboarding.includes('does not invent tax rates or overwrite shipping zones') && !profile.includes('WC_Shipping_Zone') && !profile.includes('wp_insert_post'));
check('page intent does not create pages', profile.includes('PAGE_VISIBILITY_META') && onboarding.includes('Kiwe does not create the pages') && !profile.includes('wp_insert_post'));
check('secondary pages are noindex and excluded from native XML sitemaps', seo.includes('wp_robots') && seo.includes('wp_sitemaps_posts_query_args') && seo.includes("'secondary'") && seo.includes("'noindex'"));
check('native meta and organization schema yield to dedicated SEO plugins', seo.includes('dedicated_seo_plugin_active') && seo.includes('WPSEO_VERSION') && seo.includes('RANK_MATH_VERSION') && seo.includes('application/ld+json'));
check('invitations are random hashed expiring account-bound links', onboarding.includes('random_bytes( 32 )') && onboarding.includes('wp_hash_password') && onboarding.includes('7*DAY_IN_SECONDS') && onboarding.includes('get_current_user_id()') && onboarding.includes('wp_check_password'));
check('invitation writes require nonce and administrator authority', onboarding.includes("check_admin_referer( 'kiwe_save_onboarding' )") && onboarding.includes("check_admin_referer( 'kiwe_create_onboarding_invite' )") && onboarding.includes("current_user_can( 'manage_options' )"));
check('delivery supports WordPress email configured Kiwe channels and manual copy', onboarding.includes('wp_mail') && onboarding.includes("[ 'sms', 'whatsapp' ]") && onboarding.includes("'copy'"));
check('owner context is framework opt-in, SiteGraph readable and freshness hashed', profile.includes("'frameworkOptInRequired' => true") && graph.includes("'seamDesignContext'") && graph.includes("'/seamdesigncontext'") && graph.includes("'contextHash'"));
check('owner context has native Bricks and WordPress binding surfaces', ['kiwe_business_description','kiwe_whatsapp','kiwe_brand_tone','kiwe_brand_color','kiwe_accent_color'].every((tag) => bricks.includes(tag)) && graph.includes("'businessDescription' => '{kiwe_business_description}'"));
check('anonymous design context redacts operational address detail', profile.includes('if ( ! $administrator )') && profile.includes("'operationalAddressIncluded' => $administrator") && profile.includes("'adminIdentityExcluded' => true"));
check('readiness reports separate SEO and design-context scores', profile.includes("'seoStrength'") && profile.includes("'designContextStrength'"));
check('browser timezone detection is local and requires no geolocation service', js.includes('Intl.DateTimeFormat().resolvedOptions().timeZone') && !js.includes('geolocation') && !js.includes('fetch('));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} onboarding contracts passed.`);
if (failed.length) process.exit(1);
