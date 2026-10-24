<?php
declare(strict_types=1);
use DSA\Onboarding\Design_Context_Profile_Service as Context;
require __DIR__.'/test-identity-app.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Context_Media_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Continuity_App_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Workspace_Media.php';
function wp_get_attachment_image_url($id,$size){return 'https://test.example/image.png';}
$attachments=[12=>(object)['ID'=>12,'post_type'=>'attachment','post_status'=>'inherit','post_password'=>'','post_parent'=>0,'post_title'=>'Studio portrait','post_excerpt'=>'Original caption','post_content'=>'<p>Original description.</p>','post_mime_type'=>'image/png'],13=>(object)['ID'=>13,'post_type'=>'attachment','post_status'=>'inherit','post_password'=>'','post_parent'=>0,'post_title'=>'Studio guide','post_excerpt'=>'','post_content'=>'','post_mime_type'=>'application/pdf']];
$test_page_meta[12]['_wp_attachment_image_alt']='Original image description';
function get_post($id){return $GLOBALS['attachments'][$id]??$GLOBALS['identity_test_attachments'][$id]??null;}
function wp_slash($value){return $value;}
function wp_update_post($data,$wp_error=false){$id=(int)($data['ID']??0);if(!isset($GLOBALS['attachments'][$id]))return $wp_error?new WP_Error('missing','missing'):0;foreach(array_diff_key($data,['ID'=>true])as$key=>$value)$GLOBALS['attachments'][$id]->$key=$value;return $id;}
function update_post_meta($id,$key,$value){$GLOBALS['test_page_meta'][$id][$key]=$value;return true;}
function delete_post_meta($id,$key){$exists=array_key_exists($key,$GLOBALS['test_page_meta'][$id]??[]);unset($GLOBALS['test_page_meta'][$id][$key]);return $exists;}
class ContinuityRequest extends WP_REST_Request {
 public function __construct(private string $area,string $method='GET',array $body=[],array $headers=['X-WP-Nonce'=>'wp_rest','X-Kiwe-Mutation'=>'1'],private array $query=[]){parent::__construct($method,$body,$headers);}
 public function get_url_params(){return ['area'=>$this->area];}
 public function get_param($key){return $this->query[$key]??null;}
}
$app=new DSA\Access\Continuity_App_Service();
$actor=1;$commerce=false;
$users[1]->caps['edit_post']=true;
foreach(['contact','story','brand','website-plan','services'] as $area){$r=$app->read(new ContinuityRequest($area));check($r->status===200&&is_array($r->data['fields'])&&preg_match('/^[a-f0-9]{64}$/',$r->data['expected']),'business projection available: '.$area);check($r->headers['Cache-Control']==='no-store, private','business response is private: '.$area);}
check($app->read(new ContinuityRequest('store'))->status===404,'store excluded when Woo unavailable');
$contactBefore=$options;
$options[Context::OPTION_PROFILE]['contact']['phone']='+919876543210';
$options[Context::OPTION_PROFILE]['contact']['whatsappSameAsPhone']=false;
$options[Context::OPTION_PROFILE]['contact']['whatsapp']='+919876543211';
$contact=$app->read(new ContinuityRequest('contact'))->data;
$contactFields=array_column($contact['fields'],'path');
check(in_array('contact.whatsapp',$contactFields,true),'A separate WhatsApp number is editable when the public phone is not used');
$same=$app->save(new ContinuityRequest('contact','POST',['path'=>'contact.whatsappSameAsPhone','value'=>true,'expected'=>$contact['expected']]));
check($same->status===200&&array_column($same->data['fields'],'path')===array_values(array_diff($contactFields,['contact.whatsapp'])),'Using the public phone hides only the separate WhatsApp card in the native save response');
check($same->data['values']['contact.whatsapp']===$same->data['values']['contact.phone'],'WhatsApp resolves to the public phone through the existing profile owner');
$afterSame=$options;
$reread=$app->read(new ContinuityRequest('contact'))->data;
check($options===$afterSame&&$reread['values']===$same->data['values']&&array_column($reread['fields'],'path')===array_column($same->data['fields'],'path'),'A fresh read retains the saved visibility and values without writing settings');
check($app->save(new ContinuityRequest('contact','POST',['path'=>'contact.whatsapp','value'=>'+919876543212','expected'=>$same->data['expected']]))->status===400&&$options===$afterSame,'A hidden alternate number cannot overwrite the derived public number');
check($app->save(new ContinuityRequest('contact','POST',['path'=>'contact.whatsappSameAsPhone','value'=>false,'expected'=>$contact['expected']]))->status===409&&$options===$afterSame,'A stale toggle cannot override the newer phone choice');
$separate=$app->save(new ContinuityRequest('contact','POST',['path'=>'contact.whatsappSameAsPhone','value'=>false,'expected'=>$reread['expected']]));
check($separate->status===200&&array_column($separate->data['fields'],'path')===$contactFields,'Choosing a separate WhatsApp number reveals the existing card again');
$separateNumber=$app->save(new ContinuityRequest('contact','POST',['path'=>'contact.whatsapp','value'=>'+919876543211','expected'=>$separate->data['expected']]));
check($separateNumber->status===200&&$separateNumber->data['values']['contact.whatsapp']==='+919876543211'&&$separateNumber->data['values']['contact.phone']===$separate->data['values']['contact.phone'],'The revealed card saves an independent WhatsApp number without changing the public phone');
$options=$contactBefore;
echo "Conditional WhatsApp contact cards passed.\n";
$read=$app->read(new ContinuityRequest('brand'))->data;
$before=$options;
$save=$app->save(new ContinuityRequest('brand','POST',['path'=>'brand.notes','value'=>'Keep the warm colours.','expected'=>$read['expected'],'contact'=>['email'=>'forged@example.test']]));
check($save->status===200&&$save->data['values']['brand.notes']==='Keep the warm colours.','tile save uses native profile writer and readback');
check($options[Context::OPTION_PROFILE]['contact']===$before[Context::OPTION_PROFILE]['contact'],'tile change preserves unrelated contact data');
check($app->save(new ContinuityRequest('brand','POST',['path'=>'brand.notes','value'=>'stale','expected'=>$read['expected']]))->status===409,'stale tile cannot overwrite newer edits');
check($app->save(new ContinuityRequest('brand','POST',['path'=>'contact.email','value'=>'forged@example.test','expected'=>$save->data['expected']]))->status===400,'business path cannot cross its section');
foreach([['role'=>'brand','hex'=>'javascript:bad'],['role'=>'admin','hex'=>'#123456']]as$bad){check($app->save(new ContinuityRequest('brand','POST',['path'=>'brand.colors','value'=>[$bad],'expected'=>$save->data['expected']]))->status===400,'invalid colour is rejected');}
$palette=$app->save(new ContinuityRequest('brand','POST',['path'=>'brand.colors','value'=>[['role'=>'brand','hex'=>'#123456']],'expected'=>$save->data['expected']]));
check($palette->status===200&&$palette->data['values']['brand.colors'][0]['hex']==='#123456','custom colour reaches the canonical palette');
foreach([[],['X-WP-Nonce'=>'bad','X-Kiwe-Mutation'=>'1'],['X-WP-Nonce'=>'wp_rest']]as$headers)check($app->save(new ContinuityRequest('brand','POST',[],$headers))->status===403,'mutation requires nonce and intent proof');
$_SERVER['HTTP_ORIGIN']='https://unrelated.example';check($app->read(new ContinuityRequest('brand'))->status===403,'cross-origin read denied');unset($_SERVER['HTTP_ORIGIN']);
$actor=3;check($app->read(new ContinuityRequest('story'))->status===403,'lower role cannot see business context');$actor=1;
check($app->read(new ContinuityRequest('brand','POST'))->status===403&&$app->save(new ContinuityRequest('brand','GET'))->status===403,'wrong method cannot mutate or read');
$read=$app->read(new ContinuityRequest('services'))->data;
check($app->save(new ContinuityRequest('services','POST',['path'=>'services.items','expected'=>$read['expected'],'value'=>[['recordId'=>999,'title'=>'Forged','imageId'=>0]]]))->status===400,'unknown service record rejected before write');
$read=$app->read(new ContinuityRequest('services'))->data;
$plan=['recordId'=>0,'stableId'=>'plan-new','title'=>'Consultation','status'=>'draft','imageId'=>0,'menuOrder'=>0];
foreach([array_replace($plan,['title'=>[]]),array_replace($plan,['meta'=>['forged'=>'value']]),array_replace($plan,['taxonomyPaths'=>['forged'=>'Category']])]as$invalid)check($app->save(new ContinuityRequest('services','POST',['path'=>'services.items','expected'=>$read['expected'],'value'=>[$invalid]]))->status===400,'Malformed service cannot reach native writer');
check($app->save(new ContinuityRequest('services','POST',['path'=>'services.items','expected'=>$read['expected'],'value'=>[$plan,$plan]]))->status===400,'Duplicate service plans refused');
$options[Context::OPTION_PROFILE]['services']=['enabled'=>true,'sourcePostType'=>'','useForNavigation'=>true,'items'=>[
 array_replace($plan,['stableId'=>'plan-consultation','title'=>'Consultation','summary'=>'One-to-one help','categoryPaths'=>'Advice']),
 array_replace($plan,['stableId'=>'plan-audit','title'=>'Audit','summary'=>'Independent review','menuOrder'=>1]),
]];
$plans=$app->read(new ContinuityRequest('services','GET',[],['X-WP-Nonce'=>'wp_rest'],['mode'=>'records']))->data;
check(!empty($plans['planMode'])&&empty($plans['recordMode'])&&preg_match('/^[a-f0-9]{64}$/',$plans['planExpected'])&&preg_match('/^[a-f0-9]{64}$/',$plans['values']['services.items'][0]['expected']),'unconnected plans expose collection and per-plan fingerprints');
$consultation=$plans['values']['services.items'][0];$consultation['title']='Focused consultation';
$single=$app->save(new ContinuityRequest('services','POST',['operation'=>'service_plan','action'=>'save','value'=>array_diff_key($consultation,['expected'=>true]),'expected'=>$consultation['expected']]));
check($single->status===200&&$single->data['saved']['title']==='Focused consultation'&&$options[Context::OPTION_PROFILE]['services']['items'][1]['title']==='Audit','one plan saves without replacing its neighbour');
check($app->save(new ContinuityRequest('services','POST',['operation'=>'service_plan','action'=>'save','value'=>array_diff_key($consultation,['expected'=>true]),'expected'=>$consultation['expected']]))->status===409,'stale plan replay is refused');
$plans=$app->read(new ContinuityRequest('services'))->data;$audit=$plans['values']['services.items'][1];
$removed=$app->save(new ContinuityRequest('services','POST',['operation'=>'service_plan','action'=>'delete','value'=>['stableId'=>$audit['stableId'],'recordId'=>0],'expected'=>$audit['expected']]));
check($removed->status===200&&$removed->data['deleted']==='plan-audit'&&count($options[Context::OPTION_PROFILE]['services']['items'])===1,'one plan can be explicitly removed without deleting the collection');
check($app->save(new ContinuityRequest('services','POST',['operation'=>'service_plan','action'=>'save','value'=>array_replace($plan,['stableId'=>'plan-forged','option'=>'admin']),'expected'=>$removed->data['planExpected']]))->status===400,'plan writer rejects injected fields');
echo "Continuity business boundary checks passed.\n";

