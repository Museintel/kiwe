<?php
namespace DSA\SEO;
use DSA\Access\Task_Workspace_UI as UI;
use DSA\Schema\Schema_Template_Service as Templates;
use DSA\Utilities\Origin_Checker;
if(!defined('ABSPATH'))exit;
if(!class_exists(\DSA\Onboarding\Search_Settings_Writer::class))require_once dirname(__DIR__).'/Onboarding/Search_Settings_Writer.php';

/** Explicit manager-only staging into native defaults, with conditional undo. */
final class SEO_Settings_Handover_Service extends \DSA\Onboarding\Search_Settings_Writer {
 public const JOURNAL='kiwe_seo_settings_handover_v1';
 
 public function register():void{add_action('rest_api_init',[$this,'routes']);}
 public function routes():void{register_rest_route('dsa/v1','/workspace/ai/seo-handover',[
  ['methods'=>'GET','callback'=>[$this,'read'],'permission_callback'=>[$this,'permission']],
  ['methods'=>'POST','callback'=>[$this,'mutate'],'permission_callback'=>[$this,'permission']],
 ]);}
 public function permission(\WP_REST_Request $r):bool{return current_user_can('manage_options')&&in_array($r->get_method(),['GET','POST'],true)&&wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest')&&Origin_Checker::is_same_site_request()&&($r->get_method()==='GET'||true===Origin_Checker::mutation_allowed($r));}
 private static function failure(string $message):\WP_Error{return new \WP_Error('kiwe_seo_handover_review',$message);}
 /** Bound materialization before decoding; read the physical tuple, never cached source state. */

 private static function decoded(?array $row):array{if(!$row)return[];$v=Templates::decode($row['bytes']);if(is_wp_error($v)||!is_array($v))throw new \RuntimeException('SEO settings need an explicit supported source review.');return$v;}
 private function review():array{
  $groups=[];$rows=[];foreach(['titles','sitemap','general']as$group){$rows[$group]=self::row('rank-math-options-'.$group);$groups[$group]=self::decoded($rows[$group]);}
  $view=SEO_Settings_Handover_Model::review($groups,array_values(array_filter(get_post_types(['public'=>true]),static fn($v)=>$v!=='attachment')),array_values(get_taxonomies(['public'=>true])));
  $target=self::row(SEO_Defaults_Service::OPTION,65536);$native=self::decoded($target);if(SEO_Defaults_Model::validate($native)===false)throw new \RuntimeException('Current Kiwe defaults need review.');
  $journal=self::row(self::JOURNAL,196608);$j=self::decoded($journal);
  if($journal&&(($j['schema']??null)!==1||array_diff(array_keys($j),['schema','before','after','sourceSha','actor','created'])||!array_key_exists('before',$j)||($j['before']!==null&&!is_string($j['before']))||!is_string($j['after']??null)||!preg_match('/^[a-f0-9]{64}$/D',$j['sourceSha']??'')))throw new \RuntimeException('The retained settings journal needs review.');
  if($journal){foreach(['before','after']as$key)if($j[$key]!==null){$state=Templates::decode($j[$key]);if(is_wp_error($state)||SEO_Defaults_Model::validate($state)===false)throw new \RuntimeException('The retained settings journal contains unsupported defaults.');}}
  $conflicts=[];self::conflicts($native,$view['settings'],'',$conflicts);
  // Existing explicit native choices win. Never silently replace them during source import.
  $next=self::merge($native,$view['settings']);if(SEO_Defaults_Model::validate($next)===false)throw new \RuntimeException('The combined native defaults need review.');
  $sourceSha=hash('sha256',serialize($rows));$same=$journal&&$target&&$target['bytes']===$j['after'];$prior=$journal&&(($target['bytes']??null)===$j['before']);
  $public=$view+['schema'=>'kiwe.seo-handover.v1','sourcePresent'=>(bool)array_filter($rows),'frontendOwner'=>SEO_Defaults_Service::external_owner()?:'kiwe','nativeConflicts'=>$conflicts,'canImport'=>!$journal&&(bool)$view['settings']&&$next!==$native,'canUndo'=>(bool)($same||$prior),'journalRetained'=>(bool)$journal,'defaultsUrl'=>admin_url('admin.php?page=kiwe-seo-assistant'),'sourcePreserved'=>true];
  unset($public['settings']);$public['expected']=hash('sha256',serialize([$sourceSha,$target,$journal,$next]));
  return ['public'=>$public,'target'=>$target,'journal'=>$journal,'decodedJournal'=>$j,'next'=>maybe_serialize($next),'sourceSha'=>$sourceSha];
 }
 private static function conflicts(array $current,array $incoming,string $path,array &$out):void{foreach($incoming as$k=>$v){$p=$path===''?(string)$k:$path.'.'.$k;if(!array_key_exists($k,$current))continue;if(is_array($v)&&!array_is_list($v)&&is_array($current[$k]))self::conflicts($current[$k],$v,$p,$out);elseif($current[$k]!==$v)$out[]=$p;}}
 private static function merge(array $current,array $incoming):array{foreach($incoming as$k=>$v){if(!array_key_exists($k,$current))$current[$k]=$v;elseif(is_array($v)&&!array_is_list($v)&&is_array($current[$k]))$current[$k]=self::merge($current[$k],$v);}return$current;}
 public function read(\WP_REST_Request $r):\WP_REST_Response{if(!$this->permission($r))return UI::failure(self::failure('Website manager authority is required.'),403);try{return UI::response($this->review()['public']);}catch(\Throwable$e){return UI::failure(self::failure($e->getMessage()),409);}}


 /** Native editor and snapshot recovery share the handover's exact conditional writer. */

 public function mutate(\WP_REST_Request $r):\WP_REST_Response{
  if(!$this->permission($r))return UI::failure(self::failure('Website manager authority is required.'),403);$b=$r->get_json_params();if(!is_array($b)||($b['confirmed']??null)!==true||!in_array($b['operation']??null,['import','undo'],true)||!is_string($b['expected']??null)||!preg_match('/^[a-f0-9]{64}$/D',$b['expected']))return UI::failure(self::failure('Review and confirm the saved settings action.'),400);
  global$wpdb;$this->lock='kiwe_defaults_'.substr(hash('sha256',(string)$wpdb->dbname.'|'.$wpdb->prefix),0,40);if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$this->lock))!=='1'){$this->lock='';return UI::failure(self::failure('Another settings change is active. Refresh before retrying.'),409);}
  try{$v=$this->review();if(!hash_equals($v['public']['expected'],$b['expected']))throw new \RuntimeException('Source or Kiwe settings changed. Refresh the review.');
   if($b['operation']==='import'){
    if(!$v['public']['canImport'])throw new \RuntimeException('Existing native choices or an earlier journal need review.');
    $j=['schema'=>1,'before'=>$v['target']['bytes']??null,'after'=>$v['next'],'sourceSha'=>$v['sourceSha'],'actor'=>get_current_user_id(),'created'=>time()];if(strlen(maybe_serialize($j))>Templates::BYTES)throw new \RuntimeException('The exact settings undo journal exceeds its supported size.');
    if(!$this->replace(self::JOURNAL,null,maybe_serialize($j)))throw new \RuntimeException('The settings journal could not be saved.');
    if(!$this->replace(SEO_Defaults_Service::OPTION,$v['target'],$v['next']))throw new \RuntimeException('Settings import paused with its journal. Refresh and review undo.');
   }else{
    if(!$v['public']['canUndo'])throw new \RuntimeException('Newer native settings are protected. The journal remains available.');$j=$v['decodedJournal'];
    if(($v['target']['bytes']??null)!==$j['before']&&!$this->replace(SEO_Defaults_Service::OPTION,$v['target'],$j['before']))throw new \RuntimeException('Conditional settings undo needs readback.');
    if(!$this->replace(self::JOURNAL,$v['journal'],null))throw new \RuntimeException('Settings were restored; journal retirement needs another review.');
   }
   do_action('kiwe_seo_defaults_changed');do_action('litespeed_purge_all');return UI::response($this->review()['public']);
  }catch(\Throwable$e){return UI::failure(self::failure($e->getMessage()),409);}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$this->lock));$this->lock='';}
 }
}
