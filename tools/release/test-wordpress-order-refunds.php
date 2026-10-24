<?php
/** Isolated real Woo fixture only; synthetic gateway never contacts a provider. */
if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION'))exit(2);
use DSA\Access\Order_Workspace_Service as Orders;
use DSA\Access\Site_Owner_Service as Owner;
use Automattic\WooCommerce\Utilities\OrderUtil;
class Kiwe_Refund_QA_Gateway extends WC_Payment_Gateway {
 public static array $calls=[];public static string $scenario='success';
 public function __construct(){$this->id='kiwe_refund_qa';$this->title='Synthetic refund gateway';$this->enabled='yes';$this->supports=['products','refunds'];}
 public function process_refund($id,$amount=null,$reason=''){
  self::$calls[]=['order'=>(int)$id,'amount'=>(string)$amount,'scenario'=>self::$scenario];
 if(self::$scenario==='error'||self::$scenario==='applied_error')return new WP_Error('qa_declined','Synthetic provider detail must not be returned');
  if(self::$scenario==='false')return false;
  if(self::$scenario==='throw')throw new Error('Synthetic unreadable provider outcome');
  if(self::$scenario==='race')wc_create_refund(['order_id'=>$id,'amount'=>'1.00','reason'=>'Concurrent native refund','refund_payment'=>false]);
  return true;
 }
}
$proof=['schema'=>'kiwe.native-refunds.v1','version'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'woocommerce'=>WC_VERSION,'storage'=>KIWE_ORDER_TEST_STORAGE,'checks'=>[],'cleanup'=>false];
$users=[];$orderIds=[];$productIds=[];$filters=[];$taxIds=[];$http=[];$mail=[];$actor=get_current_user_id();$prefix='kiwerefundqa_'.bin2hex(random_bytes(4));$orders=new Orders();
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$filter=static function($hook,$cb,$argc=1)use(&$filters){add_filter($hook,$cb,PHP_INT_MAX,$argc);$filters[]=[$hook,$cb];};
$request=static function($method,$id,$body=[],$suffix=''){$r=new WP_REST_Request($method,'/dsa/v1/workspace/commerce/orders/'.$id.$suffix);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_header('X-Kiwe-Mutation','1');$r->set_header('Content-Type','application/json');if($method==='POST')$r->set_body(wp_json_encode($body));return rest_do_request($r);};
$body=static function($id,$patch=[])use($orders){$row=$orders->review($id);if(is_wp_error($row))throw new RuntimeException($row->get_error_code());return array_replace(['operation'=>'create','expected'=>$row['expected'],'confirmed'=>true,'intent'=>wp_generate_uuid4(),'mode'=>'gateway','amount'=>'1.00','reason'=>'Synthetic return','reference'=>'','restock'=>false,'lines'=>[]],$patch);};
$send=static fn($id,$patch=[])=>$request('POST',$id,$body($id,$patch),'/refunds');
$make=static function($method='kiwe_refund_qa',$paid=true)use(&$orderIds,&$productIds,&$taxIds,$prefix){
 $p=new WC_Product_Simple();$p->set_name($prefix.' item');$p->set_status('publish');$p->set_regular_price('10');$p->set_manage_stock(true);$p->set_stock_quantity(10);$p->save();$productIds[]=$p->get_id();
 $o=wc_create_order();if(is_wp_error($o))throw new RuntimeException('Synthetic order');$orderIds[]=$o->get_id();$o->set_payment_method($method);$o->set_payment_method_title('Synthetic '.$method);$o->set_currency('INR');$o->set_address(['first_name'=>'Synthetic','last_name'=>'Refund','email'=>'synthetic@example.invalid','country'=>'IN'],'billing');
 $item=new WC_Order_Item_Product();$item->set_product($p);$item->set_quantity(2);$item->set_total('20');$item->set_subtotal('20');$item->set_taxes(['total'=>[$taxIds[0]=>'2.00',$taxIds[1]=>'0.00'],'subtotal'=>[$taxIds[0]=>'2.00',$taxIds[1]=>'0.00']]);$o->add_item($item);
 $shipping=new WC_Order_Item_Shipping();$shipping->set_method_title('Synthetic delivery');$shipping->set_total('2');$shipping->set_taxes(['total'=>[$taxIds[0]=>'0.20']]);$o->add_item($shipping);
 $fee=new WC_Order_Item_Fee();$fee->set_name('Synthetic packing');$fee->set_total('1');$fee->set_taxes(['total'=>[$taxIds[0]=>'0.10']]);$o->add_item($fee);
 $o->add_meta_data('_synthetic_vendor_order','preserve');$o->update_taxes();$o->calculate_totals(false);$o->save();if($paid)$o->payment_complete('QA-PAID-'.$o->get_id());return[$o->get_id(),$p->get_id(),(int)$item->get_id()];
};
$resolve=static function($id,$resolution,$patch=[])use($orders,$request){$r=$orders->review($id);return$request('POST',$id,array_replace(['operation'=>'resolve','expected'=>$r['expected'],'confirmed'=>true,'intent'=>$r['refund']['guard']['intent'],'resolution'=>$resolution,'reference'=>'QA checked provider','stockReviewed'=>true],$patch),'/refunds');};
try{
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'exact current candidate manifest');
 $assert(OrderUtil::custom_orders_table_usage_is_enabled()===(KIWE_ORDER_TEST_STORAGE==='hpos'),'native storage mode matches fixture');
 $filter('pre_http_request',static function()use(&$http){$http[]=true;return new WP_Error('qa_closed','No provider HTTP');},3);
 $filter('pre_wp_mail',static function()use(&$mail){$mail[]=true;return true;},2);
 foreach(WC()->mailer()->get_emails()as$email)$filter('woocommerce_email_enabled_'.$email->id,static fn()=>false,3);
 foreach(['customer_partially_refunded_order','customer_fully_refunded_order']as$email)$filter('woocommerce_email_enabled_'.$email,static fn()=>false,3);
 $filter('pre_option_'.Owner::OPTION,static function()use(&$users){return$users['owner']??0;});
 $filter('woocommerce_payment_gateways',static fn($all)=>array_merge($all,[Kiwe_Refund_QA_Gateway::class]));
 WC()->payment_gateways()->init();update_option('woocommerce_manage_stock','yes');update_option('woocommerce_calc_taxes','yes');update_option('woocommerce_currency','INR');update_option('woocommerce_price_num_decimals',2);
 $taxIds[]=(int)WC_Tax::_insert_tax_rate(['tax_rate_country'=>'','tax_rate_state'=>'','tax_rate'=>'10','tax_rate_name'=>'QA tax','tax_rate_priority'=>1,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>0,'tax_rate_class'=>'']);
 $taxIds[]=(int)WC_Tax::_insert_tax_rate(['tax_rate_country'=>'','tax_rate_state'=>'','tax_rate'=>'0','tax_rate_name'=>'QA zero','tax_rate_priority'=>2,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>1,'tax_rate_class'=>'']);
 foreach(['owner'=>'administrator','client'=>'administrator','manager'=>'shop_manager','editor'=>'editor','author'=>'author','subscriber'=>'subscriber','customer'=>'customer']as$key=>$role){$id=wp_insert_user(['user_login'=>$prefix.'_'.$key,'user_pass'=>wp_generate_password(30),'user_email'=>$prefix.'_'.$key.'@example.invalid','role'=>$role]);$assert(!is_wp_error($id),'native actor '.$key);$users[$key]=(int)$id;}
 wp_set_current_user($users['client']);[$id,$product,$line]=$make();$row=$orders->review($id);$proof['initialInvoice']=is_wp_error($row)?['error'=>$row->get_error_code()]:['class'=>get_class(wc_get_order($id)),'total'=>$row['total'],'status'=>$row['status'],'paidAt'=>$row['paidAt'],'refund'=>$row['refund']];$assert(!is_wp_error($row)&&$row['refund']['canCreate']&&$row['refund']['canGateway']&&$row['refund']['remaining']==='25.30','native paid invoice and refund eligibility');
 $assert(count($row['refund']['lines'])===3&&$row['refund']['lines'][0]['taxes'][$taxIds[1]]==='0.00','native product shipping fee and zero tax projections');
 foreach(['client'=>200,'manager'=>200,'editor'=>403,'author'=>403,'subscriber'=>403,'customer'=>403]as$role=>$status){wp_set_current_user($users[$role]);$r=$request('GET',$id);$assert($r->get_status()===$status,'native '.$role.' order visibility');if($status===403)$assert($request('POST',$id,$bodyForDenied=['operation'=>'create','confirmed'=>true],'/refunds')->get_status()===403,'native '.$role.' refund denied');}
 wp_set_current_user($users['client']);$draft=$body($id);
 $r=new WP_REST_Request('POST','/dsa/v1/workspace/commerce/orders/'.$id.'/refunds');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_body(wp_json_encode($draft));$r->set_header('Content-Type','application/json');$assert(rest_do_request($r)->get_status()===403,'refund mutation proof required');
 $assert($request('POST',$id,array_replace($draft,['confirmed'=>false]),'/refunds')->get_status()===400,'explicit refund confirmation required');
 $assert($request('POST',$id,array_replace($draft,['currency'=>'USD']),'/refunds')->get_status()===400,'caller cannot change currency or arbitrary financial fields');
 foreach(['0','-1','1e1','1,00','1.001','1000','9999999999999']as$amount)$assert($request('POST',$id,array_replace($draft,['amount'=>$amount]),'/refunds')->get_status()===400,'strict refund amount '.$amount);
 $assert(count(Kiwe_Refund_QA_Gateway::$calls)===0&&!wc_get_order($id)->get_refunds()&&!wc_get_order($id)->get_meta('_kiwe_refund_guard_v1'),'invalid inputs leave no refund guard gateway or ledger write');
 $second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$key='kiwe_order_'.substr(hash('sha256',DB_NAME.':'.$GLOBALS['wpdb']->prefix.':'.$id),0,40);$assert((string)$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$key))==='1','native shared order lock acquired');$assert($request('POST',$id,$draft,'/refunds')->get_status()===409,'busy refund rejected before provider');$second->get_var($second->prepare('SELECT RELEASE_LOCK(%s)',$key));$second->close();
 wc_get_order($id)->add_order_note('Concurrent refund review',0,true);$assert($request('POST',$id,$draft,'/refunds')->get_status()===409,'native notes invalidate refund snapshot');
 $fresh=$body($id);$gateway=WC()->payment_gateways()->payment_gateways()['kiwe_refund_qa'];$gateway->settings['qa_changed']=true;$assert($request('POST',$id,$fresh,'/refunds')->get_status()===409,'gateway configuration change invalidates snapshot');
 $nativeBefore=$body($id);wc_create_refund(['order_id'=>$id,'amount'=>'0','reason'=>'Native zero marker','refund_payment'=>false]);$assert($request('POST',$id,$nativeBefore,'/refunds')->get_status()===409,'native zero refund identity invalidates snapshot');

 [$manual,$manualStock,$manualLine]=$make('bacs');$manualRow=$orders->review($manual);$assert($manualRow['refund']['canCreate']&&!$manualRow['refund']['canGateway'],'offline refunds are recorded manually only');
 $item=['id'=>$manualLine,'quantity'=>1,'total'=>'10.00','taxes'=>[(string)$taxIds[0]=>'1.00',(string)$taxIds[1]=>'0.00']];
 $manualDraft=$body($manual,['mode'=>'manual','amount'=>'11.00','reference'=>'QA-MANUAL-1','restock'=>true,'lines'=>[$item]]);
 $bad=$manualDraft;$bad['lines'][0]['id']=$line;$assert($request('POST',$manual,$bad,'/refunds')->get_status()===400,'foreign order item refused');
 $bad=$manualDraft;$bad['lines'][0]['quantity']=3;$assert($request('POST',$manual,$bad,'/refunds')->get_status()===400,'over-returned quantity refused');
 $bad=$manualDraft;$bad['lines'][0]['taxes'][(string)$taxIds[0]]='3.00';$assert($request('POST',$manual,$bad,'/refunds')->get_status()===400,'over-refunded tax refused');
 $bad=$manualDraft;$bad['lines'][]=$item;$assert($request('POST',$manual,$bad,'/refunds')->get_status()===400,'duplicate refund item refused');
 $bad=$manualDraft;$bad['amount']='10.00';$assert($request('POST',$manual,$bad,'/refunds')->get_status()===400,'item tax sum must equal refund amount');
 $bad=$manualDraft;$bad['lines']=[];$assert($request('POST',$manual,$bad,'/refunds')->get_status()===400,'restock requires reviewed item quantities');
 $partial=$request('POST',$manual,$manualDraft,'/refunds');$partialRow=$partial->get_data();$proof['manualPartial']=['status'=>$partial->get_status(),'code'=>$partialRow['code']??null,'remaining'=>$partialRow['refund']['remaining']??null,'stock'=>wc_get_product($manualStock)->get_stock_quantity()];
 $assert($partial->get_status()===200&&$partialRow['totalRefunded']==='11'&&$partialRow['refund']['remaining']==='14.30'&&wc_get_product($manualStock)->get_stock_quantity()===9,'native partial amount tax and stock return exactly once');
 $saved=wc_get_order($partialRow['refund']['history'][0]['id']);$assert(!$saved->get_refunded_payment()&&$saved->get_refunded_by()===$users['client']&&$saved->get_meta('_kiwe_refund_reference_v1')==='QA-MANUAL-1','native manual refund author and reference');
 $assert(isset(array_values($saved->get_items('line_item'))[0]->get_taxes()['total'][$taxIds[1]]),'native zero tax rate retained');
 $manualDraft['expected']=$orders->review($manual)['expected'];$assert($request('POST',$manual,$manualDraft,'/refunds')->get_status()===409&&count(wc_get_order($manual)->get_refunds())===1&&wc_get_product($manualStock)->get_stock_quantity()===9,'fresh-review same intent cannot refund or restock twice');
 $rest=$orders->review($manual)['refund']['lines'];$remainingLines=array_map(static fn($x)=>['id'=>$x['id'],'quantity'=>$x['quantity'],'total'=>$x['total'],'taxes'=>$x['taxes']],$rest);
 $full=$send($manual,['mode'=>'manual','amount'=>'14.30','reference'=>'QA-MANUAL-2','restock'=>true,'lines'=>$remainingLines]);$assert($full->get_status()===200&&$full->get_data()['status']==='refunded'&&!$full->get_data()['refund']['canCreate']&&wc_get_product($manualStock)->get_stock_quantity()===10,'native full balance status and remaining stock');
 $assert(wc_get_order($manual)->get_meta('_synthetic_vendor_order')==='preserve'&&wc_get_order($manual)->get_total()==='25.30','native original invoice and vendor metadata preserved');

 [$online,$onlineStock]=$make();$onlineDraft=$body($online,['amount'=>'3.30']);$calls=count(Kiwe_Refund_QA_Gateway::$calls);$success=$request('POST',$online,$onlineDraft,'/refunds');$assert($success->get_status()===200&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+1&&$success->get_data()['refund']['history'][0]['gateway']&&wc_get_product($onlineStock)->get_stock_quantity()===8,'native gateway amount-only refund invoked once without stock change');
 $onlineDraft['expected']=$orders->review($online)['expected'];$assert($request('POST',$online,$onlineDraft,'/refunds')->get_status()===409&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+1,'refreshed partial provider intent cannot replay');
 $assert($send($online,['amount'=>'0.70'])->get_status()===200&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+2,'new reviewed partial refund uses a new intent');
 [$oldIntent]=$make();$old=wc_create_refund(['order_id'=>$oldIntent,'amount'=>'0','reason'=>'Earlier native marker','refund_payment'=>false]);$oldKey=wp_generate_uuid4();$old->update_meta_data('_kiwe_refund_intent_v1',$oldKey);$old->set_date_created(time()-86400);$old->save();for($i=0;$i<21;$i++)wc_create_refund(['order_id'=>$oldIntent,'amount'=>'0','reason'=>'Recent native marker','refund_payment'=>false]);$oldRow=$orders->review($oldIntent);$calls=count(Kiwe_Refund_QA_Gateway::$calls);$assert(!array_filter($oldRow['refund']['history'],static fn($r)=>$r['intent']===$oldKey)&&$send($oldIntent,['intent'=>$oldKey])->get_status()===409&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls,'intent outside displayed history cannot replay');
 [$falseProvider]=$make();Kiwe_Refund_QA_Gateway::$scenario='false';$calls=count(Kiwe_Refund_QA_Gateway::$calls);$assert($send($falseProvider)->get_status()===409&&$orders->review($falseProvider)['refund']['blocked']&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+1,'native false provider response remains uncertain');

 [$failed,$failedStock]=$make();Kiwe_Refund_QA_Gateway::$scenario='error';$calls=count(Kiwe_Refund_QA_Gateway::$calls);$error=$send($failed);$errorRow=$error->get_data();$assert($error->get_status()===409&&!str_contains(wp_json_encode($errorRow),'Synthetic provider detail')&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+1,'native provider failure is opaque and uncertain after one attempt');
 $blocked=$orders->review($failed);$assert($blocked['refund']['blocked']&&$blocked['refund']['canResolve']&&!$blocked['refund']['canCreate']&&!wc_get_order($failed)->get_refunds(),'durable guard survives Woo deleting failed local refund');
 $assert($send($failed)->get_status()===409&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+1,'fresh intent cannot bypass uncertain guard');
 $notSent=$resolve($failed,'not_sent');$assert($notSent->get_status()===200&&!$notSent->get_data()['refund']['blocked']&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+1,'verified no-transfer release calls no gateway');
 Kiwe_Refund_QA_Gateway::$scenario='success';$assert($send($failed)->get_status()===200&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+2,'only a new reviewed intent after no-transfer verification can send');
 $guard=wc_get_order($failed)->get_meta('_kiwe_refund_guard_v1');$tamper=$guard;$tamper['amount']='99.00';$o=wc_get_order($failed);$o->update_meta_data('_kiwe_refund_guard_v1',$tamper);$o->save_meta_data();$tampered=$orders->review($failed);$assert($tampered['refund']['blocked']&&!$tampered['refund']['canResolve']&&!$tampered['refund']['canCreate'],'tampered native guard is fail closed');$o->update_meta_data('_kiwe_refund_guard_v1',$guard);$o->save_meta_data();

 [$thrown]=$make();Kiwe_Refund_QA_Gateway::$scenario='throw';$calls=count(Kiwe_Refund_QA_Gateway::$calls);$throwResult=$send($thrown);$throwRow=$orders->review($thrown);$assert($throwResult->get_status()===409&&$throwRow['refund']['blocked']&&count($throwRow['refund']['history'])===1&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+1,'provider Throwable leaves native record under durable review');
 $matchId=$throwRow['refund']['history'][0]['id'];$assert($resolve($thrown,'not_sent')->get_status()===409,'no-transfer claim cannot erase a new native refund');
 $assert($resolve($thrown,'recorded',['refundId'=>$success->get_data()['refund']['history'][0]['id']])->get_status()===400,'foreign native refund cannot resolve an attempt');
 $matched=$resolve($thrown,'recorded',['refundId'=>$matchId]);$assert($matched->get_status()===200&&!$matched->get_data()['refund']['blocked']&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls+1,'matching native refund resolves without provider replay');

 [$lost,$lostStock,$lostLine]=$make();Kiwe_Refund_QA_Gateway::$scenario='success';
 $throwHook=static function(){throw new Exception('Synthetic post-refund lifecycle failure');};add_action('woocommerce_refund_created',$throwHook,PHP_INT_MAX,2);
 $lostResult=$send($lost,['amount'=>'11.00','restock'=>true,'lines'=>[['id'=>$lostLine,'quantity'=>1,'total'=>'10.00','taxes'=>[(string)$taxIds[0]=>'1.00',(string)$taxIds[1]=>'0.00']]]]);remove_action('woocommerce_refund_created',$throwHook,PHP_INT_MAX);
 $lostRow=$orders->review($lost);$proof['lostOutcome']=['status'=>$lostResult->get_status(),'blocked'=>$lostRow['refund']['blocked'],'refunds'=>count($lostRow['refund']['history']),'stock'=>wc_get_product($lostStock)->get_stock_quantity()];
 $assert($lostResult->get_status()===409&&$lostRow['refund']['blocked']&&!$lostRow['refund']['history']&&wc_get_product($lostStock)->get_stock_quantity()===9,'native lifecycle failure can remove record after provider and stock effects');
 $calls=count(Kiwe_Refund_QA_Gateway::$calls);$assert($resolve($lost,'record_elsewhere',['stockReviewed'=>false])->get_status()===400,'uncertain stock must be reviewed before recovery');
 $recovered=$resolve($lost,'record_elsewhere');$assert($recovered->get_status()===200&&!$recovered->get_data()['refund']['blocked']&&$recovered->get_data()['totalRefunded']==='11'&&wc_get_product($lostStock)->get_stock_quantity()===9&&count(Kiwe_Refund_QA_Gateway::$calls)===$calls,'verified record-only recovery never repeats provider or restock');

 [$racing]=$make();Kiwe_Refund_QA_Gateway::$scenario='race';$race=$send($racing,['amount'=>'2.00']);$assert($race->get_status()===409&&$orders->review($racing)['refund']['blocked']&&count(wc_get_order($racing)->get_refunds())===2,'external native refund race stays uncertain without fictional rollback');
 Kiwe_Refund_QA_Gateway::$scenario='success';
 [$unpaid]=$make('cod',false);$assert(!$orders->review($unpaid)['refund']['canCreate']&&$send($unpaid,['mode'=>'manual','reference'=>'QA nope'])->get_status()===409,'unpaid COD cannot be refunded');
 [$disabled]=$make();$gateway->enabled='no';$assert(!$orders->review($disabled)['refund']['canGateway']&&$send($disabled)->get_status()===400,'disabled gateway cannot send refund');$gateway->enabled='yes';
 [$large]=$make();for($i=0;$i<51;$i++)wc_create_refund(['order_id'=>$large,'amount'=>'0','reason'=>'Synthetic bounded marker','refund_payment'=>false]);$largeRow=$orders->review($large);$assert(!$largeRow['refund']['canCreate']&&count($largeRow['refund']['history'])===20&&$largeRow['refund']['historyHasMore'],'refund history bounded and oversized ledger review-only');
 $assert(!$http&&!$mail,'no provider HTTP or customer mail attempts');
 $proof['passed']=true;
}catch(Throwable$e){$proof['passed']=false;$proof['error']=$e->getMessage();}
finally{
 foreach($orderIds as$id){$o=wc_get_order($id);if($o){foreach($o->get_refunds()as$r)$r->delete(true);$o->delete(true);}}
 foreach($productIds as$id){$p=wc_get_product($id);if($p)$p->delete(true);}
 foreach($taxIds as$id)WC_Tax::_delete_tax_rate($id);
 require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$id)wp_delete_user($id);wp_set_current_user($actor);
 foreach($filters as[$hook,$cb])remove_filter($hook,$cb,PHP_INT_MAX);
 $proof['cleanup']=true;$proof['httpAttempts']=count($http);$proof['mailAttempts']=count($mail);$proof['gatewayAttempts']=count(Kiwe_Refund_QA_Gateway::$calls);
}
echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES)."\n";
