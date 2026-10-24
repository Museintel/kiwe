<?php
declare(strict_types=1);
require __DIR__.'/workspace-test-bootstrap.php';
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_strip_all_tags($value){return strip_tags($value);}
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Continuity_App_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Analytics/Analytics_Report_Service.php';
use DSA\SEO\SEO_Defaults_Service as Defaults;
use DSA\SEO\SEO_Sitemap_Service as Sitemaps;
use DSA\Onboarding\Design_Context_Profile_Service as Profile;
use DSA\Onboarding\SEO_Context_Service as Context;

$options[Defaults::OPTION]=[];
$options['blog_public']='1';
$options[Profile::OPTION_PROFILE]=['identity'=>['siteType'=>'ecommerce'],'seo'=>['primaryGoal'=>'buy']];
check(!Defaults::news_automatic()&&!Defaults::news()['enabled'],'a store does not invent a News feed');
$options[Profile::OPTION_PROFILE]['identity']['siteType']='publication';
$options['blogname']='न्यूज़ & <b>Publication</b>';
$options['WPLANG']='en_IN';
$before=$options;
check(Defaults::news()===['enabled'=>true,'publication'=>'न्यूज़ & Publication','language'=>'en'],'publication uses current native name and website language without a second enable switch');
check($options===$before,'resolving automatic News performs no settings write');
foreach(['zh_TW'=>'zh-tw','zh_HK'=>'zh-tw','zh_Hans_CN'=>'zh-cn','pt_BR'=>'pt','azb'=>'azb',''=>'en']as$locale=>$language){$options['WPLANG']=$locale;check(Defaults::news()['language']===$language,'native locale normalizes for News: '.$locale);}
$options['WPLANG']='invalid-locale';
check(Defaults::news()['language']==='','unsupported native language is not silently invented');
$maps=new Sitemaps();
check($maps->news_xml()->get_error_code()==='kiwe_news_metadata','invalid publication metadata refuses malformed XML before any article query');
$options['WPLANG']='en_US';$options['blogname']='';
check($maps->news_xml()->get_error_code()==='kiwe_news_metadata','missing publication name refuses malformed News XML');
$options['blogname']='Native publication';
check(Context::goal()['key']==='buy'&&Context::goal()['label']==='Sales','SEO reads the existing approved Design Context goal');
check(DSA\Analytics\Analytics_Report_Service::goal()===Context::goal(),'Analytics consumes the same goal projection');
$hash=Context::goal_hash();$options[Profile::OPTION_PROFILE]['seo']['primaryGoal']='read';
check(Context::goal()['label']==='Readership'&&$hash!==Context::goal_hash(),'a saved goal change invalidates earlier goal-bound reviews');
$options[Profile::OPTION_PROFILE]['seo']['primaryGoal']=['injected'];
check(Context::goal()['key']==='','malformed goal cannot enter the approved context');
$options[Profile::OPTION_PROFILE]['identity']['siteType']='business';
$options[Defaults::OPTION]=Defaults::validate(['news'=>['enabled'=>true,'publication'=>'Retained paper','language'=>'fr']]);
check(Defaults::news()['publication']==='Retained paper'&&!Defaults::news_automatic(),'legacy imported News defaults remain exact and usable');
$options['blog_public']='0';
check($maps->news_xml()->get_error_code()==='kiwe_news_disabled','native WordPress privacy still excludes staging News discovery');
$fields=DSA\Access\Continuity_App_Service::fields()['story']['fields'];
check(!in_array('seo.allowIndexing',array_column($fields,'path'),true),'client Story has no whole-site indexing writer');
check(count(array_filter($fields,fn($f)=>$f['path']==='seo.primaryGoal'&&$f['label']==='SEO goal'))===1,'the approved goal has one named Design Context editing home');
echo "$count SEO context journey checks passed.\n";

require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/SEO/SEO_Settings_Handover_Model.php';
$verification=['google'=>'SyntheticGoogleCode_123456789','bing'=>str_repeat('a',32),'pinterest'=>str_repeat('b',32)];
check(Defaults::validate(['verification'=>$verification])['verification']===$verification,'native verification validates and retains the original provider codes');
foreach([['google'=>'<script>bad</script>'],['other'=>'synthetic'],['bing'=>[]],['pinterest'=>'https://example.test']]as$invalid)check(is_wp_error(Defaults::validate(['verification'=>$invalid])),'verification refuses unknown malformed or markup-bearing values');
$handover=DSA\SEO\SEO_Settings_Handover_Model::review(['general'=>['google_verify'=>$verification['google'],'bing_verify'=>$verification['bing'],'pinterest_verify'=>$verification['pinterest']]],[],[]);
check($handover['settings']['verification']===$verification&&$handover['providerRemovalReady']===false,'verification handover is exact without claiming full provider compatibility');
