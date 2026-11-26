<?php
/** Source doubles qualify export/permission boundaries; actual WordPress and client remain separate. */
namespace DSA\Apps {
 final class Runtime_Registry {public static function installed($id){return $id==='mcp';}public static function owner($id){return self::class;}public static function enabled(){return true;}}
 final class Store_App_Service {public static function enabled(){return $GLOBALS['store'];}public static function taxonomy_available($v){return $GLOBALS['store']||!str_starts_with($v,'product');}public static function content_available($v){return $GLOBALS['store']||!str_starts_with($v,'product');}}
}
namespace DSA\Bricks {
 final class Document_Publication_Service {
  public static function available(){return true;}
  public static function allowed(){return true;}
 }
}
namespace DSA\MCP {
 final class Credentials {public static function actor_allowed($id){return $id===1&&!$GLOBALS['revoked'];}}
 final class Urls {public static function issuer(){return 'https://fixture.example/subdirectory';}}
}
namespace DSA\AI {final class Site_Graph_Service {public function __construct(...$args){}}}
namespace DSA\Site_Graph {
 final class Design_Context_Service {
  const MCP_DESIGN_API=1;
  public function __construct(...$args){}
  public function context($a,$private){$GLOBALS['nativeCalls'][]=['context',$a,$private];return ['schema'=>'kiwe.site-graph.v1','generatedAt'=>uniqid(),'authority'=>['source'=>'https://fixture.example/subdirectory/','publicDataOnly'=>true,'mayMutateWordPress'=>false],'siteGraph'=>['calibration'=>['generatedAt'=>uniqid(),'target'=>['version'=>'native']]],'nativeValue'=>$GLOBALS['nativeValue'],'text'=>$GLOBALS['nativeText']]+$GLOBALS['homepageFixture'];}
 }
 final class Data_Query_Service {public function query($a,$private){$GLOBALS['nativeCalls'][]=['query',$a,$private];return ['schema'=>'kiwe.site-graph.data.v1','generatedAt'=>uniqid(),'data'=>[['id'=>2,'title'=>'Published','image'=>'https://fixture.example/uploads/public.webp']],'query'=>$a];}}
}
namespace DSA\Design {
 final class Seam_Token_Service {
  public static function overrides_from_settings($s){return $s['overrides'];}public static function tokens_with_overrides($v){return $v;}public static function export_for_bricks($v){return ['native'=>$v];}public static function framework_classes_for_bricks(){return ['native-class'];}public static function sanitize_project_extensions($v){return $v;}
 }
 final class Seam_Vocabulary_Schema {public static function contract(){return ['native'=>'vocabulary'];}}
}
namespace DSA {
 final class Plugin {public static function instance(){return new self();}public function modules(){return new \stdClass();}public function settings(){return new class{public function all(){return ['overrides'=>['brand'=>'#abc'],'tokens'=>['project'=>['name'=>'native-project']]];}};}}
}
namespace {
 define('ABSPATH',__DIR__.'/');define('DSA_VERSION','8.0.0-rc.485');$store=true;$revoked=false;$caps=['kiwe_manage_context'=>true,'manage_options'=>true];$nativeCalls=[];$nativeValue='original';$nativeText=str_repeat('ನಮಸ್ಕಾರ',1600);$checks=[];$homepageFixture=[];
 function __($value,$domain=""){return $value;}
 function current_user_can($cap){return $GLOBALS['caps'][$cap]??false;}function get_current_user_id(){return 1;}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
 class WP_Error {public function __construct(public string $code,public string $message=''){}}function is_wp_error($v){return $v instanceof WP_Error;}
 function wp_strip_all_tags($value){return strip_tags($value);}
 function sanitize_email($value){return filter_var($value,FILTER_VALIDATE_EMAIL)?:'';}
 function wp_check_invalid_utf8($value,$strip=false){return $value;}
 function esc_url_raw($value,$protocols=[]){return str_starts_with($value,'https://')?$value:'';}
 function get_taxonomy($name){return in_array($name,['category','product_cat'],true)?(object)['name'=>$name,'public'=>true]:false;}
 function get_post_type_object($name){return in_array($name,['post','private_type','product'],true)?(object)['name'=>$name,'public'=>$name!=='private_type']:false;}
 $base=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/';foreach(['Bricks/Authoring_Reference','Apps/Runtime_Contract','Apps/MCP_Provider_Contract','MCP/Schema','MCP/Operation_Registry','MCP/Bricks_Document_Provider','MCP/Design_Provider','MCP/Design_Service']as$name)require $base.$name.'.php';
 use DSA\MCP\{Design_Service as Service,Design_Provider as Provider,Schema};
 function check($ok,$why){if(!$ok)throw new \RuntimeException($why);$GLOBALS['checks'][$why]=true;}
 function deny($fn,$why){try{$fn();}catch(\RuntimeException){check(true,$why);return;}throw new \RuntimeException('Expected refusal '.$why);}
 function read($a){return json_decode((new Service())->read($a)['resultJson'],true,512,JSON_THROW_ON_ERROR);}
 $placement=DSA\MCP\Bricks_Document_Provider::operations();
 check(count($placement)===3,'Document publication offers status, publish and restore');
 foreach($placement as $name=>$operation){
  check(Schema::contract($operation['inputSchema'])&&Schema::contract($operation['outputSchema'])&&$operation['scope']==='mcp.bricks.document','Document operation fits the closed MCP schema '.$name);
  if($operation['effect']==='write')check($operation['approval']==='required','Document write requires exact approval '.$name);
 }
 $ops=Provider::operations();$op=$ops['mcp.design.read'];check(count($ops)===4&&$op['scope']==='mcp.design.read'&&$op['effect']==='read'&&$op['approval']==='none','Design evidence offers four independently pinned reads');
 check(Schema::contract($op['inputSchema'])&&Schema::contract($op['outputSchema']),'Closed bounded native schemas');check(($op['permission'])([])&&($op['discoverPermission'])(),'Native discover/read permission');
 $registry=new DSA\MCP\Operation_Registry();$registry->register('mcp',1,fn()=>['schema'=>DSA\Apps\MCP_Provider_Contract::SCHEMA,'api'=>1,'app'=>'mcp','setup'=>['state'=>'ready','needs'=>[],'providerConsentRequired'=>false],'operations'=>$ops]);$oldPins=$registry->freeze(['mcp.read']);
 check($registry->describe(['mcp.read'],$oldPins)===[],'Earlier read scope does not advertise design exports');check(is_wp_error($registry->read('mcp.design.read',['area'=>'guide'],['mcp.read'],$oldPins)),'Earlier scope cannot execute design reads');check(is_wp_error($registry->read('mcp.design.read',['area'=>'guide'],['mcp.design.read'],$oldPins)),'Adding a requested scope cannot expand original frozen pins');
 $guide=read(['area'=>'guide']);check(count($nativeCalls)===0,'Guide performs no graph/catalogue read');check($guide['start']['entry']==='https://start.kiwelaunch.com/start.md'&&str_contains($guide['start']['versionPolicy'],'actual matching'),'Canonical Start is not a fabricated installed-release pin');check(in_array('Native Order payment and Thank-you endpoints',$guide['pageChecklist'],true),'Complete applicable commerce endpoints are in the design checklist');
 $first=read(['area'=>'sitegraph']);$parts=[$first];for($i=1;$i<$first['parts'];$i++)$parts[]=read(['area'=>'sitegraph','part'=>$i,'expected'=>$first['sha256']]);$joined='';foreach($parts as$part){$joined.=base64_decode($part['dataBase64'],true);check(Schema::value(['resultJson'=>wp_json_encode($part)],$op['outputSchema']),'Bounded result part '.$part['part']);}
 check(strlen($joined)===$first['bytes']&&hash('sha256',$joined)===$first['sha256'],'Complete multibyte native graph survives binary chunk assembly');$graph=json_decode($joined,true,512,JSON_THROW_ON_ERROR);
 check($graph['text']===$nativeText&&$graph['nativeValue']==='original','Native factual content remains exact');check(!isset($graph['generatedAt'],$graph['siteGraph']['calibration']['generatedAt'])&&str_contains($graph['authority']['captureClocks'],'omitted'),'Only documented volatile capture clocks are omitted');
 check(preg_match('/^sha256:[a-f0-9]{64}$/D',$graph['revision'])===1,'The timestamp-free graph has a stable native revision for strict semantic maps');
 check($nativeCalls[0][0]==='context'&&$nativeCalls[0][2]===false&&$nativeCalls[0][1]['mediaLimit']===8,'Existing public native context owns selected bounded evidence');
 foreach([['area'=>'sitegraph','part'=>1],['area'=>'sitegraph','expected'=>'invalid'],['area'=>'sitegraph','part'=>$first['parts'],'expected'=>$first['sha256']],['area'=>'sitegraph','resource'=>'media'],['area'=>'guide','part'=>1],['area'=>'guide','unexpected'=>'x']]as$i=>$args)deny(fn()=>read($args),'Malformed or ambiguous request refused '.$i);
 $nativeValue='updated';deny(fn()=>read(['area'=>'sitegraph','part'=>1,'expected'=>$first['sha256']]),'Changed native state refuses mixed-revision continuation');$nativeValue='original';
 $nativeText=str_repeat('x',Service::MAX_BYTES+1);deny(fn()=>read(['area'=>'sitegraph']),'Oversize graph refuses instead of truncating');$nativeText='Small';
 $framework=read(['area'=>'framework']);$frameworkDoc=json_decode(base64_decode($framework['dataBase64']),true);check($frameworkDoc['tokens']===['brand'=>'#abc']&&$frameworkDoc['project']['name']==='native-project','Framework reuses native overrides/project owner');
 $media=read(['area'=>'catalogue','resource'=>'media','page'=>2]);$last=end($nativeCalls);check($last[0]==='query'&&$last[2]===false&&$last[1]['contextOnly']===true&&$last[1]['limit']===8&&$last[1]['page']===2,'Catalogue uses native public selected-media pagination');
 read(['area'=>'catalogue','resource'=>'products']);$last=end($nativeCalls);check($last[1]['publicMeta']===false&&!in_array('meta',$last[1]['fields'],true),'No arbitrary metadata is requested for product preview');
 read(['area'=>'catalogue','resource'=>'terms','taxonomy'=>'category']);$last=end($nativeCalls);check($last[1]['taxonomy']==='category'&&$last[1]['limit']===100,'Terms preserve native bounded query semantics');
 foreach([['area'=>'catalogue','resource'=>'terms','taxonomy'=>'category','page'=>2],['area'=>'catalogue','resource'=>'menus','page'=>2],['area'=>'catalogue','resource'=>'posts','postType'=>'private_type'],['area'=>'catalogue','resource'=>'posts','postType'=>'product'],['area'=>'catalogue','resource'=>'media','taxonomy'=>'category'],['area'=>'catalogue','resource'=>'media','postType'=>'post'],['area'=>'catalogue','resource'=>'terms','taxonomy'=>'private']]as$i=>$args)deny(fn()=>read($args),'Protected or unsupported catalogue selection refused '.$i);
 $homepageFixture=[
  'seamDesignContext'=>['identity'=>['siteName'=>'Workshop','tagline'=>'Choose with care','description'=>'Published business fact'],
   'about'=>['story'=>'Our public workshop story'],'contact'=>['email'=>'public@example.test','phone'=>'+1 555 0100']],
  'catalog'=>['products'=>[['id'=>7,'title'=>'Actual published product','url'=>'https://fixture.example/shop/published']],'posts'=>[],'orders'=>[['id'=>77]]],
 ];
 $pins=$registry->freeze(['mcp.design.read']);$earlierPins=$pins;unset($earlierPins['mcp.design.homepage.read'],$earlierPins['mcp.design.document.read']);
 check(is_wp_error($registry->read('mcp.design.homepage.read',[],['mcp.design.read'],$earlierPins)),'Existing design grants retain their original operation pins');
 $home=$registry->read('mcp.design.homepage.read',[],['mcp.design.read'],$pins);
 $homepage=json_decode($home['resultJson'],true,512,JSON_THROW_ON_ERROR);
 check($homepage['seamDesignContext']['identity']['description']==='Published business fact'&&$homepage['catalog']['products'][0]['id']===7,'Homepage projects the website’s current public facts and products');
 check(!isset($homepage['catalog']['orders'])&&$homepage['authority']['mayMutateWordPress']===false,'Homepage capture excludes transaction and write state');
 check(is_wp_error($registry->read('mcp.design.document.read',[],['mcp.design.read'],$earlierPins)),'An older frozen grant cannot read the new document brief');
 $document=$registry->read('mcp.design.document.read',[],['mcp.design.read'],$pins);
 $brief=json_decode($document['resultJson'],true,64,JSON_THROW_ON_ERROR);
 check($brief['seamDesignContext']['about']['story']==='Our public workshop story'&&$brief['seamDesignContext']['contact']['email']==='public@example.test','Fresh document scope projects public story and contact from Design Context');
 check(!isset($homepage['seamDesignContext']['contact'],$brief['catalog']['orders']),'Document additions keep the homepage and private-data contracts separate');
 $store=false;
 $homeOff=json_decode((new Service())->homepage()['resultJson'],true,512,JSON_THROW_ON_ERROR);
 check($homeOff['catalog']['products']===[],'Store-off homepage contains no product preview records');
 check(!in_array('Shop',read(['area'=>'guide'])['pageChecklist'],true),'Store-off guide omits commerce checklist');deny(fn()=>read(['area'=>'catalogue','resource'=>'products']),'Store-off product catalogue refuses');deny(fn()=>read(['area'=>'catalogue','resource'=>'terms','taxonomy'=>'product_cat']),'Store-off product taxonomy refuses');check(read(['area'=>'framework'])['readOnly'],'Core Framework evidence survives Store absence');
 $revoked=true;check(!($op['permission'])([])&&!($op['discoverPermission'])(),'Revocation removes current discovery/read authority');deny(fn()=>read(['area'=>'guide']),'Direct read rechecks revoked actor');$revoked=false;
 foreach(array_keys($caps)as$cap){$caps[$cap]=false;check(!Service::allowed(),'Native permission remains required '.$cap);deny(fn()=>read(['area'=>'guide']),'No missing capability bypass '.$cap);$caps[$cap]=true;}
 $source=file_get_contents($base.'Site_Graph/Design_Context_Service.php');check(str_contains($source,"'apps'")&&str_contains($source,"\$graph['kiwe']['apps']"),'Core export retains authoritative App capability state');
 echo wp_json_encode(['passed'=>true,'checks'=>count($checks),'nativeSql'=>false,'actualClient'=>false,'writes'=>0]);
}
