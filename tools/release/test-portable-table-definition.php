<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('ABSPATH',__DIR__.'/');
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_Table_Definition','Portable_Recovery_Database']as$class)require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$class.'.php';
use DSA\Backup\Portable_Table_Definition as Definition;
use DSA\Backup\Portable_Recovery_Database as Database;
$proof=['schema'=>'kiwe.native-table-definition-proof.v1','runtime'=>PHP_VERSION,'passed'=>false,'cleanup'=>true,'nativeDatabaseExecuted'=>false,'checks'=>[]];
$check=static function(bool$ok,string$name)use(&$proof){$proof['checks'][$name]=$ok;if(!$ok)throw new RuntimeException($name);};
$reject=static function(callable$fn,string$name)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$name);return;}throw new RuntimeException('Expected refusal: '.$name);};
try{
 $base="CREATE TABLE `wp_posts` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`payload` longblob DEFAULT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
 $sql=Definition::compile($base,'wp_posts','krd_synthetic_posts');$ast=Definition::parse($sql,'krd_synthetic_posts');
 $check($ast['columns'][0]['autoIncrement']&&$ast['autoIncrement']==='9'&&str_starts_with($sql,'CREATE TABLE `krd_synthetic_posts` ('),'renderer constructs fresh native table name and preserves sequence');
 $check(Definition::fingerprint($base,'wp_posts')===Definition::fingerprint($sql,'krd_synthetic_posts'),'schema comparison is independent of native table name');
 $allocate=new ReflectionMethod(Database::class,'recovery_prefix');$validate=new ReflectionMethod(Database::class,'valid_prefix');$id='0123456789abcdef0123456789abcdef';
 $short=$allocate->invoke(null,$id,['prefix'=>'wp_','tables'=>[['name'=>'wp_posts']]]);$check($short==='krd_'.substr($id,0,24).'_'&&$validate->invoke(null,$short,$id),'short-name recovery retains legacy receipt namespace compatibility');
 foreach(['orderable_location_delivery_zones_lookup','woocommerce_downloadable_product_permissions',str_repeat('s',47)]as$suffix){
  $sourceName='wp_'.$suffix;$ddl=str_replace('`wp_posts`','`'.$sourceName.'`',$base);$prefix=$allocate->invoke(null,$id,['prefix'=>'wp_','tables'=>[['name'=>$sourceName]]]);$target=$prefix.$suffix;$built=Definition::compile($ddl,$sourceName,$target);
  $check(strlen($target)<=64&&str_starts_with($built,'CREATE TABLE `'.$target.'`')&&$validate->invoke(null,$prefix,$id),'long native suffix fits the unchanged SQL identifier bound: '.$suffix);
 }
 $long='woocommerce_downloadable_product_permissions';$reject(static fn()=>Definition::compile(str_replace('`wp_posts`','`wp_'.$long.'`',$base),'wp_'.$long,$short.$long),'legacy fixed-length allocation reproduces the native Woo identifier failure');
 $reject(static fn()=>$allocate->invoke(null,$id,['prefix'=>'wp_','tables'=>[['name'=>'wp_'.str_repeat('s',48)]]]),'unsupported suffix refuses before database allocation');
 $reject(static fn()=>$allocate->invoke(null,$id,['prefix'=>'wp_','tables'=>[['name'=>'foreign_posts']]]),'foreign source namespace refuses prefix allocation');
 $check(!$validate->invoke(null,'krd_'.str_repeat('a',15).'_',$id)&&!$validate->invoke(null,'krd_'.substr($id,0,11).'_',$id)&&!$validate->invoke(null,'krd_'.substr($id,0,25).'_',$id),'receipt prefix must match its full owner id and bounded namespace length');
 $changed=str_replace(['bigint(20)','AUTO_INCREMENT=9'],['bigint','AUTO_INCREMENT=14'],$sql);
 $check(Definition::fingerprint($changed,'krd_synthetic_posts')===Definition::fingerprint($sql,'krd_synthetic_posts'),'server integer width and advancing sequence do not create false schema drift');
 foreach(['tinyint(1) unsigned','smallint(6)','mediumint','int(11) unsigned','bigint unsigned','decimal(65,30) unsigned','float','double(16,4)','varchar(190)','char(8)','varbinary(65535)','binary(32)','bit(64)','datetime(6)','timestamp','time(3)','date','year(4)','tinyblob','blob','mediumblob','longblob','tinytext','text','mediumtext','longtext',"enum('sms','email','whats\\'app')","set('a','b')"]as$type){$parsed=Definition::column_type($type);$check(is_string($parsed['name']),'typed native column '.$type);}
 $rich="CREATE TABLE `wp_records` (`id` bigint unsigned NOT NULL,`title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',`kind` enum('safe','x\\'y','a,b','-- text') NOT NULL DEFAULT 'safe',`stamp` timestamp(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),`body` longtext DEFAULT NULL COMMENT 'DROP TABLE is literal text',PRIMARY KEY (`id`),UNIQUE KEY `title` (`title`(32)) USING BTREE,FULLTEXT KEY `body` (`body`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC COMMENT='literal \\n punctuation; /* not SQL */'";
 $compiled=Definition::compile($rich,'wp_records','krd_records');
 $check(Definition::fingerprint($rich,'wp_records')===Definition::fingerprint($compiled,'krd_records'),'escaped literals indexes comments timestamps and collation survive typed round trip');
 $check(Definition::parse($compiled,'krd_records')['columns'][2]['type']['args']===['safe',"x'y",'a,b','-- text'],'literal punctuation stays data through enum rendering');
 $check(Definition::fingerprint(str_replace('AUTO_INCREMENT=9','AUTO_INCREMENT=9 ROW_FORMAT=COMPACT',$base),'wp_posts')!==Definition::fingerprint($base,'wp_posts'),'material table layout changes remain detectable');
 foreach([
  $base.'; DROP TABLE `users`',
  str_replace('ENGINE=InnoDB','ENGINE=MyISAM',$base),
  $base.' DATA DIRECTORY=\'/tmp\'',
  $base.' CONNECTION=\'provider\'',
  $base.' TABLESPACE `outside`',
  $base.' /*!50100 DATA DIRECTORY=\'/tmp\' */',
  str_replace('PRIMARY KEY (`id`)','CONSTRAINT `f` FOREIGN KEY (`id`) REFERENCES `wp_users` (`id`),PRIMARY KEY (`id`)',$base),
  str_replace('`payload` longblob DEFAULT NULL','`payload` longblob AS (LOAD_FILE(\'/tmp/secret\')) STORED',$base),
  str_replace('`payload` longblob DEFAULT NULL','`payload` longblob DEFAULT (uuid())',$base),
  str_replace('PRIMARY KEY (`id`)','PRIMARY KEY (`id`(2))',$base),
  str_replace('`wp_posts`','`other`.`wp_posts`',$base),
  str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$base),
  str_replace('`payload` longblob DEFAULT NULL','`payload` longblob DEFAULT NULL INVISIBLE',$base),
  str_replace('PRIMARY KEY (`id`)','KEY `id` (`id`)',$base),
  str_replace('PRIMARY KEY (`id`)','PRIMARY KEY (`missing`)',$base),
  $base.' ENGINE=InnoDB',
 ]as$i=>$bad)$reject(static fn()=>Definition::compile($bad,'wp_posts','krd_posts'),'unsupported definition refuses before SQL '.$i);
 $reject(static fn()=>Definition::compile($base,'wp_posts','bad; DROP'),'destination identifiers cannot select another SQL action');
 $reject(static fn()=>Definition::column_type('bigint); DROP TABLE users'),'column metadata is parsed independently rather than interpolated');
 $reject(static fn()=>Definition::column_type('varchar(999999)'),'declared column size has a hard bound');
 $reject(static fn()=>Definition::compile($base,'another','krd_posts'),'source identity must match the reviewed table');
 $remote=['host'=>'remote.example','port'=>3306,'database'=>'synthetic','username'=>'synthetic','password'=>'synthetic secret'];
 $reject(static fn()=>Database::connection_file('http://example.invalid/config.json'),'connection input cannot use a remote stream');
 $check(!str_contains($sql,'DROP TABLE'),'rendered supported statement contains only one CREATE action');
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($proof['passed']?0:1);
