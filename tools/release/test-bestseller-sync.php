<?php
declare(strict_types=1);
namespace DSA {class Settings {public function get($key,$default=[]){return $GLOBALS['config'];}}}
namespace {
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';define('HOUR_IN_SECONDS',3600);define('DAY_IN_SECONDS',86400);
class WP_Error{}
function is_wp_error($v){return $v instanceof WP_Error;}function absint($v){return abs((int)$v);}function sanitize_key($v){return $v;}function sanitize_title($v){return $v;}function sanitize_text_field($v){return $v;}function current_time($format){return '2026-09-12 13:00:00';}
$config=['bestseller_enabled'=>true,'bestseller_limit'=>3,'bestseller_parent_slug'=>'best','bestseller_parent_label'=>'Best'];$options=['dsa_bestseller_last_sync'=>'previous'];$cache=[];$writes=0;$queries=[];$members=[];$removed=0;$failedRemove=false;$locked=false;$released=0;$failAdd=false;
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;$GLOBALS['writes']++;return true;}
function get_transient($key){return $GLOBALS['cache'][$key]??false;}function set_transient($key,$value,$ttl){$GLOBALS['cache'][$key]=$value;return true;}
function term_exists($slug,$taxonomy){return ['term_id'=>['best'=>1,'best-week'=>2,'best-month'=>3,'best-year'=>4][$slug]];}
function get_term_meta($id,$key,$single=true){$value=$GLOBALS['termOwners'][$id]??'';return $single?$value:($value===''?[]:[$value]);}function get_term($id,$taxonomy){return (object)['term_id'=>$id,'parent'=>$GLOBALS['termParents'][$id]??($id===1?0:1)];}
function wc_get_product($id){return new class {public function get_status(){return 'publish';}public function get_type(){return 'simple';}};}
function wp_timezone(){return new DateTimeZone('Asia/Kolkata');}function wp_date($format,$timestamp,$zone){return (new DateTimeImmutable('@'.$timestamp))->setTimezone($zone)->format($format);}
$termOwners=[1=>'best:parent',2=>'best:week',3=>'best:month',4=>'best:year'];$termParents=[];$tablesAvailable=true;$rankRows=[900];$rankError=false;$rankCalls=0;
function wp_set_object_terms($id,$terms,$taxonomy,$append){if($GLOBALS['failAdd'])return new WP_Error;foreach($terms as$term)$GLOBALS['members'][$term][$id]=true;return $terms;}
function wp_remove_object_terms($id,$term,$taxonomy){if($GLOBALS['failedRemove'])return false;unset($GLOBALS['members'][$term][$id]);$GLOBALS['removed']++;return true;}
function get_posts($args){$GLOBALS['queries'][]=$args;if(isset($args['meta_key']))return [900];$term=$args['tax_query'][0]['terms'][0];$ids=array_diff(array_keys($GLOBALS['members'][$term]??[]),$args['post__not_in']);sort($ids);return array_slice($ids,0,$args['posts_per_page']);}
$wpdb=new class {public string $prefix='fixture_';public string $last_error='';public function prepare($s,...$args){return str_contains($s,'SHOW TABLES')?$s.' '.$args[0]:$s;}public function get_var($sql){if(str_contains($sql,'GET_LOCK'))return $GLOBALS['locked']?0:1;if(str_contains($sql,'RELEASE_LOCK')){$GLOBALS['released']++;return 1;}if(str_contains($sql,'SHOW TABLES')&&$GLOBALS['tablesAvailable'])return str_contains($sql,'wc_order_product_lookup')?'fixture_wc_order_product_lookup':'fixture_wc_order_stats';return '';}public function get_col($sql){$GLOBALS['rankCalls']++;$this->last_error=$GLOBALS['rankError']?'test failure':'';return $GLOBALS['rankRows'];}};
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/Linked_Products_Service.php';$service=new DSA\Commerce\Linked_Products_Service(new DSA\Settings());
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
foreach([1,2,3,4]as$term)foreach(range(1,61)as$id)$members[$term][$id]=true;
foreach(['week','month','year']as$period)$cache['dsa_bestsellers_'.$period]=['source'=>'woo-order-period-v1','limit'=>3,'ids'=>[900]];
$service->sync_bestseller_terms();check($removed===200&&$options['dsa_bestseller_sync_status']['status']==='pending','Large category cleanup is bounded and pending');check($options['dsa_bestseller_last_sync']==='previous','Pending sync preserves completed timestamp');
check(count($queries)===4&&!array_filter($queries,fn($q)=>$q['posts_per_page']!==51||$q['tax_query'][0]['include_children']!==false),'Four bounded exact-category queries exclude descendants');
$service->sync_bestseller_terms();check($removed===244&&$options['dsa_bestseller_sync_status']['status']==='complete','Next run finishes remaining members');check(!array_filter($members,fn($ids)=>array_keys($ids)!==[900]),'Desired memberships remain in all categories');
$prior=$writes;$locked=true;$service->sync_bestseller_terms();check($writes===$prior,'Busy lock returns without writes');$locked=false;
$members[2][33]=true;$failedRemove=true;$options['dsa_bestseller_last_sync']='before failure';$service->sync_bestseller_terms();check($options['dsa_bestseller_sync_status']['status']==='failed'&&$options['dsa_bestseller_last_sync']==='before failure','Failed removal cannot claim completion');check($released===3,'Lock released on success and failure');$failedRemove=false;
$failAdd=true;$service->sync_bestseller_terms();check($options['dsa_bestseller_sync_status']['status']==='failed','Failed addition prevents stale cleanup');$failAdd=false;
$config['bestseller_enabled']=false;$prior=$writes;$service->sync_bestseller_terms();check($writes===$prior,'Disabled sync has no work');$config['bestseller_enabled']=true;
$config['bestseller_limit']=4;$service->sync_bestseller_terms();check($cache['dsa_bestsellers_week']['limit']===4&&$cache['dsa_bestsellers_week']['ids']===[900],'Changed limit replaces the cached candidate shape');
$before=$members;$termOwners[2]='';$service->sync_bestseller_terms();check($members===$before&&$options['dsa_bestseller_sync_status']['reason']==='category_review','Existing unowned category blocks all membership changes');$termOwners[2]='best:week';
$termParents[2]=77;$service->sync_bestseller_terms();check($members===$before&&$options['dsa_bestseller_sync_status']['reason']==='category_review','Changed category hierarchy requires review');$termParents=[];
$cache=[];$tablesAvailable=false;$last=get_option('dsa_bestseller_last_sync');$service->sync_bestseller_terms();check($members===$before&&$options['dsa_bestseller_sync_status']['reason']==='ranking_unavailable'&&get_option('dsa_bestseller_last_sync')===$last,'Missing analytics preserves existing memberships and timestamp');
$method=new ReflectionMethod($service,'bestseller_product_ids');check($method->invoke($service,'week',4)===[],'Public recommendation read tolerates unavailable analytics');
$tablesAvailable=true;$rankError=true;$service->sync_bestseller_terms();check($members===$before&&$options['dsa_bestseller_sync_status']['reason']==='ranking_unavailable','Analytics SQL failure cannot masquerade as empty rankings');$rankError=false;
$rankRows=[];$service->sync_bestseller_terms();check($options['dsa_bestseller_sync_status']['status']==='complete'&&!array_filter($members),'Genuinely empty periods clear only managed memberships');
$cache=['dsa_bestsellers_week'=>['limit'=>4,'ids'=>[777]]];$rankRows=[901];$before=$rankCalls;check($method->invoke($service,'week',4)===[]&&$rankCalls===$before,'Old cache without provenance does not trigger visitor calculation');check($method->invoke($service,'week',4,true)===[901],'Explicit maintenance replaces cache without period provenance');
echo "Bestseller sync boundaries passed.\n";
}
