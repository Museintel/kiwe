<?php
declare(strict_types=1);
require __DIR__.'/test-workflow-apps.php';
require $base.'Commerce/Linked_Products_Service.php';require $base.'Commerce/Store_Analytics_Service.php';
function sanitize_title($value){return strtolower(trim($value));}
$actor=3;$failProduct=false;$alterReadback=false;
class LinkProduct extends WC_Product_Simple {public function save(){$id=parent::save();if(!empty($GLOBALS['refuseLinks']))$GLOBALS['products'][$id]->values['cross_sell_ids']=[];return $id;}}
$products[102]=new LinkProduct(102);$products[102]->values['cross_sell_ids']=[104];
foreach([103,104,105]as$id){$products[$id]=new WC_Product_Simple($id);$products[$id]->values['status']='publish';}$products[105]->values['catalog_visibility']='hidden';$linkCandidates=[102,103,104,105];
$input=['sameCategory'=>true,'categoryIds'=>[]];$before=$writes;$preview=$store->links_preview_record(102,$input);
check(is_array($preview)&&array_column($preview['candidates'],'id')===[103,104]&&$writes===$before,'Preview excludes self and hidden products without writes');
check($postQuery['posts_per_page']===50&&$postQuery['tax_query'][0]['terms']===[10],'Same-category preview reuses bounded native category selector');
$input+=['expected'=>$preview['expected'],'selectedIds'=>[103]];$saved=$store->save_links_record(102,$input);
check(is_array($saved)&&$products[102]->values['cross_sell_ids']===[104,103],'Apply merges selected cross-sells and preserves earlier links');
check($products[102]->values['regular_price']==='12'&&$products[102]->values['stock_quantity']===8,'Link writer preserves price and stock');
check(is_wp_error($store->save_links_record(102,$input)),'Old link preview cannot replay');
$input['expected']=$saved['expected'];$before=$writes;check(is_array($store->save_links_record(102,$input))&&$before===$writes,'Already-linked no-op does not save again');
foreach([['selectedIds'=>[999]],['selectedIds'=>['103']],['selectedIds'=>[103,103]],['selectedIds'=>[]],['selectedIds'=>[[103]]],['selectedIds'=>['key'=>103]]]as$bad){$before=$writes;check(is_wp_error($store->save_links_record(102,array_replace($input,$bad)))&&$before===$writes,'Invalid selection cannot write');}
check(is_wp_error($store->links_preview_record(102,['sameCategory'=>false,'categoryIds'=>[999]])),'Unknown category denied');
check(is_wp_error($store->links_preview_record(102,['sameCategory'=>'true','categoryIds'=>[]])),'Malformed category mode denied');
$products[103]->values['status']='draft';check(is_wp_error($store->save_links_record(102,$input)),'Candidate publication change invalidates preview');$products[103]->values['status']='publish';
$products[102]->values['cross_sell_ids']=[104];$input['expected']=$store->links_preview_record(102,$input)['expected'];$failProduct=true;check(is_wp_error($store->save_links_record(102,$input)),'Woo save failure is uncertainty');$failProduct=false;$refuseLinks=true;check(is_wp_error($store->save_links_record(102,$input)),'Refused Woo cross-sells fail native readback');$refuseLinks=false;
$actor=2;check(is_wp_error($store->links_preview_record(102,$input)),'Editor cannot preview commerce links');$actor=3;
$r=new WP_REST_Request('POST',$input,['id'=>102]);$r->headers['x-kiwe-mutation']='';check($store->preview_links($r)->status===403&&$store->save_links($r)->status===403,'Preview and apply require mutation proof');
check($store->save_links(new WP_REST_Request('GET',$input,['id'=>102]))->status===403,'GET cannot apply cross-sells');
$before=$writes;$status=$store->read_bestsellers(new WP_REST_Request());check($status->status===200&&count($status->data['rows'])===4&&$writes===$before,'Bestseller status reads four saved categories without synchronization');
$actor=2;check($store->read_bestsellers(new WP_REST_Request())->status===403,'Editor cannot read store bestseller status');
echo "Product link and bestseller checks passed.\n";
