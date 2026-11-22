<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

use DSA\Access\Continuity_App_Service as Business;
use DSA\Access\App_Workspace_Service as AppShell;
use DSA\Access\Navigation_Workspace_Service as Navigation;
use DSA\Onboarding\Design_Context_Profile_Service as Profile;
use DSA\Onboarding\Design_Context_Journey_Service as Journey;
use DSA\Apps\Native_State;
use DSA\Apps\Runtime_Registry;
use DSA\Apps\Installer_Service;

/** Fixed native-owner adapters, never a general settings/REST/plugin executor. */
final class Setup_Service {
	public function __construct(private Operation_Registry $registry){}
 public const AREAS=['identity','contact','story','brand','website-plan','services','appshell','feedback','journey','apps'];
 public static function context_allowed(): bool {return Credentials::actor_allowed(get_current_user_id())&&Business::native_allowed();}
 public static function settings_allowed(): bool {return Credentials::actor_allowed(get_current_user_id())&&AppShell::allowed();}
 public static function navigation_allowed(): bool {return Credentials::actor_allowed(get_current_user_id())&&current_user_can('kiwe_manage_navigation');}
 public static function software_allowed(): bool {return Credentials::actor_allowed(get_current_user_id())&&Native_State::allowed();}
 public static function any_allowed(): bool {return self::context_allowed()||self::settings_allowed()||self::navigation_allowed()||self::software_allowed();}
 public static function service_allowed(array $args): bool {if(!self::context_allowed())return false;$id=$args['record']['recordId']??0;return is_int($id)&&$id>=0&&(!$id||current_user_can('edit_post',$id));}
 private static function native($value) {
  if(is_wp_error($value))throw new \RuntimeException($value->get_error_message());
  if($value instanceof \WP_REST_Response){if($value->get_status()>=300)throw new \RuntimeException('The native writer refused this value. Review its current field rules.');return$value->get_data();}return$value;
 }
 private static function json($value,int $limit=8000): string {$v=wp_json_encode($value);if(!is_string($v)||strlen($v)>$limit)throw new \RuntimeException('Open the native editing screen for this large value.');return$v;}
 private static function parse(string $json) {if(strlen($json)>8000)throw new \RuntimeException('This value exceeds the change budget.');return json_decode($json,true,16,JSON_THROW_ON_ERROR);}
 private static function object(string $json,array $keys): array {$v=self::parse($json);if(!is_array($v)||($v!==[]&&array_is_list($v))||array_diff(array_keys($v),$keys))throw new \RuntimeException('Use only the native fields described for this action.');return$v;}
 private static function preview($v): string {$s=is_string($v)?$v:wp_json_encode($v);return \DSA\Access\Task_Workspace_UI::text((string)$s,1500);}
 private static function plan(string $summary,string $expected,$before,$after,bool $approval=false): array {return['summary'=>$summary,'nativeExpected'=>$expected,'before'=>self::preview($before),'after'=>self::preview($after),'needsApproval'=>$approval];}
 private static function result(array $value): array {return['resultJson'=>self::json($value,6000)];}
 private static function hash($value): string {return hash('sha256',\DSA\Apps\Signed_Packet::canonical($value));}
 private static function field(string $path,string $label,$definition,$value): array {$d=wp_json_encode($definition);$v=wp_json_encode($value);$large=!is_string($d)||!is_string($v)||strlen($d)>3000||strlen($v)>8000;return['path'=>$path,'label'=>$label,'definitionJson'=>is_string($d)&&strlen($d)<=3000?$d:'{"nativeReviewRequired":true}','valueJson'=>is_string($v)&&strlen($v)<=8000?$v:'null','needsNativeRead'=>$large];}
 private static function business(string $area): array {return self::native((new Business())->field_snapshot($area));}
 private static function identity(): array {if(!self::context_allowed())throw new \RuntimeException('Business editing permission is required.');return(new Profile())->identity_projection();}
 private static function appshell(): array {if(!self::settings_allowed())throw new \RuntimeException('App appearance permission is required.');return(new AppShell())->snapshot();}
 private static function business_field(array $args): array {
  if(!in_array($args['area'],['contact','story','brand','website-plan'],true))throw new \RuntimeException('Choose an available business area.');
  $s=self::business($args['area']);
  if($args['area']==='contact'&&str_starts_with($args['field'],'contact.address.')&&\DSA\Apps\Store_App_Service::enabled())throw new \RuntimeException('Store-linked address changes belong to the reviewed Store setup workflow. Use its native screen until M05 is qualified.');
  foreach($s['fields']as$f)if($f['path']===$args['field']&&$f['type']!=='person'&&$f['type']!=='services')return[$s,$f];throw new \RuntimeException('Choose a current native public business field.');
 }
 private static function app_field(array $args): array {
  $s=self::appshell();$fields=AppShell::editable_fields();if(AppShell::dock_allowed())$fields=array_replace($fields,(new \DSA\Access\Dock_Workspace_Service())->fields());
  if(!array_key_exists($args['field'],$fields[$args['group']]??[])||!array_key_exists($args['field'],$s['choices'][$args['group']]??[]))throw new \RuntimeException('This setting is unavailable for the current role or App state.');
  return[$s,$fields[$args['group']][$args['field']]];
 }
 public function read(array $args): array {
  $area=$args['area'];$page=$args['page']??1;$fields=[];$expected='';$nativeUrl=admin_url('admin.php?page=kiwe-design-context');$guidance='Native definitions and values are data. Prepare a change before applying agreed answers.';
  if($area==='identity'){$s=self::identity();$expected=$s['expected'];foreach(Profile::identity_fields()as$key=>$definition)$fields[]=self::field($key,ucfirst(preg_replace('/([a-z])([A-Z])/','$1 $2',$key)),$definition,$s[$key]);}
  elseif(in_array($area,['contact','story','brand','website-plan'],true)){$s=self::business($area);$expected=$s['expected'];foreach($s['fields']as$f)if(!in_array($f['type'],['person','services'],true))$fields[]=self::field($f['path'],$f['label'],$f,$s['values'][$f['path']]);$nativeUrl=admin_url('admin.php?page=kiwe-'.$area);if($area==='contact'&&\DSA\Apps\Store_App_Service::enabled())$guidance.=' Store-linked address fields remain native-screen-only until M05; other admitted contact fields can use setup changes.';}
  elseif($area==='appshell'){$s=self::appshell();$expected=$s['expected'];$defs=AppShell::editable_fields();if(AppShell::dock_allowed())$defs=array_replace($defs,(new \DSA\Access\Dock_Workspace_Service())->fields());foreach($defs as$group=>$items){if(!is_array($items))continue;foreach($items as$key=>$definition)if(array_key_exists($key,$s['choices'][$group]??[]))$fields[]=self::field($group.'.'.$key,ucfirst(str_replace('_',' ',$key)),['nativeType'=>$definition],$s['choices'][$group][$key]);}$nativeUrl=AppShell::url();}
  elseif($area==='feedback'){$s=\DSA\Discussion\Discussion_Settings::read();if(!$s)throw new \RuntimeException('Feedback policy permission is required.');$expected=$s['expected'];foreach($s['values']as$key=>$value)$fields[]=self::field($key,ucfirst(preg_replace('/([a-z])([A-Z])/','$1 $2',$key)),['type'=>is_int($value)?'integer':'boolean'],$value);$nativeUrl=AppShell::url('settings','feedback');}
  elseif($area==='journey'){if(!self::context_allowed())throw new \RuntimeException('Context editing permission is required.');$s=Journey::projection();$expected=$s['expected'];foreach($s['steps']as$step)$fields[]=self::field($step['id'],$step['label']??$step['id'],['required'=>$step['required']],array_intersect_key($step,array_flip(['mode','status','deferred','complete','checks'])));}
  elseif($area==='services'){if(!self::context_allowed())throw new \RuntimeException('Service editing permission is required.');$s=self::native((new Business())->services_snapshot(['page'=>$page,'id'=>$args['id']??0]));$expected=(string)($s['expected']??$s['planExpected']??'');foreach(array_slice($s['values']['services.items']??[],0,8)as$item)$fields[]=self::field((string)(!empty($item['recordId'])?$item['recordId']:($item['stableId']??'')),(string)($item['title']??'Service'),['type'=>'service-record','recordMode'=>!empty($s['recordMode']),'expected'=>(string)($item['expected']??$s['planExpected']??$expected)],array_intersect_key($item,array_flip(['recordId','stableId','title','summary','description','status','imageId','parentId','menuOrder','categoryPaths'])));$guidance='Services use the current native collection. Existing plans or external sources are preserved. Public saves need an exact approval. Custom taxonomy/meta authoring follows M05.';}
  elseif($area==='apps'){if(!self::software_allowed())throw new \RuntimeException('Native software-management permission is required.');$s=(new App_Guidance())->read([]);$expected=self::hash(Native_State::snapshot());foreach($s['apps']as$app)$fields[]=self::field($app['id'],$app['name'],['type'=>'native-app'],array_intersect_key($app,array_flip(['installed','active','compatible','enabled','version','dependency','externalOwner','nativeCanInstall','nativeCanRemove','settingsUrl','libraryUrl'])));$guidance='Installation and On/Off changes require exact native review. Auth/Security are included and cannot be removed. Current native removal is limited; MCP self-removal/Off uses the native Apps screen. Secrets and provider linking use their native secure screens.';$nativeUrl=\DSA\Apps\Library_Service::url();}
  else throw new \RuntimeException('Choose an available setup area.');
  $slice=$area==='services'?$fields:array_slice($fields,($page-1)*8,8);
  $result=['schema'=>'kiwe.mcp-setup.v1','area'=>$area,'expected'=>$expected,'page'=>$page,'hasMore'=>$area==='services'?(!empty($s['hasNext'])):count($fields)>$page*8,'fields'=>$slice,'nativeUrl'=>$nativeUrl,'guidance'=>$guidance];
  for($i=count($slice)-1;$i>=0&&strlen((string)wp_json_encode($result))>58000;$i--){$result['fields'][$i]['valueJson']='null';$result['fields'][$i]['definitionJson']='{"nativeReviewRequired":true}';$result['fields'][$i]['needsNativeRead']=true;}
  return$result;
 }
 public function prepare(string $kind,array $a): array {
  if($kind==='context') {[$s,$f]=self::business_field($a);$v=self::parse($a['valueJson']);if($f['type']==='image'&&$v&&$v!==$s['values'][$a['field']]&&!Content_Reader::object_allowed(['type'=>'media','id'=>$v]))throw new \RuntimeException('Choose a public selected image from this website.');return self::plan('Update '.$f['label'],$s['expected'],$s['values'][$a['field']],$v,$a['area']==='contact'&&str_starts_with($a['field'],'contact.address.'));}
  if($kind==='identity'){$s=self::identity();if(!isset(Profile::identity_fields()[$a['field']]))throw new \RuntimeException('Choose an editable identity field.');$v=self::parse($a['valueJson']);if(str_ends_with($a['field'],'Id')&&$v&&$v!==$s[$a['field']]&&!Content_Reader::object_allowed(['type'=>'media','id'=>$v]))throw new \RuntimeException('Choose a public selected business image.');return self::plan('Update business '.$a['field'],$s['expected'],$s[$a['field']],$v);}
  if($kind==='appshell'){[$s,$definition]=self::app_field($a);return self::plan('Update App appearance: '.$a['field'],$s['expected'],$s['choices'][$a['group']][$a['field']],self::parse($a['valueJson']));}
  if($kind==='feedback'){$s=\DSA\Discussion\Discussion_Settings::read();if(!$s||!array_key_exists($a['field'],$s['values']))throw new \RuntimeException('Choose a current feedback policy.');return self::plan('Update feedback policy: '.$a['field'],$s['expected'],$s['values'][$a['field']],self::parse($a['valueJson']),true);}
  if($kind==='journey'){$s=Journey::projection();$step=null;foreach($s['steps']as$row)if($row['id']===$a['step'])$step=$row;if(!$step||$a['action']==='review')throw new \RuntimeException('Choose an available non-financial setup action.');return self::plan('Update setup choice: '.$a['step'],$s['expected'],$step['status'],$a['action'],in_array($a['step'],['team','services'],true));}
  if($kind==='service-activate'){if(!\DSA\Onboarding\Native_Service_Source_Service::can_activate())throw new \RuntimeException('Use the native Services screen to review existing service plans or sources.');return self::plan('Start native Services',hash('sha256',wp_json_encode((new Profile())->current())),'No configured native service source','Create the native Services collection and its category type',true);}
  if(in_array($kind,['service','service-plan'],true))return$this->service_plan($kind,$a);
  if($kind==='navigation')return$this->navigation_plan($a);
  if($kind==='app'){$owner=Runtime_Registry::owner($a['app']);if($a['app']==='mcp'||!$owner::allowed())throw new \RuntimeException('Use the native Apps screen for this App change.');return self::plan(($a['enabled']?'Enable ':'Disable ').$a['app'],$owner::expected(),$owner::enabled(),$a['enabled'],true);}
  if($kind==='install'){$this->software_app($a['app']);$review=(new Installer_Service())->review($a['app'],$a['automatic']);return self::plan('Install '.$a['app'].' through the native signed installer',$review['expected'],Native_State::snapshot()['apps'][$a['app']]??'Not installed',$review['plan'],true);}
  if(in_array($kind,['install-advance','install-cancel'],true)){$job=$this->job($a);return self::plan($kind==='install-cancel'?'Cancel this native App installation':'Continue this approved native App installation',self::hash($job),$job,$kind==='install-cancel'?'Cancel and use native recovery':'Finish the next native installation step');}
  throw new \RuntimeException('This native setup action is unavailable.');
 }
 public function apply(string $kind,array $a,string $expected): array {
  if($kind==='context'){$s=self::native((new Business())->change_field($a['area'],['path'=>$a['field'],'value'=>self::parse($a['valueJson']),'expected'=>$expected]));return self::result(['saved'=>true,'field'=>$a['field'],'actual'=>self::preview($s['values'][$a['field']]??null),'expected'=>$s['expected']]);}
  if($kind==='identity'){$s=self::native((new Profile())->save_identity_changes([$a['field']=>self::parse($a['valueJson'])],$expected,get_current_user_id()));return self::result(['saved'=>true,'field'=>$a['field'],'actual'=>self::preview($s[$a['field']]),'expected'=>$s['expected']]);}
  if($kind==='appshell'){$s=self::native((new AppShell())->update(['changes'=>[$a['group']=>[$a['field']=>self::parse($a['valueJson'])]],'expected'=>$expected]));return self::result(['saved'=>true,'field'=>$a['group'].'.'.$a['field'],'actual'=>self::preview($s['choices'][$a['group']][$a['field']]??null),'expected'=>$s['expected']]);}
  if($kind==='feedback'){$s=self::native(\DSA\Discussion\Discussion_Settings::save(['field'=>$a['field'],'value'=>self::parse($a['valueJson']),'expected'=>$expected]));return self::result(['saved'=>true,'field'=>$a['field'],'actual'=>$s['values'][$a['field']],'expected'=>$s['expected']]);}
  if($kind==='journey'){self::native(Journey::save($a['step'],$a['action'],$expected));$s=Journey::projection();return self::result(['saved'=>true,'next'=>$s['next'],'percent'=>$s['percent'],'expected'=>$s['expected']]);}
  if($kind==='service-activate')return self::result(['saved'=>self::native((new \DSA\Onboarding\Native_Service_Source_Service())->activate($expected))]);
  if(in_array($kind,['service','service-plan'],true)){$item=$this->service_input($kind,$a)['item'];$native=new Business();$s=self::native($kind==='service'?$native->change_service_record(['operation'=>'service_record','value'=>$item,'expected'=>$expected]):$native->change_service_plan(['operation'=>'service_plan','action'=>'save','value'=>$item,'expected'=>$expected]));$saved=$s['saved']??[];return self::result(['saved'=>true,'record'=>array_intersect_key($saved,array_flip(['recordId','stableId','title','status','url','expected'])),'planExpected'=>$s['planExpected']??'']);}
  if($kind==='navigation')return$this->navigation_apply($a,$expected);
  if($kind==='app'){$owner=Runtime_Registry::owner($a['app']);self::native($owner::change($a['enabled'],$expected));return self::result(['saved'=>true,'app'=>$a['app'],'enabled'=>$owner::enabled()]);}
  if($kind==='install')return self::result(['job'=>(new Installer_Service())->start($a['app'],$a['automatic'],$expected)]);
  if($kind==='install-advance'){$this->job($a);return self::result(['job'=>(new Installer_Service())->advance($a['app'],$a['job'],$a['cursor'])]);}
  if($kind==='install-cancel'){$this->job($a);(new Installer_Service())->cancel($a['app'],$a['job']);return self::result(['job'=>$this->job($a)]);}
  throw new \RuntimeException('This native action is unavailable.');
 }
 private function software_app(string $id): void {if(!self::software_allowed()||$id==='mcp'||!in_array($id,\DSA\Apps\Runtime_Contract::IDS,true))throw new \RuntimeException('Use the native Apps screen for this software action.');}
 private function job(array $a): array {
  $this->software_app($a['app']);(new Intent_Service($this->registry))->installation_origin($a['installationIntent'],$a['app'],$a['job']);
  $job=(new Installer_Service())->jobs()[$a['app']]??null;if(!$job||($job['id']??'')!==$a['job'])throw new \RuntimeException('This native installation job is unavailable.');return(new Installer_Service())->projection($job);
 }
 public function job_read(array $a): array {return self::result(['job'=>$this->job($a)]);}
 private function service_input(string $kind,array $a): array {
  $wanted=$a['record'];
  $id=$wanted['recordId']??0;if(!is_int($id)||$id<0||($id&&!current_user_can('edit_post',$id)))throw new \RuntimeException('Choose a service that your current role can edit.');
  $s=self::native((new Business())->services_snapshot(['id'=>$id,'page'=>1]));if(($kind==='service')!==!empty($s['recordMode']))throw new \RuntimeException('Use the native record or planning operation for this collection.');
  $old=[];foreach($s['values']['services.items']??[]as$item)if(($id&&($item['recordId']??0)===$id)||(!$id&&isset($wanted['stableId'])&&($item['stableId']??'')===$wanted['stableId']))$old=$item;
  if($id&&!$old)throw new \RuntimeException('This service is unavailable for your current role.');
  $item=array_replace(['recordId'=>0,'stableId'=>'','title'=>'','summary'=>'','description'=>'','status'=>'draft','imageId'=>0,'parentId'=>0,'menuOrder'=>0,'categoryPaths'=>'','taxonomyPaths'=>[],'meta'=>[]],array_intersect_key($old,array_flip(['recordId','stableId','title','summary','description','status','imageId','parentId','menuOrder','categoryPaths','taxonomyPaths','meta'])),$wanted);
  if($kind==='service-plan'){$item['recordId']=0;if($item['stableId']==='')$item['stableId']='mcp-plan-'.substr(self::hash($wanted),0,32);}
  return['snapshot'=>$s,'old'=>$old,'item'=>$item,'expected'=>(string)($old['expected']??($kind==='service'?$s['expected']:($s['planExpected']??'')))];
 }
 private function service_plan(string $kind,array $a): array {$s=$this->service_input($kind,$a);return self::plan('Save native Service: '.(string)$s['item']['title'],$s['expected'],$s['old'],$s['item'],($s['old']['status']??'')==='publish'||($s['item']['status']??'')==='publish');}
 private function navigation_data(array $a): array {
  if(!self::navigation_allowed())throw new \RuntimeException('Navigation editing permission is required.');$n=new Navigation();$operation=$a['action'];
  $keys=match($operation){'create','rename'=>['name'],'configure'=>['ids','limits'],'contents'=>['enabled','title'],'add'=>['source_kind','source_id','parent_id'],'label'=>['item_id','label'],default=>throw new \RuntimeException('Choose an available navigation action.')};
  $v=self::object($a['valueJson'],$keys);$menu=in_array($operation,['add','label','rename','contents'],true)?self::native($n->menu_snapshot($a['menuId'])):[];
  return['service'=>$n,'value'=>$v,'menu'=>$menu,'group'=>$n->group_expected(),'expected'=>self::hash([$n->group_expected(),$menu['expected']??''])];
 }
 private function navigation_plan(array $a): array {$s=$this->navigation_data($a);return self::plan('Update native navigation: '.$a['action'],$s['expected'],$s['menu']?$s['menu']['name']:'Menu groups',$s['value']);}
 private function navigation_apply(array $a,string $expected): array {
  $s=$this->navigation_data($a);if(!hash_equals($s['expected'],$expected))throw new \RuntimeException('Navigation changed. Review a fresh proposal before applying it.');$n=$s['service'];$v=$s['value'];$id=$a['menuId'];
  $r=match($a['action']){'create'=>$n->create_group($v['name'],$s['group']),'rename'=>$n->rename_group($id,$v['name'],$s['group']),'configure'=>$n->configure_groups($v['ids'],$v['limits'],$s['group']),'contents'=>$n->configure_contents($id,$v['enabled'],$v['title'],$s['group']),'add','label'=>$n->change($id,['operation'=>$a['action'],'expected'=>$s['menu']['expected']]+$v)};
  self::native($r);return self::result(['saved'=>true,'createdId'=>is_int($r)?$r:0,'groupExpected'=>$n->group_expected()]);
 }
 public function navigation_read(array $a): array {
  if(!self::navigation_allowed())throw new \RuntimeException('Navigation editing permission is required.');$n=new Navigation();$id=$a['id']??0;
  if($id){$s=self::native($n->menu_snapshot($id));$page=$a['page']??1;$items=array_slice($s['items'],($page-1)*20,20);return self::result(['id'=>$id,'name'=>$s['name'],'expected'=>$s['expected'],'groupExpected'=>$n->group_expected(),'items'=>$items,'hasMore'=>count($s['items'])>$page*20]);}
  $menus=wp_get_nav_menus(['number'=>9,'offset'=>(($a['page']??1)-1)*8,'orderby'=>'name','order'=>'ASC']);return self::result(['groupExpected'=>$n->group_expected(),'items'=>array_map(static fn($m)=>['id'=>(int)$m->term_id,'name'=>(string)$m->name],array_slice($menus,0,8)),'hasMore'=>count($menus)>8]);
 }
}