$detail=$app->read(new ContinuityRequest('library','GET',[],['X-WP-Nonce'=>'wp_rest'],['operation'=>'attachment','id'=>12]));
check($detail->status===200&&array_keys($detail->data)===['id','title','caption','description','alt','mime','isImage','selected','explicit','automatic','public','reason','expected']&&!$detail->data['selected']&&preg_match('/^[a-f0-9]{64}$/',$detail->data['expected']),'attachment details are fetched only on demand from WordPress');
$saved=$app->save(new ContinuityRequest('library','POST',['operation'=>'attachment_field','id'=>12,'field'=>'alt','value'=>'A warm studio portrait','expected'=>$detail->data['expected']]));
check($saved->status===200&&get_post_meta(12,'_wp_attachment_image_alt',true)==='A warm studio portrait'&&$saved->data['alt']==='A warm studio portrait'&&!$saved->data['selected'],'image description save confirms native metadata without selecting Design Context');
check($app->save(new ContinuityRequest('library','POST',['operation'=>'attachment_field','id'=>12,'field'=>'title','value'=>'Stale title','expected'=>$detail->data['expected']]))->status===409,'stale attachment edit cannot overwrite newer metadata');
$saved=$app->save(new ContinuityRequest('library','POST',['operation'=>'attachment_field','id'=>12,'field'=>'caption','value'=>'<script>bad</script><p>Portrait caption</p>','expected'=>$saved->data['expected']]));
check($saved->status===200&&$attachments[12]->post_excerpt==='bad<p>Portrait caption</p>'&&!$saved->data['selected'],'caption uses the native attachment post and preserves explicit curation state');
check($app->save(new ContinuityRequest('library','POST',['operation'=>'attachment_field','id'=>12,'field'=>'title','value'=>'Forged','expected'=>$saved->data['expected'],'post_parent'=>999]))->status===400&&$attachments[12]->post_parent===0,'forged attachment fields are refused before a native write');
$document=$app->read(new ContinuityRequest('library','GET',[],['X-WP-Nonce'=>'wp_rest'],['operation'=>'attachment','id'=>13]));
check($document->status===200&&!$document->data['isImage']&&$app->save(new ContinuityRequest('library','POST',['operation'=>'attachment_field','id'=>13,'field'=>'alt','value'=>'Not valid for a document','expected'=>$document->data['expected']]))->status===400,'non-image attachments cannot receive image alt metadata');
$users[1]->caps['edit_post']=false;check($app->read(new ContinuityRequest('library','GET',[],['X-WP-Nonce'=>'wp_rest'],['operation'=>'attachment','id'=>12]))->status===404,'attachment details obey the native object edit capability');$users[1]->caps['edit_post']=true;
echo "Native attachment detail and explicit curation checks passed.\n";

