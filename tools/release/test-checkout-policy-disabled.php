<?php
namespace DSA { class Settings { function all(){return ['abandoned_cart'=>['enabled'=>true]];}function defaults(){return [];}function get($key,$default=[]){return ['cod_gate'=>['enabled'=>true]];} } }
namespace DSA\Commerce { class Store_Analytics_Service {} }
namespace DSA\Communications { class Channel_Service {} }
namespace { define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';class WP_Error {function __construct(...$args){}}$options=0;$hooks=[];
function get_option(...$args){++$GLOBALS['options'];throw new RuntimeException('Non-Woo path read an optional option.');}
function wp_parse_args($a,$b){return array_replace($b,$a);}function sanitize_key($s){return $s;}function sanitize_text_field($s){return $s;}function __($s,...$args){return $s;}
function add_action($name,...$args){$GLOBALS['hooks'][]=$name;}function add_filter($name,...$args){$GLOBALS['hooks'][]=$name;}
$base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';require $base.'Commerce/Abandoned_Cart_Service.php';require $base.'Commerce/COD_Gate_Service.php';require $base.'Access/Checkout_Policy_Workspace_Service.php';
$s=new DSA\Settings();$c=new DSA\Commerce\Abandoned_Cart_Service($s,new DSA\Commerce\Store_Analytics_Service(),new DSA\Communications\Channel_Service());$c->register();$c->maybe_install();$c->maintenance();$cod=new DSA\Commerce\COD_Gate_Service($s);
if($c->ready()||$cod->is_enabled()||$c->send_due_automatic_reminders()!==0||!(new DSA\Access\Checkout_Policy_Workspace_Service($c))->policy() instanceof WP_Error||$options||in_array('init',$hooks,true))throw new RuntimeException('Non-Woo checkout policy was not inert.');
echo json_encode(['passed'=>true,'checks'=>6,'optionalOptionReads'=>$options,'publicInstallerHooks'=>0])."\n";
}
