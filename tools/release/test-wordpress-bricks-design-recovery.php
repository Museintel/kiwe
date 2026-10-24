<?php
/** Native WordPress/Bricks, only inside the self-cleaning lab replicas. */
if ( PHP_SAPI !== 'cli' || ! defined('KIWE_WORKSPACE_TEST_SITE') || ! KIWE_WORKSPACE_TEST_SITE || empty($GLOBALS['kiwe_media_tables_isolated']) ) exit(2);
use DSA\Backup\Bricks_Design_Snapshot_Service as Design;
use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
use DSA\Backup\Content_Archive_Job_Service as ContentJobs;
use DSA\Backup\Content_Archive_Service as Content;
use DSA\Backup\Media_Archive_Job_Service as MediaJobs;
use DSA\Backup\Archive_Job_Files as Files;
use DSA\Backup\Archive_Cipher as Cipher;
$proof=['schema'=>'kiwe-native-bricks-design-recovery.v1','kiwe'=>DSA_VERSION,'bricks'=>BRICKS_VERSION,'wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'checks'=>[],'cleanup'=>false];$GLOBALS['kiwe_media_proof']=&$proof;
$assert=static function(bool $ok,string $label)use(&$proof):void{$proof['checks'][]=['label'=>$label,'passed'=>$ok];if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable $action,string $contains=''):bool{try{$action();return false;}catch(Throwable $error){return !$contains||str_contains($error->getMessage(),$contains);}};
$must=static function($value){if(is_wp_error($value)||!$value)throw new RuntimeException(is_wp_error($value)?$value->get_error_message():'Native fixture unavailable');return $value;};
$phrase='Synthetic native design recovery 2026';$content=new ContentJobs();$media=new MediaJobs();$snapshots=new Snapshots();$jobs=[];$reviews=[];$filters=[];$before=[];$actor=get_current_user_id();
$track=static function(array $job,string $kind)use(&$jobs):array{$jobs[]=[$job['id'],$kind];return $job;};
$advance=static function($service,array $job,string $status,bool $confirm=false)use($phrase):array{for($i=0;$i<120&&$job['status']!==$status;$i++)$job=$service->step($job['id'],$phrase,$job['revision'],$confirm);if($job['status']!==$status)throw new RuntimeException('Native recovery did not reach '.$status);return $job;};
$review=static function(string $bytes,string $mediaId='',string $templateId='')use($snapshots,$phrase,&$reviews):array{$value=$snapshots->preview_upload($bytes,$phrase,'',$mediaId,$templateId);$reviews[]=$value['token'];return $value;};
try{
 wp_set_current_user(78);$before=array_column($snapshots->inventory(),'id');
 $assert(!function_exists('WC')&&Design::allowed()&&Content::can_import('templates'),'real non-Woo client has native design and template recovery authority');
 $root=Snapshots::private_directory(true).'/media-qa-'.bin2hex(random_bytes(12));wp_mkdir_p($root);$GLOBALS['kiwe_media_fixture_root']=$root;
 $uploads=static fn()=>['path'=>$root,'basedir'=>$root,'url'=>home_url('/synthetic-design-recovery'),'baseurl'=>home_url('/synthetic-design-recovery'),'subdir'=>'','error'=>false];add_filter('upload_dir',$uploads,PHP_INT_MAX);$filters[]=['upload_dir',$uploads];
 file_put_contents($root.'/synthetic-image.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jF1kAAAAASUVORK5CYII='));
 $image=(int)$must(wp_insert_attachment(['post_title'=>'Synthetic design image','post_status'=>'inherit','post_mime_type'=>'image/png','post_author'=>78],$root.'/synthetic-image.png',0,true));
 update_post_meta($image,'_wp_attachment_metadata',['width'=>1,'height'=>1,'file'=>'synthetic-image.png','sizes'=>[]]);$imageUrl=wp_get_attachment_url($image);$imageHash=DSA\Backup\Media_Archive_Service::fingerprint($image);
 $nativeFont=get_template_directory().'/assets/fonts/builder/Inter.woff2';$must(copy($nativeFont,$root.'/synthetic-font.woff2'));
 $fontFile=(int)$must(wp_insert_attachment(['post_title'=>'Synthetic nested font file','post_status'=>'inherit','post_mime_type'=>'font/woff2','post_author'=>78],$root.'/synthetic-font.woff2',0,true));
 $font=(int)$must(wp_insert_post(['post_type'=>BRICKS_DB_CUSTOM_FONTS,'post_title'=>'Synthetic nested font','post_status'=>'publish','post_author'=>78],true));update_post_meta($font,BRICKS_DB_CUSTOM_FONT_FACES,['400normal'=>['woff2'=>$fontFile]]);
 $ids=[];
 for($i=0;$i<11;$i++){
  $id=(int)$must(wp_insert_post(['post_type'=>BRICKS_DB_TEMPLATE_SLUG,'post_title'=>'Synthetic recovery template '.$i,'post_status'=>'publish','post_author'=>78,'post_date'=>'2026-08-20 12:00:00','post_date_gmt'=>'2026-08-20 12:00:00'],true));$ids[]=$id;
  update_post_meta($id,BRICKS_DB_TEMPLATE_TYPE,$i===9?'popup':'section');
  update_post_meta($id,BRICKS_DB_TEMPLATE_SETTINGS,['templateConditions'=>[['main'=>'any']],'_typography'=>['fontFamily'=>'custom_font_'.$font]]);
  update_post_meta($id,BRICKS_DB_PAGE_CONTENT,[['id'=>'qa'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'name'=>'text-basic','parent'=>0,'children'=>[],'settings'=>['text'=>'Synthetic native template '.$i]]]);
 }
 $body=[['id'=>'qatmpl','name'=>'template','parent'=>0,'children'=>[],'settings'=>['template'=>(string)$ids[10]]],['id'=>'qapop0','name'=>'block','parent'=>0,'children'=>[],'settings'=>['_interactions'=>[['target'=>'popup','action'=>'show','templateId'=>$ids[9]]],'image'=>['id'=>(string)$image,'url'=>$imageUrl.'?v=2','full'=>$imageUrl,'size'=>'full']]]];
 update_post_meta($ids[0],BRICKS_DB_PAGE_CONTENT,$body);$originals=[];foreach($ids as$id)$originals[$id]=Content::template_fingerprint($id);
 foreach(Design::names()as$name)update_option($name,[]);
 $component=[['id'=>'qacmp0','label'=>'Synthetic dependency component','elements'=>[['id'=>'qacmp0','name'=>'block','parent'=>0,'children'=>[],'settings'=>['template'=>$ids[0],'image'=>['id'=>$image,'url'=>$imageUrl,'full'=>$imageUrl,'size'=>'full'],'text'=>$imageUrl]]]]];update_option(BRICKS_DB_COMPONENTS,$component);
 update_option(BRICKS_DB_GLOBAL_CLASSES_TRASH,[['id'=>'oldqa0','name'=>'Inactive','settings'=>['image'=>['id'=>$image,'full'=>$imageUrl],'fontFamily'=>'custom_font_'.$font,'template'=>$ids[0]]]]);
 $copy=$snapshots->create($phrase,'Synthetic design dependency recovery',[Design::GROUP]);$bytes=file_get_contents($snapshots->download_path($copy['id']));$payload=Cipher::open($bytes,Snapshots::SCHEMA,$phrase,Snapshots::MAX_FILE);
 $initial=$review($bytes);$assert($initial['designDependencies']===['media'=>1,'fonts'=>1,'templates'=>3],'nested templates and popup interactions include their native media and font dependencies');
 $bad=$payload['options'];$bad[BRICKS_DB_COMPONENTS]['value'][0]['elements'][0]['settings']['image']=['id'=>$image,'url'=>$imageUrl];$assert(Design::validate($bad)['media']===1,'native URL-only image descriptors participate in dependency review');
 $changed=$body;$changed[0]['settings']['template']=$ids[0];update_post_meta($ids[0],BRICKS_DB_PAGE_CONTENT,$changed);$assert($reject(fn()=>Design::validate($payload['options']),'Circular'),'native template self-cycle refused');update_post_meta($ids[0],BRICKS_DB_PAGE_CONTENT,$body);
 $changed=$body;$changed[0]['settings']['template']=0;$changed[0]['cid']='qacmp0';update_post_meta($ids[0],BRICKS_DB_PAGE_CONTENT,$changed);$assert($reject(fn()=>Design::validate($payload['options']),'Circular'),'native template to component cycle refused');update_post_meta($ids[0],BRICKS_DB_PAGE_CONTENT,$body);
 $changed=$body;$changed[0]['name']='code';update_post_meta($ids[0],BRICKS_DB_PAGE_CONTENT,$changed);$assert($reject(fn()=>Design::validate($payload['options']),'Executable'),'nested executable template requires separate review');update_post_meta($ids[0],BRICKS_DB_PAGE_CONTENT,$body);
 $export=$track($media->start_export($image,$phrase,[],wp_generate_uuid4()),'media');$export=$advance($media,$export,'ready');$mediaPath=$media->download_path($export['id']);
 $import=$track($media->start_import($mediaPath,$phrase,wp_generate_uuid4()),'media');$import=$advance($media,$import,'review');$import=$advance($media,$import,'complete',true);$mediaMap=$media->recovery_map($import['id'],true);$recoveredImage=$mediaMap['map'][$image]['id'];
	$assert($mediaMap['scope']==='attachment'&&$recoveredImage!==$image&&$imageHash===DSA\Backup\Media_Archive_Service::fingerprint($image),'completed native media map preserves the original attachment');
 $templateExport=$track($content->start_export('templates',0,$phrase,['from'=>'2026-08-20','to'=>'2026-08-20'],wp_generate_uuid4()),'content');$templateExport=$advance($content,$templateExport,'ready');$templatePath=$content->download_path($templateExport['id']);
 $templateImport=$track($content->start_import($templatePath,$phrase,'templates',wp_generate_uuid4(),$import['id']),'content');
 $assert($reject(fn()=>$content->recovery_map($templateImport['id'])),'unfinished template job cannot provide a recovery map');
 $templateImport=$advance($content,$templateImport,'review');$templateImport=$advance($content,$templateImport,'complete',true);$map=$content->recovery_map($templateImport['id']);
 $assert(count($map['map'])===11&&$templateImport['templateRecoveryAvailable'],'completed collection offers an actor-owned template map across multiple frames');
 $first=$map['map'][$ids[0]]['id'];$last=$map['map'][$ids[10]]['id'];$popup=$map['map'][$ids[9]]['id'];$newBody=get_post_meta($first,BRICKS_DB_PAGE_CONTENT,true);
 $assert($newBody[0]['settings']['template']===(string)$last&&$newBody[1]['settings']['_interactions'][0]['templateId']===$popup,'native finalization reconnects cross-part templates and popup references in copies');
 $assert($newBody[1]['settings']['image']['id']===(string)$recoveredImage&&str_contains($newBody[1]['settings']['image']['url'],'?v=2'),'template draft keeps its selected recovered media and URL suffix');
 $assert(!isset(get_post_meta($first,BRICKS_DB_TEMPLATE_SETTINGS,true)['templateConditions'])&&get_post($first)->post_status==='draft','native clone policy retains drafts and removes placement conditions');
 $assert($reject(fn()=>$review($bytes,$import['id'],$templateImport['id']),'publish'),'live design refuses mapped drafts before writes');
 $must(wp_update_post(['ID'=>$first,'post_status'=>'publish'],true));$assert($content->recovery_map($templateImport['id'])['map'][$ids[0]]['status']==='publish','reviewed native publication is allowed without changing authored copy data');
 $assert($reject(fn()=>$review($bytes,$import['id'],$templateImport['id']),'nested'),'published parent cannot conceal referenced nested draft copies');
 foreach([$last,$popup]as$id)$must(wp_update_post(['ID'=>$id,'post_status'=>'publish'],true));$map=$content->recovery_map($templateImport['id']);
 $ready=$review($bytes,$import['id'],$templateImport['id']);$assert($ready['recoveredMedia']===1&&$ready['recoveredTemplates']===11&&$ready['designDependencies']===['media'=>1,'fonts'=>1,'templates'=>3],'review counts selected copies and checks only the referenced native dependency graph');
 $restored=$snapshots->apply($ready['token'],$phrase);$newComponent=get_option(BRICKS_DB_COMPONENTS);$settings=$newComponent[0]['elements'][0]['settings'];
 $assert($restored['restored']===1&&$settings['template']===$first&&$settings['image']['id']===$recoveredImage,'native settings writer reconnects only the reviewed component references');
 $assert($settings['text']===$imageUrl&&get_option(BRICKS_DB_GLOBAL_CLASSES_TRASH)===$payload['options'][BRICKS_DB_GLOBAL_CLASSES_TRASH]['value'],'ordinary text and inactive trash stay exact');
 $unchanged=true;foreach($originals as$id=>$hash)$unchanged=$unchanged&&hash_equals($hash,Content::template_fingerprint($id));$assert($unchanged,'original template records and placement conditions remain untouched');
 $rollback=$review(file_get_contents($snapshots->download_path($restored['rollbackId'])));$snapshots->apply($rollback['token'],$phrase);$assert(get_option(BRICKS_DB_COMPONENTS)===$component,'encrypted before-copy restores the former native references');
 $failed=$review($bytes,$import['id'],$templateImport['id']);$lastSettings=get_post_meta($last,BRICKS_DB_TEMPLATE_SETTINGS,true);$injected=false;
 $afterWrite=static function($name,$old,$new)use($last,$first,$lastSettings,&$injected):void{if(!$injected&&$name===BRICKS_DB_COMPONENTS&&($new[0]['elements'][0]['settings']['template']??null)===$first){$injected=true;update_post_meta($last,BRICKS_DB_TEMPLATE_SETTINGS,$lastSettings+['templateConditions'=>[['main'=>'any']]]);}};add_action('updated_option',$afterWrite,10,3);
 $rolledBack=$reject(fn()=>$snapshots->apply($failed['token'],$phrase),'before-restore choices');remove_action('updated_option',$afterWrite,10);
 $assert($injected&&$rolledBack&&get_option(BRICKS_DB_COMPONENTS)===$component,'dependency mutation during native option writing rolls back settings through the retained before-copy');
 $assert(isset(get_post_meta($last,BRICKS_DB_TEMPLATE_SETTINGS,true)['templateConditions']),'rollback preserves the concurrent template edit rather than overwriting it');update_post_meta($last,BRICKS_DB_TEMPLATE_SETTINGS,$lastSettings);
 $stale=$review($bytes,$import['id'],$templateImport['id']);$savedSettings=get_post_meta($last,BRICKS_DB_TEMPLATE_SETTINGS,true);update_post_meta($last,BRICKS_DB_TEMPLATE_SETTINGS,$savedSettings+['templateConditions'=>[['main'=>'any']]]);
 $assert($reject(fn()=>$snapshots->apply($stale['token'],$phrase),'changed')&&get_option(BRICKS_DB_COMPONENTS)===$component,'changed native placement conditions invalidate recovery before any settings write');update_post_meta($last,BRICKS_DB_TEMPLATE_SETTINGS,$savedSettings);
 $stale=$review($bytes,$import['id'],$templateImport['id']);$must(wp_update_post(['ID'=>$popup,'post_status'=>'draft'],true));$assert($reject(fn()=>$snapshots->apply($stale['token'],$phrase),'changed')&&get_option(BRICKS_DB_COMPONENTS)===$component,'publication changes after review invalidate its proof');$must(wp_update_post(['ID'=>$popup,'post_status'=>'publish'],true));
 $stale=$review($bytes,$import['id'],$templateImport['id']);$nativeFontBytes=file_get_contents($root.'/synthetic-font.woff2');file_put_contents($root.'/synthetic-font.woff2','Changed synthetic nested dependency');$assert($reject(fn()=>$snapshots->apply($stale['token'],$phrase),'dependencies changed'),'nested font file changes invalidate settings recovery');file_put_contents($root.'/synthetic-font.woff2',$nativeFontBytes);
 $subscriber=(int)$must(wp_insert_user(['user_login'=>'kiwedesignqa_'.bin2hex(random_bytes(5)),'user_email'=>'synthetic-design-user@example.invalid','user_pass'=>wp_generate_password(40),'role'=>'subscriber']));wp_set_current_user($subscriber);
 $assert($reject(fn()=>$content->recovery_map($templateImport['id']))&&$reject(fn()=>$media->recovery_map($import['id'])),'foreign subscriber cannot inspect either recovery map');wp_set_current_user(78);
 $vault=Snapshots::private_directory();$journalPath=$vault.'/content-job-'.$templateImport['id'].'/info.json';$journal=json_decode(file_get_contents($journalPath),true);$lock=fopen($vault.'/content-job-'.$templateImport['id'].'/lock','c+b');flock($lock,LOCK_EX);$assert($reject(fn()=>$content->recovery_map($templateImport['id'])),'native OS lock prevents concurrent map reads during journal mutation');flock($lock,LOCK_UN);fclose($lock);
 $bad=$journal;$bad['expiresAt']=time()-1;file_put_contents($journalPath,wp_json_encode($bad));$assert($reject(fn()=>$content->recovery_map($templateImport['id'])),'expired template map cannot be reused');file_put_contents($journalPath,wp_json_encode($journal));
 $bad=$journal;unset($bad['templateRecovery']);file_put_contents($journalPath,wp_json_encode($bad));$assert($reject(fn()=>$content->recovery_map($templateImport['id'])),'older completed jobs without an ownership journal require a fresh import');file_put_contents($journalPath,wp_json_encode($journal));
 $stale=$review($bytes,$import['id'],$templateImport['id']);$content->remove($templateImport['id']);$assert($reject(fn()=>$snapshots->apply($stale['token'],$phrase))&&get_post($first)&&get_post($last),'retiring a completed template job expires its map and keeps recovered copies');
 $media->remove($import['id']);$assert(get_post($recoveredImage)&&$reject(fn()=>$media->recovery_map($import['id'])),'media job retirement retains recovered attachment and expires its map');
 $proof['providerRequestsBlocked']=count($GLOBALS['kiwe_media_http']);$proof['mailBlocked']=count($GLOBALS['kiwe_media_mail']);
}catch(Throwable $error){$proof['error']=['message'=>$error->getMessage(),'line'=>$error->getLine(),'trace'=>array_map(static fn($row)=>['file'=>basename($row['file']??''),'line'=>$row['line']??0,'function'=>$row['function']??''],array_slice($error->getTrace(),0,8))];}
finally{
 wp_set_current_user(78);
 foreach($reviews as$token){$value=get_transient('kiwe_backup_review_'.$token);delete_transient('kiwe_backup_review_'.$token);if(is_array($value)&&preg_match('/^[a-f0-9]{24}$/D',$value['id']??''))@unlink(Snapshots::private_directory().'/stage-'.$value['id'].'.kiwe');}
 foreach(array_reverse($jobs)as[$id,$kind]){try{if(is_dir(Snapshots::private_directory().'/'.$kind.'-job-'.$id))('content'===$kind?$content:$media)->remove($id);}catch(Throwable $error){Files::retire(Snapshots::private_directory().'/'.$kind.'-job-'.$id);}}
 foreach($snapshots->inventory()as$copy)if(!in_array($copy['id'],$before,true))$snapshots->remove($copy['id']);
 foreach($filters as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);wp_set_current_user($actor);
 $remaining=[];foreach($jobs as[$id,$kind])if(is_dir(Snapshots::private_directory().'/'.$kind.'-job-'.$id))$remaining[]=$id;
 $proof['cleanup']=!$remaining&&array_column($snapshots->inventory(),'id')===$before;
 $proof['passed']=empty($proof['error'])&&$proof['cleanup']&&!in_array(false,array_column($proof['checks'],'passed'),true);
}
return $proof;