$definition=['source'=>'studio_service','hierarchical'=>true,'canCreate'=>true,'canPublish'=>true,'fields'=>['price'=>['type'=>'number'],'duration'=>['type'=>'integer'],'featured'=>['type'=>'boolean'],'note'=>['type'=>'string']],'taxonomies'=>['service_category'=>['hierarchical'=>true]]];
$wanted=['meta'=>['price'=>'12.5','duration'=>'30','featured'=>true,'note'=>'Advice'],'taxonomyPaths'=>['service_category'=>"Care>Consultation,Other\nCare > Consultation"]];
$actual=['meta'=>['price'=>'12.50','duration'=>30,'featured'=>true,'note'=>'Advice'],'taxonomyPaths'=>['service_category'=>"Other\nCare > Consultation"]];
check(invoke($app,'service_fields_match',$wanted,$actual,$definition),'Native numeric storage and reordered category sets confirm correctly');
foreach([array_replace_recursive($actual,['meta'=>['price'=>'13']]),array_replace_recursive($actual,['meta'=>['featured'=>false]]),array_replace_recursive($actual,['taxonomyPaths'=>['service_category'=>'Other']])]as$rejected)check(!invoke($app,'service_fields_match',$wanted,$rejected,$definition),'Rejected metadata/category writes cannot report success');
check(!invoke($app,'service_fields_match',['meta'=>['price'=>'']],['meta'=>['price'=>'0']],$definition),'Clearing a number is distinct from zero');
$validation=['service'=>$definition,'values'=>['services.items'=>[]]];
foreach([['duration'=>'1.5'],['price'=>'not a number'],['price'=>INF],['featured'=>'false']]as$invalid)check(is_wp_error(invoke($app,'validate_services',[array_replace($plan,['meta'=>$invalid])],$validation)),'Invalid typed service metadata fails before write');
foreach([[array_replace($plan,['recordId'=>1,'parentId'=>1])],[array_replace($plan,['recordId'=>1,'parentId'=>2]),array_replace($plan,['recordId'=>2,'parentId'=>1])],[array_replace($plan,['recordId'=>1,'parentId'=>999])]]as$invalid)check(is_wp_error(invoke($app,'validate_services',$invalid,$validation)),'Invalid service hierarchy fails before write');
echo "Service write confirmation and hierarchy checks passed.\n";

check(!array_intersect(['commerce.taxEnabled','commerce.pricesIncludeTax'],array_column(DSA\Access\Continuity_App_Service::fields()['store']['fields'],'path')),'Tax switches have a single client editing home outside Store setup');
