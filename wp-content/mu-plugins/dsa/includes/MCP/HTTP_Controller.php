<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

use DSA\Utilities\Atomic_Rate_Limiter;
use DSA\Utilities\Origin_Checker;

final class HTTP_Controller {
 public function __construct(private Authorization_Service $auth,private Operation_Registry $registry) {}
 public function register(): void {
  add_action('rest_api_init',[$this,'routes']);add_action('parse_request',[$this,'well_known'],0);
  add_action('admin_post_kiwe_mcp_authorize',[$this,'authorize']);add_action('admin_post_nopriv_kiwe_mcp_authorize',[$this,'authorize']);
  add_action('admin_post_kiwe_mcp_consent',[$this,'consent']);
  add_filter('rest_pre_serve_request',[$this,'headers'],20,4);
 }
 public function metadata(): array {
  return['issuer'=>Urls::issuer(),'authorization_endpoint'=>Urls::authorize(),'token_endpoint'=>Urls::endpoint('token'),'registration_endpoint'=>Urls::endpoint('register'),'revocation_endpoint'=>Urls::endpoint('revoke'),
   'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['none'],'revocation_endpoint_auth_methods_supported'=>['none'],
   'scopes_supported'=>array_merge($this->registry->scopes(),['offline_access']),'authorization_response_iss_parameter_supported'=>true];
 }
 public function resource_metadata(): array { return['resource'=>Urls::resource(),'authorization_servers'=>[Urls::issuer()],'bearer_methods_supported'=>['header'],'scopes_supported'=>$this->registry->scopes(),'resource_name'=>'Kiwe website MCP']; }
 public static function response(array $value,int $status=200): \WP_REST_Response {
  $r=new \WP_REST_Response($value,$status);$r->header('Cache-Control','no-store, private');$r->header('Pragma','no-cache');$r->header('X-Robots-Tag','noindex, nofollow');$r->header('X-Content-Type-Options','nosniff');return$r;
 }
 private function rate(string $kind,int $limit): void {
  if(!Atomic_Rate_Limiter::allow('kiwe-mcp-'.$kind.'|'.Origin_Checker::client_ip(),$limit,60,false))throw new OAuth_Failure('temporarily_unavailable',429);
 }
 public function routes(): void {
  foreach(['resource-metadata','authorization-metadata']as$path)register_rest_route(Urls::NS,'/'.$path,['methods'=>'GET','permission_callback'=>'__return_true','callback'=>fn()=>self::response($path==='resource-metadata'?$this->resource_metadata():$this->metadata())]);
  foreach(['register','token','revoke']as$path)register_rest_route(Urls::NS,'/'.$path,['methods'=>'POST','permission_callback'=>'__return_true','callback'=>fn($r)=>$this->request($path,$r)]);
  foreach ( ['source'=>'GET', 'result'=>'POST'] as $kind=>$method ) {
   register_rest_route( Urls::NS, '/visual/' . $kind, [
    'methods'=>$method, 'permission_callback'=>'__return_true',
    'callback'=>fn( \WP_REST_Request $request ) => $this->visual_file( $kind, $request ),
   ] );
  }
 }

