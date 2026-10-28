<?php
declare(strict_types=1);
namespace DSA\Apps { final class Store_App_Service { public static bool $on = true; public static function enabled(): bool { return self::$on; } } }
namespace DSA\Diagnostics { final class Runtime_Profiler { public static function start(){return 0;} public static function finish(...$args):void{} } }
namespace {
define('ABSPATH',__DIR__.'/'); define('DSA_DIR',dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/');
require DSA_DIR.'includes/Autoloader.php'; DSA\Autoloader::register();
require __DIR__.'/fixtures/saved-metadata.php';
$meta=[]; $writes=0; $actor=4; $checks=[]; $saved_events=[]; $saved_filters=[]; $saved_actions=[]; $analytics=0;
function check(bool $ok,string $label):void { $GLOBALS['checks'][$label]=$ok; if(!$ok)throw new RuntimeException($label); }
function get_current_user_id(){return $GLOBALS['actor'];} function get_user_meta($id,$key,$single){return $GLOBALS['meta'][$id][$key]??[];}
function get_post($id){return null;} function get_post_type($id){return false;} function absint($n){return abs((int)$n);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function sanitize_text_field($v){return trim(strip_tags((string)$v));}function esc_url_raw($v){return (string)$v;}
function __($v,...$args){return $v;}function delete_transient($key){return true;}function is_multisite(){return false;}
function item(string $title):array{return ['title'=>$title,'url'=>'https://example.invalid/'.strtolower($title),'savedAt'=>5];}
try {
 $service=new DSA\Saved\Saved_Items_Service(static function(){++$GLOBALS['analytics'];return null;});
 $a=item('A');$b=item('B');$c=item('C');
 $first=$service->mutate('add',$a);check($first['ok']&&count($first['items'])===1,'absent native row is created and read back before success');
 $second=null;$wpdb->before_write=static function()use($service,$b,&$second){$second=$service->mutate('add',$b);};
 $outer=$service->mutate('add',$c);check($outer['ok']&&!$second['ok']&&$second['status']===409,'same-user overlapping request gets explicit nonblocking conflict');
 $second=$service->mutate('add',$b);check($second['ok']&&array_column($second['items'],'title')===['B','C','A'],'second-device retry retains both acknowledged changes and order');
 $wpdb->before_write=static function()use($a){array_unshift($GLOBALS['meta'][4]['dsa_saved_items'],['key'=>'bookmark:outside','type'=>'bookmark','id'=>0,'title'=>'Outside','url'=>'https://example.invalid/outside','savedAt'=>4]);};
 $reply=$service->mutate('remove',$a);check($reply['ok']&&array_column($reply['items'],'title')===['Outside','B','C'],'conditional conflict rereads outside metadata changes before reapplying remove');
 $reply=$service->mutate('add',$b);check($reply['ok']&&count(array_filter($reply['items'],fn($i)=>$i['title']==='B'))===1,'double add keeps one identity and preserves other saved items');
 $before=serialize($meta);$n=$writes;$wpdb->fail_write=true;$reply=$service->mutate('add',item('Failure'));$wpdb->fail_write=false;
 check(!$reply['ok']&&$reply['status']===503&&serialize($meta)===$before&&$writes===$n,'failed native storage never returns a successful save');
 $wpdb->raw_overrides[4]='malformed';$reply=$service->mutate('add',$a);unset($wpdb->raw_overrides[4]);check(!$reply['ok']&&serialize($meta)===$before,'malformed stored state is preserved instead of replaced with an empty list');
 $saved_filters['update_user_metadata']=[static fn()=>false];$reply=$service->mutate('add',$a);check(!$reply['ok']&&serialize($meta)===$before,'native metadata denial remains authoritative');
 $saved_filters['update_user_metadata']=[static fn()=>true];$reply=$service->mutate('add',$a);check(!$reply['ok']&&serialize($meta)===$before,'truthy metadata interceptor cannot fabricate an uncommitted success');$saved_filters=[];
 $actor=0;$n=count($wpdb->statements);$events=count($saved_events);$reply=$service->mutate('add',$a);check(!$reply['ok']&&$reply['status']===401&&count($wpdb->statements)===$n&&count($saved_events)===$events,'guest denial performs no SQL hooks or mutation');$actor=4;
 $claim=DSA\Saved\Saved_DB_Session::claim('user:4');$owner=$claim['owner'];$state=DSA\Saved\Saved_Items_State::read(4);$wpdb->connection=18;$r=DSA\Saved\Saved_Items_State::write(4,$state,[$a],$owner);check($r['status']===503&&serialize($meta)===$before,'reconnected database session cannot use stale save ownership');$owner->release();$wpdb->connection=17;$wpdb->locks=[];
 $wpdb->options_data[DSA\Saved\Saved_Report_Journal::READY]='1';$reply=$service->mutate('add',$a);check($reply['ok']&&isset($wpdb->options_data[DSA\Saved\Saved_Report_Journal::user_key(4)],$wpdb->options_data[DSA\Saved\Saved_Report_Journal::EPOCH]),'prepared report receives a constant-size coalesced invalidation before saved metadata changes');
 check(!array_filter($wpdb->statements,fn($s)=>preg_match('/\b(?:CREATE|ALTER)\s|\bdsa_saved_report_v2\b/',$s)),'visitor mutations create no schema and run no report aggregation');
 check(in_array('added_user_meta',$saved_events,true)&&in_array('updated_user_meta',$saved_events,true)&&in_array('updated_usermeta',$saved_events,true),'native successful-change lifecycle hooks are retained');
 $heap=[];$rank=new ReflectionMethod(DSA\Saved\Saved_Report_Index::class,'rank_item');$compare=new ReflectionMethod(DSA\Saved\Saved_Report_Index::class,'compare');$reference=[];
 for($i=0;$i<3000;++$i){$row=['key'=>'bookmark:'.$i,'title'=>sprintf('%04d',$i%799),'user_count'=>($i*37)%101];$reference[]=$row;$rank->invokeArgs(null,[&$heap,$row]);}
 $cmp=static fn($a,$b)=>$compare->invoke(null,$a,$b);usort($reference,$cmp);usort($heap,$cmp);check($heap===array_slice($reference,0,250),'bounded ranking heap exactly matches full reference across count/title/key ties');
 echo json_encode(['passed'=>true,'checks'=>count($checks),'cases'=>$checks,'scope'=>'Actual Core saved service, session fencing and ranking with synthetic native I/O; real SQL remains a separate gate']).PHP_EOL;
}catch(Throwable $e){echo json_encode(['passed'=>false,'checks'=>$checks,'error'=>$e->getMessage()]).PHP_EOL;exit(1);}
}
