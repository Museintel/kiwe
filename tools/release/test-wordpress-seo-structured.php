<?php
/** CLI-owned native SQL and public output proof. Uses the accepted foundation isolation. */
if(PHP_SAPI!=='cli'||!defined('KIWE_SEO_NATIVE_TEST')||true!==KIWE_SEO_NATIVE_TEST)throw new RuntimeException('Owned native fixture required.');

function kiwe_structured_test_isolate():void{
 kiwe_seo_test_isolate();global $wpdb;
 $extras=[$wpdb->prefix.'rank_math_redirections',$wpdb->prefix.'kiwe_seo_redirects_v1'];
 $GLOBALS['kiwe_structured_original']=[];$GLOBALS['kiwe_structured_tables']=[];
 foreach($extras as$table){
  if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Native extra table refused.');
  $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table;
  $GLOBALS['kiwe_structured_original'][$table]=$exists?hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$table.' ORDER BY 1',ARRAY_A))):null;
 }
 remove_filter('query',$GLOBALS['kiwe_seo_test_write_guard'],PHP_INT_MAX);
 $allow=array_merge(array_values($GLOBALS['kiwe_seo_test_tables']),$extras);
 $GLOBALS['kiwe_seo_test_write_guard']=static function($sql)use($allow,$extras){
  if(preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([A-Za-z0-9_]+)/i',$sql,$m)&&in_array($m[1],$extras,true))$sql=preg_replace('/^\s*CREATE\s+TABLE/i','CREATE TEMPORARY TABLE',$sql,1);
  if(preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|ALTER\s+TABLE|DROP(?:\s+TEMPORARY)?\s+TABLE|TRUNCATE(?:\s+TABLE)?|CREATE(?:\s+TEMPORARY)?\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+`?([A-Za-z0-9_]+)/i',$sql,$m)&&!in_array($m[1],$allow,true))throw new RuntimeException('Unowned native write refused: '.$m[1]);
  if(preg_match('/^\s*(?:CREATE|DROP)\s+TABLE/i',$sql))throw new RuntimeException('Permanent native DDL refused.');
  return$sql;
 };
 add_filter('query',$GLOBALS['kiwe_seo_test_write_guard'],PHP_INT_MAX);
 $source=$extras[0];
 if(false===$wpdb->query("CREATE TEMPORARY TABLE {$source} (id bigint unsigned PRIMARY KEY,sources longtext NOT NULL,url_to text NOT NULL,header_code smallint NOT NULL,hits bigint NOT NULL DEFAULT 0,status varchar(20) NOT NULL,created datetime NULL,updated datetime NULL,last_accessed datetime NULL) ENGINE=InnoDB"))throw new RuntimeException('Native source fixture refused.');
 $GLOBALS['kiwe_structured_tables'][]=$source;
 delete_option(DSA\SEO\SEO_Redirect_Service::OPTION);
}

function kiwe_structured_test_run():array{
 global $wpdb,$wp_query,$wp_the_query,$wp_rewrite;
 $proof=['schema'=>'kiwe.seo-structured.native.v1','passed'=>false,'cleanup'=>false,'checks'=>[],'observations'=>[]];$GLOBALS['kiwe_seo_test_partial_proof']=&$proof;
 $assert=static function($ok,string$name)use(&$proof){$proof['checks'][$name]=(bool)$ok;kiwe_seo_test_progress($proof);if(!$ok)throw new RuntimeException($name);};
 $query=static function(array$args){global$wp_query,$wp_the_query;$wp_query=new WP_Query($args);$wp_the_query=$wp_query;$GLOBALS['post']=$wp_query->post;};
 $request=static function(string$kind,int$id,string$op='import',?string$expected=null,bool$confirm=true,bool$mutation=true){
  $r=new WP_REST_Request('POST');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$r->set_header('x-kiwe-mutation','1');$r->set_header('content-type','application/json');
  $review=$kind==='schema'?DSA\SEO\SEO_Structured_Import_Service::schema_review($id,$op==='undo'):DSA\SEO\SEO_Structured_Import_Service::redirect_review($id,$op==='undo');
  if($expected===null&&is_wp_error($review))throw new RuntimeException('Native review refused: '.$review->get_error_message());
  $r->set_body(wp_json_encode(['kind'=>$kind,'objectId'=>$id,'operation'=>$op,'expected'=>$expected??DSA\SEO\SEO_Structured_Import_Service::expected($review),'confirmed'=>$confirm]));return$r;
 };
 $schemaKey=DSA\Schema\Schema_Template_Service::META;$journalKey=DSA\SEO\SEO_Structured_Import_Service::JOURNAL;
 $redirectTable=DSA\SEO\SEO_Redirect_Service::table();$sourceTable=$wpdb->prefix.'rank_math_redirections';
 try{
  foreach(['litespeed_purge_all','litespeed_purge_post','litespeed_purge_url','transition_post_status','publish_post']as$hook)remove_all_actions($hook);
  $seed=static function($name,$role)use($wpdb){$wpdb->insert($wpdb->users,['user_login'=>'synthetic-structured-'.$name,'user_pass'=>wp_hash_password(wp_generate_password(24)),'user_email'=>$name.'@example.test','display_name'=>'Synthetic '.$name,'user_registered'=>current_time('mysql',true)]);$id=(int)$wpdb->insert_id;update_user_meta($id,$wpdb->prefix.'capabilities',[$role=>true]);clean_user_cache($id);return$id;};
  $owner=$seed('manager','administrator');$author=$seed('writer','author');wp_set_current_user($owner);
  $assert(current_user_can('manage_options')&&$owner>0&&$author>0,'Native users keep WordPress role authority');
  update_option('blogname','Synthetic News',false);update_option('show_on_front','posts',false);update_option('permalink_structure','/%postname%/',false);$wp_rewrite->init();
  $post=wp_insert_post(['post_title'=>'A native news story','post_name'=>'native-story','post_type'=>'post','post_status'=>'publish','post_author'=>$author,'post_content'=>'Article body remains native.','post_excerpt'=>'Native excerpt','post_date'=>'2026-10-01 10:00:00','post_date_gmt'=>'2026-10-01 10:00:00']);
  $assert(is_int($post)&&$post>0,'Native news is created only in isolated tables');
  $_SERVER['HTTP_ORIGIN']=home_url();$_SERVER['HTTP_REFERER']=home_url('/wp-admin/');$_SERVER['REQUEST_METHOD']='POST';
  $import=new DSA\SEO\SEO_Structured_Import_Service();$redirect=new DSA\SEO\SEO_Redirect_Service();
  $node=['@type'=>'NewsArticle','@id'=>'%url%#article','headline'=>'%title%','description'=>'%excerpt%','url'=>'%url%','datePublished'=>'%date(Y-m-dTH:i:sP)%','dateModified'=>'%modified(Y-m-dTH:i:sP)%','author'=>['@type'=>'Person','name'=>'%post_author%'],'metadata'=>['type'=>'template','title'=>'Article','shortcode'=>'s-synthetic','isPrimary'=>true]];
  update_post_meta($post,'rank_math_schema_NewsArticle',wp_slash($node));
  $sourceMetaSha=hash('sha256',serialize($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s ORDER BY meta_id",$post,$wpdb->esc_like('rank_math_schema').'%'),ARRAY_A)));
  $before=$wpdb->num_queries;$read=new WP_REST_Request('GET');$read->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$read->set_param('kind','schema');$read->set_param('objectId',$post);
  $review=$import->read($read);$assert($review->get_status()===200&&!isset($review->get_data()['graph']),'Manager review returns bounded summary without a raw graph');
  $assert(!get_option(DSA\SEO\SEO_Redirect_Service::OPTION)&&!metadata_exists('post',$post,$schemaKey),'Read-only schema review adds no target settings or graph');
  $assert($import->mutate($request('schema',$post,'import',null,true,false))->get_status()===403,'Mutation without same-site proof is denied');
  $assert($import->mutate($request('schema',$post,'import',str_repeat('0',64)))->get_status()===409,'Stale schema review refuses all writes');
  wp_set_current_user($author);$read->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$assert($import->read($read)->get_status()===403,'Native Author cannot inspect global source handover');wp_set_current_user($owner);
  $assert($import->mutate($request('schema',$post))->get_status()===200&&metadata_exists('post',$post,$schemaKey),'Reviewed schema imports through native metadata readback');
  $assert($import->mutate($request('schema',$post))->get_status()===409,'Import never overwrites an existing Kiwe schema');
  $query(['p'=>$post]);$context=new DSA\SEO\SEO_Output_Service(new DSA\Onboarding\Design_Context_Profile_Service());
  if(defined('RANK_MATH_VERSION')){
   $variables=null;foreach(($GLOBALS['wp_filter']['wp']->callbacks??[])as$callbacks)foreach($callbacks as$item){$fn=$item['function'];if(is_array($fn)&&($fn[0]??null)instanceof \RankMath\Replace_Variables\Manager&&($fn[1]??'')==='setup'){$variables=$fn[0];$fn();}}
   $assert($variables!==null,'Actual Rank Math variable owner initializes against the native request');
   $engine=new RankMath\Schema\JsonLD();$source=$engine->replace_variables($node,get_post($post));unset($source['isPrimary'],$source['isCustom']);
   $facts=['title'=>get_the_title($post),'excerpt'=>get_post($post)->post_excerpt,'url'=>get_permalink($post),'post_author'=>get_userdata($author)->display_name,'date(Y-m-dTH:i:sP)'=>get_post_time(DATE_W3C,false,$post),'modified(Y-m-dTH:i:sP)'=>get_post_modified_time(DATE_W3C,false,$post)];
   $ours=DSA\Schema\Schema_Template_Service::resolve(DSA\Schema\Schema_Template_Service::source_node($node),$facts)[0];
   foreach(['headline','description','url','datePublished','dateModified','author']as$field)$assert(($source[$field]??null)===$ours[$field],'Actual Rank Math and Kiwe agree on live '.$field);
   $before=$wpdb->num_queries;$assert($redirect->decision('/old-story')===null&&$wpdb->num_queries===$before,'Actual active Rank Math suppresses native redirect table reads');
   $settings=new DSA\Settings();$schema=new DSA\Schema\Schema_Geo_Service($settings,new DSA\Element_Registry());ob_start();$schema->print_schema();$out=ob_get_clean();$assert($out==='','Actual active Rank Math suppresses Kiwe schema output');
  }else{
   $schema=new DSA\Schema\Schema_Geo_Service(new DSA\Settings(),new DSA\Element_Registry());
   add_filter('dsa_schema_geo_facts',[$context,'schema_facts']);add_filter('dsa_schema_geo_graph',[$context,'organization_graph']);
   $config=['enabled'=>true,'webpage'=>true,'breadcrumb'=>true,'woo_product'=>true,'registry_hints'=>false];$settings=new DSA\Settings();$ref=new ReflectionProperty($settings,'resolved_settings');$ref->setValue($settings,['enabled'=>true,'schema_geo'=>$config]);$schema=new DSA\Schema\Schema_Geo_Service($settings,new DSA\Element_Registry());
   $render=static function()use($schema){ob_start();$schema->print_schema();return(string)ob_get_clean();};
   $writes=[];$capture=static function($sql)use(&$writes){if(preg_match('/^\s*(?:INSERT|UPDATE|DELETE|CREATE|ALTER|REPLACE)/i',$sql))$writes[]=$sql;return$sql;};add_filter('query',$capture);
   $out=$render();$assert(substr_count($out,'application/ld+json')===1&&str_contains($out,'A native news story')&&str_contains($out,'Synthetic writer'),'One schema owner renders imported native news and author');
   $assert($writes===[],'Ordinary schema output performs no DDL cache telemetry or metadata writes');remove_filter('query',$capture);
   wp_update_post(['ID'=>$post,'post_title'=>'Edited story']);$query(['p'=>$post]);$assert(str_contains($render(),'Edited story'),'Imported schema uses edited native facts without a route cache');
   update_post_meta($post,'_kiwe_seo_robots',['noindex']);$assert($render()==='','Native noindex suppresses structured data');delete_post_meta($post,'_kiwe_seo_robots');
   update_option('blog_public','0',false);$assert($render()==='','Private-site policy suppresses schema');update_option('blog_public','1',false);
   update_option('kiwe_seo_workspace_enabled_v1','0',false);$assert($render()==='','SEO-off suppresses schema before facts or graph work');update_option('kiwe_seo_workspace_enabled_v1','1',false);
   wp_update_post(['ID'=>$post,'post_password'=>'synthetic']);$query(['p'=>$post]);$assert($render()==='','Password-protected content never emits schema');wp_update_post(['ID'=>$post,'post_password'=>'']);$query(['p'=>$post]);
  }
  $edited=get_post_meta($post,$schemaKey,true);$edited[0]['headline']='Newer manager schema';update_post_meta($post,$schemaKey,wp_slash($edited));
  $assert($import->mutate($request('schema',$post,'undo'))->get_status()===409&&get_post_meta($post,$schemaKey,true)===$edited,'Undo protects newer schema edits');
  update_post_meta($post,$schemaKey,wp_slash(DSA\Schema\Schema_Template_Service::source_node($node)));
  $assert($import->mutate($request('schema',$post,'undo'))->get_status()===200&&!metadata_exists('post',$post,$schemaKey)&&!metadata_exists('post',$post,$journalKey),'Reviewed undo retires unchanged schema and journal');
  $fail=static function($check,$id,$key){return$key===DSA\Schema\Schema_Template_Service::META?false:$check;};add_filter('add_post_metadata',$fail,10,3);
  $assert($import->mutate($request('schema',$post))->get_status()===409&&metadata_exists('post',$post,$journalKey)&&!metadata_exists('post',$post,$schemaKey),'Interrupted target write retains an inert schema journal');remove_filter('add_post_metadata',$fail,10);
  $assert($import->mutate($request('schema',$post))->get_status()===200,'Schema import resumes from the exact retained journal');
  $assert($sourceMetaSha===hash('sha256',serialize($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s ORDER BY meta_id",$post,$wpdb->esc_like('rank_math_schema').'%'),ARRAY_A))),'All schema import failure resume and undo paths preserve raw source rows');
  delete_post_meta($post,$schemaKey);delete_post_meta($post,'rank_math_schema_NewsArticle');
  $assert($import->mutate($request('schema',$post,'undo'))->get_status()===200&&!metadata_exists('post',$post,$journalKey),'Interrupted undo can finish even after source schema is unavailable');update_post_meta($post,'rank_math_schema_NewsArticle',wp_slash($node));
  // Keep source preservation attribution separate from the deliberate fixture delete/recreate above.
  $sourceMetaSha=hash('sha256',serialize($wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s ORDER BY meta_key",$post,$wpdb->esc_like('rank_math_schema').'%'),ARRAY_A)));
  $assert($import->mutate($request('schema',$post))->get_status()===200&&$import->mutate($request('schema',$post,'undo'))->get_status()===200,'Fresh native schema import and undo complete together');
  $assert($sourceMetaSha===hash('sha256',serialize($wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s ORDER BY meta_key",$post,$wpdb->esc_like('rank_math_schema').'%'),ARRAY_A))),'Schema import and undo preserve tested raw source fields exactly');
  $wpdb->insert($sourceTable,['id'=>41,'sources'=>serialize([['pattern'=>'old-story','comparison'=>'exact','ignore'=>'']]),'url_to'=>home_url('/destination/'),'header_code'=>301,'hits'=>5,'status'=>'active']);
  $sourceSha=hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$sourceTable.' ORDER BY id',ARRAY_A)));
  $read->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$read->set_param('kind','redirect');$read->set_param('objectId',41);
  $assert($import->read($read)->get_status()===200&&!get_option(DSA\SEO\SEO_Redirect_Service::OPTION),'Redirect GET performs no automatic storage preparation');
  $response=$import->mutate($request('redirect',41));$assert($response->get_status()===200,'Reviewed exact redirect prepares native indexed storage and publishes');$GLOBALS['kiwe_structured_tables'][]=$redirectTable;
  $assert(get_option(DSA\SEO\SEO_Redirect_Service::OPTION)['enabled']===false,'Import keeps public redirect activation an explicit separate choice');
  $indexes=$wpdb->get_results('SHOW INDEX FROM '.$redirectTable,ARRAY_A);$assert(count($indexes)===4,'Native SQL verifies exact unique and secondary indexes');
  $rows=$wpdb->get_results('SELECT * FROM '.$redirectTable.' ORDER BY id',ARRAY_A);$assert(count($rows)===1&&$rows[0]['state']==='active','Native redirect publication has a complete active journal');
  $assert($import->mutate($request('redirect',41))->get_status()===200&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$redirectTable)===1,'Replay is idempotent without duplicate redirect rows');
  $settings=get_option(DSA\SEO\SEO_Redirect_Service::OPTION);$settings['enabled']=true;update_option(DSA\SEO\SEO_Redirect_Service::OPTION,$settings,false);
  if(!defined('RANK_MATH_VERSION')){
   $writes=[];add_filter('query',$capture);$decision=$redirect->decision('/old-story/?utm_source=test');remove_filter('query',$capture);
   $assert($decision===['code'=>301,'url'=>home_url('/destination/').'?utm_source=test']&&$writes===[],'Public native redirect preserves status and query with no hit writes');
   $plan=$wpdb->get_results($wpdb->prepare('EXPLAIN SELECT source_path,destination,code,ignore_case FROM '.$redirectTable." WHERE source_hash IN (%s,%s) AND state='active' LIMIT 2",hash('sha256','c:old-story'),hash('sha256','i:old-story')),ARRAY_A);
   $assert(($plan[0]['key']??'')==='source_hash','Actual MariaDB lookup uses the unique source hash index');
   $sent=[];$intercept=static function($url,$code)use(&$sent){$sent[]=[$url,$code];return false;};add_filter('wp_redirect',$intercept,10,2);$_SERVER['REQUEST_URI']='/old-story/';$_SERVER['REQUEST_METHOD']='GET';$redirect->handle();remove_filter('wp_redirect',$intercept,10);$assert($sent===[[home_url('/destination/'),301]],'Native wp_redirect boundary receives reviewed target and status');
   $home=get_option('home');update_option('home',rtrim($home,'/').'/subsite',false);$wpdb->update($redirectTable,['destination'=>home_url('/destination/')],['source_id'=>41]);$assert($redirect->decision('/subsite/old-story')['url']===home_url('/destination/'),'Subdirectory requests match site-relative redirect identities');update_option('home',$home,false);$wpdb->update($redirectTable,['destination'=>$rows[0]['destination']],['source_id'=>41]);
   $before=$wpdb->num_queries;update_option('kiwe_seo_workspace_enabled_v1','0',false);$before=$wpdb->num_queries;$assert($redirect->decision('/old-story')===null&&$wpdb->num_queries===$before,'SEO-off adds zero optional native redirect queries');update_option('kiwe_seo_workspace_enabled_v1','1',false);
  }else{
   $matches=RankMath\Redirections\DB::compare_sources([['pattern'=>'old-story','comparison'=>'exact','ignore'=>'']],'old-story');$assert($matches===true,'Actual Rank Math exact matcher recognizes the same source path');
   $assert(RankMath\Redirections\DB::compare_sources([['pattern'=>'Old-Story','comparison'=>'exact','ignore'=>'case']],'old-story')===true,'Actual Rank Math case-insensitive matcher agrees with native path identity');
  }
  $wpdb->update($redirectTable,['destination'=>home_url('/newer-manager-edit/')],['source_id'=>41]);$assert($import->mutate($request('redirect',41,'undo'))->get_status()===409,'Redirect undo protects a newer destination edit');$wpdb->update($redirectTable,['destination'=>$rows[0]['destination']],['source_id'=>41]);
  $assert($import->mutate($request('redirect',41,'undo'))->get_status()===200&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$redirectTable)===0,'Redirect undo retires unchanged native rules');
  $wpdb->insert($sourceTable,['id'=>42,'sources'=>serialize([['pattern'=>'regex.*','comparison'=>'regex','ignore'=>'']]),'url_to'=>home_url('/destination/'),'header_code'=>301,'status'=>'active']);
  $assert(is_wp_error(DSA\SEO\SEO_Structured_Import_Service::redirect_review(42)),'Effective regex source behavior explicitly refuses unsupported import');$wpdb->delete($sourceTable,['id'=>42]);
  $assert($sourceSha===hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$sourceTable.' ORDER BY id',ARRAY_A))),'Redirect imports reads and undo preserve source fields and hit counts exactly');
  $rules=[DSA\SEO\SEO_Redirect_Service::rule('pending-a',home_url('/destination/'),301),DSA\SEO\SEO_Redirect_Service::rule('pending-b',home_url('/destination/'),301)];
  $n=0;$interrupt=static function($sql)use(&$n,$redirectTable){if(preg_match('/^INSERT INTO `?'.preg_quote($redirectTable,'/').'`?/i',$sql)&&++$n===2)throw new RuntimeException('Synthetic interruption');return$sql;};add_filter('query',$interrupt,PHP_INT_MAX);
  try{DSA\SEO\SEO_Redirect_Service::stage($rules,77,str_repeat('a',64));$interrupted=false;}catch(RuntimeException$e){$interrupted=$e->getMessage()==='Synthetic interruption';}remove_filter('query',$interrupt,PHP_INT_MAX);
  $assert($interrupted&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$redirectTable} WHERE state='pending'")===1,'Interrupted SQL import retains only inert pending rows');
  $assert($redirect->decision('/pending-a')===null,'Pending redirect never becomes visitor-visible');
  $assert(DSA\SEO\SEO_Redirect_Service::stage($rules,77,str_repeat('a',64))===true&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$redirectTable} WHERE state='active'")===2,'Redirect import resumes and atomically publishes its full rule set');
  $assert(is_wp_error(DSA\SEO\SEO_Redirect_Service::stage([$rules[0]],78,str_repeat('b',64))),'A competing source cannot take an existing redirect path');
  $case=DSA\SEO\SEO_Redirect_Service::rule('PENDING-A',home_url('/destination/'),301,true);$assert(is_wp_error(DSA\SEO\SEO_Redirect_Service::stage([$case],79,str_repeat('c',64))),'Case-fold overlap cannot introduce ambiguous matching');
  $assert(has_action('template_redirect','wp_old_slug_redirect')!==false,'Native old-slug redirect callback remains installed');
  $assert(($GLOBALS['kiwe_seo_test_http_calls']??0)===0&&($GLOBALS['kiwe_seo_test_mail_calls']??0)===0,'Structured reads output import and undo send no provider mail or AI requests');
  $proof['observations']=['wordpressVersion'=>$GLOBALS['wp_version'],'phpVersion'=>PHP_VERSION,'databaseVersion'=>$wpdb->db_version(),'bricksVersion'=>defined('BRICKS_VERSION')?BRICKS_VERSION:'','actualRankMath'=>defined('RANK_MATH_VERSION')?RANK_MATH_VERSION:null,'httpHeaderScope'=>'native wp_redirect filter; public HTTP handover follows STSEO-7/8'];$proof['passed']=true;
 }catch(Throwable$e){$proof['error']=$e->getMessage();$proof['line']=$e->getLine();$proof['errorClass']=get_class($e);}
 finally{try{do_action('shutdown');$proof['checks']['Native shutdown stays isolated']=true;}catch(Throwable$e){$proof['checks']['Native shutdown stays isolated']=false;$proof['shutdownError']=$e->getMessage();}remove_all_actions('shutdown');kiwe_structured_test_cleanup($proof);}
 $proof['passed']=$proof['passed']&&$proof['cleanup']&&!in_array(false,$proof['checks'],true);$proof['checkCount']=count($proof['checks']);return$proof;
}

function kiwe_structured_test_cleanup(array&$proof):void{
 global$wpdb;$clean=true;
 // Connection-local tables shadow any original source/store. Never DROP a permanent table.
 foreach(array_unique($GLOBALS['kiwe_structured_tables']??[])as$table)if(false===$wpdb->query('DROP TEMPORARY TABLE '.$table))$clean=false;
 foreach($GLOBALS['kiwe_structured_original']??[]as$table=>$hash){$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table;$same=$hash===null?!$exists:($exists&&$hash===hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$table.' ORDER BY 1',ARRAY_A))));$proof['checks']['Original '.$table.' preserved']=$same;$clean=$clean&&$same;}
 kiwe_seo_test_cleanup($proof);$proof['cleanup']=$proof['cleanup']&&$clean;
}

/** Focused owner projection proof; no repeated import/redirect scenarios. */
function kiwe_structured_test_owner_run():array{
 global$wpdb,$wp_query,$wp_the_query;
 $proof=['schema'=>'kiwe.seo-structured.owner.native.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];$GLOBALS['kiwe_seo_test_partial_proof']=&$proof;
 $assert=static function($ok,$name)use(&$proof){$proof['checks'][$name]=(bool)$ok;if(!$ok)throw new RuntimeException($name);};
 try{
  update_option('show_on_front','posts',false);update_option('blogname','Synthetic organization',false);
  $wp_query=new WP_Query(['post_type'=>'post']);$wp_the_query=$wp_query;$GLOBALS['post']=$wp_query->post;
  $assert(is_front_page(),'Native homepage context is active');
  $context=new DSA\SEO\SEO_Output_Service(new DSA\Onboarding\Design_Context_Profile_Service());
  $stamp=new ReflectionMethod($context,'public_stamp');$stamp->setAccessible(true);
  $approved=['complete'=>true,'identity'=>['siteType'=>'news','siteName'=>'Synthetic organization','description'=>'Approved public description','logo'=>'https://example.test/logo.png'],'seo'=>['homepageDescription'=>'Approved public description','legalName'=>'Synthetic legal name','foundedYear'=>2025],'contact'=>['email'=>'public@example.test','phone'=>'','socialLinks'=>['https://example.test/public-profile'],'address'=>['line1'=>'Synthetic public street','city'=>'Synthetic city','country'=>'IN']]];
  update_option(DSA\Onboarding\SEO_Context_Service::PUBLIC_CACHE,['stamp'=>$stamp->invoke($context),'context'=>$approved],false);
  $settings=new DSA\Settings();(new ReflectionProperty($settings,'resolved_settings'))->setValue($settings,['enabled'=>true,'schema_geo'=>['enabled'=>true,'webpage'=>true,'breadcrumb'=>false,'registry_hints'=>false,'woo_product'=>false]]);
  $schema=new DSA\Schema\Schema_Geo_Service($settings,new DSA\Element_Registry());add_filter('dsa_schema_geo_graph',[$context,'organization_graph']);
  $queries=[];$capture=static function($sql)use(&$queries){$queries[]=$sql;return$sql;};add_filter('query',$capture);
  ob_start();$context->head();$head=ob_get_clean();ob_start();$schema->print_schema();$out=ob_get_clean();remove_filter('query',$capture);
  $assert(!str_contains($head,'application/ld+json')&&substr_count($out,'application/ld+json')===1,'Native homepage has one JSON-LD writer across head and footer');
  preg_match('~<script[^>]*>(.*)</script>~s',$out,$match);$data=json_decode($match[1]??'',true);$organizations=array_values(array_filter($data['@graph']??[],static fn($n)=>($n['@type']??null)==='Organization'));
  $assert(count($organizations)===1&&$organizations[0]['name']==='Synthetic organization','Approved public organization appears once in the common graph');
  $assert(($organizations[0]['address']['streetAddress']??'')==='Synthetic public street'&&($organizations[0]['address']['addressCountry']??'')==='IN','Approved public address survives owner consolidation');
  $assert(!array_filter($queries,static fn($q)=>preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER)/i',$q)),'Homepage schema projection performs no optional writes or DDL');
  $assert(!array_filter($queries,static fn($q)=>preg_match('/FROM\s+.*(?:posts|postmeta|termmeta|terms|comments)/i',$q)),'Approved homepage schema does not enumerate a content or media library');
  $graph=$context->organization_graph([['@type'=>'Organization','name'=>'Imported organization']]);$assert(count($graph)===1&&$graph[0]['name']==='Imported organization','An existing imported organization prevents a second approved organization');
  $term=wp_insert_term('Synthetic archive','category');if(is_wp_error($term))throw new RuntimeException('Native archive fixture failed.');
  $wp_query=new WP_Query(['cat'=>$term['term_id']]);$wp_the_query=$wp_query;$GLOBALS['post']=$wp_query->post;
  $queries=[];add_filter('query',$capture);ob_start();$schema->print_schema();$archive=ob_get_clean();remove_filter('query',$capture);
  $assert(is_category()&&!str_contains($archive,'dateModified')&&!array_filter($queries,static fn($q)=>preg_match('/FROM\s+.*\b(?:[a-z0-9_]*posts)\b/i',$q)),'Native term archive never borrows another post clock or reads the posts table');
  define('RANK_MATH_VERSION','synthetic-owner-guard');$before=$wpdb->num_queries;ob_start();$schema->print_schema();$suppressed=ob_get_clean();
  $assert($suppressed===''&&$wpdb->num_queries===$before,'Final schema owner yields before optional work when a source provider is active');
  $proof['passed']=true;
 }catch(Throwable$e){$proof['error']=$e->getMessage();$proof['line']=$e->getLine();}
 finally{try{do_action('shutdown');$proof['checks']['Native shutdown stays isolated']=true;}catch(Throwable$e){$proof['checks']['Native shutdown stays isolated']=false;}remove_all_actions('shutdown');kiwe_structured_test_cleanup($proof);}
 $proof['passed']=$proof['passed']&&$proof['cleanup']&&!in_array(false,$proof['checks'],true);$proof['checkCount']=count($proof['checks']);return$proof;
}
