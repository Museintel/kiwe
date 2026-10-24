<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/SEO/SEO_Defaults_Model.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/SEO/SEO_Settings_Handover_Model.php';
use DSA\SEO\SEO_Settings_Handover_Model as Model;
$proof=['schema'=>'kiwe.seo-settings-handover-local.v1','passed'=>false,'cleanup'=>true,'checks'=>[]];
$check=static function(bool $ok,string $label)use(&$proof):void{$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
try{
 $groups=['titles'=>['title_separator'=>'·','robots_global'=>['index','follow'],'homepage_title'=>'%sitename% %separator% %sitedesc%','pt_post_title'=>'%title% %sep% %sitename%','pt_post_custom_robots'=>'off','pt_post_robots'=>['noindex'],'tax_category_title'=>'%term% %sep% %sitename%','tax_category_custom_robots'=>'on','tax_category_robots'=>['noindex','follow'],'open_graph_image'=>'https://source.invalid/cover.jpg','twitter_card_type'=>'summary'], 'sitemap'=>['items_per_page'=>'200','authors_sitemap'=>'off','pt_post_sitemap'=>'on','tax_category_sitemap'=>'off','exclude_posts'=>'4, 5,4','exclude_terms'=>'7'], 'general'=>[]];
 $r=Model::review($groups,['post','page'],['category','post_tag']);$s=$r['settings'];
 $check($s['separator']==='·'&&$s['globalRobots']===['index','follow'],'stored site separator and indexing map through the shared defaults contract');
 $check($s['home']['title']==='%sitename% %sep% %tagline%','semantically equivalent native template aliases are translated');
 $check($s['postTypes']['post']['title']==='%title% %sep% %sitename%'&&!isset($s['postTypes']['post']['robots']),'disabled source custom robots never become an effective native override');
 $check($s['taxonomies']['category']['robots']===['noindex','follow']&&$s['taxonomies']['category']['sitemap']===false,'enabled taxonomy indexing and sitemap rules preserve their meaning');
 $check($s['social']===['image'=>'https://source.invalid/cover.jpg','twitterCard'=>'summary'],'global sharing defaults retain image and card choices');
 $check($s['sitemap']===['pageSize'=>200,'authors'=>false,'excludePosts'=>[4,5],'excludeTerms'=>[7]],'bounded sitemap exclusions retain identifiers and remove duplicates');
 $check(!$r['blockedFields']&&!$r['providerRemovalReady']&&count($r['manualReview'])===5,'clean stored fields do not certify implicit provider behavior or plugin removal');
 $check(!isset($s['postTypes']['page'],$s['taxonomies']['post_tag']),'absent stored scope choices are not invented from provider defaults');
 $before=$groups;$extended=$groups;$extended['general']['smtp_password']='Never expose source value';$extended['titles']['pt_post_default_rich_snippet']='article';$extended['titles']['pt_post_title']='%title% %page%';$extended['titles']['facebook_secret']='Never expose another source value';$x=Model::review($extended,['post'],['category']);
 $check(in_array('general.smtp_password',$x['blockedFields'],true)&&!str_contains(json_encode($x),'Never expose'),'unsupported source report contains field names rather than secret values');
 $check(in_array('titles.pt_post_default_rich_snippet',$x['blockedFields'],true),'generated provider schema cannot be silently treated as copied post metadata');
 $check(in_array('titles.pt_post_title',$x['blockedFields'],true)&&!isset($x['settings']['postTypes']['post']['title']),'different pagination semantics require explicit support rather than lossy substitution');
 $check($groups===$before,'mapping leaves every source setting unchanged');
 foreach([['pt_post_title','%unknown%'],['pt_post_title','<b>unsafe</b>'],['pt_post_title',"bad\0title"],['pt_post_title',"\xff"],['pt_post_custom_robots','invalid'],['open_graph_image','https://user:secret@source.invalid/image'],['twitter_card_type','player']]as[$key,$value]){$g=$groups;$g['titles'][$key]=$value;$x=Model::review($g,['post'],['category']);$check(in_array('titles.'.$key,$x['blockedFields'],true),'invalid mapped source field remains a handover blocker: '.$key.' '.count($proof['checks']));}
 foreach([['items_per_page','2000'],['exclude_posts','4,-5'],['exclude_posts','0'],['authors_sitemap','invalid'],['tax_category_sitemap',[]]]as[$key,$value]){$g=$groups;$g['sitemap'][$key]=$value;$x=Model::review($g,['post'],['category']);$check(in_array('sitemap.'.$key,$x['blockedFields'],true),'invalid discovery choice remains a handover blocker: '.$key.' '.count($proof['checks']));}
 $g=['titles'=>['capitalize_titles'=>'on','noindex_empty_taxonomies'=>'on'],'sitemap'=>['include_images'=>'on'],'general'=>['attachment_redirect_urls'=>'on']];$x=Model::review($g,['post'],['category']);$check($x['blockedFields']===['sitemap.include_images','titles.capitalize_titles']&&$x['settings']['policies']['noindexEmptyTerms']&&$x['settings']['policies']['redirectAttachments'],'only implemented source policies leave the explicit unsupported frontend report');
 $g=['titles'=>['disable_date_archives'=>'on','disable_author_archives'=>'off','author_custom_robots'=>'on','author_robots'=>['noindex'],'date_archive_robots'=>['noindex']], 'general'=>['attachment_redirect_urls'=>'on','attachment_redirect_default'=>'https://source.invalid/'], 'sitemap'=>[]];$x=Model::review($g,['post'],[]);
 $check($x['settings']['policies']===['redirectDateArchives'=>true,'redirectAuthorArchives'=>false,'redirectAttachments'=>true,'attachmentFallback'=>'https://source.invalid/'],'archive and attachment policies preserve stored enabled and disabled choices');
 $check($x['settings']['archives']['author']['robots']===['noindex']&&$x['settings']['archives']['date']['robots']===['noindex']&&!$x['blockedFields'],'archive noindex directives map through the existing native rules');
 $g['titles']['author_custom_robots']='off';$x=Model::review($g,['post'],[]);$check(!isset($x['settings']['archives']['author']['robots']),'disabled author custom robots stay inherited');
 foreach([['titles','disable_date_archives','maybe'],['titles','noindex_empty_taxonomies',[]],['titles','author_custom_robots','maybe'],['general','attachment_redirect_urls','yes'],['general','attachment_redirect_default','javascript:alert(1)']]as[$group,$key,$value]){$bad=$g;$bad[$group][$key]=$value;$x=Model::review($bad,['post'],[]);$check(in_array($group.'.'.$key,$x['blockedFields'],true),'invalid archive policy remains a blocker: '.$group.'.'.$key);}
 try{Model::review(['titles'=>['invalid.key'=>'x']],['post'],[]);throw new RuntimeException('Expected invalid source field refusal');}catch(InvalidArgumentException$e){$check(true,'malformed source keys refuse the report');}
 try{Model::review(['titles'=>[]],['bad.scope'],[]);throw new RuntimeException('Expected invalid scope refusal');}catch(InvalidArgumentException$e){$check(true,'unknown malformed public scope refuses mapping');}
 $proof['passed']=true;
}catch(Throwable$e){$proof['error']=$e->getMessage();}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($proof['passed']?0:1);
