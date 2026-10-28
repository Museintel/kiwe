<?php
declare(strict_types=1);
namespace Bricks {
	final class Database {public static $mode='inline';public static function get_setting($name){return self::$mode;}}
}
namespace DSA\Bricks {
	function headers_list(){return $GLOBALS['headers'];}
}
namespace {
define('ABSPATH',__DIR__.'/fixture/');define('BRICKS_VERSION',$argv[1]??'2.4.3');
if(($argv[2]??'')==='rest')define('REST_REQUEST',true);
$hooks=[];$context=[];$headers=[];$veto=false;$writes=0;$cacheWrites=0;$options=[];
function add_filter($name,$callback,$priority=10){$GLOBALS['hooks'][$name][]=$callback;}
function apply_filters($name,$value){return $name==='kiwe/bricks/reduce_inline_css'?!$GLOBALS['veto']:$value;}
function is_admin(){return !empty($GLOBALS['context']['admin']);}
function wp_doing_ajax(){return !empty($GLOBALS['context']['ajax']);}
function bricks_is_builder(){return !empty($GLOBALS['context']['builder']);}
function bricks_is_builder_call(){return !empty($GLOBALS['context']['builderCall']);}
function is_customize_preview(){return !empty($GLOBALS['context']['customizer']);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function add_option($key,$value,$unused='',$autoload=null){global $options,$cacheWrites;if(array_key_exists($key,$options))return false;if($autoload!==false)throw new RuntimeException('Autoload');$options[$key]=$value;$cacheWrites++;return true;}
function wp_cache_delete($key,$group){}
function wp_salt($scheme){return 'fixture-only-'.$scheme;}
class wpdb {public $options='wp_options';public function prepare($sql,...$args){return $args;}public function query($args){global $options,$cacheWrites;[$after,$name,$before]=$args;if(($options[$name]??false)!==$before)return 0;$options[$name]=$after;$cacheWrites++;return 1;}}
$wpdb=new wpdb;
class WP_Styles {
	public $registered=[];public $writes=0;
	public function get_data($name,$key){return $this->registered[$name]->extra[$key]??false;}
	public function add_data($name,$key,$value){$this->writes++;$this->registered[$name]->extra[$key]=$value;return true;}
}
$root=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
require $root.'Performance/CSS_Rule_Reducer.php';require $root.'Performance/CSS_Reduction_Cache.php';require $root.'Bricks/Inline_Style_Service.php';
use DSA\Bricks\Inline_Style_Service;
$checks=0;$assert=static function($ok,$name)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($name);};
$service=new Inline_Style_Service;$service->register();$supported=version_compare(BRICKS_VERSION,'2.3.4','>=')&&version_compare(BRICKS_VERSION,'2.5','<');
$assert(isset($hooks['print_styles_array'])===$supported,'Qualified native version registration');
if(!$supported){echo json_encode(['passed'=>true,'version'=>BRICKS_VERSION,'checks'=>$checks,'writes'=>0]),PHP_EOL;exit;}
$expected='.x{color:red;background:blue;margin:1px;--spacing:'.str_repeat('1',140).'}';
$css=str_repeat($expected,3000);
$fixture=static function($after)use(&$wp_styles){
	$wp_styles=new WP_Styles;
	$wp_styles->registered['bricks-frontend-inline']=(object)['src'=>false,'deps'=>['native-theme'],'ver'=>'native','args'=>true,'extra'=>['after'=>$after,'conditional'=>'native','before'=>['untouched']]];
	return $wp_styles;
};
$handles=['native-before','bricks-frontend-inline','native-after'];$style=$fixture([$css]);$before=clone $style->registered['bricks-frontend-inline'];$before->extra=$before->extra;
if(defined('REST_REQUEST')&&REST_REQUEST){$assert($service->reduce($handles)===$handles&&$style->writes===0&&$cacheWrites===0,'REST/cart endpoints perform no CSS work');echo json_encode(['passed'=>true,'version'=>BRICKS_VERSION,'checks'=>$checks,'rest'=>true,'cacheWrites'=>0]),PHP_EOL;exit;}
$assert($service->reduce($handles)===$handles,'Native handle and order unchanged');
$assert($style->get_data('bricks-frontend-inline','after')===[$expected],'Large duplicate CSS is reduced');
$after=$style->registered['bricks-frontend-inline'];unset($before->extra['after']);$copy=clone $after;$copy->extra=$copy->extra;unset($copy->extra['after']);
$assert($before==$copy,'Native dependencies, metadata and delivery remain');
$count=$style->writes;$service->reduce($handles);$assert($style->writes===$count,'Repeated printing does not rewrite');
$style=$fixture(['small']);(new Inline_Style_Service)->reduce($handles);$assert($style->writes===0,'Small styles skip');
$style=$fixture([$css]);(new Inline_Style_Service)->reduce(['other']);$assert($style->writes===0,'Foreign handles skip');
foreach(['admin','ajax','builder','builderCall','customizer']as$flag){$context=[$flag=>true];$style=$fixture([$css]);(new Inline_Style_Service)->reduce($handles);$assert($style->writes===0,'Native '.$flag.' stays untouched');}$context=[];
foreach(['Content-Security-Policy: style-src sha256-test','Content-Security-Policy-Report-Only: style-src sha256-test']as$header){$headers=[$header];$style=$fixture([$css]);(new Inline_Style_Service)->reduce($handles);$assert($style->writes===0,'Hash policy retains original bytes');}$headers=[];
\Bricks\Database::$mode='file';$style=$fixture([$css]);(new Inline_Style_Service)->reduce($handles);$assert($style->writes===0,'External CSS mode untouched');\Bricks\Database::$mode='inline';
$style=$fixture([$css]);$style->registered['bricks-frontend-inline']->src='custom.css';(new Inline_Style_Service)->reduce($handles);$assert($style->writes===0,'Reowned native handle untouched');
$veto=true;$style=$fixture([$css]);(new Inline_Style_Service)->reduce($handles);$assert($style->writes===0,'Extension veto');$veto=false;
foreach([[42],['name'=>$css],array_fill(0,33,$css),[str_repeat('a',4194305)],[$css,str_repeat('a',4194304)]]as$bad){$style=$fixture($bad);(new Inline_Style_Service)->reduce($handles);$assert($style->writes===0,'Ambiguous or oversized metadata untouched');}
$style=$fixture([$css,'/*separate native block*/']);(new Inline_Style_Service)->reduce($handles);$assert($style->get_data('bricks-frontend-inline','after')===[$expected,'/*separate native block*/'],'Other inline entries retain order and bytes');
$assert(!class_exists('WooCommerce'),'No Woo dependency');
echo json_encode(['passed'=>true,'version'=>BRICKS_VERSION,'checks'=>$checks,'fixtureCacheWrites'=>$cacheWrites,'websiteWrites'=>0],JSON_THROW_ON_ERROR),PHP_EOL;
}