 /** Exact file endpoints participate in the bearer-only request boundary. */
 public static function visual_request(): bool {
  $method = $_SERVER['REQUEST_METHOD'] ?? '';
  $kind = [ 'GET'=>'source', 'POST'=>'result' ][$method] ?? '';
  if ( $kind === '' ) return false;
  $suffix = '/' . Urls::NS . '/visual/' . $kind;
  $route = is_string( $_GET['rest_route'] ?? null ) ? rtrim( $_GET['rest_route'], '/' ) : '';
  $url = Urls::endpoint( 'visual/' . $kind );
  $path = rtrim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
  return $route === $suffix || ( ! wp_parse_url( $url, PHP_URL_QUERY ) && $path === rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
 }

 private function visual_file( string $kind, \WP_REST_Request $request ): \WP_REST_Response {
  $prior_user = get_current_user_id();
  Auth_Context::clear();
  try {
   $source = $kind === 'source';
   if ( ! Urls::request_host_matches() || $request->get_method() !== ( $source ? 'GET' : 'POST' ) ) throw new OAuth_Failure( 'invalid_target', 403 );
   $this->rate( 'visual-file', 45 );
   $header = (string) $request->get_header( 'Authorization' );
   if ( strlen( $header ) > 200 || ! preg_match( '/^Bearer (kiwe_mcp_access_[A-Za-z0-9_.-]+)$/D', $header, $match ) ) throw new OAuth_Failure( 'invalid_token', 401 );
   $grant = Auth_Context::establish( $this->auth, $match[1] );
   if ( ! $this->origin_for_client( $grant['client'], (string) $request->get_header( 'Origin' ) ) ) throw new OAuth_Failure( 'access_denied', 403 );
   $operation = $source ? Featured_Image_Provider::CLIENT_SOURCES : Featured_Image_Provider::CLIENT_RECEIVE;
   if ( ! isset( $this->registry->retain( $grant['pins'], $grant['scopes'] )[$operation] ) ) throw new OAuth_Failure( 'insufficient_scope', 403 );
   Records::seen( $grant['id'] );
   if ( $source ) {
    $photo = Featured_Image_Provider::client_file( (string) $request->get_header( 'X-Kiwe-Image-Proof' ), $grant );
    $response = self::response( [] );
    $response->set_data( $photo['bytes'] );
    $response->header( 'Content-Type', $photo['mime'] );
    $response->header( 'Content-Length', (string) strlen( $photo['bytes'] ) );
    return $response;
   }
   $intent = (string) $request->get_header( 'X-Kiwe-Intent' );
   $mime = strtolower( trim( (string) $request->get_header( 'Content-Type' ) ) );
   if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) throw new OAuth_Failure( 'unsupported_media_type', 415 );
   $bytes = $request->get_body();
   if ( strlen( $bytes ) > min( Featured_Image_Provider::MAX_CLIENT_BYTES, (int) wp_max_upload_size() ) ) throw new OAuth_Failure( 'invalid_request', 413 );
   $intents = new Intent_Service( $this->registry );
   if ( $intents->status( $intent )['operation'] !== $operation ) throw new OAuth_Failure( 'access_denied', 403 );
   return self::response( Featured_Image_Provider::with_client_upload( $bytes, $mime, fn() => $intents->apply( $intent ) ) );
  } catch ( OAuth_Failure $error ) {
   $response = self::response( [ 'error'=>$error->reason ], $error->status );
   if ( $error->status === 401 ) $response->header( 'WWW-Authenticate', Urls::challenge( 'invalid_token' ) );
   return $response;
  } catch ( \RuntimeException $error ) {
   $message = $kind === 'source' ? 'Read the current image brief before downloading a source.' : 'Read the current image review and send the approved file.';
   return self::response( [ 'error'=>'image_review_required', 'message'=>$message ], 409 );
  } catch ( \Throwable ) {
   return self::response( [ 'error'=>'temporarily_unavailable' ], 503 );
  } finally {
   Auth_Context::clear();
   wp_set_current_user( $prior_user );
  }
 }
 /** Public OAuth requests never inherit native cookie/application-password authority. */
 public function request(string $kind,\WP_REST_Request $request): \WP_REST_Response {
  try{
   $this->auth->ready();if(!Urls::request_host_matches())throw new OAuth_Failure('invalid_target');$this->rate($kind,$kind==='register'?5:40);
   if(strlen($request->get_body())>16384||strlen((string)$request->get_header('Authorization'))>0)throw new OAuth_Failure();
   $content=strtolower(trim(explode(';',(string)$request->get_header('Content-Type'))[0]));
   if($kind==='register'){
    if($content!=='application/json')throw new OAuth_Failure();$value=$request->get_json_params();
   }else{
    if($content!=='application/x-www-form-urlencoded')throw new OAuth_Failure();$value=$request->get_body_params();
    $keys=[];foreach(explode('&',$request->get_body())as$pair){$key=urldecode(explode('=',$pair,2)[0]);if(in_array($key,$keys,true)||str_contains($key,'['))throw new OAuth_Failure();$keys[]=$key;}
   }
   if(!is_array($value)||($value!==[]&&array_is_list($value))||count($value)>24)throw new OAuth_Failure();
   if($kind==='register')return self::response($this->auth->register_client($value),201);
   if(!$this->origin_for_client((string)($value['client_id']??''),(string)$request->get_header('Origin')))throw new OAuth_Failure('access_denied',403);
   if($kind==='token')return self::response($this->auth->exchange($value));
   $this->auth->revoke($value);return self::response([]);
  }catch(OAuth_Failure $e){return self::response(['error'=>$e->reason],$e->status);}
  catch(\Throwable){return self::response(['error'=>'temporarily_unavailable'],503);}
 }
 private function origin(string $url): string { $v=wp_parse_url($url);return is_array($v)&&isset($v['scheme'],$v['host'])?strtolower($v['scheme'].'://'.$v['host']).(isset($v['port'])?':'.$v['port']:''):''; }
 public function origin_for_client(string $client,string $origin): bool {
  if($origin==='')return true;if($origin==='null'||$origin!==$this->origin($origin))return false;
  if(hash_equals($this->origin(Urls::issuer()),$origin))return true;
  if(preg_match('/^cl_[a-f0-9]{32}$/D',$client)!==1||!Records::ready())return false;
  $row=Records::get('client',$client);if(!$row||$row['expiresAt']<=time())return false;
  foreach($row['data']['redirects']as$redirect)if($origin===$this->origin($redirect))return true;return false;
 }
 public function headers($served,$result,$request,$server) {
  if(!str_starts_with($request->get_route(),'/'.Urls::NS.'/'))return$served;
  header_remove('Access-Control-Allow-Credentials');header_remove('Access-Control-Allow-Origin');
  header('Cache-Control: no-store, private');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
  $path=basename($request->get_route());$origin=(string)$request->get_header('Origin');
  if(in_array($path,['resource-metadata','authorization-metadata','register'],true))header('Access-Control-Allow-Origin: *');
  elseif($origin!==''){$grant=$path==='mcp'?Auth_Context::current():null;$client=$grant['client']??$request->get_param('client_id');if(is_string($client)&&$this->origin_for_client($client,$origin)){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin',false);}}
  header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id, MCP-Protocol-Version');
  if ( ! $served && rtrim( $request->get_route(), '/' ) === '/' . Urls::NS . '/visual/source' &&
   $result instanceof \WP_REST_Response && $result->get_status() === 200 && is_string( $result->get_data() ) ) {
   $mime = $result->get_headers()['Content-Type'] ?? '';
   if ( in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
    header( 'Content-Type: ' . $mime );
    echo $result->get_data();
    return true;
   }
  }
  return$served;
 }
 public function well_known($wp): void {
  $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);$base=rtrim((string)wp_parse_url(Urls::issuer(),PHP_URL_PATH),'/');
  $authPaths=[$base.'/.well-known/oauth-authorization-server',$base.'/.well-known/openid-configuration','/.well-known/oauth-authorization-server'.$base];
  $resourcePaths=[$base.'/.well-known/oauth-protected-resource'.wp_parse_url(Urls::resource(),PHP_URL_PATH),$base.'/.well-known/oauth-protected-resource'];
  if(!in_array($path,array_merge($authPaths,$resourcePaths),true))return;
  if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){status_header(405);exit;}
  nocache_headers();header('Content-Type: application/json; charset=UTF-8');header('X-Robots-Tag: noindex, nofollow');header('Access-Control-Allow-Origin: *');
  echo wp_json_encode(in_array($path,$authPaths,true)?$this->metadata():$this->resource_metadata());exit;
 }
 private function cookie_name(string $flow): string { return 'kiwe_mcp_flow_'.substr(hash('sha256',$flow),0,20); }
 private function browser_secret(string $flow): string { $s=$_COOKIE[$this->cookie_name($flow)]??'';return is_string($s)?$s:''; }
 private function flow_cookie(string $flow,string $value,int $expires): void { setcookie($this->cookie_name($flow),$value,['expires'=>$expires,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); }
 public function authorize(): void {
  try{
   if(($_SERVER['REQUEST_METHOD']??'')!=='GET')throw new OAuth_Failure();$this->rate('authorize',30);
   $v=wp_unslash($_GET);$flow=$v['flow']??'';
   if(!is_string($flow))throw new OAuth_Failure();
   if($flow===''){$secret=Credentials::secret();$flow=$this->auth->begin($v,$secret);$this->flow_cookie($flow,$secret,time()+Authorization_Service::FLOW_TTL);}
   else $secret=$this->browser_secret($flow);
   if(!is_user_logged_in()){wp_safe_redirect(wp_login_url(add_query_arg('flow',$flow,Urls::authorize())));exit;}
   $review=$this->auth->review($flow,$secret,get_current_user_id());
   if(isset($review['decision']))$this->consent_complete($review['decision']!=='cancel');else $this->consent_document($review);
   exit;
  }catch(\Throwable){wp_die('This connection link is unavailable or has expired. Start again from your AI application.','Connect your AI',['response'=>400]);}
 }
 public function consent(): void {
  try{
   if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!Origin_Checker::is_same_site_request())throw new OAuth_Failure('access_denied',403);
   $v=wp_unslash($_POST);$flow=$v['flow']??'';$digest=$v['review']??'';
   if(!is_string($flow)||preg_match('/^p_[a-f0-9]{32}$/D',$flow)!==1||!is_string($digest)||preg_match('/^[a-f0-9]{64}$/D',$digest)!==1)throw new OAuth_Failure();
   if(!is_string($v['_wpnonce']??null)||!wp_verify_nonce($v['_wpnonce'],'kiwe_mcp_consent_'.$flow.'_'.$digest)||!in_array($v['choice']??null,['allow','cancel'],true))throw new OAuth_Failure('access_denied',403);
   $secret=$this->browser_secret($flow);
   $result=$this->auth->approve($flow,$secret,get_current_user_id(),$v['choice']==='allow',($v['offline']??'')==='1',$digest);
   if(!empty($result['alreadySent'])){$this->consent_complete(true);exit;}
   // A browser retry uses this same cookie and the same one-use code. Its original expiry can only get shorter.
   $this->flow_cookie($flow,$secret,$result['browserUntil']);
   // Finish the same-origin form before navigating to the validated callback.
   // Chromium applies the consent form's CSP throughout an HTTP redirect chain.
   $params=['state'=>$result['state'],'iss'=>Urls::issuer()]+array_intersect_key($result,array_flip(['code','error']));
   $this->consent_complete($v['choice']==='allow',add_query_arg($params,$result['redirect']));exit;
  }catch(\Throwable){nocache_headers();wp_die('Kiwe could not confirm this decision. Reopen the connection review from your AI application.','Connect your AI',['response'=>403]);}
 }
 private function consent_complete(bool $allowed,string $callback=''): void {
  $nonce=bin2hex(random_bytes(16));
  nocache_headers();header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
  header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'");
  $title=$allowed?'Connection decision sent':'Connection cancelled';
  $description=$allowed?'Return to your AI application to finish connecting.':'Return to your AI application whenever you’re ready to start a new connection.';
  echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($title).' · Kiwe</title><main style="font:17px/1.6 system-ui;max-width:42rem;margin:3rem auto;padding:1rem;color:#203d30"><h1>'.esc_html($title).'</h1><p>'.esc_html($description).'</p>';
  $link=esc_url($callback);
  if($link!=='')echo '<p><a id="kiwe-consent-return" href="'.$link.'" referrerpolicy="no-referrer">Return to your AI application</a></p>';
  echo '</main>';
  if($link!=='')echo '<script nonce="'.esc_attr($nonce).'">window.location.replace(document.getElementById("kiwe-consent-return").href);</script>';
  echo '</html>';
 }
 private function consent_document(array $review): void {
  // A no-referrer document makes native form POSTs send Origin: null. Keep
  // the real Origin for our same-origin consent form; callbacks still use
  // no-referrer above, and no referrer is sent to a different origin here.
  $script_nonce=bin2hex(random_bytes(16));
  nocache_headers();header('Cache-Control: no-store, private');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: same-origin');header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$script_nonce}'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
  $writes=array_filter($review['operations'],static fn($operation)=>$operation['effect']==='write');
  echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connect your AI · Kiwe</title><style>body{font:17px/1.6 system-ui;background:#f8f7f2;color:#203d30;margin:0}main{max-width:42rem;padding:clamp(1rem,5vw,3rem);margin:auto}h1{line-height:1.1}code{overflow-wrap:anywhere}button{font:inherit;padding:.8rem 1.2rem;border:1px solid;border-radius:.5rem;margin:1rem .5rem 0 0;cursor:pointer}button[value=allow]{background:#203d30;color:white}label{display:block}li{margin:.5rem 0}</style><main><p>KIWE · CONNECT YOUR AI</p><h1>Allow this connection?</h1><p><strong>'.esc_html($review['clientName']).'</strong> is asking to connect to <strong>'.esc_html(get_bloginfo('name')).'</strong>.</p><p>Website: <code>'.esc_html(Urls::issuer()).'</code><br>Return address: <code>'.esc_html($review['redirect']).'</code></p><p>Client names are supplied by the requesting application. Continue only if you started this connection.</p><h2>'.($writes?'Website guidance and permitted changes':'Read website guidance').'</h2><ul>';
  foreach($review['operations']as$operation)echo '<li>'.esc_html($operation['label']).'</li>';

  echo '</ul><p>'.esc_html($writes?'The changes listed above follow your website permissions. Kiwe asks you to review publishing, SEO metadata, campaign sends, policies and software changes on the website.':'This connection can read the guidance listed above. You can review other permissions in a separate connection.').'</p><form id="kiwe-connection-decision" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kiwe_mcp_consent"><input type="hidden" name="flow" value="'.esc_attr($review['id']).'"><input type="hidden" name="review" value="'.esc_attr($review['reviewDigest']).'">';
  wp_nonce_field('kiwe_mcp_consent_'.$review['id'].'_'.$review['reviewDigest']);
  if(in_array('offline_access',$review['scopes'],true))echo '<label><input type="checkbox" name="offline" value="1" checked> Keep this connection available for up to 14 days. You can disconnect it in Kiwe → Apps → MCP.</label>';
  echo '<button name="choice" value="allow">Allow connection</button><button name="choice" value="cancel">Cancel</button><p id="kiwe-consent-progress" role="status" hidden>Sending your decision…</p></form></main><script nonce="'.esc_attr($script_nonce).'">';
  echo <<<'SCRIPT'
const form = document.getElementById('kiwe-connection-decision');
const progress = document.getElementById('kiwe-consent-progress');
let submitting = false;
form.addEventListener('submit', (event) => {
 if (submitting) { event.preventDefault(); return; }
 submitting = true;
 progress.hidden = false;
 for (const button of form.querySelectorAll('button')) button.setAttribute('aria-disabled', 'true');
});
window.addEventListener('pageshow', (event) => {
 if (!event.persisted) return;
 submitting = false;
 progress.hidden = true;
 for (const button of form.querySelectorAll('button')) button.removeAttribute('aria-disabled');
});
SCRIPT;
  echo '</script></html>';
 }
}
