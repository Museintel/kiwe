<?php
/** RC59–RC63 grouped native proof. Invoke from a CLI-only, explicitly authorized lab wrapper. */
if (PHP_SAPI !== 'cli' || !defined('ABSPATH') || !defined('KIWE_WORKSPACE_TEST_SITE') || KIWE_WORKSPACE_TEST_SITE !== true || !defined('KIWE_WORKSPACE_EXPECTED_VERSION')) exit(2);
use DSA\AI\AI_Allowance_Service as Allowance;
use DSA\AI\AI_Feature_Policy as Policy;
use DSA\AI\AI_Broker_Service as Broker;
use DSA\SEO\SEO_Job_Store as Jobs;
use DSA\SEO\SEO_Score_Service as Score;
use DSA\SEO\SEO_Score_Source as Source;
use DSA\SEO\SEO_Refinement_Service as Reviews;
use DSA\Onboarding\Design_Context_Profile_Service as Profile;
use DSA\Access\Site_Owner_Service as Owner;

$proof = ['schema'=>'kiwe-native-ai-foundation/v1','kiwe'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'checks'=>[],'cleanup'=>false];
$assert = static function(bool $ok, string $label) use (&$proof) { $proof['checks'][]=['label'=>$label,'passed'=>$ok]; if (!$ok) throw new RuntimeException($label); };
$must = static function($value) { if (!$value || is_wp_error($value)) throw new RuntimeException('Native operation failed'); return $value; };
$actor=get_current_user_id(); $nativeOptions=$wpdb->options; $nativeCache=$GLOBALS['wp_object_cache']; wp_roles();
$table=$wpdb->prefix.'kiwe63qa_'.bin2hex(random_bytes(6)); $created=false; $users=[]; $posts=[]; $hooks=[]; $filters=[];
$prefix='kiwe63qa_'.bin2hex(random_bytes(5)); $calls=0; $httpMode='deny'; $recordsToReturn=[];
$filter=static function($hook,$callback,$args=1)use(&$filters){add_filter($hook,$callback,PHP_INT_MAX,$args);$filters[]=[$hook,$callback];};
$write=static function($key,$value)use(&$wpdb){$value=wp_json_encode($value);$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)",$key,$value));wp_cache_delete($key,'options');wp_cache_delete('notoptions','options');};
$read=static fn($key)=>json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)),true);
$reset=static function($key)use(&$wpdb){$wpdb->delete($wpdb->options,['option_name'=>$key]);wp_cache_delete($key,'options');wp_cache_delete('notoptions','options');};
try {
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION,'matching installed candidate');
 $assert(!empty(DSA\Runtime\Package_Manifest::verify(true)['valid']),'installed package integrity');
 // A core request-local cache prevents test option values entering the real site's cache.
 $assert(!wp_using_ext_object_cache() && str_ends_with(str_replace('\\','/',(new ReflectionClass('WP_Object_Cache'))->getFileName()),'/wp-includes/class-wp-object-cache.php'),'core request-local cache permits isolated native options');
 $seed=['siteurl'=>get_option('siteurl'),'home'=>get_option('home'),$wpdb->prefix.'user_roles'=>wp_roles()->roles,'blogname'=>'Synthetic Kiwe studio','blogdescription'=>'Synthetic native tagline','active_plugins'=>[],'template'=>get_option('template'),'stylesheet'=>get_option('stylesheet')];
 $assert($wpdb->query("CREATE TEMPORARY TABLE {$table} LIKE {$nativeOptions}")!==false,'isolated temporary options table created'); $created=true;
 $wpdb->options=$table; $GLOBALS['wp_object_cache']=new WP_Object_Cache();
 foreach($seed as$key=>$value)add_option($key,$value,'',false);
 $filter('pre_wp_mail',static fn()=>false);
 $filter('pre_http_request',static function($pre,$args,$url)use(&$calls,&$httpMode,&$recordsToReturn){
  if($httpMode!=='synthetic'||$url!=='https://generativelanguage.googleapis.com/v1/interactions')return new WP_Error('kiwe_qa_network','Network blocked during native proof');
  ++$calls;
  $body=json_decode($args['body'],true);$input=$body['input']??'';
  foreach($recordsToReturn as$id)if(!str_contains($input,'"id":'.$id))throw new RuntimeException('Synthetic record missing from transport');
  $contract=['schema'=>'kiwe.seo-refinement-batch.v1','proposals'=>array_map(static fn($id)=>['id'=>$id,'fields'=>['seoTitle'=>'Reviewed synthetic title','metaDescription'=>'Verified synthetic description','excerpt'=>"Reader's synthetic excerpt",'searchPhrases'=>['synthetic studio']],'reason'=>'Synthetic fixture evidence'],$recordsToReturn)];
  return ['headers'=>[],'response'=>['code'=>200,'message'=>'OK'],'body'=>wp_json_encode(['steps'=>[['type'=>'model_output','content'=>[['type'=>'text','text'=>wp_json_encode($contract)]]]],'usage'=>['total_input_tokens'=>30,'total_output_tokens'=>50,'total_thought_tokens'=>20,'total_tokens'=>100]]),'cookies'=>[]];
 },3);
 foreach(['kiwe_notification_event','dsa_admin_notification_event','transition_post_status']as$hook){$hooks[$hook]=$GLOBALS['wp_filter'][$hook]??null;remove_all_actions($hook);}
 foreach(['owner'=>'administrator','client'=>'administrator','visitor'=>'subscriber']as$name=>$role)$users[$name]=(int)$must(wp_insert_user(['user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password(32,true,true),'user_email'=>$prefix.'_'.$name.'@example.invalid','role'=>$role]));
 $filter('pre_option_'.Owner::OPTION,static fn()=>['enabled'=>true,'ownerId'=>$users['owner'],'rankMath'=>'inline']);
 $settings=(new DSA\Settings())->defaults();
 $settings['ai']=array_replace($settings['ai'],['seo_enabled'=>true,'securetrack_brief_enabled'=>false,'allow_native_generation'=>true,'native_provider'=>'gemini','native_model'=>'gemini-3-flash-preview','native_api_key'=>DSA\Security\Secret_Store::encrypt('synthetic-fixture-key'),'request_allowance'=>['minute'=>60,'day'=>100,'month'=>300],'token_allowance'=>['minute'=>1000000,'day'=>2000000,'month'=>3000000]]);
 update_option(DSA_OPTION_SETTINGS,$settings,false);
 wp_set_current_user($users['client']);$policy=new Policy();
 $assert(current_user_can(Policy::CAPABILITY)&&!current_user_can('manage_options'),'client preference capability excludes technical settings');
 $assert($policy->set_preference('seo',true)===true&&get_option('kiwe_ai_opt_in_seo_v1')==='1','client opt-in writes and reads native option');
 $assert(is_wp_error($policy->set_preference('securetrack',true)),'owner-disabled feature cannot be enabled by client');
 wp_set_current_user($users['visitor']);$assert(is_wp_error($policy->set_preference('seo',false)),'subscriber cannot change site AI preference');
 wp_set_current_user($users['owner']);$assert(current_user_can('manage_options'),'technical owner retains review authority');
 $allowance=new Allowance(new DSA\Settings());$ticket=$allowance->reserve(100);
 $assert(!empty($ticket['ok'])&&$allowance->status()['used']['month']===1,'native allowance reserves request and token units');
 $assert($allowance->settle($ticket['ticket'],20)['settled']&&$allowance->status()['tokenBudget']['used']['month']===20,'native settlement releases only unused tokens');
 $assert(!$allowance->settle($ticket['ticket'],20)['settled']&&$allowance->status()['used']['month']===1,'settlement replay cannot refund twice or refund request');
 $ticket=$allowance->reserve(80);$allowance->settle($ticket['ticket'],null);
 $assert($allowance->status()['tokenBudget']['used']['month']===100,'unknown usage retains full reservation');
 $legacy=$read(Allowance::OPTION);$legacy['schema']=1;unset($legacy['tokens'],$legacy['pending']);$write(Allowance::OPTION,$legacy);$allowance->reserve(10);
 $assert($read(Allowance::OPTION)['schema']===2&&$allowance->status()['used']['month']===3,'schema-one requests survive native schema-two upgrade');
 $allowance->cooldown('120');$assert(empty($allowance->reserve(10)['ok']),'provider cooldown blocks later consumers');
 $write(Allowance::OPTION,['corrupt'=>true]);$assert(($allowance->reserve(10)['reason']??'')==='site_ai_ledger_invalid','malformed native ledger fails closed');$reset(Allowance::OPTION);

 for($i=0;$i<30;++$i)$posts[]=(int)$must(wp_insert_post(['post_title'=>$prefix.' Article '.$i,'post_content'=>'Verified synthetic studio description.','post_excerpt'=>'Existing excerpt','post_status'=>$i===1?'private':'publish','post_password'=>$i===2?'synthetic':'','post_type'=>'post','post_author'=>$users['owner']],true));
 $first=$posts[0];$last=end($posts);
 update_post_meta($first,'_kiwe_seo_title','');add_post_meta($first,'_kiwe_seo_title','Ignored duplicate');
 $source=new Source();$rows=$must($source->page($first-1,$last));
 $assert(count($rows)===25&&(int)$rows[0]['ID']===$first,'native MySQL checklist projects one fixed 25-record page');
 $assert(!isset($rows[0]['post_content'])&&!$rows[0]['has_seo_title'],'projection omits bodies and honors first native metadata value');
 $assert(Source::points($rows[1],true)===null&&Source::points($rows[2],true)===null,'native private and password-protected rows excluded');
 $jobs=new Jobs();$job=$must($jobs->start('all_posts',$posts[4],5,$users['owner']));$job['cursorId']=$first-1;$write(Jobs::OPTION,$job);
 $broker=new Broker(new DSA\Settings());$reviews=new Reviews($broker,new Profile());
 $assert(!empty($broker->status('seo',true)['profile']['enabled']),'native broker sees owner and client permission intersection');
 $recordsToReturn=[$posts[0],$posts[3],$posts[4]];$httpMode='synthetic';$reviews->run_batch($job['id']);$j=$must($jobs->read())['job'];
 $proof['worker']=['status'=>$j['status'],'errorCode'=>$j['lastError']['code']??null,'calls'=>$calls];
 $assert($calls===1&&$j['counts']['inspected']===5&&count($j['proposals'])===3,'real broker and synthetic HTTP produce bounded public-record proposals');
 $assert(get_post($first)->post_excerpt==='Existing excerpt','generation leaves native content unchanged');
 $assert($allowance->status()['used']['month']===1&&$allowance->status()['tokenBudget']['used']['month']===100,'provider usage settles the shared native ledger');
 $reviews->run_batch($j['id']);$assert($calls===1,'duplicate completed step does not dispatch provider again');
 $filter('wp_redirect',static function(){throw new LogicException('kiwe_fixture_redirect');},2);
 $review=static function($decision,$id)use($jobs,$reviews){$j=$jobs->read()['job'];$nonce=wp_create_nonce('kiwe_review_seo_refinement_'.$j['id']);$_SERVER['REQUEST_METHOD']='POST';$_POST=['job_id'=>$j['id'],'proposal_id'=>$id,'proposal_version'=>Jobs::proposal_version($j['proposals'][$id]),'decision'=>$decision,'_wpnonce'=>$nonce];$_REQUEST=$_POST;try{$reviews->handle_review();}catch(LogicException $e){if($e->getMessage()!=='kiwe_fixture_redirect')throw $e;}};
 $ids=array_keys($j['proposals']);$review('accept',$ids[0]);$j=$jobs->read()['job'];
 $assert($j['counts']['applied']===1&&get_post($first)->post_excerpt==="Reader's synthetic excerpt"&&get_post_meta($first,'_kiwe_seo_title',true)==='Reviewed synthetic title','real nonce review applies native fields and verifies readback');
 $stalePost=$j['proposals'][$ids[1]]['objectId'];$must(wp_update_post(['ID'=>$stalePost,'post_content'=>'Changed native evidence.'],true));$review('accept',$ids[1]);$j=$jobs->read()['job'];
 $assert($j['counts']['applied']===1&&isset($j['proposals'][$ids[1]])&&get_post_meta($stalePost,'_kiwe_seo_title',true)==='','native source changes prevent stale acceptance');
 $review('reject',$ids[1]);$review('reject',$ids[2]);$assert($jobs->read()['job']['status']==='complete','dismissal clears remaining review slots');
 $job=$must($jobs->start('all_posts',$last,30,$users['owner']));$claim=$must($jobs->claim($job['id']));
 $assert(is_wp_error($jobs->claim($job['id'])),'second native worker cannot acquire active lease');
 $jobs->control($job['id'],'pause');$assert($jobs->finish($job['id'],$claim['lease']['token'],$claim)['status']==='paused','pause survives in-flight native completion');
 $jobs->control($job['id'],'resume');$jobs->control($job['id'],'cancel');$assert(is_wp_error($jobs->claim($job['id'])),'cancel blocks later worker claims');
 $policy->set_preference('seo',false);$assert(empty($broker->status('seo',true)['profile']['enabled']),'native preference revocation refreshes running broker');

 $reset(Score::OPTION);$score=new Score();$must($score->request());$state=$read(Score::OPTION);
 $assert(wp_next_scheduled(Score::CRON)!==false,'native cron stores a recovery event in isolated options');
 $state['job']=['request'=>$state['requested'],'upper'=>$last,'cursor'=>$first-1,'inspected'=>0,'startedAt'=>time(),'commerce'=>function_exists('WC'),'onboarding'=>(new Profile())->seo_evidence_component(),'counts'=>[]];
 foreach(['posts','pages','products','media']as$scope)$state['job']['counts'][$scope]=['points'=>0,'ready'=>0,'total'=>0];$write(Score::OPTION,$state);
 $score->step();$state=$read(Score::OPTION);
 $assert($state['job']['inspected']===25&&Score::snapshot()['report']===null,'native score step persists counts without publishing partial result');
 $state['nextAt']=0;$write(Score::OPTION,$state);$score->step();$snapshot=Score::snapshot();
 $assert(!$snapshot['pending']&&$snapshot['report']['components']['posts']['total']===28,'native second page atomically publishes eligible totals');
 $before=$wpdb->num_queries;Score::snapshot();$assert($wpdb->num_queries===$before,'warm snapshot reads do not query native content');
 $score=new Score();$score->register();update_post_meta($first,'_kiwe_seo_description','Native edit after completed checklist');
 $assert(Score::snapshot()['pending']&&Score::snapshot()['report']['components']['posts']['total']===28,'native metadata hook queues refresh while retaining completed snapshot');
 $autoload=$wpdb->get_col($wpdb->prepare("SELECT autoload FROM {$table} WHERE option_name IN (%s,%s,%s)",Allowance::OPTION,Jobs::OPTION,Score::OPTION));
 $assert(count($autoload)===3&&!array_diff($autoload,['no','off']),'all three native worker states remain non-autoloaded');
 ob_start();Score::render();$rendered=ob_get_clean();$assert(str_contains($rendered,'kiwe_update_seo_checklist')&&str_contains($rendered,'<button'),'native checklist renders its nonce-protected form');
 $proof['syntheticProviderCalls']=$calls;
}catch(Throwable $e){$proof['error']=['class'=>get_class($e),'line'=>$e->getLine(),'stage'=>'native acceptance'];}
finally {
 if($created){
  foreach($filters as[$hook,$callback])if($hook==='pre_option_'.Owner::OPTION)remove_filter($hook,$callback,PHP_INT_MAX);
  wp_set_current_user($actor);
  $httpMode='deny'; require_once ABSPATH.'wp-admin/includes/user.php';
  foreach(array_reverse($posts)as$id){$post=get_post($id);if($post&&str_starts_with($post->post_title,$prefix))wp_delete_post($id,true);}
  foreach($users as$id){$user=get_userdata($id);if($user&&str_starts_with($user->user_login,$prefix))wp_delete_user($id);}
  $proof['cleanup']=!array_filter($posts,'get_post')&&!array_filter($users,'get_userdata');
  $wpdb->options=$nativeOptions;$GLOBALS['wp_object_cache']=$nativeCache;
  $proof['cleanup']=$wpdb->query("DROP TEMPORARY TABLE {$table}")!==false&&$proof['cleanup'];
 }
 foreach(array_reverse($filters)as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);
 foreach($hooks as$hook=>$saved){remove_all_actions($hook);if($saved)$GLOBALS['wp_filter'][$hook]=$saved;}
 wp_set_current_user($actor);
}
$proof['passed']=!isset($proof['error'])&&$proof['cleanup'];
$proof['limitations']=['Real WordPress/MySQL and core request-local cache; temporary native options table.','Synthetic HTTP response validates integration, not live provider model availability or billing.','Independent-process races and browser interaction are separate acceptance.'];
return $proof;
