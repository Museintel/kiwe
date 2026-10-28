<?php
namespace DSA\Site { final class Site_Identity_Service { public static function attachment_id(){return 7;} public static function store_phone(){return '+15550000000';} public static function store_email(){return 'hello@example.invalid';} } }
namespace DSA\AI { final class AI_Feature_Policy { public static function seo_enabled(){return true;} } }
namespace DSA\Access { final class Commerce_App_Service { public static function url($area){return 'https://example.invalid/wp-admin/admin.php?page=kiwe-store&area='.$area;} } }
namespace DSA\Onboarding {
 final class Design_Context_Profile_Service { public const OPTION_PROFILE='kiwe_seam_design_context_v1'; public const USER_META_TEAM_MEMBER='kiwe_public_team_member'; public const USER_META_TEAM_TITLE='kiwe_team_title'; public function commerce_available(){return $GLOBALS['woo'];} }
 final class Context_Selection_Service { public const MEDIA_META='_kiwe_design_context_media'; public const TESTIMONIAL_META='_kiwe_design_context_testimonial'; }
 final class User_Profile_Service { public static function eligible($id){return $id===9;} }
}
namespace {
 define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php'; define('DSA_OPTION_SETTINGS','dsa_settings');
 $woo=false;$allowed=true;$options=['site_icon'=>8];$transients=[];$queries=0;$writes=0;$evidence=['pages'=>true,'products'=>false,'media'=>false,'testimonials'=>false,'team'=>false,'articles'=>false,'navigation'=>false];
 class WP_Error { public function __construct(public $code,public $message){} public function get_error_message(){return $this->message;} }
 function is_wp_error($v){return $v instanceof WP_Error;} function is_user_logged_in(){return true;} function current_user_can($cap){return $GLOBALS['allowed'];}
 function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;} function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;++$GLOBALS['writes'];return true;}
 function get_transient($key){return $GLOBALS['transients'][$key]??false;} function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;return true;} function delete_transient($key){unset($GLOBALS['transients'][$key]);}
 function get_post_types($args){return ['post','page','product'];} function get_users($args){if(($args['number']??0)!==1||empty($args['capability__in']))throw new \RuntimeException('Team evidence must be bounded and capability filtered');return $GLOBALS['evidence']['team']?[9]:[];}
 function WC(){return null;}
 function get_bloginfo($key){return 'Synthetic business';} function wp_strip_all_tags($v){return strip_tags($v);} function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);} function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
 function admin_url($path){return 'https://example.invalid/wp-admin/'.$path;} function wp_json_encode($v){return json_encode($v);} function post_type_exists($v){return $v==='service';}
 $wpdb=new class { public $posts='wp_posts',$postmeta='wp_postmeta',$term_relationships='wp_term_relationships',$term_taxonomy='wp_term_taxonomy',$comments='wp_comments',$commentmeta='wp_commentmeta',$last_error='';public function prepare($s,...$v){return $s.implode('|',$v);} public function get_var($s){++$GLOBALS['queries'];foreach(['media'=>'wp_postmeta','testimonials'=>'wp_commentmeta','products'=>"post_type='product'",'pages'=>"post_type='page'",'articles'=>"post_type='post'",'navigation'=>"post_type='nav_menu_item'"]as$k=>$v)if(str_contains($s,$v))return $GLOBALS['evidence'][$k]?1:null;return null;} };
 require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Journey_Service.php';
 $j=\DSA\Onboarding\Design_Context_Journey_Service::class;$key=\DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE;
 $options[$key]=['identity'=>['description'=>'What we do'],'audience'=>['primary'=>'Our readers'],'seo'=>['primaryGoal'=>'read','homepageDescription'=>'A publication','searchIntent'=>'Local news'],'brand'=>['tone'=>'clear'],'services'=>['enabled'=>false,'items'=>[['title'=>'Kept','description'=>'Kept']]],'about'=>['team'=>['members'=>[['userId'=>9]]]],'future'=>['keep'=>true]];
 $checks=0;$assert=function($ok,$label)use(&$checks){++$checks;if(!$ok)throw new \RuntimeException($label);};
 $read=fn()=>$j::projection();$find=fn($v,$id)=>array_values(array_filter($v['steps'],fn($s)=>$s['id']===$id))[0]??null;
 $v=$read();$assert($v['percent']===100,'Optional empty features do not block completed essentials');$assert($v['later']===3,'Optional evidence stays visible without a separate manual testimonial step');$assert($find($v,'testimonials')===null,'Live conversation sources replace the manual testimonial selector');$assert(!$find($v,'store')&&!$find($v,'products'),'Non-Woo has no commerce steps');
 $before=$queries;$read();$assert($queries===$before,'Warm progress has no catalog queries');$assert(!str_contains(json_encode($v),'hello@example.invalid'),'Projection contains readiness only, not private contact values');
 $before=$options[$key];$assert(true===$j::save('services','include',$v['expected']),'Enable services');$assert($options[$key]['future']===$before['future']&&$options[$key]['services']['items']===$before['services']['items'],'Exact unrelated context and service plans retained');$v=$read();$assert($find($v,'services')['included']&&$find($v,'services')['complete'],'Existing service plan reused');
 $assert(is_wp_error($j::save('team','include',str_repeat('a',64))),'Stale preference save denied');$assert(is_wp_error($j::save('identity','off',$v['expected'])),'Required information cannot be dismissed');
 $assert(true===$j::save('services','off',$v['expected']),'Disable services');$assert($options[$key]['services']['items']===$before['services']['items'],'Disabled source preserved');
 $v=$read();$assert(true===$j::save('media','include',$v['expected']),'Choose media');$v=$read();$assert($v['percent']<100&&!$find($v,'media')['complete'],'Enabled missing evidence reduces progress');$assert(true===$j::save('media','later',$v['expected']),'Defer optional evidence');$v=$read();$assert($v['percent']===100&&!$find($v,'media')['included'],'Deferred optional evidence excluded without being marked complete');
 $options[$key]['identity']['description']='';$v=$read();$percent=$v['percent'];$assert(true===$j::save('story','later',$v['expected']),'Required work can be resumed later');$v=$read();$assert($v['percent']===$percent&&$find($v,'story')['included']&&!$find($v,'story')['complete'],'Deferring required work never increases progress');
 $allowed=false;$before=$writes;$assert($read()===null&&is_wp_error($j::save('team','include',$v['expected']))&&$writes===$before,'Non-manager denied projection and writes');$allowed=true;
 $woo=true;$evidence['products']=true;$j::invalidate();$options['woocommerce_store_address']='Synthetic street';$options['woocommerce_store_city']='Synthetic city';$options['woocommerce_default_country']='IN';$options['woocommerce_currency']='INR';$v=$read();$assert($find($v,'products')['complete']&&$find($v,'store')!==null&&$find($v,'regulatory')!==null,'Woo product store and registration families present');
 $assert(true===$j::save('store','review',$v['expected']),'Explicit store review saved');$v=$read();$assert($find($v,'store')['complete'],'Native store basics plus review');$options['woocommerce_currency']='USD';$v=$read();$assert(!$find($v,'store')['complete'],'Changed store choices require review again');
 $assert(true===$j::save('team','include',$v['expected']),'Team opt in saved');$evidence['team']=true;$j::invalidate();$v=$read();$assert($find($v,'team')['complete']&&$j::feature_enabled('team'),'Selected eligible membership used');
 $assert(is_wp_error($j::save('team','later',$v['expected']))&&is_wp_error($j::save('contact','review',$v['expected'])),'Unsupported actions rejected');
 $complete_journey=$read();$profile_before=$options[$key];$writes_before=$writes;
 foreach(['0','1'] as $seo_choice){$options['kiwe_seo_workspace_enabled_v1']=$seo_choice;$assert($read()===$complete_journey,'Complete journey and readiness are unchanged with SEO '.$seo_choice);$assert($find($read(),'search')!==null,'Search and sharing stays in Core with SEO '.$seo_choice);}
 define('RANK_MATH_VERSION','synthetic');$assert($read()===$complete_journey,'External SEO ownership does not shrink Design Context setup');$assert($options[$key]===$profile_before&&$writes===$writes_before,'Journey parity reads never rewrite owner context');
 echo json_encode(['passed'=>true,'checks'=>$checks,'syntheticIo'=>true]).PHP_EOL;
}
