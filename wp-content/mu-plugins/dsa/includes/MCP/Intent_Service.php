<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

/** Expiring delegated-operation reviews. Native owners remain the business store. */
final class Intent_Service {
 public const REVIEW_TTL=900,RECEIPT_TTL=86400;
 public const BATCH_LIMIT=12;
 /** Only the native review's current-state callback may resolve its earlier intent. */
 private static ?array $nativeReviewContext=null;
 public function __construct(private Operation_Registry $registry){}
 private function grant(): array {
  $context=self::$nativeReviewContext;
  $g=$context ? $context['auth']->native_review_grant($context['grant'],get_current_user_id()) : Auth_Context::current();
  if(!$g)throw new \RuntimeException('Reconnect your AI before changing this website.');return$g;
 }
 private function digest(array $value): string {return hash('sha256',\DSA\Apps\Signed_Packet::canonical($value));}
 private function binding(array $g): array {return['grant'=>$g['id'],'actor'=>$g['actor'],'client'=>$g['client'],'site'=>Urls::binding(),'resource'=>Urls::resource(),'actorPin'=>Credentials::actor_pin($g['actor'])];}
 private function row(string $id,array $g,bool $lock=false): array {
  if(preg_match('/^i_[a-f0-9]{32}$/D',$id)!==1)throw new \RuntimeException('Choose an existing Kiwe change review.');
  $row=Records::get('intent',$id,$lock);if(!$row||$row['expiresAt']<=time()||($row['data']['binding']??null)!==$this->binding($g))throw new \RuntimeException('This review belongs to another connection or has expired.');
  $d=$row['data'];$this->registry->write_contract($d['operation'],$d['arguments'],$g['scopes'],$g['pins']);
  if(!is_string($d['pin']??null)||!hash_equals($g['pins'][$d['operation']]??'',$d['pin']))throw new \RuntimeException('The operation changed. Review it again.');return$row;
 }
 private function save(array $row): void { Records::put('intent',$row['id'],$row['data'],$row['expiresAt'],$row['userId'],$row['data']['binding']['client']); }
 private function projection(array $row): array {
  $d=$row['data'];return['id'=>$row['id'],'operation'=>$d['operation'],'state'=>$d['state'],'summary'=>$d['plan']['summary'],'before'=>$d['plan']['before'],'after'=>$d['plan']['after'],
   'approvalRequired'=>$d['plan']['needsApproval'],'approvalUrl'=>$d['plan']['needsApproval']&&$d['state']==='prepared'?add_query_arg(['action'=>'kiwe_mcp_change_review','intent'=>$row['id']],admin_url('admin-post.php')):'',
   'reviewExpires'=>gmdate('c',$d['reviewUntil']),'message'=>$d['message']??'','result'=>$d['result']??null];
 }
 public function prepare(string $operation,array $arguments,string $requestId): array {
  $g=$this->grant();if(preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$requestId)!==1||strlen((string)wp_json_encode($arguments))>10000)throw new \RuntimeException('Use a unique bounded request identifier and the exact operation inputs.');
  $this->registry->write_contract($operation,$arguments,$g['scopes'],$g['pins']);
  $id='i_'.substr($this->digest([$this->binding($g),$requestId]),0,32);$digest=$this->digest([$operation,$arguments]);
  $old=Records::get('intent',$id);if($old&&$old['expiresAt']>time()){$old=$this->row($id,$g);if(!hash_equals($old['data']['inputDigest'],$digest))throw new \RuntimeException('That request identifier was already used for different inputs.');return$this->projection($old);}
  $plan=$this->registry->prepare_change($operation,$arguments,$g['scopes'],$g['pins']);
  Records::purge();return Records::atomic(function()use($id,$g,$operation,$arguments,$digest,$plan){
   $existing=Records::get('intent',$id,true);if($existing&&$existing['expiresAt']>time()){if(($existing['data']['inputDigest']??'')!==$digest)throw new \RuntimeException('That change identifier is already in use.');return$this->projection($this->row($id,$g));}
   if(Records::count('intent',$g['actor'])>=500||Records::count('intent')>=4000)throw new \RuntimeException('Earlier change receipts need to expire before preparing more.');
   $row=['id'=>$id,'userId'=>$g['actor'],'expiresAt'=>time()+self::RECEIPT_TTL,'data'=>['binding'=>$this->binding($g),'operation'=>$operation,'arguments'=>$arguments,'inputDigest'=>$digest,'pin'=>$g['pins'][$operation],'plan'=>$plan,'planDigest'=>$this->digest([$operation,$arguments,$plan]),'reviewUntil'=>time()+self::REVIEW_TTL,'state'=>'prepared','approval'=>null]];
   $this->save($row);return$this->projection($row);
  });
 }
 public function status(string $id): array {return$this->projection($this->row($id,$this->grant()));}
 private function native_grant(string $id,Authorization_Service $auth): array {
  $raw=Records::get('intent',$id);if(!$raw)throw new \RuntimeException('This change review is unavailable.');
  return $auth->native_review_grant((string)($raw['data']['binding']['grant']??''),get_current_user_id());
 }
 private function check_native_review(array $row,array $g,Authorization_Service $auth): void {
  if($row['data']['state']!=='prepared'||$row['data']['reviewUntil']<=time())throw new \RuntimeException('Prepare a fresh change review.');
  // A normal browser session has no delegated bearer. Retain the exact
  // freshly validated native grant only while reading this proposal's state;
  // providers can then validate an originating approved intent. Never install
  // token authentication, create a grant or carry this context past the read.
  $previous=self::$nativeReviewContext;self::$nativeReviewContext=['auth'=>$auth,'grant'=>$g['id']];
  try{$expected=$this->registry->current_change($row['data']['operation'],$row['data']['arguments'],$g['scopes'],$g['pins']);}
  finally{self::$nativeReviewContext=$previous;}
  if(!hash_equals($expected,$row['data']['plan']['nativeExpected']))throw new \RuntimeException('The website changed since this proposal. Ask your AI for a fresh review.');
 }
 /** Same native actor/session can review only the exact grant-bound proposal. */
 public function native_review(string $id,Authorization_Service $auth): array {
  $g=$this->native_grant($id,$auth);$row=$this->row($id,$g);$this->check_native_review($row,$g,$auth);
  return ['review'=>$this->projection($row),'arguments'=>$row['data']['arguments'],'grant'=>$g];
 }
 private static function batch_ids(array $ids): array {
  if(!array_is_list($ids)||count($ids)<2||count($ids)>self::BATCH_LIMIT)throw new \RuntimeException('Choose 2 to '.self::BATCH_LIMIT.' related draft changes.');
  foreach($ids as $id)if(!is_string($id)||preg_match('/^i_[a-f0-9]{32}$/D',$id)!==1)throw new \RuntimeException('Choose existing Kiwe change reviews.');
  if(count(array_unique($ids))!==count($ids))throw new \RuntimeException('Each change can appear in the review only once.');
  return $ids;
 }
 /** Grouped publication is a status change on a sale-ready simple draft. */
 private function publication_offer(array $arguments): ?array {
  $fields = json_decode((string)($arguments['fieldsJson'] ?? ''), true, 8);
  if ($fields !== ['status'=>'publish'] || !function_exists('wc_get_product')) return null;
  $id = (int)($arguments['id'] ?? 0);
  $product = $id > 0 ? wc_get_product($id) : null;
  if (!$product instanceof \WC_Product_Simple || $product->get_status('edit') !== 'draft' ||
   $product->get_catalog_visibility('edit') !== 'visible' || !is_numeric($product->get_price('edit')) ||
   $product->get_stock_status('edit') !== 'instock' ||
   ($product->managing_stock() && (float)$product->get_stock_quantity('edit') < 1)) {
   return null;
  }
  return ['price'=>(string)$product->get_price('edit'),'currency'=>get_woocommerce_currency()];
 }
 /** A publication batch is separate from work on the contents of drafts. */
 private function product_batch(array $ids,array $g,bool $lock=false): array {
  $rows=[];$products=[];$seen=[];$pins=[];$offers=[];$deadline=PHP_INT_MAX;$kind=null;
  foreach($ids as $id){
   $row=$this->row($id,$g,$lock);$d=$row['data'];$args=$d['arguments'];
   if($d['state']!=='prepared'||$d['reviewUntil']<=time()||!$d['plan']['needsApproval'])throw new \RuntimeException('Prepare a fresh review for each draft change.');
   $product_id=0;$offer=null;
   if ($d['operation'] === 'store.product.publish') {
    $offer = $this->publication_offer($args);
    if ($offer === null) throw new \RuntimeException('For grouped publication, choose visible simple drafts with a price and stock available for orders.');
    $product_id = (int)$args['id'];
    $offers[$id] = $offer;
   } elseif($d['operation']==='store.product.delivery'){
    $fields=json_decode((string)($args['fieldsJson']??''),true,8);
    if(is_array($fields)&&array_keys($fields)===['weight']&&is_scalar($fields['weight'])&&is_numeric($fields['weight']))$product_id=(int)($args['id']??0);
   }elseif($d['operation']===Featured_Image_Provider::CLIENT_RECEIVE&&($args['brief']['targetType']??'')==='product'){
    $product_id=(int)($args['brief']['targetId']??0);
   }
   $post=$product_id>0?get_post($product_id):null;
   if(!$post||$post->post_type!=='product'||$post->post_status!=='draft')throw new \RuntimeException('Choose publication or related weight and photo changes on product drafts.');
   $itemKind = $d['operation'] === 'store.product.publish' ? 'publish' : 'draft';
   if ($kind !== null && $kind !== $itemKind) throw new \RuntimeException('Review public product publication separately from draft edits.');
   $kind = $itemKind;
   $key=$product_id.':'.$d['operation'];
   if(isset($seen[$key]))throw new \RuntimeException('Choose one proposed value for each change to a product.');
   $seen[$key]=true;$pins[]=$offer === null ? [$id,$d['planDigest']] : [$id,$d['planDigest'],$offer];
   $rows[]=$row;$deadline=min($deadline,$d['reviewUntil']);
   $products[$product_id]??=['title'=>$post->post_title,'changes'=>[]];
   $products[$product_id]['changes'][]=['review'=>$this->projection($row),'arguments'=>$args,'offer'=>$offer];
  }
  $digest=$this->digest([$this->binding($g),$pins]);
  foreach($rows as $row)if($row['data']['approval']!==null&&($row['data']['approval']['batch']??'')!==$digest)throw new \RuntimeException('A change was already reviewed separately. Prepare a new review for the remaining changes.');
  return ['rows'=>$rows,'products'=>array_values($products),'digest'=>$digest,'reviewExpires'=>gmdate('c',$deadline),'kind'=>$kind,'offers'=>$offers];
 }
 /** An MCP client can propose a link. Only a native website owner can approve it. */
 public function prepare_batch(array $ids): array {
  $ids=self::batch_ids($ids);$batch=$this->product_batch($ids,$this->grant());
  return ['approvalUrl'=>add_query_arg(['action'=>'kiwe_mcp_change_review','batch'=>implode(',',$ids)],admin_url('admin-post.php')),
   'reviewCount'=>count($ids),'productCount'=>count($batch['products']),'reviewExpires'=>$batch['reviewExpires'],
   'reviewKind'=>$batch['kind'],
   'guidance'=>'Open this one native review. After the owner approves it, apply the original intents and verify each receipt. For publication, confirm the products appear on the storefront.'];
 }
 public function native_batch(array $ids,Authorization_Service $auth): array {
  $ids=self::batch_ids($ids);$g=$this->native_grant($ids[0],$auth);$batch=$this->product_batch($ids,$g);
  foreach($batch['rows'] as $row)$this->check_native_review($row,$g,$auth);
  return ['products'=>$batch['products'],'digest'=>$batch['digest'],'reviewExpires'=>$batch['reviewExpires'],'grant'=>$g,'kind'=>$batch['kind']];
 }
 /** Show the original website user the status of an approved batch after its AI connection closes. */
 public function native_batch_receipt(array $ids): ?array {
  $actor = get_current_user_id();
  if (!Credentials::actor_allowed($actor)) return null;

  $binding = null;
  $pins = [];
  $approvedDigests = [];
  $states = [];
  $operations = [];
  $now = time();
  $reviewUntil = PHP_INT_MAX;
  foreach (self::batch_ids($ids) as $id) {
   $record = Records::get('intent', $id);
   if (!$record || $record['userId'] !== $actor || $record['expiresAt'] <= $now) return null;
   $data = $record['data'];
   $approval = $data['approval'] ?? null;
   $planDigest = $data['planDigest'] ?? null;
   $recordBinding = $data['binding'] ?? null;
   if (!is_array($approval) || ($approval['actor'] ?? null) !== $actor ||
    !is_string($approval['batch'] ?? null) || !is_string($approval['digest'] ?? null) ||
    !is_string($planDigest) || !hash_equals($planDigest, $approval['digest']) ||
    !is_array($recordBinding)) return null;

   if ($binding === null) {
    if (!is_string($recordBinding['grant'] ?? null) || !is_string($recordBinding['client'] ?? null)) return null;
    $binding = $this->binding(['id'=>$recordBinding['grant'], 'client'=>$recordBinding['client'], 'actor'=>$actor]);
   }
   if ($recordBinding !== $binding) return null;

   $offer = $approval['offer'] ?? null;
   $pins[] = $offer === null ? [$id, $planDigest] : [$id, $planDigest, $offer];
   $approvedDigests[] = $approval['batch'];
   $states[] = $data['state'];
   $operations[] = $data['operation'];
   $reviewUntil = min($reviewUntil, $data['reviewUntil']);
  }

  $digest = $this->digest([$binding, $pins]);
  foreach ($approvedDigests as $approvedDigest) {
   if (!hash_equals($digest, $approvedDigest)) return null;
  }
  $receipt = ['digest'=>$digest];
  if (array_unique($operations) === ['store.product.publish']) $receipt['kind'] = 'publish';
  if (array_unique($states) === ['complete']) return $receipt + ['state'=>'complete'];
  $needsAttention = $reviewUntil <= $now || array_intersect($states, ['cancelled', 'failed', 'needs-review']);
  return $receipt + ['state'=>$needsAttention ? 'needs-check' : 'approved'];
 }
 /** Verify the exact displayed set and approve it atomically. Application still uses each native writer. */
 public function approve_batch(array $ids,Authorization_Service $auth,string $nonce,bool $allow): array {
  $view=$this->native_batch($ids,$auth);$g=$view['grant'];$digest=$view['digest'];
  if(!wp_verify_nonce($nonce,'kiwe_mcp_review_batch_'.$digest))throw new \RuntimeException('Reload this native review before confirming.');
  Records::atomic(function()use($ids,$g,$digest,$allow){
   $batch=$this->product_batch($ids,$g,true);
   if(!hash_equals($digest,$batch['digest']))throw new \RuntimeException('The exact set of draft changes has changed.');
   foreach($batch['rows'] as $row){
    if($row['data']['approval']!==null){
     if(!$allow)throw new \RuntimeException('This review is already approved.');
     continue;
    }
    if($allow){
     $row['data']['approval']=['actor'=>$g['actor'],'at'=>time(),'digest'=>$row['data']['planDigest'],'batch'=>$digest];
     if (isset($batch['offers'][$row['id']])) $row['data']['approval']['offer'] = $batch['offers'][$row['id']];
     $row['data']['message']='Approved for this exact proposal.';
    }else{
     $row['data']['state']='cancelled';$row['data']['message']='Cancelled in the native website review.';
    }
    $this->save($row);
   }
  });
  return ['productCount'=>count($view['products']),'reviewCount'=>count($ids),'kind'=>$view['kind']];
 }
 /** Caller verifies native same-origin form and actor-bound nonce. No remote approval flag. */
 public function approve(string $id,Authorization_Service $auth,bool $allow): array {
  $view=$this->native_review($id,$auth);$g=$view['grant'];return Records::atomic(function()use($id,$g,$allow){
   $row=$this->row($id,$g,true);if($row['data']['state']!=='prepared'||$row['data']['reviewUntil']<=time())throw new \RuntimeException('The change review has expired.');
   if(!$allow){$row['data']['state']='cancelled';$row['data']['message']='Cancelled in the native website review.';}
   else{$row['data']['approval']=['actor'=>$g['actor'],'at'=>time(),'digest'=>$row['data']['planDigest']];$row['data']['message']='Approved for this exact proposal.';}
   $this->save($row);return$this->projection($row);
  });
 }
 public function apply(string $id): array {
  $g=$this->grant();global$wpdb;$lock='kiwe-mcp-intent-'.substr(hash('sha256',Records::table().'|'.$id),0,32);
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new \RuntimeException('This change is already finishing. Check its receipt shortly.');
  try{
   $row=$this->row($id,$g);$d=$row['data'];
   if(in_array($d['state'],['complete','failed','cancelled','needs-review'],true))return$this->projection($row);
   if($d['state']==='running')return$this->projection($row)+['interrupted'=>true,'guidance'=>'Read native current state before preparing another change; this action will not be repeated automatically.'];
   if($d['reviewUntil']<=time())throw new \RuntimeException('Prepare a fresh review before applying this change.');
   if($d['plan']['needsApproval']&&(($d['approval']['actor']??null)!==$g['actor']||!hash_equals($d['planDigest'],(string)($d['approval']['digest']??''))))throw new \RuntimeException('Approve the exact proposal in the native website review first.');
   if(!hash_equals($this->registry->current_change($d['operation'],$d['arguments'],$g['scopes'],$g['pins']),$d['plan']['nativeExpected']))throw new \RuntimeException('The native state changed. Prepare a new review.');
   if (isset($d['approval']['offer']) && $d['approval']['offer'] !== $this->publication_offer($d['arguments'])) {
    throw new \RuntimeException('The approved price, currency or sale availability changed. Prepare a fresh review.');
   }
   if($d['operation']==='mcp.visual.client.receive')Featured_Image_Provider::admit_client_upload($d['arguments']);
   Records::atomic(function()use(&$row,$g){$row=$this->row($row['id'],$g,true);if($row['data']['state']!=='prepared')throw new \RuntimeException('This change is no longer awaiting application.');$row['data']['state']='running';$row['data']['message']='Native operation started; interruption requires readback.';$this->save($row);});
   // Never hold the connection-table transaction across native writers/network jobs.
   try{$current=$this->grant();if($current!==$g)throw new \RuntimeException('The delegated authority changed.');$result=$this->registry->execute_prepared($d['operation'],$d['arguments'],$d['plan']['nativeExpected'],$g['scopes'],$g['pins']);$state='complete';$message='Native writer confirmed this change.';}
   catch(Unconfirmed_Write|Image_Request_Failure $error){$result=$error->receipt();$state='needs-review';$message=$error->getMessage();}
   catch(\Throwable $error){$result=null;$state='needs-review';$message='The native change was not confirmed. Read current state before retrying.';}
   Records::atomic(function()use(&$row,$g,$result,$state,$message){$row=$this->row($row['id'],$g,true);if($row['data']['state']!=='running')throw new \RuntimeException('The native receipt needs review.');$row['data']['state']=$state;$row['data']['message']=$message;$row['data']['result']=$result;$this->save($row);});return$this->projection($row);
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 /** Only an already completed, approved installation can authorize its same-grant continuation. */
 public function installation_origin(string $id,string $app,string $job): array {
  $row=$this->row($id,$this->grant());$d=$row['data'];$result=json_decode((string)($d['result']['resultJson']??''),true);
  if($d['operation']!=='mcp.app.install'||$d['state']!=='complete'||!is_array($d['approval'])||($d['arguments']['app']??'')!==$app||($result['job']['id']??'')!==$job)throw new \RuntimeException('This installation needs its original approved connection and job.');return$result['job'];
 }
 /** Resume/recovery is bound to the originating, natively approved import and grant. */
 public function bricks_origin(string $id,string $job,string $hash): void {
  $row=$this->row($id,$this->grant());$d=$row['data'];$result=json_decode((string)($d['result']['resultJson']??''),true);
  if($d['operation']!=='mcp.bricks.import.begin'||$d['state']!=='complete'||!is_array($d['approval'])||!hash_equals($d['planDigest'],(string)($d['approval']['digest']??''))||($d['arguments']['job']??'')!==$job||($d['arguments']['archiveSha256']??'')!==$hash||($result['job']??'')!==$job||($result['archiveSha256']??'')!==$hash)throw new \RuntimeException('Continue using the original approved connection and exact Bricks import job.');
 }
}
