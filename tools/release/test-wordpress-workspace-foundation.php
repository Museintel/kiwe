<?php
// Native style graph proof; only the package-integrity cache is refreshed.
// No actor, content, business choice or provider mutations.
if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||!KIWE_WORKSPACE_TEST_SITE||home_url()!=='https://plum-giraffe-539524.hostingersite.com')throw new RuntimeException('Named disposable lab only');
$proof=['schema'=>'kiwe.workspace-foundation-native.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
$assert=static function($ok,$label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>(bool)$ok];if(!$ok)throw new RuntimeException($label);};
$originalStyles=$GLOBALS['wp_styles']??null;
try{
 $assert(KIWE_PACKAGE_BOOT_COMPLETE===true,'complete native package boot');
 $assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION,'expected immutable runtime');
 $manifest=DSA\Runtime\Package_Manifest::verify(true);
 $assert(!empty($manifest['valid'])&&!empty($manifest['complete']),'all native installed bytes verify');
 $tokenCss=file_get_contents(DSA_DIR.'assets/css/workspace-tokens.css');
 $foundation=file_get_contents(DSA_DIR.'assets/css/workspace-foundation.css');
 $assert(str_starts_with($foundation,$tokenCss),'native shared asset starts with exact private tokens');
 $variables=DSA\Design\Seam_Token_Service::css_variables();
 foreach(array_keys($variables)as $name)$assert(str_contains($tokenCss,$name.':'),'native canonical token present '.$name);
 $kinds=['ai','analytics','backups','commerce','continuity','dock','guest','identity','media','navigation','notification','posts','seo-object','taxonomy','team','templates','trash'];
 foreach($kinds as $kind){
  $GLOBALS['wp_styles']=new WP_Styles();
  $deps=$kind==='posts'?['wp-block-library','wp-block-editor','wp-edit-blocks','wp-format-library','wp-components']:[];
  $resolved=DSA\Access\Workspace_Design_Service::app_dependencies($deps);
  $assert($resolved===[...$deps,'kiwe-workspace-foundation'],'native dependencies preserve '.$kind);
  $handle='kiwe-'.$kind.'-app';
  wp_enqueue_style($handle,DSA_URL.'assets/css/'.$kind.'-app.css',$resolved,DSA_VERSION);
  ob_start();wp_print_styles([$handle]);$html=ob_get_clean();
  $shared=strpos($html,'id=\'kiwe-workspace-foundation-css\'');
  $route=strpos($html,'id=\''.$handle.'-css\'');
  $assert($shared!==false&&$route!==false&&$shared<$route,'selected-handle printing resolves foundation before '.$kind);
  $assert(substr_count($html,"id='kiwe-workspace-foundation-css'")===1,'one shared download for '.$kind);
  $assert(str_contains($html,'workspace-foundation.css?ver='.DSA_VERSION),'versioned native shared cache identity for '.$kind);
  ob_start();wp_print_styles([$handle,'kiwe-workspace-foundation']);$repeat=ob_get_clean();
  $assert(!str_contains($repeat,'<link '),'native done handles are not printed twice for '.$kind);
 }
 $GLOBALS['wp_styles']=new WP_Styles();
 DSA\Access\Workspace_Design_Service::enqueue();
 ob_start();wp_print_styles(['kiwe-app-primitives']);$home=ob_get_clean();
 $assert(strpos($home,'workspace-tokens.css')<strpos($home,'app-primitives.css')&&substr_count($home,'workspace-tokens.css')===1,'Home and legacy selected handles load tokens first once');
 $assert(!wp_style_is('kiwe-workspace-foundation','enqueued'),'Home does not load all React screen geometry');
 $GLOBALS['wp_styles']=new WP_Styles();
 $assert(!wp_style_is('kiwe-workspace-foundation','enqueued')&&!wp_style_is('kiwe-workspace-tokens','enqueued'),'fresh unrelated style graph has no private foundation');
 $proof['canonicalVariables']=count($variables);$proof['nativeRoutes']=count($kinds);$proof['version']=DSA_VERSION;
 $proof['passed']=true;
}catch(Throwable $e){$proof['error']=$e->getMessage();}
finally{$GLOBALS['wp_styles']=$originalStyles;$proof['cleanup']=true;}
echo json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
