<?php
declare(strict_types=1);
/** Actual private projection with synthetic WordPress/PhoneKey I/O. No credential or delivery adapter. */
namespace DSA { final class Settings { public function all():array{return ['abandoned_cart'=>['enabled'=>true]];} public function defaults():array{return [];} } }
namespace DSA\Commerce { final class Store_Analytics_Service {} }
namespace DSA\Communications { final class Channel_Service { public function recovery_config():array{return ['enabled'=>true];} } }
namespace DSA\Security { final class Secret_Store { public static function decrypt(string $value):string{return str_starts_with($value,'sealed:')?substr($value,7):'';} } }
namespace {
 define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';$allowed=true;$checks=0;$factorReads=0;$users=[1=>(object)['user_email'=>'shopper@example.test','display_name'=>'Shopper'],2=>(object)['user_email'=>'phone@phonekey.invalid','display_name'=>'Phone shopper']];$factors=[2=>['status'=>'verified','factor_value'=>'+15555550101']];
 function current_user_can($cap){return$GLOBALS['allowed'];}function get_option($key,$default=false){return$key==='dsa_abandoned_cart_db_version'?'4':$default;}function get_userdata($id){return$GLOBALS['users'][$id]??false;}function get_user_meta($id,$key,$single=true){return$id===2&&$key==='pk_phone_last4'?'0101':'';}function absint($v){return abs((int)$v);}function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function sanitize_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL)?$v:'';}function is_email($v){return(bool)filter_var($v,FILTER_VALIDATE_EMAIL);}function sanitize_text_field($v){return trim(strip_tags((string)$v));}function __($v,$domain=''){return$v;}function wp_strip_all_tags($v){return strip_tags($v);}function wc_price($v,$options=[]){return '<span>&#8377;'.number_format($v,2,'.','').'</span>';}function get_woocommerce_currency(){return'INR';}function number_format_i18n($v,$p){return number_format($v,$p);}function pk_factor($id,$type){$GLOBALS['factorReads']++;return$GLOBALS['factors'][$id]??null;}function pk_decrypt($v){return$v;}function WC(){return true;}function wp_parse_args($v,$defaults=[]){return array_merge($defaults,$v);}
 class WC_Order {}
 function wp_json_encode($v){return json_encode($v);}
 $wpdb=new class {public string $prefix='synthetic_';public array $rows=[];public function prepare($sql,...$args){return$sql;}public function get_results($sql,$mode){return$this->rows;}};define('ARRAY_A','ARRAY_A');
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/Abandoned_Cart_Service.php';
 $carts=new \DSA\Commerce\Abandoned_Cart_Service(new \DSA\Settings(),new \DSA\Commerce\Store_Analytics_Service(),new \DSA\Communications\Channel_Service());$label=new ReflectionMethod($carts,'customer_label');$money=new ReflectionMethod($carts,'money');
 function check($ok,$label){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($label);}
 check($label->invoke($carts,['user_id'=>0])==='Unknown','unknown browser identity is not a total or hash');
 check($label->invoke($carts,['user_id'=>1])==='shopper@example.test','known email without marketing enrollment');check($factorReads===0,'known email needs no PhoneKey factor lookup');
 check($label->invoke($carts,['user_id'=>2,'contact_type'=>'phone'])==='+15555550101','known verified PhoneKey phone rather than synthetic email');$reads=$factorReads;$label->invoke($carts,['user_id'=>2,'contact_type'=>'phone']);check($reads===$factorReads,'repeated phone uses request-local identity cache');
 check($label->invoke($carts,['user_id'=>0,'contact_email_enc'=>'sealed:guest@example.test'])==='guest@example.test','already captured guest contact uses existing encrypted owner');
 check($label->invoke($carts,['user_id'=>999,'contact_email_enc'=>'sealed:old@example.test'])==='Unknown','removed account does not revive old guest identity');
	$summary=new ReflectionMethod($carts,'customer_summary');$journey=new ReflectionMethod($carts,'journey');
	check($summary->invoke($carts,['user_id'=>1])['contactKnown']===true,'real email receives the known-contact cue without reminder opt-in');
	check($summary->invoke($carts,['user_id'=>0,'contact_phone_enc'=>'sealed:+15555550102'])===['customer'=>'+15555550102','contactKnown'=>true],'encrypted guest phone is a known contact');
	check($summary->invoke($carts,['user_id'=>0,'contact_phone_enc'=>'sealed:not-a-phone'])['contactKnown']===false,'invalid captured phone is never green');
	$users[3]=(object)['user_email'=>'generated@phonekey.invalid','display_name'=>'Account name only'];check($summary->invoke($carts,['user_id'=>3])===['customer'=>'Account name only','contactKnown'=>false],'a display name alone is not a known email or phone');
	check($journey->invoke($carts,['status'=>'abandoned'])['journeyLabel']==='Checkout visit not recorded','historical carts do not claim a checkout visit');
	check($journey->invoke($carts,['status'=>'abandoned','checkout_details_at'=>'2026-10-22 10:00:00'])['journeyLabel']==='Checkout form started','sheet details are distinct from the checkout page');
	check($journey->invoke($carts,['status'=>'abandoned','checkout_started_at'=>'2026-10-22 10:01:00'])['journeyLabel']==='Left at checkout','actual abandoned checkout has its clear cue');
	check($journey->invoke($carts,['status'=>'active','checkout_started_at'=>'2026-10-22 10:01:00'])['journeyLabel']==='Reached checkout','active checkout is not labelled abandoned');
	check($journey->invoke($carts,['status'=>'converted','checkout_started_at'=>'2026-10-22 10:01:00'])['journeyLabel']==='Order placed','placed order does not remain a checkout abandonment');
 $allowed=false;check($label->invoke($carts,['user_id'=>1])==='Unknown','private title is denied before cache lookup');check($carts->workspace_rows()['items']===[],'denied role receives no list identity');$allowed=true;
 check($money->invoke($carts,523.16,'INR')==='₹523.16','Woo numeric currency entity is decoded as text');
 $wpdb->rows=[['id'=>1,'user_id'=>1,'contact_type'=>'email','contact_email_enc'=>'sealed:unused@example.test','status'=>'abandoned','last_activity_at'=>'2026-10-14 00:00:00','item_count'=>2,'cart_total'=>523.16,'currency'=>'INR','reminder_count'=>0]];
 $row=$carts->workspace_rows()['items'][0];check($row['customer']==='shopper@example.test'&&$row['total']==='₹523.16','list keeps identity and native amount separate');check(!isset($row['contact_email_enc'])&&!isset($row['user_id']),'list projects only the authorized presentation fields');
 echo json_encode(['passed'=>true,'checks'=>$checks,'sends'=>0])."\n";
}
