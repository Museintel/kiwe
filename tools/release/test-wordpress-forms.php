<?php
/** Real Bricks save/email + native role/read boundaries. No outbound delivery. Named lab only. */
use DSA\Access\Form_Workspace_Service as Forms;
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Site\Site_Identity_Service as Identity;
use Bricks\Integrations\Form\Submission_Database as Submissions;

if (PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||!KIWE_WORKSPACE_TEST_SITE||home_url()!=='https://plum-giraffe-539524.hostingersite.com')throw new RuntimeException('Named lab required');
$out=['schema'=>'kiwe.forms-native.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
$check=static function($ok,$label)use(&$out){$out['checks'][]=['passed'=>(bool)$ok,'label'=>$label];if(!$ok)throw new RuntimeException($label);};
$filters=[];$borrowed=[];$users=[];$post=0;$rows=[];$table='';$created=false;$mail=[];$beforeCount=null;$actor=get_current_user_id();$beforeServer=$_SERVER;
$emailKey=Identity::OPTION_STORE_EMAIL;$emailBefore=get_option($emailKey,null);$activityBefore=get_option(DSA\Access\Workspace_Activity_Service::OPTION,null);$policy=[];
$filter=static function($hook,$fn,$args=1)use(&$filters){add_filter($hook,$fn,PHP_INT_MAX,$args);$filters[]=[$hook,$fn];};
$filter('pre_wp_mail',static function($value,$args)use(&$mail){$mail[]=['to'=>$args['to']];return true;},2);
$filter('pre_http_request',static fn()=>new WP_Error('qa_no_outbound','No test provider traffic'),3);
$filter('pre_schedule_event','__return_false');
$filter('pre_option_'.Owner::OPTION,static function()use(&$policy){return $policy;});
foreach(['user_register','set_user_role']as$hook)foreach(($GLOBALS['wp_filter'][$hook]->callbacks??[])as$priority=>$entries)foreach($entries as$entry){$cb=$entry['function'];if(is_array($cb)&&$cb[0] instanceof DSA\Notifications\Admin_Event_Notification_Service){$borrowed[]=[$hook,$priority,$entry];remove_action($hook,$cb,$priority);}}
try{
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$check(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'Exact complete installed runtime');
 $check(class_exists(Submissions::class)&&class_exists('Bricks\\Integrations\\Form\\Actions\\Email'),'Native Bricks form actions available');
 require_once ABSPATH.'wp-admin/includes/user.php';
 $prefix='kiwe_forms_'.bin2hex(random_bytes(5));
 foreach(['owner'=>'administrator','client'=>'administrator','editor'=>'editor','author'=>'author','contributor'=>'contributor','shop'=>'shop_manager','designer'=>'kiwe_web_designer','subscriber'=>'subscriber','customer'=>'customer']as$name=>$role){$id=wp_insert_user(['user_login'=>$prefix.$name,'user_pass'=>wp_generate_password(48,true,true),'user_email'=>$prefix.$name.'@example.invalid','role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Synthetic account creation failed');$users[$name]=(int)$id;}
 $policy=['enabled'=>true,'ownerId'=>$users['owner'],'rankMath'=>'inline'];wp_set_current_user($users['owner']);
 $table=Submissions::get_table_name();$check($table===$wpdb->prefix.'bricks_form_submissions','Original native table authority');
 $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table;
 if(!$exists){Submissions::maybe_create_table();$created=true;}
 $check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table,'Native owner creates only its native schema when absent');
 $beforeCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
 $fields=[['id'=>'name','type'=>'text','label'=>'Your name','name'=>'name','required'=>true],['id'=>'email','type'=>'email','label'=>'Email','name'=>'email','required'=>true],['id'=>'message','type'=>'textarea','label'=>'Message','name'=>'message','required'=>true]];
 $settings=['fields'=>$fields,'actions'=>['save-submission','email'],'submissionFormName'=>'QA business enquiries','emailTo'=>'custom','emailToCustom'=>'{kiwe_store_email}','emailSubject'=>'Private QA enquiry'];
 $post=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>$prefix,'post_author'=>$users['owner']],true);if(is_wp_error($post))throw new RuntimeException('Synthetic native form page failed');
 update_post_meta($post,BRICKS_DB_PAGE_CONTENT,[['id'=>'a81f01','name'=>'form','parent'=>0,'children'=>[],'settings'=>$settings]]);
 $form=new Bricks\Integrations\Form\Init();
 $set=static function($name,$value)use($form){$p=new ReflectionProperty($form,$name);$p->setValue($form,$value);};
 $set('form_settings',$settings);$set('form_fields',['formId'=>'a81f01','postId'=>$post,'form-field-name'=>'QA visitor','form-field-email'=>'visitor@example.invalid','form-field-message'=>"Line one\nLine two"]);$set('form_id','a81f01');$set('post_id',$post);$set('results',[]);$set('uploaded_files',[]);
 $_SERVER['HTTP_USER_AGENT']='Kiwe native acceptance';$_SERVER['HTTP_ORIGIN']=home_url();$_SERVER['HTTP_REFERER']=admin_url();
 foreach(['first@example.invalid','second@example.invalid']as$target){
  update_option($emailKey,$target,false);$set('results',[]);
  $save=new Bricks\Integrations\Form\Actions\Save_Submission('save-submission');$save->run($form);$row=(int)$wpdb->insert_id;if($row)$rows[]=$row;
  $check($row>0&&$wpdb->get_var($wpdb->prepare("SELECT form_id FROM `{$table}` WHERE id=%d",$row))==='a81f01','Real native Save submission action persists fixture');
  $email=new Bricks\Integrations\Form\Actions\Email('email');$email->run($form);
  $sent=end($mail);$recipients=is_array($sent['to']??null)?$sent['to']:explode(',',(string)($sent['to']??''));
  $check(in_array($target,array_map('trim',$recipients),true),'Native dynamic recipient follows current Design Context business email');
 }
 $service=new Forms();
 foreach($users as$name=>$id){wp_set_current_user($id);$request=new WP_REST_Request('GET','/dsa/v1/workspace/forms');$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$request->set_query_params(['form'=>'a81f01']);$response=$service->listing($request);$admitted=in_array($name,['owner','client'],true);$check(($response->get_status()===200)===$admitted,'Native collection role '.$name);$detail=new WP_REST_Request('GET','/dsa/v1/workspace/forms/'.end($rows));$detail->set_param('id',end($rows));$detail->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$message=$service->detail($detail);$check(($message->get_status()===200)===$admitted,'Native private message role '.$name);if($admitted){$data=$response->get_data();$check($data['total']===2&&count($data['items'])===2,'Exact fixture count without duplicate message store');$check(!str_contains(wp_json_encode($data),'visitor@example.invalid'),'Native list omits message fields');$content=$message->get_data()['item']['fields'];$check((bool)array_filter($content,static fn($f)=>$f['value']==="Line one\nLine two"),'Native detail retains multiline saved message');}}
 wp_set_current_user($users['client']);$bad=new WP_REST_Request('GET');$bad->set_header('X-WP-Nonce','invalid');$check($service->listing($bad)->get_status()===403,'Actual nonce denial before reading');
 $postRequest=new WP_REST_Request('POST');$postRequest->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$check(!$service->permission($postRequest),'Native inbox grants no mutation');
 $out['passed']=true;$out['nativeBricksVersion']=wp_get_theme(get_template())->get('Version');$out['outboundDelivered']=0;
}catch(Throwable$e){$out['error']=$e->getMessage();}
finally{
 try{
  if($table)foreach($rows as$id)$wpdb->delete($table,['id'=>$id,'form_id'=>'a81f01'],['%d','%s']);
  wp_set_current_user($users['owner']??$actor);if($post)wp_delete_post($post,true);
  // Retire the fixture-only owner policy before removing its owner account.
  foreach($filters as[$hook,$fn])if($hook==='pre_option_'.Owner::OPTION)remove_filter($hook,$fn,PHP_INT_MAX);
  foreach($users as$id){wp_set_current_user($actor);wp_delete_user($id);$check(!get_user_by('id',$id),'Synthetic role retired');}
  foreach([$emailKey=>$emailBefore,DSA\Access\Workspace_Activity_Service::OPTION=>$activityBefore]as$key=>$value){if(null===$value)delete_option($key);else update_option($key,$value,false);$check(get_option($key,null)===$value,'Exact original control restoration');}
  if($table&&null!==$beforeCount)$check((int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`")===$beforeCount,'Original native submission rows preserved');
  if($created&&$table&&(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`")===0)$wpdb->query("DROP TABLE `{$table}`");
  $out['cleanup']=true;
 }catch(Throwable$e){$out['passed']=false;$out['cleanupError']=$e->getMessage();}
 foreach(array_reverse($filters)as[$hook,$fn])remove_filter($hook,$fn,PHP_INT_MAX);
 foreach($borrowed as[$hook,$priority,$entry])add_action($hook,$entry['function'],$priority,$entry['accepted_args']);
 $_SERVER=$beforeServer;wp_set_current_user($actor);
}
echo wp_json_encode($out);
