<?php
/** Real WordPress Service proof inside the parent fixture's temporary options table. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE || ! isset( $assert,$users,$posts,$terms,$prefix,$phrase,$temporary ) || ! $temporary ) exit( 2 );
use DSA\Backup\Content_Archive_Service as Archives;
use DSA\Backup\Archive_Cipher as Cipher;
use DSA\Access\Content_Archive_App_Service as App;

// Included PHP shares its caller's symbol table. Keep this subfixture isolated so
// its Service terms cannot replace the Post/category variables used by later gates.
(static function () use ( $users,$prefix,$phrase,$assert,$rejects,$dispatch,$preview,$saveArchive,$service,$wpdb,&$posts,&$terms,&$archives,&$jobIds ) {
$serviceActor = get_current_user_id();
$profileKey = DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE;
$previousProfile = get_option( $profileKey, null );
$suffix = substr( hash( 'sha256', $prefix ), 0, 8 );
$serviceType = 'qasvc_' . $suffix; $plainType = 'qaplain_' . $suffix;
$serviceTax = 'qasvcat_' . $suffix; $serviceTag = 'qasvtag_' . $suffix;
$serviceFields = [ 'qa_duration'=>'integer','qa_price'=>'number','qa_intro'=>'string','qa_featured'=>'boolean' ];
$denyServiceField = false; $serviceJobs = []; $serviceStaffRoleChanged = false;
$metaAuthority = static function( $allowed,$key,$id ) use ( &$denyServiceField ) { return ! $denyServiceField && current_user_can( 'edit_post', $id ); };
$registerField = static fn( $key,$type ) => register_post_meta( $serviceType, $key, [ 'type'=>$type,'single'=>true,'show_in_rest'=>true,'auth_callback'=>$metaAuthority ] );
$changeSource = static function( string $source ) use ( $profileKey ) { update_option( $profileKey, [ 'services'=>[ 'enabled'=>true,'sourcePostType'=>$source ] ], false ); };
$serviceJobsApi = new DSA\Backup\Content_Archive_Job_Service();
$trackServiceJob = static function( array $job ) use ( &$serviceJobs,&$jobIds ) { $serviceJobs[]=$job['id']; $jobIds[]=[$job['id'],get_current_user_id()]; return $job; };
$finishServiceJob = static function( array $job,string $target ) use ( $serviceJobsApi,$phrase,$wpdb ) {
 for ( $steps=0; $job['status']!==$target && $steps<50; $steps++ ) { if ( '1' !== (string) $wpdb->get_var( 'SELECT 1' ) ) throw new RuntimeException( 'Service fixture database heartbeat failed' ); $job=$serviceJobsApi->step($job['id'],$phrase,$job['revision']); }
 if ( $job['status']!==$target ) throw new RuntimeException( 'Service job did not reach ' . $target ); return $job;
};
$grantServiceOnly = static function( $all,$caps,$args,$user ) use ( $users,$serviceType ) {
 if ( (int) $user->ID !== $users['visitor'] ) return $all;
 return array_fill_keys( [ 'read','edit_' . $serviceType . 's','edit_published_' . $serviceType . 's','create_' . $serviceType . 's' ], true ) + [ 'do_not_allow'=>false ];
};
try {
 foreach ( [ $serviceType,$plainType ] as $type ) {
  if ( post_type_exists( $type ) ) throw new RuntimeException( 'Synthetic Service type collision' );
  $registered=register_post_type($type,['public'=>true,'show_ui'=>true,'show_in_rest'=>true,'hierarchical'=>true,'rewrite'=>false,'capability_type'=>[$type,$type.'s'],'map_meta_cap'=>true,'supports'=>['title','editor','excerpt','author','revisions','page-attributes','custom-fields']]);
  if ( is_wp_error( $registered ) ) throw new RuntimeException( 'Synthetic Service type unavailable' );
 }
 foreach ( [ $serviceTax=>true,$serviceTag=>false ] as $tax=>$hierarchical ) {
  $registered=register_taxonomy($tax,[$serviceType],['public'=>true,'show_ui'=>true,'show_in_rest'=>true,'hierarchical'=>$hierarchical,'rewrite'=>false]);
  if ( is_wp_error( $registered ) ) throw new RuntimeException( 'Synthetic Service taxonomy unavailable' );
 }
 foreach ( $serviceFields as $key=>$type ) $registerField($key,$type);
 register_post_meta($serviceType,'_qa_private',['type'=>'string','single'=>true,'show_in_rest'=>true]);
 register_post_meta($serviceType,'qa_hidden',['type'=>'string','single'=>true,'show_in_rest'=>false]);
 register_post_meta($serviceType,'qa_multiple',['type'=>'string','single'=>false,'show_in_rest'=>true]);
 $changeSource($serviceType); wp_set_current_user($users['client']);
 $definition=Archives::service_definition();$expectedFields=$serviceFields;ksort($expectedFields);
 $assert($definition['postType']===$serviceType&&$definition['fields']===$expectedFields&&$definition['hierarchical'],'real registered public scalar fields define the connected Service archive');
 $config=App::config();$scope=Archives::category_scope($serviceTax,true);
 $assert($config['canImportArchives']['service']&&$config['canImportArchives'][$scope]&&$config['canExportArchiveCollections']['services'],'native Service and exact taxonomy capabilities reach the shared dialog');
 $root=wp_insert_term($prefix.' Service root',$serviceTax,['slug'=>$prefix.'-svc-root']);if(is_wp_error($root))throw new RuntimeException('Service root unavailable');$root=(int)$root['term_id'];
 $child=wp_insert_term($prefix.' Service child',$serviceTax,['slug'=>$prefix.'-svc-child','parent'=>$root]);$tag=wp_insert_term($prefix.' Service tag',$serviceTag,['slug'=>$prefix.'-svc-tag']);if(is_wp_error($child)||is_wp_error($tag))throw new RuntimeException('Service terms unavailable');$child=(int)$child['term_id'];$tag=(int)$tag['term_id'];
 update_term_meta($root,'_kiwe_seo_title','Native Service category SEO');$originalServices=[];
 foreach([0,1,2]as$index){$id=wp_insert_post(['post_type'=>$serviceType,'post_status'=>'draft','post_author'=>$users[$index===1?'author':'client'],'post_title'=>$prefix.' Service '.$index,'post_content'=>'<p><strong>Native Service</strong> details.</p>','post_excerpt'=>'Service excerpt','post_date'=>'2088-01-02 12:00:00','post_date_gmt'=>get_gmt_from_date('2088-01-02 12:00:00'),'menu_order'=>$index],true);if(is_wp_error($id))throw new RuntimeException('Service source unavailable');$id=(int)$id;$originalServices[]=$id;wp_set_object_terms($id,[$root,$child],$serviceTax);wp_set_object_terms($id,[$tag],$serviceTag);foreach(['qa_duration'=>60,'qa_price'=>12.5,'qa_intro'=>"Public details\nA quote: \"hi\" and a slash: \\",'qa_featured'=>'0','_qa_private'=>'excluded-private','qa_hidden'=>'excluded-hidden','qa_multiple'=>'excluded-multiple','_kiwe_seo_title'=>'Native Service SEO']as$key=>$value)update_post_meta($id,$key,wp_slash($value));}
 wp_update_post(['ID'=>$originalServices[0],'post_parent'=>$originalServices[2]]);
 $categoryBytes=$saveArchive($scope,$root,['from'=>'2088-01-02','to'=>'2088-01-02']);$categoryPayload=Cipher::open($categoryBytes,Archives::SCHEMA,$phrase,Archives::MAX_BYTES);
 $assert(count($categoryPayload['records'])===3&&count($categoryPayload['terms'])===3&&!str_contains(wp_json_encode($categoryPayload),'excluded-'),'native Service category includes complete hierarchy and excludes private hidden multi-value meta');
 $singleBytes=$saveArchive('service',$originalServices[0]);$singlePayload=Cipher::open($singleBytes,Archives::SCHEMA,$phrase,Archives::MAX_BYTES);
 $assert($singlePayload['records'][0]['serviceMeta']['qa_featured']===false&&$singlePayload['records'][0]['serviceMeta']['qa_duration']===60&&$singlePayload['records'][0]['serviceMeta']['qa_price']===12.5,'real WordPress metadata strings normalize to typed Service scalars');
 $review=$preview($singleBytes,'service');$restored=$service->apply($review['token'],$phrase);$copy=$restored['ids'][0];
 $assert(get_post_type($copy)===$serviceType&&get_post_status($copy)==='draft'&&(int)get_post($copy)->post_parent===$originalServices[2]&&get_post($originalServices[0]),'native individual Service restore keeps its authorized parent and originals');
 $assert(get_post_meta($copy,'qa_intro',true)===get_post_meta($originalServices[0],'qa_intro',true)&&get_post_meta($copy,'qa_featured',true)==='0'&&get_post_meta($copy,'_kiwe_seo_title',true)==='Native Service SEO','native Service field readback preserves false multiline quotes slashes and SEO');
 $export=$service->export('service',$originalServices[0],$phrase);$archives[]=$export['id'];$denyServiceField=true;
 $assert(!Archives::can_export('service',$originalServices[0])&&$rejects(fn()=>$service->download_path($export['id'])),'registered native metadata authority is rechecked on Service download');$denyServiceField=false;
 $review=$preview($singleBytes,'service');$before=get_posts(['post_type'=>$serviceType,'post_status'=>'any','fields'=>'ids','numberposts'=>-1]);$denyServiceField=true;
 $assert($rejects(fn()=>$service->apply($review['token'],$phrase))&&get_posts(['post_type'=>$serviceType,'post_status'=>'any','fields'=>'ids','numberposts'=>-1])===$before,'native Service field denial rolls back only its new partial draft');$denyServiceField=false;
 $review=$preview($singleBytes,'service');$registerField('qa_duration','string');
 $assert($rejects(fn()=>$service->apply($review['token'],$phrase))&&$rejects(fn()=>$preview($singleBytes,'service')),'native registered field changes invalidate fresh and already reviewed Service imports');$registerField('qa_duration','integer');
 $changeSource($plainType);$assert($rejects(fn()=>$preview($singleBytes,'service'))&&$rejects(fn()=>$service->download_path($export['id'])),'changed explicit native Service connection blocks import and signed download');$changeSource($serviceType);
 wp_set_current_user($users['visitor']);$assert(!Archives::can_import('service')&&!Archives::can_export_collection('services'),'ordinary native Subscriber receives no Service archive authority');
 // Existing role/type policy stays intact: temporarily restrict a synthetic Administrator
 // to this native Service capability matrix, without granting ordinary Subscribers access.
 wp_get_current_user()->set_role('administrator');$serviceStaffRoleChanged=true;
 add_filter('user_has_cap',$grantServiceOnly,PHP_INT_MAX,4);$staffSource=wp_insert_post(['post_type'=>$serviceType,'post_status'=>'draft','post_author'=>$users['visitor'],'post_title'=>$prefix.' Service-only writer'],true);if(is_wp_error($staffSource))throw new RuntimeException('Service-only fixture source unavailable');$staffSource=(int)$staffSource;
 $assert(!current_user_can('edit_posts')&&!current_user_can('edit_pages')&&!current_user_can('edit_products')&&Archives::can_export('service',$staffSource)&&!Archives::can_export('service',$originalServices[0])&&!Archives::can_export($scope,$root),'native Service-only writer retains own records without category or foreign author escalation');
 $response=$dispatch('export',['scope'=>'service','id'=>$staffSource,'passphrase'=>$phrase]);$responseBody=$response->get_data();if(!empty($responseBody['archive']['id']))$archives[]=$responseBody['archive']['id'];
 $assert($response->get_status()===200&&!empty($responseBody['archive']['id']),'real REST central gate admits native Service-only export');
 $response=$dispatch('export',['scope'=>'service','id'=>$staffSource,'passphrase'=>$phrase,'resumable'=>true,'requestKey'=>'synthetic-service-only-'.bin2hex(random_bytes(12))]);$responseBody=$response->get_data();if(!empty($responseBody['job']))$staffJob=$trackServiceJob($responseBody['job']);
 $assert($response->get_status()===200&&!empty($responseBody['job']['id']),'real REST central gate admits Service-only durable export');
 $response=$dispatch('jobs/'.$staffJob['id'].'/step',['passphrase'=>$phrase,'revision'=>$staffJob['revision']]);
 $assert($response->get_status()===200,'real REST central gate admits Service-only saved checkpoint');
 remove_filter('user_has_cap',$grantServiceOnly,PHP_INT_MAX);wp_get_current_user()->set_role('subscriber');$serviceStaffRoleChanged=false;wp_set_current_user($users['client']);
 foreach([$child,$root]as$id)wp_delete_term($id,$serviceTax);wp_delete_term($tag,$serviceTag);
 $assert($rejects(fn()=>$preview($categoryBytes,Archives::category_scope($serviceTag,true))),'native category file cannot import into a different Service taxonomy');
 $review=$preview($categoryBytes,$scope);$categoryRestore=$service->apply($review['token'],$phrase);$newRoot=$categoryRestore['categoryId'];$newChild=get_term_by('slug',$prefix.'-svc-child',$serviceTax);
 $assert($newRoot!==$root&&get_term_meta($newRoot,'_kiwe_seo_title',true)==='Native Service category SEO'&&$newChild&&(int)$newChild->parent===$newRoot&&$categoryRestore['count']===3,'native missing Service category restores its hierarchy SEO and complete new draft collection');
 $assert((int)get_post($categoryRestore['ids'][0])->post_parent===$categoryRestore['ids'][2]&&get_post($originalServices[0]),'native Service category hierarchy follows restored IDs while originals remain');
 $jobSources=[];foreach(range(0,14)as$index){$id=wp_insert_post(['post_type'=>$serviceType,'post_status'=>'draft','post_author'=>$users['client'],'post_title'=>$prefix.' Service set '.$index,'post_content'=>'<p>Bounded Service frame '.$index.'</p>','post_date'=>'2089-01-02 12:00:00','post_date_gmt'=>get_gmt_from_date('2089-01-02 12:00:00')],true);if(is_wp_error($id))throw new RuntimeException('Service set source unavailable');$id=(int)$id;$jobSources[]=$id;update_post_meta($id,'qa_duration',$index);update_post_meta($id,'qa_featured',$index%2?'1':'0');}
 wp_update_post(['ID'=>$jobSources[0],'post_parent'=>$jobSources[14]]);
 $job=$trackServiceJob($serviceJobsApi->start_export('services',0,$phrase,['from'=>'2089-01-02','to'=>'2089-01-02'],'synthetic-service-set-'.bin2hex(random_bytes(12))));$job=$serviceJobsApi->step($job['id'],$phrase,$job['revision']);
 $changeSource($plainType);$assert($rejects(fn()=>$serviceJobsApi->step($job['id'],$phrase,$job['revision'])),'native durable Service checkpoint stops after its source connection changes');$changeSource($serviceType);
 $job=$finishServiceJob($job,'ready');$assert($job['total']===15&&$job['status']==='ready','native date-selected Service set resumes across bounded parts');
 $import=$trackServiceJob($serviceJobsApi->start_import($serviceJobsApi->download_path($job['id']),$phrase,'service','synthetic-service-set-import-'.bin2hex(random_bytes(12))));$import=$finishServiceJob($import,'review');
 $assert($import['review']['count']===15&&$import['scope']==='services','native Service set reviews all records before importing any frame');
 $import=$serviceJobsApi->step($import['id'],$phrase,$import['revision'],true);$import=$finishServiceJob($import,'complete');$newIds=$import['result']['ids'];
 $assert(count($newIds)===15&&(int)get_post($newIds[0])->post_parent===$newIds[14]&&get_post_meta($newIds[14],'qa_duration',true)==='14'&&get_post_meta($newIds[0],'qa_featured',true)==='0','native Service set reconciles late parent and typed fields across parts');
 $assert(array_reduce($newIds,static fn($ok,$id)=>$ok&&get_post_status($id)==='draft',true)&&array_reduce($jobSources,static fn($ok,$id)=>$ok&&(bool)get_post($id),true),'native durable Service import creates only drafts and preserves every source');
 $changeSource($plainType);$plain=wp_insert_post(['post_type'=>$plainType,'post_status'=>'draft','post_author'=>$users['client'],'post_title'=>$prefix.' Taxonomyless Service'],true);if(is_wp_error($plain))throw new RuntimeException('Taxonomyless source unavailable');$plain=(int)$plain;update_post_meta($plain,DSA\Onboarding\Design_Context_Profile_Service::SERVICE_CATEGORY_PLAN_META,"Advisory > Planning\nSupport");
 $plainBytes=$saveArchive('service',$plain);$review=$preview($plainBytes,'service');$plainImport=$service->apply($review['token'],$phrase);
 $assert(get_post_meta($plainImport['ids'][0],DSA\Onboarding\Design_Context_Profile_Service::SERVICE_CATEGORY_PLAN_META,true)===get_post_meta($plain,DSA\Onboarding\Design_Context_Profile_Service::SERVICE_CATEGORY_PLAN_META,true),'native taxonomyless Service retains the Profile-owned category plan through draft restore');
 $changeSource('');$assert(!Archives::can_import('service')&&!Archives::can_export_collection('services'),'native unconnected Services do not become public records through backups');
} finally {
 remove_filter('user_has_cap',$grantServiceOnly,PHP_INT_MAX);if($serviceStaffRoleChanged)get_userdata($users['visitor'])->set_role('subscriber');$denyServiceField=false;wp_set_current_user($users['client']);
 foreach($serviceJobs as$id){$row=array_values(array_filter($jobIds,static fn($row)=>$row[0]===$id));if($row)wp_set_current_user($row[0][1]);$serviceJobsApi->remove($id);$jobIds=array_values(array_filter($jobIds,static fn($row)=>$row[0]!==$id));}
 wp_set_current_user($users['owner']);foreach(array_reverse(array_unique($posts))as$id){$post=get_post($id);if($post&&in_array($post->post_type,[$serviceType,$plainType],true)&&str_starts_with($post->post_title,$prefix))wp_delete_post($id,true);}
 foreach(array_reverse($terms)as[$id,$tax])if(in_array($tax,[$serviceTax,$serviceTag],true)){if(get_term($id,$tax))wp_delete_term($id,$tax);if(get_term($id,$tax))throw new RuntimeException('Native Service term cleanup failed');}
 // The parent later verifies every remaining term; unregistering this taxonomy would
 // make get_term return WP_Error even for an already removed ID, rather than null.
 $terms=array_values(array_filter($terms,static fn($row)=>!in_array($row[1],[$serviceTax,$serviceTag],true)));
 foreach([$serviceTax,$serviceTag]as$tax)if(taxonomy_exists($tax))unregister_taxonomy($tax);
 foreach([$serviceType,$plainType]as$type){foreach(array_keys(get_registered_meta_keys('post',$type))as$key)unregister_post_meta($type,$key);if(post_type_exists($type))unregister_post_type($type);}
 if(null===$previousProfile)delete_option($profileKey);else update_option($profileKey,$previousProfile,false);wp_set_current_user($serviceActor);
}
})();
