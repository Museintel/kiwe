<?php
namespace DSA\MCP;
if(!defined('ABSPATH'))exit;

use DSA\Utilities\Origin_Checker;

/** Exact native confirmation; no browser field can confer remote authority. */
final class Change_Review_Controller {
 public function __construct(private Authorization_Service $auth,private Operation_Registry $registry){}
 public function register(): void {add_action('admin_post_kiwe_mcp_change_review',[$this,'handle']);}
 public function handle(): void {
  try{
   if(!is_user_logged_in()||!Credentials::actor_allowed(get_current_user_id())||!Urls::request_host_matches())throw new \RuntimeException('Sign in with the website account that approved this AI connection.');
   $method=$_SERVER['REQUEST_METHOD']??'';if(!in_array($method,['GET','POST'],true))throw new \RuntimeException('Open the native review link.');
   $input=$method==='POST'?wp_unslash($_POST):wp_unslash($_GET);
   $intents=new Intent_Service($this->registry);
   if(isset($input['batch'])){
    $this->batch($intents,$input,$method);
    return;
   }
   $id=$input['intent']??'';
   if(!is_string($id)||preg_match('/^i_[a-f0-9]{32}$/D',$id)!==1)throw new \RuntimeException('Choose a current change review.');
   if($method==='POST'){
    if(!Origin_Checker::is_same_site_request()||!is_string($input['_wpnonce']??null)||!wp_verify_nonce($input['_wpnonce'],'kiwe_mcp_change_'.$id)||!in_array($input['choice']??null,['approve','cancel'],true))throw new \RuntimeException('Reload this native change review before confirming.');
    $result=$intents->approve($id,$this->auth,$input['choice']==='approve');
    $this->document($result,null,$input['choice']==='approve'?'Approved. Return to your AI to apply this exact proposal.':'Cancelled. This proposal cannot be applied.');return;
   }
   $view=$intents->native_review($id,$this->auth);$this->document($view['review'],$view['arguments']);
  }catch(\Throwable){nocache_headers();wp_die('This review is unavailable or its native state changed. Return to your AI for a fresh review.','Kiwe change review',['response'=>409]);}
 }
 private function batch(Intent_Service $intents,array $input,string $method): void {
  if (isset($input['intent']) || !is_string($input['batch']) || strlen($input['batch']) > Intent_Service::BATCH_LIMIT * 35 - 1) {
   throw new \RuntimeException('Choose a current draft review.');
  }
  $isPost = $method === 'POST';
  if ($isPost && (!Origin_Checker::is_same_site_request() || !is_string($input['_wpnonce'] ?? null) ||
   !in_array($input['choice'] ?? null, ['approve', 'cancel'], true))) {
   throw new \RuntimeException('Reload this draft review before confirming.');
  }
  $ids = explode(',', $input['batch']);
  $receipt = $intents->native_batch_receipt($ids);
  if ($receipt === null) {
   try {
    if ($isPost) {
     $allow = $input['choice'] === 'approve';
     $result = $intents->approve_batch($ids, $this->auth, $input['_wpnonce'], $allow);
    } else {
     $view = $intents->native_batch($ids, $this->auth);
    }
   } catch (\RuntimeException $error) {
    // A parallel confirmation can finish the batch while the page is opening.
    $receipt = $intents->native_batch_receipt($ids);
    if ($receipt === null) throw $error;
   }
   if ($receipt === null) {
    if ($isPost) {
     $message = match (true) {
      !$allow => 'Cancelled. These changes will not be applied.',
      $result['kind'] === 'publish' => 'Approved. Your AI can now publish these products.',
      default => 'Approved. Your AI can now finish saving these drafts.',
     };
     $this->batch_document(['kind'=>$result['kind']], '', $message);
    } else {
     $this->batch_document($view, $input['batch']);
    }
    return;
   }
  }
  if ($isPost && !wp_verify_nonce($input['_wpnonce'], 'kiwe_mcp_review_batch_' . $receipt['digest'])) {
   throw new \RuntimeException('Reload this draft review before confirming.');
  }
  $isPublication = ($receipt['kind'] ?? '') === 'publish';
  $message = match ($receipt['state']) {
   'complete' => $isPublication ? 'Publication complete. Kiwe published the approved products.' : 'Review complete. Kiwe saved the approved weights and photo files.',
   'needs-check' => 'Approval recorded. Ask your AI to check which changes were saved.',
   default => 'Approval recorded. Kiwe is waiting for confirmation that the changes were saved.',
  };
  $this->batch_document($receipt, '', $message);
 }
 private static function weight_label($value,string $unit): string {
  if(!is_numeric($value))return 'Not set';
  if($unit==='kg'&&(float)$value>0&&(float)$value<1){
   $grams=(float)$value*1000;
   if($grams>=1&&abs($grams-round($grams))<0.00000001)return (string)(int)round($grams).' g';
  }
  return trim((string)$value.' '.$unit);
 }
 private function batch_document(array $batch,string $ids,string $message=''): void {
  $isPublication = ($batch['kind'] ?? '') === 'publish';
  $title = $isPublication ? 'Publish products' : 'Review product drafts';
  $this->open_document($title,$title,$isPublication ? 'KIWE · PRODUCT PUBLICATION' : 'KIWE · PRODUCT DRAFTS');
  if($message!==''){
   $this->receipt($message);
  }else{
   echo $isPublication ? '<p>Review the products below. After approval, your AI will publish them so customers can order them.</p>' :
    '<p>Review these changes together. Your products will stay as drafts.</p>';
   foreach($batch['products'] as $product){
    echo '<section class="draft"><h2>'.esc_html($product['title']!==''?$product['title']:'Untitled product').'</h2><ul>';
    foreach($product['changes'] as $item){
     $review=$item['review'];
     if ($isPublication) {
      $offer = $item['offer'];
      $price = html_entity_decode(wp_strip_all_tags(wc_price($offer['price'], ['currency'=>$offer['currency']])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $before = json_decode($review['before'], true);
      echo '<li><strong>Price:</strong> '.esc_html($price).'</li><li><strong>Stock:</strong> Available to order</li>';
      if (!empty($before['weight'])) {
       echo '<li><strong>Shipping weight:</strong> '.esc_html(self::weight_label($before['weight'], (string)($before['weightUnit'] ?? ''))).'</li>';
      }
     }elseif($review['operation']==='store.product.delivery'){
      $before=json_decode($review['before'],true);
      $after=json_decode($review['after'],true);
      $unit=(string)($before['weightUnit']??'');
      echo '<li><strong>Shipping weight:</strong> '.esc_html(self::weight_label($before['weight']??null,$unit)).' &rarr; <strong>'.esc_html(self::weight_label($after['weight']??null,$unit)).'</strong></li>';
     }else{
      echo '<li><strong>Featured photo:</strong> Save the exact photo you reviewed with your AI.</li>';
     }
    }
    echo '</ul><details><summary>Exact change details</summary>';
    foreach($product['changes'] as $item)echo '<h3>'.esc_html($item['review']['summary']).'</h3>'.self::review_content($item['review']['after']);
    echo '</details></section>';
   }
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kiwe_mcp_change_review"><input type="hidden" name="batch" value="'.esc_attr($ids).'">';
   wp_nonce_field('kiwe_mcp_review_batch_'.$batch['digest']);
   echo '<button name="choice" value="approve">'.($isPublication ? 'Approve publication' : 'Approve changes').'</button><button name="choice" value="cancel">Cancel</button></form>';
  }
  echo '</main></html>';
 }
 /** Readable display of an existing native review. The saved plan still owns approval. */
 private static function review_content( string $value,string $operation = '' ): string {
  $values = json_decode( $value,true,8 );
  if ( ! is_array( $values ) ) return '<p>' . esc_html( $value === '' ? 'No information was provided for this step.' : $value ) . '</p>';

  if ( $operation === 'mcp.cleanup.change' && isset( $values['title'],$values['type'],$values['status'] ) ) {
   $types = [ 'post'=>'Post','page'=>'Page','bricks_template'=>'Bricks template' ];
   $status = [ 'draft'=>'Draft','trash'=>'In Trash','publish'=>'Published','pending'=>'Pending review','private'=>'Private','future'=>'Scheduled' ];
   $matches = $values['referenceMatches'] ?? [];
   $values = [
    'Title'=>$values['title'] ?: 'Untitled',
    'Content type'=>$types[$values['type']] ?? $values['type'],
    'Status'=>$status[$values['status']] ?? $values['status'],
    'References found in the native audit'=>is_array( $matches ) ? array_sum( $matches ) : $matches,
   ];
  } elseif ( $operation === 'mcp.maintenance.cache' && isset( $values['pageCache']['adapters'] ) && is_array( $values['pageCache']['adapters'] ) ) {
   $adapters = array_column( $values['pageCache']['adapters'],'label' );
   $values = [ 'Cache provider'=>array_filter( $adapters,'is_string' ),'Pages affected'=>'Entire website page cache' ];
  }
  return self::review_values( $values );
 }

 private static function review_values( array $values ): string {
  if ( $values === [] ) return '<p>None</p>';
  $list = array_is_list( $values );
  $html = $list ? '<ul>' : '<dl>';
  foreach ( $values as $key=>$value ) {
   if ( is_array( $value ) ) $display = self::review_values( $value );
   else {
    $text = match ( true ) {
     $value === true=>'Yes', $value === false=>'No', $value === null || $value === ''=>'None', default=>(string) $value,
    };
    $display = esc_html( $text );
   }
   if ( $list ) $html .= '<li>' . $display . '</li>';
   else {
    $label = preg_replace( '/([a-z\d])([A-Z])/','$1 $2',(string) $key );
    $label = ucfirst( str_replace( [ '_','-' ],' ',$label ) );
    $html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $display . '</dd>';
   }
  }
  return $html . ( $list ? '</ul>' : '</dl>' );
 }

 private function open_document(string $title,string $heading,string $eyebrow): void {
  nocache_headers();header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex, nofollow',true);header('Referrer-Policy: same-origin');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
  echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($title).' · Kiwe</title><style>body{font:17px/1.6 system-ui;background:#f8f7f2;color:#203d30;margin:0}main{max-width:44rem;padding:clamp(1rem,5vw,3rem);margin:auto;overflow-wrap:anywhere}h1{line-height:1.15}pre{font:inherit;white-space:pre-wrap;overflow-wrap:anywhere;padding:1rem;border:1px solid #c6d1c9;border-radius:.5rem;background:#fff}dt{font-weight:650}dd{margin:0 0 .75rem}dd dl,dd ul{margin:.4rem 0 .7rem 1rem}button,a{font:inherit}button{padding:.8rem 1.2rem;border:1px solid;border-radius:.5rem;margin:1rem .5rem 0 0;cursor:pointer}button[value=approve]{background:#203d30;color:white}details{margin:1rem 0}.draft{background:#fff;border:1px solid #c6d1c9;border-radius:.6rem;margin:1rem 0;padding:1rem}.draft h2{font-size:1.18rem;margin:0}.draft ul{padding-inline-start:1.25rem}.draft details{margin:0}</style><main><p>'.esc_html($eyebrow).'</p><h1>'.esc_html($heading).'</h1><p>'.esc_html(Urls::issuer()).'</p>';
 }
 private function receipt(string $message): void {
  echo '<p role="status">'.esc_html($message).'</p><p><a href="'.esc_url(admin_url('admin.php?page=kiwe-mcp-app')).'">Return to MCP connections</a></p>';
 }
 private function document(array $review,?array $arguments,string $message=''): void {
  $this->open_document('Review this change',$review['summary'],'KIWE · REVIEW THIS CHANGE');
  if($message!=='')$this->receipt($message);
  else{
   $expiry = strtotime( $review['reviewExpires'] );
   $deadline = $expiry ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),$expiry ) : $review['reviewExpires'];
   echo '<p>Kiwe checks the website again before your AI can apply this change. If the relevant details change, Kiwe asks for a fresh review.</p><h2>What’s on the site now</h2>'.self::review_content($review['before'],$review['operation']).'<h2>What will change</h2>'.self::review_content($review['after']).'<details><summary>Technical details</summary><h3>Current preview</h3><pre>'.esc_html($review['before']).'</pre><h3>Proposed preview</h3><pre>'.esc_html($review['after']).'</pre><h3>Exact requested inputs</h3><pre>'.esc_html(wp_json_encode($arguments,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</pre></details><p>Review available until <time datetime="'.esc_attr($review['reviewExpires']).'">'.esc_html($deadline).'</time>.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kiwe_mcp_change_review"><input type="hidden" name="intent" value="'.esc_attr($review['id']).'">';
   wp_nonce_field('kiwe_mcp_change_'.$review['id']);echo '<button name="choice" value="approve">Approve this change</button><button name="choice" value="cancel">Cancel</button></form>';
  }
  echo '</main></html>';
 }
}
