<?php
/** Bounded, CLI-only native authoring operation. Render a private exact target config before upload. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$c=json_decode(base64_decode('__KIWE_IDENTITY_BINDING_CONFIG__'),true,512,JSON_THROW_ON_ERROR);
if(realpath(__DIR__)!==$c['executionRoot']||basename(__FILE__)!==$c['helper']||$c['domain']!=='dharwadpedha.com'||$c['root']!=='/home/u406734797/domains/dharwadpedha.com/public_html'||dirname($c['private'])!==dirname($c['root']))exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');set_time_limit(100);
$proof=['schema'=>'kiwe.native-bricks-identity-binding.v1','domain'=>$c['domain'],'checks'=>[],'passed'=>false,'cleanup'=>false];$lock=null;$written=[];$plan=[];
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
function kib_write($file,$value){if(file_exists($file)||is_link($file))throw new RuntimeException('Fresh private receipt required');file_put_contents($file,json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);chmod($file,0600);}
function kib_hash($value){return hash('sha256',serialize($value));}
function kib_snapshot($id,$key){$meta=get_post_meta($id);unset($meta[$key]);return ['post'=>kib_hash(get_post($id)),'otherMeta'=>kib_hash($meta),'data'=>Bricks\Database::get_data($id,in_array($key,[BRICKS_DB_PAGE_HEADER,BRICKS_DB_PAGE_FOOTER],true)?($key===BRICKS_DB_PAGE_HEADER?'header':'footer'):'content')];}
function kib_globals(){global$wpdb;$out=[];$rows=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN ('dsa_settings','active_plugins','kiwe_access_policy_v1','kiwe_tag_groups_v1','kiwe_seam_design_context_v1','kiwe_site_logo_id','kiwe_site_logo_inverse_id','kiwe_store_phone','kiwe_store_email','template','stylesheet') OR option_name LIKE 'bricks\\_%' OR option_name LIKE 'woocommerce\\_%' ORDER BY option_name",ARRAY_A);if(!is_array($rows)||$wpdb->last_error!=='')throw new RuntimeException('Native control read failed');foreach($rows as$r)$out[$r['option_name']]=hash('sha256',$r['option_value']);return$out;}
function kib_bind_text($value,$field,$hints){
 if(!is_string($value)||!in_array($field,['text','url'],true))return$value;
 $map=[];foreach($hints['links']as$from=>$tag)if($from!=='')$map[$from]=$tag;
 foreach($hints['text']as$from=>$tag)if($from!=='')$map[$from]=$tag;
 if(!empty($hints['address']['line1'])&&str_contains($value,$hints['address']['line1']))foreach($hints['address']as$key=>$from)if($from!=='')$map[$from]='{kiwe_store_'.(['line1'=>'address_1','line2'=>'address_2'][$key]??$key).'}';
 return strtr($value,$map);
}
try{
 if(file_exists($c['private'].'/receipt.json')){echo file_get_contents($c['private'].'/receipt.json');exit;}
 $assert(!is_link($c['private'])&&!file_exists($c['private']),'fresh unserved custody root');$assert(mkdir($c['private'],0700),'private authoring custody created');
 $lock=fopen($c['private'].'/owner.lock','c+b');$assert($lock&&flock($lock,LOCK_EX|LOCK_NB),'exclusive authoring owner');
 define('WP_USE_THEMES',false);$_SERVER['HTTP_HOST']=$c['domain'];$_SERVER['SERVER_NAME']=$c['domain'];$_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/';$_SERVER['REMOTE_ADDR']='127.0.0.1';ob_start();require$c['root'].'/wp-load.php';ob_end_clean();wp_set_current_user(1);
 $assert(DSA_VERSION===$c['version']&&DSA\Access\Site_Owner_Service::is_owner(),'matching native release and existing owner');$assert(hash_file('sha256',$c['root'].'/wp-config.php')===$c['configHash'],'literal native configuration retained');
 $controls=kib_globals();$before=[];$logos=0;$changes=[];
 foreach($c['ids']as$id){$post=get_post($id);$assert($post&&in_array($post->post_type,['page','bricks_template'],true),'allowlisted native layout '.$id);$area='content';if($post->post_type==='bricks_template'){$type=get_post_meta($id,BRICKS_DB_TEMPLATE_TYPE,true);if(in_array($type,['header','footer'],true))$area=$type;}$key=Bricks\Database::get_bricks_data_key($area);$snapshot=kib_snapshot($id,$key);$data=$snapshot['data'];if(!is_array($data)||!$data)continue;$before[$id]=['key'=>$key,'area'=>$area,'snapshot'=>$snapshot,'allMeta'=>get_post_meta($id)];$after=$data;
  foreach($after as&$element){$settings=$element['settings']??[];$old=$settings;
   if(($element['name']??'')==='image'&&is_array($settings['image']??null)&&str_contains((string)($settings['image']['url']??''),$c['logoFile'])){$settings['image']=['useDynamicData'=>'{kiwe_site_logo}','size'=>'full'];$settings['altText']='{site_title}';$logos++;}
   if(isset($settings['text']))$settings['text']=kib_bind_text($settings['text'],'text',$c['hints']);
   if(is_array($settings['link']??null)&&isset($settings['link']['url']))$settings['link']['url']=kib_bind_text($settings['link']['url'],'url',$c['hints']);
   if($settings!==$old){$allowed=['image','altText','text','link'];$oldRest=array_diff_key($old,array_flip($allowed));$newRest=array_diff_key($settings,array_flip($allowed));$assert($oldRest===$newRest,'styles and unrelated element choices retained '.$id.'/'.$element['id']);$changes[]=['postId'=>$id,'area'=>$area,'elementId'=>$element['id'],'fields'=>array_values(array_filter($allowed,static fn($field)=>($old[$field]??null)!==($settings[$field]??null)))];$element['settings']=$settings;}
  }unset($element);if($after!==$data)$plan[$id]=['key'=>$key,'area'=>$area,'beforeHash'=>kib_hash($data),'after'=>$after];
 }
 $assert($logos===2&&isset($plan[18300],$plan[18299]),'exact published header and footer logos identified');$assert(count($changes)>=10&&count($changes)<=100,'bounded native contact and identity changes');
 kib_write($c['private'].'/original.json',$before);kib_write($c['private'].'/controls-before.json',$controls);kib_write($c['private'].'/plan.json',$plan);$proof['changes']=$changes;
 foreach($plan as$id=>$entry){$actual=kib_snapshot($id,$entry['key']);$assert(kib_hash($actual['data'])===$entry['beforeHash']&&$actual['post']===$before[$id]['snapshot']['post']&&$actual['otherMeta']===$before[$id]['snapshot']['otherMeta'],'compare original authoring before write '.$id);}
 foreach($plan as$id=>$entry){update_post_meta($id,$entry['key'],wp_slash($entry['after']));$written[]=$id;clean_post_cache($id);$after=kib_snapshot($id,$entry['key']);$assert($after['data']===$entry['after'],'exact native dynamic bindings saved '.$id);$assert($after['post']===$before[$id]['snapshot']['post']&&$after['otherMeta']===$before[$id]['snapshot']['otherMeta'],'native post and all other metadata preserved '.$id);}
 $assert(kib_globals()===$controls,'native settings, global classes, conditions and business choices preserved');
 $logoId=DSA\Site\Site_Identity_Service::attachment_id(DSA\Site\Site_Identity_Service::OPTION_LOGO);$assert($logoId>0&&wp_attachment_is_image($logoId),'native dashboard logo remains an actual attachment');
 foreach([18300=>'header',18299=>'footer']as$id=>$area){$found=[];foreach(Bricks\Database::get_data($id,$area)as$element)if(($element['settings']['image']['useDynamicData']??'')==='{kiwe_site_logo}'){$image=new Bricks\Element_Image($element);$native=$image->get_normalized_image_settings($element['settings']);$assert(($native['id']??0)===$logoId,'actual native image control resolves dashboard logo '.$id);$found[]=$element['id'];}$assert(count($found)===1,'one bound native logo '.$area);$proof['builderLinks'][$area]=Bricks\Helpers::get_builder_edit_link($id);}
 do_action('litespeed_purge_all');$proof['version']=DSA_VERSION;$proof['layoutsChanged']=count($plan);$proof['logoBindings']=$logos;$proof['passed']=true;$proof['cleanup']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();$proof['reversal']=[];foreach(array_reverse($written)as$id){$entry=$plan[$id];$current=Bricks\Database::get_data($id,$entry['area']);if($current===$entry['after']){update_post_meta($id,$entry['key'],wp_slash($before[$id]['snapshot']['data']));clean_post_cache($id);$proof['reversal'][$id]=Bricks\Database::get_data($id,$entry['area'])===$before[$id]['snapshot']['data'];}else$proof['reversal'][$id]=false;}if($written)do_action('litespeed_purge_all');}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}if($proof['passed'])$proof['helperRetired']=unlink(__FILE__);if(is_dir($c['private'])&&!file_exists($c['private'].'/receipt.json'))kib_write($c['private'].'/receipt.json',$proof);}
echo json_encode($proof,JSON_UNESCAPED_SLASHES);exit($proof['passed']?0:1);
