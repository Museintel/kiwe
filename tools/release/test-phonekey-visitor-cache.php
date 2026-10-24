<?php
declare(strict_types=1);
ob_start();
define('PK_VISITOR_COOKIE','pkv');define('PK_VER','fixture-version');define('COOKIEPATH','/');define('COOKIE_DOMAIN','');define('YEAR_IN_SECONDS',31536000);
$actions=[];$loggedIn=false;$checks=0;
function add_action($name,$callback,$priority=10){global$actions;$actions[$name]=$callback;}
function get_option($key){return PK_VER;}
function is_admin(){return false;}function is_user_logged_in(){global$loggedIn;return$loggedIn;}function is_ssl(){return true;}
function pk_apply_policy_defaults_once(){}function pk_apply_subscriber_signup_default_once(){}function pk_apply_editorial_access_policy_once(){}function pk_apply_session_timeout_default_off_once(){}function pk_enforce_session_timeout(){}
function pk_rand_token($bytes){return bin2hex(random_bytes($bytes));}function wp_unslash($v){return$v;}function sanitize_text_field($v){return trim($v);}
function pk_ip(){return'192.0.2.15';}function pk_ua(){return'Synthetic browser';}function pk_hmac($v){return hash_hmac('sha256',$v,'synthetic-key');}
function check8($yes,$label){global$checks;if(!$yes)throw new RuntimeException($label);$checks++;}
$source=file_get_contents(dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
foreach(['pk_ensure_visitor_cookie','pk_visitor_hash']as$name){if(!preg_match('/function '.preg_quote($name,'/').'\(\)\s*\{.*?\n\}/s',$source,$m))throw new RuntimeException('Native function source missing');eval($m[0]);}
if(!preg_match('/add_action\( \'init\', function \(\) \{.*?\n\}, 1 \);/s',$source,$m))throw new RuntimeException('Native init callback missing');eval($m[0]);
$_COOKIE=[];$actions['init']();check8($_COOKIE===[],'Ordinary guest init allocates no identity cookie');
$first=pk_visitor_hash();check8(!empty($_COOKIE['pkv']),'Explicit identity journey seeds a visitor');check8($first===pk_hmac($_COOKIE['pkv']),'First identity is the cookie identity');
$token=$_COOKIE['pkv'];check8(pk_visitor_hash()===$first&&$_COOKIE['pkv']===$token,'Repeated same-request journey keeps identity');
$_COOKIE=['pkv'=>'existing-visitor'];check8(pk_visitor_hash()===pk_hmac('existing-visitor')&&$_COOKIE['pkv']==='existing-visitor','Existing visitor identity preserved');
$_COOKIE=[];$loggedIn=true;pk_ensure_visitor_cookie();check8($_COOKIE===[],'Authenticated account acquires no guest identity');
ob_end_clean();echo json_encode(['passed'=>true,'checks'=>$checks,'scope'=>'Extracted native callbacks against local WordPress API boundaries; HTTP proof separate']).PHP_EOL;
