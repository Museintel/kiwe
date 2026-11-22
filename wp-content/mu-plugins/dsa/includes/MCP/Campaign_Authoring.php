<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

use DSA\Access\Campaign_Workspace_Service as Workspace;
use DSA\MCP\Authoring_Service as Native;

/** Typed bridge to the existing consent, deduplication and bounded delivery owners. */
final class Campaign_Authoring {
 public static function allowed():bool{return Credentials::actor_allowed(get_current_user_id())&&Workspace::native_allowed();}
 private function service():Workspace{if(!self::allowed())throw new \RuntimeException('Campaigns are unavailable to your current role.');return \DSA\Plugin::instance()->campaign_workspace();}
 private function draft(string $id):array{$d=Native::native($this->service()->native_read(['id'=>$id]));if(!$id||!is_array($d['draft']??null))throw new \RuntimeException('Choose a current campaign draft.');return$d['draft'];}
 private function audience(array $d):array{return Native::native($this->service()->native_audience($d['id']));}
 private function delivery():array{return Native::native($this->service()->native_read([]))['delivery'];}
 /** Review text must be complete, never clipped to hide consequential message content. */
 public static function review(string $summary,string $expected,$before,$after):array{
  return['summary'=>$summary,'nativeExpected'=>$expected,'before'=>Native::json($before,2400),'after'=>Native::json($after,2400),'needsApproval'=>true];
 }
 public function read(array $a):array{
  $s=$this->service();$area=$a['area'];$page=$a['page']??1;
  if($area==='catalog'){$data=$s->catalog();$groups=$data['groups'];$data['groups']=array_map(static fn($g)=>array_intersect_key($g,array_flip(['id','name','smart','description'])),array_slice($groups,($page-1)*8,8));$data['hasMore']=$page*8<count($groups);}
  elseif($area==='draft')$data=$this->draft($a['id']??'');
  elseif($area==='audience')$data=$this->audience($this->draft($a['id']??''));
  elseif($area==='delivery'){$v=$this->delivery();$history=$v['history']??[];$data=$v;$data['history']=array_slice($history,($page-1)*8,8);$data['hasMore']=$page*8<count($history);}
  elseif($area==='automations')$data=$s->automations();
  elseif($area==='sources'){$v=Native::native($s->native_read(['view'=>'sources','type'=>$a['type']??'article','search'=>$a['search']??'']));$data=['items'=>array_slice($v['items'],($page-1)*8,8),'hasMore'=>$page*8<count($v['items']),'coverage'=>'Native source search is bounded to its latest twenty matches. Refine search for another source.'];}
  elseif($area==='drafts'){$v=Native::native($s->native_read(['search'=>$a['search']??'']));$data=['items'=>array_map(static fn($d)=>array_intersect_key($d,array_flip(['id','name','type','status','created','updated','expected'])),array_slice($v['items'],($page-1)*8,8)),'hasMore'=>$page*8<count($v['items'])];}
  else throw new \RuntimeException('Choose a described campaign read.');
  return['resultJson'=>Native::json(['page'=>$page,'data'=>$data,'guidance'=>'Contacts and targets remain private. A draft is not permission to send. Native consent, verified contacts/enrolled browsers, current source and provider readiness govern delivery. No Google/Meta account or ad spend is connected.'],24000)];
 }
 private function fields(array $a):array{return Native::object($a['fieldsJson'],['name','type','sourceId','group','channels','copies','scheduledAt']);}
 private function policy(array $a):array{
  $s=$this->service();$before=$s->automations()[$a['kind']]??null;
  if(!$before||empty($before['canEdit']))throw new \RuntimeException('This automation requires its active Store and current manager role.');
  $f=Native::object($a['fieldsJson'],array_keys($before['fields']));
  return['before'=>$before,'fields'=>$f,'body'=>['operation'=>$a['kind']==='cart'?'automation':'order-automation','fields'=>$f,'expected'=>$before['expected'],'confirmed'=>true]];
 }
 public function prepare(string $kind,array $a):array{
  $s=$this->service();
  if($kind==='save'){$v=Native::native($s->native_draft_plan(['id'=>$a['id'],...$this->fields($a)]));return Native::plan('Save this agreed private campaign draft',$v['expected'],$v['before'],$v['after']);}
  if($kind==='queue'){
   $d=$this->draft($a['id']);if($d['status']!=='draft')throw new \RuntimeException('This campaign is already queued or completed. Read its receipt.');
   $p=$this->audience($d);$delivery=$this->delivery();
   if(empty($p['enabled'])||empty($p['count'])||!empty($p['limited'])||!empty($delivery['active']))throw new \RuntimeException('No campaign was queued. Review consent, provider readiness, the audience limit and any current delivery.');
   $display=array_intersect_key($d,array_flip(['name','type','sourceId','group','channels','scheduledAt']));$display['copies']=$p['copies'];
   return self::review('Queue this exact campaign for its currently reachable opted-in audience',Native::digest([$d['expected'],$p['expected'],$delivery]),['audience'=>array_intersect_key($p,array_flip(['count','registered','anonymous','counts','excluded']))],$display);
  }
  if($kind==='control'){
   $state=$this->delivery();$active=$state['active']??null;
   if(!$active||($active['id']??'')!==$a['id']||empty($active['expected']))throw new \RuntimeException('Choose the current native campaign job.');
   return self::review('Review '.$a['action'].' of the current campaign',Native::digest($state),$active,['action'=>$a['action'],'jobId'=>$a['id'],'notice'=>'Earlier or uncertain attempts are never replayed. Resume may deliver remaining messages.']);
  }
  if($kind==='automation'){$v=$this->policy($a);return self::review('Review the prospective native '.$a['kind'].' delivery policy',$v['before']['expected'],$v['before']['fields'],$v['fields']);}
  throw new \RuntimeException('Choose a described campaign task.');
 }
 public function apply(string $kind,array $a,string $expected):array{
  return Native::mutate('campaigns',function()use($kind,$a,$expected){
   if(!hash_equals($this->prepare($kind,$a)['nativeExpected'],$expected))throw new \RuntimeException('The campaign, source, consent-qualified audience or delivery state changed. Prepare a fresh review.');
   $s=$this->service();
   if($kind==='save'){$before=$a['id']?$this->draft($a['id']):null;$body=['operation'=>'save','id'=>$a['id'],...$this->fields($a)];if($before)$body['expected']=$before['expected'];$v=Native::native($s->native_write($body));$result=array_intersect_key($v,array_flip(['id','name','status','expected']));}
   elseif($kind==='queue'){$d=$this->draft($a['id']);$p=$this->audience($d);$v=Native::native($s->native_write(['operation'=>'queue','id'=>$d['id'],'expected'=>$d['expected'],'audienceExpected'=>$p['expected'],'confirmed'=>true]));if(empty($v['queued'])||empty($v['job']['id'])||($v['draft']['status']??'')!=='queued'||($v['draft']['jobId']??'')!==$v['job']['id'])throw new \RuntimeException('Delivery may already be queued. Read native delivery status before another action; the draft receipt was not confirmed.');$result=['queued'=>true,'job'=>$v['job'],'draftId'=>$d['id']];}
   elseif($kind==='control'){$state=$this->delivery();$v=Native::native($s->native_write(['operation'=>$a['action'],'jobId'=>$a['id'],'expected'=>$state['active']['expected']]));$result=['action'=>$a['action'],'receipt'=>$v];}
   elseif($kind==='automation'){$v=$this->policy($a);$after=Native::native($s->native_write($v['body']));$result=['saved'=>true,'kind'=>$a['kind'],'expected'=>$after[$a['kind']]['expected']];}
   else throw new \RuntimeException('Choose a described campaign task.');
   return['resultJson'=>Native::json($result)];
  });
 }
}
