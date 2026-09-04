const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const surface = read('wp-content/mu-plugins/dsa/assets/js/surface.js');
const schema = read('wp-content/mu-plugins/dsa/includes/Theme/Screen_Copy_Schema.php');
const search = read('wp-content/mu-plugins/dsa/assets/js/search.js');
const controller = read('wp-content/mu-plugins/dsa/includes/Rest/Search_Controller.php');
const assets = read('wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php');
const service = read('wp-content/mu-plugins/dsa/includes/Search/Search_Service.php');
const secure = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');

check('search REST controller is Surface-gated, not context-awareness-gated',
	plugin.includes("if ( $surface_enabled ) {\n\t\t\t( new Search_Controller( $this->search ) )->register();")
	&& !plugin.includes("$surface_enabled && ! empty( $settings['search']['context_aware'] )")
);
check('search cache and indexing hooks are always registered with the Surface',
	plugin.includes("if ( $surface_enabled ) {\n\t\t\t$this->search->register();")
);
check('non-commerce runtime copy is news-specific',
	surface.includes("'Search news and stories'")
	&& surface.includes("'Find the story you need.'")
	&& surface.includes("/\\bproducts?\\b/i")
);
check('admin copy defaults follow site commerce capability',
	schema.includes("$has_commerce = function_exists( 'wc_get_product' ) && post_type_exists( 'product' )")
	&& schema.includes("__( 'Search news and stories', 'dsa' )")
);
check('search requests default results on mount',
	search.includes('editorialInitialPayload( config, root._dsaSearchScope )')
	&& search.includes("source: 'boot'")
);
check('search GETs reuse browser and edge caches',
	search.includes("cache: 'default'")
	&& !search.includes("_dsa_rt")
	&& !search.includes("no-cache, no-store")
	&& controller.includes("public, max-age=60, s-maxage=300")
);
check('boot token catalog is localized once',
	assets.includes("'kiweTokens' => $kiwe_tokens")
	&& !assets.includes("'seamTokens' => $this->kiwe_tokens_data()")
);
check('Latest and Popular pills use server-authoritative cached sort requests',
	surface.includes('data-dsa-search-sort-options')
	&& search.includes("data-dsa-search-sort=\"")
	&& search.includes("url.searchParams.set( 'sort', sort )")
	&& search.includes("const key = scope + '|' + sort")
	&& controller.includes("in_array( $value, [ 'latest', 'popular' ], true )")
	&& service.includes("'popularAvailable' => $this->popularity_available()")
);
check('Popular news ranking reuses SecureTrack page views without a second tracker',
	secure.includes("add_filter( 'dsa_search_popularity_scores'")
	&& secure.includes("SELECT url, COUNT(*) AS views FROM")
	&& secure.includes("stp_t( 'pages' )")
	&& service.includes("apply_filters( 'dsa_search_popularity_scores'")
	&& assets.includes('private function search_popularity_available()')
);

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} search Surface contracts passed.`);
if (failed.length) process.exit(1);
