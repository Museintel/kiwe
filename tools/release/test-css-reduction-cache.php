<?php
declare(strict_types=1);
namespace DSA\Performance {
	function time(): int { return $GLOBALS['clock']; }
	final class CSS_Rule_Reducer {
		public static function removals(string $css): ?array {
			$GLOBALS['computes']++;
			if($GLOBALS['during']??null){$f=$GLOBALS['during'];$GLOBALS['during']=null;$f();}
			if($GLOBALS['throws']??false)throw new \RuntimeException('probe');
			return $GLOBALS['plan'];
		}
	}
}
namespace {
define('ABSPATH',__DIR__.'/fixture/');
$options=[];$writes=0;$reads=0;$computes=0;$clock=100000;$plan=[0=>9000];$during=null;$fail=false;
class wpdb {
	public $options='wp_options';
	public function prepare($sql,...$args){return[$sql,$args];}
	public function query($query){global $options,$writes,$fail;[$sql,[$after,$name,$before]]=$query;if($fail||($options[$name]??false)!==$before)return 0;$options[$name]=$after;$writes++;return 1;}
}
$wpdb=new wpdb;
function get_option($key,$default=false){global $options,$reads;$reads++;return $options[$key]??$default;}
function add_option($key,$value,$unused='',$autoload=null){global $options,$writes,$fail;if($autoload!==false)throw new RuntimeException('Autoload');if($fail||array_key_exists($key,$options))return false;$options[$key]=$value;$writes++;return true;}
function wp_cache_delete($key,$group){if($group!=='options')throw new RuntimeException('Wrong cache owner');}
function wp_salt($scheme){return 'fixture-only-secret-'.$scheme;}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Performance/CSS_Reduction_Cache.php';
use DSA\Performance\CSS_Reduction_Cache as Cache;
$checks=0;$assert=static function($ok,$name)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($name);};
$css=str_repeat('x',9000).str_repeat('a',530000);$expected=substr($css,9000);
$assert(Cache::reduce($css)===$expected,'First owner computes fresh result');
$assert($computes===1&&$writes===2&&count($options)===1,'One learning owner, bounded native metadata');
$key=array_key_first($options);$original=$options[$key];$beforeWrites=$writes;
$assert(Cache::reduce($css)===$expected,'Exact-content warm result');
$assert($computes===1&&$writes===$beforeWrites,'Warm requests never recompute or write');
$assert(!str_contains($original,str_repeat('x',32))&&!str_contains($original,str_repeat('a',32)),'Metadata never stores CSS');
$assert(strlen($original)<=65536,'Metadata size bound');
$bucket=static fn($input)=>hexdec(substr(hash('sha256',$input),0,2))%32;
$other=$css;for($i=0;$i<10000;$i++){if($bucket($css.'/*'.$i.'*/')===$bucket($css)){$other=$css.'/*'.$i.'*/';break;}}
$assert($other!==$css,'Collision fixture exists');
$assert(Cache::reduce($other)===$other&&$computes===1,'Recent slot collision uses current native CSS');
$clock+=61;
$assert(Cache::reduce($other)===substr($other,9000)&&$computes===2,'Changed CSS never receives old plan');
$options=[];$computes=0;$during=static function()use($css,$assert){$assert(Cache::reduce($css)===$css,'Concurrent request immediately keeps native CSS');};
$assert(Cache::reduce($css)===$expected&&$computes===1,'Only one worker parses');
$options=[];$computes=0;$during=static function()use($other,$assert,&$clock){$clock+=31;$assert(Cache::reduce($other)===substr($other,9000),'Expired owner can be replaced');};
$assert(Cache::reduce($css)===$expected,'Old worker may return its own fresh result');
$after=$computes;$assert(Cache::reduce($other)===substr($other,9000)&&$computes===$after,'Old worker cannot overwrite replacement owner');
$options=[];$computes=0;$throws=true;
$assert(Cache::reduce($css)===$css,'Parser exception fails open');
$throws=false;$assert(Cache::reduce($css)===$css&&$computes===1,'Failure lease prevents immediate retry storm');
$clock+=31;$assert(Cache::reduce($css)===$expected&&$computes===2,'Failure lease expires');
$raw=$options[$key];$options[$key]=str_replace('"mac":"','"mac":"broken',$raw);$fail=true;
$assert(Cache::reduce($css)===$css,'Unauthenticated plan is never applied');
$fail=false;$assert(Cache::reduce($css)===$expected,'Invalid plan can be replaced safely');
$good=$options[$key];
foreach([[[-1,9000]],[[0,9000],[10,9000]],[[0,PHP_INT_MAX]],[['0',9000]],['bad']]as$spans){
	$outer=json_decode($good,true);$inner=json_decode($outer['data'],true);$inner['spans']=$spans;$outer['data']=json_encode($inner);$outer['mac']=hash_hmac('sha256',$outer['data'],wp_salt('auth'));$options[$key]=json_encode($outer);
	$assert(Cache::reduce($css)===$css,'Invalid authenticated plan uses original complete CSS');
}
$options[$key]=str_repeat('a',65537);$after=$computes;$beforeWrites=$writes;
$assert(Cache::reduce($css)===$css&&$computes===$after&&$writes===$beforeWrites,'Oversized entry is ignored without work');
$options[$key]=['unknown'=>'owner'];
$assert(Cache::reduce($css)===$css&&$writes===$beforeWrites,'Unknown option type is preserved');
$options=[];$plan=null;$assert(Cache::reduce($css)===$css,'Ambiguous parse retains CSS');$after=$computes;
$assert(Cache::reduce($css)===$css&&$computes===$after,'Negative result is cached');
$options=[];$plan=array_fill(0,4097,1);$assert(Cache::reduce($css)===$css,'Oversized plan is declined');
$after=$computes;$assert(Cache::reduce('small')==='small'&&$computes===$after,'Small CSS has no cache or parse cost');
$big=str_repeat('a',4194305);$assert(Cache::reduce($big)===$big&&$computes===$after,'Input bound');
$wpdb=null;$assert(Cache::reduce($css)===$css,'Unavailable native storage keeps original CSS');
echo json_encode(['passed'=>true,'checks'=>$checks,'fixtureWrites'=>$writes,'websiteWrites'=>0],JSON_THROW_ON_ERROR),PHP_EOL;
}
