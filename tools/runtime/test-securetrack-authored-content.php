<?php
declare(strict_types=1);
namespace Bricks {final class Capabilities {public static function current_user_can_use_builder($id){return$GLOBALS['builder'];}}}
namespace {
 define('ABSPATH',__DIR__.'/');$loggedIn=true;$canEdit=true;$unfiltered=true;$builder=true;$deferred=false;$checks=[];
 function is_user_logged_in(){return$GLOBALS['loggedIn'];}function current_user_can($cap,...$args){return$cap==='unfiltered_html'?$GLOBALS['unfiltered']:$GLOBALS['canEdit'];}
 function wp_verify_nonce($nonce,$action){return$nonce==='valid-'.$action;}function wp_unslash($v){return$v;}function absint($v){return abs((int)$v);}function wp_json_encode($v){return json_encode($v);}
 function wp_parse_url($v,$part=-1){return parse_url($v,$part);}function rest_url(){return'https://fixture.example/sub/wp-json/';}function get_post($id){return(object)['ID'=>$id,'post_type'=>'post'];}
 function get_post_types(...$args){return[(object)['name'=>'post','rest_namespace'=>'wp/v2','rest_base'=>'posts','cap'=>(object)['create_posts'=>'edit_posts']]];}
 function apply_filters($tag,$value){return$tag==='kiwe_securetrack_defer_native_body'?$GLOBALS['deferred']:$value;}function sanitize_text_field($v){return strip_tags((string)$v);}
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Payload_Service.php';
 $source=file_get_contents(dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');$from=strpos($source,'function stp_waf_normalize(');$to=strpos($source,'function stp_waf_guard(',$from);eval(substr($source,$from,$to-$from));
 $check=static function($ok,$label)use(&$checks){$checks[$label]=(bool)$ok;if(!$ok)throw new RuntimeException($label);};
 $request=static function($uri,$post,$body,$nonce=''){$_SERVER=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>$uri,'CONTENT_TYPE'=>'application/json','HTTP_X_WP_NONCE'=>$nonce];$_GET=[];$_POST=$_REQUEST=$post;$_COOKIE=[];$GLOBALS['HTTP_RAW_POST_DATA']=$body;};
 $article=['title'=>'About UNION SELECT','content'=>'<p>Learn about UNION SELECT queries and system($_GET["cmd"]) examples.</p>'];
 $request('/sub/wp-json/wp/v2/posts/12',[],json_encode($article),'valid-wp_rest');$before=$GLOBALS['HTTP_RAW_POST_DATA'];
 $check(stp_scan_payload(stp_request_payload())['score']<80,'Native REST editor content remains data under its nonce and object authority');
 $check($GLOBALS['HTTP_RAW_POST_DATA']===$before,'Inspection never modifies the actual editor payload');
 $request('/sub/?rest_route=/wp/v2/posts/12',[],json_encode($article),'valid-wp_rest');$_GET['rest_route']='/wp/v2/posts/12';$check(stp_scan_payload(stp_request_payload())['score']<80,'Default-permalink native editor has the same authored-field boundary');
 $request('/sub/wp-json/wp/v2/posts/12',[],json_encode($article),'invalid');$check(stp_scan_payload(stp_request_payload())['score']>=80,'A forged native REST nonce cannot hide an attack');
 $request('/sub/wp-json/wp/v2/posts/12',[],json_encode($article),'valid-wp_rest');$canEdit=false;$check(stp_scan_payload(stp_request_payload())['score']>=80,'A nonce never substitutes for current object permission');$canEdit=true;
 $request('/sub/wp-json/unknown/v1/write',[],json_encode($article),'valid-wp_rest');$check(stp_scan_payload(stp_request_payload())['score']>=80,'Other REST routes receive no blanket authenticated bypass');
 $request('/sub/wp-json/wp/v2/posts/12',[],json_encode($article+['sql'=>'id=1 UNION SELECT secret FROM accounts']),'valid-wp_rest');$check(stp_scan_payload(stp_request_payload())['score']>=80,'Unrelated request fields retain full attack screening');
 $request('/sub/wp-admin/post.php',['action'=>'editpost','post_ID'=>12,'_wpnonce'=>'valid-update-post_12','content'=>$article['content']],'content='.urlencode($article['content']));$_SERVER['CONTENT_TYPE']='application/x-www-form-urlencoded';$check(stp_scan_payload(stp_request_payload())['score']<80,'Classic native post editor uses the same object-bound nonce');
 $_POST['_wpnonce']='invalid';$check(stp_scan_payload(stp_request_payload())['score']>=80,'Invalid classic editor nonce does not exclude body fields');
 $bricks=['action'=>'bricks_save_post','postId'=>12,'nonce'=>'valid-bricks-nonce-builder','content'=>[['name'=>'code','settings'=>['code'=>'<?php system($safe_input); ?>','javascriptCode'=>'const sleep = ms => new Promise(r => setTimeout(r,ms));']]]];
 $request('/sub/wp-admin/admin-ajax.php',$bricks,json_encode($bricks));$check(stp_scan_payload(stp_request_payload())['score']<80,'Native Bricks content retains builder, nonce, object and unfiltered authority');
 $unfiltered=false;$check(stp_scan_payload(stp_request_payload())['score']>=80,'Builder access alone does not grant a code-authoring exclusion');$unfiltered=true;
 $builder=false;$check(stp_scan_payload(stp_request_payload())['score']>=80,'Current native Bricks denial overrides a valid nonce');$builder=true;
 $_POST['sql']='UNION SELECT password FROM accounts';$check(stp_scan_payload(stp_request_payload())['score']>=80,'Bricks exclusion is field-specific, not an AJAX bypass');
 $loggedIn=false;$request('/sub/wp-admin/admin-ajax.php',$bricks,json_encode($bricks));$check(stp_scan_payload(stp_request_payload())['score']>=80,'Visitors cannot claim native editor authority');$loggedIn=true;
 $deferred=true;$request('/sub/wp-json/kiwe-mcp/v1/mcp',[],json_encode(['params'=>['value'=>'UNION SELECT']]));$check(stp_scan_payload(stp_request_payload())['score']<80,'A native MCP deferral leaves body ownership to its later authenticated gate');
 $_GET['sql']='UNION SELECT secret FROM accounts';$check(stp_scan_payload(stp_request_payload())['score']>=80,'Deferred native bodies do not defer query screening');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'nativeSql'=>false,'actualBrowserNonce'=>false])."\n";
}
