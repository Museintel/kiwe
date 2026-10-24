<?php
// Named lab only. Native script graph and readonly public projections; no business or actor writes.
if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||!KIWE_WORKSPACE_TEST_SITE||home_url()!=='https://plum-giraffe-539524.hostingersite.com')throw new RuntimeException('Named disposable lab only');
$proof=['schema'=>'kiwe.lean-runtime-native.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$oldScripts=$GLOBALS['wp_scripts']??null;$oldStyles=$GLOBALS['wp_styles']??null;$oldUser=get_current_user_id();
$before=hash('sha256',maybe_serialize(get_option(DSA_OPTION_SETTINGS)));
try{
 $assert(KIWE_PACKAGE_BOOT_COMPLETE===true&&DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION,'complete expected native package boot');
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(!empty($manifest['valid'])&&!empty($manifest['complete']),'all installed members verify');
 $asset=null;foreach(($GLOBALS['wp_filter']['wp_enqueue_scripts']->callbacks??[])as $callbacks)foreach($callbacks as $callback){$fn=$callback['function'];if(is_array($fn)&&$fn[0] instanceof DSA\Public_Endpoint\Assets&&$fn[1]==='enqueue')$asset=$fn[0];}
 $assert($asset instanceof DSA\Public_Endpoint\Assets,'original registered public asset owner');
 $GLOBALS['wp_scripts']=new WP_Scripts();$GLOBALS['wp_styles']=new WP_Styles();
 $asset->enqueue();$scripts=wp_scripts();
 $assert(wp_script_is('dsa-public-contract','enqueued')&&wp_script_is('dsa-surface','enqueued'),'enabled public graph includes reusable catalog and Surface');
 $assert(in_array('dsa-public-contract',$scripts->registered['dsa-surface']->deps,true),'native consumer declares catalog dependency');
 $assert(str_contains($scripts->registered['dsa-public-contract']->ver,DSA_VERSION),'immutable catalog cache identity');
 $extra=$scripts->get_data('dsa-surface','data');
 $boot=json_decode(substr(trim($extra),strlen('var DSA_DATA = '),-1),true,512,JSON_THROW_ON_ERROR);
 $assert((string)$boot['contractCatalog']==='1'&&!isset($boot['kiweTokens']['items'])&&!isset($boot['seam']['vocabulary']),'HTML contains compact values instead of repeated static metadata');
 $assert(strlen($extra)<50000,'bounded actual localized boot after metadata extraction');
 $settings=DSA\Plugin::instance()->settings()->all();
 $expected=DSA\Design\Seam_Token_Service::tokens_with_overrides(DSA\Design\Seam_Token_Service::overrides_from_settings($settings));
 $catalogSource=file_get_contents(DSA_DIR.'assets/js/public-contract.js');
 preg_match('/const c=(\{.*\});window\.KiwePublicContract=/s',$catalogSource,$match);
 $catalog=json_decode($match[1]??'',true,512,JSON_THROW_ON_ERROR);
 foreach($catalog['tokens'] as &$token)if(array_key_exists($token['name'],$boot['kiweTokens']['values']))$token['value']=$boot['kiweTokens']['values'][$token['name']];unset($token);
 $assert($catalog['tokens']===array_slice($expected,0,80),'all eighty actual token records reconstruct exact prior native values');
 $assert($catalog['vocabulary']===DSA\Design\Seam_Vocabulary_Schema::contract(),'generated public vocabulary equals actual native owner');
 $assert($boot['kiweTokens']['count']===count($expected),'existing full token count remains intact');
 $assert($boot['nonce']===''&&empty($boot['phonekey']['nonce'])&&empty($boot['phonekey']['user']['id']),'public catalog does not introduce private authority or account identity');
 $assert(isset($boot['presentationModules']['diagnostics'])&&!wp_script_is('dsa-diagnostics','enqueued'),'diagnostics remains requested through the existing capability loader');
 ob_start();$scripts->do_items(['dsa-surface'],1);$printed=ob_get_clean();
 $positions=[];foreach(['dsa-public-contract-js','dsa-surface-js-extra','dsa-surface-js-before','dsa-surface-js']as $id){$positions[$id]=strpos($printed,'id="'.$id.'"');$assert($positions[$id]!==false,'actual WordPress prints '.$id);}
 $assert($positions['dsa-public-contract-js']<$positions['dsa-surface-js-extra']&&$positions['dsa-surface-js-extra']<$positions['dsa-surface-js-before']&&$positions['dsa-surface-js-before']<$positions['dsa-surface-js'],'catalog then localized site data then hydration then consumer ordering');
 $assert(substr_count($printed,'public-contract.js')===1,'exactly one reusable catalog request');
 ob_start();$scripts->do_items(['dsa-public-contract','dsa-surface'],1);$repeat=ob_get_clean();$assert(!str_contains($repeat,'<script'),'native done handles do not print twice');
 // Exercise the real disabled early boundary using an isolated settings object, never a stored write.
 $disabled=new DSA\Settings();$sr=new ReflectionClass($disabled);$sr->getProperty('resolved_settings')->setValue($disabled,['enabled'=>false]);$sr->getProperty('resolved_generation')->setValue($disabled,$sr->getStaticPropertyValue('write_generation'));
 $clone=clone $asset;(new ReflectionProperty($clone,'settings'))->setValue($clone,$disabled);
 $GLOBALS['wp_scripts']=new WP_Scripts();$GLOBALS['wp_styles']=new WP_Styles();$clone->enqueue();
 $assert(!wp_script_is('dsa-public-contract','enqueued')&&!wp_script_is('dsa-surface','enqueued'),'disabled Surface does not enqueue a catalog, core or diagnostic request');
 foreach(['assets/js/public-contract.js','assets/js/modules/diagnostics.js']as $name)$assert(in_array($name,DSA\Runtime\Package_Manifest::required_files(),true),'critical asset admission '.$name);
 $assert(hash('sha256',maybe_serialize(get_option(DSA_OPTION_SETTINGS)))===$before,'all original site choices remain exact');
 $proof['version']=DSA_VERSION;$proof['localizedBytes']=strlen($extra);$proof['tokenRecords']=count($catalog['tokens']);$proof['passed']=true;
}catch(Throwable $e){$proof['error']=$e->getMessage();}
finally{$GLOBALS['wp_scripts']=$oldScripts;$GLOBALS['wp_styles']=$oldStyles;wp_set_current_user($oldUser);$proof['cleanup']=true;}
echo json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
