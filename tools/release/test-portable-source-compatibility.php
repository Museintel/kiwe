<?php
declare(strict_types=1);
if ( PHP_SAPI !== 'cli' ) exit(2);

function is_multisite(){return $GLOBALS['native']['multisite'];}
function get_option($name,$default=false){return $GLOBALS['native']['options'][$name]??$default;}
function get_template(){return $GLOBALS['native']['template'];}
function get_stylesheet(){return $GLOBALS['native']['stylesheet'];}
function get_template_directory(){return $GLOBALS['native']['templateDirectory'];}
function get_stylesheet_directory(){return $GLOBALS['native']['stylesheetDirectory'];}
function get_theme_root($slug=''){return $GLOBALS['native']['themeRoot'];}
function wp_get_theme($slug='',$root=''){return new SyntheticPortableTheme();}
function wp_upload_dir($time=null,$create=true,$refresh=false){
	if($time!==null||$create!==false||$refresh!==true)throw new RuntimeException('Upload preflight attempted native directory creation');
	$GLOBALS['native']['uploadCalls']++;return $GLOBALS['native']['uploads'];
}
final class SyntheticPortableTheme{public function exists(){return $GLOBALS['native']['themeExists'];}public function errors(){return $GLOBALS['native']['themeError'];}}

$worker=($argv[1]??'')==='--worker';
$root=$worker?($argv[3]??''):sys_get_temp_dir().'/kiwe-portable-layout-'.bin2hex(random_bytes(12));
$outside=$root.'-outside';$owned=[];
$proof=['schema'=>'kiwe.portable-source-compatibility-proof.v1','scope'=>'synthetic-native-WordPress-API-and-temporary-files','passed'=>false,'cleanup'=>false,'checks'=>[],'skips'=>[]];
$check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable $fn,string $label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error $e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};

