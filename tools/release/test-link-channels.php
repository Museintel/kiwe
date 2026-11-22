<?php
declare(strict_types=1);
namespace DSA\Onboarding { class Design_Context_Enhancement_Service { public function resolved_profile(): array { $GLOBALS['ownerResolutions']++; return $GLOBALS['profile']; } } }
namespace DSA\Site { class Site_Identity_Service { public static function store_phone(): string { return '+91 90000 00000'; } public static function store_email(): string { return $GLOBALS['businessEmail']; } } }
namespace {
define('ABSPATH',__DIR__.'/');
$ownerResolutions=0;$optionReads=0;$businessEmail='business@example.test';
$profile=['contact'=>['whatsapp'=>'+91 90000 00001','socialLinks'=>['instagram'=>'https://www.instagram.com/example/','x'=>'https://x.com/example','private'=>'DO_NOT_EXPORT']], 'private'=>'DO_NOT_EXPORT'];
function __($s,$domain=''){return$s;}
function sanitize_text_field($s){return strip_tags((string)$s);}
function sanitize_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL)?$s:'';}
function is_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL);}
function esc_url_raw($s,$protocols=null){return$s;}
function get_option($key,$default=false){$GLOBALS['optionReads']++;return$default;}
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Identity/Form_Contact_Service.php';require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Link_Hub/Channel_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Rest/Settings_Controller.php';
use DSA\Link_Hub\Channel_Service as Channels;
$checks=[];$check=static function(bool$ok,string$label)use(&$checks):void{if(!$ok)throw new \RuntimeException($label);$checks[]=$label;};
$contract=Channels::contract();
$check($ownerResolutions===0&&$optionReads===0,'Static action discovery does not read a site, user, option or provider');
$check($contract['attributes']['enquiry']['available']&&$contract['attributes']['share']['available'],'Implemented enquiry/sharing intents expose their shared attributes');
$check($contract['channels']['instagram']['prefill']==='unqualified'&&$contract['channels']['facebook']['prefill']==='unqualified','Unqualified Meta behavior cannot silently become universal prefill');
$check($contract['channels']['x']['requires']==='qualified-numeric-recipient-or-message-link','An X profile handle is not a messaging recipient ID');
$actions=Channels::site_actions();
$check($actions['phone']==='tel:+919000000000'&&$actions['email']==='mailto:business@example.test','Existing canonical public phone/email owners are used');
$check($actions['whatsapp']==='https://wa.me/919000000001','The resolved separate WhatsApp choice is not replaced by public phone');
$check(array_keys($actions['socials'])===['instagram','x']&&!str_contains(json_encode($actions),'DO_NOT_EXPORT'),'Only declared public profile destinations enter the projection');
foreach(['x'=>['https://twitter.com/messages/compose?recipient_id=123','https://x.com/messages/compose?recipient_id=123'],'facebook'=>['https://m.me/business','https://m.me/business'],'instagram'=>['https://ig.me/m/business','https://ig.me/m/business']]as$id=>$pair)$check(Channels::messaging_url($id,$pair[0])===$pair[1],'Accept explicit qualified message link '.$id);
foreach([['x','https://x.com/business'],['x','https://x.com/messages/compose?recipient_id=123&text=private'],['x','https://x.com/messages/compose?recipient_id=123&recipient_id=1'],['facebook','https://m.me/business?ref=private'],['instagram','https://ig.me/m/../'],['facebook','https://user@m.me/business'],['instagram','https://ig.me.evil.test/m/business'],['facebook','https://m.me:443/business'],['instagram',[]]]as$pair)$check(Channels::messaging_url($pair[0],$pair[1])==='','Refuse guessed/ambiguous/unqualified message link '.count($checks));
$profile['contact']['messagingLinks']=['x'=>'https://x.com/messages/compose?recipient_id=123'];$discovery=Channels::discovery();$check($discovery['configuredEnquiries']===['whatsapp','email','x','instagram']&&!str_contains(json_encode($discovery),'business@example.test'),'Read-only discovery exposes configured channels, not visitor or business contact values');unset($profile['contact']['messagingLinks']);

