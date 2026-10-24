<?php
/** Native non-Woo proof. The runner must load real Rank Math and isolate native options before plugins initialize. */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || KIWE_WORKSPACE_TEST_SITE !== true || ! defined( 'KIWE_WORKSPACE_EXPECTED_VERSION' ) || empty( $GLOBALS['kiwe_nonwoo_seo_options_isolated'] ) ) exit( 2 );
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\Taxonomy_App_Service as Taxonomies;
use DSA\Access\Navigation_Workspace_Service as Navigation;
use DSA\Onboarding\SEO_Context_Service as SEO;

$proof = [ 'schema'=>'kiwe-native-nonwoo-seo.v1', 'kiwe'=>DSA_VERSION, 'php'=>PHP_VERSION, 'wordpress'=>$GLOBALS['wp_version'], 'rankMath'=>defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '', 'checks'=>[], 'cleanup'=>false ];
$GLOBALS['kiwe_nonwoo_seo_proof'] =& $proof;
$actor = get_current_user_id(); $prefix = 'kiwenonwooseo_' . bin2hex( random_bytes( 6 ) ); $users=[]; $posts=[]; $terms=[]; $menus=[]; $filters=[];
$query = $GLOBALS['wp_query']; $mainQuery = $GLOBALS['wp_the_query']; $oldPost = $GLOBALS['post'] ?? null; $buffer = ob_get_level();
$assert = static function( bool $ok, string $label ) use ( &$proof ): void { $proof['checks'][] = [ 'label'=>$label, 'passed'=>$ok ]; };
$must = static function( $value ) { if ( is_wp_error( $value ) || ! $value ) throw new RuntimeException( 'Native synthetic fixture unavailable' ); return $value; };
$filter = static function( string $hook, callable $callback, int $args=1 ) use ( &$filters ): void { add_filter( $hook, $callback, PHP_INT_MAX, $args ); $filters[] = [ $hook,$callback ]; };
$request = static function( string $method, string $suffix, array $body=[], bool $nonce=true, bool $mutation=true ) {
 $r = new WP_REST_Request( $method, '/dsa/v1/workspace/' . $suffix );
 if ( $nonce ) $r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
 if ( $mutation ) $r->set_header( 'X-Kiwe-Mutation', '1' );
 if ( $method === 'GET' ) $r->set_query_params( $body ); else { $r->set_header( 'Content-Type','application/json' ); $r->set_body( wp_json_encode( $body ) ); }
 return rest_do_request( $r );
};
$protected = static fn( int $id ) => [ get_post( $id )->post_title, get_post( $id )->post_content, get_post_status( $id ), get_post( $id )->post_date, (int) get_post( $id )->post_parent, get_post_meta( $id,'_bricks_page_content_2',true ) ];
try {
 $assert( DSA_VERSION === KIWE_WORKSPACE_EXPECTED_VERSION && ! empty( DSA\Runtime\Package_Manifest::verify( true )['valid'] ), 'complete matching Kiwe candidate' );
 $assert( ! function_exists( 'WC' ) && ! post_type_exists( 'product' ), 'Woo genuinely unloaded' );
 $assert( RANK_MATH_VERSION === '1.0.277.1' && class_exists( 'RankMath\Frontend\Head' ) && rank_math()->frontend instanceof RankMath\Frontend\Frontend, 'real Rank Math matching Ascendants initializes its native frontend' );
 // Delivery is outside this fixture. Keep native SEO metadata/head hooks while suppressing notification events in this process.
 foreach ( [ 'kiwe_notification_event','dsa_admin_notification_event','transition_post_status' ] as $event ) remove_all_actions( $event );
 $off = static fn() => []; $filter( 'pre_option_' . Owner::OPTION, $off );
 require_once ABSPATH.'wp-admin/includes/user.php';
 $orphanIds=$wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s",$wpdb->esc_like( 'kiwenonwooseo_' ).'%' ) );
 foreach ( $orphanIds as $id ) { $u=get_userdata( $id ); if ( $u && preg_match( '/^kiwenonwooseo_[a-f0-9]{12}_(owner|client|editor|author|subscriber)$/D',$u->user_login ) ) wp_delete_user( $id ); }
 $assert( ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s LIMIT 1",$wpdb->esc_like( 'kiwenonwooseo_' ).'%' ) ), 'interrupted owned synthetic accounts are retired before the new fixture' );
 foreach ( [ 'owner'=>'administrator','client'=>'administrator','editor'=>'editor','author'=>'author','subscriber'=>'subscriber' ] as $name=>$role ) $users[$name]=(int) $must( wp_insert_user( [ 'user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password( 32,true,true ),'user_email'=>$prefix.'_'.$name.'@example.invalid','role'=>$role ] ) );
 remove_filter( 'pre_option_' . Owner::OPTION,$off,PHP_INT_MAX );
 $filter( 'pre_option_' . Owner::OPTION,static fn()=>[ 'enabled'=>true,'ownerId'=>$users['owner'],'rankMath'=>'inline' ] );
 wp_set_current_user( $users['client'] );
 $assert( current_user_can( 'kiwe_manage_navigation' ) && ! current_user_can( 'manage_options' ), 'narrow native client has navigation authority without technical settings' );
 $term=(array) $must( wp_insert_term( $prefix.' Category','category' ) ); $category=(int) $term['term_id']; $terms[]=[ $category,'category' ];
 $postId=(int) $must( wp_insert_post( [ 'post_type'=>'post','post_status'=>'publish','post_author'=>$users['author'],'post_title'=>$prefix.' Article','post_content'=>'<p>Native article.</p>','post_excerpt'=>'Original reader excerpt','post_category'=>[ $category ] ],true ) ); $posts[]=$postId;
 $pageId=(int) $must( wp_insert_post( [ 'post_type'=>'page','post_status'=>'publish','post_author'=>$users['owner'],'post_title'=>$prefix.' Page','post_content'=>'Native page layout.' ],true ) ); $posts[]=$pageId;
 update_post_meta( $pageId,'_bricks_page_content_2',[ [ 'id'=>'fixture','name'=>'text','settings'=>[ 'text'=>'Keep native design' ] ] ] );
 update_post_meta( $postId,'_kiwe_seo_title','Inactive Kiwe title' ); update_post_meta( $postId,'rank_math_title','Native Rank Math title' );
 update_term_meta( $category,'_kiwe_seo_title','Inactive Kiwe category title' ); update_term_meta( $category,'rank_math_title','Native category title' );
 $own=$request( 'GET','seo/objects',[ 'scope'=>'post','focus'=>$postId ] ); $item=$own->get_data()['items'][0] ?? [];
 $assert( $own->get_status()===200 && ($item['current']['seoTitle'] ?? '')==='Native Rank Math title', 'SEO card reads the active Rank Math authority despite conflicting Kiwe metadata' );
 $fields=[ 'seoTitle'=>'Reviewed Rank Math title','metaDescription'=>'Reviewed native article description','searchPhrases'=>[ 'native article','editorial' ],'excerpt'=>'Reviewed reader excerpt' ];
 $saveBody=[ 'scope'=>'post','expected'=>$item['expected'] ?? '', 'fields'=>$fields ];
 $assert( $request( 'POST','seo/object/'.$postId.'/save',$saveBody,false )->get_status()===403 && $request( 'POST','seo/object/'.$postId.'/save',$saveBody,true,false )->get_status()===403, 'SEO writes require native nonce and mutation proof' );
 wp_set_current_user( $users['author'] ); $saved=$request( 'POST','seo/object/'.$postId.'/save',$saveBody );
 $assert( $saved->get_status()===200 && get_post_meta( $postId,'rank_math_title',true )===$fields['seoTitle'] && get_post_meta( $postId,'rank_math_description',true )===$fields['metaDescription'] && get_post_meta( $postId,'rank_math_focus_keyword',true )==='native article, editorial' && get_post( $postId )->post_excerpt===$fields['excerpt'], 'Author saves own SEO to real native Rank Math metadata and WordPress excerpt' );
 $assert( get_post_meta( $postId,'_kiwe_seo_title',true )==='Inactive Kiwe title' && ! metadata_exists( 'post',$postId,'_kiwe_seo_description' ), 'active Rank Math edits do not create a second metadata owner' );
 $assert( $request( 'POST','seo/object/'.$postId.'/save',$saveBody )->get_status()===409, 'replayed SEO write is stale' );
 update_post_meta( $postId,'rank_math_description','Native plugin edit' ); $reread=$request( 'GET','seo/objects',[ 'scope'=>'post','focus'=>$postId ] )->get_data();
 $assert( ($reread['items'][0]['current']['metaDescription'] ?? '')==='Native plugin edit', 'native Rank Math edit immediately reaches the Kiwe projection' );
 $assert( $request( 'GET','seo/objects',[ 'scope'=>'page','focus'=>$pageId ] )->get_status()===403, 'Author has no Page SEO authority' );
 wp_set_current_user( $users['client'] ); $pageBefore=$protected( $pageId ); $page=$request( 'GET','seo/objects',[ 'scope'=>'page','focus'=>$pageId ] )->get_data()['items'][0] ?? [];
 $assert( isset( $page['expected'] ) && ! isset( $page['current']['excerpt'] ), 'client Page SEO omits unsupported excerpt fields within the existing native Page authority' );
 $assert( $request( 'POST','seo/object/'.$pageId.'/save',[ 'scope'=>'page','expected'=>$page['expected'] ?? '', 'fields'=>[ 'excerpt'=>'Unsupported Page excerpt' ] ] )->get_status()===400 && $protected( $pageId )===$pageBefore, 'Page SEO rejects unsupported writing fields without changing native content or design' );
 $pageSave=$request( 'POST','seo/object/'.$pageId.'/save',[ 'scope'=>'page','expected'=>$page['expected'] ?? '', 'fields'=>[ 'seoTitle'=>'Reviewed Page title','metaDescription'=>'Reviewed Page description','searchPhrases'=>[] ] ] );
 $assert( $pageSave->get_status()===200 && get_post_meta( $pageId,'rank_math_title',true )==='Reviewed Page title' && $protected( $pageId )===$pageBefore, 'client Page metadata uses Rank Math while native content publication dates and Bricks layout remain intact' );
 $termUrl='seo/term/category/'.$category; $termView=$request( 'GET',$termUrl )->get_data()['items'][0] ?? [];
 $assert( ($termView['current']['seoTitle'] ?? '')==='Native category title', 'category SEO also reads its active native authority' );
 $termFields=[ 'seoTitle'=>'Reviewed category title','metaDescription'=>'Reviewed category archive description','searchPhrases'=>[ 'category editorial' ] ];
 $termSaved=$request( 'POST',$termUrl.'/save',[ 'expected'=>$termView['expected'] ?? '', 'fields'=>$termFields ] );
 $assert( $termSaved->get_status()===200 && get_term_meta( $category,'rank_math_description',true )===$termFields['metaDescription'] && get_term_meta( $category,'_kiwe_seo_title',true )==='Inactive Kiwe category title', 'category save writes native Rank Math fields without overwriting inactive metadata' );
 $beforeIndex=Taxonomies::fingerprint( get_term( $category,'category' ) ); update_term_meta( $category,'rank_math_robots',[ 'index','nofollow' ] );
 $indexBefore=Taxonomies::fingerprint( get_term( $category,'category' ) );
 $assert( $beforeIndex!==$indexBefore, 'native Rank Math robots edits invalidate the category write fingerprint' );
 $indexPath='taxonomies/category/'.$category;
 $cacheDirectory=RankMath\Sitemap\Cache::get_cache_directory();
 $must( file_put_contents( $cacheDirectory.'qa-category.xml','<urlset/>' ) ); $must( file_put_contents( $cacheDirectory.'qa-post.xml','<urlset/>' ) );
 RankMath\Sitemap\Cache::cached_files( [ 'qa-category.xml'=>'category','qa-post.xml'=>'post' ] );
 $invalidations=[]; $filter( 'rank_math/sitemap/invalidate_storage',static function( $value,$type )use(&$invalidations){ $invalidations[]=$type; return $value; },2 );
 $assert( $request( 'POST',$indexPath,[ 'operation'=>'indexing','expected'=>$indexBefore,'indexable'=>false ],false )->get_status()===403 && $request( 'POST',$indexPath,[ 'operation'=>'indexing','expected'=>$indexBefore,'indexable'=>false ],true,false )->get_status()===403, 'category indexing also requires native nonce and mutation proof' );
 $hide=$request( 'POST',$indexPath,[ 'operation'=>'indexing','expected'=>$indexBefore,'indexable'=>false ] );
 $assert( $hide->get_status()===200 && in_array( 'noindex',(array) get_term_meta( $category,'rank_math_robots',true ),true ) && in_array( 'nofollow',(array) get_term_meta( $category,'rank_math_robots',true ),true ), 'category Indexable switch writes native Rank Math robots and preserves other directives' );
 $assert( in_array( 'category',$invalidations,true ), 'category writer calls the native Rank Math sitemap invalidator' );
 $assert( ! is_file( $cacheDirectory.'qa-category.xml' ) && is_file( $cacheDirectory.'qa-post.xml' ) && RankMath\Sitemap\Cache::cached_files()===[ 'qa-post.xml'=>'post' ], 'real native sitemap invalidation removes only the private category cache and keeps unrelated cache' );
 $assert( ! RankMath\Sitemap\Sitemap::is_object_indexable( get_term( $category,'category' ),'term' ), 'real Rank Math sitemap respects the reviewed category noindex choice' );
 wp_set_current_user( 0 ); $GLOBALS['wp_query']=$GLOBALS['wp_the_query']=new WP_Query( [ 'cat'=>$category,'posts_per_page'=>1 ] ); $GLOBALS['post']=$GLOBALS['wp_query']->post;
 $assert( is_category() && get_queried_object_id()===$category, 'real native category query provides frontend context' );
 rank_math()->frontend->integrations(); ob_start(); wp_head(); $head=ob_get_clean();
 $dom=new DOMDocument(); @$dom->loadHTML( '<html><head>'.$head.'</head><body></body></html>' ); $descriptions=[]; $robots=[]; $canonicals=[];
 foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) { if ( $meta->getAttribute( 'name' )==='description' ) $descriptions[]=$meta->getAttribute( 'content' ); if ( $meta->getAttribute( 'name' )==='robots' ) $robots[]=$meta->getAttribute( 'content' ); }
 foreach ( $dom->getElementsByTagName( 'link' ) as $link ) if ( $link->getAttribute( 'rel' )==='canonical' ) $canonicals[]=$link->getAttribute( 'href' );
 $titleNodes=$dom->getElementsByTagName( 'title' ); $renderedTitle=$titleNodes->length ? trim( $titleNodes->item(0)->textContent ) : '';
 $nativeCanonical=RankMath\Paper\Paper::get()->get_canonical();
 $proof['headObservation']=[ 'titles'=>$titleNodes->length,'titleMatches'=>$renderedTitle===$termFields['seoTitle'],'titleSha256'=>hash('sha256',$renderedTitle),'expectedTitleSha256'=>hash('sha256',$termFields['seoTitle']),'nativeTitleMatches'=>RankMath\Paper\Paper::get()->get_title()===$termFields['seoTitle'],'descriptions'=>count($descriptions),'descriptionMatches'=>$descriptions===[ $termFields['metaDescription'] ],'canonicals'=>count($canonicals),'canonicalOmittedForNoindex'=>$canonicals===[],'nativeCanonicalMatches'=>$nativeCanonical===get_term_link( $category,'category' ) ];
 $assert( $descriptions===[ $termFields['metaDescription'] ] && $titleNodes->length===1 && $renderedTitle===$termFields['seoTitle'], 'actual WordPress head has exactly one reviewed native category title and description' );
 // Rank Math Head::robots() removes its canonical action for noindex; the native URL remains self-referential.
 $assert( $canonicals===[] && $nativeCanonical===get_term_link( $category,'category' ), 'actual noindex head follows Rank Math canonical omission while retaining its native self canonical URL' );
 $assert( count( $robots )===1 && str_contains( $robots[0],'noindex' ) && str_contains( $robots[0],'nofollow' ), 'actual Rank Math category head reflects noindex and retains nofollow' );
 wp_set_current_user( $users['client'] ); $staleIndex=Taxonomies::fingerprint( get_term( $category,'category' ) ); update_term_meta( $category,'rank_math_robots',[ 'index','nofollow' ] );
 $assert( ! SEO::term_noindex( $category ), 'external native Rank Math index choice wins over a previous Kiwe mirror' );
 $assert( $request( 'POST',$indexPath,[ 'operation'=>'indexing','expected'=>$staleIndex,'indexable'=>false ] )->get_status()===409, 'concurrent native Rank Math indexing changes refuse a stale card write' );
 $detail=$request( 'GET',$indexPath )->get_data();
 $assert( ($detail['term']['indexable'] ?? null)===true, 'category card reflects native indexing changes without a copied inventory' );
 $hook=static function( $metaId,$id,$key )use($category,&$hook){ if ( $id===$category && $key==='rank_math_robots' ) { remove_action( 'updated_term_meta',$hook,PHP_INT_MAX ); update_term_meta( $category,'rank_math_robots',[ 'index','nofollow' ] ); } };
 add_action( 'updated_term_meta',$hook,PHP_INT_MAX,3 );
 $unconfirmed=$request( 'POST',$indexPath,[ 'operation'=>'indexing','expected'=>Taxonomies::fingerprint( get_term( $category,'category' ) ),'indexable'=>false ] ); remove_action( 'updated_term_meta',$hook,PHP_INT_MAX );
 $assert( $unconfirmed->get_status()===409 && ! SEO::term_noindex( $category ), 'a late native robots hook cannot be reported as a successful indexing save' );
 $assert( get_term_meta( $category,Taxonomies::VISIBILITY_META,true )==='primary', 'native robots readback keeps the existing Design Context mirror aligned' );
 $menuId=(int) $must( wp_create_nav_menu( $prefix.' Menu' ) ); $menus[]=$menuId; $navigation=new Navigation(); $add=[ 'operation'=>'add','source_kind'=>'page','source_id'=>$pageId,'parent_id'=>0,'expected'=>Navigation::fingerprint( $navigation->items( $menuId ) ) ];
 $menu=$request( 'POST','navigation/'.$menuId,$add ); $items=$navigation->items( $menuId );
 $assert( $menu->get_status()===200 && count( $items )===1 && (int) $items[0]->object_id===$pageId, 'narrow client menu addition references the same native Page' );
 $assert( ! current_user_can( 'edit_post',(int) $items[0]->ID ) && wp_get_nav_menu_items( $menuId )[0]->url===get_permalink( $pageId ), 'public native menu uses Page URL without generic menu-item write authority' );
 wp_set_current_user( $users['editor'] ); wp_update_post( [ 'ID'=>$pageId,'post_title'=>$prefix.' Native renamed Page' ],true ); wp_set_current_user( $users['client'] );
 $assert( $navigation->items( $menuId )[0]->title===get_post( $pageId )->post_title, 'native Page rename reaches the client and public menu' );
 wp_set_current_user( $users['subscriber'] );
 $assert( $request( 'GET',$termUrl )->get_status()===403 && $request( 'GET','seo/objects',[ 'scope'=>'post','focus'=>$postId ] )->get_status()===403 && $request( 'POST','navigation/'.$menuId,$add )->get_status()===403, 'Subscriber cannot read private SEO or edit navigation' );
 wp_set_current_user( 0 ); $assert( in_array( $request( 'GET',$termUrl )->get_status(),[ 401,403 ],true ), 'anonymous visitor cannot read category editing data' );
 $assert( empty( $GLOBALS['kiwe_nonwoo_seo_mail'] ) && empty( $GLOBALS['kiwe_nonwoo_seo_http'] ), 'native proof performs no outbound mail or provider HTTP' );
} catch ( Throwable $error ) { $proof['error']=[ 'message'=>$error->getMessage(),'line'=>$error->getLine() ]; }
finally {
 while ( ob_get_level()>$buffer ) ob_end_clean(); $GLOBALS['wp_query']=$query; $GLOBALS['wp_the_query']=$mainQuery; $GLOBALS['post']=$oldPost;
 wp_set_current_user( $users['owner'] ?? $actor ); $clean=true;
 foreach ( array_reverse( $menus ) as $id ) { $term=get_term( $id,'nav_menu' ); if ( $term && ! is_wp_error( $term ) && str_starts_with( $term->name,$prefix ) ) wp_delete_nav_menu( $id ); if ( wp_get_nav_menu_object( $id ) ) $clean=false; }
 foreach ( array_reverse( $posts ) as $id ) { $p=get_post( $id ); if ( $p && str_starts_with( $p->post_title,$prefix ) ) wp_delete_post( $id,true ); if ( get_post( $id ) ) $clean=false; }
 foreach ( array_reverse( $terms ) as [ $id,$taxonomy ] ) { $t=get_term( $id,$taxonomy ); if ( $t && ! is_wp_error( $t ) && str_starts_with( $t->name,$prefix ) ) wp_delete_term( $id,$taxonomy ); if ( get_term( $id,$taxonomy ) ) $clean=false; }
 foreach ( $filters as [ $hook,$callback ] ) if ( $hook==='pre_option_'.Owner::OPTION ) remove_filter( $hook,$callback,PHP_INT_MAX );
 require_once ABSPATH.'wp-admin/includes/user.php'; wp_set_current_user( $actor ); foreach ( $users as $id ) { $u=get_userdata( $id ); if ( $u && str_starts_with( $u->user_login,$prefix ) ) wp_delete_user( $id ); if ( get_userdata( $id ) ) $clean=false; }
 foreach ( array_reverse( $filters ) as [ $hook,$callback ] ) remove_filter( $hook,$callback,PHP_INT_MAX ); $proof['cleanup']=$clean;
}
$proof['passed']=! isset( $proof['error'] ) && $proof['cleanup'] && ! in_array( false,array_column( $proof['checks'],'passed' ),true );
return $proof;
