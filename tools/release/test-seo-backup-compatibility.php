<?php
declare(strict_types=1);
namespace DSA\Access { final class Backup_Workspace_Service { public static function allowed(): bool { return $GLOBALS['manager']; } } }
namespace {
class WP_Error {}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function wp_parse_url($v){return parse_url($v);}
function esc_url_raw($v){return filter_var($v,FILTER_VALIDATE_URL)?$v:'';}
function current_user_can($v){return $GLOBALS['manager'];}
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['options'])?$GLOBALS['options'][$k]:$d;}
function update_option($k,$v,$a=null){if($GLOBALS['failWrite'])return false;$GLOBALS['options'][$k]=$v;return true;}
function delete_option($k){if($GLOBALS['failWrite'])return false;unset($GLOBALS['options'][$k]);return true;}
function do_action($k){$GLOBALS['events'][]=$k;}
if(!function_exists('mb_strlen')){function mb_strlen($v){return preg_match_all('/./us',$v);}}
function home_url($p='/'){return 'https://example.test'.$p;}
function get_current_blog_id(){return 1;}
function get_current_user_id(){return 7;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function absint($v){return abs((int)$v);}
function get_stylesheet(){return 'synthetic-theme';}
function get_theme_mod($k,$d=false){return $d;}
function get_post($id){return null;}
function wp_attachment_is_image($id){return false;}
function untrailingslashit($s){return rtrim($s,'/');}
function wp_normalize_path($s){return str_replace('\\','/',$s);}
function trailingslashit($s){return rtrim($s,'/').'/';}
function wp_mkdir_p($s){return is_dir($s)||mkdir($s,0700,true);}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$v,$t){$GLOBALS['transients'][$k]=$v;return true;}
function delete_transient($k){unset($GLOBALS['transients'][$k]);return true;}
function add_option($k,$v,$d='',$a=null){if(array_key_exists($k,$GLOBALS['options']))return false;return update_option($k,$v,$a);}
$root=sys_get_temp_dir().'/kiwe-seo-backup-'.bin2hex(random_bytes(6));mkdir($root.'/public_html',0700,true);define('ABSPATH',$root.'/public_html/');define('DAY_IN_SECONDS',86400);
require __DIR__.'/fixtures/seo-defaults-native-option-db.php';
$manager=true;$options=['kiwe_seo_workspace_enabled_v1'=>'0','kiwe_ai_opt_in_global_v1'=>'0','rank-math-options-titles'=>['homepage_title'=>'Provider title'],'active_plugins'=>['seo-by-rank-math/rank-math.php']];$transients=[];$events=[];$failWrite=false;$checks=[];
$base=dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/';
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require $base.'Apps/SEO_App_Service.php';
foreach(['Backup/Archive_Error.php','Backup/Archive_Cipher.php','SEO/SEO_Defaults_Service.php','Schema/Schema_Template_Service.php','Backup/SEO_State_Snapshot_Service.php','Backup/Configuration_Snapshot_Service.php','Backup/Content_Archive_Service.php']as$file)require$base.$file;
require $base.'Analytics/Restored_Reporting_State.php';
use DSA\Backup\SEO_State_Snapshot_Service as SEO;
use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
use DSA\Backup\Content_Archive_Service as Content;
use DSA\SEO\SEO_Defaults_Service as Defaults;
function check($v,$n){$GLOBALS['checks'][$n]=$v;if(!$v)throw new RuntimeException($n);}
function refuse($f,$n){$ok=false;try{$f();}catch(RuntimeException $e){$ok=true;}check($ok,$n);}
$passed=false;$error='';
try{
 check(SEO::read()===['schema'=>1,'defaults'=>null],'Absent native defaults retain exact existence in the adapter');
 $protected=$options;$settings=Defaults::validate(['verification'=>['google'=>'SyntheticGoogleCode_123456789','bing'=>str_repeat('a',32),'pinterest'=>str_repeat('b',32)],'postTypes'=>['post'=>['title'=>'%title% %sep% %sitename%']],'news'=>['enabled'=>true,'publication'=>'Synthetic paper','language'=>'en']]);
 SEO::write(['exists'=>true,'value'=>['schema'=>1,'defaults'=>$settings]]);
 check(SEO::read()['defaults']===$settings,'Validated native defaults write and readback exactly');
 check(array_intersect_key($options,$protected)===$protected,'SEO AI plugin choices and source provider fields stay exact');
 $before=$options;$manager=false;
 refuse(fn()=>SEO::read(),'Author cannot read global SEO snapshot choices');refuse(fn()=>SEO::write(['exists'=>true,'value'=>['schema'=>1,'defaults'=>$settings]]),'Author cannot restore global SEO defaults');
 check(!isset(Snapshots::option_groups()[SEO::GROUP])&&$options===$before,'Unavailable snapshot group produces no writes');$manager=true;
 foreach([
  ['exists'=>false],['exists'=>true,'value'=>['schema'=>2,'defaults'=>null]],['exists'=>true,'value'=>['schema'=>1]],
  ['exists'=>true,'value'=>['schema'=>1,'defaults'=>['unknown'=>true]]],['exists'=>true,'value'=>['schema'=>1,'defaults'=>['home'=>['title'=>'%unsupported%']]]],
  ['exists'=>true,'value'=>['schema'=>1,'defaults'=>$settings,'provider'=>'secret']],['exists'=>true,'value'=>['schema'=>1,'defaults'=>$settings],'credential'=>'secret'],
 ]as$i=>$bad)refuse(fn()=>SEO::write($bad),'Unknown invalid or credential-bearing snapshot refuses '.$i);
 $failWrite=true;refuse(fn()=>SEO::write(['exists'=>true,'value'=>['schema'=>1,'defaults'=>null]]),'Failed defaults deletion refuses confirmation');$failWrite=false;
 check(SEO::read()['defaults']===$settings,'Failed write preserves current defaults');
 $service=new Snapshots();$password='synthetic-private-backup-passphrase';$copy=$service->create($password,'SEO defaults',[SEO::GROUP]);$bytes=file_get_contents($service->download_path($copy['id']));
 check(!str_contains($bytes,'Synthetic paper')&&!str_contains($bytes,'%sitename%'),'Shared snapshot encryption hides native SEO state');
 delete_option(Defaults::OPTION);$review=$service->preview_upload($bytes,$password);update_option(Defaults::OPTION,Defaults::defaults());
 refuse(fn()=>$service->apply($review['token'],$password),'Newer defaults invalidate an old restore review');delete_option(Defaults::OPTION);
 $review=$service->preview_upload($bytes,$password);$result=$service->apply($review['token'],$password);
 check(SEO::read()['defaults']===$settings&&$result['restored']===1,'Encrypted reviewed restore dispatches the shared SEO adapter');
 $undo=$service->preview_upload(file_get_contents($service->download_path($result['rollbackId'])),$password);$service->apply($undo['token'],$password);
 check(!array_key_exists(Defaults::OPTION,$options),'Before-restore encrypted copy restores exact original option absence');
 check(array_intersect_key($options,$protected)===$protected,'Roundtrip never enrolls a provider enables AI or changes SEO choice');
 $newer=Defaults::validate(['home'=>['title'=>'Client edit after restore']]);$review=$service->preview_upload($bytes,$password);
 refuse(fn()=>$service->apply($review['token'],$password,static function($phase)use($newer){if($phase==='complete'){update_option(Defaults::OPTION,$newer,false);throw new RuntimeException('Synthetic post-write completion failure');}}),'Failure after a newer edit refuses automatic rollback confirmation');
 check(SEO::read()['defaults']===$newer,'Failure cleanup preserves the observed newer client defaults and retains the before-restore copy');
 update_option(Defaults::OPTION,$settings,false);$expected=['exists'=>true,'value'=>['schema'=>1,'defaults'=>$settings]];
 $seo_defaults_query_hook=static function($sql)use($newer){if(str_starts_with($sql,'UPDATE')){unset($GLOBALS['seo_defaults_query_hook']);update_option(Defaults::OPTION,$newer,false);}};
 refuse(fn()=>SEO::write(['exists'=>true,'value'=>['schema'=>1,'defaults'=>Defaults::defaults()]],$expected),'Interleaved restore edit defeats the exact native option comparison');
 check(SEO::read()['defaults']===$newer,'Concurrent restore does not overwrite the newer defaults after its earlier read');
 delete_option(Defaults::OPTION);$seo_defaults_query_hook=static function($sql){if(str_starts_with($sql,'INSERT'))$GLOBALS['seo_defaults_lease_lost']=true;};
 refuse(fn()=>SEO::write(['exists'=>true,'value'=>['schema'=>1,'defaults'=>$settings]],['exists'=>true,'value'=>['schema'=>1,'defaults'=>null]]),'Lost writer lease refuses creating a previously absent defaults option');
 check(!array_key_exists(Defaults::OPTION,$options),'Missing-row conditional insert performs no write after session ownership is lost');unset($seo_defaults_query_hook);$seo_defaults_lease_lost=false;
 define('RANK_MATH_VERSION','synthetic-active-provider');SEO::write(['exists'=>true,'value'=>['schema'=>1,'defaults'=>$settings]]);
 check(Defaults::external_owner()==='rank_math'&&!Defaults::enabled()&&get_option('rank-math-options-titles')===$protected['rank-math-options-titles'],'Staged native defaults do not displace an active source provider');
 $graph=[['@type'=>'NewsArticle','headline'=>'%title%','url'=>'%permalink%']];
 Content::assert_seo_metadata(['_kiwe_schema_graph_v1'=>$graph,'_kiwe_seo_facebook_title'=>'Article sharing title','_kiwe_seo_facebook_image'=>'https://example.test/picture.jpg']);
 check(true,'Content archive accepts supported declarative graph and sharing fields');
 foreach([['_kiwe_schema_graph_v1'=>[['@type'=>'Executable','code'=>'arbitrary']]],['_kiwe_seo_facebook_image'=>'javascript:alert(1)'],['_kiwe_seo_twitter_title'=>[]],['_kiwe_seo_twitter_image'=>'https://person:secret@example.test/picture.jpg']]as$i=>$bad)refuse(fn()=>Content::assert_seo_metadata($bad),'Unsupported SEO content payload refuses '.$i);
 $keys=(new ReflectionClass(Content::class))->getConstant('POST_META');$termKeys=(new ReflectionClass(Content::class))->getConstant('TERM_META');
 check(in_array('_kiwe_schema_graph_v1',$keys,true)&&in_array('_kiwe_seo_twitter_image',$keys,true)&&in_array('_kiwe_seo_canonical_url',$termKeys,true)&&in_array('_kiwe_seo_robots',$termKeys,true),'Post and taxonomy archives include the native SEO fields they can restore');
 check(!in_array('_kiwe_schema_import_v1',$keys,true)&&!in_array('_kiwe_seo_migration_backup_v1',$keys,true),'Copied content cannot inherit source import undo journals');
 $guard=DSA\Analytics\Restored_Reporting_State::class;
 check(!$guard::blocks('search')&&!$guard::blocks('secure'),'Ordinary reporting has no copied-history restriction');
 $options[$guard::OPTION]=['schema'=>1,'recoverySha256'=>str_repeat('a',64),'tables'=>['kiwe_search_rows_v1'],'identityMapped'=>false];
 check($guard::blocks('search')&&!$guard::blocks('secure'),'Copied search facts block only their own report family');
 $options[$guard::OPTION]['tables'][]='stp_report_days';check($guard::blocks('search')&&$guard::blocks('secure'),'Copied security summaries cannot acquire destination reporting authority');
 $options[$guard::OPTION]['tables']=[[]];check($guard::blocks('search')&&$guard::blocks('secure'),'Malformed restored-history marker refuses activation without type warnings');unset($options[$guard::OPTION]);
 $passed=true;
}catch(Throwable$e){$error=$e->getMessage();}
finally{
 $real=realpath($root);if($real!==false&&str_starts_with(str_replace('\\','/',$real),str_replace('\\','/',sys_get_temp_dir()).'/kiwe-seo-backup-')){
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($root);
 }
}
echo json_encode(['passed'=>$passed,'cleanup'=>!file_exists($root),'checks'=>$checks,'error'=>$error],JSON_UNESCAPED_SLASHES).PHP_EOL;exit($passed&&!file_exists($root)?0:1);
}
