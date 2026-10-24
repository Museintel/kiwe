<?php
declare(strict_types=1);
require __DIR__.'/workspace-test-bootstrap.php';
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_parse_url($v){return parse_url($v);}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Analytics/Search_Performance_Model.php';
use DSA\Analytics\Search_Performance_Model as M;

foreach(['2026-10-07','2024-02-29','2026-01-01'] as $d)check(M::date($d),'valid Pacific calendar date '.$d);
foreach(['2026-02-29','2026-13-01','2026-10-00','2026-1-01','2026-10-07 00:00:00',[],null] as $i=>$d)check(!M::date($d),'invalid provider date '.$i);
check(M::property('sc-domain:example.test','https://news.example.test/'),'domain property contains website');
check(M::property('https://example.test/news/','https://example.test/news/section/'),'prefix property contains website');
foreach(['sc-domain:foreign.test','sc-domain:bad..test','https://example.test/newsroom/','http://example.test/news/','https://example.test:99/news/','https://u:p@example.test/news/','https://example.test/news/#x'] as $i=>$p)check(!M::property($p,'https://example.test/news/'),'unrelated or unsafe property '.$i);
foreach(['file:///tmp/x','javascript:bad','https://u:p@example.test/','https://example.test/#secret',"https://example.test/\n"] as $i=>$u)check(!M::url($u),'unsafe detail URL '.$i);
$properties=M::properties(['siteEntry'=>[['siteUrl'=>'https://example.test/','permissionLevel'=>'siteOwner'],['siteUrl'=>'https://foreign.test/','permissionLevel'=>'siteOwner'],['siteUrl'=>'sc-domain:example.test','permissionLevel'=>'siteUnverifiedUser']]],'https://example.test/');
check(count($properties)===1&&$properties[0]['permission']==='siteOwner','property inventory keeps only native website permissions');
check(is_wp_error(M::properties(['siteEntry'=>[['siteUrl'=>'https://example.test/','permissionLevel'=>'unknown']]],'https://example.test/')),'unknown permission is not readiness');
foreach(M::TYPES as $type){$q=M::query('2026-10-01',$type,'site');check($q['dataState']==='final'&&$q['rowLimit']===1&&$q['aggregationType']===(in_array($type,['discover','googleNews'],true)?'byPage':'byProperty'),'site aggregation for '.$type);}
$q=M::query('2026-10-01','web','page',20);check($q['startRow']===20&&$q['dimensions']===['page']&&$q['aggregationType']==='byPage','bounded page pagination preserves aggregation');
foreach([['web','page',40],['discover','query',0],['googleNews','query',0],['unknown','site',0],['web','site',20]] as $i=>$v)check(is_wp_error(M::query('2026-10-01',...$v)),'unsupported query refused '.$i);
$site=M::query('2026-10-01','web','site');$empty=M::response(['responseAggregationType'=>'byProperty'],$site);check(count($empty)===1&&$empty[0]['clicks']===0&&$empty[0]['position']===null,'explicit empty property response records observed zero');
$metric=['clicks'=>5,'impressions'=>50,'ctr'=>0.1,'position'=>2.5];$row=M::response(['responseAggregationType'=>'byProperty','rows'=>[$metric]],$site);check(count($row)===1&&$row[0]['position']===2.5,'site response preserves fractional position');
$page=M::query('2026-10-01','web','page');$detail=M::response(['responseAggregationType'=>'byPage','rows'=>[['keys'=>['https://example.test/news/'],...$metric]]],$page);check(count($detail)===1&&$detail[0]['label']==='https://example.test/news/','page response has a separate dimension');
foreach([
 ['responseAggregationType'=>'byProperty','rows'=>[['keys'=>['wrong'],...$metric]]],
 ['responseAggregationType'=>'auto','rows'=>[$metric]],
 ['responseAggregationType'=>'byProperty','metadata'=>['first_incomplete_date'=>'2026-10-01']],
 ['responseAggregationType'=>'byProperty','rows'=>array_fill(0,2,$metric)],
 ['responseAggregationType'=>'byProperty','rows'=>[['extra'=>'bad',...$metric]]],
] as $i=>$v)check(is_wp_error(M::response($v,$site)),'response drift rejected '.$i);
check(is_wp_error(M::response(['responseAggregationType'=>'byPage','rows'=>[['keys'=>['https://example.test/'],...$metric],['keys'=>['https://example.test/'],...$metric]]],$page)),'duplicate pagination dimensions are refused');
foreach([['clicks'=>-1,'impressions'=>10],['clicks'=>1.5,'impressions'=>10],['clicks'=>11,'impressions'=>10],['clicks'=>1,'impressions'=>INF],['clicks'=>1,'impressions'=>10,'position'=>NAN],['clicks'=>1,'impressions'=>10,'ctr'=>1.1]] as $i=>$v)check(is_wp_error(M::metrics($v)),'invalid measurements refused '.$i);
$v=M::weighted([['clicks'=>1,'impressions'=>10,'position'=>10],['clicks'=>20,'impressions'=>100,'position'=>1]]);
check(abs($v['ctr']-100*21/110)<0.000001&&abs($v['position']-200/110)<0.000001,'rates and positions are weighted by impressions');
check(M::weighted([['clicks'=>0,'impressions'=>0,'position'=>null]])['position']===null,'zero observations do not invent rank');
$range=M::range(90);check((new DateTimeImmutable($range['prior']))->diff(new DateTimeImmutable($range['to']))->days===179,'90-day comparisons use 180 provider calendar dates');
echo 'Search performance model: '.$GLOBALS['count'].' checks passed. Native SQL, OAuth and provider acceptance are separate.'.PHP_EOL;
