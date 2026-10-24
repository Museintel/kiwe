<?php
declare(strict_types=1);
require __DIR__.'/test-workflow-apps.php';
$zone='Asia/Kolkata';function wp_timezone(){return new DateTimeZone($GLOBALS['zone']);}
class SaleProduct extends WC_Product_Simple {
 public function set_date_on_sale_from($v){$this->values['date_on_sale_from']=$v===null?null:new DateTimeImmutable('@'.$v);}public function set_date_on_sale_to($v){$this->values['date_on_sale_to']=$v===null?null:new DateTimeImmutable('@'.$v);}
 public function is_on_sale($context='view'){return $this->values['sale_price']!==''&&(float)$this->values['sale_price']<(float)$this->values['regular_price']&&(!$this->get_date_on_sale_from()||$this->get_date_on_sale_from()->getTimestamp()<=time())&&(!$this->get_date_on_sale_to()||$this->get_date_on_sale_to()->getTimestamp()>=time());}
 public function save(){if(!empty($GLOBALS['refuseSale']))return 0;$this->values['price']=$this->is_on_sale()?$this->values['sale_price']:$this->values['regular_price'];$id=parent::save();if(!empty($GLOBALS['alterSale']))$GLOBALS['products'][$id]->values['sale_price']='11';return $id;}
}
$actor=3;$failProduct=false;$alterReadback=false;$products[102]=new SaleProduct(102);$products[102]->values['sale_price']='';$r=$store->sale_record(102);$before=$products[102]->values;
$body=['salePrice'=>'8','startsAt'=>'2031-03-01T10:00:00','endsAt'=>'2031-03-02T10:00:00','expected'=>$r['expected']];$out=$store->save_sale_record(102,$body);
check(is_array($out)&&$out['salePrice']==='8'&&$out['startsAt']===$body['startsAt']&&!$out['onSale'],'Future sale preserves local dates without activating early');
check($products[102]->values['regular_price']===$before['regular_price']&&$products[102]->values['stock_quantity']===$before['stock_quantity']&&$products[102]->values['sku']===$before['sku'],'Sale task preserves regular price stock and SKU');
check(is_wp_error($store->save_sale_record(102,$body)),'Old sale snapshot cannot replay');$body['expected']=$out['expected'];$countBefore=$writes;
check(is_array($store->save_sale_record(102,$body))&&$writes===$countBefore,'Unchanged sale skips Woo save');
foreach([['salePrice'=>'12'],['salePrice'=>'-1'],['salePrice'=>8],['salePrice'=>'1e2'],['startsAt'=>'2031-02-30T10:00:00'],['endsAt'=>'2031-02-01T10:00:00'],['endsAt'=>'2020-01-01T10:00:00'],['salePrice'=>''],['regularPrice'=>'20']]as$bad)check(is_wp_error($store->save_sale_record(102,array_replace($body,$bad)))&&$writes===$countBefore,'Invalid sale edit cannot write '.array_key_first($bad));
$zone='UTC';check(is_wp_error($store->save_sale_record(102,$body)),'Website timezone change invalidates sale editor');$zone='Asia/Kolkata';
$refuseSale=true;check(is_wp_error($store->save_sale_record(102,array_replace($body,['salePrice'=>'7']))),'Failed Woo sale save is not success');$refuseSale=false;
$alterSale=true;check(is_wp_error($store->save_sale_record(102,array_replace($body,['salePrice'=>'7']))),'Altered native sale readback reports uncertainty');$alterSale=false;
$r=$store->sale_record(102);$zero=['salePrice'=>'0','startsAt'=>'','endsAt'=>'','expected'=>$r['expected']];$out=$store->save_sale_record(102,$zero);check(is_array($out)&&$out['onSale']&&$out['activePrice']==='0','Zero sale price is distinct from no sale');
$out=$store->save_sale_record(102,['salePrice'=>'','startsAt'=>'','endsAt'=>'','expected'=>$out['expected']]);check(is_array($out)&&!$out['onSale']&&$out['activePrice']==='12'&&$out['startsAt']==='','Clear sale restores regular pricing and dates');
$zone='America/New_York';check(is_wp_error($store->sale_date('2026-03-08T02:30:00')),'Nonexistent daylight-saving time rejected');check(is_wp_error($store->sale_date('2026-11-01T01:30:00')),'Repeated daylight-saving time rejected');check(is_int($store->sale_date('2026-11-01T03:30:00')),'Unambiguous timezone time accepted');$zone='Asia/Kolkata';
$products[102]->type='variable';check(is_wp_error($store->sale_record(102)),'Variable pricing cannot use simple sale editor');$products[102]->type='simple';$actor=2;check(is_wp_error($store->sale_record(102)),'Editorial role denied sale pricing');$actor=3;
$r=new WP_REST_Request('POST',[],['id'=>102]);$r->headers['x-kiwe-mutation']='';check($store->save_sale($r)->status===403,'Sale writes require mutation proof');
echo "Sale pricing and schedule checks passed.\n";
