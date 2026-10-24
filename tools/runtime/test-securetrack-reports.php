<?php
/** Exercise report semantics, coverage, cheap disabled paths and cache isolation. Native SQL tests own worker parity. */
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');
$checks=[];$options=['stp_report_schema'=>'1'];$cache=[];$state=['generation'=>1,'timezone'=>'Asia/Kolkata','coverage_start'=>'2026-04-01 00:00:00','updated_at'=>'2026-10-07 10:00:00','status'=>'current','rejected_rows'=>0];$pending=false;
function get_option($n,$d=null){return $GLOBALS['options'][$n]??$d;}function wp_timezone(){return new DateTimeZone('Asia/Kolkata');}function wp_timezone_string(){return 'Asia/Kolkata';}function current_time($t){return '2026-10-07 10:00:00';}
function wp_cache_get($k,$g){return $GLOBALS['cache'][$g][$k]??false;}function wp_cache_set($k,$v,$g,$ttl){$GLOBALS['cache'][$g][$k]=$v;return true;}function absint($v){return abs((int)$v);}function get_gmt_from_date($v,$f){return (new DateTimeImmutable($v,wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format($f);}
class ReportDb {
 public string $prefix='qa_',$last_error='';public array$queries=[];public bool$missing=false,$error=false;
 function db_server_info(){return '11.8.9-MariaDB';}function esc_like($v){return addcslashes($v,'_%\\');}
 function prepare($sql,...$args){if(count($args)===1&&is_array($args[0]))$args=$args[0];foreach($args as$v)$sql=preg_replace_callback('/%[sd]/',fn($m)=>$m[0]==='%d'?(string)(int)$v:"'".str_replace("'","''",(string)$v)."'",$sql,1);return $sql;}
 function record($sql){$this->queries[]=$sql;$this->last_error=$this->error?'synthetic':'';}
 function get_var($sql){$this->record($sql);if(str_contains($sql,'SHOW TABLES'))return $this->missing?null:stripslashes(substr($sql,strpos($sql,"'")+1,-1));if(str_contains($sql,'report_pending'))return $GLOBALS['pending']?1:null;return '3';}
 function get_row($sql,$type){$this->record($sql);if(str_contains($sql,'report_state'))return $GLOBALS['state'];return ['views'=>7,'seconds'=>50,'timed_views'=>3,'events'=>5,'login_failures'=>2,'protections'=>1];}
 function get_results($sql,$type){$this->record($sql);return [['user_id'=>7,'last_activity'=>'2026-10-07 10:00:00']];}
}
$wpdb=new ReportDb();require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Db_Service.php';require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Report_Service.php';
use DSA\Secure\SecureTrack_Report_Service as Reports;
$assert=function($ok,$name)use(&$checks){$checks[]=['name'=>$name,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($name);};
$reset=function()use(&$cache){$cache=[];Reports::reset_readiness();(new ReflectionProperty(DSA\Secure\SecureTrack_Db_Service::class,'table_existence'))->setValue(null,[]);};
try{
 $assert(Reports::scope_hash('https://example.test/article?utm_source=a')===Reports::scope_hash('https://example.test/article'),'Query variants share the article scope');
 $assert(Reports::scope_hash('https://example.test/article/')!==Reports::scope_hash('https://example.test/article'),'Different canonical paths remain distinct');
 $assert(Reports::scope_hash('https://example.test/?p=12&utm_source=a')===Reports::scope_hash('https://example.test/?p=12'),'Plain permalink tracking variants retain the record');
 $assert(Reports::scope_hash('https://example.test/?p=12')!==Reports::scope_hash('https://example.test/?p=13'),'Plain permalink records never collapse into the homepage');
 $assert(Reports::scope_hash('https://example.test/?product=one')!==Reports::scope_hash('https://example.test/?product=two'),'Plain Woo product routes remain distinct');
 $options=[];$wpdb->queries=[];$assert(Reports::run()['status']==='unavailable'&&Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00')===null&&!Reports::activity([7])&&!$wpdb->queries,'Missing summary marker performs zero SQL or writes');
 $options=['stp_report_schema'=>'1'];$reset();$wpdb->missing=true;$assert(!Reports::ready(),'Missing summary table is unavailable');$wpdb->missing=false;$reset();
 foreach([['2026-10-01 01:00:00','2026-10-08 00:00:00'],['2026-02-31 00:00:00','2026-10-08 00:00:00'],['2026-10-08 00:00:00','2026-10-01 00:00:00']]as[$a,$b])$assert(Reports::period($a,$b)===null,'Invalid or non-calendar window refuses: '.$a);
 $r=Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00');$assert($r['views']===7&&$r['sessions']===3&&$r['engagedSeconds']===16.7,'Engagement is sum divided by positive timed visits, not average of daily averages');
 $assert($r['coverage']['complete']&&$r['coverage']['status']==='recorded','Known current processed history has recorded coverage');
 $wpdb->queries=[];Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00');$assert(count($wpdb->queries)===2&&!array_filter($wpdb->queries,fn($q)=>str_contains($q,'SUM(')),'Cached report checks generation and dirty index without repeating aggregates');
 $state['generation']++;$wpdb->queries=[];Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00');$assert(count($wpdb->queries)===4,'Committed generation invalidates report aggregates');
 $r=Reports::period('2026-03-01 00:00:00','2026-04-01 00:00:00');$assert(!$r['coverage']['complete']&&$r['coverage']['status']==='partial_history','No fabricated coverage for expired or pre-install history');
 $pending=true;$r=Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00');$assert(!$r['coverage']['complete']&&$r['coverage']['status']==='catching_up','New source rows invalidate apparent completeness before the next job');$pending=false;
 $state['generation']++;$state['updated_at']='2026-10-07 09:00:00';$r=Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00');$assert(!$r['coverage']['complete']&&$r['coverage']['status']==='stale','Stopped background work is visibly stale');$state['updated_at']='2026-10-07 10:00:00';
 foreach(['capacity','error','budget','engine']as$status){$state['status']=$status;$state['generation']++;$r=Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00');$assert(!$r['coverage']['complete']&&$r['coverage']['status']===$status,'Coverage preserves background failure: '.$status);}
 $state['status']='current';$state['rejected_rows']=1;$state['generation']++;$assert(!Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00')['coverage']['complete'],'Rejected source clocks cannot claim full coverage');$state['rejected_rows']=0;
 $state['timezone']='UTC';$assert(Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00')===null,'Timezone changes cannot reinterpret stored days');$state['timezone']='Asia/Kolkata';
 $assert(Reports::activity([7,7,0])===[7=>'2026-10-07T04:30:00Z'],'Durable activity is unique and converted from site time to UTC');
 $wpdb->queries=[];$assert(!Reports::activity(range(1,101))&&!$wpdb->queries,'Activity cards accept at most 100 requested accounts');
 $wpdb->error=true;$assert(Reports::period('2026-10-01 00:00:00','2026-10-08 00:00:00')===null&&!Reports::activity([7]),'Read failures return unavailable, never false zero');
 $assert(!array_filter($wpdb->queries,fn($q)=>preg_match('/\b(?:INSERT|UPDATE|DELETE|CREATE|ALTER)\b/',$q)),'Report reads perform no persistence, maintenance or schema work');
}catch(Throwable$e){$checks[]=['name'=>'Exception: '.$e->getMessage(),'passed'=>false];}
echo json_encode(['passed'=>!in_array(false,array_column($checks,'passed'),true),'checks'=>$checks],JSON_UNESCAPED_SLASHES);exit(in_array(false,array_column($checks,'passed'),true)?1:0);
