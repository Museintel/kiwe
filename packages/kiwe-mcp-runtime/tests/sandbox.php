<?php
declare(strict_types=1);
require dirname(__DIR__,3).'/tools/mcp/native-table-sandbox.php';
$map=['wp_options'=>'kiwe_m04_fixture_options','wp_posts'=>'kiwe_m04_fixture_posts'];$checks=[];
$check=static function($value,$label)use(&$checks){$checks[$label]=(bool)$value;if(!$value)throw new RuntimeException($label);};
$rewrite=static fn($sql)=>Kiwe_MCP_Native_Table_Sandbox::rewrite_sql($sql,$map,'0123456789abcdef');
$check($rewrite('SELECT * FROM wp_options')==='SELECT * FROM kiwe_m04_fixture_options','Bare native table identifier maps');
$check($rewrite('SELECT * FROM `wp_options`')==='SELECT * FROM `kiwe_m04_fixture_options`','Quoted native table identifier maps');
$check($rewrite("UPDATE wp_options SET option_value='wp_options'")==="UPDATE kiwe_m04_fixture_options SET option_value='wp_options'",'A stored value is never rewritten as a table');
$escaped=<<<'SQL'
UPDATE wp_options SET option_value='escaped\'wp_options'
SQL;
$check($rewrite($escaped)===str_replace('UPDATE wp_options','UPDATE kiwe_m04_fixture_options',$escaped),'Escaped quotes preserve their literal data');
$check($rewrite("UPDATE wp_options SET option_value='one''wp_options'")==="UPDATE kiwe_m04_fixture_options SET option_value='one''wp_options'",'Doubled SQL quotes preserve their literal data');
$check($rewrite('SELECT wp_options_value FROM wp_options')==='SELECT wp_options_value FROM kiwe_m04_fixture_options','Identifier substrings remain unchanged');
$check($rewrite('SELECT * FROM wp_posts p JOIN wp_options o ON 1=1')==='SELECT * FROM kiwe_m04_fixture_posts p JOIN kiwe_m04_fixture_options o ON 1=1','Every joined native table maps');
$check($rewrite('SELECT /* wp_options */ * FROM wp_posts')==='SELECT /* wp_options */ * FROM kiwe_m04_fixture_posts','SQL comments remain inert');
$check($rewrite("SELECT * FROM wp_options -- wp_posts\n")==="SELECT * FROM kiwe_m04_fixture_options -- wp_posts\n",'Line comments remain inert');
$first=$rewrite("SELECT GET_LOCK('kiwe-context-fixture',0)");$check($first!=="SELECT GET_LOCK('kiwe-context-fixture',0)",'Fixture mutexes do not contend with the actual site');
$second=Kiwe_MCP_Native_Table_Sandbox::rewrite_sql("SELECT GET_LOCK('kiwe-context-fixture',0)",$map,'fedcba9876543210');$check($first!==$second,'Separate fixture runs do not share mutexes');
$check($rewrite("SELECT GET_LOCK('other-system',0)")==="SELECT GET_LOCK('other-system',0)",'Other native mutexes remain untouched');
echo json_encode(['passed'=>true,'checks'=>count($checks),'nativeSql'=>false])."\n";
