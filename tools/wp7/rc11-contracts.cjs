const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..', '..' );
const read = ( file ) => fs.readFileSync( path.join( root, file ), 'utf8' );
const abilities = read( 'wp-content/mu-plugins/dsa/includes/WP7/Abilities_Service.php' );
const native = read( 'wp-content/mu-plugins/dsa/includes/WP7/Native_Service.php' );
const interactivity = read( 'wp-content/mu-plugins/dsa/includes/WP7/Interactive_Blocks_Service.php' );
const assets = read( 'wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php' );
const moduleSource = read( 'wp-content/mu-plugins/dsa/assets/js/modules/native-islands.js' );
const bindings = read( 'wp-content/mu-plugins/dsa/includes/WP7/Bindings_Service.php' );
const plugin = read( 'wp-content/mu-plugins/dsa/includes/Plugin.php' );
const restFiles = fs.readdirSync( path.join( root, 'wp-content/mu-plugins/dsa/includes/Rest' ) ).filter( ( file ) => file.endsWith( '.php' ) ).map( ( file ) => read( 'wp-content/mu-plugins/dsa/includes/Rest/' + file ) ).join( '\n' );
const checks = [];
const check = ( name, pass ) => checks.push( { name, pass: Boolean( pass ) } );
const abilityCount = ( abilities.match(/wp_register_ability\(/g) || [] ).length;
const expectedAbilities = [
	'dsa/audit-trust',
	'dsa/summarize-route',
	'dsa/get-site-graph',
	'dsa/validate-bindings',
	'dsa/prepare-apply-plan',
	'dsa/stage-apply-plan',
];

check( 'Abilities register on official category and ability hooks', abilities.includes( "wp_abilities_api_categories_init" ) && abilities.includes( "wp_abilities_api_init" ) );
check( 'Ability category is registered before use', abilities.includes( 'wp_register_ability_category' ) && abilities.includes( "private const CATEGORY = 'kiwe-appsite'" ) );
check( 'Only bounded read-first AI connector abilities ship', abilityCount === expectedAbilities.length && expectedAbilities.every( ( id ) => abilities.includes( `'${ id }'` ) ) );
check( 'Abilities are core-REST discoverable with explicit non-mutation annotations', ( abilities.match(/'show_in_rest'\s*=>\s*true/g) || [] ).length === abilityCount && ( abilities.match(/'readonly'\s*=>\s*true/g) || [] ).length >= 5 && abilities.includes( "'writesKiweReviewQueue' => true" ) && abilities.includes( "'mutatesWordPress'      => false" ) && abilities.includes( "'mutatesBricksContent'  => false" ) );
check( 'Abilities require explicit administrator capability', abilities.includes( "current_user_can( 'manage_options' )" ) && ( abilities.match(/'permission_callback'/g) || [] ).length === abilityCount );
check( 'Every returned ability payload has a mandatory schema', ( abilities.match(/'output_schema'/g) || [] ).length === abilityCount && abilities.includes( "'required'" ) && abilities.includes( "'maxItems' => 24" ) );
check( 'Ability output is bounded and excludes raw settings and elements', abilities.includes( 'array_slice( $types, 0, 24 )' ) && abilities.includes( 'array_slice( (array)' ) && !abilities.includes( "'elements' =>" ) && !abilities.includes( '$this->settings->all()' ) );
check( 'Ability service contains no write or transport authority', !/\b(?:update_option|update_post_meta|update_user_meta|wp_insert_post|wp_update_post|delete_option|delete_post_meta|wp_remote_|curl_|dsaPost)\s*\(/.test( abilities ) );
check( 'Native service registers the ability adapter', native.includes( '$this->abilities->register();' ) && plugin.includes( 'new Native_Service( $this->settings, $this->registry, $this->trust, $this->site_graph )' ) );
check( 'WordPress 7 receives one native Interactivity script module', assets.includes( "wp_enqueue_script_module(" ) && assets.includes( "'dsa-native-islands'" ) && assets.includes( "[ '@wordpress/interactivity' ]" ) );
check( 'Partial hosts retain classic island fallbacks', assets.includes( "foreach ( [ 'ai', 'app', 'data' ] as $island )" ) && assets.includes( "function_exists( 'wp_interactivity_state' )" ) );
check( 'Native island bridge consumes the canonical Surface events', [ 'surface:ai:notifications', 'surface:app:adoption', 'surface:native:data' ].every( ( event ) => moduleSource.includes( event ) ) );
check( 'Native island bridge imports only Interactivity and owns no server mutations', moduleSource.includes( "from '@wordpress/interactivity'" ) && !/\b(?:fetch|XMLHttpRequest|dsaPost|localStorage|sessionStorage)\s*\(/.test( moduleSource ) );
check( 'Existing site identity Block Binding remains read-only', bindings.includes( "'kiwe/site'" ) && bindings.includes( "'mutations'  => false" ) );
check( 'REST registration stays explicit', restFiles.includes( 'register_rest_route(' ) && !/Reflection(?:Class|Method|Function)/.test( restFiles ) );
check( 'AI Client remains availability-only and cannot alter visitor trust', interactivity.includes( 'Surface rendering' ) && native.includes( 'Visitor-facing trust remains deterministic' ) );

for ( const item of checks ) console.log( `${ item.pass ? 'PASS' : 'FAIL' } ${ item.name }` );
const failed = checks.filter( ( item ) => !item.pass );
console.log( `\n${ checks.length - failed.length }/${ checks.length } RC11 WordPress 7 native-adapter contracts passed.` );
if ( failed.length ) process.exit( 1 );
