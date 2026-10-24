<?php
declare(strict_types=1);
if ( PHP_SAPI!=='cli' ) exit(2);
define('ABSPATH',__DIR__.'/');
foreach(['Archive_Error','Archive_Cipher','Portable_Archive_Cipher','Portable_File_Inventory','Portable_Recovery_Configuration','Portable_Recovery_Activation']as$class)require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/'.$class.'.php';
use DSA\Backup\Portable_Recovery_Configuration as Config;
use DSA\Backup\Portable_Recovery_Activation as Activation;
$proof=['schema'=>'kiwe.recovery-activation-local-proof.v1','passed'=>false,'cleanup'=>true,'checks'=>[]];
$check=static function(bool$ok,string$label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable$fn,string$label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error$e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
$connection=['host'=>'localhost','port'=>3306,'database'=>'synthetic_database','username'=>'synthetic_user','password'=>'synthetic\\private\'password'];
$old='fixture_';$new='krd_'.str_repeat('a',24).'_';$urls=['home'=>'https://destination.invalid','siteurl'=>'https://destination.invalid','root'=>'/prepared/public'];
$prepared="<?php\n// Prepared native destination\n";foreach(['DB_HOST'=>'host','DB_NAME'=>'database','DB_USER'=>'username','DB_PASSWORD'=>'password']as$key=>$v)$prepared.='define( '.var_export($key,true).', '.var_export($connection[$v],true)." );\n";
$salts=[];foreach(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']as$key){$salts[$key]=hash('sha256','synthetic-'.$key);$prepared.='define( '.var_export($key,true).', '.var_export($salts[$key],true)." );\n";}
$prepared.="define( 'WP_DEBUG', false );\n\$table_prefix = 'fixture_';\nif ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
$build=static fn($bytes,$conn=null)=>Config::build($bytes,$conn??$connection,$old,$new,$urls);
try{
 $output=$build($prepared);$check(str_contains($output,"\$table_prefix = '".$new."';"),'one reviewed native prefix switches');
 foreach([12,15,24]as$digits){$p='krd_'.str_repeat('b',$digits).'_';$out=Config::build($prepared,$connection,$old,$p,$urls);$check(str_contains($out,"\$table_prefix = '".$p."';"),'bounded recovery prefix preserves prepared configuration: '.$digits);}
 foreach([11,25]as$digits)$reject(static fn()=>Config::build($prepared,$connection,$old,'krd_'.str_repeat('b',$digits).'_',$urls),'out of scope recovery prefix refuses configuration: '.$digits);
 foreach($salts as$key=>$value)$check(str_contains($output,'define( '.var_export($key,true).', '.var_export($value,true).' );'),'exact destination salt retained: '.$key);
 foreach(['DB_HOST'=>'host','DB_NAME'=>'database','DB_USER'=>'username','DB_PASSWORD'=>'password']as$key=>$v)$check(str_contains($output,'define( '.var_export($key,true).', '.var_export($connection[$v],true).' );'),'exact destination connection retained: '.$key);
 $check(str_contains($output,"define( 'DISABLE_WP_CRON', true );")&&str_contains($output,"define( 'AUTOMATIC_UPDATER_DISABLED', true );")&&str_contains($output,"define( 'WP_CACHE', false );"),'native cron updater and captured cache cannot replay');
 $existing=str_replace('// Prepared native destination',"define('WP_CACHE', true); define('DISABLE_WP_CRON', false); define('AUTOMATIC_UPDATER_DISABLED', false);",$prepared);$replacement=$build($existing);$check(str_contains($replacement,"define('WP_CACHE', false);")&&str_contains($replacement,"define('DISABLE_WP_CRON', true);")&&substr_count($replacement,"define('WP_CACHE'")===1,'existing guards are replaced without duplicate native definitions');
 $legacy=str_replace("__DIR__ . '/'","dirname(__FILE__) . '/'",$prepared);$check(str_contains($build($legacy),"dirname(__FILE__) . '/'"),'ordinary native legacy root expression remains supported');
 foreach(['host'=>'127.0.0.1','database'=>'other','username'=>'other','password'=>'wrong','port'=>3307]as$key=>$value){$c=$connection;$c[$key]=$value;$reject(static fn()=>$build($prepared,$c),'mismatched destination connection refused: '.$key);}
 $vectors=[
  'duplicate native credential'=>str_replace('// Prepared native destination',"define('DB_PASSWORD','duplicate');",$prepared),
  'dynamic credential'=>str_replace(var_export($connection['password'],true),"getenv('PRIVATE_SECRET')",$prepared),
  'conditional authority'=>str_replace('define( \'DB_NAME\', \'synthetic_database\' );',"if(true){define('DB_NAME','synthetic_database');}",$prepared),
  'foreign root'=>str_replace("__DIR__ . '/'","'/other/public/'",$prepared),
  'multisite'=>str_replace('// Prepared native destination',"define('MULTISITE',true);",$prepared),
  'custom users'=>str_replace('// Prepared native destination',"define('CUSTOM_USER_TABLE','other_users');",$prepared),
  'foreign include'=>str_replace('require_once ABSPATH',"include 'captured.php'; require_once ABSPATH",$prepared),
  'qualified executable call'=>str_replace('// Prepared native destination',"\\system('synthetic');",$prepared),
  'shell expression'=>str_replace('// Prepared native destination','`synthetic`;', $prepared),
  'second prefix'=>str_replace('// Prepared native destination',"\$table_prefix='other_';",$prepared),
  'changed prepared prefix'=>str_replace("'fixture_'","'other_'",$prepared),
  'missing salt'=>str_replace("define( 'AUTH_KEY', '".$salts['AUTH_KEY']."' );\n",'', $prepared),
  'weak salt'=>str_replace($salts['AUTH_KEY'],'short',$prepared),
  'duplicate salts'=>str_replace($salts['AUTH_SALT'],$salts['AUTH_KEY'],$prepared),
  'source URL override'=>str_replace('// Prepared native destination',"define('WP_HOME','https://source.invalid');",$prepared),
  'bootstrap continuation'=>str_replace("require_once ABSPATH . 'wp-settings.php';","require_once ABSPATH . 'wp-settings.php'; define('WP_DEBUG',true);",$prepared),
  'oversized configuration'=>$prepared.str_repeat(' ',1048576),
 ];
 foreach($vectors as$label=>$bytes)$reject(static fn()=>$build($bytes),'prepared configuration refuses '.$label);
 $expected=['schema'=>Activation::SCHEMA,'recoverySha256'=>str_repeat('a',64),'databaseReceiptSha256'=>str_repeat('b',64),'sourceInventorySha256'=>str_repeat('c',64),'destinationInventorySha256'=>str_repeat('d',64),'destinationDatabaseSha256'=>str_repeat('e',64),'codeReview'=>null,'destinationIsolation'=>null,'quarantinedTables'=>[['suffix'=>'stp_events','rows'=>1]],'withheldStateReview'=>null];$plan=$expected;$plan['codeReview']='exact-captured-code-approved';$plan['destinationIsolation']='requests-writers-host-cron-paused';$plan['withheldStateReview']='original-private-review-complete';
 $check(Activation::policy($plan,$expected)===$plan,'exact source destination and withheld-state review accepted');
 foreach(array_keys($plan)as$key){$v=$plan;$v[$key]=null;$reject(static fn()=>Activation::policy($v,$expected),'activation refuses unbound review: '.$key);}
 $extra=$plan;$extra['preserveCredentials']=true;$reject(static fn()=>Activation::policy($extra,$expected),'review cannot bypass credential quarantine');
 $v=$plan;$v['quarantinedTables']=[];$reject(static fn()=>Activation::policy($v,$expected),'review cannot silently omit withheld histories');
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($proof['passed']&&$proof['cleanup']?0:1);
