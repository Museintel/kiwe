<?php
/** Real Woo orders/roles and connection-local cart tables; named lab or disposable fixture only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || ! KIWE_WORKSPACE_TEST_SITE || ( home_url() !== 'https://plum-giraffe-539524.hostingersite.com' && ( ! defined( 'KIWE_ACTIVITY_DISPOSABLE' ) || ! KIWE_ACTIVITY_DISPOSABLE ) ) ) exit(2);

use DSA\Commerce\Abandoned_Cart_Service as Carts;
use DSA\Commerce\Paid_Customer_Query as Buyers;

$proof = [ 'passed'=>false, 'cleanup'=>false, 'checks'=>[] ];
$check = static function ( $ok, $why ) use ( &$proof ): void { $proof['checks'][$why]=(bool)$ok; if (!$ok) throw new RuntimeException($why); };
$actor=get_current_user_id();$wc=WC();$originalWoo=[$wc->session,$wc->customer,$wc->cart];$tables=[];$users=[];$orders=[];$filters=[];$borrowed=[];$mail=0;$http=0;$tag='activityqa_'.bin2hex(random_bytes(5));
$originalActivity=get_option(DSA\Access\Workspace_Activity_Service::OPTION,null);$originalCursor=get_option('dsa_cart_conversion_cursor',null);
$filter=static function($hook,$callback,$args=1)use(&$filters){add_filter($hook,$callback,PHP_INT_MAX,$args);$filters[]=[$hook,$callback];};
try {
 $check(current_user_can('edit_others_shop_orders')&&current_user_can('delete_shop_orders')&&current_user_can('edit_users'),'Native actor has cart cleanup and private account permissions');
 $filter('pre_http_request',static function()use(&$http){$http++;return new WP_Error('activity_no_provider','No external request in this isolated test.');},3);
 $filter('pre_wp_mail',static function()use(&$mail){$mail++;return false;},2);
 foreach(['user_register','set_user_role','dsa_saved_cart_abandoned','woocommerce_order_status_changed']as$hook)foreach(($GLOBALS['wp_filter'][$hook]->callbacks??[])as$priority=>$entries)foreach($entries as$entry){$cb=$entry['function'];if(is_array($cb)&&$cb[0] instanceof DSA\Notifications\Admin_Event_Notification_Service){$borrowed[]=[$hook,$priority,$entry];remove_action($hook,$cb,$priority);}}
 $settings=new DSA\Settings();$analytics=new DSA\Commerce\Store_Analytics_Service($settings);$payload=new DSA\Commerce\Cart_Payload_Service($settings,new DSA\Commerce\Linked_Products_Service($settings),$analytics);
 $channels=new DSA\Communications\Channel_Service($settings,new DSA\Communications\Email_Service($settings),new DSA\PhoneKey\PhoneKey_Bridge($payload));$carts=new Carts($settings,$analytics,$channels);
 $carts->maybe_install();$check($carts->ready(),'Native saved-cart owner and schema are ready');
 foreach(['dsa_abandoned_carts','dsa_abandoned_cart_reminders']as$suffix){$table=$wpdb->prefix.$suffix;$schema=$wpdb->get_row("SHOW CREATE TABLE `{$table}`",ARRAY_N);$ddl=is_array($schema)?preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$schema[1],1):'';$check($ddl&&false!==$wpdb->query($ddl),'Connection-local shadow table '.$suffix);$tables[]=$table;}
 [$cartTable,$logTable]=$tables;$now=current_time('mysql');
 $insert=static function(array$patch=[])use($wpdb,$cartTable,$now):int{$data=array_replace(['visitor_hash'=>str_repeat('a',64),'customer_hash'=>str_repeat('b',64),'user_id'=>0,'cart_hash'=>str_repeat('c',64),'items'=>'[]','item_count'=>1,'cart_total'=>100,'currency'=>'INR','status'=>'abandoned','first_seen_at'=>$now,'last_activity_at'=>$now,'created_at'=>$now,'updated_at'=>$now],$patch);if(1!==$wpdb->insert($cartTable,$data))throw new RuntimeException('Synthetic cart insertion failed');return(int)$wpdb->insert_id;};
 $anonymous=[];for($i=0;$i<130;$i++)$anonymous[]=$insert();
 $protected=[];foreach(['user_id'=>99,'phonekey_verified'=>1,'contact_hash'=>str_repeat('d',64),'contact_type'=>'email','contact_email_enc'=>'unreadable-ciphertext','contact_phone_enc'=>'unreadable-ciphertext','checkout_snapshot_enc'=>DSA\Security\Secret_Store::encrypt(wp_json_encode(['billing'=>['first_name'=>'Synthetic name']])),'checkout_details_at'=>$now,'order_id'=>12345,'reminder_count'=>1]as$key=>$value)$protected[]=$insert([$key=>$value]);
 $withReceipt=$insert();$protected[]=$withReceipt;$wpdb->insert($logTable,['cart_id'=>$withReceipt,'recipient_hash'=>str_repeat('e',64),'created_at'=>$now]);
 $preview=$carts->unknown_clear_preview();$check(!is_wp_error($preview)&&$preview['count']===130,'Cleanup preview excludes names, contacts, accounts, order links, uncertain encryption and reminder history');
 $new=$insert();$body=['maxId'=>$preview['maxId'],'expires'=>$preview['expires'],'expected'=>$preview['expected'],'cursor'=>0,'confirmed'=>true];
 foreach([array_replace($body,['confirmed'=>false]),array_replace($body,['maxId'=>$new]),array_replace($body,['expires'=>time()-1]),$body+['id'=>$protected[0]]]as$bad)$check(is_wp_error($carts->clear_unknown_carts($bad)),'Forged or stale anonymous-cleanup request is refused '.count($proof['checks']));
 $changed=$anonymous[0];$injected=false;
 $race=static function($sql)use($wpdb,$cartTable,$changed,&$injected){if(!$injected&&str_starts_with($sql,"DELETE FROM {$cartTable} WHERE id IN (")){$injected=true;$wpdb->update($cartTable,['contact_phone_enc'=>'new-contact-entered-after-review'],['id'=>$changed]);}return$sql;};
 add_filter('query',$race);$first=$carts->clear_unknown_carts($body);remove_filter('query',$race);
 $check(!is_wp_error($first)&&$first['deleted']===99&&$first['hasMore'],'Atomic deletion preserves customer information added after selection');
 $second=$carts->clear_unknown_carts(array_replace($body,['cursor'=>$first['cursor']]));$check(!is_wp_error($second)&&$second['deleted']===30&&!$second['hasMore'],'Cleanup drains more than one batch without an unbounded request');
 $remaining=array_map('intval',$wpdb->get_col("SELECT id FROM {$cartTable} ORDER BY id"));$expected=[...$protected,$changed,$new];sort($expected);$check($remaining===$expected,'Every protected cart and all newer carts survive cleanup');
 $repeat=$carts->clear_unknown_carts($body);$check(!is_wp_error($repeat)&&$repeat['deleted']===0,'Retrying the same completed snapshot does not remove newer or protected records');
 wp_set_current_user(0);$check(is_wp_error($carts->unknown_clear_preview())&&is_wp_error($carts->clear_unknown_carts($body)),'Anonymous visitors cannot preview or clear private saved carts');wp_set_current_user($actor);

 // Real Woo CRUD with no customer contact, stock item, gateway call or payment.
 foreach(['subscriber','customer','subscriber']as$index=>$role){$id=wp_insert_user(['user_login'=>$tag.'_'.$index,'user_email'=>$tag.'_'.$index.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);$check(!is_wp_error($id),'Synthetic native account '.$index);$users[]=(int)$id;}
 $makeOrder=static function(int$buyer=0,string$status='pending')use(&$orders,$tag):WC_Order{$o=wc_create_order(['customer_id'=>$buyer,'status'=>'pending']);if(is_wp_error($o))throw new RuntimeException('Native fixture order failed');$orders[]=$o->get_id();$o->set_created_via($tag);$o->set_total('100');$o->set_status($status);$o->save();return$o;};
 $paid=$makeOrder($users[0],'processing');$unpaid=$makeOrder($users[1]);$guest=$makeOrder(0,'processing');
 $buyerRows=Buyers::users(['number'=>13,'count_total'=>false,'include'=>$users,'orderby'=>'ID','order'=>'ASC']);$check(array_map(static fn($u)=>(int)$u->ID,$buyerRows)===[$users[0]],'Paid Subscriber is a Customer; unpaid customer-role account and guest email are not account buyers');
 $paid->set_status('refunded');$paid->save();$check(Buyers::users(['number'=>13,'include'=>$users])===[],'Order status changes immediately invalidate customer classification without stale user-query caches');
 $paid->set_status('completed');$paid->save();$buyerRows=Buyers::users(['number'=>1,'offset'=>0,'include'=>$users]);$check(count($buyerRows)===1&&(int)$buyerRows[0]->ID===$users[0],'Buyer restriction is applied before native pagination');
 $check(count(get_users(['include'=>$users,'fields'=>'ID']))===3,'Other native user queries remain unfiltered');

 // Native Woo guest cart identity, then an order linked before payment succeeds.
 if ( ! function_exists( 'wc_get_chosen_shipping_method_ids' ) ) WC()->frontend_includes();
 wp_set_current_user(0);$wc->session=new class extends WC_Session {};$wc->customer=new WC_Customer();$wc->cart=new WC_Cart();$product=new WC_Product_Simple();$product->set_name('Synthetic activity fixture');$product->set_price('100');$product->set_virtual(true);
 $wc->cart->set_cart_contents(['fixture'=>['data'=>$product,'product_id'=>0,'variation_id'=>0,'quantity'=>1,'variation'=>[]]]);$wc->cart->set_totals(['subtotal'=>100,'cart_contents_total'=>100,'total'=>100]);
 $carts->capture_current_cart('cart_change');$anchor=$wc->session->get('kiwe_saved_cart');$check(!empty($anchor['id']),'Native guest identity has its own saved cart');$id=(int)$anchor['id'];
 $pending=$makeOrder();$carts->mark_order_converted($pending->get_id(),[], $pending);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$cartTable} WHERE id=%d",$id),ARRAY_A);
 $check((int)$row['order_id']===$pending->get_id()&&$row['status']==='active'&&$row['converted_at']===null,'Placing an unpaid order links the exact cart without claiming a conversion');
 $carts->mark_current_cart_cleared();$check($wpdb->get_var($wpdb->prepare("SELECT status FROM {$cartTable} WHERE id=%d",$id))==='active','Emptying the browser cart cannot erase the pending-order journey');
 $pending->set_status('processing');$pending->save();$carts->sync_order_conversion($pending->get_id());$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$cartTable} WHERE id=%d",$id),ARRAY_A);$check($row['status']==='converted'&&$row['converted_at']!==null,'Payment state converts the persisted order/cart link without needing a guest browser session');
 $pending->set_status('failed');$pending->save();$carts->sync_order_conversion($pending->get_id());$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$cartTable} WHERE id=%d",$id),ARRAY_A);$check($row['status']==='abandoned'&&$row['converted_at']===null,'Failed or reversed payment removes a false conversion while keeping the record');
 $historical=$insert(['status'=>'converted','converted_at'=>$now,'order_id'=>$unpaid->get_id()]);wp_set_current_user($actor);update_option('dsa_cart_conversion_cursor',0,false);$reconciled=$carts->reconcile_order_conversions();$check($reconciled['changed']>=1&&$wpdb->get_var($wpdb->prepare("SELECT status FROM {$cartTable} WHERE id=%d",$historical))==='abandoned','Bounded historical repair cross-checks actual native orders');

 // The cleanup wins before a guest contact UPDATE; the fallback preserves it.
 wp_set_current_user(0);$carts->capture_current_cart('race-initial');$raceId=(int)$wc->session->get('kiwe_saved_cart')['id'];$removedBeforeWrite=false;
 $race=static function($sql)use($wpdb,$cartTable,$raceId,&$removedBeforeWrite){if(!$removedBeforeWrite&&str_starts_with($sql,"UPDATE `{$cartTable}` SET")&&str_contains($sql,'contact_email_enc')){$removedBeforeWrite=true;$wpdb->delete($cartTable,['id'=>$raceId]);}return$sql;};
 add_filter('query',$race);$carts->capture_checkout_details(['billing_first_name'=>'Synthetic visitor','billing_email'=>$tag.'@example.invalid']);remove_filter('query',$race);
 $savedId=(int)$wc->session->get('kiwe_saved_cart')['id'];$saved=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$cartTable} WHERE id=%d",$savedId),ARRAY_A);$check($removedBeforeWrite&&$savedId!==$raceId&&DSA\Security\Secret_Store::decrypt($saved['contact_email_enc'])===$tag.'@example.invalid','Contact saved during concurrent anonymous cleanup is reinserted, encrypted and retained');
 $subscriber=get_user_by('email',$tag.'@example.invalid');if($subscriber)$users[]=(int)$subscriber->ID;
 $check($subscriber&&in_array('subscriber',$subscriber->roles,true)&&!get_current_user_id(),'Admitted partial checkout contact becomes a pending Subscriber without authentication');
 $guestCustomerStep=new ReflectionMethod($wc->checkout(),'process_customer');$guestCustomerStep->invoke($wc->checkout(),['billing_email'=>$tag.'@example.invalid','createaccount'=>false]);
 $check(get_current_user_id()===0&&get_userdata($subscriber->ID)->roles===$subscriber->roles,'Native guest checkout still accepts an email already captured as a pending Subscriber without claiming its account');
 $filter('pre_option_kiwe_store_app_enabled_v1',static fn()=>'0');wp_set_current_user($actor);$check(is_wp_error($carts->unknown_clear_preview())&&Buyers::users([])===[],'Disabled Store exposes no cleanup or buyer work');
 $proof['storage']=class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')&&Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'hpos':'legacy';$proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();$proof['line']=$e->getLine();}
finally {
 if(isset($race))remove_filter('query',$race);
 foreach(array_reverse($filters)as[$hook,$cb])remove_filter($hook,$cb,PHP_INT_MAX);
 [$wc->session,$wc->customer,$wc->cart]=$originalWoo;wp_set_current_user($actor);
 foreach($orders as$id){$order=wc_get_order($id);if($order&&$order->get_created_via()===$tag)$order->delete(true);}
 require_once ABSPATH.'wp-admin/includes/user.php';foreach(array_unique($users)as$id){$user=get_userdata($id);if($user&&(str_starts_with($user->user_login,$tag)||$user->user_email===$tag.'@example.invalid')&&$id!==$actor)wp_delete_user($id);}
 foreach(array_reverse($tables)as$table)$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
 if($originalCursor===null)delete_option('dsa_cart_conversion_cursor');else update_option('dsa_cart_conversion_cursor',$originalCursor,false);
 if($originalActivity===null)delete_option(DSA\Access\Workspace_Activity_Service::OPTION);else update_option(DSA\Access\Workspace_Activity_Service::OPTION,$originalActivity,false);
 foreach($borrowed as[$hook,$priority,$entry])add_action($hook,$entry['function'],$priority,$entry['accepted_args']);
 $proof['cleanup']=get_current_user_id()===$actor;foreach($orders as$id)$proof['cleanup']=$proof['cleanup']&&!wc_get_order($id);foreach($users as$id)$proof['cleanup']=$proof['cleanup']&&!get_userdata($id);
 $proof['httpBlocked']=$http;$proof['mailBlocked']=$mail;
}
echo wp_json_encode($proof)."\n";if(!$proof['passed']||!$proof['cleanup'])exit(1);
