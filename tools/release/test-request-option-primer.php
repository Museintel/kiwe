<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
$cache=[];$stored=['kiwe_access_policy_v1'=>['enabled'=>true,'owner'=>9],'pk_crypto_key_id'=>'existing-key','kiwe_seo_workspace_enabled_v1'=>false];$batches=[];$failure='';$wpdb=(object)['last_error'=>''];$checks=0;
function wp_cache_get($key,$group){global$cache;return$cache[$group][$key]??false;}
function wp_cache_set($key,$value,$group){global$cache;$cache[$group][$key]=$value;}
function wp_cache_delete($key,$group){global$cache;unset($cache[$group][$key]);}
function get_option($key,$default=false){return read_control($key,$default);}
function wp_prime_option_caches($keys){global$cache,$stored,$batches,$failure,$wpdb;$batches[]=$keys;$wpdb->last_error='';if($failure){foreach($keys as$key)$cache['options']['notoptions'][$key]=true;$cache['options']['notoptions']['foreign_missing']=true;if($failure==='throw')throw new RuntimeException('Synthetic batch fault');$wpdb->last_error='Synthetic SQL failure';return;}foreach($keys as$key){if(array_key_exists($key,$stored))$cache['options'][$key]=$stored[$key];else$cache['options']['notoptions'][$key]=true;}}
function read_control($key,$default=null){global$cache,$stored;if(array_key_exists($key,$cache['options']??[]))return$cache['options'][$key];if(!empty($cache['options']['notoptions'][$key]))return$default;return$stored[$key]??$default;}
function check8($yes,$label){global$checks;if(!$yes)throw new RuntimeException($label);$checks++;}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Runtime/Request_Option_Primer.php';
$settings=['phonekey'=>['enabled'=>true],'secure'=>['enabled'=>true],'metrics'=>['enabled'=>false],'abandoned_cart'=>['enabled'=>false],'commerce'=>['linked_products_enabled'=>false],'permissions'=>['enabled'=>false]];
DSA\Runtime\Request_Option_Primer::prime($settings);
check8(count($batches)===1,'One bounded native batch');check8(count($batches[0])<=30&&count($batches[0])===count(array_unique($batches[0])),'Closed unique control cardinality');
check8(read_control('kiwe_access_policy_v1')===['enabled'=>true,'owner'=>9],'Structured native owner control preserved');check8(read_control('kiwe_seo_workspace_enabled_v1',true)===false,'Explicit false remains false');check8(read_control('pk_crypto_key_id')==='existing-key','Existing key identity preserved');
$stored['kiwe_access_policy_v1']=['enabled'=>true,'owner'=>12];wp_cache_delete('kiwe_access_policy_v1','options');check8(read_control('kiwe_access_policy_v1')['owner']===12,'Native writer invalidation exposes newer ownership');
foreach(['sql','throw']as$failure){$cache=[];DSA\Runtime\Request_Option_Primer::prime($settings);check8(read_control('kiwe_access_policy_v1')['owner']===12,$failure.' cannot mask owner policy');check8(read_control('pk_crypto_key_id')==='existing-key',$failure.' cannot turn key identity into missing data');check8(read_control('kiwe_seo_workspace_enabled_v1',true)===false,$failure.' cannot enable disabled SEO');check8(!empty($cache['options']['notoptions']['foreign_missing']),$failure.' retains unrelated negative entries');}
$failure='';$cache=[];DSA\Runtime\Request_Option_Primer::prime([]);check8(!in_array('pk_crypto_key_id',end($batches),true)&&!in_array('stp_settings',end($batches),true),'Disabled auth/security lanes are not preloaded');check8(!in_array('dsa_store_analytics_db_version',end($batches),true),'Disabled store tracking is not preloaded');
check8(!in_array('kiwe_restored_store_v1',end($batches),true),'Non-Woo boot does not preload Store recovery marker');$stored['active_plugins']=['woocommerce/woocommerce.php'];$cache=[];DSA\Runtime\Request_Option_Primer::prime([]);check8(in_array('kiwe_restored_store_v1',end($batches),true)&&count(end($batches))<30,'Woo recovery marker shares the existing bounded native batch');
echo json_encode(['passed'=>true,'checks'=>$checks,'scope'=>'Local native-cache API boundary model, not WordPress integration']).PHP_EOL;
