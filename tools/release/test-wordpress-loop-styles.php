<?php
/** Actual Bricks Query/CSS lifecycle on existing lab products; all fixture state is request-local. */
if(!defined('KIWE_WORKSPACE_TEST_SITE')||!KIWE_WORKSPACE_TEST_SITE||home_url()!=='https://plum-giraffe-539524.hostingersite.com')throw new RuntimeException('Named isolated lab required');
$proof=['passed'=>false,'checks'=>[]];$loopCheck=static function($ok,$label)use(&$proof){$proof['checks'][]=$label;if(!$ok)throw new RuntimeException($label);};
$loopOriginalElements=Bricks\Assets::$elements;$loopOriginalClasses=Bricks\Database::$global_data['globalClasses']??[];
$loopOriginalQuery=$GLOBALS['wp_query'];$loopOriginalPost=$GLOBALS['post']??null;
$loopVisits=[];$loopObserver=static function($extra,$element,$type)use(&$loopVisits){if(($element['id']??'')==='a118label')$loopVisits[]=['index'=>Bricks\Query::get_loop_index(),'id'=>get_the_ID()];return $extra;};
try{
 $loopCheck(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&function_exists('WC'),'Exact candidate and native Woo loaded');
 $loopCheck(has_action('bricks/query/before_loop')!==false&&has_action('bricks/query/after_loop')!==false,'Native lifecycle registered');
 add_filter('bricks/assets/generate_css_from_element',$loopObserver,999,3);
 $loopRoot=['id'=>'a118root','name'=>'div','children'=>['a118label'],'settings'=>['hasLoop'=>true,'query'=>['objectType'=>'post','post_type'=>['product'],'posts_per_page'=>3,'post_status'=>'publish']]];
 $loopLabel=['id'=>'a118label','name'=>'text-basic','parent'=>'a118root','children'=>[],'settings'=>['text'=>'{post_title}','_typography'=>['color'=>['raw'=>'#222222']]]];
 Bricks\Assets::$elements=['a118root'=>$loopRoot,'a118label'=>$loopLabel];
 $loopQuery=new Bricks\Query($loopRoot);$loopResult=$loopQuery->query_result;$loopPosts=$loopResult->posts;$loopCount=$loopResult->post_count;
 $loopCheck($loopCount===3,'Real existing native product results');
 $loopQuery->render('Bricks\\Assets::generate_css_from_element',['element'=>$loopRoot,'css_type'=>'content']);
 $loopCheck(count($loopVisits)===1,'Static real CSS generates once');
 $loopCheck($loopResult===$loopQuery->query_result&&$loopResult->post_count===$loopCount&&$loopResult->posts===$loopPosts,'Complete native result and history identity restored');
 $loopHtmlIds=[];$loopQuery->render(static function()use(&$loopHtmlIds){$loopHtmlIds[]=get_the_ID();return '';},['element'=>$loopRoot]);
 $loopCheck($loopHtmlIds===array_map(static fn($p)=>(int)$p->ID,$loopPosts),'Subsequent native content still renders every product in order');
 $loopVisits=[];Bricks\Assets::$elements['a118label']['settings']['_width']='{post_id}px';
 $loopQuery->render('Bricks\\Assets::generate_css_from_element',['element'=>$loopRoot,'css_type'=>'content']);
 $loopCheck(count($loopVisits)===3,'Record-specific native style keeps full CSS loop');
 $loopCheck(array_column($loopVisits,'id')===array_map(static fn($p)=>(int)$p->ID,$loopPosts),'Dynamic CSS sees each original product');
 $loopCheck($loopResult->post_count===3&&$loopResult->posts===$loopPosts,'Dynamic style result remains exact');
 $loopVisits=[];Bricks\Assets::$elements['a118label']['settings']['_width']='{post_id @fallback:"1"}px';
 $loopQuery->render('Bricks\\Assets::generate_css_from_element',['element'=>$loopRoot,'css_type'=>'content']);
 $loopCheck(count($loopVisits)===3,'Whitespace/fallback tag retains every native CSS iteration');
 $loopCheck($loopResult->post_count===3&&$loopResult->posts===$loopPosts,'Argument-bearing dynamic CSS preserves complete native results');
 $proof['passed']=true;$proof['bricks']=BRICKS_VERSION;
}catch(Throwable$loopError){$proof['error']=$loopError->getMessage();}
finally{remove_filter('bricks/assets/generate_css_from_element',$loopObserver,999);Bricks\Assets::$elements=$loopOriginalElements;Bricks\Database::$global_data['globalClasses']=$loopOriginalClasses;$GLOBALS['wp_query']=$loopOriginalQuery;$GLOBALS['post']=$loopOriginalPost;if($loopOriginalPost)setup_postdata($loopOriginalPost);}
echo json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
