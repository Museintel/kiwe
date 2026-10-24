<?php
/** Actual native writers; run only inside the disposable WordPress audit replicas. */
if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||!KIWE_WORKSPACE_TEST_SITE||empty($GLOBALS['kiwe_media_tables_isolated']))exit(2);
use DSA\Backup\Bricks_Font_Archive_Service as Fonts;
use DSA\Backup\Bricks_Design_Snapshot_Service as Design;
use DSA\Backup\Media_Archive_Job_Service as Jobs;
use DSA\Backup\Media_Archive_Service as Media;
use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
use DSA\Backup\Archive_Job_Files as Files;
use DSA\Backup\Archive_Cipher as Cipher;
use DSA\Access\Comment_Moderation_Service as Feedback;
use DSA\Onboarding\Context_Selection_Service as Selection;
$proof=['schema'=>'kiwe-native-context-font-archives.v1','kiwe'=>DSA_VERSION,'bricks'=>BRICKS_VERSION,'wordpress'=>$GLOBALS['wp_version'],'checks'=>[],'cleanup'=>false];$GLOBALS['kiwe_media_proof']=&$proof;
$assert=static function(bool $ok,string $label)use(&$proof):void{$proof['checks'][]=['label'=>$label,'passed'=>$ok];if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable $action):bool{try{$value=$action();return is_wp_error($value);}catch(Throwable $e){return true;}};
$must=static function($value){if(is_wp_error($value)||!$value)throw new RuntimeException(is_wp_error($value)?$value->get_error_message():'Native fixture unavailable');return $value;};
$phrase='Synthetic native fonts 2026 recovery';$jobs=new Jobs();$snapshots=new Snapshots();$jobIds=[];$reviews=[];$filters=[];$beforeCopies=[];$actor=get_current_user_id();
$create=static function($job)use(&$jobIds){$jobIds[]=$job['id'];return $job;};
$advance=static function(array $job,string $target,bool $confirm=false)use($jobs,$phrase){for($i=0;$i<100&&$job['status']!==$target;$i++)$job=$jobs->step($job['id'],$phrase,$job['revision'],$confirm);if($job['status']!==$target)throw new RuntimeException('Native font job did not reach '.$target);return $job;};
try{
 wp_set_current_user(78);$beforeCopies=array_column($snapshots->inventory(),'id');
 $assert(!function_exists('WC'),'Woo remains genuinely absent in this isolated native process');
 $assert(Fonts::available()&&Fonts::allowed(),'client has native Bricks font-manager and backup authority');
 $root=Snapshots::private_directory(true).'/media-qa-'.bin2hex(random_bytes(12));wp_mkdir_p($root);$GLOBALS['kiwe_media_fixture_root']=$root;
 $uploads=static fn()=>['path'=>$root,'basedir'=>$root,'url'=>home_url('/synthetic-fonts'),'baseurl'=>home_url('/synthetic-fonts'),'subdir'=>'','error'=>false];add_filter('upload_dir',$uploads,PHP_INT_MAX);$filters[]=['upload_dir',$uploads];
 $source=get_template_directory().'/assets/fonts/builder/Inter.woff2';if(!is_file($source)||!copy($source,$root.'/synthetic-font.woff2'))throw new RuntimeException('Native theme font fixture unavailable');
 $attachment=$must(wp_insert_attachment(['post_title'=>'Synthetic font file','post_status'=>'inherit','post_mime_type'=>'font/woff2','post_author'=>78],$root.'/synthetic-font.woff2',0,true));
 $font=$must(wp_insert_post(['post_type'=>BRICKS_DB_CUSTOM_FONTS,'post_title'=>'Synthetic Kiwe Font','post_status'=>'publish','post_author'=>78],true));
 $faces=['400normal'=>['woff2'=>$attachment],'700italic'=>[['woff2'=>(string)$attachment,'unicode-range'=>'U+0000-00FF'],['woff2'=>$attachment,'unicode-range'=>'U+0100-017F']]];update_post_meta($font,BRICKS_DB_CUSTOM_FONT_FACES,$faces);
 $assert(Fonts::faces($faces)===$faces,'native legacy and multi-subset faces retain their exact shape and scalar IDs');
 foreach([['400normal'=>['woff2'=>$attachment,'css'=>'body{}']],['400normal'=>['woff2'=>$attachment,'unicode-range'=>'U+0000;}body{color:red']],['400normal'=>['woff2'=>0]],['400normal'=>['unicode-range'=>'U+0000']],['400normal'=>[['woff2'=>$attachment],[]]],['999evil'=>['woff2'=>$attachment]]]as$bad)$assert($reject(fn()=>Fonts::faces($bad)),'unsafe or incomplete face registry rejected '.count($proof['checks']));
 $plan=Fonts::plan();$assert(count($plan['fonts'])===1&&count($plan['records'])===1&&$plan['fonts'][0]['faces']===$faces,'shared attachment is captured once for all native faces');
 $original=Fonts::fingerprint($font);$originalFile=hash_file('sha256',$root.'/synthetic-font.woff2');
 $bad=$plan;$bad['fonts'][0]['faces']['400normal']['woff2']=999999;$assert($reject(fn()=>Fonts::validate_plan($bad)),'missing encrypted font attachment rejected');
 $bad=$plan;$bad['fonts'][]=$bad['fonts'][0];$assert($reject(fn()=>Fonts::validate_plan($bad)),'repeated font record rejected');
 $bad=$plan;$bad['fonts'][0]['title']='Font";body{color:red}';$assert($reject(fn()=>Fonts::validate_plan($bad)),'CSS-breaking family name rejected before native generation');
 $job=$create($jobs->start_fonts($phrase,wp_generate_uuid4()));
 $assert($reject(fn()=>$jobs->start_fonts($phrase,'invalid')),'invalid request key cannot start another native job');
 $export=$advance($job,'ready');$path=$jobs->download_path($export['id']);$zip=new ZipArchive();$must($zip->open($path));$header=$zip->getFromName('manifest.kiwe');$zip->close();
 $manifest=Cipher::open($header,Jobs::FONT_SCHEMA,$phrase,4194304);$assert($manifest['scope']==='bricks_fonts'&&count($manifest['fonts'])===1&&!str_contains($header,'Synthetic Kiwe Font'),'font metadata and bytes share the encrypted media package with a distinct schema');
 $assert($reject(fn()=>$jobs->start_import($path,'incorrect recovery phrase',wp_generate_uuid4())),'wrong passphrase cannot create native copies');
 $import=$create($jobs->start_import($path,$phrase,wp_generate_uuid4()));$import=$advance($import,'review');
 $assert($import['review']['fonts']===['Synthetic Kiwe Font']&&count(get_posts(['post_type'=>BRICKS_DB_CUSTOM_FONTS,'numberposts'=>10]))===1,'review lists native families before any font is created');
 $unchanged=$jobs->step($import['id'],$phrase,$import['revision']);$assert($unchanged['status']==='review','native copies require explicit confirmation');
 $stale=$jobs->step($import['id'],$phrase,$import['revision']-1,true);$assert($stale['status']==='review','stale step proof cannot bypass review');
 $import=$advance($import,'complete',true);$map=$jobs->recovery_map($import['id'],true);$new=$map['fontMap'][$font];$newFile=$map['map'][$attachment]['id'];
 $assert($new!==$font&&$newFile!==$attachment,'recovery allocates native font and attachment copies');
 $assert(get_post_meta($new,BRICKS_DB_CUSTOM_FONT_FACES,true)===Fonts::faces($faces,$map['map']),'every legacy and subset face maps to recovered attachment IDs');
 $assert(hash_file('sha256',get_attached_file($newFile))===$originalFile,'recovered font binaries match the frozen complete checksum');
 $assert(Fonts::fingerprint($font)===$original&&hash_file('sha256',$root.'/synthetic-font.woff2')===$originalFile,'original native font and binary remain unchanged');
 $native=Bricks\Custom_Fonts::get_custom_fonts();$assert(isset($native['custom_font_'.$new])&&str_contains(get_option(BRICKS_DB_CUSTOM_FONT_FACE_RULES,''),wp_get_attachment_url($newFile)),'native font getter and derived CSS resolve the recovered attachment');
 foreach(Design::names()as$name)delete_option($name);
 $classes=[['id'=>'font01','name'=>'synthetic-font-class','settings'=>['_typography'=>['fontFamily'=>'custom_font_'.$font],'text'=>'custom_font_'.$font]]];update_option(BRICKS_DB_GLOBAL_CLASSES,$classes);
 $copy=$snapshots->create($phrase,'Synthetic design font mapping',[Design::GROUP]);$bytes=file_get_contents($snapshots->download_path($copy['id']));
 $review=$snapshots->preview_upload($bytes,$phrase,$import['id']);$reviews[]=$review['token'];$assert($review['recoveredFonts']===1&&$review['changedCount']===1,'settings review reports explicit recovered font remapping');
 $snapshots->apply($review['token'],$phrase);$current=get_option(BRICKS_DB_GLOBAL_CLASSES);$assert($current[0]['settings']['_typography']['fontFamily']==='custom_font_'.$new&&$current[0]['settings']['text']==='custom_font_'.$font,'only typed fontFamily references change; ordinary text is preserved');
 $review=$snapshots->preview_upload($bytes,$phrase,$import['id']);$reviews[]=$review['token'];wp_update_post(['ID'=>$new,'post_title'=>'Changed recovered family']);$assert($reject(fn()=>$snapshots->apply($review['token'],$phrase)),'changed recovered font invalidates the reviewed settings map');
 // A changed copy remains preserved; capture a fresh recovery rather than reusing it.
 $assert($reject(fn()=>$jobs->recovery_map($import['id'])),'edited font copy remains preserved and cannot silently re-enter an old map');
 $retry=$create($jobs->start_import($path,$phrase,wp_generate_uuid4()));$retry=$advance($retry,'review');
 $interrupt=static function($id,$post){if($post->post_type===BRICKS_DB_CUSTOM_FONTS&&get_post_meta($id,Fonts::MARKER,true))throw new RuntimeException('Synthetic post-save interruption');};add_action('save_post',$interrupt,PHP_INT_MAX,2);
 try{$retry=$advance($retry,'complete',true);throw new RuntimeException('Expected native interruption absent');}catch(Throwable $e){if($e->getMessage()==='Expected native interruption absent')throw$e;}finally{remove_action('save_post',$interrupt,PHP_INT_MAX);}
 $saved=array_values(array_filter($jobs->inventory(),fn($item)=>$item['id']===$retry['id']))[0];$before=count(get_posts(['post_type'=>BRICKS_DB_CUSTOM_FONTS,'numberposts'=>20]));$retry=$advance($saved,'complete',true);$assert(count(get_posts(['post_type'=>BRICKS_DB_CUSTOM_FONTS,'numberposts'=>20]))===$before,'interrupted native font save resumes by journal identity without duplicate fonts');
 $map2=$jobs->recovery_map($retry['id'],true);$record=['records'=>[['featuredImage'=>0,'content'=>'custom_font_'.$font,'excerpt'=>'','builder'=>['_bricks_page_content_2'=>[['name'=>'text','settings'=>['_typography'=>['fontFamily'=>'custom_font_'.$font]]]]]]]];
 $mapped=Media::remap_content($record,$map2);$assert($mapped['records'][0]['content']===$record['records'][0]['content']&&str_contains(wp_json_encode($mapped['records'][0]['builder']),'custom_font_'.$map2['fontMap'][$font]),'new content drafts reuse the same typed font recovery map');
 $removed=$jobs->remove($retry['id']);$assert($removed['completedMediaKept']&&get_post($map2['fontMap'][$font]),'retiring completed jobs keeps native font and media copies');
 $assert($reject(fn()=>$snapshots->preview_upload($bytes,$phrase,$retry['id'])),'retired font map cannot be used for a new settings review');
 $changed=$create($jobs->start_fonts($phrase,wp_generate_uuid4()));wp_update_post(['ID'=>$font,'post_title'=>'Source family changed']);$assert($reject(fn()=>$jobs->step($changed['id'],$phrase,$changed['revision'])),'source font drift stops frozen capture');wp_update_post(['ID'=>$font,'post_title'=>'Synthetic Kiwe Font']);
 $subscriber=$must(wp_insert_user(['user_login'=>'kiwefontqa_'.bin2hex(random_bytes(5)),'user_email'=>'synthetic-font@example.invalid','user_pass'=>wp_generate_password(40),'role'=>'subscriber']));wp_set_current_user($subscriber);
 $assert(!Fonts::allowed()&&$reject(fn()=>$jobs->start_fonts($phrase,wp_generate_uuid4())),'subscriber cannot capture native font records');$assert($reject(fn()=>$jobs->recovery_map($import['id'])),'another account cannot use an actor-owned font mapping');wp_set_current_user(78);
 // Feedback and media use their existing native membership authority, independent of font work.
 $post=$must(wp_insert_post(['post_type'=>'post','post_title'=>'Synthetic public feedback source','post_content'=>'Synthetic article','post_status'=>'publish','post_author'=>78],true));
 $comment=$must(wp_insert_comment(['comment_post_ID'=>$post,'comment_author'=>'Synthetic public reader','comment_content'=>'A useful public testimonial.','comment_approved'=>'1','comment_type'=>'comment']));
 $pending=$must(wp_insert_comment(['comment_post_ID'=>$post,'comment_author'=>'Synthetic pending reader','comment_content'=>'Pending words stay private.','comment_approved'=>'0','comment_type'=>'comment']));
 $feedback=new Feedback();$marked=$must($feedback->select(['id'=>$comment,'selected'=>true,'expected'=>false]));$assert($marked['selected'],'Feedback marks native comment membership in Design Context');
 $listing=$feedback->listing(['status'=>'all','type'=>'testimonial']);$assert(count($listing['items'])===1&&$listing['items'][0]['id']===$comment,'Testimonials tab queries only marked native feedback');
 $assert($reject(fn()=>$feedback->select(['id'=>$pending,'selected'=>true,'expected'=>false])),'pending comments cannot enter public Design Context');
 $assert($reject(fn()=>$feedback->select(['id'=>$comment,'selected'=>false,'expected'=>false])),'stale testimonial membership refuses a write');
 $quotes=Selection::testimonials();$assert(count($quotes)===1&&$quotes[0]['quote']==='A useful public testimonial.','AI Design Context receives the selected original public words');
 $moved=$must($feedback->change_status(['id'=>$comment,'status'=>'hold','expected'=>$marked['expected'],'acceptEffect'=>true]));$assert(!$moved['public']&&count(Selection::testimonials())===0,'revoked approval immediately removes the quote from public Design Context');
 $listing=$feedback->listing(['status'=>'all','type'=>'testimonial']);$assert(count($listing['items'])===1&&$listing['items'][0]['selected']&&!$listing['items'][0]['public'],'marked but unavailable quote remains manageable from Testimonials');
 $unmarked=$must($feedback->select(['id'=>$comment,'selected'=>false,'expected'=>true]));$assert(!$unmarked['selected']&&count($feedback->listing(['status'=>'all','type'=>'testimonial'])['items'])===0,'unmark removes the native testimonial from its tab without deleting the comment');
 $media=(new DSA\Access\Context_Workspace_Service())->listing('media');$assert(!$media['selected']&&count($media['items'])>0,'Media native default opens all eligible files without a browse step');
 $selected=$must((new Selection())->select('media',$attachment,true,false));$assert(Selection::selected('media',$attachment),'file mark reuses existing native Design Context membership');
 $selected=(new DSA\Access\Context_Workspace_Service())->listing('media',['view'=>'selected']);$assert(count($selected['items'])===1&&$selected['items'][0]->ID===$attachment,'Design Context view queries only independently marked files');
 $must((new Selection())->select('media',$attachment,false,true));$assert(!Selection::selected('media',$attachment),'unmark preserves the original native attachment');
 $moderator=$must(wp_insert_user(['user_login'=>'kiwemodqa_'.bin2hex(random_bytes(5)),'user_email'=>'synthetic-moderator@example.invalid','user_pass'=>wp_generate_password(40),'role'=>'subscriber']));$user=get_user_by('id',$moderator);$user->add_cap('moderate_comments');wp_set_current_user($moderator);$assert(is_wp_error($feedback->listing(['status'=>'all','type'=>'testimonial'])),'moderator without Design Context authority cannot access testimonial curation');wp_set_current_user(78);
 $proof['providerRequestsBlocked']=count($GLOBALS['kiwe_media_http']);$proof['mailBlocked']=count($GLOBALS['kiwe_media_mail']);
}catch(Throwable $e){$proof['error']=['message'=>$e->getMessage(),'line'=>$e->getLine()];}
finally{
 wp_set_current_user(78);
 foreach($reviews as$token){$value=get_transient('kiwe_backup_review_'.$token);delete_transient('kiwe_backup_review_'.$token);if(is_array($value)&&preg_match('/^[a-f0-9]{24}$/D',$value['id']??''))@unlink(Snapshots::private_directory().'/stage-'.$value['id'].'.kiwe');}
 foreach(array_unique($jobIds)as$id){$dir=Snapshots::private_directory().'/media-job-'.$id;if(is_dir($dir)){try{$jobs->remove($id);}catch(Throwable $e){if(preg_match('/^[a-f0-9]{32}$/D',$id)&&!is_link($dir))Files::retire($dir);}}}
 foreach($snapshots->inventory()as$copy)if(!in_array($copy['id'],$beforeCopies,true))$snapshots->remove($copy['id']);
 foreach($filters as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);wp_set_current_user($actor);
 $remaining=array_intersect(array_column($jobs->inventory(),'id'),$jobIds);$proof['cleanup']=!$remaining&&array_column($snapshots->inventory(),'id')===$beforeCopies;
 $proof['passed']=empty($proof['error'])&&$proof['cleanup']&&!in_array(false,array_column($proof['checks'],'passed'),true);
}
return $proof;
