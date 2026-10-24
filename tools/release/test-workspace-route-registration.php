<?php
// Exercise registration from outside each service, as WordPress REST does.
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
define('KIWE_REACT_WORKSPACE',true);
function WC(){return (object)[];}function get_option($key,$default=false){return $default;}

$routes=[];$checks=0;
function register_rest_route($namespace,$route,$definitions){
	foreach(isset($definitions['callback'])?[$definitions]:$definitions as $endpoint){
		foreach(['callback','permission_callback'] as $key){
			if(!is_callable($endpoint[$key]??null)){
				throw new RuntimeException("$namespace$route has an uncallable $key");
			}
			$GLOBALS['checks']++;
		}
		$GLOBALS['routes'][]=$namespace.$route.' '.$endpoint['methods'];
	}
}
$base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/';
require $base.'Post_Workspace_Service.php';
foreach(['Team','Identity','Post','Taxonomy','Navigation','Guest','Notification','Commerce','Continuity'] as $name){
	require $base.$name.'_App_Service.php';
	$class=new ReflectionClass('DSA\\Access\\'.$name.'_App_Service');
	$class->newInstanceWithoutConstructor()->routes();
}
foreach(['Workspace_Activity_Service','Analytics_Reporting_Setup_Service']as$name){require $base.$name.'.php';(new ReflectionClass('DSA\\Access\\'.$name))->newInstanceWithoutConstructor()->routes();}
echo json_encode(['passed'=>true,'callbacks'=>$checks,'endpoints'=>count($routes),'routes'=>$routes])."\n";
