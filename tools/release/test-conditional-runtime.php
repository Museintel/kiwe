<?php
/** Actual Plugin lifecycle and optional services, with unrelated owners replaced by spies. Native WP proof is separate. */
declare(strict_types=1);
namespace DSA {
	class Settings {
		public function all(): array { return $GLOBALS['settings']; }
		public function get($key, $default = null) { return $GLOBALS['settings'][$key] ?? $default; }
		public function run_migrations(): void {}
	}
}
namespace {
	define('ABSPATH', __DIR__ . '/');
	define('WP_CONTENT_DIR', sys_get_temp_dir().'/kiwe-conditional-runtime-no-pending');
	define('KIWE_REACT_WORKSPACE', true);
	$mode = $argv[1] ?? 'nonwoo';
	$settings = ['enabled'=>true,'phonekey'=>['enabled'=>true],'permissions'=>['enabled'=>true,'notifications_enabled'=>true],'metrics'=>['enabled'=>in_array($mode,['nonwoo-metrics','woo-store-off-metrics'],true)],'commerce'=>['cart_surface_enabled'=>$mode!=='woo-no-surface','checkout_surface_enabled'=>$mode!=='woo-no-surface','linked_products_enabled'=>true,'bestseller_sync_on_order'=>true,'cod_gate'=>['enabled'=>true]],'abandoned_cart'=>['enabled'=>false]];
	$hooks=[]; $constructed=[]; $registrations=[]; $checks=[];
	set_error_handler(static function($severity,$message,$file,$line) { throw new \ErrorException($message,0,$severity,$file,$line); });
	class DependencySpy {
		public function __construct(...$args) { $GLOBALS['constructed'][static::class]=($GLOBALS['constructed'][static::class]??0)+1; }
		public function __call($name,$args) { $GLOBALS['registrations'][]=static::class.'::'.$name; return null; }
		public static function __callStatic($name,$args) { return $name==='is_paused' ? $GLOBALS['mode']==='woo-paused' : null; }
	}
	$real=['Runtime/Private_Storage','Runtime/Release_Storage','Apps/Runtime_Registry','Apps/Runtime_Contract','Apps/Package_Layout','Apps/Store_App_Service','Apps/App_Card_Service','Plugin','Commerce/Cart_Payload_Service','Commerce/Commerce_Context_Service','Commerce/Linked_Products_Service','Commerce/Store_Analytics_Service','Commerce/Store_Commerce_Analytics_Service','Commerce/Abandoned_Cart_Service','Commerce/Checkout_Service','Commerce/COD_Gate_Service','Commerce/Product_Context_Service','Metrics/Metrics_Service','Notifications/Notification_Preference_Service','Notifications/Admin_Event_Notification_Service','Notifications/Order_Notification_Service','Access/WordPress_Role_Access_Service','Access/Workspace_Activity_Service','Access/Workspace_Admin_Service','Saved/Saved_Items_Service','Bricks/Bricks_Integration'];
	spl_autoload_register(static function($class) use($real) {
		if(!str_starts_with($class,'DSA\\'))throw new \RuntimeException('Unexpected dependency '.$class);
		$name=str_replace('\\','/',substr($class,4));
		if(in_array($name,$real,true)) { require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/'.$name.'.php'; return; }
		$at=strrpos($class,'\\');$ns=substr($class,0,$at);$short=substr($class,$at+1);
		eval('namespace '.$ns.'; class '.$short.' extends \\DependencySpy {}');
	});
	function add_action($name,$callback,$priority=10,$args=1) { $key=is_array($callback)?(is_object($callback[0])?spl_object_id($callback[0]):$callback[0]).'::'.$callback[1]:(is_object($callback)?spl_object_id($callback):$callback); $GLOBALS['hooks'][$name][$priority][$key]=[$callback,$args]; }
	function add_filter($name,$callback,$priority=10,$args=1) { add_action($name,$callback,$priority,$args); }
	function do_action($name,...$args) { $set=$GLOBALS['hooks'][$name]??[];ksort($set);foreach($set as $callbacks)foreach($callbacks as [$cb,$count])call_user_func_array($cb,array_slice($args,0,$count)); }
	function is_admin(){return false;} function wp_doing_ajax(){return false;} function wp_doing_cron(){return false;}
	function get_template(){return 'bricks';} function get_option($key,$fallback=false){if($key==='kiwe_store_app_enabled_v1'){if(str_contains($GLOBALS['mode'],'store-off'))return '0';if($GLOBALS['mode']==='nonwoo-requested-on')return '1';}return $fallback;} function current_user_can(...$args){return false;}
	function check(bool $ok,string $label): void { $GLOBALS['checks'][]=$label;if(!$ok)throw new \RuntimeException($label); }
	function count_hook(string $hook,string $class,string $method): int { $n=0;foreach($GLOBALS['hooks'][$hook]??[]as$callbacks)foreach($callbacks as[$cb])if(is_array($cb)&&is_object($cb[0])&&get_class($cb[0])===$class&&$cb[1]===$method)$n++;return $n; }
	function store_classes(): array { return array_values(array_filter(['Linked_Products_Service','Store_Analytics_Service','Store_Commerce_Analytics_Service','Abandoned_Cart_Service','Checkout_Service','COD_Gate_Service','Product_Context_Service'],static fn($name)=>class_exists('DSA\\Commerce\\'.$name,false))); }
	try {
		$plugin=\DSA\Plugin::instance();$plugin->boot();
		check(store_classes()===[],'constructing shared account/search/bookmark services loads no store service');
		do_action('muplugins_loaded');
		check(count_hook('woocommerce_add_to_cart','DSA\\Commerce\\Abandoned_Cart_Service','capture_after_cart_change')===0,'MU bootstrap does not guess whether a regular Woo plugin will load');
		if(str_starts_with($mode,'woo'))eval('function WC(){return (object)[];} class WC_Order {}');
		do_action('plugins_loaded');do_action('after_setup_theme');
		check(in_array('DSA\\Rest\\Account_Controller::register',$registrations,true)&&in_array('DSA\\Rest\\Search_Controller::register',$registrations,true)&&in_array('DSA\\Rest\\Saved_Items_Controller::register',$registrations,true),'account, search and Saved endpoints survive optional-store admission');
		check(count_hook('kiwe_notification_event','DSA\\Notifications\\Admin_Event_Notification_Service','dispatch_event')===1&&count_hook('user_register','DSA\\Notifications\\Admin_Event_Notification_Service','account_registered')===1,'core notification ingress and account events remain registered once');
		if(!str_starts_with($mode,'woo')||str_contains($mode,'store-off')) {
			$factoryCalls=0;$forbidden=static function()use(&$factoryCalls){$factoryCalls++;throw new \RuntimeException('Non-Woo read requested store dependency');};
			$cart=new \DSA\Commerce\Cart_Payload_Service(new \DSA\Settings(),$forbidden,$forbidden);
			check($cart->cart_data()['available']===false&&$cart->runtime_data()['available']===false&&$factoryCalls===0,'full and startup cart reads return unavailable without resolving optional factories');
			check(store_classes()===(in_array($mode,['nonwoo-metrics','woo-store-off-metrics'],true)?['Store_Analytics_Service']:[]),'non-Woo or inactive Store loads only explicitly requested generic analytics');
			$wooHooks=array_filter(array_keys($hooks),static fn($key)=>str_starts_with($key,'woocommerce_'));
			check($wooHooks===[],'non-Woo or inactive Store registers no cart, checkout, order, product or login Woo callbacks');
			check(!class_exists('DSA\\Access\\Commerce_App_Service',false),'non-Woo does not parse the store workspace');
			check(!in_array('DSA\\Rest\\Cart_Controller::register',$registrations,true)&&!in_array('DSA\\Rest\\Checkout_Controller::register',$registrations,true),'non-Woo exposes no Kiwe cart/checkout routes');
			check(count_hook('bricks/dynamic_tags_list','DSA\\Bricks\\Bricks_Integration','add_dynamic_tags')===1&&!isset($hooks['bricks/elements/product-add-to-cart/controls']),'Bricks retains shared dynamic tags without store element controls');
			check(count_hook('template_redirect','DSA\\Commerce\\Store_Analytics_Service','record_store_visit')===(in_array($mode,['nonwoo-metrics','woo-store-off-metrics'],true)?1:0),'optional visitor reporting still follows its original setting');
		} else {
			$paused=$mode==='woo-paused';
			check(count_hook('woocommerce_add_to_cart','DSA\\Commerce\\Abandoned_Cart_Service','capture_after_cart_change')===($paused?0:1),'native saved-cart capture is automatic even when a stale saved-cart setting says off, except recovery pause');
			check(count_hook('woocommerce_before_calculate_totals','DSA\\Commerce\\Store_Analytics_Service','sync_cart_upsell_coupons')===($paused?0:1),'Woo discount authority remains registered once when active');
			check(count_hook('woocommerce_product_options_general_product_data','DSA\\Commerce\\Product_Context_Service','render_fields')===1,'Woo product authoring remains available');
			$before=count_hook('woocommerce_add_to_cart','DSA\\Commerce\\Abandoned_Cart_Service','capture_after_cart_change');$plugin->register_store_services();$plugin->register_woo_capture_analytics();
			check(count_hook('woocommerce_add_to_cart','DSA\\Commerce\\Abandoned_Cart_Service','capture_after_cart_change')===$before&&count_hook('woocommerce_before_calculate_totals','DSA\\Commerce\\Store_Analytics_Service','sync_cart_upsell_coupons')===($paused?0:1),'repeated lifecycle admission does not duplicate capture or pricing');
			check(in_array('DSA\\Rest\\Cart_Controller::register',$registrations,true)===!$paused&&in_array('DSA\\Rest\\Checkout_Controller::register',$registrations,true)===(!$paused&&$mode!=='woo-no-surface'),'surface controls, Bricks cart fallback and recovery pause retain distinct authority');
		}
		check(!isset($hooks['bricks/elements/product-add-to-cart/controls'])||str_starts_with($mode,'woo'),'Bricks commerce controls depend on actual Woo presence');
		echo json_encode(['passed'=>true,'mode'=>$mode,'checks'=>$checks,'loadedStoreClasses'=>store_classes()]),"\n";
	} catch(\Throwable $error) { echo json_encode(['passed'=>false,'mode'=>$mode,'error'=>$error->getMessage(),'line'=>$error->getLine(),'checks'=>$checks]),"\n";exit(1); }
}
