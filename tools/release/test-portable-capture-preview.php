<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('ABSPATH',__DIR__.'/');
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Portable_Capture_App_Service.php';
$proof=['schema'=>'kiwe.capture-preview-gate-source.v1','passed'=>false,'cleanup'=>true,'checks'=>[]];
$check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
try{
 $class=\DSA\Access\Portable_Capture_App_Service::class;$app=new $class();
 $check(!$class::enabled(),'absent flags keep capture preview disabled');$app->register();$app->routes();$check(true,'disabled preview registers neither worker route nor download hook');
 define('KIWE_REACT_WORKSPACE',true);$check(!$class::enabled(),'workspace activation alone cannot expose capture preview');
 $value=$argv[1]??'off';define('KIWE_PORTABLE_CAPTURE_PREVIEW',match($value){'on'=>true,'string'=>'true','one'=>1,default=>false});
 $check($class::enabled()===($value==='on'),'only an explicit boolean true exposes capture preview');$proof['passed']=true;
}catch(Throwable $e){$proof['error']=$e->getMessage();}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($proof['passed']?0:1);
