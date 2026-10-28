<?php
namespace DSA\Apps;

if (!defined('ABSPATH')) exit;

/** Explicit administration/native update work only; visitor capability checks never instantiate this owner. */
final class Catalogue_Service {
 public const OPTION='kiwe_apps_catalogue_v2';
 public const TTL=21600;
 public const MAX_BYTES=65536;
 private ?array $cached=null;

 public function snapshot(): array {
  if($this->cached!==null)return$this->cached;
  $stored=get_option(self::OPTION,[]);$packet=is_array($stored)?($stored['catalogue']??[]):[];
  $valid=is_array($packet)&&Signed_Packet::catalogue($packet);
  return$this->cached=['available'=>$valid,'catalogue'=>$valid?$packet:null,'checkedAt'=>is_array($stored)?(int)($stored['checkedAt']??0):0,'message'=>is_array($stored)&&is_string($stored['message']??null)?$stored['message']:($valid?'':'Check available Apps before installing.')];
 }
 public function refresh(bool $force=false): array {
  if(!self::maintenance_context())throw new \RuntimeException('App checks run only in website administration or native scheduled updates.');
  $existing=$this->snapshot();if(!$force&&$existing['available']&&time()-$existing['checkedAt']<self::TTL)return$existing;
  $url=Signed_Packet::ORIGIN.'/apps/v1/channels/workspace-stable.json';
  // A candidate URL is admitted only on the original disposable lab, never by a visitor or remote manifest.
  if(defined('KIWE_APPS_CANDIDATE_CATALOGUE')&&KIWE_APPS_CANDIDATE_CATALOGUE===true&&home_url()==='https://plum-giraffe-539524.hostingersite.com')$url=Signed_Packet::ORIGIN.'/apps/v1/channels/workspace-candidate.json';
  $response=wp_safe_remote_get($url,['timeout'=>12,'redirection'=>0,'limit_response_size'=>self::MAX_BYTES+1,'headers'=>['Accept'=>'application/json'],'user-agent'=>'Kiwe-Apps/'.Runtime_Contract::VERSION]);
  $message='';$packet=null;
  if(is_wp_error($response))$message='App releases could not be reached. Installed Apps continue working.';
  elseif((int)wp_remote_retrieve_response_code($response)!==200)$message='App releases are temporarily unavailable. Retry later.';
  else{
   $body=(string)wp_remote_retrieve_body($response);
   if(strlen($body)>self::MAX_BYTES)$message='The App catalogue is too large to verify.';
   else{try{$decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR);if(!is_array($decoded)||!Signed_Packet::catalogue($decoded))$message='The App catalogue could not be authenticated.';else$packet=$decoded;}catch(\Throwable){$message='The App catalogue is not a valid release packet.';}}
  }
  if($packet!==null){
   $prior=get_option(self::OPTION,[]);$previous=is_array($prior)?($prior['catalogue']??null):null;
   if(is_array($previous)&&Signed_Packet::catalogue($previous,(int)strtotime((string)($previous['publishedAt']??''))+1)
    && (strtotime($packet['publishedAt'])<strtotime($previous['publishedAt'])
     || ($packet['publishedAt']===$previous['publishedAt']&&Signed_Packet::canonical($packet)!==Signed_Packet::canonical($previous)))){
    $packet=null;$message='An older or conflicting App catalogue was refused. The last authenticated release stays available.';
   }
  }
  if($packet!==null){$stored=['catalogue'=>$packet,'checkedAt'=>time(),'message'=>''];}
  else{$prior=get_option(self::OPTION,[]);$stored=is_array($prior)?$prior:[];$stored['message']=$message;$stored['checkedAt']=time();}
  update_option(self::OPTION,$stored,false);$this->cached=null;return$this->snapshot();
 }
 public function release(string $id): array {
  if(!in_array($id,Runtime_Contract::IDS,true))throw new \InvalidArgumentException('Choose an available Kiwe App.');
  $state=$this->snapshot();$release=$state['catalogue']['apps'][$id]??null;
  if(!$state['available']||!is_array($release)||in_array($id.'@'.$release['version'],$state['catalogue']['revoked'],true))throw new \RuntimeException('A current authenticated release is required. Refresh available Apps.');
  return$release;
 }
 public static function maintenance_context(): bool {
  return (function_exists('is_admin')&&is_admin())||(function_exists('wp_doing_cron')&&wp_doing_cron())||(defined('WP_CLI')&&WP_CLI===true)||(defined('REST_REQUEST')&&REST_REQUEST===true&&Native_State::can_review());
 }
}
