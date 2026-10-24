<?php
declare(strict_types=1);
namespace DSA {class Settings {public function get($key,$default=[]){return $GLOBALS['config'];}}}
namespace {
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
class WP_Error {public function __construct(public $code,public $message='',public $data=[]){} }
function is_wp_error($v){return $v instanceof WP_Error;}function wc_get_product($id){throw new RuntimeException('Review must not load products');}
function current_user_can($cap,...$args){return !empty($GLOBALS['caps'][$cap]);}function sanitize_title($s){return $s;}function wp_json_encode($v){return json_encode($v);}
function get_term_by($field,$slug,$tax){foreach($GLOBALS['terms']as$t)if($t->slug===$slug)return clone $t;return false;}
function get_term_meta($id,$key,$single=false){return $GLOBALS['meta'][$id]??[];}
function add_term_meta($id,$key,$value,$unique){$GLOBALS['writes']++;if($GLOBALS['refuse']||isset($GLOBALS['meta'][$id]))return false;$GLOBALS['meta'][$id]=[$GLOBALS['alter']?'other':$value];return 1;}
$config=['bestseller_enabled'=>false,'bestseller_parent_slug'=>'best','bestseller_limit'=>3];$caps=array_fill_keys(['edit_products','manage_product_terms','edit_product_terms','edit_term'],true);$terms=[];$meta=[];$writes=0;$refuse=false;$alter=false;$locked=false;$releases=0;
foreach(['parent'=>'','week'=>'-week','month'=>'-month','year'=>'-year']as$period=>$suffix){$id=count($terms)+1;$terms[$id]=(object)['term_id'=>$id,'slug'=>'best'.$suffix,'name'=>'Best '.$period,'count'=>9,'parent'=>$id===1?0:1];}
$wpdb=new class {public $prefix='fixture_';public function prepare($s,...$args){return $s;}public function get_var($s){if(str_contains($s,'GET_LOCK'))return $GLOBALS['locked']?0:1;$GLOBALS['releases']++;return 1;}};
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Commerce_App_Service.php';$service=new DSA\Access\Commerce_App_Service();$checks=0;
function check($ok,$name){if(!$ok)throw new RuntimeException($name);$GLOBALS['checks']++;echo "PASS $name\n";}
$r=$service->bestseller_review_record();check(count($r['rows'])===4&&$writes===0&&$r['canAdopt'],'Review reads four categories without writes');
$input=['id'=>2,'expected'=>$r['expected'],'acceptMembershipChanges'=>true];$saved=$service->adopt_bestseller_record($input);
check(!is_wp_error($saved)&&$meta[2]===['best:week']&&!$config['bestseller_enabled'],'Approval writes one native marker without enabling automation');
check(is_wp_error($service->adopt_bestseller_record($input))&&$writes===1,'Replayed approval is stale');
unset($meta[2]);$r=$service->bestseller_review_record();$input['expected']=$r['expected'];$terms[2]->name='Renamed';check(is_wp_error($service->adopt_bestseller_record($input))&&$writes===1,'Rename invalidates review');$terms[2]->name='Best week';
$config['bestseller_enabled']=true;check(is_wp_error($service->adopt_bestseller_record($input)),'Enablement change invalidates review');$config['bestseller_enabled']=false;
foreach([['id'=>999],['id'=>'2'],['acceptMembershipChanges'=>'true'],['acceptMembershipChanges'=>false],['expected'=>'bad']]as$bad)check(is_wp_error($service->adopt_bestseller_record(array_replace($input,$bad)))&&$writes===1,'Invalid approval cannot write: '.array_key_first($bad));
$locked=true;check(is_wp_error($service->adopt_bestseller_record($input))&&$writes===1,'Busy sync lock refuses approval without waiting');$locked=false;
$caps['manage_product_terms']=false;check(!$service->bestseller_review_record()['canAdopt']&&is_wp_error($service->adopt_bestseller_record($input)),'Product-only staff can inspect but not approve');$caps['manage_product_terms']=true;
$caps['edit_term']=false;check(is_wp_error($service->adopt_bestseller_record($input)),'Native per-term denial is respected');$caps['edit_term']=true;
$terms[2]->parent=77;$r=$service->bestseller_review_record();check($r['rows'][1]['state']==='hierarchy'&&is_wp_error($service->adopt_bestseller_record(array_replace($input,['expected'=>$r['expected']]))),'Incorrect hierarchy cannot be adopted');$terms[2]->parent=1;
$meta[2]=['other:week'];$r=$service->bestseller_review_record();check($r['rows'][1]['state']==='conflict'&&is_wp_error($service->adopt_bestseller_record(array_replace($input,['expected'=>$r['expected']]))),'Another ownership marker cannot be replaced');unset($meta[2]);
$refuse=true;check(is_wp_error($service->adopt_bestseller_record($input))&&!isset($meta[2]),'Refused marker write cannot report success');$refuse=false;
$alter=true;check(is_wp_error($service->adopt_bestseller_record($input)),'Altered marker fails native readback');$alter=false;unset($meta[2]);
unset($terms[1]);$r=$service->bestseller_review_record();check($r['rows'][0]['state']==='missing'&&$r['rows'][1]['state']==='hierarchy','Missing parent prevents child adoption');
$caps['edit_products']=false;check(is_wp_error($service->bestseller_review_record()),'Non-commerce role cannot inspect categories');
echo "$checks bestseller review checks passed.\n";
}
