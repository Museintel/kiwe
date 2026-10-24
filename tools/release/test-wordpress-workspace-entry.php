<?php
if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||!KIWE_WORKSPACE_TEST_SITE)exit(2);
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Access\Workspace_Admin_Service as Home;
$proof=['schema'=>'kiwe-native-staff-entry/v1','kiwe'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'checks'=>[],'cleanup'=>false];
$actor=get_current_user_id();$users=[];$posts=[];$terms=[];$hooks=[];$level=ob_get_level();$policy=null;$prefix='kiwe76qa_'.strtolower(wp_generate_password(8,false,false));
$check=static function($ok,$name)use(&$proof){$proof['checks'][]=['label'=>$name,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($name);};
$must=static function($v){if(!$v||is_wp_error($v))throw new RuntimeException('Fixture failed');return $v;};
$deny=static fn()=>new WP_Error('qa_blocked','Synthetic proof');add_filter('pre_wp_mail','__return_false',PHP_INT_MAX);add_filter('pre_http_request',$deny,PHP_INT_MAX);add_filter('pre_schedule_event','__return_false',PHP_INT_MAX);
foreach(['kiwe_notification_event','dsa_admin_notification_event','transition_post_status','kiwe_seo_score_dirty']as$hook){$hooks[$hook]=$GLOBALS['wp_filter'][$hook]??null;remove_all_actions($hook);}
$off=static fn()=>['enabled'=>false];add_filter('pre_option_'.Owner::OPTION,$off,PHP_INT_MAX);
try{
 $check(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty(DSA\Runtime\Package_Manifest::verify(true)['valid']),'installed package verified');
 foreach(['owner'=>'administrator','client'=>'administrator','author'=>'author','editor'=>'editor','guest'=>'contributor','shop'=>'shop_manager','visitor'=>'subscriber']as$kind=>$role)$users[$kind]=(int)$must(wp_insert_user(['user_login'=>$prefix.$kind,'user_pass'=>wp_generate_password(48,true,true),'user_email'=>$prefix.$kind.'@example.invalid','display_name'=>'Synthetic '.$kind,'role'=>$role]));
 $terms['category']=(int)$must(wp_insert_term('Kiwe RC76 post category '.$prefix,'category'))['term_id'];
 $terms['product_cat']=(int)$must(wp_insert_term('Kiwe RC76 product category '.$prefix,'product_cat'))['term_id'];
 $home=new Home();require_once ABSPATH.'wp-content/plugins/woocommerce/includes/admin/class-wc-admin.php';$woo=(new ReflectionClass('WC_Admin'))->newInstanceWithoutConstructor();

 foreach([false,true]as$enabled){
  if($policy)remove_filter('pre_option_'.Owner::OPTION,$policy,PHP_INT_MAX);
  $policy=static fn()=>['enabled'=>$enabled,'ownerId'=>$users['owner'],'rankMath'=>'inline'];add_filter('pre_option_'.Owner::OPTION,$policy,PHP_INT_MAX);
  foreach($users as$kind=>$id){
   wp_set_current_user($id);$expected=in_array($kind,['owner','client','author','editor','guest','shop'],true);
   $check(Home::uses_workspace(wp_get_current_user())===$expected,'native workspace eligibility '.$kind.' policy '.(int)$enabled);
   foreach(['login_redirect','woocommerce_login_redirect','kiwe_workspace_login_destination']as$hook){$destination=apply_filters($hook,home_url('/'),...($hook==='login_redirect'?['',wp_get_current_user()]:[wp_get_current_user()]));$check(($destination===Home::url())===$expected,'native '.$hook.' '.$kind.' policy '.(int)$enabled);}
  }
  foreach(['author','editor','guest','shop','visitor']as$kind){wp_set_current_user($users[$kind]);$_SERVER['SCRIPT_FILENAME']=ABSPATH.'wp-admin/admin.php';$decision=null;$capture=static function($prevent)use(&$decision){$decision=$prevent;return false;};add_filter('woocommerce_prevent_admin_access',$capture,PHP_INT_MAX);$woo->prevent_admin_access();remove_filter('woocommerce_prevent_admin_access',$capture,PHP_INT_MAX);$check($decision===($kind==='visitor'),'actual Woo admin-access decision '.$kind.' policy '.(int)$enabled);}
  wp_set_current_user($users['author']);$request=new WP_REST_Request('POST','/wp/v2/posts');$request->set_body_params(['title'=>'Kiwe RC76 native post','content'=>'Synthetic article','status'=>'draft','categories'=>[$terms['category']]]);$response=rest_do_request($request);$data=$response->get_data();if(!empty($data['id']))$posts[]=(int)$data['id'];
  $check($response->get_status()===201&&!empty($data['id'])&&wp_get_post_categories($data['id'])===[$terms['category']],'Author native draft and category assignment policy '.(int)$enabled);
  $check((int)get_post($data['id'])->post_author===$users['author'],'native post ownership policy '.(int)$enabled);
  wp_set_current_user($users['editor']);$response=rest_do_request($request);$check($response->get_status()===403,'Editor cannot create policy '.(int)$enabled);
  wp_set_current_user($users['shop']);$request=new WP_REST_Request('POST','/wc/v3/products');$request->set_body_params(['name'=>'Kiwe RC76 native product','type'=>'simple','status'=>'draft','regular_price'=>'25.00','manage_stock'=>true,'stock_quantity'=>7,'categories'=>[['id'=>$terms['product_cat']]]]);$response=rest_do_request($request);$data=$response->get_data();if(!empty($data['id']))$posts[]=(int)$data['id'];
  $check($response->get_status()===201&&!empty($data['id']),'shop native product creation policy '.(int)$enabled);
  $product=wc_get_product($data['id']);$check($product&&(float)$product->get_regular_price()===25.0&&$product->get_stock_quantity()===7&&$product->get_category_ids()===[$terms['product_cat']],'Woo native price stock category readback policy '.(int)$enabled);
  wp_set_current_user($users['author']);$response=rest_do_request($request);$check($response->get_status()===403,'Author cannot create products policy '.(int)$enabled);
 }
 wp_set_current_user($users['author']);ob_start();$home->render_dashboard();$home->navigation();$html=ob_get_clean();$check(str_contains($html,Home::url())&&str_contains($html,'post-new.php'),'native staff Home links its editor and dedicated Home');
 $check(!WP_DEBUG&&!WP_DEBUG_LOG&&!WP_DEBUG_DISPLAY,'debug remains off');
}catch(Throwable $e){$proof['error']=['class'=>get_class($e),'line'=>$e->getLine(),'message'=>$e->getMessage()];}
finally{
 while(ob_get_level()>$level)ob_end_clean();if($policy)remove_filter('pre_option_'.Owner::OPTION,$policy,PHP_INT_MAX);wp_set_current_user(0);
 foreach($posts as$id)wp_delete_post($id,true);foreach($terms as$tax=>$id)wp_delete_term($id,$tax);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$id)wp_delete_user($id);
 $proof['cleanup']=!array_filter($users,'get_userdata')&&!array_filter($posts,'get_post');foreach($terms as$tax=>$id)$proof['cleanup']=$proof['cleanup']&&!term_exists($id,$tax);
 remove_filter('pre_option_'.Owner::OPTION,$off,PHP_INT_MAX);foreach($hooks as$hook=>$v){if($v)$GLOBALS['wp_filter'][$hook]=$v;else unset($GLOBALS['wp_filter'][$hook]);}remove_filter('pre_wp_mail','__return_false',PHP_INT_MAX);remove_filter('pre_http_request',$deny,PHP_INT_MAX);remove_filter('pre_schedule_event','__return_false',PHP_INT_MAX);wp_set_current_user($actor);
}
$proof['passed']=!isset($proof['error'])&&$proof['cleanup']&&!in_array(false,array_column($proof['checks'],'passed'),true);return $proof;
