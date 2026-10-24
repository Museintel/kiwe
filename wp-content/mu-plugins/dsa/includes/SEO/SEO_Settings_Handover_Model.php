<?php
namespace DSA\SEO;
if(!defined('ABSPATH'))exit;

/** Closed stored-setting mapping. A partial copy never certifies plugin removal. */
final class SEO_Settings_Handover_Model {
 public static function review(array $groups,array $types,array $taxonomies): array {
  $out=[];$mapped=[];$blocked=[];$handled=[];
  $put=static function(string $group,string $key,array $path,string $type)use(&$out,&$mapped,&$blocked,&$handled,$groups):void{
   if(!array_key_exists($key,$groups[$group]??[]))return;
   $handled[$group][$key]=true;$value=$groups[$group][$key];$valid=true;
   if($type==='text'){$valid=is_string($value)&&strlen($value)<=1000;if($valid){$value=strtr($value,['%separator%'=>'%sep%','%sitedesc%'=>'%tagline%']);$valid=SEO_Defaults_Model::valid_template($value)&&!str_contains($value,'%page%');}}
   elseif($type==='bool'){$valid=in_array($value,['on','off',true,false,1,0,'1','0'],true);$value=in_array($value,['on',true,1,'1'],true);}
   elseif($type==='robots'){$value=SEO_Defaults_Model::validate_robots($value);$valid=$value!==false;}
   elseif($type==='ids'){$valid=is_string($value)&&strlen($value)<=8000&&preg_match('/^\s*(?:[1-9][0-9]{0,9}\s*(?:,\s*[1-9][0-9]{0,9}\s*)*)?$/D',$value);$value=$valid&&trim($value)!==''?array_values(array_unique(array_map('intval',explode(',',$value)))):[];$valid=$valid&&count($value)<=1000;}
   elseif($type==='size'){$valid=(is_int($value)||is_string($value)&&preg_match('/^[0-9]{2,4}$/D',$value))&&(int)$value>=50&&(int)$value<=1000;$value=(int)$value;}
   elseif($type==='url'){$valid=is_string($value)&&($value===''||SEO_Defaults_Model::url($value)!=='');}
   elseif($type==='verification'){$valid=SEO_Defaults_Model::verification_code($path[1],$value);}
   elseif($type==='card'){$valid=in_array($value,['summary','summary_large_image'],true);}
   if(!$valid){$blocked[]=$group.'.'.$key;return;}
   $cursor=&$out;foreach($path as$part){if(!isset($cursor[$part]))$cursor[$part]=[];$cursor=&$cursor[$part];}$cursor=$value;unset($cursor);$mapped[]=$group.'.'.$key;
  };
  foreach(['title_separator'=>[['separator'],'text'],'robots_global'=>[['globalRobots'],'robots'],'open_graph_image'=>[['social','image'],'url'],'twitter_card_type'=>[['social','twitterCard'],'card']]as$key=>[$path,$type])$put('titles',$key,$path,$type);
  foreach(['title','description','facebook_title','facebook_description','facebook_image']as$field)$put('titles','homepage_'.$field,['home',self::field($field)],$field==='facebook_image'?'url':'text');
  foreach(['postTypes'=>$types,'taxonomies'=>$taxonomies]as$scope=>$names){
   if(count($names)>32)throw new \InvalidArgumentException('Too many public SEO scopes.');
   foreach($names as$name){if(!is_string($name)||!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$name))throw new \InvalidArgumentException('Invalid public SEO scope.');$prefix=($scope==='postTypes'?'pt_':'tax_').$name.'_';
    foreach(['title','description','facebook_image']as$field)$put('titles',$prefix.$field,[$scope,$name,self::field($field)],$field==='facebook_image'?'url':'text');
    $toggle=$groups['titles'][$prefix.'custom_robots']??'off';
    if(array_key_exists($prefix.'custom_robots',$groups['titles']??[])){if(!in_array($toggle,['on','off'],true))$blocked[]='titles.'.$prefix.'custom_robots';$handled['titles'][$prefix.'custom_robots']=true;}
    if($toggle==='on')$put('titles',$prefix.'robots',[$scope,$name,'robots'],'robots');elseif($toggle==='off')$handled['titles'][$prefix.'robots']=true;
    $put('sitemap',($scope==='postTypes'?'pt_':'tax_').$name.'_sitemap',[$scope,$name,'sitemap'],'bool');
   }
  }
  foreach(['author','date']as$scope)foreach(['title','description']as$field)$put('titles',$scope.'_archive_'.$field,['archives',$scope,$field],'text');
  foreach(['disable_date_archives'=>'redirectDateArchives','disable_author_archives'=>'redirectAuthorArchives','noindex_empty_taxonomies'=>'noindexEmptyTerms']as$key=>$native)$put('titles',$key,['policies',$native],'bool');
  $put('general','attachment_redirect_urls',['policies','redirectAttachments'],'bool');
  $put('general','attachment_redirect_default',['policies','attachmentFallback'],'url');
  foreach(['author','date']as$scope){
   $custom=$scope==='date'?'date_archive_custom_robots':'author_custom_robots';$robotsKey=$scope==='date'?'date_archive_robots':'author_robots';$toggle=$groups['titles'][$custom]??($scope==='date'?'on':'off');
   if(array_key_exists($custom,$groups['titles']??[])){if(!in_array($toggle,['on','off'],true))$blocked[]='titles.'.$custom;$handled['titles'][$custom]=true;}
   if($toggle==='on')$put('titles',$robotsKey,['archives',$scope,'robots'],'robots');elseif($toggle==='off')$handled['titles'][$robotsKey]=true;
  }
  foreach(['items_per_page'=>[['sitemap','pageSize'],'size'],'authors_sitemap'=>[['sitemap','authors'],'bool'],'exclude_posts'=>[['sitemap','excludePosts'],'ids'],'exclude_terms'=>[['sitemap','excludeTerms'],'ids']]as$key=>[$path,$type])$put('sitemap',$key,$path,$type);
  foreach(['google_verify'=>'google','bing_verify'=>'bing','pinterest_verify'=>'pinterest']as$key=>$provider)$put('general',$key,['verification',$provider],'verification');
  foreach($groups as$group=>$values){if(!is_array($values)||count($values)>512)throw new \InvalidArgumentException('Oversized source settings.');foreach($values as$key=>$value){if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$key))throw new \InvalidArgumentException('Invalid source field.');if(!isset($handled[$group][$key])&&$value!==''&&$value!==false&&$value!==null&&$value!==[]&&$value!=='off'&&$value!==0&&$value!=='0')$blocked[]=$group.'.'.$key;}}
  if(SEO_Defaults_Model::validate($out)===false)throw new \InvalidArgumentException('Unsupported mapped defaults.');
  sort($mapped);$blocked=array_values(array_unique($blocked));sort($blocked);
  return ['settings'=>$out,'mappedFields'=>$mapped,'blockedFields'=>$blocked,'manualReview'=>['Implicit provider defaults and pagination','Schema graphs and generated schema','Redirect rules and sitemap URLs','Stored search history and provider connections','Native rendered output before plugin removal'],'providerRemovalReady'=>false];
 }
 private static function field(string $key): string{return ['facebook_title'=>'facebookTitle','facebook_description'=>'facebookDescription','facebook_image'=>'facebookImage'][$key]??$key;}
}
