<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');
$checks=[];$allowed=true;
function current_user_can($cap){return$GLOBALS['allowed'];}
function sanitize_text_field($v){return trim(strip_tags($v));}
function is_email($v){return(bool)filter_var($v,FILTER_VALIDATE_EMAIL);}
function sanitize_email($v){return filter_var($v,FILTER_SANITIZE_EMAIL);}
function wp_json_encode($v){return json_encode($v);}
function wp_salt($v){return'synthetic-checkout-test-only';}
function current_time($v){return'2026-10-25 12:00:00';}
function is_serialized($v){return is_string($v)&&preg_match('/^(?:a|s|b|i|d|O):/',$v);}
function get_option($key,$default=false){return$GLOBALS['options'][$key]??$default;}
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Commerce/Checkout_Details_Model.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Commerce/Checkout_Session_Details.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Commerce/Checkout_Field_Service.php';
use DSA\Commerce\Checkout_Details_Model as Model;
use DSA\Commerce\Checkout_Session_Details as Sessions;
use DSA\Commerce\Checkout_Field_Service as Fields;
function check($ok,$label){$GLOBALS['checks'][$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);}
class CheckoutDetailsDatabase {
 public $prefix='fixture_';public $reads=0;public $payloadReads=0;public $hashKey='test-session';public $payload;public $miss=false;
 function prepare($sql,...$args){return$sql.'|'.json_encode($args);}
 function get_results($sql,$mode){$this->reads++;return[['session_id'=>12,'session_key'=>$this->miss?'other-session':$this->hashKey]];}
 function get_var($sql){$this->payloadReads++;return$this->payload;}
}
try{
 $fields=['billing_first_name'=>'Visitor','billing_email'=>'VISITOR@example.test','billing_phone'=>'+1 (555) 555-0101','billing_address_1'=>'A synthetic address','billing_city'=>'Example city','billing_country'=>'IN','shipping_address_1'=>'Old shipping address','account_password'=>'excluded','payment_token'=>'excluded','order_comments'=>'excluded'];
 $v=Model::envelope($fields,'dsa',current_time('mysql'));
 check($v['billing']['email']==='visitor@example.test'&&$v['billing']['phone']==='+15555550101','Contact normalized with native-shaped inputs');
 check(!str_contains(wp_json_encode($v),'excluded')&&!$v['shipping'],'Only fixed operational fields and selected shipping are retained');
 check(Model::decode(wp_json_encode($v))===$v,'Bounded envelope roundtrip');
 $cleared=Model::normalize(['billing_phone'=>'','billing_email'=>'not an email']);check($cleared['billing']===['email'=>'','phone'=>'']&&!Model::meaningful($cleared),'Explicit clears remain distinct from absent fields');
 $different=Model::normalize($fields+['ship_to_different_address'=>'1']);check($different['shipping']['address_1']==='Old shipping address','Selected separate shipping retained');
 $bad=Model::normalize(['billing_first_name'=>['nested'],'billing_address_1'=>str_repeat('a',601),'billing_country'=>'not-country']);check(!isset($bad['billing']['first_name'],$bad['billing']['address_1'])&&$bad['billing']['country']==='','Nested oversized and malformed values excluded');
 check(Model::decode(str_repeat('x',16385))===null&&Model::decode('{"schema":"other"}')===null,'Unknown and oversized snapshots refuse');
 check(Fields::valid(['phone'=>'required','company'=>'hidden','address_2'=>'optional'])&&!Fields::valid(['phone'=>['required'],'company'=>'hidden','address_2'=>'optional'])&&!Fields::valid(['phone'=>'required']),'Native checkout field choices reject nested and incomplete controls');
 check(Fields::projection()===Fields::DEFAULTS,'Classic defaults remain native-shaped');$options=['woocommerce_checkout_phone_field'=>'optional'];check(Fields::projection()['phone']==='optional','Existing native option reflected');
 $wpdb=new CheckoutDetailsDatabase();$cart=[['product_id'=>7,'variation_id'=>0,'quantity'=>2,'variation'=>[]]];
 $wpdb->payload=serialize(['cart'=>serialize($cart),'dsa_checkout_draft'=>serialize($fields)]);
 $row=['status'=>'abandoned','checkout_details_at'=>current_time('mysql'),'customer_hash'=>hash_hmac('sha256','wc:'.$wpdb->hashKey,wp_salt('auth')),'user_id'=>0,'items'=>wp_json_encode($cart)];
 $found=Sessions::recover($row);check($found&&$found['source']==='active-session'&&$wpdb->payloadReads===1,'Matching guest binding and exact cart admit active-session recovery');
 $other=$row;$other['items']=wp_json_encode([array_replace($cart[0],['quantity'=>3])]);check(Sessions::recover($other)===null,'Different cart quantities refuse historical recovery');
 $wpdb->miss=true;$before=$wpdb->payloadReads;check(Sessions::recover($row)===null&&$wpdb->payloadReads===$before,'Other shoppers payloads are never read');
 $allowed=false;$before=$wpdb->reads;check(Sessions::recover($row)===null&&$wpdb->reads===$before,'Unauthorized review does not scan session keys');
 echo json_encode(['passed'=>true,'checks'=>$checks,'cleanup'=>true,'nativeSqlExecuted'=>false])."\n";
}catch(Throwable$e){echo json_encode(['passed'=>false,'checks'=>$checks,'error'=>$e->getMessage()])."\n";exit(1);}
