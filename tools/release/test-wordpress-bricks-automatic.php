<?php
/** Native core integration and Woo cart boundaries. Named disposable lab only. */
if(PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
use DSA\Access\Site_Owner_Service as Owner;
$proof=['schema'=>'kiwe.native-bricks-automatic.v1','version'=>DSA_VERSION,'passed'=>false,'cleanup'=>false,'checks'=>[]];
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$actor=get_current_user_id();$originalCookies=$_COOKIE;$originalTable=$wpdb->options;$originalCache=$GLOBALS['wp_object_cache'];$table=$wpdb->prefix.'kiwe_auto_qa_'.bin2hex(random_bytes(4));$isolated=false;$user=0;$products=[];$filters=[];$mail=[];$http=[];$original=[];
$wcOriginal=['session'=>WC()->session,'cart'=>WC()->cart,'customer'=>WC()->customer];
foreach([Owner::OPTION,DSA_OPTION_SETTINGS,BRICKS_DB_GLOBAL_SETTINGS,'active_plugins','template','stylesheet']as$key)$original[$key]=get_option($key);
$filter=static function($hook,$fn,$args=1)use(&$filters){add_filter($hook,$fn,PHP_INT_MAX,$args);$filters[]=[$hook,$fn];};
try{
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&DSA\Bricks\Bricks_Integration::is_active_theme(),'matching native Bricks candidate');$assert(!wp_using_ext_object_cache(),'request-local native cache');
 $filter('pre_wp_mail',static function($v,$args)use(&$mail){$mail[]=true;return true;},2);$filter('pre_http_request',static function($v,$args,$url)use(&$http){$http[]=true;return new WP_Error('kiwe_qa_outbound','Blocked');},3);
 $ownerOff=static fn()=>[];$filter('pre_option_'.Owner::OPTION,$ownerOff);
 $user=wp_insert_user(['user_login'=>'kiweautoqa_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(40,true,true),'user_email'=>'auto_'.bin2hex(random_bytes(5)).'@example.invalid','role'=>'subscriber']);$assert(!is_wp_error($user),'synthetic cart customer created');$user=(int)$user;
 $assert(false!==$wpdb->query("CREATE TABLE {$table} LIKE {$originalTable}"),'isolated native options created');$isolated=true;$assert(false!==$wpdb->query("INSERT INTO {$table} SELECT * FROM {$originalTable}"),'native option choices copied');$wpdb->options=$table;$GLOBALS['wp_object_cache']=new WP_Object_Cache();
 $settings=new DSA\Settings();$raw=$settings->fresh_install_defaults();$raw['bricks']=array_fill_keys(array_keys(DSA\Settings::automatic_bricks_settings()),false);$raw['search']['bricks_bridge_enabled']=false;$settings->update_selected($raw);
 $stored=get_option(DSA_OPTION_SETTINGS);$resolved=$settings->all();
 foreach(DSA\Settings::automatic_bricks_settings()as$key=>$value)$assert($resolved['bricks'][$key]===true,'automatic compatibility flag '.$key);
 $assert($resolved['search']['bricks_bridge_enabled']===true,'native search bridge automatic');$assert($settings->all()===$resolved&&get_option(DSA_OPTION_SETTINGS)===$stored,'cached read leaves original stored flags untouched');
 $assert(empty($resolved['enabled'])&&empty($resolved['ai']['studio_enabled'])&&empty($resolved['phonekey']['enabled'])&&empty($resolved['metrics']['enabled']),'optional dock AI auth and analytics remain off');
 $bridge=(new ReflectionClass(DSA\Bricks\Bricks_Integration::class))->newInstanceWithoutConstructor();$ref=new ReflectionClass($bridge);$ref->getProperty('settings')->setValue($bridge,$settings);
 $native=apply_filters('bricks/dynamic_tags_list',[]);$assert(in_array('{kiwe_site_logo}',array_column($native,'name'),true),'native dynamic chooser contains logo with all old flags false');
 $attrs=$bridge->add_element_attributes([], '_root',(object)['id'=>'autoi','name'=>'icon','settings'=>['dsaOpenModule'=>'cart']]);$assert(($attrs['_root']['data-dsa-open-module']??'')==='cart','native icon launcher ignores obsolete disable flag');
 $query=$bridge->bridge_element_settings(['type'=>'upsells','brxKiweLinkedProductsMode'=>'cross_sells'],(object)['name'=>'product-upsells']);$assert($query['type']==='cross_sells','native linked-product preset automatic');
 $assert($bridge->add_add_to_cart_attributes(['native'=>'kept'],(object)['name'=>'product-add-to-cart','settings'=>[]])===['native'=>'kept'],'unselected native ATC stays unchanged');
 $atc=$bridge->add_add_to_cart_attributes([],(object)['name'=>'product-add-to-cart','settings'=>['brxPlusOnly'=>true],'post_id'=>0]);$assert(($atc['data-brx-t4']??'')==='true','selected native ATC behaviour automatic');
 $rawHtml='<form class="author-search"><input type="search" name="q"></form>';
 $htmlElement=['id'=>'qasearch','name'=>'html','settings'=>['html'=>$rawHtml,'_attributes'=>[['id'=>'qaopen','name'=>'data-dsa-open-module','value'=>'search']]],'is_frontend'=>true];
 $rendered=Bricks\Frontend::render_element($htmlElement);$parsed=new WP_HTML_Tag_Processor($rendered);$parsed->next_tag();
 $assert($parsed->get_tag()==='FORM'&&$parsed->get_attribute('data-dsa-open-module')==='search','native raw HTML rendering forwards the authored launcher to its real root');
 $assert(str_contains($rendered,'<input type="search" name="q">')&&!str_contains($rendered,'<script'),'raw HTML launcher preserves authored children without injected code');
 $assert($htmlElement['settings']['html']===$rawHtml&&count($htmlElement['settings']['_attributes'])===1,'rendering does not rewrite native authored settings');
 $htmlElement['settings']['_attributes'][0]['value']='unknown';$assert(Bricks\Frontend::render_element($htmlElement)===$rawHtml,'unknown HTML launcher destination is ignored');
 $htmlElement['settings']['_attributes']=[];$assert(Bricks\Frontend::render_element($htmlElement)===$rawHtml,'unmarked raw HTML remains byte exact');
 $htmlElement['settings']['_attributes']=[['id'=>'qaopen','name'=>'data-dsa-open-module','value'=>'search']];
 $htmlElement['settings']['html']='<form data-dsa-open-module="menu"><input></form>';$assert(Bricks\Frontend::render_element($htmlElement)===$htmlElement['settings']['html'],'existing authored root launcher remains authoritative');
 $htmlElement['settings']['html']='<script>/* synthetic */</script><form></form>';$assert(Bricks\Frontend::render_element($htmlElement)===$htmlElement['settings']['html'],'nonvisual script root is not repurposed as a launcher');
 $nativeAtc=new Bricks\Product_Add_To_Cart(['id'=>'qaatc','is_frontend'=>true]);$nativeAtc->load();
 $assert(isset($nativeAtc->controls['brxQtyPlusBg'],$nativeAtc->controls['brxQtyMinusTypo'])&&($nativeAtc->control_groups['dsaAtcStyles']['tab']??'')==='style','installed native Bricks element exposes automatic styling controls with old flags false');
 $admin=(new ReflectionClass(DSA\Admin\Admin::class))->newInstanceWithoutConstructor();ob_start();$admin->render_bricks_page();$page=ob_get_clean();$assert(!str_contains($page,'type="checkbox"')&&!str_contains($page,'<form')&&str_contains($page,'data-kiwe-add-to-cart'),'native admin status has no enable forms');
 wp_set_current_user($user);WC()->session=new WC_Session_Handler();WC()->session->init();WC()->customer=new WC_Customer($user);WC()->cart=new WC_Cart();
 $_SERVER['HTTP_ORIGIN']=home_url();$_SERVER['HTTP_REFERER']=home_url();$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';
 // Match a nonce-free REST bootstrap after native cookie auth cleared the user.
 $token=WP_Session_Tokens::get_instance($user)->create(time()+600);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($user,time()+600,'logged_in',$token);wp_set_current_user(0);
 $nonceRequest=new WP_REST_Request('GET','/dsa/v1/cart/nonce');$nonceResponse=rest_do_request($nonceRequest);$nonce=$nonceResponse->get_data()['nonce']??'';
 $assert($nonceResponse->get_status()===200&&get_current_user_id()===$user&&wp_verify_nonce($nonce,'wp_rest'),'nonce bootstrap validates the existing native signed session');
 $assert(str_contains($nonceResponse->get_headers()['Cache-Control']??'','private, no-store')&&($nonceResponse->get_headers()['Vary']??'')==='Cookie','nonce response is private and cookie varied');
 $_COOKIE[LOGGED_IN_COOKIE]='invalid';wp_set_current_user(0);$nonceResponse=rest_do_request($nonceRequest);$assert(get_current_user_id()===0&&wp_verify_nonce($nonceResponse->get_data()['nonce'],'wp_rest'),'invalid cookie remains anonymous');
 $_SERVER['HTTP_ORIGIN']='https://foreign.example';$nonceResponse=rest_do_request($nonceRequest);$assert($nonceResponse->get_status()>=400&&get_current_user_id()===0,'foreign nonce bootstrap is denied without session elevation');
 $_COOKIE=$originalCookies;$_SERVER['HTTP_ORIGIN']=home_url();wp_set_current_user($user);
 $product=new WC_Product_Simple();$product->set_name('Kiwe automatic cart QA');$product->set_status('publish');$product->set_regular_price('10');$product->set_manage_stock(true);$product->set_stock_quantity(5);$id=$product->save();$products[]=$id;$assert($id>0,'synthetic native product created');
 $request=static function($body,$origin=true,$requestProof=true){$_SERVER['HTTP_ORIGIN']=$origin?home_url():'https://foreign.example';$r=new WP_REST_Request('POST','/dsa/v1/cart/add');$r->set_header('Content-Type','application/json');if($requestProof)$r->set_header('X-Kiwe-Mutation','1');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_body(wp_json_encode($body));return rest_do_request($r);};
 $deny=$request(['productId'=>$id,'quantity'=>1],false);$assert($deny->get_status()>=400&&WC()->cart->get_cart_contents_count()===0,'foreign-origin cart mutation denied');
 $deny=$request(['productId'=>$id,'quantity'=>1],true,false);$assert($deny->get_status()>=400&&WC()->cart->get_cart_contents_count()===0,'missing mutation request proof denied');
 $result=$request(['productId'=>$id,'quantity'=>2]);$assert($result->get_status()===200&&WC()->cart->get_cart_contents_count()===2,'existing native Woo cart route adds requested quantity');
 $deny=$request(['productId'=>$id,'quantity'=>9]);$assert($deny->get_status()===409&&WC()->cart->get_cart_contents_count()===2,'native stock refusal retains prior cart');
 $parent=new WC_Product_Variable();$parent->set_name('Kiwe variable cart QA');$parent->set_status('publish');$parentId=$parent->save();$products[]=$parentId;
 $variation=new WC_Product_Variation();$variation->set_parent_id($parentId);$variation->set_status('publish');$variation->set_regular_price('12');$variationId=$variation->save();$products[]=$variationId;WC_Product_Variable::sync($parentId);
 $result=$request(['productId'=>$variationId,'quantity'=>1]);$items=WC()->cart->get_cart();$assert($result->get_status()===200&&count(array_filter($items,static fn($item)=>(int)$item['variation_id']===$variationId))===1,'concrete variation resolves through native parent relationship');
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
finally{try{
 if($user&&is_int($user)){if(WC()->cart)WC()->cart->empty_cart(false);if(WC()->session)WC()->session->destroy_session();}
 foreach($wcOriginal as$key=>$value)WC()->$key=$value;$_COOKIE=$originalCookies;wp_set_current_user($actor);
 foreach(array_reverse($products)as$id){wp_delete_post($id,true);$assert(!get_post($id),'synthetic product retired');}
 $wpdb->options=$originalTable;$GLOBALS['wp_object_cache']=$originalCache;foreach($filters as[$hook,$fn])if($hook==='pre_option_'.Owner::OPTION)remove_filter($hook,$fn,PHP_INT_MAX);
 if($user&&is_int($user)){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user);$assert(!get_userdata($user),'synthetic cart account retired');}
 if($isolated)$assert(false!==$wpdb->query("DROP TABLE {$table}"),'isolated options retired');foreach($original as$key=>$value)$assert(get_option($key)===$value,'original choice retained '.$key);$proof['cleanup']=true;
}catch(Throwable$e){$proof['passed']=false;$proof['cleanupError']=$e->getMessage();}foreach($filters as[$hook,$fn])remove_filter($hook,$fn,PHP_INT_MAX);$proof['mailAttempts']=count($mail);$proof['httpAttempts']=count($http);}
echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES).PHP_EOL;
