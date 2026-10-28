<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
$source=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/';
$mode=$argv[1]??'bundled';if(!in_array($mode,['bundled','split'],true))exit(2);
$root=str_replace('\\','/',sys_get_temp_dir()).'/kiwe-app-contract-'.bin2hex(random_bytes(6));
if(!mkdir($root,0700))throw new RuntimeException('Owned fixture directory required');define('WP_PLUGIN_DIR',$root);
require $source.'Runtime_Contract.php';
if($mode==='split')eval(str_replace('public const BUNDLED = true;','public const BUNDLED = false;',substr(file_get_contents($source.'Package_Layout.php'),5)));else require $source.'Package_Layout.php';
require $source.'Runtime_Registry.php';
use DSA\Apps\Runtime_Contract as Contract;
use DSA\Apps\Runtime_Registry as Registry;
$wp_version='7.1.3';$loaded=0;$checks=[];$files=[];
function did_action($name){return $GLOBALS['loaded'];}
function plugins_url($path,$file){return 'https://site.invalid/wp-content/plugins/'.basename(dirname($file)).'/'.$path;}
function check(bool $ok,string $label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks'][$label]=true;}
$descriptor=['schema'=>Contract::SCHEMA,'id'=>'seo','version'=>'1.0.0','coreApi'=>1,'requiresPhp'=>'8.2','requiresWp'=>'7.0','requiresWoo'=>'','namespaces'=>['DSA\\SEO\\'=>'includes/SEO/'],'classes'=>[],'assets'=>['assets/js/seo.js'=>'assets/js/seo.js']];
try{
 check(Contract::validate($descriptor),'Exact versioned provider accepted');
 check(Contract::validate(array_replace($descriptor,['coreApi'=>2])),'New Core admits the prior and current provider APIs');
 foreach(['guest','ai','sitegraph']as$id){$provider=array_replace($descriptor,['id'=>$id,'coreApi'=>2,'requiresWoo'=>'','namespaces'=>[],'classes'=>[],'assets'=>[]]);check(Contract::validate($provider),'Noncommerce optional provider admitted '.$id);check(!Contract::validate(array_replace($provider,['requiresWoo'=>'11.2'])),'Noncommerce App cannot smuggle a Woo dependency '.$id);}
 foreach(['1.0','../1.0.0','1.0.0+unknown','1.0.0-rc.0','v1.0.0']as$bad)check(!Contract::version($bad),'Invalid version refused '.$bad);
 foreach(['../file.php','a/../file.php','/absolute.php','a//b.php','a\\b.php','a/.']as$bad)check(!Contract::relative($bad),'Unsafe relative path refused '.$bad);
 foreach([['coreApi'=>3],['extra'=>true],['id'=>'unknown'],['requiresWp'=>'0;code'],['requiresWoo'=>'1.0'],['namespaces'=>['DSA\\Access\\'=>'includes/Access/']],['classes'=>['DSA\\Runtime\\Update_Service'=>'includes/update.php']],['assets'=>['x'=>'../private.php']]]as$i=>$delta)check(!Contract::validate(array_replace($descriptor,$delta)),'Invalid provider boundary refused '.$i);
 check(!Contract::compatible(array_replace($descriptor,['version'=>'1.0.0-rc.2'])),'Older signed code cannot supply this Core API implementation');
 check(Contract::supports('seo','1.0.0-rc.3')&&Contract::supports('store','1.0.0-rc.2'),'Current independent App floors admitted');
 check(!Contract::supports('store','1.0.0-rc.1')&&!Contract::supports('unknown','99.0.0'),'Older or unknown App floor refused');
 check(!Contract::compatible(array_replace($descriptor,['requiresPhp'=>'99.0'])),'Incompatible PHP refused');
 check(!Contract::compatible(array_replace($descriptor,['requiresWp'=>'99.0'])),'Incompatible WordPress refused');
 check(Registry::installed('seo')===($mode==='bundled'),'App absence is honest in both profiles');
 check(!Registry::installed('arbitrary'),'Unknown capability absent');
 $plugin=$root.'/kiwe-seo';mkdir($plugin,0700);mkdir($plugin.'/includes',0700);mkdir($plugin.'/includes/SEO',0700);
 $file=$plugin.'/includes/SEO/Contract_Example.php';file_put_contents($file,'<?php namespace DSA\\SEO; $GLOBALS["providerLoads"]++; final class Contract_Example {}');$files[]=$file;$providerLoads=0;
 check(!Registry::register($descriptor,$root),'Foreign package root refused');
 check(Registry::register($descriptor,$plugin),'Fixed native plugin announces its provider');
 check($providerLoads===0,'Provider admission never constructs business code');
 check(Registry::installed('seo')&&Registry::provider('seo')===$descriptor,'Registered provider projected without database reads');
 check(!Registry::register($descriptor,$plugin),'Duplicate provider refused');
 check(!Registry::load('DSA\\SEO\\..\\private'),'Traversal class refused');
 check(!Registry::load('Other\\Service'),'Foreign namespace refused');
 check(!Registry::load('DSA\\SEO\\Missing_Service'),'Missing optional class has no fake fallback');
 check(Registry::load('DSA\\SEO\\Contract_Example')&&$providerLoads===1,'Owned normal plugin supplies its declared class lazily');
 check(Registry::load('DSA\\SEO\\Contract_Example')&&$providerLoads===1,'PHP native once authority retained');
 check(Registry::asset('assets/js/seo.js')==='https://site.invalid/wp-content/plugins/kiwe-seo/assets/js/seo.js','Owned public asset resolves to installed plugin');
 check(Registry::asset('assets/js/cart.js')==='','Absent asset remains absent');
 $loaded=1;$store=array_replace($descriptor,['id'=>'store','requiresWoo'=>'11.0','namespaces'=>['DSA\\Commerce\\'=>'includes/Commerce/'],'assets'=>[]]);
 check(!Registry::register($store,$root.'/kiwe-store'),'Late provider cannot change admitted runtime');
 $out=['passed'=>true,'mode'=>$mode,'checks'=>count($checks),'businessLoads'=>$providerLoads,'visitorWrites'=>0,'catalogueRequests'=>0];
}finally{foreach($files as$file)unlink($file);foreach(['/kiwe-seo/includes/SEO','/kiwe-seo/includes','/kiwe-seo','']as$dir)if(is_dir($root.$dir)&&!rmdir($root.$dir))throw new RuntimeException('Owned fixture cleanup refused');}
echo json_encode($out??['passed'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit(empty($out['passed'])?1:0);
