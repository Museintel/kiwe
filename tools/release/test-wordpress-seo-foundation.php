<?php
/** CLI-only WordPress/MariaDB fixture. Every write belongs to connection-local native-shaped tables. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_SEO_NATIVE_TEST' ) || true !== KIWE_SEO_NATIVE_TEST ) throw new RuntimeException( 'Owned native SEO fixture required.' );

function kiwe_seo_test_isolate(): void {
 global $wpdb;
 if ( wp_using_ext_object_cache() ) throw new RuntimeException( 'Persistent object cache cannot be isolated safely.' );
 $GLOBALS['kiwe_seo_test_native_cache'] = $GLOBALS['wp_object_cache'];
 $prefix = 'kiwe288qa_' . substr( hash( 'sha256', KIWE_SEO_SOURCE_DIR ), 0, 12 ) . '_';
 $names = [ 'options', 'posts', 'postmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'users', 'usermeta', 'comments', 'commentmeta', 'links' ];
 $original = []; foreach ( $names as $name ) $original[$name] = $wpdb->$name;
 $GLOBALS['kiwe_seo_test_original'] = $original;
 $GLOBALS['kiwe_seo_test_tables'] = [];
 $GLOBALS['kiwe_seo_test_protected'] = [];
 $GLOBALS['kiwe_seo_test_options_hash'] = kiwe_seo_test_options_hash( $original['options'] );
 foreach ( $original as $name=>$table ) {
  if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) throw new RuntimeException( 'Original native table refused.' );
  if ( 'options' !== $name ) $GLOBALS['kiwe_seo_test_protected'][$name] = hash( 'sha256', serialize( $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY 1', ARRAY_A ) ) );
  $owned = $prefix . $name;
  if ( false === $wpdb->query( 'CREATE TEMPORARY TABLE ' . $owned . ' LIKE ' . $table ) ) throw new RuntimeException( 'Owned native table creation refused.' );
  $GLOBALS['kiwe_seo_test_tables'][$name] = $owned;
  if ( 'options' === $name && false === $wpdb->query( 'INSERT INTO ' . $owned . ' SELECT * FROM ' . $table ) ) throw new RuntimeException( 'Owned native options unavailable.' );
  $wpdb->$name = $owned;
 }
 $nativeOptions = $wpdb->options;
 foreach ( [ 'kiwe_site_owner_policy_v1'=>serialize( [ 'enabled'=>false, 'ownerId'=>0 ] ), 'kiwe_seo_workspace_enabled_v1'=>'1', 'kiwe_ai_workspace_enabled_v1'=>'0', 'blog_public'=>'1', 'kiwe_seo_defaults_v1'=>serialize( [] ), 'kiwe_securetrack_enabled'=>'0' ] as $key=>$value ) $wpdb->query( $wpdb->prepare( "INSERT INTO {$nativeOptions}(option_name,option_value,autoload) VALUES(%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'", $key, $value ) );
 $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
 // Route optional integration SQL into distinct connection-local native-shaped tables.
 $GLOBALS['kiwe_seo_test_shadows']=[];$optionalMap=[];
 $candidates=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'));
 foreach($candidates as$table){$suffix=substr($table,strlen($wpdb->prefix));if(!preg_match('/^(?:stp_|dsa_|pk_|wc_|litespeed_|actionscheduler_|woocommerce_sessions$)/D',$suffix))continue;if(!preg_match('/^[A-Za-z0-9_]+$/D',$table)||count($optionalMap)>=128)throw new RuntimeException('Bounded optional fixture schemas required');$shadow=$prefix.'optional_'.count($optionalMap);if(false===$wpdb->query('CREATE TEMPORARY TABLE '.$shadow.' LIKE '.$table))throw new RuntimeException('Optional native schema isolation unavailable');$optionalMap[$table]=$shadow;$GLOBALS['kiwe_seo_test_shadows'][]=$shadow;}
 $GLOBALS['kiwe_seo_test_optional_routes']=static function($sql)use($optionalMap){foreach($optionalMap as$from=>$to)$sql=preg_replace('/(?<![A-Za-z0-9_])'.preg_quote($from,'/').'(?![A-Za-z0-9_])/',$to,$sql);return$sql;};
 add_filter('query',$GLOBALS['kiwe_seo_test_optional_routes'],999999);
 $allow = array_merge(array_values( $GLOBALS['kiwe_seo_test_tables'] ),$GLOBALS['kiwe_seo_test_shadows']);
 $GLOBALS['kiwe_seo_test_write_guard'] = static function( $sql ) use ( $allow ) {
  if ( preg_match( '/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE(?:\s+[A-Za-z0-9_]+)?\s+FROM|ALTER\s+TABLE|DROP(?:\s+TEMPORARY)?\s+TABLE|TRUNCATE(?:\s+TABLE)?|CREATE(?:\s+TEMPORARY)?\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+`?([A-Za-z0-9_]+)/i', $sql, $m ) && ! in_array( $m[1], $allow, true ) ) throw new RuntimeException( 'Unowned native write refused: ' . $m[1] );
  return $sql;
 };
 add_filter( 'query', $GLOBALS['kiwe_seo_test_write_guard'], PHP_INT_MAX );
 $GLOBALS['kiwe_seo_test_http_calls'] = 0; $GLOBALS['kiwe_seo_test_mail_calls'] = 0;
 add_filter( 'pre_http_request', static function(){ ++$GLOBALS['kiwe_seo_test_http_calls']; return new WP_Error( 'fixture_network', 'Native fixture refuses outgoing network.' ); }, PHP_INT_MAX );
 add_filter( 'pre_wp_mail', static function(){ ++$GLOBALS['kiwe_seo_test_mail_calls']; return false; }, PHP_INT_MAX );
}

function kiwe_seo_test_options_hash( string $table ): string {
 global $wpdb;
 $rows = $wpdb->get_results( "SELECT option_name,option_value,autoload FROM {$table} WHERE option_name REGEXP '^(kiwe_|dsa_|pk_|bricks)' OR option_name IN ('active_plugins','blogname','blogdescription','blog_public','home','siteurl','permalink_structure','show_on_front','page_on_front','page_for_posts','template','stylesheet','{$wpdb->prefix}user_roles') ORDER BY option_name", ARRAY_A );
 if ( $wpdb->last_error ) throw new RuntimeException( 'Original authority read unavailable.' );
 return hash( 'sha256', serialize( $rows ) );
}

function kiwe_seo_test_run(): array {
 global $wpdb, $wp_query, $wp_the_query, $wp_rewrite, $current_screen;
 $proof = [ 'schema'=>'kiwe.seo-native-foundation.v1', 'passed'=>false, 'cleanup'=>false, 'checks'=>[], 'observations'=>[] ];
 $GLOBALS['kiwe_seo_test_partial_proof'] =& $proof;
 $assert = static function( $ok, string $name ) use ( &$proof ): void {
  $proof['checks'][$name]=(bool)$ok;
  if(!$ok)$proof['failures'][]=$name;
  kiwe_seo_test_progress($proof);
  // Required fixture setup fails closed; independent output regressions are collected in one native pass.
  if(!$ok&&in_array($name,['Native synthetic users retain WordPress role authority','Native posts pages custom types categories and tags are created only in owned tables','Native defaults validate before any write','Manager defaults save through actual WordPress option readback'],true))throw new RuntimeException($name);
 };
 $query = static function( array $args ): WP_Query { global $wp_query, $wp_the_query; $wp_query = new WP_Query(); $wp_query->query($args); $wp_the_query = $wp_query; $GLOBALS['post']=$wp_query->post; return $wp_query; };
 $refresh = static function(): void { ( new DSA\SEO\SEO_Sitemap_Service() )->invalidate(); };
 $request = static function( string $method = 'GET', array $body = [], bool $proof = true ): WP_REST_Request {
  $r = new WP_REST_Request( $method ); $r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); if ( $proof ) $r->set_header( 'x-kiwe-mutation', '1' ); $r->set_body( wp_json_encode( $body ) ); $r->set_header( 'content-type', 'application/json' ); return $r;
 };
 $owner = null; $source = null;
 try {
  // The fixture observes cache invalidation intent without purging the served lab's cache or cache tables.
  $GLOBALS['kiwe_seo_test_cache_purges']=0;
  foreach(['litespeed_purge_all','litespeed_purge_post','litespeed_purge_url']as$hook){remove_all_actions($hook);add_action($hook,static function(){++$GLOBALS['kiwe_seo_test_cache_purges'];});}
  remove_all_actions( 'transition_post_status' );
  remove_all_actions( 'publish_post' );
  update_option( 'blogname', 'Synthetic News', false ); update_option( 'blogdescription', 'Independent reporting', false );
  update_option( 'show_on_front', 'posts', false ); update_option( 'page_for_posts', 0, false ); update_option( 'page_on_front', 0, false );
  update_option( 'permalink_structure', '/%postname%/', false ); $wp_rewrite->init();
  // Native fixture identities exercise real WP_User capabilities without unrelated enrollment, campaign or commerce hooks.
  $seed_user=static function(string $name,string $role)use($wpdb):int{
   if(!$wpdb->insert($wpdb->users,['user_login'=>'synthetic-seo-'.$name,'user_pass'=>wp_hash_password(wp_generate_password(24)),'user_email'=>'seo-'.$name.'@example.test','user_nicename'=>'synthetic-'.$name,'user_registered'=>current_time('mysql',true),'display_name'=>'Synthetic '.$name]))throw new RuntimeException('Native fixture identity could not be seeded.');
   $id=(int)$wpdb->insert_id;update_user_meta($id,$wpdb->prefix.'capabilities',[$role=>true]);clean_user_cache($id);return$id;
  };
  $owner=$seed_user('manager','administrator');$author=$seed_user('author','author');$editor=$seed_user('editor','editor');
  $assert( ! is_wp_error( $owner ) && ! is_wp_error( $author ) && ! is_wp_error( $editor ), 'Native synthetic users retain WordPress role authority' );
  wp_set_current_user( $owner );
  kiwe_seo_test_progress($proof+['phase'=>'manager current user set']);
  register_post_type( 'fixture_service', [ 'public'=>true, 'publicly_queryable'=>true, 'has_archive'=>true, 'show_in_rest'=>true, 'label'=>'Fixture services', 'supports'=>[ 'title','editor' ] ] );
  register_taxonomy( 'fixture_topic', 'fixture_service', [ 'public'=>true, 'show_in_rest'=>true, 'label'=>'Fixture topics' ] );
  kiwe_seo_test_progress($proof+['phase'=>'public fixture types registered']);
  $post = wp_insert_post( [ 'post_type'=>'post', 'post_status'=>'publish', 'post_author'=>$author, 'post_title'=>'Original news', 'post_name'=>'synthetic-news', 'post_excerpt'=>'Native excerpt.', 'post_content'=>'Authored news body.' ], true );
  kiwe_seo_test_progress($proof+['phase'=>'native post inserted']);
  $page = wp_insert_post( [ 'post_type'=>'page', 'post_status'=>'publish', 'post_title'=>'About the paper', 'post_name'=>'synthetic-about', 'post_content'=>'Authored page body.' ], true );
  $servicePost = wp_insert_post( [ 'post_type'=>'fixture_service', 'post_status'=>'publish', 'post_title'=>'Public reporting', 'post_name'=>'synthetic-service' ], true );
  $category = wp_insert_term( 'Synthetic blog', 'category', [ 'description'=>'Original native category.' ] ); $category = is_wp_error( $category ) ? 0 : $category['term_id'];
  $tag = wp_insert_term( 'Synthetic news tag', 'post_tag' ); $tag = is_wp_error( $tag ) ? 0 : $tag['term_id'];
  wp_set_post_terms( $post, [ $category ], 'category' );
  $assert( ! is_wp_error( $post ) && ! is_wp_error( $page ) && ! is_wp_error( $servicePost ) && $category && $tag, 'Native posts pages custom types categories and tags are created only in owned tables' );
  $_SERVER['HTTP_ORIGIN'] = home_url(); $_SERVER['HTTP_REFERER'] = home_url( '/wp-admin/' ); $_SERVER['REQUEST_METHOD'] = 'POST';
  $defaults = new DSA\SEO\SEO_Defaults_Service(); $maps = new DSA\SEO\SEO_Sitemap_Service(); $seo = new DSA\SEO\SEO_Output_Service( new DSA\Onboarding\Design_Context_Profile_Service() );
   if(!defined('RANK_MATH_VERSION')){
    $contextBefore=get_option(DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE,[]);$languageBefore=get_option('WPLANG','');$pub=$contextBefore;$pub['identity']['siteType']='publication';$pub['business']['activities']['publication']=true;$pub['seo']['primaryGoal']='read';update_option(DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE,$pub,false);update_option('WPLANG','en_IN',false);
    $auto=DSA\SEO\SEO_Defaults_Service::news();$xml=$maps->news_xml();
    $assert($auto===['enabled'=>true,'publication'=>'Synthetic News','language'=>'en']&&!is_wp_error($xml)&&str_contains($xml,'<news:name>Synthetic News</news:name>'),'Native publication identity automatically generates News XML with saved native language and name');
    DSA\Onboarding\SEO_Context_Service::set_object_indexing($post,true);$refresh();$assert(!str_contains($maps->news_xml(),esc_xml(get_permalink($post))),'Native individual noindex removes a recent article from automatic News XML');
    DSA\Onboarding\SEO_Context_Service::set_object_indexing($post,false);$refresh();$assert(str_contains($maps->news_xml(),esc_xml(get_permalink($post))),'Native individual index returns the recent article to automatic News XML');delete_post_meta($post,'_kiwe_seo_robots');
    $contextApp=new DSA\Access\Continuity_App_Service();$read=$request('GET');$read->set_url_params(['area'=>'story']);$view=$contextApp->read($read)->get_data();
    $assert(isset($view['fields'])&&!in_array('seo.allowIndexing',array_column($view['fields'],'path'),true),'Native client Story exposes no site-wide indexing field');
    $write=$request('POST',['path'=>'seo.primaryGoal','value'=>'subscribe','expected'=>$view['expected']]);$write->set_url_params(['area'=>'story']);$saved=$contextApp->save($write);
    $assert($saved->get_status()===200&&DSA\Onboarding\SEO_Context_Service::goal()['key']==='subscribe'&&DSA\Analytics\Analytics_Report_Service::goal()['label']==='Subscriptions','Native goal field changes the shared SEO and Analytics goal through one original writer');
    $latest=$contextApp->read($read)->get_data();$master=$request('POST',['path'=>'seo.allowIndexing','value'=>false,'expected'=>$latest['expected']]);$master->set_url_params(['area'=>'story']);$denied=$contextApp->save($master);$assert($denied->get_status()===400&&get_option('blog_public')==='1','Native removed master indexing path refuses without changing site privacy');
    $copy=(new DSA\Onboarding\Design_Context_Profile_Service())->current();$copy['seo']['allowIndexing']=false;$result=(new DSA\Onboarding\Design_Context_Profile_Service())->save_section('story',$copy,$owner);$assert(!is_wp_error($result)&&get_option('blog_public')==='1','Native legacy Story input cannot turn public indexing off');
    update_option('blog_public','0',false);$copy['seo']['allowIndexing']=true;$result=(new DSA\Onboarding\Design_Context_Profile_Service())->save_section('story',$copy,$owner);$refresh();$proof['observations']['privateStory']=['saved'=>!is_wp_error($result),'error'=>is_wp_error($result)?$result->get_error_message():'','public'=>get_option('blog_public'),'newsDisabled'=>is_wp_error($maps->news_xml())];$assert(!is_wp_error($result)&&(string)get_option('blog_public')==='0'&&is_wp_error($maps->news_xml()),'Native Story saves preserve technical private-site protection and exclude News discovery');update_option('blog_public','1',false);
    $pub['identity']['siteType']='business';$pub['business']['activities']['publication']=false;update_option(DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE,$pub,false);$assert(is_wp_error($maps->news_xml()),'Native business type does not enable the automatic publication feed');
    update_option(DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE,$contextBefore,false);update_option('WPLANG',$languageBefore,false);
   }
  $settings = DSA\SEO\SEO_Defaults_Service::validate( [ 'verification'=>['google'=>'SyntheticGoogleCode_123456789','bing'=>str_repeat('a',32),'pinterest'=>str_repeat('b',32)], 'separator'=>'|', 'home'=>[ 'title'=>'%sitename% %sep% %tagline%', 'description'=>'Homepage description' ], 'blog'=>[ 'title'=>'News listing %sep% %sitename%', 'description'=>'All our reporting' ], 'postTypes'=>[ 'post'=>[ 'title'=>'%title% %sep% %sitename%', 'description'=>'%excerpt%' ], 'page'=>[ 'title'=>'%title% %sep% %sitename%' ], 'fixture_service'=>[ 'title'=>'%title% %sep% Services' ] ], 'taxonomies'=>[ 'category'=>[ 'title'=>'%term% %sep% %sitename%', 'description'=>'%term_description%' ], 'post_tag'=>[ 'title'=>'%term% %sep% Tags' ] ], 'archives'=>[ 'author'=>[ 'title'=>'%name% %sep% Author' ], 'date'=>[ 'title'=>'%title% %sep% Archive' ], 'fixture_service'=>[ 'title'=>'Services %sep% %sitename%' ] ], 'social'=>[ 'image'=>'https://example.test/synthetic-image.jpg' ], 'sitemap'=>[ 'pageSize'=>50 ], 'news'=>[ 'enabled'=>true, 'publication'=>'Synthetic News', 'language'=>'en' ] ] );
  $assert( ! is_wp_error( $settings ), 'Native defaults validate before any write' );
  $external = defined( 'RANK_MATH_VERSION' );
  $render = static function(): string { ob_start(); do_action( 'wp_head' ); return (string) ob_get_clean(); };
  if ( $external ) {
   $assert( ! DSA\SEO\SEO_Defaults_Service::enabled(), 'Active actual Rank Math retains native SEO output authority' );
   $assert( 409 === $defaults->write( $request( 'POST', [ 'expected'=>DSA\SEO\SEO_Defaults_Service::expected(), 'settings'=>$settings ] ) )->get_status(), 'Active actual Rank Math blocks conflicting Kiwe defaults writes' );
   update_post_meta( $post, 'rank_math_title', 'Rank title %sep% %sitename%' ); update_post_meta( $post, 'rank_math_description', 'Rank description' ); update_post_meta( $post, 'rank_math_robots', [ 'index','follow' ] );
   update_post_meta( $post, '_kiwe_seo_title', 'Dormant Kiwe title' ); update_post_meta( $post, '_kiwe_seo_description', 'Dormant Kiwe description' ); update_post_meta( $post, '_kiwe_seo_robots', [ 'noindex' ] );
   $query( [ 'p'=>$post, 'post_type'=>'post' ] );
   // CLI wp-load does not run the public wp lifecycle. Initialize the real provider's registered integration.
   $front=null;$variables=null;
   foreach(($GLOBALS['wp_filter']['wp']->callbacks??[])as$callbacks)foreach($callbacks as$item){$fn=$item['function'];if(!is_array($fn))continue;
    if(($fn[0]??null)instanceof \RankMath\Frontend\Frontend&&($fn[1]??'')==='integrations'){$front=$fn[0];$fn();}
    elseif(($fn[0]??null)instanceof \RankMath\Replace_Variables\Manager&&($fn[1]??'')==='setup'){$variables=$fn[0];$fn();}
   }
   if(!$front instanceof \RankMath\Frontend\Frontend||!$variables instanceof \RankMath\Replace_Variables\Manager)throw new RuntimeException('Actual provider public lifecycle unavailable');
   $assert(\RankMath\Helper::replace_vars('%sep% %sitename%',get_post($post))==='- Synthetic News','Actual Rank Math registered public variable lifecycle resolves native site facts');
   $assert(rank_math()->head instanceof \RankMath\Frontend\Head&&has_filter('pre_get_document_title',[rank_math()->head,'title']),'Actual Rank Math frontend integration owns native title and head hooks');
   $assert( $seo->document_title( 'Provider title' ) === 'Provider title', 'External title cannot be replaced by dormant Kiwe metadata' );
   $assert( $seo->robots( [ 'index'=>true ] ) === [ 'index'=>true ], 'External robots cannot be replaced by dormant Kiwe directives' );
   $assert( ! DSA\Onboarding\SEO_Context_Service::object_noindex( $post ), 'Actual external explicit indexing wins over dormant Kiwe noindex' );
   delete_post_meta( $post, 'rank_math_robots' );
   $assert( ! DSA\Onboarding\SEO_Context_Service::object_noindex( $post ), 'Missing actual external directive never activates dormant Kiwe metadata' );
   $assert( $maps->enabled( false ) === false && $maps->posts_args( [ 'provider'=>'external' ], 'post' ) === [ 'provider'=>'external' ], 'External sitemap enable and query decisions pass through untouched' );
   $robots = 'Sitemap: ' . home_url( '/sitemap_index.xml' ) . "\n";
   $assert( $maps->robots_txt( $robots, true ) === $robots, 'Actual external discovery never gains a competing Kiwe sitemap' );
   $externalHead=$render();
   $proof['observations']['externalDocumentTitle']=wp_get_document_title();$proof['observations']['externalKiweHeadPresent']=str_contains($externalHead,'data-kiwe-seo');
   $assert(wp_get_document_title()==='Rank title - Synthetic News'&&!str_contains($externalHead,'data-kiwe-seo'),'Actual Rank Math title renders while the Kiwe head owner stays dormant');
   $assert(substr_count($externalHead,'name="description"')===1&&str_contains($externalHead,'content="Rank description"'),'Actual Rank Math renders the sole supported description');
   $assert(substr_count($externalHead,'rel="canonical"')===1&&str_contains($externalHead,get_permalink($post)),'Actual Rank Math retains canonical output without a Kiwe duplicate');
   $assert( $seo->bricks_seo( false ) === true, 'Active external provider owns metadata while competing Bricks output stays suppressed' );
   ob_start(); $seo->head(); $head = ob_get_clean();
   $assert( $head === '', 'Kiwe emits no competing head tags with actual Rank Math loaded' );
   $proof['observations']['externalVersion'] = RANK_MATH_VERSION;
  } else {
   $saved = $defaults->write( $request( 'POST', [ 'expected'=>DSA\SEO\SEO_Defaults_Service::expected(), 'settings'=>$settings ] ) );
   $assert( $saved->get_status() === 200 && get_option( DSA\SEO\SEO_Defaults_Service::OPTION ) === $settings, 'Manager defaults save through actual WordPress option readback' );
   $assert($GLOBALS['kiwe_seo_test_cache_purges']>0,'Native defaults save requests cache-owner invalidation through an isolated transport');
   $assert( ! in_array( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", DSA\SEO\SEO_Defaults_Service::OPTION ) ), [ 'yes','on','auto-on' ], true ), 'SEO defaults are not added to visitor autoload state' );
   wp_set_current_user( $author ); $assert( 403 === $defaults->read( $request() )->get_status(), 'Native Author cannot read website defaults' );
   $assert( 403 === $defaults->write( $request( 'POST', [ 'expected'=>DSA\SEO\SEO_Defaults_Service::expected(), 'settings'=>$settings ] ) )->get_status(), 'Native Author cannot write website defaults' );
   wp_set_current_user( $editor ); $assert( 403 === $defaults->read( $request() )->get_status(), 'Native Editor does not inherit website-manager SEO rights' );
   wp_set_current_user( $owner );
   $assert( 403 === $defaults->write( $request( 'POST', [ 'expected'=>DSA\SEO\SEO_Defaults_Service::expected(), 'settings'=>$settings ], false ) )->get_status(), 'Native mutation proof is required for defaults writes' );
   $assert( 409 === $defaults->write( $request( 'POST', [ 'expected'=>str_repeat( '0',64 ), 'settings'=>$settings ] ) )->get_status(), 'Native stale settings cannot overwrite current defaults' );
   $query( [ 'p'=>$post, 'post_type'=>'post' ] );
   $assert( wp_get_document_title() === 'Original news | Synthetic News', 'Native post title renders a full live template without duplicate site suffix' );
   wp_update_post( [ 'ID'=>$post, 'post_title'=>'Edited news' ] ); clean_post_cache( $post ); $query( [ 'p'=>$post ] );
   $assert( wp_get_document_title() === 'Edited news | Synthetic News', 'Native authored title edits update the rendered template' );
   update_post_meta( $post, '_kiwe_seo_title', 'Record %title%' );
   $assert( wp_get_document_title() === 'Record Edited news', 'Record title takes precedence over website defaults' );
   delete_post_meta( $post, '_kiwe_seo_title' );
   $head = $render();
   foreach ( [ 'name="description"','rel="canonical"','property="og:title"','property="og:description"','name="twitter:title"' ] as $needle ) $assert( substr_count( $head, $needle ) === 1, 'One native rendered head owner: ' . $needle );
   $assert(preg_match_all('#<meta\b[^>]*\bname\s*=\s*(?:"robots"|\x27robots\x27)#i',$head)===1,'One native rendered head owner: robots with native quote formatting');
   $assert( str_contains( $head, 'Native excerpt.' ) && str_contains( $head, get_permalink( $post ) ), 'Native excerpt canonical and social context reach rendered head' );
   update_post_meta( $post, '_kiwe_seo_description', 'Record description' ); update_post_meta( $post, '_kiwe_seo_facebook_title', 'Social %title%' ); update_post_meta( $post, '_kiwe_seo_twitter_title', 'Tweet %title%' );
   $head = $render(); $assert( str_contains( $head, 'Record description' ) && str_contains( $head, 'Social Edited news' ) && str_contains( $head, 'Tweet Edited news' ), 'Individual social and description templates render current native facts' );
   update_post_meta( $post, '_kiwe_seo_description', '' ); $head = $render();
   $assert( ! str_contains( $head, 'name="description"' ), 'Explicit empty record description suppresses inherited descriptions' ); delete_post_meta( $post, '_kiwe_seo_description' );
   update_post_meta( $post, '_kiwe_seo_canonical_url', 'https://example.test/original-source/' ); $head = $render();
   $assert( str_contains( $head, 'rel="canonical" href="https://example.test/original-source/"' ), 'Custom canonical renders through the single native head owner' );
   $assert( SitemapsFixtureEntries( $maps->post_entry( [ 'loc'=>get_permalink( $post ) ], get_post( $post ) ) ) === [], 'Custom non-self canonical excludes a native sitemap record' ); delete_post_meta( $post, '_kiwe_seo_canonical_url' );
   update_post_meta( $post, '_kiwe_seo_robots', [ 'noindex','nofollow' ] ); $robots = apply_filters( 'wp_robots', [] );
   $assert( ! empty( $robots['noindex'] ) && ! empty( $robots['nofollow'] ), 'Native noindex and nofollow directives reach WordPress robots' );
   $assert( SitemapsFixtureEntries( $maps->post_entry( [ 'loc'=>get_permalink( $post ) ], get_post( $post ) ) ) === [], 'Native noindex safely removes a full URL entry' );
   $assert( is_array( $maps->post_entry( [ 'loc'=>get_permalink( $post ) ], get_post( $post ) ) ), 'Native provider never passes false to the WordPress XML renderer' ); delete_post_meta( $post, '_kiwe_seo_robots' );
   $query( [ 'page_id'=>$page ] ); $assert( wp_get_document_title() === 'About the paper | Synthetic News', 'Native page title uses page scope defaults' );
   $query( [ 'p'=>$servicePost, 'post_type'=>'fixture_service' ] ); $assert( wp_get_document_title() === 'Public reporting | Services', 'Public custom post types use their registered scope' );
   $query( [ 'cat'=>$category ] ); $assert( wp_get_document_title() === 'Synthetic blog | Synthetic News', 'Native category title uses taxonomy facts' );
   $assert( str_contains( $render(), 'Original native category.' ), 'Native category description renders through taxonomy defaults' );
   update_term_meta( $category, '_kiwe_seo_title', 'Record %term%' ); $assert( wp_get_document_title() === 'Record Synthetic blog', 'Explicit term title wins over taxonomy defaults' ); delete_term_meta( $category, '_kiwe_seo_title' );
   $query( [ 'tag_id'=>$tag ] ); $assert( wp_get_document_title() === 'Synthetic news tag | Tags', 'Native tag archive receives its own SEO template' );
   $query( [ 'author'=>$author ] ); $assert( wp_get_document_title() === 'Synthetic author | Author', 'Native author archive uses author scope' );
   $query( [ 'year'=>(int) gmdate( 'Y' ), 'monthnum'=>(int) gmdate( 'm' ) ] ); $assert( str_contains( wp_get_document_title(), ' | Archive' ), 'Native date archive uses date scope' );
   $query( [ 'post_type'=>'fixture_service' ] ); $assert( wp_get_document_title() === 'Services | Synthetic News', 'Native custom type archive uses archive defaults' );
   $query( [ 'cat'=>$category, 'paged'=>2 ] ); $assert( str_ends_with( wp_get_document_title(), ' | Page 2' ), 'Native archive pagination is visible without duplicate title parts' );
   $assert( str_contains( $render(), get_term_link( $category, 'category' ) . 'page/2/' ), 'Native archive canonical preserves its page without copying request tracking parameters' );
   update_option( 'show_on_front','posts',false ); $query( [] );
   $assert( wp_get_document_title() === 'Synthetic News | Independent reporting', 'Native homepage title uses website scope' );
   $verificationHead=$render();$assert(substr_count($verificationHead,'name="google-site-verification"')===1&&str_contains($verificationHead,'SyntheticGoogleCode_123456789')&&substr_count($verificationHead,'name="msvalidate.01"')===1&&substr_count($verificationHead,'name="p:domain_verify"')===1,'Native homepage renders exactly the three validated verification tags');
   $fallbackSettings=$settings;unset($fallbackSettings['home']['description']);
   $defaults->write($request('POST',['expected'=>DSA\SEO\SEO_Defaults_Service::expected(),'settings'=>$fallbackSettings]));
   update_option(DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE,[],false);delete_option(DSA\SEO\SEO_Output_Service::PUBLIC_CACHE);
   $coldHomeQueries=[];$traceHome=static function($sql)use(&$coldHomeQueries){$coldHomeQueries[]=$sql;return$sql;};add_filter('query',$traceHome,999999);
   ob_start();$seo->head();$coldHome=ob_get_clean();remove_filter('query',$traceHome,999999);
   $assert(str_contains($coldHome,'content="Independent reporting"'),'Cold native homepage empty approved fields retain the native description fallback');
   $assert(!array_filter($coldHomeQueries,static fn($sql)=>preg_match('/(?:INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\s/i',$sql)),'Cold native homepage SEO does not write or rebuild a public projection');
   $assert(!array_filter($coldHomeQueries,static fn($sql)=>str_contains($sql,'post_type IN')||str_contains($sql,"post_type = 'page'")),'Cold native homepage SEO does not enumerate the content library');
   $proof['observations']['coldHomepageSeoQueries']=count($coldHomeQueries);
   $defaults->write($request('POST',['expected'=>DSA\SEO\SEO_Defaults_Service::expected(),'settings'=>$settings]));
   $query( [ 'p'=>$post ] );
   if ( defined( 'BRICKS_DB_PAGE_SETTINGS' ) && class_exists( '\Bricks\Database' ) ) {
    $base = wp_insert_post( [ 'post_type'=>'bricks_template', 'post_status'=>'publish', 'post_title'=>'Synthetic native template' ], true );
    update_post_meta( $base, BRICKS_DB_PAGE_SETTINGS, [ 'documentTitle'=>'Template title', 'metaDescription'=>'Template description', 'sharingTitle'=>'Template social' ] );
    update_post_meta( $post, BRICKS_DB_PAGE_SETTINGS, [ 'documentTitle'=>'', 'metaDescription'=>'' ] );
    $oldActive = \Bricks\Database::$active_templates; $oldGlobal = \Bricks\Database::$global_settings;
    \Bricks\Database::$active_templates['content'] = $base; \Bricks\Database::$global_settings['disableSeo'] = false;
    $assert( wp_get_document_title() === 'Template title', 'Actual Bricks empty page values retain active template fallback' );
    $assert( str_contains( $render(), 'Template description' ), 'Actual Bricks template description flows through Kiwe head authority' );
    update_post_meta( $post, BRICKS_DB_PAGE_SETTINGS, [ 'documentTitle'=>'Page Bricks title', 'metaDescription'=>'Page Bricks description', 'metaRobots'=>[ 'noindex' ] ] );
    $assert( wp_get_document_title() === 'Page Bricks title' && str_contains( $render(), 'Page Bricks description' ), 'Actual Bricks page settings precede selected template settings' );
    $assert( ! empty( apply_filters( 'wp_robots', [] )['noindex'] ) && SitemapsFixtureEntries( $maps->post_entry( [ 'loc'=>get_permalink( $post ) ], get_post( $post ) ) ) === [], 'Actual Bricks record noindex reaches robots and regular sitemap exclusion' );
    update_post_meta( $post, '_kiwe_seo_title', 'Kiwe record title' ); $assert( wp_get_document_title() === 'Kiwe record title', 'Explicit Kiwe record title precedes Bricks fallback' );
    delete_post_meta( $post, '_kiwe_seo_title' ); delete_post_meta( $post, BRICKS_DB_PAGE_SETTINGS ); \Bricks\Database::$active_templates=$oldActive; \Bricks\Database::$global_settings=$oldGlobal;
   } else throw new RuntimeException( 'Actual native Bricks compatibility source required.' );
   $before = $wpdb->num_queries; $writes = [];
   $trace = static function( $sql ) use ( &$writes ) { $writes[]=$sql; return $sql; }; add_filter( 'query', $trace, 999999 );
   ob_start(); $seo->head(); ob_end_clean(); remove_filter( 'query',$trace,999999 );
   $assert( ! array_filter( $writes, static fn( $sql ) => preg_match( '/(?:INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\s/i',$sql ) ), 'Ordinary current-record SEO rendering adds no native database writes' );
   $assert( ! array_filter( $writes, static fn( $sql ) => str_contains( $sql, 'post_type IN' ) || str_contains( $sql, 'post_type = \'page\'' ) ), 'Ordinary current-record SEO owner does not enumerate the page library' );
   $proof['observations']['currentRecordSeoQueries']=$wpdb->num_queries-$before;
   ob_start(); update_option('kiwe_seo_workspace_enabled_v1','0',false); $seo->head(); $off=ob_get_clean();
   $assert( $off==='' && $seo->document_title('Native title')==='Native title' && $maps->posts_args(['native'=>true],'post')===['native'=>true], 'Native SEO-off preserves core output and performs no optional render work' ); update_option('kiwe_seo_workspace_enabled_v1','1',false);
   update_option('blog_public','0',false); $assert(!$maps->enabled(true)&&!empty(apply_filters('wp_robots',[])['noindex']),'Native site privacy cannot be overridden by SEO defaults'); update_option('blog_public','1',false);
   $password=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Protected story','post_password'=>'synthetic-only'],true); $query(['p'=>$password]);
   $assert(!empty(apply_filters('wp_robots',[])['noindex']),'Native password protection always requires noindex');
   $query(['s'=>'synthetic']);$assert(!empty(apply_filters('wp_robots',[])['noindex']),'Native search pages always require noindex');
   $query(['p'=>99999999]);$wp_query->set_404();$assert(!empty(apply_filters('wp_robots',[])['noindex']),'Native 404 pages always require noindex');
   $query(['p'=>$post]);update_post_meta($page,DSA\Onboarding\Design_Context_Profile_Service::PAGE_VISIBILITY_META,'secondary');update_term_meta($category,DSA\Access\Taxonomy_App_Service::VISIBILITY_META,'secondary');$refresh();
   $server=new WP_Sitemaps();$server->register_sitemaps();$providers=$server->registry->get_providers();
   $assert($providers['posts'] instanceof DSA\SEO\SEO_Posts_Sitemap_Provider && $providers['taxonomies'] instanceof DSA\SEO\SEO_Taxonomies_Sitemap_Provider,'Actual WordPress registry uses thin native pagination providers');
   $pageUrls=$providers['posts']->get_url_list(1,'page');$termUrls=$providers['taxonomies']->get_url_list(1,'category');
   $assert(!in_array(get_permalink($page),array_column($pageUrls,'loc'),true),'Native secondary page is excluded before renderer output');
   $assert(!in_array(get_term_link($category,'category'),array_column($termUrls,'loc'),true),'Native secondary term is excluded before renderer output');
   update_post_meta($page,'_kiwe_seo_robots',['index']);update_term_meta($category,'_kiwe_seo_robots',['index']);$refresh();
   $assert(in_array(get_permalink($page),array_column($providers['posts']->get_url_list(1,'page'),'loc'),true),'Explicit native page index overrides its legacy visibility mirror');
   $assert(in_array(get_term_link($category,'category'),array_column($providers['taxonomies']->get_url_list(1,'category'),'loc'),true),'Explicit native term index overrides its legacy visibility mirror');
   $before=$wpdb->num_queries;$providers['posts']->get_url_list(1,'post');$cold=$wpdb->num_queries-$before;$before=$wpdb->num_queries;$providers['posts']->get_url_list(1,'post');$warm=$wpdb->num_queries-$before;
   $assert($warm<$cold,'Actual native warm sitemap uses its cached projection');$proof['observations']['regularSitemapQueries']=['cold'=>$cold,'warm'=>$warm];
   $renderer=new WP_Sitemaps_Renderer();$xml=$renderer->get_sitemap_xml($providers['posts']->get_url_list(1,'post'));
   $assert((bool)simplexml_load_string($xml)&&!str_contains($xml,'_kiwe_excluded'),'Actual core XML renderer receives valid URL arrays and no private markers');
   // Homepage and blog pages retain distinct indexing defaults when ordinary pages inherit noindex.
   $blog=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Synthetic blog listing','post_name'=>'synthetic-listing'],true);
   delete_post_meta($page,'_kiwe_seo_robots');delete_post_meta($page,DSA\Onboarding\Design_Context_Profile_Service::PAGE_VISIBILITY_META);
   update_option('show_on_front','page',false);update_option('page_on_front',$page,false);update_option('page_for_posts',$blog,false);
   $scoped=$settings;$scoped['postTypes']['page']['robots']=['noindex'];
   $assert($defaults->write($request('POST',['expected'=>DSA\SEO\SEO_Defaults_Service::expected(),'settings'=>$scoped]))->get_status()===200,'Native separate homepage and blog defaults save without shadow page state');$refresh();
   $query(['page_id'=>$page]);$assert(wp_get_document_title()==='Synthetic News | Independent reporting'&&empty(apply_filters('wp_robots',[])['noindex']),'Native static homepage retains its home title and indexing scope');
   $query(['page_id'=>$blog]);$assert(wp_get_document_title()==='News listing | Synthetic News','Native designated blog page retains its blog title scope');
   $urls=array_column($providers['posts']->get_url_list(1,'page'),'loc');
   $assert(in_array(get_permalink($page),$urls,true)&&in_array(get_permalink($blog),$urls,true),'Native static home and blog remain discoverable under ordinary-page noindex');
   update_post_meta($blog,BRICKS_DB_PAGE_SETTINGS,['metaRobots'=>['noindex']]);$refresh();
   $assert(!in_array(get_permalink($blog),array_column($providers['posts']->get_url_list(1,'page'),'loc'),true),'Native Bricks explicit noindex removes the blog page despite inherited blog indexing');
   update_post_meta($blog,'_kiwe_seo_robots',['index']);$refresh();
   $assert(in_array(get_permalink($blog),array_column($providers['posts']->get_url_list(1,'page'),'loc'),true),'Native explicit Kiwe index returns the blog page and overrides Bricks noindex');
   delete_post_meta($blog,'_kiwe_seo_robots');delete_post_meta($blog,BRICKS_DB_PAGE_SETTINGS);
   update_option('show_on_front','posts',false);update_option('page_on_front',0,false);update_option('page_for_posts',0,false);
   $defaults->write($request('POST',['expected'=>DSA\SEO\SEO_Defaults_Service::expected(),'settings'=>$settings]));$refresh();$query(['p'=>$post]);
   $robots=$maps->robots_txt("User-agent: *\n",true);$assert(substr_count($robots,'/wp-sitemap.xml')===1&&substr_count($robots,'/news-sitemap.xml')===1&&$maps->robots_txt($robots,true)===$robots,'Stable native regular and news discovery advertise once');
   $assert($maps->robots_txt('Private robots',false)==='Private robots','Private robots never advertises a Kiwe sitemap');
   // Larger news inventory tests core-query ordering and stable child files without selecting article bodies.
   $old=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Historic archive','post_date'=>gmdate('Y-m-d H:i:s',time()-4*DAY_IN_SECONDS),'post_date_gmt'=>gmdate('Y-m-d H:i:s',time()-4*DAY_IN_SECONDS)],true);
   $recent=gmdate('Y-m-d H:i:s',time()-60);
   $values=[];for($i=0;$i<1001;$i++)$values[]=$wpdb->prepare("('post','publish','Synthetic recent %d','synthetic-recent-%d',%s,%s,%s,%s,%d,'')",$i,$i,$recent,$recent,$recent,$recent,$author);
   $assert($wpdb->query("INSERT INTO {$wpdb->posts}(post_type,post_status,post_title,post_name,post_date,post_date_gmt,post_modified,post_modified_gmt,post_author,post_content) VALUES ".implode(',',$values))===1001,'Owned native news inventory exceeds one thousand recent articles');
   $refresh();$newsIndex=$maps->news_xml();$newsOne=$maps->news_xml('1');$newsTwo=$maps->news_xml('2');
   $index=simplexml_load_string($newsIndex);$one=simplexml_load_string($newsOne);$two=simplexml_load_string($newsTwo);
   $assert($index&&$one&&$two&&$index->getName()==='sitemapindex'&&count($index->sitemap)===2,'Native news inventory splits into stable child sitemap URLs');
   $assert(count($one->url)<=1000&&count($two->url)>0,'Every native news child respects the one-thousand-news limit');
   $assert(!str_contains($newsOne,'Historic archive')&&!str_contains($newsTwo,'Historic archive')&&!str_contains($newsOne,'Protected story')&&!str_contains($newsTwo,'Protected story'),'Native recent discovery excludes historic and password-protected articles');
   $assert(str_contains($newsOne,'<news:name>Synthetic News</news:name>')&&str_contains($newsOne,'<news:language>en</news:language>')&&str_contains($newsOne,'<news:publication_date>'),'Native news XML retains publication name language and original publication date');
   update_post_meta($post,BRICKS_DB_PAGE_SETTINGS,['metaRobots'=>['noindex']]);$refresh();
   $assert(!str_contains($maps->news_xml('1'),esc_xml(get_permalink($post))),'Native news discovery excludes explicit Bricks noindex');
   update_post_meta($post,'_kiwe_seo_robots',['index']);$refresh();
   $assert(str_contains($maps->news_xml('1'),esc_xml(get_permalink($post))),'Native explicit Kiwe index returns news after a Bricks exclusion');
   delete_post_meta($post,'_kiwe_seo_robots');delete_post_meta($post,BRICKS_DB_PAGE_SETTINGS);
   update_post_meta($post,'_kiwe_seo_canonical_url','https://example.test/original-source/');$refresh();
   $assert(!str_contains($maps->news_xml('1'),esc_xml(get_permalink($post))),'Native non-self canonical is excluded from news discovery');delete_post_meta($post,'_kiwe_seo_canonical_url');
   update_post_meta($post,BRICKS_DB_PAGE_SETTINGS,['syntheticPadding'=>str_repeat('x',66000)]);$refresh();
   $capacity=$maps->news_xml('1');$assert(is_wp_error($capacity)&&$capacity->get_error_code()==='kiwe_news_capacity','Native oversized discovery metadata refuses before batch materialization');delete_post_meta($post,BRICKS_DB_PAGE_SETTINGS);$refresh();
   $q=[];$trace=static function($sql)use(&$q){$q[]=$sql;return$sql;};$refresh();add_filter('query',$trace,999999);$maps->news_xml('1');remove_filter('query',$trace,999999);
   $assert(!array_filter($q,static fn($sql)=>preg_match('/SELECT\s+(?:[A-Za-z0-9_]+\.)?\*\s+FROM.*posts|SELECT\s+.*\bpost_content\b/i',$sql)),'Cold native news discovery never selects full articles or article bodies');
   $before=$wpdb->num_queries;$maps->news_xml('1');$assert($wpdb->num_queries-$before<4,'Warm native news discovery uses a cached XML projection');
   $proof['observations']['newsColdQueries']=count($q);
   // Archive handover runs against real query identity and the existing native defaults owner.
   $policyBefore=get_option(DSA\SEO\SEO_Defaults_Service::OPTION,[]);
   $policySettings=$policyBefore;$policySettings['policies']=['redirectDateArchives'=>true,'redirectAuthorArchives'=>false,'redirectAttachments'=>true,'attachmentFallback'=>home_url(),'noindexEmptyTerms'=>true];$policySettings['archives']['author']['robots']=['noindex'];
   update_option(DSA\SEO\SEO_Defaults_Service::OPTION,$policySettings,false);$redirects=new DSA\SEO\SEO_Redirect_Service();
   $query(['year'=>2026,'monthnum'=>10]);$beforeQueries=$wpdb->num_queries;$date=$redirects->decision('/2026/10/');$assert(is_date()&&$date===['code'=>301,'url'=>home_url()],'Native date query preserves source homepage redirect');$assert($wpdb->num_queries-$beforeQueries<3,'Native archive policy needs no redirect scan or visitor write');
   $assert($redirects->decision('/?m=202610','HEAD')===['code'=>301,'url'=>home_url()]&&$redirects->decision('/2026/10/','POST')===null,'Native query-date HEAD works while POST stays untouched');
   $query(['author'=>$author]);$assert(is_author()&&$redirects->decision('/author/synthetic-author/')===null&&!empty(apply_filters('wp_robots',[])['noindex']),'Native author archive keeps its independent noindex choice');
   $policySettings['policies']['redirectAuthorArchives']=true;update_option(DSA\SEO\SEO_Defaults_Service::OPTION,$policySettings,false);$assert($redirects->decision('/author/synthetic-author/')['code']===301,'Native author policy can redirect without changing author or content records');
   $attachment=wp_insert_post(['post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Synthetic policy image','post_mime_type'=>'image/jpeg','post_parent'=>$page,'guid'=>home_url('/synthetic-policy.jpg')],true);$assert(!is_wp_error($attachment),'Native attachment fixture needs no media file or image conversion');
   $query(['attachment_id'=>$attachment]);$parentDecision=$redirects->decision('/synthetic-policy-image/');$assert(is_attachment()&&$parentDecision===['code'=>301,'url'=>get_permalink($page)],'Native attachment redirects to its public parent');
   wp_update_post(['ID'=>$page,'post_status'=>'private']);$query(['attachment_id'=>$attachment]);$assert($redirects->decision('/?attachment_id='.$attachment)['url']===home_url(),'Native private parent does not disclose a destination');wp_update_post(['ID'=>$page,'post_status'=>'publish']);
   wp_update_post(['ID'=>$attachment,'post_parent'=>0]);$query(['attachment_id'=>$attachment]);$assert($redirects->decision('/?attachment_id='.$attachment)['url']===home_url(),'Native orphan attachment retains the source homepage fallback');
   $assert(DSA\Onboarding\SEO_Context_Service::term_noindex($tag),'Native empty term follows its saved automatic noindex policy');update_term_meta($tag,'_kiwe_seo_robots',['index']);$assert(!DSA\Onboarding\SEO_Context_Service::term_noindex($tag),'Native per-record index remains stronger than automatic empty-term policy');delete_term_meta($tag,'_kiwe_seo_robots');
   $maps->rewrites();$rules=$wp_rewrite->extra_rules_top;$assert(($rules['^post-sitemap\\.xml$']??'')==='index.php?sitemap=posts&sitemap-subtype=post&paged=1'&&($rules['^category-sitemap([1-9][0-9]{0,3})\\.xml$']??'')==='index.php?sitemap=taxonomies&sitemap-subtype=category&paged=$matches[1]','Native legacy sitemap addresses retain one WordPress renderer and bounded pagination');
   update_option(DSA\SEO\SEO_Defaults_Service::OPTION,[],false);delete_option(DSA\SEO\SEO_Settings_Handover_Service::JOURNAL);
   update_option('rank-math-options-titles',['disable_date_archives'=>'on','author_custom_robots'=>'on','author_robots'=>['noindex'],'noindex_empty_taxonomies'=>'on'],false);update_option('rank-math-options-general',['attachment_redirect_urls'=>'on','attachment_redirect_default'=>home_url()],false);update_option('rank-math-options-sitemap',[],false);
   $sourcePolicyHash=hash('sha256',serialize([get_option('rank-math-options-titles'),get_option('rank-math-options-general')]));$handover=new DSA\SEO\SEO_Settings_Handover_Service();$review=$handover->read($request())->get_data();
   wp_set_current_user($author);$assert($handover->mutate($request('POST',['operation'=>'import','expected'=>$review['expected'],'confirmed'=>true]))->get_status()===403,'Native Author cannot import archive policy');wp_set_current_user($owner);
   $imported=$handover->mutate($request('POST',['operation'=>'import','expected'=>$review['expected'],'confirmed'=>true]));$importedSettings=get_option(DSA\SEO\SEO_Defaults_Service::OPTION,[]);
   $assert($imported->get_status()===200&&!empty($importedSettings['policies']['redirectDateArchives'])&&!empty($importedSettings['policies']['redirectAttachments'])&&$importedSettings['archives']['author']['robots']===['noindex'],'Native reviewed settings import retains policy and robots through the same conditional owner');
   $newer=$importedSettings;$newer['home']['title']='Newer client title';update_option(DSA\SEO\SEO_Defaults_Service::OPTION,$newer,false);$review=$handover->read($request())->get_data();$assert($handover->mutate($request('POST',['operation'=>'undo','expected'=>$review['expected'],'confirmed'=>true]))->get_status()===409&&get_option(DSA\SEO\SEO_Defaults_Service::OPTION)===$newer,'Native policy undo protects newer owner edits');
   update_option(DSA\SEO\SEO_Defaults_Service::OPTION,$importedSettings,false);$review=$handover->read($request())->get_data();$undone=$handover->mutate($request('POST',['operation'=>'undo','expected'=>$review['expected'],'confirmed'=>true]));$assert($undone->get_status()===200&&get_option(DSA\SEO\SEO_Defaults_Service::OPTION)===[],'Native policy undo restores exact missing defaults before code reversal');
   $assert($sourcePolicyHash===hash('sha256',serialize([get_option('rank-math-options-titles'),get_option('rank-math-options-general')])),'Native policy import and undo preserve original source configuration');update_option(DSA\SEO\SEO_Defaults_Service::OPTION,$policyBefore,false);$query(['p'=>$post]);
   // Existing import worker: explicit cursor/batch queue and native post/term owner separation.
   $migration=new DSA\SEO\SEO_Metadata_Migration_Service();
   update_post_meta($post,'rank_math_title','%title% %sep% %sitename%');update_post_meta($post,'rank_math_description','Imported description');update_post_meta($post,'_kiwe_seo_description','Client description');
   update_term_meta($category,'rank_math_title','%term% %sep% %sitename%');update_term_meta($category,'rank_math_description','Term imported description');
   $sourceHash=hash('sha256',serialize([get_post_meta($post,'rank_math_title',true),get_post_meta($post,'rank_math_description',true),get_term_meta($category,'rank_math_title',true),get_term_meta($category,'rank_math_description',true)]));
   $state=$migration->status();$queued=$migration->mutate($request('POST',['operation'=>'start','scope'=>'post','expected'=>$state['expected'],'confirmed'=>true]));
   $assert($queued->get_status()===200&&$queued->get_data()['active']['scope']==='post','Actual manager post import schedules one native queue');
   $assert(!metadata_exists('post',$post,'_kiwe_seo_title'),'Actual import REST boundary does not execute work inline');
   $job=$queued->get_data()['active']['id'];wp_clear_scheduled_hook(DSA\SEO\SEO_Metadata_Migration_Service::CRON,[$job]);$migration->run($job);
   $assert(get_post_meta($post,'_kiwe_seo_title',true)==='%title% %sep% %sitename%'&&get_post_meta($post,'_kiwe_seo_description',true)==='Client description','Actual post import retains live variables and client fill-empty precedence');
   $assert(wp_get_document_title()==='Edited news | Synthetic News','Imported native template renders current post facts');
   $state=$migration->status();$rollback=$migration->mutate($request('POST',['operation'=>'rollback','expected'=>$state['expected'],'confirmed'=>true]));$job=$rollback->get_data()['active']['id'];wp_clear_scheduled_hook(DSA\SEO\SEO_Metadata_Migration_Service::CRON,[$job]);$migration->run($job);
   $assert(!metadata_exists('post',$post,'_kiwe_seo_title')&&get_post_meta($post,'_kiwe_seo_description',true)==='Client description','Actual completed post rollback restores its journal without changing untouched client fields');
   $state=$migration->status();$queued=$migration->mutate($request('POST',['operation'=>'start','scope'=>'term','expected'=>$state['expected'],'confirmed'=>true]));$job=$queued->get_data()['active']['id'];wp_clear_scheduled_hook(DSA\SEO\SEO_Metadata_Migration_Service::CRON,[$job]);$migration->run($job);
   $assert(get_term_meta($category,'_kiwe_seo_title',true)==='%term% %sep% %sitename%'&&!metadata_exists('post',$category,'_kiwe_seo_title'),'Actual taxonomy import writes only the native term owner');
   $state=$migration->status();$rollback=$migration->mutate($request('POST',['operation'=>'rollback','expected'=>$state['expected'],'confirmed'=>true]));$job=$rollback->get_data()['active']['id'];wp_clear_scheduled_hook(DSA\SEO\SEO_Metadata_Migration_Service::CRON,[$job]);$migration->run($job);
   $assert(!metadata_exists('term',$category,'_kiwe_seo_title'),'Actual completed taxonomy rollback restores native term metadata');
   $assert($sourceHash===hash('sha256',serialize([get_post_meta($post,'rank_math_title',true),get_post_meta($post,'rank_math_description',true),get_term_meta($category,'rank_math_title',true),get_term_meta($category,'rank_math_description',true)])),'Actual import and undo preserve every tested Rank Math source field exactly');
   wp_set_current_user($author);$assert($migration->read($request())->get_status()===403,'Native Author cannot start or inspect website metadata import');wp_set_current_user($owner);
   $proof['observations']['newsRecentCount']=1001;
  }
  $assert(($GLOBALS['kiwe_seo_test_http_calls']??0)===0&&($GLOBALS['kiwe_seo_test_mail_calls']??0)===0,'Native SEO reads imports and output send no AI delivery email or remote provider requests');
  $proof['observations']['wordpressVersion']=$GLOBALS['wp_version'];$proof['observations']['phpVersion']=PHP_VERSION;$proof['observations']['databaseVersion']=$wpdb->db_version();$proof['observations']['bricksVersion']=defined('BRICKS_VERSION')?BRICKS_VERSION:'';
  $proof['passed']=true;
 } catch(Throwable $e){$proof['error']=$e->getMessage();$proof['errorClass']=get_class($e);$proof['errorFrames']=array_map(static fn($f)=>['file'=>basename($f['file']??''),'line'=>$f['line']??0,'function'=>$f['function']??'','class'=>$f['class']??''],array_slice($e->getTrace(),0,8));kiwe_seo_test_progress($proof);}
 finally {
  // Native plugin shutdown runs once while every WordPress write still belongs to the isolated tables.
  try { do_action('shutdown'); $proof['checks']['Native shutdown finishes within isolated table authority']=true; }
  catch(Throwable $e){$proof['checks']['Native shutdown finishes within isolated table authority']=false;$proof['shutdownError']=$e->getMessage();}
  remove_all_actions('shutdown');
  kiwe_seo_test_cleanup($proof);
  kiwe_seo_test_progress($proof);
 }
 $proof['passed']=$proof['passed']&&$proof['cleanup']&&!in_array(false,$proof['checks'],true);
 $proof['checkCount']=count($proof['checks']);return$proof;
}
function kiwe_seo_test_progress(array $proof):void{
 if(!defined('KIWE_SEO_TEST_PROGRESS'))return;
 $tmp=KIWE_SEO_TEST_PROGRESS.'.tmp';$body=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
 if(file_put_contents($tmp,$body)!==strlen($body)||!rename($tmp,KIWE_SEO_TEST_PROGRESS))throw new RuntimeException('Private fixture progress could not be retained.');
}
function kiwe_seo_test_cleanup(array &$proof):void{
 global $wpdb;
  wp_set_current_user(0);
  $clean=true;
  foreach($GLOBALS['kiwe_seo_test_tables']??[]as$name=>$table){if(!preg_match('/^kiwe288qa_[a-f0-9]{12}_[a-z_]+$/D',$table)||false===$wpdb->query('DROP TEMPORARY TABLE '.$table))$clean=false;$wpdb->$name=$GLOBALS['kiwe_seo_test_original'][$name];}
  // Keep the write guard through shutdown; restoring table pointers never grants original-site writes.
  foreach($GLOBALS['kiwe_seo_test_shadows']??[]as$table)if(false===$wpdb->query('DROP TEMPORARY TABLE '.$table))$clean=false;
  if(isset($GLOBALS['kiwe_seo_test_native_cache']))$GLOBALS['wp_object_cache']=$GLOBALS['kiwe_seo_test_native_cache'];
  foreach($GLOBALS['kiwe_seo_test_protected']??[]as$name=>$hash){$same=$hash===hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$GLOBALS['kiwe_seo_test_original'][$name].' ORDER BY 1',ARRAY_A)));$proof['checks']['Original native '.$name.' tuples preserved']=$same;$clean=$clean&&$same;}
  $same=($GLOBALS['kiwe_seo_test_options_hash']??'')===kiwe_seo_test_options_hash($GLOBALS['kiwe_seo_test_original']['options']);$proof['checks']['Original identity settings roles PhoneKey Kiwe and Bricks options preserved']=$same;
  $proof['cleanup']=$clean&&$same;
}
function SitemapsFixtureEntries(array $entry):array{return DSA\SEO\SEO_Sitemap_Service::clean_entries([$entry]);}