foreach([['instagram','https://www.instagram.com/example/','https://ig.me/m/example'],['facebook','https://www.facebook.com/business/','https://m.me/business'],['facebook','https://www.facebook.com/profile.php?id=123','https://m.me/123']]as$case)$check(Channels::profile_message_url($case[0],$case[1])===$case[2],'Standard account profile supplies its native messaging identifier');
foreach([['instagram','https://instagram.com/p/123'],['instagram','https://instagram.com/direct/'],['instagram','https://instagram.com/example?next=evil'],['instagram','https://instagram.com.evil.test/example'],['facebook','https://facebook.com/sharer.php'],['facebook','https://facebook.com/groups/123'],['facebook','https://facebook.com/profile.php?id=1&id=2'],['facebook','https://user@facebook.com/business'],['facebook','https://facebook.com:443/business'],['x','https://x.com/business']]as$case)$check(Channels::profile_message_url($case[0],$case[1])==='','Ambiguous, content, hostile and unsupported profiles cannot become a message recipient');
$profile['contact']['messagingLinks']=['instagram'=>'https://ig.me/m/override'];$check(Channels::site_actions()['messaging']['instagram']['url']==='https://ig.me/m/override','Explicit conversation wins over a derived profile account');$profile['contact']['messagingLinks']['instagram']='https://evil.test/';$check(!isset(Channels::site_actions()['messaging']['instagram']),'Invalid explicit conversation fails closed instead of silently choosing another account');unset($profile['contact']['messagingLinks']);
$rows=Channels::contact_rows($actions);
$check(array_column($rows,'id')===['whatsapp','email']&&array_column($rows,'kind')===['contact','contact'],'Contact destinations have explicit identity distinct from social profiles');
$check(Channels::contact_rows([])===[],'A site without public contacts has no invented contact rows');
foreach(['https://wa.me/000000','http://wa.me/919000000001','https://wa.me.evil.test/919000000001','https://user@wa.me/919000000001','https://wa.me/919000000001?text=untrusted','https://wa.me/919000000001#extra','https://wa.me/123','https://wa.me/1234567890123456',null,[]]as$url)$check(Channels::contact_rows(['whatsapp'=>$url])===[],'Reject malformed/ambiguous WhatsApp destination '.count($checks));
foreach(['mailto:user@example.test?bcc=other@example.test',"mailto:user@example.test\r\nBcc: other@example.test",'mailto:user%0d%0a@example.test','mailto:user@example.test,other@example.test','mailto:not-an-address','javascript:alert(1)',null,[]]as$url)$check(Channels::contact_rows(['email'=>$url])===[],'Reject email header injection or malformed recipient '.count($checks));
foreach(['javascript:alert(1)','data:text/html,unsafe','mailto:user@example.test','https://user:secret@example.test/profile',"https://example.test/\nprofile",'https://example.test\\@evil.test/profile',[]]as$url)$check(Channels::social_rows(['social_links'=>['facebook'=>$url]])===[],'Reject unsafe profile destination '.count($checks));
$config=['social_links'=>['facebook'=>'https://example.test/facebook','x'=>'https://x.com/example','unknown'=>'https://example.test/unknown']];
$socials=Channels::social_rows($config);
$check(array_column($socials,'id')===['facebook','x']&&count($socials[0])===3,'Valid legacy social projection retains its existing three-field shape');
foreach([DSA\Public_Endpoint\Assets::class,DSA\Rest\Settings_Controller::class]as$class){$reflection=new \ReflectionClass($class);$object=$reflection->newInstanceWithoutConstructor();$method=$reflection->getMethod('social_links');$check($method->invoke($object,$config)===$socials,'Initial and refresh projections share the same social owner: '.$class);}
$object=(new \ReflectionClass(DSA\Public_Endpoint\Assets::class))->newInstanceWithoutConstructor();$method=(new \ReflectionClass($object))->getMethod('contact_actions_data');$before=$ownerResolutions;$first=$method->invoke($object);$second=$method->invoke($object);
$check($first===$second&&$ownerResolutions===$before+1&&$optionReads===5,'Root contact data and Links reuse one owner resolution per Assets object');
$check(array_keys($first)===['phone','email','whatsapp','directions','socials']&&$first['directions']==='','Legacy page contact action keys and empty directions remain compatible');
$check(!function_exists('WC')&&!class_exists('Bricks\\Elements'),'The contact projection works without loading WooCommerce or Bricks');
$profile['contact']['whatsapp']='+91 90000 00000';
$check(Channels::site_actions()['whatsapp']==='https://wa.me/919000000000','The already-resolved same-as-phone choice also follows its owner');
$businessEmail='';$profile['contact']['whatsapp']='';
$check(Channels::site_contact_rows()===[],'Removing public contact choices removes their rows on fresh projection');
echo json_encode(['passed'=>true,'scope'=>'Actual Core channel/Assets/controller methods with native owner/escaping adapters stubbed; not a hosted or provider test','checks'=>$checks],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