try{
	if(!$worker){
		foreach([$root,$outside]as$directory){mkdir($directory,0700);$owned[]=$directory;}
		foreach(['wp-includes','wp-admin','wp-admin/includes','wp-content','wp-content/plugins','wp-content/plugins/example','wp-content/mu-plugins','wp-content/mu-plugins/kiwe-native','wp-content/mu-plugins/kiwe-native/includes','wp-content/mu-plugins/kiwe-native/includes/Backup','wp-content/themes','wp-content/themes/parent','wp-content/themes/child','wp-content/uploads','wp-content/cache']as$directory){mkdir($root.'/'.$directory,0700);$owned[]=$root.'/'.$directory;}
		$files=['index.php','wp-blog-header.php','wp-load.php','wp-settings.php','wp-config.php','wp-login.php','wp-cron.php','wp-includes/version.php','wp-includes/load.php','wp-includes/functions.php','wp-admin/admin.php','wp-admin/includes/admin.php','wp-content/plugins/example/main.php','wp-content/mu-plugins/kiwe-native/entry.php','wp-content/themes/parent/style.css','wp-content/themes/child/style.css','wp-content/cache/transient.bin'];
		foreach($files as$file){file_put_contents($root.'/'.$file,str_ends_with($file,'.php')?'<?php /* synthetic fixture, no credentials */':"synthetic file\n");$owned[]=$root.'/'.$file;}
		$loader=$root.'/wp-content/mu-plugins/kiwe-loader.php';file_put_contents($loader,"<?php function kiwe_mu_debug_log(){ /* synthetic native MU entry */ }\n");$owned[]=$loader;
		file_put_contents($outside.'/external-dependency.php','<?php /* synthetic external runtime dependency */');$owned[]=$outside.'/external-dependency.php';
		foreach(['Archive_Error','Portable_File_Inventory','Portable_Source_Compatibility']as$class){$target=$root.'/wp-content/mu-plugins/kiwe-native/includes/Backup/'.$class.'.php';copy(__DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$class.'.php',$target);$owned[]=$target;}
	}
	define('ABSPATH',$root.'/');define('WPINC','wp-includes');
	$scenario=$worker?($argv[2]??''):'';
	define('WP_CONTENT_DIR',$scenario==='external-content'?$outside:$root.'/wp-content');
	define('WP_PLUGIN_DIR',$scenario==='external-plugins'?$outside:$root.'/wp-content/plugins');
	define('WPMU_PLUGIN_DIR',$scenario==='external-mu'?$outside:$root.'/wp-content/mu-plugins');
	define('DSA_DIR',$root.'/wp-content/mu-plugins/kiwe-native/');define('DSA_FILE',DSA_DIR.'entry.php');
	define('DSA_VERSION','8.0.0-fixture');define('KIWE_MU_LOADER_VERSION',$scenario==='mismatched-kiwe'?'8.0.0-other':'8.0.0-fixture');
	$wp_version='7.1.2';$wp_db_version=61234;$wp_theme_directories=[$root.'/wp-content/themes'];
	$GLOBALS['native']=['multisite'=>false,'options'=>['active_plugins'=>['example/main.php']],'template'=>'parent','stylesheet'=>'child','templateDirectory'=>$root.'/wp-content/themes/parent','stylesheetDirectory'=>$root.'/wp-content/themes/child','themeRoot'=>$root.'/wp-content/themes','themeExists'=>true,'themeError'=>false,'uploads'=>['basedir'=>$root.'/wp-content/uploads','error'=>false],'uploadCalls'=>0];
	require $root.'/wp-config.php';require $root.'/wp-content/mu-plugins/kiwe-loader.php';require DSA_FILE;
	foreach(['Archive_Error','Portable_File_Inventory','Portable_Source_Compatibility']as$class)require DSA_DIR.'includes/Backup/'.$class.'.php';
	if($scenario==='external-loaded-php')require $outside.'/external-dependency.php';
	$plan=DSA\Backup\Portable_File_Inventory::scan($root);
	$inspect=static fn($inventory=null)=>DSA\Backup\Portable_Source_Compatibility::inspect($root,$inventory??$plan);
	if($worker){
		try{$inspect();echo json_encode(['rejected'=>false]);exit(1);}catch(DSA\Backup\Archive_Error $e){echo json_encode(['rejected'=>true,'safeMessage'=>!str_contains($e->getMessage(),$root)&&!str_contains($e->getMessage(),$outside)]);exit(0);}
	}
	$report=$inspect();$check($report['schema']===DSA\Backup\Portable_Source_Compatibility::SCHEMA,'native layout contract returned');
	$check($report['layout']['content']==='wp-content'&&$report['layout']['uploads']==='wp-content/uploads','actual native content and upload roots captured relatively');
	$check($report['dependencies']['activePlugins']===['wp-content/plugins/example/main.php'],'configured active plugin included');
	$check($report['dependencies']['mustUseEntries']===['wp-content/mu-plugins/kiwe-loader.php'],'native MU automatic entries included');
	$check($report['dependencies']['themes']['template']['directory']==='wp-content/themes/parent'&&$report['dependencies']['themes']['stylesheet']['directory']==='wp-content/themes/child','active parent and child theme roots included');
	$check($report['dependencies']['kiwe']===['loader'=>'wp-content/mu-plugins/kiwe-loader.php','package'=>'wp-content/mu-plugins/kiwe-native','entry'=>'wp-content/mu-plugins/kiwe-native/entry.php'],'Kiwe loader located through actual native function provenance');
	$check(count($report['dependencies']['loadedFiles'])===6,'loaded native PHP files included and external CLI entry alone omitted');
	$check($report['platform']['wordpress']==='7.1.2'&&$report['platform']['wordpressDatabaseVersion']===61234&&$report['platform']['php']===PHP_VERSION,'source platform declarations retained');
	$check(count($report['exclusions'])===1&&$report['exclusions'][0]['path']==='wp-content/cache','reviewed exact exclusions retained');
	$check(!array_filter($report['limits']),'preflight explicitly leaves database coherence external services and destination acceptance unverified');
	$encoded=json_encode($report);$check(!str_contains($encoded,str_replace('\\','/',$root))&&!str_contains($encoded,'synthetic fixture')&&!str_contains($encoded,'credentials'),'report excludes absolute roots and file bodies');
	$check($GLOBALS['native']['uploadCalls']===1,'upload API called without directory creation');
	$GLOBALS['native']['multisite']=true;$reject($inspect,'multisite refused');$GLOBALS['native']['multisite']=false;
	$wp_version='5.9.9';$reject($inspect,'unsupported WordPress generation refused');$wp_version='7.1.2';
	$wp_version='private invalid version';$reject($inspect,'unstructured version refused');$wp_version='7.1.2';
	$wp_db_version='61234';$reject($inspect,'invalid native database version declaration refused');$wp_db_version=61234;
	$reject(static fn()=>DSA\Backup\Portable_Source_Compatibility::inspect($outside,$plan),'source root must equal loaded WordPress root');
	$GLOBALS['native']['options']['active_plugins']=['missing/main.php'];$reject($inspect,'configured missing plugin refused');
	$GLOBALS['native']['options']['active_plugins']=['example/main.php','example/main.php'];$reject($inspect,'duplicate configured plugin refused');
	$GLOBALS['native']['options']['active_plugins']=['../escape.php'];$reject($inspect,'unsafe configured plugin refused');
	$GLOBALS['native']['options']['active_plugins']=['example/deep/main.php'];$reject($inspect,'unsupported deep plugin entry refused');
	$GLOBALS['native']['options']['active_plugins']=['example/main.php'];
	$GLOBALS['native']['options']['active_plugins']=[3=>'example/main.php'];$check($inspect()['dependencies']['activePlugins']===['wp-content/plugins/example/main.php'],'native sparse numeric plugin keys retain the exact dependency list');
	$GLOBALS['native']['options']['active_plugins']=['named'=>'example/main.php'];$reject($inspect,'named plugin option keys refused');
	$GLOBALS['native']['options']['active_plugins']=[-1=>'example/main.php'];$reject($inspect,'negative plugin option keys refused');
	$GLOBALS['native']['options']['active_plugins']=['example/main.php'];
	$GLOBALS['native']['themeRoot']=$outside;$GLOBALS['native']['templateDirectory']=$outside.'/parent';$reject($inspect,'external active theme root refused');$GLOBALS['native']['themeRoot']=$root.'/wp-content/themes';$GLOBALS['native']['templateDirectory']=$root.'/wp-content/themes/parent';
	$wp_theme_directories[]=$outside;$reject($inspect,'external registered theme directory refused');array_pop($wp_theme_directories);
	$GLOBALS['native']['themeExists']=false;$reject($inspect,'missing native theme refused');$GLOBALS['native']['themeExists']=true;
	$GLOBALS['native']['themeError']=true;$reject($inspect,'broken native theme refused');$GLOBALS['native']['themeError']=false;
	$GLOBALS['native']['stylesheetDirectory']=$root.'/wp-content/themes/parent';$reject($inspect,'inconsistent child theme identity refused');$GLOBALS['native']['stylesheetDirectory']=$root.'/wp-content/themes/child';
	$GLOBALS['native']['uploads']['basedir']=$outside;$reject($inspect,'external uploads refused');$GLOBALS['native']['uploads']['basedir']=$root.'/wp-content/uploads';
	$GLOBALS['native']['uploads']['error']='synthetic native error';$reject($inspect,'unavailable upload root refused');$GLOBALS['native']['uploads']['error']=false;
	$without=static function(string $path)use($plan){$copy=$plan;$copy['files']=array_values(array_filter($copy['files'],static fn($file)=>$file['path']!==$path));$copy['bytes']=array_sum(array_column($copy['files'],'bytes'));return $copy;};
	foreach(['wp-config.php','wp-load.php','wp-includes/version.php','wp-admin/admin.php','wp-content/plugins/example/main.php','wp-content/mu-plugins/kiwe-loader.php','wp-content/mu-plugins/kiwe-native/entry.php','wp-content/themes/parent/style.css','wp-content/themes/child/style.css','wp-content/mu-plugins/kiwe-native/includes/Backup/Archive_Error.php']as$i=>$path)$reject(static fn()=>$inspect($without($path)),'omitted required native file '.$i.' refused');
	$bad=$plan;$bad['bytes']++;$reject(static fn()=>$inspect($bad),'incorrect inventory byte total refused');
	$bad=$plan;$bad['files']=array_reverse($bad['files']);$reject(static fn()=>$inspect($bad),'unordered file inventory refused');
	$bad=$plan;$bad['extra']='unsupported';$reject(static fn()=>$inspect($bad),'unsupported inventory metadata refused');
	$bad=$plan;$bad['files'][0]['sha256']='broken';$reject(static fn()=>$inspect($bad),'invalid reviewed file digest refused');
	$bad=$plan;$bad['exclusions']=array_fill(0,101,['path'=>'synthetic-exclusion','reason'=>'excluded']);$reject(static fn()=>$inspect($bad),'exclusion count remains bounded');
	$bad=$plan;$bad['directories']=array_values(array_filter($bad['directories'],static fn($path)=>$path!=='wp-content/plugins'));$reject(static fn()=>$inspect($bad),'missing inventory parent refused');
	$bad=$plan;$bad['exclusions'][]=['path'=>'wp-content/mu-plugins','reason'=>'excluded dependency'];usort($bad['exclusions'],static fn($a,$b)=>strcmp($a['path'],$b['path']));$reject(static fn()=>$inspect($bad),'excluded native dependency subtree refused');
	$calls=0;try{DSA\Backup\Portable_Source_Compatibility::inspect($root,$plan,static function()use(&$calls){if(++$calls===5)throw new RuntimeException('owned budget');});throw new RuntimeException('Budget ignored');}catch(RuntimeException $e){$check($e->getMessage()==='owned budget'&&$calls===5,'budget interrupts read-only preflight');}
	foreach(['external-content','external-plugins','external-mu','external-loaded-php','mismatched-kiwe']as$case){
		$command=[PHP_BINARY];$dll=dirname(PHP_BINARY).'/ext/php_openssl.dll';if(PHP_OS_FAMILY==='Windows'&&is_file($dll))array_push($command,'-n','-d','extension='.$dll);
		array_push($command,__FILE__,'--worker',$case,$root);$pipes=[];$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Synthetic isolated worker unavailable');$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);$out=json_decode($stdout,true);$check($status===0&&$stderr===''&&($out['rejected']??false)===true&&($out['safeMessage']??false)===true,$case.' refused with nonprivate feedback');
	}
	$config=$root.'/wp-config.php';$temporary=$root.'/wp-config-owned-test.php';rename($config,$temporary);try{$reject($inspect,'missing root config refused even when an earlier configuration is loaded');}finally{rename($temporary,$config);}
	$check(DSA\Backup\Portable_File_Inventory::scan($root)===$plan,'preflight leaves complete source fixture unchanged');
	$proof['passed']=true;
}catch(Throwable $e){$proof['error']=$e->getMessage();}
if($worker){echo json_encode(['rejected'=>false,'workerFailed'=>true]);exit(1);}
foreach(array_reverse($owned)as$path){if(is_link($path)||is_file($path))unlink($path);elseif(is_dir($path))rmdir($path);}
$proof['cleanup']=!file_exists($root)&&!file_exists($outside);$proof['passed']=$proof['passed']&&$proof['cleanup'];
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($proof['passed']?0:1);
