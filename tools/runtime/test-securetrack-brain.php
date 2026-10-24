<?php
/** Native learning functions against fixed decision fixtures; SQL atomicity is checked on MariaDB separately. */
if(PHP_SAPI!=='cli')exit(2);
define('ABSPATH',__DIR__.'/');
$dir=$argv[1]??dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Secure';
$source=file_get_contents($dir.'/securetrack-site-brain.php');
$start=strpos($source,'function stp_brain_feature_key(');$end=strpos($source,'function stp_ai_hash_value(',$start);
$checks=[];$enabled=true;$available=true;$rows=[];$args=['url'=>'https://example.test/news/story','sub'=>'synthetic','_brain_hour'=>'Tue-00','_brain_ua_family'=>'chrome'];$ip=(object)['status'=>'unknown','country_code'=>'US'];
function stp_cfg(){global$enabled;return['v2_site_brain'=>$enabled];}
function stp_table_exists($table){global$available;return$available;}
function stp_t($table){return'fixture_stp_'.$table;}
function stp_safe_table_name($table){return preg_match('/^fixture_stp_[a-z_]+$/',$table)?$table:'';}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function sanitize_text_field($value){return strip_tags($value);}
function sanitize_title($value){return strtolower($value);}
function wp_unslash($value){return$value;}
function wp_parse_url($value,$part){return parse_url($value,$part);}
function wp_json_encode($value){return json_encode($value);}
function current_time($format){return'Tue-00';}
function stp_is_containment_event($type,$sub){return$type==='protection_block';}
function check($name,$ok){global$checks;$checks[]=['name'=>$name,'passed'=>(bool)$ok];}
class BrainDb{
 public array $queries=[],$values=[];
 public array $prepared=[];
 function prepare($sql,...$args){$this->prepared=$args;if(str_starts_with($sql,'(')||str_starts_with($sql,'INSERT'))$this->values[]=$args;return$sql;}
 function get_row($sql){global$rows;$this->queries[]=$sql;return$rows[$this->prepared[0]]??null;}
 function get_results($sql){global$rows;$this->queries[]=$sql;return array_values(array_intersect_key($rows,array_fill_keys($this->prepared,true)));}
 function query($sql){$this->queries[]=$sql;return 1;}
}
$wpdb=new BrainDb();eval(substr($source,$start,$end-$start));
$features=stp_brain_features_for_event('page_view',$args,$ip);
$seed=static function($good,$risk,$confidence)use(&$rows,$features){$rows=[];foreach($features as$feature){$key=stp_brain_feature_key($feature['type'],$feature['value']);$rows[$key]=(object)['feature_key'=>$key,'good_count'=>$good,'risk_count'=>$risk,'confidence'=>$confidence];}};
$enabled=false;check('Disabled learning has no query or persistence work',stp_brain_context('page_view',$args,$ip)===[]&&!$wpdb->queries);stp_brain_observe(1,'page_view',$args,['score'=>0],$ip);check('Disabled observations create no learning writes',!$wpdb->queries);
$enabled=true;$available=false;check('Missing native learning storage safely returns no prediction',stp_brain_context('page_view',$args,$ip)===[]&&!$wpdb->queries);$available=true;
$context=stp_brain_context('page_view',$args,$ip);check('Six unseen features retain the original anomaly and four reasons',($context['v2_brain_score']??0)===24&&count($context['v2_brain_reasons']??[])===4);
check('Feature prediction uses one bounded native lookup',count($wpdb->queries)===1);
$seed(40,0,40);$context=stp_brain_context('page_view',$args,$ip);check('Established good features retain the capped discount',$context===['v2_brain_discount'=>12]);
$seed(9,11,11);check('Risk pattern stays inactive below confidence threshold',stp_brain_context('page_view',$args,$ip)===[]);
$seed(9,11,12);$context=stp_brain_context('page_view',$args,$ip);check('Risk pattern activates at the unchanged confidence and risk-ratio boundaries',($context['v2_brain_score']??0)===45&&count($context['v2_brain_reasons']??[])===4);
$seed(10,10,100);check('High confidence alone does not classify balanced evidence as risky',stp_brain_context('page_view',$args,$ip)===[]);
$seed(19,1,20);check('Five-percent risk retains the good-pattern discount',stp_brain_context('page_view',$args,$ip)===['v2_brain_discount'=>12]);
foreach([['unknown','page_view','synthetic',0,1,0],['unknown','protection_block','synthetic',5,0,1],['trusted','protection_block','synthetic',5,1,0],['trusted','protection_block','honeypot_hit',5,0,1],['trusted','page_view','synthetic',80,0,1]]as[$status,$type,$sub,$score,$good,$risk]){
 $wpdb->queries=[];$wpdb->values=[];$ip->status=$status;$args['sub']=$sub;
 stp_brain_observe(1,$type,$args,['score'=>$score,'flag'=>$score>=60?'red':'green'],$ip);
 check("$status/$type/$sub preserves good/risk occurrence classification",count($wpdb->values)===6&&!array_filter($wpdb->values,static fn($values)=>$values[2]!==$good||$values[3]!==$risk));
 check("$status/$type/$sub uses one write for all occurrences",count($wpdb->queries)===1);
}
$enabled=false;$wpdb->queries=[];$wpdb->values=[];stp_brain_observe(2,'page_view',$args,['score'=>0,'flag'=>'green'],$ip,true,'fixture_stp_brain_rebuild');
check('An explicit native rebuild still observes while live learning is disabled',count($wpdb->queries)===1&&str_contains($wpdb->queries[0],'fixture_stp_brain_rebuild'));
$passed=!in_array(false,array_column($checks,'passed'),true);echo json_encode(['passed'=>$passed,'checks'=>$checks])."\n";exit($passed?0:1);
